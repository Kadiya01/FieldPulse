<?php

declare(strict_types=1);

/**
 * Authentication and device-registration contract suite (Phase 2).
 *
 *   php private_storage/bin/auth.php
 *   php private_storage/bin/auth.php --verbose
 *   php private_storage/bin/auth.php --filter=refresh
 *   php private_storage/bin/auth.php --keep
 *
 * WHY THIS EXISTS, AND WHY IT IS SEPARATE FROM contract.php
 *
 * The auth flow is two-legged, and the second leg cannot be reached by any
 * in-process test: login returns a *bootstrap* session bound to no device, and
 * the client trades that session plus a browser-generated key pair for a
 * device-bound one. Only the client holds the private key, so the exchange is
 * unobservable unless the client is modelled as a real HTTP client — which is
 * what this suite is. integration.php calls controllers in-process and cannot
 * see a Set-Cookie header, a 401 status, or the fact that the refresh token
 * never left the server as anything but a cookie.
 *
 * The specific defects this suite exists to catch, each of which is plausible
 * here and none of which any other suite would notice:
 *
 *   - a bootstrap token accepted on a signed route, which would mean a username
 *     and password alone were enough to upload
 *   - a device-bound token accepted for device registration, which would make
 *     the pairing policy advisory
 *   - refresh rotation not rotating, or reuse not being detected
 *   - a password or key reaching a response body
 *   - IMEI still accepted anywhere in the login or pairing path
 *
 * It runs against a real server over a real socket, with real P-256 keys, real
 * device rows, real signed JWTs and real ECDSA signatures. Nothing about
 * authentication is stubbed; see Testing\HttpServer for the single exception
 * (HTTPS, which healthcheck asserts against the deployed .htaccess instead).
 *
 * It also asserts the negative the audit cares about most: that a page reload
 * and a second tab are served by the refresh cookie alone. If a future change
 * starts storing the access token in localStorage to make that easier, the
 * storage test in the frontend suite is what fails.
 *
 * SAFETY
 *
 * Refuses to run against APP_ENV=production or a database that does not look
 * like a test or dev database, unless --force is given. Every row it creates is
 * tracked by primary key and deleted on exit, and every agent is named with the
 * run tag so leftovers are findable.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Database\RefreshTokenRepository;
use FieldPulse\Http\ErrorCode;
use FieldPulse\Security\Credentials;
use FieldPulse\Security\DeviceStatus;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\PairingCode;
use FieldPulse\Support\Clock;
use FieldPulse\Support\Str;
use FieldPulse\Support\Uuid;
use FieldPulse\Testing\HttpServer;
use FieldPulse\Testing\TestRunner;

$argv    = Cli::argv();
$force   = Cli::hasFlag($argv, 'force');
$keep    = Cli::hasFlag($argv, 'keep');
$filter  = Cli::option($argv, 'filter');
$verbose = Cli::hasFlag($argv, 'verbose');

Config::boot(Cli::option($argv, 'env'));

$config        = Config::instance();
$dbName        = $config->str('db.name');
$appEnv        = $config->str('app.env');
$appUrl        = $config->str('app.url');
$cookieName    = $config->str('security.cookie_name');
$serverVersion = (string) Connection::fetchValue('SELECT VERSION()');

/* ---------------------------------------------------------------------------
 * Safety
 * --------------------------------------------------------------------------- */

if ($appEnv === 'production' && !$force) {
    Cli::fail('Refusing to run the auth suite against APP_ENV=production.');
    exit(1);
}

if (!preg_match('/(test|dev)/i', $dbName) && !$force) {
    Cli::fail('Refusing to run the auth suite against database "' . $dbName
        . '". Its name does not look like a test or development database.');
    exit(1);
}

$t = new TestRunner($filter);

/* ---------------------------------------------------------------------------
 * Fixtures
 * --------------------------------------------------------------------------- */

$runTag = 'au' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

$agents  = new AgentRepository();
$devices = new DeviceRepository();

$createdAgentIds      = [];
$createdDeviceIds     = [];
$createdSubmissionIds = [];

$storageBaseline = HttpServer::snapshotStorage();

/**
 * An agent with real credentials.
 *
 * The password goes through the same Credentials::hash() the operator CLI uses,
 * so the suite cannot pass against a hash the login path would never accept.
 *
 * @return array{id:int,agentCode:string,username:string,password:string,imei:?string,row:array<string,mixed>}
 */
$makeAgent = static function (string $label, string $password = 'correct-horse-battery-staple') use ($runTag, $agents, &$createdAgentIds): array {
    $agentCode = $runTag . '-' . $label;

    /*
     * The username is run-scoped, not just the agent_code. Usernames are
     * globally unique, so a run that dies before its shutdown handler cleans up
     * would otherwise leave 'alice' behind and make every subsequent run fail on
     * a duplicate key — a failure that has nothing to do with the code under
     * test. Tying the username to the run tag makes a crashed run harmless and
     * its leftovers obviously attributable.
     */
    $username = $runTag . '.' . strtolower($label);

    $id = $agents->create($agentCode, 'Auth ' . ucfirst($label), null);
    $createdAgentIds[] = $id;

    $agents->setCredentials($id, $username, Credentials::hash($password));

    $row = $agents->findById($id);

    if ($row === null) {
        throw new RuntimeException('Auth fixture could not re-read its own agent row.');
    }

    return [
        'id'        => $id,
        'agentCode' => $agentCode,
        'username'  => $username,
        'password'  => $password,
        'imei'      => $row['imei'] === null ? null : (string) $row['imei'],
        'row'       => $row,
    ];
};

/**
 * An agent with no devices, for tests that need codeRequired() to be true.
 *
 * The pairing tests cannot share a fixture agent. Under FIRST_DEVICE_ONLY the
 * requirement is decided by whether the agent already has a device, so reusing
 * an agent that an earlier test enrolled turns "a wrong code is refused" into
 * "no code was needed at all" — a 200 that looks like a pass and asserts
 * nothing. Each of these gets a unique username, so the rate limiter still sees
 * a distinct identifier per test.
 */
$freshAgent = static function (string $label) use ($makeAgent, $runTag): array {
    return $makeAgent($label . '-' . substr(bin2hex(random_bytes(3)), 0, 6));
};

/**
 * A P-256 key pair, standing in for the browser's.
 *
 * @return array{privatePem:string,privateKey:\OpenSSLAsymmetricKey,public:array<string,mixed>}
 */
$makeKeyPair = static function (): array {
    $pair = Jwk::generateKeyPair();
    $pem  = '';

    if (!openssl_pkey_export($pair['private'], $pem) || $pem === '') {
        throw new RuntimeException('Could not export the fixture private key.');
    }

    $private = openssl_pkey_get_private($pem);

    if ($private === false) {
        throw new RuntimeException('Could not re-read the fixture private key.');
    }

    return ['privatePem' => $pem, 'privateKey' => $private, 'public' => $pair['public']];
};

/** A usable one-time pairing code row for an agent. */
$issueCode = static function (int $agentId): string {
    $code = PairingCode::random();

    Connection::execute(
        "INSERT INTO pairing_codes (agent_id, code_hash, attempts, max_attempts, expires_at, created_by, created_at)
         VALUES (:agent_id, :hash, 0, 5, :expires_at, 'auth_suite', UTC_TIMESTAMP())",
        [
            'agent_id'   => $agentId,
            'hash'       => PairingCode::hash($code),
            'expires_at' => Clock::sql(Clock::shift(1800)),
        ]
    );

    return $code;
};

/* ---------------------------------------------------------------------------
 * Server
 * --------------------------------------------------------------------------- */

