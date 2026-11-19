<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminAgentRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET /api/v1/abh/admin/agents.php */
final class AbhAdminAgentsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('agents.read');

        $status = strtoupper((string) ($request->query('status', 'PENDING') ?? 'PENDING'));
        if (!in_array($status, ['ALL', 'PENDING', 'ACTIVE', 'REJECTED', 'SUSPENDED'], true)) {
            throw ApiException::validation(
                'Status must be ALL, PENDING, ACTIVE, REJECTED or SUSPENDED.',
                ['field' => 'status']
            );
        }

        $search = trim((string) ($request->query('q', '') ?? ''));
        if (mb_strlen($search) > 100) {
            throw ApiException::validation('Search text is too long.', ['field' => 'q']);
        }

        $limit = $request->queryInt('limit', 25) ?? 25;
        $offset = $request->queryInt('offset', 0) ?? 0;
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw ApiException::validation('Invalid pagination values.', ['field' => 'limit']);
        }

        return Response::json(AbhAdminAgentRepository::listAgents($status, $search, $limit, $offset));
    }
}
