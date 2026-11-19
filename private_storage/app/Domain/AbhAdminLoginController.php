<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAuthRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\AbhAdminAuthRateLimiter;
use FieldPulse\Security\Credentials;

/** POST /api/v1/abh/admin/auth/login.php */
final class AbhAdminLoginController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['email', 'password'], 'ABH administrator sign in');
        $email = Validator::email($body['email'] ?? null, 'email');
        if ($email === null) {
            throw ApiException::validation('Administrator email is required.', ['field' => 'email']);
        }
        $password = Validator::password($body['password'] ?? null);

        AbhAdminAuthRateLimiter::recordAndEnforce($email, $request->clientIp());

        $admin = AbhAdminAuthRepository::findLoginIdentity($email);
        // Always perform bcrypt, including for unknown email addresses.
        $passwordValid = Credentials::verify($password, $admin['password_hash'] ?? null);
        if ($admin === null || !$passwordValid) {
            throw new ApiException(401, ErrorCode::UNAUTHENTICATED, 'Email address or password is incorrect.');
        }

        if ((string) $admin['status'] !== 'ACTIVE') {
            throw new ApiException(403, ErrorCode::FORBIDDEN, 'This administrator account is not active.');
        }

        if ($admin['roles'] === []) {
            throw new ApiException(403, ErrorCode::FORBIDDEN, 'This administrator account has no assigned role. Contact the system owner.');
        }

        $tokens = AbhAdminAuthRepository::issueSession($admin);

        return Response::json([
            'access_token' => $tokens['access_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            'admin' => $tokens['admin'],
        ])->withCookie([
            'name' => AbhAdminAuthCookie::NAME,
            'value' => $tokens['refresh_token'],
            'expires' => strtotime($tokens['refresh_expires_at'] . ' UTC') ?: (time() + 86400),
            'path' => AbhAdminAuthCookie::PATH,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
