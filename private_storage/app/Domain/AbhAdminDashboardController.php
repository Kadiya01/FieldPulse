<?php

declare(strict_types=1);

namespace FieldPulse\\Domain;

use FieldPulse\\Database\\AbhAdminDashboardRepository;
use FieldPulse\\Http\\ApiException;
use FieldPulse\\Http\\ErrorCode;
use FieldPulse\\Http\\Request;
use FieldPulse\\Http\\Response;

/** GET /api/v1/abh/admin/dashboard.php */
final class AbhAdminDashboardController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'GET') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $permissions = $admin->permissions();
        $allowed = [
            'agents.read',
            'submissions.read',
            'targets.manage',
            'periods.close',
            'rewards.manage',
            'rewards.approve',
        ];
        if (array_intersect($allowed, $permissions) === []) {
            throw new ApiException(403, ErrorCode::FORBIDDEN, 'You do not have permission to view dashboard summaries.');
        }

        $month = Validator::periodMonth(
            $request->query('month') ?? (new \\DateTimeImmutable('now', new \\DateTimeZone('Africa/Lagos')))->format('Y-m'),
            'month',
            true
        );

        return Response::json([
            'data' => AbhAdminDashboardRepository::overview(substr($month, 0, 7), $permissions),
        ]);
    }
}
