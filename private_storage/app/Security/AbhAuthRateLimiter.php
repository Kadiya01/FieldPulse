<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/**
 * Rate limits ABH login attempts without reading or writing legacy tables.
 * Only keyed hashes are retained; raw phone numbers and IP addresses are not.
 */
final class AbhAuthRateLimiter
{
    private function __construct()
    {
    }

    public static function recordAndEnforce(string $identifier, string $ip): void
    {
        $identifierHash = hash('sha256', "abh-login\0" . $identifier);
        $ipHash = hash('sha256', "abh-login-ip\0" . ($ip !== '' ? $ip : 'unknown'));

        Connection::execute(
            'INSERT INTO abh_auth_attempts (identifier_hash, ip_hash, attempted_at)
             VALUES (:identifier_hash, :ip_hash, UTC_TIMESTAMP(6))',
            ['identifier_hash' => $identifierHash, 'ip_hash' => $ipHash]
        );

        $identifierCount = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_auth_attempts
              WHERE identifier_hash = :hash
                AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE',
            ['hash' => $identifierHash]
        );
        $ipCount = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_auth_attempts
              WHERE ip_hash = :hash
                AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE',
            ['hash' => $ipHash]
        );

        // Keep the ledger bounded on shared hosting. The limit avoids a large
        // cleanup transaction during a login request.
        Connection::execute(
            'DELETE FROM abh_auth_attempts
              WHERE attempted_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY
              ORDER BY attempted_at ASC
              LIMIT 500'
        );

        if ($identifierCount > 10 || $ipCount > 30) {
            throw new ApiException(
                429,
                ErrorCode::TOO_MANY_REQUESTS,
                'Too many sign-in attempts. Please wait before trying again.'
            );
        }
    }
}