/*
 * Shared by the main server AND every policy-variant server.
 *
 * Each HttpServer::start() writes its own copy of .env containing only the
 * overrides it is given, so a variant that asked only for a different pairing
 * policy would silently fall back to the deployment's LOGIN_RATE_LIMIT of 10.
 * The limiter counts per endpoint and per IP in the shared database, and the
 * main suite has already spent that window from 127.0.0.1, so every variant
 * login would come back 429 with no token and the policy test would assert
 * nothing.
 */
$serverOverrides = [
    'LOGIN_RATE_LIMIT'    => '100000',
    'LOGIN_RATE_WINDOW'   => '900',
    'REGISTER_RATE_LIMIT' => '100000',
];

try {
    /*
     * The login rate limit is 10 attempts per 15 minutes, per username AND per
     * IP. Every request here arrives from 127.0.0.1 and most reuse one of two
     * fixture usernames, so a full run trips it within the first few tests and
     * the rest fail RATE_LIMITED — which says nothing about the behaviour under
     * test.
     *
     * The threshold is raised, not the limiter disabled: the limiter code still
     * runs on every request, so a regression in how it counts is still visible.
     * These land in a copy of .env in the scratch directory, so a run that dies
     * before cleanup cannot leave the deployment's configuration modified.
     */
    $server = HttpServer::start($keep, $serverOverrides);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}

$baseUrl = $server->baseUrl;
$jarDir  = sys_get_temp_dir() . '/fieldpulse-auth-jar-' . $runTag;

if (!is_dir($jarDir)) {
    @mkdir($jarDir, 0o777, true);
}

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    &$createdSubmissionIds,
    $server,
    $jarDir,
    $storageBaseline
): void {
    /*
     * Database teardown first, and every statement in its own try.
     *
     * This used to run as one block, so the first foreign-key violation aborted
     * the rest: a single submission referencing an agent meant no agents were
     * deleted at all, and the suite silently leaked its whole fixture set — 271
     * agents across a handful of runs, none of it visible in the results. A
     * cleanup routine that can lose everything to one bad row is not a cleanup
     * routine, so each delete is isolated and failures are counted rather than
     * swallowed.
     *
     * The order is the foreign-key graph, children before parents:
     *   agents    <- agent_performance_summary, agent_sites, audit_logs,
     *                devices, pairing_codes, refresh_tokens,
     *                submission_verifications, submissions
     *   devices   <- pairing_codes, refresh_tokens, request_nonces, submissions
     *   submissions <- processing_jobs, submission_verifications
     * Deleting agents first is what produced the original silent leak, so the
     * rows are removed in the reverse of that list.
     */
    $failures = 0;

    $delete = static function (string $sql, array $params) use (&$failures): void {
        try {
            Connection::execute($sql, $params);
        } catch (Throwable) {
            $failures++;
        }
    };

    foreach ($createdSubmissionIds as $submissionId) {
        $delete('DELETE FROM processing_jobs WHERE submission_id = :id', ['id' => $submissionId]);
        $delete('DELETE FROM submission_verifications WHERE submission_id = :id', ['id' => $submissionId]);
        $delete('DELETE FROM submissions WHERE id = :id', ['id' => $submissionId]);
    }

    foreach ($createdDeviceIds as $deviceId) {
        /*
         * Everything referencing a device, in child-first order. refresh_tokens
         * and pairing_codes also hang off the agent, so they are cleared again
         * in the agent loop below — deleting a device without clearing its own
         * children first is what made 30 of them survive per run.
         */
        $delete('DELETE FROM request_nonces WHERE device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM refresh_tokens WHERE device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM pairing_codes WHERE consumed_by_device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM submissions WHERE device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM devices WHERE id = :id', ['id' => $deviceId]);
    }

    foreach ($createdAgentIds as $agentId) {
        /*
         * Agent-scoped, so this cannot be defeated by a row the suite created
         * without going through a tracked fixture. Anything that hangs off this
         * agent's devices or submissions is removed first, because an agent with
         * one surviving device cannot be deleted at all.
         */
        $delete(
            'DELETE FROM request_nonces WHERE device_id IN (SELECT id FROM devices WHERE agent_id = :a)',
            ['a' => $agentId]
        );
        $delete(
            'DELETE FROM refresh_tokens WHERE device_id IN (SELECT id FROM devices WHERE agent_id = :a)',
            ['a' => $agentId]
        );
        $delete(
            'DELETE FROM pairing_codes WHERE consumed_by_device_id IN (SELECT id FROM devices WHERE agent_id = :a)',
            ['a' => $agentId]
        );
        $delete('DELETE FROM processing_jobs WHERE submission_id IN (SELECT id FROM submissions WHERE agent_id = :a)', ['a' => $agentId]);
        $delete('DELETE FROM submission_verifications WHERE submission_id IN (SELECT id FROM submissions WHERE agent_id = :a)', ['a' => $agentId]);
        $delete('DELETE FROM submission_verifications WHERE reviewed_by_agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM submissions WHERE agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM devices WHERE agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM refresh_tokens WHERE agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM pairing_codes WHERE agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM agent_performance_summary WHERE agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM agent_sites WHERE agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM audit_logs WHERE actor_agent_id = :a', ['a' => $agentId]);
        $delete('DELETE FROM agents WHERE id = :a', ['a' => $agentId]);
    }

    if ($failures > 0) {
        fwrite(
            STDERR,
            "\nWARNING: the auth suite left " . $failures . " row(s) behind; "
            . 'search for agent codes beginning with the run tag prefix.' . "\n"
        );
    }

    // Server and scratch teardown last, and separately: neither may be able to
    // prevent the database cleanup above from running.
    try {
        $server->stop();
        HttpServer::removeNewStorage($storageBaseline);

        foreach ((array) glob($jarDir . '/*') as $jar) {
            @unlink((string) $jar);
        }

        @rmdir($jarDir);
    } catch (Throwable) {
        // Nothing useful to do at shutdown; the scratch directory is in the
        // system temp directory and is named for this run.
    }
});

/** A fresh cookie jar path, one per simulated browser. */
$newJar = static function (string $label) use ($jarDir): string {
    return $jarDir . '/' . $label . '-' . bin2hex(random_bytes(4)) . '.txt';
};

/**
 * Log in, returning the bootstrap token and the refresh cookie value.
 *
 * The cookie is carried explicitly rather than through cURL's jar: the server
 * sets it Secure, and cURL refuses to send a Secure cookie back over the
 * plaintext loopback connection, so a jar-driven refresh would report "no
 * refresh token was presented" and assert nothing. See
 * HttpServer::cookieValue().
 *
 * @param  ?string $base Overridden for the policy-variant servers, which run
 *                       on their own port with their own config.
 * @return array{token:?string,cookie:?string,result:array<string,mixed>}
 */
$login = static function (array $agent, ?string $password = null, ?string $base = null) use ($baseUrl, $appUrl, $cookieName): array {
    $result = HttpServer::sendJson(
        $base ?? $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $agent['username'], 'password' => $password ?? $agent['password']]
    );

    $token = is_string($result['body']['access_token'] ?? null) ? $result['body']['access_token'] : null;

    return [
        'token'  => $token,
        'cookie' => HttpServer::cookieValue($result, $cookieName),
        'result' => $result,
    ];
};

/**
 * The whole client half: log in, then register a device.
 *
 * Every test needing a signed request goes through here, so none of them can
 * accidentally assert against a session obtained some other way.
 *
 * @return array{agentId:int,deviceId:int,deviceUuid:string,token:string,privateKey:\OpenSSLAsymmetricKey,privatePem:string,publicJwk:array<string,mixed>,cookie:?string}
 */
