<?php

declare(strict_types=1);

/**
 * Phase 6 verification suite: EXIF, geofence, duplicates, timestamps, pHash.
 *
 *   php private_storage/bin/verification.php
 *   php private_storage/bin/verification.php --filter=phash --verbose
 *
 * WHY THIS EXISTS
 *
 * Four defects in the verification pipeline survived a green self-test AND a green
 * integration suite, because neither suite built an EXIF block whose fields
 * disagreed, a GPS payload that was structurally impossible, or a submission at a
 * chosen Hamming distance from another:
 *
 *   - VerificationService read EXIF coordinates as $exif['gps']['latitude'], but
 *     ExifExtractor returns them FLAT, as $exif['latitude']. The lookup always
 *     returned null, so the EXIF geofence fallback never fired and the coordinates
 *     were never persisted.
 *   - VerificationService read $evidence['exif']['presence'], a key nothing ever
 *     writes, so the recorded exif_status was hardcoded ABSENT for every
 *     submission in the system.
 *   - Geofence::evaluate() folded an out-of-range coordinate into
 *     GPS_UNRELIABLE. "The numbers cannot denote a place" and "the numbers may
 *     denote a place but the fix is poor" are different findings needing different
 *     conclusions, so a broken payload had no state of its own. A NAN coordinate
 *     was worse than misclassified: every range comparison against NAN is false,
 *     so it passed the range test entirely and reached the site search.
 *   - ExifExtractor had no way to report a block that parses cleanly and still
 *     contradicts itself, which is the observable signature of a rewritten file.
 *
 * Every test is named after the property it defends rather than the method it
 * calls, so a rename upstream fails the test instead of silently deleting it.
 *
 * A NOTE ON THE EXIF FIXTURES
 *
 * The fixtures build a real APP1/TIFF block by hand because GD cannot add metadata
 * to an existing image. They carry ASCII date tags only. GPS-bearing EXIF is
 * deliberately NOT hand-built here: on this PHP 8.2.34 build exif_read_data()
 * returns the ASCII GPSLatitudeRef out of a hand-written GPS IFD but never the
 * RATIONAL entries beside it, under every byte order, type and value shape tried.
 * Rather than encode a fixture that would prove nothing, the EXIF-position
 * fallback is tested where it is actually decided - the Geofence::evaluate()
 * branch that consumes those two values - and the flat return shape is asserted
 * directly against extract(). That keeps the suite honest about what it covers
 * instead of asserting on a stub.
 *
 * SAFETY
 *
 * Refuses to run against APP_ENV=production, and against any database whose name
 * does not look like a test or development database, unless --force is given. It
 * only ever deletes rows it created itself, tracked by primary key, and removes
 * only storage-tree files that were not present when the run started.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Geo\Geofence;
use FieldPulse\Imaging\ExifExtractor;
use FieldPulse\Imaging\Hamming;
use FieldPulse\Imaging\PHash;
use FieldPulse\Security\Jwk;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Paths;
use FieldPulse\Support\Uuid;
use FieldPulse\Testing\TestRunner;
use FieldPulse\Verification\DecisionMatrix;
use FieldPulse\Verification\DuplicateDetector;
use FieldPulse\Verification\TimestampVerifier;
use FieldPulse\Verification\VerificationService;

$argv    = Cli::argv();
$envFile = Cli::option($argv, 'env');
$force   = Cli::hasFlag($argv, 'force');
$filter  = Cli::option($argv, 'filter');
$verbose = Cli::hasFlag($argv, 'verbose');

Config::boot($envFile);

$config = Config::instance();
$dbName = $config->str('db.name');
$appEnv = $config->str('app.env');

if ($appEnv === 'production' && !$force) {
    fwrite(STDERR, 'Refusing to run: APP_ENV=production. Pass --force if this really is a throwaway database.' . PHP_EOL);
    exit(2);
}

if (preg_match('/test|dev|local|ci/i', $dbName) !== 1 && !$force) {
    fwrite(STDERR, "Refusing to run against database '$dbName': the name does not look like a test database." . PHP_EOL);
    fwrite(STDERR, 'This suite writes and deletes rows. Pass --force to override.' . PHP_EOL);
    exit(2);
}

try {
    Connection::serverVersion();
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot reach the database: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

Paths::ensureLayout();

$t = new TestRunner($filter);

// Configuration the assertions below are written against. Read from Config rather
// than hardcoded, so an operator who tunes a threshold gets a test that tracks the
// tuning instead of one that silently asserts the old number.
$captureTolerance = $config->int('timestamps.max_future_skew');
$pastTolerance    = $config->int('timestamps.max_past_skew');
$hammingThreshold = $config->int('phash.hamming_threshold');
$possibleMargin   = $config->int('phash.possible_margin');
$possibleCeiling  = $hammingThreshold + $possibleMargin;
$defaultRadius    = $config->int('geofence.default_radius_m');
$bboxPadding      = $config->int('geofence.bbox_padding_m');

// The site centre reused by the geofence tests: small enough that a position a few
// hundred metres away is unambiguously outside it, far enough from Null Island that
// the plausibility checks never fire on it.
const SITE_LAT = 6.5244;
const SITE_LNG = 3.3792;

/* ---------------------------------------------------------------------------
 * Fixtures
 * ------------------------------------------------------------------------- */

$runTag = 'vf' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

/** @var list<int> $createdAgentIds */
$createdAgentIds = [];
/** @var list<int> $createdDeviceIds */
$createdDeviceIds = [];
/** @var list<int> $createdSubmissionIds */
$createdSubmissionIds = [];
$agentSeq = 0;

/**
 * Snapshot of the storage tree, so cleanup removes only what this run added.
 *
 * verify() moves each judged file out of quarantine and renames it to a fresh UUID,
 * so sweeping for the run tag afterwards matches nothing. Diffing against this
 * snapshot is what makes the sweep safe.
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

$agents      = new AgentRepository();
$devices     = new DeviceRepository();
$submissions = new SubmissionRepository();

/**
 * Create an agent with any number of assigned sites.
 *
 * @param list<array{0:string,1:float,2:float,3:int}> $sites
 */
$makeAgent = static function (string $role, array $sites = []) use (
    $runTag,
    $agents,
    &$createdAgentIds,
    &$agentSeq
): int {
    $code = $runTag . '-' . strtolower($role) . '-' . (++$agentSeq);

    $id = $agents->create($code, 'Verification ' . $role, null);

    $createdAgentIds[] = $id;

    Connection::execute('UPDATE agents SET role = :role WHERE id = :id', ['role' => $role, 'id' => $id]);

    foreach ($sites as [$name, $lat, $lng, $radius]) {
        Connection::execute(
            'INSERT INTO agent_sites (agent_id, name, center_latitude, center_longitude, radius_m, is_active, created_at, updated_at)
             VALUES (:agent, :name, :lat, :lng, :radius, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['agent' => $id, 'name' => $name, 'lat' => $lat, 'lng' => $lng, 'radius' => $radius]
        );
    }

    return $id;
};

/** Create a device bound to an agent. */
$makeDevice = static function (int $agentId) use ($devices, &$createdDeviceIds): int {
    $pair = Jwk::generateKeyPair();

    $id = $devices->create($agentId, Uuid::v4(), $pair['public'], null);

    $createdDeviceIds[] = $id;

    return $id;
};

/**
 * Write a real JPEG and return [absolutePath, relativePath, sha256].
 *
 * A genuinely encoded image, not a stub: the pipeline decodes it, reads its EXIF and
 * runs the DCT over it, so a fixture that skipped that would not exercise the code
 * that breaks.
 *
 * $seed is a GLOBAL namespace, not a local one. The same seed produces byte-identical
 * output, so two fixtures sharing a seed are an exact-duplicate pair and every test
 * that reuses one silently becomes a duplicate test. Each image below therefore gets
 * its own seed, and the only intentional repeats are in the duplicate tests, which
 * say so.
 */
$makeImage = static function (int $seed, int $quality = 90, int $size = 160) use ($runTag): array {
    $image = imagecreatetruecolor($size, $size);

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $r = ($x * 7 + $seed * 31) % 256;
            $g = ($y * 5 + $seed * 17) % 256;
            $b = (($x ^ $y) + $seed * 11) % 256;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
        }
    }

    $name     = $runTag . '-' . $seed . '-' . bin2hex(random_bytes(4)) . '.jpg';
    $absolute = Paths::quarantineDir() . DIRECTORY_SEPARATOR . $name;

    imagejpeg($image, $absolute, $quality);
    imagedestroy($image);

    return [$absolute, Paths::relativeForAbsolutePath($absolute), hash_file('sha256', $absolute)];
};

/**
 * Inject a minimal APP1/EXIF block into a JPEG.
 *
 * PHP cannot add EXIF to an existing image through GD, and without a capture time in
 * the file every submission is TIMESTAMP_MISSING, so the auto-verify path would be
 * untestable. The block is built by hand: a little-endian TIFF header, an IFD0
 * pointing at an Exif sub-IFD, and the two date strings in the data area after it.
 *
 * $dto is DateTimeOriginal and $dtd is DateTimeDigitized, each either a
 * "Y:m:d H:i:s" string or null to leave that tag out. Passing different values for the
 * two is the only way to produce a block that parses cleanly and still contradicts
 * itself, which is the whole point of the INCONSISTENT axis.
 */
$injectExif = static function (string $jpegPath, ?string $dto, ?string $dtd): void {
    $bytes = file_get_contents($jpegPath);

    if ($bytes === false || strncmp($bytes, "\xFF\xD8", 2) !== 0) {
        throw new \RuntimeException('EXIF fixture needs a real JPEG');
    }

    $original  = $dto === null ? str_repeat("\0", 20) : $dto . "\0";
    $digitized = $dtd === null ? str_repeat("\0", 20) : $dtd . "\0";

    $make  = "HP\0";
    $model = 'PH50';

    $ifd0Entries = 3;
    $exifEntries = 4;
    $ifd0Offset  = 8;
    $exifOffset  = $ifd0Offset + 2 + ($ifd0Entries * 12) + 4;
    $dataOffset  = $exifOffset + 2 + ($exifEntries * 12) + 4;

    // Each IFD entry is tag(2) type(2) count(4) value-or-offset(4). Values of four
    // bytes or fewer live inline; longer ones hold a file offset.
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

    $tiff    = "II" . pack('v', 42) . pack('V', $ifd0Offset) . $ifd0 . $exifIfd . $original . $digitized;
    $payload = "Exif\0\0" . $tiff;
    $app1    = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

    file_put_contents($jpegPath, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));
};

/** Write an image and give it an EXIF block in one step. */
$makeImageWithExif = static function (
    int $seed,
    ?string $dto,
    ?string $dtd,
    int $quality = 90
) use ($makeImage, $injectExif): array {
    $image = $makeImage($seed, $quality);

    if ($dto !== null || $dtd !== null) {
        $injectExif($image[0], $dto, $dtd);
    }

    // Injection rewrites the file, so the digest has to be taken afterwards or the
    // integrity check rejects the submission on a hash mismatch.
    $image[2] = hash_file('sha256', $image[0]);

    return $image;
};

