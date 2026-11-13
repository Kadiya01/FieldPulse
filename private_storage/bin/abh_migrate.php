<?php

declare(strict_types=1);

/**
 * Dedicated ABH migration runner.
 *
 * Safe inspection:
 *   php private_storage/bin/abh_migrate.php --status
 *   php private_storage/bin/abh_migrate.php --dry-run
 *
 * Apply (requires a verified preview backup and separate approval):
 *   php private_storage/bin/abh_migrate.php --apply --confirm-staging-db=abhgloba_abhpreview
 *
 * This runner is bound to the ABH preview private directory, APP_URL, .env,
 * DB_NAME and DB_USER. It has a separate SQL directory and ledger, and never
 * uses or modifies the legacy schema_migrations ledger. MySQL/MariaDB DDL may
 * partially apply before an error; inspect the schema before any retry.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AbhMigrationCatalog;
use FieldPulse\Database\Connection;
use FieldPulse\Database\Migrator;
use FieldPulse\Support\Env;

Cli::init(__FILE__);

$argv = Cli::argv();
$modes = array_values(array_filter(
    ['status', 'dry-run', 'apply'],
    static fn (string $flag): bool => Cli::hasFlag($argv, $flag)
));

if (count($modes) !== 1) {
    Cli::err('Choose exactly one mode: --status, --dry-run, or --apply.');
    exit(2);
}

$unknown = array_values(array_filter(
    $argv,
    static fn (string $argument): bool =>
        !in_array($argument, ['--status', '--dry-run', '--apply'], true)
        && !str_starts_with($argument, '--confirm-staging-db=')
));
if ($unknown !== []) {
    Cli::err('Unsupported argument: ' . $unknown[0]);
    exit(2);
}

if (Cli::option($argv, 'env') !== null) {
    Cli::err('The ABH migration runner does not accept --env; it must use the preview private directory .env.');
    exit(2);
}

$mode = $modes[0];
$confirmation = Cli::option($argv, 'confirm-staging-db');

if ($mode === 'apply' && $confirmation !== AbhMigrationCatalog::EXPECTED_DATABASE) {
    Cli::err('Apply requires --confirm-staging-db=' . AbhMigrationCatalog::EXPECTED_DATABASE . '.');
    exit(2);
}
if ($mode !== 'apply' && $confirmation !== null) {
    Cli::err('--confirm-staging-db is valid only with --apply.');
    exit(2);
}

try {
    $config = Config::instance();

    // All local deployment identity checks happen before the first DB connect.
    AbhMigrationCatalog::assertPreviewConfiguration(
        FIELDPULSE_PRIVATE_ROOT,
        $config->str('app.url'),
        $config->str('db.name'),
        $config->str('db.user'),
        Env::loadedFile()
    );

    $catalog = new AbhMigrationCatalog(FIELDPULSE_PRIVATE_ROOT . '/abh_migrations');

    Cli::heading('ABH preview migration runner');
    Cli::out('  target database: ' . AbhMigrationCatalog::EXPECTED_DATABASE);
    Cli::out('  mode:            ' . $mode);

    $pdo = Connection::pdo();
    $actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    AbhMigrationCatalog::assertConnectedDatabase($actualDatabase);

    $actualUser = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
    AbhMigrationCatalog::assertConnectedUser($actualUser);

    $serverVersion = Connection::serverVersion();
    $isMariaDb = stripos($serverVersion, 'MariaDB') !== false;
    $versionMatch = preg_match('/^(\d+\.\d+\.\d+)/', $serverVersion, $versionParts);
    if ($versionMatch !== 1) {
        throw new RuntimeException('Unable to identify the database server version.');
    }

    $minimumVersion = $isMariaDb ? '10.11.0' : '8.0.3';
    if (version_compare($versionParts[1], $minimumVersion, '<')) {
        throw new RuntimeException(
            'Database server is below the ABH migration baseline: '
            . ($isMariaDb ? 'MariaDB 10.11+' : 'MySQL 8.0.3+') . ' required.'
        );
    }
    Cli::out('  server version:  ' . $serverVersion);

    // Re-check existence on every call. The apply path obtains an advisory lock
    // and reads again after locking, avoiding a stale "ledger absent" race.
    $readApplied = static function (): array {
        $exists = (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => 'abh_schema_migrations']
        ) > 0;

        if (!$exists) {
            return [];
        }

        return Connection::fetchAll(
            'SELECT filename, checksum, applied_at FROM abh_schema_migrations ORDER BY filename ASC'
        );
    };

    if ($mode === 'apply') {
        $lock = (int) Connection::fetchValue(
            'SELECT GET_LOCK(:lock_name, 10)',
            ['lock_name' => 'abh_monthly_enrollment_migrations']
        );
        if ($lock !== 1) {
            throw new RuntimeException('Could not acquire the ABH migration lock; another migration may be running.');
        }

        try {
            $statuses = $catalog->status($readApplied());
            $problems = array_values(array_filter(
                $statuses,
                static fn (array $row): bool => $row['checksum_mismatch']
            ));

            if ($problems !== []) {
                foreach ($problems as $problem) {
                    Cli::fail($problem['filename'] . ' — ' . $problem['status'] . ' / checksum mismatch');
                }
                throw new RuntimeException('ABH migration history is inconsistent. No pending migration was applied.');
            }

            $pending = array_values(array_filter(
                $statuses,
                static fn (array $row): bool => $row['status'] === 'PENDING'
            ));

            if ($pending === []) {
                Cli::ok('no pending ABH migrations; migration ledger was not changed');
            } else {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS abh_schema_migrations (
                        filename VARCHAR(191) NOT NULL,
                        checksum CHAR(64) NOT NULL,
                        applied_at DATETIME(6) NOT NULL,
                        PRIMARY KEY (filename)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );

                foreach ($catalog->discover() as $file) {
                    $isPending = false;
                    foreach ($pending as $row) {
                        if ($row['filename'] === $file['filename']) {
                            $isPending = true;
                            break;
                        }
                    }
                    if (!$isPending) {
                        continue;
                    }

                    $contents = file_get_contents($file['path']);
                    if ($contents === false) {
                        throw new RuntimeException('Unable to read ' . $file['filename'] . '.');
                    }

                    foreach (Migrator::splitStatements($contents) as $index => $statement) {
                        try {
                            $pdo->exec($statement);
                        } catch (Throwable $error) {
                            throw new RuntimeException(
                                'Migration ' . $file['filename'] . ' failed at statement ' . ($index + 1)
                                . '. MySQL/MariaDB DDL may have partially applied; inspect the staging schema before retrying.',
                                0,
                                $error
                            );
                        }
                    }

                    $insert = $pdo->prepare(
                        'INSERT INTO abh_schema_migrations (filename, checksum, applied_at) VALUES (:filename, :checksum, UTC_TIMESTAMP(6))'
                    );
                    $insert->execute([
                        'filename' => $file['filename'],
                        'checksum' => $file['checksum'],
                    ]);
                    Cli::ok('applied ' . $file['filename']);
                }
            }
        } finally {
            try {
                Connection::fetchValue(
                    'SELECT RELEASE_LOCK(:lock_name)',
                    ['lock_name' => 'abh_monthly_enrollment_migrations']
                );
            } catch (Throwable) {
                // The server may already have released the lock on disconnect.
            }
        }
    } else {
        $statuses = $catalog->status($readApplied());
        if ($statuses === []) {
            Cli::out('  no ABH SQL migration files have been added yet.');
        }

        $mismatch = false;
        $pendingCount = 0;
        foreach ($statuses as $row) {
            if ($row['checksum_mismatch']) {
                $mismatch = true;
                Cli::fail($row['filename'] . ' — ' . $row['status'] . ' / mismatch or missing file');
                continue;
            }

            if ($row['status'] === 'PENDING') {
                $pendingCount++;
                Cli::out('  [pending] ' . $row['filename'] . ' (' . $row['statement_count'] . ' statements)');
            } else {
                Cli::ok($row['filename'] . ' applied');
            }
        }

        if ($mismatch) {
            throw new RuntimeException('ABH migration history has a checksum mismatch or a missing migration file.');
        }

        if ($mode === 'dry-run') {
            Cli::out('  no database writes performed.');
            Cli::out('  pending files: ' . $pendingCount);
        }
    }

    Cli::heading('Result');
    Cli::ok('ABH preview database identity verified.');
    if ($mode === 'apply') {
        Cli::out('  inspect with --status after this operation.');
    }
    exit(0);
} catch (Throwable $error) {
    Cli::fail($error->getMessage());
    exit(1);
}
