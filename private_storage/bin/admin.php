<?php

declare(strict_types=1);

/**
 * ADMIN account-management contract suite (Admin user management).
 *
 *   php private_storage/bin/admin.php
 *   php private_storage/bin/admin.php --verbose
 *   php private_storage/bin/admin.php --filter=retire
 *   php private_storage/bin/admin.php --keep
 *
 * WHY THIS EXISTS
 *
 * The account-management endpoints are the most privileged surface in the
 * system: an ADMIN can re-role, suspend, retire and re-credential other users.
 * A regression in the auth gate or the guards would not be an inconvenience,
 * it would be a lockout vector — the tool that removes the last administrator
 * is the one tool that must refuse to. This suite asserts the gate, the
 * lifecycle transitions, the terminality of retirement, and the guards that
 * keep the surface from locking itself out. It also covers the no-lockout
 * class: issuing a device pairing code from the admin surface lets an agent
 * (or a reinstated admin) bind a device without ever touching SSH.
 *
 * It runs over a real socket with real sessions (see Testing\HttpServer): the
 * ADMIN calling the tool must actually hold a device-bound ADMIN session, the
 * suspended agent must actually be refused at login, and the reinstated agent
 * must actually log back in.
 *
 * SAFETY
 *
 * Refuses to run against APP_ENV=production or a database that does not look
 * like a test or development database, unless --force is given. Every row it
 * creates is tracked by primary key and deleted on exit, and every fixture is
 * named with the run tag so leftovers are findable.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Database\DeviceRepository;
use FieldPulse\Security\Credentials;
use FieldPulse\Security\Jwk;
use FieldPulse\Security\PairingCode;
use FieldPulse\Support\Clock;
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
    Cli::fail('Refusing to run the admin suite against APP_ENV=production.');
    exit(1);
}

if (!preg_match('/(test|dev)/i', $dbName) && !$force) {
    Cli::fail('Refusing to run the admin suite against database "' . $dbName
        . '". Its name does not look like a test or development database.');
    exit(1);
}

$t = new TestRunner($filter);

/* ---------------------------------------------------------------------------
 * Fixtures
 * --------------------------------------------------------------------------- */

$runTag = 'ad' . strtolower(substr(bin2hex(random_bytes(5)), 0, 8));

$agents  = new AgentRepository();
$devices = new DeviceRepository();

$createdAgentIds  = [];
$createdDeviceIds = [];

$storageBaseline = HttpServer::snapshotStorage();

/**
 * An agent with real credentials and a chosen role.
 *
 * @return array{id:int,agentCode:string,username:string,password:string,row:array<string,mixed>}
 */
$makeAgent = static function (string $label, string $role = 'AGENT', string $password = 'correct-horse-battery-staple') use ($runTag, $agents, &$createdAgentIds): array {
    $agentCode = $runTag . '-' . $label;
    $username  = $runTag . '.' . strtolower($label);

    $id = $agents->create($agentCode, 'Admin ' . ucfirst($label), null);
    $createdAgentIds[] = $id;

    $agents->setRole($id, $role);
    $agents->setCredentials($id, $username, Credentials::hash($password));

    $row = $agents->findById($id);

    if ($row === null) {
        throw new RuntimeException('Admin fixture could not re-read its own agent row.');
    }

    return ['id' => $id, 'agentCode' => $agentCode, 'username' => $username, 'password' => $password, 'row' => $row];
};

$makeKeyPair = static function (): array {
    $pair = Jwk::generateKeyPair();
    $pem  = '';

    if (!openssl_pkey_export($pair['private'], $pem) || $pem === '') {
        throw new RuntimeException('Could not export the fixture private key.');
    }

    /** @var \OpenSSLAsymmetricKey|false $private */
    $private = openssl_pkey_get_private($pem);

    if ($private === false) {
        throw new RuntimeException('Could not re-read the fixture private key.');
    }

    return ['privatePem' => $pem, 'privateKey' => $private, 'public' => $pair['public']];
};

