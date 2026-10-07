<?php

declare(strict_types=1);

namespace FieldPulse\Http;

use FieldPulse\Config\Config;
use FieldPulse\Support\Json;

/**
 * Immutable view of the inbound request.
 *
 * Design notes that matter for security:
 *
 *  - path() is the path ONLY, never the query string. The signed canonical
 *    string contains the path, so anything security-relevant arriving in the
 *    query string would be outside the signature. Signatures are validated
 *    against this value, never against a client-supplied header.
 *
 *  - rawBody() reads php://input. It is empty for multipart/form-data, which is
 *    exactly why submit.php signs the verbatim `payload` field instead
 *    (see Security\CanonicalPayload). signedBody() picks the correct source and
 *    is the only accessor the signature layer should use.
 *
 *  - clientIp() ignores X-Forwarded-For unless the operator has explicitly
 *    declared a trusted proxy. On shared hosting an unvalidated XFF is an
 *    attacker-controlled string, and it feeds audit logs and rate limiting.
 */
final class Request
{
    /**
     * @param array<string,string> $headers @param array<string,mixed> $query @param array<string,mixed> $form @param array<string,mixed> $files
     *
     * $origin is the one field that is not readonly, and it is written exactly
     * once, in capture(), immediately after construction. It has to be: reading
     * it needs $this->headers, which does not exist until the object is built,
     * so a static call inside the constructor's argument list fatals. Every
     * other field is genuinely known before the object exists.
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $form,
        private readonly array $files,
        private readonly array $headers,
        private readonly string $rawBody,
        private readonly string $clientIp,
        private ?string $origin = null,
    ) {
    }

    /**
     * The authorised caller, attached by Http\Kernel after authentication.
     *
     * This is the one mutable field on an otherwise immutable object, and it is
     * mutable only by the kernel, never by application code. Attaching identity
     * to the request rather than passing it as a second argument to __invoke()
     * keeps ActionInterface a one-method interface and keeps every controller's
     * signature identical, so adding authentication to a route is a change in
     * Kernel::ROUTES rather than an edit to nine controllers.
     *
     * requireAuth() fails closed: a controller that asks for the context and
     * receives none gets a 401, never an anonymous request.
     */
    private ?\FieldPulse\Security\AuthContext $authContext = null;

    /** @internal Called only by Http\Kernel. */
    public function setAuthContext(\FieldPulse\Security\AuthContext $context): void
    {
        $this->authContext = $context;
    }

    public function authContext(): ?\FieldPulse\Security\AuthContext
    {
        return $this->authContext;
    }

    public function requireAuth(): \FieldPulse\Security\AuthContext
    {
        if ($this->authContext === null) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Authentication is required.');
        }

