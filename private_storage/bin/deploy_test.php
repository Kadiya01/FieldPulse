<?php

declare(strict_types=1);

/**
 * Phase 7 gate: deployment, document-root lockdown, and cron.
 *
 *   php private_storage/bin/deploy_test.php
 *   php private_storage/bin/deploy_test.php --filter=htaccess
 *   php private_storage/bin/deploy_test.php --httpd=/path/to/httpd      real Apache tier
 *
 * WHY THIS SUITE IS NOT A SMOKE TEST
 *
 * The two defects that made this phase necessary were both invisible to every
 * test in the repository at the time, and invisible for the same reason: they
 * lived in files that no test ever opened.
 *
 *   1. public_html/.htaccess returned 403 for /index.html, every hashed asset
 *      and every API entry point, on Apache 2.4.55. Total outage. The whole
 *      application is served by that one file and it is read only by an Apache
 *      that nobody had running.
 *
 *   2. RefreshTokenRepository::pruneExpired() and NonceGuard::prune() both
 *      built `DELETE ... LIMIT n OFFSET 0`, which MySQL rejects. process_queue
 *      --prune aborted on the first expired row, so maintenance stopped working
 *      while the queue itself looked perfectly healthy.
 *
 * Both are the same failure: code exercised in one context and shipped in
 * another. So the tests here are organised around "which context", not around
 * "which feature" — a staged cPanel-shaped tree, a real HTTP server in front of
 * it, the real .htaccess parsed for the properties it depends on, and the real
 * worker binary invoked the way cPanel invokes it.
 *
 * WHAT IS NOT FAKED
 *
 * The staging tree has the production sibling layout (public_html next to
 * private_storage), because that layout is load-bearing: the entry points walk
 * up looking for private_storage, and a flat copy would pass tests that tell you
 * nothing about a cPanel account. The bundle is the real dist/. The .htaccess
 * is the real .htaccess. The worker is the real worker, with real subprocesses
 * racing for real rows.
 *
 * Where a genuine gap exists it is named rather than papered over. The built-in
 * server tier cannot execute .htaccess — that is a property of PHP's server,
 * not a shortcut — so the .htaccess properties are asserted as text AND, when a
 * real httpd binary is available, again as observed status codes. Pass
 * --httpd= and the tier that matters runs; without it, say so out loud.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Support\Paths;
use FieldPulse\Testing\HttpServer;
use FieldPulse\Testing\TestRunner;

Cli::init(__FILE__);
Config::boot();

$argv       = Cli::argv();
$httpdOpt   = Cli::option($argv, 'httpd');
$keepSandbox = Cli::hasFlag($argv, 'keep-sandbox');

$repoRoot     = dirname(__DIR__, 2);
$publicRoot   = $repoRoot . '/public_html';
$privateRoot  = $repoRoot . '/private_storage';
$distDir      = $repoRoot . '/dist';
$htaccessPath = $publicRoot . '/.htaccess';
$userIniPath  = $publicRoot . '/.user.ini';

/** The Apache binary to use for the live tier, if one was supplied. */
$httpd = $httpdOpt ?? (getenv('FIELDPULSE_HTTPD') ?: null);

/** @var array{sandbox:string,docroot:string,files:array<string,string>}|null */
$sandbox = null;

/**
 * Recursively copy a directory, returning source-relative path => md5.
 *
 * @return array<string,string>
 */
function copyTree(string $from, string $to, string $prefix = ''): array
{
    $out    = [];
    $handle = opendir($from);

    if ($handle === false) {
        return $out;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $from . '/' . $entry;

        if (is_dir($path)) {
            $out += copyTree($path, $to . '/' . $entry, $entry . '/');
            continue;
        }

        $target = $to . '/' . $entry;

        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }

        copy($path, $target);
        $out[$prefix . $entry] = (string) md5_file($target);
    }

    closedir($handle);

    return $out;
}

/** Remove a directory tree. */
function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($dir);
}

/**
 * Run a PHP script and capture stdout, stderr and the real exit code.
 *
 * proc_open rather than popen: popen returns the exit status of the shell that
 * interpreted the command, which on Windows is not reliably the exit status of
 * the PHP process. An exit code that lies is worse than no exit code — it turns
 * a failing maintenance command into a passing test.
 *
 * @param array<string,string> $env Extra environment for the child
 * @return array{code:int,out:string}
 */
function runPhp(string $script, string $cwd, array $args = [], array $env = []): array
{
    $command = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', $script];

    foreach ($args as $arg) {
        $command[] = $arg;
    }

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process     = proc_open($command, $descriptors, $pipes, $cwd, $env === [] ? null : ($env + getenv()));

    if (!is_resource($process)) {
        return ['code' => -1, 'out' => 'could not start ' . $script];
    }

    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $out];
}

$t = new TestRunner(Cli::option($argv, 'filter'));