$issueCode = static function (int $agentId): string {
    $code = PairingCode::random();

    Connection::execute(
        "INSERT INTO pairing_codes (agent_id, code_hash, attempts, max_attempts, expires_at, created_by, created_at)
         VALUES (:agent_id, :hash, 0, 5, :expires_at, 'admin_suite', UTC_TIMESTAMP())",
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

$serverOverrides = [
    'LOGIN_RATE_LIMIT'    => '100000',
    'LOGIN_RATE_WINDOW'   => '900',
    'REGISTER_RATE_LIMIT' => '100000',
];

try {
    $server = HttpServer::start($keep, $serverOverrides);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}

$baseUrl = $server->baseUrl;

register_shutdown_function(static function () use (
    &$createdAgentIds,
    &$createdDeviceIds,
    $server,
    $storageBaseline
): void {
    $failures = 0;

    $delete = static function (string $sql, array $params) use (&$failures): void {
        try {
            Connection::execute($sql, $params);
        } catch (Throwable) {
            $failures++;
        }
    };

    // Child-first, mirroring the auth suite's foreign-key order.
    foreach ($createdDeviceIds as $deviceId) {
        $delete('DELETE FROM request_nonces WHERE device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM refresh_tokens WHERE device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM pairing_codes WHERE consumed_by_device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM submissions WHERE device_id = :id', ['id' => $deviceId]);
        $delete('DELETE FROM devices WHERE id = :id', ['id' => $deviceId]);
    }

    foreach ($createdAgentIds as $agentId) {
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
            "\nWARNING: the admin suite left " . $failures . " row(s) behind; "
            . 'search for agent codes beginning with the run tag prefix.' . "\n"
        );
    }

    try {
        $server->stop();
        HttpServer::removeNewStorage($storageBaseline);
    } catch (Throwable) {
        // Nothing useful to do at shutdown.
    }
});

/** Log in and return the bootstrap token and refresh cookie. */
$login = static function (array $agent, ?string $password = null) use ($baseUrl, $appUrl): array {
    $result = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/auth/login.php',
        ['username' => $agent['username'], 'password' => $password ?? $agent['password']]
    );

    return [
        'token'  => is_string($result['body']['access_token'] ?? null) ? $result['body']['access_token'] : null,
        'result' => $result,
    ];
};

/** Full client side: log in, then register a device. */
$enrol = static function (array $agent, ?string $password = null) use ($baseUrl, $appUrl, $login, $makeKeyPair, $issueCode, &$createdDeviceIds): array {
    $session = $login($agent, $password);

    if ($session['token'] === null) {
        throw new RuntimeException('Admin fixture could not log in: ' . $session['result']['raw']);
    }

    $keys       = $makeKeyPair();
    $deviceUuid = Uuid::v4();

    $result = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        [
            'device_uuid'    => $deviceUuid,
            'public_key_jwk' => $keys['public'],
            'pairing_code'   => $issueCode((int) $agent['id']),
        ],
        (string) $session['token']
    );

    if ($result['status'] !== 200) {
        throw new RuntimeException('Admin fixture could not register: ' . $result['status'] . ' ' . $result['raw']);
    }

    $deviceId = (int) Connection::fetchValue(
        'SELECT id FROM devices WHERE device_uuid = :u',
        ['u' => $deviceUuid]
    );

    if ($deviceId > 0) {
        $createdDeviceIds[] = $deviceId;
    }

    return [
        'agentId'   => (int) $agent['id'],
        'deviceId'  => $deviceId,
        'token'     => (string) $result['body']['access_token'],
        'privatePem' => $keys['privatePem'],
    ];
};

