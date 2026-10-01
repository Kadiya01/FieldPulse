<?php

declare(strict_types=1);

/**
 * TTL housekeeping.
 *
 *   php private_storage/bin/prune.php
 *   php private_storage/bin/prune.php --dry-run
 *
 * Safe to run from cron. Everything it deletes is either expired, already
 * superseded, or bounded by a retention window, and every delete is batched so a
 * single tick cannot hold a metadata lock long enough to stall live requests on
 * a shared host.
 *
 * Suggest running it hourly rather than every minute: the worker prunes as a side
 * effect, so this is the belt to that braces.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Console\QueueWorker;
use FieldPulse\Config\Config;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\JobRepository;
use FieldPulse\Database\RefreshTokenRepository;
use FieldPulse\Security\NonceGuard;
use FieldPulse\Security\RateLimiter;
use FieldPulse\Support\Logger;

Cli::init(__FILE__);

$argv    = Cli::argv();
$dryRun  = Cli::hasFlag($argv, 'dry-run');
$batch   = 2000;

try {
    $c = Config::instance();

    $jobDays = (int) (Cli::option($argv, 'job-days') ?? $c->int('ops.job_retention_days', 7));
    $auditDays = (int) (Cli::option($argv, 'audit-days') ?? $c->int('ops.audit_retention_days', 730));
    $loginDays = (int) (Cli::option($argv, 'login-days') ?? $c->int('ops.login_attempt_retention_days', 30));

    Cli::heading($dryRun ? 'Prune (dry run — nothing will be deleted)' : 'Prune');

    // Each entry is [label, callable]. A failure in one bucket must not stop the
    // others, or a single locked table would block all housekeeping indefinitely.
    $buckets = [
        ['request nonces'    , static fn (): int => NonceGuard::prune($batch)],
        ['refresh tokens'    , static fn (): int => (new RefreshTokenRepository())->pruneExpired($batch)],
        ['login attempts'    , static fn (): int => RateLimiter::pruneOlderThanDays($loginDays)],
        ['expired pairings'  , static fn (): int => \FieldPulse\Database\Connection::execute(
            'DELETE FROM pairing_codes WHERE expires_at < UTC_TIMESTAMP() ORDER BY id ASC LIMIT ' . $batch
        )],
        ['completed jobs'    , static fn (): int => (new JobRepository())->purgeCompleted($jobDays, $batch)],
        ['audit logs'        , static fn (): int => (new AuditRepository())->pruneOlderThan($auditDays, $batch)],
    ];

    $total = 0;

    foreach ($buckets as [$label, $fn]) {
        try {
            $count = $dryRun ? 0 : $fn();
            $total += $count;

            Cli::out('  ' . str_pad($label, 18) . $count . ($dryRun ? ' (skipped)' : ' removed'));
        } catch (Throwable $e) {
            Cli::warn(str_pad($label, 18) . 'failed: ' . $e->getMessage());
            Logger::warning('prune.bucket_failed', ['bucket' => $label, 'error' => $e->getMessage()]);
        }
    }

    /*
     * Storage reconciliation, after the TTL deletes rather than alongside them.
     *
     * It is not a delete and not a bucket: reconcile() inspects rows whose bytes
     * must exist and reports where they actually are. Running it after the TTL
     * work means a tick that deletes a lot still reports on storage, and a
     * reconcile that throws cannot prevent any expiry from being reclaimed.
     *
     * Reported on every run, not just when something is wrong. A reconciler whose
     * failures are visible only when the count is non-zero is a reconciler
     * nobody thinks to check, so the healthy line is printed too — "0 missing"
     * from a cron log is the evidence that the check is still running.
     *
     * Reconcile never throws; it counts. bin/integrity.php is the suite that
     * asserts its behaviour, so this stays a report.
     */
    Cli::heading('Storage reconciliation');

    $storage = \FieldPulse\Storage\StorageState::reconcile(new \FieldPulse\Database\SubmissionRepository());

    Cli::out('  ' . str_pad('rows checked', 18) . $storage['checked']);
    Cli::out('  ' . str_pad('evidence found', 18) . ($storage['checked'] - $storage['missing'] - $storage['unreadable']));
    Cli::out('  ' . str_pad('moves repaired', 18) . $storage['repaired']);
    Cli::out('  ' . str_pad('rows unresolvable', 18) . $storage['unreadable']);

    if ($storage['missing'] > 0) {
        Cli::warn(
            str_pad('evidence missing', 18) . $storage['missing']
            . ' — marked MISSING in the ledger; this is storage loss, not a race, and needs a human'
        );
    } else {
        Cli::out('  ' . str_pad('evidence missing', 18) . '0');
    }

    $stats = (new JobRepository())->stats();

    Cli::heading('Queue depth');
    foreach ($stats as $status => $count) {
        Cli::out('  ' . str_pad(strtolower($status), 12) . $count);
    }

    Cli::out('');
    Cli::out('  total removed: ' . $total);
    exit(0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