$enrol = static function (array $agent, ?string $pairingCode = null) use ($baseUrl, $appUrl, $login, $makeKeyPair, $issueCode, $cookieName, &$createdDeviceIds): array {
    $session = $login($agent);

    if ($session['token'] === null) {
        throw new RuntimeException('Auth fixture could not log in: ' . $session['result']['raw']);
    }

    $keys       = $makeKeyPair();
    $deviceUuid = Uuid::v4();

    $result = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        [
            'device_uuid'    => $deviceUuid,
            'public_key_jwk' => $keys['public'],
            'pairing_code'   => $pairingCode ?? $issueCode((int) $agent['id']),
        ],
        $session['token']
    );

    if ($result['status'] !== 200) {
        throw new RuntimeException('Auth fixture could not register: ' . $result['status'] . ' ' . $result['raw']);
    }

    $deviceId = (int) Connection::fetchValue(
        'SELECT id FROM devices WHERE device_uuid = :u',
        ['u' => $deviceUuid]
    );

    if ($deviceId > 0) {
        $createdDeviceIds[] = $deviceId;
    }

    return [
        'agentId'    => (int) $agent['id'],
        'deviceId'   => $deviceId,
        'deviceUuid' => $deviceUuid,
        'token'      => (string) $result['body']['access_token'],
        'privateKey' => $keys['privateKey'],
        'privatePem' => $keys['privatePem'],
        'publicJwk'  => $keys['public'],
        'cookie'     => HttpServer::cookieValue($result, $cookieName),
    ];
};

/**
 * One registration attempt, returning the HTTP result and the device row id.
 *
 * Deliberately does not throw on a non-200: the pairing tests assert on the
 * failure, and a fixture that insists on success cannot express "this must be
 * refused".
 *
 * @return array{result:array<string,mixed>,deviceId:int}
 */
$registerDevice = static function (array $session, array $body, &$createdDeviceIds) use ($baseUrl, $appUrl): array {
    $result = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        $body,
        (string) $session['token']
    );

    $deviceId = (int) Connection::fetchValue(
        'SELECT id FROM devices WHERE device_uuid = :u',
        ['u' => (string) $body['device_uuid']]
    );

    if ($deviceId > 0 && !in_array($deviceId, $createdDeviceIds, true)) {
        $createdDeviceIds[] = $deviceId;
    }

    return ['result' => $result, 'deviceId' => $deviceId];
};

/* -- Login ---------------------------------------------------------------- */

$t->group('login');

$alice = $makeAgent('alice');
$bob   = $makeAgent('bob');

$t->test('a correct username and password returns a bootstrap session', function (TestRunner $t) use ($baseUrl, $appUrl, $alice) {
    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $alice['username'], 'password' => $alice['password']]
    );

    $t->assertSame(200, $r['status'], 'a valid login succeeds');
    $t->assertTrue(is_string($r['body']['access_token'] ?? null), 'an access token is returned');
    $t->assertSame(false, $r['body']['device_bound'] ?? null, 'the session is explicitly not device-bound');
    $t->assertSame('device.register', $r['body']['next_step'] ?? null, 'the client is told what to do next');
    $t->assertTrue(
        !array_key_exists('refresh_token', $r['body']),
        'the refresh token must be in the cookie only, never the body'
    );
});

$t->test('the refresh token arrives as an HttpOnly, SameSite cookie', function (TestRunner $t) use ($baseUrl, $appUrl, $alice, $cookieName) {
    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $alice['username'], 'password' => $alice['password']]
    );

    $cookie = $r['headers']['set-cookie'][0] ?? '';

    $t->assertContains($cookieName . '=', $cookie, 'the cookie carries the configured name');
    $t->assertContains('HttpOnly', $cookie, 'HttpOnly, so script cannot read it');
    $t->assertContains('SameSite=Strict', $cookie, 'SameSite=Strict');
});

$t->test('a wrong password is indistinguishable from an unknown user', function (TestRunner $t) use ($baseUrl, $appUrl, $alice) {
    $wrong = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $alice['username'], 'password' => 'not-the-password']
    );

    $absent = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => 'no.such.user', 'password' => 'not-the-password']
    );

    $t->assertSame(401, $wrong['status'], 'a wrong password is 401');
    $t->assertSame(401, $absent['status'], 'an unknown user is 401');
    $t->assertSame(
        $wrong['body']['error']['code'] ?? null,
        $absent['body']['error']['code'] ?? null,
        'the error code must not distinguish the two'
    );
    $t->assertSame(
        $wrong['body']['error']['message'] ?? null,
        $absent['body']['error']['message'] ?? null,
        'nor the message'
    );
});

$t->test('an agent with no password hash cannot log in', function (TestRunner $t) use ($baseUrl, $appUrl, $runTag, $agents, &$createdAgentIds) {
    // username set, password_hash NULL: exactly the state migration 017 leaves
    // every pre-existing agent in until an operator sets a credential, and
    // reached here through the production API rather than raw SQL — set, then
    // revoke — so the test cannot describe a state the app cannot be in.
    $id = $agents->create($runTag . '-nohash', 'Auth No Hash', null);
    $createdAgentIds[] = $id;
    $agents->setCredentials($id, $runTag . '.nohash', Credentials::hash('a-password'));
    $agents->clearCredentials($id);

    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $runTag . '.nohash', 'password' => 'anything']
    );

    $t->assertSame(401, $r['status'], 'a NULL hash must not authenticate');
});

$t->test('login never accepts an IMEI where a username belongs', function (TestRunner $t) use ($baseUrl, $appUrl, $runTag, $agents, &$createdAgentIds) {
    // The old model identified the agent by IMEI and needed no password. If any
    // path still accepted that, the password would be decoration.
    //
    // The IMEI is run-scoped because agents.imei is globally unique, and a run
    // that dies before its shutdown handler would otherwise wedge every later
    // run on a duplicate key.
    //
    // Fifteen digits, derived from the run tag. An earlier version spliced the
    // tag in directly and so produced something like "35a3f9c20017614" — which
    // is not an IMEI at all, and which passed the "must contain a letter" rule
    // that is the very thing under test. Hex digits are not decimal digits.
    $imei = '35' . substr(str_pad((string) hexdec(substr($runTag, 2)), 13, '0', STR_PAD_LEFT), -13);
    $id   = $agents->create($runTag . '-imei', 'Auth Imei', $imei);
    $createdAgentIds[] = $id;
    $agents->setCredentials($id, $runTag . '.imeiuser', Credentials::hash('a-password'));

    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $imei, 'password' => 'a-password']
    );

    $t->assertSame(422, $r['status'], 'an IMEI is not a valid username, so it is refused as malformed, got ' . $r['raw']);
});

/* -- Device registration -------------------------------------------------- */

$t->group('device registration');

$t->test('a bootstrap session registers a device and returns a device-bound one', function (TestRunner $t) use ($baseUrl, $appUrl, $alice, $makeKeyPair, $issueCode) {
    $session = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $alice['username'], 'password' => $alice['password']]
    );

    $keys       = $makeKeyPair();
    $deviceUuid = Uuid::v4();

    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        [
            'device_uuid'    => $deviceUuid,
            'public_key_jwk' => $keys['public'],
            'pairing_code'   => $issueCode((int) $alice['id']),
        ],
        (string) $session['body']['access_token']
    );

    $t->assertSame(200, $r['status'], 'registration succeeds, got ' . $r['raw']);
    $t->assertSame(true, $r['body']['device_bound'] ?? null, 'the new session is device-bound');
    $t->assertSame($deviceUuid, $r['body']['device_uuid'] ?? null, 'the server echoes the device_uuid');
    $t->assertTrue(
        !array_key_exists('private_key', $r['body']) && !array_key_exists('public_key_jwk', $r['body']),
        'the server must not echo key material back'
    );
});

