<?php

declare(strict_types=1);

/**
 * Seed a working administrator account.
 *
 *   php private_storage/bin/seed_admin.php
 *   php private_storage/bin/seed_admin.php --username=admin --password-stdin < secret.txt
 *   php private_storage/bin/seed_admin.php --code=ADMIN-002 --username=ops --name="Ops Lead"
 *
 * With no arguments it creates (or repairs) the default administrator:
 * username "admin", password "admin123", role ADMIN. That default is a
 * bootstrap convenience, not a credential to leave in place: the password is
 * printed on no screen and stored only as a bcrypt hash, but it is weak and
 * public, so the first thing an operator should do after running this is
 * change it (set_credentials.php) or revoke it.
 *
 * Idempotent by design. Provisioning an account is an operator action on a
 * server console (see provision_agent.php), and a seed that could only be run
 * once would fail the second time an environment was brought up. Re-running
 * this re-asserts the role, the credential, and ACTIVE status against the
 * agent identified by --code, so "make sure an admin exists" is a command that
 * can be run on every boot.
 *
 *   - the agent is looked up by agent_code, and created only when absent;
 *   - the role is set with AgentRepository::setRole(), the same method the
 *     account-management surface uses, so a seeded role cannot be a value the
 *     application would refuse to write;
 *   - the password goes through Credentials::hash(), the one place the codebase
 *     calls password_hash(), so the stored hash is one the login path accepts.
 *
 * The password may be supplied with --password=, read from stdin with
 * --password-stdin, or omitted to use the default. Passing it in argv is
 * visible in the process list to every user on the host and lands in shell
 * history, which is why --password-stdin exists; the plaintext is never echoed
 * and never written to the audit trail.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Domain\Validator;
use FieldPulse\Security\Credentials;

Cli::init(__FILE__);

$argv   = Cli::argv();
$agents = new AgentRepository();
$audit  = new AuditRepository();

const DEFAULT_USERNAME = 'admin';
const DEFAULT_PASSWORD = 'admin123';
const DEFAULT_CODE     = 'ADMIN-001';
const DEFAULT_NAME     = 'Administrator';

$allowedRoles = ['AGENT', 'SUPERVISOR', 'ADMIN'];

$code     = (string) (Cli::option($argv, 'code', DEFAULT_CODE));
$name     = trim((string) (Cli::option($argv, 'name', DEFAULT_NAME)));
$username = (string) (Cli::option($argv, 'username', DEFAULT_USERNAME));
$role     = strtoupper((string) (Cli::option($argv, 'role', 'ADMIN')));

try {
    if (preg_match('/^[A-Za-z0-9._-]{2,64}$/', $code) !== 1) {
        Cli::fail('--code must use letters, digits, dot, underscore or dash (2-64 chars)');
        exit(1);
    }

    if ($name === '' || mb_strlen($name) > 191) {
        Cli::fail('--name is required (max 191 characters)');
        exit(1);
    }

    if (!in_array($role, $allowedRoles, true)) {
        Cli::fail('--role must be one of: ' . implode(', ', $allowedRoles));
        exit(1);
    }

    $username = Validator::username($username);

    /*
     * Resolve the password without ever echoing it. --password-stdin wins when
     * present; otherwise --password; otherwise the documented default. A
     * trailing newline from a piped secret is stripped, because the operator
     * cannot see it and a hidden "\n" is not part of the password they meant.
     */
    $usingDefault = false;

    if (Cli::hasFlag($argv, 'password-stdin')) {
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
    } elseif (($given = Cli::option($argv, 'password')) !== null) {
        $password = $given;
    } else {
        $password  = DEFAULT_PASSWORD;
        $usingDefault = true;
    }

    // Enforce the same policy the admin API enforces (Validator::password):
    // 8-128 characters with at least one letter and one digit. A seeded admin
    // whose credential the account-management surface would itself refuse is a
    // contradiction worth preventing here rather than discovering later.
    $password = Validator::password($password);

    $byCode     = $agents->findByCode($code);
    $byUsername = $agents->findByUsername($username);

    if ($byCode !== null && $byUsername !== null && (int) $byUsername['id'] !== (int) $byCode['id']) {
        Cli::fail('username "' . $username . '" is already taken by agent ' . (string) $byUsername['agent_code']);
        exit(1);
    }

    if ($byCode === null && $byUsername !== null) {
        // The username belongs to a different agent_code. Reassigning it here
        // would be a silent takeover of another account, so refuse.
        Cli::fail(
            'username "' . $username . '" is already taken by agent ' . (string) $byUsername['agent_code']
            . '; pass a different --username or --code'
        );
        exit(1);
    }

    $created = false;

    if ($byCode === null) {
        $agentId = $agents->create($code, $name, null);
        $created = true;
    } else {
        $agentId = (int) $byCode['id'];
    }

    $agents->setRole($agentId, $role);
    $agents->setCredentials($agentId, $username, Credentials::hash($password));

    // A seed whose account cannot log in is not a seed. Status is re-asserted so
    // a suspended or deleted row is brought back to ACTIVE; the credential and
    // role are worthless without it.
    $row = $agents->findById($agentId);

    if ($row !== null && ($row['status'] ?? null) !== AgentRepository::ACTIVE) {
        $agents->setStatus($agentId, AgentRepository::ACTIVE);
        Cli::warn('agent was not ACTIVE; status reset to ACTIVE');
    }

    $audit->recordSafe([
        'actor_agent_id' => null,
        'action'         => 'admin.seeded',
        'entity_type'    => 'agent',
        'entity_id'      => $agentId,
        'metadata'       => [
            'agent_code' => $code,
            'username'   => $username,
            'role'       => $role,
            'created'    => $created,
        ],
    ]);

    Cli::heading($created ? 'Admin created' : 'Admin updated');
    Cli::out('');
    Cli::ok('agent_id:   ' . $agentId);
    Cli::ok('agent_code: ' . $code);
    Cli::ok('name:       ' . $name);
    Cli::ok('username:   ' . $username);
    Cli::ok('role:       ' . $role);
    Cli::ok('password:   set (bcrypt, not displayed)');
    Cli::out('');
    Cli::out('  Sign in with the username and password above, then register a device');
    Cli::out('  with php private_storage/bin/pair_device.php --code=' . $code . '.');

    if ($usingDefault) {
        Cli::out('');
        Cli::warn('the default password "' . DEFAULT_PASSWORD . '" is public; change it now with');
        Cli::out('         php private_storage/bin/set_credentials.php --code=' . $code . ' --username=' . $username);
    }

    exit(0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
