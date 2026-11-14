<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Shared read model for ABH live and frozen monthly leaderboards.
 * Closed periods always read snapshot rows; open periods read approved totals.
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
                   FROM abh_monthly_agent_totals
                  WHERE reporting_period_id = :period_id
                    AND approved_quantity > 0',
                ['period_id' => $periodId]
            );

            $items = Connection::fetchAll(
                'SELECT RANK() OVER (ORDER BY mt.approved_quantity DESC) AS rank_position,
                        mt.agent_id, mt.approved_quantity,
                        a.agent_code, a.full_name AS display_name,
                        t.target_quantity,
                        ROUND(mt.approved_quantity / NULLIF(t.target_quantity, 0) * 100, 2) AS progress_percent
                   FROM abh_monthly_agent_totals mt
                   INNER JOIN abh_agents a ON a.id = mt.agent_id
                   LEFT JOIN abh_monthly_targets t
                     ON t.reporting_period_id = mt.reporting_period_id
                    AND t.scope_type = "AGENT"
                    AND t.scope_owner_id = mt.agent_id
                    AND t.agent_id = mt.agent_id
                  WHERE mt.reporting_period_id = :period_id
                    AND mt.approved_quantity > 0
                  ORDER BY rank_position ASC, mt.agent_id ASC
                  LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['period_id' => $periodId]
            );

            $viewer = $viewerAgentId === null ? null : Connection::fetchOne(
                'SELECT mt.approved_quantity,
                        (SELECT COUNT(*) + 1
                           FROM abh_monthly_agent_totals ahead
                          WHERE ahead.reporting_period_id = mt.reporting_period_id
                            AND ahead.approved_quantity > mt.approved_quantity) AS rank_position,
                        t.target_quantity,
                        ROUND(mt.approved_quantity / NULLIF(t.target_quantity, 0) * 100, 2) AS progress_percent
                   FROM abh_monthly_agent_totals mt
                   LEFT JOIN abh_monthly_targets t
                     ON t.reporting_period_id = mt.reporting_period_id
                    AND t.scope_type = "AGENT"
                    AND t.scope_owner_id = mt.agent_id
                    AND t.agent_id = mt.agent_id
                  WHERE mt.reporting_period_id = :period_id
                    AND mt.agent_id = :agent_id
                    AND mt.approved_quantity > 0
                  LIMIT 1',
                ['period_id' => $periodId, 'agent_id' => $viewerAgentId]
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
