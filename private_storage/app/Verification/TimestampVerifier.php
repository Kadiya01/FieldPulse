<?php

declare(strict_types=1);

namespace FieldPulse\Verification;

use FieldPulse\Config\Config;
use FieldPulse\Support\Clock;

/**
 * Timestamp evidence checks (§11).
 *
 * Nothing here trusts a client-supplied time. Every comparison is anchored on
 * server_received_at, which the database sets with UTC_TIMESTAMP() and which no
 * client can influence.
 *
 * Outcomes are graded rather than binary. A capture time that is missing is not
 * the same as one that is in the future: the first is an old camera or a
 * stripped file and deserves a human, the second is a clock the photographer
 * can fix and deserves rejection.
 */
final class TimestampVerifier
{
    public const VALID          = 'VALID';
    public const MISSING        = 'MISSING';
    public const UNPARSEABLE    = 'UNPARSEABLE';
    public const FUTURE         = 'FUTURE';
    public const TOO_OLD        = 'TOO_OLD';
    public const DISCREPANCY    = 'DISCREPANCY';

    private function __construct()
    {
    }

    /**
     * @param  string      $receivedAt   server_received_at (UTC)
     * @param  string|null $exifAt       server-observed EXIF DateTimeOriginal
     * @param  string|null $clientAt     client-asserted capture time
     * @return array{status:string,pass:bool,review:bool,exif:array<string,mixed>,client:array<string,mixed>,delta_seconds:?int,message:string}
     */
    public static function evaluate(string $receivedAt, ?string $exifAt, ?string $clientAt): array
    {
        $c       = Config::instance();
        $received = Clock::parseSql($receivedAt) ?? Clock::now();

        $result = [
            'status'         => self::VALID,
            'pass'           => true,
            'review'         => false,
            'exif'           => ['present' => $exifAt !== null, 'parsed' => false, 'value' => null, 'delta_seconds' => null],
            'client'         => ['present' => $clientAt !== null, 'parsed' => false, 'value' => null, 'delta_seconds' => null],
            'delta_seconds'  => null,
            'message'        => 'timestamps consistent',
        ];

        // A photo with no EXIF timestamp at all is the single most common reason
        // a legitimate submission cannot be auto-verified, so it is a review, not
        // a rejection.
        if ($exifAt === null || $exifAt === '') {
            $result['status'] = self::MISSING;
            $result['pass']   = false;
            $result['review'] = true;
            $result['message'] = 'no EXIF capture timestamp available';

            if ($clientAt !== null && $clientAt !== '') {
                // The client still asserted a time, so fall through to judging that
                // instead of stopping at "missing".
                $result = self::judgeClientOnly($result, $received, $clientAt, $c);
            }

            return $result;
        }

        $exif = Clock::parseSql($exifAt);

        if ($exif === null) {
            $result['status'] = self::UNPARSEABLE;
            $result['pass']   = false;
            $result['review'] = true;
            $result['message'] = 'EXIF capture timestamp is not a valid date';

            return $result;
        }

        $exifDelta  = $exif->getTimestamp() - $received->getTimestamp();
        $result['exif']['parsed'] = true;
        $result['exif']['value']  = Clock::sql($exif);
        $result['exif']['delta_seconds'] = $exifDelta;

        // Future capture: the file claims to have been taken after we received
        // it. No camera clock correction runs that far forward, so this is a
        // client-side time assertion and is rejected rather than reviewed.
        if ($exifDelta > 0) {
            if ($exifDelta <= $c->int('timestamps.max_future_skew')) {
                $result['message'] = 'EXIF timestamp marginally in the future (within tolerance)';
            } else {
                $result['status'] = self::FUTURE;
                $result['pass']   = false;
                $result['message'] = 'EXIF capture timestamp is ' . $exifDelta . 's in the future';

                return $result;
            }
        }

        if ($exifDelta < 0 && abs($exifDelta) > $c->int('timestamps.max_past_skew')) {
            $result['status'] = self::TOO_OLD;
            $result['pass']   = false;
            $result['review'] = true;
            $result['message'] = 'EXIF capture timestamp is ' . abs($exifDelta) . 's before receipt';

            return $result;
        }

        if ($clientAt !== null && $clientAt !== '') {
            $client = Clock::parseSql($clientAt);

            if ($client === null) {
                $result['review']  = true;
                $result['message'] = 'client-asserted timestamp is not a valid date';
            } else {
                $clientDelta = $client->getTimestamp() - $received->getTimestamp();
                $result['client']['parsed'] = true;
                $result['client']['value']  = Clock::sql($client);
                $result['client']['delta_seconds'] = $clientDelta;

                $agreement = abs($exif->getTimestamp() - $client->getTimestamp());

                // The two sources disagreeing by more than a few minutes is a
                // signal worth a human's attention, but either one may be the
                // inaccurate one, so it grades to review rather than reject.
                if ($agreement > $c->int('timestamps.max_future_skew')) {
                    $result['status'] = self::DISCREPANCY;
                    $result['pass']   = false;
                    $result['review'] = true;
                    $result['delta_seconds'] = $agreement;
                    $result['message'] = 'EXIF and client timestamps differ by ' . $agreement . 's';
                } elseif ($clientDelta > $c->int('timestamps.max_future_skew')) {
                    $result['review']  = true;
                    $result['message'] = 'client-asserted timestamp is implausibly in the future';
                }
            }
        }

        return $result;
    }

    /**
     * Used when EXIF is absent but the client did assert a time.
     *
     * @param  array<string,mixed> $result
     * @return array<string,mixed>
     */
    private static function judgeClientOnly(array $result, \DateTimeImmutable $received, string $clientAt, Config $c): array
    {
        $client = Clock::parseSql($clientAt);

        if ($client === null) {
            $result['review']  = true;
            $result['message'] = 'EXIF missing and client-asserted timestamp is unparseable';

            return $result;
        }

        $delta = $client->getTimestamp() - $received->getTimestamp();

        $result['client']['parsed'] = true;
        $result['client']['value']  = Clock::sql($client);
        $result['client']['delta_seconds'] = $delta;
        $result['delta_seconds'] = $delta;

        if ($delta > $c->int('timestamps.max_future_skew')) {
            $result['message'] = 'EXIF missing; client-asserted timestamp is ' . $delta . 's in the future';
        } elseif (abs($delta) > $c->int('timestamps.max_past_skew')) {
            $result['message'] = 'EXIF missing; client-asserted timestamp is older than the allowed window';
        } else {
            $result['message'] = 'EXIF missing; client-asserted timestamp accepted as corroboration';
        }

        return $result;
    }
}
