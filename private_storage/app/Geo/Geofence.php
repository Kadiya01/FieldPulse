<?php

declare(strict_types=1);

namespace FieldPulse\Geo;

use FieldPulse\Config\Config;
use FieldPulse\Database\Connection;
use FieldPulse\Http\ErrorCode;

/**
 * Geofence evaluation against operator-assigned centres (§12).
 *
 * The centre coordinates come exclusively from `agent_sites`, which is written
 * by an operator. Nothing in this class ever treats a client-supplied position
 * as a reference point.
 *
 * Outcomes:
 *   WITHIN_GEOFENCE       inside at least one assigned site radius
 *   OUTSIDE_GEOFENCE      outside every assigned radius
 *   NO_GPS                the client supplied no usable position
 *   INVALID_GPS           the coordinates are not a position at all
 *   SITE_UNASSIGNED       the agent has no active site configured, so no
 *                         geofence judgement is possible
 *   GPS_UNRELIABLE        position present but implausible for a handset
 *
 * INVALID_GPS is kept separate from GPS_UNRELIABLE because the two call for
 * different conclusions. Out-of-range or non-finite coordinates are a broken
 * payload, and no amount of review will make them describe a place. A fix that
 * is merely implausible — null island, a transposed pair, a confidence radius
 * wider than the site — is a readable position whose *reliability* is in doubt,
 * which is exactly what a human is for.
 *
 * SITE_UNASSIGNED is deliberately not treated as a pass. An agent with no site
 * assignment must reach a human, not be auto-verified on the absence of a check.
 */
final class Geofence
{
    public const WITHIN_GEOFENCE  = 'WITHIN_GEOFENCE';
    public const OUTSIDE_GEOFENCE = 'OUTSIDE_GEOFENCE';
    public const NO_GPS           = 'NO_GPS';
    public const INVALID_GPS      = 'INVALID_GPS';
    public const SITE_UNASSIGNED  = 'SITE_UNASSIGNED';
    public const GPS_UNRELIABLE   = 'GPS_UNRELIABLE';

    private function __construct()
    {
    }

    /**
     * Evaluate a position for an agent.
     *
     * @param  float|null $clientLat
     * @param  float|null $clientLng
     * @param  float|null $exifLat    Server-read EXIF position, used when the
     *                                client supplied none.
     * @param  float|null $exifLng
     * @param  float|null $accuracyM  Client-reported horizontal accuracy in
     *                                metres. Untrusted, but it is the only
     *                                signal available about how *good* the fix
     *                                is, and a fix with a 2 km confidence radius
     *                                cannot be used to decide a 250 m geofence.
     * @return array{
     *   status:string, site_id:?int, site_name:?string,
     *   distance_m:?float, radius_m:?int, source:string
     * }
     */
    public static function evaluate(
        int $agentId,
        ?float $clientLat,
        ?float $clientLng,
        ?float $exifLat = null,
        ?float $exifLng = null,
        ?float $accuracyM = null
    ): array {
        $source = 'client';
        $lat    = $clientLat;
        $lng    = $clientLng;

        // Fall back to EXIF only when the client sent nothing at all. When both
        // exist they are compared against each other by TimestampVerifier; a
        // geofence decision must not silently prefer whichever source is
        // convenient.
        if ($lat === null || $lng === null) {
            if ($exifLat !== null && $exifLng !== null) {
                $lat    = $exifLat;
                $lng    = $exifLng;
                $source = 'exif';
            }
        }

        if ($lat === null || $lng === null) {
            return self::result(self::NO_GPS);
        }

        // A coordinate that cannot be a position is rejected before any
        // plausibility judgement and before the site search. Falling back to the
        // EXIF position here would hide a broken payload behind a different
        // source, and the box search would otherwise compare nonsense.
        if (!self::isCoordinate($lat, $lng)) {
            return self::result(self::INVALID_GPS);
        }

        if (!self::isPlausibleHandsetPosition($lat, $lng)) {
            return self::result(self::GPS_UNRELIABLE);
        }

        // A position with a confidence radius larger than the tolerance cannot
        // decide a site radius. This is checked against the *site* radius rather
        // than a fixed constant, because a 250 m site and a 2 km site have
        // genuinely different ideas about what counts as a usable fix.
        //
        // Only the client path carries accuracyM: EXIF GPS does not record a
        // confidence figure, so an EXIF-only fix is judged on position alone,
        // exactly as it was before accuracy_m was stored.
        if ($accuracyM !== null && self::exceedsSiteTolerance($agentId, $accuracyM)) {
            return self::result(self::GPS_UNRELIABLE);
        }

        $sites = self::candidateSites($agentId, $lat, $lng);

        if ($sites === []) {
            $anySite = (int) Connection::fetchValue(
                'SELECT COUNT(*) FROM agent_sites WHERE agent_id = :a AND is_active = 1',
                ['a' => $agentId]
            );

            return $anySite === 0
                ? self::result(self::SITE_UNASSIGNED)
                : self::result(self::OUTSIDE_GEOFENCE);
        }

        $nearest         = null;
        $nearestDistance = PHP_FLOAT_MAX;

        foreach ($sites as $site) {
            $distance = Haversine::metres(
                $lat,
                $lng,
                (float) $site['center_latitude'],
                (float) $site['center_longitude']
            );

            if ($distance < $nearestDistance) {
                $nearestDistance = $distance;
                $nearest         = $site;
            }
        }

        if ($nearest === null) {
            return self::result(self::SITE_UNASSIGNED);
        }

        $radius = (int) $nearest['radius_m'];

        return [
            'status'     => $nearestDistance <= $radius ? self::WITHIN_GEOFENCE : self::OUTSIDE_GEOFENCE,
            'site_id'    => (int) $nearest['id'],
            'site_name'  => (string) $nearest['name'],
            'distance_m' => round($nearestDistance, 1),
            'radius_m'   => $radius,
            'source'     => $source,
        ];
    }