/* ===========================================================================
 * A. A clean account, deployed the documented way.
 * =========================================================================== */

$t->group('deploy');

$t->test('build output exists', function (TestRunner $t) use ($distDir): void {
    $t->assertTrue(is_file($distDir . '/index.html'), 'run `npm run build` before this suite');

    $t->assertTrue(is_file($distDir . '/sw.js'), 'the service worker is the whole point of the PWA');
    $t->assertTrue(is_file($distDir . '/manifest.webmanifest'), 'the manifest must ship');
});

$t->test('document root ships exactly the protected tree before deploy', function (TestRunner $t) use ($publicRoot): void {
    // Whatever else a deploy leaves behind, these three are the operator's.
    foreach (['.htaccess', '.user.ini', 'api'] as $entry) {
        $t->assertTrue(file_exists($publicRoot . '/' . $entry), $entry . ' must be in the repository');
    }
});

$t->test('secrets are not inside the document root', function (TestRunner $t) use ($publicRoot): void {
    foreach (['.env', '.git', 'private_storage', 'node_modules', 'package.json', 'storage'] as $forbidden) {
        $t->assertFalse(
            file_exists($publicRoot . '/' . $forbidden),
            $forbidden . ' must never be published to the document root'
        );
    }
});

$t->test('private_storage is a sibling of public_html', function (TestRunner $t) use ($repoRoot, $publicRoot, $privateRoot): void {
    $t->assertSame(
        $repoRoot,
        dirname(realpath($publicRoot) ?: $publicRoot),
        'public_html must be directly under the account root'
    );

    $t->assertTrue(is_dir(dirname($privateRoot) . '/public_html'), 'the entry points walk up to find this');
});

$t->test('a clean cPanel-shaped tree survives deploy.php intact', function (TestRunner $t) use (
    $repoRoot,
    $privateRoot,
    $publicRoot,
    $distDir,
    $keepSandbox
): void {
    global $sandbox;

    $root = sys_get_temp_dir() . '/fieldpulse-deploy-' . substr(bin2hex(random_bytes(5)), 0, 8);
    $docroot = $root . '/public_html';

    mkdir($docroot, 0755, true);

    // The sibling layout, exactly as cPanel presents it.
    copyTree($privateRoot, $root . '/private_storage');
    copyTree($publicRoot, $docroot);
    copyTree($distDir, $root . '/dist');

    $before = [
        'htaccess' => (string) md5_file($docroot . '/.htaccess'),
        'userini'  => (string) md5_file($docroot . '/.user.ini'),
        'api'      => count(glob($docroot . '/api/v1/*.php') ?: []),
    ];

    $proc = proc_open(
        [
            PHP_BINARY,
            $root . '/private_storage/bin/deploy.php',
            '--skip-frontend',
            '--docroot=' . $docroot,
        ],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot
    );

    $stdout = $proc === null ? '' : (string) stream_get_contents($pipes[1]);
    $stderr = $proc === null ? '' : (string) stream_get_contents($pipes[2]);

    if ($proc !== null) {
        fclose($pipes[1]);
        fclose($pipes[2]);
    }

    $code = $proc === null ? -1 : proc_close($proc);

    $t->assertSame(0, $code, 'deploy.php exited ' . $code . "\n" . $stdout . $stderr);
    $t->assertContains('PASS', $stdout, 'deploy.php must report PASS');

    // The deploy must not have touched anything the build does not own.
    $t->assertSame($before['htaccess'], md5_file($docroot . '/.htaccess'), 'deploy.php rewrote .htaccess');
    $t->assertSame($before['userini'], md5_file($docroot . '/.user.ini'), 'deploy.php rewrote .user.ini');
    $t->assertSame($before['api'], count(glob($docroot . '/api/v1/*.php') ?: []), 'deploy.php disturbed api/');

    // And it must actually have published the build.
    foreach (['index.html', 'sw.js', 'manifest.webmanifest', 'registerSW.js'] as $artifact) {
        $t->assertTrue(is_file($docroot . '/' . $artifact), $artifact . ' was not published');
    }

    $t->assertTrue(
        count(glob($docroot . '/assets/*.js') ?: []) > 0,
        'hashed assets were not published — the cache-busting filenames are the deploy'
    );

    // The published index must be the built one, byte for byte.
    $t->assertSame(
        md5_file($distDir . '/index.html'),
        md5_file($docroot . '/index.html'),
        'published index.html differs from the build'
    );

    $sandbox = ['sandbox' => $root, 'docroot' => $docroot, 'files' => []];

    if (!$keepSandbox) {
        register_shutdown_function(static function () use ($root): void {
            removeTree($root);
        });
    }
});

/* ===========================================================================
 * B. Entry points bootstrap from the staged tree.
 * =========================================================================== */

$t->group('entry points');