/** The two admin endpoints. */
$list   = static fn (?string $token, array $overrides = []) => HttpServer::sendJson(
    $baseUrl, $appUrl, 'GET', '/api/v1/admin/agents.php', [], $token, $overrides
);
$create = static fn (string $token, array $body) => HttpServer::sendJson(
    $baseUrl, $appUrl, 'POST', '/api/v1/admin/agents.php', $body, $token
);
$update = static function (string $token, array $body) use ($baseUrl, $appUrl): array {
    $r = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/admin/agent.php', $body, $token
    );
    if ($r['status'] >= 500) {
        fwrite(STDERR, 'RAW500 ' . $r['raw'] . "\n");
    }

    return $r;
};

$errorCode = static fn (array $r): ?string => is_string($r['body']['error']['code'] ?? null)
    ? $r['body']['error']['code']
    : null;

/* ---------------------------------------------------------------------------
 * Fixture sessions
 * --------------------------------------------------------------------------- */

$admin        = $makeAgent('root', 'ADMIN');
$supervisor   = $makeAgent('super', 'SUPERVISOR');
$agent        = $makeAgent('agent');
$secondAdmin  = $makeAgent('root2', 'ADMIN');

$adminSession     = $enrol($admin);
$supervisorToken  = $enrol($supervisor)['token'];
$agentToken       = $enrol($agent)['token'];

// A dedicated AGENT for the ISSUE_PAIRING_CODE gate check. The shared agent
// fixture is suspended later in the run (which revokes its device), so its
// token stops being a plain "wrong role" probe and starts failing with
// DEVICE_REVOKED. This one is never touched by other groups.
$pairGateAgent = $makeAgent('pairgate');
$pairGateToken = $enrol($pairGateAgent)['token'];

/* -- The gate -------------------------------------------------------------- */

$t->group('gate');

$t->test('the account directory refuses anonymous access', function (TestRunner $t) use ($list, $errorCode) {
    $r = $list(null);

    $t->assertSame(401, $r['status'], 'no token is refused, got ' . $r['status']);
    $t->assertSame('UNAUTHENTICATED', $errorCode($r), 'the code is a missing credential, not a role error');
});

$t->test('a plain AGENT is refused the account directory', function (TestRunner $t) use ($list, $agentToken, $errorCode) {
    $r = $list($agentToken);

    $t->assertSame(403, $r['status'], 'an agent session is refused, got ' . $r['status']);
    $t->assertSame('FORBIDDEN', $errorCode($r), 'the code is FORBIDDEN');
});

$t->test('even a SUPERVISOR is refused the account directory', function (TestRunner $t) use ($list, $supervisorToken, $errorCode) {
    $r = $list($supervisorToken);

    $t->assertSame(403, $r['status'], 'a supervisor session is refused, got ' . $r['status']);
    $t->assertSame('FORBIDDEN', $errorCode($r), 'the code is FORBIDDEN');
});

$t->test('an ADMIN lists the account directory', function (TestRunner $t) use ($list, $adminSession, $admin) {
    $r = $list($adminSession['token']);

    $t->assertSame(200, $r['status'], 'an admin session lists, got ' . $r['status']);
    $codes = array_column($r['body']['data'] ?? [], 'agent_code');
    $t->assertTrue(in_array($admin['agentCode'], $codes, true), 'the admin appears in their own directory');
    $t->assertTrue(is_int($r['body']['meta']['active_admins'] ?? null), 'meta reports the active-admin count');
});

/* -- Create ---------------------------------------------------------------- */

$t->group('create');

$t->test('a valid account is created and can log in immediately', function (TestRunner $t) use ($create, $list, $login, $adminSession, $runTag) {
    $username = $runTag . '.created';
    $password = 'created-pass-4921';

    $r = $create($adminSession['token'], [
        'agent_code' => $runTag . '-created',
        'full_name'  => 'Created Agent',
        'username'   => $username,
        'password'   => $password,
    ]);

    $t->assertSame(201, $r['status'], 'a valid create succeeds, got ' . $r['status']);
    $t->assertSame('AGENT', $r['body']['data']['role'] ?? null, 'the default role is AGENT');
    $t->assertSame(true, $r['body']['data']['has_credential'] ?? null, 'the account is immediately usable');
    $t->assertFalse(str_contains((string) $r['raw'], $password), 'the plaintext password never leaves the server');

    $loginResult = $login(['username' => $username, 'password' => $password]);
    $t->assertSame(200, $loginResult['result']['status'], 'the created user can log in');
});