$t->test('registering a device retires the bootstrap session', function (TestRunner $t) use ($baseUrl, $appUrl, $login, $makeKeyPair, $freshAgent, $registerDevice, $issueCode, $cookieName, &$createdDeviceIds) {
    $agent   = $freshAgent('bootstrap-spent');
    $session = $login($agent);

    // The bootstrap cookie is captured BEFORE registration: the client keeps
    // using it until the bound cookie comes back, and holding the old value is
    // the only way to prove afterwards that it stopped working.
    $bootstrapCookie = (string) $session['cookie'];

    $attempt = $registerDevice($session, [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => $issueCode((int) $agent['id']),
    ], $createdDeviceIds);

    $t->assertSame(200, $attempt['result']['status'],
        'the device is bound, got ' . $attempt['result']['raw']);

    $t->assertSame(
        0,
        (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM refresh_tokens
              WHERE agent_id = :a AND device_id IS NULL AND revoked_at IS NULL',
            ['a' => (int) $agent['id']]
        ),
        'no unbound session survives the binding'
    );

    // And the old cookie is genuinely unusable, not merely marked as revoked.
    $replay = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/refresh.php',
        [], null, ['Cookie: ' . $cookieName . '=' . $bootstrapCookie]
    );

    $t->assertSame(401, $replay['status'],
        'the pre-registration cookie no longer refreshes, got ' . $replay['status']);
});

$t->test('registration without a credential is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $makeKeyPair) {
    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        ['device_uuid' => Uuid::v4(), 'public_key_jwk' => $makeKeyPair()['public']]
    );

    $t->assertSame(401, $r['status'], 'an unauthenticated registration is 401');
});

$t->test('a bootstrap token cannot be used on a device-bound route', function (TestRunner $t) use ($baseUrl, $appUrl, $alice, $makeKeyPair) {
    /*
     * The central claim of the bootstrap design. A bootstrap session is a real
     * signed JWT for a real agent, so any check of the form "is there a valid
     * bearer token" would accept it — and the agent could upload with nothing
     * but a username and password, no device key at all.
     */
    $session = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $alice['username'], 'password' => $alice['password']]
    );

    $r = HttpServer::sendSigned(
        $baseUrl, $appUrl,
        ['privateKey' => $makeKeyPair()['privateKey'], 'deviceUuid' => Uuid::v4()],
        'GET',
        '/api/v1/submission.php',
        '',
        (string) $session['body']['access_token']
    );

    $t->assertSame(401, $r['status'], 'a bootstrap token is refused here, got ' . $r['status']);
});

$t->test('a bootstrap token is refused even alongside a genuinely registered device key', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $login, $alice) {
    /*
     * The same claim from the other side, and the one a shortcut gets wrong.
     * Pairing a bootstrap token with an UNREGISTERED key would fail anyway, so
     * it proves nothing. This registers a real device, then takes a FRESH
     * bootstrap token for the same agent and signs with that device's real key:
     * the only thing wrong is that the token has no device claim. An
     * implementation that reads a missing claim as "any device" accepts this,
     * and then a username and password are enough to upload.
     */
    $enrolled = $enrol($alice);

    $session = $login($alice);

    $t->assertNotNull($session['token'], 'a second login yields another bootstrap session');

    $r = HttpServer::sendSigned(
        $baseUrl, $appUrl,
        ['privateKey' => $enrolled['privateKey'], 'deviceUuid' => $enrolled['deviceUuid']],
        'GET',
        '/api/v1/submission.php',
        '',
        (string) $session['token']
    );

    $t->assertSame(401, $r['status'], 'the real key does not rescue a bootstrap token, got ' . $r['status']);

    // Sanity: the very same key and device with a device-bound token does work,
    // so the 401 above is about the token and not about the key or the route.
    $ok = HttpServer::sendSigned(
        $baseUrl, $appUrl,
        ['privateKey' => $enrolled['privateKey'], 'deviceUuid' => $enrolled['deviceUuid']],
        'GET',
        '/api/v1/submission.php',
        '',
        $enrolled['token']
    );

    $t->assertNotSame(401, $ok['status'], 'the same key with a device-bound token is accepted');
});

/* -- Refresh -------------------------------------------------------------- */

$t->group('refresh');

$t->test('a refresh cookie mints a new access token with no password', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $cookieName) {
    $principal = $enrol($bob);

    $t->assertNotNull($principal['cookie'], 'registration issued a refresh cookie');

    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/refresh.php',
        [], null, HttpServer::cookieHeader([$cookieName => (string) $principal['cookie']])
    );

    $t->assertSame(200, $r['status'], 'refresh succeeds, got ' . $r['raw']);
    $t->assertTrue(is_string($r['body']['access_token'] ?? null), 'a new access token is returned');
    $t->assertTrue(
        !array_key_exists('refresh_token', $r['body']),
        'the rotated refresh token is delivered as a cookie only'
    );
});

$t->test('refreshing without a cookie is refused', function (TestRunner $t) use ($baseUrl, $appUrl) {
    $r = HttpServer::sendJson($baseUrl, $appUrl, 'POST', '/api/v1/auth/refresh.php', []);

    $t->assertSame(401, $r['status'], 'no cookie means no credential, got ' . $r['status']);
});

$t->test('rotation issues a new token and reuse of the old one kills the family', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $cookieName, $bob) {
    /*
     * The replay test. Presenting an already-rotated token is the signature of a
     * copied cookie: either the attacker or the legitimate client now holds one,
     * and the server cannot tell which. So the response is to revoke the whole
     * family and force both to re-authenticate.
     */
    $principal = $enrol($bob);

    $original = $principal['cookie'];

    $t->assertNotNull($original, 'registration issued a refresh cookie');

    $rotate = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/refresh.php',
        [], null, HttpServer::cookieHeader([$cookieName => (string) $original])
    );

    $t->assertSame(200, $rotate['status'], 'the first refresh rotates, got ' . $rotate['raw']);

    $rotated = HttpServer::cookieValue($rotate, $cookieName);

    $t->assertNotNull($rotated, 'a replacement cookie was issued');
    $t->assertNotSame($original, $rotated, 'the replacement value differs from the original');

    $family = (string) Connection::fetchValue(
        'SELECT token_family FROM refresh_tokens WHERE token_hash = :h',
        ['h' => RefreshTokenRepository::hash((string) $original)]
    );

    // A second, unrelated session for the same agent, which reuse detection
    // must leave alone. Without this the test would pass even if the handler
    // revoked every token the agent owns, which is a denial of service any
    // attacker with one stolen cookie could trigger.
    $otherCookie = (string) $enrol($bob)['cookie'];

    // Replay the ORIGINAL value by hand, which is what an attacker holding a
    // copied cookie would send.
    $replay = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/refresh.php',
        [], null,
        ['Cookie: ' . $cookieName . '=' . $original]
    );

    $t->assertSame(401, $replay['status'], 'the rotated-away token is refused, got ' . $replay['status']);

    $t->assertSame(
        0,
        (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM refresh_tokens
              WHERE token_family = :f AND revoked_at IS NULL',
            ['f' => $family]
        ),
        'reuse detection revoked every token in the replayed family'
    );

    $t->assertSame(
        1,
        (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM refresh_tokens
              WHERE token_hash = :h AND revoked_at IS NULL',
            ['h' => RefreshTokenRepository::hash($otherCookie)]
        ),
        'and left the unrelated session alone'
    );
});

