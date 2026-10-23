<?php

declare(strict_types=1);

/**
 * Submission API contract suite (Phase 1).
 *
 *   php private_storage/bin/contract.php
 *   php private_storage/bin/contract.php --verbose
 *   php private_storage/bin/contract.php --filter=docs
 *
 * WHY THIS EXISTS
 *
 * The status codes and the response bodies of POST /api/v1/submit.php are a
 * contract between two independently deployable halves — a PHP app on shared
 * hosting and a PWA that is frequently offline — and the only previously
 * executable description of it lived in docs/API.md, which is not code and was
 * not wrong often enough to notice. Integration.php exercises the
 * controller by direct invocation, which cannot observe an HTTP status code at
 * all: a handler that returned the right JSON with the wrong status would pass
 * every assertion in that suite and still break every client.
 *
 * So the three things a client actually branches on are asserted here, over a
 * real socket, against a real PHP process:
 *
 *   - 202 on first acceptance, with QUEUED and a numeric submission_id
 *   - 200 on a same-owner retry, with ALREADY_RECEIVED and the same id
 *   - 409 when the UUID belongs to a different agent, leaking nothing
 *
 * and, because the client also branches on them:
 *
 *   - a payload carrying agent_id is rejected, not silently accepted
 *   - docs/API.md still describes the route and codes this suite asserts
 *
 * The last one is the only assertion here that is not about PHP behaviour, and
 * it is here precisely because a documentation/code divergence is the defect
 * class most likely to reach production here: nothing else in the build fails
 * when prose goes stale.
 *
 * WHY A BUILT-IN WEB SERVER
 *
 * is_uploaded_file() is the gate that a direct handler invocation cannot pass,
 * and it returns false for a file that was not genuinely uploaded. The built-in
 * server is a real HTTP server, so $_FILES is populated by the SAPI exactly as
 * under Apache or LiteSpeed, and a genuine multipart POST is accepted for real
 * storage instead of being faked. Two conditions are stubbed, and only two:
 *
 *   $_SERVER['HTTPS']  The test speaks plaintext HTTP to localhost. Kernel
 *                      requires TLS, which is correct in production and
 *                      unrelated to the submission contract, so the router
 *                      marks the request as secure. This weakens nothing: the
 *                      TLS gate is asserted by bin/healthcheck.php, which reads
 *                      the deployed .htaccess rather than a router.
 *
 *   the port           Bound to 127.0.0.1 on an OS-assigned free port, so
 *                      parallel runs cannot collide.
 *
 * AUTHENTICATION IS REAL
 *
 * A real P-256 key pair, a real device row, a real signed JWT and a real
 * ECDSA signature over the canonical string. The one thing deliberately not
 * stubbed is the thing under test: ownership. The 409 case below uses a second
 * genuinely different agent, because a stubbed owner would make the assertion
 * about the harness rather than about the server.
 *
 * SAFETY
 *
 * Refuses to run against APP_ENV=production or a database that does not look
 * like a test/dev database, unless --force is given. Deletes only rows it
 * created, tracked by primary key, and stops the server it started.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\SubmissionRepository;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\TokenService;
use FieldPulse\Support\Paths;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;
use FieldPulse\Testing\TestRunner;

$argv    = Cli::argv();
$force   = Cli::hasFlag($argv, 'force');
$keep    = Cli::hasFlag($argv, 'keep');
$filter  = Cli::option($argv, 'filter');
$verbose = Cli::hasFlag($argv, 'verbose');

$envFile = Cli::option($argv, 'env');
Config::boot($envFile);

$config   = Config::instance();
$dbName   = $config->str('db.name');
$appEnv   = $config->str('app.env');
$appUrl   = $config->str('app.url');
$serverVersion = (string) Connection::fetchValue('SELECT VERSION()');

/* ---------------------------------------------------------------------------
 * Safety
 * --------------------------------------------------------------------------- */

