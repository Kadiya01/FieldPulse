<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AuditRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET /api/v1/admin/audit.php
 *
 * Paged, read-only audit history. The audit table is append-only; this
 * endpoint cannot edit or delete a row. Filters are exact matches so a search
 * cannot accidentally hide the action the administrator is looking for behind
 * a LIKE pattern.
 */
final class AdminAuditController implements ActionInterface
{
    public function __construct(
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $limit = $request->queryInt('limit', 50) ?? 50;
        $limit = max(1, min($limit, 200));

        $offset = $request->queryInt('offset', 0) ?? 0;
        $offset = max(0, $offset);

        $filters = [];

        $action = $request->query('action');
        if ($action !== null && trim($action) !== '') {
            $filters['action'] = Validator::string($action, 1, 64, 'action');
        }

        $actor = $request->query('actor_agent_id');
        if ($actor !== null && $actor !== '') {
            $filters['actor_agent_id'] = Validator::intRange($actor, 1, PHP_INT_MAX, 'actor_agent_id');
        }

        $entityType = $request->query('entity_type');
        if ($entityType !== null && trim($entityType) !== '') {
            $filters['entity_type'] = Validator::string($entityType, 1, 64, 'entity_type');
        }

        $entityId = $request->query('entity_id');
        if ($entityId !== null && $entityId !== '') {
            $filters['entity_id'] = Validator::intRange($entityId, 1, PHP_INT_MAX, 'entity_id');
        }

        $rows  = $this->audit->adminSearch($filters, $limit, $offset);
        $total = $this->audit->countAdminSearch($filters);

        return Response::json([
            'data' => AdminPresenter::auditRows($rows),
            'meta' => [
                'pagination' => [
                    'total'  => $total,
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
                'filters' => $filters,
            ],
        ]);
    }
}
