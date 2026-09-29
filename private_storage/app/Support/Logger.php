<?php

declare(strict_types=1);

namespace FieldPulse\Support;

use FieldPulse\Config\Config;

/**
 * Append-only file logger with daily rotation.
 *
 * Rules:
 *   - Never logs secrets, tokens, signatures, file contents or raw JWTs.
 *   - Writes with LOCK_EX so a parallel cron worker cannot interleave a line.
 *   - Every entry carries the request id for correlation.
 */
final class Logger
{
    private const REDACTED = '[redacted]';

    /** @var list<string> */
    private static array $sensitiveKeys = [
        'password', 'token', 'refresh_token', 'access_token', 'authorization',
        'signature', 'secret', 'jwt', 'jwk', 'private_key', 'imei', 'challenge',
        'payload_hash', 'nonce',
    ];

    private static ?string $channel = null;

    private function __construct()
    {
    }

    public static function channel(string $channel): self
    {
        self::$channel = $channel;

        return new self();
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function debug(string $message, array $context = []): void
    {
        if (!Config::instance()->bool('app.debug')) {
            return;
        }

        self::write('DEBUG', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $line = [
            'ts'      => Clock::sql(),
            'level'   => $level,
            'channel' => self::$channel ?? 'app',
            'req'     => RequestId::current(),
            'msg'     => self::scrub($message),
            'ctx'     => self::scrubArray($context),
        ];

        try {
            $dir = Paths::logsDir();
            if (!is_dir($dir)) {
                return;
            }

            $file = $dir . '/' . (self::$channel ?? 'app') . '-' . date('Y-m-d') . '.log';
            $encoded = Json::encode($line) . "\n";

            $fh = @fopen($file, 'ab');
            if ($fh === false) {
                return;
            }

            try {
                if (flock($fh, LOCK_EX)) {
                    fwrite($fh, $encoded);
                    fflush($fh);
                    flock($fh, LOCK_UN);
                }
            } finally {
                fclose($fh);
            }
        } catch (\Throwable) {
            // Logging must never mask the original failure.
        }
    }

    /** @param array<string,mixed> $context */
    private static function scrubArray(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);

            foreach (self::$sensitiveKeys as $sensitive) {
                if (str_contains($lower, $sensitive)) {
                    $out[$key] = self::REDACTED;
                    continue 2;
                }
            }

            $out[$key] = is_array($value) ? self::scrubArray($value) : $value;
        }

        return $out;
    }

    private static function scrub(string $message): string
    {
        return (string) preg_replace(
            '/\b(eyJ[A-Za-z0-9_\-]{8,})\b/',          // JWT-looking strings
            self::REDACTED,
            (string) preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ' . self::REDACTED, $message)
        );
    }
}
