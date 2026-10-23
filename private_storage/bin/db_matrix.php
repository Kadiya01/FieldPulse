<?php

declare(strict_types=1);

/**
 * Supported-database matrix.
 *
 *   php private_storage/bin/db_matrix.php
 *   php private_storage/bin/db_matrix.php --engine=mariadb # one cell
 *   php private_storage/bin/db_matrix.php --quick          # migrate + healthcheck only
 *
 *   FIELDPULSE_MARIADB_ENV=private_storage/.env.mariadb    # the MariaDB cell
 *   FIELDPULSE_MYSQL_ENV=private_storage/.env.mysql        # the MySQL cell
 *
 * WHY THIS EXISTS RATHER THAN A RUNBOOK
 *
 * "Supports MySQL 8 and MariaDB" is a claim with a version number attached, and
 * the version number is the part that rots. A schema that runs on MySQL 8.0.40
 * can fail on MariaDB 10.3 for one reason — `SELECT ... FOR UPDATE SKIP LOCKED`
 * — and nothing in a single-server test suite would notice, because on MySQL the
 * same code takes the native path and never exercises the fallback.
 *
 * So the matrix does the unglamorous thing: for each engine, in its own
 * environment file, apply every migration from empty and run the suites that
 * touch the database. A cell only reads PASS if migrations applied AND the
 * healthcheck is clean AND the database-facing suites are green.
 *
 * THE POINT OF A MATRIX
 *
 * Two engines is not a matrix, so the runner takes engines on the command line
 * and ships with one defined. Adding an engine is a new line, and the exit code
 * is non-zero if any cell fails — which is what makes it usable from CI or a
 * pre-release checklist rather than a thing a human reads once.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;

Cli::init(__FILE__);

$argv  = Cli::argv();
$quick = Cli::hasFlag($argv, 'quick');

/**
 * Every cell this runner knows how to test.
 *
 * A cell is named by the environment file it reads, and an engine cell other
 * than the application's own has no default: `getenv()` returning '' is a cell
 * the operator has not declared, and an undeclared cell is reported as NOT
 * CERTIFIED rather than quietly dropped. Quietly dropping it is how a matrix
 * ends up green while proving nothing about the engine it names.
 *
 * The ENGINE column printed in the matrix is not this list's key — it is read
 * back from `SELECT VERSION()` below, so a file called `.env` that happens to
 * point at MariaDB cannot be reported as MySQL.
 *
 * @return list<array{key:string,name:string,var:string|null,env:string|null}>
 */
function engines(): array
{
    global $argv;

    $var = static fn (string $name): string => (string) (getenv($name) ?: '');

    $defined = [
        // The server this checkout is configured against, and therefore the
        // one every other suite already runs against. It always exists.
        ['app', 'application configuration', null, dirname(__DIR__) . '/.env'],
        ['mariadb', 'MariaDB 11.4.13', 'FIELDPULSE_MARIADB_ENV', $var('FIELDPULSE_MARIADB_ENV')],
        ['mysql', 'MySQL 8.0.40', 'FIELDPULSE_MYSQL_ENV', $var('FIELDPULSE_MYSQL_ENV')],
    ];

    $filter = Cli::option($argv, 'engine');
    $cells  = [];

    foreach ($defined as [$key, $name, $varName, $env]) {
        if ($filter !== null && !str_contains($filter, $key)) {
            continue;
        }

        if ($varName !== null && $env === '') {
            /*
             * Asking for a cell by name and getting nothing back is an error,
             * not a skip: `--engine=mysql` with no server is an attempt to
             * certify something that cannot be certified here.
             */
            if ($filter !== null) {
                Cli::err($name . ' is not configured: set ' . $varName);

                exit(1);
            }

            $cells[] = ['key' => $key, 'name' => $name, 'var' => $varName, 'env' => null];

            continue;
        }

        if (!is_file($env) || !is_readable($env)) {
            Cli::err('environment file is not readable: ' . $env);

            exit(1);
        }

        $cells[] = ['key' => $key, 'name' => $name, 'var' => $varName, 'env' => $env];
    }

    if ($cells === []) {
        Cli::err('No engines selected. Nothing was tested, so nothing was proven.');

        exit(1);
    }

    return $cells;
}

/**
 * Turn a server version string into the engine family it actually is.
 *
 * `SELECT VERSION()` returns `11.4.13-MariaDB` on MariaDB and `8.0.40` on
 * MySQL; anything unrecognisable is reported verbatim rather than guessed at,
 * because a wrong guess here is the whole reason this function exists.
 */
function engineFamily(string $version): string
{
    if (stripos($version, 'mariadb') !== false) {
        return 'MariaDB';
    }

    if (preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
        return 'MySQL';
    }

    return $version;
}

/**
 * Run one child suite and return whether it passed.
 *
 * The suites print their own detail, so their output is forwarded rather than
 * swallowed: a matrix that only says "MariaDB: FAIL" sends the reader back to
 * the shell anyway, and the shell output is the thing worth reading.
 *
 * @param list<string> $args
 */
function runSuite(string $script, array $args, string $env): array
{
    $command = [PHP_BINARY, $script];

    foreach ($args as $arg) {
        $command[] = $arg;
    }

    $command[] = '--env=' . $env;

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process     = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), null);

    if (!is_resource($process)) {
        return ['code' => -1, 'out' => 'could not start ' . basename($script)];
    }

    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out];
}

$bin     = dirname(__DIR__) . '/bin';
$results = [];
$failed  = false;

