<?php

declare(strict_types=1);

/**
 * Preflight + runtime health check.
 *
 *   php private_storage/bin/healthcheck.php
 *
 * Exits 0 when every hard requirement is met, 1 otherwise. Designed to be run
 * once immediately after upload, and safe to run from cron for monitoring.
 *
 * It checks the things that silently break a cPanel deployment: missing PHP
 * extensions, upload limits smaller than the contract's 5 MB ceiling, storage
 * directories outside the document root, world-writable storage, and a database
 * that cannot see the schema.
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use FieldPulse\Config\Config;
use FieldPulse\Console\Cli;
use FieldPulse\Database\Connection;
use FieldPulse\Database\Migrator;
use FieldPulse\Support\Env;
use FieldPulse\Support\Paths;

Cli::init(__FILE__);

// --env=<path> points the check at a different environment file. The DB matrix
// needs this: verifying a second engine by copying the tree next to a second
// document root proves nothing about the deployment that will actually run,
// while re-reading one .env proves the same code against a different server.
$argv = Cli::argv();

if (Cli::option($argv, 'env') !== null) {
    Config::boot(Cli::option($argv, 'env'));
}

$hardFailures = 0;
$warnings     = 0;

$fail = static function (string $message) use (&$hardFailures): void {
    Cli::fail($message);
    $hardFailures++;
};

$warn = static function (string $message) use (&$warnings): void {
    Cli::warn($message);
    $warnings++;
};

$ok = static function (string $message): void {
    Cli::ok($message);
};

/* -------------------------------------------------------------------------- */
/* Runtime                                                                     */
/* -------------------------------------------------------------------------- */

Cli::heading('Runtime');

$ok('PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')');
if (PHP_VERSION_ID < 80200) {
    $fail('PHP 8.2+ is required');
}

if (PHP_SAPI === 'cli') {
    $ok('running from CLI as expected');
}

foreach (['PDO', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'fileinfo', 'hash', 'filter'] as $ext) {
    if (extension_loaded($ext)) {
        $ok('ext ' . $ext);
    } else {
        $fail('ext ' . $ext . ' is REQUIRED and not loaded');
    }
}

foreach (['gd', 'exif'] as $ext) {
    if (extension_loaded($ext)) {
        $ok('ext ' . $ext);
    } else {
        $fail('ext ' . $ext . ' is REQUIRED (pHash and EXIF extraction) and not loaded');
    }
}

if (extension_loaded('imagick')) {
    $ok('ext imagick (optional, unused — GD is the supported path)');
}

$gd = extension_loaded('gd') ? (function_exists('imagecreatefromjpeg') ? true : false) : false;
if (!$gd) {
    $fail('GD cannot decode JPEG; pHash has no fallback path');
}

if (function_exists('openssl_pkey_get_public') && function_exists('openssl_verify')) {
    $ok('openssl signature verification available');
} else {
    $fail('openssl_verify() unavailable — device signing cannot be checked');
}

if (function_exists('exif_read_data')) {
    $ok('exif_read_data() available');
} else {
    $fail('exif_read_data() unavailable — §12 EXIF classification cannot run');
}

/* -------------------------------------------------------------------------- */
/* Upload limits                                                               */
/* -------------------------------------------------------------------------- */

/*
 * Upload and resource limits.
 *
 * Two things are checked, and they are different questions:
 *
 *   1. Are the values large enough for the contract?  max_upload_bytes is the
 *      application's own ceiling; every ini limit below is compared against it
 *      or against a documented floor.
 *
 *   2. Are the values that this process is actually enforcing the ones the web
 *      SAPI enforces?  Under CLI, PHP reports max_execution_time=0 because the
 *      CLI SAPI does not implement the directive at all. Reporting that as a
 *      warning on every run was noise that trained operators to ignore the
 *      warnings, which is how the real ones get missed — and it made "PASS with
 *      1 warning" the permanent state of a healthy deployment. Time limits are
 *      therefore verified against the deployed web configuration (.user.ini, and
 *      any php_value block in .htaccess) instead of the CLI runtime, and the CLI
 *      value is reported as informational.
 *
 * post_max_size is checked against max_upload_bytes rather than being assumed
 * to follow upload_max_filesize. The two are independent directives; a host
 * that sets upload_max_filesize=6M while leaving post_max_size at the 8M
 * default has under 1 MB of envelope for multipart boundaries, form fields and
 * any file the client sends alongside the photo, and PHP truncates the request
 * with an empty $_POST. The API cannot see a truncated body, so the client sees
 * an unexplained failure rather than the documented FILE_TOO_LARGE.
 */

