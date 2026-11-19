<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Shared read model for ABH live and frozen monthly leaderboards.
 * Closed periods always read immutable snapshots; open periods are calculated
 * from approved revisions, the source of truth used by agent monthly summaries.
 */
final class AbhLeaderboardRepository
{
    private function __construct()
    {
    }

    /**
     * @return array{
     *   period_key:string,period_status:string|null,total:int,
     *   items:list<array<string,mixed>>,
     *   viewer:array{rank_position:int|null,approved_quantity:int,target_quantity:int|null,progress_percent:string|null}
     * }
     */
    public static function getForMonth(string $periodKey, ?int $viewerAgentId, int $limit, int $offset): array
    {
        $emptyViewer = [
            'rank_position' => null,
            'approved_quantity' => 0,
            'target_quantity' => null,
            'progress_percent' => null,
        ];

        $period = Connection::fetchOne(
            'SELECT id, period_key, status
               FROM abh_reporting_periods
              WHERE period_key = :period_key
              LIMIT 1',
            ['period_key' => $periodKey]
        );

        if ($period === null) {
            return [
                'period_key' => $periodKey,
                'period_status' => null,
                'total' => 0,
                'items' => [],
                'viewer' => $emptyViewer,
            ];
        }

        $periodId = (int) $period['id'];
        $isClosed = (string) $period['status'] === 'CLOSED';

        if ($isClosed) {
            $total = (int) Connection::fetchValue(
                'SELECT COUNT(*)
                   FROM abh_monthly_leaderboard_snapshots
                  WHERE reporting_period_id = :period_id',
                ['period_id' => $periodId]
            );

            $items = Connection::fetchAll(
                'SELECT s.agent_id, s.rank_position, s.approved_quantity,
                        s.agent_code_snapshot AS agent_code,
                        s.display_name_snapshot AS display_name,
                        t.target_quantity,
                        ROUND(s.approved_quantity / NULLIF(t.target_quantity, 0) * 100, 2) AS progress_percent
                   FROM abh_monthly_leaderboard_snapshots s
                   LEFT JOIN abh_monthly_targets t
                     ON t.reporting_period_id = s.reporting_period_id
                    AND t.scope_type = "AGENT"
                    AND t.scope_owner_id = s.agent_id
                    AND t.agent_id = s.agent_id
                  WHERE s.reporting_period_id = :period_id
                  ORDER BY s.rank_position ASC, s.agent_id ASC
                  LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['period_id' => $periodId]
            );

            $viewer = $viewerAgentId === null ? null : Connection::fetchOne(
                'SELECT s.rank_position, s.approved_quantity, t.target_quantity,
                        ROUND(s.approved_quantity / NULLIF(t.target_quantity, 0) * 100, 2) AS progress_percent
                   FROM abh_monthly_leaderboard_snapshots s
                   LEFT JOIN abh_monthly_targets t
                     ON t.reporting_period_id = s.reporting_period_id
                    AND t.scope_type = "AGENT"
                    AND t.scope_owner_id = s.agent_id
                    AND t.agent_id = s.agent_id
                  WHERE s.reporting_period_id = :period_id
                    AND s.agent_id = :agent_id
                  LIMIT 1',
                ['period_id' => $periodId, 'agent_id' => $viewerAgentId]
            );
        } else {
            $total = (int) Connection::fetchValue(
                'SELECT COUNT(*)
                   FROM (
                        SELECT s.agent_id
                          FROM abh_enrollment_submissions s
                          INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                         WHERE s.reporting_period_id = :period_count
                           AND s.status = "APPROVED"
                         GROUP BY s.agent_id
                   ) ranked_agents',
                ['period_count' => $periodId]
            );

            $items = Connection::fetchAll(
                'SELECT RANK() OVER (ORDER BY ranked.approved_quantity DESC) AS rank_position,
                        ranked.agent_id, ranked.approved_quantity,
                        a.agent_code, a.full_name AS display_name,
                        t.target_quantity,
                        ROUND(ranked.approved_quantity / NULLIF(t.target_quantity, 0) * 100, 2) AS progress_percent
                   FROM (
                        SELECT s.agent_id, SUM(r.claimed_quantity) AS approved_quantity
                          FROM abh_enrollment_submissions s
                          INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                         WHERE s.reporting_period_id = :period_aggregate
                           AND s.status = "APPROVED"
                         GROUP BY s.agent_id
                   ) ranked
                   INNER JOIN abh_agents a ON a.id = ranked.agent_id
                   LEFT JOIN abh_monthly_targets t
                     ON t.reporting_period_id = :period_target
                    AND t.scope_type = "AGENT"
                    AND t.scope_owner_id = ranked.agent_id
                    AND t.agent_id = ranked.agent_id
                  ORDER BY rank_position ASC, ranked.agent_id ASC
                  LIMIT ' . $limit . ' OFFSET ' . $offset,
                [
                    'period_aggregate' => $periodId,
                    'period_target' => $periodId,
                ]
            );

            $viewer = $viewerAgentId === null ? null : Connection::fetchOne(
                'SELECT own.approved_quantity,
                        (
                            SELECT COUNT(*) + 1
                              FROM (
                                    SELECT s2.agent_id, SUM(r2.claimed_quantity) AS approved_quantity
                                      FROM abh_enrollment_submissions s2
                                      INNER JOIN abh_submission_revisions r2 ON r2.id = s2.current_revision_id
                                     WHERE s2.reporting_period_id = :period_ahead
                                       AND s2.status = "APPROVED"
                                     GROUP BY s2.agent_id
                              ) ahead
                             WHERE ahead.approved_quantity > own.approved_quantity
                        ) AS rank_position,
                        t.target_quantity,
                        ROUND(own.approved_quantity / NULLIF(t.target_quantity, 0) * 100, 2) AS progress_percent
                   FROM (
                        SELECT s.agent_id, SUM(r.claimed_quantity) AS approved_quantity
                          FROM abh_enrollment_submissions s
                          INNER JOIN abh_submission_revisions r ON r.id = s.current_revision_id
                         WHERE s.reporting_period_id = :period_own
                           AND s.status = "APPROVED"
                         GROUP BY s.agent_id
                   ) own
                   LEFT JOIN abh_monthly_targets t
                     ON t.reporting_period_id = :period_target
                    AND t.scope_type = "AGENT"
                    AND t.scope_owner_id = own.agent_id
                    AND t.agent_id = own.agent_id
                  WHERE own.agent_id = :agent_id
                  LIMIT 1',
                [
                    'period_ahead' => $periodId,
                    'period_own' => $periodId,
                    'period_target' => $periodId,
                    'agent_id' => $viewerAgentId,
                ]
            );
        }

        $viewerResult = $emptyViewer;
        if ($viewer !== null) {
            $viewerResult = [
                'rank_position' => (int) $viewer['rank_position'],
                'approved_quantity' => (int) $viewer['approved_quantity'],
                'target_quantity' => $viewer['target_quantity'] === null ? null : (int) $viewer['target_quantity'],
                'progress_percent' => $viewer['progress_percent'] === null ? null : (string) $viewer['progress_percent'],
            ];
        }

        foreach ($items as &$item) {
            $item['rank_position'] = (int) $item['rank_position'];
            $item['agent_id'] = (int) $item['agent_id'];
            $item['approved_quantity'] = (int) $item['approved_quantity'];
            $item['target_quantity'] = $item['target_quantity'] === null ? null : (int) $item['target_quantity'];
            $item['progress_percent'] = $item['progress_percent'] === null ? null : (string) $item['progress_percent'];
            $item['is_viewer'] = $viewerAgentId !== null && (int) $item['agent_id'] === $viewerAgentId;
        }
        unset($item);

        return [
            'period_key' => (string) $period['period_key'],
            'period_status' => (string) $period['status'],
            'total' => $total,
            'items' => $items,
            'viewer' => $viewerResult,
        ];
    }
}
