<?php
// Public JSON for the Live map page (booking.html): free parking spots + active admin-registered places that have
// coordinates. Public information only (names, city, coordinates, price); no availability, no personal data.
// Booking is not done from here: bookable places link to reserve.php through the Find Parking flow.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/free_parking.php';

$bookable = [];
try {
    if (new_schema_ready()) {
        foreach (q_all(pdo(), 'SELECT id, name, kind, city, lat, lng, hourly_rate FROM parking_locations WHERE is_active = 1 ORDER BY city, name LIMIT 500') as $r) {
            if (fp_valid_coords($r['lat'], $r['lng'])) {
                $bookable[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'city' => fp_location_city($r) ?? (string) $r['city'],
                               'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'rate' => (int) $r['hourly_rate']];
            }
        }
    }
} catch (Throwable $e) {
    error_log('[parkaro] parking_points: ' . get_class($e));
    $bookable = [];
}
$free = array_values(fp_without_bookable_duplicates(fp_free_spots(), $bookable));
$free = array_map(static fn ($f) => ['id' => $f['id'], 'name' => $f['name'], 'city' => $f['city'], 'lat' => (float) $f['lat'], 'lng' => (float) $f['lng']], $free);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode(['cities' => FP_CITIES, 'free' => $free, 'bookable' => $bookable], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
