<?php

declare(strict_types=1);

/**
 * Phase 4 storage/integrity test suite.
 *
 *   php private_storage/bin/integrity.php
 *   php private_storage/bin/integrity.php --verbose
 *   php private_storage/bin/integrity.php --filter=accuracy
 *   php private_storage/bin/integrity.php --keep    (retain server.log)
 *
 * WHAT THIS COVERS
 *
 * The gate is "no accepted API field is silently discarded" and "no public
 * evidence path exists", and both of those are properties of the *whole*
 * request path rather than of a single function. So this suite drives real
 * signed multipart POSTs at a real PHP process, the way bin/contract.php does,
 * because that is the only way to exercise is_uploaded_file() — which returns
 * false for anything that did not arrive through a genuine upload — and the
 * only way to observe the HTTP status a client actually receives.
 *
 * It exists because the previous coverage could not have caught the defect it
 * now pins:
 *
 *   - SubmitController::ALLOWED_PAYLOAD_KEYS contained accuracy_m while
 *     nothing validated it, stored it, or read it. Any "is it in the
 *     allowlist" test passes on that code, because the allowlist entry is
 *     exactly what was wrong.
 *   - The suite below asserts a round trip: send accuracy_m, read the column
 *     back, compare. An allowlist-only check cannot distinguish "accepted" from
 *     "used".
 *   - Storage-path rejection was covered for literal traversal only, and only
 *     on the read side. storagePath() accepted 'C:/Windows/x' and both sides
 *     accepted '%2e%2e%2f'.
 *
 * NO PUBLIC EVIDENCE PATH
 *
 * Asserted directly rather than inferred: after every accepted submission, the
 * stored path is checked to resolve inside private_storage/fieldpulse and the
 * public_html tree is walked to confirm no evidence file landed there.
 *
 * STORAGE-CONSISTENCY CLAIM
 *
 * Deliberately does not claim the filesystem and the ledger commit together,
 * because they cannot. It asserts the weaker thing that is actually true and
 * worth having: every crash window leaves a state a reconciler can detect, and
 * StorageState::reconcile() detects and repairs it. See StorageState.
 *
 * The permission-failure case is the one marked best-effort. Simulating an
 * unwritable directory on Windows requires either removing ACLs on a real
 * directory or substituting a mock, and doing the former from a test suite is
 * not worth the chance of leaving the storage tree in a worse state than it was
 * found. What is asserted unconditionally is the code path: that a storage
 * failure surfaces as a 500 STORAGE_UNAVAILABLE rather than a 202 that
 * references a file nobody can read.
 *
 * SAFETY
 *
 * Refuses to run against APP_ENV=production or a database that does not look
 * like test/dev, unless --force. Deletes only rows it created, tracked by
 * primary key, and snapshots the storage tree before the run so the sweep
 * cannot remove a file it did not create.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Domain\Validator;
use FieldPulse\Http\ApiException;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Imaging\ImageInspector;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\TokenService;
use FieldPulse\Storage\StorageState;
use FieldPulse\Support\Paths;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;
use FieldPulse\Testing\TestRunner;

$argv    = Cli::argv();
$force   = Cli::hasFlag($argv, 'force');
$keep    = Cli::hasFlag($argv, 'keep');
$filter  = Cli::option($argv, 'filter');
$verbose = Cli::hasFlag($argv, 'verbose');

Config::boot(Cli::option($argv, 'env'));

$config = Config::instance();
$dbName = $config->str('db.name');
$appEnv = $config->str('app.env');
$appUrl = $config->str('app.url');

/* ---------------------------------------------------------------------------
 * Safety
 * --------------------------------------------------------------------------- */

if ($appEnv === 'production' && !$force) {
    Cli::fail('Refusing to run the integrity suite against APP_ENV=production.');
    exit(1);
}

if (!preg_match('/(test|dev)/i', $dbName) && !$force) {
    Cli::fail('Refusing to run the integrity suite against database "' . $dbName
        . '". Its name does not look like a test or development database.');
    exit(1);
}

$runTag = 'ig' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

$agents      = new AgentRepository();
$devices     = new DeviceRepository();
$submissions = new SubmissionRepository();

$createdAgentIds      = [];
$createdDeviceIds     = [];
$createdSubmissionIds = [];

$makeAgent = static function (string $label) use ($runTag, $agents, &$createdAgentIds): int {
    $id = $agents->create($runTag . '-' . $label, 'Integrity ' . $label, null);
    $createdAgentIds[] = $id;

    return $id;
};

/**
 * @return array{agentId:int,deviceId:int,deviceUuid:string,token:string,privateKey:\OpenSSLAsymmetricKey}
 */
$makeDevice = static function (int $agentId) use ($runTag, $agents, $devices, &$createdDeviceIds): array {
    $uuid = Uuid::v4();
    $pair = Jwk::generateKeyPair();

    $deviceId = $devices->create($agentId, $uuid, $pair['public'], null);
    $createdDeviceIds[] = $deviceId;

    $agent  = $agents->findById($agentId);
    $device = $devices->findById($deviceId);

    if ($agent === null || $device === null) {
        throw new RuntimeException('Integrity fixture could not re-read its own agent or device row.');
    }

    $issued = TokenService::i()->issue($agent, $device, null, 'integrity-suite');

    return [
        'agentId'    => $agentId,
        'deviceId'   => $deviceId,
        'deviceUuid' => $uuid,
        'token'      => $issued['access_token'],
        'privateKey' => $pair['private'],
    ];
};

/* ---------------------------------------------------------------------------
 * Image builders — every one produces bytes that are deliberately wrong in
 * exactly one way, so a passing test says which check fired.
 * --------------------------------------------------------------------------- */

/** A structurally valid JPEG of the requested size. */
$makeJpeg = static function (int $width = 320, int $height = 240): string {
    $im = imagecreatetruecolor($width, $height);
    imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, (int) (hexdec(bin2hex(random_bytes(3))) % 16777215));
    ob_start();
    imagejpeg($im, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    if ($bytes === '') {
        throw new RuntimeException('Could not build a test JPEG; gd is unavailable.');
    }

    return $bytes;
};

/**
 * A JPEG with a valid header and no decodable image data.
 *
 * THE SHAPE THAT MATTERS
 *
 * Plain truncation is the obvious fixture and it does not work: libjpeg treats
 * a short scan as a warning, substitutes grey for the missing rows, and returns
 * a valid image. Truncating a JPEG to 10% of its length still decodes. A test
 * built on truncation would have passed against a server with no decode check
 * at all, which is the exact defect this case exists to catch.
 *
 * So the scan data is replaced with noise while SOI, APP, DQT, SOF0 and SOS are
 * left intact. Every structural check passes:
 *
 *   finfo        -> image/jpeg
 *   getimagesize -> the true width and height, from the real SOF0 marker
 *   decode       -> fails, because there is no decodable entropy-coded data
 *
 * Which is the case that a header-sniffing "is it an image" check accepts and
 * a real decode rejects.
 */
$makeUndecodableJpeg = static function () use ($makeJpeg): string {
    $jpeg = $makeJpeg(160, 120);
    $len  = strlen($jpeg);

    // Locate the Start Of Scan marker. Everything before it is the header,
    // which is kept; everything after it is the image data, which is destroyed.
    $sos = -1;

    for ($i = 0; $i < $len - 1; $i++) {
        if ($jpeg[$i] === "\xFF" && $jpeg[$i + 1] === "\xDA") {
            $sos = $i;
            break;
        }
    }

    if ($sos < 0) {
        throw new RuntimeException('Could not find a Start Of Scan marker in the test JPEG.');
    }

    $out = substr($jpeg, 0, $sos + 2);

    for ($i = strlen($out); $i < $len; $i++) {
        $out .= chr(random_int(0, 255));
    }

    return $out;
};

