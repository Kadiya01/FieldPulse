<?php

declare(strict_types=1);

namespace FieldPulse\Security;

use FieldPulse\Database\AbhAdminAuthRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;

/**
 * Authenticates ABH administrative routes against administrator-only sessions.
 * Roles and permissions are reloaded for each request, so revoking a role or
 * suspending the account takes effect immediately.
 */
final class AbhAdminAuthenticator
{
    private function __construct()
    {
    }

    public static function authenticate(Request $request): AbhAdminContext
    {
        $token = $request->bearerToken();
        if ($token === null) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'An ABH administrator Bearer token is required.');
        }

        $admin = AbhAdminAuthRepository::findByAccessToken($token);
        if ($admin === null) {
            throw new ApiException(401, ErrorCode::TOKEN_INVALID, 'ABH administrator session is not valid.');
        }

        if ((string) $admin['status'] !== 'ACTIVE') {
            throw new ApiException(403, ErrorCode::FORBIDDEN, 'This administrator account is not active.');
        }

        if ($admin['roles'] === []) {
            throw new ApiException(403, ErrorCode::FORBIDDEN, 'This administrator account has no assigned role.');
        }

        Connection::execute(
            'UPDATE abh_admin_sessions SET last_used_at = UTC_TIMESTAMP(6) WHERE id = :id',
            ['id' => $admin['session_id']]
        );

        return new AbhAdminContext(
            (int) $admin['id'],
            (string) $admin['full_name'],
            (string) $admin['email'],
            $admin['roles'],
            $admin['permissions']
        );
    }
}
