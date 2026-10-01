<?php

declare(strict_types=1);

namespace FieldPulse\Imaging;

use FieldPulse\Config\Config;
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
 * Five outcomes, per §12:
 *   PRESENT_VALID   GPS and/or capture time present and parseable
 *   PRESENT_INVALID present but unparseable or out of range (tampering signal)
 *   ABSENT          the block has no such field
 *   UNREADABLE      the file cannot be opened for metadata at all
 *   INCONSISTENT    every field parses, and they contradict each other
 *
 * INCONSISTENT is a separate axis rather than a fifth parse outcome, because it
 * is a different kind of finding. PRESENT_INVALID says a field is broken;
 * INCONSISTENT says the fields are individually well-formed and still cannot
 * all be true — a capture time later than the digitisation time, or a GPS
 * timestamp hours away from DateTimeOriginal in the same file. Those pairs only
 * disagree when the block has been assembled from more than one source, which is
 * the observable signature of a rewritten file. Neither read proves or disproves
 * anything on its own, so the state escalates to review and claims nothing.
 */
final class ExifExtractor
{
    public const PRESENT_VALID   = 'PRESENT_VALID';
    public const PRESENT_INVALID = 'PRESENT_INVALID';
    public const ABSENT          = 'ABSENT';
    public const UNREADABLE      = 'UNREADABLE';
    public const INCONSISTENT    = 'INCONSISTENT';

    /** No cross-tag contradiction was found. */
    public const CONSISTENT      = 'CONSISTENT';

    private function __construct()
    {
    }

    /**
     * @return array{
     *   gps_status:string,
     *   captured_status:string,
     *   consistency:string,
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
            'gps_status'            => self::ABSENT,
            'captured_status'       => self::ABSENT,
            'consistency'           => self::CONSISTENT,
            'latitude'              => null,
            'longitude'             => null,
            'captured_at'           => null,
            'make'                  => null,
            'model'                 => null,
            'datetime_original_raw' => null,
        ];

        if (!function_exists('exif_read_data')) {
            Logger::warning('exif.extension_missing');

            return [
                'gps_status'     => self::UNREADABLE,
                'captured_status' => self::UNREADABLE,
            ] + $empty;
        }

        $data = @exif_read_data($path, null, true, false);

        if ($data === false) {
            // No EXIF block, or an unreadable one. A plain JPEG with no EXIF is
            // entirely normal and must not be treated as suspicious on its own.
            return $empty;
        }

        $result  = $empty;
        $gpsTags = null;

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
            $gpsTags = $gps;
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

        $result['consistency'] = self::consistency($tags, $gpsTags);

        return $result;
    }

    /**
     * Do the parsed tags contradict each other?
     *
     * Two pairs are compared, both of which have a fixed internal order:
     *
     *   DateTimeOriginal <= DateTimeDigitized
     *     A photo is captured and then digitised, so the original time can be
     *     equal to or earlier than the digitised one. The reverse means one of
     *     the two strings was edited.
     *
     *   GPSDateStamp + GPSTimeStamp ~= DateTimeOriginal
     *     Both are written by the camera from the same clock in the same file.
     *     A large disagreement means the two blocks came from different captures.
     *
     * The tolerance is the same window TimestampVerifier already uses to decide
     * whether the EXIF and client clocks agree, so "these two timestamps are
     * from the same moment" means one thing across the whole pipeline instead of
     * being defined twice.
     *
     * A missing partner is not a contradiction: files routinely carry one of the
     * two, and an absent field is reported as ABSENT on its own axis.
     *
     * @param  array<array-key,mixed> $tags
     * @param  array<array-key,mixed>|null $gpsTags
     */
    private static function consistency(array $tags, ?array $gpsTags): string
    {
        $tolerance = Config::instance()->int('timestamps.max_future_skew');

        $original = self::parseExifDate((string) (self::firstString($tags, ['DateTimeOriginal']) ?? ''));
        $digitized = self::parseExifDate((string) (self::firstString($tags, ['DateTimeDigitized']) ?? ''));

        if ($original !== null && $digitized !== null) {
            if ($original->getTimestamp() - $digitized->getTimestamp() > $tolerance) {
                return self::INCONSISTENT;
            }
        }

        $gpsAt = self::gpsTimestamp($gpsTags);

        if ($original !== null && $gpsAt !== null) {
            if (abs($gpsAt->getTimestamp() - $original->getTimestamp()) > $tolerance) {
                return self::INCONSISTENT;
            }
        }

        return self::CONSISTENT;
    }

