<?php

declare(strict_types=1);

/**
 * Rebuild the weekly leaderboard summary from `submissions`.
 *
 *   php private_storage/bin/reaggregate.php --period=2026-09-28
 *   php private_storage/bin/reaggregate.php --period=2026-09-28 --agent=AG-001
 *   php private_storage/bin/reaggregate.php --all
 *
 * Summary rows are written incrementally as submissions are dispositioned, and
 * each write is a full recompute of that agent's period, so they should never
 * need repairing. This tool exists for the two cases where they legitimately do:
 *
 *   - VERIFICATION_VERSION or a threshold changed, so past verdicts are now wrong
 *   - a restore from backup, or an import, left the summary behind the ledger
 *
 * Every counter is recomputed from the submissions table rather than adjusted by
 * a delta, so running it twice cannot double anything. That property is the
 * entire reason to prefer a recompute over an increment, and it is why this tool
 * is safe to run repeatedly and in parallel with a live worker.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Support\Logger;

Cli::init(__FILE__);

$argv       = Cli::argv();
$leaderboard = new LeaderboardRepository();
$agents      = new AgentRepository();

try {
    $periodArg = Cli::option($argv, 'period');
    $agentArg  = Cli::option($argv, 'agent');
    $all       = Cli::hasFlag($argv, 'all');

    if ($periodArg === null && !$all) {
        Cli::fail('Pass --period=YYYY-MM-DD (a Monday) or --all');
        exit(1);
    }

    // --- Resolve the set of (agent, period) pairs to rebuild ----------------
    $pairs = [];

    if ($all) {
        Cli::heading('Discovering work');

        $periods = Connection::fetchAll(
            'SELECT DISTINCT period_start_date FROM agent_performance_summary ORDER BY period_start_date'
        );

        if ($periods === []) {
            Cli::out('  no summary rows exist yet; nothing to rebuild');
            exit(0);
        }

        foreach ($periods as $row) {
            $period = (string) $row['period_start_date'];

            $agentIds = Connection::fetchAll(
                'SELECT agent_id FROM agent_performance_summary WHERE period_start_date = :p',
                ['p' => $period]
            );

            foreach ($agentIds as $a) {
                $pairs[] = [(int) $a['agent_id'], $period];
            }
        }

        Cli::out('  ' . count($pairs) . ' agent/period pairs');
    } else {
        if (\FieldPulse\Domain\Validator::isIsoDate($periodArg) === false) {
            Cli::fail('--period must be a real ISO date, e.g. 2026-09-28');
            exit(1);
        }

        $monday = (new DateTimeImmutable($periodArg . ' 00:00:00'))
            ->modify('monday this week')
            ->format('Y-m-d');

        if ($monday !== $periodArg) {
            Cli::fail($periodArg . ' is not a Monday; the period starts on ' . $monday);
            exit(1);
        }

        $sql    = 'SELECT DISTINCT agent_id FROM submissions WHERE server_received_at >= :s AND server_received_at < :e';
        $params = ['s' => PeriodResolver::utcRangeForPeriod($periodArg)['start_utc'],
                   'e' => PeriodResolver::utcRangeForPeriod($periodArg)['end_utc']];

        if ($agentArg !== null) {
            $agent = $agents->findByCode($agentArg);

            if ($agent === null) {
                Cli::fail('no agent with code "' . $agentArg . '"');
                exit(1);
            }

            $sql .= ' AND agent_id = :a';
            $params['a'] = (int) $agent['id'];
        }

        foreach (Connection::fetchAll($sql, $params) as $row) {
            $pairs[] = [(int) $row['agent_id'], $periodArg];
        }

        Cli::out('  ' . count($pairs) . ' agent/period pairs for ' . $periodArg);
    }

    if ($pairs === []) {
        Cli::out('  nothing to do');
        exit(0);
    }

    // --- Rebuild -------------------------------------------------------------
    Cli::heading('Rebuilding');

    $rebuilt = 0;
    $failed  = 0;

    foreach ($pairs as [$agentId, $period]) {
        try {
            $leaderboard->refresh($agentId, $period);
            $rebuilt++;
        } catch (Throwable $e) {
            $failed++;
            Cli::warn('  agent ' . $agentId . ' ' . $period . ': ' . $e->getMessage());
            Logger::warning('reaggregate.failed', [
                'agent_id' => $agentId,
                'period'   => $period,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    Cli::out('  rebuilt: ' . $rebuilt);
    Cli::out('  failed:  ' . $failed);

    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