    /**
     * Bounding-box candidate search (§12).
     *
     * The box is sized from the largest radius the agent is configured with, so
     * a single indexed query replaces a per-site round trip while still bounding
     * the result set that PHP has to measure exactly.
     *
     * @return list<array<string,mixed>>
     */
    private static function candidateSites(int $agentId, float $lat, float $lng): array
    {
        $maxRadius = (int) Connection::fetchValue(
            'SELECT MAX(radius_m) FROM agent_sites WHERE agent_id = :a AND is_active = 1',
            ['a' => $agentId]
        );

        if ($maxRadius <= 0) {
            $maxRadius = Config::instance()->int('geofence.default_radius_m');
        }

        $box = Haversine::boundingBox($lat, $lng, $maxRadius + Config::instance()->int('geofence.bbox_padding_m'));

        return Connection::fetchAll(
            'SELECT id, name, center_latitude, center_longitude, radius_m
               FROM agent_sites
              WHERE agent_id = :agent_id
                AND is_active = 1
                AND center_latitude BETWEEN :min_lat AND :max_lat
                AND center_longitude BETWEEN :min_lng AND :max_lng',
            [
                'agent_id' => $agentId,
                'min_lat'  => $box['min_lat'],
                'max_lat'  => $box['max_lat'],
                'min_lng'  => $box['min_lng'],
                'max_lng'  => $box['max_lng'],
            ]
        );
    }

    /**
     * Can this accuracy figure decide any of this agent's site radii?
     *
     * True when the reported accuracy is worse than the smallest site the agent
     * is assigned — a fix that cannot rule out being inside a 100 m radius is
     * not usable evidence for that radius, however correct the point itself is.
     *
     * An agent with no assigned sites returns false, so the check cannot
     * manufacture an OUTSIDE/SITE_UNASSIGNED outcome into a GPS failure. The
     * NO_GPS and SITE_UNASSIGNED branches below are reached on their own terms.
     */
    private static function exceedsSiteTolerance(int $agentId, float $accuracyM): bool
    {
        $smallestRadius = (int) Connection::fetchValue(
            'SELECT MIN(radius_m) FROM agent_sites WHERE agent_id = :a AND is_active = 1',
            ['a' => $agentId]
        );

        if ($smallestRadius <= 0) {
            return false;
        }

        return $accuracyM > $smallestRadius;
    }

    /**
     * Can these two numbers denote a point on the earth?
     *
     * is_finite() is checked first and on its own, because every comparison
     * below is false for NAN (NAN < x and NAN > x are both false), so a range
     * test alone would wave NAN straight through into the site search.
     */
    private static function isCoordinate(float $lat, float $lng): bool
    {
        if (!is_finite($lat) || !is_finite($lng)) {
            return false;
        }

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return false;
        }

        return true;
    }

    /**
     * A (0,0) fix or a coordinate in the middle of an ocean is a broken handset,
     * not a field visit. Null Island is the classic software default that leaks
     * straight into a geofence decision.
     *
     * The range check is not repeated here: isCoordinate() has already run, so
     * reaching this point means the numbers are in range and only their
     * *plausibility* is in question.
     */
    private static function isPlausibleHandsetPosition(float $lat, float $lng): bool
    {
        if ($lat === 0.0 && $lng === 0.0) {
            return false;
        }

        // Exactly 0.0000000 is what a failed GPS read produces; a real fix has
        // noise in the low digits.
        if (abs($lat) < 1e-7 && abs($lng) < 1e-7) {
            return false;
        }

        return true;
    }

    /**
     * @return array{status:string,site_id:?int,site_name:?string,distance_m:?float,radius_m:?int,source:string}
     */
    private static function result(string $status): array
    {
        return [
            'status'     => $status,
            'site_id'    => null,
            'site_name'  => null,
            'distance_m' => null,
            'radius_m'   => null,
            'source'     => 'none',
        ];
    }

    public static function isSatisfied(string $status): bool
    {
        return $status === self::WITHIN_GEOFENCE;
    }

    public static function isBlocking(string $status): bool
    {
        return $status === self::INVALID_GPS
            || $status === self::GPS_UNRELIABLE
            || $status === self::NO_GPS;
    }

    public static function errorCode(): string
    {
        return ErrorCode::UNKNOWN_SITE;
    }
}