if ($appEnv === 'production' && !$force) {
    Cli::fail('Refusing to run the contract suite against APP_ENV=production.');
    exit(1);
}

if (!preg_match('/(test|dev)/i', $dbName) && !$force) {
    Cli::fail('Refusing to run the contract suite against database "' . $dbName
        . '". Its name does not look like a test or development database.');
    exit(1);
}

/* ---------------------------------------------------------------------------
 * Fixtures
 * --------------------------------------------------------------------------- */

$runTag = 'ct' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

$agents     = new AgentRepository();
$devices    = new DeviceRepository();
$submissions = new SubmissionRepository();

$createdAgentIds = [];
$createdDeviceIds = [];
$createdSubmissionIds = [];

$makeAgent = static function (string $label) use ($runTag, $agents, &$createdAgentIds): int {
    $id = $agents->create($runTag . '-' . $label, 'Contract ' . $label, null);
    $createdAgentIds[] = $id;

    return $id;
};

/**
 * A real key pair, a real device row and a real access token.
 *
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
        throw new RuntimeException('Contract fixture could not re-read its own agent or device row.');
    }

    $issued = TokenService::i()->issue($agent, $device, null, 'contract-suite');

    return [
        'agentId'    => $agentId,
        'deviceId'   => $deviceId,
        'deviceUuid' => $uuid,
        'token'      => $issued['access_token'],
        'privateKey' => $pair['private'],
    ];
};

/**
 * A minimal but structurally valid JPEG.
 *
 * gd is only used to build a file that is a real JPEG; the server never decodes
 * it during submission, it only hashes and stores the bytes.
 */
$makeJpeg = static function (string $salt): string {
    $im = imagecreatetruecolor(320, 240);
    imagefilledrectangle($im, 0, 0, 319, 239, (int) (hexdec(substr($salt, 0, 6)) % 16777215));
    ob_start();
    imagejpeg($im, null, 80);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    if ($bytes === '') {
        throw new RuntimeException('Could not build a test JPEG; gd is unavailable.');
    }

    return $bytes;
};

/* ---------------------------------------------------------------------------
 * Built-in web server
 * --------------------------------------------------------------------------- */

$repoRoot   = dirname(__DIR__, 2);
$publicRoot = $repoRoot . '/public_html';
$workDir    = sys_get_temp_dir() . '/fieldpulse-contract-' . $runTag;

/*
 * Snapshot the storage tree before anything can write to it. The submission
 * endpoint is what moves a file out of quarantine and into the verified dir, so
 * this run will create files that a run-tag sweep could never find.
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

if (!is_dir($workDir) && !mkdir($workDir, 0o777, true) && !is_dir($workDir)) {
    Cli::fail('Could not create the contract scratch directory ' . $workDir);
    exit(1);
}

$routerPath = $workDir . '/router.php';

/*
 * Generated rather than committed. It is a test fixture for a server that does
 * not exist in production, and a committed router.php at a path reachable from
 * the document root would be one more thing to forget to lock down.
 */