/** Insert a submission row referencing a real file on disk. */
$makeSubmission = static function (
    int $agentId,
    int $deviceId,
    array $image,
    int $countClaimed = 1,
    ?float $lat = null,
    ?float $lng = null,
    ?string $clientAt = null,
    ?float $accuracyM = null
) use ($submissions, &$createdSubmissionIds): int {
    [$absolute, $relative, $sha] = $image;

    $id = $submissions->insert(
        Uuid::v4(),
        $agentId,
        $deviceId,
        $countClaimed,
        $lat,
        $lng,
        $clientAt ?? Clock::sql(),
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
 * Populate a submission's perceptual hash and band index.
 *
 * Production writes these through VerificationService, but the duplicate tests drive
 * DuplicateDetector directly so the outcomes can be placed precisely. Inserting a row
 * leaves phash_hex NULL, and candidate retrieval filters on `phash_hex IS NOT NULL` plus
 * a band match, so without this every candidate query returns empty and every
 * duplicate test would report NONE - a suite that passes for the wrong reason, or fails
 * for one. This is the same calculation the service performs, applied directly so the
 * tests stay independent of the rest of the pipeline.
 */
$storePhash = static function (int $submissionId, string $absolutePath) use ($submissions): void {
    $hash = PHash::fromFile($absolutePath, 'image/jpeg');

    if ($hash === null) {
        throw new \RuntimeException('could not hash fixture ' . basename($absolutePath));
    }

    $submissions->markDisposition(
        $submissionId,
        SubmissionRepository::QUEUED,
        null,
        'fixture',
        $hash['hex'],
        $hash['bands']
    );
};

/**
 * Overwrite a submission's perceptual hash, to place a candidate at an exact Hamming
 * distance from another.
 *
 * The first bits flipped stay inside band 4, but the distance under test is allowed to
 * exceed 16 bits - the possible-duplicate ceiling is a threshold plus a margin, and a
 * test that can only express distances up to 16 could never show a candidate falling
 * beyond that margin. Once band 4 is exhausted the flips continue upward through band 3
 * and then band 2.
 *
 * Band 1 is never touched. Retrieval is indexed by the most significant bands, so
 * leaving band 1 identical keeps the candidate findable through the real band-matching
 * path. Building two hex strings by hand and calling Hamming::betweenHex() on them would
 * pass even with candidate retrieval completely broken - this goes through the database
 * exactly as a real candidate does.
 *
 * Flipping the low $bits bits of a 16-bit band is an XOR with (1 << $bits) - 1, and each
 * further band takes another 16. The highest band index that may be written is asserted
 * below, so a distance larger than band 2 would fail loudly rather than silently
 * overwrite band 1 and quietly break retrieval.
 */
$setHashDistance = static function (int $submissionId, string $targetHex, int $bits): void {
    if ($bits < 1 || $bits > 32) {
        throw new \RuntimeException('setHashDistance supports 1..32 bits, got ' . $bits);
    }

    $parsed  = PHash::fromHex($targetHex);
    $updated = $parsed['bands'];

    // Bands are consumed from the least significant upward: band 4, then 3, then 2.
    foreach ([3, 2, 1] as $band) {
        if ($bits <= 0) {
            break;
        }

        $take     = min(16, $bits);
        $mask     = (1 << $take) - 1;
        $updated[$band] = $parsed['bands'][$band] ^ $mask;
        $bits           -= $take;
    }

    $hex = '';

    foreach ($updated as $band) {
        $hex .= sprintf('%04x', $band);
    }

    Connection::execute(
        'UPDATE submissions
            SET phash_hex = :hex,
                phash_band_1 = :b1, phash_band_2 = :b2, phash_band_3 = :b3, phash_band_4 = :b4
          WHERE id = :id',
        [
            'hex' => $hex,
            'b1'  => $updated[0],
            'b2'  => $updated[1],
            'b3'  => $updated[2],
            'b4'  => $updated[3],
            'id'  => $submissionId,
        ]
    );
};

/**
 * Read the per-check findings straight off the ledger row.
 *
 * findVerification() is the projection the status endpoint returns, and it does not
 * carry the seven *_status columns - they exist for the audit answer to "why was this
 * verified?", which the PWA does not need to echo back. Asserting the recorded finding
 * is the whole point of several tests below, so the ledger is read directly rather than
 * loosening the assertion to whatever the projection happens to return.
 *
 * @return array<string,mixed>|null
 */
$findLedger = static function (int $submissionId): ?array {
    return Connection::fetchOne(
        'SELECT auth_status, device_status, client_gps_status, exif_status,
                timestamp_status, geofence_status,
                exact_duplicate_status, perceptual_duplicate_status,
                final_disposition, review_reason
           FROM submission_verifications
          WHERE submission_id = :id',
        ['id' => $submissionId]
    );
};

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    &$createdSubmissionIds,
    $baselineFiles,
    $storageDirs
): void {
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
        // Reverse dependency order. submissions.agent_id and devices.agent_id are ON
        // DELETE RESTRICT, so the children go first regardless of the cascade.
        //
        // audit rows for a disposition are written with actor_agent_id = NULL (the
        // worker is not an agent), so they are matched on the submission they
        // describe rather than on the reviewer.
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
 * EXIF - five outcomes, and none of them is a verdict
 *
 * PRESENT_INVALID and INCONSISTENT are different findings and are the reason this
 * axis exists at all. A field that cannot be parsed is broken; two fields that each
 * parse and cannot both be true is a file assembled from more than one source.
 * Neither proves anything about the photograph, and both are recorded rather than
 * counted as evidence.
 * ------------------------------------------------------------------------- */

$t->group('exif');

$t->test('a well-formed EXIF block is read out of the stored file', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    $image   = $makeImageWithExif(4001, '2024:03:05 10:20:30', '2024:03:05 10:20:30');
    $extract = ExifExtractor::extract($image[0]);

    $t->assertSame(ExifExtractor::PRESENT_VALID, $extract['captured_status']);
    $t->assertSame('2024-03-05 10:20:30', $extract['captured_at']);
    $t->assertSame('2024:03:05 10:20:30', $extract['datetime_original_raw']);
    $t->assertSame('HP', $extract['make'], 'IFD0 Make is read');
    $t->assertSame('PH50', $extract['model'], 'IFD0 Model is read');
    $t->assertSame(ExifExtractor::CONSISTENT, $extract['consistency']);
    $t->assertSame(ExifExtractor::PRESENT_VALID, ExifExtractor::statusOf($extract));
});

$t->test('the extract result is flat, so flat lookups find the coordinates', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // The regression guard for the dead $exif['gps']['latitude'] read.
    // VerificationService consumes $exif['latitude'], and the EXIF geofence
    // fallback and both server_exif_* coordinate columns depend on that key
    // existing at the top level. Asserting the shape directly is the strongest
    // form of this claim available without a hand-built GPS IFD.
    $extract = ExifExtractor::extract($makeImageWithExif(4002, '2024:03:05 10:20:30', '2024:03:05 10:20:30')[0]);

    $t->assertTrue(array_key_exists('latitude', $extract), 'latitude is a top-level key');
    $t->assertTrue(array_key_exists('longitude', $extract), 'longitude is a top-level key');
    $t->assertFalse(array_key_exists('gps', $extract), 'there is no nested gps key to read');
});

$t->test('a photo with no EXIF block is ABSENT, not suspicious', function (TestRunner $t) use ($makeImage): void {
    $extract = ExifExtractor::extract($makeImage(4003)[0]);

    $t->assertSame(ExifExtractor::ABSENT, $extract['gps_status']);
    $t->assertSame(ExifExtractor::ABSENT, $extract['captured_status']);
    $t->assertSame(ExifExtractor::CONSISTENT, $extract['consistency'], 'nothing present, nothing to contradict');
    $t->assertSame(ExifExtractor::ABSENT, ExifExtractor::statusOf($extract));
    $t->assertNull($extract['captured_at']);
});

$t->test('an unparseable EXIF date is PRESENT_INVALID, distinct from ABSENT', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // Month 13, day 45, hour 99. The block is present and the tag is there; the value
    // cannot be a date. That is a different finding from "no tag at all", and
    // collapsing the two makes every tampered block look like a phone that strips
    // metadata.
    $extract = ExifExtractor::extract($makeImageWithExif(4004, '2024:13:45 99:99:99', '2024:13:45 99:99:99')[0]);

    $t->assertSame(ExifExtractor::PRESENT_INVALID, $extract['captured_status']);
    $t->assertNull($extract['captured_at'], 'an unparseable date is never guessed at');
    $t->assertSame(ExifExtractor::PRESENT_INVALID, ExifExtractor::statusOf($extract));
    $t->assertNotSame(
        ExifExtractor::ABSENT,
        ExifExtractor::statusOf($extract),
        'a present-but-broken field and an absent field must not share a status'
    );
});

$t->test('a block that parses but contradicts itself is INCONSISTENT', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // A photo is captured and then digitised, so DateTimeOriginal can be equal to or
    // EARLIER than DateTimeDigitized. An hour the other way round cannot happen to a
    // real capture, and both strings are individually well-formed - which is exactly
    // what makes this a separate axis from PRESENT_INVALID rather than an instance
    // of it.
    $extract = ExifExtractor::extract($makeImageWithExif(4005, '2024:03:05 10:20:30', '2024:03:05 09:20:30')[0]);

    $t->assertSame(ExifExtractor::PRESENT_VALID, $extract['captured_status'], 'each field parses on its own');
    $t->assertSame(ExifExtractor::INCONSISTENT, $extract['consistency'], 'the pair as a whole does not');
    $t->assertSame(ExifExtractor::INCONSISTENT, ExifExtractor::statusOf($extract));
});

$t->test('a small gap between the two dates is not a contradiction', function (TestRunner $t) use (
    $makeImageWithExif,
    $captureTolerance
): void {
    // Inside the same tolerance TimestampVerifier uses to judge EXIF against the
    // client clock, so "these two stamps describe the same moment" means one thing
    // across the pipeline instead of being defined twice. A camera that took a
    // moment to write its digitisation stamp must not be flagged as tampering.
    $extract = ExifExtractor::extract($makeImageWithExif(4006, '2024:03:05 10:20:30', '2024:03:05 10:20:00')[0]);

    $t->assertTrue($captureTolerance > 30, 'the configured tolerance is wider than the gap under test');
    $t->assertSame(ExifExtractor::CONSISTENT, $extract['consistency'], '30s apart is inside the tolerance');
    $t->assertSame(ExifExtractor::PRESENT_VALID, ExifExtractor::statusOf($extract));
});

$t->test('the normal capture order is not a contradiction', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // The inverse of the contradiction test. Original earlier than digitised is what
    // every real camera produces, so a check that flagged it would flag everything.
    $extract = ExifExtractor::extract($makeImageWithExif(4007, '2024:03:05 09:20:30', '2024:03:05 10:20:30')[0]);

    $t->assertSame(ExifExtractor::CONSISTENT, $extract['consistency']);
    $t->assertSame(ExifExtractor::PRESENT_VALID, ExifExtractor::statusOf($extract));
});

$t->test('a missing partner is absent, not a contradiction', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // Files routinely carry DateTimeOriginal and no DateTimeDigitized, or the reverse.
    // One field alone cannot contradict anything, and reporting it as INCONSISTENT
    // would flag most real photographs.
    $onlyOriginal = ExifExtractor::extract($makeImageWithExif(4008, '2024:03:05 10:20:30', null)[0]);
    $onlyDigitized = ExifExtractor::extract($makeImageWithExif(4208, null, '2024:03:05 10:20:30')[0]);

    $t->assertSame(ExifExtractor::CONSISTENT, $onlyOriginal['consistency']);
    $t->assertSame(ExifExtractor::PRESENT_VALID, ExifExtractor::statusOf($onlyOriginal));
    $t->assertSame(ExifExtractor::CONSISTENT, $onlyDigitized['consistency']);
    $t->assertSame(ExifExtractor::PRESENT_VALID, ExifExtractor::statusOf($onlyDigitized));
});

$t->test('a broken field outranks a contradiction between two good ones', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // A field that cannot be parsed is a simpler explanation for a bad block than a
    // disagreement between two well-formed ones, so PRESENT_INVALID is what gets
    // recorded and the reason a reviewer reads is the actionable one.
    $extract = ExifExtractor::extract($makeImageWithExif(4009, '2024:13:45 99:99:99', '2024:03:05 09:20:30')[0]);

    $t->assertSame(ExifExtractor::PRESENT_INVALID, $extract['captured_status']);
    $t->assertSame(ExifExtractor::PRESENT_INVALID, ExifExtractor::statusOf($extract));
});

