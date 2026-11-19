<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Read-only aggregates for the admin dashboard and period history.
 *
 * Every number comes from the same tables the agent dashboard and reward
 * close use. There is no separate statistics table and no cached snapshot, so
 * an approval immediately moves the admin figures in the same way it moves the
 * agent's own figure.
 */
final class AdminDashboardRepository extends Repository
{
    /**
     * @return array{total:int,active:int,inactive:int,pending:int,rejected:int,suspended:int,retired:int,self_registered:int}
     */
    public function agentCounts(): array
    {
        $rows = $this->all('SELECT status, COUNT(*) AS n FROM agents GROUP BY status');
        $counts = [
            'total'           => 0,
            'active'          => 0,
            'inactive'        => 0,
            'pending'         => 0,
            'rejected'        => 0,
            'suspended'       => 0,
            'retired'         => 0,
            'self_registered' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $n = (int) $row['n'];
            $counts['total'] += $n;

            $key = strtolower($status);
            if (array_key_exists($key, $counts)) {
                $counts[$key] = $n;
            }
        }

        $counts['self_registered'] = (int) $this->value(
            'SELECT COUNT(*) FROM agents WHERE self_registered = 1'
        );

        return $counts;
    }

    /**
     * @return array{total:int,active:int,pending:int,revoked:int,disabled:int}
     */
    public function deviceCounts(): array
    {
        $rows = $this->all('SELECT status, COUNT(*) AS n FROM devices GROUP BY status');
        $counts = ['total' => 0, 'active' => 0, 'pending' => 0, 'revoked' => 0, 'disabled' => 0];

        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $counts['total'] += $n;
            $key = strtolower((string) $row['status']);
            if (array_key_exists($key, $counts)) {
                $counts[$key] = $n;
            }
        }

        return $counts;
    }

    /**
     * @return array{approved:int,pending:int,rejected:int,submissions:int,agents_reporting:int}
     */
    public function periodTotals(string $periodDate, string $periodType): array
    {
        $row = $this->one(
            'SELECT COALESCE(SUM(total_verified_count), 0) AS approved,
                    COALESCE(SUM(total_pending), 0) AS pending,
                    COALESCE(SUM(total_rejected), 0) AS rejected,
                    COALESCE(SUM(total_submissions), 0) AS submissions,
                    COUNT(*) AS agents_reporting
               FROM agent_performance_summary
              WHERE period_start_date = :period AND period_type = :period_type',
            ['period' => $periodDate, 'period_type' => $periodType]
        );

        return [
            'approved'         => (int) ($row['approved'] ?? 0),
            'pending'          => (int) ($row['pending'] ?? 0),
            'rejected'         => (int) ($row['rejected'] ?? 0),
            'submissions'      => (int) ($row['submissions'] ?? 0),
            'agents_reporting' => (int) ($row['agents_reporting'] ?? 0),
        ];
    }

    /**
     * Active agents grouped by their location, including agents with no
     * location under "Unassigned" so the totals always add up to the active
     * population.
     *
     * @return list<array{location:string,agent_count:int,approved:int,pending:int,rejected:int,submissions:int}>
     */
    public function locationBreakdown(string $periodDate, string $periodType): array
    {
        $rows = $this->all(
            "SELECT COALESCE(NULLIF(a.location, ''), 'Unassigned') AS location,
                    COUNT(*) AS agent_count,
                    COALESCE(SUM(s.total_verified_count), 0) AS approved,
                    COALESCE(SUM(s.total_pending), 0) AS pending,
                    COALESCE(SUM(s.total_rejected), 0) AS rejected,
                    COALESCE(SUM(s.total_submissions), 0) AS submissions
               FROM agents a
               LEFT JOIN agent_performance_summary s
                 ON s.agent_id = a.id
                AND s.period_start_date = :period
                AND s.period_type = :period_type
              WHERE a.status = 'ACTIVE'
              GROUP BY location
              ORDER BY approved DESC, location",
            ['period' => $periodDate, 'period_type' => $periodType]
        );

        return array_map(static fn (array $row): array => [
            'location'    => (string) $row['location'],
            'agent_count' => (int) $row['agent_count'],
            'approved'    => (int) $row['approved'],
            'pending'     => (int) $row['pending'],
            'rejected'    => (int) $row['rejected'],
            'submissions' => (int) $row['submissions'],
        ], $rows);
    }

    /**
     * @return list<array{agent_id:int,agent_code:string,full_name:string,location:?string,monthly_target:int,approved:int,pending:int,rejected:int}>
     */
    public function topAgents(string $periodDate, string $periodType, int $limit = 5): array
    {
        $rows = $this->all(
            "SELECT a.id AS agent_id, a.agent_code, a.full_name, a.location, a.monthly_target,
                    COALESCE(s.total_verified_count, 0) AS approved,
                    COALESCE(s.total_pending, 0) AS pending,
                    COALESCE(s.total_rejected, 0) AS rejected
               FROM agents a
               LEFT JOIN agent_performance_summary s
                 ON s.agent_id = a.id
                AND s.period_start_date = :period
                AND s.period_type = :period_type
              WHERE a.status = 'ACTIVE'
              ORDER BY approved DESC, pending ASC, a.agent_code
              LIMIT " . max(1, min($limit, 50)),
            ['period' => $periodDate, 'period_type' => $periodType]
        );

        return array_map(static fn (array $row): array => [
            'agent_id'      => (int) $row['agent_id'],
            'agent_code'    => (string) $row['agent_code'],
            'full_name'     => (string) $row['full_name'],
            'location'      => $row['location'] === null ? null : (string) $row['location'],
            'monthly_target' => (int) $row['monthly_target'],
            'approved'      => (int) $row['approved'],
            'pending'       => (int) $row['pending'],
            'rejected'      => (int) $row['rejected'],
        ], $rows);
    }

    /** @return array{approved:int,pending:int,rejected:int,submissions:int} */
    public function agentPeriodSummary(int $agentId, string $periodDate, string $periodType): array
    {
        $row = $this->one(
            'SELECT total_verified_count, total_pending, total_rejected, total_submissions
               FROM agent_performance_summary
              WHERE agent_id = :agent_id
                AND period_start_date = :period
                AND period_type = :period_type',
            ['agent_id' => $agentId, 'period' => $periodDate, 'period_type' => $periodType]
        );

        return [
            'approved'    => (int) ($row['total_verified_count'] ?? 0),
            'pending'     => (int) ($row['total_pending'] ?? 0),
            'rejected'    => (int) ($row['total_rejected'] ?? 0),
            'submissions' => (int) ($row['total_submissions'] ?? 0),
        ];
    }

    /**
     * @return array{pending:int,approved:int,paid:int,void:int,total:int}
     */
    public function rewardCounts(string $periodDate, string $periodType): array
    {
        $rows = $this->all(
            'SELECT status, COUNT(*) AS n
               FROM agent_rewards
              WHERE period_start_date = :period AND period_type = :period_type
              GROUP BY status',
            ['period' => $periodDate, 'period_type' => $periodType]
        );

        $counts = ['pending' => 0, 'approved' => 0, 'paid' => 0, 'void' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $n = (int) $row['n'];
            $counts['total'] += $n;
            $key = strtolower((string) $row['status']);
            if (array_key_exists($key, $counts)) {
                $counts[$key] = $n;
            }
        }

        return $counts;
    }

    public function rewardRankingCount(string $periodDate, string $periodType): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM reward_rankings
              WHERE period_start_date = :period AND period_type = :period_type',
            ['period' => $periodDate, 'period_type' => $periodType]
        );
    }
}
