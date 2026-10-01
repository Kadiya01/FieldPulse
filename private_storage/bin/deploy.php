<?php

declare(strict_types=1);

/**
 * Frontend deployment: build, then copy dist/ into the document root.
 *
 *   php private_storage/bin/deploy.php                       build + publish
 *   php private_storage/bin/deploy.php --skip-frontend        publish existing dist/
 *   php private_storage/bin/deploy.php --skip-build           test/lint, do not publish
 *   php private_storage/bin/deploy.php --docroot=/path/to/public_html
 *   php private_storage/bin/deploy.php --dry-run             print the plan, change nothing
 *
 * WHY A SCRIPT AND NOT A LINE OF cp IN THE RUNBOOK
 *
 * The documented deployment was `cp -r dist/. ~/public_html/`, followed by a
 * warning that a `rm -rf` first would delete .htaccess and .user.ini. A
 * warning is not a control: the runbook is read once, months later, by someone
 * who does not remember the warning, and the failure mode is a document root
 * with no .htaccess on it — which means no CSP, no dotfile denial, no suffix
 * allowlist and no SPA fallback. The whole PWA 404s or, worse, serves index.html
 * for missing API endpoints. deploy_test.php now asserts those headers, so the
 * next deploy would catch it, but the point is to make it impossible rather
 * than to catch it.
 *
 * So this script refuses to publish unless the files it protects are still in
 * place afterwards, and it is the only documented way to deploy. Three rules:
 *
 *   1. .htaccess and .user.ini are never written, never deleted, never moved.
 *      The build does not produce them, so there is nothing to merge.
 *   2. dist/ is copied file-by-file, not merged directory-by-directory. A
 *      stale assets/ chunk from the previous build is not carried forward,
 *      which matters because a client whose service worker still points at the
 *      old filename would otherwise keep fetching a chunk that no longer exists
 *      in the build — the 404 the comment in .htaccess is written about.
 *   3. The build gate is real. npm ci, lint and test all run before anything is
 *      copied, and a non-zero exit anywhere stops the deploy. A deploy that
 *      publishes a shell its own test suite rejects is not a deploy.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Support\Paths;

Cli::init(__FILE__);

$argv     = Cli::argv();
$dryRun   = Cli::hasFlag($argv, 'dry-run');
$doBuild  = !Cli::hasFlag($argv, 'skip-frontend');
$doPublish = !Cli::hasFlag($argv, 'skip-build');
$docrootOpt = Cli::option($argv, 'docroot');

/**
 * Files in the document root that belong to the operator, not to the build.
 * Anything in this list that the deploy would remove is a bug in the deploy.
 */
const PROTECTED_DOCROOT_FILES = ['.htaccess', '.user.ini', 'api'];

/* ---------------------------------------------------------------------------
 * Locate the two trees.
 * ------------------------------------------------------------------------ */

$baseRoot = dirname(FIELDPULSE_PRIVATE_ROOT);
$distDir  = $baseRoot . '/dist';
$docroot  = $docrootOpt ?? (Paths::documentRoots()[0] ?? null);

if ($docroot === null) {
    Cli::err('No document root found. Pass --docroot=/path/to/public_html.');
    exit(1);
}

if (!is_dir($docroot)) {
    Cli::err('Document root does not exist: ' . $docroot);
    exit(1);
}

Cli::heading('Deploy target');
Cli::out('  dist      ' . $distDir);
Cli::out('  docroot   ' . realpath($docroot) ?: $docroot);

/* ---------------------------------------------------------------------------
 * Frontend gate.
 * ------------------------------------------------------------------------ */

if ($doBuild) {
    $npm = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';

    $steps = [
        ['ci',   'npm ci',            'a lockfile that does not match package.json stops here'],
        ['lint', 'npm run lint',      'a build that fails its own linter stops here'],
        ['test', 'npm test',          'a build whose tests fail does not get published'],
        ['build', 'npm run build',    'the output is what actually ships'],
    ];

    foreach ($steps as [$name, $command, $why]) {
        Cli::heading('npm ' . $name);

        if ($dryRun) {
            Cli::out('  would run: ' . $command . '  (' . $why . ')');
            continue;
        }

        $exit = 0;
        passthru(escapeshellarg($npm) . ' ' . $command . ' 2>&1', $exit);

        if ($exit !== 0) {
            Cli::out('');
            Cli::fail($command . ' exited ' . $exit . ' — nothing was published. ' . ucfirst($why) . '.');
            exit($exit);
        }
    }
}