$t->test('a bootstrap session survives a refresh and can still register', function (TestRunner $t) use ($baseUrl, $appUrl, $login, $makeKeyPair, $issueCode, $bob, $cookieName) {
    /*
     * A reload between login and registration is ordinary, not an error: the
     * user can close the tab. The bootstrap session must be refreshable, or the
     * agent is forced to type their password again for a timing accident — and
     * the failure mode to guard against is the opposite one, where a
     * "bootstrap refresh" quietly returns a device-bound token.
     */
    $session = $login($bob);

    $t->assertNotNull($session['cookie'], 'login issued a refresh cookie');

    $refresh = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/refresh.php',
        [], null, HttpServer::cookieHeader([$cookieName => (string) $session['cookie']])
    );

    $t->assertSame(200, $refresh['status'], 'a bootstrap session refreshes, got ' . $refresh['raw']);
    $t->assertSame(false, $refresh['body']['device_bound'] ?? null, 'the refreshed session is still unbound');
    $t->assertTrue(
        !array_key_exists('refresh_token', $refresh['body']),
        'the rotated token is a cookie only'
    );

    // And the refreshed bootstrap token still completes registration.
    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        ['device_uuid' => Uuid::v4(), 'public_key_jwk' => $makeKeyPair()['public'],
         'pairing_code' => $issueCode((int) $bob['id'])],
        (string) $refresh['body']['access_token'],
        HttpServer::cookieHeader([$cookieName => (string) HttpServer::cookieValue($refresh, $cookieName)])
    );

    $t->assertSame(200, $r['status'], 'registration completes with the refreshed token, got ' . $r['raw']);
    $t->assertSame(true, $r['body']['device_bound'] ?? null, 'and yields a device-bound session');
});

/* -- Pairing policy ------------------------------------------------------- */

/* -- Policy variants ------------------------------------------------------ */

/**
 * Start a second server on a different policy, run the body, shut it down.
 *
 * @param callable(TestRunner,callable):void $body
 */
$withPolicy = static function (TestRunner $t, string $policy, callable $body) use ($keep, $appUrl, $login, $serverOverrides, &$createdDeviceIds): void {
    $variant = HttpServer::start($keep, ['DEVICE_PAIRING_POLICY' => $policy] + $serverOverrides);

    $register = static function (array $agent, array $body) use ($variant, $appUrl, $login, &$createdDeviceIds): array {
        $result = HttpServer::sendJson(
            $variant->baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
            $body,
            (string) $login($agent, null, $variant->baseUrl)['token']
        );

        $deviceId = (int) Connection::fetchValue(
            'SELECT id FROM devices WHERE device_uuid = :u',
            ['u' => (string) $body['device_uuid']]
        );

        if ($deviceId > 0 && !in_array($deviceId, $createdDeviceIds, true)) {
            $createdDeviceIds[] = $deviceId;
        }

        return $result;
    };

    try {
        $body($t, $register);
    } finally {
        $variant->stop();
    }
};

$t->group('pairing policy');

/*
 * The default policy is FIRST_DEVICE_ONLY, and these tests run against the
 * shared server. The variants at the end each get their own short-lived server
 * because the policy is read from config inside the server process — it cannot
 * be varied per request, and a policy that could be would mean it was coming
 * from somewhere the client controls.
 */

$t->test('a first device needs a pairing code', function (TestRunner $t) use ($login, $makeKeyPair, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $agent   = $freshAgent('first-needs-code');
    $session = $login($agent);

    $attempt = $registerDevice($session, [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
    ], $createdDeviceIds);

    $t->assertSame(422, $attempt['result']['status'],
        'a missing code is a validation error, got ' . $attempt['result']['raw']);
    $t->assertSame(0, $attempt['deviceId'], 'and no device row is created');
});

$t->test('a wrong pairing code does not register a device', function (TestRunner $t) use ($login, $makeKeyPair, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $session = $login($freshAgent('wrong-code'));
    $uuid    = Uuid::v4();

    $attempt = $registerDevice($session, [
        'device_uuid'    => $uuid,
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => '0000000000',
    ], $createdDeviceIds);

    $t->assertSame(401, $attempt['result']['status'],
        'a wrong code is refused, got ' . $attempt['result']['status']);
    $t->assertSame(0, $attempt['deviceId'], 'and no device row is created');
});

$t->test('a wrong code and an unknown code are indistinguishable', function (TestRunner $t) use ($login, $makeKeyPair, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $wrong = $registerDevice($login($freshAgent('oracle-a')), [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => '0000000000',
    ], $createdDeviceIds)['result'];

    $absent = $registerDevice($login($freshAgent('oracle-b')), [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => '1111111111',
    ], $createdDeviceIds)['result'];

    // A distinct answer for "no such code" versus "wrong code" confirms which
    // codes exist, so the two refusals must be byte-identical to the client.
    $t->assertSame(
        $wrong['body']['error']['code'] ?? null,
        $absent['body']['error']['code'] ?? null,
        'both refusals carry the same error code'
    );
    $t->assertSame(
        $wrong['body']['error']['message'] ?? null,
        $absent['body']['error']['message'] ?? null,
        'and the same message'
    );
});

/*
 * Single-use is tested under ALWAYS, not on the shared server.
 *
 * Under FIRST_DEVICE_ONLY the first redemption gives the agent a device, so the
 * second attempt needs no code and would succeed — correctly, and for a reason
 * that has nothing to do with the code. Only a policy that demands a code every
 * time actually exercises the "redeemed exactly once" rule.
 */
$t->test('a pairing code is single-use', function (TestRunner $t) use ($withPolicy, $freshAgent, $makeKeyPair, $issueCode) {
    $withPolicy($t, 'ALWAYS', function (TestRunner $t, $register) use ($freshAgent, $makeKeyPair, $issueCode): void {
        $agent = $freshAgent('single-use');
        $code  = $issueCode((int) $agent['id']);

        $first = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
            'pairing_code'   => $code,
        ]);

        $t->assertSame(200, $first['status'],
            'the first use succeeds, got ' . $first['raw']);

        // A second tab, or one code photocopied, must not also win. The
        // redemption UPDATE is the check, so this matches zero rows rather than
        // racing a read against a write.
        $second = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
            'pairing_code'   => $code,
        ]);

        $t->assertSame(401, $second['status'],
            'the second use is refused, got ' . $second['raw']);
        $t->assertSame(1, (int) Connection::fetchValue(
            'SELECT COUNT(*) FROM devices WHERE agent_id = :a',
            ['a' => (int) $agent['id']]
        ), 'and creates no second device');
    });
});

$t->test('a pairing code belonging to another agent is refused', function (TestRunner $t) use ($login, $makeKeyPair, $issueCode, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $other = $freshAgent('code-owner');
    $mine  = $freshAgent('code-thief');

    // Minted for one agent, presented by another. The redemption UPDATE matches
    // on agent_id, so this is refused by construction, not by a later check.
    $attempt = $registerDevice($login($mine), [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => $issueCode((int) $other['id']),
    ], $createdDeviceIds);

    $t->assertSame(401, $attempt['result']['status'],
        "another agent's code is refused, got " . $attempt['result']['status']);
    $t->assertSame(0, $attempt['deviceId'], 'and no device row is created');
});

$t->test('a guess from another agent does not consume the code', function (TestRunner $t) use ($login, $makeKeyPair, $issueCode, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $agent = $freshAgent('code-survives');
    $code  = $issueCode((int) $agent['id']);

    // A failing attempt must not burn the real owner's code. The redemption is
    // scoped by agent_id, so an attempt by someone else cannot reach the row.
    $registerDevice($login($freshAgent('probe')), [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => $code,
    ], $createdDeviceIds);

    $owner = $registerDevice($login($agent), [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => $code,
    ], $createdDeviceIds);

    $t->assertSame(200, $owner['result']['status'],
        'the owner can still redeem it, got ' . $owner['result']['raw']);
});

