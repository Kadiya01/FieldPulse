<?php

declare(strict_types=1);

namespace FieldPulse\Support;

/**
 * Minimal, strict .env reader.
 *
 * Deliberate rules:
 *   - The .env FILE is authoritative. getenv() is consulted only for keys the
 *     file does not define. This keeps behaviour predictable when cPanel
 *     injects stray variables into the process environment.
 *   - No variable interpolation, no shell evaluation, no command execution.
 *   - Values may be wrapped in single or double quotes; quotes are stripped and
 *     (for double quotes only) \" \\ \n \r \t are unescaped.
 *   - A malformed line is a hard failure, never a silent skip.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];

    private static bool $loaded = false;

    private function __construct()
    {
    }

    public static function load(string $file): void
    {
        self::$vars   = [];
        self::$loaded = true;

        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(
                'Environment file not found or not readable: ' . basename(dirname($file)) . '/.env'
            );
        }

        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open environment file.');
        }

        $lineNo = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $lineNo++;
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }

                $eq = strpos($line, '=');
                if ($eq === false) {
                    throw new \RuntimeException('Malformed environment file at line ' . $lineNo . ' (expected KEY=VALUE).');
                }

                $key = trim(substr($line, 0, $eq));
                if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                    throw new \RuntimeException('Malformed environment key at line ' . $lineNo . '.');
                }

                self::$vars[$key] = self::parseValue(trim(substr($line, $eq + 1)));
            }
        } finally {
            fclose($handle);
        }
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars)) {
            return self::$vars[$key];
        }

        $fromProcess = getenv($key);
        if ($fromProcess !== false && $fromProcess !== '') {
            return $fromProcess;
        }

        return $default;
    }

    /**
     * @return array<string,string>
     */
    public static function all(): array
    {
        return self::$vars;
    }

    private static function parseValue(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $first = $raw[0];

        if ($first === '"' && str_ends_with($raw, '"') && strlen($raw) > 1) {
            return strtr(substr($raw, 1, -1), [
                '\\"'  => '"',
                '\\\\' => '\\',
                '\\n'  => "\n",
                '\\r'  => "\r",
                '\\t'  => "\t",
            ]);
        }

        if ($first === "'" && str_ends_with($raw, "'") && strlen($raw) > 1) {
            // Single quotes are literal: no unescaping whatsoever.
            return substr($raw, 1, -1);
        }

        // Unquoted: strip an inline comment only when preceded by whitespace,
        // so that values such as DB_PASS="pa#ss" survive intact.
        $value = preg_replace('/\s+#.*$/', '', $raw) ?? $raw;

        return trim($value);
    }
}
