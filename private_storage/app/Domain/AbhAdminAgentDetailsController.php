<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAgentRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET /api/v1/abh/admin/agent.php?id=... */
final class AbhAdminAgentDetailsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('agents.read');

        $agentId = $request->queryInt('id');
        if ($agentId === null || $agentId < 1) {
            throw ApiException::validation('A valid agent id is required.', ['field' => 'id']);
        }

        $details = AbhAdminAgentRepository::agentDetails($agentId, $admin->hasPermission('devices.read'));
        if ($details === null) {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Agent not found.');
        }

        return Response::json($details);
    }
}