$t->test('a malformed public key is refused without burning the pairing code', function (TestRunner $t) use ($login, $issueCode, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $agent = $freshAgent('bad-key');
    $code  = $issueCode((int) $agent['id']);

    // kty EC, crv P-256, but a point that is not on the curve. Structurally
    // plausible and cryptographically impossible.
    $offCurve = [
        'kty' => 'EC',
        'crv' => 'P-256',
        'x'   => str_repeat('A', 43),
        'y'   => str_repeat('B', 43),
    ];

    $attempt = $registerDevice($login($agent), [
        'device_uuid'    => Uuid::v4(),
        'public_key_jwk' => $offCurve,
        'pairing_code'   => $code,
    ], $createdDeviceIds);

    $t->assertSame(422, $attempt['result']['status'],
        'an off-curve key is refused as malformed, got ' . $attempt['result']['status']);

    // Validating the key before authorising is precisely so that a mistyped
    // key does not cost the agent their code.
    $t->assertSame(1, (int) Connection::fetchValue(
        'SELECT COUNT(*) FROM pairing_codes
          WHERE agent_id = :a AND consumed_at IS NULL AND code_hash = :h',
        ['a' => (int) $agent['id'], 'h' => PairingCode::hash($code)]
    ), 'the pairing code is still unconsumed');
});

$t->test('re-registering the same device and key is idempotent', function (TestRunner $t) use ($login, $makeKeyPair, $issueCode, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $agent = $freshAgent('idempotent');
    $keys  = $makeKeyPair();
    $uuid  = Uuid::v4();

    $first = $registerDevice($login($agent), [
        'device_uuid'    => $uuid,
        'public_key_jwk' => $keys['public'],
        'pairing_code'   => $issueCode((int) $agent['id']),
    ], $createdDeviceIds);

    $t->assertSame(200, $first['result']['status'],
        'the first registration succeeds, got ' . $first['result']['raw']);

    // A retried request, or a page reloaded after a dropped response.
    $again = $registerDevice($login($agent), [
        'device_uuid'    => $uuid,
        'public_key_jwk' => $keys['public'],
    ], $createdDeviceIds);

    $t->assertSame(200, $again['result']['status'],
        'the retry succeeds too, got ' . $again['result']['raw']);
    $t->assertSame($first['deviceId'], $again['deviceId'],
        'and it is the same device row, not a second one');
    $t->assertSame(true, $again['result']['body']['device_bound'] ?? null,
        'with a device-bound token');
});

$t->test('re-registering a device_uuid with a different key is refused', function (TestRunner $t) use ($login, $makeKeyPair, $issueCode, $freshAgent, $registerDevice, &$createdDeviceIds) {
    $agent = $freshAgent('rebind');
    $uuid  = Uuid::v4();

    $first = $registerDevice($login($agent), [
        'device_uuid'    => $uuid,
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => $issueCode((int) $agent['id']),
    ], $createdDeviceIds);

    $t->assertSame(200, $first['result']['status'],
        'the first registration succeeds, got ' . $first['result']['raw']);

    // Accepting this would let anyone holding a bootstrap session silently take
    // over a device_uuid, while the previous key's holder keeps submitting as
    // the same device until they notice something is wrong.
    $rebind = $registerDevice($login($agent), [
        'device_uuid'    => $uuid,
        'public_key_jwk' => $makeKeyPair()['public'],
        'pairing_code'   => $issueCode((int) $agent['id']),
    ], $createdDeviceIds);

    $t->assertSame(409, $rebind['result']['status'],
        'rebinding a device_uuid to a new key is refused, got ' . $rebind['result']['status']);
});


$t->test('NEVER requires no pairing code at all', function (TestRunner $t) use ($withPolicy, $freshAgent, $makeKeyPair) {
    $withPolicy($t, 'NEVER', function (TestRunner $t, $register) use ($freshAgent, $makeKeyPair): void {
        $first = $register($freshAgent('never-1'), [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
        ]);

        $t->assertSame(200, $first['status'],
            'a first device enrols with no code, got ' . $first['raw']);

        $second = $register($freshAgent('never-2'), [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
        ]);

        $t->assertSame(200, $second['status'],
            'and so does a second one, got ' . $second['raw']);
    });
});

$t->test('ALWAYS demands a code for a second device too', function (TestRunner $t) use ($withPolicy, $freshAgent, $makeKeyPair, $issueCode) {
    $withPolicy($t, 'ALWAYS', function (TestRunner $t, $register) use ($freshAgent, $makeKeyPair, $issueCode): void {
        $agent = $freshAgent('always');

        $first = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
            'pairing_code'   => $issueCode((int) $agent['id']),
        ]);

        $t->assertSame(200, $first['status'],
            'the first device is enrolled, got ' . $first['raw']);

        // This is the line between ALWAYS and FIRST_DEVICE_ONLY. A second
        // device must not be added on a password alone.
        $second = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
        ]);

        $t->assertSame(422, $second['status'],
            'a second device with no code is refused, got ' . $second['raw']);

        $withCode = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
            'pairing_code'   => $issueCode((int) $agent['id']),
        ]);

        $t->assertSame(200, $withCode['status'],
            'and succeeds once one is supplied, got ' . $withCode['raw']);
    });
});

$t->test('FIRST_DEVICE_ONLY spares the agent a code for a second device', function (TestRunner $t) use ($withPolicy, $freshAgent, $makeKeyPair, $issueCode) {
    $withPolicy($t, 'FIRST_DEVICE_ONLY', function (TestRunner $t, $register) use ($freshAgent, $makeKeyPair, $issueCode): void {
        $agent = $freshAgent('firstonly');

        $first = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
            'pairing_code'   => $issueCode((int) $agent['id']),
        ]);

        $t->assertSame(200, $first['status'],
            'the first device is enrolled, got ' . $first['raw']);

        $second = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
        ]);

        $t->assertSame(200, $second['status'],
            'a second device needs no code, got ' . $second['raw']);
    });
});

$t->test('an unrecognised policy fails closed to ALWAYS', function (TestRunner $t) use ($withPolicy, $freshAgent, $makeKeyPair, $issueCode) {
    // A typo in a deployment's config must not silently downgrade to "anyone
    // with a password may enrol any number of devices". The schema upper-cases
    // the value, so the probe has to be a genuinely unknown word rather than
    // merely a differently-cased one.
    $withPolicy($t, 'yes', function (TestRunner $t, $register) use ($freshAgent, $makeKeyPair, $issueCode): void {
        $agent = $freshAgent('failclosed');

        $first = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
            'pairing_code'   => $issueCode((int) $agent['id']),
        ]);

        $t->assertSame(200, $first['status'],
            'a first device is still enrolled, got ' . $first['raw']);

        $second = $register($agent, [
            'device_uuid'    => Uuid::v4(),
            'public_key_jwk' => $makeKeyPair()['public'],
        ]);

        $t->assertSame(422, $second['status'],
            'but a second is not, so it behaved as ALWAYS, got ' . $second['raw']);
    });
});

$t->group('revocation');

$t->test('a revoked device is refused on the very next request', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob) {
    $principal = $enrol($bob);

    $before = HttpServer::sendSigned(
        $baseUrl, $appUrl, $principal, 'GET', '/api/v1/submission.php', '', $principal['token']
    );

    $t->assertNotSame(401, $before['status'], 'the device works before revocation, got ' . $before['status']);

    Connection::execute('UPDATE devices SET status = :s WHERE id = :id', [
        's' => DeviceStatus::REVOKED,
        'id' => $principal['deviceId'],
    ]);

    $after = HttpServer::sendSigned(
        $baseUrl, $appUrl, $principal, 'GET', '/api/v1/submission.php', '', $principal['token']
    );

    $t->assertSame(403, $after['status'], 'revocation takes effect immediately, got ' . $after['status']);
});