file_put_contents($routerPath, <<<'ROUTER'
<?php

declare(strict_types=1);

/*
 * Contract-suite router. Two jobs, both narrowly scoped:
 *
 *   1. Mark the request as HTTPS. The suite speaks plaintext HTTP to
 *      localhost, and Kernel requires TLS. The TLS deployment itself is
 *      asserted by bin/healthcheck.php against the real .htaccess, so stubbing
 *      it here does not duplicate or weaken that check.
 *
 *   2. Hand the real public_html file back to the server, so the request is
 *      served by the same shim that runs in production. Returning false is
 *      what makes the built-in server execute the .php file rather than
 *      serve it as source.
 */

$_SERVER['HTTPS'] = 'on';

/*
 * Point configuration at the suite's copy of the environment file. Without
 * this the child process boots against private_storage/.env while the parent
 * runs against --env, so the fixtures are written to one database and the
 * application reads another. Every signed request then fails authentication
 * with UNKNOWN_DEVICE, which reads exactly like an application bug and is not
 * one. Config::boot() is called before the app is loaded so the whole request
 * sees it.
 */
$envFile = (string) getenv('FP_ENV_FILE');

if ($envFile !== '') {
    require_once getenv('FP_DOCROOT') . '/../private_storage/app/bootstrap.php';
    \FieldPulse\Config\Config::boot($envFile);
}

$docRoot = (string) getenv('FP_DOCROOT');
$path    = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$target  = $docRoot . $path;

// Contain path traversal: the resolved target must stay under the document root.
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

/** Ask the OS for a free port, then release it for the server to bind. */
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

/*
 * proc_open rather than exec/sh -c: the arguments carry a Windows path with a
 * drive letter and backslashes, and every shell between here and PHP would get
 * a chance to reinterpret them.
 */
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']];

$serverProcess = proc_open(
    [
        PHP_BINARY,
        '-d', 'post_max_size=16M',
        '-d', 'upload_max_filesize=16M',
        '-S', '127.0.0.1:' . $port,
        '-t', str_replace('\\', '/', $publicRoot),
        str_replace('\\', '/', $routerPath),
    ],
    $descriptors,
    $pipes,
    $repoRoot,
    ['FP_DOCROOT' => str_replace('\\', '/', $publicRoot)]
        + ['FP_ENV_FILE' => $envFile !== null ? str_replace('\\', '/', $envFile) : '']
        + getenv()
);

if (!is_resource($serverProcess)) {
    Cli::fail('Could not start the built-in web server.');
    exit(1);
}

$stopServer = static function () use ($serverProcess, $workDir, $keep): void {
    if (is_resource($serverProcess)) {
        // proc_terminate only reaches the server itself; close the stdin pipe
        // first so the built-in server's own shutdown path can run.
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }

    // --keep leaves the scratch directory in place. A 500 from the built-in
    // server is only diagnosable from its stderr, and deleting that before the
    // failure has been read turns a one-line fix into a guessing game.
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

// Wait for the listener, not for a fixed sleep. A fixed sleep is either slower
// than necessary or flaky, and flaky only on a loaded CI box, i.e. rarely and
// expensively.
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
 * Signed multipart request
 * --------------------------------------------------------------------------- */

/**
 * POST a real multipart submission and return [status, decodedBody, rawBody].
 *
 * Signs exactly the bytes placed in the `payload` part, which is what
 * Http\Request::signedBody() reads back out of the form, so the signature
 * covers what the server verifies without the suite having to model the
 * multipart boundary the encoder chose.
 *
 * @param array<string,mixed>  $payload
 * @param array<int,string>    $extraPayloadFields Raw parts, for negative tests
 *                                               that need a field JSON cannot
 *                                               express cleanly.
 * @return array{0:int,1:mixed,2:string}
 */
$postSubmission = static function (
    array $principal,
    string $payloadJson,
    string $jpegPath,
    string $token = null,
    array $extraPayloadFields = [],
    string $path = '/api/v1/submit.php'
) use ($baseUrl, $appUrl, $workDir): array {
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
        throw new RuntimeException('The contract suite could not sign its own request.');
    }

    $ch = curl_init($baseUrl . $path);

    $fields = [
        /*
         * A plain string, deliberately NOT a CURLFile.
         *
         * Giving this part a filename is what makes PHP classify it as an
         * upload and put it in $_FILES instead of $_POST, and
         * Http\Request::signedBody() reads $_POST['payload']. The field would
         * be present in the request and still invisible to the server, which
         * is exactly the "multipart is missing the payload field" 422 a real
         * integrator hits when they wrap it as a file.
         */
        'payload' => $payloadJson,
        'file'    => new CURLFile($jpegPath, 'image/jpeg', 'capture.jpg'),
    ];

    foreach ($extraPayloadFields as $name => $value) {
        $fields[$name] = $value;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
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
        throw new RuntimeException('The contract suite could not reach the server: ' . $err);
    }

    return [$status, json_decode((string) $raw, true), (string) $raw];
};

/** A payload that satisfies every rule the controller enforces. */
$validPayload = static function (string $jpegPath, array $overrides = []): string {
    return json_encode(array_merge([
        'submission_uuid' => Uuid::v4(),
        'count_claimed'   => 3,
        'captured_at'     => gmdate('c'),
        'latitude'        => 26.7000,
        'longitude'       => 78.9000,
        'accuracy_m'      => 12.5,
        'file_sha256'     => hash_file('sha256', $jpegPath),
    ], $overrides), JSON_THROW_ON_ERROR);
};

/* ---------------------------------------------------------------------------
 * Cleanup
 * --------------------------------------------------------------------------- */

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    $stopServer,
    $storageDirs,
    $baselineFiles
): void {
    $stopServer();

    // verify() moves each judged file out of quarantine and renames it to a
    // fresh UUID, so sweeping for the run tag afterwards would match nothing.
    // Diffing against the pre-run snapshot removes exactly what this run
    // created and cannot touch a file that was already there.
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
        foreach ($createdAgentIds as $agentId) {
            foreach (Connection::fetchAll(
                'SELECT id FROM submissions WHERE agent_id = :id',
                ['id' => $agentId]
            ) as $row) {
                $submissionId = (int) $row['id'];

                // audit rows for a disposition are written with actor_agent_id
                // NULL (the worker is not an agent), so they have to be matched
                // on the submission they describe rather than on the reviewer.
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
 * Tests
 * --------------------------------------------------------------------------- */

$t = new TestRunner($filter);

$agentAId  = $makeAgent('alpha');
$agentBId  = $makeAgent('bravo');
$principalA = $makeDevice($agentAId);
$principalB = $makeDevice($agentBId);

$jpegPath = $workDir . '/capture.jpg';
file_put_contents($jpegPath, $makeJpeg(bin2hex(random_bytes(3))));

/* -- 202 ------------------------------------------------------------------- */

$t->group('202 — first acceptance');

$t->test('first submission of an unknown UUID returns 202 QUEUED', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA) {
    [$status, $body] = $postSubmission($principalA, $validPayload($jpegPath), $jpegPath);

    $t->assertSame(202, $status, 'a new submission is accepted asynchronously');
    $t->assertSame('QUEUED', $body['data']['status'] ?? null, 'the body reports QUEUED');
    $t->assertMatches(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        (string) ($body['data']['submission_uuid'] ?? ''),
        'the response echoes the submitted UUID'
    );
    $t->assertTrue(
        is_int($body['data']['submission_id'] ?? null),
        'submission_id is a JSON number, not a string: '
        . var_export($body['data']['submission_id'] ?? null, true)
    );
    $t->assertContains('/api/v1/submission.php', (string) ($body['data']['self'] ?? ''), 'self links to the submission');
});

$t->test('the 202 row is owned by the authenticated agent, not the request', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA, $agentAId, $agentBId) {
    $uuid = Uuid::v4();
    $payload = json_decode($validPayload($jpegPath, ['submission_uuid' => $uuid]), true);

    // A client naming its own owner must not be able to, and the assertion
    // checks the row rather than the response, because a server that honoured
    // the claim would still answer 202.
    $payload['agent_id'] = $agentBId;

    [$status] = $postSubmission($principalA, json_encode($payload, JSON_THROW_ON_ERROR), $jpegPath);

    $t->assertSame(422, $status, 'a payload carrying agent_id is rejected, not ignored');

    $owner = Connection::fetchValue(
        'SELECT agent_id FROM submissions WHERE submission_uuid = :u',
        ['u' => $uuid]
    );

    $t->assertNull($owner, 'nothing was persisted under the smuggled agent_id');
});

/* -- 200 ------------------------------------------------------------------- */

$t->group('200 — idempotent replay');

$t->test('the same agent re-sending the same UUID returns 200 ALREADY_RECEIVED and the same id', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA) {
    $uuid = Uuid::v4();
    $payload = $validPayload($jpegPath, ['submission_uuid' => $uuid]);

    [$status1, $body1] = $postSubmission($principalA, $payload, $jpegPath);
    $t->assertSame(202, $status1, 'the first send is accepted');

    [$status2, $body2] = $postSubmission($principalA, $payload, $jpegPath);

    $t->assertSame(200, $status2, 'the retry is not re-queued');
    $t->assertSame('ALREADY_RECEIVED', $body2['data']['status'] ?? null, 'the body reports ALREADY_RECEIVED');
    $t->assertSame(
        $body1['data']['submission_id'],
        $body2['data']['submission_id'] ?? null,
        'the retry returns the original submission_id, not a second one'
    );
    $t->assertSame($uuid, $body2['data']['submission_uuid'] ?? null, 'the retry echoes the same UUID');
    $t->assertSame(
        true,
        $body2['data']['idempotent_replay'] ?? null,
        'the retry is marked as a replay'
    );

    $t->assertSame(
        1,
        (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM submissions WHERE submission_uuid = :u',
            ['u' => $uuid]
        ),
        'the retry did not create a second row'
    );
});

