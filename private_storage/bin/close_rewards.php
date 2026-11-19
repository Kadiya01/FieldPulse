<?php

declare(strict_types=1);

/**
 * Close reward periods: freeze the weekly standing and publish entitlements.
 *
 *   php private_storage/bin/close_rewards.php                 # everything due
 *   php private_storage/bin/close_rewards.php --period=2026-09-28
 *   php private_storage/bin/close_rewards.php --all --dry-run
 *
 * This is the manual twin of the cPanel cron hook in
 * workers/process_queue.php. Both call the same RewardService::closePeriod(),
 * which is idempotent, so running this on a deployment whose cron has already
 * closed the period does nothing at all — and running it twice in parallel is
 * harmless for the same reason.
 *
 * Publishing does not depend on this script or on cron being healthy: the read
 * path closes on demand when rewards.close_on_read is on. What the read path
 * cannot do is close a period nobody has looked at yet, which is why this tool
 * and the cron hook both exist.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Domain\Validator;
use FieldPulse\Reward\RewardService;

Cli::init(__FILE__);

$argv    = Cli::argv();
$service = new RewardService();

try {
    $periodArg = Cli::option($argv, 'period');
    $typeArg   = Cli::option($argv, 'type');
    $dryRun    = Cli::hasFlag($argv, 'dry-run');

    if ($typeArg !== null) {
        $typeArg = strtoupper($typeArg);
        if (!in_array($typeArg, PeriodResolver::PERIOD_TYPES, true)) {
            Cli::fail('--type must be WEEKLY or MONTHLY');
            exit(1);
        }
    }

    if ($periodArg !== null) {
        if (Validator::isIsoDate($periodArg) === false) {
            Cli::fail('--period must be a real ISO date, e.g. 2026-09-28');
            exit(1);
        }

        if ($typeArg === PeriodResolver::TYPE_MONTHLY) {
            $firstDay = (new DateTimeImmutable($periodArg . ' 00:00:00'))->format('Y-m-01');
            if ($firstDay !== $periodArg) {
                Cli::fail($periodArg . ' is not the first day of the month; the period starts on ' . $firstDay);
                exit(1);
            }
        } elseif ($typeArg === PeriodResolver::TYPE_WEEKLY) {
            $monday = (new DateTimeImmutable($periodArg . ' 00:00:00'))
                ->modify('monday this week')
                ->format('Y-m-d');

            if ($monday !== $periodArg) {
                Cli::fail($periodArg . ' is not a Monday; the period starts on ' . $monday);
                exit(1);
            }
        } else {
            // Infer type from date: Monday => WEEKLY, first of month => MONTHLY
            $isMonday = (new DateTimeImmutable($periodArg . ' 00:00:00'))
                ->modify('monday this week')
                ->format('Y-m-d') === $periodArg;
            $isFirstOfMonth = (new DateTimeImmutable($periodArg . ' 00:00:00'))->format('Y-m-01') === $periodArg;

            if ($isMonday) {
                $typeArg = PeriodResolver::TYPE_WEEKLY;
            } elseif ($isFirstOfMonth) {
                $typeArg = PeriodResolver::TYPE_MONTHLY;
            } else {
                Cli::fail($periodArg . ' is neither a Monday nor the first day of the month');
                exit(1);
            }
        }
    }

    Cli::heading('Reward close');

    $grace = $service->graceHours();
    Cli::out('  grace window:  ' . $grace . 'h after the period ends');
    Cli::out('  now (UTC):     ' . \FieldPulse\Support\Clock::sql());
    Cli::out('');

    if ($periodArg !== null) {
        $periodType = $typeArg ?? PeriodResolver::TYPE_WEEKLY;

        if ($dryRun) {
            $frozen = (new \FieldPulse\Database\RewardRepository())->periodIsFrozen($periodArg, $periodType);
            $due    = $service->isDue($periodArg, $periodType);

            Cli::out('  ' . $periodArg . ' (' . $periodType . '): due=' . ($due ? 'yes' : 'no')
                . ', frozen=' . ($frozen ? 'yes' : 'no'));

            exit(0);
        }

        $result = $service->closePeriod($periodArg, $periodType);
        report([$result]);

        exit($result['closed'] || $result['reason'] === 'already_closed' ? 0 : 1);
    }

    // Everything due, whether that is one period or a backlog left behind by a
    // deployment whose cron had stopped.
    $rewardRepo = new \FieldPulse\Database\RewardRepository();
    $typesToScan = $typeArg !== null ? [$typeArg] : [PeriodResolver::TYPE_WEEKLY, PeriodResolver::TYPE_MONTHLY];
    $candidates = [];

    foreach ($typesToScan as $scanType) {
        $cutoff = \FieldPulse\Support\Clock::now()->modify('-' . $grace . ' hours');
        if ($scanType === PeriodResolver::TYPE_MONTHLY) {
            $cutoffDate = \FieldPulse\Support\Clock::sql($cutoff->modify('-1 month'));
            $cutoffPeriod = PeriodResolver::monthlyPeriodStartDate($cutoffDate);
        } else {
            $cutoffDate = \FieldPulse\Support\Clock::sql($cutoff->modify('-7 days'));
            $cutoffPeriod = PeriodResolver::periodStartDate($cutoffDate);
        }

        $pending = $rewardRepo->unfrozenPeriodsOnOrBefore($cutoffPeriod, $scanType);
        foreach ($pending as $p) {
            $candidates[] = ['period' => $p, 'type' => $scanType];
        }
    }

    if ($candidates === []) {
        Cli::ok('  nothing due; every period within the window is already closed');
        exit(0);
    }

    $summaryStrings = array_map(static fn (array $c): string => $c['period'] . ' (' . $c['type'] . ')', $candidates);
    Cli::out('  ' . count($candidates) . ' candidate period(s): ' . implode(', ', $summaryStrings));
    Cli::out('');

    if ($dryRun) {
        foreach ($candidates as $candidate) {
            $due = $service->isDue($candidate['period'], $candidate['type']);
            Cli::out('  would close ' . $candidate['period'] . ' (' . $candidate['type'] . ')' . ($due ? '' : ' (not yet due)'));
        }
        exit(0);
    }

    $results = [];

    foreach ($candidates as $candidate) {
        $results[] = $service->closePeriod($candidate['period'], $candidate['type']);
    }

    report($results);

    // A period that was due but could not be frozen (no standing at all) is a
    // real problem worth a non-zero exit; everything else is success.
    $stuck = array_filter($results, static fn (array $r): bool => $r['reason'] === 'no_standing');

    exit($stuck === [] ? 0 : 1);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}

/**
 * @param list<array{closed:bool,reason:string,period:string,frozen:int,published:int}> $results
 */
function report(array $results): void
{
    Cli::heading('Result');

    foreach ($results as $result) {
        $typeStr = isset($result['period_type']) ? ' (' . $result['period_type'] . ')' : '';
        if ($result['closed']) {
            Cli::ok('  ' . $result['period'] . $typeStr . ': frozen ' . $result['frozen']
                . ', published ' . $result['published']);
            continue;
        }

        Cli::out('  ' . $result['period'] . $typeStr . ': not closed (' . $result['reason'] . ')');
    }
}
