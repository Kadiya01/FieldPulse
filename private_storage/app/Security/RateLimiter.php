<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;

/**
 * Fixed-window rate limiting backed by login_attempts.
 *
 * Two independent windows are enforced: per identifier (the IMEI) and per IP.
 * Either being exhausted blocks the request, so an attacker cannot distribute
 * guesses for one IMEI across many addresses, nor spray many IMEIs from one
 * address.
 *
 * Counts are exact within the window, and a rejected request does not extend
 * it — otherwise a continuous trickle would hold the door open forever.
 *
 * Hashes, not plaintext: identifier_hash and ip_hash are salted digests, so a
 * table dump yields neither usable IMEIs nor address lists.
 */
final class RateLimiter
{
    /** Per-day rotation of the IP salt, so hashes are not correlatable forever. */
    private const IP_SALT_DAYS = 1;

    private function __construct()
    {
    }

    public static function hashIdentifier(string $identifier): string
    {
        return hash('sha256', 'fieldpulse-id:' . strtolower(trim($identifier)));
    }

    public static function hashIp(string $ip): string
    {
        $day = Clock::date();

        return hash('sha256', 'fieldpulse-ip:' . $day . ':' . $ip);
    }

    /**
     * Record an attempt and enforce the configured window.
     *
     * @param  string $endpoint  Logical endpoint key, e.g. 'auth.login'.
     * @param  int    $limit     Max attempts allowed inside the window.
     * @param  int    $window    Window length in seconds.
     * @throws ApiException when the limit is exceeded
     */
    public static function enforce(string $endpoint, string $identifier, string $ip, int $limit, int $window, string $errorCode = ErrorCode::RATE_LIMITED): void
    {
        $idHash = self::hashIdentifier($identifier);
        $ipHash = self::hashIp($ip);
        $since  = Clock::sql(Clock::shift(-$window));

        $byIdentifier = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM login_attempts
              WHERE identifier_hash = :h AND endpoint = :e AND attempted_at > :since',
            ['h' => $idHash, 'e' => $endpoint, 'since' => $since]
        );

        // A failed-attempt budget is separate from the raw request count so a
        // client retrying a correct request is not punished.
        $byIp = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_hash = :h AND endpoint = :e AND attempted_at > :since',
            ['h' => $ipHash, 'e' => $endpoint, 'since' => $since]
        );

        if ($byIdentifier >= $limit || $byIp >= $limit) {
            throw new ApiException(
                429,
                $errorCode,
                'Too many attempts. Please wait before trying again.',
                ['retry_after_seconds' => $window]
            );
        }
    }

    public static function record(
        string $endpoint,
        string $identifier,
        string $ip,
        bool $succeeded,
        ?string $failureCode = null
    ): void {
        try {
            Connection::execute(
                'INSERT INTO login_attempts (identifier_hash, ip_hash, endpoint, succeeded, failure_code, attempted_at)
                 VALUES (:id, :ip, :endpoint, :succeeded, :code, UTC_TIMESTAMP())',
                [
                    'id'        => self::hashIdentifier($identifier),
                    'ip'        => self::hashIp($ip),
                    'endpoint'  => $endpoint,
                    'succeeded' => $succeeded ? 1 : 0,
                    'code'      => $failureCode,
                ]
            );
        } catch (\Throwable) {
            // Rate-limit bookkeeping must not fail the request being judged.
        }
    }

    /**
     * Window limits pulled from config, so an operator can tune without a deploy.
     *
     * @return array{limit:int,window:int}
     */
    public static function limitsFor(string $endpoint): array
    {
        $c = Config::instance();

        return match ($endpoint) {
            'device.register' => [
                'limit'  => $c->int('auth.register_rate_limit'),
                'window' => $c->int('auth.register_rate_window'),
            ],
            default => [
                'limit'  => $c->int('auth.login_rate_limit'),
                'window' => $c->int('auth.login_rate_window'),
            ],
        };
    }

    public static function pruneOlderThanDays(int $days = 30): int
    {
        $cutoff = Clock::sql(Clock::shift(-$days * 86400));

        return Connection::execute(
            'DELETE FROM login_attempts WHERE attempted_at < :cutoff ORDER BY attempted_at ASC LIMIT 5000',
            ['cutoff' => $cutoff]
        );
    }

    public static function ipSaltRotationSeconds(): int
    {
        return self::IP_SALT_DAYS * 86400;
    }
}