/* -- 409 ------------------------------------------------------------------- */

$t->group('409 — idempotency conflict');

$t->test('another agent sending a UUID that already exists gets 409 and learns nothing', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA, $principalB) {
    $uuid = Uuid::v4();

    // Signed over the *same bytes* as the first request, so the 409 is reached
    // through the idempotency check and not by tripping signature verification
    // first. Otherwise this would pass for the wrong reason.
    $payload = $validPayload($jpegPath, ['submission_uuid' => $uuid]);

    [$status1] = $postSubmission($principalA, $payload, $jpegPath);
    $t->assertSame(202, $status1, 'agent A creates the submission');

    [$status2, $body2, $raw2] = $postSubmission($principalB, $payload, $jpegPath);

    $t->assertSame(409, $status2, 'agent B is refused');
    $t->assertSame('IDEMPOTENCY_CONFLICT', $body2['error']['code'] ?? null, 'the error code is IDEMPOTENCY_CONFLICT');

    // The real risk in a 409 is a handler that returns the existing row
    // "helpfully" and thereby confirms another agent's submission exists.
    $t->assertTrue(
        !isset($body2['data']),
        'a conflict must not return a data envelope'
    );
    $t->assertTrue(
        !str_contains($raw2, (string) Connection::fetchValue(
            'SELECT file_sha256 FROM submissions WHERE submission_uuid = :u',
            ['u' => $uuid]
        )),
        'the conflict response does not echo the existing submission'
    );
});