Cli::heading('Upload and resource limits (contract ceiling: 5 MB)');

$configMax = Config::instance()->int('storage.max_upload_bytes');
$ok('configured max_upload_bytes = ' . $configMax);

/** Multipart envelope: boundaries, the part headers, and any extra form fields. */
const MULTIPART_ENVELOPE_BYTES = 2 * 1024 * 1024;

/** Documented floors, independent of the contract ceiling. */
$checks = [
    ['upload_max_filesize', $configMax, 'PHP rejects the photo before the app sees it'],
    ['post_max_size', $configMax + MULTIPART_ENVELOPE_BYTES, 'PHP truncates the request and $_POST arrives empty'],
    ['memory_limit', 128 * 1024 * 1024, 'pHash DCT holds a 32x32 float matrix; 128M is comfortable'],
];

foreach ($checks as [$ini, $required, $why]) {
    $value = ini_get($ini);
    $bytes = toBytes((string) $value);

    if ($bytes === 0) {
        // 0 means unlimited, which satisfies every minimum.
        $ok(sprintf('%-22s = %-10s unlimited', $ini, $value));
        continue;
    }

    if ($bytes >= $required) {
        $ok(sprintf('%-22s = %-10s (>= ' . round($required / 1048576, 2) . ' MB required)', $ini, $value));
    } else {
        $fail(sprintf(
            '%-22s = %-10s too small — needs at least %d bytes, %s. Set it in .user.ini or php.ini.',
            $ini,
            $value,
            $required,
            $why
        ));
    }
}

if (ini_get('file_uploads') === '0' || ini_get('file_uploads') === '') {
    $fail('file_uploads is disabled');
} else {
    $ok('file_uploads enabled');
}

/*
 * Time limits: enforced by the web SAPI, not by this process.
 *
 * Verified against the deployed configuration files rather than the CLI
 * runtime, because that is the only place the number the web tier will actually
 * use can be observed from outside. An absent file is not a warning: a host
 * running mod_php ignores .user.ini entirely and takes these from php.ini, which
 * this script cannot read. That case is reported as informational, because the
 * operator's only way to find out is to look at the file, and a warning that
 * fires on every correctly configured host is a warning nobody reads.
 */
$timeLimits = ['max_execution_time' => 30, 'max_input_time' => 30];
$deployedIni = [];

foreach (Paths::webConfigFiles() as $file) {
    $deployedIni[basename($file)] = parseIniFileValues($file);
}

$userIni = $deployedIni['.user.ini'] ?? null;

