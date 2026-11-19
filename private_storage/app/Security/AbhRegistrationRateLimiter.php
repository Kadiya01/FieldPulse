<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * ABH public-registration throttling.
 *
 * The identifier and IP are stored only as SHA-256 hashes. Both the phone and
 * IP have independent rolling windows, so rotating one does not bypass the
 * other. This uses the ABH-owned table, not the legacy FieldPulse login ledger.
 */
final class AbhRegistrationRateLimiter
{
    private const LIMIT = 5;
    private const WINDOW_SECONDS = 3600;

    private function __construct()
    {
    }

    public static function recordAndEnforce(string $phone, string $ip): void
    {
        $identifierHash = hash('sha256', 'abh-registration:' . strtolower(trim($phone)));
        $ipHash = hash('sha256', 'abh-registration-ip:' . gmdate('Y-m-d') . ':' . ($ip !== '' ? $ip : 'unknown'));

        $byIdentifier = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_registration_attempts
              WHERE identifier_hash = :hash
                AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 3600 SECOND',
            ['hash' => $identifierHash]
        );

        $byIp = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_registration_attempts
              WHERE ip_hash = :hash
                AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 3600 SECOND',
            ['hash' => $ipHash]
        );

        if ($byIdentifier >= self::LIMIT || $byIp >= self::LIMIT) {
            throw new ApiException(
                429,
                ErrorCode::RATE_LIMITED,
                'Too many registration attempts. Please try again later.',
                ['retry_after_seconds' => self::WINDOW_SECONDS]
            );
        }

        Connection::execute(
            'INSERT INTO abh_registration_attempts (identifier_hash, ip_hash, attempted_at)
             VALUES (:identifier_hash, :ip_hash, UTC_TIMESTAMP(6))',
            [
                'identifier_hash' => $identifierHash,
                'ip_hash' => $ipHash,
            ]
        );

        // Keep this housekeeping bounded; the rolling-window checks above only
        // inspect recent records, while old anonymous hashes need not accumulate.
        Connection::execute(
            'DELETE FROM abh_registration_attempts
              WHERE attempted_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY
              ORDER BY attempted_at ASC
              LIMIT 500'
        );
    }
}