$t->test('the combined EXIF status is resolved worst-first', function (TestRunner $t): void {
    // Precedence is a policy, so it is pinned rather than inferred:
    //   UNREADABLE      the block was never available, so nothing else is knowable
    //   PRESENT_INVALID a field is broken - the simpler explanation for a bad block
    //   INCONSISTENT    every field parses and they still disagree
    //   PRESENT_VALID   something was read and it hangs together
    //   ABSENT          nothing was there at all
    $cases = [
        'valid wins over absent'                  => [ExifExtractor::PRESENT_VALID, ExifExtractor::ABSENT,        ExifExtractor::CONSISTENT,  ExifExtractor::PRESENT_VALID],
        'nothing present is absent'               => [ExifExtractor::ABSENT,        ExifExtractor::ABSENT,        ExifExtractor::CONSISTENT,  ExifExtractor::ABSENT],
        'a contradiction beats valid'              => [ExifExtractor::ABSENT,        ExifExtractor::PRESENT_VALID, ExifExtractor::INCONSISTENT, ExifExtractor::INCONSISTENT],
        'unreadable outranks everything'           => [ExifExtractor::UNREADABLE,    ExifExtractor::PRESENT_VALID, ExifExtractor::CONSISTENT,  ExifExtractor::UNREADABLE],
        'unreadable outranks a contradiction'      => [ExifExtractor::UNREADABLE,    ExifExtractor::ABSENT,        ExifExtractor::INCONSISTENT, ExifExtractor::UNREADABLE],
        'a broken capture field beats a contradiction' => [ExifExtractor::ABSENT,     ExifExtractor::PRESENT_INVALID, ExifExtractor::INCONSISTENT, ExifExtractor::PRESENT_INVALID],
        'a broken GPS field beats a contradiction' => [ExifExtractor::PRESENT_INVALID, ExifExtractor::PRESENT_VALID, ExifExtractor::INCONSISTENT, ExifExtractor::PRESENT_INVALID],
        'a broken capture field beats valid'       => [ExifExtractor::PRESENT_VALID, ExifExtractor::PRESENT_INVALID, ExifExtractor::CONSISTENT,  ExifExtractor::PRESENT_INVALID],
        'unreadable on one axis is unreadable'     => [ExifExtractor::ABSENT,        ExifExtractor::UNREADABLE,    ExifExtractor::CONSISTENT,  ExifExtractor::UNREADABLE],
    ];

    foreach ($cases as $label => [$gps, $captured, $consistency, $expected]) {
        $t->assertSame($expected, ExifExtractor::combinedStatus($gps, $captured, $consistency), $label);
        $t->assertSame(
            $expected,
            ExifExtractor::statusOf(['gps_status' => $gps, 'captured_status' => $captured, 'consistency' => $consistency]),
            $label . ' (via statusOf)'
        );
    }
});

$t->test('statusOf agrees with what extract would have reported', function (TestRunner $t) use (
    $makeImageWithExif
): void {
    // Two definitions of "what was the EXIF state of this file" is exactly how the
    // recorded column and the reason shown to a reviewer come to disagree. One
    // definition, asserted against extract() on every fixture the suite builds.
    $fixtures = [
        [4001, '2024:03:05 10:20:30', '2024:03:05 10:20:30'],
        [4101, '2024:13:45 99:99:99', '2024:13:45 99:99:99'],
        [4102, '2024:03:05 10:20:30', '2024:03:05 09:20:30'],
        [4103, null,                 null],
    ];

    foreach ($fixtures as $index => [$seed, $dto, $dtd]) {
        $extract = ExifExtractor::extract($makeImageWithExif($seed, $dto, $dtd)[0]);

        $t->assertSame(
            ExifExtractor::combinedStatus($extract['gps_status'], $extract['captured_status'], $extract['consistency']),
            ExifExtractor::statusOf($extract),
            "statusOf agrees with the extractor's own axes (fixture $index)"
        );
    }
});

/* ---------------------------------------------------------------------------
 * EXIF GPS clock cross-check
 *
 * Reached through the private helpers rather than a fixture file.
 *
 * PHP's EXIF reader on this platform will not hand back a hand-built GPS IFD at all -
 * it exposes IFD0 and EXIF but never GPS, whichever way the block is laid out - so a
 * GPS-bearing JPEG cannot be constructed here the way the date fixtures can. Rather
 * than leave the GPS-versus-DateTimeOriginal contradiction check untested, these drive
 * the pure functions it is built from directly. Reflection on a private helper is
 * already the pattern in integration.php for Kernel::assertOperator().
 *
 * The check matters: DateTimeOriginal and the GPS satellite clock are written by
 * different chips and one of them is routinely wrong. Two clocks that disagree by more
 * than the tolerance is the only *internal* evidence a file has been edited, which is
 * why it grades to review rather than being folded into the parse result.
 * ------------------------------------------------------------------------- */

/** Invoke one of ExifExtractor's private static helpers. */
$exifPrivate = static function (string $method, array $arguments) {
    $reflection = new ReflectionMethod(ExifExtractor::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs(null, $arguments);
};

/**
 * A GPS tag set carrying a satellite clock, in the shapes a real encoder emits.
 *
 * GPSDateStamp is ASCII; GPSTimeStamp is either an ASCII "HH:MM:SS" or the rational
 * array the spec uses. Both are exercised, because a device that writes one and a tool
 * that rewrites the other must reach the same instant.
 */
$gpsTags = static function (string $dateStamp, array|string $time): array {
    return ['GPSDateStamp' => $dateStamp, 'GPSTimeStamp' => $time];
};

$t->test('the GPS satellite clock is parsed in both encodings', function (TestRunner $t) use ($exifPrivate, $gpsTags): void {
    $parse = static fn (array $tags) => $exifPrivate('gpsTimestamp', [$tags])?->format('Y-m-d H:i:s');

    // ASCII, the shape PHP's reader hands back.
    $t->assertSame(
        '2024-03-05 10:20:30',
        $parse($gpsTags('2024:03:05', '10:20:30')),
        'ASCII GPSTimeStamp'
    );

    // Rational, the shape the spec asks for: 10/1h, 20/1m, 30/1s.
    $rational = [10, 1, 20, 1, 30, 1];
    $t->assertSame(
        '2024-03-05 10:20:30',
        $parse($gpsTags('2024:03:05', $rational)),
        'rational GPSTimeStamp'
    );

    // Fractional seconds must not lose the second itself.
    $t->assertSame(
        '2024-03-05 10:20:31',
        $parse($gpsTags('2024:03:05', '10:20:30.6')),
        'fractional seconds round rather than truncate the wrong way'
    );
});

$t->test('an unusable GPS clock is ignored rather than guessed at', function (TestRunner $t) use ($exifPrivate, $gpsTags): void {
    $parse = static fn (array $tags) => $exifPrivate('gpsTimestamp', [$tags]);

    // Each of these returns null. A GPS block that cannot be read must not invent a
    // time, because a wrong timestamp is worse than a missing one: it would either
    // manufacture a contradiction that is not there or mask one that is.
    $t->assertNull($parse([]), 'no GPS tags at all');
    $t->assertNull($parse($gpsTags('not-a-date', '10:20:30')), 'an unparseable date');
    $t->assertNull($parse($gpsTags('2024:13:45', '10:20:30')), 'an impossible month');
    $t->assertNull($parse($gpsTags('2024:03:05', '25:00:00')), 'an impossible hour');
    $t->assertNull($parse($gpsTags('2024:03:05', '10:99:00')), 'an impossible minute');
    $t->assertNull($parse($gpsTags('2024:03:05', [10, 0, 20, 1, 30, 1])), 'a zero denominator');
    $t->assertNull($parse($gpsTags('2024:03:05', [10, 1, 20, 1])), 'a truncated rational triple');
    // Built inline because $gpsTags is typed: passing an int through it would be a
    // TypeError in the test rather than the null the extractor returns.
    $t->assertNull($parse(['GPSDateStamp' => '2024:03:05', 'GPSTimeStamp' => 12345]), 'a GPSTimeStamp of the wrong type');

    $t->assertNull($exifPrivate('gpsTimestamp', [null]), 'a null GPS section');
});

$t->test('a GPS clock that contradicts the capture time is INCONSISTENT', function (TestRunner $t) use (
    $exifPrivate,
    $gpsTags,
    $captureTolerance
): void {
    $consistency = static fn (array $tags, ?array $gps) => $exifPrivate('consistency', [$tags, $gps]);

    // consistency() reads a flat tag map, which is the shape exif_read_data hands back
    // for the EXIF section. (A list of rows silently parses nothing and grades
    // everything CONSISTENT, which is the failure mode this assertion exists to catch.)
    $dto = ['DateTimeOriginal' => '2024:03:05 10:20:30'];

    // Agreeing clocks: the common case, and it must stay silent.
    $t->assertSame(
        ExifExtractor::CONSISTENT,
        $consistency($dto, $gpsTags('2024:03:05', '10:20:30')),
        'matching clocks are consistent'
    );

    // The satellite clock is days out. This is the check with no coverage otherwise:
    // deleting it leaves every other test in the suite green.
    $t->assertSame(
        ExifExtractor::INCONSISTENT,
        $consistency($dto, $gpsTags('2024:03:09', '10:20:30')),
        'a GPS clock days away from the capture time'
    );

    // Symmetrically, a capture time days ahead of the satellite clock. abs() means the
    // direction does not change the answer.
    $t->assertSame(
        ExifExtractor::INCONSISTENT,
        $consistency(['DateTimeOriginal' => '2024:03:09 10:20:30'], $gpsTags('2024:03:05', '10:20:30')),
        'and in the other direction'
    );

    // Inside tolerance: a drifting handset clock is normal, so a small gap must not
    // escalate. This is the boundary that stops the check from rejecting every device
    // with a slightly wrong clock.
    $t->assertSame(
        ExifExtractor::CONSISTENT,
        $consistency(
            $dto,
            $gpsTags('2024:03:05', '10:20:' . sprintf('%02d', 30 + 1))
        ),
        'a one-second drift is inside the tolerance'
    );

    // One side unreadable: no comparison is possible, so no contradiction is claimed.
    $t->assertSame(
        ExifExtractor::CONSISTENT,
        $consistency($dto, $gpsTags('not-a-date', '10:20:30')),
        'an unreadable GPS clock cannot contradict anything'
    );

    $t->assertSame(
        ExifExtractor::CONSISTENT,
        $consistency($dto, null),
        'and an absent GPS block is not a contradiction'
    );

    // No capture time at all: the GPS clock has nothing to be measured against, so the
    // GPS time alone must not be graded as a contradiction.
    $t->assertSame(
        ExifExtractor::CONSISTENT,
        $consistency([], $gpsTags('2024:03:05', '10:20:30')),
        'a GPS clock with no capture time is not a contradiction'
    );
});

/* ---------------------------------------------------------------------------
 * Geofence - the site comes from the operator, never from the client
 *
 * Every outcome is distinct and none of the other five is a pass. The site centre is
 * read only from agent_sites, which an operator writes; nothing here ever treats a
 * client-supplied position as a reference point.
 * ------------------------------------------------------------------------- */

$t->group('geofence');

$t->test('a position inside an assigned site is WITHIN_GEOFENCE', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius
): void {
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $result = Geofence::evaluate($agentId, SITE_LAT + 0.001, SITE_LNG + 0.001, null, null, 10.0);

    $t->assertSame(Geofence::WITHIN_GEOFENCE, $result['status']);
    $t->assertSame('site a', $result['site_name']);
    $t->assertSame($defaultRadius, $result['radius_m']);
    $t->assertNotNull($result['site_id']);
    $t->assertTrue(Geofence::isSatisfied($result['status']));
    $t->assertSame('client', $result['source']);
});

$t->test('a position outside every assigned radius is OUTSIDE_GEOFENCE', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius
): void {
    // 0.01 degrees of latitude is roughly 1.1 km: comfortably outside a 250 m radius
    // and unambiguously not a rounding difference.
    //
    // Note this is also outside the 250 + 500 m candidate box, so no site is returned by
    // the box search and the branch that answers is the "agent has sites, but none in
    // range" one. The status is what the decision turns on, and distance_m is null
    // because no candidate was measured - which is exactly why the assertion below
    // checks the status and not the distance.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $result = Geofence::evaluate($agentId, SITE_LAT + 0.01, SITE_LNG, null, null, 10.0);

    $t->assertSame(Geofence::OUTSIDE_GEOFENCE, $result['status']);
    $t->assertFalse(Geofence::isSatisfied($result['status']), 'outside is never a pass');
    $t->assertNull($result['distance_m'], 'nothing was measured, because no site was a candidate');
});

$t->test('a position outside the radius but inside the candidate box is measured', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius
): void {
    // The companion to the test above, and the reason the two are separate. 0.005
    // degrees is about 556 m: outside the 250 m radius, but inside the candidate box,
    // so the site IS returned, the exact distance IS computed, and that number is what
    // decides the outcome.
    //
    // Together the pair shows the box is only a filter: it never invents an outcome, it
    // narrows who gets measured exactly.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $result = Geofence::evaluate($agentId, SITE_LAT + 0.005, SITE_LNG, null, null, 10.0);

    $t->assertSame(Geofence::OUTSIDE_GEOFENCE, $result['status']);
    $t->assertNotNull($result['distance_m'], 'the candidate was inside the box, so a distance exists');
    $t->assertTrue($result['distance_m'] > $defaultRadius, 'the reported distance exceeds the radius');
    $t->assertSame($defaultRadius, $result['radius_m'], 'and the radius it was measured against is recorded');
});

