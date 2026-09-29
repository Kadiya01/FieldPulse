<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Config\Config;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;

/**
 * Nonce replay protection (§7).
 *
 * A signature is valid forever. Replay protection is what stops a captured
 * `submit.php` request from being re-sent to claim the same activity a second
 * time, and it is enforced by the database rather than by a cache because the
 * deployment target has no guaranteed Redis, and because a cache eviction
 * would silently re-open the replay window.
 *
 * The write is the check: INSERT into a UNIQUE (device_id, nonce_sha256)
 * index, and treat duplicate-key as REPLAY_DETECTED. A SELECT-then-INSERT
 * sequence is not sufficient — two concurrent replays of the same captured
 * request would both observe "not seen yet".
 *
 * The nonce is namespaced per device, so two agents that happen to generate the
 * same random nonce do not lock each other out.
 */
final class NonceGuard
{
    private function __construct()
    {
    }

    /**
     * @throws ApiException when the nonce has already been used by this device
     */
    public static function consume(int $deviceId, string $nonce, string $path): void
    {
        $ttl  = Config::instance()->int('security.nonce_ttl');
        $hash = self::hash($deviceId, $nonce);

        // Expiry is computed in PHP rather than as INTERVAL :ttl SECOND. A bound
        // parameter inside INTERVAL is not portable across MySQL 5.7/8.0 and
        // MariaDB versions, and this code must not be version-sensitive.
        $expiresAt = Clock::sql(Clock::shift($ttl));

        try {
            Connection::execute(
                'INSERT INTO request_nonces (device_id, nonce_sha256, request_path, created_at, expires_at)
                 VALUES (:device_id, :nonce, :path, UTC_TIMESTAMP(), :expires_at)',
                [
                    'device_id'  => $deviceId,
                    'nonce'      => $hash,
                    'path'       => mb_substr($path, 0, 191),
                    'expires_at' => $expiresAt,
                ]
            );
        } catch (\PDOException $e) {
            if (self::isDuplicate($e)) {
                throw new ApiException(
                    401,
                    ErrorCode::REPLAY_DETECTED,
                    'This request nonce has already been used.'
                );
            }

            throw $e;
        }
    }

    /**
     * Expiry window is inclusive of the clock-skew allowance: a nonce must
     * remain rejected for at least as long as its timestamped request could
     * still pass the skew check.
     */
    public static function hash(int $deviceId, string $nonce): string
    {
        return hash('sha256', $deviceId . ':' . $nonce);
    }

    /**
     * Delete expired nonces. Called opportunistically by the worker and by
     * bin/prune.php; bounded so a single cron tick cannot lock the table for long.
     */
    public static function prune(int $limit = 5000): int
    {
        $deleted = Connection::execute(
            'DELETE FROM request_nonces WHERE expires_at < UTC_TIMESTAMP() ORDER BY expires_at ASC'
            . \FieldPulse\Database\Repository::limitClause($limit, 10000)
        );

        return $deleted;
    }

    /**
     * Low-probability opportunistic sweep from the request path, so a host that
     * only runs the per-minute cron still cannot accumulate nonces without bound.
     */
    public static function pruneOccasionally(): void
    {
        if (random_int(1, 200) !== 1) {
            return;
        }

        try {
            self::prune(500);
        } catch (\Throwable) {
            // Housekeeping only.
        }
    }

    public static function ttl(): int
    {
        return Config::instance()->int('security.nonce_ttl');
    }

    private static function isDuplicate(\PDOException $e): bool
    {
        // 1062 = ER_DUP_ENTRY; 23000 is the SQLSTATE for integrity constraint
        // violation, which is what the driver reports consistently.
        $driverCode  = (int) ($e->errorInfo[1] ?? 0);
        $sqlState    = (string) ($e->errorInfo[0] ?? '');

        return $driverCode === 1062 || $sqlState === '23000';
    }

    public static function expiresAt(): string
    {
        return Clock::sql(Clock::shift(self::ttl()));
    }
}
