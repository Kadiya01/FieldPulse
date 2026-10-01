<?php

declare(strict_types=1);

/**
 * Queue / worker concurrency suite (Phase 5).
 *
 *   php private_storage/bin/queue.php
 *   php private_storage/bin/queue.php --filter=stale --verbose
 *   php private_storage/bin/queue.php --env=/path/to/.env
 *
 * WHY THIS EXISTS
 *
 * integration.php exercises the queue in a single process, which is exactly the
 * blind spot that matters here: the queue's whole job is to stay correct when
 * two cron ticks overlap or when a tick is killed mid-flight. A single-process
 * test cannot observe a claim race, an expired lease, a reaped lock, or a
 * retried job — so it passes while the worker quietly double-processes or
 * strands work.
 *
 * This suite spawns real PHP subprocesses that claim from the same MySQL queue
 * at the same instant, proves the claims are disjoint, and drives recovery,
 * retry and permanent-failure paths. It also proves aggregation is idempotent:
 * the same submission processed twice must contribute once.
 *
 * SAFETY
 *
 * Refuses APP_ENV=production and non-test-looking databases unless --force.
 * Only deletes rows it created (tracked by primary key) and storage files that
 * did not exist before the run. Spawned probes run with the same .env.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Console\QueueWorker;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\JobRepository;
use FieldPulse\Database\LeaderboardRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Domain\PeriodResolver;
use FieldPulse\Security\Jwk;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Paths;
use FieldPulse\Testing\TestRunner;
use FieldPulse\Verification\VerificationService;

$argv    = Cli::argv();
$envFile = Cli::option($argv, 'env');
$force   = Cli::hasFlag($argv, 'force');
$filter  = Cli::option($argv, 'filter');
$verbose = Cli::hasFlag($argv, 'verbose');

/* ---------------------------------------------------------------------------
 * Probe mode.
 *
 * A probe is a real, separate PHP process that claims a batch and exits
 * WITHOUT completing it, leaving the rows locked. That is the crash we want to
 * test, and it is also how the race tests get two independent workers onto the
 * same queue at the same instant. The barrier file makes both processes call
 * claimBatch together rather than tens of milliseconds apart.
 * ------------------------------------------------------------------------- */

if (Cli::hasFlag($argv, 'probe')) {
    Config::boot(Cli::option($argv, 'env'));

    $worker  = (string) (Cli::option($argv, 'probe-worker') ?? 'probe');
    $limit   = (int) (Cli::option($argv, 'probe-limit') ?? '10');
    $barrier = Cli::option($argv, 'probe-barrier');
    $out     = Cli::option($argv, 'probe-out');

    if ($barrier !== null && $barrier !== '') {
        $deadline = microtime(true) + 15.0;

        while (!is_file($barrier) && microtime(true) < $deadline) {
            usleep(1000);
        }
    }

    try {
        $claimed = (new JobRepository())->claimBatch($worker, $limit);

        $payload = [
            'ok'      => true,
            'claimed' => array_map(static fn (array $job): array => [
                'id'            => (int) $job['id'],
                'submission_id' => (int) $job['submission_id'],
                'locked_by'     => (string) $job['locked_by'],
                'attempts'      => (int) $job['attempts'],
            ], $claimed),
        ];
    } catch (\Throwable $e) {
        $payload = ['ok' => false, 'error' => $e->getMessage()];
    }

    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    if ($out !== null && $out !== '') {
        file_put_contents($out, $json);
    } else {
        fwrite(STDOUT, $json);
    }

    exit(0);
}

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
    fwrite(STDERR, 'Cannot reach the database: ' . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Read the connection settings from ' . ($envFile ?? 'private_storage/.env') . PHP_EOL);
    exit(2);
}

Paths::ensureLayout();

$t = new TestRunner($filter);

/* ---------------------------------------------------------------------------
 * Fixtures and cleanup. Mirrors integration.php: run-scoped suffix, primary-key
 * tracking, storage diff.
 * ------------------------------------------------------------------------- */

$runTag = 'q' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

/** @var list<int> $createdAgentIds */
$createdAgentIds = [];
/** @var list<int> $createdDeviceIds */
$createdDeviceIds = [];
/** @var list<int> $createdSubmissionIds */
$createdSubmissionIds = [];
/** @var list<string> $createdFiles */
$createdFiles = [];
$agentSeq = 0;

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

