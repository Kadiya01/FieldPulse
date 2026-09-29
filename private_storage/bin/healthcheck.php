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

Cli::heading('Upload limits (contract ceiling: 5 MB)');

$configMax = Config::instance()->int('storage.max_upload_bytes');
$ok('configured max_upload_bytes = ' . $configMax);

$checks = [
    ['upload_max_filesize', 6 * 1024 * 1024, 'must exceed 5 MB (POST overhead adds ~1 KB)'],
    ['post_max_size', $configMax + 2 * 1024 * 1024, 'must exceed upload_max_filesize (payload + file)'],
    ['memory_limit', 128 * 1024 * 1024, 'pHash DCT holds a 32x32 float matrix; 128M is comfortable'],
    ['max_execution_time', 30, 'submit.php must respond immediately; the worker does the work'],
];

foreach ($checks as [$ini, $recommended, $why]) {
    $value = ini_get($ini);
    $bytes = toBytes((string) $value);

    if ($bytes === 0) {
        $warn(sprintf('%-22s = %-10s unlimited (CLI) — fine here, check php.ini on the web SAPI', $ini, $value));
        continue;
    }

    if ($bytes >= $recommended) {
        $ok(sprintf('%-22s = %s', $ini, $value));
    } else {
        $fail(sprintf('%-22s = %-10s too small — %s. Set it in .user.ini or php.ini.', $ini, $value, $why));
    }
}

if (ini_get('file_uploads') === '0' || ini_get('file_uploads') === '') {
    $fail('file_uploads is disabled');
} else {
    $ok('file_uploads enabled');
}

/* -------------------------------------------------------------------------- */
/* Storage                                                                     */
/* -------------------------------------------------------------------------- */

Cli::heading('Storage');

$root = Paths::storageRoot();
$real = realpath($root) ?: $root;

Cli::out('  root: ' . $real);

$docrootGuess = dirname($real) . '/public_html';
$docrootReal  = realpath($docrootGuess);

if ($docrootReal === false) {
    $warn('could not resolve public_html; verify manually that storage is outside the document root');
} elseif (str_starts_with($real, (string) $docrootReal)) {
    $fail('storage root is INSIDE public_html — uploaded evidence would be web-readable');
} else {
    $ok('storage is outside the document root');
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
 * Returns 0 for unlimited / not applicable, which callers treat as "skip".
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
