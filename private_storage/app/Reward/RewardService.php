<?php

declare(strict_types=1);

namespace FieldPulse\Reward;

use FieldPulse\Config\Config;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Database\RewardRepository;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;

/**
 * Reward periods: close, publish, decide (§14).
 *
 * The whole feature rests on one sentence: a reward is a function of the frozen
 * weekly standing and of nothing else. Everything below is that sentence made
 * mechanical.
 *
 * CLOSING
 *
 * A period becomes closable once its UTC window plus a grace window has passed.
 * closePeriod() then copies the standing out of agent_performance_summary into
 * reward_rankings and writes entitlements onto agent_rewards — both inside one
 * transaction, both keyed so a second run is a no-op rather than a second
 * reward.
 *
 * Publication does not depend on anyone opening the rewards screen. The cPanel
 * cron worker calls closeDuePeriods() on every tick when rewards.auto_close is
 * on; bin/close_rewards.php does the same by hand; and the read path keeps a
 * close-on-read fallback for a deployment whose cron has stopped. All three run
 * the same idempotent closePeriod(), so whichever gets there first wins and the
 * others find nothing left to do.
 *
 * THE CUTOFF RULE
 *
 * At period close, only the frozen verified weekly ranking determines reward
 * eligibility and rank; later changes to live performance summaries do not
 * change an already published reward entitlement. That is why closePeriod()
 * writes to reward_rankings and never reads from it to "refresh", and why an
 * entitlement that already exists is left exactly as it was by any subsequent
 * close.
 *
 * PUBLICATION IS NOT PAYMENT
 *
 * Closing publishes PENDING. APPROVED and PAID are separate acts by an
 * operator, and nothing in this class can move money.
 */
final class RewardService
{
    public const ACTION_APPROVE = 'APPROVE';
    public const ACTION_PAY     = 'PAY';
    public const ACTION_VOID    = 'VOID';

    /**
     * Legal state changes, in the only order they may happen.
     *
     * PENDING -> APPROVED -> PAID, with VOID reachable from PENDING or APPROVED
     * and from nowhere else: once something has been marked paid, cancelling it
     * would erase the record of a payment rather than record a cancellation.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        self::ACTION_APPROVE => [RewardRepository::STATUS_PENDING],
        self::ACTION_PAY     => [RewardRepository::STATUS_APPROVED],
        self::ACTION_VOID    => [RewardRepository::STATUS_PENDING, RewardRepository::STATUS_APPROVED],
    ];

    public function __construct(
        private readonly RewardRepository $rewards = new RewardRepository(),
        private readonly LeaderboardRepository $board = new LeaderboardRepository(),
        private readonly AuditRepository $audit = new AuditRepository()
    ) {
    }

    /* -----------------------------------------------------------------------
     * Closing
     * -------------------------------------------------------------------- */

    /**
     * The straggler margin, in hours, after a period's UTC window ends.
     *
     * It is not an extension of the period: a report that arrives inside the
     * grace still belongs to the week it was captured in, it simply has to be
     * verified before the standing is read out.
     */
    public function graceHours(): int
    {
        return max(0, Config::instance()->int('rewards.close_grace_hours', 24));
    }

    /**
     * Has this period's window plus grace passed?
     */
    public function isDue(string $periodStartDate): bool
    {
        $window = PeriodResolver::utcRangeForPeriod($periodStartDate);
        $ends   = Clock::parseSql($window['end_utc']);

        if ($ends === null) {
            return false;
        }

        return Clock::now() >= $ends->modify('+' . $this->graceHours() . ' hours');
    }

    /**
     * Close every period that is due and has not been frozen yet.
     *
     * The cutoff is derived rather than queried: a period is due exactly when
     * period_start_date <= (now - grace - 7 days) expressed as a period start,
     * which reduces the candidate set to one indexed range scan before any
     * period is opened at all. PeriodResolver does the business-timezone
     * arithmetic so this class never has to know what a Monday is.
     *
     * @return list<string> the periods this call closed
     */
    public function closeDuePeriods(): array
    {
        $cutoffInstant = Clock::now()
            ->modify('-' . $this->graceHours() . ' hours')
            ->modify('-7 days');

        $cutoffPeriod = PeriodResolver::periodStartDate(Clock::sql($cutoffInstant));

        $closed = [];

        foreach ($this->rewards->unfrozenPeriodsOnOrBefore($cutoffPeriod) as $period) {
            $result = $this->closePeriod($period);

            if ($result['closed']) {
                $closed[] = $period;
            }
        }

        return $closed;
    }