$t->test('duplicate agent codes and duplicate usernames are refused', function (TestRunner $t) use ($create, $adminSession, $admin, $runTag) {
    $dupCode = $create($adminSession['token'], [
        'agent_code' => $admin['agentCode'],
        'full_name'  => 'Duplicate',
        'username'   => $runTag . '.dupcode',
        'password'   => 'duplicate-pass-42',
    ]);

    $t->assertSame(409, $dupCode['status'], 'a duplicate agent code is refused, got ' . $dupCode['status']);

    $r2 = $create($adminSession['token'], [
        'agent_code' => $runTag . '-dupuser',
        'full_name'  => 'Duplicate User',
        'username'   => $admin['username'],
        'password'   => 'duplicate-pass-42',
    ]);

    $t->assertSame(409, $r2['status'], 'a duplicate username is refused, got ' . $r2['status']);
});

$t->test('a weak password, an unknown role and an unknown field are all 422', function (TestRunner $t) use ($create, $adminSession, $runTag) {
    $weak = $create($adminSession['token'], [
        'agent_code' => $runTag . '-weakpw',
        'full_name'  => 'Weak',
        'username'   => $runTag . '.weak',
        'password'   => 'short',
    ]);
    $t->assertSame(422, $weak['status'], 'a weak password is refused, got ' . $weak['status']);

    $role = $create($adminSession['token'], [
        'agent_code' => $runTag . '-badrole',
        'full_name'  => 'Bad Role',
        'username'   => $runTag . '.badrole',
        'password'   => 'role-pass-4921',
        'role'       => 'GOD',
    ]);
    $t->assertSame(422, $role['status'], 'an unknown role is refused, got ' . $role['status']);

    $extra = $create($adminSession['token'], [
        'agent_code' => $runTag . '-extra',
        'full_name'  => 'Extra',
        'username'   => $runTag . '.extra',
        'password'   => 'extra-pass-4921',
        'privilege'  => 'everything',
    ]);
    $t->assertSame(422, $extra['status'], 'an unknown field is refused, got ' . $extra['status']);
});

/* -- Role changes ---------------------------------------------------------- */

$t->group('role');

$t->test('the last-active-admin guard and the self-guard protect the surface', function (TestRunner $t) use ($update, $adminSession, $admin) {
    $demoteSelf = $update($adminSession['token'], [
        'id'     => $admin['id'],
        'action' => 'SET_ROLE',
        'role'   => 'AGENT',
    ]);

    $t->assertSame(409, $demoteSelf['status'], 'an admin cannot demote themselves, got ' . $demoteSelf['status']);

    $suspendSelf = $update($adminSession['token'], [
        'id'     => $admin['id'],
        'action' => 'SET_STATUS',
        'status' => 'SUSPENDED',
    ]);

    $t->assertSame(409, $suspendSelf['status'], 'an admin cannot suspend themselves, got ' . $suspendSelf['status']);
});

$t->test('an admin can demote another admin while an administrator remains', function (TestRunner $t) use ($update, $list, $adminSession, $secondAdmin, $runTag) {
    $r = $update($adminSession['token'], [
        'id'     => $secondAdmin['id'],
        'action' => 'SET_ROLE',
        'role'   => 'SUPERVISOR',
    ]);

    $t->assertSame(200, $r['status'], 'demoting a second admin succeeds, got ' . $r['status']);
    $t->assertSame('SUPERVISOR', $r['body']['data']['role'] ?? null, 'the role change is visible in the response');

    $directory = $list($adminSession['token']);
    $row = null;
    foreach ($directory['body']['data'] ?? [] as $entry) {
        if (($entry['agent_code'] ?? null) === $secondAdmin['agentCode']) {
            $row = $entry;
        }
    }

    $t->assertSame('SUPERVISOR', $row['role'] ?? null, 'the role change is visible in the directory');
});

