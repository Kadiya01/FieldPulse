<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminRewardQualificationRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** GET preview / POST evaluate for a single ABH reward programme. */
final class AbhAdminRewardQualificationController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('rewards.manage');

        if ($request->method() === 'GET') {
            $programId = Validator::intRange($request->query('program_id'), 1, 2147483647, 'program_id');
            $result = AbhAdminRewardQualificationRepository::preview($programId);
            self::assertResult($result);
            return Response::json(['data' => $result]);
        }
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['action', 'program_id'], 'reward qualification evaluation');
        if (($body['action'] ?? null) !== 'EVALUATE') {
            throw ApiException::validation('action must be EVALUATE.', ['field' => 'action']);
        }
        $programId = Validator::intRange($body['program_id'] ?? null, 1, 2147483647, 'program_id');
        $result = AbhAdminRewardQualificationRepository::evaluate($admin->id(), $programId);
        self::assertResult($result);
        return Response::json(['data' => $result]);
    }

    /** @param array<string,mixed> $result */
    private static function assertResult(array $result): void
    {
        $error = $result['error'] ?? null;
        if ($error === 'program_not_found') {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Reward programme not found.');
        }
        if ($error === 'period_not_closed') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Close the reporting period before previewing or evaluating reward qualifications.');
        }
        if ($error === 'program_not_active') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Activate the reward programme before qualification preview or evaluation.');
        }
        if ($error === 'rules_required') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'The active reward programme has no active eligibility rules.');
        }
        if ($error === 'snapshot_empty') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'The closed reporting period has no frozen leaderboard entries to evaluate.');
        }
        if ($error === 'qualification_snapshot_incomplete') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'Existing qualification records do not match the frozen leaderboard. Manual reconciliation is required.');
        }
    }
}
