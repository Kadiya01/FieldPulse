<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminSubmissionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET /api/v1/abh/admin/submissions.php */
final class AbhAdminSubmissionsController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('submissions.read');

        $status = strtoupper((string) ($request->query('status', 'PENDING_REVIEW') ?? 'PENDING_REVIEW'));
        if (!in_array($status, ['PENDING_REVIEW', 'CORRECTION_REQUESTED', 'APPROVED', 'REJECTED', 'ALL'], true)) {
            throw ApiException::validation('Unsupported submission status filter.', ['field' => 'status']);
        }

        $month = $request->query('month');
        $periodKey = null;
        if ($month !== null && $month !== '') {
            $periodKey = substr(Validator::periodMonth($month, 'month', true), 0, 7);
        }

        $limit = $request->queryInt('limit', 50) ?? 50;
        $offset = $request->queryInt('offset', 0) ?? 0;
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            throw ApiException::validation('Invalid pagination values.', ['field' => 'limit']);
        }

        return Response::json(AbhAdminSubmissionRepository::list($status, $periodKey, $limit, $offset));
    }
}