    /**
     * Compose GPSDateStamp + GPSTimeStamp into a UTC instant.
     *
     * PHP returns GPSTimeStamp as "HH:MM:SS" on most builds and as an array of
     * rationals on some, so both shapes are accepted. Neither tag carries a
     * timezone in EXIF 2.2; the pair is read as UTC, the same assumption
     * parseExifDate() makes for DateTimeOriginal, which is what makes the two
     * comparable at all.
     *
     * @param  array<array-key,mixed>|null $gpsTags
     */
    private static function gpsTimestamp(?array $gpsTags): ?\DateTimeImmutable
    {
        if ($gpsTags === null) {
            return null;
        }

        $date = $gpsTags['GPSDateStamp'] ?? null;

        if (!is_string($date)) {
            return null;
        }

        $date = trim($date);

        if (preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})$/', $date, $d) !== 1) {
            return null;
        }

        $time = $gpsTags['GPSTimeStamp'] ?? null;

        if (is_array($time)) {
            // Rational triples, as a non-standard encoder may write them.
            $parts = array_values($time);

            if (count($parts) < 3) {
                return null;
            }

            $numerator = 0.0;
            $denominator = 1.0;

            foreach ([[$parts[0], $parts[1]], [$parts[2], $parts[3] ?? null], [$parts[4] ?? null, $parts[5] ?? null]] as $i => $pair) {
                if ($pair[0] === null) {
                    return null;
                }

                $n = (float) $pair[0];
                $den = $pair[1] === null ? 1.0 : (float) $pair[1];

                if ($den == 0.0) {
                    return null;
                }

                $component = $n / $den;

                match ($i) {
                    0 => $numerator = $component * 3600.0,
                    1 => $numerator += $component * 60.0,
                    default => $numerator += $component,
                };
            }

            $hours = (int) floor($numerator / 3600);
            $minutes = (int) floor(fmod($numerator, 3600.0) / 60);
            $seconds = (int) round(fmod($numerator, 60.0));
        } else {
            if (!is_string($time)) {
                return null;
            }

            if (preg_match('/^(\d{2}):(\d{2}):(\d{2}(\.\d+)?)$/', trim($time), $t) !== 1) {
                return null;
            }

            $hours   = (int) $t[1];
            $minutes = (int) $t[2];
            $seconds = (int) round((float) $t[3]);
        }

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            return null;
        }

        if (!checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            return null;
        }

        return new \DateTimeImmutable(
            sprintf('%s-%s-%s %02d:%02d:%02d', $d[1], $d[2], $d[3], $hours, $minutes, $seconds),
            new \DateTimeZone('UTC')
        );
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
     * Combine the per-field findings into one status for submission_verifications.
     *
     * Precedence is worst-first. UNREADABLE means the block was never available,
     * which outranks everything; PRESENT_INVALID outranks INCONSISTENT because a
     * broken field is a simpler explanation for a bad block than a contradiction
     * between two well-formed ones, and reporting the simpler cause first keeps
     * the recorded reason actionable.
     */
    public static function combinedStatus(
        string $gpsStatus,
        string $capturedStatus,
        string $consistency = self::CONSISTENT
    ): string {
        if ($gpsStatus === self::UNREADABLE || $capturedStatus === self::UNREADABLE) {
            return self::UNREADABLE;
        }

        if ($gpsStatus === self::PRESENT_INVALID || $capturedStatus === self::PRESENT_INVALID) {
            return self::PRESENT_INVALID;
        }

        if ($consistency === self::INCONSISTENT) {
            return self::INCONSISTENT;
        }

        if ($gpsStatus === self::PRESENT_VALID || $capturedStatus === self::PRESENT_VALID) {
            return self::PRESENT_VALID;
        }

        return self::ABSENT;
    }

    /**
     * The combined status of an extract() result.
     *
     * One definition of "what was the EXIF state of this file", used by both the
     * verdict row and the decision matrix, so the column written to the database
     * and the reason shown to a reviewer can never disagree.
     *
     * @param  array<string,mixed> $extract
     */
    public static function statusOf(array $extract): string
    {
        return self::combinedStatus(
            (string) ($extract['gps_status'] ?? self::ABSENT),
            (string) ($extract['captured_status'] ?? self::ABSENT),
            (string) ($extract['consistency'] ?? self::CONSISTENT)
        );
    }
}
