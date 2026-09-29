<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Json;
use FieldPulse\Support\Str;

/**
 * Minimal HS256 JWT codec.
 *
 * Hand-rolled on purpose: a JWT is `base64url(header).base64url(payload)` with
 * one HMAC, and pulling a library onto a host where `composer install` is not
 * guaranteed buys nothing. The verification path is written to be strict —
 * the algorithm is checked before any comparison, `alg: none` and every other
 * algorithm are rejected, and the signature comparison is constant time.
 *
 * The token carries no authorisation decision. `agent_id` and `device_uuid` are
 * claims, not permissions: the device row (and therefore its status) is re-read
 * from the database on every request, so a revoked device or a suspended agent
 * is refused immediately regardless of what a still-valid token asserts.
 */
final class Jwt
{
    private const HEADER = ['alg' => 'HS256', 'typ' => 'JWT'];

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $claims
     */
    public static function issue(array $claims): string
    {
        $c     = Config::instance();
        $now   = Clock::timestamp();
        $ttl   = $c->int('security.access_ttl');

        $payload = array_merge([
            'iss' => $c->str('security.jwt_issuer'),
            'aud' => $c->str('security.jwt_audience'),
            'iat' => $now,
            'nbf' => $now - 5,        // small allowance for host clock drift
            'exp' => $now + $ttl,
            'jti' => Str::base64UrlEncode(random_bytes(12)),
        ], $claims);

        // `sub` must be a string per RFC 7519.
        if (isset($payload['agent_id'])) {
            $payload['sub'] = (string) $payload['agent_id'];
        }

        $segments = [
            Str::base64UrlEncode((string) Json::encode(self::HEADER)),
            Str::base64UrlEncode((string) Json::encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $segments[]   = Str::base64UrlEncode(self::sign($signingInput));

        return implode('.', $segments);
    }

    /**
     * Verify signature, algorithm, issuer, audience and lifetime.
     *
     * @return array<string,mixed> claims
     * @throws ApiException
     */
    public static function verifyAndDecode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Malformed access token.');
        }

        [$h64, $p64, $s64] = $parts;

        $header = self::decodeSegment($h64, 'header');
        $claims = self::decodeSegment($p64, 'payload');
        $sig    = Str::base64UrlDecode($s64);

        if ($sig === null) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Malformed access token signature.');
        }

        // Pin the algorithm before touching the signature. Accepting the token's
        // own `alg` is the classic JWT bypass.
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Unsupported token algorithm.');
        }

        $expected = self::sign($h64 . '.' . $p64);

        if (!hash_equals($expected, $sig)) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token signature is invalid.');
        }

        self::assertClaims($claims);

        return $claims;
    }

    /**
     * @return array<string,mixed>
     */
    private static function decodeSegment(string $segment, string $what): array
    {
        $raw = Str::base64UrlDecode($segment);

        if ($raw === null) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Malformed access token ' . $what . '.');
        }

        try {
            $decoded = Json::decode($raw, 'jwt ' . $what);
        } catch (\JsonException) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Malformed access token ' . $what . '.');
        }

        if (!is_array($decoded)) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Malformed access token ' . $what . '.');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $claims
     */
    private static function assertClaims(array $claims): void
    {
        $c   = Config::instance();
        $now = Clock::timestamp();

        if (($claims['iss'] ?? null) !== $c->str('security.jwt_issuer')) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token issuer mismatch.');
        }

        if (($claims['aud'] ?? null) !== $c->str('security.jwt_audience')) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token audience mismatch.');
        }

        $exp = $claims['exp'] ?? null;
        if (!is_int($exp)) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token is missing an expiry.');
        }

        if ($now >= $exp) {
            throw new ApiException(401, ErrorCode::TOKEN_EXPIRED, 'Access token has expired.');
        }

        // Tolerate a small amount of host clock drift, but never more.
        $nbf = $claims['nbf'] ?? null;
        if (is_int($nbf) && $now + 30 < $nbf) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token is not yet valid.');
        }

        if (!isset($claims['sub']) || !is_string($claims['sub']) || !ctype_digit($claims['sub'])) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token subject is invalid.');
        }

        /*
         * device_uuid is required to be PRESENT, and may be null.
         *
         * A null device_uuid is a bootstrap session: login is pure credentials,
         * so at the moment the token is minted the client provably has no bound
         * device. Rejecting null here would make the bootstrap token
         * unrepresentable and force login to either bind a device it does not
         * have yet or drop the refresh cookie on the floor.
         *
         * This method checks the claim is well formed, not that it is
         * *sufficient* for a given route. That distinction is the whole design:
         * Authenticator::authenticate() requires a non-null device_uuid,
         * authenticateBootstrap() requires a null one, and neither can be
         * reached with the other's token. A token that is well formed but
         * insufficient for the route it is presented to fails there, which is
         * where the route's requirement is visible.
         */
        if (!array_key_exists('device_uuid', $claims)) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token does not declare a device binding.');
        }

        $deviceUuid = $claims['device_uuid'];

        if ($deviceUuid !== null && (!is_string($deviceUuid) || $deviceUuid === '')) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token device binding is invalid.');
        }

        /*
         * A bootstrap token must SAY it is one. Without this, scope is inferred
         * from the absence of a device claim, and any future code path that
         * mints a device-less token for some other reason would silently
         * inherit bootstrap privileges.
         */
        $scope = $claims['scope'] ?? null;

        if ($deviceUuid === null && $scope !== 'bootstrap') {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token is not a bootstrap session.');
        }

        if ($deviceUuid !== null && $scope === 'bootstrap') {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'Access token is internally inconsistent.');
        }
    }

    private static function sign(string $input): string
    {
        return hash_hmac('sha256', $input, Config::instance()->str('security.jwt_secret'), true);
    }

    /**
     * @return array<string,mixed>
     */
    public static function decodeWithoutVerification(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return [];
        }

        $raw = Str::base64UrlDecode($parts[1]);

        if ($raw === null) {
            return [];
        }

        try {
            $decoded = Json::decode($raw, 'jwt payload');
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
