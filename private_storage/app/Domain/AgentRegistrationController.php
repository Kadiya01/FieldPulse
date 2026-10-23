<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Support\Logger;

final class AgentRegistrationController implements ActionInterface
{
    private const ENDPOINT = 'agent.register';

    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly AuditRepository $audit = new AuditRepository(),
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['full_name', 'nin', 'phone', 'imei', 'device_type'], 'agent registration');
        $fullName = trim(Validator::string($body['full_name'] ?? null, 2, 191, 'full_name'));
        $nin = Validator::nin($body['nin'] ?? null);
        $phone = Validator::nigerianPhone($body['phone'] ?? null);
        $imei = Validator::imei($body['imei'] ?? null);
        $deviceType = Validator::deviceType($body['device_type'] ?? null);

        if ($fullName === '' || preg_match('/[\x00-\x1F\x7F]/', $fullName) === 1) {
            throw ApiException::validation('Enter a valid full name.', ['field' => 'full_name']);
        }

        $ip = $request->clientIp();
        $limits = RateLimiter::limitsFor(self::ENDPOINT);
        RateLimiter::enforce(self::ENDPOINT, $nin, $ip, $limits['limit'], $limits['window']);

        if ($this->agents->registrationExists($nin, $imei)) {
            RateLimiter::record(self::ENDPOINT, $nin, $ip, false, 'REGISTRATION_EXISTS');
            throw new ApiException(
                409,
                ErrorCode::STATE_CONFLICT,
                'A registration already exists for this NIN or IMEI.'
            );
        }

        $agentCode = 'FP-' . strtoupper(bin2hex(random_bytes(6)));

        try {
            $agentId = $this->agents->createSelfRegistered(
                $agentCode,
                $fullName,
                $nin,
                $phone,
                $imei,
                $deviceType
            );
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                RateLimiter::record(self::ENDPOINT, $nin, $ip, false, 'REGISTRATION_EXISTS');
                throw new ApiException(
                    409,
                    ErrorCode::STATE_CONFLICT,
                    'A registration already exists for this NIN or IMEI.',
                    [],
                    $e
                );
            }

            Logger::channel('app')->error('agent.registration_failed', [
                'nin_hash' => RateLimiter::hashIdentifier($nin),
                'reason' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->audit->recordSafe([
            'actor_agent_id' => $agentId,
            'action' => 'agent.self_registered',
            'entity_type' => 'agent',
            'entity_id' => $agentId,
            'ip_address' => $ip,
            'metadata' => ['device_type' => $deviceType],
        ]);

        RateLimiter::record(self::ENDPOINT, $nin, $ip, true);

        return Response::json([
            'status' => 'PENDING',
            'agent' => [
                'id' => $agentId,
                'agent_code' => $agentCode,
                'full_name' => $fullName,
                'role' => 'AGENT',
            ],
        ], 201);
    }
}
