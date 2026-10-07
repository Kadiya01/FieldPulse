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
    $dryRun    = Cli::hasFlag($argv, 'dry-run');

    if ($periodArg !== null) {
        if (Validator::isIsoDate($periodArg) === false) {
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
    }

    Cli::heading('Reward close');

    $grace = $service->graceHours();
    Cli::out('  grace window:  ' . $grace . 'h after the period ends');
    Cli::out('  now (UTC):     ' . \FieldPulse\Support\Clock::sql());
    Cli::out('');

    if ($periodArg !== null) {
        if ($dryRun) {
            $frozen = (new \FieldPulse\Database\RewardRepository())->periodIsFrozen($periodArg);
            $due    = $service->isDue($periodArg);

            Cli::out('  ' . $periodArg . ': due=' . ($due ? 'yes' : 'no')
                . ', frozen=' . ($frozen ? 'yes' : 'no'));

            exit(0);
        }

        $result = $service->closePeriod($periodArg);
        report([$result]);

        exit($result['closed'] || $result['reason'] === 'already_closed' ? 0 : 1);
    }

    // Everything due, whether that is one period or a backlog left behind by a
    // deployment whose cron had stopped.
    $cutoff = \FieldPulse\Support\Clock::now()
        ->modify('-' . $grace . ' hours')
        ->modify('-7 days');
    $cutoffPeriod = PeriodResolver::periodStartDate(\FieldPulse\Support\Clock::sql($cutoff));

    $pending = (new \FieldPulse\Database\RewardRepository())->unfrozenPeriodsOnOrBefore($cutoffPeriod);

    if ($pending === []) {
        Cli::ok('  nothing due; every period within the window is already closed');
        exit(0);
    }

    Cli::out('  ' . count($pending) . ' candidate period(s): ' . implode(', ', $pending));
    Cli::out('');

    if ($dryRun) {
        foreach ($pending as $period) {
            Cli::out('  would close ' . $period . ($service->isDue($period) ? '' : ' (not yet due)'));
        }
        exit(0);
    }

    $results = [];

    foreach ($pending as $period) {
        $results[] = $service->closePeriod($period);
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
        if ($result['closed']) {
            Cli::ok('  ' . $result['period'] . ': frozen ' . $result['frozen']
                . ', published ' . $result['published']);
            continue;
        }

        Cli::out('  ' . $result['period'] . ': not closed (' . $result['reason'] . ')');
    }
}