        return $this->authContext;
    }

    public function isAuthenticated(): bool
    {
        return $this->authContext !== null;
    }

    public static function capture(): self
    {
        $request = new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::extractPath(),
            $_GET,
            $_POST,
            $_FILES,
            self::extractHeaders(),
            self::readRawBody(),
            self::resolveClientIp(),
            null,
        );

        /*
         * Resolved after construction, not as a ninth constructor argument.
         *
         * header() reads $this->headers, which does not exist until the object
         * is built, so the origin used to be read with self::header('Origin')
         * inside the argument list. That is a static call to a non-static
         * method: it fatals with "Non-static method cannot be called
         * statically" on the very first request of every process. Nothing
         * caught it because the origin is the only field that needs the
         * already-extracted header map, and every test that builds a Request
         * by hand passes the origin directly.
         */
        $request->origin = $request->header('Origin');

        return $request;
    }

    /**
     * Normalise REQUEST_URI to a path.
     *
     * Percent-encoding is decoded so that the value the server verifies is the
     * same logical path the client signed. Decoding happens exactly once: a
     * second decode would let %252F collapse to a separator after the check.
     */
    private static function extractPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $q   = strpos($uri, '?');
        $raw = $q === false ? $uri : substr($uri, 0, $q);

        $decoded = rawurldecode($raw);

        if (!str_starts_with($decoded, '/')) {
            $decoded = '/' . $decoded;
        }

        // Collapse duplicate slashes and strip a trailing slash (except for "/"),
        // so /api/v1/submit.php and //api/v1//submit.php/ verify identically.
        $normalised = preg_replace('#/+#', '/', $decoded) ?? $decoded;

        return $normalised !== '/' ? rtrim($normalised, '/') : '/';
    }

    /** @return array<string,string> */
    private static function extractHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $name) {
            if (isset($_SERVER[$server]) && is_scalar($_SERVER[$server])) {
                $headers[$name] = (string) $_SERVER[$server];
            }
        }

        return $headers;
    }

    private static function readRawBody(): string
    {
        $isMultipart = str_contains(strtolower(self::rawContentType()), 'multipart/form-data');

        if ($isMultipart) {
            // PHP has already consumed the stream into $_POST/$_FILES.
            return '';
        }

        $body = file_get_contents('php://input', false, null, 0, 1024 * 1024);

        return $body === false ? '' : $body;
    }

    private static function rawContentType(): string
    {
        return (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    }

    private static function resolveClientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!Config::instance()->bool('security.trust_proxy_headers')) {
            return $remote;
        }

        // Only consulted when the operator has declared the host sits behind a
        // proxy they control. Left-most XFF entry is the original client.
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;

        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return $remote;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The endpoint the shim dispatched to: the final path segment, without any
     * PHP extension.
     *
     * Routing must not test path() for a suffix such as "/decide". The deployed
     * URI is /api/v1/rewards/decide.php, which ends in ".php", so a suffix test
     * never matched: every action fell through to the controller's index action,
     * an operator's POST was answered 405, and an agent's /self was served the
     * whole period board instead of their own entitlement. path() itself cannot
     * be normalised — the signature and the nonce replay guard are verified
     * against it verbatim (Security\Authenticator), so this is a routing-only
     * view of the same request.
     */
    public function endpoint(): string
    {
        $leaf = basename($this->path);

        return str_ends_with($leaf, '.php') ? substr($leaf, 0, -4) : $leaf;
    }

    public function isMultipart(): bool
    {
        return str_contains(strtolower($this->contentType()), 'multipart/form-data');
    }

    public function contentType(): string
    {
        return self::rawContentType();
    }

    /**
     * The exact bytes covered by the request signature.
     *
     * multipart -> the verbatim `payload` field.
     * otherwise -> the raw request body.
     */
    public function signedBody(): string
    {
        if ($this->isMultipart()) {
            $payload = $this->form['payload'] ?? null;

            if (!is_string($payload)) {
                throw new ApiException(
                    422,
                    ErrorCode::MALFORMED_REQUEST,
                    'Multipart request is missing the signed "payload" field.'
                );
            }

            return $payload;
        }

        return $this->rawBody;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Decoded JSON body. Enforces the content type so a form post cannot be
     * mistaken for a signed JSON request.
     *
     * @return array<string,mixed>
     */
    public function json(): array
    {
        $type = strtolower($this->contentType());

        if ($type !== '' && !str_contains($type, 'application/json')) {
            throw new ApiException(
                415,
                ErrorCode::UNSUPPORTED_MEDIA,
                'Content-Type must be application/json.'
            );
        }

        if (trim($this->rawBody) === '') {
            return [];
        }

        try {
            return Json::decodeArray($this->rawBody, 'request body');
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(400, ErrorCode::INVALID_JSON, 'Request body is not valid JSON.');
        }
    }

    public function form(string $key, ?string $default = null): ?string
    {
        $value = $this->form[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /** @return array<string,mixed> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string,mixed>|null */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        if (is_array($value)) {
            return $default;
        }

        return is_string($value) ? $value : $default;
    }

    public function queryInt(string $key, ?int $default = null): ?int
    {
        $value = $this->query($key);

        if ($value === null || !preg_match('/^-?\d+$/', $value)) {
            return $default;
        }

        return (int) $value;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function requireHeader(string $name, string $errorCode = ErrorCode::SIGNATURE_INVALID): string
    {
        $value = $this->header($name);

        if ($value === null || trim($value) === '') {
            throw new ApiException(400, $errorCode, 'Missing required header: ' . $name);
        }

        return trim($value);
    }

    /**
     * Extract the bearer token. The scheme is matched case-insensitively per
     * RFC 7235, and anything else is refused rather than ignored.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');

        if ($header === null || $header === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+([A-Za-z0-9._\-]+)$/i', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function origin(): ?string
    {
        return $this->origin;
    }

    public function userAgent(): ?string
    {
        return $this->header('user-agent');
    }

    public function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        return Config::instance()->bool('security.trust_proxy_headers')
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
