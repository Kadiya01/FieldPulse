<?php

declare(strict_types=1);

/**
 * Database integration suite.
 *
 *   php private_storage/bin/integration.php
 *   php private_storage/bin/integration.php --env=/path/to/.env
 *   php private_storage/bin/integration.php --filter=review --verbose
 *
 * WHY THIS EXISTS
 *
 * bin/selftest.php passes without a database, and that is a weaker guarantee
 * than it looks. Every defect below survived a green self-test and would have
 * failed on the first real request against a fresh cPanel install:
 *
 *   - devices.revoked_at and submissions.client_notes were referenced by PHP but
 *     never created by a migration, so both raised ER_BAD_FIELD_ERROR.
 *   - ReviewRepository guarded with `final_disposition IS NULL` on a column
 *     declared NOT NULL, so every review returned 409 and the conditional UPDATE
 *     matched zero rows. The whole review path was unreachable.
 *   - ReviewRepository bound the API vocabulary (APPROVE/REJECT) into an ENUM
 *     that only accepts VERIFIED/REJECTED.
 *   - findAuthorisedPair() did not project agents.role, so Kernel::assertOperator
 *     defaulted every caller to AGENT and 403'd every supervisor.
 *
 * All four are schema/code mismatches, and a pure-logic suite is structurally
 * incapable of seeing them. This file runs the real repositories against a real
 * schema so that the next mismatch is a test failure rather than a 500 in
 * production.
 *
 * SAFETY
 *
 * Refuses to run against APP_ENV=production, and against any database whose name
 * does not look like a test or development database, unless --force is given.
 * It only ever deletes rows it created itself, tracked by primary key.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\JobRepository;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Database\ReviewRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Security\AuthContext;
use FieldPulse\Security\Jwk;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Paths;
use FieldPulse\Testing\TestRunner;
use FieldPulse\Verification\DecisionMatrix;
use FieldPulse\Verification\VerificationService;

$argv     = Cli::argv();
$envFile  = Cli::option($argv, 'env');
$force    = Cli::hasFlag($argv, 'force');
$filter   = Cli::option($argv, 'filter');
$verbose  = Cli::hasFlag($argv, 'verbose');

Config::boot($envFile);

$config = Config::instance();
$dbName = $config->str('db.name');
$appEnv = $config->str('app.env');

if ($appEnv === 'production' && !$force) {
    fwrite(STDERR, "Refusing to run: APP_ENV=production. Pass --force if this really is a throwaway database." . PHP_EOL);
    exit(2);
}

if (preg_match('/test|dev|local|ci/i', $dbName) !== 1 && !$force) {
    fwrite(STDERR, "Refusing to run against database '$dbName': the name does not look like a test database." . PHP_EOL);
    fwrite(STDERR, "This suite writes and deletes rows. Pass --force to override." . PHP_EOL);
    exit(2);
}

try {
    $serverVersion = Connection::serverVersion();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reach the database: " . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Read the connection settings from ' . ($envFile ?? 'private_storage/.env') . PHP_EOL);
    exit(2);
}

Paths::ensureLayout();

$t = new TestRunner($filter);

/* ---------------------------------------------------------------------------
 * Fixtures
 *
 * Everything is tagged with a run-scoped suffix so a crashed run leaves
 * recognisable rows rather than anonymous ones, and so cleanup can find its own
 * rows without touching anyone else's.
 * ------------------------------------------------------------------------- */

$runTag = 'it' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

/** @var list<int> $createdAgentIds */
$createdAgentIds = [];
/** @var list<int> $createdDeviceIds */
$createdDeviceIds = [];
/** @var list<int> $createdSubmissionIds */
$createdSubmissionIds = [];
/** @var list<string> $createdFiles */
$createdFiles = [];
/** @var int $agentSeq Agent codes are unique, so every fixture needs its own. */
$agentSeq = 0;

/**
 * Snapshot of the storage tree as it was before this run.
 *
 * verify() moves each judged file out of quarantine and renames it to a fresh
 * UUID, so sweeping for the run tag afterwards matches nothing. Diffing against
 * this snapshot instead removes exactly what this run created and cannot touch
 * a file that was already there.
 */
$storageDirs = [
    Paths::quarantineDir(),
    Paths::verifiedDir(),
    Paths::reviewDir(),
    Paths::rejectedDir(),
];

$baselineFiles = [];

