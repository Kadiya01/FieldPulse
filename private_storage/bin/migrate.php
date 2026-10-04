<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php private_storage/bin/migrate.php                    # apply pending
 *   php private_storage/bin/migrate.php --status           # show state only
 *   php private_storage/bin/migrate.php --env=/path/to.env # a specific deployment
 *
 * Safe to run from cPanel Terminal or a one-off cron. Idempotent: already
 * applied files are skipped, and a file edited after being applied is a hard
 * error rather than a silent no-op.
 *
 * WHY --env EXISTS HERE
 *
 * The support matrix has to apply every migration to every supported engine
 * from one command, and the only honest way to point a run at a different
 * server is to hand it a different environment file rather than editing .env
 * in place. Without this flag, bin/db_matrix.php would have to rewrite the
 * deployment's configuration between engines, which means a matrix run that
 * dies halfway leaves the deployment pointed at the wrong database — the
 * failure mode is worse than the one the flag removes.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\Connection;
use FieldPulse\Database\Migrator;

Cli::init(__FILE__);

$argv    = Cli::argv();
$envFile = Cli::option($argv, 'env');
$status  = Cli::hasFlag($argv, 'status');

Config::boot($envFile);

$migrator = new Migrator(FIELDPULSE_PRIVATE_ROOT . '/migrations');

Cli::heading('FieldPulse database');

try {
    $version = Connection::serverVersion();
    Cli::out('  server: ' . $version);

    /*
     * The supported floor, and it is deliberately the TESTED floor rather than
     * the oldest version the code would probably tolerate.
     *
     * The schema needs MySQL 8.0.3+ (generated columns are avoided, so the real
     * dependency is only the utf8mb4_unicode_ci collation and CHECK constraints).
     * MariaDB is the awkward one: the queue takes the native
     * `FOR UPDATE SKIP LOCKED` path on 10.6+ and a portable fallback below it,
     * so 10.3+ has always been plausible. Plausible is not supported. Only
     * MariaDB 10.11 has actually been run through bin/db_matrix.php, so 10.11 is
     * what is claimed — see docs/DEPLOYMENT.md. Raising this floor without
     * testing the version first is how a matrix stops meaning anything.
     */
    $isMariaDb = str_contains($version, 'MariaDB');
    $tooOld    = $isMariaDb
        ? preg_match('/^10\.(?:[0-9]|10)(?:\.|$)/', $version) === 1
        : preg_match('/^(?:5\.|8\.0\.[0-2])/', $version) === 1;

    if ($tooOld) {
        Cli::warn(
            'server is older than the supported baseline (MySQL 8.0.3+ / MariaDB 10.11+); '
                . 'it may work, but it is not a supported configuration'
        );
    }

    if ($status) {
        Cli::heading('Status');
        $rows = $migrator->status();

        if ($rows === []) {
            Cli::warn('no migration files found');
        }

        foreach ($rows as $row) {
            if ($row['status'] === 'APPLIED') {
                Cli::ok(str_pad($row['filename'], 42) . ' applied');
            } else {
                Cli::out('  [todo] ' . str_pad($row['filename'], 42) . ' pending');
            }

            if ($row['checksum_mismatch']) {
                Cli::fail('  ^ checksum mismatch: file changed after it was applied');
            }
        }

        exit($rows === [] ? 1 : 0);
    }

    Cli::heading('Applying');
    $applied = $migrator->migrate();

    if ($applied === []) {
        Cli::out('  database is already up to date');
    } else {
        foreach ($applied as $file) {
            Cli::ok('applied ' . $file);
        }
    }

    Cli::heading('Result');
    foreach (Connection::fetchAll('SHOW TABLES') as $row) {
        Cli::out('  ' . (string) reset($row));
    }

    exit(0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