if (!$doPublish) {
    Cli::heading('Verdict');
    Cli::out('  PASS — frontend gate only, nothing published (--skip-build)');
    exit(0);
}

/* ---------------------------------------------------------------------------
 * Publish.
 * ------------------------------------------------------------------------ */

if (!is_dir($distDir)) {
    Cli::fail('No build output at ' . $distDir . '. Run npm run build first.');
    exit(1);
}

/**
 * Recursively list files in $dir as paths relative to it, sorted.
 *
 * Sorted so the deploy is deterministic and so two consecutive deploys of the
 * same build produce the same log, which is what makes a diff of the log
 * meaningful when something has gone wrong.
 *
 * @return list<string>
 */
function relativeFiles(string $dir, string $prefix = ''): array
{
    $out    = [];
    $handle = opendir($dir);

    if ($handle === false) {
        return $out;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path     = $dir . '/' . $entry;
        $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;

        if (is_dir($path)) {
            $out = array_merge($out, relativeFiles($path, $relative));
            continue;
        }

        $out[] = $relative;
    }

    closedir($handle);

    sort($out);

    return $out;
}

$files = relativeFiles($distDir);

if ($files === [] || !in_array('index.html', $files, true)) {
    Cli::fail('dist/ has no index.html — that is not a build output. Nothing was published.');
    exit(1);
}

Cli::heading('Publish');
Cli::out('  ' . count($files) . ' file(s) in dist/');

$protectedBefore = [];

foreach (PROTECTED_DOCROOT_FILES as $name) {
    $path = $docroot . '/' . $name;
    $protectedBefore[$name] = is_dir($path) ? 'dir' : (is_file($path) ? md5_file($path) : null);
}

if ($dryRun) {
    foreach ($files as $relative) {
        Cli::out('  would copy  ' . $relative);
    }

    Cli::heading('Verdict');
    Cli::out('  PASS — dry run, nothing changed');
    exit(0);
}

$copied = 0;

foreach ($files as $relative) {
    $from = $distDir . '/' . $relative;
    $to   = $docroot . '/' . $relative;
    $dir  = dirname($to);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        Cli::fail('Could not create ' . $dir);
        exit(1);
    }

    if (!@copy($from, $to)) {
        Cli::fail('Could not copy ' . $relative);
        exit(1);
    }

    $copied++;
}

Cli::out('  copied ' . $copied . ' file(s)');

/*
 * Remove stale hashed assets.
 *
 * Only assets/ is swept, and only files that are not in the build. Nothing else
 * in the document root is touched, so the protected files are not at risk — and
 * the guard below proves that rather than assuming it.
 */
$assetsDir = $docroot . '/assets';

if (is_dir($assetsDir)) {
    $keep = [];

    foreach ($files as $relative) {
        if (str_starts_with($relative, 'assets/')) {
            $keep[substr($relative, 7)] = true;
        }
    }

    $removed = 0;

    foreach (relativeFiles($assetsDir) as $relative) {
        if (isset($keep[$relative])) {
            continue;
        }

        if (@unlink($assetsDir . '/' . $relative)) {
            $removed++;
        }
    }

    Cli::out('  removed ' . $removed . ' stale asset(s)');
}

/* ---------------------------------------------------------------------------
 * Post-condition: the protected files survived.
 * ------------------------------------------------------------------------ */

Cli::heading('Protected files');

$problems = 0;

foreach ($protectedBefore as $name => $before) {
    $path = $docroot . '/' . $name;
    $after = is_dir($path) ? 'dir' : (is_file($path) ? md5_file($path) : null);

    if ($before === null) {
        // It was not there before, so the deploy did not remove it. Worth a
        // warning either way: a missing .htaccess means an unprotected docroot.
        if ($after === null && in_array($name, ['.htaccess', '.user.ini'], true)) {
            Cli::warn($name . ' was not present before or after the deploy');
            $problems++;
        }

        continue;
    }

    if ($after === $before) {
        Cli::ok($name . ' untouched');
        continue;
    }

    Cli::fail($name . ' changed during a deploy that should never have touched it');
    $problems++;
}

if ($problems > 0) {
    Cli::out('');
    Cli::fail($problems . ' protected-file problem(s). Restore the document root before serving traffic.');
    exit(1);
}

Cli::heading('Verdict');
Cli::out('  PASS — ' . $copied . ' file(s) published, ' . count(PROTECTED_DOCROOT_FILES) . ' protected entries verified');
exit(0);
