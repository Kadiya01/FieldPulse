<?php

declare(strict_types=1);

/**
 * Backfill historical MONTHLY leaderboard summaries from submissions.
 *
 *   php private_storage/bin/backfill_monthly.php
 *   php private_storage/bin/backfill_monthly.php --from=2026-01 --to=2026-10
 *   php private_storage/bin/backfill_monthly.php --period=2026-09 --dry-run
 *   php private_storage/bin/backfill_monthly.php --from=2026-01 --to=2026-10 --overwrite
 *
 * WHY THIS EXISTS
 *
 * Migration 026 adds the monthly discriminator, but it deliberately does not
 * invent monthly totals. Existing rows stay WEEKLY. This tool derives MONTHLY
 * summaries from the authoritative submission ledger, one calendar month at a
 * time, so a deployment that has historical submissions can show historical
 * monthly standings without touching any weekly row or any frozen reward.
 *
 * SAFETY RULES
 *
 *   - Only MONTHLY rows are written. WEEKLY summaries are never selected or
 *     modified.
 *   - Every total is a full recompute through SubmissionRepository, never a
 *     delta, so running this twice cannot double-count.
 *   - An existing MONTHLY row is only rewritten when --overwrite is supplied.
 *     Without it, a difference is reported as skipped_inconsistent instead of
 *     being silently replaced.
 *   - A frozen MONTHLY period (reward_rankings has rows for it) is never
 *     rewritten, even with --overwrite. The frozen reward is authoritative for
 *     that period and must not be disturbed by a live-summary repair.
 *   - reward_rankings and agent_rewards are never written here.
 *
 * Exit codes: 0 success, 1 failure, 2 inconsistent historical rows were left
 * untouched for an operator to review.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Database\RewardRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Domain\Validator;
use FieldPulse\Support\Logger;

Cli::init(__FILE__);

$argv = Cli::argv();

try {
    $dryRun    = Cli::hasFlag($argv, 'dry-run');
    $overwrite = Cli::hasFlag($argv, 'overwrite');
    $json      = Cli::hasFlag($argv, 'json');
    $agentArg  = Cli::option($argv, 'agent');
    $periodArg = Cli::option($argv, 'period');
    $fromArg   = Cli::option($argv, 'from');
    $toArg     = Cli::option($argv, 'to');

    if ($periodArg !== null && ($fromArg !== null || $toArg !== null)) {
        invalid('--period cannot be combined with --from or --to');
    }

    if ($periodArg !== null) {
        $from = normalizeMonth($periodArg, '--period');
        $to   = $from;
    } else {
        $from = $fromArg !== null
            ? normalizeMonth($fromArg, '--from')
            : earliestSubmissionMonth();
        $to = $toArg !== null
            ? normalizeMonth($toArg, '--to')
            : PeriodResolver::monthlyPeriodStartDate();
    }

    if ($from > $to) {
        invalid('--from must be on or before --to');
    }

    $months = monthsBetween($from, $to);

    if (count($months) > 240) {
        invalid('range is larger than 240 months; narrow --from/--to');
    }

    $agentId = null;

    if ($agentArg !== null && $agentArg !== '') {
        $agent = (new AgentRepository())->findByCode($agentArg);

        if ($agent === null) {
            invalid('no agent with code "' . $agentArg . '"');
        }

        $agentId = (int) $agent['id'];
    }

    $leaderboard = new LeaderboardRepository();
    $submissions = new SubmissionRepository();
    $rewards     = new RewardRepository();

    $stats = [
        'months_examined'      => 0,
        'agents_examined'      => 0,
        'created'              => 0,
        'updated'              => 0,
        'unchanged'            => 0,
        'skipped_inconsistent' => 0,
        'skipped_frozen'       => 0,
        'failed'               => 0,
    ];

    $details  = [];
    $warnings = [];

    Cli::heading('Monthly backfill');
    Cli::out('  range:    ' . $from . ' .. ' . $to . ' (' . count($months) . ' month(s))');
    Cli::out('  agent:    ' . ($agentArg ?? 'all agents'));
    Cli::out('  mode:     ' . ($dryRun ? 'dry run' : 'write') . ($overwrite ? ', overwrite unfrozen differences' : ''));
    Cli::out('');

    foreach ($months as $period) {
        $stats['months_examined']++;

        $range  = PeriodResolver::utcRangeForPeriodType($period, PeriodResolver::TYPE_MONTHLY);
        $sql    = 'SELECT DISTINCT agent_id FROM submissions
                    WHERE server_received_at >= :start
                      AND server_received_at < :end';
        $params = ['start' => $range['start_utc'], 'end' => $range['end_utc']];

        if ($agentId !== null) {
            $sql .= ' AND agent_id = :agent_id';
            $params['agent_id'] = $agentId;
        }

        $agentRows = Connection::fetchAll($sql, $params);

        if ($agentRows === []) {
            $details[] = ['period' => $period, 'agents' => 0, 'frozen' => false];
            Cli::out('  ' . $period . ': no submissions');
            continue;
        }

        $frozen = $rewards->periodIsFrozen($period, PeriodResolver::TYPE_MONTHLY);

        $month = [
            'period' => $period,
            'agents' => 0,
            'frozen' => $frozen,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_inconsistent' => 0,
            'skipped_frozen' => 0,
            'failed' => 0,
        ];

        foreach ($agentRows as $row) {
            $id = (int) $row['agent_id'];
            $stats['agents_examined']++;
            $month['agents']++;

            try {
                $totals   = $submissions->summarisePeriod($id, $range['start_utc'], $range['end_utc']);
                $existing = $leaderboard->summaryFor($id, $period, PeriodResolver::TYPE_MONTHLY);

                if ($existing === null && !$frozen) {
                    if (!$dryRun) {
                        $leaderboard->saveSummary($id, $period, PeriodResolver::TYPE_MONTHLY, $totals);
                    }

                    $stats['created']++;
                    $month['created']++;
                    continue;
                }

                if ($existing !== null && sameSummary($existing, $totals)) {
                    $stats['unchanged']++;
                    $month['unchanged']++;
                    continue;
                }

                if ($frozen) {
                    $stats['skipped_frozen']++;
                    $month['skipped_frozen']++;
                    $warnings[] = $period . ' agent ' . $id . ': frozen period left unchanged';
                    continue;
                }

                if (!$overwrite) {
                    $stats['skipped_inconsistent']++;
                    $month['skipped_inconsistent']++;
                    $warnings[] = $period . ' agent ' . $id . ': existing summary differs; pass --overwrite to replace it';
                    continue;
                }

                if (!$dryRun) {
                    $leaderboard->saveSummary($id, $period, PeriodResolver::TYPE_MONTHLY, $totals);
                }

                $stats['updated']++;
                $month['updated']++;
            } catch (Throwable $e) {
                $stats['failed']++;
                $month['failed']++;
                $warnings[] = $period . ' agent ' . $id . ': ' . $e->getMessage();

                Logger::warning('backfill_monthly.failed', [
                    'agent_id'    => $id,
                    'period'      => $period,
                    'period_type' => PeriodResolver::TYPE_MONTHLY,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $details[] = $month;

        Cli::out('  ' . $period
            . ': ' . $month['agents'] . ' agent(s)'
            . ', frozen=' . ($frozen ? 'yes' : 'no')
            . ', created=' . $month['created']
            . ', updated=' . $month['updated']
            . ', unchanged=' . $month['unchanged']
            . ', inconsistent=' . $month['skipped_inconsistent']
            . ', frozen-skipped=' . $month['skipped_frozen']
            . ', failed=' . $month['failed']);
    }

    Cli::heading('Result');
    Cli::out('  months examined:      ' . $stats['months_examined']);
    Cli::out('  agent/period records: ' . $stats['agents_examined']);
    Cli::out('  created:              ' . $stats['created']);
    Cli::out('  updated:              ' . $stats['updated']);
    Cli::out('  unchanged:            ' . $stats['unchanged']);
    Cli::out('  skipped inconsistent: ' . $stats['skipped_inconsistent']);
    Cli::out('  skipped frozen:       ' . $stats['skipped_frozen']);
    Cli::out('  failed:               ' . $stats['failed']);

    if ($dryRun) {
        Cli::out('  dry run:              no rows written');
    }

    if ($json) {
        Cli::out(json_encode([
            'dry_run'  => $dryRun,
            'overwrite' => $overwrite,
            'from'     => $from,
            'to'       => $to,
            'agent'    => $agentArg,
            'stats'    => $stats,
            'details'  => $details,
            'warnings' => $warnings,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    exit($stats['failed'] > 0 ? 1 : ($stats['skipped_inconsistent'] > 0 ? 2 : 0));
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}

/* -------------------------------------------------------------------------- */

