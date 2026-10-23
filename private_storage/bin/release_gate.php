<?php

declare(strict_types=1);

/**
<<<<<<< HEAD
 * Release gate.
 *
 *   php private_storage/bin/release_gate.php
 *   php private_storage/bin/release_gate.php --httpd=/path/to/apache/bin/httpd
 *   php private_storage/bin/release_gate.php --no-reset          # do not reset test DBs
 *   php private_storage/bin/release_gate.php --skip-e2e          # dev only; verdict cannot be PASS
 *
 * WHY THIS EXISTS
 *
 * Every phase shipped with its own gate — selftest, auth, contract, integrity,
 * integration, verification, queue, deploy_test, db_matrix — and each one is
 * honest about the one thing it covers. None of them is honest about the whole,
 * and the failure that closes a release is almost always a gap *between* two
 * green suites, not a red one. This runner makes the whole set one command so
 * that a release candidate is a single run with a single verdict, rather than a
 * checklist a human is trusted to have done completely.
 *
 * WHAT IT WILL NOT DO
 *
 * A gate that reports PASS while a required check was skipped is worse than no
 * gate, because it launders a gap into a certification. So:
 *
 *   - a skipped browser suite is NOT PASS;
 *   - an Apache tier that did not run (no --httpd) is NOT PASS, even though
 *     deploy_test.php itself downgrades it to a warning;
 *   - a database matrix that certifies fewer than two engines, or an engine
 *     family other than MySQL and MariaDB, is NOT PASS;
 *   - a run that did not start from a freshly migrated database is NOT PASS.
 *
 * THE DATABASE RESET
 *
 * queue.php and the verification suites are deliberately not idempotent: the
 * perceptual-duplicate detector correctly rejects a second near-identical
 * fixture, so running them twice against one database reports a regression that
 * is not one. db_matrix.php exists to give each engine a clean schema, and this
 * runner extends the same discipline to the primary suite run, the deployment
 * tier and the browser suite. Each database named by an environment file is
 * dropped and recreated, then migrated, before the checks that need it. That is
 * destructive by design, so it refuses to touch a database whose APP_ENV is not
 * a test or development environment unless --force is given.
=======
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
>>>>>>> origin/main
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
<<<<<<< HEAD
use FieldPulse\Database\Connection;

Cli::init(__FILE__);

$argv = Cli::argv();

/*
 * Internal mode: reset one database named by an environment file and exit.
 *
 * This runs in a child process on purpose. Config::boot() is process-wide, so
 * resetting several environment files in one process would boot several times
 * and read all but the first wrong. A subprocess per engine keeps each boot
 * isolated.
 */
$resetEnv = Cli::option($argv, 'reset-env');

