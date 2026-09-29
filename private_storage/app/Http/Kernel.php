<?php

declare(strict_types=1);

namespace FieldPulse\Http;

use FieldPulse\Config\Config;
use FieldPulse\Support\Logger;
use FieldPulse\Support\RequestId;

/**
 * Front-controller kernel.
 *
 * Each public_html/api/v1/**\/*.php file is a three-line shim that calls
 * Kernel::boot(dirname(__FILE__)) then Kernel::handle('route.name'). All logic
 * lives in private_storage/app, outside the document root, so a misconfigured
 * .htaccess cannot expose the application code, the schema, or the .env.
 *
 * Cross-cutting concerns enforced here, once, for every route:
 *   - HTTPS is mandatory (refresh tokens are Secure cookies; without TLS the
 *     whole §6/§7 model collapses)
 *   - Origin/Referer equality for every request that can mutate state
 *   - uniform error envelope; unexpected exceptions never reach the client
 */
final class Kernel
{
    /** @var array<string,class-string> */
    private const ROUTES = [
        'auth.challenge'   => \FieldPulse\Domain\ChallengeController::class,
        'auth.login'       => \FieldPulse\Domain\LoginController::class,
        'auth.refresh'     => \FieldPulse\Domain\RefreshController::class,
        'auth.logout'      => \FieldPulse\Domain\LogoutController::class,
        'device.register'  => \FieldPulse\Domain\DeviceController::class,
        'submit'           => \FieldPulse\Domain\SubmitController::class,
        'submission.status' => \FieldPulse\Domain\SubmissionStatusController::class,
        'leaderboard'      => \FieldPulse\Domain\LeaderboardController::class,
        'reviews.index'    => \FieldPulse\Domain\ReviewController::class,
        'reviews.decide'   => \FieldPulse\Domain\ReviewController::class,
    ];

    /**
     * How each route is authenticated, by name.
     *
     * This table is the single source of truth for "who may call what", which
     * matters more than it looks: with one shim file per endpoint, forgetting to
     * add an auth check inside a controller leaves that endpoint completely
     * open, and nothing else in the system would notice. Declaring the
     * requirement here means an unauthenticated route is something you can see
     * in one place.
     *
     *   public    no credentials at all
     *   bearer    access JWT, plus a live agent and ACTIVE device
     *   signed    bearer, plus a valid device PoP signature over this request
     *   operator  bearer, plus SUPERVISOR or ADMIN role
     *
     * device.register is public, and that is not an oversight. A first-time
     * pairing happens before the agent has any token or any key on record, so
     * requiring a credential here would make pairing impossible. It is protected
     * inside the controller instead, by two factors the client cannot forge: an
     * operator-issued pairing code, or an existing valid access token for the
     * same agent. Both are checked there, per request.
     */
    private const AUTH = [
        'auth.challenge'   => 'public',
        'auth.login'       => 'public',
        'auth.refresh'     => 'public',   // the refresh cookie IS the credential
        'auth.logout'      => 'public',   // must work with an expired access token
        'device.register'  => 'public',   // authorised in-controller; see above
        'submit'           => 'signed',
        'submission.status' => 'bearer',
        'leaderboard'      => 'bearer',
        'reviews.index'    => 'operator',
        'reviews.decide'   => 'operator',
    ];

    private static bool $booted = false;

    private function __construct()
    {
    }