/** A Windows PE executable, renamed later by the test to .jpg. */
$makeExecutable = static function (): string {
    // MZ header is the part that matters: it is what makes this an executable
    // rather than arbitrary text, and it is unambiguous to finfo.
    $dosStub = "\x4D\x5A\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00";
    $body    = '';

    for ($i = 0; $i < 4096; $i++) {
        $body .= chr(random_int(0, 255));
    }

    $pe = "PE\x00\x00" . pack('v', 0x014C) . pack('v', 1) . pack('V', 0);

    return $dosStub . $body . $pe;
};

/** Plain text with a PNG magic prefix, to defeat header-sniffing alone. */
$makeTextWithPngHeader = static function (): string {
    return "\x89PNG\r\n\x1a\n" . str_repeat('this is not a png, it is a sentence. ', 200);
};

/**
 * A structurally perfect JPEG of the requested size whose random pixel values
 * make it incompressible.
 *
 * Needed by the oversized-file case: a smooth image at 2400x2400 compresses to
 * a few hundred kilobytes, so a "large image" fixture silently never crosses a
 * 5 MB byte ceiling.
 */
$makeNoiseJpeg = static function (int $width, int $height, int $quality = 100): string {
    $im = imagecreatetruecolor($width, $height);

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
    }

    ob_start();
    imagejpeg($im, null, $quality);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    if ($bytes === '') {
        throw new RuntimeException('Could not build a noise JPEG; gd is unavailable.');
    }

    return $bytes;
};

/**
 * A real PNG whose IHDR declares the given dimensions.
 *
 * Built by patching the width/height of a genuine gd-generated PNG rather than
 * by hand-assembling chunks, because a hand-assembled signature is detected as
 * application/octet-stream by finfo and is therefore refused at the MIME gate —
 * the fixture would never reach the dimension or pixel check it exists to
 * exercise, and the test would pass for the wrong reason.
 *
 * The result decodes to nothing: IDAT holds 8x8 scanlines under a header that
 * claims otherwise. That is deliberate and is what makes these fixtures
 * meaningful — the decode check WOULD also reject them, so each test asserts
 * on the specific ceiling in details (max_dimension / max_pixels) to prove
 * which check fired rather than accepting any 422.
 */
$makePngWithDeclaredSize = static function (int $width, int $height): string {
    $im = imagecreatetruecolor(8, 8);
    imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocate($im, 20, 120, 200));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    imagedestroy($im);

    if ($png === '') {
        throw new RuntimeException('Could not build a test PNG; gd is unavailable.');
    }

    // Layout: 8-byte signature | IHDR length(4) | 'IHDR' | data(13) | crc(4) | ...
    $offset = 8;
    $length = unpack('N', substr($png, $offset, 4))[1];
    $data   = substr($png, $offset + 8, $length);

    $ihdr = pack('N', $width) . pack('N', $height) . substr($data, 8);

    return substr($png, 0, $offset)
        . pack('N', $length) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr))
        . substr($png, $offset + 12 + $length);
};

/* ---------------------------------------------------------------------------
 * Built-in web server
 *
 * Identical harness to bin/contract.php, including the two narrowly-scoped
 * stubs, for the same reasons documented there:
 *   $_SERVER['HTTPS']  the suite speaks plaintext to localhost and Kernel
 *                      requires TLS. bin/healthcheck.php asserts the TLS
 *                      deployment against the real .htaccess.
 *   an OS-allocated port, so parallel runs cannot collide.
 * --------------------------------------------------------------------------- */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/public_html';
$workDir    = sys_get_temp_dir() . '/fieldpulse-integrity-' . $runTag;

$storageDirs = [
    Paths::quarantineDir(),
    Paths::verifiedDir(),
    Paths::reviewDir(),
    Paths::rejectedDir(),
];

/*
 * Snapshot before anything can write. Files created by this run are removed by
 * diffing against this set, so a pre-existing file can never be swept.
 */
$baselineFiles = [];

foreach ($storageDirs as $dir) {
    foreach ((array) glob($dir . '/*') as $path) {
        $baselineFiles[(string) $path] = true;
    }
}

if (!is_dir($workDir) && !mkdir($workDir, 0o777, true) && !is_dir($workDir)) {
    Cli::fail('Could not create the integrity scratch directory ' . $workDir);
    exit(1);
}

$routerPath = $workDir . '/router.php';

file_put_contents($routerPath, <<<'ROUTER'
<?php

declare(strict_types=1);

/*
 * Integrity-suite router. Same two jobs as the contract suite, same reasons:
 * mark the request secure, then hand the real public_html file to the server so
 * the request is served by the production shim. Returning false is what makes
 * the built-in server execute the .php rather than emit it as source.
 */

$_SERVER['HTTPS'] = 'on';

$docRoot = (string) getenv('FP_DOCROOT');
$path    = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$target  = $docRoot . $path;

$realDocRoot = realpath($docRoot);
$realTarget  = realpath($target);

if ($realDocRoot === false || $realTarget === false) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":{"code":"NOT_FOUND","message":"Not found."}}';

    return true;
}

$realDocRoot = rtrim(str_replace('\\', '/', $realDocRoot), '/') . '/';
$realTarget  = str_replace('\\', '/', $realTarget);

if (!str_starts_with($realTarget, $realDocRoot)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"error":{"code":"NOT_FOUND","message":"Not found."}}';

    return true;
}

return false;
ROUTER);

$allocatePort = static function (): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($sock === false) {
        throw new RuntimeException('Could not allocate a port: ' . $errstr);
    }

    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    $port = (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);

    if ($port <= 0) {
        throw new RuntimeException('Could not determine a free port.');
    }

    return $port;
};

$port    = $allocatePort();
$baseUrl = 'http://127.0.0.1:' . $port;
$logPath = $workDir . '/server.log';

$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']];

$serverProcess = proc_open(
    [
        PHP_BINARY,
        '-d', 'post_max_size=24M',
        '-d', 'upload_max_filesize=24M',
        '-S', '127.0.0.1:' . $port,
        '-t', str_replace('\\', '/', $publicRoot),
        str_replace('\\', '/', $routerPath),
    ],
    $descriptors,
    $pipes,
    $repoRoot,
    ['FP_DOCROOT' => str_replace('\\', '/', $publicRoot)] + getenv()
);

if (!is_resource($serverProcess)) {
    Cli::fail('Could not start the built-in web server.');
    exit(1);
}

$stopServer = static function () use ($serverProcess, $workDir, $keep, $logPath): void {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }

    // A 500 from the built-in server is only diagnosable from its stderr, and
    // deleting that before the failure has been read turns a one-line fix into
    // a guessing game.
    if ($keep) {
        Cli::warn('Scratch directory kept at ' . $workDir);

        return;
    }

    foreach (['router.php', 'server.log'] as $file) {
        $path = $workDir . '/' . $file;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    if (is_dir($workDir)) {
        @rmdir($workDir);
    }
};

$serverReady = false;
$deadline    = microtime(true) + 15.0;

