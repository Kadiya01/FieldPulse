<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET  /api/v1/profile.php   the caller's profile
 * POST /api/v1/profile.php   update email, location and phone
 *
 * Signed, not merely bearer: a profile write is a state change, and the device
 * key is the second factor that already protects every other write. GET uses the
 * same route, so it is signed too; that is stricter than necessary for a read
 * and keeps one route declaration with one auth rule.
 *
 * Identity fields (NIN, IMEI, role, status, monthly target) are not writable
 * here. A profile form must not be able to promote an account or change the
 * number it is measured against.
 */
final class ProfileController implements ActionInterface
{
    public function __construct(
        private readonly AgentRepository $agents = new AgentRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $request->requireAuth();
        $agent   = $this->agents->findById($context->agentId());

        if ($agent === null) {
            throw ApiException::notFound(ErrorCode::UNKNOWN_AGENT, 'Account not found.');
        }

        if ($request->method() === 'POST') {
            return $this->update($request, $agent);
        }

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        return Response::json(['data' => $this->present($agent)]);
    }

    /** @param array<string,mixed> $agent */
    private function update(Request $request, array $agent): Response
    {
        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['email', 'location', 'phone'], 'profile.update');

        $email = array_key_exists('email', $body)
            ? Validator::email($body['email'])
            : ($agent['email'] ?? null);

        $location = array_key_exists('location', $body)
            ? Validator::location($body['location'])
            : ($agent['location'] ?? null);

        $phone = array_key_exists('phone', $body)
            ? Validator::nigerianPhone($body['phone'])
            : (string) ($agent['phone'] ?? '');

        if ($phone === '') {
            throw ApiException::validation('Enter a Nigerian mobile phone number.', ['field' => 'phone']);
        }

        if ($email !== null && (string) ($agent['email'] ?? '') !== $email) {
            $existing = $this->agents->findByEmail($email);

            if ($existing !== null && (int) $existing['id'] !== (int) $agent['id']) {
                throw ApiException::conflict(
                    ErrorCode::STATE_CONFLICT,
                    'This email address is already in use.'
                );
            }
        }

        $this->agents->updateProfile((int) $agent['id'], $email, $location, $phone);

        $this->audit->recordSafe([
            'actor_agent_id' => (int) $agent['id'],
            'action'         => 'agent.profile_updated',
            'entity_type'    => 'agent',
            'entity_id'      => (int) $agent['id'],
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'email_changed'    => (string) ($agent['email'] ?? '') !== (string) ($email ?? ''),
                'location_changed' => (string) ($agent['location'] ?? '') !== (string) ($location ?? ''),
                'phone_changed'    => (string) ($agent['phone'] ?? '') !== $phone,
            ],
        ]);

        $fresh = $this->agents->findById((int) $agent['id']);

        return Response::json(['data' => $this->present($fresh ?? $agent)]);
    }

    /** @param array<string,mixed> $agent @return array<string,mixed> */
    private function present(array $agent): array
    {
        return [
            'id'             => (int) $agent['id'],
            'agent_code'     => (string) $agent['agent_code'],
            'full_name'      => (string) $agent['full_name'],
            'nin'            => $agent['nin'] ?? null,
            'phone'          => $agent['phone'] ?? null,
            'email'          => $agent['email'] ?? null,
            'location'       => $agent['location'] ?? null,
            'imei'           => $agent['imei'] ?? null,
            'device_type'    => $agent['device_type'] ?? null,
            'role'           => (string) ($agent['role'] ?? 'AGENT'),
            'status'         => (string) $agent['status'],
            'monthly_target' => (int) ($agent['monthly_target'] ?? 0),
            'created_at'     => (string) $agent['created_at'],
        ];
    }
}