function invalid(string $message): void
{
    Cli::fail($message);
    exit(1);
}

function normalizeMonth(string $value, string $option): string
{
    $value = trim($value);

    if (preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
        $value .= '-01';
    }

    if (!Validator::isIsoDate($value)) {
        invalid($option . ' must be YYYY-MM or YYYY-MM-01');
    }

    if ((new DateTimeImmutable($value . ' 00:00:00'))->format('Y-m-01') !== $value) {
        invalid($option . ' must identify the first day of a month');
    }

    return $value;
}

function earliestSubmissionMonth(): string
{
    $first = Connection::fetchValue('SELECT MIN(server_received_at) FROM submissions');

    if ($first === null || $first === '') {
        return PeriodResolver::monthlyPeriodStartDate();
    }

    return PeriodResolver::monthlyPeriodStartDate((string) $first);
}

/**
 * @return list<string>
 */
function monthsBetween(string $from, string $to): array
{
    $months = [];
    $cursor = new DateTimeImmutable($from . ' 00:00:00');
    $end    = new DateTimeImmutable($to . ' 00:00:00');

    while ($cursor <= $end) {
        $months[] = $cursor->format('Y-m-01');
        $cursor   = $cursor->modify('+1 month');
    }

    return $months;
}

/**
 * @param array<string,mixed> $existing
 * @param array{total_verified_count:int,total_submissions:int,total_rejected:int,total_pending:int} $totals
 */
function sameSummary(array $existing, array $totals): bool
{
    foreach ($totals as $key => $value) {
        if ((int) ($existing[$key] ?? 0) !== (int) $value) {
            return false;
        }
    }

    return true;
}
