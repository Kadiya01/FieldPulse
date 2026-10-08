<?php

declare(strict_types=1);

/**
 * FieldPulse release gate.
 *
 *   php private_storage/bin/release_gate.php
 *   php private_storage/bin/release_gate.php --httpd=C:\path\to\httpd.exe
 *
 * The gate is the assertion that the repository is a release candidate. Every
 * tier is executed against a real server, a real browser or a real database —
 * nothing here is a stub, a skip or an inherited claim. A tier reports PASS only
 * when it actually ran and exited cleanly; anything shorter produces
 *
 *   FIELDPULSE RELEASE CANDIDATE: NOT READY
 *
 * and only a fully green run produces
 *
 *   FIELDPULSE RELEASE CANDIDATE: PASS
 *
 * TIERS
 *
 *   frontend    lint, unit tests, production build
 *   application fresh reset + migrate, then healthcheck, selftest, auth,
 *               contract, integrity, integration, verification, queue
 *   deployment  deploy_test against a real httpd binary (--httpd)
 *   browser     fresh reset + migrate, then the real Playwright suite (Chromium)
 *   database    fresh reset on both engine cells, then bin/db_matrix.php
 *
 * The database is reset to empty before every tier that reads it, so a green
 * run cannot be the residue of a server that was already populated. The matrix
 * cells are certified independently: each reports the engine back from
 * `SELECT VERSION()`, never from its configuration file.
 *
 * The gate itself holds no privileged secrets. It reads the same environment
 * files the application reads and hands child processes nothing but their
 * inherited environment.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Support\Env;

Cli::init(__FILE__);

$argv    = Cli::argv();
$repo    = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
$private = $repo . '/private_storage';
$bin     = $private . '/bin';
$appEnv  = $private . '/.env';
$mysqlEnv = $private . '/.env.mysql';
$mariaEnv = $private . '/.env.mariadb';
$httpd   = Cli::option($argv, 'httpd') ?? (getenv('FIELDPULSE_HTTPD') ?: null);

/**
 * The whole repository is built and tested from the root, so the gate fixes
 * one cwd and never reasons about anything else.
 */
if (!chdir($repo)) {
    Cli::err('cannot change into repository root: ' . $repo);
    exit(2);
}

if (!is_file($bin . '/healthcheck.php') || !is_dir($repo . '/public_html')) {
    Cli::err('this script must live at private_storage/bin and be run from the repository root');
    exit(2);
}

/*
 * Children inherit this process's environment. PHP_BINARY is this interpreter,
 * so the browser suite's serve.mjs and fixtures talk to the same PHP rather
 * than to whatever `php` happens to resolve to on PATH.
 */
putenv('FIELDPULSE_PHP=' . PHP_BINARY);
putenv('FIELDPULSE_MYSQL_ENV=' . $mysqlEnv);
putenv('FIELDPULSE_MARIADB_ENV=' . $mariaEnv);

$results = [];
$failed  = false;

/**
 * Record a tier result and echo a small verdict line.
 */
function tier(string $label, bool $ok): void
{
    global $results, $failed;

    $results[$label] = $ok;

    if ($ok) {
        Cli::ok($label);
    } else {
        $failed = true;
        Cli::fail($label);
    }
}

/**
 * Run a command from the repository root and return success.
 *
 * Captured output is trimmed to a tail so a failure is diagnosable without
 * burying the verdict in a wall of green.
 *
 * @param  list<string> $argv    the decomposed command
 * @param  int|null     $retries additional attempts after a failure
 */