$t->test('a suspended agent is refused even with a valid device token', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob) {
    $principal = $enrol($bob);

    Connection::execute('UPDATE agents SET status = :s WHERE id = :id', [
        's' => AgentRepository::SUSPENDED,
        'id' => $principal['agentId'],
    ]);

    $r = HttpServer::sendSigned(
        $baseUrl, $appUrl, $principal, 'GET', '/api/v1/submission.php', '', $principal['token']
    );

    $t->assertSame(403, $r['status'], 'a suspended agent is refused, got ' . $r['status']);

    // Leave the fixture usable for anything ordered after this.
    Connection::execute('UPDATE agents SET status = :s WHERE id = :id', [
        's' => AgentRepository::ACTIVE,
        'id' => $principal['agentId'],
    ]);
});

/* -- Invariants ----------------------------------------------------------- */

$t->group('request signing');

/*
 * Every case here goes to /api/v1/submit.php, because that is the only route
 * Kernel dispatches as 'signed'.
 *
 * That matters more than it sounds. An earlier draft of this group pointed the
 * tamper tests at /api/v1/leaderboard.php, which is a plain 'bearer' route, so
 * none of the signature code ran and every forgery came back 200 — a suite that
 * reported failures on the first run and then quietly stopped testing anything
 * once the route was "fixed". The negative cases are only worth writing against
 * the endpoint that actually verifies.
 *
 * A signature failure happens in the Kernel, before SubmitController, so these
 * tests do not need a well-formed upload to prove a forgery is caught. The
 * positive case does, and it is the same helper the submission group uses.
 */

/**
 * A real JPEG in memory, plus the digest of its exact bytes.
 *
 * Seeded, because two calls with identical pixels produce identical bytes and
 * the swapped-bytes test would then be testing a no-op.
 *
 * @return array{bytes:string,name:string,sha256:string}
 */
$makeImage = static function (int $seed = 1): array {
    $image = imagecreatetruecolor(96, 96);

    if ($image === false) {
        throw new RuntimeException('Could not create the fixture image.');
    }

    for ($y = 0; $y < 96; $y++) {
        for ($x = 0; $x < 96; $x++) {
            imagesetpixel($image, $x, $y, imagecolorallocate(
                $image,
                ($x * 3 + $seed * 11) % 256,
                ($y * 5 + $seed * 23) % 256,
                (($x ^ $y) * 7 + $seed * 41) % 256
            ));
        }
    }

    ob_start();
    imagejpeg($image, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    if ($bytes === '') {
        throw new RuntimeException('Could not encode the fixture image.');
    }

    return [
        'bytes'  => $bytes,
        'name'   => 'field.jpg',
        'sha256' => hash('sha256', $bytes),
    ];
};

/** The verbatim payload part: the bytes that get signed. */
$makePayload = static function (string $sha256): string {
    return (string) json_encode([
        'submission_uuid' => Uuid::v4(),
        'count_claimed'   => 1,
        'captured_at'     => Clock::sql(),
        'file_sha256'     => $sha256,
    ], JSON_THROW_ON_ERROR);
};

$SUBMIT_PATH = '/api/v1/submit.php';

/**
 * Note a submission the suite caused to be accepted, so teardown can remove it.
 *
 * The signing tests are the only ones that create real rows, and an uncleaned
 * submission blocks deletion of its agent — which is what silently leaked the
 * entire fixture set before the teardown was made per-statement.
 */
$recordSubmission = static function (array $result) use (&$createdSubmissionIds): void {
    $id = (int) Connection::fetchValue(
        'SELECT id FROM submissions WHERE submission_uuid = :u',
        ['u' => (string) ($result['body']['data']['submission_uuid'] ?? '')]
    );

    if ($id > 0 && !in_array($id, $createdSubmissionIds, true)) {
        $createdSubmissionIds[] = $id;
    }
};

$t->test('a correctly signed request is accepted', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes']
    );

    $t->assertSame(202, $r['status'], 'a valid signature passes, got ' . $r['raw']);

    $recordSubmission($r);

    $t->assertSame(
        $image['sha256'],
        (string) Connection::fetchValue(
            'SELECT file_sha256 FROM submissions WHERE submission_uuid = :u',
            ['u' => (string) ($r['body']['data']['submission_uuid'] ?? '')]
        ),
        'and the stored digest is the digest that was signed'
    );
});

$t->test('a tampered signature is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    /*
     * A well-formed base64url blob that is not a valid signature. Replacing the
     * value wholesale also proves the server is not merely checking that the
     * header is present and parses.
     */
    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes'],
        ['X-Request-Signature: ' . Str::base64UrlEncode(random_bytes(64))]
    );

    $t->assertSame(401, $r['status'], 'a forged signature is refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::SIGNATURE_INVALID, $r['body']['error']['code'] ?? null,
        'and is reported as a signature failure, not a generic auth failure');
});

$t->test('a signature made by a different key is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $makeKeyPair, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();
    $foreign = $makeKeyPair();

    /*
     * A correct signature over the correct canonical string, by a key the
     * server has never seen. This is the case a "does the header exist and
     * parse" check waves straight through.
     */
    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes'],
        [], $foreign['privateKey']
    );

    $t->assertSame(401, $r['status'], 'a foreign key is refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::SIGNATURE_INVALID, $r['body']['error']['code'] ?? null,
        'and is reported as a signature failure');
});

$t->test('a signed request carrying another device UUID is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    // A second, fully legitimate device for the same agent. Both its token and
    // its key are valid; the two are simply not paired with each other.
    $other = $enrol($bob);

    $t->assertNotSame($session['deviceUuid'], $other['deviceUuid'], 'the two devices differ');

    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes'],
        ['X-Device-UUID: ' . $other['deviceUuid']]
    );

    $t->assertSame(401, $r['status'], 'a mismatched X-Device-UUID is refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::TOKEN_INVALID, $r['body']['error']['code'] ?? null,
        'and is reported as a token/device mismatch, not a bad signature');
});

$t->test('a signature does not transfer to a different path', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    /*
     * Sign for the leaderboard, send to submit. Nothing about the request is
     * malformed; only the path inside the signed string differs. A verifier
     * that hashed the body but not the path would accept this, and a signature
     * captured for a cheap read could then be replayed against the upload.
     */
    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes'],
        [], null, '/api/v1/leaderboard.php'
    );

    $t->assertSame(401, $r['status'], 'a signature for another path is refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::SIGNATURE_INVALID, $r['body']['error']['code'] ?? null,
        'as a signature failure');
});

$t->test('an expired timestamp is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    /*
     * A day outside the skew window. The nonce is fresh and the signature is
     * valid over the old timestamp, so the only thing wrong is the clock — which
     * is what makes this the test that proves the freshness window is enforced
     * at all rather than merely present.
     */
    $stale = (string) (time() - 86_400);

    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes'],
        ['X-Request-Timestamp: ' . $stale]
    );

    $t->assertSame(401, $r['status'], 'a day-old timestamp is refused, got ' . $r['raw']);

    // CLOCK_SKEW rather than SIGNATURE_INVALID, and the distinction is worth
    // keeping: one means "fix your clock", the other means "someone changed this
    // request". Collapsing them sends the agent to security support for a clock.
    $t->assertSame(ErrorCode::CLOCK_SKEW, $r['body']['error']['code'] ?? null,
        'and is reported as clock skew, not as a signature failure');
});