foreach ($storageDirs as $dir) {
    foreach ((array) glob($dir . '/*') as $path) {
        $baselineFiles[(string) $path] = true;
    }
}

$agents   = new AgentRepository();
$devices  = new DeviceRepository();
$submissions = new SubmissionRepository();
$reviews  = new ReviewRepository();
$leaderboard = new LeaderboardRepository();
$jobs     = new JobRepository();

/**
 * Create an agent with a role, optionally with one assigned site.
 */
$makeAgent = static function (string $role, bool $withSite, float $lat, float $lng) use (
    $runTag,
    $agents,
    &$createdAgentIds,
    &$agentSeq
): int {
    $code = $runTag . '-' . strtolower($role) . '-' . (++$agentSeq);

    $id = $agents->create($code, 'Integration ' . $role, null);

    $createdAgentIds[] = $id;

    Connection::execute(
        'UPDATE agents SET role = :role WHERE id = :id',
        ['role' => $role, 'id' => $id]
    );

    if ($withSite) {
        Connection::execute(
            'INSERT INTO agent_sites (agent_id, name, center_latitude, center_longitude, radius_m, is_active, created_at, updated_at)
             VALUES (:agent, :name, :lat, :lng, 250, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'agent' => $id,
                'name'  => $runTag . ' site ' . $agentSeq,
                'lat'   => $lat,
                'lng'   => $lng,
            ]
        );
    }

    return $id;
};

/**
 * Create a device bound to an agent.
 */
$makeDevice = static function (int $agentId) use ($runTag, $devices, &$createdDeviceIds): int {
    $uuid  = \FieldPulse\Support\Uuid::v4();
    $pair  = Jwk::generateKeyPair();

    $id = $devices->create($agentId, $uuid, $pair['public'], null);

    $createdDeviceIds[] = $id;

    return $id;
};

/**
 * Inject a minimal APP1/EXIF block carrying DateTimeOriginal into a JPEG.
 *
 * PHP cannot add EXIF to an existing image through GD, and without a capture
 * time in the file every submission is TIMESTAMP_MISSING and lands in review,
 * so the auto-verify path would be untestable. The block is built by hand:
 * little-endian TIFF header, an IFD0 pointing at an Exif sub-IFD, and the
 * DateTimeOriginal string in the data area. Only the tags the pipeline reads
 * are present.
 */
$injectExif = static function (string $jpegPath, \DateTimeImmutable $when): void {
    $bytes = file_get_contents($jpegPath);

    if ($bytes === false || strncmp($bytes, "\xFF\xD8", 2) !== 0) {
        throw new \RuntimeException('EXIF fixture needs a real JPEG');
    }

    $stamp  = $when->setTimezone(new \DateTimeZone('UTC'))->format('Y:m:d H:i:s') . "\0";
    $make   = "HP\0";
    $model  = 'PH50';

    $ifd0Entries = 3;
    $exifEntries = 4;

    $ifd0Offset = 8;
    $exifOffset = $ifd0Offset + 2 + ($ifd0Entries * 12) + 4;
    $dataOffset = $exifOffset + 2 + ($exifEntries * 12) + 4;

    // Each IFD entry is tag(2) type(2) count(4) value-or-offset(4). Values of
    // four bytes or fewer are stored inline; longer ones hold a file offset.
    $entry = static fn (int $tag, int $type, int $count, string $value4): string
        => pack('vvV', $tag, $type, $count) . $value4;

    $ifd0 = pack('v', $ifd0Entries)
        . $entry(0x010F, 2, strlen($make),  str_pad($make, 4, "\0"))
        . $entry(0x0110, 2, strlen($model), str_pad($model, 4, "\0"))
        . $entry(0x8769, 4, 1, pack('V', $exifOffset))
        . pack('V', 0);

    $exifIfd = pack('v', $exifEntries)
        . $entry(0x9003, 2, 20, pack('V', $dataOffset))
        . $entry(0x9004, 2, 20, pack('V', $dataOffset + 20))
        . $entry(0xA002, 4, 1, pack('V', 160))
        . $entry(0xA003, 4, 1, pack('V', 160))
        . pack('V', 0);

    $tiff    = "II" . pack('v', 42) . pack('V', $ifd0Offset) . $ifd0 . $exifIfd . $stamp . $stamp;
    $payload = "Exif\0\0" . $tiff;
    $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    file_put_contents($jpegPath, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));
};

