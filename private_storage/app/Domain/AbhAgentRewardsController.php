<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAgentRewardsRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET /api/v1/abh/agents/rewards.php — authenticated agent's own rewards only. */
final class AbhAgentRewardsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $agent = $request->requireAuth();
        return Response::json([
            'data' => AbhAgentRewardsRepository::listForAgent($agent->agentId()),
        ]);
    }
}