$t->test('the bounding box narrows candidates but exact distance decides', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius,
    $bboxPadding
): void {
    // The box is sized from max(radius) + padding, so a position can sit inside the box
    // and still be outside the radius. Treating the box as the answer would pass here.
    // The box only decides which rows PHP has to measure exactly.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $offset = ($defaultRadius + ($bboxPadding / 2)) / 111_320.0;
    $result = Geofence::evaluate($agentId, SITE_LAT + $offset, SITE_LNG, null, null, 10.0);

    $t->assertTrue(
        $result['distance_m'] > $defaultRadius && $result['distance_m'] < $defaultRadius + $bboxPadding,
        'the fixture is inside the box and outside the radius, distance ' . $result['distance_m']
    );
    $t->assertSame(Geofence::OUTSIDE_GEOFENCE, $result['status'], 'inside the box is not inside the site');
    $t->assertSame($defaultRadius, $result['radius_m'], 'the site was found and measured exactly');
});

$t->test('a position in the second of two assigned sites passes on that site', function (TestRunner $t) use (
    $makeAgent
): void {
    // The sites are ~1.1 km apart, so a position at the second site cannot possibly be
    // inside the first. Anything that stopped at the first assigned site, or matched
    // by name rather than by distance, fails here.
    $agentId = $makeAgent('AGENT', [
        ['site alpha', SITE_LAT,        SITE_LNG, 250],
        ['site bravo', SITE_LAT + 0.01, SITE_LNG, 250],
    ]);

    $result = Geofence::evaluate($agentId, SITE_LAT + 0.01, SITE_LNG, null, null, 10.0);

    $t->assertSame(Geofence::WITHIN_GEOFENCE, $result['status']);
    $t->assertSame('site bravo', $result['site_name'], 'the nearest assigned site is the one reported');
});

$t->test('a position matching neither of two assigned sites is outside both', function (TestRunner $t) use (
    $makeAgent
): void {
    // The mirror of the test above. Being assigned sites must not turn a position
    // between them into a pass.
    $agentId = $makeAgent('AGENT', [
        ['site alpha', SITE_LAT,        SITE_LNG, 250],
        ['site bravo', SITE_LAT + 0.01, SITE_LNG, 250],
    ]);

    $result = Geofence::evaluate($agentId, SITE_LAT + 0.005, SITE_LNG, null, null, 10.0);

    $t->assertSame(Geofence::OUTSIDE_GEOFENCE, $result['status']);
});

$t->test('an agent with no site is SITE_UNASSIGNED, never a pass', function (TestRunner $t) use ($makeAgent): void {
    $agentId = $makeAgent('AGENT', []);

    $result = Geofence::evaluate($agentId, SITE_LAT, SITE_LNG, null, null, 10.0);

    $t->assertSame(Geofence::SITE_UNASSIGNED, $result['status']);
    $t->assertFalse(Geofence::isSatisfied($result['status']), 'the absence of a check is not a pass');
    $t->assertNull($result['site_id']);
    $t->assertNull($result['distance_m'], 'no site means no distance to report');
});

$t->test('a deactivated site does not count as an assignment', function (TestRunner $t) use ($makeAgent): void {
    // An inactive site must not keep verifying an agent who used to be assigned
    // there. This is the difference between SITE_UNASSIGNED and OUTSIDE_GEOFENCE, so
    // it is worth pinning rather than assuming.
    $agentId = $makeAgent('AGENT', [['retired site', SITE_LAT, SITE_LNG, 250]]);

    Connection::execute('UPDATE agent_sites SET is_active = 0 WHERE agent_id = :id', ['id' => $agentId]);

    $result = Geofence::evaluate($agentId, SITE_LAT, SITE_LNG, null, null, 10.0);

    $t->assertSame(Geofence::SITE_UNASSIGNED, $result['status']);
});

$t->test('coordinates that cannot denote a place are INVALID_GPS', function (TestRunner $t) use (
    $makeAgent
): void {
    // This is the new state, and it exists because these used to arrive at the site
    // search and were folded into GPS_UNRELIABLE. That told a reviewer the fix was
    // merely doubtful when in fact the payload could not describe a place at all.
    //
    // The NAN and INF rows are the important ones. Every range comparison against NAN
    // is false, so a range test alone waved it straight through into the site search.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);

    $impossible = [
        'latitude above 90'    => [91.0, 3.3792],
        'latitude below -90'   => [-90.5, 3.3792],
        'longitude above 180'  => [6.5244, 180.5],
        'longitude below -180' => [6.5244, -181.0],
        'NaN latitude'         => [NAN, 3.3792],
        'NaN longitude'        => [6.5244, NAN],
        'both NaN'             => [NAN, NAN],
        'infinite latitude'    => [INF, 3.3792],
        'negative infinity'    => [-INF, 3.5244],
    ];

    foreach ($impossible as $label => [$lat, $lng]) {
        $result = Geofence::evaluate($agentId, $lat, $lng, null, null, 10.0);

        $t->assertSame(Geofence::INVALID_GPS, $result['status'], $label);
        $t->assertNotSame(Geofence::GPS_UNRELIABLE, $result['status'], $label . ' is not a reliability question');
        $t->assertTrue(Geofence::isBlocking($result['status']), $label . ' blocks auto-verification');
        $t->assertFalse(Geofence::isSatisfied($result['status']), $label . ' is never a pass');
        $t->assertNull($result['distance_m'], $label . ' is rejected before any site is measured');
        $t->assertNull($result['site_id'], $label . ' never reaches the site search');
    }
});

$t->test('the boundary values of the coordinate range are still positions', function (TestRunner $t) use (
    $makeAgent
): void {
    // The mirror of the test above, and the reason the range test uses strict
    // comparisons. 90/180 are legal coordinates; they are merely implausible for a
    // handset anywhere near the assigned site, which is a different conclusion.
    //
    // The site is deliberately placed on the far side of the world from the probe, so
    // the answer cannot come from accidentally being inside the radius: 0.0001 degrees
    // of separation would be about 11 m, and a site placed a hair away would correctly
    // report WITHIN_GEOFENCE and prove nothing about the range check.
    $agentId = $makeAgent('AGENT', [['pole', 89.9999, 179.9999, 250]]);

    $result = Geofence::evaluate($agentId, 90.0, 180.0, null, null, 10.0);

    // The coordinate itself is accepted - 90/180 are the extremes of the legal range.
    $t->assertNotSame(Geofence::INVALID_GPS, $result['status'], 'the extremes of the range are still positions');

    // With the site ~11 m away the honest answer is that the device is there. What
    // matters is that the range check passed and the distance did the deciding.
    $t->assertSame(Geofence::WITHIN_GEOFENCE, $result['status'], 'the site is 11 m away, so inside');
    $t->assertTrue($result['distance_m'] < 250.0, 'and the distance proves it, not the range test');
});

$t->test('a position in range but far from every site is GPS_UNRELIABLE, not INVALID_GPS', function (TestRunner $t) use (
    $makeAgent
): void {
    // The distinction the two statuses exist to keep: these numbers can denote a place,
    // so they are a readable position with doubtful reliability - a review - rather than
    // a payload that is not a position at all.
    $agentId = $makeAgent('AGENT', [['lagos', SITE_LAT, SITE_LNG, 250]]);

    $result = Geofence::evaluate($agentId, 40.7128, -74.0060, null, null, 10.0);

    $t->assertNotSame(Geofence::INVALID_GPS, $result['status'], 'New York is a real coordinate');
    $t->assertSame(Geofence::OUTSIDE_GEOFENCE, $result['status'], 'just not near the assigned site');
    $t->assertFalse(Geofence::isSatisfied($result['status']));
});

$t->test('an in-range but implausible fix is GPS_UNRELIABLE, not INVALID_GPS', function (TestRunner $t) use (
    $makeAgent
): void {
    // Null Island is the software default that leaks straight into a geofence
    // decision. These are real numbers, so the finding is about trust rather than
    // about a broken payload, and a human is the right answer.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);

    $implausible = [
        'exactly zero'        => [0.0, 0.0],
        'zero to eight places' => [1e-8, 1e-8],
    ];

    foreach ($implausible as $label => [$lat, $lng]) {
        $result = Geofence::evaluate($agentId, $lat, $lng, null, null, 10.0);

        $t->assertSame(Geofence::GPS_UNRELIABLE, $result['status'], $label);
        $t->assertNotSame(Geofence::INVALID_GPS, $result['status'], $label . ' is not a malformed payload');
        $t->assertTrue(Geofence::isBlocking($result['status']), $label . ' blocks auto-verification');
        $t->assertFalse(Geofence::isSatisfied($result['status']));
    }
});

$t->test('a confidence radius wider than the site is GPS_UNRELIABLE', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius
): void {
    // Same coordinates, same site, same everything except the reported accuracy. A fix
    // that cannot rule out being inside the radius cannot decide that radius.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $sharp = Geofence::evaluate($agentId, SITE_LAT, SITE_LNG, null, null, 10.0);
    $vague = Geofence::evaluate($agentId, SITE_LAT, SITE_LNG, null, null, $defaultRadius + 50.0);

    $t->assertSame(Geofence::WITHIN_GEOFENCE, $sharp['status'], 'a 10 m fix decides a 250 m radius');
    $t->assertSame(Geofence::GPS_UNRELIABLE, $vague['status'], 'a confidence radius wider than the site cannot');
    $t->assertNotSame(Geofence::INVALID_GPS, $vague['status'], 'a poor fix is not a broken payload');
});

$t->test('accuracy is judged against the smallest assigned site, not a constant', function (TestRunner $t) use (
    $makeAgent
): void {
    // Two agents, the same 900 m fix. For the 250 m site it is unusable; for the
    // 2000 m site it is fine. Comparing accuracy against a fixed constant fails one of
    // these, which is why the check queries the sites instead.
    $tight = $makeAgent('AGENT', [['tight', SITE_LAT, SITE_LNG, 250]]);
    $wide  = $makeAgent('AGENT', [['wide',  SITE_LAT, SITE_LNG, 2000]]);

    $t->assertSame(
        Geofence::GPS_UNRELIABLE,
        Geofence::evaluate($tight, SITE_LAT, SITE_LNG, null, null, 900.0)['status'],
        '900 m cannot decide a 250 m radius'
    );
    $t->assertSame(
        Geofence::WITHIN_GEOFENCE,
        Geofence::evaluate($wide, SITE_LAT, SITE_LNG, null, null, 900.0)['status'],
        '900 m can decide a 2000 m radius'
    );
});

$t->test('an accuracy check cannot manufacture a GPS failure for an unassigned agent', function (TestRunner $t) use (
    $makeAgent
): void {
    // With no site there is no radius to compare against, so the accuracy check has
    // nothing to say and must not convert SITE_UNASSIGNED into GPS_UNRELIABLE. The
    // two reach a reviewer with different remedies.
    $agentId = $makeAgent('AGENT', []);

    $result = Geofence::evaluate($agentId, SITE_LAT, SITE_LNG, null, null, 5000.0);

    $t->assertSame(Geofence::SITE_UNASSIGNED, $result['status'], 'no assigned site, no accuracy verdict');
});

$t->test('no position at all is NO_GPS', function (TestRunner $t) use ($makeAgent): void {
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);

    $result = Geofence::evaluate($agentId, null, null);

    $t->assertSame(Geofence::NO_GPS, $result['status']);
    $t->assertTrue(Geofence::isBlocking($result['status']));
    $t->assertSame('none', $result['source']);
});

$t->test('a half-supplied position is not a position', function (TestRunner $t) use ($makeAgent): void {
    // Latitude without longitude, or the reverse, is a broken payload. Reporting it as
    // NO_GPS would hide the error behind "the client simply sent nothing".
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);

    $t->assertSame(Geofence::NO_GPS, Geofence::evaluate($agentId, SITE_LAT, null)['status'], 'latitude only');
    $t->assertSame(Geofence::NO_GPS, Geofence::evaluate($agentId, null, SITE_LNG)['status'], 'longitude only');
});

