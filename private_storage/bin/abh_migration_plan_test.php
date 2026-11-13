<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Database\AbhMigrationCatalog;

$checks = 0;
$failures = [];

function abhMigrationAssert(string $label, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $label;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        return;
    }
    fwrite(STDOUT, 'PASS: ' . $label . PHP_EOL);
}

function abhMigrationThrows(callable $callback): bool
{
    try {
        $callback();
    } catch (Throwable) {
        return true;
    }
    return false;
}

function abhMigrationRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        $itemPath = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            abhMigrationRemoveTree($itemPath);
        } else {
            @unlink($itemPath);
        }
    }
    @rmdir($path);
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'abh-migration-test-' . bin2hex(random_bytes(6));
$privateRoot = $base . DIRECTORY_SEPARATOR . 'abhpreview_private_storage';
$envFile = $privateRoot . DIRECTORY_SEPARATOR . '.env';
$migrationDir = $privateRoot . DIRECTORY_SEPARATOR . 'abh_migrations';

try {
    mkdir($migrationDir, 0750, true);
    file_put_contents($envFile, "APP_ENV=testing\n");

    abhMigrationAssert(
        'configured preview database name is accepted',
        !abhMigrationThrows(static fn () => AbhMigrationCatalog::assertConfiguredDatabase('abhgloba_abhpreview'))
    );
    abhMigrationAssert(
        'wrong configured database name is refused',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertConfiguredDatabase('abhgloba_fieldpulse'))
    );
    abhMigrationAssert(
        'connected database identity is checked independently',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertConnectedDatabase('abhgloba_fieldpulse'))
    );
    abhMigrationAssert(
        'connected database account is checked independently',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertConnectedUser('abhgloba_fieldpulse@localhost'))
    );
    abhMigrationAssert(
        'dedicated preview database account is accepted',
        !abhMigrationThrows(static fn () => AbhMigrationCatalog::assertConnectedUser('abhgloba_abhpreview@localhost'))
    );

    abhMigrationAssert(
        'valid preview configuration is accepted',
        !abhMigrationThrows(static fn () => AbhMigrationCatalog::assertPreviewConfiguration(
            $privateRoot,
            AbhMigrationCatalog::EXPECTED_APP_URL,
            AbhMigrationCatalog::EXPECTED_DATABASE,
            AbhMigrationCatalog::EXPECTED_DATABASE_USER,
            $envFile,
            $privateRoot
        ))
    );
    abhMigrationAssert(
        'production private root is refused',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertPreviewConfiguration(
            $privateRoot,
            AbhMigrationCatalog::EXPECTED_APP_URL,
            AbhMigrationCatalog::EXPECTED_DATABASE,
            AbhMigrationCatalog::EXPECTED_DATABASE_USER,
            $envFile,
            $base . DIRECTORY_SEPARATOR . 'private_storage'
        ))
    );
    abhMigrationAssert(
        'production APP_URL is refused',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertPreviewConfiguration(
            $privateRoot,
            'https://monthlyperformance.abhglobalinformationtechnologysolutions.com.ng',
            AbhMigrationCatalog::EXPECTED_DATABASE,
            AbhMigrationCatalog::EXPECTED_DATABASE_USER,
            $envFile,
            $privateRoot
        ))
    );
    abhMigrationAssert(
        'wrong database user is refused',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertPreviewConfiguration(
            $privateRoot,
            AbhMigrationCatalog::EXPECTED_APP_URL,
            AbhMigrationCatalog::EXPECTED_DATABASE,
            'abhgloba_fieldpulse',
            $envFile,
            $privateRoot
        ))
    );
    abhMigrationAssert(
        'external environment file is refused',
        abhMigrationThrows(static fn () => AbhMigrationCatalog::assertPreviewConfiguration(
            $privateRoot,
            AbhMigrationCatalog::EXPECTED_APP_URL,
            AbhMigrationCatalog::EXPECTED_DATABASE,
            AbhMigrationCatalog::EXPECTED_DATABASE_USER,
            $base . DIRECTORY_SEPARATOR . 'outside.env',
            $privateRoot
        ))
    );

    file_put_contents(
        $migrationDir . DIRECTORY_SEPARATOR . '002_create_second.sql',
        "CREATE TABLE second_table (id INT NOT NULL);\n"
    );
    file_put_contents(
        $migrationDir . DIRECTORY_SEPARATOR . '001_create_first.sql',
        "CREATE TABLE first_table (id INT NOT NULL, note VARCHAR(40) DEFAULT 'a;b');\n"
    );

    $catalog = new AbhMigrationCatalog($migrationDir);
    $files = $catalog->discover();

    abhMigrationAssert(
        'migration files are discovered in lexical order',
        array_column($files, 'filename') === ['001_create_first.sql', '002_create_second.sql']
    );
    abhMigrationAssert(
        'SQL statement splitting preserves quoted semicolons',
        $files[0]['statement_count'] === 1
    );
    abhMigrationAssert(
        'migration checksum is a SHA-256 digest',
        preg_match('/^[a-f0-9]{64}$/', $files[0]['checksum']) === 1
    );

    $pending = $catalog->status([]);
    abhMigrationAssert(
        'unapplied migration files are reported as pending',
        count(array_filter($pending, static fn (array $row): bool => $row['status'] === 'PENDING')) === 2
    );

    $applied = [[
        'filename' => '001_create_first.sql',
        'checksum' => $files[0]['checksum'],
        'applied_at' => '2026-01-01 00:00:00',
    ]];
    $status = $catalog->status($applied);
    abhMigrationAssert(
        'an exact applied checksum is accepted',
        $status[0]['status'] === 'APPLIED' && !$status[0]['checksum_mismatch']
    );
    abhMigrationAssert(
        'an unapplied following migration remains pending',
        $status[1]['status'] === 'PENDING'
    );

    $wrongChecksum = $catalog->status([[
        'filename' => '001_create_first.sql',
        'checksum' => str_repeat('0', 64),
        'applied_at' => '2026-01-01 00:00:00',
    ]]);
    abhMigrationAssert('modified applied migration is detected', $wrongChecksum[0]['checksum_mismatch']);

    $missingFile = $catalog->status([[
        'filename' => '000_removed_migration.sql',
        'checksum' => str_repeat('a', 64),
        'applied_at' => '2026-01-01 00:00:00',
    ]]);
    abhMigrationAssert(
        'missing applied migration file is detected',
        $missingFile[0]['status'] === 'MISSING_FILE'
    );

    file_put_contents(
        $migrationDir . DIRECTORY_SEPARATOR . 'invalid-name.sql',
        'CREATE TABLE invalid_table (id INT);'
    );
    abhMigrationAssert(
        'migration filename outside the numbered convention is refused',
        abhMigrationThrows(static fn () => $catalog->discover())
    );
} catch (Throwable $error) {
    $failures[] = 'test execution: ' . $error->getMessage();
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
} finally {
    abhMigrationRemoveTree($base);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf(
        'ABH migration tests failed: %d/%d checks failed.%s',
        count($failures),
        $checks,
        PHP_EOL
    ));
    exit(1);
}

fwrite(STDOUT, sprintf('ABH migration tests passed: %d checks.%s', $checks, PHP_EOL));