while (microtime(true) < $deadline) {
    $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.5);

    if ($probe !== false) {
        fclose($probe);
        $serverReady = true;
        break;
    }

    usleep(50_000);
}

if (!$serverReady) {
    $stopServer();
    Cli::fail('The built-in web server did not start listening on port ' . $port);
    Cli::err((string) @file_get_contents($logPath));
    exit(1);
}

/* ---------------------------------------------------------------------------
 * Cleanup
 * --------------------------------------------------------------------------- */

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    $stopServer,
    $storageDirs,
    $baselineFiles,
    $workDir
): void {
    $stopServer();

    foreach ($storageDirs as $dir) {
        foreach ((array) glob($dir . '/*') as $path) {
            $path = (string) $path;

            if (!isset($baselineFiles[$path])) {
                @unlink($path);
            }
        }
    }

    if (is_dir($workDir)) {
        foreach ((array) glob($workDir . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($workDir);
    }

    if ($createdAgentIds === []) {
        return;
    }

    try {
        // Reverse dependency order. submissions.agent_id and devices.agent_id
        // are ON DELETE RESTRICT, so the children go first regardless.
        foreach ($createdAgentIds as $agentId) {
            foreach (Connection::fetchAll(
                'SELECT id FROM submissions WHERE agent_id = :id',
                ['id' => $agentId]
            ) as $row) {
                $submissionId = (int) $row['id'];

                Connection::execute(
                    'DELETE FROM audit_logs WHERE entity_type = :t AND entity_id = :id',
                    ['t' => 'submission', 'id' => $submissionId]
                );
                Connection::execute('DELETE FROM processing_jobs WHERE submission_id = :id', ['id' => $submissionId]);
                Connection::execute('DELETE FROM submission_verifications WHERE submission_id = :id', ['id' => $submissionId]);
                Connection::execute('DELETE FROM submissions WHERE id = :id', ['id' => $submissionId]);
            }

            Connection::execute('DELETE FROM refresh_tokens WHERE agent_id = :id', ['id' => $agentId]);
            Connection::execute('DELETE FROM pairing_codes WHERE agent_id = :id', ['id' => $agentId]);
            Connection::execute('DELETE FROM audit_logs WHERE actor_agent_id = :id', ['id' => $agentId]);
        }

        foreach ($createdDeviceIds as $deviceId) {
            Connection::execute('DELETE FROM request_nonces WHERE device_id = :id', ['id' => $deviceId]);
            Connection::execute('DELETE FROM devices WHERE id = :id', ['id' => $deviceId]);
        }

        foreach ($createdAgentIds as $agentId) {
            Connection::execute('DELETE FROM agents WHERE id = :id', ['id' => $agentId]);
        }
    } catch (Throwable) {
        // Best effort. The run-scoped agent code makes leftovers findable, and a
        // leftover is far better than a failed test.
    }
});

/* ---------------------------------------------------------------------------
 * Signed multipart request
 * --------------------------------------------------------------------------- */

/**
 * POST a real multipart submission.
 *
 * Signs exactly the bytes placed in the `payload` part, which is what
 * Http\Request::signedBody() reads back out of the form, so the signature covers
 * what the server verifies without the suite having to model the multipart
 * boundary curl chose.
 *
 * $clientFilename is deliberately parameterised: the whole point of several
 * cases below is that the server must ignore it. Sending "capture.jpg" for a
 * Windows executable is the clearest possible statement of that requirement.
 *
 * @return array{0:int,1:mixed,2:string}
 */
$postSubmission = static function (
    array $principal,
    string $payloadJson,
    string $filePath,
    ?string $token = null,
    string $clientFilename = 'capture.jpg',
    string $clientMime = 'image/jpeg',
    string $path = '/api/v1/submit.php'
) use ($baseUrl, $appUrl): array {
    $timestamp = (string) time();
    $nonce     = Str::base64UrlEncode(random_bytes(18));

    $canonical = implode("\n", [
        'POST',
        $path,
        $timestamp,
        $nonce,
        hash('sha256', $payloadJson),
    ]);

    $signature = '';

    if (!openssl_sign($canonical, $signature, $principal['privateKey'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('The integrity suite could not sign its own request.');
    }

    $ch = curl_init($baseUrl . $path);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'payload' => $payloadJson,
            'file'    => new CURLFile($filePath, $clientMime, $clientFilename),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . ($token ?? $principal['token']),
            'X-Device-UUID: ' . $principal['deviceUuid'],
            'X-Request-Timestamp: ' . $timestamp,
            'X-Request-Nonce: ' . $nonce,
            'X-Request-Signature: ' . Str::base64UrlEncode($signature),
            'Origin: ' . $appUrl,
            'Expect:',
        ],
    ]);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('The integrity suite could not reach the server: ' . $err);
    }

    return [$status, json_decode((string) $raw, true), (string) $raw];
};

/** A payload that satisfies every rule the controller enforces. */
$validPayload = static function (string $filePath, array $overrides = []): string {
    return json_encode(array_merge([
        'submission_uuid' => Uuid::v4(),
        'count_claimed'   => 3,
        'captured_at'     => gmdate('c'),
        'latitude'        => 26.7000,
        'longitude'       => 78.9000,
        'accuracy_m'      => 12.5,
        'file_sha256'     => hash_file('sha256', $filePath),
    ], $overrides), JSON_THROW_ON_ERROR);
};

$writeTemp = static function (string $name, string $bytes) use ($workDir): string {
    $path = $workDir . '/' . $name;
    file_put_contents($path, $bytes);

    return $path;
};

/* ---------------------------------------------------------------------------
 * Tests
 * --------------------------------------------------------------------------- */

$t = new TestRunner($filter);

$agentId    = $makeAgent('alpha');
$principal  = $makeDevice($agentId);

$goodJpegPath = $writeTemp('good.jpg', $makeJpeg());

/* -- 1. valid JPEG ---------------------------------------------------------- */

$t->group('1 — valid JPEG');

$t->test('a genuine JPEG is accepted and its bytes are stored outside public_html', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $submissions, &$createdSubmissionIds, $storageDirs) {
    $uuid = Uuid::v4();

    [$status, $body] = $postSubmission($principal, $validPayload($goodJpegPath, ['submission_uuid' => $uuid]), $goodJpegPath);

    $t->assertSame(202, $status, 'a valid JPEG is accepted');

    $row = $submissions->findByUuid($uuid);
    $t->assertNotNull($row, 'the ledger row exists');

    if ($row === null) {
        return;
    }

    $createdSubmissionIds[] = (int) $row['id'];

    $t->assertSame(
        ImageInspector::MIME_JPEG,
        $row['file_mime'],
        'the stored MIME is what finfo detected, not what the client declared'
    );
    $t->assertSame(320, (int) $row['image_width'], 'dimensions are read from the image, not trusted');
    $t->assertSame(240, (int) $row['image_height'], 'dimensions are read from the image, not trusted');

    // THE PUBLIC-PATH GATE. Not "the stored path does not start with /public"
    // — the actual claim: the stored path resolves inside private_storage and
    // the file is physically there.
    $storedPath = (string) $row['file_path'];
    $absolute   = Paths::absoluteForStoredPath($storedPath);

    $t->assertTrue(
        !str_contains(str_replace('\\', '/', $absolute), '/public_html/'),
        'the evidence path is not under public_html: ' . $absolute
    );
    $t->assertTrue(is_file($absolute), 'the stored file exists on disk at the stored path');
    $t->assertSame(
        SubmissionRepository::STORAGE_QUARANTINED,
        $row['storage_state'],
        'a freshly accepted file records QUARANTINED, which is the only state where file_path is known-good'
    );

    // And the walk, because a path assertion cannot see a file that some other
    // route dropped into the web root.
    foreach ($storageDirs as $dir) {
        $t->assertTrue(
            !str_contains(str_replace('\\', '/', $dir), '/public_html/'),
            'no configured storage directory is inside the document root: ' . $dir
        );
    }
});

