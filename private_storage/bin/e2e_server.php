<?php

declare(strict_types=1);

/**
 * Serve the built app to a real browser.
 *
 * WHAT THIS IS FOR
 *
 * Every other suite in this repository exercises the app in process: PHP
 * handlers called directly, or HTTP over a socket with a synthesised request.
 * None of them can produce a genuine photo, a genuine IndexedDB, a genuine
 * service worker, or a genuine second browser tab, and none of them can lose a
 * response the way a real network loses one. Those are exactly the conditions
 * under which this application has to be correct — it is an offline-first
 * capture tool for phones on bad connections — so they get a real browser.
 *
 * WHAT IS REAL HERE
 *
 *   - the document root is a real deploy: bin/deploy.php publishes dist/ into a
 *     copy of public_html, so .htaccess, .user.ini and the API tree are exactly
 *     what a cPanel publish would leave behind;
 *   - the server is a real PHP SAPI over a real socket;
 *   - the database is the configured MySQL/MariaDB, with real migrations;
 *   - authentication, device keys, ECDSA signatures and the idempotency check
 *     are all production code paths;
 *   - the camera is the browser's own capture device, driven by Playwright's
 *     fake-device flags, so getUserMedia returns decodable frames.
 *
 * WHAT IS INJECTED
 *
 * Only three faults, and all three live in the test router rather than in
 * application code: a slow upload, a response lost after the server committed,
 * and (handled entirely in the browser) a killed tab. There is no test-only
 * branch in the application, no stubbed verification, and no flag that lets a
 * suite assert success by disabling the thing it is asserting.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Console\Cli;
use FieldPulse\Testing\HttpServer;

Cli::init(__FILE__);

$argv    = Cli::argv();
$repo    = dirname(__DIR__, 2);
$docRoot = $repo . '/.e2e_docroot';
$dist    = $repo . '/dist';
$public  = $repo . '/public_html';
$faults  = $repo . '/.e2e_faults.json';

/*
 * Port is pinned rather than OS-assigned. Playwright resolves baseURL before it
 * starts anything, so the port has to be knowable in advance; the contract
 * suites can let the OS choose because they read baseUrl back from the object.
 */
$port = (int) (Cli::option($argv, 'port') ?? '8791');

if (!is_file($dist . '/index.html')) {
    Cli::fail('No build at dist/. Run `npm run build` first.');
    exit(1);
}

if (!is_dir($public)) {
    Cli::fail('No document root template at ' . $public);
    exit(1);
}

/* ---------------------------------------------------------------------------
 * Stage a real deploy.
 *
 * Rebuilt from scratch every run rather than reused, so a stale hashed asset
 * from a previous build cannot make a test pass against code that is no longer
 * in dist/. The copy of public_html supplies the three files deploy.php refuses
 * to overwrite (.htaccess, .user.ini, api/), which is also what makes this a
 * faithful stand-in for a cPanel account rather than a bare static folder.
 * ------------------------------------------------------------------------ */

if (is_dir($docRoot)) {
    rrmdir($docRoot);
}

if (!copyTree($public, $docRoot)) {
    Cli::fail('Could not stage ' . $docRoot);
    exit(1);
}

Cli::heading('Document root');
Cli::out('  staged  ' . $docRoot);

// Publish through the real deploy path rather than copying dist by hand, so the
// suite exercises the same protection of .htaccess/.user.ini/api that a
// production publish relies on.
$deploy = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/private_storage/bin/deploy.php')
    . ' --skip-frontend --docroot=' . escapeshellarg($docRoot);

exec($deploy . ' 2>&1', $deployOut, $deployCode);

foreach ($deployOut as $line) {
    Cli::out('  deploy: ' . $line);
}

if ($deployCode !== 0) {
    Cli::fail('deploy.php exited ' . $deployCode . ' - not serving a partial tree.');
    exit(1);
}

// Reset the fault state so a crashed previous run cannot leak a delay into this
// one. Every spec arms what it needs explicitly.
file_put_contents($faults, json_encode(['submit' => ['mode' => 'none', 'ms' => 0]], JSON_PRETTY_PRINT));

/*
 * The upload ceiling is raised well above the shipped 6M so a test can prove the
 * client's own compression keeps a real photo inside the deployment's limit.
 * Raising it here rather than shipping a larger .user.ini keeps production
 * unchanged.
 *
 * APP_URL is overridden to this server's own address, which is not cosmetic and
 * not optional. The API checks the Origin header against APP_URL and answers 403
 * when they disagree. curl sends no Origin and sails through; a real browser
 * always sends one, so without this override every browser login is refused and
 * the suite fails on "Sign in failed" against credentials that are perfectly
 * valid. The override is written to a scratch copy of .env inside the harness's
 * own temp directory — the deployment's real .env is never modified.
 *
 * LOGIN_RATE_LIMIT is raised for the same reason the contract suites raise
 * theirs. The limiter is keyed on the client address, and every browser test
 * signs in from 127.0.0.1, so a handful of runs — or one debugging session that
 * retries — exhausts the real limit and the suite fails with "Too many attempts"
 * having tested nothing. The limit itself is unchanged in production and is
 * still asserted by bin/auth.php.
 */
$server = HttpServer::start(
    false,
    [
        'APP_URL' => 'http://127.0.0.1:' . $port,
        'LOGIN_RATE_LIMIT' => '10000',
        'REGISTER_RATE_LIMIT' => '10000',
    ],
    [],
    32_000_000,
    $docRoot,
    true,
    $port,
    $faults
);

Cli::heading('Serving');
Cli::out('  base URL  ' . $server->baseUrl);
Cli::out('  docroot   ' . $docRoot);
Cli::out('  faults    ' . $faults);
Cli::out('  log       ' . $server->logPath);
Cli::out('');
Cli::out('Press Ctrl-C to stop.');

/*
 * Hold the process open. The built-in server runs in a child process, so this
 * parent exists only to keep it alive and to clean it up; without it the runner
 * would exit the moment it printed the URL and take the server with it.
 *
 * The shutdown handler matters more than it looks: the child is a separate
 * `php -S` process, and a parent that exits without reaping it leaves a server
 * holding the port. The next run then fails with "address already in use" and
 * the actual cause — a previous run that was interrupted rather than
 * completed — is nowhere in the message.
 */
register_shutdown_function(static function () use ($server): void {
    $server->stop();
});

$running = true;

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
}

// Report the URL on stdout and flush, because Playwright waits for the port
// rather than this line; the line is for a human running it by hand.
while ($running) {
    sleep(1);
}

Cli::out('');
Cli::out('Stopping...');
$server->stop();

exit(0);

/* -------------------------------------------------------------------------- */

/**
 * Recursively copy a directory.
 *
 * Symlinks are not followed: the document root contains none, and following one
 * that points outside the tree would publish whatever it names into a server
 * that binds a real socket.
 */
function copyTree(string $from, string $to): bool
{
    if (!is_dir($to) && !mkdir($to, 0o755, true) && !is_dir($to)) {
        return false;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $relative = substr($item->getPathname(), strlen($from) + 1);
        $target   = $to . '/' . $relative;

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0o755, true) && !is_dir($target)) {
                return false;
            }
        } elseif (!copy($item->getPathname(), $target)) {
            return false;
        }
    }

    return true;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}
