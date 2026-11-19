<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminPeriodRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/admin/period-close.php */
final class AbhAdminPeriodCloseController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('periods.close');

        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['period_key'], 'period close');
        $periodMonth = Validator::periodMonth($body['period_key'] ?? null, 'period_key', true);
        $periodKey = substr($periodMonth, 0, 7);

        $result = AbhAdminPeriodRepository::close($admin->id(), $periodKey);
        $error = $result['error'] ?? null;

        if ($error === 'not_found') {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'The reporting month does not exist.');
        }
        if ($error === 'already_closed') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'This reporting month is already closed.');
        }
        if ($error === 'period_not_ended') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'A reporting month can only be finalised after its last calendar day.');
        }
        if ($error === 'pending_reviews') {
            throw new ApiException(
                409,
                ErrorCode::STATE_CONFLICT,
                'Review all ' . (int) $result['pending_count'] . ' pending or correction-requested submission(s) before closing this month.'
            );
        }
        if ($error === 'totals_mismatch') {
            throw new ApiException(
                409,
                ErrorCode::STATE_CONFLICT,
                'The approved submission ledger does not match the monthly totals. Reconcile the totals before closing this month.'
            );
        }

        return Response::json(['data' => $result]);
    }
}