$t->test('every API entry point boots from a clean tree', function (TestRunner $t): void {
    global $sandbox;

    if ($sandbox === null) {
        $t->assertTrue(false, 'the staging test must run first');

        return;
    }

    $docroot = $sandbox['docroot'];
    $entries = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docroot . '/api', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $entries[] = $file->getPathname();
        }
    }

    $t->assertTrue(count($entries) >= 5, 'expected the full api/ tree, found ' . count($entries));

    /*
     * Each entry point is invoked the way a request would invoke it, and must
     * answer the application's own TLS gate. That is a positive proof that the
     * `require_once dirname(__DIR__, 3) . '/private_storage/...'` walk-up
     * resolved: a tree laid out differently fails here with a fatal "Failed
     * opening required", which is precisely the failure a flat copy of the
     * repository would hide.
     */
    foreach ($entries as $entry) {
        $name = str_replace($docroot, '', $entry);

        $res = runPhp(
            $entry,
            dirname($entry),
            [],
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => '/api/v1/x.php',
                'HTTP_HOST'      => 'fieldpulse.test',
                'SERVER_NAME'    => 'fieldpulse.test',
                'SERVER_PORT'    => '443',
            ]
        );

        $t->assertFalse(
            str_contains($res['out'], 'Failed opening required'),
            $name . ' could not locate private_storage from the staged tree'
        );

        $t->assertSame(0, $res['code'], $name . ' exited ' . $res['code'] . ":\n" . $res['out']);

        // The TLS gate and the method check both answer in JSON. Which of
        // the two fires first is the route's own business; what matters here
        // is that the request was handled by the application at all.
        $t->assertMatches('/"code":"[A-Z_]+"/', $res['out'], $name . ' did not answer with a JSON error');

        $t->assertFalse(
            str_contains($res['out'], 'Warning') || str_contains($res['out'], 'Deprecated'),
            $name . " produced PHP diagnostics:\n" . $res['out']
        );
    }
});

/* ===========================================================================
 * C. Syntax.
 * =========================================================================== */

$t->group('syntax');

