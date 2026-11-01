<?php
/** Run:  php tests/unit/free_parking_test.php     (needs no database) */
declare(strict_types=1);

require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/free_parking.php';

T::group('distance');
T::ok(abs(fp_distance_m(16.7, 74.2, 16.7, 74.2)) < 0.001, 'same point = 0 m');
T::ok(abs(fp_distance_m(0, 0, 0, 1) - 111195) < 100, '1 degree of longitude on the equator is about 111.2 km');
T::ok(abs(fp_distance_m(16.7, 74.2, 16.71, 74.2) - 1112) < 5, '0.01 degree of latitude is about 1.11 km');
T::ok(abs(fp_distance_m(16.7, 74.2, 16.8, 74.3) - fp_distance_m(16.8, 74.3, 16.7, 74.2)) < 0.001, 'distance is symmetric');
T::same('0 m', fp_format_distance(0.0), 'format 0');
T::same('850 m', fp_format_distance(852.0), 'format under 1 km, rounded to 10 m');
T::same('1.2 km', fp_format_distance(1234.0), 'format km with one decimal');
T::same('12 km', fp_format_distance(12345.0), 'format >= 10 km without decimals');

T::group('coordinates');
foreach ([[16.7, 74.2, true], ['16.7', '74.2', true], [null, 74.2, false], [16.7, null, false], ['', '', false], [0, 0, false], [91, 10, false], [10, 181, false], ['abc', 1, false], [NAN, 1, false]] as [$a, $b, $exp]) {
    T::same($exp, fp_valid_coords($a, $b), 'valid_coords(' . var_export($a, true) . ', ' . var_export($b, true) . ')');
}

T::group('directions url');
T::same('https://www.google.com/maps/dir/?api=1&destination=16.7075%2C74.2433&travelmode=driving', fp_directions_url(16.7075, 74.2433), 'destination only when no origin is known');
T::same('https://www.google.com/maps/dir/?api=1&origin=16.7%2C74.2&destination=16.7075%2C74.2433&travelmode=driving', fp_directions_url(16.7075, 74.2433, 16.7, 74.2), 'origin + destination when the user location is known');
T::same(null, fp_directions_url(null, null), 'no link without a destination');
T::same(null, fp_directions_url(0, 0), 'no link for 0,0');
T::same('https://www.google.com/maps/dir/?api=1&destination=16.7075%2C74.2433&travelmode=driving', fp_directions_url(16.7075, 74.2433, 'x', 74.2), 'an invalid origin is dropped, not invented');

T::group('city names');
foreach ([['Kolhapur', 'Kolhapur'], ['  ajara ', 'Ajara'], ['Gadhinglaj', 'Gadhinglaj'], ['Gadhinjlaj', 'Gadhinglaj'], ['Rajarampuri, Kolhapur', 'Kolhapur'], ['Pune', null], ['', null], [null, null]] as [$in, $exp]) {
    T::same($exp, fp_canonical_city($in), 'city ' . var_export($in, true));
}

T::group('the free-parking dataset');
$spots = fp_free_spots();
T::ok(count($spots) > 100, 'dataset loaded (' . count($spots) . ' spots)');
$ids = array_column($spots, 'id');
T::same(count($ids), count(array_unique($ids)), 'ids are unique');
$seen = []; $dupes = 0; $outside = 0; $badKeys = 0;
foreach ($spots as $s) {
    if (array_diff(['id', 'name', 'city', 'lat', 'lng'], array_keys($s))) { $badKeys++; continue; }
    $k = $s['city'] . '|' . fp_name_key($s['name']);
    if (isset($seen[$k])) { $dupes++; }
    $seen[$k] = true;
    $c = FP_CITIES[$s['city']] ?? null;
    if (!$c || !fp_valid_coords($s['lat'], $s['lng']) || fp_distance_m($c['lat'], $c['lng'], (float) $s['lat'], (float) $s['lng']) > 16000) { $outside++; }
}
T::same(0, $badKeys, 'every spot has id, name, city, lat, lng');
T::same(0, $dupes, 'no two spots share a city + name');
T::same(0, $outside, 'every spot has valid coordinates inside its own city area');
T::same(['Ajara', 'Gadhinglaj', 'Kolhapur'], array_values(array_unique(array_column($spots, 'city'))) === [] ? [] : (function () use ($spots) { $c = array_unique(array_column($spots, 'city')); sort($c); return $c; })(), 'all three cities have spots');

T::group('duplicates with admin places');
$free = [['id' => 'a', 'name' => 'Rajarampuri Parking', 'city' => 'Kolhapur', 'lat' => 16.70, 'lng' => 74.24], ['id' => 'b', 'name' => 'Far Away', 'city' => 'Kolhapur', 'lat' => 16.72, 'lng' => 74.26], ['id' => 'c', 'name' => 'Same Name', 'city' => 'Ajara', 'lat' => 16.11, 'lng' => 74.21]];
$admin = [['name' => 'Rajarampuri', 'city' => 'Kolhapur', 'lat' => 16.9, 'lng' => 74.9], ['name' => 'Other', 'city' => 'Kolhapur', 'lat' => 16.72, 'lng' => 74.26001], ['name' => 'Same Name', 'city' => 'Kolhapur', 'lat' => null, 'lng' => null]];
T::same(['c'], array_column(fp_without_bookable_duplicates($free, $admin), 'id'), 'same name (any filler words) or within 30 m is dropped; same name in another city is kept');

T::group('which city a place belongs to');
T::same('Ajara', fp_canonical_city('Ajara, Kolhapur'), 'first-named city wins ("Ajara, Kolhapur")');
T::same('Ajara', fp_canonical_city('Ajara Kolhapur district'), '"Ajara Kolhapur district" is Ajara');
T::same('Kolhapur', fp_canonical_city('Kolhapur, Maharashtra'), 'plain Kolhapur still works');
T::same('Ajara', fp_city_by_coords(16.117, 74.211), 'coordinates in Ajara');
T::same('Kolhapur', fp_city_by_coords(16.705, 74.2433), 'coordinates in Kolhapur');
T::same(null, fp_city_by_coords(19.07, 72.87), 'coordinates far away (Mumbai) are in none of the three');
T::same(null, fp_city_by_coords(null, null), 'no coordinates -> null');
T::same('Ajara', fp_location_city(['city' => 'Kolhapur', 'address' => 'x', 'lat' => 16.117, 'lng' => 74.211]), 'the map position beats wrong city text');
T::same('Ajara', fp_location_city(['city' => 'Ajara, Kolhapur', 'address' => 'x', 'lat' => null, 'lng' => null]), 'without coordinates the city text decides');
T::same('Gadhinglaj', fp_location_city(['city' => 'Town', 'address' => 'Main road, Gadhinglaj', 'lat' => null, 'lng' => null]), 'then the address');
T::same(null, fp_location_city(['city' => 'Pune', 'address' => 'x', 'lat' => null, 'lng' => null]), 'another town -> none of the three');
T::finish();