$t->test('a non-admin role can be promoted and the account keeps working', function (TestRunner $t) use ($update, $create, $enrol, $login, $adminSession, $runTag) {
    $username = $runTag . '.promote';
    $password = 'promote-pass-4921';

    $created = $create($adminSession['token'], [
        'agent_code' => $runTag . '-promote',
        'full_name'  => 'Promotable',
        'username'   => $username,
        'password'   => $password,
    ]);

    $t->assertSame(201, $created['status'], 'the promotable agent is created');
    $id = (int) $created['body']['data']['id'];

    $r = $update($adminSession['token'], [
        'id'     => $id,
        'action' => 'SET_ROLE',
        'role'   => 'SUPERVISOR',
    ]);

    $t->assertSame(200, $r['status'], 'the promotion succeeds, got ' . $r['status']);
    $t->assertSame('SUPERVISOR', $r['body']['data']['role'] ?? null, 'the new role is returned');

    $session = $enrol(['id' => $id, 'username' => $username, 'password' => $password]);
    $t->assertTrue(is_string($session['token']) && $session['token'] !== '', 'a promoted agent can still hold a session');
});

/* -- Suspend / reinstate --------------------------------------------------- */

$t->group('suspend');

$t->test('suspending revokes the devices and kills the session', function (TestRunner $t) use ($update, $login, $list, $adminSession, $agent, $agents, $errorCode) {
    $r = $update($adminSession['token'], [
        'id'     => $agent['id'],
        'action' => 'SET_STATUS',
        'status' => 'SUSPENDED',
    ]);

    $t->assertSame(200, $r['status'], 'the suspension succeeds, got ' . $r['status']);
    $t->assertSame('SUSPENDED', $r['body']['data']['status'] ?? null, 'the status is returned');
    $t->assertSame(0, $r['body']['data']['active_device_count'] ?? -1, 'the device was revoked by the suspension');

    $relogin = $login($agent);
    $t->assertSame(401, $relogin['result']['status'], 'a suspended account cannot log in, got ' . $relogin['result']['status']);
    $t->assertNotSame('AGENT_INACTIVE', $errorCode($relogin['result']), 'login refuses with the indistinguishable 401, not a new oracle');
});

$t->test('reinstate restores login with a fresh session', function (TestRunner $t) use ($update, $login, $adminSession, $agent) {
    $r = $update($adminSession['token'], [
        'id'     => $agent['id'],
        'action' => 'SET_STATUS',
        'status' => 'ACTIVE',
    ]);

    $t->assertSame(200, $r['status'], 'the reinstatement succeeds, got ' . $r['status']);
    $t->assertSame('ACTIVE', $r['body']['data']['status'] ?? null, 'the agent is active again');

    $relogin = $login($agent);
    $t->assertSame(200, $relogin['result']['status'], 'a reinstated account can log in again');
});

/* -- Retire ---------------------------------------------------------------- */

$t->group('retire');

$t->test('retiring is terminal: login dies, devices die, and nothing can change it', function (TestRunner $t) use ($update, $login, $list, $adminSession, $supervisor, $agents, $devices) {
    $r = $update($adminSession['token'], [
        'id'     => $supervisor['id'],
        'action' => 'SET_STATUS',
        'status' => 'DELETED',
    ]);

    $t->assertSame(200, $r['status'], 'the retirement succeeds, got ' . $r['status']);
    $t->assertSame('DELETED', $r['body']['data']['status'] ?? null, 'the status is returned');
    $t->assertSame(false, $r['body']['data']['has_credential'] ?? null, 'the credential was cleared');
    $t->assertSame(0, $r['body']['data']['active_device_count'] ?? -1, 'the devices were revoked');

    $relogin = $login($supervisor);
    $t->assertNotSame(200, $relogin['result']['status'], 'a retired account cannot log in');

    $revive = $update($adminSession['token'], [
        'id'     => $supervisor['id'],
        'action' => 'SET_STATUS',
        'status' => 'ACTIVE',
    ]);
    $t->assertSame(409, $revive['status'], 'retirement cannot be undone, got ' . $revive['status']);

    $retireAgain = $update($adminSession['token'], [
        'id'     => $supervisor['id'],
        'action' => 'SET_STATUS',
        'status' => 'DELETED',
    ]);
    $t->assertSame(409, $retireAgain['status'], 'a retired account is immutable, got ' . $retireAgain['status']);
});