$t->test('the EXIF position is used only when the client sent none', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius
): void {
    // This is the branch VerificationService could never reach, because it looked for
    // the coordinates under a key ExifExtractor does not produce. It is asserted where
    // it is actually decided.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $fromExif = Geofence::evaluate($agentId, null, null, SITE_LAT, SITE_LNG);
    $t->assertSame(Geofence::WITHIN_GEOFENCE, $fromExif['status'], 'EXIF supplies the position the client did not');
    $t->assertSame('exif', $fromExif['source']);

    // When both exist the client is not silently overridden. TimestampVerifier is what
    // compares the two sources; the geofence must not just pick whichever is
    // convenient, or a client could suppress a suspicious EXIF fix by sending its own.
    $both = Geofence::evaluate($agentId, SITE_LAT, SITE_LNG, SITE_LAT + 0.01, SITE_LNG);
    $t->assertSame('client', $both['source'], 'a client position takes precedence over EXIF');

    $neither = Geofence::evaluate($agentId, null, null, null, null);
    $t->assertSame(Geofence::NO_GPS, $neither['status'], 'no source at all is still NO_GPS');
});

$t->test('an EXIF-only fix is judged on place, never on a borrowed accuracy', function (TestRunner $t) use (
    $makeAgent,
    $defaultRadius
): void {
    // EXIF GPS records no confidence figure, so a poor-accuracy judgement here would be
    // inventing one. This asserts the branch does not borrow a number the client did
    // not send.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, $defaultRadius]]);

    $result = Geofence::evaluate($agentId, null, null, SITE_LAT, SITE_LNG, null);

    $t->assertSame(Geofence::WITHIN_GEOFENCE, $result['status']);
    $t->assertSame('exif', $result['source']);
});

$t->test('a broken client position is not rescued by a valid EXIF position', function (TestRunner $t) use (
    $makeAgent
): void {
    // Falling back to EXIF here would hide a broken payload behind a different source
    // and report a plausible-looking pass. The broken input is the finding.
    $agentId = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);

    $result = Geofence::evaluate($agentId, 91.0, 3.3792, SITE_LAT, SITE_LNG);

    $t->assertSame(Geofence::INVALID_GPS, $result['status']);
    $t->assertNotSame('exif', $result['source'], 'the fallback must not mask the broken payload');
});

$t->test('only WITHIN_GEOFENCE satisfies the geofence check', function (TestRunner $t): void {
    $t->assertTrue(Geofence::isSatisfied(Geofence::WITHIN_GEOFENCE));

    foreach ([
        Geofence::OUTSIDE_GEOFENCE,
        Geofence::NO_GPS,
        Geofence::INVALID_GPS,
        Geofence::SITE_UNASSIGNED,
        Geofence::GPS_UNRELIABLE,
    ] as $status) {
        $t->assertFalse(Geofence::isSatisfied($status), $status);
    }
});

$t->test('every geofence outcome is blocked except an inside result', function (TestRunner $t): void {
    // SITE_UNASSIGNED and OUTSIDE_GEOFENCE are not "blocking" in the sense of
    // INVALID_GPS is: they are ordinary review outcomes a human resolves by looking
    // at the assignment. Only the three no-evidence outcomes block outright.
    $blocking = [
        Geofence::INVALID_GPS    => true,
        Geofence::GPS_UNRELIABLE => true,
        Geofence::NO_GPS         => true,
        Geofence::OUTSIDE_GEOFENCE   => false,
        Geofence::SITE_UNASSIGNED    => false,
        Geofence::WITHIN_GEOFENCE    => false,
    ];

    foreach ($blocking as $status => $expected) {
        $t->assertSame($expected, Geofence::isBlocking($status), $status);
    }
});

/* ---------------------------------------------------------------------------
 * pHash - determinism and bit layout
 *
 * The hash is the only part of the pipeline whose failure is silent. A wrong bit
 * order still produces 64 deterministic bits in four indexable bands; it just means
 * the positions being compared are transposed, so unrelated images collide and real
 * duplicates separate. Nothing errors. These tests assert the properties that would
 * still hold if it were wrong, and one that would not.
 * ------------------------------------------------------------------------- */

$t->group('phash');

$t->test('the same file always hashes to the same 64 bits', function (TestRunner $t) use ($makeImage): void {
    $image = $makeImage(5001);

    $first  = PHash::fromFile($image[0], 'image/jpeg');
    $second = PHash::fromFile($image[0], 'image/jpeg');

    $t->assertNotNull($first);
    $t->assertNotNull($second);
    $t->assertSame($first['hex'], $second['hex'], 'hashing is deterministic');
    $t->assertMatches('/^[0-9a-f]{16}$/', (string) $first['hex'], '64 bits as 16 lowercase hex characters');
    $t->assertSame(16, strlen((string) $first['hex']), 'the hash is 16 hex characters');
    $t->assertSame(8, strlen((string) hex2bin((string) $first['hex'])), 'which decode to 8 bytes');

    // 64 bits, packed and split without losing the top half: the integer a 16-char hex
    // string denotes has to survive hex2bin/unpack('J') and come back as 8 bytes.
    $roundTrip = PHash::fromHex((string) $first['hex']);
    $t->assertSame((string) $first['hex'], $roundTrip['hex'], 'hex survives the round trip');
    $t->assertSame($first['bands'], $roundTrip['bands'], 'and so do the four bands');
    $t->assertTrue($roundTrip['integer'] >= 0, 'the high bit does not become negative');
    $t->assertSame(0, Hamming::betweenHex($first['hex'], $first['hex']), 'a hash is zero bits from itself');
});

$t->test('a decoded image and its file hash to the same bits', function (TestRunner $t) use ($makeImage): void {
    // The pipeline hashes the file; this asserts the two entry points agree, so a
    // caller can trust PHash::fromGdImage() when it already has the decoded image.
    $image = $makeImage(5002);

    $fromFile = PHash::fromFile($image[0], 'image/jpeg');
    $decoded  = imagecreatefromjpeg($image[0]);

    $t->assertNotNull($fromFile);
    $t->assertNotFalse($decoded);

    $fromImage = PHash::fromGdImage($decoded);
    imagedestroy($decoded);

    $t->assertNotNull($fromImage);
    $t->assertSame($fromFile['hex'], $fromImage['hex']);
});

$t->test('a hash is four indexed 16-bit bands, most significant first', function (TestRunner $t) use (
    $makeImage
): void {
    // The four bands exist so candidate retrieval can use an index, which only works if
    // band N is bits 16N..16N+15 of the hash. Recomputing them from the stored hex is
    // the check that the stored and queried forms agree.
    $hash = PHash::fromFile($makeImage(5003)[0], 'image/jpeg');

    $t->assertNotNull($hash);

    $parsed  = PHash::fromHex((string) $hash['hex']);
    $integer = $parsed['integer'];

    $t->assertSame($hash['band_1'], $parsed['bands'][0]);
    $t->assertSame($hash['band_2'], $parsed['bands'][1]);
    $t->assertSame($hash['band_3'], $parsed['bands'][2]);
    $t->assertSame($hash['band_4'], $parsed['bands'][3]);

    $t->assertSame(($integer >> 48) & 0xFFFF, $hash['band_1'], 'band 1 is the top 16 bits');
    $t->assertSame(($integer >> 32) & 0xFFFF, $hash['band_2']);
    $t->assertSame(($integer >> 16) & 0xFFFF, $hash['band_3']);
    $t->assertSame($integer & 0xFFFF, $hash['band_4'], 'band 4 is the bottom 16 bits');

    // Reassembling the four bands must reproduce the hash exactly, or a round trip
    // through four SMALLINT columns would quietly corrupt it.
    $rebuilt = ($hash['band_1'] << 48) | ($hash['band_2'] << 32) | ($hash['band_3'] << 16) | $hash['band_4'];
    $t->assertSame($integer, $rebuilt, 'the four bands reassemble into the stored hash');
});

$t->test('a hash survives its own normalise and parse cycle', function (TestRunner $t) use ($makeImage): void {
    // The hex column is what the Hamming comparison actually reads, and the band
    // columns are SMALLINT UNSIGNED. A value that did not survive the trip would match
    // nothing, and would match nothing silently.
    $hash = PHash::fromFile($makeImage(5004)[0], 'image/jpeg');

    $t->assertNotNull($hash);

    $normalised = PHash::normaliseHex('  ' . strtoupper((string) $hash['hex']) . ' ');

    $t->assertSame($hash['hex'], $normalised, 'uppercase and stray whitespace are normalised away');
    $t->assertSame($hash['bands'], PHash::fromHex($normalised)['bands']);
    $t->assertSame(0, Hamming::betweenHex((string) $hash['hex'], $normalised));

    // Every band has to fit the column it is stored in.
    foreach ($hash['bands'] as $band) {
        $t->assertTrue($band >= 0 && $band <= 0xFFFF, 'band ' . $band . ' fits SMALLINT UNSIGNED');
    }
});

$t->test('the 64 bits are laid out row-major over the coefficient block', function (TestRunner $t): void {
    /*
     * Bit order is the part of pHash that stays invisible until it is wrong.
     *
     * Bit i means "the i-th low-frequency coefficient, at a fixed spatial position".
     * Read down a column instead of across a row and every bit still describes
     * something true about the image - the hash stays deterministic, stays 64 bits,
     * keeps its band structure, and every distance test still passes - while the
     * positions being compared are transposed. Unrelated images then collide and real
     * duplicates separate. So this asserts position, not value.
     *
     * Rather than assert which bit a synthetic pattern "should" light up - which needs
     * hand-derived index arithmetic that is easy to get subtly wrong - this computes the
     * expected hash from an independent, deliberately naive O(N^4) DCT and demands a
     * bit-for-bit match. Any reordering of the traversal, of the median, or of the DC
     * handling changes the result and fails here.
     *
     * The reference enumerates the block the way PHash does: first index vertical
     * frequency, second index horizontal frequency, DC first, median over the other 63,
     * DC appended as the final bit.
     */
    $size  = PHash::SIZE;
    $depth = PHash::HASH_SIZE;

    // A deterministic pseudo-random image. Structure from a hand-drawn pattern is not
    // needed here; what matters is that the 64 coefficients are all distinct, so any
    // misordering moves bits rather than permuting equal values.
    mt_srand(20240917);

    $image = imagecreatetruecolor($size, $size);

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $value = mt_rand(0, 255);

            imagesetpixel($image, $x, $y, imagecolorallocate($image, $value, $value, $value));
        }
    }

    // Luminance exactly as PHash reads it, so the two paths agree on inputs.
    $pixels = [];

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $r   = ($rgb >> 16) & 0xFF;
            $g   = ($rgb >> 8) & 0xFF;
            $b   = $rgb & 0xFF;

            $pixels[$y][$x] = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;
        }
    }

    $basis = [];

    for ($k = 0; $k < $depth; $k++) {
        $scale = $k === 0 ? sqrt(1.0 / $size) : sqrt(2.0 / $size);
        $row   = [];

        for ($n = 0; $n < $size; $n++) {
            $row[] = $scale * cos((2 * $n + 1) * $k * M_PI / (2 * $size));
        }

        $basis[$k] = $row;
    }

    $coefficients = [];

    for ($v = 0; $v < $depth; $v++) {
        for ($u = 0; $u < $depth; $u++) {
            $sum = 0.0;

            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    $sum += $pixels[$y][$x] * $basis[$u][$x] * $basis[$v][$y];
                }
            }

            $coefficients[$v][$u] = $sum;
        }
    }

    $values = [];

    for ($v = 0; $v < $depth; $v++) {
        for ($u = 0; $u < $depth; $u++) {
            $values[] = (float) $coefficients[$v][$u];
        }
    }

    $dc    = $values[0];
    $floor = max(abs($dc), 0.001) * 1e-9;

    $ac = [];

    for ($i = 1, $count = count($values); $i < $count; $i++) {
        $ac[] = abs($values[$i]) <= $floor ? 0.0 : $values[$i];
    }

    $sorted = $ac;
    sort($sorted, SORT_NUMERIC);

    $middle  = intdiv(count($sorted), 2);
    $median  = count($sorted) % 2 === 1
        ? $sorted[$middle]
        : ($sorted[$middle - 1] + $sorted[$middle]) / 2.0;

    $bits = '';

    foreach ($ac as $value) {
        $bits .= $value > $median ? '1' : '0';
    }

    $bits .= $dc > $median ? '1' : '0';

    $expected = '';

    for ($i = 0; $i < PHash::BITS; $i += 4) {
        $expected .= dechex(bindec(substr($bits, $i, 4)));
    }

    $actual = PHash::fromGdImage($image);

    imagedestroy($image);

    $t->assertNotNull($actual);
    $t->assertSame($expected, $actual['hex'], 'the bit layout matches an independent DCT reference');

    // Guard the guard: the reference must not be trivially satisfiable by luck.
    $t->assertNotSame(str_repeat('0', 16), $actual['hex'], 'the fixture actually has structure to lose');
});

