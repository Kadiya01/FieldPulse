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
use FieldPulse\Support\Env;

/**
 * One-time first administrator setup.
 *
 * Disabled unless ABH_ADMIN_BOOTSTRAP_KEY is set to a high-entropy secret in
 * the private .env file. The database singleton guard allows exactly one
 * successful first-admin creation, even when requests race.
 */
final class AbhAdminBootstrapController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $configuredKey = Env::get('ABH_ADMIN_BOOTSTRAP_KEY', '') ?? '';
        if (strlen($configuredKey) < 32) {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Administrator setup is disabled.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys(
            $body,
            ['bootstrap_key', 'full_name', 'email', 'password'],
            'first administrator setup'
        );

        $email = Validator::email($body['email'] ?? null, 'email');
        if ($email === null) {
            throw ApiException::validation('Administrator email is required.', ['field' => 'email']);
        }

        AbhAdminAuthRateLimiter::recordAndEnforce($email, $request->clientIp());

        $providedKey = Validator::string($body['bootstrap_key'] ?? null, 32, 256, 'bootstrap_key');
        if (!hash_equals($configuredKey, $providedKey)) {
            throw new ApiException(403, ErrorCode::FORBIDDEN, 'Administrator setup key is incorrect.');
        }

        $fullName = trim(Validator::string($body['full_name'] ?? null, 2, 160, 'full_name'));
        if ($fullName === '' || preg_match('/[\x00-\x1F\x7F]/', $fullName) === 1) {
            throw ApiException::validation('Enter a valid administrator name.', ['field' => 'full_name']);
        }

        $password = Validator::password($body['password'] ?? null);
        if (strlen($password) > 72) {
            throw ApiException::validation(
                'Password must be no more than 72 bytes.',
                ['field' => 'password', 'max_bytes' => 72]
            );
        }

        $result = AbhAdminAuthRepository::bootstrapFirstAdmin(
            $fullName,
            $email,
            Credentials::hash($password)
        );

        if (($result['error'] ?? null) === 'already_initialized') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'The first administrator has already been created.');
        }
        if (isset($result['error'])) {
            throw new \RuntimeException('ABH administrator bootstrap guard or system role is unavailable.');
        }

        return Response::json([
            'created' => true,
            'message' => 'The first ABH super administrator has been created. Sign in using the administrator portal.',
            'admin' => $result,
        ], 201);
    }
}
