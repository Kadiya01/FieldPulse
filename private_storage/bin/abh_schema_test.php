<?php

declare(strict_types=1);

/**
 * Disposable CI schema compatibility test.
 *
 * This script is intentionally NOT the cPanel migration runner. It only accepts
 * the fixed disposable database name abh_test_migrations and never creates,
 * drops or connects to a production/staging database.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Database\AbhMigrationCatalog;
use FieldPulse\Database\Migrator;

const ABH_TEST_DATABASE = 'abh_test_migrations';

$checks = 0;
$failures = [];

function abhSchemaAssert(string $label, bool $condition): void
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

function abhSchemaExpectFailure(callable $operation): bool
{
    try {
        $operation();
    } catch (PDOException) {
        return true;
    }
    return false;
}

try {
    $database = getenv('ABH_TEST_DB_NAME') ?: '';
    if ($database !== ABH_TEST_DATABASE) {
        throw new RuntimeException(
            'Refusing schema test: ABH_TEST_DB_NAME must be exactly ' . ABH_TEST_DATABASE . '.'
        );
    }

    $host = getenv('ABH_TEST_DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('ABH_TEST_DB_PORT') ?: '3306');
    $user = getenv('ABH_TEST_DB_USER') ?: '';
    $password = getenv('ABH_TEST_DB_PASSWORD') ?: '';

    if ($user === '') {
        throw new RuntimeException('ABH_TEST_DB_USER must be supplied for the disposable CI database.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDatabase !== ABH_TEST_DATABASE) {
        throw new RuntimeException('Connected database identity did not match the disposable CI database.');
    }

    $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $isMariaDb = stripos($serverVersion, 'MariaDB') !== false;
    if (!preg_match('/^(\d+\.\d+\.\d+)/', $serverVersion, $versionParts)) {
        throw new RuntimeException('Unable to identify CI database server version.');
    }
    $minimum = $isMariaDb ? '10.11.0' : '8.0.3';
    if (version_compare($versionParts[1], $minimum, '<')) {
        throw new RuntimeException('CI database server is below the supported ABH baseline.');
    }

    $catalog = new AbhMigrationCatalog(dirname(__DIR__) . '/abh_migrations');
    $files = $catalog->discover();
    if ($files === []) {
        throw new RuntimeException('No ABH SQL migrations found for compatibility tests.');
    }

    foreach ($files as $file) {
        $contents = file_get_contents($file['path']);
        if ($contents === false) {
            throw new RuntimeException('Unable to read migration ' . $file['filename'] . '.');
        }

        foreach (Migrator::splitStatements($contents) as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'Disposable schema test failed in ' . $file['filename'] . ' at statement ' . ($index + 1) . '.',
                    0,
                    $error
                );
            }
        }
        fwrite(STDOUT, 'APPLIED (test only): ' . $file['filename'] . PHP_EOL);
    }

    $expectedTables = [
        'abh_admin_roles',
        'abh_admin_permissions',
        'abh_admin_users',
        'abh_admin_user_roles',
        'abh_admin_role_permissions',
        'abh_agents',
        'abh_agent_credentials',
        'abh_agent_devices',
        'abh_reporting_periods',
    ];

    $tableRows = $pdo->query(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'abh\_%'"
    )->fetchAll();

    $engines = [];
    foreach ($tableRows as $row) {
        $engines[(string) $row['TABLE_NAME']] = (string) $row['ENGINE'];
    }

    foreach ($expectedTables as $table) {
        abhSchemaAssert('table exists: ' . $table, isset($engines[$table]));
        if (isset($engines[$table])) {
            abhSchemaAssert($table . ' uses InnoDB', strtoupper($engines[$table]) === 'INNODB');
        }
    }

    $foreignKeyCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME LIKE 'abh\_%'
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    )->fetchColumn();
    abhSchemaAssert('expected identity and access foreign keys exist', $foreignKeyCount >= 10);

    // Create synthetic fixture rows. No real agent/admin information is used.
    $adminInsert = $pdo->prepare(
        'INSERT INTO abh_admin_users
         (full_name, email, email_normalized, password_hash, status)
         VALUES (:name, :email, :normalized, :hash, :status)'
    );
    $adminInsert->execute([
        'name' => 'CI Test Administrator',
        'email' => 'admin@example.invalid',
        'normalized' => 'admin@example.invalid',
        'hash' => password_hash('disposable-ci-password', PASSWORD_DEFAULT),
        'status' => 'ACTIVE',
    ]);
    $adminId = (int) $pdo->lastInsertId();

    $roleInsert = $pdo->prepare(
        'INSERT INTO abh_admin_roles (role_key, role_name) VALUES (:key, :name)'
    );
    $roleInsert->execute(['key' => 'CI_TEST_ROLE', 'name' => 'CI Test Role']);
    $roleId = (int) $pdo->lastInsertId();

    $permissionInsert = $pdo->prepare(
        'INSERT INTO abh_admin_permissions (permission_key, description) VALUES (:key, :description)'
    );
    $permissionInsert->execute(['key' => 'ci.test.permission', 'description' => 'Disposable CI permission']);
    $permissionId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO abh_admin_user_roles (admin_user_id, role_id, assigned_by_admin_id) VALUES (?, ?, ?)'
    )->execute([$adminId, $roleId, $adminId]);
    $pdo->prepare(
        'INSERT INTO abh_admin_role_permissions (role_id, permission_id) VALUES (?, ?)'
    )->execute([$roleId, $permissionId]);
    abhSchemaAssert('admin roles and permissions can be assigned relationally', true);

    $agentInsert = $pdo->prepare(
        'INSERT INTO abh_agents
         (agent_code, full_name, phone, phone_normalized, email, email_normalized, status, approved_by_admin_id)
         VALUES (:code, :name, :phone, :phone_norm, :email, :email_norm, :status, :approved_by)'
    );
    $agentInsert->execute([
        'code' => 'CI-AGENT-001',
        'name' => 'CI Test Agent',
        'phone' => '+2348000000000',
        'phone_norm' => '+2348000000000',
        'email' => 'agent@example.invalid',
        'email_norm' => 'agent@example.invalid',
        'status' => 'PENDING',
        'approved_by' => null,
    ]);
    $agentId = (int) $pdo->lastInsertId();

    abhSchemaAssert(
        'new agent defaults to PENDING',
        (string) $pdo->query('SELECT status FROM abh_agents WHERE id = ' . $agentId)->fetchColumn() === 'PENDING'
    );

    $pdo->prepare(
        'INSERT INTO abh_agent_credentials (agent_id, password_hash) VALUES (?, ?)'
    )->execute([$agentId, password_hash('disposable-agent-password', PASSWORD_DEFAULT)]);
    $pdo->prepare(
        'INSERT INTO abh_agent_devices (agent_id, device_type, imei_value) VALUES (?, ?, ?)'
    )->execute([$agentId, 'CI Test Device', '000000000000000']);

    $periodInsert = $pdo->prepare(
        'INSERT INTO abh_reporting_periods (period_key, starts_on, ends_on, timezone, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $periodInsert->execute(['2026-11', '2026-11-01', '2026-11-30', 'Africa/Lagos', 'OPEN']);
    abhSchemaAssert('monthly reporting period can be stored', true);

    abhSchemaAssert(
        'duplicate agent code is rejected',
        abhSchemaExpectFailure(static function () use ($agentInsert): void {
            $agentInsert->execute([
                'code' => 'CI-AGENT-001',
                'name' => 'Duplicate Agent',
                'phone' => '+2348000000001',
                'phone_norm' => '+2348000000001',
                'email' => 'duplicate@example.invalid',
                'email_norm' => 'duplicate@example.invalid',
                'status' => 'PENDING',
                'approved_by' => null,
            ]);
        })
    );

    abhSchemaAssert(
        'duplicate normalized admin email is rejected',
        abhSchemaExpectFailure(static function () use ($adminInsert): void {
            $adminInsert->execute([
                'name' => 'Duplicate Administrator',
                'email' => 'ADMIN@example.invalid',
                'normalized' => 'admin@example.invalid',
                'hash' => password_hash('another-disposable-password', PASSWORD_DEFAULT),
                'status' => 'ACTIVE',
            ]);
        })
    );

    abhSchemaAssert(
        'agent credentials enforce their parent agent foreign key',
        abhSchemaExpectFailure(static function () use ($pdo): void {
            $pdo->prepare('INSERT INTO abh_agent_credentials (agent_id, password_hash) VALUES (?, ?)')
                ->execute([999999999, password_hash('invalid-agent', PASSWORD_DEFAULT)]);
        })
    );

    abhSchemaAssert(
        'CI schema test never creates the live ABH migration ledger',
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abh_schema_migrations'"
        )->fetchColumn() === 0
    );

    fwrite(STDOUT, 'Database server: ' . $serverVersion . PHP_EOL);
} catch (Throwable $error) {
    $failures[] = $error->getMessage();
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('ABH schema compatibility test failed: %d failure(s).%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf('ABH schema compatibility tests PASS: %d checks.%s', $checks, PHP_EOL));
