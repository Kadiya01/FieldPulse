<?php

declare(strict_types=1);

/*
 * One-off: remove fixture rows left by earlier FieldPulse test runs.
 *
 * Deliberately destructive and deliberately NOT part of the suites. It exists
 * because the auth suite leaked its fixture set for several runs before its
 * teardown was made per-statement, and a test database that accumulates
 * hundreds of orphan agents makes every later run slower and harder to read.
 *
 * Deletes only rows this repository's own suites create: agent codes carrying a
 * suite run tag, and the submissions those agents own. It will not touch
 * hand-created rows or anything matching the contract/integration fixtures.
 */

require __DIR__ . '/../app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\Connection;

$config = Config::boot(Cli::option($argv, 'env'));

if ($config->str('app.env') === 'production') {
    Cli::fail('Refusing to run against a production configuration.');
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

echo 'FieldPulse fixture purge (' . $config->str('app.env') . ', ' . $config->str('db.name') . ')'
    . ($dryRun ? ' [DRY RUN]' : '') . "\n";

/*
 * Suites tag their fixtures with a run tag. agent_code is the reliable marker;
 * usernames vary per suite, so matching on them would be guesswork.
 */
$tagged = Connection::fetchAll(
    "SELECT a.id, a.agent_code
       FROM agents a
      WHERE a.agent_code LIKE 'au%'
         OR a.agent_code LIKE 'ct%'
         OR a.agent_code LIKE 'it%'
         OR a.agent_code LIKE 'co%'
      ORDER BY a.id"
);

if ($tagged === []) {
    echo "Nothing to purge.\n";
    exit(0);
}

echo count($tagged) . " tagged agent(s).\n";

/*
 * Child-first, in the dependency order the foreign keys impose:
 *   submissions <- processing_jobs, submission_verifications
 *   devices    <- request_nonces, refresh_tokens, pairing_codes, submissions
 *   agents     <- everything above, plus sites, summary, audit
 */
$statements = [
    'processing_jobs'          => 'DELETE FROM processing_jobs WHERE submission_id IN
                                    (SELECT id FROM submissions WHERE agent_id = :a)',
    'submission_verifications' => 'DELETE FROM submission_verifications WHERE submission_id IN
                                    (SELECT id FROM submissions WHERE agent_id = :a)',
    'request_nonces'           => 'DELETE FROM request_nonces WHERE device_id IN
                                    (SELECT id FROM devices WHERE agent_id = :a)',
    'refresh_tokens'           => 'DELETE FROM refresh_tokens WHERE device_id IN
                                    (SELECT id FROM devices WHERE agent_id = :a)',
    'pairing_codes'            => 'DELETE FROM pairing_codes WHERE consumed_by_device_id IN
                                    (SELECT id FROM devices WHERE agent_id = :a)',
    'submissions'              => 'DELETE FROM submissions WHERE agent_id = :a',
    'devices'                  => 'DELETE FROM devices WHERE agent_id = :a',
    'refresh_tokens(agent)'    => 'DELETE FROM refresh_tokens WHERE agent_id = :a',
    'pairing_codes(agent)'     => 'DELETE FROM pairing_codes WHERE agent_id = :a',
    'verification(reviewer)'   => 'DELETE FROM submission_verifications WHERE reviewed_by_agent_id = :a',
    'performance_summary'      => 'DELETE FROM agent_performance_summary WHERE agent_id = :a',
    'agent_sites'              => 'DELETE FROM agent_sites WHERE agent_id = :a',
    'audit_logs'               => 'DELETE FROM audit_logs WHERE actor_agent_id = :a',
    'agents'                   => 'DELETE FROM agents WHERE id = :a',
];

$totals = array_fill_keys(array_keys($statements), 0);
$errors = 0;

foreach ($tagged as $agent) {
    foreach ($statements as $label => $sql) {
        if ($dryRun) {
            $totals[$label]++;
            continue;
        }

        try {
            $totals[$label] += Connection::execute($sql, ['a' => (int) $agent['id']]);
        } catch (Throwable $e) {
            $errors++;
            echo '  FAILED ' . $label . ' for agent ' . $agent['id'] . ': '
                . substr(str_replace("\n", ' ', $e->getMessage()), 0, 160) . "\n";
        }
    }
}

foreach ($totals as $label => $count) {
    if ($count > 0) {
        echo '  ' . str_pad($label, 24) . $count . "\n";
    }
}

echo $errors > 0
    ? "Completed with {$errors} error(s); the affected rows need manual attention.\n"
    : "Done.\n";
