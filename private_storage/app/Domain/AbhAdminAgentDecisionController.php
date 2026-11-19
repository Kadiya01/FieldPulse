<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAgentRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/admin/agents/decision.php */
final class AbhAdminAgentDecisionController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['agent_id', 'decision', 'reason'], 'agent decision');

        $agentId = filter_var($body['agent_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($agentId) || $agentId < 1) {
            throw ApiException::validation('A valid agent_id is required.', ['field' => 'agent_id']);
        }

        $decision = strtoupper(Validator::string($body['decision'] ?? null, 6, 12, 'decision'));
        $permission = match ($decision) {
            'APPROVE' => 'agents.approve',
            'REJECT' => 'agents.reject',
            'SUSPEND', 'REACTIVATE' => 'agents.suspend',
            default => throw ApiException::validation('Decision must be APPROVE, REJECT, SUSPEND or REACTIVATE.', ['field' => 'decision']),
        };
        $admin->requirePermission($permission);

        $reason = null;
        if (isset($body['reason'])) {
            $reason = trim(Validator::string($body['reason'], 2, 500, 'reason'));
        }
        if (in_array($decision, ['REJECT', 'SUSPEND'], true) && ($reason === null || $reason === '')) {
            throw ApiException::validation('A reason is required for rejection or suspension.', ['field' => 'reason']);
        }

        $result = AbhAdminAgentRepository::decideAgent($admin->id(), $agentId, $decision, $reason);

        return Response::json(['updated' => true, 'agent' => $result]);
    }
}