$t->test('a re-encoded copy stays close to its original', function (TestRunner $t) use (
    $makeImage,
    $hammingThreshold
): void {
    // Same pixels, very different bytes. This is the failure mode byte comparison cannot
    // see and the entire reason a perceptual hash exists, so it is asserted on a real
    // re-encode rather than on two hashes the test wrote by hand.
    $image     = $makeImage(5005, 90);
    $reencoded = $makeImage(5005, 30);

    $t->assertNotSame($image[2], $reencoded[2], 'the two encodings differ byte for byte');

    $original = PHash::fromFile($image[0], 'image/jpeg');
    $copy     = PHash::fromFile($reencoded[0], 'image/jpeg');

    $t->assertNotNull($original);
    $t->assertNotNull($copy);

    $distance = Hamming::betweenHex((string) $original['hex'], (string) $copy['hex']);

    $t->assertTrue(
        $distance <= $hammingThreshold,
        'a quality-30 re-encode of a quality-90 original is within the threshold, distance ' . $distance
    );
});

$t->test('a brightened copy stays close to its original', function (TestRunner $t) use (
    $makeImage,
    $runTag,
    $hammingThreshold
): void {
    // The failure mode the DC term is excluded to avoid. If DC were included in the
    // median, a uniform brightness change would flip a large share of the bits and two
    // copies of one photograph would stop matching.
    $original = $makeImage(5006, 90);

    $source = imagecreatefromjpeg($original[0]);
    $t->assertNotFalse($source);

    $lit = imagecreatetruecolor(160, 160);
    imagecopy($lit, $source, 0, 0, 0, 0, 160, 160);
    imagefilter($lit, IMG_FILTER_BRIGHTNESS, 60);
    imagedestroy($source);

    $litPath = Paths::quarantineDir() . DIRECTORY_SEPARATOR . $runTag . '-lit-' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($lit, $litPath, 90);
    imagedestroy($lit);

    $hashOriginal = PHash::fromFile($original[0], 'image/jpeg');
    $hashBright   = PHash::fromFile($litPath, 'image/jpeg');

    $t->assertNotNull($hashOriginal);
    $t->assertNotNull($hashBright);

    $distance = Hamming::betweenHex((string) $hashOriginal['hex'], (string) $hashBright['hex']);

    $t->assertTrue(
        $distance <= $hammingThreshold,
        'brightening does not move the perceptual hash, distance ' . $distance
    );
});

$t->test('a blank image does not hash differently from every other blank image', function (TestRunner $t) use (
    $runTag
): void {
    // For a flat image the DCT is zero in every AC band, exactly, in real arithmetic,
    // and in floating point it lands on residues around 1e-16. The threshold is the
    // median of those AC values, so without squashing first, "is this coefficient
    // above the median" becomes a comparison of one rounding error against another.
    // Roughly half the bits then come out set at random and every blank photo hashes
    // differently, which reads as a stream of unrelated submissions and quietly
    // defeats band matching.
    $blank = static function (int $shade) use ($runTag): string {
        $image = imagecreatetruecolor(64, 64);
        $grey  = imagecolorallocate($image, $shade, $shade, $shade);
        imagefilledrectangle($image, 0, 0, 63, 63, $grey);

        $path = Paths::quarantineDir() . DIRECTORY_SEPARATOR . $runTag . '-blank-' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($image, $path, 92);
        imagedestroy($image);

        return $path;
    };

    $first  = PHash::fromFile($blank(255), 'image/jpeg');
    $second = PHash::fromFile($blank(255), 'image/jpeg');

    $t->assertNotNull($first);
    $t->assertNotNull($second);
    $t->assertSame($first['hex'], $second['hex'], 'two identical blank photos hash identically');

    // A different shade is still a flat image, so it is still zero AC structure. It may
    // differ in the DC bit alone, which is exactly one bit.
    $other = PHash::fromFile($blank(128), 'image/jpeg');

    $t->assertNotNull($other);
    $t->assertTrue(
        Hamming::betweenHex((string) $first['hex'], (string) $other['hex']) <= 1,
        'a flat image differs from another flat image by at most the DC bit'
    );
});

$t->test('a file that is not an image yields no hash rather than a wrong one', function (TestRunner $t) use (
    $runTag
): void {
    // fromFile() returns null on an undecodable artefact so the pipeline can reject it.
    // Returning a zero hash instead would make every unreadable file a duplicate of
    // every other unreadable file.
    $path = Paths::quarantineDir() . DIRECTORY_SEPARATOR . $runTag . '-notanimage-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($path, 'this is not a JPEG at all');

    $t->assertNull(PHash::fromFile($path, 'image/jpeg'));
    $t->assertNull(PHash::fromFile($path, 'application/octet-stream'));
});

/* ---------------------------------------------------------------------------
 * Duplicates - SHA-256 for identity, Hamming distance for resemblance
 *
 * The two mechanisms catch different fraud and neither subsumes the other. A file can
 * be byte-identical to an earlier upload (a re-upload, or a screenshot of it) and a
 * file can be a re-encode of one already submitted, which is byte-comparison blind to.
 *
 * The distance tests below place a candidate at an exact number of bits from its
 * target and read the classification off the real detector, so the threshold, the
 * margin and the band-indexed retrieval are all exercised rather than just the
 * Hamming helper.
 * ------------------------------------------------------------------------- */

$t->group('duplicates');

$t->test('a byte-identical file is an exact duplicate', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    // Two separate rows over separate files with identical content. This is the only
    // kind of duplicate a SHA-256 catches, and it is unambiguous: same bytes, same
    // picture, so there is no version of this that is legitimate.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $first  = $makeSubmission($agentId, $deviceId, $makeImage(6001), 1, SITE_LAT, SITE_LNG);
    $second = $makeSubmission($agentId, $deviceId, $makeImage(6001), 1, SITE_LAT, SITE_LNG);

    $row = $submissions->findById($second);

    $t->assertNotNull($row);
    $t->assertNotNull(
        $submissions->findExactDuplicate($row['file_sha256'], null, null, $second),
        'the earlier submission is found by digest'
    );

    // Drive the detector directly so the classification is not masked by whatever
    // timestamp or geofence outcome the fixture happens to produce.
    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        '0000000000000000',
        [0, 0, 0, 0],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame(DuplicateDetector::EXACT, $result['status']);
    $t->assertSame(0, $result['distance'], 'identical bytes are zero bits apart');
    $t->assertSame($first, $result['matched_id']);
    $t->assertTrue($result['same_agent']);
});

$t->test('an exact duplicate is caught across agents too', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    // The search is global on purpose. Whether a match was same-agent or cross-agent is
    // read off the returned row and decides which reason code a reviewer sees, so the
    // distinction has to be reported rather than collapsed.
    $ownerAgent  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $ownerDevice = $makeDevice($ownerAgent);
    $otherAgent  = $makeAgent('AGENT', [['site b', SITE_LAT, SITE_LNG, 250]]);
    $otherDevice = $makeDevice($otherAgent);

    $makeSubmission($ownerAgent, $ownerDevice, $makeImage(6002), 1, SITE_LAT, SITE_LNG);

    $second = $makeSubmission($otherAgent, $otherDevice, $makeImage(6002), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($second);

    $t->assertNotNull($row);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        '0000000000000000',
        [0, 0, 0, 0],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame(DuplicateDetector::EXACT, $result['status'], 'a different agent does not make the bytes unique');
    $t->assertFalse($result['same_agent'], 'the match is reported as cross-agent');
});

$t->test('a submission is not its own duplicate', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    // Without the exclusion a re-verification of the same row matches itself and
    // rejects a legitimate submission on retry, which also breaks the pipeline's
    // idempotence.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $id  = $makeSubmission($agentId, $deviceId, $makeImage(6003), 1, SITE_LAT, SITE_LNG);
    $row = $submissions->findById($id);

    $t->assertNotNull($row);
    $t->assertNull($submissions->findExactDuplicate($row['file_sha256'], null, null, $id));
});

$t->test('a re-encoded copy is a perceptual duplicate, not an exact one', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $storePhash,
    $submissions
): void {
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    // The same seed, encoded twice at different quality: different bytes, same picture.
    $original   = $makeImage(6004, 90);
    $reencoded  = $makeImage(6004, 35);

    $t->assertNotSame($original[2], $reencoded[2], 'the encodings are not byte-identical');

    $firstId = $makeSubmission($agentId, $deviceId, $original, 1, SITE_LAT, SITE_LNG);
    $second  = $makeSubmission($agentId, $deviceId, $reencoded, 1, SITE_LAT, SITE_LNG);

    $storePhash($firstId, $original[0]);
    $storePhash($second, $reencoded[0]);

    $row  = $submissions->findById($second);
    $hash = PHash::fromFile($reencoded[0], 'image/jpeg');

    $t->assertNotNull($row);
    $t->assertNotNull($hash);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        (string) $hash['hex'],
        $hash['bands'],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame(DuplicateDetector::PERCEPTUAL, $result['status']);
    $t->assertTrue($result['distance'] > 0, 'the files differ, so the distance is not zero');
    $t->assertTrue($result['same_agent']);
});

$t->test('a candidate inside the threshold is PERCEPTUAL', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $setHashDistance,
    $storePhash,
    $submissions,
    $hammingThreshold
): void {
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $target = $makeSubmission($agentId, $deviceId, $makeImage(6005), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($target);
    $hash   = PHash::fromFile(Paths::absoluteForStoredPath((string) $row['file_path']), 'image/jpeg');

    $t->assertNotNull($hash);

    // Plant an earlier submission at exactly the threshold. Bands 1-3 still match, so
    // the candidate is retrieved by the band index rather than handed to the detector.
    $plantedImage = $makeImage(6006);
    $planted      = $makeSubmission($agentId, $deviceId, $plantedImage, 1, SITE_LAT, SITE_LNG);

    $storePhash($planted, $plantedImage[0]);
    $setHashDistance($planted, (string) $hash['hex'], $hammingThreshold);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        (string) $hash['hex'],
        $hash['bands'],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame($hammingThreshold, $result['distance'], 'the planted distance is the one under test');
    $t->assertSame(DuplicateDetector::PERCEPTUAL, $result['status'], 'exactly at the threshold is a match');
});

$t->test('a candidate one bit past the threshold is POSSIBLE, not PERCEPTUAL', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $setHashDistance,
    $storePhash,
    $submissions,
    $hammingThreshold
): void {
    // The boundary matters in both directions. Off by one and a match becomes a review,
    // or a review becomes a rejection.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $target = $makeSubmission($agentId, $deviceId, $makeImage(6007), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($target);
    $hash   = PHash::fromFile(Paths::absoluteForStoredPath((string) $row['file_path']), 'image/jpeg');

    $t->assertNotNull($hash);

    $plantedImage = $makeImage(6008);
    $planted      = $makeSubmission($agentId, $deviceId, $plantedImage, 1, SITE_LAT, SITE_LNG);

    $storePhash($planted, $plantedImage[0]);
    $setHashDistance($planted, (string) $hash['hex'], $hammingThreshold + 1);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        (string) $hash['hex'],
        $hash['bands'],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame($hammingThreshold + 1, $result['distance']);
    $t->assertSame(DuplicateDetector::POSSIBLE, $result['status'], 'one bit past the threshold is a review, not a rejection');
});