/* -- Credentials ----------------------------------------------------------- */

$t->group('credentials');

$t->test('a password reset disables the old password and enables the new one', function (TestRunner $t) use ($update, $login, $create, $adminSession, $runTag) {
    $username = $runTag . '.pwreset';
    $password = 'old-pass-4921';

    $created = $create($adminSession['token'], [
        'agent_code' => $runTag . '-pwreset',
        'full_name'  => 'Password Reset',
        'username'   => $username,
        'password'   => $password,
    ]);

    $t->assertSame(201, $created['status'], 'the password-reset target is created');
    $id = (int) $created['body']['data']['id'];

    $r = $update($adminSession['token'], [
        'id'       => $id,
        'action'   => 'SET_PASSWORD',
        'password' => 'brand-new-pass-88',
    ]);

    $t->assertSame(200, $r['status'], 'the reset succeeds, got ' . $r['status']);

    $old = $login(['username' => $username, 'password' => $password]);
    $t->assertSame(401, $old['result']['status'], 'the old password no longer works');

    $new = $login(['username' => $username, 'password' => 'brand-new-pass-88']);
    $t->assertSame(200, $new['result']['status'], 'the new password works');
});

$t->test('an admin cannot reset a password for an account with no username', function (TestRunner $t) use ($update, $adminSession, $agents, &$createdAgentIds, $runTag) {
    $id = $agents->create($runTag . '-nouname', 'No Username', null);
    $createdAgentIds[] = $id;

    $r = $update($adminSession['token'], [
        'id'       => $id,
        'action'   => 'SET_PASSWORD',
        'password' => 'brand-new-pass-88',
    ]);

    $t->assertSame(409, $r['status'], 'a credentialless account cannot take a password reset, got ' . $r['status']);
});

$t->test('an admin may reset their own password, but not revoke their own credential', function (TestRunner $t) use ($update, $login, $adminSession, $admin) {
    $reset = $update($adminSession['token'], [
        'id'       => $admin['id'],
        'action'   => 'SET_PASSWORD',
        'password' => 'own-pass-4921',
    ]);

    $t->assertSame(200, $reset['status'], 'an admin can reset their own password, got ' . $reset['status']);

    $relogin = $login($admin, 'own-pass-4921');
    $t->assertSame(200, $relogin['result']['status'], 'the admin logs in with the new password');

    $revoke = $update($adminSession['token'], [
        'id'     => $admin['id'],
        'action' => 'REVOKE_CREDENTIAL',
    ]);

    $t->assertSame(409, $revoke['status'], 'an admin cannot revoke their own credential, got ' . $revoke['status']);
});

$t->test('an unknown target is a 404, not a crash', function (TestRunner $t) use ($update, $adminSession, $errorCode) {
    $r = $update($adminSession['token'], [
        'id'     => 999999999,
        'action' => 'SET_STATUS',
        'status' => 'SUSPENDED',
    ]);

    $t->assertSame(404, $r['status'], 'an unknown agent is a 404, got ' . $r['status']);
    $t->assertSame('UNKNOWN_AGENT', $errorCode($r), 'the code names the missing account');
});

/* -- Pairing codes ---------------------------------------------------------- */

$t->group('pairing-code');