foreach (engines() as $engine) {
    $key  = $engine['key'];
    $env  = $engine['env'];
    $name = $engine['name'];

    Cli::heading('Engine: ' . $name);

    if ($env === null) {
        /*
         * Nothing was run against this cell, so it is reported as NOT
         * CERTIFIED rather than dropped from the table. Dropping it is what
         * lets a matrix advertise two engines while only ever testing one.
         */
        Cli::warn('NOT CERTIFIED — ' . $engine['var'] . ' is not set');

        $results[$key] = [
            'name'      => $name,
            'var'       => $engine['var'],
            'server'    => null,
            'family'    => null,
            'steps'     => [],
            'floor'     => true,
            'certified' => false,
        ];

        continue;
    }

    $version = runSuite($bin . '/healthcheck.php', [], $env);

    preg_match('/connected:\s*([^\s]+)/', $version['out'], $m);

    $reported = $m[1] ?? 'unknown';
    $family   = engineFamily($reported);

    // The family is read back from the server, never taken from the cell name.
    Cli::out('  server reports ' . $reported . ' (' . $family . ')');

    $steps = [
        'migrations'  => [$bin . '/migrate.php', []],
        'healthcheck' => [$bin . '/healthcheck.php', []],
    ];

    /*
     * These touch the database, so they are the ones a matrix exists for.
     * selftest.php is deliberately absent: it is pure logic over values, so it
     * returns the same answer on both engines and would only slow the matrix
     * down. It belongs in the pre-merge gate, not in an engine matrix.
     */
    if (!$quick) {
        $steps['integrity']     = [$bin . '/integrity.php', []];
        $steps['integration']   = [$bin . '/integration.php', []];
        $steps['verification']  = [$bin . '/verification.php', []];
        $steps['queue']         = [$bin . '/queue.php', []];
        $steps['contract']      = [$bin . '/contract.php', []];
    }

    $cell = [
        'name'      => $name,
        'var'       => $engine['var'],
        'server'    => $reported,
        'family'    => $family,
        'steps'     => [],
        'floor'     => true,
        'certified' => true,
    ];

    foreach ($steps as $step => [$script, $args]) {
        $res = runSuite($script, $args, $env);
        $ok  = $res['code'] === 0;

        $cell['steps'][$step] = $ok;

        if ($ok) {
            Cli::ok($step);
            continue;
        }

        $failed = true;
        Cli::fail($step . ' (exit ' . $res['code'] . ')');

        // Only echo the tail: the suites are verbose and the failing part is
        // always at the end.
        $lines = array_values(array_filter(explode("\n", trim($res['out']))));
        foreach (array_slice($lines, -12) as $line) {
            Cli::out('    ' . $line);
        }
    }

    /*
     * A claim is only as good as its version number. If a suite says the engine
     * is older than the documented floor, that is a failure, not a footnote:
     * the documentation would otherwise advertise support that was never tested.
     */
    $floorOk = $quick || !preg_match('/older than the supported baseline/', $version['out']);
    $cell['floor'] = $floorOk;

    if (!$floorOk) {
        $failed = true;
        Cli::fail('engine is below the documented baseline');
    }

    $results[$key] = $cell;
}

Cli::heading('Matrix');

$width = max(array_map(static fn (array $r): int => strlen($r['name']), $results)) + 2;

printf("  %-{$width}s %-16s %s\n", 'CELL', 'SERVER', 'RESULT');

foreach ($results as $cell) {
    if (!$cell['certified']) {
        printf("  %-{$width}s %-16s %s\n", $cell['name'], '-', 'NOT CERTIFIED (' . $cell['var'] . ' not set)');

        continue;
    }

    $names = array_keys($cell['steps']);
    $bad   = array_keys(array_filter($cell['steps'], static fn (bool $ok): bool => !$ok));

    $line = $bad === [] && $cell['floor']
        ? 'PASS (' . implode(', ', $names) . ')'
        : 'FAIL (' . implode(', ', $bad === [] ? ['below baseline'] : $bad) . ')';

    printf("  %-{$width}s %-16s %s\n", $cell['name'], $cell['server'], $line);
}

Cli::heading('Certification');

$passing = [];
$missing = [];

foreach ($results as $cell) {
    if (!$cell['certified']) {
        $missing[$cell['name']] = $cell['var'];

        continue;
    }

    $bad = array_keys(array_filter($cell['steps'], static fn (bool $ok): bool => !$ok));

    if ($bad !== [] || !$cell['floor']) {
        continue;
    }

    $version = preg_replace('/-(mariadb|mysql)$/i', '', $cell['server']);

    $passing[$cell['family'] . ' ' . $version][] = $cell['name'];
}

foreach ($passing as $label => $cells) {
    printf("  %-22s PASS (%s)\n", $label, implode(', ', $cells));
}

if ($passing === []) {
    Cli::out('  no engine was certified');
}

Cli::heading('Verdict');

if ($failed) {
    Cli::fail('the supported database matrix is not green');

    exit(1);
}

if ($passing === []) {
    Cli::err('no engine was certified, so nothing was proven');

    exit(1);
}

$line = '  PASS — ' . count($passing) . ' engine(s) certified: ' . implode(', ', array_keys($passing));

if ($missing !== []) {
    $line .= PHP_EOL . '  cells not run, and therefore NOT CERTIFIED: ' . implode(', ', array_keys($missing));
}

Cli::out($line);

exit(0);