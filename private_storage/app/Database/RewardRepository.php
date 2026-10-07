<?php

declare(strict_types=1);

namespace FieldPulse\Database;

/**
 * SQL for the reward tables (migrations 020/021/022).
 *
 * Repositories own SQL and nothing else, so the policy that decides *when* a
 * period closes and *what* an entitlement is worth lives in Reward\RewardService.
 * What is kept here instead is the part that has to be structurally true no
 * matter who calls it: the freeze is written once, entitlements are written only
 * from a freeze, and an entitlement that has already been published is never
 * rewritten by a later close.
 */
final class RewardRepository extends Repository
{
    public const STATUS_PENDING  = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PAID     = 'PAID';
    public const STATUS_VOID     = 'VOID';

    /* -----------------------------------------------------------------------
     * Closing
     * -------------------------------------------------------------------- */

    /**
     * Has this period already been frozen?
     *
     * The fast path for closePeriod(): the answer is one indexed count, and it
     * is what makes a cron tick that fires every minute cost nothing once the
     * week it is watching has closed.
     */
    public function periodIsFrozen(string $periodStartDate): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM reward_rankings WHERE period_start_date = :period',
            ['period' => $periodStartDate]
        ) > 0;
    }

    /**
     * Periods that have data, have not been frozen, and started on or before
     * $cutoffPeriod — the newest period whose window plus grace has already
     * passed. RewardService derives the cutoff from PeriodResolver so the
     * business-timezone boundary is not re-implemented here.
     *
     * @return list<string>
     */
    public function unfrozenPeriodsOnOrBefore(string $cutoffPeriod): array
    {
        $rows = $this->all(
            'SELECT DISTINCT period_start_date
               FROM agent_performance_summary
              WHERE period_start_date <= :cutoff
                AND NOT EXISTS (
                    SELECT 1 FROM reward_rankings r
                     WHERE r.period_start_date = agent_performance_summary.period_start_date
                )
              ORDER BY period_start_date',
            ['cutoff' => $cutoffPeriod]
        );

        return array_map(static fn (array $r): string => (string) $r['period_start_date'], $rows);
    }

    /**
     * Write the frozen order.
     *
     * IGNORE rather than a bare insert: two cron ticks can reach this at the
     * same instant, and the loser must become a no-op rather than a duplicate
     * key failure that rolls back a close somebody else just committed. The
     * PRIMARY KEY (period_start_date, rank) is what does the rejecting.
     *
     * @param list<array{agent_id:int,rank:int,verified:int,pending:int,rejected:int,status:string}> $rows
     */
    public function insertRankings(string $periodStartDate, array $rows): int
    {
        $written = 0;

        // Chunked so a very large roster cannot exceed MySQL's placeholder
        // ceiling (65535 per statement).
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $i => $row) {
                $values[] = "(:p{$i}, :r{$i}, :a{$i}, :v{$i}, :t{$i}, :j{$i}, :s{$i}, UTC_TIMESTAMP())";

                $params["p{$i}"] = $periodStartDate;
                $params["r{$i}"] = $row['rank'];
                $params["a{$i}"] = $row['agent_id'];
                $params["v{$i}"] = $row['verified'];
                $params["t{$i}"] = $row['pending'];
                $params["j{$i}"] = $row['rejected'];
                $params["s{$i}"] = $row['status'];
            }

            $written += $this->exec(
                'INSERT IGNORE INTO reward_rankings (
                    period_start_date, rank, agent_id,
                    total_verified_count, total_pending, total_rejected,
                    agent_status_at_close, snapshot_at
                 ) VALUES ' . implode(', ', $values),
                $params
            );
        }

        return $written;
    }

    /**
     * The schedule, lowest band first.
     *
     * @return list<array{id:int,min_rank:int,max_rank:int,tier_label:string,reward_amount:?float,currency:?string}>
     */
    public function activeTiers(): array
    {
        $rows = $this->all(
            'SELECT id, min_rank, max_rank, tier_label, reward_amount, currency
               FROM reward_tiers
              WHERE is_active = 1
              ORDER BY min_rank, max_rank'
        );

        return array_map(static fn (array $r): array => [
            'id'            => (int) $r['id'],
            'min_rank'      => (int) $r['min_rank'],
            'max_rank'      => (int) $r['max_rank'],
            'tier_label'    => (string) $r['tier_label'],
            'reward_amount' => $r['reward_amount'] === null ? null : (float) $r['reward_amount'],
            'currency'      => $r['currency'] === null ? null : (string) $r['currency'],
        ], $rows);
    }

    /**
     * Publish entitlements for a freshly frozen period.
     *
     * The no-op ON DUPLICATE KEY UPDATE is the "never downgrade" rule from
     * migration 022, expressed where it cannot be forgotten: a row that already
     * exists was published under a previous close and is left byte for byte as
     * it was, so an APPROVED or PAID entitlement can never be walked back to
     * PENDING by a close that ran twice. uniq_reward_agent_period is the key
     * that fires.
     *
     * Also note the FK this inherits for free: agent_rewards cannot be written
     * for an agent and week that reward_rankings does not already hold, because
     * (period_start_date, agent_id) references it. There is no code path that
     * could re-derive a reward from live summary numbers — the schema refuses.
     *
     * @param list<array{
     *   agent_id:int,rank:int,verified:int,
     *   tier_id:?int,tier_label:?string,reward_amount:?float,currency:?string
     * }> $rows
     */
    public function insertRewards(string $periodStartDate, array $rows): int
    {
        $written = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $params = [];

            foreach ($chunk as $i => $row) {
                $values[] = "(:p{$i}, :a{$i}, :r{$i}, :v{$i}, :t{$i}, :l{$i}, :m{$i}, :c{$i},
                              'PENDING', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())";

                $params["p{$i}"] = $periodStartDate;
                $params["a{$i}"] = $row['agent_id'];
                $params["r{$i}"] = $row['rank'];
                $params["v{$i}"] = $row['verified'];
                $params["t{$i}"] = $row['tier_id'];
                $params["l{$i}"] = $row['tier_label'];
                $params["m{$i}"] = $row['reward_amount'];
                $params["c{$i}"] = $row['currency'];
            }

            // The trailing assignment after VALUES() is only reachable on the
            // duplicate-key branch; see the docblock above.
            $written += $this->exec(
                'INSERT INTO agent_rewards (
                    period_start_date, agent_id, rank, total_verified_count,
                    tier_id, tier_label, reward_amount, currency,
                    status, published_at, created_at, updated_at
                 ) VALUES ' . implode(', ', $values) . '
                 ON DUPLICATE KEY UPDATE updated_at = updated_at',
                $params
            );
        }

        return $written;
    }

    /* -----------------------------------------------------------------------
     * Reading
     * -------------------------------------------------------------------- */

    /**
     * One agent's published entitlements, newest period first.
     *
     * @return list<array<string,mixed>>
     */
    public function forAgent(int $agentId, int $limit = 24): array
    {
        return $this->all(
            'SELECT r.*, a.agent_code
               FROM agent_rewards r
               JOIN agents a ON a.id = r.agent_id
              WHERE r.agent_id = :agent
              ORDER BY r.period_start_date DESC' . self::limitClause($limit, 120, 0),
            ['agent' => $agentId]
        );
    }

    /**
     * One period's published entitlements, in frozen-rank order.
     *
     * @return list<array<string,mixed>>
     */
    public function forPeriod(string $periodStartDate, int $limit, int $offset): array
    {
        return $this->all(
            'SELECT r.*, a.agent_code, a.full_name
               FROM agent_rewards r
               JOIN agents a ON a.id = r.agent_id
              WHERE r.period_start_date = :period
              ORDER BY r.rank' . self::limitClause($limit, 100, $offset),
            ['period' => $periodStartDate]
        );
    }

    public function countForPeriod(string $periodStartDate): int
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM agent_rewards WHERE period_start_date = :period',
            ['period' => $periodStartDate]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $rewardId): ?array
    {
        return $this->one(
            'SELECT r.*, a.agent_code, a.full_name
               FROM agent_rewards r
               JOIN agents a ON a.id = r.agent_id
              WHERE r.id = :id',
            ['id' => $rewardId]
        );
    }

    /**
     * Closed periods that actually produced at least one entitlement, newest
     * first. Used to populate a period selector without hardcoding dates.
     *
     * @return list<string>
     */
    public function closedPeriods(int $limit = 24): array
    {
        $rows = $this->all(
            'SELECT DISTINCT period_start_date
               FROM agent_rewards
              ORDER BY period_start_date DESC' . self::limitClause($limit, 120, 0)
        );

        return array_map(static fn (array $r): string => (string) $r['period_start_date'], $rows);
    }

    /* -----------------------------------------------------------------------
     * Decisions
     *
     * One method per transition rather than one generic UPDATE with the SET
     * clause passed in, because the columns an operator may touch differ per
     * transition and a generic one would have to accept them all. Each also
     * carries `AND status = <expected>`: the guard is inside the statement, so
     * two operators clicking at once produce rowCount 0 for the loser instead
     * of the second click silently overwriting the first. Whatever the service
     * thinks the state is, the database is asked to confirm it.
     *
     * None of these can write created_at, published_at, period_start_date or
     * total_verified_count: those are set once at close and are not operator
     * inputs.
     * -------------------------------------------------------------------- */

    public function approve(int $rewardId, int $operatorId): int
    {
        return $this->exec(
            "UPDATE agent_rewards
                SET status = 'APPROVED',
                    approved_at = UTC_TIMESTAMP(),
                    approved_by_operator_id = :operator,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id
                AND status = 'PENDING'",
            ['operator' => $operatorId, 'id' => $rewardId]
        );
    }

    public function pay(int $rewardId, int $operatorId): int
    {
        return $this->exec(
            "UPDATE agent_rewards
                SET status = 'PAID',
                    paid_at = UTC_TIMESTAMP(),
                    paid_by_operator_id = :operator,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id
                AND status = 'APPROVED'",
            ['operator' => $operatorId, 'id' => $rewardId]
        );
    }

    /**
     * A void reason is mandatory at the schema level of the application, not
     * here: cancelling an entitlement someone has already been shown without
     * saying why produces an audit row that cannot answer the only question
     * anyone will later ask of it.
     */
    public function void(int $rewardId, int $operatorId, string $reason): int
    {
        return $this->exec(
            "UPDATE agent_rewards
                SET status = 'VOID',
                    voided_at = UTC_TIMESTAMP(),
                    voided_by_operator_id = :operator,
                    void_reason = :reason,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = :id
                AND status IN ('PENDING', 'APPROVED')",
            ['operator' => $operatorId, 'reason' => $reason, 'id' => $rewardId]
        );
    }
}
