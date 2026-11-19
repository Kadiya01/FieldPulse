<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;

/** Separate hashed login-attempt ledger for ABH administrator accounts. */
final class AbhAdminAuthRateLimiter
{
    private function __construct()
    {
    }

    public static function recordAndEnforce(string $email, string $ip): void
    {
        $emailHash = hash('sha256', "abh-admin-login\0" . strtolower($email));
        $ipHash = hash('sha256', "abh-admin-login-ip\0" . ($ip !== '' ? $ip : 'unknown'));

        Connection::execute(
            'INSERT INTO abh_admin_auth_attempts (identifier_hash, ip_hash, attempted_at)
             VALUES (:identifier_hash, :ip_hash, UTC_TIMESTAMP(6))',
            ['identifier_hash' => $emailHash, 'ip_hash' => $ipHash]
        );

        $emailCount = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_admin_auth_attempts
              WHERE identifier_hash = :hash
                AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE',
            ['hash' => $emailHash]
        );
        $ipCount = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM abh_admin_auth_attempts
              WHERE ip_hash = :hash
                AND attempted_at >= UTC_TIMESTAMP(6) - INTERVAL 15 MINUTE',
            ['hash' => $ipHash]
        );

        Connection::execute(
            'DELETE FROM abh_admin_auth_attempts
              WHERE attempted_at < UTC_TIMESTAMP(6) - INTERVAL 7 DAY
              ORDER BY attempted_at ASC
              LIMIT 500'
        );

        if ($emailCount > 8 || $ipCount > 24) {
            throw new ApiException(
                429,
                ErrorCode::TOO_MANY_REQUESTS,
                'Too many administrator sign-in attempts. Please wait before trying again.'
            );
        }
    }
}