    /**
     * Freeze one period and publish its entitlements.
     *
     * Idempotent by construction rather than by locking: the frozen check, the
     * INSERT IGNORE on reward_rankings, and the no-op ON DUPLICATE KEY UPDATE
     * on agent_rewards each independently make a repeat call a no-op, so the
     * cron worker, the CLI, and a read-path fallback can all race safely.
     *
     * @return array{closed:bool,reason:string,period:string,frozen:int,published:int}
     */
    public function closePeriod(string $periodStartDate): array
    {
        if ($this->rewards->periodIsFrozen($periodStartDate)) {
            return $this->notClosed($periodStartDate, 'already_closed');
        }

        if (!$this->isDue($periodStartDate)) {
            return $this->notClosed($periodStartDate, 'not_due');
        }

        return Connection::transaction(function () use ($periodStartDate): array {
            // Re-checked inside the transaction: two ticks can both pass the
            // check above, and only the one that still sees an unfrozen period
            // should do the work.
            if ($this->rewards->periodIsFrozen($periodStartDate)) {
                return $this->notClosed($periodStartDate, 'already_closed');
            }

            $standing = $this->board->standingForPeriod($periodStartDate);

            if ($standing === []) {
                // Every agent that week is inactive, or the summary row was
                // removed after the cutoff scan. Nothing to rank, and no marker
                // row could represent an empty freeze, so the period stays
                // unclosed and is simply looked at again next tick.
                return $this->notClosed($periodStartDate, 'no_standing');
            }

            $tiers = $this->rewards->activeTiers();

            $rankings     = [];
            $entitlements = [];

            foreach ($standing as $index => $row) {
                $rank = $index + 1;

                $rankings[] = [
                    'agent_id' => (int) $row['agent_id'],
                    'rank'     => $rank,
                    'verified' => (int) $row['total_verified_count'],
                    'pending'  => (int) $row['total_pending'],
                    'rejected' => (int) $row['total_rejected'],
                    'status'   => (string) $row['agent_status'],
                ];

                $tier = $this->tierFor($rank, $tiers);

                if ($tier === null) {
                    // Outside every band: not rewarded, rather than rewarded
                    // with nothing. Absence of a band is the policy.
                    continue;
                }

                $entitlements[] = [
                    'agent_id'      => (int) $row['agent_id'],
                    'rank'          => $rank,
                    'verified'      => (int) $row['total_verified_count'],
                    'tier_id'       => $tier['id'],
                    'tier_label'    => $tier['tier_label'],
                    'reward_amount' => $tier['reward_amount'],
                    'currency'      => $tier['currency'],
                ];
            }

            $frozen    = $this->rewards->insertRankings($periodStartDate, $rankings);
            $published = $this->rewards->insertRewards($periodStartDate, $entitlements);

            // actor_agent_id is null on purpose: no operator closed this period,
            // the schedule did. Recording an operator here would credit someone
            // with a decision they never took.
            $this->audit->recordSafe([
                'actor_agent_id' => null,
                'action'         => 'reward.period_closed',
                'entity_type'    => 'reward_period',
                'metadata'       => [
                    'period_start_date' => $periodStartDate,
                    'frozen'            => $frozen,
                    'published'         => $published,
                    'grace_hours'       => $this->graceHours(),
                ],
            ]);

            Logger::info('reward.period_closed', [
                'period_start_date' => $periodStartDate,
                'frozen'            => $frozen,
                'published'         => $published,
            ]);

            return [
                'closed'   => true,
                'reason'   => 'closed',
                'period'   => $periodStartDate,
                'frozen'   => $frozen,
                'published' => $published,
            ];
        });
    }

    /**
     * The read-path fallback.
     *
     * Called on every rewards read when rewards.close_on_read is on. It does
     * the same work as the cron hook, so on a deployment where cron has died a
     * period still publishes the moment somebody looks; and because it is the
     * same idempotent close, enabling both costs one indexed query per read and
     * nothing more.
     *
     * @return list<string>
     */
    public function closeIfDue(): array
    {
        if (!Config::instance()->bool('rewards.close_on_read', true)) {
            return [];
        }

        return $this->closeDuePeriods();
    }