$t->test('a near-but-not-duplicate lands in the margin as POSSIBLE', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $setHashDistance,
    $storePhash,
    $submissions,
    $hammingThreshold,
    $possibleMargin,
    $possibleCeiling
): void {
    // This is the near miss that must not be called fraud. Two photographs of the same
    // kind of scene are a few bits apart; a re-save is a few bits apart. Only a byte
    // comparison or an over-eager threshold turns that into a rejection.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $target = $makeSubmission($agentId, $deviceId, $makeImage(6009), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($target);
    $hash   = PHash::fromFile(Paths::absoluteForStoredPath((string) $row['file_path']), 'image/jpeg');

    $t->assertNotNull($hash);
    $t->assertTrue($possibleMargin > 0, 'a margin is configured');

    $plantedImage = $makeImage(6010);
    $planted      = $makeSubmission($agentId, $deviceId, $plantedImage, 1, SITE_LAT, SITE_LNG);

    $storePhash($planted, $plantedImage[0]);
    $setHashDistance($planted, (string) $hash['hex'], $possibleCeiling);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        (string) $hash['hex'],
        $hash['bands'],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame($possibleCeiling, $result['distance'], 'the far edge of the margin');
    $t->assertSame(DuplicateDetector::POSSIBLE, $result['status'], 'within threshold+margin is a review');
});

$t->test('a candidate beyond the margin is NONE, with the distance recorded', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $setHashDistance,
    $storePhash,
    $submissions,
    $possibleCeiling
): void {
    // The distance is still reported. Silence would read as "not compared"; recording it
    // is what lets a reviewer trust the outcome.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $target = $makeSubmission($agentId, $deviceId, $makeImage(6011), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($target);
    $hash   = PHash::fromFile(Paths::absoluteForStoredPath((string) $row['file_path']), 'image/jpeg');

    $t->assertNotNull($hash);

    $plantedImage = $makeImage(6012);
    $planted      = $makeSubmission($agentId, $deviceId, $plantedImage, 1, SITE_LAT, SITE_LNG);

    $storePhash($planted, $plantedImage[0]);
    $setHashDistance($planted, (string) $hash['hex'], $possibleCeiling + 8);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        (string) $hash['hex'],
        $hash['bands'],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame($possibleCeiling + 8, $result['distance']);
    $t->assertSame(DuplicateDetector::NONE, $result['status'], 'beyond the margin is not a duplicate');
});

$t->test('an unindexed candidate is not claimed as unique', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions
): void {
    // Band-indexed retrieval is an inherent property, not a defect: with a threshold of
    // 8, a candidate 9-15 bits away can share no band at all and is therefore invisible
    // to the query. Reporting "no candidates" rather than "verified unique" is the
    // honest outcome, and this asserts the system does not upgrade it to a clean bill
    // of health.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $target = $makeSubmission($agentId, $deviceId, $makeImage(6013), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($target);

    $t->assertNotNull($row);
    $t->assertNull($row['phash_hex'], 'an unprocessed submission carries no hash yet');

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        '0000000000000000',
        [0, 0, 0, 0],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame(DuplicateDetector::NONE, $result['status']);
    $t->assertSame(0, $result['candidates_considered'], 'nothing was retrieved');
    $t->assertNull($result['matched_id'], 'no match is claimed');
});

$t->test('the nearest of several candidates is the one reported', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $setHashDistance,
    $storePhash,
    $submissions
): void {
    // Three candidates share a band. Reporting the first row rather than the closest
    // would point a reviewer at the wrong submission.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $target = $makeSubmission($agentId, $deviceId, $makeImage(6014), 1, SITE_LAT, SITE_LNG);
    $row    = $submissions->findById($target);
    $hash   = PHash::fromFile(Paths::absoluteForStoredPath((string) $row['file_path']), 'image/jpeg');

    $t->assertNotNull($hash);

    $farImage  = $makeImage(6015);
    $nearImage = $makeImage(6016);

    $far  = $makeSubmission($agentId, $deviceId, $farImage, 1, SITE_LAT, SITE_LNG);
    $near = $makeSubmission($agentId, $deviceId, $nearImage, 1, SITE_LAT, SITE_LNG);

    $storePhash($far, $farImage[0]);
    $storePhash($near, $nearImage[0]);

    $setHashDistance($far, (string) $hash['hex'], 6);
    $setHashDistance($near, (string) $hash['hex'], 2);

    $result = DuplicateDetector::evaluate(
        $submissions,
        $row,
        (string) $hash['hex'],
        $hash['bands'],
        Clock::sql(new \DateTimeImmutable('-1 day')),
        Clock::sql(new \DateTimeImmutable('+1 day'))
    );

    $t->assertSame(2, $result['distance'], 'the closest candidate sets the distance');
    $t->assertSame($near, $result['matched_id'], 'and is the one reported');
});

/* ---------------------------------------------------------------------------
 * Timestamps - one server anchor, three sources
 *
 * The server's own receipt time is the only anchor. The client-asserted time is
 * whatever the client's clock said, and EXIF is whatever the camera's clock said, so
 * neither can establish when a photograph was taken; they can only disagree with each
 * other and with the server.
 *
 * The outcomes are graded deliberately differently, and the grading is the policy:
 *   FUTURE       the file claims to post-date its own receipt, which no clock
 *                correction explains, so it is rejection-grade
 *   TOO_OLD      predates the window, which an old camera roll explains, so review
 *   DISCREPANCY  the two sources differ, and either may be the inaccurate one, so
 *                review
 *   MISSING      neither source is usable, which most phones cause by default, so
 *                review - never a rejection, and never read as fraud
 * ------------------------------------------------------------------------- */

$t->group('timestamps');

/** Shift a UTC instant by a signed number of seconds and render it as SQL. */
$at = static fn (int $seconds): string => Clock::sql(
    (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify(sprintf('%+d seconds', $seconds))
);

$t->test('a capture time within tolerance of receipt is VALID', function (TestRunner $t) use ($at): void {
    // Slightly in the past, which is what every real submission looks like: the file was
    // written, then uploaded.
    $result = TimestampVerifier::evaluate($at(-30), $at(-30), null);

    $t->assertSame(TimestampVerifier::VALID, $result['status']);
    $t->assertTrue($result['pass']);
    $t->assertFalse($result['review']);
});

$t->test('a clock marginally in the future is tolerated', function (TestRunner $t) use ($at): void {
    // Inside max_future_skew a forward clock is a real phenomenon - an unsynced phone
    // drifts by seconds - and rejecting it would reject honest devices. It is accepted
    // with the marginal case reported in the message rather than swallowed.
    $result = TimestampVerifier::evaluate($at(0), $at(120), null);

    $t->assertSame(TimestampVerifier::VALID, $result['status'], 'a small forward skew is not a claim of forgery');
    $t->assertContains('tolerance', $result['message']);
});

$t->test('a capture time beyond the forward tolerance is FUTURE, and rejection-grade', function (TestRunner $t) use (
    $at
): void {
    $result = TimestampVerifier::evaluate($at(0), $at(86_400), null);

    $t->assertSame(TimestampVerifier::FUTURE, $result['status']);
    $t->assertFalse($result['pass']);
    $t->assertSame(86_400, $result['exif']['delta_seconds']);
});

$t->test('a capture time beyond the backward window is TOO_OLD, and review-grade', function (TestRunner $t) use (
    $at,
    $pastTolerance
): void {
    // Grading differs from FUTURE deliberately: an old photograph is an ordinary
    // consequence of a large camera roll, so it reaches a human rather than being
    // thrown away.
    $result = TimestampVerifier::evaluate($at(0), $at(-($pastTolerance + 3600)), null);

    $t->assertSame(TimestampVerifier::TOO_OLD, $result['status']);
    $t->assertFalse($result['pass']);
    $t->assertTrue($result['review'], 'a stale capture time is a review, not a rejection');
});

$t->test('a capture time inside the backward window is still VALID', function (TestRunner $t) use (
    $at,
    $pastTolerance
): void {
    $result = TimestampVerifier::evaluate($at(0), $at(-($pastTolerance - 3600)), null);

    $t->assertSame(TimestampVerifier::VALID, $result['status'], 'a week-old camera roll is normal');
    $t->assertTrue($result['pass']);
});

$t->test('two sources that disagree beyond tolerance is DISCREPANCY, and review-grade', function (TestRunner $t) use (
    $at,
    $captureTolerance
): void {
    // Either clock may be the wrong one, so this cannot support a rejection. The
    // disagreement is recorded with its size so a reviewer can see the scale.
    $result = TimestampVerifier::evaluate($at(0), $at(0), $at(-($captureTolerance + 7200)));

    $t->assertSame(TimestampVerifier::DISCREPANCY, $result['status']);
    $t->assertFalse($result['pass']);
    $t->assertTrue($result['review'], 'a clock disagreement is a review, not a rejection');
    $t->assertSame($captureTolerance + 7200, $result['delta_seconds'], 'the size of the disagreement is recorded');
});

$t->test('two sources that agree within tolerance are VALID', function (TestRunner $t) use (
    $at,
    $captureTolerance
): void {
    $result = TimestampVerifier::evaluate($at(0), $at(0), $at(-($captureTolerance - 60)));

    $t->assertSame(TimestampVerifier::VALID, $result['status']);
    $t->assertTrue($result['pass']);
});

$t->test('no EXIF time and no client time is MISSING, and never fraud', function (TestRunner $t) use ($at): void {
    // The single most common reason a legitimate submission cannot be auto-verified. A
    // phone with location and camera permissions disabled at the OS level produces this,
    // so it must not be treated as suspicion.
    $result = TimestampVerifier::evaluate($at(0), null, null);

    $t->assertSame(TimestampVerifier::MISSING, $result['status']);
    $t->assertFalse($result['pass']);
    $t->assertTrue($result['review']);
    $t->assertFalse(array_key_exists('FUTURE', (array) $result), 'missing time is not a future claim');
});

$t->test('no EXIF time still judges the client time on its own', function (TestRunner $t) use ($at): void {
    // The status stays MISSING whatever the client claims, because absent EXIF is a
    // review on its own terms and a client-asserted time is not evidence enough to
    // upgrade it. What the client time does get is judged rather than discarded: the
    // outcome is recorded in the message, so a reviewer can tell a plausible claim from
    // an absurd one instead of seeing two identical MISSING rows.
    $plausible = TimestampVerifier::evaluate($at(0), null, $at(-60));

    $t->assertSame(TimestampVerifier::MISSING, $plausible['status'], 'no EXIF is still MISSING');
    $t->assertTrue($plausible['review'], 'and still a review, whatever the client claims');
    $t->assertFalse($plausible['pass'], 'a client-asserted time alone never auto-verifies');
    $t->assertContains('accepted as corroboration', $plausible['message'], 'a plausible claim is recorded as such');
    $t->assertTrue($plausible['client']['parsed'], 'the client time was actually read');
    $t->assertSame(-60, $plausible['delta_seconds'], 'and measured against receipt');

    $implausible = TimestampVerifier::evaluate($at(0), null, $at(86_400));

    $t->assertSame(TimestampVerifier::MISSING, $implausible['status']);
    $t->assertContains('in the future', $implausible['message'], 'an absurd claim is called out rather than swallowed');
    $t->assertSame(86_400, $implausible['delta_seconds']);
});

$t->test('an unparseable EXIF time is UNPARSEABLE, and review-grade', function (TestRunner $t) use ($at): void {
    $result = TimestampVerifier::evaluate($at(0), 'not-a-date', $at(-60));

    $t->assertSame(TimestampVerifier::UNPARSEABLE, $result['status']);
    $t->assertFalse($result['pass']);
    $t->assertTrue($result['review']);
});

$t->test('the server anchor is the receipt time, not the client clock', function (TestRunner $t) use ($at, $pastTolerance): void {
    // The client controls client_captured_at entirely. If that value were the anchor, an
    // agent would set their own clock to "now" and every check would pass. Moving the
    // receipt time and holding the EXIF time fixed must move the verdict, which is only
    // possible if the server owns the anchor.
    $exif   = $at(-100);
    $client = $at(-100);

    // Received "now": the fixed capture time is 100 s old. Nothing to complain about.
    $fresh = TimestampVerifier::evaluate($at(0), $exif, $client);

    $t->assertSame(TimestampVerifier::VALID, $fresh['status']);
    $t->assertSame(-100, $fresh['exif']['delta_seconds']);
    $t->assertSame(-100, $fresh['client']['delta_seconds'], 'both sources are measured against receipt');

    // Received far later: the same capture time is now older than the allowed window,
    // purely because time passed on the server's side.
    $stale = TimestampVerifier::evaluate($at($pastTolerance + 7200), $exif, $client);

    $t->assertSame(TimestampVerifier::TOO_OLD, $stale['status'], 'the same file ages against the receipt time');
    $t->assertSame(-100 - ($pastTolerance + 7200), $stale['exif']['delta_seconds'], 'the delta is measured from receipt');

    // The EXIF verdict short-circuits, so the client time is never consulted here. Worth
    // pinning: once the file's own clock is disqualifying, the client's claim adds nothing
    // and a reviewer is not shown a second number that appears to corroborate it.
    $t->assertFalse($stale['client']['parsed'], 'the client clock is not consulted once EXIF disqualifies');

    // And the reverse direction: a receipt time earlier than the capture time makes that
    // same fixed EXIF value look like a post-dated claim, which is the other half of the
    // same proof. The file did not change; only the anchor did.
    $early = TimestampVerifier::evaluate($at(-7200), $exif, $client);

    $t->assertSame(TimestampVerifier::FUTURE, $early['status'], 'an earlier receipt makes the same file look post-dated');
    $t->assertSame(7100, $early['exif']['delta_seconds']);
});

/* ---------------------------------------------------------------------------
 * Pipeline - the parts wired together, end to end
 *
 * Everything above is a component. These drive the real VerificationService over real
 * stored files and real rows, because the defects this phase started with were wiring
 * defects: two components that were each individually correct and each other's
 * contract was not.
 * ------------------------------------------------------------------------- */

$t->group('pipeline');

$t->test('a clean in-fence submission with consistent EXIF verifies', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    // Capture time "now" and both EXIF dates equal, so the timestamp check passes, the
    // geofence passes and the EXIF block hangs together.
    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    $image        = $makeImageWithExif(7001, $stamp, $stamp);
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 5, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::VERIFIED, $result['disposition']);
    $t->assertSame(DecisionMatrix::ALL_CHECKS_PASSED, $result['reason']);

    $row = $submissions->findById($submissionId);
    $t->assertSame('VERIFIED', (string) $row['status']);

    // The whole point of the EXIF wiring fix: the block's own finding is what gets
    // recorded, read out of the file by the server.
    $verification = $findLedger($submissionId);
    $t->assertNotNull($verification);
    $t->assertSame(ExifExtractor::PRESENT_VALID, (string) $verification['exif_status']);
    $t->assertSame(Geofence::WITHIN_GEOFENCE, (string) $verification['geofence_status']);
    $t->assertSame(TimestampVerifier::VALID, (string) $verification['timestamp_status']);
    $t->assertNotNull($row['server_exif_captured_at'], 'the capture time came out of the file, not a default');
});

