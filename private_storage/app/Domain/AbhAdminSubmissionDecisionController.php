<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AbhAdminSubmissionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/** POST /api/v1/abh/admin/submission-decision.php */
final class AbhAdminSubmissionDecisionController implements ActionInterface
{
    public function __invoke(Request $request): Response
    {
        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $admin = $request->requireAbhAdminContext();
        $admin->requirePermission('submissions.review');
        $body = $request->json();
        Validator::assertNoUnknownKeys($body, ['submission_id', 'decision', 'reason', 'idempotency_key'], 'submission decision');

        $submissionId = filter_var($body['submission_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($submissionId) || $submissionId < 1) {
            throw ApiException::validation('A valid submission_id is required.', ['field' => 'submission_id']);
        }

        $decision = strtoupper(Validator::string($body['decision'] ?? null, 6, 24, 'decision'));
        if (!in_array($decision, ['APPROVE', 'REJECT', 'REQUEST_CORRECTION'], true)) {
            throw ApiException::validation('Decision must be APPROVE, REJECT or REQUEST_CORRECTION.', ['field' => 'decision']);
        }

        $reason = null;
        if (isset($body['reason']) && $body['reason'] !== '') {
            $reason = trim(Validator::string($body['reason'], 2, 2000, 'reason'));
            if ($reason === '') {
                $reason = null;
            }
        }
        if ($decision !== 'APPROVE' && ($reason === null || mb_strlen($reason) < 2)) {
            throw ApiException::validation('A reason is required for rejection or correction requests.', ['field' => 'reason']);
        }

        $idempotencyKey = Validator::uuid($body['idempotency_key'] ?? null, 'idempotency_key');
        $result = AbhAdminSubmissionRepository::decide(
            $admin->id(),
            $submissionId,
            $decision,
            $reason,
            $idempotencyKey
        );

        return Response::json(['data' => $result]);
    }
}
