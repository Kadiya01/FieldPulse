<?php

declare(strict_types=1);

namespace FieldPulse\Domain;

use FieldPulse\Config\Config;
use FieldPulse\Support\Clock;

/**
 * Business-time reporting periods (§2).
 *
 * The contract requires Monday 00:00:00 in the configured business timezone
 * (default Africa/Lagos, UTC+1, no DST) and explicitly prefers period_start_date
 * over a week_number/year pair.
 *
 * Two representations are needed and they are not the same thing:
 *
 *   periodStartDate()   'Y-m-d'  — the Monday in the business timezone. This is
 *                                what lands in agent_performance_summary.
 *   utcRangeForPeriod() the equivalent [start, end) window in UTC, which is
 *                                what a WHERE clause on a UTC DATETIME column
 *                                must be compared against.
 *
 * Mixing them up is the classic off-by-one: an agent submitting at 23:30 local
 * on Sunday belongs to the week that ended four minutes later, and at 00:30 on
 * Monday belongs to the next one. Deriving both from a single business-timezone
 * Monday is what keeps the boundaries honest.
 */
final class PeriodResolver
{
    private function __construct()
    {
    }

    /**
     * The Monday of the business week containing $utcDatetime, as 'Y-m-d'.
     */
    public static function periodStartDate(?string $utcDatetime = null): string
    {
        return self::periodFor($utcDatetime)['date'];
    }

    /**
     * @return array{date:string,start_utc:string,end_utc:string,business_tz:string}
     */
    public static function periodFor(?string $utcDatetime = null): array
    {
        $c        = Config::instance();
        $business = $c->businessTimezone();

        $utc = $utcDatetime === null
            ? Clock::now()
            : (Clock::parseSql($utcDatetime) ?? Clock::now());

        $local = $utc->setTimezone($business);

        /*
         * Find the period start day, then set the time to midnight.
         *
         * Two things were wrong here and both corrupted the weekly totals:
         *
         *  1. `modify('monday this week')` hardcoded Monday, making
         *     timezone.period_day a lie. Worse, it relies on an English relative
         *     date string, which silently changes meaning under a different
         *     locale. Integer arithmetic on the ISO weekday cannot.
         *
         *  2. setTime() was being passed period_day as its HOUR argument. With
         *     period_day = 1 that made every period start at 01:00 local instead
         *     of 00:00, while utcRangeForPeriod() — which builds midnight
         *     directly — started at 00:00. The two disagreed by an hour, so
         *     submissions in that hour were assigned a period by the first and
         *     then counted against a range from the second. On a payment ledger
         *     that is a systematic off-by-one at every Monday boundary.
         */
        $startDay = (int) $c->int('timezone.period_day', 1);

        if ($startDay < 1 || $startDay > 7) {
            throw new \InvalidArgumentException(
                'timezone.period_day must be an ISO weekday (1 = Monday .. 7 = Sunday).'
            );
        }

        // ISO 'N': 1 = Monday .. 7 = Sunday. Days back to the period start,
        // wrapping so a start day of, say, Wednesday still resolves forwards.
        $isoWeekday = (int) $local->format('N');
        $offset     = ($isoWeekday - $startDay + 7) % 7;

        $mondayLocal = $offset === 0
            ? $local->setTime(0, 0, 0)
            : $local->modify('-' . $offset . ' day')->setTime(0, 0, 0);

        $nextLocal = $mondayLocal->modify('+7 days');

        return [
            'date'        => $mondayLocal->format('Y-m-d'),
            'start_utc'   => Clock::sql($mondayLocal->setTimezone(new \DateTimeZone('UTC'))),
            'end_utc'     => Clock::sql($nextLocal->setTimezone(new \DateTimeZone('UTC'))),
            'business_tz' => $business->getName(),
        ];
    }

    /**
     * UTC window for a known period_start_date, for range queries.
     *
     * Built from the same rule as periodFor() — local midnight, converted to UTC
     * — so the two can never drift. Any disagreement here silently moves
     * submissions across the week boundary, which is why the two are asserted
     * equal in the self-test.
     *
     * @return array{start_utc:string,end_utc:string}
     */
    public static function utcRangeForPeriod(string $periodDate): array
    {
        $business = Config::instance()->businessTimezone();

        $startLocal = (new \DateTimeImmutable($periodDate . ' 00:00:00', $business));
        $endLocal   = $startLocal->modify('+7 days');

        return [
            'start_utc' => Clock::sql($startLocal),
            'end_utc'   => Clock::sql($endLocal),
        ];
    }

    /**
     * Which timestamp decides a submission's period?
     *
     * EXIF capture time is the best evidence of when the work happened, but it
     * is untrusted and camera clocks drift, so it is only used when it is
     * plausible relative to the server clock; otherwise the client's asserted
     * time is used, and failing that the moment the server received the file.
     *
     * @param array<string,mixed> $submission A `submissions` row.
     * @return array{timestamp:string,source:string}
     */
    public static function authoritativeTimestamp(array $submission): array
    {
        $receivedAt = Clock::parseSql((string) $submission['server_received_at']) ?? Clock::now();
        $c          = Config::instance();

        $exif   = Clock::parseSql($submission['server_exif_captured_at'] ?? null);
        $client = Clock::parseSql($submission['client_captured_at'] ?? null);

        if ($exif !== null && self::isPlausible($exif, $receivedAt, $c)) {
            return ['timestamp' => Clock::sql($exif), 'source' => 'exif'];
        }

        if ($client !== null && self::isPlausible($client, $receivedAt, $c)) {
            return ['timestamp' => Clock::sql($client), 'source' => 'client'];
        }

        return ['timestamp' => Clock::sql($receivedAt), 'source' => 'server_received'];
    }

    /**
     * A candidate timestamp is usable when it is not absurdly far from the
     * server's own record of when the file arrived.
     */
    private static function isPlausible(\DateTimeImmutable $candidate, \DateTimeImmutable $receivedAt, \Config\Config $c): bool
    {
        $skew = abs($candidate->getTimestamp() - $receivedAt->getTimestamp());

        // A capture time more than a day before the upload is a camera-clock
        // problem, not evidence of when the work happened; a time after the
        // upload is impossible. Both fall back to a trusted source.
        return $skew <= 86400 && $candidate->getTimestamp() <= $receivedAt->getTimestamp();
    }

    /**
     * Enumerate period dates for a leaderboard window.
     *
     * @return list<string>
     */
    public static function periodRange(int $weeks): array
    {
        $weeks = max(1, min($weeks, 104));
        $dates = [];
        $date  = self::periodStartDate();

        for ($i = 0; $i < $weeks; $i++) {
            $dates[] = $date;
            $date    = (new \DateTimeImmutable($date . ' 00:00:00', Config::instance()->businessTimezone()))
                ->modify('-7 days')
                ->format('Y-m-d');
        }

        return $dates;
    }
}
