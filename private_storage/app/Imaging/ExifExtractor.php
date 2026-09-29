<?php

declare(strict_types=1);

namespace FieldPulse\Imaging;

use FieldPulse\Support\Clock;
use FieldPulse\Support\Logger;

/**
 * EXIF extraction and classification (§12).
 *
 * Everything read here is attacker-controlled: EXIF is a plain-text metadata
 * block that any tool can rewrite, and this platform explicitly refuses to
 * treat it as proof. It is a *cross-check* against the client-asserted values
 * and the server clock, which is exactly why the columns in `submissions` are
 * named server_exif_* — the prefix records that these are the server's reading
 * of an untrusted source, not a server assertion.
 *
 * Four outcomes, per §12:
 *   PRESENT_VALID   GPS and/or capture time present and parseable
 *   PRESENT_INVALID present but unparseable or out of range (tampering signal)
 *   ABSENT          the block has no such field
 *   UNREADABLE      the file cannot be opened for metadata at all
 */
final class ExifExtractor
{
    public const PRESENT_VALID   = 'PRESENT_VALID';
    public const PRESENT_INVALID = 'PRESENT_INVALID';
    public const ABSENT          = 'ABSENT';
    public const UNREADABLE      = 'UNREADABLE';

    private function __construct()
    {
    }

    /**
     * @return array{
     *   gps_status:string,
     *   captured_status:string,
     *   latitude:?float,
     *   longitude:?float,
     *   captured_at:?string,
     *   make:?string,
     *   model:?string,
     *   datetime_original_raw:?string
     * }
     */
    public static function extract(string $path): array
    {
        $empty = [
            'gps_status'         => self::ABSENT,
            'captured_status'     => self::ABSENT,
            'latitude'            => null,
            'longitude'           => null,
            'captured_at'         => null,
            'make'                => null,
            'model'               => null,
            'datetime_original_raw' => null,
        ];

        if (!function_exists('exif_read_data')) {
            Logger::warning('exif.extension_missing');

            return ['gps_status' => self::UNREADABLE, 'captured_status' => self::UNREADABLE] + $empty;
        }

        $data = @exif_read_data($path, null, true, false);

        if ($data === false) {
            // No EXIF block, or an unreadable one. A plain JPEG with no EXIF is
            // entirely normal and must not be treated as suspicious on its own.
            return $empty;
        }

        $result            = $empty;

        // exif_read_data() with section = null groups the tags by IFD rather
        // than returning them flat: DateTimeOriginal and DateTimeDigitized live
        // in ['EXIF'], while DateTime, Make and Model live in ['IFD0'].
        // Searching $data directly therefore found nothing in a perfectly good
        // photograph, every capture time came back ABSENT, every submission was
        // flagged TIMESTAMP_MISSING, and the review queue took all of the work
        // because nothing could ever be auto-verified.
        $tags = [];

        foreach (['IFD0', 'EXIF'] as $section) {
            if (isset($data[$section]) && is_array($data[$section])) {
                $tags += $data[$section];
            }
        }

        // A few encoders put these at the top level instead, so keep those too.
        $tags += $data;

        $result['make']    = self::shortString($tags['Make'] ?? null);
        $result['model']   = self::shortString($tags['Model'] ?? null);

        // --- GPS -------------------------------------------------------------
        if (isset($data['GPS']) && is_array($data['GPS'])) {
            $gps = $data['GPS'];
            $dms = self::dmsToDecimal(
                $gps['GPSLatitudeRef'] ?? null,
                $gps['GPSLatitude'] ?? null,
                $gps['GPSLongitudeRef'] ?? null,
                $gps['GPSLongitude'] ?? null
            );

            if ($dms === null) {
                $result['gps_status'] = self::PRESENT_INVALID;
                Logger::warning('exif.gps_unparseable');
            } else {
                [$lat, $lng] = $dms;

                // Range validation. A GPS tag outside the valid range is either
                // corruption or a deliberate edit, and both are the same signal.
                if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
                    $result['gps_status'] = self::PRESENT_INVALID;
                } else {
                    $result['gps_status']  = self::PRESENT_VALID;
                    $result['latitude']    = round($lat, 7);
                    $result['longitude']   = round($lng, 7);
                }
            }
        }

