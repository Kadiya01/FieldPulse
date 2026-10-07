<?php

declare(strict_types=1);

/**
 * Arrange reward state for the browser suite.
 *
 *   php private_storage/bin/e2e_state.php seed --agent=AG-001 --weeks-ago=2 --verified=42 --amount=50000 --currency=NGN
 *   php private_storage/bin/e2e_state.php bump --agent=AG-001 --weeks-ago=2 --verified=99
 *
 * The reward specs need an agent that has actually stood somewhere in a week
 * that has actually closed. Driving that through the product would mean
 * capturing and verifying submissions across a real network and a real review
 * queue for a week that is already over — a test fixture that takes days. So the
 * one thing this tool fabricates is the standing: it writes the summary row the
 * leaderboard would have written, then closes the period through the SAME
 * RewardService the cron worker calls. Everything from the freeze onward — the
 * ranking, the tier match, the entitlement, the transitions — is production
 * code. Nothing here can make a reward appear that the engine would not publish.
 *
 * `bump` is the other half. It rewrites the live summary AFTER the period has
 * frozen, which is exactly the thing the cutoff rule forbids from touching a
 * published entitlement. A spec calls it and then asserts the agent still sees
 * the frozen numbers.
 *
 * The output is a single line of JSON so a spec can read the period, the reward
 * id, and the frozen rank back out rather than recomputing them.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Http\ApiException;
use FieldPulse\Reward\RewardService;
use FieldPulse\Support\Clock;

Cli::init(__FILE__);

$argv   = Cli::argv();
$action = $argv[0] ?? '';

if (!in_array($action, ['seed', 'bump'], true)) {
    Cli::fail('usage: e2e_state.php seed|bump --agent=CODE [--period=YYYY-MM-DD | --weeks-ago=N] [--verified=N]');
    exit(1);
}

try {
    $agents = new AgentRepository();
    $code   = Cli::option($argv, 'agent');

    if ($code === null || $code === '') {
        Cli::fail('--agent is required');
        exit(1);
    }

    $agent = $agents->findByCode($code);

    if ($agent === null) {
        Cli::fail('no agent with code ' . $code);
        exit(1);
    }

    $period = resolvePeriod($argv);

    if ($action === 'bump') {
        bump($argv, (int) $agent['id'], $period);
        exit(0);
    }

    seed($argv, $agent, $period);
    exit(0);
} catch (ApiException $e) {
    Cli::fail($e->getMessage());
    exit(1);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}

/* -------------------------------------------------------------------------- */

/**
 * Which week to arrange.
 *
 * `--weeks-ago` is preferred over `--period` because the specs run against the
 * real clock: a hardcoded Monday would eventually stop being a week that has
 * closed, and the failure would look like a broken engine rather than a stale
 * fixture. `--period` stays for a spec that wants to name an exact date.
 */
function resolvePeriod(array $argv): string
{
    $explicit = Cli::option($argv, 'period');

    if ($explicit !== null && $explicit !== '') {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $explicit) !== 1) {
            throw new RuntimeException('--period must be YYYY-MM-DD');
        }

        return $explicit;
    }

    $weeks = (int) (Cli::option($argv, 'weeks-ago') ?? '2');
    $weeks = max(1, min($weeks, 52));

    return PeriodResolver::periodStartDate(
        Clock::sql(Clock::now()->modify('-' . ($weeks * 7) . ' days'))
    );
}

/**
 * Publish one entitlement for one agent in one week.
 *
 * @param array<string,mixed> $agent
 */
