<?php

declare(strict_types=1);

/**
 * Enrol an agent.
 *
 *   php private_storage/bin/provision_agent.php --code=AG-001 --name="Ada Okafor" --imei=353456789012345
 *   php private_storage/bin/provision_agent.php --code=AG-002 --name="Bo Nwosu" --imei=353456789012346 --role=SUPERVISOR
 *
 * This is the only way an agent comes into existence, and it is deliberately an
 * operator action on a server console rather than an API endpoint: enrolment
 * creates the identity that every other credential is derived from, so it must
 * never be reachable over HTTP.
 *
 * IMEI is validated with a Luhn check and stored as a string. It is an
 * administrative attribute only: it is not a login input, not an authentication
 * factor, and not required to register a device. See Security\PairingCode for
 * the factor that does stand between a stolen password and a new device.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Database\AgentRepository;
use FieldPulse\Domain\Validator;
use FieldPulse\Support\Logger;

Cli::init(__FILE__);

$argv    = Cli::argv();
$agents  = new AgentRepository();

$code    = Cli::option($argv, 'code');
$name    = Cli::option($argv, 'name');
$imei    = Cli::option($argv, 'imei');
$role    = strtoupper((string) (Cli::option($argv, 'role', 'AGENT')));
$siteLat = Cli::option($argv, 'site-lat');
$siteLng = Cli::option($argv, 'site-lng');
$siteRad = Cli::option($argv, 'site-radius', '250');
$siteNam = Cli::option($argv, 'site-name');

$allowedRoles = ['AGENT', 'SUPERVISOR', 'ADMIN'];

try {
    if ($code === null || preg_match('/^[A-Za-z0-9._-]{2,64}$/', $code) !== 1) {
        Cli::fail('--code is required (letters, digits, dot, underscore or dash; 2-64 chars)');
        exit(1);
    }

    if ($name === null || trim($name) === '' || mb_strlen(trim($name)) > 191) {
        Cli::fail('--name is required (max 191 characters)');
        exit(1);
    }

    if (!in_array($role, $allowedRoles, true)) {
        Cli::fail('--role must be one of: ' . implode(', ', $allowedRoles));
        exit(1);
    }

    // Validator::imei() raises a 422-flavoured ApiException, which is the wrong
    // shape for a console tool, so the failure is caught and re-reported plainly.
    $cleanImei = null;

    if ($imei !== null && trim($imei) !== '') {
        try {
            $cleanImei = Validator::imei($imei, 'imei');
        } catch (Throwable $e) {
            Cli::fail('--imei is not a valid 15-digit Luhn IMEI: ' . $e->getMessage());
            exit(1);
        }
    }

    if ($agents->findByCode($code) !== null) {
        Cli::fail('agent_code "' . $code . '" is already enrolled');
        exit(1);
    }

    if ($cleanImei !== null && $agents->findByImei($cleanImei) !== null) {
        Cli::fail('that IMEI is already bound to another agent');
        exit(1);
    }

    $agentId = $agents->create($code, trim($name), $cleanImei);

    \FieldPulse\Database\Connection::execute(
        'UPDATE agents SET role = :role WHERE id = :id',
        ['role' => $role, 'id' => $agentId]
    );

    Cli::heading('Enrolled');
    Cli::ok('agent_id:   ' . $agentId);
    Cli::ok('agent_code: ' . $code);
    Cli::ok('name:       ' . trim($name));
    Cli::ok('role:       ' . $role);
    Cli::ok('imei:       ' . ($cleanImei ?? '(none yet — bind with --imei later)'));

    if ($siteLat !== null && $siteLng !== null) {
        $coordinates = Validator::coordinates($siteLat, $siteLng, 'site');

        \FieldPulse\Database\Connection::execute(
            'INSERT INTO agent_sites (agent_id, name, center_latitude, center_longitude, radius_m, is_active, created_at, updated_at)
             VALUES (:agent_id, :name, :lat, :lng, :radius, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'agent_id' => $agentId,
                'name'     => $siteNam ?? ('Site for ' . $code),
                'lat'      => $coordinates['latitude'],
                'lng'      => $coordinates['longitude'],
                'radius'   => max(10, (int) $siteRad),
            ]
        );

        Cli::ok('site:       ' . ($siteNam ?? ('Site for ' . $code)) . ' r=' . max(10, (int) $siteRad) . 'm');
    } else {
        Cli::out('');
        Cli::out('  No geofence centre set. Until one is assigned, every submission from this');
        Cli::out('  agent will be flagged SITE_UNASSIGNED and land in the review queue.');
        Cli::out('  Re-run with --site-lat/--site-lng to add one.');
    }

    (new \FieldPulse\Database\AuditRepository())->recordSafe([
        'actor_agent_id' => null,
        'action'         => 'agent.provisioned',
        'entity_type'    => 'agent',
        'entity_id'      => $agentId,
        'metadata'       => ['agent_code' => $code, 'role' => $role, 'imei_bound' => $cleanImei !== null],
    ]);

    Cli::out('');
    Cli::out('  Next: php private_storage/bin/pair_device.php --code=' . $code);
    exit(0);
} catch (Throwable $e) {
    Cli::fail($e->getMessage());
    Logger::error('provision_agent.failed', ['error' => $e->getMessage()]);
    exit(1);
}
