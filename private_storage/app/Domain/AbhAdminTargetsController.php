<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminTargetRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET/POST /api/v1/abh/admin/targets.php */
final class AbhAdminTargetsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();

        if ($request->method() === 'GET') {
            if (!$admin->hasPermission('targets.manage') && !$admin->hasPermission('periods.close')) {
                $admin->requirePermission('targets.manage');
            }
            $month = Validator::periodMonth(
                $request->query('month') ?? (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Lagos')))->format('Y-m'),
                'month',
                true
            );
            return Response::json([
                'data' => AbhAdminTargetRepository::getForMonth(substr($month, 0, 7)),
            ]);
        }

        if ($request->method() === 'POST') {
            $admin->requirePermission('targets.manage');
            $body = $request->json();
            Validator::assertNoUnknownKeys(
                $body,
                ['period_key', 'scope_type', 'agent_id', 'target_quantity'],
                'monthly target'
            );

            $month = substr(Validator::periodMonth($body['period_key'] ?? null, 'period_key', true), 0, 7);
            $scopeType = strtoupper(Validator::string($body['scope_type'] ?? null, 5, 16, 'scope_type'));
            if (!in_array($scopeType, ['ORGANISATION', 'AGENT'], true)) {
                throw ApiException::validation('Scope must be ORGANISATION or AGENT.', ['field' => 'scope_type']);
            }

            $agentId = null;
            if ($scopeType === 'AGENT') {
                $agentId = Validator::intRange($body['agent_id'] ?? null, 1, 2147483647, 'agent_id');
            } elseif (array_key_exists('agent_id', $body) && $body['agent_id'] !== null) {
                throw ApiException::validation('An organisation target cannot name an agent.', ['field' => 'agent_id']);
            }

            $quantity = Validator::intRange($body['target_quantity'] ?? null, 1, 1000000000, 'target_quantity');
            $result = AbhAdminTargetRepository::save($admin->id(), $month, $scopeType, $agentId, $quantity);

            if (($result['error'] ?? null) === 'period_closed') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Targets cannot be changed after this month is closed.');
            }
            if (($result['error'] ?? null) === 'agent_not_active') {
                throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Only an active approved agent can receive an individual target.');
            }

            return Response::json(['data' => $result], 200);
        }

        throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
    }
}