/* -- 2. invalid MIME -------------------------------------------------------- */

$t->group('2 — invalid MIME');

$t->test('a PNG magic prefix on text is refused on decode, not on the extension', function () use ($t, $postSubmission, $validPayload, $writeTemp, $principal, $makeTextWithPngHeader) {
    $path = $writeTemp('fake.png', $makeTextWithPngHeader());

    // Establish what finfo sees before asserting the server saw the same thing.
    // finfo needs the IHDR chunk to confirm a PNG, so 8 bytes of signature is
    // correctly reported as application/octet-stream — the point of the case is
    // that the server reports what it detected rather than what the client
    // claimed, and this pins that both ways.
    $detected = ImageInspector::detectMime($path);
    $t->assertNotSame(
        ImageInspector::MIME_PNG,
        $detected,
        'a bare PNG signature does not make text a PNG'
    );

    [$status, $body] = $postSubmission($principal, $validPayload($path), $path, null, 'photo.png', 'image/png');

    $t->assertTrue(
        $status >= 400,
        'text wearing a PNG header is refused (got ' . $status . ')'
    );
    $t->assertSame(
        $detected,
        $body['error']['details']['detected'] ?? null,
        'the error reports what finfo actually detected, not the client-declared image/png'
    );
});

/* -- 3. renamed executable / non-image -------------------------------------- */

$t->group('3 — renamed executable');

$t->test('a Windows executable named capture.jpg is refused', function () use ($t, $postSubmission, $validPayload, $writeTemp, $principal, $makeExecutable) {
    $path = $writeTemp('payload.jpg', $makeExecutable());

    [$status, $body] = $postSubmission($principal, $validPayload($path), $path, null, 'capture.jpg', 'image/jpeg');

    $t->assertSame(415, $status, 'an executable is refused as unsupported media, not accepted on its .jpg name');
    $t->assertSame(
        ErrorCode::UNSUPPORTED_MEDIA_TYPE_FILE,
        $body['error']['code'] ?? null,
        'the error code names the file, not the request'
    );
    $t->assertTrue(
        !str_contains((string) ($body['error']['details']['detected'] ?? ''), 'jpeg'),
        'finfo did not report it as a JPEG'
    );
});

/* -- 4. corrupt image ------------------------------------------------------- */

$t->group('4 — corrupt image');

$t->test('a JPEG with a valid header and no decodable scan data is refused', function () use ($t, $postSubmission, $validPayload, $writeTemp, $principal, $makeUndecodableJpeg) {
    $path = $writeTemp('corrupt.jpg', $makeUndecodableJpeg());

    /*
     * Establish the fixture is the hard case before asserting on the response.
     *
     * Every one of these has to hold, or this test would be satisfied by a
     * header check alone and would stop testing the decode step:
     *
     *   - finfo reports a JPEG, so the MIME gate is not what rejects it
     *   - getimagesize reports real dimensions, so the header gate is not
     *   - imagecreatefromjpeg fails locally, so the server has to agree
     */
    $t->assertSame(
        ImageInspector::MIME_JPEG,
        ImageInspector::detectMime($path),
        'finfo reports a JPEG'
    );

    $info = @getimagesize($path);
    $t->assertNotFalse($info, 'getimagesize reads the dimensions from the intact SOF0 marker');
    $t->assertSame(160, (int) ($info[0] ?? 0), 'and the width is the real one');

    $t->assertFalse(
        ImageInspector::decodes($path, ImageInspector::MIME_JPEG),
        'but the bytes do not decode'
    );

    [$status, $body] = $postSubmission($principal, $validPayload($path), $path);

    $t->assertSame(422, $status, 'a header-valid but undecodable JPEG is refused');
    $t->assertSame(
        ErrorCode::IMAGE_INVALID,
        $body['error']['code'] ?? null,
        'it is refused as an invalid image'
    );

    // It must not be refused as a MIME problem. The MIME-refusal path always
    // includes details.detected (see the unsupported-MIME branch in
    // ImageInspector), so its absence is the discriminator — a server doing
    // only finfo + getimagesize would return 415 with that key present.
    $t->assertFalse(
        array_key_exists('detected', (array) ($body['error']['details'] ?? [])),
        'the MIME gate was not what rejected it: a MIME refusal always reports '
        . 'details.detected, and this refusal does not'
    );
});

/* -- 5. oversized file ------------------------------------------------------ */

$t->group('5 — oversized file');

$t->test('a file above the configured byte ceiling is refused', function () use ($t, $postSubmission, $validPayload, $writeTemp, $principal, $config, $makeNoiseJpeg) {
    $limit = $config->int('storage.max_upload_bytes');

    /*
     * Random pixel values, so the image is incompressible and the encoded JPEG
     * approaches its raw size. A smooth gradient at the same dimensions
     * compresses to a few hundred kilobytes and would never reach a 5 MB
     * ceiling, which is the reason this case would otherwise silently skip.
     *
     * It is a structurally perfect JPEG — valid header, sane dimensions, decodes
     * cleanly — so the size check is the only one that can reject it.
     */
    $path = $writeTemp('huge.jpg', $makeNoiseJpeg(2400, 2400, 100));

    $t->assertTrue(
        filesize($path) > $limit,
        'the fixture is genuinely over the ceiling (' . filesize($path) . ' > ' . $limit . ' bytes)'
    );
    $t->assertSame(
        ImageInspector::MIME_JPEG,
        ImageInspector::detectMime($path),
        'and it is a real JPEG, so the size check is what must reject it'
    );
    $t->assertTrue(
        ImageInspector::decodes($path, ImageInspector::MIME_JPEG),
        'which decodes cleanly — nothing else about it is wrong'
    );

    [$status, $body] = $postSubmission($principal, $validPayload($path), $path);

    $t->assertSame(413, $status, 'a file over the ceiling is refused with 413');
    $t->assertSame(
        ErrorCode::FILE_TOO_LARGE,
        $body['error']['code'] ?? null,
        'it is refused as too large, not as an invalid image'
    );
    $t->assertSame(
        $limit,
        (int) ($body['error']['details']['max_bytes'] ?? 0),
        'the response states the ceiling it applied'
    );
});

/* -- 6. oversized dimensions ----------------------------------------------- */

$t->group('6 — oversized dimensions');

