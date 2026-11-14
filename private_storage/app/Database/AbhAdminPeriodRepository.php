<?php

declare(strict_types=1);

namespace FieldPulse\Database;

use FieldPulse\Support\RequestId;

/**
 * Atomic reporting-period finalisation. The reporting-period row is the
 * serialization point shared by submissions, reviews, corrections and targets.
 */
final class AbhAdminPeriodRepository
{
    private function __construct()
    {
    }

    /** @return array<string,mixed> */
    public static function close(int $adminId, string $periodKey): array
    {
        return Connection::transaction(
            static function (\PDO $pdo) use ($adminId, $periodKey): array {
                $periodStmt = $pdo->prepare(
                    'SELECT id, period_key, starts_on, ends_on, status
                       FROM abh_reporting_periods
                      WHERE period_key = :period_key
                      LIMIT 1 FOR UPDATE'
                );
                $periodStmt->execute(['period_key' => $periodKey]);
                $period = $periodStmt->fetch(\PDO::FETCH_ASSOC);

                if (!is_array($period)) {
                    return ['error' => 'not_found'];
                }
                if ((string) $period['status'] !== 'OPEN') {
                    return ['error' => 'already_closed'];
                }

                $today = new \DateTimeImmutable('today', new \DateTimeZone('Africa/Lagos'));
                if ((string) $period['ends_on'] >= $today->format('Y-m-d')) {
                    return ['error' => 'period_not_ended'];
                }

                $pendingStmt = $pdo->prepare(
                    'SELECT COUNT(*)
                       FROM abh_enrollment_submissions
                      WHERE reporting_period_id = :period_id
                        AND status IN ("PENDING_REVIEW", "CORRECTION_REQUESTED")'
                );
                $pendingStmt->execute(['period_id' => (int) $period['id']]);
                $pendingCount = (int) $pendingStmt->fetchColumn();

                if ($pendingCount > 0) {
                    return ['error' => 'pending_reviews', 'pending_count' => $pendingCount];
                }

                // Recompute from the approved submission ledger at close time.
                // The cached totals table is checked before it is trusted for
                // display elsewhere; a mismatch blocks finalisation for review.
                $aggregateStmt = $pdo->prepare(
                    'SELECT s.agent_id, a.agent_code, a.full_name,
                            SUM(r.claimed_quantity) AS approved_quantity
                       FROM abh_enrollment_submissions s
                       INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                       INNER JOIN abh_agents a ON a.id = s.agent_id
                      WHERE s.reporting_period_id = :period_id
                        AND s.status = "APPROVED"
                      GROUP BY s.agent_id, a.agent_code, a.full_name
                      ORDER BY approved_quantity DESC, s.agent_id ASC'
                );
                $aggregateStmt->execute(['period_id' => (int) $period['id']]);
                $agents = $aggregateStmt->fetchAll(\PDO::FETCH_ASSOC);

                $ledgerStmt = $pdo->prepare(
                    'SELECT agent_id, approved_quantity
                       FROM abh_monthly_agent_totals
                      WHERE reporting_period_id = :period_id'
                );
                $ledgerStmt->execute(['period_id' => (int) $period['id']]);
                $ledgerRows = $ledgerStmt->fetchAll(\PDO::FETCH_ASSOC);
                $expected = [];
                foreach ($agents as $agent) {
                    $expected[(int) $agent['agent_id']] = (int) $agent['approved_quantity'];
                }
                foreach ($ledgerRows as $ledger) {
                    $agentId = (int) $ledger['agent_id'];
                    if (($expected[$agentId] ?? 0) !== (int) $ledger['approved_quantity']) {
                        return ['error' => 'totals_mismatch'];
                    }
                }
                foreach ($expected as $agentId => $quantity) {
                    $ledgerQuantity = 0;
                    foreach ($ledgerRows as $ledger) {
                        if ((int) $ledger['agent_id'] === $agentId) {
                            $ledgerQuantity = (int) $ledger['approved_quantity'];
                            break;
                        }
                    }
                    if ($ledgerQuantity !== $quantity) {
                        return ['error' => 'totals_mismatch'];
                    }
                }

                $organisationApproved = 0;
                $rankPosition = 0;
                $positionIndex = 0;
                $previousQuantity = null;
                $snapshotInsert = $pdo->prepare(
                    'INSERT INTO abh_monthly_leaderboard_snapshots
                     (reporting_period_id, agent_id, rank_position, approved_quantity,
                      reached_final_total_at, agent_code_snapshot, display_name_snapshot, created_at)
                     VALUES
                     (:period_id, :agent_id, :rank_position, :approved_quantity,
                      UTC_TIMESTAMP(6), :agent_code, :display_name, UTC_TIMESTAMP(6))'
                );

                foreach ($agents as $agent) {
                    $positionIndex++;
                    $quantity = (int) $agent['approved_quantity'];
                    if ($previousQuantity === null || $quantity !== $previousQuantity) {
                        $rankPosition = $positionIndex;
                    }
                    $previousQuantity = $quantity;
                    $organisationApproved += $quantity;
                    $snapshotInsert->execute([
                        'period_id' => (int) $period['id'],
                        'agent_id' => (int) $agent['agent_id'],
                        'rank_position' => $rankPosition,
                        'approved_quantity' => $quantity,
                        'agent_code' => (string) $agent['agent_code'],
                        'display_name' => (string) $agent['full_name'],
                    ]);
                }

                $targetStmt = $pdo->prepare(
                    'SELECT target_quantity
                       FROM abh_monthly_targets
                      WHERE reporting_period_id = :period_id
                        AND scope_type = "ORGANISATION"
                        AND scope_owner_id = 0
                      LIMIT 1'
                );
                $targetStmt->execute(['period_id' => (int) $period['id']]);
                $targetValue = $targetStmt->fetchColumn();
                $organisationTarget = $targetValue === false ? null : (int) $targetValue;
                $progressPercent = $organisationTarget !== null && $organisationTarget > 0
                    ? number_format(($organisationApproved / $organisationTarget) * 100, 2, '.', '')
                    : null;

                $pdo->prepare(
                    'INSERT INTO abh_monthly_organisation_snapshots
                     (reporting_period_id, approved_quantity, target_quantity, progress_percent,
                      agent_count, closed_at, closed_by_admin_id, created_at)
                     VALUES
                     (:period_id, :approved_quantity, :target_quantity, :progress_percent,
                      :agent_count, UTC_TIMESTAMP(6), :admin_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'period_id' => (int) $period['id'],
                    'approved_quantity' => $organisationApproved,
                    'target_quantity' => $organisationTarget,
                    'progress_percent' => $progressPercent,
                    'agent_count' => count($agents),
                    'admin_id' => $adminId,
                ]);

                $pdo->prepare(
                    'UPDATE abh_reporting_periods
                        SET status = "CLOSED",
                            closed_at = UTC_TIMESTAMP(6),
                            closed_by_admin_id = :admin_id,
                            updated_at = UTC_TIMESTAMP(6)
                      WHERE id = :period_id AND status = "OPEN"'
                )->execute([
                    'admin_id' => $adminId,
                    'period_id' => (int) $period['id'],
                ]);

                $beforeJson = json_encode([
                    'status' => 'OPEN',
                    'period_key' => $periodKey,
                ], JSON_THROW_ON_ERROR);
                $afterJson = json_encode([
                    'status' => 'CLOSED',
                    'period_key' => $periodKey,
                    'approved_quantity' => $organisationApproved,
                    'organisation_target' => $organisationTarget,
                    'progress_percent' => $progressPercent,
                    'ranked_agent_count' => count($agents),
                    'pending_review_count' => 0,
                ], JSON_THROW_ON_ERROR);

                $pdo->prepare(
                    'INSERT INTO abh_audit_events
                     (actor_type, actor_admin_user_id, action_key, entity_type, entity_id,
                      before_json, after_json, request_id, created_at)
                     VALUES ("ADMIN", :admin_id, "period.closed", "reporting_period", :period_id,
                             :before_json, :after_json, :request_id, UTC_TIMESTAMP(6))'
                )->execute([
                    'admin_id' => $adminId,
                    'period_id' => (int) $period['id'],
                    'before_json' => $beforeJson,
                    'after_json' => $afterJson,
                    'request_id' => RequestId::current(),
                ]);

                $pdo->prepare(
                    'INSERT INTO abh_notifications
                     (recipient_type, recipient_agent_id, event_key, title, message, created_at)
                     SELECT "AGENT", id, "period.closed",
                            "Monthly results finalised",
                            :message, UTC_TIMESTAMP(6)
                       FROM abh_agents
                      WHERE status = "ACTIVE"'
                )->execute([
                    'message' => 'The ' . $periodKey . ' reporting month has been closed. Your final approved quantity and monthly rank are now frozen.',
                ]);

                return [
                    'period_key' => $periodKey,
                    'status' => 'CLOSED',
                    'approved_quantity' => $organisationApproved,
                    'organisation_target' => $organisationTarget,
                    'progress_percent' => $progressPercent,
                    'ranked_agent_count' => count($agents),
                    'closed_by_admin_id' => $adminId,
                    'idempotent_replay' => false,
                ];
            }
        );
    }
}