/**
 * Write a real JPEG into quarantine and return [absolutePath, relativePath, sha256].
 *
 * A genuine encoded image, not a stub: the pipeline decodes it, reads its EXIF,
 * runs the DCT and hashes it, and a fixture that skipped that would not
 * exercise the code that actually breaks. Pass $exifAt to give the photo a
 * capture time.
 */
$makeImage = static function (int $seed, int $size = 160, ?\DateTimeImmutable $exifAt = null) use (
    &$createdFiles,
    $runTag,
    $injectExif
): array {
    $image = imagecreatetruecolor($size, $size);

    // Deterministic structure so the pHash is meaningful and differs per seed.
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $r = ($x * 7 + $seed * 31) % 256;
            $g = ($y * 5 + $seed * 17) % 256;
            $b = (($x ^ $y) + $seed * 11) % 256;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
        }
    }

    $name = $runTag . '-' . $seed . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $absolute = Paths::quarantineDir() . DIRECTORY_SEPARATOR . $name;

    imagejpeg($image, $absolute, 90);
    imagedestroy($image);

    if ($exifAt !== null) {
        $injectExif($absolute, $exifAt);
    }

    $createdFiles[] = $absolute;

    return [$absolute, Paths::relativeForAbsolutePath($absolute), hash_file('sha256', $absolute)];
};

/**
 * Insert a submission row referencing a real file on disk.
 */
$makeSubmission = static function (
    int $agentId,
    int $deviceId,
    array $image,
    int $countClaimed = 1,
    ?float $lat = null,
    ?float $lng = null
) use ($submissions, &$createdSubmissionIds, $runTag): int {
    [$absolute, $relative, $sha] = $image;

    $id = $submissions->insert(
        \FieldPulse\Support\Uuid::v4(),
        $agentId,
        $deviceId,
        $countClaimed,
        $lat,
        $lng,
        Clock::sql(),
        $relative,
        $sha,
        'image/jpeg',
        (int) filesize($absolute),
        160,
        160
    );

    $createdSubmissionIds[] = $id;

    return $id;
};

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    &$createdSubmissionIds,
    $baselineFiles,
    $storageDirs
): void {
    // Remove anything this run added to the storage tree, whatever verify()
    // renamed it to.
    foreach ($storageDirs as $dir) {
        foreach ((array) glob($dir . '/*') as $path) {
            $path = (string) $path;

            if (!isset($baselineFiles[$path])) {
                @unlink($path);
            }
        }
    }

    if ($createdAgentIds === []) {
        return;
    }

    try {
        // Reverse dependency order. Deleting agents would cascade to most of
        // this, but devices.agent_id and submissions.agent_id are ON DELETE
        // RESTRICT, so the children go first regardless.
        //
        // audit rows for a disposition are written with actor_agent_id = NULL
        // (the worker is not an agent), so they have to be matched on the
        // submission they describe rather than on the reviewer.
        foreach ($createdSubmissionIds as $submissionId) {
            Connection::execute('DELETE FROM audit_logs WHERE entity_type = :t AND entity_id = :id', [
                't'  => 'submission',
                'id' => $submissionId,
            ]);
            Connection::execute('DELETE FROM processing_jobs WHERE submission_id = :id', ['id' => $submissionId]);
        }

        foreach ($createdSubmissionIds as $submissionId) {
            Connection::execute('DELETE FROM submissions WHERE id = :id', ['id' => $submissionId]);
        }

        foreach ($createdAgentIds as $agentId) {
            Connection::execute('DELETE FROM refresh_tokens WHERE agent_id = :id', ['id' => $agentId]);
            Connection::execute('DELETE FROM pairing_codes WHERE agent_id = :id', ['id' => $agentId]);
        }

        foreach ($createdDeviceIds as $deviceId) {
            Connection::execute('DELETE FROM request_nonces WHERE device_id = :id', ['id' => $deviceId]);
        }

        foreach ($createdDeviceIds as $deviceId) {
            Connection::execute('DELETE FROM devices WHERE id = :id', ['id' => $deviceId]);
        }

        foreach ($createdAgentIds as $agentId) {
            Connection::execute('DELETE FROM audit_logs WHERE actor_agent_id = :id', ['id' => $agentId]);
        }

        // agent_sites and agent_performance_summary cascade from agents.
        foreach ($createdAgentIds as $agentId) {
            Connection::execute('DELETE FROM agents WHERE id = :id', ['id' => $agentId]);
        }
    } catch (Throwable) {
        // Cleanup is best effort. The run-scoped agent code makes leftovers
        // findable, and a leftover is far better than a failed test.
    }
});

