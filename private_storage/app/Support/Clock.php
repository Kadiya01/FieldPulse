<?php

declare(strict_types=1);

namespace FieldPulse\Support;

/**
 * All clock access in FieldPulse funnels through this class.
 *
 * Invariants:
 *   - Persisted and compared values are UTC.
 *   - Business/reporting timezone is applied explicitly by the caller.
 *   - A single overridable "now" lets the test-suite and the verification
 *     engine reason about time without touching the host clock.
 */
final class Clock
{
    private static ?\DateTimeImmutable $frozenAt = null;

    private function __construct()
    {
    }

    public static function freeze(\DateTimeImmutable $at): void
    {
        self::$frozenAt = $at->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function unfreeze(): void
    {
        self::$frozenAt = null;
    }

    public static function now(): \DateTimeImmutable
    {
        if (self::$frozenAt !== null) {
            return self::$frozenAt;
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Unix timestamp, seconds. Used for request-timestamp skew checks. */
    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    /** 'Y-m-d H:i:s' in UTC — the storage format for every DATETIME column. */
    public static function sql(?\DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** 'Y-m-d' in UTC. */
    public static function date(?\DateTimeImmutable $at = null): string
    {
        return ($at ?? self::now())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * Parse an untrusted DATETIME string from the database into UTC.
     * Returns null when the value is absent or unparseable, so callers are
     * forced to treat database timestamps as untrusted input too.
     *
     * Strict on purpose. PHP's parser silently normalises impossible dates —
     * '2026-02-30' becomes 2026-03-02 without complaint — so a corrupt
     * timestamp would be laundered into a plausible but wrong one, and
     * TimestampVerifier or PeriodResolver would then reason about the wrong
     * day and return a wrong verdict. Refusing is the only safe option: a null
     * makes the caller skip or fail loudly, whereas a rolled-over date does
     * neither.
     *
     * Accepts the exact MySQL DATETIME shape, with an optional fractional
     * second for DATETIME(6) columns, plus either 'T' or a space separator.
     */
    public static function parseSql(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?$/';

        if (preg_match($pattern, $value, $m) !== 1) {
            return null;
        }

        [$year, $month, $day, $hour, $minute, $second] =
            [(int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6]];

        // Reject 2026-02-30 and 0000-00-00 00:00:00, both of which PHP accepts.
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        // Belt and braces: confirm PHP did not shift the value we just
        // validated. If a future PHP becomes more permissive here, the
        // round-trip comparison catches it instead of returning a wrong date.
        if ($dt->format('Y-m-d') !== $m[1] . '-' . $m[2] . '-' . $m[3]) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function shift(int $seconds): \DateTimeImmutable
    {
        return self::now()->modify(sprintf('%+d seconds', $seconds));
    }
}
