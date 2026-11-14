<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAgentRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\AbhRegistrationRateLimiter;
use FieldPulse\Security\Credentials;
use FieldPulse\Support\Logger;

/**
 * ABH Monthly Enrollment public agent registration.
 *
 * Contract: full_name, phone, optional email/location, IMEI, device_type and
 * password. No username or NIN is accepted. New agents and their devices are
 * PENDING until an administrator approves them.
 */
final class AbhAgentRegistrationController implements ActionInterface
{
    public function __construct(
        private readonly string $routeName = 'abh.agent.register'
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys(
            $body,
            ['full_name', 'phone', 'email', 'location', 'imei', 'device_type', 'password'],
            'ABH agent registration'
        );

        $fullName = trim(Validator::string($body['full_name'] ?? null, 2, 160, 'full_name'));
        if ($fullName === '' || preg_match('/[\x00-\x1F\x7F]/', $fullName) === 1) {
            throw ApiException::validation('Enter a valid full name.', ['field' => 'full_name']);
        }

        $phone = Validator::nigerianPhone($body['phone'] ?? null);
        $email = Validator::email($body['email'] ?? null);
        $location = Validator::location($body['location'] ?? null);
        $imei = Validator::imei($body['imei'] ?? null);
        $deviceType = Validator::deviceType($body['device_type'] ?? null);
        $password = Validator::password($body['password'] ?? null);

        // Credentials uses bcrypt, whose input is limited to 72 bytes. Reject
        // longer UTF-8 passwords instead of silently hashing a truncated value.
        if (strlen($password) > 72) {
            throw ApiException::validation(
                'Password must be no more than 72 bytes.',
                ['field' => 'password', 'max_bytes' => 72]
            );
        }

        AbhRegistrationRateLimiter::recordAndEnforce($phone, $request->clientIp());
        $passwordHash = Credentials::hash($password);

        try {
            $agent = AbhAgentRepository::createPending(
                $fullName,
                $phone,
                $email,
                $location,
                $imei,
                $deviceType,
                $passwordHash
            );
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '23000' || (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new ApiException(
                    409,
                    ErrorCode::STATE_CONFLICT,
                    'A registration already exists for this phone number, email address or device IMEI.'
                );
            }

            Logger::channel('app')->error('abh.agent.registration_failed', [
                'phone_hash' => hash('sha256', $phone),
                'reason' => $error->getMessage(),
            ]);
            throw $error;
        }

        return Response::json([
            'status' => 'PENDING',
            'message' => 'Registration submitted and awaiting administrator approval.',
            'agent' => [
                'id' => $agent['id'],
                'agent_code' => $agent['agent_code'],
                'full_name' => $agent['full_name'],
                'status' => 'PENDING',
            ],
        ], 201);
    }
}