$agents       = new AgentRepository();
$devices      = new DeviceRepository();
$submissions  = new SubmissionRepository();
$leaderboard  = new LeaderboardRepository();
$jobs         = new JobRepository();

$makeAgent = static function (string $role, bool $withSite, float $lat, float $lng) use (
    $runTag,
    $agents,
    &$createdAgentIds,
    &$agentSeq
): int {
    $code = $runTag . '-' . strtolower($role) . '-' . (++$agentSeq);

    $id = $agents->create($code, 'Queue ' . $role, null);
    $createdAgentIds[] = $id;

    Connection::execute('UPDATE agents SET role = :role WHERE id = :id', ['role' => $role, 'id' => $id]);

    if ($withSite) {
        Connection::execute(
            'INSERT INTO agent_sites (agent_id, name, center_latitude, center_longitude, radius_m, is_active, created_at, updated_at)
             VALUES (:agent, :name, :lat, :lng, 250, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['agent' => $id, 'name' => $runTag . ' site ' . $agentSeq, 'lat' => $lat, 'lng' => $lng]
        );
    }

    return $id;
};

$makeDevice = static function (int $agentId) use ($devices, &$createdDeviceIds): int {
    $pair = Jwk::generateKeyPair();

    $id = $devices->create($agentId, \FieldPulse\Support\Uuid::v4(), $pair['public'], null);
    $createdDeviceIds[] = $id;

    return $id;
};

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
 * A real JPEG in quarantine. $seed selects the pixels and is GLOBAL: a seed
 * reused by two tests produces byte-identical files and a spurious duplicate
 * verdict, so callers take seeds from $nextSeed() below.
 */
$makeImage = static function (int $seed, int $size = 160, ?\DateTimeImmutable $exifAt = null) use (
    &$createdFiles,
    $runTag,
    $injectExif
): array {
    $image = imagecreatetruecolor($size, $size);

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

$makeSubmission = static function (
    int $agentId,
    int $deviceId,
    array $image,
    int $countClaimed = 1,
    ?float $lat = null,
    ?float $lng = null,
    ?float $accuracyM = null
) use ($submissions, &$createdSubmissionIds): int {
    [$absolute, $relative, $sha] = $image;

    $id = $submissions->insert(
        \FieldPulse\Support\Uuid::v4(),
        $agentId,
        $deviceId,
        $countClaimed,
        $lat,
        $lng,
        Clock::sql(),
        $accuracyM,
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

/**
 * Pixels for the queue tests, which never verify and so never enter the
 * duplicate index. They stay in their own range so they cannot collide with the
 * verified aggregation fixtures, which use the low seeds 200-202.
 */
$seedSeq  = 1000;
$nextSeed = static function () use (&$seedSeq): int {
    return $seedSeq++;
};

/* --- Concurrent-claim harness -------------------------------------------- */

$queueScript = __FILE__;
$scratch     = sys_get_temp_dir() . '/fieldpulse-queue-' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

if (!is_dir($scratch) && !mkdir($scratch, 0o777, true) && !is_dir($scratch)) {
    throw new \RuntimeException('Could not create the queue scratch directory ' . $scratch);
}

/**
 * Spawn one claim probe. The probe blocks on $barrier, so N probes can be
 * released together and contend for the same rows.
 */
$spawnProbe = static function (string $worker, int $limit, string $barrier, string $out) use (
    $envFile,
    $queueScript
): mixed {
    $args = [
        PHP_BINARY,
        $queueScript,
        '--probe',
        '--probe-worker=' . $worker,
        '--probe-limit=' . $limit,
        '--probe-barrier=' . $barrier,
        '--probe-out=' . $out,
    ];

    if ($envFile !== null) {
        $args[] = '--env=' . $envFile;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $out . '.stdout', 'a'],
        2 => ['file', $out . '.stderr', 'a'],
    ];

    $pipes = [];

    return proc_open($args, $descriptors, $pipes, null, null);
};

/**
 * Run two workers at once and return every row they claimed, flattened.
 *
 * @return list<array{id:int,submission_id:int,locked_by:string,attempts:int}>
 */
$raceClaim = static function (string $tag, int $limit) use ($spawnProbe, $scratch): array {
    $barrier = $scratch . '/barrier-' . $tag;
    $outs    = [$scratch . '/out-' . $tag . '-a.json', $scratch . '/out-' . $tag . '-b.json'];

    @unlink($barrier);

    foreach ($outs as $out) {
        @unlink($out);
        @unlink($out . '.stdout');
        @unlink($out . '.stderr');
    }

    $a = $spawnProbe($tag . '-a', $limit, $barrier, $outs[0]);
    $b = $spawnProbe($tag . '-b', $limit, $barrier, $outs[1]);

    if (!is_resource($a) || !is_resource($b)) {
        foreach ([$a, $b] as $proc) {
            if (is_resource($proc)) {
                proc_close($proc);
            }
        }

        throw new \RuntimeException('Could not spawn the claim probes.');
    }

    file_put_contents($barrier, 'go');

    proc_close($a);
    proc_close($b);

    $claimed = [];

    foreach ($outs as $out) {
        if (!is_file($out)) {
            continue;
        }

        $decoded = json_decode((string) file_get_contents($out), true);

        if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $stderr = is_file($out . '.stderr') ? (string) file_get_contents($out . '.stderr') : '';

            throw new \RuntimeException('A claim probe failed: ' . ($decoded['error'] ?? 'no output') . ' ' . $stderr);
        }

        foreach ($decoded['claimed'] as $row) {
            $claimed[] = $row;
        }
    }

    return $claimed;
};

/**
 * Claim and return the specific job for $submissionId.
 *
 * Tests share one database, and an earlier test may legitimately leave a
 * PENDING job behind (recovery returns a crashed job to the queue). Claiming a
 * batch and indexing [0] would then pick up somebody else's job, so this claims
 * broadly and selects by submission.
 *
 * @return array<string,mixed>
 */
$claimMine = static function (int $submissionId, string $worker) use ($jobs): array {
    foreach ($jobs->claimBatch($worker, 200) as $job) {
        if ((int) $job['submission_id'] === $submissionId) {
            return $job;
        }
    }

    throw new \RuntimeException('the job for submission ' . $submissionId . ' was not claimable');
};

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    &$createdSubmissionIds,
    $baselineFiles,
    $storageDirs,
    $scratch
): void {
    foreach ($storageDirs as $dir) {
        foreach ((array) glob($dir . '/*') as $path) {
            $path = (string) $path;

            if (!isset($baselineFiles[$path])) {
                @unlink($path);
            }
        }
    }

    if ($createdAgentIds !== []) {
        try {
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

            foreach ($createdAgentIds as $agentId) {
                Connection::execute('DELETE FROM agents WHERE id = :id', ['id' => $agentId]);
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cleanup warning: ' . $e->getMessage() . PHP_EOL);
        }
    }

    if (is_dir($scratch)) {
        foreach ((array) glob($scratch . '/*') as $path) {
            @unlink((string) $path);
        }

        @rmdir($scratch);
    }
});

/* ---------------------------------------------------------------------------
 * queue claiming
 * ------------------------------------------------------------------------- */

$t->group('queue worker');

$t->test('one worker drains the queue and completes the job end to end', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs
): void {
    // The real cron entry point, not the repository in isolation: verify() runs
    // inside the loop and the job must reach COMPLETED under this worker's token.
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $image = $makeImage(1500, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, 6.5244, 3.3792);

    $jobs->enqueue($submissionId);

    $summary = (new QueueWorker())->run(10);

    $t->assertSame(1, (int) $summary['claimed'], 'the worker claimed the only job');
    $t->assertSame(1, (int) $summary['completed'], 'the worker completed it');
    $t->assertSame('COMPLETED', (string) Connection::fetchValue(
        'SELECT status FROM processing_jobs WHERE submission_id = :id',
        ['id' => $submissionId]
    ));
    $t->assertSame('VERIFIED', (string) Connection::fetchValue(
        'SELECT status FROM submissions WHERE id = :id',
        ['id' => $submissionId]
    ), 'the worker drove the real verification pipeline');
});

