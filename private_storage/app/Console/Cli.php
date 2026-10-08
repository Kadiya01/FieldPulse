<?php

declare(strict_types=1);

/**
 * Shared CLI bootstrap.
 *
 * Every bin/ and workers/ entry point starts with:
 *     require_once dirname(__DIR__) . '/app/bootstrap.php';
 *     FieldPulse\Console\Cli::init(__FILE__);
 *
 * Cli::init() is the only place where CLI-specific concerns are handled:
 * strict SAPI checks, unbuffered output, a fatal-error reporter that writes to
 * the log file rather than to the user's terminal, and the storage-layout
 * assertion that every long-running process depends on.
 */

namespace FieldPulse\Console;

use FieldPulse\Config\Config;
use FieldPulse\Support\Logger;
use FieldPulse\Support\RequestId;

final class Cli
{
    public static function init(string $entryScript): void
    {
        if (PHP_SAPI !== 'cli') {
            // A CLI entry point reached over HTTP is a misconfiguration, and the
            // most dangerous kind: it would run privileged maintenance.
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo '{"error":{"code":"NOT_FOUND","message":"Not found."}}';
            exit;
        }

        Config::boot();

        set_exception_handler(static function (\Throwable $e) use ($entryScript): void {
            Logger::channel('app')->error('cli.unhandled_exception', [
                'entry'  => basename($entryScript),
                'class'  => $e::class,
                'error'  => $e->getMessage(),
                'line'   => $e->getLine(),
                'origin' => self::origin($e),
            ]);

            fwrite(STDERR, 'FATAL: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        RequestId::set(null);
    }

    private static function origin(\Throwable $e): string
    {
        $file = $e->getFile();
        $root = FIELDPULSE_BASE_ROOT . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
    }

    public static function out(string $line = ''): void
    {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    public static function err(string $line): void
    {
        fwrite(STDERR, $line . PHP_EOL);
    }

    public static function ok(string $line): void
    {
        self::out('  [ok]   ' . $line);
    }

    public static function warn(string $line): void
    {
        self::out('  [warn] ' . $line);
    }

    public static function fail(string $line): void
    {
        self::out('  [FAIL] ' . $line);
    }

    public static function heading(string $line): void
    {
        self::out(PHP_EOL . $line . PHP_EOL . str_repeat('-', mb_strlen($line)));
    }

    /**
     * @return list<string>
     */
    public static function argv(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        array_shift($argv);

        return array_values(array_map('strval', $argv));
    }

    public static function option(array $argv, string $name, ?string $default = null): ?string
    {
        foreach ($argv as $i => $arg) {
            if ($arg === '--' . $name) {
                return $argv[$i + 1] ?? '';
            }
            if (str_starts_with($arg, '--' . $name . '=')) {
                return substr($arg, strlen($name) + 3);
            }
        }

        return $default;
    }

    public static function hasFlag(array $argv, string $name): bool
    {
        return in_array('--' . $name, $argv, true);
    }
}