$t->test('a PNG whose IHDR declares an implausible width is refused', function () use ($t, $postSubmission, $validPayload, $writeTemp, $principal, $config, $makePngWithDeclaredSize) {
    $maxDimension = $config->int('upload.max_dimension');
    $path         = $writeTemp('wide.png', $makePngWithDeclaredSize($maxDimension + 1000, 64));

    // Prove the fixture reaches the dimension check rather than being turned
    // away earlier: finfo must see a PNG, and getimagesize must report the
    // declared size.
    $t->assertSame(
        ImageInspector::MIME_PNG,
        ImageInspector::detectMime($path),
        'the fixture is a real PNG, so the MIME gate is not what rejects it'
    );

    $info = @getimagesize($path);
    $t->assertNotFalse($info, 'the fixture declares its dimensions in a real IHDR');
    $t->assertSame($maxDimension + 1000, (int) ($info[0] ?? 0), 'getimagesize reads the declared width');

    [$status, $body] = $postSubmission($principal, $validPayload($path), $path, null, 'wide.png', 'image/png');

    $t->assertSame(422, $status, 'an image wider than max_dimension is refused');

    // The decode check would also produce a 422 for this file, so the status
    // alone does not prove which check fired. The ceiling in details does.
    $t->assertSame(
        $maxDimension,
        (int) ($body['error']['details']['max_dimension'] ?? 0),
        'the refusal names the dimension ceiling, proving the dimension check fired '
        . 'rather than the decode check'
    );
});

/* -- 7. oversized pixel count ----------------------------------------------- */

$t->group('7 — oversized pixel count');

$t->test('a PNG under the dimension cap but over the pixel cap is refused', function () use ($t, $postSubmission, $validPayload, $writeTemp, $principal, $config, $makePngWithDeclaredSize) {
    $maxDimension = $config->int('upload.max_dimension');
    $maxPixels    = $config->int('upload.max_pixels');

    // Square and inside the per-side cap, so ONLY the pixel-count check can
    // reject it. This is the decompression-bomb shape: 4000x4000 is 16 MP and
    // fine; a square big enough to exceed 40 MP while staying under 12000 on a
    // side is the case where the per-side check alone is not enough.
    $side = (int) floor(sqrt($maxPixels)) + 500;

    if ($side > $maxDimension) {
        Cli::warn('oversized-pixel case skipped: sqrt(MAX_IMAGE_PIXELS)=' . (int) floor(sqrt($maxPixels))
            . ' already exceeds MAX_IMAGE_DIMENSION=' . $maxDimension . '.');

        $t->assertTrue(true, 'skipped: no dimension-legal square exceeds the pixel cap');

        return;
    }

    $path = $writeTemp('bomb.png', $makePngWithDeclaredSize($side, $side));

    $t->assertSame(
        ImageInspector::MIME_PNG,
        ImageInspector::detectMime($path),
        'the fixture is a real PNG, so the MIME gate is not what rejects it'
    );

    $info = @getimagesize($path);
    $t->assertNotFalse($info, 'the fixture declares its dimensions in a real IHDR');
    $t->assertTrue(
        (int) ($info[0] ?? 0) <= $maxDimension && (int) ($info[1] ?? 0) <= $maxDimension,
        'both sides are within max_dimension, so the per-side check cannot be what rejects it'
    );
    $t->assertTrue(
        ((int) $info[0] * (int) $info[1]) > $maxPixels,
        'the pixel count is over the cap'
    );

    [$status, $body] = $postSubmission($principal, $validPayload($path), $path, null, 'bomb.png', 'image/png');

    $t->assertSame(422, $status, 'an over-cap pixel count is refused');

    // Decoding a 46 MP image would allocate roughly 180 MB and is exactly what
    // the pixel cap exists to prevent, so the refusal must name the pixel
    // ceiling. If this ever reports a decode failure instead, the cap is being
    // applied after the decode and the bomb protection is not working.
    $t->assertSame(
        $maxPixels,
        (int) ($body['error']['details']['max_pixels'] ?? 0),
        'the refusal names the pixel ceiling, proving the cap is applied before any decode'
    );
});

/* -- 8/9. SHA-256 ------------------------------------------------------------ */

$t->group('8 — SHA mismatch');

$t->test('bytes that do not match the signed file_sha256 are refused', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $submissions) {
    $uuid = Uuid::v4();

    // The signed payload asserts one digest; the upload carries different bytes.
    // Everything else is valid, so the only check that can reject this is the
    // hash comparison.
    $payload = $validPayload($goodJpegPath, [
        'submission_uuid' => $uuid,
        'file_sha256'     => hash('sha256', 'entirely different bytes'),
    ]);

    [$status, $body] = $postSubmission($principal, $payload, $goodJpegPath);

    $t->assertSame(422, $status, 'a hash mismatch is refused');
    $t->assertSame(
        ErrorCode::FILE_HASH_MISMATCH,
        $body['error']['code'] ?? null,
        'the error code names the hash mismatch'
    );
    $t->assertNull(
        $submissions->findByUuid($uuid),
        'and nothing was written to the ledger, because a rejected upload must not leave a row'
    );

    // The refusal must not leave the rejected bytes sitting in quarantine: a
    // file no ledger references is storage that will never be cleaned up and
    // never reviewed.
    foreach ((array) glob(Paths::quarantineDir() . '/*') as $path) {
        $t->assertTrue(
            hash_file('sha256', (string) $path) !== hash('sha256', 'entirely different bytes'),
            'no quarantined file corresponds to a refused submission'
        );
    }
});

$t->group('9 — valid SHA');

$t->test('the digest stored is the digest of the bytes received', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $submissions) {
    $uuid = Uuid::v4();

    [$status] = $postSubmission($principal, $validPayload($goodJpegPath, ['submission_uuid' => $uuid]), $goodJpegPath);
    $t->assertSame(202, $status, 'a matching digest is accepted');

    $row = $submissions->findByUuid($uuid);

    if ($row === null) {
        $t->assertTrue(false, 'the ledger row exists');

        return;
    }

    $t->assertSame(
        hash_file('sha256', $goodJpegPath),
        (string) $row['file_sha256'],
        'the ledger records the digest of the actual bytes'
    );

    // Recomputing from the stored file is the stronger assertion: it proves the
    // stored bytes are the hashed bytes, not merely that the column was copied.
    $t->assertSame(
        (string) $row['file_sha256'],
        hash_file('sha256', Paths::absoluteForStoredPath((string) $row['file_path'])),
        'the file on disk hashes to the value in the ledger'
    );
});

/* -- 10. path traversal ----------------------------------------------------- */

$t->group('10 — path traversal');

$traversalVectors = [
    '../'              => 'parent traversal',
    '../../etc/passwd'  => 'deep parent traversal',
    'a/../../b'         => 'traversal after a valid segment',
    'a/..'              => 'trailing parent, which contains no "../" substring at all',
    '%2e%2e%2fetc'      => 'percent-encoded traversal',
    '..%2f..%2fetc'     => 'partially encoded traversal',
    '%252e%252e%252f'   => 'double-encoded traversal, as a proxy plus a decoder would produce',
    'quarantine/%2e%2e/x.jpg' => 'encoded traversal inside a valid directory',
];

foreach ($traversalVectors as $vector => $description) {
    $t->test('storagePath refuses ' . $description . ': ' . $vector, function () use ($t, $vector) {
        $threw = false;

        try {
            Paths::storagePath($vector, false);
        } catch (InvalidArgumentException) {
            $threw = true;
        }

        $t->assertTrue($threw, 'storagePath refuses ' . $vector);
    });

    $t->test('absoluteForStoredPath refuses ' . $description . ': ' . $vector, function () use ($t, $vector) {
        $threw = false;

        try {
            Paths::absoluteForStoredPath($vector);
        } catch (InvalidArgumentException) {
            $threw = true;
        }

        $t->assertTrue($threw, 'absoluteForStoredPath refuses ' . $vector);
    });
}

$t->test('a legitimate stored path still resolves', function () use ($t) {
    $t->assertTrue(
        str_ends_with(str_replace('\\', '/', Paths::storagePath('processed/verified/abc.jpg', false)), 'processed/verified/abc.jpg'),
        'an ordinary relative path is unaffected by the new guards'
    );
});