$t->group('queue claiming');

$t->test('a worker claims its batch and completes it by token', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $nextSeed
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    for ($i = 0; $i < 3; $i++) {
        $jobs->enqueue($makeSubmission($agentId, $deviceId, $makeImage($nextSeed(), 64), 1, 6.5244, 3.3792));
    }

    $batch = $jobs->claimBatch('solo-worker', 10);

    $t->assertSame(3, count($batch), 'the batch holds every pending job');

    foreach ($batch as $job) {
        $t->assertSame('PROCESSING', (string) Connection::fetchValue(
            'SELECT status FROM processing_jobs WHERE id = :id',
            ['id' => (int) $job['id']]
        ));
        $t->assertTrue($jobs->complete((int) $job['id'], (string) $job['locked_by']), 'the owner completes the job');
    }

    $t->assertSame(3, (int) Connection::fetchValue(
        "SELECT COUNT(*) FROM processing_jobs WHERE status = 'COMPLETED' AND submission_id IN ("
        . implode(',', array_map(static fn (array $j): int => (int) $j['submission_id'], $batch)) . ')'
    ));
});

$t->test('the token fallback claims without overlap', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $nextSeed
): void {
    // MySQL 8 always takes the SKIP LOCKED branch, so the fallback would never
    // run otherwise. Force the cached capability off for the duration.
    $property = new \ReflectionProperty(JobRepository::class, 'supportsSkipLocked');
    $property->setAccessible(true);

    $previous = $property->getValue();
    $property->setValue(null, false);

    try {
        $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
        $deviceId = $makeDevice($agentId);

        for ($i = 0; $i < 4; $i++) {
            $jobs->enqueue($makeSubmission($agentId, $deviceId, $makeImage($nextSeed(), 64), 1, 6.5244, 3.3792));
        }

        $first  = $jobs->claimBatch('fallback-a', 2);
        $second = $jobs->claimBatch('fallback-b', 2);

        $ids = array_merge(
            array_map(static fn (array $row): int => (int) $row['id'], $first),
            array_map(static fn (array $row): int => (int) $row['id'], $second)
        );

        $t->assertSame(4, count($ids), 'the fallback claimed the whole queue across two workers');
        $t->assertSame(count($ids), count(array_unique($ids)), 'the fallback never hands a row to both workers');
    } finally {
        $property->setValue(null, $previous);
    }
});