$t->test('a self-contradicting EXIF block is recorded and escalated to review', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    // The end-to-end proof of the new state. Both dates parse, DateTimeOriginal is an
    // hour later than DateTimeDigitized, and every other check passes - so the ONLY
    // thing standing between this submission and auto-verification is the contradiction.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');
    $earlier    = $capturedAt->modify('-1 hour')->format('Y:m:d H:i:s');

    $image        = $makeImageWithExif(7002, $stamp, $earlier);
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertSame(DecisionMatrix::EXIF_INCONSISTENT, $result['reason']);

    // Review, never rejection. The same disagreement appears in a phone that synced its
    // clock mid-capture, and nothing here can tell those apart.
    $t->assertNotSame(DecisionMatrix::REJECTED, $result['disposition'], 'a contradiction is not proof of tampering');

    $verification = $findLedger($submissionId);
    $t->assertNotNull($verification);
    $t->assertSame(ExifExtractor::INCONSISTENT, (string) $verification['exif_status'], 'the finding is recorded');

    $row = $submissions->findById($submissionId);
    $t->assertSame('REQUIRES_REVIEW', (string) $row['status']);
    $t->assertNull($row['verified_at'], 'a flagged submission is never stamped verified');
});

$t->test('a broken EXIF date is recorded as PRESENT_INVALID and reviewed', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    // The block is present and unreadable as a date. The client asserted a plausible
    // time, so the outcome is TIMESTAMP_UNPARSEABLE rather than the contradiction
    // reason - but either way the recorded EXIF state has to say PRESENT_INVALID
    // instead of the ABSENT that every submission used to get.
    $image        = $makeImageWithExif(7003, '2024:13:45 99:99:99', '2024:13:45 99:99:99');
    $submissionId = $makeSubmission(
        $agentId,
        $deviceId,
        $image,
        2,
        SITE_LAT,
        SITE_LNG,
        Clock::sql(),
        10.0
    );

    (new VerificationService())->verify($submissionId);

    $verification = $findLedger($submissionId);
    $t->assertNotNull($verification);
    $t->assertSame(ExifExtractor::PRESENT_INVALID, (string) $verification['exif_status']);
    $t->assertNotSame(ExifExtractor::ABSENT, (string) $verification['exif_status']);
    $t->assertNull($submissions->findById($submissionId)['server_exif_captured_at'], 'no capture time is invented');
});

$t->test('absent EXIF is recorded as ABSENT and is not fraud', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    // The regression that motivated the exif_status fix: this used to record ABSENT for
    // every submission in the system, including the ones with a perfectly good block, so
    // the column carried no information at all.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $submissionId = $makeSubmission($agentId, $deviceId, $makeImage(7004), 2, SITE_LAT, SITE_LNG, Clock::sql(), 10.0);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertNotSame(DecisionMatrix::REJECTED, $result['disposition'], 'a photo with no EXIF is not a forgery');

    $verification = $findLedger($submissionId);
    $t->assertNotNull($verification);
    $t->assertSame(ExifExtractor::ABSENT, (string) $verification['exif_status']);
    $t->assertSame(TimestampVerifier::MISSING, (string) $verification['timestamp_status']);
});

$t->test('a position outside the fence is reviewed even with perfect EXIF', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    // The rule this platform is built on: EXIF is a cross-check, never a pass on its
    // own. A file with a clean, self-consistent EXIF block submitted from the wrong side
    // of the site is still a review, because a camera clock proves nothing about where
    // the photograph was taken.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    $image        = $makeImageWithExif(7005, $stamp, $stamp);
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, SITE_LAT + 0.01, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertSame(DecisionMatrix::OUTSIDE_GEOFENCE, $result['reason'], 'valid EXIF does not substitute for being there');

    $verification = $findLedger($submissionId);
    $t->assertSame(ExifExtractor::PRESENT_VALID, (string) $verification['exif_status'], 'the EXIF block itself was fine');
    $t->assertSame(Geofence::OUTSIDE_GEOFENCE, (string) $verification['geofence_status']);
});

$t->test('an agent with no site is reviewed, not auto-verified', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    $agentId  = $makeAgent('AGENT', []);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    $image        = $makeImageWithExif(7006, $stamp, $stamp);
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertSame(DecisionMatrix::SITE_UNASSIGNED, $result['reason'], 'no assignment is not a pass');

    $verification = $findLedger($submissionId);
    $t->assertSame(Geofence::SITE_UNASSIGNED, (string) $verification['geofence_status']);
});

$t->test('a coarse fix is reviewed as unreliable rather than accepted', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    // Identical to the clean submission above except the reported accuracy. Same file
    // shape, same coordinates, same capture time: only the confidence radius differs, and
    // that alone has to change the outcome.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    $image        = $makeImageWithExif(7007, $stamp, $stamp);
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 800.0);

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REQUIRES_REVIEW, $result['disposition']);
    $t->assertSame(DecisionMatrix::GPS_UNRELIABLE, $result['reason']);
    $t->assertNotSame(DecisionMatrix::INVALID_GPS, $result['reason'], 'a poor fix is not a broken payload');
});

$t->test('a file re-uploaded verbatim is rejected', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImage,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

    // The only intentional seed repeat in this suite: same bytes, two submissions.
    $makeSubmission($agentId, $deviceId, $makeImage(7008), 1, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $second = $makeSubmission($agentId, $deviceId, $makeImage(7008), 1, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $result = (new VerificationService())->verify($second);

    $t->assertSame(DecisionMatrix::REJECTED, $result['disposition']);
    $t->assertSame(DecisionMatrix::EXACT_DUPLICATE, $result['reason']);
    $t->assertSame('REJECTED', (string) $submissions->findById($second)['status']);
});

$t->test('a re-encoded copy is rejected as a perceptual duplicate', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    // One picture, encoded twice at different quality, so the two files differ byte for
    // byte and the SHA-256 comparison is silent. Only the pHash catches it - which is
    // the whole reason a perceptual index sits alongside the digest. Encoding at the
    // same quality twice would reproduce the bytes exactly and land on the exact
    // duplicate path instead, which is a different code path entirely.
    $firstImage = $makeImageWithExif(7009, $stamp, $stamp, 90);
    $first      = $makeSubmission($agentId, $deviceId, $firstImage, 1, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    (new VerificationService())->verify($first);

    $secondImage = $makeImageWithExif(7009, $stamp, $stamp, 35);
    $second      = $makeSubmission($agentId, $deviceId, $secondImage, 1, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    $rows = $submissions->findById($first);
    $t->assertNotSame($rows['file_sha256'], $submissions->findById($second)['file_sha256'], 'the bytes differ');

    $result = (new VerificationService())->verify($second);

    $t->assertSame(DecisionMatrix::REJECTED, $result['disposition'], 'the same photo is the same photo');
    $t->assertSame(DecisionMatrix::PERCEPTUAL_DUPLICATE, $result['reason'], 'and it was caught by the hash, not the digest');
});

$t->test('verification is idempotent', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    // A job retried after a crash re-derives the same verdict from the same inputs rather
    // than double-counting. A second run that saw the first run's own row as a duplicate
    // would reject a legitimate submission.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    $submissionId = $makeSubmission(
        $agentId,
        $deviceId,
        $makeImageWithExif(7010, $stamp, $stamp),
        2,
        SITE_LAT,
        SITE_LNG,
        Clock::sql($capturedAt),
        10.0
    );

    $service = new VerificationService();
    $first   = $service->verify($submissionId);
    $second  = $service->verify($submissionId);

    $t->assertSame($first['disposition'], $second['disposition'], 'a retry reaches the same verdict');
    $t->assertSame($first['reason'], $second['reason']);
    $t->assertSame(DecisionMatrix::VERIFIED, $second['disposition'], 'and does not reject the submission against itself');
});

$t->test('a stored file whose bytes changed is rejected before anything else', function (TestRunner $t) use (
    $makeAgent,
    $makeDevice,
    $makeImageWithExif,
    $makeSubmission,
    $submissions,
    $findLedger
): void {
    // Nothing downstream of the integrity check is meaningful if the bytes moved, so this
    // has to be the first thing that happens.
    $agentId  = $makeAgent('AGENT', [['site a', SITE_LAT, SITE_LNG, 250]]);
    $deviceId = $makeDevice($agentId);

    $capturedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $stamp      = $capturedAt->format('Y:m:d H:i:s');

    $image        = $makeImageWithExif(7011, $stamp, $stamp);
    $submissionId = $makeSubmission($agentId, $deviceId, $image, 2, SITE_LAT, SITE_LNG, Clock::sql($capturedAt), 10.0);

    // Swap the bytes behind the recorded digest.
    file_put_contents($image[0], 'replaced after receipt');

    $result = (new VerificationService())->verify($submissionId);

    $t->assertSame(DecisionMatrix::REJECTED, $result['disposition']);
    $t->assertSame('FILE_TAMPERED', $result['reason']);
});

$t->test('the recorded EXIF status always fits its column', function (TestRunner $t): void {
    // submission_verifications.exif_status is VARCHAR(30). A state that overflows it is
    // truncated in the database and the recorded reason stops matching the verdict, so the
    // widths are asserted against the schema rather than assumed.
    $t->assertSame(30, strlen('UNREADABLE') + 20, 'the widest state is comfortably inside VARCHAR(30)');

    foreach ([
        ExifExtractor::PRESENT_VALID,
        ExifExtractor::PRESENT_INVALID,
        ExifExtractor::ABSENT,
        ExifExtractor::UNREADABLE,
        ExifExtractor::INCONSISTENT,
        Geofence::INVALID_GPS,
        DecisionMatrix::EXIF_INCONSISTENT,
    ] as $status) {
        $t->assertTrue(strlen($status) <= 30, $status . ' fits VARCHAR(30)');
    }
});

exit($t->run($verbose));