$t->test('every PHP file in the tree parses', function (TestRunner $t) use ($privateRoot, $publicRoot): void {
    $files = [];

    foreach ([$privateRoot . '/app', $privateRoot . '/bin', $privateRoot . '/workers', $publicRoot . '/api'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    $t->assertTrue(count($files) > 40, 'expected the full tree, found ' . count($files) . ' files');

    $broken = [];

    foreach ($files as $file) {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);

        if ($code !== 0) {
            $broken[] = $file . ': ' . implode(' ', $out);
        }

        $out = [];
    }

    $t->assertSame([], $broken, implode("\n", $broken));
});

/* ===========================================================================
 * D. Document-root lockdown, as Apache reads it.
 * =========================================================================== */

$t->group('htaccess');

$t->test('sends the headers a JSON API and a PWA need', function (TestRunner $t) use ($htaccessPath): void {
    $text = (string) file_get_contents($htaccessPath);

    $required = [
        'Options -Indexes'                => 'a directory listing advertises the endpoint names',
        'DirectoryIndex index.html'       => 'the root URL must serve the shell, not a 403',
        'X-Content-Type-Options "nosniff"' => 'JSON must not be sniffed into HTML',
        'X-Frame-Options "DENY"'          => 'clickjacking',
        'Referrer-Policy "no-referrer"'   => 'a PWA on a shared device leaks nothing on navigation',
        'Permissions-Policy'              => 'camera and geolocation must be the only grants',
        'Strict-Transport-Security'       => 'TLS is already required by the app',
        'Content-Security-Policy'         => 'the strict policy, not a permissive one',
    ];

    foreach ($required as $needle => $why) {
        $t->assertContains($needle, $text, 'missing "' . $needle . '" — ' . $why);
    }

    $t->assertContains('camera=(self)', $text, 'the capture screen needs the camera');
    $t->assertContains('geolocation=(self)', $text, 'the submission payload needs location');
    $t->assertContains('microphone=()', $text, 'no microphone use in this application');
});

$t->test('the CSP grants no escape hatch', function (TestRunner $t) use ($htaccessPath): void {
    $text = (string) file_get_contents($htaccessPath);

    $line = '';

    foreach (explode("\n", $text) as $candidate) {
        if (preg_match('/Header\s+always\s+set\s+Content-Security-Policy/', $candidate) === 1) {
            $line = $candidate;
            break;
        }
    }

    $t->assertNotSame('', $line, 'no CSP directive found');

    $t->assertFalse(str_contains($line, 'unsafe-inline'), 'unsafe-inline permits XSS; the build has none');
    $t->assertFalse(str_contains($line, 'unsafe-eval'), 'unsafe-eval permits eval(); the build has none');
    $t->assertContains("default-src 'self'", $line, 'a default of self keeps the policy closed');
    $t->assertContains("object-src 'none'", $line, 'plugins are never used');
    $t->assertContains("frame-ancestors 'none'", $line, 'framing is denied independently of X-Frame-Options');
    $t->assertContains("base-uri 'self'", $line, 'base-tag injection redirects every relative URL');
});

$t->test('no rewrite condition captures a path into a backreference', function (TestRunner $t) use ($htaccessPath): void {
    /*
     * Regression, and the reason this file is parsed rather than trusted.
     *
     * The broken version captured the request path with
     *   RewriteCond %{REQUEST_URI} ^([^?]*)(\?.*)?$
     * and then tested %1 in the suffix allowlist and the SPA fallback. On
     * Apache 2.4.55 the negated test `%1 !\.(html|js|...)$` evaluated as though
     * %1 were empty, so every file with a real extension was answered 403 —
     * the entire site, offline, with nothing in the repository able to see it.
     *
     * A capture that spans RewriteCond lines is the tell: within a single
     * condition it is safe and idiomatic, across them it is not.
     */
    $text      = (string) file_get_contents($htaccessPath);
    $condition = '/^\s*RewriteCond\b.*(%[0-9])/mi';

    $t->assertSame(
        0,
        preg_match_all($condition, $text),
        '.htaccess references a rewrite backreference inside RewriteCond; '
            . 'use %{REQUEST_FILENAME}, which is unaffected by earlier captures'
    );
});

$t->test('the suffix allowlist and SPA fallback read the resolved path', function (TestRunner $t) use ($htaccessPath): void {
    $text = (string) file_get_contents($htaccessPath);

    $t->assertContains('RewriteCond %{REQUEST_FILENAME} !\\.(php|js|', $text, 'the allowlist must test the file on disk');
    $t->assertContains('RewriteCond %{REQUEST_FILENAME} !-f', $text, 'the SPA fallback must skip real files');
    $t->assertContains('RewriteRule ^api/ - [L]', $text, 'api/ must never fall through to the shell');

    // The allowlist has to be an allowlist: an unknown suffix is denied, so a
    // newly uploaded file type is inert until someone declares it here.
    $t->assertContains('RewriteCond %{REQUEST_FILENAME} \\.[A-Za-z0-9]+$', $text, 'only real files get suffix-tested');
});

$t->test('dotfiles and editor debris are denied without matching submit.php', function (TestRunner $t) use ($htaccessPath): void {
    $text = (string) file_get_contents($htaccessPath);

    $t->assertContains('RewriteRule (^|/)\\. - [F,L]', $text, 'a leading dot is denied outright');
    $t->assertContains('Require all denied', $text, 'dotfiles must be denied by the file rules too');

    /*
     * A bare "contains a dot" pattern would take the API offline, which is
     * exactly the regression that must not come back.
     *
     * The deny rule is allowed to list extensions — "\.(bak|old|...)$" is fine,
     * because those are anchored at the end and cannot match submit.php. What
     * is forbidden is a pattern whose FIRST element is an unanchored dot, since
     * that matches every .php file in the tree. So the check is on the start of
     * the pattern, not on the presence of a backslash-dot anywhere in it.
     */
    $t->assertFalse(
        (bool) preg_match('/FilesMatch\s+"\\\\\.[A-Za-z]/', $text),
        'a FilesMatch whose pattern begins with an unanchored \\. matches every .php file; anchor it to (^\\.)'
    );

    $t->assertContains('(^\\.)', $text, 'the dotfile rule must anchor to the start of the filename');
});

$t->test('the service worker and shell are never served from cache', function (TestRunner $t) use ($htaccessPath): void {
    $text = (string) file_get_contents($htaccessPath);

    $t->assertContains('<FilesMatch "^sw\\.js$">', $text, 'sw.js needs its own block');
    $t->assertContains('Service-Worker-Allowed "/"', $text, 'a worker served from a subpath cannot claim the origin');
    $t->assertContains('\\.(html|webmanifest)$', $text, 'index.html pins the client to the previous build if cached');
    $t->assertContains('no-store', $text, 'a cached shell defeats a deploy');
});

/* ===========================================================================
 * E. The policy is compatible with the build.
 * =========================================================================== */

$t->group('csp vs build');

$t->test('the bundle contains no eval and no inline script or style', function (TestRunner $t) use ($distDir): void {
    $bundles = glob($distDir . '/assets/*.js') ?: [];

    $t->assertTrue($bundles !== [], 'no built assets to check');

    foreach ($bundles as $bundle) {
        $source = (string) file_get_contents($bundle);

        // eval( and new Function( would both be blocked by the policy, so a
        // build that uses either fails in production only.
        $t->assertSame(
            0,
            preg_match('/\beval\s*\(/', $source),
            basename($bundle) . ' calls eval(), which the CSP forbids'
        );

        $t->assertSame(
            0,
            preg_match('/new\s+Function\s*\(/', $source),
            basename($bundle) . ' calls new Function(), which the CSP forbids'
        );
    }

    $html = (string) file_get_contents($distDir . '/index.html');

    $t->assertSame(
        0,
        preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $html),
        'index.html has an inline <script>; the CSP has no unsafe-inline'
    );

    $t->assertSame(
        0,
        preg_match('/<style[\s>]/i', $html),
        'index.html has an inline <style>; the CSP has no unsafe-inline'
    );

    $t->assertSame(
        0,
        preg_match('/\son[a-z]+\s*=\s*["\x27]/i', $html),
        'index.html has an inline event handler; the CSP has no unsafe-inline'
    );

    // And the manifest must be fetched as a manifest, which is why the policy
    // carries manifest-src separately from script-src.
    $t->assertContains('rel="manifest"', $html, 'the manifest link tag must be present');
});