$t->test('issuing a code returns it once, stores only its hash, and binds the first device', function (TestRunner $t) use (
    $create, $update, $login, $makeKeyPair, $adminSession, $admin, $runTag, $baseUrl, $appUrl,
    &$createdDeviceIds, $errorCode
) {
    $username = $runTag . '.pair';
    $password = 'pair-pass-4921';

    $created = $create($adminSession['token'], [
        'agent_code' => $runTag . '-pair',
        'full_name'  => 'Pairing Target',
        'username'   => $username,
        'password'   => $password,
    ]);

    $t->assertSame(201, $created['status'], 'the pairing target is created');
    $t->assertSame(0, $created['body']['data']['active_device_count'] ?? -1, 'the target starts with no devices');

    $id = (int) $created['body']['data']['id'];

    $r = $update($adminSession['token'], [
        'id'     => $id,
        'action' => 'ISSUE_PAIRING_CODE',
        'label'  => 'Field kit',
    ]);

    $t->assertSame(200, $r['status'], 'issuing succeeds, got ' . $r['status']);

    $code = $r['body']['data']['pairing_code'] ?? null;
    $t->assertTrue(is_string($code) && preg_match('/^\d{10}$/', $code) === 1, 'the response carries a 10-digit code');
    $t->assertTrue(is_int($r['body']['data']['ttl_seconds'] ?? null), 'the response carries the TTL');
    $t->assertTrue(is_string($r['body']['data']['expires_at'] ?? null), 'the response carries the expiry');
    $t->assertSame($id, $r['body']['data']['id'] ?? null, 'the code rides on the presented account row');

    $createdBy = Connection::fetchValue(
        'SELECT created_by FROM pairing_codes WHERE agent_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $id]
    );
    $t->assertSame($admin['agentCode'], $createdBy, 'the issuer is recorded on the row');

    $storedHash = Connection::fetchValue(
        'SELECT code_hash FROM pairing_codes WHERE agent_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $id]
    );
    $t->assertSame(PairingCode::hash((string) $code), $storedHash, 'only the code hash is stored');

    $label = Connection::fetchValue(
        'SELECT label FROM pairing_codes WHERE agent_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $id]
    );
    $t->assertSame('Field kit', $label, 'the optional label is stored');

    $session = $login(['username' => $username, 'password' => $password]);
    $t->assertSame(200, $session['result']['status'], 'the target logs in with its credential');

    $keys       = $makeKeyPair();
    $deviceUuid = Uuid::v4();

    $reg = HttpServer::sendJson(
        $baseUrl, $appUrl, 'POST', '/api/v1/device/register.php',
        [
            'device_uuid'    => $deviceUuid,
            'public_key_jwk' => $keys['public'],
            'pairing_code'   => (string) $code,
        ],
        (string) $session['token']
    );

    $t->assertSame(
        200, $reg['status'],
        'the issued code binds the first device, got ' . $reg['status'] . ' ' . $reg['raw']
    );

    $deviceId = (int) Connection::fetchValue('SELECT id FROM devices WHERE device_uuid = :u', ['u' => $deviceUuid]);
    $t->assertTrue($deviceId > 0, 'the device row exists after registering with the code');

    if ($deviceId > 0) {
        $createdDeviceIds[] = $deviceId;
    }

    $consumed = Connection::fetchValue(
        'SELECT consumed_at IS NOT NULL FROM pairing_codes WHERE agent_id = :id ORDER BY id DESC LIMIT 1',
        ['id' => $id]
    );
    $t->assertSame(1, (int) $consumed, 'the registration consumes the code');
});

