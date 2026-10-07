<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * Leaderboard reads (§13).
 *
 * The leaderboard is served entirely from agent_performance_summary, never by
 * aggregating submissions on the fly. That table is a full recompute written
 * when a submission is dispositioned, so a leaderboard read is a single indexed
 * range scan regardless of how many submissions exist.
 *
 * Global by default, optionally narrowed to one site. The site filter joins
 * agent_sites, which is also what makes a per-site board honest: an agent
 * assigned to Lagos and Abuja appears on both boards, and their counts are the
 * same on each, because the same submission satisfied one geofence.
 */
final class LeaderboardRepository extends Repository
{
    /**
     * The one definition of rank order.
     *
     * The page query and the self-rank count must sort ties identically. They
     * did not: this clause broke verified-count ties on pending count, while
     * the count that produced an agent's own rank ignored pending entirely and
     * jumped straight to agent_code. Two agents level on verified but not on
     * pending were ordered one way on screen and counted the other way in the
     * "you are Nth" number beside it — and a rank frozen from that pair is not
     * a rank anyone can defend afterwards.
     */
    private const ORDER_BY = 's.total_verified_count DESC, s.total_pending ASC, a.agent_code ASC';

    /**
     * "Strictly ahead of me", in exactly the terms {@see self::ORDER_BY} uses.
     *
     * Kept as one fragment so the comparison cannot drift from the ordering
     * again: a rank is the count of agents sorted before this one, and that is
     * only true if both statements agree on what "before" means.
     */
    private const AHEAD_OF_SELF = ' AND (s.total_verified_count > :self_count
                                   OR (s.total_verified_count = :self_count AND s.total_pending < :self_pending)
                                   OR (s.total_verified_count = :self_count
                                       AND s.total_pending = :self_pending
                                       AND a.agent_code < :self_code))';

    /**
     * @return array{
     *   entries:list<array<string,mixed>>,
     *   total:int,
     *   self:?array<string,mixed>,
     *   self_rank:?int
     * }
     */
    public function board(
        string $periodStartDate,
        int $limit,
        int $offset,
        ?int $siteId = null,
        ?int $selfAgentId = null
    ): array {
        [$where, $params] = $this->filters($periodStartDate, $siteId);

        $rows = $this->all(
            'SELECT a.id AS agent_id, a.agent_code, a.full_name,
                    s.total_verified_count, s.total_submissions, s.total_rejected, s.total_pending
               FROM agent_performance_summary s
               JOIN agents a ON a.id = s.agent_id
               ' . $where . '
              ORDER BY ' . self::ORDER_BY . self::limitClause($limit, 200, $offset),
            $params
        );

        $entries = [];

        foreach ($rows as $index => $row) {
            $entries[] = [
                'rank'                 => $offset + $index + 1,
                'agent_id'             => (int) $row['agent_id'],
                'agent_code'           => (string) $row['agent_code'],
                'display_name'         => $this->maskName((string) $row['full_name']),
                'total_verified_count' => (int) $row['total_verified_count'],
                'total_submissions'    => (int) $row['total_submissions'],
                'total_pending'        => (int) $row['total_pending'],
                'total_rejected'       => (int) $row['total_rejected'],
            ];
        }

        $total = (int) $this->value(
            'SELECT COUNT(*) FROM agent_performance_summary s JOIN agents a ON a.id = s.agent_id ' . $where,
            $params
        );

        // The caller's own row is fetched even when it is off the visible page,
        // otherwise an agent ranked 400th is told nothing at all, which reads as
        // "I do not exist" rather than "you are 400th".
        $self       = null;
        $selfRank   = null;

        if ($selfAgentId !== null) {
            /*
             * No $where is passed: filters() yields a string, and the earlier
             * signature here declared array, so every leaderboard request for a
             * logged-in agent died on a TypeError. The clause is rebuilt from
             * scratch below anyway, so the argument was never used.
             */
            $self = $this->selfRow($periodStartDate, $selfAgentId, $siteId, $params);

            if ($self !== null) {
                $selfRank = (int) $this->value(
                    'SELECT COUNT(*) + 1
                       FROM agent_performance_summary s
                       JOIN agents a ON a.id = s.agent_id
                      ' . $where . self::AHEAD_OF_SELF,
                    $params + [
                        'self_count'   => (int) $self['total_verified_count'],
                        'self_pending' => (int) $self['total_pending'],
                        'self_code'    => (string) $self['agent_code'],
                    ]
                );
            }
        }

        return [
            'entries'   => $entries,
            'total'     => $total,
            'self'      => $self === null ? null : [
                'rank'                 => $selfRank,
                'agent_id'             => (int) $self['agent_id'],
                'agent_code'           => (string) $self['agent_code'],
                'display_name'         => $this->maskName((string) $self['full_name']),
                'total_verified_count' => (int) $self['total_verified_count'],
                'total_pending'        => (int) $self['total_pending'],
            ],
            'self_rank' => $selfRank,
        ];
    }

    /**
     * The caller's own row, ignoring the site filter so the "you" block is
     * always the global truth even on a site-filtered board — except that a
     * site filter is honoured when present, because a board for a site the
     * agent is not assigned to has no row for them to be shown.
     *
     * `total_pending` comes back as well as the verified count: it is the
     * tiebreaker in {@see self::ORDER_BY}, so the rank comparison needs it.
     *
     * @param  array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    private function selfRow(string $period, int $agentId, ?int $siteId, array $params): ?array
    {
        $sql = 'SELECT a.id AS agent_id, a.agent_code, a.full_name,
                       s.total_verified_count, s.total_pending
                  FROM agent_performance_summary s
                  JOIN agents a ON a.id = s.agent_id
                 WHERE s.period_start_date = :period
                   AND s.agent_id = :agent_id';

        $own = $params;
        $own['period']   = $period;
        $own['agent_id'] = $agentId;

        // A site filter must not hide the caller's own row; if they are not
        // assigned to that site there is nothing to show.
        if ($siteId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM agent_sites s2
                                     WHERE s2.agent_id = s.agent_id
                                       AND s2.id = :site_id
                                       AND s2.is_active = 1)';
            $own['site_id'] = $siteId;
        }

        return $this->one($sql, $own);
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(string $periodStartDate, ?int $siteId): array
    {
        $where  = ['s.period_start_date = :period', "a.status = 'ACTIVE'"];

        $params = ['period' => $periodStartDate];

        if ($siteId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM agent_sites s2
                                  WHERE s2.agent_id = s.agent_id
                                    AND s2.id = :site_id
                                    AND s2.is_active = 1)';
            $params['site_id'] = $siteId;
        }

        // The WHERE keyword belongs to this clause, not to each caller. It used
        // to be returned bare, and the three statements that splice it in after
        // a JOIN (the page query, the COUNT, and the self-rank count) all
        // produced "... JOIN agents a ON a.id = s.agent_id s.period_start_date
        // = ? AND a.status = 'ACTIVE' ORDER BY ..." which is a parse error. The
        // leaderboard endpoint was a 500 on every request.
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Leaderboards are visible to every agent, so a full name is not. Agents
     * identify each other by agent_code, which the operator assigns and which
     * carries no personal data.
     */
    private function maskName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $last  = end($parts);

        if ($last === false || $last === '') {
            return 'Agent';
        }

        $initial = mb_substr($last, 0, 1, 'UTF-8');

        return 'Agent ' . mb_strtoupper($initial, 'UTF-8') . '.';
    }

    /**
     * Distinct periods that actually have data, newest first. Used to populate
     * the client's period selector without hardcoding dates.
     *
     * @return list<string>
     */
    public function availablePeriods(int $limit = 12): array
    {
        $rows = $this->all(
            'SELECT DISTINCT period_start_date
               FROM agent_performance_summary
              ORDER BY period_start_date DESC' . self::limitClause($limit, 52, 0)
        );

        return array_map(static fn (array $r): string => (string) $r['period_start_date'], $rows);
    }

    /**
     * Sites an agent may be scoped to, for the filter dropdown.
     *
     * @return list<array{id:int,name:string,radius_m:int}>
     */
    public function sitesForAgent(int $agentId): array
    {
        $rows = $this->all(
            'SELECT id, name, radius_m FROM agent_sites WHERE agent_id = :a AND is_active = 1 ORDER BY name',
            ['a' => $agentId]
        );

        return array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'name'      => (string) $r['name'],
            'radius_m'  => (int) $r['radius_m'],
        ], $rows);
    }

    /**
     * The whole standing for a period, in {@see self::ORDER_BY} order.
     *
     * This is what RewardService freezes when a period closes. It reuses
     * filters(), which is the point: the frozen rank and the rank the agent saw
     * on screen come out of one WHERE clause and one ORDER BY, so nobody can be
     * rewarded at a number the board never showed them. Deriving the order
     * anywhere else would reintroduce exactly the split that made the old
     * self-rank disagree with the page.
     *
     * @return list<array<string,mixed>>
     */
    public function standingForPeriod(string $periodStartDate): array
    {
        [$where, $params] = $this->filters($periodStartDate, null);

        return $this->all(
            'SELECT s.agent_id, s.total_verified_count, s.total_pending, s.total_rejected,
                    a.status AS agent_status
               FROM agent_performance_summary s
               JOIN agents a ON a.id = s.agent_id
              ' . $where . '
              ORDER BY ' . self::ORDER_BY,
            $params
        );
    }

    /**
     * Recompute and persist one agent's summary for one period.
     *
     * Exposed here rather than only inside the verification service so that
     * bin/reaggregate.php can rebuild a period after a policy change.
     */
    public function refresh(int $agentId, string $periodStartDate): void
    {
        // utcRangeForPeriod() returns ['start_utc' => ..., 'end_utc' => ...].
        // It was destructured positionally, which on an associative array yields
        // an "Undefined array key 0" warning and leaves both bounds null, so the
        // summary was recomputed over an empty window and every leaderboard read
        // came back as zero.
        $window    = \FieldPulse\Domain\PeriodResolver::utcRangeForPeriod($periodStartDate);
        $startUtc  = $window['start_utc'];
        $endUtc    = $window['end_utc'];

        $totals = (new SubmissionRepository())->summarisePeriod($agentId, $startUtc, $endUtc);

        $this->exec(
            'INSERT INTO agent_performance_summary (
                agent_id, period_start_date,
                total_verified_count, total_submissions, total_rejected, total_pending, updated_at
             ) VALUES (
                :agent_id, :period_date,
                :verified, :submissions, :rejected, :pending, UTC_TIMESTAMP()
             )
             ON DUPLICATE KEY UPDATE
                total_verified_count = VALUES(total_verified_count),
                total_submissions    = VALUES(total_submissions),
                total_rejected       = VALUES(total_rejected),
                total_pending        = VALUES(total_pending),
                updated_at           = UTC_TIMESTAMP()',
            [
                'agent_id'    => $agentId,
                'period_date' => $periodStartDate,
                'verified'    => $totals['total_verified_count'],
                'submissions' => $totals['total_submissions'],
                'rejected'    => $totals['total_rejected'],
                'pending'     => $totals['total_pending'],
            ]
        );
    }
}