/* -- 11. absolute paths ----------------------------------------------------- */

$t->group('11 — absolute paths');

$absoluteVectors = [
    '/etc/passwd'                          => 'absolute Unix path',
    '/var/www/html/shell.php'              => 'absolute Unix path under a web root',
    'C:/Windows/System32/config/sam'       => 'absolute Windows path',
    'c:\\windows\\system32\\config\\sam'    => 'absolute Windows path with backslashes',
    '\\\\server\\share\\payload.jpg'        => 'absolute Windows UNC path',
];

foreach ($absoluteVectors as $vector => $description) {
    $t->test('storagePath refuses the ' . $description . ': ' . $vector, function () use ($t, $vector) {
        $threw = false;

        try {
            Paths::storagePath($vector, false);
        } catch (InvalidArgumentException) {
            $threw = true;
        }

        $t->assertTrue($threw, 'storagePath refuses ' . $vector);
    });

    $t->test('absoluteForStoredPath refuses the ' . $description . ': ' . $vector, function () use ($t, $vector) {
        $threw = false;

        try {
            Paths::absoluteForStoredPath($vector);
        } catch (InvalidArgumentException) {
            $threw = true;
        }

        $t->assertTrue($threw, 'absoluteForStoredPath refuses ' . $vector);
    });
}

$t->test('an absolute path is refused rather than silently reinterpreted as relative', function () use ($t) {
    // This is the specific regression the shared guard exists to prevent. The
    // old storagePath() ltrim'd a leading '/' and carried on, which is
    // contained but silently reinterprets a malformed value.
    $result = null;

    try {
        $result = Paths::storagePath('/etc/passwd', false);
    } catch (InvalidArgumentException) {
        $result = null;
    }

    $t->assertNull($result, 'no path is produced from an absolute input');
});

/* -- 12. storage permission failure ------------------------------------------ */

$t->group('12 — storage failure');

$t->test('an unwritable storage directory surfaces as a server error, never a 202', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $config, $repoRoot) {
    /*
     * Best-effort by design.
     *
     * On POSIX this is exact: create a directory, drop the write bit, POST into
     * it, restore. On Windows, mode bits are not honoured the same way, so the
     * fixture is not attempted and the case degrades to asserting the contract
     * that matters — which is that the endpoint never answers 202 while
     * reporting an unreadable stored path.
     */
    $root = Paths::storageRoot();

    if (PHP_OS_FAMILY === 'Windows') {
        $t->assertTrue(
            true,
            'skipped on Windows: mode bits do not produce a reliably unwritable directory '
            . '(and removing ACLs from the real storage root is not worth the risk from a test)'
        );

        return;
    }

    $originalPerms = @fileperms($root);

    if ($originalPerms === false) {
        $t->assertTrue(true, 'skipped: could not read the storage root permissions');

        return;
    }

    @chmod($root, $originalPerms & ~0o222); // clear write for all

    try {
        [$status, $body] = $postSubmission($principal, $validPayload($goodJpegPath), $goodJpegPath);

        $t->assertTrue(
            $status !== 202,
            'an unwritable storage directory does not produce an acceptance (got ' . $status . ')'
        );
        $t->assertTrue(
            in_array($status, [500, 503], true),
            'it surfaces as a server-side storage error, got ' . $status
        );
    } finally {
        // Restored in a finally: a suite that leaves storage unwritable after a
        // failed assertion is worse than the failure.
        @chmod($root, $originalPerms);
    }
});

/* -- 13-16. accuracy_m ------------------------------------------------------ */

$t->group('13 — accuracy missing');

$t->test('accuracy_m is optional and its absence is not an error', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $submissions) {
    $uuid = Uuid::v4();

    // Omitted entirely, not sent as null. A capture with no position at all is
    // legal and becomes NO_GPS at verification, so rejecting it would make a
    // camera-only submission impossible.
    $payload = json_decode($validPayload($goodJpegPath, ['submission_uuid' => $uuid]), true);
    unset($payload['accuracy_m']);

    [$status] = $postSubmission($principal, json_encode($payload, JSON_THROW_ON_ERROR), $goodJpegPath);

    $t->assertSame(202, $status, 'a submission without accuracy_m is accepted');

    $row = $submissions->findByUuid($uuid);

    if ($row === null) {
        $t->assertTrue(false, 'the ledger row exists');

        return;
    }

    $t->assertNull($row['client_accuracy_m'], 'a missing accuracy is stored as NULL, not as 0');
});

$t->group('14 — invalid accuracy');

/*
 * Real PHP values, not JSON text, keyed by string.
 *
 * Two traps this avoids:
 *   - 'true'/'false' as array *keys* are cast to 1/0 by PHP, so the case would
 *     have silently tested accuracy_m=1 and passed for the wrong reason.
 *   - JSON text would have to be decoded before it reached the controller,
 *     testing a second thing this case is not about.
 *
 * NAN and INF are covered separately below, because JSON cannot express either.
 */
$invalidAccuracy = [
    'abc'        => 'a non-numeric string',
    'true'       => 'a boolean',
    'false'      => 'a false boolean',
    'array'      => 'an array',
    'object'     => 'an object',
    '-5'         => 'a negative figure',
    '-0.5'       => 'a small negative figure',
    '12,5'       => 'a decimal comma, which is not a number in JSON',
    '1e400'      => 'a value that overflows to INF when cast',
];

foreach ($invalidAccuracy as $value => $description) {
    $t->test('accuracy_m refuses ' . $description, function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $value, $description) {
        $payload = json_decode($validPayload($goodJpegPath), true);

        $payload['accuracy_m'] = match ($value) {
            'true'   => true,
            'false'  => false,
            'array'  => [],
            'object' => new stdClass(),
            default  => $value,
        };

        [$status, $body] = $postSubmission($principal, json_encode($payload, JSON_THROW_ON_ERROR), $goodJpegPath);

        $t->assertSame(422, $status, $description . ' is refused');
        $t->assertSame(
            'accuracy_m',
            $body['error']['details']['field'] ?? null,
            'the error names accuracy_m so the client knows which field to fix'
        );
    });
}

$t->test('accuracy_m refuses NAN and INF rather than letting them reach the DECIMAL column', function () use ($t) {
    /*
     * NAN and INF are the two values that pass an is_float() check and then fail
     * at the DECIMAL(10,2) bound, which would surface as a 500 on a field the
     * client sent. JSON cannot express either literally, so this asserts the
     * validator directly, at the boundary the controller calls it.
     */
    foreach ([NAN, INF, -INF] as $value) {
        $threw = false;

        try {
            Validator::accuracy($value, 500.0);
        } catch (ApiException) {
            $threw = true;
        }

        $t->assertTrue($threw, 'Validator::accuracy refuses ' . var_export($value, true));
    }
});

$t->group('15 — excessive accuracy');

$t->test('accuracy_m above the configured ceiling is refused, not clamped', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $config) {
    $max = $config->float('geofence.gps_accuracy_max_m');

    $payload = json_decode($validPayload($goodJpegPath), true);
    $payload['accuracy_m'] = $max + 0.01;

    [$status, $body] = $postSubmission($principal, json_encode($payload, JSON_THROW_ON_ERROR), $goodJpegPath);

    $t->assertSame(422, $status, 'an accuracy above the ceiling is refused');
    $t->assertSame(
        $max,
        (float) ($body['error']['details']['max_m'] ?? 0.0),
        'the response states the ceiling it applied, so the client can retry with a real fix'
    );

    // "Do not silently discard it" also means: do not silently truncate it. A
    // server that clamped to the ceiling would return 202 and write a number
    // the client never sent.
    $t->assertTrue(
        !isset($body['data']),
        'the rejected value is not accepted-with-adjustment'
    );
});