/* ---------------------------------------------------------------------------
 * schema
 * ------------------------------------------------------------------------- */

$t->group('schema');

$t->test('every table the code writes to exists', function (TestRunner $t): void {
    $tables = [
        'agents', 'devices', 'refresh_tokens', 'submissions',
        'submission_verifications', 'processing_jobs', 'agent_performance_summary',
        'audit_logs', 'request_nonces', 'agent_sites',
        'login_attempts', 'pairing_codes',
    ];

    foreach ($tables as $table) {
        $count = Connection::fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = :db AND table_name = :t',
            ['db' => Config::instance()->str('db.name'), 't' => $table]
        );

        $t->assertSame(1, (int) $count, "table $table exists");
    }
});

$t->test('columns the code writes to but the schema may lack', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission
): void {
    // devices.revoked_at: VerificationService::deviceStatus() selects it on
    // every verification. submissions.client_notes: SubmitController writes it
    // inside the submit transaction. Both were referenced by PHP and created by
    // no migration, so both raised ER_BAD_FIELD_ERROR on a fresh install.
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(90);

    $row = Connection::fetchOne('SELECT status, revoked_at FROM devices WHERE id = :id', ['id' => $deviceId]);
    $t->assertNotNull($row, 'the deviceStatus() query runs');
    $t->assertTrue(array_key_exists('revoked_at', $row), 'revoked_at is present in the result');

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 1, 6.5244, 3.3792);

    Connection::execute(
        'UPDATE submissions SET client_notes = :notes WHERE id = :id',
        ['notes' => 'integration note', 'id' => $submissionId]
    );

    $stored = Connection::fetchValue('SELECT client_notes FROM submissions WHERE id = :id', ['id' => $submissionId]);
    $t->assertSame('integration note', (string) $stored, 'client_notes round-trips');
});

/* ---------------------------------------------------------------------------
 * devices
 * ------------------------------------------------------------------------- */

$t->group('devices');

$t->test('the authenticated pair carries the agent role', function (TestRunner $t) use ($runTag, $makeAgent, $makeDevice, $devices, &$createdAgentIds): void {
    // findAuthorisedPair() omitted a.role, so Kernel::assertOperator()'s
    // $context->agent()['role'] ?? 'AGENT' defaulted every caller to AGENT and
    // 403'd every supervisor on every operator route.
    $agentId  = $makeAgent('SUPERVISOR', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $device = $devices->findById($deviceId);
    $t->assertNotNull($device);

    $pair = $devices->findAuthorisedPair((string) $device['device_uuid']);

    $t->assertNotNull($pair, 'the pair resolves');
    $t->assertTrue(array_key_exists('role', $pair['agent']), 'agent.role is present');
    $t->assertSame('SUPERVISOR', $pair['agent']['role'], 'role is projected, not defaulted');
});

$t->test('the operator gate admits a supervisor and refuses an agent', function (TestRunner $t): void {
    // Exercises the real Kernel::assertOperator() via reflection, because the
    // consequence of the missing column is what matters, not the projection.
    $method = new ReflectionMethod(\FieldPulse\Http\Kernel::class, 'assertOperator');
    $method->setAccessible(true);

    $contextFor = static function (string $role): AuthContext {
        return new AuthContext(
            ['id' => 1, 'agent_code' => 'x', 'full_name' => 'x', 'status' => 'ACTIVE', 'role' => $role],
            ['id' => 1, 'device_uuid' => 'x', 'status' => 'ACTIVE'],
            []
        );
    };

    $method->invoke(null, $contextFor('SUPERVISOR'), 'reviews.decide');
    $t->assertTrue(true, 'SUPERVISOR passes the gate');

    $method->invoke(null, $contextFor('ADMIN'), 'reviews.decide');
    $t->assertTrue(true, 'ADMIN passes the gate');

    $t->assertThrows(
        static fn () => $method->invoke(null, $contextFor('AGENT'), 'reviews.decide'),
        null,
        'AGENT is refused'
    );
});

$t->test('revoked_at tracks the device status', function (TestRunner $t) use ($makeAgent, $makeDevice, $devices, $createdDeviceIds): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $read = static fn (int $id): array => (array) Connection::fetchOne(
        'SELECT status, revoked_at FROM devices WHERE id = :id',
        ['id' => $id]
    );

    $devices->setStatus($deviceId, \FieldPulse\Security\DeviceStatus::REVOKED);
    $after = $read($deviceId);
    $t->assertSame('REVOKED', (string) $after['status']);
    $t->assertNotNull($after['revoked_at'], 'revoked_at is stamped on revocation');

    $devices->setStatus($deviceId, \FieldPulse\Security\DeviceStatus::ACTIVE);
    $after = $read($deviceId);
    $t->assertSame('ACTIVE', (string) $after['status']);
    $t->assertNull($after['revoked_at'], 'revoked_at clears so it cannot report a stale revocation');

    $devices->setStatus($deviceId, \FieldPulse\Security\DeviceStatus::REVOKED);
    $t->assertNotNull($read($deviceId)['revoked_at'], 're-revocation stamps again');

    $devices->revokeAllForAgent($agentId);
    $t->assertSame(0, (int) Connection::fetchValue(
        'SELECT COUNT(*) FROM devices WHERE agent_id = :a AND status <> :r',
        ['a' => $agentId, 'r' => \FieldPulse\Security\DeviceStatus::REVOKED]
    ), 'revokeAllForAgent leaves nothing active');
});