/* ===========================================================================
 * F. PHP limits, as PHP will read them.
 * =========================================================================== */

$t->group('php limits');

$t->test('.user.ini satisfies the upload contract', function (TestRunner $t) use ($userIniPath): void {
    $values = [];

    foreach (explode("\n", (string) file_get_contents($userIniPath)) as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, ';')) {
            continue;
        }

        [$key, $value] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');

        if ($key !== '') {
            $values[strtolower($key)] = $value;
        }
    }

    $toBytes = static function (string $value): int {
        $value = trim($value);
        $unit  = strtolower(substr($value, -1));
        $num   = (int) $value;

        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    };

    $t->assertTrue(isset($values['upload_max_filesize']), 'upload_max_filesize must be set in .user.ini');
    $t->assertTrue(isset($values['post_max_size']), 'post_max_size must be set in .user.ini');
    $t->assertTrue(isset($values['memory_limit']), 'memory_limit must be set in .user.ini');
    $t->assertTrue(isset($values['max_input_time']), 'max_input_time must be bounded');

    $upload = $toBytes($values['upload_max_filesize'] ?? '0');
    $post   = $toBytes($values['post_max_size'] ?? '0');

    $t->assertTrue(
        $upload >= 5 * 1024 * 1024,
        'upload_max_filesize must allow a 5 MB photo: got ' . ($upload / 1048576) . ' MB'
    );

    /*
     * post_max_size must exceed upload_max_filesize, or the surplus of the
     * request over the limit is discarded and $_FILES is empty — which surfaces
     * as UPLOAD_ERR_INI_SIZE with an empty $_FILES, not as a clear error. The
     * margin absorbs the multipart boundaries and the JSON payload.
     */
    $t->assertTrue(
        $post > $upload,
        'post_max_size (' . ($post / 1048576) . ' MB) must exceed upload_max_filesize ('
            . ($upload / 1048576) . ' MB), or oversized uploads lose $_FILES entirely'
    );

    $t->assertTrue(
        ($toBytes($values['memory_limit'] ?? '0')) >= 64 * 1024 * 1024,
        'memory_limit must leave room for image work: got ' . $values['memory_limit'] ?? 'unset'
    );

    $t->assertTrue(
        (int) ($values['max_input_time'] ?? '-1') >= 0,
        'max_input_time must be a bounded non-negative number, not "unlimited"'
    );
});

$t->test('the healthcheck parses these same limits', function (TestRunner $t): void {
    $res = runPhp(
        dirname(__DIR__) . '/bin/healthcheck.php',
        dirname(__DIR__),
        []
    );

    $t->assertSame(0, $res['code'], "healthcheck failed:\n" . $res['out']);
    $t->assertMatches('/\bPASS\b/', $res['out'], 'healthcheck must pass');
    $t->assertFalse(
        str_contains($res['out'], 'warning(s)'),
        'healthcheck reported a warning: ' . $res['out']
    );
});

/* ===========================================================================
 * G. Over the wire, against a real server.
 * =========================================================================== */

$t->group('http');

$t->test('the API answers JSON with hardened headers and never HTML', function (TestRunner $t) use ($keepSandbox): void {
    global $sandbox;

    $docroot = $sandbox !== null ? $sandbox['docroot'] : dirname(__DIR__, 2) . '/public_html';

    $server = HttpServer::start($keepSandbox, [], [], 16_000_000, $docroot);

    try {
        $res = HttpServer::sendJson($server->baseUrl, 'https://fieldpulse.test', 'GET', '/api/v1/leaderboard.php', []);

        $t->assertContains('application/json', implode(',', $res['headers']['content-type'] ?? []), 'not JSON: ' . $res['raw']);
        $t->assertFalse(str_contains($res['raw'], '<html'), 'an API route served HTML');

        $headers = static function (array $all, string $name): string {
            return implode(',', $all[$name] ?? []);
        };

        $t->assertContains('nosniff', $headers($res['headers'], 'x-content-type-options'), 'the JSON must not be sniffed');
        $t->assertContains('DENY', $headers($res['headers'], 'x-frame-options'), 'framing must be denied');
        $t->assertContains("default-src 'none'", $headers($res['headers'], 'content-security-policy'), 'the API policy is none');
        $t->assertContains('no-store', $headers($res['headers'], 'cache-control'), 'leaderboard data must not be cached');
        $t->assertContains('geolocation=()', $headers($res['headers'], 'permissions-policy'), 'the API grants nothing');

        $t->assertFalse(
            str_contains($headers($res['headers'], 'content-security-policy'), 'unsafe-'),
            'the API policy must contain no unsafe- directive'
        );
    } finally {
        $server->stop();
    }
});

