<?php

declare(strict_types=1);

/**
 * Migration runner.
 *
 *   php private_storage/bin/migrate.php            # apply pending
 *   php private_storage/bin/migrate.php --status   # show state only
 *
 * Safe to run from cPanel Terminal or a one-off cron. Idempotent: already
 * applied files are skipped, and a file edited after being applied is a hard
 * error rather than a silent no-op.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\Connection;
use FieldPulse\Database\Migrator;

Cli::init(__FILE__);

$argv    = Cli::argv();
$status  = Cli::hasFlag($argv, 'status');
$migrator = new Migrator(FIELDPULSE_PRIVATE_ROOT . '/migrations');

Cli::heading('FieldPulse database');

try {
    $version = Connection::serverVersion();
    Cli::out('  server: ' . $version);

    // MySQL 8 / MariaDB 10.3+ are required for the generated-column-free schema
    // plus the utf8mb4_unicode_ci collation used by every table.
    if (preg_match('/^(5\.[0-7]|8\.0\.[0-2])/', $version) === 1 && !str_contains($version, 'MariaDB')) {
        Cli::warn('server is older than the supported baseline (MySQL 8.0.3+ / MariaDB 10.3+)');
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