/* ---------------------------------------------------------------------------
 * verification
 * ------------------------------------------------------------------------- */

$t->group('verification');

$t->test('a clean submission verifies end to end', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    // Drives the real pipeline: integrity, decode, EXIF, pHash, duplicates,
    // geofence, decision matrix, the verdict row, aggregation and the file move.
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(1, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 5, 6.5244, 3.3792);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::VERIFIED, $result['disposition'], 'in-geofence, clean image verifies');
    $t->assertSame(DecisionMatrix::ALL_CHECKS_PASSED, $result['reason']);

    $row = $submissions->findById($submissionId);
    $t->assertSame('VERIFIED', (string) $row['status'], 'submission status updated');
    $t->assertNotNull($row['verified_at'], 'verified_at stamped');
    $t->assertMatches('/^[0-9a-f]{16}$/', (string) $row['phash_hex'], 'pHash persisted');
    $t->assertNotNull($row['phash_band_1'], 'bands persisted');

    // The EXIF capture time must have been read out of the file, not defaulted.
    $t->assertNotNull($row['server_exif_captured_at'], 'EXIF capture time was extracted');

    $verification = $submissions->findVerification($submissionId);
    $t->assertNotNull($verification, 'a verdict row exists');
    $t->assertSame('VERIFIED', (string) $verification['final_disposition']);
    $t->assertNull($verification['reviewed_at'], 'an automated verdict is not a human review');
});

$t->test('a photo with no EXIF is held for review, not auto-verified', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission
): void {
    // Policy, not an accident: TimestampVerifier treats a missing EXIF time as
    // review-grade because the client's asserted timestamp is self-reported and
    // would not survive an agent who simply set their own clock. Asserted here
    // so a future change to that rule is a deliberate edit to this test.
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(10);

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, 6.5244, 3.3792);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertSame(DecisionMatrix::TIMESTAMP_MISSING, $result['reason']);
});

$t->test('an agent with no assigned site lands in review, not a false pass', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    $agentId  = $makeAgent('AGENT', false, 0.0, 0.0);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(2, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 3, 6.5244, 3.3792);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertSame(DecisionMatrix::SITE_UNASSIGNED, $result['reason']);

    $row = $submissions->findById($submissionId);
    $t->assertSame('REQUIRES_REVIEW', (string) $row['status'], 'status matches the disposition');
    $t->assertNull($row['verified_at'], 'a flagged submission is never marked verified');
});