if ($userIni === null) {
    $ok('.user.ini not found in the document root — PHP limits come from php.ini or .htaccess php_value');
} else {
    foreach ($timeLimits as $ini => $required) {
        if (!isset($userIni[$ini])) {
            $fail(sprintf('.user.ini does not set %s; a request that hangs ties up a shared worker', $ini));
            continue;
        }

        $declared = toBytes((string) $userIni[$ini]);

        if ($declared === 0 || $declared >= $required) {
            $ok(sprintf('.user.ini %-18s = %-8s (>= %d s required)', $ini, $userIni[$ini], $required));
        } else {
            $fail(sprintf('.user.ini %-18s = %-8s too small — submit.php must respond in %d s or less', $ini, $userIni[$ini], $required));
        }
    }

    /*
     * The declared upload limits are checked against the contract as well,
     * because they are the numbers the web tier will enforce whether or not
     * this process happens to be the one enforcing them. The shipped .user.ini
     * did fail this: post_max_size was 6M against a 5 MB contract, leaving 1 MB
     * for the multipart boundary, the part headers and any sibling form field,
     * which is thin enough that a legitimate upload of a 5 MB photo plus a
     * signature arrives truncated with an empty $_POST.
     */
    foreach ($checks as [$ini, $required, $why]) {
        if (!isset($userIni[$ini])) {
            $fail(sprintf('.user.ini does not set %s; on a CGI/FastCGI host it would fall back to a php.ini default', $ini));
            continue;
        }

        $declared = toBytes((string) $userIni[$ini]);

        if ($declared === 0 || $declared >= $required) {
            $ok(sprintf('.user.ini %-18s = %-8s (>= %d bytes required)', $ini, $userIni[$ini], $required));
        } else {
            $fail(sprintf(
                '.user.ini %-18s = %-8s too small — needs %d bytes, %s',
                $ini,
                $userIni[$ini],
                $required,
                $why
            ));
        }
    }

    // The upload limits declared in .user.ini are the ones that decide whether a
    // phone upload is rejected by PHP or by the application, so comparing them
    // against the running configuration catches a docroot that is not being read
    // at all — the single most common cause of an unexplainable 500.
    //
    // Only meaningful under a web SAPI. .user.ini is scanned by CGI/FastCGI for
    // the script's own directory and is never read by the CLI SAPI, so under CLI
    // the running values come from php.ini and would differ for a perfectly
    // healthy deployment. Comparing them there would report a false failure on
    // every correct install.
    $sapiReadsUserIni = PHP_SAPI === 'cgi-fcgi' || PHP_SAPI === 'cgi';

    foreach (['upload_max_filesize', 'post_max_size', 'memory_limit'] as $ini) {
        if (!isset($userIni[$ini])) {
            continue;
        }

        $declared = toBytes((string) $userIni[$ini]);
        $running  = toBytes((string) ini_get($ini));

        if (!$sapiReadsUserIni) {
            $ok(sprintf('.user.ini %-18s = %-8s declared (not applied to the CLI SAPI)', $ini, $userIni[$ini]));
            continue;
        }

        if ($declared === $running) {
            $ok(sprintf('.user.ini %-18s = %-8s applied', $ini, $userIni[$ini]));
        } else {
            $fail(sprintf(
                '.user.ini declares %s = %s but this SAPI is running %s — the file is not being read',
                $ini,
                $userIni[$ini],
                (string) ini_get($ini)
            ));
        }
    }
}

foreach ($timeLimits as $ini => $required) {
    $runtime = (string) ini_get($ini);

    if (PHP_SAPI === 'cli') {
        $ok(sprintf('%-22s = %-10s not enforced by the CLI SAPI; the web limit is the .user.ini value above', $ini, $runtime));
    } elseif (toBytes($runtime) >= $required || toBytes($runtime) === 0) {
        $ok(sprintf('%-22s = %s', $ini, $runtime));
    } else {
        $fail(sprintf('%s = %s is too small for a web request', $ini, $runtime));
    }
}

/* -------------------------------------------------------------------------- */
/* Storage                                                                     */
/* -------------------------------------------------------------------------- */

Cli::heading('Storage');

$root = Paths::storageRoot();
$real = realpath($root) ?: $root;

Cli::out('  root: ' . $real);

/*
 * Locate the document root properly.
 *
 * The original check guessed dirname(storageRoot) . '/public_html'. With the
 * default layout that is private_storage/public_html, which does not exist, so
 * realpath() returned false and the check degraded to a warning — on every run,
 * on every deployment using the default layout. The one assertion that must
 * never be skipped, that evidence is not web-readable, was therefore never
 * actually made. A health check that cannot fail is worse than no check, because
 * it is read as a pass.
 *
 * Resolution is anchored to FIELDPULSE_BASE_ROOT, the account root that
 * bootstrap.php defines as the parent of private_storage and is known to contain
 * public_html, rather than inferred from the storage path.
 *
 * Every candidate is checked, and an unresolved docroot is a failure rather than
 * a warning: an operator who cannot prove evidence is unreachable from the web
 * has not verified it. It is also a stricter bar on purpose. Pointing
 * storage.root at the docroot is a plausible enough misconfiguration that the
 * check must be impossible to fool.
 */
