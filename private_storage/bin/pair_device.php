<?php

declare(strict_types=1);

/**
 * Issue a one-time device pairing code.
 *
 *   php private_storage/bin/pair_device.php --code=AG-001
 *   php private_storage/bin/pair_device.php --code=AG-001 --label="replacement handset" --ttl=3600
 *
 * Prints the code exactly once. Only its SHA-256 is stored, so a database dump
 * does not yield a usable code, and there is no "reprint" — a lost code is
 * replaced by issuing another one.
 *
 * The agent signs in with their username and password, then enters this code in
 * the PWA, and the browser generates its P-256 keypair locally. The private key
 * never leaves the handset; only the public JWK is sent.
 *
 * No IMEI is involved at any point. It is not accepted by the login or
 * registration endpoints, so asking an operator to read it off a handset buys
 * nothing and teaches staff that it is part of authentication.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Database\AuditRepository;
use FieldPulse\Database\Connection;
use FieldPulse\Security\PairingCode;
use FieldPulse\Support\Clock;

Cli::init(__FILE__);

$argv    = Cli::argv();
$agents  = new AgentRepository();
$defaultTtl = Config::instance()->int('security.pairing_code_ttl', 1800);

$code    = Cli::option($argv, 'code');
$label   = Cli::option($argv, 'label');
$ttl     = (int) (Cli::option($argv, 'ttl', (string) $defaultTtl));
$created = get_current_user() !== '' ? get_current_user() : 'cli';

try {
    if ($code === null || $code === '') {
        Cli::fail('--code is required (the agent_code, e.g. AG-001)');
        exit(1);
    }

    $agent = $agents->findByCode($code);

    if ($agent === null) {
        Cli::fail('no agent with agent_code "' . $code . '"');
        exit(1);
    }

    if ($agent['status'] !== AgentRepository::ACTIVE) {
        Cli::fail('agent "' . $code . '" is ' . $agent['status'] . '; set them ACTIVE before pairing');
        exit(1);
    }

    $ttl = max(60, min($ttl, 86400));

    // Retry on the astronomically unlikely hash collision rather than failing:
    // a UNIQUE violation here means the same code already exists, which for a
    // 10-digit CSPRNG value is a broken RNG, and either way one more attempt
    // distinguishes the two cases for the operator.
    $pairingCode = null;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $candidate = PairingCode::random();

        try {
            Connection::execute(
                'INSERT INTO pairing_codes (
                    agent_id, code_hash, label, attempts, max_attempts, expires_at, created_by, created_at
                 ) VALUES (
                    :agent_id, :hash, :label, 0, 5, :expires_at, :created_by, UTC_TIMESTAMP()
                 )',
                [
                    'agent_id'    => (int) $agent['id'],
                    'hash'        => PairingCode::hash($candidate),
                    'label'       => $label === null ? null : mb_substr($label, 0, 100),
                    'expires_at'  => Clock::sql(Clock::shift($ttl)),
                    'created_by'  => mb_substr($created, 0, 100),
                ]
            );

            $pairingCode = $candidate;
            break;
        } catch (PDOException $e) {
            $isDuplicate = (int) ($e->errorInfo[1] ?? 0) === 1062
                || (string) ($e->errorInfo[0] ?? '') === '23000';

            if (!$isDuplicate) {
                throw $e;
            }
        }
    }

    if ($pairingCode === null) {
        Cli::fail('could not allocate a unique pairing code after 3 attempts');
        exit(1);
    }

    (new AuditRepository())->recordSafe([
        'actor_agent_id' => null,
        'action'         => 'pairing_code.issued',
        'entity_type'    => 'agent',
        'entity_id'      => (int) $agent['id'],
        'metadata'       => ['agent_code' => $code, 'label' => $label, 'ttl_seconds' => $ttl],
    ]);

    Cli::heading('Pairing code for ' . $code);
    Cli::out('');
    Cli::out('    ' . $pairingCode);
    Cli::out('');
    Cli::out('  Expires:  ' . Clock::sql(Clock::shift($ttl)) . ' UTC (' . $ttl . 's)');
    Cli::out('  Attempts: 5 before the code is burned');
    Cli::out('  Label:    ' . ($label ?? '(none)'));
    Cli::out('');
    Cli::warn('This is the only time the code is displayed. Only its SHA-256 is stored.');
    Cli::warn('Deliver it to the agent out of band, not by SMS to the handset being enrolled.');
    exit(0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    exit(1);
}