if ($resetEnv !== null) {
    Config::boot($resetEnv);

    $config = Config::instance();
    $appEnv = $config->str('app.env');
    $dbName = $config->str('db.name');
    $force  = Cli::hasFlag($argv, 'force');

    if (!$force && !in_array($appEnv, ['testing', 'development', 'local'], true)) {
        Cli::err('refusing to drop ' . $dbName . ': APP_ENV is "' . $appEnv . '", not a test environment (use --force)');

        exit(2);
    }

    // Backtick-quoted: the name comes from an env file, not user input, but a
    // bare identifier here would break on any deployment name that is not one.
    Connection::execute('DROP DATABASE IF EXISTS `' . $dbName . '`');
    Connection::execute('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    Cli::out('reset ' . $dbName . ' on ' . Connection::serverVersion());

    exit(0);
}

/* ===========================================================================
 * Configuration of the run
 * =========================================================================== */

$repoRoot   = dirname(__DIR__, 2);
$binDir     = __DIR__;
$phpBin     = PHP_BINARY;
$primaryEnv = Cli::option($argv, 'env') ?? ($repoRoot . '/private_storage/.env');

$httpd   = Cli::option($argv, 'httpd') ?? (getenv('FIELDPULSE_HTTPD') ?: null);
$fresh   = !Cli::hasFlag($argv, 'no-reset');
$force   = Cli::hasFlag($argv, 'force');
$skipE2e = Cli::hasFlag($argv, 'skip-e2e');

/*
 * Point the matrix at the secondary engines when it was not told otherwise.
 *
 * The files are gitignored (they hold a password), so they are a local
 * convention rather than a tracked artefact: if `.env.mariadb` exists the
 * MariaDB cell can run, and if it does not the cell reports NOT CERTIFIED
 * rather than being quietly dropped. A present-but-unreadable file is left to
 * db_matrix.php to report as the error it is.
 */
$mariaEnv = getenv('FIELDPULSE_MARIADB_ENV') ?: null;
$mysqlEnv = getenv('FIELDPULSE_MYSQL_ENV') ?: null;

if ($mariaEnv === null && is_file($repoRoot . '/private_storage/.env.mariadb')) {
    $mariaEnv = $repoRoot . '/private_storage/.env.mariadb';
    putenv('FIELDPULSE_MARIADB_ENV=' . $mariaEnv);
}

if ($mysqlEnv === null && is_file($repoRoot . '/private_storage/.env.mysql')) {
    $mysqlEnv = $repoRoot . '/private_storage/.env.mysql';
    putenv('FIELDPULSE_MYSQL_ENV=' . $mysqlEnv);
}

/*
 * The bundled OpenSSL config on Windows.
 *
 * Without it every test that touches a certificate (selftest, integrity,
 * integration, verification) fails at the JWK layer and looks like a code
 * fault. The caller is free to set OPENSSL_CONF; when they have not, the config
 * that ships beside the running PHP is the one that works, so it is found
 * rather than demanded.
 */
if (PHP_OS_FAMILY === 'Windows' && (getenv('OPENSSL_CONF') ?: '') === '') {
    foreach ([dirname($phpBin) . '/extras/ssl/openssl.cnf', dirname($phpBin) . '/../extras/ssl/openssl.cnf'] as $candidate) {
        if (is_file($candidate)) {
            putenv('OPENSSL_CONF=' . $candidate);

            break;
        }
    }
}

/* ===========================================================================
 * Process plumbing
 * =========================================================================== */

/**
 * Quote one token for cmd.exe / /bin/sh without over-quoting.
 *
 * escapeshellarg() wraps everything, including a bare `npm`, which cmd.exe's
 * leading-quote handling can then mangle. Only tokens that actually contain a
 * metacharacter are quoted; a double quote inside a double-quoted argument is
 * doubled, which is what cmd expects.
 *
 * @param list<string> $parts
 */
function commandLine(array $parts): string
{
    $quote = static function (string $part): string {
        if ($part === '') {
            return '""';
        }

        if (preg_match('/[\s"^&|<>()]/', $part) === 1) {
            return '"' . str_replace('"', '""', $part) . '"';
        }

        return $part;
    };

    return implode(' ', array_map($quote, $parts));
}

/**
 * Run one child process and capture its merged stdout/stderr.
 *
 * A string command through proc_open is what makes `npm` resolvable on Windows
 * (cmd.exe applies PATHEXT); an argument array would look for a file literally
 * named "npm" and fail. The child inherits this process's environment, which is
 * how the matrix env vars and OPENSSL_CONF reach it.
 *
 * @param list<string> $command
 *
 * @return array{code:int,out:string}
 */
function run(array $command, string $cwd): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process     = @proc_open(commandLine($command), $descriptors, $pipes, $cwd, null);

    if (!is_resource($process)) {
        return ['code' => -1, 'out' => 'could not start ' . $command[0]];
    }

    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out];
}

/** @var list<array{name:string,group:string,ok:bool,summary:string,out:string}> */
$results = [];

/**
 * Run one gate, record it, and print a single line for it.
 *
 * The validator returns a one-line summary on success and null on failure; null
 * is the only failure signal, so a gate cannot pass by printing encouraging
 * text.
 *
 * @param list<string> $command
 * @param callable(int,string):?string $validate
 */
function gate(array $command, string $cwd, callable $validate, string $name, string $group): void
{
    global $results;

    $res     = run($command, $cwd);
    $summary = $validate($res['code'], $res['out']);
    $ok      = $summary !== null;

    $results[] = [
        'name'    => $name,
        'group'   => $group,
        'ok'      => $ok,
        'summary' => $ok ? (string) $summary : 'FAILED (exit ' . $res['code'] . ')',
        'out'     => $res['out'],
    ];

    if ($ok) {
        Cli::ok(str_pad($name, 16) . $summary);

        return;
    }

    Cli::fail(str_pad($name, 16) . 'FAILED (exit ' . $res['code'] . ')');

    // The failing part of a verbose suite is always at the end.
    foreach (array_slice(array_values(array_filter(explode("\n", trim($res['out'])))), -15) as $line) {
        Cli::out('        ' . $line);
    }
}

