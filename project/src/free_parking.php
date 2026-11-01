<?php
/**
 * Helpers for the Find Parking page: the free/local parking list, city handling, distance and
 * Google Maps directions links. Pure functions (no database, no output) so they can be tested.
 *
 * Two kinds of place are shown together but NEVER mixed up:
 *   - "bookable": rows of parking_locations registered by an admin (price, capacity, reservation flow)
 *   - "free":     informational spots from src/free_parking_data.php (no price, no booking, availability unknown)
 */
declare(strict_types=1);

/** The three supported cities, with the centre used when a city is picked and no spot is known. */
const FP_CITIES = [
    'Kolhapur'   => ['lat' => 16.704, 'lng' => 74.243, 'zoom' => 13, 'radius' => 12000],
    'Ajara'      => ['lat' => 16.116, 'lng' => 74.211, 'zoom' => 14, 'radius' => 6000],
    'Gadhinglaj' => ['lat' => 16.226, 'lng' => 74.350, 'zoom' => 14, 'radius' => 6000],
];

/**
 * Map any spelling of a city (the old pages wrote "Gadhinjlaj") to one of FP_CITIES, or null.
 * When the text names several cities ("Ajara, Kolhapur district") the one named FIRST wins, because
 * people write the town first and the district after it.
 */
function fp_canonical_city(?string $city): ?string
{
    $c = strtolower(trim((string) $city));
    if ($c === '') {
        return null;
    }
    $c = str_replace('gadhinjlaj', 'gadhinglaj', $c);
    $best = null;
    $bestPos = PHP_INT_MAX;
    foreach (FP_CITIES as $name => $_) {
        if (preg_match('/\b' . strtolower($name) . '\b/', $c, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] < $bestPos) {
            $best = $name;
            $bestPos = $m[0][1];
        }
    }
    return $best;
}

/** The supported city whose centre is nearest to the point, if the point lies inside that city's area; else null. */
function fp_city_by_coords($lat, $lng): ?string
{
    if (!fp_valid_coords($lat, $lng)) {
        return null;
    }
    $best = null;
    $bestD = PHP_FLOAT_MAX;
    foreach (FP_CITIES as $name => $c) {
        $d = fp_distance_m((float) $lat, (float) $lng, $c['lat'], $c['lng']);
        if ($d <= $c['radius'] && $d < $bestD) {
            $best = $name;
            $bestD = $d;
        }
    }
    return $best;
}

/**
 * Which supported city an admin-registered place belongs to. The map position is the most reliable
 * evidence (people type "Kolhapur" for a place in Ajara because it is in Kolhapur district); then the
 * city text; then the address. Null when it is none of the three cities.
 */
function fp_location_city(array $loc): ?string
{
    return fp_city_by_coords($loc['lat'] ?? null, $loc['lng'] ?? null)
        ?? fp_canonical_city((string) ($loc['city'] ?? ''))
        ?? fp_canonical_city((string) ($loc['address'] ?? ''));
}

/** A coordinate pair that is a real, usable location (not null / 0,0 / out of range). */
function fp_valid_coords($lat, $lng): bool
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
        return false;
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    if (!is_finite($lat) || !is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
        return false;
    }
    return !($lat == 0.0 && $lng == 0.0);
}

/** The free spots that are shown (see tools/build_free_parking_data.php). */
function fp_free_spots(?array $override = null): array
{
    static $cache = null;
    if ($override !== null) {
        return $override;
    }
    if ($cache === null) {
        $data = require __DIR__ . '/free_parking_data.php';
        $cache = $data['spots'] ?? [];
    }
    return $cache;
}

/** Straight-line (great-circle) distance in metres between two points. NOT a road distance. */
function fp_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $r = 6371000.0;
    $p1 = deg2rad($lat1);
    $p2 = deg2rad($lat2);
    $dp = $p2 - $p1;
    $dl = deg2rad($lng2 - $lng1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
}

/** "850 m" under a kilometre, otherwise "3.2 km". */
function fp_format_distance(float $metres): string
{
    if ($metres < 1000) {
        return (int) (round($metres / 10) * 10) . ' m';
    }
    return number_format($metres / 1000, $metres < 10000 ? 1 : 0) . ' km';
}

/**
 * Standard Google Maps directions link. The origin is added ONLY when the user's real coordinates
 * are given; the destination must be a valid coordinate pair or null is returned (no link at all).
 */
function fp_directions_url($destLat, $destLng, $originLat = null, $originLng = null): ?string
{
    if (!fp_valid_coords($destLat, $destLng)) {
        return null;
    }
    $params = ['api' => '1'];
    if (fp_valid_coords($originLat, $originLng)) {
        $params['origin'] = ((float) $originLat) . ',' . ((float) $originLng);
    }
    $params['destination'] = ((float) $destLat) . ',' . ((float) $destLng);
    $params['travelmode'] = 'driving';
    return 'https://www.google.com/maps/dir/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/** Lower-case words of a place name without filler ("parking", city names), for duplicate matching. */
function fp_name_key(string $name): string
{
    $n = strtolower(str_replace('gadhinjlaj', 'gadhinglaj', $name));
    $n = preg_replace('/\b(parking|kolhapur|ajara|gadhinglaj|shree|shri)\b/', ' ', $n) ?? $n;
    $n = preg_replace('/[^a-z0-9]+/', ' ', $n) ?? $n;
    return trim(preg_replace('/\s+/', ' ', $n) ?? $n);
}

/**
 * Drop free spots that are really the same place as an admin-registered (bookable) one, so a place is
 * never listed twice: same city and same name, or within 30 m.
 */
function fp_without_bookable_duplicates(array $free, array $bookable): array
{
    $out = [];
    foreach ($free as $f) {
        $dup = false;
        foreach ($bookable as $b) {
            if (fp_location_city($b) !== $f['city']) {
                continue;
            }
            $sameName = fp_name_key((string) $b['name']) !== '' && fp_name_key((string) $b['name']) === fp_name_key((string) $f['name']);
            $close = fp_valid_coords($b['lat'] ?? null, $b['lng'] ?? null)
                && fp_distance_m((float) $b['lat'], (float) $b['lng'], (float) $f['lat'], (float) $f['lng']) <= 30;
            if ($sameName || $close) {
                $dup = true;
                break;
            }
        }
        if (!$dup) {
            $out[] = $f;
        }
    }
    return $out;
}