$t->test('two workers at once never claim the same job', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $raceClaim,
    $nextSeed
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $expected = 24;

    for ($i = 0; $i < $expected; $i++) {
        $jobs->enqueue($makeSubmission($agentId, $deviceId, $makeImage($nextSeed(), 64), 1, 6.5244, 3.3792));
    }

    $claimed = $raceClaim('multi', $expected);
    $ids     = array_map(static fn (array $row): int => (int) $row['id'], $claimed);

    $t->assertSame($expected, count($ids), 'the two workers together claimed every job');
    $t->assertSame(count($ids), count(array_unique($ids)), 'no job was handed to both workers');

    $submissionIds = array_map(static fn (array $row): int => (int) $row['submission_id'], $claimed);
    $t->assertSame(count($ids), count(array_unique($submissionIds)), 'every submission claimed exactly once');
});

$t->test('a single job has exactly one winner in a claim race', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $raceClaim
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(217, 64), 1, 6.5244, 3.3792);
    $jobs->enqueue($submissionId);

    $claimed = $raceClaim('single', 5);
    $mine    = array_values(array_filter($claimed, static fn (array $row): bool => (int) $row['submission_id'] === $submissionId));

    $t->assertSame(1, count($mine), 'exactly one worker won the job');
    $t->assertSame(1, (int) Connection::fetchValue(
        'SELECT attempts FROM processing_jobs WHERE submission_id = :id',
        ['id' => $submissionId]
    ), 'the winning claim incremented attempts exactly once');
});

/* ---------------------------------------------------------------------------
 * crash / stale recovery
 * ------------------------------------------------------------------------- */

$t->group('queue recovery');

$t->test('a crashed worker leaves a lock that recovery returns to the queue', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $raceClaim
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(218, 64), 1, 6.5244, 3.3792);
    $jobs->enqueue($submissionId);

    // The probes claim and exit without completing: a worker killed mid-job.
    $claimed = $raceClaim('crash', 5);
    $t->assertSame(1, count($claimed), 'one probe claimed the job and then died');

    $jobId = (int) $claimed[0]['id'];

    $t->assertSame('PROCESSING', (string) Connection::fetchValue(
        'SELECT status FROM processing_jobs WHERE id = :id',
        ['id' => $jobId]
    ), 'the dead worker left the row PROCESSING');

    // Age the lease past the timeout instead of waiting for it.
    Connection::execute(
        'UPDATE processing_jobs SET locked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = :id',
        ['id' => $jobId]
    );

    $recovered = $jobs->recoverStaleLocks();

    $t->assertTrue($recovered >= 1, 'recovery reported the stale row');

    $row = Connection::fetchOne(
        'SELECT status, locked_by, locked_at FROM processing_jobs WHERE id = :id',
        ['id' => $jobId]
    );

    $t->assertSame('PENDING', (string) $row['status'], 'the job is retryable again');
    $t->assertNull($row['locked_by'] ?? null, 'the dead token is cleared');
    $t->assertNull($row['locked_at'] ?? null, 'the dead lease is cleared');
});