/** Drop and recreate one database named by an environment file. */
function resetDatabase(string $envFile): void
{
    global $phpBin, $force;

    $command = [$phpBin, __FILE__, '--reset-env=' . $envFile];

    if ($force) {
        $command[] = '--force';
    }

    $res = run($command, dirname(__DIR__, 2));

    if ($res['code'] !== 0) {
        Cli::fail('could not reset ' . $envFile . ': ' . trim($res['out']));

        return;
    }

    Cli::out('  [reset] ' . trim($res['out']));
}

/** Apply every pending migration to an environment; false on failure. */
function migrate(string $envFile): bool
{
    global $phpBin, $binDir, $repoRoot;

    $res = run([$phpBin, $binDir . '/migrate.php', '--env=' . $envFile], $repoRoot);

    if ($res['code'] !== 0) {
        Cli::fail('migration failed for ' . $envFile);

        foreach (array_slice(array_values(array_filter(explode("\n", trim($res['out'])))), -10) as $line) {
            Cli::out('        ' . $line);
        }

=======
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

>>>>>>> origin/main
        return false;
    }

    return true;
}

<<<<<<< HEAD
/* ===========================================================================
 * Validators
 * =========================================================================== */

/** A suite passes on exit 0; its count is repeated when it reports one. */
function validateSuite(int $code, string $out): ?string
{
    if ($code !== 0) {
        return null;
    }

    if (preg_match('/passed:\s*(\d+)\s+failed:\s*(\d+)/', $out, $m) === 1) {
        return $m[1] . ' passed, ' . $m[2] . ' failed';
    }

    return 'passed';
}

/** vitest prints "Tests  N passed". */
function validateUnit(int $code, string $out): ?string
{
    if ($code !== 0) {
        return null;
    }

    return preg_match('/Tests\s+(\d+) passed/', $out, $m) === 1 ? ($m[1] . ' passed') : 'passed';
}

/** healthcheck's own verdict word is PASS or FAIL. */
function validateHealthcheck(int $code, string $out): ?string
{
    if ($code !== 0 || !str_contains($out, 'PASS')) {
        return null;
    }

    return str_contains($out, 'warning(s)') ? 'PASS with warnings' : 'PASS';
}

/**
 * deploy_test.php, with the skipped Apache tier treated as a failure.
 *
 * The suite downgrades a missing --httpd to a warning and still exits 0, which
 * is right for a developer running static checks and wrong for a release gate:
 * the status-code tier is the only thing that proves a real Apache reads the
 * .htaccess. So the warning text is a hard failure here.
 */
function validateDeploy(int $code, string $out): ?string
{
    if ($code !== 0) {
        return null;
    }

    if (preg_match('/did not run|no --httpd|tier was skipped/i', $out) === 1) {
        return null;
    }

    if (preg_match('/passed:\s*(\d+)\s+failed:\s*(\d+)/', $out, $m) === 1) {
        return $m[1] . ' passed, ' . $m[2] . ' failed, .htaccess tier ran';
    }

    return '.htaccess tier ran';
}

/**
 * The database matrix, requiring both engine families to be certified.
 *
 * "Two engines" is not enough: a run configured with two MySQL cells would
 * satisfy a count and certify nothing about MariaDB, which is the engine the
 * portability work exists for. The families are named explicitly.
 */
function validateMatrix(int $code, string $out): ?string
{
    if ($code !== 0) {
        return null;
    }

    if (preg_match('/PASS — (\d+) engine\(s\) certified: (.+)/', $out, $m) !== 1) {
        return null;
    }

    if (stripos($m[2], 'MariaDB') === false || stripos($m[2], 'MySQL') === false) {
        return null;
    }

    return $m[2];
}

function validateE2e(int $code, string $out): ?string
{
    if ($code !== 0) {
        return null;
    }

    return preg_match('/(\d+) passed/', $out, $m) === 1 ? ($m[1] . ' passed') : 'passed';
}

/** @return callable(int,string):?string */
function simpleValidator(): callable
{
    return static fn (int $code, string $out): ?string => $code === 0 ? 'ok' : null;
}

/* ===========================================================================
 * The run
 * =========================================================================== */

Cli::heading('FieldPulse release gate');
Cli::out('  primary env    ' . $primaryEnv);
Cli::out('  apache         ' . ($httpd ?? '(not given — the HTTP tier will FAIL)'));
Cli::out('  mariadb env    ' . ($mariaEnv ?? '(not set — the MariaDB cell cannot be certified)'));
Cli::out('  mysql env      ' . ($mysqlEnv ?? '(not set)'));
Cli::out('  reset test DBs ' . ($fresh ? 'yes (drop + recreate + migrate)' : 'no (--no-reset)'));
Cli::out('  browser suite  ' . ($skipE2e ? 'SKIPPED (--skip-e2e)' : 'required'));