/* -- Ownership never comes from the wire ---------------------------------- */

$t->group('client-supplied ownership');

$t->test('agent_id as a JSON payload field is rejected', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA) {
    $payload = json_decode($validPayload($jpegPath), true);
    $payload['agent_id'] = 1;

    [$status, $body] = $postSubmission($principalA, json_encode($payload, JSON_THROW_ON_ERROR), $jpegPath);

    $t->assertSame(422, $status, 'the request is refused as malformed');
    $t->assertTrue(isset($body['error']), 'the response uses the error envelope');
});

$t->test('IMEI as a JSON payload field is rejected', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA) {
    $payload = json_decode($validPayload($jpegPath), true);
    $payload['imei'] = '352099001761481';

    [$status] = $postSubmission($principalA, json_encode($payload, JSON_THROW_ON_ERROR), $jpegPath);

    $t->assertSame(422, $status, 'the request is refused as malformed');
});

/* -- Validation the client also branches on -------------------------------- */

$t->group('validation');

$t->test('a payload whose file_sha256 does not match the upload is refused', function () use ($t, $postSubmission, $validPayload, $jpegPath, $principalA) {
    $payload = $validPayload($jpegPath, ['file_sha256' => hash('sha256', 'not the file')]);

    [$status] = $postSubmission($principalA, $payload, $jpegPath);

    $t->assertTrue(
        $status === 422 || $status === 400,
        'a hash mismatch is a client error, got ' . $status
    );
});