$t->test('recovery fails a stale job that has exhausted its attempts', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(219, 64), 1, 6.5244, 3.3792);
    $jobs->enqueue($submissionId);

    // A poison job: it kills its worker on the final allowed attempt, so no
    // fail() call ever runs and only stale recovery can retire it.
    Connection::execute(
        "UPDATE processing_jobs
            SET status = 'PROCESSING',
                attempts = max_attempts,
                locked_by = :lock,
                locked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)
          WHERE submission_id = :id",
        ['lock' => 'dead-worker:poison', 'id' => $submissionId]
    );

    $t->assertTrue($jobs->recoverStaleLocks() >= 1);

    $row = Connection::fetchOne(
        'SELECT status, last_error_code FROM processing_jobs WHERE submission_id = :id',
        ['id' => $submissionId]
    );

    $t->assertSame('FAILED', (string) $row['status'], 'an exhausted job is failed, not retried forever');
    $t->assertSame('JOB_STALE_EXHAUSTED', (string) $row['last_error_code']);

    $t->assertSame('REQUIRES_REVIEW', (string) Connection::fetchValue(
        'SELECT status FROM submissions WHERE id = :id',
        ['id' => $submissionId]
    ), 'the abandoned submission is surfaced for a human');
});

/* ---------------------------------------------------------------------------
 * retry / permanent failure / ownership
 * ------------------------------------------------------------------------- */

$t->group('queue failure handling');

$t->test('a retryable failure returns the job to PENDING with backoff', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $claimMine,
    $nextSeed
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage($nextSeed(), 64), 1, 6.5244, 3.3792);
    $jobs->enqueue($submissionId);

    $job = $claimMine($submissionId, 'retry-worker');

    $willRetry = $jobs->fail(
        (int) $job['id'],
        (string) $job['locked_by'],
        'JOB_EXCEPTION',
        'transient disk error',
        (int) $job['attempts'],
        (int) $job['max_attempts']
    );

    $t->assertTrue($willRetry, 'the job is scheduled for a retry');

    $row = Connection::fetchOne(
        'SELECT status, available_at, last_error_code, locked_by FROM processing_jobs WHERE id = :id',
        ['id' => (int) $job['id']]
    );

    $t->assertSame('PENDING', (string) $row['status']);
    $t->assertSame('JOB_EXCEPTION', (string) $row['last_error_code']);
    $t->assertNull($row['locked_by'] ?? null, 'the retry releases the lock');
    $t->assertTrue(
        strtotime((string) $row['available_at']) > strtotime('now'),
        'the retry is pushed behind a backoff window'
    );
});

$t->test('the last allowed failure marks the job FAILED', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $claimMine,
    $nextSeed
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage($nextSeed(), 64), 1, 6.5244, 3.3792);
    $jobs->enqueue($submissionId);

    $job = $claimMine($submissionId, 'last-attempt');

    $willRetry = $jobs->fail(
        (int) $job['id'],
        (string) $job['locked_by'],
        'JOB_EXCEPTION',
        'permanent failure',
        (int) $job['max_attempts'],
        (int) $job['max_attempts']
    );

    $t->assertFalse($willRetry, 'an exhausted job is not retried');

    $t->assertSame('FAILED', (string) Connection::fetchValue(
        'SELECT status FROM processing_jobs WHERE id = :id',
        ['id' => (int) $job['id']]
    ));
});

$t->test('a worker whose lease was reaped cannot complete the job', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $jobs,
    $claimMine,
    $nextSeed
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage($nextSeed(), 64), 1, 6.5244, 3.3792);
    $jobs->enqueue($submissionId);

    $stale = $claimMine($submissionId, 'slow-worker');
    $jobId = (int) $stale['id'];
    $staleLock = (string) $stale['locked_by'];

    // The slow worker's lease expires and recovery hands the job to a new one.
    Connection::execute(
        'UPDATE processing_jobs SET status = :pending, locked_at = NULL, locked_by = NULL WHERE id = :id',
        ['pending' => JobRepository::PENDING, 'id' => $jobId]
    );

    $fresh = array_values(array_filter(
        $jobs->claimBatch('fresh-worker', 200),
        static fn (array $row): bool => (int) $row['id'] === $jobId
    ))[0];

    $t->assertNotSame($staleLock, (string) $fresh['locked_by'], 'the job now carries the fresh token');

    // The old worker finally finishes and tries to complete. It must be refused.
    $t->assertFalse($jobs->complete($jobId, $staleLock), 'the stale owner cannot complete the job');

    $t->assertSame('PROCESSING', (string) Connection::fetchValue(
        'SELECT status FROM processing_jobs WHERE id = :id',
        ['id' => $jobId]
    ), 'the fresh owner still holds the job');

    $t->assertTrue($jobs->complete($jobId, (string) $fresh['locked_by']), 'the current owner can complete it');
});