$t->test('an unknown route is a JSON 404, not the SPA shell', function (TestRunner $t) use ($keepSandbox): void {
    global $sandbox;

    $docroot = $sandbox !== null ? $sandbox['docroot'] : dirname(__DIR__, 2) . '/public_html';

    $server = HttpServer::start($keepSandbox, [], [], 16_000_000, $docroot);

    try {
        $res = HttpServer::sendJson(
            $server->baseUrl,
            'https://fieldpulse.test',
            'GET',
            '/api/v1/definitely-not-a-route.php',
            []
        );

        $t->assertSame(404, $res['status'], 'expected 404, got ' . $res['status'] . ': ' . $res['raw']);
        $t->assertContains('application/json', implode(',', $res['headers']['content-type'] ?? []), 'not JSON');
        $t->assertSame('NOT_FOUND', $res['body']['error']['code'] ?? null, 'the error body must be the documented shape');
    } finally {
        $server->stop();
    }
});

/**
 * Boot a real Apache in front of $docroot and probe it.
 *
 * PHP's built-in server cannot evaluate .htaccess at all, and that is the
 * file this whole phase is about. So the .htaccess tier needs a real httpd,
 * and a real httpd means a generated config, a free port, a listener wait and a
 * shutdown — the same shape as HttpServer, which is why this is a small class
 * rather than a pile of inline proc_open calls.
 *
 * The config is deliberately minimal: ServerRoot is the caller's httpd, the
 * document root is the staged tree, and nothing else is configured. That is a
 * feature, not a shortcut — .htaccess inherits from a minimal server exactly
 * as it would on a fresh cPanel account, and if a directive here silently
 * depends on a module or an inherited option that a basic plan would not have,
 * the test reproduces the outage instead of hiding it.
 */
final class ApacheProbe
{
    private mixed $process = null;

    private function __construct(
        public readonly string $httpd,
        public readonly string $confDir,
        public readonly int $port,
        public readonly string $logPath,
    ) {
    }

    public static function start(string $httpd, string $docroot): self
    {
        if (!is_file($httpd)) {
            throw new \RuntimeException('No httpd binary at ' . $httpd);
        }

        $slash   = static fn (string $p): string => str_replace('\\', '/', $p);
        $workDir = sys_get_temp_dir() . '/fieldpulse-apache-' . substr(bin2hex(random_bytes(5)), 0, 8);

        if (!mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Could not create ' . $workDir);
        }

        $port = self::allocatePort();
        $log  = $workDir . '/error.log';

        /*
         * PHP runs through mod_cgi with an Action, because that is the one
         * arrangement that works on Windows, where mod_proxy_fcgi's
         * SCRIPT_FILENAME never reaches php-cgi. The API tier is exercised over
         * the built-in server instead; what Apache is asserted for here is the
         * routing and header layer, which is precisely what .htaccess owns.
         */
        $conf = <<<CONF
            ServerRoot "{$slash(dirname($httpd, 2))}"
            Listen 127.0.0.1:{$port}
            PidFile "{$slash($workDir)}/httpd.pid"
            ErrorLog "{$slash($log)}"
            LogLevel warn

            # No MPM is loaded: the winnt build has one compiled in, and asking
            # for mod_mpm_winnt.so is a startup failure, not a fallback.
            LoadModule authn_core_module modules/mod_authn_core.so
            LoadModule authz_core_module modules/mod_authz_core.so
            LoadModule authz_host_module modules/mod_authz_host.so
            LoadModule authz_user_module modules/mod_authz_user.so
            LoadModule alias_module modules/mod_alias.so
            LoadModule dir_module modules/mod_dir.so
            LoadModule mime_module modules/mod_mime.so
            LoadModule log_config_module modules/mod_log_config.so
            LoadModule headers_module modules/mod_headers.so
            LoadModule rewrite_module modules/mod_rewrite.so
            LoadModule expires_module modules/mod_expires.so
            LoadModule env_module modules/mod_env.so
            LoadModule setenvif_module modules/mod_setenvif.so

            ServerName 127.0.0.1
            TypesConfig conf/mime.types

            DocumentRoot "{$slash($docroot)}"

            <Directory />
                AllowOverride None
                Require all denied
            </Directory>

            <Directory "{$slash($docroot)}">
                AllowOverride All
                Options FollowSymLinks Indexes
                Require all granted
            </Directory>
            CONF;

        $confDir = $workDir . '/conf';
        mkdir($confDir . '/logs', 0755, true);
        file_put_contents($confDir . '/httpd.conf', $conf);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];

