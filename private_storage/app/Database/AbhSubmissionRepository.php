<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Uuid;

/**
 * ABH monthly enrollment submissions. This repository is deliberately isolated
 * from the legacy FieldPulse submission ledger.
 */
final class AbhSubmissionRepository
{
    private function __construct()
    {
    }

    /** @return array<string,mixed>|null */
    public static function findByIdempotencyKey(int $agentId, string $key): ?array
    {
        return Connection::fetchOne(
            'SELECT s.id, s.reference_no, s.status, s.submitted_at,
                    p.period_key, r.claimed_quantity, r.activity_date, r.agent_note,
                    e.sha256 AS evidence_sha256
               FROM abh_enrollment_submissions s
               INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
               LEFT JOIN abh_submission_revisions r ON r.id = s.current_revision_id
               LEFT JOIN abh_submission_evidence e ON e.revision_id = r.id
              WHERE s.agent_id = :agent_id AND s.idempotency_key = :idempotency_key
              LIMIT 1',
            ['agent_id' => $agentId, 'idempotency_key' => $key]
        );
    }

    /**
     * Persist the submission, first immutable revision, evidence metadata,
     * audit event and reviewer notifications in one database transaction.
     *
     * @return array<string,mixed>
     */
    public static function create(
        int $agentId,
        string $periodKey,
        string $idempotencyKey,
        string $activityDate,
        int $quantity,
        ?string $note,
        string $storageKey,
        string $originalFilename,
        string $mime,
        int $byteSize,
        string $sha256
    ): array {
        return Connection::transaction(
            static function (\PDO $pdo) use (
                $agentId,
                $periodKey,
                $idempotencyKey,
                $activityDate,
                $quantity,
                $note,
                $storageKey,
                $originalFilename,
                $mime,
                $byteSize,
                $sha256
            ): array {
                // Lock the reporting period while accepting the report. A
                // concurrent month-close cannot close it between this check
                // and the submission commit.
                $periodStmt = $pdo->prepare(
                    'SELECT id, starts_on, ends_on, status
                       FROM abh_reporting_periods
                      WHERE period_key = :period_key
                      LIMIT 1 FOR UPDATE'
                );
                $periodStmt->execute(['period_key' => $periodKey]);
                $period = $periodStmt->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($period) || (string) $period['status'] !== 'OPEN') {
                    return ['error' => 'period_not_open'];
                }

                if ($activityDate < (string) $period['starts_on'] || $activityDate > (string) $period['ends_on']) {
                    return ['error' => 'date_outside_period'];
                }

                $reference = 'ABH-ENR-' . str_replace('-', '', $periodKey) . '-' . strtoupper(bin2hex(random_bytes(4)));

                $insertSubmission = $pdo->prepare(
                    'INSERT INTO abh_enrollment_submissions
                     (reference_no, agent_id, reporting_period_id, current_revision_id,
                      status, idempotency_key, submitted_at, created_at, updated_at)
                     VALUES
                     (:reference_no, :agent_id, :period_id, NULL,
                      "PENDING_REVIEW", :idempotency_key, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
                );
                $insertSubmission->execute([
                    'reference_no' => $reference,
                    'agent_id' => $agentId,
                    'period_id' => (int) $period['id'],
                    'idempotency_key' => $idempotencyKey,
                ]);
                $submissionId = (int) $pdo->lastInsertId();

                $insertRevision = $pdo->prepare(
                    'INSERT INTO abh_submission_revisions
                     (submission_id, revision_no, claimed_quantity, activity_date,
                      agent_note, created_by_agent_id, created_at)
                     VALUES
                     (:submission_id, 1, :quantity, :activity_date,
                      :agent_note, :agent_id, UTC_TIMESTAMP(6))'
                );
                $insertRevision->execute([
                    'submission_id' => $submissionId,
                    'quantity' => $quantity,
                    'activity_date' => $activityDate,
                    'agent_note' => $note,
                    'agent_id' => $agentId,
                ]);
                $revisionId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'UPDATE abh_enrollment_submissions
                        SET current_revision_id = :revision_id, updated_at = UTC_TIMESTAMP(6)
                      WHERE id = :submission_id AND agent_id = :agent_id'
                )->execute([
                    'revision_id' => $revisionId,
                    'submission_id' => $submissionId,
                    'agent_id' => $agentId,
                ]);

                $pdo->prepare(
                    'INSERT INTO abh_submission_evidence
                     (revision_id, storage_key, original_filename, mime_type,
                      byte_size, sha256, uploaded_at)
                     VALUES
                     (:revision_id, :storage_key, :filename, :mime,
                      :byte_size, :sha256, UTC_TIMESTAMP(6))'
                )->execute([
                    'revision_id' => $revisionId,
                    'storage_key' => $storageKey,
                    'filename' => $originalFilename,
                    'mime' => $mime,
                    'byte_size' => $byteSize,
                    'sha256' => $sha256,
                ]);

                $after = json_encode([
                    'reference_no' => $reference,
                    'period_key' => $periodKey,
                    'claimed_quantity' => $quantity,
                    'activity_date' => $activityDate,
                    'evidence_sha256' => $sha256,
                    'status' => 'PENDING_REVIEW',
                ], JSON_THROW_ON_ERROR);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_agent_id, action_key, entity_type, entity_id, after_json, created_at)
                     VALUES ("AGENT", :agent_id, "submission.created", "enrollment_submission",
                             :submission_id, :after_json, UTC_TIMESTAMP(6))'
                )->execute([
                    'agent_id' => $agentId,
                    'submission_id' => $submissionId,
                    'after_json' => $after,
                ]);