/* -- Documentation --------------------------------------------------------- */

$t->group('documentation');

$t->test('docs/API.md documents the route and all three submission codes', function () use ($t, $repoRoot) {
    $docPath = $repoRoot . '/docs/API.md';
    $t->assertTrue(is_file($docPath), 'docs/API.md exists');

    $doc = (string) file_get_contents($docPath);

    $t->assertContains('/api/v1/submit.php', $doc, 'the submission route is documented');
    $t->assertContains('202', $doc, '202 is documented');
    $t->assertContains('ALREADY_RECEIVED', $doc, 'the replay status is documented');
    $t->assertContains('IDEMPOTENCY_CONFLICT', $doc, 'the conflict code is documented');

    // The client parses this exact key, and a string id would be silently
    // stored as one by JSON.parse-free code paths.
    $t->assertContains('submission_id', $doc, 'submission_id is documented');
});

$t->test('docs/API.md does not still claim a 201 is the submit success code', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');

    /*
     * Scoped to the submission section rather than the whole document.
     *
     * The rule is about submit.php: its success is 202/200, and a stale 201 there
     * tells a client integrator to build the wrong branch. It is not a claim that
     * no endpoint in the product may ever answer 201 — the admin directory's POST
     * creates an account and does exactly that — so the scan is over the section
     * that owns the invariant, not over every byte of the file.
     */
    $start = strpos($doc, '### POST /api/v1/submit.php');
    $end   = strpos($doc, '### GET /api/v1/submission.php');
    $t->assertTrue($start !== false && $end !== false && $end > $start, 'the submission section is present');

    $section = substr($doc, (int) $start, (int) $end - (int) $start);

    $t->assertTrue(
        preg_match('/(^|[^0-9])201([^0-9]|$)/m', $section) !== 1,
        'a stale 201 in the submission section would tell a client integrator to build the wrong branch'
    );
});

/*
 * The four assertions below exist because each one was actually wrong in this
 * document at some point, and each was wrong in a way that a reader could not
 * detect by reading it: the prose was confident and internally consistent, just
 * not about the code. That is the failure mode prose has and an assertion has
 * not — so each is now pinned against the source it describes rather than
 * against a copy of itself.
 */

$t->test('docs/API.md names the signature headers the code reads', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');

    foreach (['X-Device-UUID', 'X-Request-Timestamp', 'X-Request-Nonce', 'X-Request-Signature'] as $header) {
        $t->assertContains($header, $doc, $header . ' is documented');
    }

    /*
     * The shorter forms are what the documentation used to claim. They are not
     * merely undocumented extras: an unrecognised header is treated as absent,
     * so a client written from the old text sends no signature it thinks it
     * sent, and gets 401 with nothing to explain it.
     */
    foreach (['X-Device-Id', 'X-Timestamp:', 'X-Nonce:', 'X-Signature:'] as $stale) {
        $t->assertTrue(
            strpos($doc, $stale) === false,
            'docs/API.md still documents the non-existent header ' . $stale
        );
    }

    // And the names must be the ones the request actually authenticates with.
    $authenticator = (string) file_get_contents($repoRoot . '/private_storage/app/Security/Authenticator.php');
    foreach (['X-Device-UUID', 'X-Request-Timestamp', 'X-Request-Nonce', 'X-Request-Signature'] as $header) {
        $t->assertContains($header, $authenticator, $header . ' is the header the code reads');
    }
});