/* --- 1. Frontend -------------------------------------------------------- */
Cli::heading('Frontend');
gate(['npm', 'run', 'build'], $repoRoot, simpleValidator(), 'build', 'frontend');
gate(['npm', 'run', 'lint'], $repoRoot, simpleValidator(), 'lint', 'frontend');
gate(['npm', 'test'], $repoRoot, 'validateUnit', 'unit', 'frontend');

/* --- 2. Application suites, against a fresh primary database ------------ */
Cli::heading('Application suites');

if ($fresh) {
    resetDatabase($primaryEnv);
}

migrate($primaryEnv);

gate([$phpBin, $binDir . '/healthcheck.php', '--env=' . $primaryEnv], $repoRoot, 'validateHealthcheck', 'healthcheck', 'application');
gate([$phpBin, $binDir . '/selftest.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'selftest', 'application');
gate([$phpBin, $binDir . '/auth.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'auth', 'application');
gate([$phpBin, $binDir . '/contract.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'contract', 'application');
gate([$phpBin, $binDir . '/integrity.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'integrity', 'application');
gate([$phpBin, $binDir . '/integration.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'integration', 'application');
gate([$phpBin, $binDir . '/verification.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'verification', 'application');
gate([$phpBin, $binDir . '/queue.php', '--env=' . $primaryEnv], $repoRoot, 'validateSuite', 'queue', 'application');

/* --- 3. Deployment and the real Apache tier ----------------------------- */
Cli::heading('Deployment / HTTP');

if ($fresh) {
    resetDatabase($primaryEnv);
}

migrate($primaryEnv);

$deploy = [$phpBin, $binDir . '/deploy_test.php'];

if ($httpd !== null) {
    $deploy[] = '--httpd=' . $httpd;
}

gate($deploy, $repoRoot, 'validateDeploy', 'deploy_test', 'deployment');

/* --- 4. Browser end-to-end ---------------------------------------------- */
Cli::heading('Browser end-to-end');

if ($skipE2e) {
    $results[] = ['name' => 'e2e', 'group' => 'browser', 'ok' => false, 'summary' => 'SKIPPED (--skip-e2e)', 'out' => ''];
    Cli::warn(str_pad('e2e', 16) . 'SKIPPED (--skip-e2e)');
} else {
    if ($fresh) {
        resetDatabase($primaryEnv);
    }

    migrate($primaryEnv);

    gate(['npm', 'run', 'test:e2e:chromium'], $repoRoot, 'validateE2e', 'e2e', 'browser');
}

/* --- 5. Database matrix ------------------------------------------------- */
Cli::heading('Database matrix');

if ($fresh) {
    resetDatabase($primaryEnv);

    if ($mariaEnv !== null) {
        resetDatabase($mariaEnv);
    }

    if ($mysqlEnv !== null) {
        resetDatabase($mysqlEnv);
    }
}

gate([$phpBin, $binDir . '/db_matrix.php'], $repoRoot, 'validateMatrix', 'db_matrix', 'database');

/* ===========================================================================
 * Report
 * =========================================================================== */

Cli::heading('Certified engines');

$dbText = '';

foreach ($results as $r) {
    if ($r['name'] === 'db_matrix') {
        $dbText = $r['out'];
    }
}

if (preg_match_all('/server reports ([^\s]+) \(([^)]+)\)/', $dbText, $mm, PREG_SET_ORDER) > 0) {
    foreach ($mm as $row) {
        Cli::out('  ' . str_pad($row[2], 10) . $row[1]);
    }
} else {
    Cli::out('  (no engine reported a version)');
}

Cli::heading('Summary');

foreach ($results as $r) {
    $line = str_pad($r['group'], 13) . str_pad($r['name'], 16) . $r['summary'];

    if ($r['ok']) {
        Cli::ok($line);
    } else {
        Cli::fail($line);
    }
}

/* --- Verdict ------------------------------------------------------------ */
$failed = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']));

Cli::heading('Verdict');

if ($failed === [] && $fresh) {
    Cli::out('  FIELDPULSE RELEASE CANDIDATE: PASS');

    exit(0);
}

Cli::fail('FIELDPULSE RELEASE CANDIDATE: NOT PASS');

if (!$fresh) {
    Cli::out('  reason: the run did not reset the test databases (--no-reset), so a fresh-database result was not established');
}

foreach ($failed as $r) {
    Cli::out('  failed: ' . $r['group'] . '/' . $r['name'] . ' — ' . $r['summary']);
}

exit(1);
=======
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
>>>>>>> origin/main