                // Notify only active administrators who currently hold the
                // submission-review permission. DISTINCT avoids one message
                // per role when an administrator has several role assignments.
                $notify = $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_admin_user_id, event_key, title, message, created_at)
                     SELECT DISTINCT "ADMIN", u.id, "submission.received",
                            "New enrollment submission",
                            :message, UTC_TIMESTAMP(6)
                       FROM abh_admin_users u
                       INNER JOIN abh_admin_user_roles ur ON ur.admin_user_id = u.id
                       INNER JOIN abh_admin_role_permissions rp ON rp.role_id = ur.role_id
                       INNER JOIN abh_admin_permissions p ON p.id = rp.permission_id
                      WHERE u.status = "ACTIVE"
                        AND p.permission_key = "submissions.review"'
                );
                $notify->execute([
                    'message' => 'Submission ' . $reference . ' is awaiting review.',
                ]);

                return [
                    'id' => $submissionId,
                    'reference_no' => $reference,
                    'status' => 'PENDING_REVIEW',
                    'period_key' => $periodKey,
                    'activity_date' => $activityDate,
                    'claimed_quantity' => $quantity,
                    'submitted_at' => gmdate('Y-m-d H:i:s'),
                    'revision_no' => 1,
                ];
            }
        );
    }

    /**
     * Calculate complete period totals independently of history pagination.
     * Closed periods prefer frozen leaderboard snapshots; open periods rank
     * directly from approved submissions.
     *
     * @return array{
     *   period_status:?string,submitted_count:int,approved_quantity:int,
     *   pending_count:int,rejected_count:int,target_quantity:?int,
     *   progress_percent:?float,leaderboard_rank:?int
     * }
     */
    public static function summaryForAgent(int $agentId, string $periodKey): array
    {
        $period = Connection::fetchOne(
            'SELECT id, status FROM abh_reporting_periods
              WHERE period_key = :period_key
              LIMIT 1',
            ['period_key' => $periodKey]
        );

        if ($period === null) {
            return [
                'period_status' => null,
                'submitted_count' => 0,
                'approved_quantity' => 0,
                'pending_count' => 0,
                'rejected_count' => 0,
                'target_quantity' => null,
                'progress_percent' => null,
                'leaderboard_rank' => null,
            ];
        }

        $periodId = (int) $period['id'];
        $counts = Connection::fetchOne(
            'SELECT
                COUNT(*) AS submitted_count,
                COALESCE(SUM(CASE WHEN s.status = "APPROVED" THEN r.claimed_quantity ELSE 0 END), 0) AS approved_quantity,
                COALESCE(SUM(CASE WHEN s.status = "PENDING_REVIEW" THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN s.status = "REJECTED" THEN 1 ELSE 0 END), 0) AS rejected_count
               FROM abh_enrollment_submissions s
               LEFT JOIN abh_submission_revisions r ON r.id = s.current_revision_id
              WHERE s.agent_id = :agent_id AND s.reporting_period_id = :period_id',
            ['agent_id' => $agentId, 'period_id' => $periodId]
        ) ?? [];

        $approvedQuantity = (int) ($counts['approved_quantity'] ?? 0);
        $targetRow = Connection::fetchOne(
            'SELECT target_quantity
               FROM abh_monthly_targets
              WHERE reporting_period_id = :period_id
                AND agent_id = :agent_id
              LIMIT 1',
            ['period_id' => $periodId, 'agent_id' => $agentId]
        );
        $targetQuantity = $targetRow === null ? null : (int) $targetRow['target_quantity'];

        $rank = null;
        if ($approvedQuantity > 0) {
            if ((string) $period['status'] === 'CLOSED') {
                $snapshot = Connection::fetchOne(
                    'SELECT rank_position
                       FROM abh_monthly_leaderboard_snapshots
                      WHERE reporting_period_id = :period_id AND agent_id = :agent_id
                      LIMIT 1',
                    ['period_id' => $periodId, 'agent_id' => $agentId]
                );
                if ($snapshot !== null) {
                    $rank = (int) $snapshot['rank_position'];
                }
            }

            if ($rank === null) {
                $ahead = (int) Connection::fetchValue(
                    'SELECT COUNT(*)
                       FROM (
                            SELECT s.agent_id, SUM(r.claimed_quantity) AS approved_quantity
                              FROM abh_enrollment_submissions s
                              INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                             WHERE s.reporting_period_id = :period_id
                               AND s.status = "APPROVED"
                             GROUP BY s.agent_id
                       ) ranked
                      WHERE ranked.approved_quantity > :own_quantity
                         OR (ranked.approved_quantity = :tie_quantity AND ranked.agent_id < :agent_id)',
                    [
                        'period_id' => $periodId,
                        'own_quantity' => $approvedQuantity,
                        'tie_quantity' => $approvedQuantity,
                        'agent_id' => $agentId,
                    ]
                );
                $rank = $ahead + 1;
            }
        }

        return [
            'period_status' => (string) $period['status'],
            'submitted_count' => (int) ($counts['submitted_count'] ?? 0),
            'approved_quantity' => $approvedQuantity,
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'rejected_count' => (int) ($counts['rejected_count'] ?? 0),
            'target_quantity' => $targetQuantity,
            'progress_percent' => $targetQuantity !== null && $targetQuantity > 0
                ? round(($approvedQuantity / $targetQuantity) * 100, 2)
                : null,
            'leaderboard_rank' => $rank,
        ];
    }

    /**
     * A caller's own submission history only. Ownership is always supplied by
     * the authenticated context, never by a query or payload field.
     *
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public static function listForAgent(int $agentId, ?string $periodKey, int $limit, int $offset): array
    {
        $where = 's.agent_id = :agent_id';
        $params = ['agent_id' => $agentId];

        if ($periodKey !== null) {
            $where .= ' AND p.period_key = :period_key';
            $params['period_key'] = $periodKey;
        }

        $total = (int) Connection::fetchValue(
            'SELECT COUNT(*)
               FROM abh_enrollment_submissions s
               INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
              WHERE ' . $where,
            $params
        );

        // Both values are range-checked by the controller before interpolation.
        $items = Connection::fetchAll(
            'SELECT s.id, s.reference_no, s.status, s.submitted_at,
                    p.period_key, r.activity_date, r.claimed_quantity, r.revision_no
               FROM abh_enrollment_submissions s
               INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
               LEFT JOIN abh_submission_revisions r ON r.id = s.current_revision_id
              WHERE ' . $where . '
              ORDER BY s.submitted_at DESC, s.id DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }
}
