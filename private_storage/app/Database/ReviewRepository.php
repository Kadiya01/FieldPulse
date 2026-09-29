<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Supervisor review-queue access (§13).
 *
 * Reviews are the only place a human overturns an automated decision, so two
 * invariants matter:
 *
 *   1. A decision is immutable. UPDATE ... WHERE reviewed_at IS NULL means two
 *      reviewers racing on the same submission produce one decision and one 409,
 *      never a silent last-write-wins that erases the first reviewer's judgement.
 *
 *      The guard is reviewed_at, not final_disposition. Migration 005 declares
 *      final_disposition NOT NULL, and VerificationService always writes a value
 *      into it, so "final_disposition IS NULL" is a predicate on a NOT NULL
 *      column: it is always false, the check 409'd every pending submission, and
 *      the conditional UPDATE matched zero rows. The whole review path was
 *      unreachable. Migration 015 documents the intended invariant explicitly:
 *      REQUIRES_REVIEW with reviewed_at IS NULL means pending.
 *
 *   2. A decision re-derives the counters. The reviewer is changing a count an
 *      agent will be paid from, so the summary is recomputed in the same
 *      transaction rather than trusted to a later cron.
 */
final class ReviewRepository extends Repository
{
    public const DECISION_APPROVE = 'APPROVE';
    public const DECISION_REJECT  = 'REJECT';

    /**
     * The review decision vocabulary -> the stored outcome vocabulary.
     *
     * Two different vocabularies by design: the API says APPROVE/REJECT because
     * that is what a human clicks, while both submissions.status and
     * submission_verifications.final_disposition are ENUMs over the outcome
     * vocabulary. Binding the decision straight into final_disposition is
     * rejected under strict mode and truncates to '' otherwise, so the mapping
     * has to happen in PHP.
     *
     * Public and static so the self-test can assert it without a database. When
     * this lived inline in the UPDATE, the mismatch was invisible to the whole
     * test suite and the review path failed on first contact with a real schema.
     */
    public const OUTCOME_FOR_DECISION = [
        self::DECISION_APPROVE => SubmissionRepository::VERIFIED,
        self::DECISION_REJECT  => SubmissionRepository::REJECTED,
    ];

    /**
     * @throws \InvalidArgumentException on an unrecognised decision
     */
    public static function outcomeFor(string $decision): string
    {
        $outcome = self::OUTCOME_FOR_DECISION[$decision] ?? null;

        if ($outcome === null) {
            throw new \InvalidArgumentException('Unknown review decision: ' . $decision);
        }

        return $outcome;
    }

