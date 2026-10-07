<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\ReviewRepository;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Http\Request;
use FieldPulse\Http\Response;
use FieldPulse\Support\Logger;

/**
 * Supervisor review endpoints (§13).
 *
 *   GET  /api/v1/reviews          the queue
 *   POST /api/v1/reviews/decide   record a decision
 *
 * Both routes are gated by Http\Kernel's `operator` requirement, which refuses
 * anything that is not a SUPERVISOR or ADMIN. That check lives in the kernel
 * rather than here on purpose: a controller that forgot its own role check would
 * still be protected, and the requirement is visible in one table.
 *
 * The decision itself is a signed-writer's problem, not an authorisation
 * problem: whoever holds a supervisor token is trusted, and the audit row plus
 * the immutable-verdict constraint are what make that trust accountable.
 */
final class ReviewController implements ActionInterface
{
    private const ALLOWED_DECISIONS = [
        ReviewRepository::DECISION_APPROVE,
        ReviewRepository::DECISION_REJECT,
    ];

    public function __construct(
        private readonly ReviewRepository $reviews = new ReviewRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return $request->endpoint() === 'decide'
            ? $this->decide($request)
            : $this->index($request);
    }

    private function index(Request $request): Response
    {
        $context = $request->requireAuth();

        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $limit  = $this->clamp($request->queryInt('limit'), 25, 1, 100);
        $offset = $this->clamp($request->queryInt('offset'), 0, 0, 10000);

        $reason = $request->query('reason');
        $agent  = $request->query('agent_code');

        $queue = $this->reviews->queue($limit, $offset, $reason, $agent);

        return Response::json([
            'data' => array_map(fn (array $row): array => $this->present($row), $queue['items']),
            'meta' => [
                'pagination' => [
                    'total'  => $queue['total'],
                    'limit'  => $limit,
                    'offset' => $offset,
                ],
                'reasons'    => $this->reviews->reasons(),
                'requested_by' => $context->agentCode(),
            ],
        ]);
    }

    private function decide(Request $request): Response
    {
        $context = $request->requireAuth();

        if ($request->method() !== 'POST') {
            throw new ApiException(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method not allowed.');
        }

        $body = $request->json();

        Validator::assertNoUnknownKeys($body, ['submission_id', 'decision', 'note'], 'review');

        $submissionId = Validator::intRange($body['submission_id'] ?? null, 1, PHP_INT_MAX, 'submission_id');

        $decision = Validator::string($body['decision'] ?? null, 4, 20, 'decision');
        $decision = strtoupper($decision);

        if (!in_array($decision, self::ALLOWED_DECISIONS, true)) {
            throw ApiException::validation(
                'decision must be APPROVE or REJECT.',
                ['field' => 'decision', 'allowed' => self::ALLOWED_DECISIONS]
            );
        }

        // A note is mandatory. A reviewer who overturns a machine decision
        // without saying why produces an audit trail that cannot answer the only
        // question anyone will later ask of it.
        $note = Validator::string($body['note'] ?? null, 5, 1000, 'note');

        $result = $this->reviews->decide($submissionId, $decision, $context->agentId(), $note);
        $submission = $result['submission'] ?? [];

        $this->audit->recordSafe([
            'actor_agent_id' => $context->agentId(),
            'action'         => 'review.' . strtolower($decision),
            'entity_type'    => 'submission',
            'entity_id'      => $submissionId,
            'ip_address'     => $request->clientIp(),
            'metadata'       => [
                'submission_uuid' => $submission['submission_uuid'] ?? null,
                'agent_code'      => null,
                'note'            => $note,
            ],
        ]);

        Logger::info('review.decided', [
            'submission_id' => $submissionId,
            'reviewer'      => $context->agentCode(),
            'decision'      => $decision,
        ]);

        return Response::json([
            'data' => [
                'submission_id' => $submissionId,
                'status'        => $decision === ReviewRepository::DECISION_APPROVE
                    ? 'VERIFIED'
                    : 'REJECTED',
                'reviewed_by'   => $context->agentCode(),
                'reviewed_at'   => \FieldPulse\Support\Clock::sql(),
            ],
        ]);
    }

    /**
     * Shape a queue row for the supervisor UI.
     *
     * The evidence_json blob is passed through as decoded structure rather than
     * a string, so the PWA does not have to parse JSON to render a reason. It is
     * decoded defensively: a row written by a future version with a different
     * shape must not blank out the entire queue.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $evidence = null;

        if (is_string($row['evidence_json'] ?? null)) {
            $decoded = json_decode((string) $row['evidence_json'], true);
            $evidence = is_array($decoded) ? $decoded : null;
        }

        return [
            'submission_id'    => (int) $row['id'],
            'submission_uuid'  => (string) $row['submission_uuid'],
            'agent'            => [
                'agent_code' => (string) $row['agent_code'],
                'full_name'  => (string) $row['full_name'],
            ],
            'device'           => [
                'device_uuid' => $row['device_uuid'] ?? null,
                'imei'        => $row['imei'] ?? null,
            ],
            'count_claimed'    => (int) $row['count_claimed'],
            'received_at'      => (string) $row['server_received_at'],
            'image'            => [
                'width'  => (int) $row['image_width'],
                'height' => (int) $row['image_height'],
                'path'   => (string) $row['file_path'],
            ],
            'position'         => [
                'latitude'  => $row['client_latitude'] === null ? null : (float) $row['client_latitude'],
                'longitude' => $row['client_longitude'] === null ? null : (float) $row['client_longitude'],
            ],
            'checks'           => [
                'auth'                => (string) $row['auth_status'],
                'device'              => (string) $row['device_status'],
                'client_gps'          => (string) $row['client_gps_status'],
                'exif'                => (string) $row['exif_status'],
                'timestamp'           => (string) $row['timestamp_status'],
                'geofence'            => (string) $row['geofence_status'],
                'exact_duplicate'     => (string) $row['exact_duplicate_status'],
                'perceptual_duplicate' => (string) $row['perceptual_duplicate_status'],
            ],
            'review_reason'    => $row['review_reason'] ?? null,
            'evidence'         => $evidence,
            'final_disposition' => $row['final_disposition'] ?? null,
            'already_reviewed' => ($row['reviewed_at'] ?? null) !== null,
            'reviewed_at'      => $row['reviewed_at'] ?? null,
            'review_note'      => $row['review_note'] ?? null,
        ];
    }

    private function clamp(?int $value, int $default, int $min, int $max): int
    {
        return $value === null ? $default : max($min, min($max, $value));
    }
}
