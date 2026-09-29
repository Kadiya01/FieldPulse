<?php

declare(strict_types=1);

/**
 * FieldPulse — application bootstrap.
 *
 * Zero-dependency entry point. Responsibilities:
 *   1. PHP version gate.
 *   2. PSR-4 autoloader for the FieldPulse\ namespace.
 *   3. Path constants.
 *   4. Error/exception handling that fails closed and never leaks internals.
 *   5. Default timezone = UTC (all DB timestamps are UTC; business timezone is
 *      applied explicitly at reporting time, never implicitly).
 *
 * This file must stay free of side effects beyond the above so that it is safe
 * to include from web front controllers, the queue worker, and CLI scripts.
 */

if (PHP_VERSION_ID < 80200) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":{"code":"SERVICE_UNAVAILABLE","message":"Server runtime is not supported."}}';
    } else {
        fwrite(STDERR, "FieldPulse requires PHP 8.2 or newer. Running " . PHP_VERSION . PHP_EOL);
    }
    exit(1);
}

/* -------------------------------------------------------------------------- */
/* Paths                                                                       */
/* -------------------------------------------------------------------------- */

/** private_storage/app */
define('FIELDPULSE_APP_ROOT', __DIR__);
/** private_storage */
define('FIELDPULSE_PRIVATE_ROOT', dirname(__DIR__));
/** Account root: the directory that contains both public_html/ and private_storage/ */
define('FIELDPULSE_BASE_ROOT', dirname(FIELDPULSE_PRIVATE_ROOT));
define('FIELDPULSE_VERSION', '1.0.0');
define('FIELDPULSE_NAMESPACE', 'FieldPulse\\');

/* -------------------------------------------------------------------------- */
/* Autoloader                                                                  */
/* -------------------------------------------------------------------------- */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, FIELDPULSE_NAMESPACE)) {
        return;
    }

    $relative = substr($class, strlen(FIELDPULSE_NAMESPACE));
    $path     = FIELDPULSE_APP_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

/* -------------------------------------------------------------------------- */
/* Runtime defaults                                                            */
/* -------------------------------------------------------------------------- */

// Every persisted DATETIME is UTC. Conversions to the business timezone are
// explicit (see FieldPulse\Domain\PeriodResolver) so that the host's local
// timezone can never influence stored or reported values.
date_default_timezone_set('UTC');

mb_internal_encoding('UTF-8');

if (PHP_SAPI === 'cli') {
    // Cron workers must never block on output buffering.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

/* -------------------------------------------------------------------------- */
/* Error handling — fail closed                                                */
/* -------------------------------------------------------------------------- */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

/**
 * Promote warnings/notices to ErrorException so that a suppressed-but-real
 * failure (for example a corrupt image header) can never be mistaken for a
 * successful result. The @ suppression operator is honoured: when it is used
 * the handler yields control to the internal handler instead of throwing.
 */
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});