    /**
     * @return array{
     *   items:list<array<string,mixed>>,
     *   total:int
     * }
     */
    public function queue(int $limit, int $offset, ?string $reason, ?string $agentCode): array
    {
        [$where, $params] = $this->filters($reason, $agentCode);

        $items = $this->all(
            'SELECT s.id, s.submission_uuid, s.count_claimed, s.status,
                    s.server_received_at, s.file_path, s.image_width, s.image_height,
                    s.client_latitude, s.client_longitude, s.verified_at,
                    v.verification_version, v.review_reason,
                    v.auth_status, v.device_status, v.client_gps_status, v.exif_status,
                    v.timestamp_status, v.geofence_status,
                    v.exact_duplicate_status, v.perceptual_duplicate_status,
                    v.evidence_json, v.final_disposition,
                    v.reviewed_by_agent_id, v.reviewed_at, v.review_note,
                    a.agent_code, a.full_name,
                    d.device_uuid, d.imei
               FROM submissions s
               LEFT JOIN submission_verifications v ON v.submission_id = s.id
               LEFT JOIN agents a ON a.id = s.agent_id
               LEFT JOIN devices d ON d.id = s.device_id
              WHERE ' . $where . '
              ORDER BY s.server_received_at ASC, s.id ASC'
            . self::limitClause($limit, 100, $offset),
            $params
        );

        $total = (int) $this->value(
            'SELECT COUNT(*)
               FROM submissions s
               LEFT JOIN submission_verifications v ON v.submission_id = s.id
               LEFT JOIN agents a ON a.id = s.agent_id
              WHERE ' . $where,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(?string $reason, ?string $agentCode): array
    {
        $where  = ['s.status = :status'];
        $params = ['status' => SubmissionRepository::REQUIRES_REVIEW];

        if ($reason !== null && $reason !== '') {
            $where[] = 'v.review_reason = :reason';
            $params['reason'] = $reason;
        }

        if ($agentCode !== null && $agentCode !== '') {
            $where[] = 'a.agent_code = :agent_code';
            $params['agent_code'] = $agentCode;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Record a review decision and apply it.
     *
     * The whole thing is one transaction: if the summary update fails, the
     * review must not be recorded, because a recorded APPROVE with an
     * un-updated count would pay an agent nothing for work a supervisor has
     * already judged good.
     *
     * @return array{applied:bool,submission:?array<string,mixed>}
     */
    public function decide(
        int $submissionId,
        string $decision,
        int $reviewerAgentId,
        string $note
    ): array {
        return Connection::transaction(function () use ($submissionId, $decision, $reviewerAgentId, $note): array {
            $submission = $this->one('SELECT * FROM submissions WHERE id = :id FOR UPDATE', ['id' => $submissionId]);

            if ($submission === null) {
                throw new \FieldPulse\Http\ApiException(
                    404,
                    \FieldPulse\Http\ErrorCode::UNKNOWN_SUBMISSION,
                    'Submission not found.'
                );
            }

            $existing = $this->one(
                'SELECT final_disposition, reviewed_by_agent_id, reviewed_at
                   FROM submission_verifications WHERE submission_id = :id',
                ['id' => $submissionId]
            );

            if ($existing !== null && $existing['reviewed_at'] !== null) {
                throw new \FieldPulse\Http\ApiException(
                    409,
                    \FieldPulse\Http\ErrorCode::STATE_CONFLICT,
                    'This submission has already been reviewed.'
                );
            }

            if ($submission['status'] !== SubmissionRepository::REQUIRES_REVIEW) {
                throw new \FieldPulse\Http\ApiException(
                    409,
                    \FieldPulse\Http\ErrorCode::STATE_CONFLICT,
                    'This submission is not awaiting review.'
                );
            }

            // The API vocabulary (APPROVE/REJECT) is not either stored vocabulary.
            // Mapped once, used for both writes, so they cannot drift apart again.
            $outcome = self::outcomeFor($decision);

            $now = \FieldPulse\Support\Clock::sql();

            $updated = $this->exec(
                'UPDATE submission_verifications
                    SET final_disposition = :disposition,
                        reviewed_by_agent_id = :reviewer,
                        reviewed_at = :now,
                        review_note = :note,
                        updated_at = :updated_now
                  WHERE submission_id = :id AND reviewed_at IS NULL',
                [
                    'disposition'  => $outcome,
                    'reviewer'     => $reviewerAgentId,
                    'now'          => $now,
                    // A second name for the same value on purpose. MySQL native
                    // prepares do not allow a named placeholder to appear twice
                    // in one statement, and this connection runs with
                    // EMULATE_PREPARES = false, so `:now` used for both columns
                    // raised HY093 and every review decision failed.
                    'updated_now'  => $now,
                    'note'         => $note,
                    'id'           => $submissionId,
                ]
            );

            if ($updated !== 1) {
                // Lost the race; the caller gets a 409 rather than a second
                // decision being written.
                throw new \FieldPulse\Http\ApiException(
                    409,
                    \FieldPulse\Http\ErrorCode::STATE_CONFLICT,
                    'This submission was reviewed by someone else a moment ago.'
                );
            }

            $this->exec(
                'UPDATE submissions
                    SET status = :status,
                        verified_at = :verified_at,
                        updated_at = :now
                  WHERE id = :id',
                [
                    'status'      => $outcome,
                    'verified_at' => $decision === self::DECISION_APPROVE ? $now : null,
                    'now'         => $now,
                    'id'          => $submissionId,
                ]
            );

            $this->refreshSummary((int) $submission['agent_id'], (string) $submission['server_received_at']);

            return ['applied' => true, 'submission' => $submission];
        });
    }

    /**
     * Recompute the affected agent's period summary after a review.
     */
    private function refreshSummary(int $agentId, string $receivedAt): void
    {
        $period = \FieldPulse\Domain\PeriodResolver::periodFor($receivedAt);

        (new LeaderboardRepository())->refresh($agentId, $period['date']);
    }

    /**
     * Distinct review reasons currently in the queue, for filter dropdowns.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        $rows = $this->all(
            'SELECT DISTINCT v.review_reason
               FROM submission_verifications v
               JOIN submissions s ON s.id = v.submission_id
              WHERE s.status = :status AND v.review_reason IS NOT NULL
              ORDER BY v.review_reason',
            ['status' => SubmissionRepository::REQUIRES_REVIEW]
        );

        return array_map(static fn (array $r): string => (string) $r['review_reason'], $rows);
    }
}