function run(array $argv, ?int $retries = null): bool
{
    global $repo;

    $attempts = $retries === null ? 1 : $retries + 1;
    $tail     = '';

    for ($i = 1; $i <= $attempts; $i++) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($argv, $descriptors, $pipes, $repo, null);

        if (!is_resource($process)) {
            $tail = 'could not start ' . implode(' ', $argv);
            $code = -1;
        } else {
            $out  = (string) stream_get_contents($pipes[1]);
            $err  = (string) stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $code = proc_close($process);
            $tail = implode(PHP_EOL, array_filter(array_slice(explode(PHP_EOL, trim($out . PHP_EOL . $err)), -14)));
        }

        if ($code === 0) {
            return true;
        }

        if ($i < $attempts) {
            Cli::out('    retrying…');
        }
    }

    Cli::out($tail === '' ? '    <no output>' : '    ' . str_replace(PHP_EOL, PHP_EOL . '    ', $tail));

    return false;
}

/**
 * Locate the node interpreter, returning its absolute path or null.
 */
function nodeBin(): ?string
{
    $out = [];
    exec('where node 2>NUL', $out, $code);

    if ($code !== 0) {
        return null;
    }

    foreach ($out as $line) {
        $line = trim($line);

        if ($line !== '' && is_file($line)) {
            return $line;
        }
    }

    return null;
}

/**
 * Locate the npm CLI entry so npm can be launched through node directly.
 *
 * The npm.cmd shim relies on cmd.exe resolving `npm-cli.js` relative to a
 * directory it guesses on its own, and that guess has been observed to be wrong
 * when the shim is handed to a child process rather than a human typing into a
 * terminal. Running `node npm-cli.js run …` has no such ambiguity, so the gate
 * prefers that over a shim. An explicit NPM_CLI environment variable wins over
 * every probe.
 */
function npmCli(): ?string
{
    $override = getenv('NPM_CLI');

    if ($override !== false && $override !== '' && is_file($override)) {
        return $override;
    }

    $node = nodeBin();
    $seek = $node !== null ? [dirname($node) . '/node_modules/npm/bin/npm-cli.js'] : [];

    $appData = getenv('APPDATA');

    if ($appData !== false && $appData !== '') {
        $seek[] = $appData . '/npm/node_modules/npm/bin/npm-cli.js';
    }

    $seek[] = 'C:/Program Files/nodejs/node_modules/npm/bin/npm-cli.js';

    foreach ($seek as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Run one PHP suite from the repository root.
 */
function runPhp(string $script, ?string $flag = null): bool
{
    $argv = [PHP_BINARY, $script];

    if ($flag !== null) {
        $argv[] = $flag;
    }

    return run($argv);
}

/**
 * Run an npm script. npm itself runs through node against the resolved
 * npm-cli.js; see npmCli() for why the shim is not used.
 */
function runNpm(string $script, ?int $retries = null): bool
{
    $node = nodeBin();
    $cli  = npmCli();

    if ($node === null || $cli === null) {
        Cli::out('    could not locate node/npm for "' . $script . '"');

        return false;
    }

    return run([$node, $cli, 'run', $script], $retries);
}

/**
 * Reset every table in the database named by an environment file.
 *
 * Dropping the tables rather than the schema keeps this within the privileges
 * the application is granted (ALL on the named database) on both engines. The
 * foreign-key checks are switched off so the drop order does not matter.
 */
function resetDatabase(string $envFile): bool
{
    Env::load($envFile);

    $host = Env::get('DB_HOST', '127.0.0.1');
    $port = Env::get('DB_PORT') ?? '3306';
    $name = Env::get('DB_NAME', '');
    $user = Env::get('DB_USER', '');
    $pass = Env::get('DB_PASSWORD') ?? '';
    $dsn  = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';

    try {
        $pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT            => 10,
            \PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]);
    } catch (\PDOException $e) {
        Cli::out('    could not connect for reset: ' . $e->getMessage());

        return false;
    }

    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $rows = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($rows as $table) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (\PDOException $e) {
        Cli::out('    reset failed: ' . $e->getMessage());

        return false;
    }

    return true;
}

/**
 * Fresh reset plus migrate on one environment file.
 */