$t->test('docs/API.md nests the error field the way the envelope emits it', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');

    /*
     * Response::error() puts client-safe details under error.details, and
     * src/api/client.ts reads body.error.details?.field. A document showing
     * "field" beside "code" teaches an integrator to read a key that is never
     * there, which surfaces as a form that cannot say which input was wrong.
     */
    $t->assertContains('"details"', $doc, 'the envelope documents a details object');

    $t->assertTrue(
        preg_match('/"field"\s*:/', $doc) === 1
        && preg_match('/"details"\s*:\s*\{[^}]*"field"\s*:/s', $doc) === 1,
        'the example nests field inside details'
    );

    $response = (string) file_get_contents($repoRoot . '/private_storage/app/Http/Response.php');
    $t->assertContains("\$body['error']['details']", $response, 'the envelope really does nest details');
});

$t->test('every route docs/API.md lists exists on disk', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');
    $apiDir = $repoRoot . '/public_html/api/v1';

    /*
     * A path in the table that is not a file is a 404 for whoever writes the
     * client, and there is no extensionless rewrite to rescue them: .htaccess
     * sends anything under api/ straight to the API. So this reads the routes
     * out of the table and checks each one against the filesystem, which is the
     * only source that cannot drift silently.
     */
    preg_match_all('/^\| (?:GET|POST) \| `([^`]+)`/m', $doc, $matches);

    $checked = 0;

    foreach ($matches[1] as $path) {
        if (strpos($path, '/api/v1/') !== 0) {
            continue;
        }

        $file = $repoRoot . '/public_html' . explode('?', $path)[0];

        $t->assertTrue(
            is_file($file),
            'docs/API.md documents ' . $path . ', which is not a file (expected ' . $file . ')'
        );
        $checked++;
    }

    $t->assertTrue($checked >= 12, 'expected the full route table, checked ' . $checked);
});

$t->test('the review queue routes in the table are the ones the router serves', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');

    /*
     * The review queue moved into a directory and the docs kept naming the flat
     * file. That is the worst kind of staleness here: /api/v1/reviews.php was
     * never a real path, .htaccess sends everything under api/ to the API rather
     * than the SPA fallback, and the client would get a bare 404 for a screen
     * the documentation promised was operator-only.
     */
    $t->assertContains('/api/v1/reviews/index.php', $doc, 'the queue route is documented');
    $t->assertTrue(
        strpos($doc, '/api/v1/reviews.php') === false,
        'docs/API.md still lists the flat /api/v1/reviews.php, which does not exist'
    );

    // The path and the route name are two separate claims; both have to hold.
    $shim = (string) file_get_contents($repoRoot . '/public_html/api/v1/reviews/index.php');
    $t->assertContains("Kernel::handle('reviews.index')", $shim, 'the shim serves the documented path');

    $kernel = (string) file_get_contents($repoRoot . '/private_storage/app/Http/Kernel.php');
    $t->assertContains("'reviews.index'", $kernel, 'the kernel registers the route the shim names');
    $t->assertContains("'reviews.index'    => 'operator'", $kernel, 'and requires an operator');
});

