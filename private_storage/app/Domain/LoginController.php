<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\Credentials;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Security\TokenService;

/**
 * Username and password.
 *
 * This replaces the previous zero-password login, in which an IMEI identified
 * the agent and the device key proved possession. That model had no secret
 * anywhere: the IMEI is printed on the handset and on the box, is recycled
 * between owners, and cannot be read by a browser at all, so it was a
 * user-typed string with the assurance of a serial number. It is not an
 * authentication factor and is not used as one here.
 *
 * The response is a *bootstrap* session. Login is pure credentials, so at this
 * point the client holds no bound device — it cannot have one, because the
 * device key is generated in the browser and registered afterwards. The token
 * issued here is therefore deliberately weak: it is bound to no device, and
 * Security\Authenticator will only let it reach the bearer-only bootstrap route.
 * Completing DeviceController's registration replaces it with a device-bound
 * token and a device-bound refresh cookie, and revokes the bootstrap pair.
 *
 * Every failure below returns the same 401 with the same body. The distinctions
 * that exist internally — no such user, wrong password, inactive agent, no
 * credential configured — are all collapsed at the edge, because any of them
 * being distinguishable is a way to learn something about the account.
 */
final class LoginController implements ActionInterface
{
    private const ENDPOINT = 'auth.login';

    /** Long enough to be a real password, short enough not to be a DoS vector. */
    private const PASSWORD_MAX = 200;

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw Validator::badRequest(ErrorCode::MALFORMED_REQUEST, 'Use POST.');
        }

        $body     = $request->json();
        $username = Validator::username($body['username'] ?? null);
        $password = Validator::string($body['password'] ?? null, 1, self::PASSWORD_MAX, 'password');

        $ip = $request->clientIp();

        /*
         * Rate limited on the username AND the IP, both. Per-username stops one
         * account being ground down from a botnet; per-IP stops a single host
         * walking the whole user table. Neither alone is sufficient: without
         * the per-IP key, "login" is a free username oracle at 10 tries per 15
         * minutes for the entire user table at once.
         */
        $limits = RateLimiter::limitsFor('auth.login');

        // The raw username: RateLimiter::enforce() and ::record() both hash the
        // identifier themselves, so passing an already-hashed value here would
        // store hash-of-a-hash in login_attempts and make the recorded key
        // impossible to correlate with any other hash of the username.
        RateLimiter::enforce(self::ENDPOINT, $username, $ip, $limits['limit'], $limits['window']);

        try {
            $agent = $this->authenticate($username, $password);

            $tokens = TokenService::i()->issueBootstrap($agent, $request->userAgent());

            $this->audit->recordSafe([
                'actor_agent_id' => (int) $agent['id'],
                'action'         => 'auth.login',
                'entity_type'    => 'agent',
                'entity_id'      => (int) $agent['id'],
                'ip_address'     => $ip,
                'metadata'       => ['username' => $agent['username']],
            ]);

            RateLimiter::record(self::ENDPOINT, $username, $ip, true);

            return Response::json([
                'access_token'       => $tokens['access_token'],
                'token_type'         => $tokens['token_type'],
                'expires_in'         => $tokens['expires_in'],
                'refresh_expires_at' => $tokens['refresh_expires_at'],
                'agent'              => [
                    'id'         => (int) $agent['id'],
                    'agent_code' => (string) $agent['agent_code'],
                    'full_name'  => (string) $agent['full_name'],
                ],
                /*
                 * Told to the client explicitly, because the next step is
                 * mandatory and a client that assumed it already held a
                 * bound session would discover it by getting a 403 on its
                 * first upload.
                 */
                'device_bound' => false,
                'next_step'    => 'device.register',
            ])->withCookie(array_merge(
                [
                    'name'  => TokenService::refreshCookieName(),
                    'value' => $tokens['refresh_token'],
                ],
                TokenService::refreshCookieAttributes()
            ));
        } catch (\Throwable $e) {
            RateLimiter::record(self::ENDPOINT, $username, $ip, false, 'AUTH_FAILED');

            if ($e instanceof ApiException && $e->status() === 429) {
                throw $e;
            }

            /*
             * The real reason is logged, never returned. A 401 whose message
             * differs between "no such user" and "wrong password" is a working
             * account-enumeration oracle even when the status code is the same.
             */
            \FieldPulse\Support\Logger::channel('app')->info('auth.login_failed', [
                'username_hash' => RateLimiter::hashIdentifier($username),
                'reason'        => $e->getMessage(),
            ]);

            throw self::genericFailure();
        }
    }

    /**
     * @throws ApiException on every failure
     */
    private function authenticate(string $username, string $password): array
    {
        $agent = $this->agents->findByUsername($username);

        // Credentials::verify() is called unconditionally, including when the
        // account does not exist, so the response time is the same either way.
        $hash = $agent === null ? null : (is_string($agent['password_hash'] ?? null) ? $agent['password_hash'] : null);

        if (!Credentials::verify($password, $hash)) {
            throw self::genericFailure();
        }

        // Only reachable once the password matched, so these checks cannot be
        // used to probe account state without the password.
        if ($agent === null || $agent['status'] !== AgentRepository::ACTIVE) {
            throw self::genericFailure();
        }

        return $agent;
    }

    private static function genericFailure(): ApiException
    {
        return new ApiException(
            401,
            ErrorCode::UNAUTHENTICATED,
            'Authentication failed.'
        );
    }
}