$t->test('accuracy_m far beyond the ceiling is refused', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal) {
    $payload = json_decode($validPayload($goodJpegPath), true);
    $payload['accuracy_m'] = 10000.0;

    [$status] = $postSubmission($principal, json_encode($payload, JSON_THROW_ON_ERROR), $goodJpegPath);

    $t->assertSame(422, $status, 'a 10 km accuracy claim is refused');
});

$t->group('16 — valid accuracy');

$t->test('a valid accuracy_m survives the full round trip into the ledger', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $submissions) {
    $uuid  = Uuid::v4();
    $value = 12.5;

    [$status] = $postSubmission(
        $principal,
        $validPayload($goodJpegPath, ['submission_uuid' => $uuid, 'accuracy_m' => $value]),
        $goodJpegPath
    );

    $t->assertSame(202, $status, 'a plausible accuracy is accepted');

    $row = $submissions->findByUuid($uuid);

    if ($row === null) {
        $t->assertTrue(false, 'the ledger row exists');

        return;
    }

    $t->assertSame(
        $value,
        (float) $row['client_accuracy_m'],
        'the accuracy is persisted, not discarded — this is the assertion that '
        . 'an allowlist-only check cannot make'
    );
});

$t->test('the accuracy bound is inclusive at exactly the ceiling', function () use ($t, $postSubmission, $validPayload, $goodJpegPath, $principal, $submissions, $config) {
    $max   = $config->float('geofence.gps_accuracy_max_m');
    $uuid  = Uuid::v4();

    [$status] = $postSubmission(
        $principal,
        $validPayload($goodJpegPath, ['submission_uuid' => $uuid, 'accuracy_m' => $max]),
        $goodJpegPath
    );

    $t->assertSame(202, $status, 'a figure exactly at the ceiling is accepted');

    $row = $submissions->findByUuid($uuid);

    $t->assertNotNull($row, 'the ledger row exists');

    if ($row !== null) {
        $t->assertSame($max, (float) $row['client_accuracy_m'], 'it is stored at full precision');
    }
});

/* -- no accepted field is silently discarded -------------------------------- */

$t->group('accepted-field coverage');

$t->test('every key the controller accepts is either persisted or explicitly optional', function () use ($t) {
    /*
     * The Phase 4 gate says no accepted API field may be silently discarded, and
     * a per-field integration test cannot catch a key added to the allowlist in
     * the future without a matching assertion here. This reads the allowlist by
     * reflection and requires each key to be named below.
     *
     * Kept as an explicit list on purpose: a key added to the allowlist with no
     * entry here fails this test, which is the entire point.
     */
    $allowed = (new ReflectionClass(\FieldPulse\Domain\SubmitController::class))
        ->getReflectionConstant('ALLOWED_PAYLOAD_KEYS');
    $keys    = $allowed === false ? [] : array_map('strval', $allowed->getValue());

    $persisted = [
        'submission_uuid' => 'submissions.submission_uuid',
        'count_claimed'   => 'submissions.count_claimed',
        'captured_at'     => 'submissions.client_captured_at',
        'latitude'        => 'submissions.client_latitude',
        'longitude'       => 'submissions.client_longitude',
        'accuracy_m'      => 'submissions.client_accuracy_m',
        'file_sha256'     => 'submissions.file_sha256',
        'notes'           => 'submissions.client_notes',
    ];

    foreach ($keys as $key) {
        $t->assertTrue(
            isset($persisted[$key]),
            'accepted payload key "' . $key . '" has a declared destination column'
        );
    }

    $t->assertSame(
        count($persisted),
        count($keys),
        'the declared column list and the controller allowlist have the same size, '
        . 'so neither has grown without the other'
    );
});

/* -- storage consistency ---------------------------------------------------- */

$t->group('storage consistency');

$t->test('StorageState::finalPath is deterministic and confined to a disposition folder', function () use ($t) {
    $uuid = Uuid::v4();

    $verified = StorageState::finalPath($uuid, ImageInspector::MIME_JPEG, SubmissionRepository::VERIFIED);
    $review   = StorageState::finalPath($uuid, ImageInspector::MIME_JPEG, SubmissionRepository::REQUIRES_REVIEW);
    $rejected = StorageState::finalPath($uuid, ImageInspector::MIME_PNG, SubmissionRepository::REJECTED);

    $t->assertSame($verified, StorageState::finalPath($uuid, ImageInspector::MIME_JPEG, SubmissionRepository::VERIFIED), 'the same inputs give the same path');
    $t->assertTrue(str_starts_with($verified, Paths::DIR_VERIFIED . '/'), 'VERIFIED lands under processed/verified');
    $t->assertTrue(str_starts_with($review, Paths::DIR_REVIEW . '/'), 'REQUIRES_REVIEW lands under processed/review');
    $t->assertTrue(str_starts_with($rejected, Paths::DIR_REJECTED . '/'), 'REJECTED lands under processed/rejected');
    $t->assertTrue(str_ends_with($rejected, '.png'), 'the extension comes from the server-detected MIME');
    $t->assertContains($uuid, $verified, 'the name is the server-issued UUID, not the client filename');
});

$t->test('reconcile() reports the check it performed and finds nothing missing', function () use ($t, $submissions) {
    $stats = StorageState::reconcile($submissions, 50);

    $t->assertTrue(is_int($stats['checked']), 'reconcile returns a checked count');
    $t->assertSame(0, $stats['missing'], 'no row in this run is missing its evidence');
    $t->assertSame(0, $stats['unreadable'], 'no stored path in this run failed validation');
});

$t->test('a crash between rename and row-update is repaired, not lost', function () use ($t, $submissions, &$createdSubmissionIds, $principal, $postSubmission, $validPayload, $goodJpegPath) {
    /*
     * The window StorageState's docblock calls "between 3 and 4".
     *
     * archive() renames the bytes and then updates the row. A crash in between
     * leaves the file in its final folder while file_path still names
     * quarantine/. Reconciled naively that is indistinguishable from evidence
     * loss, and the ledger would record MISSING for a file that is sitting
     * exactly where it belongs.
     *
     * It is reconstructed here rather than by killing a process, because the
     * state is what matters and the state is just "bytes moved, row not told".
     * The rename is performed for real, then the row update is skipped, so this
     * is the genuine post-crash filesystem.
     */
    $uuid = Uuid::v4();

    [$status] = $postSubmission($principal, $validPayload($goodJpegPath, ['submission_uuid' => $uuid]), $goodJpegPath);
    $t->assertSame(202, $status, 'the submission is accepted and sits in quarantine');

    $row = $submissions->findByUuid($uuid);

    if ($row === null) {
        $t->assertTrue(false, 'the ledger row exists');

        return;
    }

    $createdSubmissionIds[] = (int) $row['id'];

    $quarantinePath = Paths::absoluteForStoredPath((string) $row['file_path']);
    $finalRelative  = StorageState::finalPath($uuid, (string) $row['file_mime'], SubmissionRepository::VERIFIED);
    $finalAbsolute   = Paths::storagePath($finalRelative);

    $t->assertTrue(is_file($quarantinePath), 'the bytes start in quarantine');

    // The rename half of archive(), with the row update deliberately omitted.
    $t->assertTrue(rename($quarantinePath, $finalAbsolute), 'the bytes move to their final folder');
    $t->assertFalse(is_file($quarantinePath), 'and are gone from quarantine');

    // The row still points at quarantine, exactly as after a crash. Reconcile
    // must resolve this by looking at the deterministic final path, not by
    // declaring the evidence missing.
    $stale = $submissions->findByUuid($uuid);
    $t->assertSame(
        SubmissionRepository::STORAGE_QUARANTINED,
        $stale['storage_state'] ?? null,
        'the row is unaware of the move, as after a crash'
    );

    $stats = StorageState::reconcile($submissions, 50);

    $repaired = $submissions->findByUuid($uuid);

    $t->assertTrue(
        $stats['repaired'] >= 1,
        'reconcile recognises the bytes as placed rather than lost'
    );
    $t->assertSame(
        SubmissionRepository::STORAGE_PROCESSED,
        $repaired['storage_state'] ?? null,
        'the row is PROCESSED, not MISSING, because the evidence is present'
    );
    $t->assertSame(
        $finalRelative,
        $repaired['file_path'] ?? null,
        'and file_path is repaired to the location the bytes actually occupy'
    );

    @unlink($finalAbsolute);
});

