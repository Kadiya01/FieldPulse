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
 *   SITE_UNASSIGNED       the agent has no active site configured, so no
 *                         geofence judgement is possible
 *   GPS_UNRELIABLE        position present but implausible for a handset
 *
 * SITE_UNASSIGNED is deliberately not treated as a pass. An agent with no site
 * assignment must reach a human, not be auto-verified on the absence of a check.
 */
final class Geofence
{
    public const WITHIN_GEOFENCE  = 'WITHIN_GEOFENCE';
    public const OUTSIDE_GEOFENCE = 'OUTSIDE_GEOFENCE';
    public const NO_GPS           = 'NO_GPS';
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
        ?float $exifLng = null
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

        if (!self::isPlausibleHandsetPosition($lat, $lng)) {
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
     * A (0,0) fix or a coordinate in the middle of an ocean is a broken handset,
     * not a field visit. Null Island is the classic software default that leaks
     * straight into a geofence decision.
     */
    private static function isPlausibleHandsetPosition(float $lat, float $lng): bool
    {
        if ($lat === 0.0 && $lng === 0.0) {
            return false;
        }

        if (abs($lat) > 90.0 || abs($lng) > 180.0) {
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
        return $status === self::GPS_UNRELIABLE || $status === self::NO_GPS;
    }

    public static function errorCode(): string
    {
        return ErrorCode::UNKNOWN_SITE;
    }
}
