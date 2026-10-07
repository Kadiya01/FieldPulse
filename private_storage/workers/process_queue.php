<?php

declare(strict_types=1);

/**
 * Queue worker entry point.
 *
 *   * * * * * /usr/local/bin/php /home/USER/private_storage/workers/process_queue.php
 *
 * cPanel Cron runs this every minute. All work state lives in the database, so
 * this script is safe to run concurrently with itself: overlapping ticks simply
 * compete for the same queue and the claim statement hands each worker a
 * disjoint batch.
 *
 * Optional flags:
 *   --max-jobs=N     stop after N jobs (useful for a manual run)
 *   --prune          only run TTL housekeeping, then exit
 *   --stats          print queue depth and exit
 *
 * Every tick also publishes any reward period that has passed its window plus
 * the configured grace (rewards.auto_close). Publication must not depend on
 * somebody opening the rewards screen, and this is the process that is already
 * running every minute on the host, so it is where the close belongs. The close
 * is idempotent, so the cost on an ordinary tick is one indexed query that
 * finds nothing.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Console\QueueWorker;
use FieldPulse\Database\JobRepository;
use FieldPulse\Reward\RewardService;

Cli::init(__FILE__);

$argv    = Cli::argv();
$worker  = new QueueWorker();
$maxJobs = Cli::option($argv, 'max-jobs');

try {
    if (Cli::hasFlag($argv, 'stats')) {
        Cli::heading('Queue');
        $stats = (new JobRepository())->stats();

        foreach ($stats as $status => $count) {
            Cli::out('  ' . str_pad(strtolower($status), 12) . $count);
        }

        exit(0);
    }

    if (Cli::hasFlag($argv, 'prune')) {
        Cli::heading('Pruning');
        $result = $worker->prune();

        foreach ($result as $what => $count) {
            Cli::out('  ' . str_pad($what, 16) . $count . ' removed');
        }

        exit(0);
    }

    $summary = $worker->run($maxJobs === null ? null : max(1, (int) $maxJobs));

    if (Config::instance()->bool('rewards.auto_close', true)) {
        $closed = (new RewardService())->closeDuePeriods();

        if ($closed !== []) {
            Cli::heading('Rewards');
            foreach ($closed as $period) {
                Cli::out('  closed ' . $period . ' and published its entitlements');
            }
        }
    }

    Cli::heading('Worker ' . $summary['worker']);
    Cli::out('  claimed:   ' . $summary['claimed']);
    Cli::out('  completed: ' . $summary['completed']);
    Cli::out('  retried:   ' . $summary['retried']);
    Cli::out('  failed:    ' . $summary['failed']);
    Cli::out('  recovered: ' . $summary['recovered'] . ' stale locks');
    Cli::out('  duration:  ' . $summary['duration'] . 's');

    // A non-zero exit tells cPanel the tick had trouble, which is the only
    // failure signal a cron log gives an operator.
    exit($summary['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