$t->test('a reused nonce is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();
    $nonce   = Str::base64UrlEncode(random_bytes(18));
    $payload = $makePayload($image['sha256']);

    $first = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $payload, $image['name'], $image['bytes'],
        [], null, null, $nonce
    );

    $t->assertSame(202, $first['status'], 'the first use is accepted, got ' . $first['raw']);

    $recordSubmission($first);

    /*
     * Byte-identical replay, nonce included. A signature check alone cannot see
     * this: nothing about the request changed, so the signature is still valid.
     * Only remembering the nonce catches it, which is why the nonce store is not
     * an optimisation.
     */
    $replay = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $payload, $image['name'], $image['bytes'],
        [], null, null, $nonce
    );

    $t->assertSame(401, $replay['status'], 'the replay is refused, got ' . $replay['raw']);
    $t->assertSame(ErrorCode::REPLAY_DETECTED, $replay['body']['error']['code'] ?? null,
        'and is reported as a replay, which is a different client problem from a bad signature');
});

$t->test('a request with no signature header at all is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes'],
        ['X-Request-Signature: ']
    );

    // 400, not 401: nothing is being challenged, the request is simply not
    // well-formed. 401 would be right only if the credential were present and
    // wrong.
    $t->assertSame(400, $r['status'], 'a missing signature is refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::SIGNATURE_INVALID, $r['body']['error']['code'] ?? null,
        'and names the missing header');
});

/* -- Submission ------------------------------------------------------------ */

$t->group('submission');

$t->test('a multipart signature covers the payload part, not the raw body', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    /*
     * The payload is signed verbatim, as a multipart text field. A server that
     * hashed the raw request body could not verify this request at all, because
     * PHP has already consumed the stream into $_POST/$_FILES by the time the
     * application runs. Reaching 202 is therefore the proof that both sides are
     * following the same rule, and the reason §10 documents the deviation.
     */
    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes']
    );

    $t->assertSame(202, $r['status'], 'the payload-part rule is what the server verifies, got ' . $r['raw']);

    $recordSubmission($r);
});

$t->test('file bytes that differ from the signed digest are refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage(1);

    /*
     * A valid signature over a payload naming one digest, with different bytes
     * actually uploaded. This is the whole reason the digest lives inside the
     * signed payload: without that, the file could be swapped after signing and
     * the signature would still verify.
     */
    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $makeImage(2)['bytes']
    );

    $t->assertSame(422, $r['status'], 'swapped bytes are refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::FILE_HASH_MISMATCH, $r['body']['error']['code'] ?? null,
        'and the client is told the file did not match, so it can re-upload rather than retry blindly');
});

$t->test('a smuggled agent_id in the payload is refused', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    // The allowlist is the boundary: an agent_id alongside an otherwise valid
    // payload is rejected rather than ignored, so a submission can never be
    // attributed to somebody else.
    $payload          = json_decode($makePayload($image['sha256']), true, 32, JSON_THROW_ON_ERROR);
    $payload['agent_id'] = 999999;

    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        (string) json_encode($payload, JSON_THROW_ON_ERROR), $image['name'], $image['bytes']
    );

    $t->assertSame(422, $r['status'], 'a smuggled agent_id is refused, got ' . $r['raw']);
    $t->assertSame(ErrorCode::VALIDATION_FAILED, $r['body']['error']['code'] ?? null,
        'as a validation failure naming the unknown field');
});

$t->test('a submission is attributed to the device that signed it', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $bob, $makeImage, $makePayload, $SUBMIT_PATH, $recordSubmission, &$createdSubmissionIds) {
    $session = $enrol($bob);
    $image   = $makeImage();

    $r = HttpServer::sendMultipart(
        $baseUrl, $appUrl, $session, $SUBMIT_PATH,
        $makePayload($image['sha256']), $image['name'], $image['bytes']
    );

    $recordSubmission($r);

    $row = Connection::fetchOne(
        'SELECT agent_id, device_id FROM submissions WHERE submission_uuid = :u',
        ['u' => (string) ($r['body']['data']['submission_uuid'] ?? '')]
    );

    $t->assertSame((int) $session['agentId'], (int) ($row['agent_id'] ?? 0),
        'the agent comes from the token, not the payload');
    $t->assertSame((int) $session['deviceId'], (int) ($row['device_id'] ?? 0),
        'and the device comes from the key that signed it');
});

$t->group('invariants');

$t->test('no response body carries the password or any key material', function (TestRunner $t) use ($baseUrl, $appUrl, $enrol, $alice, $makeKeyPair, $issueCode) {
    $session = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $alice['username'], 'password' => $alice['password']]
    );

    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        ['device_uuid' => Uuid::v4(), 'public_key_jwk' => $makeKeyPair()['public'],
         'pairing_code' => $issueCode((int) $alice['id'])],
        (string) $session['body']['access_token']
    );

    $t->assertTrue(!str_contains($session['raw'], $alice['password']), 'the password never appears in a response');
    $t->assertTrue(!str_contains($r['raw'], 'PRIVATE KEY'), 'no private key material is returned');
});

$t->test('the retired IMEI challenge route no longer exists', function (TestRunner $t) use ($baseUrl, $appUrl) {
    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/challenge.php',
        ['imei' => '352099001761481']
    );

    $t->assertSame(404, $r['status'], 'the challenge endpoint is gone, got ' . $r['status']);
});

$t->test('no executable code treats an IMEI as a credential', function (TestRunner $t) {
    /*
     * A structural check, because the behavioural version is what the tests
     * above already are.
     *
     * Comments are stripped with token_get_all() rather than a regex, for two
     * reasons. The obvious /imei/i pattern matches "DateTimeImmutable", which
     * made the first version of this test report nineteen files and prove
     * nothing; and the files that discuss IMEI in prose are exactly the ones
     * explaining that it is no longer used, so counting them would mean the
     * test could only ever pass while the explanation was deleted.
     *
     * What is left is executable code. Each entry in $allow is a place the
     * column is legitimately touched, and none of them is an authentication
     * decision — the new list below is a second IMEI test to add to if a real
     * need appears, which is the point of naming them.
     */
    $allow = [
        // The administrative lookup and the enrolment writes, for the
        // provision_agent.php and pair_device.php CLIs.
        'Database/AgentRepository.php' => 'administrative',
        // The column, when writing or reading a device row.
        'Database/DeviceRepository.php' => 'administrative',
        // Shown to a reviewer so a disputed device can be identified. Operator
        // only, and a display concern rather than an identity decision.
        'Database/ReviewRepository.php' => 'display',
        'Domain/ReviewController.php'   => 'display',
        // The enrolment rule, used only by provision_agent.php.
        'Domain/Validator.php' => 'administrative',
        // An accessor on the context, mirroring the agent row. No route reads
        // it to make an authorisation decision.
        'Security/AuthContext.php' => 'accessor',
        // Keeps the value out of application logs.
        'Support/Logger.php' => 'redaction',
    ];

    $appRoot = dirname(__DIR__) . '/app';
    $found   = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        if (preg_match('/\bimei\b/i', $code) !== 1) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($appRoot) + 1));

        if (isset($allow[$relative])) {
            continue;
        }

        $found[] = $relative;
    }

    $t->assertSame([], $found, 'IMEI appears in executable code only in the administrative files listed');
});

/* ---------------------------------------------------------------------------
 * Exit
 * --------------------------------------------------------------------------- */

Cli::heading('FieldPulse auth contract (server ' . $serverVersion . ', database ' . $dbName . ')');

$code = $t->run($verbose);

exit($code);