function seed(array $argv, array $agent, string $period): void
{
    $agentId  = (int) $agent['id'];
    $verified = max(0, (int) (Cli::option($argv, 'verified') ?? '1'));
    $pending  = max(0, (int) (Cli::option($argv, 'pending') ?? '0'));
    $rejected = max(0, (int) (Cli::option($argv, 'rejected') ?? '0'));

    $amount   = Cli::option($argv, 'amount');
    $currency = strtoupper((string) (Cli::option($argv, 'currency', 'NGN')));
    $tierMin  = (int) (Cli::option($argv, 'tier-min') ?? '1');
    $tierMax  = (int) (Cli::option($argv, 'tier-max') ?? '1');

    if ($amount !== null && $amount !== '') {
        // The band the rank falls in is an ordinary row; fill in its figure so
        // the entitlement publishes with an amount rather than as "not set".
        // Omitting --amount leaves it NULL, which is itself a state a spec
        // asserts the UI renders honestly.
        Connection::execute(
            'UPDATE reward_tiers
                SET reward_amount = :amount, currency = :currency, updated_at = UTC_TIMESTAMP()
              WHERE min_rank = :lo AND max_rank = :hi',
            [
                'amount'   => $amount,
                'currency' => $currency,
                'lo'       => $tierMin,
                'hi'       => $tierMax,
            ]
        );
    }

    $service = new RewardService();

    // Re-seeding the same week must be repeatable: clear anything a previous
    // run left so the frozen standing is exactly what this call specifies.
    Connection::execute('DELETE FROM agent_rewards WHERE period_start_date = :p', ['p' => $period]);
    Connection::execute('DELETE FROM reward_rankings WHERE period_start_date = :p', ['p' => $period]);
    Connection::execute('DELETE FROM agent_performance_summary WHERE period_start_date = :p', ['p' => $period]);

    Connection::execute(
        'INSERT INTO agent_performance_summary (
            agent_id, period_start_date,
            total_verified_count, total_submissions, total_rejected, total_pending, updated_at
         ) VALUES (
            :agent_id, :period_date,
            :verified, :submissions, :rejected, :pending, UTC_TIMESTAMP()
         )',
        [
            'agent_id'    => $agentId,
            'period_date' => $period,
            'verified'    => $verified,
            'submissions' => $verified + $pending + $rejected,
            'rejected'    => $rejected,
            'pending'     => $pending,
        ]
    );

    $result = $service->closePeriod($period);

    $reward = Connection::fetchOne(
        'SELECT r.id, r.rank, r.status, r.tier_label, r.reward_amount, r.currency,
                r.total_verified_count
           FROM agent_rewards r
          WHERE r.period_start_date = :p AND r.agent_id = :a',
        ['p' => $period, 'a' => $agentId]
    );

    emit([
        'action'  => 'seed',
        'period'  => $period,
        'agent'   => (string) $agent['agent_code'],
        'agent_id' => $agentId,
        'closed'  => $result['closed'],
        'reason'  => $result['reason'],
        'frozen'  => $result['frozen'],
        'published' => $result['published'],
        'reward'  => $reward,
    ]);
}

/**
 * Rewrite the live summary after the period has frozen.
 *
 * This is the mutation the cutoff rule is about: it is what a late verification
 * or bin/reaggregate.php would do to a historical week. A correct system leaves
 * the published entitlement untouched.
 */
function bump(array $argv, int $agentId, string $period): void
{
    $verified = max(0, (int) (Cli::option($argv, 'verified') ?? '1'));

    $changed = Connection::execute(
        'UPDATE agent_performance_summary
            SET total_verified_count = :verified,
                total_submissions = :submissions,
                updated_at = UTC_TIMESTAMP()
          WHERE agent_id = :agent_id AND period_start_date = :period_date',
        [
            'verified'      => $verified,
            'submissions'   => $verified,
            'agent_id'      => $agentId,
            'period_date'   => $period,
        ]
    );

    Cli::out(json_encode([
        'action'  => 'bump',
        'period'  => $period,
        'agent_id' => $agentId,
        'changed' => $changed,
        'verified' => $verified,
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * The last line of stdout is the machine-readable result the spec parses.
 *
 * @param array<string,mixed> $payload
 */
function emit(array $payload): void
{
    Cli::out(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}