$docroots = Paths::documentRootCandidates();
$resolvedDocroots = Paths::documentRoots();

if ($resolvedDocroots === []) {
    $fail(
        'could not resolve any document root, so it cannot be proven that evidence is '
        . 'not web-readable; set app.url or confirm the public_html location manually'
    );
} else {
    $inside = false;

    foreach ($resolvedDocroots as $docroot) {
        Cli::out('  docroot: ' . $docroot);

        // Compared with a trailing separator on both sides. Without it, a docroot
        // of /srv/app would match a storage root of /srv/app-secrets, and the
        // check would pass on a layout where the evidence is a sibling of the
        // web root rather than a child of it.
        $prefix = rtrim($docroot, '/\\') . '/';

        if (str_starts_with(rtrim($real, '/\\') . '/', $prefix)) {
            $inside = true;
        }
    }

    if ($inside) {
        $fail('storage root is INSIDE the document root — uploaded evidence would be web-readable');
    } else {
        $ok('storage is outside the document root');
    }
}

try {
    Paths::ensureLayout();
    $ok('quarantine/verified/review/rejected/logs/tmp present, 0750, not world-writable');
} catch (Throwable $e) {
    $fail('storage layout: ' . $e->getMessage());
}

$envFile = FIELDPULSE_PRIVATE_ROOT . '/.env';
if (!is_file($envFile)) {
    $fail('.env missing — copy .env.example and configure it');
} else {
    $perms = @fileperms($envFile);
    // POSIX mode bits only. Windows reports 0777 from fileperms() for every
    // file and enforces access through ACLs, so the test there is permanently
    // true and would report a local file as world-readable no matter what.
    if (PHP_OS_FAMILY !== 'Windows' && $perms !== false && ($perms & 0o004) !== 0) {
        $fail('.env is world-readable - run: chmod 640 .env');
    } else {
        $ok('.env present with 0640-style permissions');
    }
}

$dotfiles = glob(FIELDPULSE_BASE_ROOT . '/public_html/.env*') ?: [];
if ($dotfiles !== []) {
    $fail('an .env file exists inside public_html: ' . implode(', ', array_map('basename', $dotfiles)));
} else {
    $ok('no .env inside public_html');
}

/* -------------------------------------------------------------------------- */
/* Configuration                                                               */
/* -------------------------------------------------------------------------- */

Cli::heading('Configuration');

try {
    $c = Config::instance();
    $ok('config loaded (' . $c->str('app.env') . ', debug=' . ($c->bool('app.debug') ? 'on' : 'off') . ')');
    $ok('business timezone ' . $c->str('timezone.business') . ' — periods start Monday 00:00:00');
    $ok('phash threshold ' . $c->int('phash.hamming_threshold') . ', possible margin ' . $c->int('phash.possible_margin'));
    $ok('weekly cap ' . $c->int('limits.weekly_cap') . ' per agent');
    $ok('verification_version ' . $c->str('verification.version'));
} catch (Throwable $e) {
    $fail('configuration invalid: ' . $e->getMessage());
}

$allowedOrigins = Env::get('ALLOWED_ORIGINS');
$ok('origin policy: ' . ($allowedOrigins !== null && $allowedOrigins !== '' ? $allowedOrigins : 'derived from APP_URL'));

/* -------------------------------------------------------------------------- */
/* Database                                                                    */
/* -------------------------------------------------------------------------- */

Cli::heading('Database');