$t->test('a byte-identical resubmission is caught as an exact duplicate', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $shotAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    // Two separate files with byte-identical content. verify() moves the file
    // out of quarantine once it has judged it, so if both rows pointed at one
    // path the second submission would come back IMAGE_UNREADABLE and the
    // duplicate check would never be reached.
    $first  = $makeSubmission($agentId, $deviceId, $makeImage(3, 160, $shotAt), 2, 6.5244, 3.3792);
    $second = $makeSubmission($agentId, $deviceId, $makeImage(3, 160, $shotAt), 2, 6.5244, 3.3792);

    (new VerificationService())->verify($first);
    $result = (new VerificationService())->verify($second);

    // A byte-identical file is rejection-grade, not review-grade: there is
    // nothing for a supervisor to look at, the same bytes were already paid for.
    $t->assertSame(DecisionMatrix::REJECTED, $result['disposition'], 'the duplicate is not paid again');
    $t->assertSame(DecisionMatrix::EXACT_DUPLICATE, $result['reason']);

    $verification = $submissions->findVerification($second);
    $t->assertNotNull($verification, 'a verdict row exists for the duplicate');
    $t->assertSame('REJECTED', (string) $verification['final_disposition']);

    // findVerification() is the status endpoint's projection and does not carry
    // the duplicate columns, so read the ledger directly to assert on them.
    $stored = Connection::fetchOne(
        'SELECT exact_duplicate_status, perceptual_duplicate_status
           FROM submission_verifications WHERE submission_id = :id',
        ['id' => $second]
    );

    $t->assertNotNull($stored);
    $t->assertSame('DUPLICATE', (string) $stored['exact_duplicate_status']);
    $t->assertSame('NOT_EVALUATED', (string) $stored['perceptual_duplicate_status'], 'no point hashing what is already an exact match');

    $row = $submissions->findById($second);
    $t->assertSame('REJECTED', (string) $row['status']);
    $t->assertNull($row['verified_at'], 'a rejected duplicate is never marked verified');
});

$t->test('a tampered file is rejected', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    // The stored digest is the record of what arrived. If the bytes change after
    // receipt, the submission is not the thing the agent took.
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(4);

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 1, 6.5244, 3.3792);

    $handle = fopen($image[0], 'ab');
    fwrite($handle, 'tampered');
    fclose($handle);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REJECTED, $result['disposition'], 'tampering is rejected outright');
    $t->assertSame('FILE_TAMPERED', $result['reason']);
});

/* ---------------------------------------------------------------------------
 * review
 * ------------------------------------------------------------------------- */

$t->group('review');

$t->test('a pending submission can be approved, once', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $reviews,
    $submissions
): void {
    // The defect this pins: the guard was `final_disposition IS NULL` on a NOT
    // NULL column, so decide() 409'd every pending submission and the conditional
    // UPDATE matched zero rows. Reviewed_at is the documented pending marker.
    $agentId  = $makeAgent('AGENT', false, 0.0, 0.0);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(5, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 4, 6.5244, 3.3792);

    (new VerificationService())->verify($submissionId);
    $t->assertSame('REQUIRES_REVIEW', (string) $submissions->findById($submissionId)['status'], 'precondition: pending');

    $supervisorId = $makeAgent('SUPERVISOR', true, 6.5244, 3.3792);

    $outcome = $reviews->decide(
        $submissionId,
        ReviewRepository::DECISION_APPROVE,
        $supervisorId,
        'confirmed on site, approving'
    );

    $t->assertTrue($outcome['applied'], 'the decision applied');

    $verification = $submissions->findVerification($submissionId);

    // APPROVE must be translated into the stored vocabulary. Binding it raw put
    // 'APPROVE' into an ENUM of ('VERIFIED','REQUIRES_REVIEW','REJECTED').
    $t->assertSame('VERIFIED', (string) $verification['final_disposition'], 'stored as VERIFIED, not APPROVE');
    $t->assertSame($supervisorId, (int) $verification['reviewed_by_agent_id'], 'attributed to the reviewer');
    $t->assertNotNull($verification['reviewed_at'], 'stamped');
    $t->assertSame('confirmed on site, approving', (string) $verification['review_note'], 'note kept');

    $row = $submissions->findById($submissionId);
    $t->assertSame('VERIFIED', (string) $row['status']);
    $t->assertNotNull($row['verified_at'], 'approval sets verified_at');

    $t->assertThrows(
        fn () => $reviews->decide(
            $submissionId,
            ReviewRepository::DECISION_APPROVE,
            $supervisorId,
            'trying to review it twice'
        ),
        'already been reviewed',
        'a second decision is refused'
    );
});

