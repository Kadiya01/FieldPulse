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
    $typeArg   = Cli::option($argv, 'type');
    $all       = Cli::hasFlag($argv, 'all');

    if ($typeArg !== null) {
        $typeArg = strtoupper($typeArg);
        if (!in_array($typeArg, PeriodResolver::PERIOD_TYPES, true)) {
            Cli::fail('--type must be WEEKLY or MONTHLY');
            exit(1);
        }
    }

    if ($periodArg === null && !$all) {
        Cli::fail('Pass --period=YYYY-MM-DD or --all');
        exit(1);
    }

    // --- Resolve the set of (agent, period, periodType) tuples to rebuild ---
    $pairs = [];

    if ($all) {
        Cli::heading('Discovering work');

        $whereClause = $typeArg !== null ? ' WHERE period_type = :t' : '';
        $params      = $typeArg !== null ? ['t' => $typeArg] : [];

        $periods = Connection::fetchAll(
            'SELECT DISTINCT period_start_date, period_type FROM agent_performance_summary' . $whereClause . ' ORDER BY period_start_date, period_type',
            $params
        );

        if ($periods === []) {
            Cli::out('  no summary rows exist yet; nothing to rebuild');
            exit(0);
        }

        foreach ($periods as $row) {
            $period     = (string) $row['period_start_date'];
            $periodType = (string) ($row['period_type'] ?? PeriodResolver::TYPE_WEEKLY);

            $agentIds = Connection::fetchAll(
                'SELECT agent_id FROM agent_performance_summary WHERE period_start_date = :p AND period_type = :t',
                ['p' => $period, 't' => $periodType]
            );

            foreach ($agentIds as $a) {
                $pairs[] = [(int) $a['agent_id'], $period, $periodType];
            }
        }

        Cli::out('  ' . count($pairs) . ' agent/period pairs');
    } else {
        if (\FieldPulse\Domain\Validator::isIsoDate($periodArg) === false) {
            Cli::fail('--period must be a real ISO date, e.g. 2026-09-28');
            exit(1);
        }

        $periodType = $typeArg;
        if ($periodType === null) {
            $isMonday = (new DateTimeImmutable($periodArg . ' 00:00:00'))
                ->modify('monday this week')
                ->format('Y-m-d') === $periodArg;
            $isFirstOfMonth = (new DateTimeImmutable($periodArg . ' 00:00:00'))->format('Y-m-01') === $periodArg;

            if ($isMonday) {
                $periodType = PeriodResolver::TYPE_WEEKLY;
            } elseif ($isFirstOfMonth) {
                $periodType = PeriodResolver::TYPE_MONTHLY;
            } else {
                Cli::fail($periodArg . ' is neither a Monday nor the first day of the month');
                exit(1);
            }
        }

        if ($periodType === PeriodResolver::TYPE_MONTHLY) {
            $firstDay = (new DateTimeImmutable($periodArg . ' 00:00:00'))->format('Y-m-01');
            if ($firstDay !== $periodArg) {
                Cli::fail($periodArg . ' is not the first day of the month; the period starts on ' . $firstDay);
                exit(1);
            }
        } else {
            $monday = (new DateTimeImmutable($periodArg . ' 00:00:00'))
                ->modify('monday this week')
                ->format('Y-m-d');

            if ($monday !== $periodArg) {
                Cli::fail($periodArg . ' is not a Monday; the period starts on ' . $monday);
                exit(1);
            }
        }

        $range  = PeriodResolver::utcRangeForPeriodType($periodArg, $periodType);
        $sql    = 'SELECT DISTINCT agent_id FROM submissions WHERE server_received_at >= :s AND server_received_at < :e';
        $params = ['s' => $range['start_utc'], 'e' => $range['end_utc']];

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
            $pairs[] = [(int) $row['agent_id'], $periodArg, $periodType];
        }

        Cli::out('  ' . count($pairs) . ' agent/period pairs for ' . $periodArg . ' (' . $periodType . ')');
    }

    if ($pairs === []) {
        Cli::out('  nothing to do');
        exit(0);
    }

    // --- Rebuild -------------------------------------------------------------
    Cli::heading('Rebuilding');

    $rebuilt = 0;
    $failed  = 0;

    foreach ($pairs as [$agentId, $period, $pType]) {
        try {
            $leaderboard->refresh($agentId, $period, $pType);
            $rebuilt++;
        } catch (Throwable $e) {
            $failed++;
            Cli::warn('  agent ' . $agentId . ' ' . $period . ' (' . $pType . '): ' . $e->getMessage());
            Logger::warning('reaggregate.failed', [
                'agent_id'    => $agentId,
                'period'      => $period,
                'period_type' => $pType,
                'error'       => $e->getMessage(),
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