try {
    $version = Connection::serverVersion();
    $ok('connected: ' . $version);

    $tz = Connection::fetchValue("SELECT @@session.time_zone");
    $tz === '+00:00' ? $ok('session time_zone = +00:00') : $fail('session time_zone is ' . $tz . ', expected +00:00');

    $mode = (string) Connection::fetchValue("SELECT @@session.sql_mode");
    str_contains($mode, 'STRICT_TRANS_TABLES')
        ? $ok('STRICT_TRANS_TABLES active')
        : $warn('STRICT_TRANS_TABLES not in sql_mode: ' . $mode);

    // DEFAULT_STORAGE_ENGINE is a server variable, not a column of
    // information_schema.SCHEMATA, so selecting it from there raised
    // "Unknown column 'DEFAULT_STORAGE_ENGINE' in 'field list'" and this check
    // never reported. The variable is spelled the same on MySQL and MariaDB.
    $engine = (string) Connection::fetchValue('SELECT @@default_storage_engine');
    strtolower($engine) === 'innodb'
        ? $ok('default engine InnoDB')
        : $warn('default engine is ' . $engine . ' (every table declares InnoDB explicitly)');

    // Spatial support is optional: Geofence falls back to Haversine in PHP.
    $hasSphere = (int) Connection::fetchValue(
        "SELECT COUNT(*) FROM information_schema.ROUTINES
          WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'ST_Distance_Sphere'"
    ) === 1;
    $ok($hasSphere
        ? 'ST_Distance_Sphere available (Haversine fallback not required)'
        : 'ST_Distance_Sphere absent — PHP Haversine fallback will be used');

    $migrator = new Migrator(FIELDPULSE_PRIVATE_ROOT . '/migrations');
    $pending  = array_filter($migrator->status(), static fn (array $r): bool => $r['status'] !== 'APPLIED');

    if ($pending === []) {
        $ok('schema up to date (' . count($migrator->status()) . ' migrations)');
    } else {
        $fail('pending migrations: ' . implode(', ', array_column($pending, 'filename')));
    }

    $nonce = (int) Connection::fetchValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['request_nonces']);
    $nonce === 1 ? $ok('request_nonces present (replay protection active)') : $fail('request_nonces missing — replay protection unavailable');
} catch (Throwable $e) {
    $fail('database: ' . $e->getMessage());
}

/* -------------------------------------------------------------------------- */
/* Verdict                                                                    */
/* -------------------------------------------------------------------------- */

Cli::heading('Verdict');

if ($hardFailures === 0) {
    Cli::out('  PASS' . ($warnings > 0 ? ' with ' . $warnings . ' warning(s)' : '') . PHP_EOL);
    exit(0);
}

Cli::out('  FAIL — ' . $hardFailures . ' hard failure(s)' . ($warnings > 0 ? ', ' . $warnings . ' warning(s)' : '') . PHP_EOL);
exit(1);

/**
 * Convert a php.ini shorthand size ("8M", "512K", "1G", "0") to bytes.
 * Returns 0 for unlimited / not applicable, which callers treat as "satisfies
 * any minimum".
 */
function toBytes(string $value): int
{
    $value = trim($value);

    if ($value === '') {
        return 0;
    }

    $unit = strtoupper(substr($value, -1));
    $num  = (float) $value;

    return match ($unit) {
        'G'     => (int) ($num * 1024 * 1024 * 1024),
        'M'     => (int) ($num * 1024 * 1024),
        'K'     => (int) ($num * 1024),
        default => (int) $num,
    };
}

/**
 * Parse "key = value" pairs out of a .user.ini.
 *
 * parse_ini_file() is not used because it reports a .user.ini as a parse failure:
 * the file is PHP-scanned configuration, not INI sections, and it routinely
 * contains directives this function has never heard of. Hand-parsing keeps the
 * check working on a host whose php.ini is full of directives from extensions
 * that are not installed here.
 *
 * Values are compared as strings by the caller, so quoting is stripped but no
 * attempt is made to interpret the PHP constant syntax ("256M" vs "256 M").
 *
 * @return array<string,string>
 */
function parseIniFileValues(string $file): array
{
    $values = [];
    $lines  = @file($file, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#') || str_starts_with($line, '[')) {
            continue;
        }

        $eq = strpos($line, '=');

        if ($eq === false) {
            continue;
        }

        $key   = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));

        if ($key !== '') {
            $values[$key] = trim($value, "\"' \t");
        }
    }

    return $values;
}