    /**
     * Locate and load the application from a public_html file's directory.
     *
     * Walks upward looking for private_storage/app/bootstrap.php so the
     * deployment tolerates public_html sitting at different depths.
     */
    public static function boot(string $startDir): void
    {
        if (self::$booted) {
            return;
        }

        $dir  = rtrim(str_replace('\\', '/', $startDir), '/');
        $root = null;

        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/private_storage/app/bootstrap.php')) {
                $root = $dir;
                break;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        if ($root === null) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "FieldPulse: cannot locate private_storage/app/bootstrap.php\n");
            } else {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo '{"error":{"code":"SERVICE_UNAVAILABLE","message":"Service unavailable."}}';
            }
            exit;
        }

        require_once $root . '/private_storage/app/bootstrap.php';
        self::$booted = true;
    }

    public static function handle(string $route): void
    {
        $startedAt = microtime(true);

        try {
            $request = Request::capture();

            self::assertHttps($request);
            self::assertSameOrigin($request, $route);

            $controllerClass = self::ROUTES[$route] ?? null;

            if ($controllerClass === null) {
                throw new ApiException(404, ErrorCode::NOT_FOUND, 'Not found.');
            }

            self::authenticate($request, $route);

            /** @var \FieldPulse\Domain\ActionInterface $controller */
            $controller = new $controllerClass();
            $response  = $controller($request);

            $response->send();

            Logger::debug('request.complete', [
                'route'    => $route,
                'status'   => $response->status(),
                'duration' => round((microtime(true) - $startedAt) * 1000, 1),
            ]);
        } catch (ApiException $e) {
            // Expected, client-facing failure.
            Logger::info('request.rejected', [
                'route'  => $route,
                'status' => $e->status(),
                'code'   => $e->errorCode(),
                'reason' => $e->getMessage(),
                'ctx'    => $e->context(),
            ]);

            self::debug($e);

            $errorResponse = Response::error(
                $e->errorCode(),
                $e->getMessage(),
                $e->status(),
                self::debugDetails($e)
            );

            foreach ($e->cookies() as $cookie) {
                $errorResponse = $errorResponse->withCookie($cookie);
            }

            $errorResponse->send();
        } catch (\Throwable $e) {
            // Unexpected. The client learns nothing about the cause.
            Logger::channel('app')->error('request.unhandled_exception', [
                'route'  => $route,
                'class'  => $e::class,
                'error'  => $e->getMessage(),
                'line'   => $e->getLine(),
                'file'   => self::relative($e->getFile()),
                'trace'  => array_slice(explode("\n", $e->getTraceAsString()), 0, 8),
            ]);

            Response::error(
                ErrorCode::INTERNAL_ERROR,
                'An unexpected error occurred.',
                500
            )->send();
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function debugDetails(ApiException $e): array
    {
        if (!Config::isBooted() || !Config::instance()->bool('app.debug')) {
            return [];
        }

        return $e->context();
    }

    private static function debug(ApiException $e): void
    {
        if (Config::isBooted() && Config::instance()->bool('app.debug')) {
            Logger::debug('api_exception.context', $e->context());
        }
    }

    /**
     * TLS is not optional: the refresh token is a Secure cookie and every
     * request carries a bearer token, both of which are worthless in cleartext.
     * Refusing here is better than silently degrading.
     */
    private static function assertHttps(Request $request): void
    {
        if ($request->isSecure()) {
            return;
        }

        Logger::channel('app')->warning('request.rejected_insecure_transport', [
            'path' => $request->path(),
            'ip'   => $request->clientIp(),
        ]);

        throw new ApiException(
            403,
            ErrorCode::FORBIDDEN,
            'HTTPS is required.'
        );
    }

    /**
     * Defence in depth against CSRF on the cookie-authenticated refresh endpoint.
     *
     * The main API authenticates with a Bearer header, which a browser will not
     * attach automatically from another origin, so it is not CSRF-exposed.
     * refresh.php is, because it reads an automatic cookie — hence this check.
     */
    private static function assertSameOrigin(Request $request, string $route): void
    {
        if ($request->method() === 'GET' || $request->method() === 'HEAD') {
            return;
        }

        $origin = $request->origin();

        // A same-origin fetch from some browsers omits Origin on GET only; for
        // non-GET it is always sent cross-origin, so its absence is acceptable.
        if ($origin === null) {
            return;
        }

        $appUrl = Config::instance()->str('app.url');

        if ($appUrl !== '') {
            $appOrigin = (string) (parse_url($appUrl, PHP_URL_SCHEME) . '://' . parse_url($appUrl, PHP_URL_HOST)
                . (parse_url($appUrl, PHP_URL_PORT) !== null ? ':' . parse_url($appUrl, PHP_URL_PORT) : ''));

            if (rtrim($origin, '/') === rtrim($appOrigin, '/')) {
                return;
            }
        }

        $allowed = array_filter(array_map('trim', explode(',', Config::instance()->str('security.allowed_origins'))));

        foreach ($allowed as $candidate) {
            if ($candidate !== '' && rtrim($origin, '/') === rtrim($candidate, '/')) {
                return;
            }
        }

        Logger::channel('app')->warning('request.rejected_bad_origin', [
            'route'  => $route,
            'origin' => $origin,
        ]);

        throw new ApiException(403, ErrorCode::ORIGIN_NOT_ALLOWED, 'Request origin is not allowed.');
    }

    private static function relative(string $path): string
    {
        $root = defined('FIELDPULSE_BASE_ROOT') ? FIELDPULSE_BASE_ROOT . DIRECTORY_SEPARATOR : '';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : basename($path);
    }

    /**
     * Apply the route's declared authentication requirement.
     *
     * Unknown routes are treated as `bearer` rather than `public`. That is the
     * safe direction for the error: a route name that fails to match the table
     * should lock itself down, not open itself up.
     */
    private static function authenticate(Request $request, string $route): void
    {
        $requirement = self::AUTH[$route] ?? 'bearer';

        if ($requirement === 'public') {
            return;
        }

        $authenticator = \FieldPulse\Security\Authenticator::i();

        $context = $requirement === 'signed'
            ? $authenticator->authenticateSignedRequest($request)
            : $authenticator->authenticate($request);

        $request->setAuthContext($context);

        if ($requirement === 'operator') {
            self::assertOperator($context, $route);
        }
    }

    /**
     * Supervisor-gated endpoints (§13).
     *
     * Reviews decide whether an agent gets paid for a submission, so they are
     * the most consequential write in the system. The check is here rather than
     * in ReviewController so that the requirement is visible in the route table
     * above, next to the route it protects.
     */
    private static function assertOperator(\FieldPulse\Security\AuthContext $context, string $route): void
    {
        $role = (string) ($context->agent()['role'] ?? 'AGENT');

        if (!in_array($role, ['SUPERVISOR', 'ADMIN'], true)) {
            Logger::channel('app')->warning('request.rejected_non_operator', [
                'route' => $route,
                'agent' => $context->agentCode(),
                'role'  => $role,
            ]);

            throw new ApiException(403, ErrorCode::FORBIDDEN, 'Supervisor access is required.');
        }
    }

    /**
     * @return list<string>
     */
    public static function routes(): array
    {
        return array_keys(self::ROUTES);
    }

    public static function isPublicRoute(string $route): bool
    {
        return (self::AUTH[$route] ?? 'bearer') === 'public';
    }

    /**
     * @return array<string,string>
     */
    public static function authRequirements(): array
    {
        return self::AUTH;
    }
}