/* ---------------------------------------------------------------------------
 * idempotent aggregation
 * ------------------------------------------------------------------------- */

$t->group('queue aggregation');

$t->test('processing the same submission twice counts once', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $image = $makeImage(200, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 3, 6.5244, 3.3792);

    (new VerificationService())->verify($submissionId);

    $submission = (new SubmissionRepository())->findById($submissionId);
    $period = PeriodResolver::periodFor((string) $submission['server_received_at']);

    $first = Connection::fetchOne(
        'SELECT total_verified_count, total_submissions FROM agent_performance_summary
          WHERE agent_id = :a AND period_start_date = :p',
        ['a' => $agentId, 'p' => $period['date']]
    );

    $t->assertSame(3, (int) $first['total_verified_count'], 'the first pass counts the claimed amount once');

    // Process the identical submission a second time, as a retried job would.
    (new VerificationService())->verify($submissionId);

    $second = Connection::fetchOne(
        'SELECT total_verified_count, total_submissions FROM agent_performance_summary
          WHERE agent_id = :a AND period_start_date = :p',
        ['a' => $agentId, 'p' => $period['date']]
    );

    $t->assertSame(3, (int) $second['total_verified_count'], 'a second pass does not double the total');
    $t->assertSame((int) $first['total_submissions'], (int) $second['total_submissions'], 'and does not double the count');
});

$t->test('summary rebuild is a deterministic recompute', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $leaderboard
): void {
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);

    $image = $makeImage(201, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 4, 6.5244, 3.3792);

    (new VerificationService())->verify($submissionId);

    $submission = (new SubmissionRepository())->findById($submissionId);
    $period = PeriodResolver::periodFor((string) $submission['server_received_at']);

    $read = static fn (): array => (array) Connection::fetchOne(
        'SELECT total_verified_count, total_submissions, total_rejected, total_pending
           FROM agent_performance_summary WHERE agent_id = :a AND period_start_date = :p',
        ['a' => $agentId, 'p' => $period['date']]
    );

    $before = $read();

    // Rebuild from the ledger twice; both rebuilds must reproduce the same row.
    $leaderboard->refresh($agentId, $period['date']);
    $leaderboard->refresh($agentId, $period['date']);

    $after = $read();

    $t->assertSame($before, $after, 'the summary is a pure function of the ledger');
});

/* ---------------------------------------------------------------------------
 * structural guarantees
 * ------------------------------------------------------------------------- */

$t->group('queue structure');

$t->test('expensive image work runs outside any database transaction', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission
): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/Verification/VerificationService.php');

    $phashPos   = strpos($source, 'PHash::fromFile');
    $archivePos = strpos($source, '$this->archiveFile(');
    $txPos      = strpos($source, 'Connection::transaction(');

    $t->assertNotFalse($phashPos, 'the probe can find the pHash step');
    $t->assertNotFalse($archivePos, 'the probe can find the file-move step');
    $t->assertNotFalse($txPos, 'the probe can find the transaction step');

    $t->assertTrue($phashPos < $txPos, 'the expensive pHash is computed before a transaction opens');
    $t->assertTrue($archivePos < $txPos, 'the file move happens before the disposition transaction');

    // Behavioural backstop: a full verify leaves the connection out of a
    // transaction rather than leaking one open.
    $agentId  = $makeAgent('AGENT', true, 6.5244, 3.3792);
    $deviceId = $makeDevice($agentId);
    $image    = $makeImage(202, 160, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 1, 6.5244, 3.3792);

    (new VerificationService())->verify($submissionId);

    $t->assertFalse(Connection::inTransaction(), 'verify() leaves no transaction open');
});

/* ---------------------------------------------------------------------------
 * Exit
 * ------------------------------------------------------------------------- */

Cli::heading('FieldPulse queue suite (server ' . $serverVersion . ', database ' . $dbName . ')');

$code = $t->run($verbose);

exit($code);
