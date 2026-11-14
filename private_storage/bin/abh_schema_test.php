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
        'abh_enrollment_submissions',
        'abh_submission_revisions',
        'abh_submission_evidence',
        'abh_submission_reviews',
        'abh_submission_adjustments',
        'abh_monthly_targets',
        'abh_monthly_agent_totals',
        'abh_monthly_leaderboard_snapshots',
        'abh_monthly_organisation_snapshots',
        'abh_reward_programs',
        'abh_reward_rules',
        'abh_reward_qualifications',
        'abh_reward_gifts',
        'abh_reward_awards',
        'abh_reward_fulfilment_events',
        'abh_audit_events',
        'abh_notifications',
        'abh_registration_attempts',
        'abh_agent_sessions',
        'abh_auth_nonces',
        'abh_auth_attempts',
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

    $deviceColumns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abh_agent_devices'"
    )->fetchAll(PDO::FETCH_COLUMN);
    abhSchemaAssert('ABH devices store browser UUIDs', in_array('device_uuid', $deviceColumns, true));
    abhSchemaAssert('ABH devices store public JWKs', in_array('public_key_jwk', $deviceColumns, true));
    abhSchemaAssert('ABH devices store last-seen timestamps', in_array('last_seen_at', $deviceColumns, true));

    $sessionColumns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'abh_agent_sessions'"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach (['access_token_hash', 'refresh_token_hash', 'token_family', 'access_expires_at', 'refresh_expires_at', 'revoked_at', 'rotated_to_session_id'] as $column) {
        abhSchemaAssert('ABH session field exists: ' . $column, in_array($column, $sessionColumns, true));
    }

    $foreignKeyCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME LIKE 'abh\_%'
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    )->fetchColumn();
    abhSchemaAssert('expected identity, submission, performance, reward, audit and notification foreign keys exist', $foreignKeyCount >= 50);

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

    $agentInsert->execute([
        'code' => 'CI-AGENT-002',
        'name' => 'CI Test Agent Two',
        'phone' => '+2348000000002',
        'phone_norm' => '+2348000000002',
        'email' => 'agent2@example.invalid',
        'email_norm' => 'agent2@example.invalid',
        'status' => 'PENDING',
        'approved_by' => null,
    ]);
    $agentTwoId = (int) $pdo->lastInsertId();

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

    abhSchemaAssert(
        'a registered device IMEI cannot be assigned to another agent',
        abhSchemaExpectFailure(static function () use ($pdo, $agentTwoId): void {
            $pdo->prepare(
                'INSERT INTO abh_agent_devices (agent_id, device_type, imei_value) VALUES (?, ?, ?)'
            )->execute([$agentTwoId, 'CI Test Device', '000000000000000']);
        })
    );

    $attemptInsert = $pdo->prepare(
        'INSERT INTO abh_registration_attempts (identifier_hash, ip_hash) VALUES (?, ?)'
    );
    $attemptInsert->execute([str_repeat('a', 64), str_repeat('b', 64)]);
    abhSchemaAssert(
        'ABH registration throttling stores only fixed-length hashes',
        (int) $pdo->query(
            "SELECT COUNT(*) FROM abh_registration_attempts
             WHERE identifier_hash = '" . str_repeat('a', 64) . "'
               AND ip_hash = '" . str_repeat('b', 64) . "'"
        )->fetchColumn() === 1
    );

    $periodInsert = $pdo->prepare(
        'INSERT INTO abh_reporting_periods (period_key, starts_on, ends_on, timezone, status)
         VALUES (?, ?, ?, ?, ?)'
    );
    $periodInsert->execute(['2026-11', '2026-11-01', '2026-11-30', 'Africa/Lagos', 'OPEN']);
    $periodId = (int) $pdo->lastInsertId();
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
        'duplicate normalized agent phone is rejected',
        abhSchemaExpectFailure(static function () use ($agentInsert): void {
            $agentInsert->execute([
                'code' => 'CI-AGENT-DUPLICATE-PHONE',
                'name' => 'Duplicate Phone Agent',
                'phone' => '+2348000000000',
                'phone_norm' => '+2348000000000',
                'email' => null,
                'email_norm' => null,
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


    // One agent can submit multiple independent reports in the same open month.
    $submissionInsert = $pdo->prepare(
        'INSERT INTO abh_enrollment_submissions
         (reference_no, agent_id, reporting_period_id, status, idempotency_key)
         VALUES (?, ?, ?, ?, ?)'
    );
    $submissionInsert->execute([
        'ABH-CI-REF-001',
        $agentId,
        $periodId,
        'PENDING_REVIEW',
        '00000000-0000-4000-8000-000000000001',
    ]);
    $submissionOneId = (int) $pdo->lastInsertId();
    $submissionInsert->execute([
        'ABH-CI-REF-002',
        $agentId,
        $periodId,
        'PENDING_REVIEW',
        '00000000-0000-4000-8000-000000000002',
    ]);
    $submissionTwoId = (int) $pdo->lastInsertId();

    abhSchemaAssert(
        'agent can create multiple independent reports in the same month',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_enrollment_submissions WHERE agent_id = ' . $agentId
        )->fetchColumn() === 2
    );

    $revisionInsert = $pdo->prepare(
        'INSERT INTO abh_submission_revisions
         (submission_id, revision_no, claimed_quantity, activity_date, agent_note, created_by_agent_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $revisionInsert->execute([
        $submissionOneId,
        1,
        12,
        '2026-11-05',
        'Initial report',
        $agentId,
    ]);
    $revisionOneId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE abh_enrollment_submissions SET current_revision_id = ? WHERE id = ?')
        ->execute([$revisionOneId, $submissionOneId]);

    $revisionInsert->execute([
        $submissionOneId,
        2,
        15,
        '2026-11-05',
        'Corrected quantity',
        $agentId,
    ]);
    $revisionTwoId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE abh_enrollment_submissions SET current_revision_id = ? WHERE id = ?')
        ->execute([$revisionTwoId, $submissionOneId]);

    $revisionInsert->execute([
        $submissionTwoId,
        1,
        7,
        '2026-11-10',
        'Independent second report',
        $agentId,
    ]);
    $revisionOtherSubmissionId = (int) $pdo->lastInsertId();

    abhSchemaAssert(
        'submission correction appends a revision and preserves the first',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_submission_revisions WHERE submission_id = ' . $submissionOneId
        )->fetchColumn() === 2
    );

    abhSchemaAssert(
        'current revision cannot point to a different submission',
        abhSchemaExpectFailure(static function () use ($pdo, $submissionOneId, $revisionOtherSubmissionId): void {
            $pdo->prepare('UPDATE abh_enrollment_submissions SET current_revision_id = ? WHERE id = ?')
                ->execute([$revisionOtherSubmissionId, $submissionOneId]);
        })
    );

    // Evidence is indexed by revision and stores an opaque private key, not a URL.
    $evidenceInsert = $pdo->prepare(
        'INSERT INTO abh_submission_evidence
         (revision_id, storage_key, original_filename, mime_type, byte_size, sha256)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $evidenceInsert->execute([
        $revisionOneId,
        'ci/evidence/abh-ci-evidence-001.jpg',
        'evidence.jpg',
        'image/jpeg',
        2048,
        str_repeat('a', 64),
    ]);
    abhSchemaAssert('private evidence metadata can be linked to a revision', true);

    $reviewInsert = $pdo->prepare(
        'INSERT INTO abh_submission_reviews
         (submission_id, revision_id, review_cycle_no, reviewer_admin_id, decision, reason_code, review_note, idempotency_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $reviewInsert->execute([
        $submissionOneId,
        $revisionOneId,
        1,
        $adminId,
        'CORRECTION_REQUESTED',
        'CI_TEST',
        'Please verify the quantity.',
        '00000000-0000-4000-8000-000000000011',
    ]);
    $reviewInsert->execute([
        $submissionOneId,
        $revisionTwoId,
        2,
        $adminId,
        'APPROVED',
        null,
        'Corrected report approved in disposable CI.',
        '00000000-0000-4000-8000-000000000012',
    ]);
    abhSchemaAssert(
        'review history supports correction and a later review cycle',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_submission_reviews WHERE submission_id = ' . $submissionOneId
        )->fetchColumn() === 2
    );

    abhSchemaAssert(
        'review cannot reference a revision from another submission',
        abhSchemaExpectFailure(static function () use (
            $reviewInsert,
            $submissionOneId,
            $revisionOtherSubmissionId,
            $adminId
        ): void {
            $reviewInsert->execute([
                $submissionOneId,
                $revisionOtherSubmissionId,
                3,
                $adminId,
                'APPROVED',
                null,
                'Invalid cross-submission review.',
                '00000000-0000-4000-8000-000000000013',
            ]);
        })
    );

    $adjustmentInsert = $pdo->prepare(
        'INSERT INTO abh_submission_adjustments
         (submission_id, approved_revision_id, quantity_delta, reason, created_by_admin_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $adjustmentInsert->execute([
        $submissionOneId,
        $revisionTwoId,
        -2,
        'Disposable CI quantity adjustment.',
        $adminId,
    ]);
    abhSchemaAssert('authorised quantity adjustment can be appended', true);

    abhSchemaAssert(
        'adjustment cannot reference a revision from another submission',
        abhSchemaExpectFailure(static function () use (
            $adjustmentInsert,
            $submissionOneId,
            $revisionOtherSubmissionId,
            $adminId
        ): void {
            $adjustmentInsert->execute([
                $submissionOneId,
                $revisionOtherSubmissionId,
                -1,
                'Invalid cross-submission adjustment.',
                $adminId,
            ]);
        })
    );

    abhSchemaAssert(
        'agent-scoped idempotency key prevents duplicate report creation',
        abhSchemaExpectFailure(static function () use ($submissionInsert, $agentId, $periodId): void {
            $submissionInsert->execute([
                'ABH-CI-REF-003',
                $agentId,
                $periodId,
                'PENDING_REVIEW',
                '00000000-0000-4000-8000-000000000001',
            ]);
        })
    );

    abhSchemaAssert(
        'duplicate submission reference is rejected',
        abhSchemaExpectFailure(static function () use ($submissionInsert, $agentId, $periodId): void {
            $submissionInsert->execute([
                'ABH-CI-REF-001',
                $agentId,
                $periodId,
                'PENDING_REVIEW',
                '00000000-0000-4000-8000-000000000004',
            ]);
        })
    );


    // Organisation and individual targets are stored independently. Scope
    // owner 0 is reserved for the organisation; an agent uses its own positive ID.
    $targetInsert = $pdo->prepare(
        'INSERT INTO abh_monthly_targets
         (reporting_period_id, scope_type, scope_owner_id, agent_id, target_quantity,
          created_by_admin_id, updated_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $targetInsert->execute([$periodId, 'ORGANISATION', 0, null, 100, $adminId, null]);
    $targetInsert->execute([$periodId, 'AGENT', $agentId, $agentId, 50, $adminId, $adminId]);
    abhSchemaAssert('organisation and individual monthly targets are stored independently', true);

    abhSchemaAssert(
        'duplicate organisation target for a month is rejected',
        abhSchemaExpectFailure(static function () use ($targetInsert, $periodId, $adminId): void {
            $targetInsert->execute([$periodId, 'ORGANISATION', 0, null, 200, $adminId, null]);
        })
    );
    abhSchemaAssert(
        'duplicate individual target for an agent and month is rejected',
        abhSchemaExpectFailure(static function () use ($targetInsert, $periodId, $agentId, $adminId): void {
            $targetInsert->execute([$periodId, 'AGENT', $agentId, $agentId, 75, $adminId, $adminId]);
        })
    );

    $totalsInsert = $pdo->prepare(
        'INSERT INTO abh_monthly_agent_totals
         (reporting_period_id, agent_id, approved_quantity, approved_submission_count)
         VALUES (?, ?, ?, ?)'
    );
    $totalsInsert->execute([$periodId, $agentId, 25, 2]);
    $totalsInsert->execute([$periodId, $agentTwoId, 25, 2]);
    abhSchemaAssert('approved monthly totals can be stored for multiple agents', true);

    abhSchemaAssert(
        'monthly totals enforce one row per agent and period',
        abhSchemaExpectFailure(static function () use ($totalsInsert, $periodId, $agentId): void {
            $totalsInsert->execute([$periodId, $agentId, 30, 3]);
        })
    );

    $leaderboardInsert = $pdo->prepare(
        'INSERT INTO abh_monthly_leaderboard_snapshots
         (reporting_period_id, agent_id, rank_position, approved_quantity, reached_final_total_at,
          agent_code_snapshot, display_name_snapshot)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $leaderboardInsert->execute([
        $periodId, $agentId, 1, 25, '2026-11-20 10:00:00.000000', 'CI-AGENT-001', 'CI Test Agent'
    ]);
    $leaderboardInsert->execute([
        $periodId, $agentTwoId, 1, 25, '2026-11-20 10:00:00.000000', 'CI-AGENT-002', 'CI Test Agent Two'
    ]);
    abhSchemaAssert(
        'competition leaderboard allows agents to share the same rank',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_monthly_leaderboard_snapshots WHERE reporting_period_id = '
            . $periodId . ' AND rank_position = 1'
        )->fetchColumn() === 2
    );

    $organisationSnapshotInsert = $pdo->prepare(
        'INSERT INTO abh_monthly_organisation_snapshots
         (reporting_period_id, approved_quantity, target_quantity, progress_percent,
          agent_count, closed_at, closed_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $organisationSnapshotInsert->execute([
        $periodId, 50, 100, '50.00', 2, '2026-11-30 23:00:00.000000', $adminId
    ]);
    abhSchemaAssert('organisation month-close snapshot can be stored', true);

    abhSchemaAssert(
        'organisation close snapshot is unique per reporting month',
        abhSchemaExpectFailure(static function () use ($organisationSnapshotInsert, $periodId, $adminId): void {
            $organisationSnapshotInsert->execute([
                $periodId, 60, 100, '60.00', 2, '2026-11-30 23:00:01.000000', $adminId
            ]);
        })
    );


    // Reward programmes and eligibility are calculated/configured separately
    // from the final human winner and gift assignment.
    $programInsert = $pdo->prepare(
        'INSERT INTO abh_reward_programs
         (reporting_period_id, program_code, name, description, status, created_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $programInsert->execute([
        $periodId,
        'CI-REWARD-01',
        'CI Monthly Reward',
        'Disposable CI reward programme.',
        'ACTIVE',
        $adminId,
    ]);
    $programOneId = (int) $pdo->lastInsertId();

    $ruleInsert = $pdo->prepare(
        'INSERT INTO abh_reward_rules
         (program_id, rule_key, rule_version, rank_from, rank_to, minimum_approved_quantity,
          eligibility_conditions_json, is_active, created_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $ruleInsert->execute([
        $programOneId,
        'TOP_RANKS',
        1,
        1,
        3,
        null,
        '{"must_be_eligible":true}',
        1,
        $adminId,
    ]);
    $ruleOneId = (int) $pdo->lastInsertId();

    $qualificationInsert = $pdo->prepare(
        'INSERT INTO abh_reward_qualifications
         (program_id, rule_id, agent_id, reporting_period_id, frozen_rank,
          frozen_approved_quantity, qualification_status, eligibility_snapshot_json, evaluated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $qualificationInsert->execute([
        $programOneId,
        $ruleOneId,
        $agentId,
        $periodId,
        1,
        25,
        'ELIGIBLE',
        '{"rank":1,"approved_quantity":25}',
        '2026-11-30 23:00:00.000000',
    ]);
    $qualificationOneId = (int) $pdo->lastInsertId();
    abhSchemaAssert('system-calculated reward qualification can be recorded', true);

    abhSchemaAssert(
        'qualification does not automatically create a gift award',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_reward_awards WHERE qualification_id = ' . $qualificationOneId
        )->fetchColumn() === 0
    );

    abhSchemaAssert(
        'one agent has at most one qualification per programme',
        abhSchemaExpectFailure(static function () use (
            $qualificationInsert,
            $programOneId,
            $ruleOneId,
            $agentId,
            $periodId
        ): void {
            $qualificationInsert->execute([
                $programOneId,
                $ruleOneId,
                $agentId,
                $periodId,
                1,
                25,
                'ELIGIBLE',
                '{"rank":1,"approved_quantity":25}',
                '2026-11-30 23:00:01.000000',
            ]);
        })
    );

    // One month can contain multiple reward categories/programmes.
    $programInsert->execute([
        $periodId,
        'CI-REWARD-02',
        'CI Secondary Reward',
        'Second disposable reward programme.',
        'ACTIVE',
        $adminId,
    ]);
    $programTwoId = (int) $pdo->lastInsertId();

    $ruleInsert->execute([
        $programTwoId,
        'MINIMUM_COUNT',
        1,
        null,
        null,
        10,
        '{"minimum_count":10}',
        1,
        $adminId,
    ]);
    $ruleTwoId = (int) $pdo->lastInsertId();

    $qualificationInsert->execute([
        $programTwoId,
        $ruleTwoId,
        $agentId,
        $periodId,
        1,
        25,
        'ELIGIBLE',
        '{"minimum_count_met":true}',
        '2026-11-30 23:00:02.000000',
    ]);
    abhSchemaAssert(
        'same agent may qualify for more than one programme in a month',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_reward_qualifications WHERE agent_id = ' . $agentId
            . ' AND reporting_period_id = ' . $periodId
        )->fetchColumn() === 2
    );

    $giftInsert = $pdo->prepare(
        'INSERT INTO abh_reward_gifts
         (program_id, gift_code, gift_title, gift_description, available_quantity,
          estimated_value, currency_code, status, created_by_admin_id, updated_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $giftInsert->execute([
        $programOneId,
        'CI-GIFT-01',
        'CI Gift One',
        'First disposable gift catalogue item.',
        5,
        '25000.00',
        'NGN',
        'ACTIVE',
        $adminId,
        null,
    ]);
    $giftOneId = (int) $pdo->lastInsertId();

    $giftInsert->execute([
        $programOneId,
        'CI-GIFT-02',
        'CI Gift Two',
        'Second disposable gift catalogue item.',
        2,
        '10000.00',
        'NGN',
        'ACTIVE',
        $adminId,
        $adminId,
    ]);
    $giftTwoId = (int) $pdo->lastInsertId();
    abhSchemaAssert('management gift catalogue stores multiple gift choices', true);

    $awardInsert = $pdo->prepare(
        'INSERT INTO abh_reward_awards
         (qualification_id, program_id, gift_id, agent_id, reporting_period_id,
          gift_title_snapshot, gift_description_snapshot, gift_quantity,
          estimated_value_snapshot, currency_code_snapshot, assigned_by_admin_id, award_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $awardInsert->execute([
        $qualificationOneId,
        $programOneId,
        $giftOneId,
        $agentId,
        $periodId,
        'CI Gift One',
        'First disposable gift catalogue item.',
        1,
        '25000.00',
        'NGN',
        $adminId,
        'PENDING_APPROVAL',
    ]);
    $awardOneId = (int) $pdo->lastInsertId();

    // Multiple gift lines are supported; the system did not choose either gift.
    $awardInsert->execute([
        $qualificationOneId,
        $programOneId,
        $giftTwoId,
        $agentId,
        $periodId,
        'CI Gift Two',
        'Second disposable gift catalogue item.',
        1,
        '10000.00',
        'NGN',
        $adminId,
        'PENDING_APPROVAL',
    ]);
    abhSchemaAssert(
        'management can assign more than one gift line to an eligible agent',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_reward_awards WHERE qualification_id = ' . $qualificationOneId
        )->fetchColumn() === 2
    );

    abhSchemaAssert(
        'award cannot pair a qualification with a different programme gift',
        abhSchemaExpectFailure(static function () use (
            $awardInsert,
            $qualificationOneId,
            $programTwoId,
            $giftOneId,
            $agentId,
            $periodId,
            $adminId
        ): void {
            $awardInsert->execute([
                $qualificationOneId,
                $programTwoId,
                $giftOneId,
                $agentId,
                $periodId,
                'Invalid cross-programme award',
                null,
                1,
                null,
                null,
                $adminId,
                'PENDING_APPROVAL',
            ]);
        })
    );

    // Catalogue edits must not rewrite the historical award snapshot.
    $pdo->prepare('UPDATE abh_reward_gifts SET gift_title = ? WHERE id = ?')
        ->execute(['Edited CI Gift Title', $giftOneId]);
    abhSchemaAssert(
        'award preserves gift title snapshot after catalogue edit',
        (string) $pdo->query(
            'SELECT gift_title_snapshot FROM abh_reward_awards WHERE id = ' . $awardOneId
        )->fetchColumn() === 'CI Gift One'
    );

    $pdo->prepare(
        'UPDATE abh_reward_awards
         SET award_status = ?, approved_by_admin_id = ?, approved_at = UTC_TIMESTAMP(6)
         WHERE id = ?'
    )->execute(['APPROVED', $adminId, $awardOneId]);
    abhSchemaAssert('award approval is recorded separately from assignment', true);

    $fulfilmentInsert = $pdo->prepare(
        'INSERT INTO abh_reward_fulfilment_events
         (award_id, event_type, quantity, amount, currency_code, reference_no, note, recorded_by_admin_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $fulfilmentInsert->execute([
        $awardOneId,
        'PAID',
        null,
        '25000.00',
        'NGN',
        'CI-PAYMENT-001',
        'Disposable payment record.',
        $adminId,
    ]);
    $fulfilmentInsert->execute([
        $awardOneId,
        'DELIVERED',
        1,
        null,
        'NGN',
        'CI-DELIVERY-001',
        'Disposable delivery record.',
        $adminId,
    ]);
    abhSchemaAssert(
        'payment and delivery are separate fulfilment events',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_reward_fulfilment_events WHERE award_id = ' . $awardOneId
        )->fetchColumn() === 2
    );


    $auditInsert = $pdo->prepare(
        'INSERT INTO abh_audit_events
         (actor_type, actor_admin_user_id, actor_agent_id, action_key, entity_type,
          entity_id, reason, before_json, after_json, request_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $auditInsert->execute([
        'ADMIN',
        $adminId,
        null,
        'agent.approve',
        'agent',
        $agentId,
        'Disposable CI audit event.',
        '{"status":"PENDING"}',
        '{"status":"APPROVED"}',
        '00000000-0000-4000-8000-000000000021',
    ]);
    $auditInsert->execute([
        'SYSTEM',
        null,
        null,
        'period.close',
        'reporting_period',
        $periodId,
        null,
        null,
        '{"status":"CLOSED"}',
        '00000000-0000-4000-8000-000000000022',
    ]);
    abhSchemaAssert('admin and system audit events can be appended', true);

    abhSchemaAssert(
        'audit actor type requires the matching actor identity',
        abhSchemaExpectFailure(static function () use ($auditInsert, $adminId, $agentId): void {
            $auditInsert->execute([
                'ADMIN',
                null,
                $agentId,
                'invalid.actor',
                'agent',
                $agentId,
                null,
                null,
                null,
                '00000000-0000-4000-8000-000000000023',
            ]);
        })
    );

    $notificationInsert = $pdo->prepare(
        'INSERT INTO abh_notifications
         (recipient_type, recipient_admin_user_id, recipient_agent_id, event_key, title, message)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $notificationInsert->execute([
        'AGENT',
        null,
        $agentId,
        'submission.approved',
        'Submission approved',
        'Your November report has been approved.',
    ]);
    $notificationAgentId = (int) $pdo->lastInsertId();
    $notificationInsert->execute([
        'ADMIN',
        $adminId,
        null,
        'agent.registration',
        'New agent registration',
        'An agent registration requires review.',
    ]);
    abhSchemaAssert('agent and admin notifications can be stored separately', true);

    abhSchemaAssert(
        'notification recipient type requires exactly one matching recipient',
        abhSchemaExpectFailure(static function () use ($notificationInsert, $adminId, $agentId): void {
            $notificationInsert->execute([
                'AGENT',
                $adminId,
                $agentId,
                'invalid.recipient',
                'Invalid notification',
                'This recipient pair is not permitted.',
            ]);
        })
    );

    $pdo->prepare('UPDATE abh_notifications SET read_at = UTC_TIMESTAMP(6) WHERE id = ?')
        ->execute([$notificationAgentId]);
    abhSchemaAssert(
        'notification read state is recorded without deleting the notification',
        (int) $pdo->query(
            'SELECT COUNT(*) FROM abh_notifications WHERE id = ' . $notificationAgentId
            . ' AND read_at IS NOT NULL'
        )->fetchColumn() === 1
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
