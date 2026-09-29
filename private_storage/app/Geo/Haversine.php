<?php

declare(strict_types=1);

namespace FieldPulse\Geo;

use FieldPulse\Config\Config;

/**
 * Great-circle distance on a sphere.
 *
 * ST_Distance_Sphere() is not used even where available, and that is
 * deliberate:
 *   - it is absent on MariaDB, so a fallback path would be needed anyway;
 *   - it computes in degrees with a legacy radius, giving a different answer
 *     from the metre-based value a geofence threshold is expressed in;
 *   - the candidate set after the bounding-box filter is a handful of rows, so
 *     doing the final arithmetic in PHP costs nothing and makes the geofence
 *     decision reproducible from the stored DECIMAL coordinates alone.
 *
 * The bounding-box pre-filter (§12) still happens in SQL, where it is
 * index-friendly, so the row count stays bounded.
 */
final class Haversine
{
    private function __construct()
    {
    }

    public static function earthRadius(): float
    {
        return Config::instance()->float('geofence.earth_radius_m');
    }

    /**
     * Distance in metres between two WGS-84 coordinates.
     */
    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $deltaPhi = deg2rad($lat2 - $lat1);
        $deltaLambda = deg2rad($lng2 - $lng1);

        $a = (sin($deltaPhi / 2) ** 2)
            + (cos($phi1) * cos($phi2) * (sin($deltaLambda / 2) ** 2));

        // Clamp before asin: floating point can push $a a hair above 1 for
        // antipodal points, which would make asin() return NAN and silently
        // turn a distance comparison into a false.
        $a = min(1.0, max(0.0, $a));

        $c = 2.0 * atan2(sqrt($a), sqrt(1.0 - $a));

        return self::earthRadius() * $c;
    }

    /**
     * Axis-aligned bounding box that fully contains a circle of $radiusM.
     *
     * @return array{min_lat:float,max_lat:float,min_lng:float,max_lng:float}
     */
    public static function boundingBox(float $lat, float $lng, float $radiusM): array
    {
        $radius   = self::earthRadius();
        $latDelta = rad2deg($radiusM / $radius);

        // Longitude degrees shrink with latitude, so the longitude delta must be
        // divided by cos(latitude). Near the poles that term explodes, so the
        // box is clamped to the full longitude range instead of producing an
        // out-of-range coordinate.
        $cosLat = cos(deg2rad($lat));
        $lngDelta = abs($cosLat) < 1e-9 ? 180.0 : rad2deg($radiusM / ($radius * $cosLat));

        return [
            'min_lat' => max(-90.0, $lat - $latDelta),
            'max_lat' => min(90.0, $lat + $latDelta),
            'min_lng' => max(-180.0, $lng - $lngDelta),
            'max_lng' => min(180.0, $lng + $lngDelta),
        ];
    }

    /**
     * True when (lat, lng) lies inside the pre-filter box.
     */
    public static function withinBox(float $lat, float $lng, array $box): bool
    {
        return $lat >= $box['min_lat'] && $lat <= $box['max_lat']
            && $lng >= $box['min_lng'] && $lng <= $box['max_lng'];
    }
}