$t->test('a rejection stores REJECTED and clears verified_at', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $reviews,
    $submissions
): void {
    $agentId  = $makeAgent('AGENT', false, 0.0, 0.0);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(6, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, 6.5244, 3.3792);
    (new VerificationService())->verify($submissionId);

    $supervisorId = $makeAgent('SUPERVISOR', true, 6.5244, 3.3792);

    $reviews->decide($submissionId, ReviewRepository::DECISION_REJECT, $supervisorId, 'out of geofence, rejecting');

    $verification = $submissions->findVerification($submissionId);
    $t->assertSame('REJECTED', (string) $verification['final_disposition'], 'stored as REJECTED, not REJECT');

    $row = $submissions->findById($submissionId);
    $t->assertSame('REJECTED', (string) $row['status']);
    $t->assertNull($row['verified_at'], 'a rejected submission is never marked verified');
});

$t->test('the review queue lists pending work', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $reviews
): void {
    $agentId  = $makeAgent('AGENT', false, 0.0, 0.0);
    $deviceId = $makeDevice($agentId);

    $before = $reviews->queue(500, 0, null, null)['total'];

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(7, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), 1, 6.5244, 3.3792);
    (new VerificationService())->verify($submissionId);

    $after = $reviews->queue(500, 0, null, null)['total'];

    $t->assertSame($before + 1, $after, 'the flagged submission appears in the queue');
});

/* ---------------------------------------------------------------------------
 * leaderboard
 * ------------------------------------------------------------------------- */

$t->group('leaderboard');

$t->test('the summary counts the period it was verified in', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions,
    $leaderboard
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(8, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))), 7, 6.5244, 3.3792);
    (new VerificationService())->verify($submissionId);

    $submission = $submissions->findById($submissionId);
    $period     = PeriodResolver::periodFor((string) $submission['server_received_at']);

    $row = Connection::fetchOne(
        'SELECT * FROM agent_performance_summary WHERE agent_id = :a AND period_start_date = :p',
        ['a' => $agentId, 'p' => $period['date']]
    );

    $t->assertNotNull($row, 'a summary row exists for the derived period');
    $t->assertSame(7, (int) $row['total_verified_count'], 'the claimed count is what is paid');
    $t->assertSame(1, (int) $row['total_submissions']);

    // The summary must land on the Monday, not an hour off it. periodFor() used
    // to start periods at 01:00 local because period_day was passed as the hour
    // argument to setTime(), which disagreed with utcRangeForPeriod() and moved
    // the first hour of every week into the wrong total.
    $t->assertMatches('/^\d{4}-\d{2}-\d{2}$/', (string) $row['period_start_date']);
    $t->assertSame('1', (string) date('N', strtotime((string) $row['period_start_date'])), 'the period starts on a Monday');

    $board = $leaderboard->board($period['date'], 50, 0);
    $t->assertTrue($board['total'] >= 1, 'the agent is on the board');
});

/* ---------------------------------------------------------------------------
 * queue
 * ------------------------------------------------------------------------- */

$t->group('queue');

$t->test('a job is enqueued, claimed once and completed', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $runTag
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(9), 1, 6.5244, 3.3792);

    $jobId = $jobs->enqueue($submissionId);
    $t->assertTrue($jobId > 0, 'enqueue returns a job id');

    // Idempotent: enqueueing the same submission again must not double-queue.
    $again = $jobs->enqueue($submissionId);
    $t->assertSame(
        1,
        (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM processing_jobs WHERE submission_id = :id',
            ['id' => $submissionId]
        ),
        'one job per submission'
    );

    $claimed = $jobs->claimBatch($runTag . '-worker', 10);
    $t->assertTrue($claimed !== [], 'the worker claims the job');

    $mine = array_values(array_filter($claimed, static fn (array $j): bool => (int) $j['submission_id'] === $submissionId));
    $t->assertSame(1, count($mine), 'the job is in the batch');

    $second = $jobs->claimBatch($runTag . '-worker-2', 10);
    $t->assertSame(0, count(array_filter($second, static fn (array $j): bool => (int) $j['submission_id'] === $submissionId)), 'a claimed job is not claimed again');

    $jobs->complete((int) $mine[0]['id']);

    $t->assertSame(
        'COMPLETED',
        (string) Connection::fetchValue('SELECT status FROM processing_jobs WHERE id = :id', ['id' => (int) $mine[0]['id']]),
        'the job is completed'
    );
});

/* ---------------------------------------------------------------------------
 * Exit
 * ------------------------------------------------------------------------- */

Cli::heading('FieldPulse integration (server ' . $serverVersion . ', database ' . $dbName . ')');

$code = $t->run($verbose);

exit($code);