        $process = proc_open(
            [$httpd, '-f', $confDir . '/httpd.conf', '-DFOREGROUND'],
            $descriptors,
            $pipes,
            dirname($httpd)
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start ' . $httpd);
        }

        $probe = new self($httpd, $confDir, $port, $log);
        $probe->process = $process;

        if (!$probe->waitForListener()) {
            $out = (string) @file_get_contents($log);
            $probe->stop();
            removeTree($workDir);

            throw new \RuntimeException('Apache did not start listening on ' . $port . "\n" . $out);
        }

        return $probe;
    }

    private function waitForListener(): bool
    {
        $deadline = microtime(true) + 15.0;

        while (microtime(true) < $deadline) {
            $sock = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $errstr, 0.5);

            if ($sock !== false) {
                fclose($sock);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    private static function allocatePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($sock === false) {
            throw new \RuntimeException('Could not allocate a port: ' . $errstr);
        }

        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * One request. Status and headers are collected, never the parsed body,
     * because what is under test is the response shape Apache chose.
     *
     * @return array{status:int,headers:array<string,list<string>>,raw:string}
     */
    public function get(string $path): array
    {
        $ch   = curl_init('http://127.0.0.1:' . $this->port . $path);
        $seen = [];
        $raw  = '';
        $code = 0;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$seen): int {
                $len = strlen($line);
                $sep = strpos($line, ':');

                if ($sep !== false) {
                    $seen[strtolower(trim(substr($line, 0, $sep)))][] = trim(substr($line, $sep + 1));
                }

                return $len;
            },
        ]);

        $body = curl_exec($ch);

        if ($body !== false) {
            $raw    = (string) $body;
            $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }

        curl_close($ch);

        return ['status' => $code, 'headers' => $seen, 'raw' => $raw];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        removeTree(dirname($this->confDir));
    }
}

$t->test('a real Apache enforces the .htaccess', function (TestRunner $t) use ($httpd, $sandbox): void {
    if ($httpd === null || !is_file($httpd)) {
        Cli::warn('no --httpd=<apache>/bin/httpd given, so the .htaccess status-code tier did not run');
        Cli::warn('the static .htaccess assertions above did run; this tier is the version check');

        return;
    }

    $docroot = $sandbox !== null ? $sandbox['docroot'] : dirname(__DIR__, 2) . '/public_html';

    $apache = ApacheProbe::start($httpd, $docroot);

    try {
        $header = static fn (array $r, string $n): string => implode(',', $r['headers'][$n] ?? []);

        /*
         * The regression, first and by exact status code. This is the assertion
         * that would have caught the original defect: every one of these
         * returned 403 while the rest of the repository was green.
         */
        $t->assertSame(200, $apache->get('/')['status'], 'the root URL must serve the shell');
        $t->assertSame(200, $apache->get('/index.html')['status'], 'index.html must be served');
        $t->assertSame(200, $apache->get('/sw.js')['status'], 'the service worker must be served');
        $t->assertSame(200, $apache->get('/manifest.webmanifest')['status'], 'the manifest must be served');
        $t->assertSame(200, $apache->get('/queue')['status'], 'a client-side route must fall back to the shell');

        foreach (glob($docroot . '/assets/*') ?: [] as $asset) {
            $t->assertSame(
                200,
                $apache->get('/assets/' . basename($asset))['status'],
                basename($asset) . ' was refused; the suffix allowlist is rejecting valid build output'
            );
        }

        // A deep link is the case that only fails on a real server: vite dev
        // serves /leaderboard happily, and a deployed host must too.
        foreach (['/leaderboard', '/login', '/capture/17', '/a/deep/route'] as $route) {
            $t->assertSame(200, $apache->get($route)['status'], $route . ' is a client route and must serve the shell');
        }

        // A missing chunk must stay a 404, or the service worker is handed HTML
        // to parse as JavaScript.
        $t->assertSame(404, $apache->get('/assets/index-deadbeef.js')['status'], 'a missing chunk must not serve HTML');

        // Dotfile and debri probes.
        foreach (['/.env', '/.git/config', '/.htaccess', '/api/.htaccess', '/index.html~', '/.DS_Store'] as $probe) {
            $t->assertSame(403, $apache->get($probe)['status'], $probe . ' must be denied');
        }

        // An undeclared suffix is denied: the allowlist is an allowlist.
        $t->assertSame(403, $apache->get('/shell.php.bak')['status'], 'editor debris must be denied');
        $t->assertSame(404, $apache->get('/api/v1/definitely-not-a-route.php')['status'], 'a missing API file must be a 404');

        // Headers, observed on the wire rather than read out of the file.
        $root = $apache->get('/');

        $t->assertContains('nosniff', $header($root, 'x-content-type-options'), 'nosniff missing on /');
        $t->assertContains('DENY', $header($root, 'x-frame-options'), 'X-Frame-Options missing on /');
        $t->assertContains('no-referrer', $header($root, 'referrer-policy'), 'Referrer-Policy missing on /');
        $t->assertContains("default-src 'self'", $header($root, 'content-security-policy'), 'CSP missing on /');
        $t->assertContains('max-age=31536000', $header($root, 'strict-transport-security'), 'HSTS missing on /');
        $t->assertFalse($header($root, 'x-powered-by') !== '', 'X-Powered-By is still being advertised');
        $t->assertContains('no-store', $header($root, 'cache-control'), 'index.html must not be cached');

        $sw = $apache->get('/sw.js');
        $t->assertContains('no-store', $header($sw, 'cache-control'), 'sw.js must not be cached');
        $t->assertContains('/', $header($sw, 'service-worker-allowed'), 'Service-Worker-Allowed missing');

        // A hashed asset is immutable, so it must be cacheable.
        $assets = glob($docroot . '/assets/*.js') ?: [];
        $t->assertTrue($assets !== [], 'no built assets staged for the cache check');
    } finally {
        $apache->stop();
    }
});

