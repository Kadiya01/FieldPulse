<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;

/**
 * GET /api/v1/submission.php?uuid=<submission_uuid>
 *
 * The status read for a submission the caller already owns. `POST /submit`
 * returns 202 because the work is asynchronous, so the client needs somewhere to
 * discover the outcome — without it, a PWA can only poll the leaderboard, which
 * tells an agent their total but not what happened to the photo they just took.
 *
 * Bearer, not device-signed: this is a read, there is no state to protect, and
 * the response is not a credential. Same reasoning as the leaderboard.
 *
 * Two access rules, in this order:
 *
 *   1. The submission must belong to the calling agent. A UUID that exists but
 *      belongs to someone else is reported as 404 UNKNOWN_SUBMISSION, not 403.
 *      A 403 would confirm the UUID is real, which turns this endpoint into an
 *      oracle for guessing other agents' submission identifiers.
 *   2. Otherwise the full record is returned to its owner.
 */
final class SubmissionStatusController implements ActionInterface
{
    public function __construct(
        private readonly SubmissionRepository $submissions = new SubmissionRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $uuid = Validator::uuid($request->query('uuid'), 'uuid');
        $row  = $this->submissions->findByUuid($uuid);

        // Deliberately one 404 for "does not exist" and "belongs to someone else".
        if ($row === null || (int) $row['agent_id'] !== $context->agentId()) {
            throw new ApiException(
                404,
                ErrorCode::UNKNOWN_SUBMISSION,
                'No such submission.'
            );
        }

        // Null while the job is still queued or running. The absence of a
        // verdict is itself the answer, so it maps to null rather than to a
        // misleading "VERIFIED".
        $verification = $this->submissions->findVerification((int) $row['id']);
        $status       = (string) $row['status'];

        $data = [
            'submission_uuid' => (string) $row['submission_uuid'],
            'status'          => $status,
            'count_claimed'   => (int) $row['count_claimed'],
            'received_at'     => (string) $row['server_received_at'],
            'captured_at'     => $row['client_captured_at'] ?? null,
            'verified_at'     => $row['verified_at'] ?? null,
            'counted'         => $status === SubmissionRepository::VERIFIED,
            'pending'         => in_array(
                $status,
                [SubmissionRepository::RECEIVED, SubmissionRepository::QUEUED, SubmissionRepository::PROCESSING],
                true
            ),
        ];

        if ($verification !== null) {
            $evidence = $this->decodeEvidence($verification['evidence_json'] ?? null);

            $data['disposition']         = (string) $verification['final_disposition'];
            $data['reason']              = $verification['review_reason'] ?? null;
            $data['reasons']             = $evidence['reasons'] ?? [];
            $data['awaiting_review']     = $verification['final_disposition'] === 'REQUIRES_REVIEW'
                && $verification['reviewed_at'] === null;
            $data['reviewed_at']         = $verification['reviewed_at'] ?? null;
            $data['verification_version'] = $verification['verification_version'] ?? null;
        }

        return Response::json(['data' => $data]);
    }

    /**
     * The reason list lives inside evidence_json rather than in a column of its
     * own, so it is decoded defensively. A malformed value must not take down
     * the status endpoint — an agent asking "did my photo go through" is owed an
     * answer even if the detail is unreadable.
     *
     * @return array<string,mixed>
     */
    private function decodeEvidence(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
