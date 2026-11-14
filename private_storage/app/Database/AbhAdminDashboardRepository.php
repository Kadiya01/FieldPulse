<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Read-only ABH administrator overview. Every section is sourced from the
 * ABH identity, submission, target and reward ledgers; no demo values or
 * legacy FieldPulse tables are used.
 */
final class AbhAdminDashboardRepository
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $permissions
     * @return array<string,mixed>
     */
    public static function overview(string $periodKey, array $permissions): array
    {
        $canAgents = in_array('agents.read', $permissions, true);
        $canSubmissions = in_array('submissions.read', $permissions, true);
        $canPerformance = $canSubmissions
            || in_array('targets.manage', $permissions, true)
            || in_array('periods.close', $permissions, true);
        $canRewards = in_array('rewards.manage', $permissions, true)
            || in_array('rewards.approve', $permissions, true);

        $period = Connection::fetchOne(
            'SELECT id, period_key, starts_on, ends_on, timezone, status, closed_at
               FROM abh_reporting_periods
              WHERE period_key = :period_key
              LIMIT 1',
            ['period_key' => $periodKey]
        );

        $agents = null;
        if ($canAgents) {
            $counts = [
                'PENDING' => 0,
                'ACTIVE' => 0,
                'REJECTED' => 0,
                'SUSPENDED' => 0,
            ];
            foreach (Connection::fetchAll(
                'SELECT status, COUNT(*) AS row_count
                   FROM abh_agents
                  GROUP BY status'
            ) as $row) {
                $status = (string) $row['status'];
                if (array_key_exists($status, $counts)) {
                    $counts[$status] = (int) $row['row_count'];
                }
            }
            $agents = [
                'total' => array_sum($counts),
                'pending' => $counts['PENDING'],
                'active' => $counts['ACTIVE'],
                'rejected' => $counts['REJECTED'],
                'suspended' => $counts['SUSPENDED'],
            ];
        }

        $submissions = null;
        $performance = null;
        $rewards = null;
        $periodId = $period === null ? null : (int) $period['id'];

        if ($canSubmissions) {
            $submissions = [
                'total' => 0,
                'pending_review' => 0,
                'correction_requested' => 0,
                'approved' => 0,
                'rejected' => 0,
                'claimed_quantity' => 0,
            ];

            if ($periodId !== null) {
                $counts = Connection::fetchOne(
                    'SELECT COUNT(*) AS total,
                            COALESCE(SUM(CASE WHEN s.status = "PENDING_REVIEW" THEN 1 ELSE 0 END), 0) AS pending_review,
                            COALESCE(SUM(CASE WHEN s.status = "CORRECTION_REQUESTED" THEN 1 ELSE 0 END), 0) AS correction_requested,
                            COALESCE(SUM(CASE WHEN s.status = "APPROVED" THEN 1 ELSE 0 END), 0) AS approved,
                            COALESCE(SUM(CASE WHEN s.status = "REJECTED" THEN 1 ELSE 0 END), 0) AS rejected,
                            COALESCE(SUM(r.claimed_quantity), 0) AS claimed_quantity
                       FROM abh_enrollment_submissions s
                       LEFT JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                      WHERE s.reporting_period_id = :period_id',
                    ['period_id' => $periodId]
                );
                if ($counts !== null) {
                    foreach ($submissions as $key => $_) {
                        $submissions[$key] = (int) ($counts[$key] ?? 0);
                    }
                }
            }
        }

        if ($canPerformance) {
            $approved = 0;
            $target = null;
            $topAgents = [];

            if ($periodId !== null) {
                $approved = (int) Connection::fetchValue(
                    'SELECT COALESCE(SUM(approved_quantity), 0)
                       FROM abh_monthly_agent_totals
                      WHERE reporting_period_id = :period_id',
                    ['period_id' => $periodId]
                );

                $targetValue = Connection::fetchValue(
                    'SELECT target_quantity
                       FROM abh_monthly_targets
                      WHERE reporting_period_id = :period_id
                        AND scope_type = "ORGANISATION"
                        AND scope_owner_id = 0
                      LIMIT 1',
                    ['period_id' => $periodId]
                );
                $target = $targetValue === null ? null : (int) $targetValue;

                $topAgents = Connection::fetchAll(
                    'SELECT t.agent_id, a.agent_code, a.full_name,
                            t.approved_quantity, target.target_quantity
                       FROM abh_monthly_agent_totals t
                       INNER JOIN abh_agents a ON a.id = t.agent_id
                       LEFT JOIN abh_monthly_targets target
                         ON target.reporting_period_id = t.reporting_period_id
                        AND target.scope_type = "AGENT"
                        AND target.agent_id = t.agent_id
                      WHERE t.reporting_period_id = :period_id
                        AND a.status = "ACTIVE"
                      ORDER BY t.approved_quantity DESC, a.agent_code ASC
                      LIMIT 5',
                    ['period_id' => $periodId]
                );

                $rank = 0;
                $position = 0;
                $previousQuantity = null;
                foreach ($topAgents as &$agent) {
                    $position++;
                    $quantity = (int) $agent['approved_quantity'];
                    if ($previousQuantity === null || $quantity !== $previousQuantity) {
                        $rank = $position;
                    }
                    $previousQuantity = $quantity;
                    $agent['rank_position'] = $rank;
                    $agent['agent_id'] = (int) $agent['agent_id'];
                    $agent['approved_quantity'] = $quantity;
                    $agent['target_quantity'] = $agent['target_quantity'] === null
                        ? null : (int) $agent['target_quantity'];
                    $agent['progress_percent'] = $agent['target_quantity'] > 0
                        ? number_format(($quantity / $agent['target_quantity']) * 100, 2, '.', '')
                        : null;
                }
                unset($agent);
            }

            $performance = [
                'approved_quantity' => $approved,
                'organisation_target' => $target,
                'progress_percent' => $target !== null && $target > 0
                    ? number_format(($approved / $target) * 100, 2, '.', '')
                    : null,
                'top_agents' => $topAgents,
            ];
        }

        if ($canRewards) {
            $rewards = [
                'programs_by_status' => [],
                'qualifications_by_status' => [],
                'awards_by_status' => [],
                'paid_events' => 0,
                'delivered_events' => 0,
            ];

            if ($periodId !== null) {
                foreach (Connection::fetchAll(
                    'SELECT status, COUNT(*) AS row_count
                       FROM abh_reward_programs
                      WHERE reporting_period_id = :period_id
                      GROUP BY status',
                    ['period_id' => $periodId]
                ) as $row) {
                    $rewards['programs_by_status'][(string) $row['status']] = (int) $row['row_count'];
                }

                foreach (Connection::fetchAll(
                    'SELECT qualification_status AS status, COUNT(*) AS row_count
                       FROM abh_reward_qualifications
                      WHERE reporting_period_id = :period_id
                      GROUP BY qualification_status',
                    ['period_id' => $periodId]
                ) as $row) {
                    $rewards['qualifications_by_status'][(string) $row['status']] = (int) $row['row_count'];
                }

                foreach (Connection::fetchAll(
                    'SELECT award_status AS status, COUNT(*) AS row_count
                       FROM abh_reward_awards
                      WHERE reporting_period_id = :period_id
                      GROUP BY award_status',
                    ['period_id' => $periodId]
                ) as $row) {
                    $rewards['awards_by_status'][(string) $row['status']] = (int) $row['row_count'];
                }

                $events = Connection::fetchOne(
                    'SELECT
                        COALESCE(SUM(CASE WHEN e.event_type = "PAID" THEN 1 ELSE 0 END), 0) AS paid_events,
                        COALESCE(SUM(CASE WHEN e.event_type = "DELIVERED" THEN 1 ELSE 0 END), 0) AS delivered_events
                       FROM abh_reward_fulfilment_events e
                       INNER JOIN abh_reward_awards a ON a.id = e.award_id
                      WHERE a.reporting_period_id = :period_id',
                    ['period_id' => $periodId]
                );
                if ($events !== null) {
                    $rewards['paid_events'] = (int) $events['paid_events'];
                    $rewards['delivered_events'] = (int) $events['delivered_events'];
                }
            }
        }

        $periodData = $period === null ? null : [
            'id' => (int) $period['id'],
            'period_key' => (string) $period['period_key'],
            'starts_on' => (string) $period['starts_on'],
            'ends_on' => (string) $period['ends_on'],
            'timezone' => (string) $period['timezone'],
            'status' => (string) $period['status'],
            'closed_at' => $period['closed_at'] === null ? null : (string) $period['closed_at'],
        ];

        return [
            'period' => $periodData,
            'agents' => $agents,
            'submissions' => $submissions,
            'performance' => $performance,
            'rewards' => $rewards,
        ];
    }
}