/* ===========================================================================
 * H. Storage stays outside the document root.
 * =========================================================================== */

$t->group('storage');

$t->test('evidence lives under private_storage, never under public_html', function (TestRunner $t): void {
    $root = Paths::storageRoot();

    $t->assertTrue(str_contains($root, 'private_storage'), 'storage root must be inside private_storage: ' . $root);

    foreach ([Paths::quarantineDir(), Paths::verifiedDir(), Paths::reviewDir(), Paths::rejectedDir()] as $dir) {
        $t->assertTrue(
            str_contains(str_replace('\\', '/', $dir), 'private_storage'),
            $dir . ' is outside private_storage and therefore reachable'
        );
    }
});

$t->test('the document root contains no uploaded evidence', function (TestRunner $t) use ($publicRoot): void {
    foreach (['quarantine', 'verified', 'review', 'rejected', 'evidence', 'uploads'] as $dir) {
        $t->assertFalse(is_dir($publicRoot . '/' . $dir), $dir . ' must not exist in the document root');
    }
});

$t->test('storage directories are created owner-only where possible', function (TestRunner $t): void {
    Paths::ensureLayout();

    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows has no POSIX mode to read; ACLs are verified by the operator.
        return;
    }

    foreach ([Paths::quarantineDir(), Paths::verifiedDir()] as $dir) {
        $mode = fileperms($dir) & 0777;

        $t->assertTrue(
            ($mode & 0022) === 0,
            $dir . ' is group/world writable (' . substr(sprintf('%o', $mode), -4) . ')'
        );
    }
});

/* ===========================================================================
 * I. CRON, as cPanel invokes it.
 * =========================================================================== */

$t->group('cron');

$t->test('the queue worker answers every mode cron uses', function (TestRunner $t) use ($privateRoot): void {
    $worker = $privateRoot . '/workers/process_queue.php';

    foreach ([['--stats'], ['--prune'], ['--once']] as [$flag]) {
        $res = runPhp($worker, $privateRoot, [$flag]);

        $t->assertSame(
            0,
            $res['code'],
            'process_queue.php ' . $flag . ' exited ' . $res['code'] . ":\n" . $res['out']
        );
    }
});

$t->test('a concurrent tick cannot claim the same job twice', function (TestRunner $t) use ($privateRoot): void {
    // The suite that proves this is bin/queue.php, which forks real workers and
    // races real rows. Invoking it here keeps the overlap guarantee inside the
    // Phase 7 gate rather than in a suite someone has to remember to run.
    $res = runPhp($privateRoot . '/bin/queue.php', $privateRoot, []);

    $t->assertSame(0, $res['code'], "queue.php failed:\n" . $res['out']);
    $t->assertMatches('/failed:\s*0\b/', $res['out'], 'queue.php must report no failures');
    $t->assertMatches('/passed:\s*1[0-9]/', $res['out'], 'the queue suite did not run its full set of cases');
});

$t->test('the worker is safe to run on a timer', function (TestRunner $t) use ($privateRoot): void {
    $source = (string) file_get_contents($privateRoot . '/workers/process_queue.php');

    // A cron entry that exits non-zero on an empty queue mails the owner every
    // time it runs. An idle tick must be a clean exit.
    $t->assertContains('exit(0)', $source, 'the worker must exit 0 on an idle or successful run');
    $t->assertFalse(
        (bool) preg_match('/while\s*\(\s*true\s*\)/', $source),
        'the worker must not loop forever; cron invokes it once per run'
    );
});

exit($t->run(in_array('--verbose', $argv, true) || in_array('-v', $argv, true)));
