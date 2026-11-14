<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\RequestId;

/**
 * Creates immutable corrected revisions for an agent's own submission.
 * Ownership, current status and reporting-period state are locked and checked
 * in the same transaction that appends the revision and evidence metadata.
 */
final class AbhSubmissionCorrectionRepository
{
    private function __construct()
    {
    }

    /** @return array<string,mixed>|null */
    public static function findByIdempotencyKey(int $agentId, int $submissionId, string $key): ?array
    {
        return Connection::fetchOne(
            'SELECT s.id, s.reference_no, s.status, s.submitted_at,
                    p.period_key, r.revision_no, r.claimed_quantity, r.activity_date,
                    r.agent_note, e.sha256 AS evidence_sha256
               FROM abh_enrollment_submissions s
               INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
               INNER JOIN abh_submission_revisions r ON r.submission_id = s.id
               INNER JOIN abh_submission_evidence e ON e.revision_id = r.id
              WHERE s.id = :submission_id
                AND s.agent_id = :agent_id
                AND r.idempotency_key = :idempotency_key
              LIMIT 1',
            [
                'submission_id' => $submissionId,
                'agent_id' => $agentId,
                'idempotency_key' => $key,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function create(
        int $agentId,
        int $submissionId,
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
                $submissionId,
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
                $stmt = $pdo->prepare(
                    'SELECT s.reference_no, s.agent_id, s.reporting_period_id,
                            s.current_revision_id, s.status, s.submitted_at,
                            p.period_key, p.starts_on, p.ends_on, p.status AS period_status,
                            r.revision_no
                       FROM abh_enrollment_submissions s
                       INNER JOIN abh_reporting_periods p ON p.id = s.reporting_period_id
                       INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                      WHERE s.id = :submission_id AND s.agent_id = :agent_id
                      LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['submission_id' => $submissionId, 'agent_id' => $agentId]);
                $submission = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($submission)) {
                    return ['error' => 'not_found'];
                }

                // Re-check after locking the submission so a concurrent retry
                // that committed while this request waited returns its revision.
                $priorStmt = $pdo->prepare(
                    'SELECT r.revision_no, r.claimed_quantity, r.activity_date, r.agent_note,
                            e.sha256 AS evidence_sha256
                       FROM abh_submission_revisions r
                       INNER JOIN abh_submission_evidence e ON e.revision_id = r.id
                      WHERE r.submission_id = :submission_id
                        AND r.idempotency_key = :idempotency_key
                      LIMIT 1 FOR UPDATE'
                );
                $priorStmt->execute([
                    'submission_id' => $submissionId,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $prior = $priorStmt->fetch(\PDO::FETCH_ASSOC);
                if (is_array($prior)) {
                    $same = (int) $prior['claimed_quantity'] === $quantity
                        && (string) $prior['activity_date'] === $activityDate
                        && (($prior['agent_note'] ?? null) === $note)
                        && hash_equals((string) $prior['evidence_sha256'], $sha256);
                    if (!$same) {
                        return ['error' => 'idempotency_conflict'];
                    }

                    return [
                        'id' => $submissionId,
                        'reference_no' => (string) $submission['reference_no'],
                        'status' => (string) $submission['status'],
                        'period_key' => (string) $submission['period_key'],
                        'activity_date' => (string) $prior['activity_date'],
                        'claimed_quantity' => (int) $prior['claimed_quantity'],
                        'submitted_at' => (string) $submission['submitted_at'],
                        'revision_no' => (int) $prior['revision_no'],
                        'idempotent_replay' => true,
                    ];
                }

                if ((string) $submission['period_key'] !== $periodKey) {
                    return ['error' => 'period_conflict'];
                }

                if ((string) $submission['period_status'] !== 'OPEN') {
                    return ['error' => 'period_not_open'];
                }
                if ((string) $submission['status'] !== 'CORRECTION_REQUESTED') {
                    return ['error' => 'state_conflict'];
                }
                if ($activityDate < (string) $submission['starts_on'] || $activityDate > (string) $submission['ends_on']) {
                    return ['error' => 'date_outside_period'];
                }

                $revisionNo = (int) $submission['revision_no'] + 1;
                $pdo->prepare(
                    'INSERT INTO abh_submission_revisions
                     (submission_id, revision_no, idempotency_key, claimed_quantity, activity_date,
                      agent_note, created_by_agent_id, created_at)
                     VALUES
                     (:submission_id, :revision_no, :idempotency_key, :quantity, :activity_date,
                      :agent_note, :agent_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'submission_id' => $submissionId,
                    'revision_no' => $revisionNo,
                    'idempotency_key' => $idempotencyKey,
                    'quantity' => $quantity,
                    'activity_date' => $activityDate,
                    'agent_note' => $note,
                    'agent_id' => $agentId,
                ]);
                $revisionId = (int) $pdo->lastInsertId();

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

                $pdo->prepare(
                    'UPDATE abh_enrollment_submissions
                        SET current_revision_id = :revision_id,
                            status = "PENDING_REVIEW",
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE id = :submission_id
                        AND agent_id = :agent_id
                        AND status = "CORRECTION_REQUESTED"'
                )->execute([
                    'revision_id' => $revisionId,
                    'submission_id' => $submissionId,
                    'agent_id' => $agentId,
                ]);

                $after = json_encode([
                    'reference_no' => (string) $submission['reference_no'],
                    'period_key' => (string) $submission['period_key'],
                    'revision_no' => $revisionNo,
                    'claimed_quantity' => $quantity,
                    'activity_date' => $activityDate,
                    'evidence_sha256' => $sha256,
                    'status' => 'PENDING_REVIEW',
                ], JSON_THROW_ON_ERROR);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_agent_id, action_key, entity_type, entity_id,
                      after_json, request_id, created_at)
                     VALUES ("AGENT", :agent_id, "submission.corrected", "enrollment_submission",
                             :submission_id, :after_json, :request_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'agent_id' => $agentId,
                    'submission_id' => $submissionId,
                    'after_json' => $after,
                    'request_id' => RequestId::current(),
                ]);

                $notify = $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_admin_user_id, event_key, title, message, created_at)
                     SELECT DISTINCT "ADMIN", u.id, "submission.corrected",
                            "Corrected enrollment submitted",
                            :message, UTC_TIMESTAMP(6)
                       FROM abh_admin_users u
                       INNER JOIN abh_admin_user_roles ur ON ur.admin_user_id = u.id
                       INNER JOIN abh_admin_role_permissions rp ON rp.role_id = ur.role_id
                       INNER JOIN abh_admin_permissions p ON p.id = rp.permission_id
                      WHERE u.status = "ACTIVE"
                        AND p.permission_key = "submissions.review"'
                );
                $notify->execute([
                    'message' => 'Submission ' . $submission['reference_no'] . ' has a corrected revision awaiting review.',
                ]);

                return [
                    'id' => $submissionId,
                    'reference_no' => (string) $submission['reference_no'],
                    'status' => 'PENDING_REVIEW',
                    'period_key' => (string) $submission['period_key'],
                    'activity_date' => $activityDate,
                    'claimed_quantity' => $quantity,
                    'submitted_at' => gmdate('Y-m-d H:i:s'),
                    'revision_no' => $revisionNo,
                    'idempotent_replay' => false,
                ];
            }
        );
    }
}
