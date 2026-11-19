<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAgentRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/admin/devices/decision.php */
final class AbhAdminDeviceDecisionController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['device_id', 'decision', 'reason'], 'device decision');

        $deviceId = filter_var($body['device_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($deviceId) || $deviceId < 1) {
            throw ApiException::validation('A valid device_id is required.', ['field' => 'device_id']);
        }

        $decision = strtoupper(Validator::string($body['decision'] ?? null, 6, 12, 'decision'));
        $permission = match ($decision) {
            'APPROVE' => 'devices.approve',
            'REVOKE' => 'devices.revoke',
            default => throw ApiException::validation('Decision must be APPROVE or REVOKE.', ['field' => 'decision']),
        };
        $admin->requirePermission($permission);

        $reason = null;
        if (isset($body['reason'])) {
            $reason = trim(Validator::string($body['reason'], 2, 2000, 'reason'));
        }
        if ($decision === 'REVOKE' && ($reason === null || $reason === '')) {
            throw ApiException::validation('A reason is required when revoking a device.', ['field' => 'reason']);
        }

        $result = AbhAdminAgentRepository::decideDevice($admin->id(), $deviceId, $decision, $reason);

        return Response::json(['updated' => true, 'device' => $result]);
    }
}
