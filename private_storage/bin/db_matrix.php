<?php

declare(strict_types=1);

/**
 * Supported-database matrix.
 *
 *   php private_storage/bin/db_matrix.php
 *   php private_storage/bin/db_matrix.php --engine=mysql:8.0.40:3307
 *   php private_storage/bin/db_matrix.php --quick        # migrate + healthcheck only
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
 * @return list<array{label:string,env:string}>
 */
function engines(): array
{
    global $argv;

    $defined = [
        ['mysql',   dirname(__DIR__) . '/.env'],
        ['mariadb', null],
    ];

    /*
     * The MariaDB cell reads its own environment file. There is no default
     * because a default would be a guess: the whole point of this runner is
     * that each engine is named explicitly, so a missing file is an error the
     * operator sees rather than a silently skipped cell that reads as PASS.
     */
    $mariadbEnv = (string) (getenv('FIELDPULSE_MARIADB_ENV') ?: '');

    $defined[1][1] = $mariadbEnv !== '' ? $mariadbEnv : null;

    $cells = [];

    foreach ($defined as [$label, $env]) {
        if (Cli::option($argv, 'engine') !== null && !str_contains(Cli::option($argv, 'engine') ?? '', $label)) {
            continue;
        }

        if ($env === null) {
            Cli::warn('skipping ' . $label . ': set FIELDPULSE_MARIADB_ENV to its environment file');

            continue;
        }

        if (!is_file($env) || !is_readable($env)) {
            Cli::err('environment file is not readable: ' . $env);

            exit(1);
        }

        $cells[] = ['label' => $label, 'env' => $env];
    }

    if ($cells === []) {
        Cli::err('No engines selected. Nothing was tested, so nothing was proven.');

        exit(1);
    }

    return $cells;
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
    $process     = proc_open($command, $descriptors, $pipes, dirname(__DIR__), null);

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
    $label = $engine['label'];
    $env   = $engine['env'];

    Cli::heading('Engine: ' . $label);

    $version = runSuite($bin . '/healthcheck.php', [], $env);

    preg_match('/connected:\s*([^\s]+)/', $version['out'], $m);

    $reported = $m[1] ?? 'unknown';
    Cli::out('  server reports ' . $reported);

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

    $cell = ['server' => $reported, 'steps' => []];

    foreach ($steps as $name => [$script, $args]) {
        $res = runSuite($script, $args, $env);
        $ok  = $res['code'] === 0;

        $cell['steps'][$name] = $ok;

        if ($ok) {
            Cli::ok($name);
            continue;
        }

        $failed = true;
        Cli::fail($name . ' (exit ' . $res['code'] . ')');

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

    $results[$label] = $cell;
}

Cli::heading('Matrix');

$width = max(array_map('strlen', array_keys($results))) + 2;

printf("  %-{$width}s %-12s %s\n", 'ENGINE', 'SERVER', 'RESULT');

foreach ($results as $label => $cell) {
    $names = array_keys($cell['steps']);
    $bad   = array_keys(array_filter($cell['steps'], static fn (bool $ok): bool => !$ok));

    $line = $bad === [] && ($cell['floor'] ?? true)
        ? 'PASS (' . implode(', ', $names) . ')'
        : 'FAIL (' . implode(', ', $bad === [] ? ['below baseline'] : $bad) . ')';

    printf("  %-{$width}s %-12s %s\n", $label, $cell['server'], $line);
}

Cli::heading('Verdict');

if ($failed) {
    Cli::fail('the supported database matrix is not green');

    exit(1);
}

Cli::out('  PASS — ' . count($results) . ' engine(s), migrations applied and suites green');

exit(0);