$t->test('issuing is gated: AGENTs are refused, non-active accounts are refused, bad fields are 422', function (TestRunner $t) use (
    $create, $update, $adminSession, $pairGateToken, $pairGateAgent, $agent, $runTag, $errorCode
) {
    $forbidden = $update($pairGateToken, [
        'id'     => $pairGateAgent['id'],
        'action' => 'ISSUE_PAIRING_CODE',
    ]);
    $t->assertSame(403, $forbidden['status'], 'an AGENT cannot issue a code, got ' . $forbidden['status']);
    $t->assertSame('FORBIDDEN', $errorCode($forbidden), 'the refusal is FORBIDDEN');

    $suspend = $update($adminSession['token'], [
        'id'     => $agent['id'],
        'action' => 'SET_STATUS',
        'status' => 'SUSPENDED',
    ]);
    $t->assertSame(200, $suspend['status'], 'the fixture is suspended for this test');

    $refused = $update($adminSession['token'], [
        'id'     => $agent['id'],
        'action' => 'ISSUE_PAIRING_CODE',
    ]);
    $t->assertSame(409, $refused['status'], 'a suspended account is refused a code, got ' . $refused['status']);
    $t->assertSame('STATE_CONFLICT', $errorCode($refused), 'the refusal is a state conflict');

    $reinstate = $update($adminSession['token'], [
        'id'     => $agent['id'],
        'action' => 'SET_STATUS',
        'status' => 'ACTIVE',
    ]);
    $t->assertSame(200, $reinstate['status'], 'the fixture is reinstated for later groups');

    // A self-contained already-retired account (independent of the retire
    // group, so the assertion holds even when this group runs alone).
    $username = $runTag . '.dead';
    $created = $create($adminSession['token'], [
        'agent_code' => $runTag . '-dead',
        'full_name'  => 'Already Retired',
        'username'   => $username,
        'password'   => 'dead-pass-4921',
    ]);
    $t->assertSame(201, $created['status'], 'a retirement fixture is created');
    $retireId = (int) $created['body']['data']['id'];

    $retiredStatus = $update($adminSession['token'], [
        'id'     => $retireId,
        'action' => 'SET_STATUS',
        'status' => 'DELETED',
    ]);
    $t->assertSame(200, $retiredStatus['status'], 'the retirement fixture is retired');

    $retired = $update($adminSession['token'], [
        'id'     => $retireId,
        'action' => 'ISSUE_PAIRING_CODE',
    ]);
    $t->assertSame(
        409, $retired['status'],
        'a retired account is refused through the immutable guard, got ' . $retired['status']
    );
    $t->assertSame('STATE_CONFLICT', $errorCode($retired), 'the refusal is a state conflict');

    $unknown = $update($adminSession['token'], [
        'id'     => $agent['id'],
        'action' => 'ISSUE_PAIRING_CODE',
        'ttl'    => 9999,
    ]);
    $t->assertSame(422, $unknown['status'], 'an unknown field is refused, got ' . $unknown['status']);
});

$t->test('an admin may issue a pairing code for their own account', function (TestRunner $t) use ($update, $adminSession, $admin) {
    $r = $update($adminSession['token'], [
        'id'     => $admin['id'],
        'action' => 'ISSUE_PAIRING_CODE',
    ]);

    $t->assertSame(200, $r['status'], 'issuing for self succeeds (it cannot lock anyone out), got ' . $r['status']);
    $t->assertTrue(
        preg_match('/^\d{10}$/', (string) ($r['body']['data']['pairing_code'] ?? '')) === 1,
        'a code is returned'
    );
});

/* -- Invariant ------------------------------------------------------------- */

$t->group('invariant');

$t->test('the system never loses its last active administrator', function (TestRunner $t) use ($agents) {
    $remaining = Connection::fetchValue(
        'SELECT COUNT(*) FROM agents WHERE role = :role AND status = :status',
        ['role' => 'ADMIN', 'status' => AgentRepository::ACTIVE]
    );

    $t->assertTrue(is_numeric($remaining) && (int) $remaining >= 1, 'at least one ACTIVE ADMIN remains after the whole run');
});

/* ---------------------------------------------------------------------------
 * Exit
 * --------------------------------------------------------------------------- */

Cli::heading('FieldPulse admin contract (server ' . $serverVersion . ', database ' . $dbName . ')');

$code = $t->run($verbose);

exit($code);