$t->test('the reward routes are documented, shimmed, and gated as claimed', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');

    foreach (['self', 'index', 'decide'] as $leaf) {
        $t->assertContains(
            '/api/v1/rewards/' . $leaf . '.php',
            $doc,
            'the rewards/' . $leaf . ' route is documented'
        );

        $shim = (string) file_get_contents($repoRoot . '/public_html/api/v1/rewards/' . $leaf . '.php');
        $t->assertContains(
            "Kernel::handle('rewards." . $leaf . "')",
            $shim,
            'the rewards/' . $leaf . ' shim serves the documented path'
        );
    }

    /*
     * The auth split is the security claim of this feature, so it is asserted
     * here rather than trusted: an agent may read their own entitlement and
     * nothing else, while reading a whole period or moving any entitlement to
     * APPROVED/PAID is operator work. If someone later relaxes one of these the
     * documentation above would be quietly wrong.
     */
    $kernel = (string) file_get_contents($repoRoot . '/private_storage/app/Http/Kernel.php');

    $t->assertMatches("/'rewards\.self'\s*=>\s*'bearer'/", $kernel, 'self is bearer');
    $t->assertMatches("/'rewards\.index'\s*=>\s*'operator'/", $kernel, 'index is operator-only');
    $t->assertMatches("/'rewards\.decide'\s*=>\s*'operator'/", $kernel, 'decide is operator-only');

    // The cutoff rule is the contract of the feature. It lives verbatim in the
    // rewards documentation so a later reader cannot "fix" the behaviour
    // without contradicting a sentence they had to read.
    $rewardsDoc = $repoRoot . '/docs/REWARDS.md';
    $t->assertTrue(is_file($rewardsDoc), 'docs/REWARDS.md exists');

    $t->assertContains(
        'At period close, only the frozen verified weekly ranking determines reward eligibility and rank; '
            . 'later changes to live performance summaries do not change an already published reward entitlement.',
        (string) file_get_contents($rewardsDoc),
        'docs/REWARDS.md states the cutoff rule verbatim'
    );
});

$t->test('the admin routes are documented, shimmed, and gated admin-only', function () use ($t, $repoRoot) {
    $doc = (string) file_get_contents($repoRoot . '/docs/API.md');

    foreach (['/api/v1/admin/agents.php', '/api/v1/admin/agent.php'] as $path) {
        $t->assertContains($path, $doc, 'the ' . $path . ' route is documented');
    }

    /*
     * The directory and the per-account action are two different files; both must
     * resolve to the router. A path under api/ with no file is a bare 404 — not
     * the SPA fallback, and not a redirect — so a documented path the shim does
     * not serve is a dead screen for whoever builds the client.
     */
    $shim = (string) file_get_contents($repoRoot . '/public_html/api/v1/admin/agents.php');
    $t->assertContains("Kernel::handle('admin.agents')", $shim, 'the directory shim serves the documented path');

    $shim = (string) file_get_contents($repoRoot . '/public_html/api/v1/admin/agent.php');
    $t->assertContains("Kernel::handle('admin.agent')", $shim, 'the action shim serves the documented path');

    /*
     * The point of the surface is that it is narrower than "operator": a
     * supervisor runs the review queue and the rewards ledger, but only an admin
     * mints, demotes or retires accounts. If either route were downgraded to
     * 'operator', the prose above would still read correctly while the code let a
     * supervisor escalate, so the gate is asserted rather than trusted.
     */
    $kernel = (string) file_get_contents($repoRoot . '/private_storage/app/Http/Kernel.php');

    $t->assertMatches("/'admin\.agents'\s*=>\s*'admin'/", $kernel, 'admin.agents requires admin');
    $t->assertMatches("/'admin\.agent'\s*=>\s*'admin'/", $kernel, 'admin.agent requires admin');
    $t->assertContains('Administrator access is required.', $kernel, 'the role gate refuses with the documented 403');

    // The refusals an integrator meets must be the ones the documentation names.
    $controller = (string) file_get_contents($repoRoot . '/private_storage/app/Domain/AdminAgentController.php');
    $t->assertContains('STATE_CONFLICT', $controller, 'the self and last-admin guards report STATE_CONFLICT');
    $t->assertContains('UNKNOWN_AGENT', $controller, 'an unknown target is UNKNOWN_AGENT');
    $t->assertContains('IDEMPOTENCY_CONFLICT', $controller, 'a duplicate code or username is IDEMPOTENCY_CONFLICT');
});

/* ---------------------------------------------------------------------------
 * Exit
 * --------------------------------------------------------------------------- */

Cli::heading('FieldPulse submission contract (server ' . $serverVersion . ', database ' . $dbName . ')');

$code = $t->run($verbose);

exit($code);
