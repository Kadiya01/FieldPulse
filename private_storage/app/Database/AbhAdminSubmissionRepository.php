<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\RequestId;

/**
 * ABH administrator access to monthly enrollment reports and review decisions.
 * All state changes are isolated to abh_* tables and commit atomically.
 */
final class AbhAdminSubmissionRepository
{
    private function __construct()
    {
    }

    /**
     * @return array{items:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public static function list(string $status, ?string $periodKey, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        if ($status !== 'ALL') {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($periodKey !== null) {
            $where[] = 'p.period_key = :period_key';
            $params['period_key'] = $periodKey;
        }

        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) Connection::fetchValue(
            'SELECT COUNT(*)
               FROM abh_enrollment_submissions s
               INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id' . $clause,
            $params
        );

        $items = Connection::fetchAll(
            'SELECT s.id, s.reference_no, s.status, s.submitted_at,
                    p.period_key, p.status AS period_status,
                    a.id AS agent_id, a.agent_code, a.full_name, a.phone, a.location_text,
                    r.id AS revision_id, r.revision_no, r.claimed_quantity,
                    r.activity_date, r.agent_note,
                    e.original_filename, e.mime_type, e.byte_size, e.sha256,
                    (SELECT COUNT(*) FROM abh_submission_reviews rv WHERE rv.submission_id = s.id) AS review_count
               FROM abh_enrollment_submissions s
               INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
               INNER JOIN abh_agents a ON a.id = s.agent_id
               LEFT JOIN abh_submission_revisions r ON r.id = s.current_revision_id
               LEFT JOIN abh_submission_evidence e ON e.revision_id = r.id' . $clause . '
              ORDER BY CASE s.status
                           WHEN "PENDING_REVIEW" THEN 0
                           WHEN "CORRECTION_REQUESTED" THEN 1
                           WHEN "REJECTED" THEN 2
                           WHEN "APPROVED" THEN 3
                           ELSE 4
                       END,
                       s.submitted_at ASC, s.id ASC
              LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array<string,mixed>|null */
    public static function evidence(int $submissionId): ?array
    {
        return Connection::fetchOne(
            'SELECT s.id AS submission_id, s.reference_no, s.status,
                    e.storage_key, e.original_filename, e.mime_type, e.byte_size, e.sha256
               FROM abh_enrollment_submissions s
               INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
               INNER JOIN abh_submission_evidence e ON e.revision_id = r.id
              WHERE s.id = :submission_id
              LIMIT 1',
            ['submission_id' => $submissionId]
        );
    }

