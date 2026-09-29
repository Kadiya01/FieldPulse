<?php

declare(strict_types=1);

namespace FieldPulse\Config;

use FieldPulse\Support\Env;
use FieldPulse\Support\Paths;

/**
 * Immutable configuration container.
 *
 * Immutability is enforced by API surface: the backing array is private, there
 * are no setters, and arr() returns a copy-on-write value. Once constructed the
 * object cannot be modified, so a mid-request config change is impossible.
 */
final class Config
{
    private static ?self $instance = null;

    /** @param array<string,mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::boot();
        }

        return self::$instance;
    }

    public static function boot(?string $envFile = null): self
    {
        $envFile ??= FIELDPULSE_PRIVATE_ROOT . '/.env';

        if (!Env::isLoaded()) {
            Env::load($envFile);
        }

        /** @var array<string,mixed> $schema */
        $schema = require FIELDPULSE_APP_ROOT . '/Config/schema.php';

        self::$instance = new self($schema);
        self::validate();

        return self::$instance;
    }

    public static function isBooted(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Startup validation. Fails loudly and early rather than at 03:00 inside a
     * cron worker with a misconfigured secret.
     */
    private static function validate(): void
    {
        $c = self::$instance;

        $secret = $c->str('security.jwt_secret');
        if (strlen($secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be at least 32 bytes. Generate one with: '
                . 'php -r "echo bin2hex(random_bytes(48)), PHP_EOL;"'
            );
        }

        if ($c->str('db.name') === '' || $c->str('db.user') === '') {
            throw new \RuntimeException('DB_NAME and DB_USER must be configured in .env.');
        }

        if (!in_array($c->str('timezone.business'), timezone_identifiers_list(), true)) {
            throw new \RuntimeException('APP_TIMEZONE is not a valid timezone identifier.');
        }

        if ($c->bool('app.debug') && $c->str('app.env') === 'production') {
            // Refuse to serve a production environment with debug enabled: it is
            // the difference between a stack trace and an empty 500.
            throw new \RuntimeException('APP_DEBUG must be false when APP_ENV=production.');
        }

        if ($c->int('phash.hamming_threshold') < 1) {
            throw new \RuntimeException('PHASH_HAMMING_THRESHOLD must be >= 1.');
        }

        if ($c->int('security.access_ttl') < 60) {
            throw new \RuntimeException('ACCESS_TOKEN_TTL must be >= 60 seconds.');
        }
    }

    public function has(string $path): bool
    {
        $sentinel = new \stdClass();

        return $this->get($path, $sentinel) !== $sentinel;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->values;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function str(string $path, ?string $default = null): string
    {
        $value = $this->get($path, $default);

        if ($value === null) {
            throw new \RuntimeException('Missing required config key: ' . $path);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (!is_scalar($value)) {
            throw new \RuntimeException('Config key is not a scalar: ' . $path);
        }

        return (string) $value;
    }

    public function int(string $path, ?int $default = null): int
    {
        $value = $this->get($path, $default);

        if ($value === null) {
            throw new \RuntimeException('Missing required config key: ' . $path);
        }

        if (!is_numeric($value)) {
            throw new \RuntimeException('Config key is not numeric: ' . $path);
        }

        return (int) $value;
    }

    public function float(string $path, ?float $default = null): float
    {
        $value = $this->get($path, $default);

        if ($value === null || !is_numeric($value)) {
            throw new \RuntimeException('Config key is not numeric: ' . $path);
        }

        return (float) $value;
    }

    public function bool(string $path, ?bool $default = null): bool
    {
        $value = $this->get($path, $default);

        if ($value === null) {
            throw new \RuntimeException('Missing required config key: ' . $path);
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<mixed>
     */
    public function arr(string $path): array
    {
        $value = $this->get($path, []);

        if (!is_array($value)) {
            throw new \RuntimeException('Config key is not an array: ' . $path);
        }

        return $value;
    }

    public function businessTimezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->str('timezone.business'));
    }

    /**
     * Verifies the storage tree exists with safe permissions. Called by the
     * HTTP kernel and the worker; never during boot, so a CLI-only task such
     * as `migrate` still works on a host where uploads are not yet deployed.
     */
    public function assertStorageReady(): void
    {
        Paths::ensureLayout();
    }
}