        // --- capture time ----------------------------------------------------
        $raw = self::firstString($tags, [
            'DateTimeOriginal',
            'DateTimeDigitized',
            'DateTime',
        ]);

        if ($raw !== null) {
            $result['datetime_original_raw'] = $raw;
            $parsed = self::parseExifDate($raw);

            if ($parsed === null) {
                $result['captured_status'] = self::PRESENT_INVALID;
            } else {
                $result['captured_status'] = self::PRESENT_VALID;
                $result['captured_at']     = Clock::sql($parsed);
            }
        }

        return $result;
    }

    /**
     * EXIF stores GPS as degrees/minutes/seconds in separate tags.
     *
     * The "denominator" values are written as 1 even when the numerator is an
     * integer, and some cameras omit them entirely, so the numerator is used
     * directly when the denominator is absent or 1.
     *
     * @return array{0:float,1:float}|null
     */
    private static function dmsToDecimal(mixed $latRef, mixed $lat, mixed $lngRef, mixed $lng): ?array
    {
        $latitude  = self::dmsComponent($lat, $latRef, 'N', 'S');
        $longitude = self::dmsComponent($lng, $lngRef, 'E', 'W');

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [$latitude, $longitude];
    }

    /**
     * @return float|null
     */
    private static function dmsComponent(mixed $value, mixed $ref, string $positive, string $negative): ?float
    {
        if (!is_array($value) || count($value) < 3) {
            return null;
        }

        $parts = array_values($value);

        foreach ([$parts[0], $parts[1], $parts[2]] as $part) {
            if (!is_numeric($part)) {
                return null;
            }
        }

        $degrees = (float) $parts[0];
        $minutes = (float) $parts[1];
        $seconds = (float) $parts[2];

        if ($minutes >= 60.0 || $seconds >= 60.0) {
            // Impossible in valid EXIF: minutes/seconds must be < 60.
            return null;
        }

        $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);

        $reference = is_string($ref) ? strtoupper(substr(trim($ref), 0, 1)) : '';

        if ($reference === $negative) {
            $decimal = -$decimal;
        } elseif ($reference !== $positive && $reference !== '') {
            return null;
        }

        return $decimal;
    }

    /**
     * Parse "Y:m:d H:i:s". EXIF has no timezone concept — the value is whatever
     * the camera's local clock was set to — so it is read as UTC and the
     * resulting skew is treated as a review signal rather than as a fact.
     */
    public static function parseExifDate(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);

        if (preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $raw, $m) !== 1) {
            return null;
        }

        [, $y, $mo, $d, $h, $mi, $s] = $m;

        if (!checkdate((int) $mo, (int) $d, (int) $y)) {
            return null;
        }

        if ((int) $h > 23 || (int) $mi > 59 || (int) $s > 59) {
            return null;
        }

        return new \DateTimeImmutable(
            sprintf('%s-%s-%s %s:%s:%s', $y, $mo, $d, $h, $mi, $s),
            new \DateTimeZone('UTC')
        );
    }

    /**
     * @param  array<array-key,mixed> $data
     * @param  list<string>           $keys
     */
    private static function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function shortString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 100);
    }

    /**
     * Combine the GPS findings into one status for submission_verifications.
     */
    public static function combinedStatus(string $gpsStatus, string $capturedStatus): string
    {
        if ($gpsStatus === self::UNREADABLE || $capturedStatus === self::UNREADABLE) {
            return self::UNREADABLE;
        }

        if ($gpsStatus === self::PRESENT_INVALID || $capturedStatus === self::PRESENT_INVALID) {
            return self::PRESENT_INVALID;
        }

        if ($gpsStatus === self::PRESENT_VALID || $capturedStatus === self::PRESENT_VALID) {
            return self::PRESENT_VALID;
        }

        return self::ABSENT;
    }
}