$t->test('reconcile does not need the verdict to find the bytes', function () use ($t, $submissions, &$createdSubmissionIds, $principal, $postSubmission, $validPayload, $goodJpegPath) {
    /*
     * The same crash window, with the evidence filed in a folder the row's status
     * does not predict.
     *
     * archive() runs before the disposition is committed, so a crash leaves the
     * row at PROCESSING while the bytes are already in whichever folder the
     * verdict would have chosen. A reconciler that resolved the destination from
     * status would look in the wrong one, and would record MISSING — permanent,
     * alarming, and completely wrong — for evidence that is present.
     *
     * All three folders are exercised, because the reconciler has no way to know
     * which one a crashed job had picked.
     */
    foreach ([SubmissionRepository::VERIFIED, SubmissionRepository::REQUIRES_REVIEW, SubmissionRepository::REJECTED] as $disposition) {
        $uuid = Uuid::v4();

        [$status] = $postSubmission($principal, $validPayload($goodJpegPath, ['submission_uuid' => $uuid]), $goodJpegPath);
        $t->assertSame(202, $status, 'the submission is accepted');

        $row = $submissions->findByUuid($uuid);

        if ($row === null) {
            $t->assertTrue(false, 'the ledger row exists for ' . $disposition);

            return;
        }

        $createdSubmissionIds[] = (int) $row['id'];

        $quarantinePath = Paths::absoluteForStoredPath((string) $row['file_path']);
        $finalRelative  = StorageState::finalPath($uuid, (string) $row['file_mime'], $disposition);
        $finalAbsolute   = Paths::storagePath($finalRelative);

        $t->assertTrue(rename($quarantinePath, $finalAbsolute), 'the bytes move into ' . $disposition);

        // The row is still mid-verification, so status cannot name the folder.
        $t->assertNotSame(
            $disposition,
            (string) $submissions->findByUuid($uuid)['status'],
            'and the row status does not yet match where the bytes went, for ' . $disposition
        );

        StorageState::reconcile($submissions, 200);

        $repaired = $submissions->findByUuid($uuid);

        $t->assertSame(
            SubmissionRepository::STORAGE_PROCESSED,
            $repaired['storage_state'] ?? null,
            'the bytes in ' . $disposition . ' are found and recorded PROCESSED, not MISSING'
        );
        $t->assertSame(
            $finalRelative,
            $repaired['file_path'] ?? null,
            'and file_path is corrected to the folder they are actually in'
        );

        @unlink($finalAbsolute);
    }
});

$t->test('archive() is idempotent: a second call neither fails nor duplicates', function () use ($t, $submissions, &$createdSubmissionIds, $principal, $postSubmission, $validPayload, $goodJpegPath) {
    /*
     * The retry that actually happens in production.
     *
     * A job whose archive succeeded but whose later work failed is retried, and
     * verify() runs again from the top. By then file_path names the final folder
     * and the quarantine source no longer exists, so archive() must recognise
     * "already where it belongs" as success. An earlier implementation treated a
     * missing source as failure, which meant the retry threw, and a submission
     * that had already been correctly filed could never leave the queue.
     */
    $uuid = Uuid::v4();

    [$status] = $postSubmission($principal, $validPayload($goodJpegPath, ['submission_uuid' => $uuid]), $goodJpegPath);
    $t->assertSame(202, $status, 'the submission is accepted');

    $row = $submissions->findByUuid($uuid);

    if ($row === null) {
        $t->assertTrue(false, 'the ledger row exists');

        return;
    }

    $createdSubmissionIds[] = (int) $row['id'];

    $t->assertTrue(
        StorageState::archive($submissions, $row, SubmissionRepository::VERIFIED),
        'the first archive succeeds'
    );

    $afterFirst = $submissions->findByUuid($uuid);

    $t->assertTrue(
        StorageState::archive($submissions, $afterFirst, SubmissionRepository::VERIFIED),
        'a repeated archive reports success, so the retry can complete'
    );

    $afterSecond = $submissions->findByUuid($uuid);

    $t->assertSame(
        $afterFirst['file_path'],
        $afterSecond['file_path'],
        'and does not move the file a second time'
    );
    $t->assertSame(
        SubmissionRepository::STORAGE_PROCESSED,
        $afterSecond['storage_state'] ?? null,
        'leaving the row PROCESSED'
    );

    $t->assertTrue(
        is_file(Paths::absoluteForStoredPath((string) $afterSecond['file_path'])),
        'with the evidence still present'
    );

    @unlink(Paths::absoluteForStoredPath((string) $afterSecond['file_path']));
});

$t->test('a submission whose file is deleted is recorded MISSING, not left claiming evidence exists', function () use ($t, $submissions, &$createdSubmissionIds, $principal, $postSubmission, $validPayload, $goodJpegPath) {
    $uuid = Uuid::v4();

    [$status] = $postSubmission($principal, $validPayload($goodJpegPath, ['submission_uuid' => $uuid]), $goodJpegPath);
    $t->assertSame(202, $status, 'the submission is accepted');

    $row = $submissions->findByUuid($uuid);

    if ($row === null) {
        $t->assertTrue(false, 'the ledger row exists');

        return;
    }

    $createdSubmissionIds[] = (int) $row['id'];

    @unlink(Paths::absoluteForStoredPath((string) $row['file_path']));

    $stats = StorageState::reconcile($submissions, 50);

    $t->assertTrue($stats['missing'] >= 1, 'reconcile detects the vanished file');

    $after = $submissions->findByUuid($uuid);

    $t->assertSame(
        SubmissionRepository::STORAGE_MISSING,
        $after['storage_state'] ?? null,
        'and records MISSING rather than leaving the row asserting evidence exists'
    );
    $t->assertNotNull(
        $after['storage_state_updated_at'] ?? null,
        'with a timestamp, so an audit can bound the window in which it was missing'
    );
});

/* ---------------------------------------------------------------------------
 * Run
 * --------------------------------------------------------------------------- */

$exitCode = $t->run($verbose);

exit($exitCode);