function freshMigrate(string $envFile, string $label): bool
{
    global $bin;

    if (!is_file($envFile)) {
        Cli::fail('environment file missing: ' . $envFile);

        return false;
    }

    Cli::out('  resetting ' . basename($envFile));

    if (!resetDatabase($envFile)) {
        Cli::fail('reset failed for ' . $envFile);

        return false;
    }

    $ok = runPhp($bin . '/migrate.php', '--env=' . $envFile);

    if (!$ok) {
        Cli::fail('migrate failed for ' . $envFile);
    }

    return $ok;
}

/*
 * ------------------------------------------------------------------ FRONTEND
 */
Cli::heading('Tier 1 — frontend');

tier('frontend: lint', runNpm('lint'));
tier('frontend: unit tests', runNpm('test', 1));
tier('frontend: build', runNpm('build', 1));

/*
 * ------------------------------------------------------------------ APPLICATION
 */
Cli::heading('Tier 2 — application');

$app = freshMigrate($appEnv, 'application database');

foreach ([
    ['healthcheck.php', 'application: healthcheck'],
    ['selftest.php', 'application: selftest'],
    ['auth.php', 'application: auth'],
    ['contract.php', 'application: contract'],
    ['integrity.php', 'application: integrity'],
    ['integration.php', 'application: integration'],
    ['verification.php', 'application: verification'],
    ['queue.php', 'application: queue'],
] as [$script, $label]) {
    tier($label, $app && runPhp($bin . '/' . $script));
}

/*
 * ------------------------------------------------------------------ DEPLOYMENT
 */
Cli::heading('Tier 3 — deployment');

if ($httpd === null || !is_file($httpd)) {
    Cli::warn('no httpd binary supplied (--httpd), and FIELDPULSE_HTTPD is empty');
    tier('deployment: httpd', false);
} else {
    tier(
        'deployment: httpd',
        runPhp($bin . '/deploy_test.php', '--httpd=' . $httpd)
    );
}

/*
 * ------------------------------------------------------------------ BROWSER
 */
Cli::heading('Tier 4 — browser (real Playwright suite)');

$browserDb = freshMigrate($appEnv, 'browser database');

if ($browserDb) {
    /*
     * The entire Chromium project is the certified suite. The edge project is
     * documented as an opt-in fallback that depends on an installed Edge, and
     * it is not part of the certification contract.
     */
    tier('browser: chromium e2e', runNpm('test:e2e:chromium'));
} else {
    tier('browser: chromium e2e', false);
}

/*
 * ------------------------------------------------------------------ DATABASE MATRIX
 */
Cli::heading('Tier 5 — supported database matrix');

$mysqlReady = is_file($mysqlEnv) && resetDatabase($mysqlEnv);
$mariaReady = is_file($mariaEnv) && resetDatabase($mariaEnv);

foreach (['MySQL 8.0.40' => $mysqlEnv, 'MariaDB 11.4.13' => $mariaEnv] as $cell => $envFile) {
    if (!is_file($envFile)) {
        Cli::warn('matrix cell not configured: ' . $cell . ' (' . $envFile . ' missing)');
        tier('database: ' . $cell, false);
    }
}

if ($mysqlReady && $mariaReady) {
    tier('database: matrix', runPhp($bin . '/db_matrix.php'));
} else {
    tier('database: matrix', false);
}

/*
 * ------------------------------------------------------------------ VERDICT
 */
Cli::heading('Release verdict');

$bad = array_keys(array_filter($results, static fn (bool $ok): bool => !$ok));

foreach ($results as $label => $ok) {
    printf("  %-28s %s\n", $label, $ok ? 'PASS' : 'FAIL');
}

Cli::heading('Gate');

if ($bad !== []) {
    foreach ($bad as $label) {
        Cli::fail($label);
    }

    Cli::out('FIELDPULSE RELEASE CANDIDATE: NOT READY');

    exit(1);
}

Cli::out('FIELDPULSE RELEASE CANDIDATE: PASS');

exit(0);