<?php

declare(strict_types=1);

namespace FieldPulse\Testing;

/**
 * A minimal test harness.
 *
 * No PHPUnit, no Composer. The deployment target is a basic cPanel plan where
 * running `composer install` is often not possible, and a test suite nobody can
 * run is worth less than a small one everybody can. This is deliberately the
 * smallest thing that gives useful failure output and a non-zero exit code, so
 * it can be wired into cPanel Cron alongside the worker.
 *
 *   php private_storage/bin/selftest.php
 *   php private_storage/bin/selftest.php --filter=phash
 */
final class TestRunner
{
    /** @var list<array{group:string,name:string,fn:callable}> */
    private array $tests = [];

    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    private string $currentGroup = 'general';

    private ?string $filter;

    public function __construct(?string $filter = null)
    {
        $this->filter = $filter === null || $filter === '' ? null : strtolower($filter);
    }

    public function group(string $name): void
    {
        $this->currentGroup = $name;
    }

    public function test(string $name, callable $fn): void
    {
        $this->tests[] = ['group' => $this->currentGroup, 'name' => $name, 'fn' => $fn];
    }

    /**
     * @return int process exit code
     */
    public function run(bool $verbose = false): int
    {
        $group = null;
        $started = microtime(true);

        foreach ($this->tests as $test) {
            $label = strtolower($test['group'] . ' ' . $test['name']);

            if ($this->filter !== null && !str_contains($label, $this->filter)) {
                continue;
            }

            if ($group !== $test['group']) {
                $group = $test['group'];
                \FieldPulse\Console\Cli::heading($group);
            }

            try {
                ($test['fn'])($this);
                $this->passed++;

                if ($verbose) {
                    \FieldPulse\Console\Cli::ok($test['name']);
                }
            } catch (\Throwable $e) {
                $this->failures[] = sprintf(
                    '%s / %s: %s (%s:%d)',
                    $test['group'],
                    $test['name'],
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine()
                );

                \FieldPulse\Console\Cli::fail($test['name'] . ' — ' . $e->getMessage());
            }
        }

        $duration = round((microtime(true) - $started) * 1000);

        \FieldPulse\Console\Cli::heading('Result');
        \FieldPulse\Console\Cli::out('  passed:  ' . $this->passed);
        \FieldPulse\Console\Cli::out('  failed:  ' . count($this->failures));
        \FieldPulse\Console\Cli::out('  elapsed: ' . $duration . 'ms');

        return $this->failures === [] ? 0 : 1;
    }

    // --- Assertions --------------------------------------------------------

    public function assertTrue(bool $condition, string $message = 'expected true'): void
    {
        if ($condition !== true) {
            throw new \RuntimeException($message);
        }
    }

    public function assertFalse(bool $condition, string $message = 'expected false'): void
    {
        if ($condition !== false) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Distinct from assertTrue on purpose: a falsy-but-not-false value such as
     * null, 0 or '' is the near-miss that produces a confusing downstream
     * failure, so this only passes for a real boolean true.
     */
    public function assertNotFalse(mixed $value, string $message = 'expected not false'): void
    {
        if ($value === false) {
            throw new \RuntimeException($message);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(sprintf(
                '%sexpected %s, got %s',
                $message === '' ? '' : $message . ': ',
                self::render($expected),
                self::render($actual)
            ));
        }
    }

    public function assertNotSame(mixed $unexpected, mixed $actual, string $message = ''): void
    {
        if ($unexpected === $actual) {
            throw new \RuntimeException(sprintf(
                '%sexpected a value other than %s',
                $message === '' ? '' : $message . ': ',
                self::render($unexpected)
            ));
        }
    }

    public function assertNull(mixed $value, string $message = 'expected null'): void
    {
        if ($value !== null) {
            throw new \RuntimeException($message . ', got ' . self::render($value));
        }
    }

    public function assertNotNull(mixed $value, string $message = 'expected a value, got null'): void
    {
        if ($value === null) {
            throw new \RuntimeException($message);
        }
    }

    public function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new \RuntimeException(sprintf(
                '%sexpected to find "%s" in "%s"',
                $message === '' ? '' : $message . ': ',
                $needle,
                mb_substr($haystack, 0, 120)
            ));
        }
    }

    public function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        if (preg_match($pattern, $subject) !== 1) {
            throw new \RuntimeException(sprintf(
                '%sexpected %s to match %s',
                $message === '' ? '' : $message . ': ',
                self::render($subject),
                $pattern
            ));
        }
    }

    /**
     * Absolute/relative tolerance, for float maths like the DCT and Haversine.
     */
    public function assertNear(float $expected, float $actual, float $tolerance, string $message = ''): void
    {
        if (abs($expected - $actual) > $tolerance) {
            throw new \RuntimeException(sprintf(
                '%sexpected %s +/- %s, got %s',
                $message === '' ? '' : $message . ': ',
                self::render($expected),
                self::render($tolerance),
                self::render($actual)
            ));
        }
    }

    /**
     * Assert that a callable throws, optionally with a message containing $needle.
     */
    public function assertThrows(callable $fn, ?string $needle = null, string $message = 'expected an exception'): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($needle !== null && !str_contains($e->getMessage(), $needle)) {
                throw new \RuntimeException(sprintf(
                    '%s: expected the message to contain "%s", got "%s"',
                    $message,
                    $needle,
                    $e->getMessage()
                ));
            }

            return;
        }

        throw new \RuntimeException($message . ', but nothing was thrown');
    }

    private static function render(mixed $value): string
    {
        return match (true) {
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_float($value)  => sprintf('%.10g', $value),
            is_array($value)  => 'array(' . count($value) . ')',
            default           => (string) $value,
        };
    }
}