    /**
     * The band a rank falls in, or null when no active band covers it.
     *
     * @param  list<array{id:int,min_rank:int,max_rank:int,tier_label:string,reward_amount:?float,currency:?string}> $tiers
     * @return array{id:int,min_rank:int,max_rank:int,tier_label:string,reward_amount:?float,currency:?string}|null
     */
    private function tierFor(int $rank, array $tiers): ?array
    {
        foreach ($tiers as $tier) {
            if ($rank >= $tier['min_rank'] && $rank <= $tier['max_rank']) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * @return array{closed:bool,reason:string,period:string,frozen:int,published:int}
     */
    private function notClosed(string $period, string $reason): array
    {
        return [
            'closed'   => false,
            'reason'   => $reason,
            'period'   => $period,
            'frozen'   => 0,
            'published' => 0,
        ];
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
        $this->closeIfDue();

        return $this->rewards->forAgent($agentId, $limit);
    }

    /**
     * One period's published entitlements, in frozen-rank order.
     *
     * @return array{items:list<array<string,mixed>>,total:int,closed:bool}
     */
    public function forPeriod(string $periodStartDate, int $limit, int $offset): array
    {
        $this->closeIfDue();

        return [
            'items'  => $this->rewards->forPeriod($periodStartDate, $limit, $offset),
            'total'  => $this->rewards->countForPeriod($periodStartDate),
            'closed' => $this->rewards->periodIsFrozen($periodStartDate),
        ];
    }

    /**
     * @return list<string>
     */
    public function closedPeriods(int $limit = 24): array
    {
        $this->closeIfDue();

        return $this->rewards->closedPeriods($limit);
    }

    /**
     * @return list<array{id:int,min_rank:int,max_rank:int,tier_label:string,reward_amount:?float,currency:?string}>
     */
    public function tiers(): array
    {
        return $this->rewards->activeTiers();
    }

    /* -----------------------------------------------------------------------
     * Decisions
     * -------------------------------------------------------------------- */

    /**
     * Record an operator's decision about one entitlement.
     *
     * @param  string $action one of {@see self::ACTION_*}
     * @param  ?string $reason mandatory for VOID
     * @return array<string,mixed> the row as it now stands
     */
    public function decide(int $rewardId, string $action, int $operatorId, ?string $reason = null): array
    {
        if (!isset(self::TRANSITIONS[$action])) {
            throw ApiException::validation(
                'action must be APPROVE, PAY or VOID.',
                ['field' => 'action', 'allowed' => array_keys(self::TRANSITIONS)]
            );
        }

        $row = $this->rewards->find($rewardId);

        if ($row === null) {
            throw ApiException::notFound(ErrorCode::NOT_FOUND, 'No such reward.');
        }

        $current = (string) $row['status'];
        $allowed = self::TRANSITIONS[$action];

        if (!in_array($current, $allowed, true)) {
            throw new ApiException(
                409,
                ErrorCode::STATE_CONFLICT,
                'A reward in status ' . $current . ' cannot be ' . $this->pastTense($action) . '.',
                ['status' => $current, 'allowed' => $allowed]
            );
        }

        $changed = match ($action) {
            self::ACTION_APPROVE => $this->rewards->approve($rewardId, $operatorId),
            self::ACTION_PAY     => $this->rewards->pay($rewardId, $operatorId),
            self::ACTION_VOID    => $this->rewards->void($rewardId, $operatorId, (string) $reason),
        };

        if ($changed === 0) {
            // The guard inside the UPDATE lost a race. The state the caller
            // asked to move from is no longer the state the row is in, which is
            // a conflict and not a success.
            throw new ApiException(
                409,
                ErrorCode::STATE_CONFLICT,
                'This reward was changed by someone else. Reload and try again.',
                ['status' => (string) ($this->rewards->find($rewardId)['status'] ?? 'UNKNOWN')]
            );
        }

        $this->audit->recordSafe([
            'actor_agent_id' => $operatorId,
            'action'         => 'reward.' . strtolower($action),
            'entity_type'    => 'agent_reward',
            'entity_id'      => $rewardId,
            'metadata'       => [
                'period_start_date' => $row['period_start_date'],
                'agent_code'        => $row['agent_code'],
                'from'              => $current,
                'to'                => match ($action) {
                    self::ACTION_APPROVE => RewardRepository::STATUS_APPROVED,
                    self::ACTION_PAY     => RewardRepository::STATUS_PAID,
                    self::ACTION_VOID    => RewardRepository::STATUS_VOID,
                },
                'reason'            => $reason,
            ],
        ]);

        Logger::info('reward.decided', [
            'reward_id' => $rewardId,
            'action'    => $action,
            'from'      => $current,
        ]);

        return $this->rewards->find($rewardId) ?? [];
    }

    private function pastTense(string $action): string
    {
        return match ($action) {
            self::ACTION_APPROVE => 'approved',
            self::ACTION_PAY     => 'paid',
            default              => 'voided',
        };
    }
}