    /**
     * @return array{submission_id:int,reference_no:string,status:string,decision:string,idempotent_replay:bool}
     */
    public static function decide(
        int $adminId,
        int $submissionId,
        string $decision,
        ?string $note,
        string $idempotencyKey
    ): array {
        $result = Connection::transaction(
            static function (\PDO $pdo) use ($adminId, $submissionId, $decision, $note, $idempotencyKey): array {
                $existing = $pdo->prepare(
                    'SELECT submission_id, decision, review_note
                       FROM abh_submission_reviews
                      WHERE idempotency_key = :idempotency_key
                      LIMIT 1'
                );
                $existing->execute(['idempotency_key' => $idempotencyKey]);
                $prior = $existing->fetch(\PDO::FETCH_ASSOC);
                if (is_array($prior)) {
                    $same = (int) $prior['submission_id'] === $submissionId
                        && (string) $prior['decision'] === $decision
                        && (($prior['review_note'] ?? null) === $note);
                    if (!$same) {
                        return ['error' => 'idempotency_conflict'];
                    }

                    $current = $pdo->prepare(
                        'SELECT reference_no, status FROM abh_enrollment_submissions WHERE id = :id'
                    );
                    $current->execute(['id' => $submissionId]);
                    $row = $current->fetch(\PDO::FETCH_ASSOC);

                    if (!is_array($row)) {
                        return ['error' => 'not_found'];
                    }

                    return [
                        'submission_id' => $submissionId,
                        'reference_no' => (string) $row['reference_no'],
                        'status' => (string) $row['status'],
                        'decision' => $decision,
                        'idempotent_replay' => true,
                    ];
                }

                $stmt = $pdo->prepare(
                    'SELECT s.reference_no, s.agent_id, s.reporting_period_id,
                            s.current_revision_id, s.status, p.period_key, p.status AS period_status,
                            r.revision_no, r.claimed_quantity
                       FROM abh_enrollment_submissions s
                       INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
                       INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                      WHERE s.id = :submission_id
                      LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['submission_id' => $submissionId]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($row)) {
                    return ['error' => 'not_found'];
                }

                // Re-check the idempotency key after obtaining the submission
                // lock. A concurrent retry may have committed while this
                // request waited for that lock.
                $recheck = $pdo->prepare(
                    'SELECT submission_id, decision, review_note
                       FROM abh_submission_reviews
                      WHERE idempotency_key = :idempotency_key
                      LIMIT 1 FOR UPDATE'
                );
                $recheck->execute(['idempotency_key' => $idempotencyKey]);
                $committed = $recheck->fetch(\PDO::FETCH_ASSOC);
                if (is_array($committed)) {
                    $same = (int) $committed['submission_id'] === $submissionId
                        && (string) $committed['decision'] === $decision
                        && (($committed['review_note'] ?? null) === $note);
                    if (!$same) {
                        return ['error' => 'idempotency_conflict'];
                    }

                    $current = $pdo->prepare(
                        'SELECT reference_no, status FROM abh_enrollment_submissions WHERE id = :id'
                    );
                    $current->execute(['id' => $submissionId]);
                    $latest = $current->fetch(\PDO::FETCH_ASSOC);

                    if (!is_array($latest)) {
                        return ['error' => 'not_found'];
                    }

                    return [
                        'submission_id' => $submissionId,
                        'reference_no' => (string) $latest['reference_no'],
                        'status' => (string) $latest['status'],
                        'decision' => $decision,
                        'idempotent_replay' => true,
                    ];
                }

                if ((string) $row['period_status'] !== 'OPEN') {
                    return ['error' => 'period_closed'];
                }

                if ((string) $row['status'] !== 'PENDING_REVIEW') {
                    return ['error' => 'state_conflict'];
                }

                $newStatus = match ($decision) {
                    'APPROVE' => 'APPROVED',
                    'REJECT' => 'REJECTED',
                    'REQUEST_CORRECTION' => 'CORRECTION_REQUESTED',
                    default => 'INVALID_DECISION',
                };
                if ($newStatus === 'INVALID_DECISION') {
                    return ['error' => 'invalid_decision'];
                }

                $cycleStmt = $pdo->prepare(
                    'SELECT COALESCE(MAX(review_cycle_no), 0) + 1
                       FROM abh_submission_reviews
                      WHERE submission_id = :submission_id'
                );
                $cycleStmt->execute(['submission_id' => $submissionId]);
                $cycle = (int) $cycleStmt->fetchColumn();

                $pdo->prepare(
                    'INSERT INTO abh_submission_reviews
                     (submission_id, revision_id, review_cycle_no, reviewer_admin_id,
                      decision, reason_code, review_note, idempotency_key, created_at)
                     VALUES
                     (:submission_id, :revision_id, :cycle, :admin_id,
                      :decision, :reason_code, :review_note, :idempotency_key, UTC_TIMESTAMP(6))'
                )->execute([
                    'submission_id' => $submissionId,
                    'revision_id' => (int) $row['current_revision_id'],
                    'cycle' => $cycle,
                    'admin_id' => $adminId,
                    'decision' => $decision,
                    'reason_code' => $decision === 'APPROVE' ? null : $decision,
                    'review_note' => $note,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $pdo->prepare(
                    'UPDATE abh_enrollment_submissions
                        SET status = :status, updated_at = UTC_TIMESTAMP(6)
                      WHERE id = :submission_id AND status = "PENDING_REVIEW"'
                )->execute([
                    'status' => $newStatus,
                    'submission_id' => $submissionId,
                ]);

                if ($decision === 'APPROVE') {
                    // Only the first successful transition from PENDING_REVIEW
                    // reaches this upsert. The row lock and status check prevent
                    // duplicate approvals from incrementing totals twice.
                    $pdo->prepare(
                        'INSERT INTO abh_monthly_agent_totals
                         (reporting_period_id, agent_id, approved_quantity, approved_submission_count, updated_at)
                         VALUES (:period_id, :agent_id, :quantity, 1, UTC_TIMESTAMP(6))
                         ON DUPLICATE KEY UPDATE
                           approved_quantity = approved_quantity + VALUES(approved_quantity),
                           approved_submission_count = approved_submission_count + 1,
                           updated_at = UTC_TIMESTAMP(6)'
                    )->execute([
                        'period_id' => (int) $row['reporting_period_id'],
                        'agent_id' => (int) $row['agent_id'],
                        'quantity' => (int) $row['claimed_quantity'],
                    ]);
                }

                $beforeJson = json_encode([
                    'status' => 'PENDING_REVIEW',
                    'claimed_quantity' => (int) $row['claimed_quantity'],
                    'revision_no' => (int) $row['revision_no'],
                ], JSON_THROW_ON_ERROR);
                $afterJson = json_encode([
                    'status' => $newStatus,
                    'decision' => $decision,
                    'period_key' => (string) $row['period_key'],
                ], JSON_THROW_ON_ERROR);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
                      reason, before_json, after_json, request_id, created_at)
                     VALUES ("ADMIN", :admin_id, :action, "enrollment_submission", :submission_id,
                             :reason, :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'admin_id' => $adminId,
                    'action' => 'submission.' . strtolower($decision),
                    'submission_id' => $submissionId,
                    'reason' => $note,
                    'before_json' => $beforeJson,
                    'after_json' => $afterJson,
                    'request_id' => RequestId::current(),
                ]);

                $message = match ($decision) {
                    'APPROVE' => 'Your enrollment submission ' . $row['reference_no'] . ' has been approved. The approved quantity now counts toward your monthly performance.',
                    'REJECT' => 'Your enrollment submission ' . $row['reference_no'] . ' was rejected. Review the reason and contact your administrator if you need help.',
                    default => 'Your enrollment submission ' . $row['reference_no'] . ' needs correction. Review the administrator note before resubmitting.',
                };
                $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_agent_id, event_key, title, message, created_at)
                     VALUES ("AGENT", :agent_id, :event_key, :title, :message, UTC_TIMESTAMP(6))'
                )->execute([
                    'agent_id' => (int) $row['agent_id'],
                    'event_key' => 'submission.' . strtolower($decision),
                    'title' => match ($decision) {
                        'APPROVE' => 'Enrollment approved',
                        'REJECT' => 'Enrollment rejected',
                        default => 'Correction requested',
                    },
                    'message' => $message . ($note !== null && $note !== '' ? ' Note: ' . $note : ''),
                ]);

                return [
                    'submission_id' => $submissionId,
                    'reference_no' => (string) $row['reference_no'],
                    'status' => $newStatus,
                    'decision' => $decision,
                    'idempotent_replay' => false,
                ];
            }
        );

        if (($result['error'] ?? null) === 'not_found') {
            throw new ApiException(404, ErrorCode::NOT_FOUND, 'Enrollment submission not found.');
        }
        if (($result['error'] ?? null) === 'period_closed') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'The reporting period is closed; this submission cannot be changed.');
        }
        if (($result['error'] ?? null) === 'state_conflict') {
            throw new ApiException(409, ErrorCode::STATE_CONFLICT, 'This submission has already been reviewed or is not awaiting review.');
        }
        if (($result['error'] ?? null) === 'idempotency_conflict') {
            throw new ApiException(409, ErrorCode::IDEMPOTENCY_CONFLICT, 'This review request key was already used for a different decision.');
        }
        if (($result['error'] ?? null) === 'invalid_decision') {
            throw ApiException::validation('Unsupported review decision.', ['field' => 'decision']);
        }

        return $result;
    }
}
