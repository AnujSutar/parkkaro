<?php
/**
 * Parking locations: an admin registers a place (public or private), users search them.
 *
 * Every admin-side function takes the owner's username and only ever touches rows that
 * admin owns (WHERE owner_username = ?), so one admin cannot edit another admin's place.
 * Needs src/bootstrap.php and src/dbx.php; src/reservations.php is loaded here for the capacity maths.
 */
declare(strict_types=1);

require_once __DIR__ . '/reservations.php';
require_once __DIR__ . '/free_parking.php';

const LOCATION_KINDS = ['public' => 'Public', 'private' => 'Private'];
const MAX_LOCATION_CAPACITY = 500;
const MAX_HOURLY_RATE = 10000;

/**
 * Validate the "add / edit place" form.
 * @return array{0: string[], 1: array} [errors, clean values ready for the database]
 */
function validate_location_input(array $in): array
{
    $errors = [];
    $textPattern = '/^[\p{L}\p{N}][\p{L}\p{N}\p{M} .,&\'\-\/()]*$/u';

    $name = trim((string) ($in['name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100 || preg_match($textPattern, $name) !== 1) {
        $errors[] = 'Enter a place name (2-100 characters; letters, numbers and . , & \' - / ( ) only).';
    }

    $kind = (string) ($in['kind'] ?? '');
    if (!isset(LOCATION_KINDS[$kind])) {
        $errors[] = 'Choose whether the place is public or private.';
    }

    $city = trim((string) ($in['city'] ?? ''));
    if (mb_strlen($city) < 2 || mb_strlen($city) > 60 || preg_match($textPattern, $city) !== 1) {
        $errors[] = 'Enter the city or town (2-60 characters).';
    }

    $address = trim((string) ($in['address'] ?? ''));
    if (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
        $errors[] = 'Enter the address (5-255 characters).';
    }

    $description = trim((string) ($in['description'] ?? ''));
    if (mb_strlen($description) > 500) {
        $errors[] = 'The description can be at most 500 characters.';
    }

    $capacity = filter_var($in['capacity'] ?? '', FILTER_VALIDATE_INT);
    if ($capacity === false || $capacity < 1 || $capacity > MAX_LOCATION_CAPACITY) {
        $errors[] = 'Capacity must be a whole number from 1 to ' . MAX_LOCATION_CAPACITY . '.';
        $capacity = 1;
    }

    $rate = filter_var($in['hourly_rate'] ?? '', FILTER_VALIDATE_INT);
    if ($rate === false || $rate < 1 || $rate > MAX_HOURLY_RATE) {
        $errors[] = 'The hourly rate must be a whole number of rupees from 1 to ' . MAX_HOURLY_RATE . '.';
        $rate = 1;
    }

    // Coordinates: both or neither.
    $latRaw = trim((string) ($in['lat'] ?? ''));
    $lngRaw = trim((string) ($in['lng'] ?? ''));
    $lat = null;
    $lng = null;
    if ($latRaw !== '' || $lngRaw !== '') {
        if (!is_numeric($latRaw) || !is_numeric($lngRaw)
            || (float) $latRaw < -90 || (float) $latRaw > 90 || (float) $lngRaw < -180 || (float) $lngRaw > 180) {
            $errors[] = 'Latitude (-90 to 90) and longitude (-180 to 180) must both be valid numbers, or both left empty.';
        } else {
            $lat = number_format((float) $latRaw, 6, '.', '');
            $lng = number_format((float) $lngRaw, 6, '.', '');
        }
    }

    // How customers may pay at this place. Forms send pay_modes_present=1 so an unticked box can be told from "not sent".
    $marker = isset($in['pay_modes_present']);
    $acceptsOnline = $marker ? !empty($in['pay_online']) : true;
    $acceptsCash = $marker ? !empty($in['pay_cash']) : true;
    if (!$acceptsOnline && !$acceptsCash) {
        $errors[] = 'Choose at least one way for customers to pay: online, or at the place.';
    }

    // The city must agree with the map position, so the place shows up under the right city in Find parking.
    $byText = fp_canonical_city($city);
    $byMap = fp_city_by_coords($lat, $lng);
    if ($byText !== null && $byMap !== null && $byText !== $byMap) {
        $errors[] = "The latitude/longitude is in $byMap, but the city says $byText. Choose $byMap, or correct the coordinates.";
    } elseif ($byText !== null || $byMap !== null) {
        $city = $byMap ?? $byText;   // store the plain city name ("Ajara"), not "Ajara, Kolhapur district"
    }

    // Opening hours: both empty = open 24 hours.
    $from = trim((string) ($in['open_from'] ?? ''));
    $until = trim((string) ($in['open_until'] ?? ''));
    $openFrom = null;
    $openUntil = null;
    if ($from !== '' || $until !== '') {
        $okFrom = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) === 1;
        $okUntil = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $until) === 1;
        if (!$okFrom || !$okUntil || $from >= $until) {
            $errors[] = 'Opening hours need both times (HH:MM) with the opening time before the closing time, or leave both empty for 24 hours.';
        } else {
            $openFrom = $from . ':00';
            $openUntil = $until . ':00';
        }
    }

    return [$errors, [
        'name' => $name, 'kind' => $kind, 'city' => $city, 'address' => $address, 'description' => $description,
        'capacity' => (int) $capacity, 'hourly_rate' => (int) $rate, 'lat' => $lat, 'lng' => $lng,
        'open_from' => $openFrom, 'open_until' => $openUntil,
        'accepts_online' => $acceptsOnline ? 1 : 0, 'accepts_cash' => $acceptsCash ? 1 : 0,
    ]];
}

/**
 * Register a place for an admin.
 * @return array{ok:bool,id?:int,error?:string}
 */
function create_location(PDO $pdo, string $ownerUsername, string $adminZone, array $clean, DateTimeImmutable $now): array
{
    $exists = (int) q_val($pdo, 'SELECT COUNT(*) FROM parking_locations WHERE owner_username = ? AND name = ?', [$ownerUsername, $clean['name']], 0);
    if ($exists > 0) {
        return ['ok' => false, 'error' => 'You already have a place with this name.'];
    }
    $nowStr = $now->format(PK_DT);
    q_run(
        $pdo,
        'INSERT INTO parking_locations
           (owner_username, admin_zone, name, kind, city, address, description, lat, lng, capacity, hourly_rate,
            open_from, open_until, is_active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
        [$ownerUsername, $adminZone, $clean['name'], $clean['kind'], $clean['city'], $clean['address'], $clean['description'],
         $clean['lat'], $clean['lng'], $clean['capacity'], $clean['hourly_rate'], $clean['open_from'], $clean['open_until'],
         $nowStr, $nowStr]
    );
    $newId = (int) $pdo->lastInsertId();
    if (notify_schema_ready($pdo)) {   // migration 002 present: store the accepted payment methods
        q_run($pdo, 'UPDATE parking_locations SET accepts_online = ?, accepts_cash = ? WHERE id = ?',
            [(int) ($clean['accepts_online'] ?? 1), (int) ($clean['accepts_cash'] ?? 1), $newId]);
    }
    return ['ok' => true, 'id' => $newId];
}

/**
 * Update a place the admin owns. Capacity may not be lowered below what is already reserved
 * for the future (that would leave paying customers without a space).
 * @return array{ok:bool,error?:string}
 */
function update_location(PDO $pdo, string $ownerUsername, int $id, array $clean, DateTimeImmutable $now): array
{
    return with_write_tx($pdo, static function (PDO $pdo) use ($ownerUsername, $id, $clean, $now): array {
        $loc = lock_location($pdo, $id);
        if ($loc === null || $loc['owner_username'] !== $ownerUsername) {
            return ['ok' => false, 'error' => 'Place not found.'];
        }
        $dup = (int) q_val($pdo, 'SELECT COUNT(*) FROM parking_locations WHERE owner_username = ? AND name = ? AND id <> ?', [$ownerUsername, $clean['name'], $id], 0);
        if ($dup > 0) {
            return ['ok' => false, 'error' => 'You already have another place with this name.'];
        }
        if ($clean['capacity'] < (int) $loc['capacity']) {
            $peak = location_peak_usage($pdo, $id, $now->format(PK_DT), '9999-12-31 00:00:00', $now);
            if ($clean['capacity'] < $peak) {
                return ['ok' => false, 'error' => "Capacity cannot go below $peak: that many spaces are already reserved at one time in the future."];
            }
        }
        q_run(
            $pdo,
            'UPDATE parking_locations SET name = ?, kind = ?, city = ?, address = ?, description = ?, lat = ?, lng = ?,
                    capacity = ?, hourly_rate = ?, open_from = ?, open_until = ?, updated_at = ?
             WHERE id = ? AND owner_username = ?',
            [$clean['name'], $clean['kind'], $clean['city'], $clean['address'], $clean['description'], $clean['lat'], $clean['lng'],
             $clean['capacity'], $clean['hourly_rate'], $clean['open_from'], $clean['open_until'], $now->format(PK_DT),
             $id, $ownerUsername]
        );
        if (notify_schema_ready($pdo)) {
            q_run($pdo, 'UPDATE parking_locations SET accepts_online = ?, accepts_cash = ? WHERE id = ? AND owner_username = ?',
                [(int) ($clean['accepts_online'] ?? 1), (int) ($clean['accepts_cash'] ?? 1), $id, $ownerUsername]);
        }
        return ['ok' => true];
    });
}

/** Switch a place on/off for NEW reservations. Existing reservations are not touched. */
function set_location_active(PDO $pdo, string $ownerUsername, int $id, bool $active, DateTimeImmutable $now): bool
{
    $st = q_run(
        $pdo,
        'UPDATE parking_locations SET is_active = ?, updated_at = ? WHERE id = ? AND owner_username = ?',
        [$active ? 1 : 0, $now->format(PK_DT), $id, $ownerUsername]
    );
    return $st->rowCount() === 1;
}

function list_owner_locations(PDO $pdo, string $ownerUsername): array
{
    return q_all($pdo, 'SELECT * FROM parking_locations WHERE owner_username = ? ORDER BY name', [$ownerUsername]);
}

function get_owner_location(PDO $pdo, string $ownerUsername, int $id): ?array
{
    return q_one($pdo, 'SELECT * FROM parking_locations WHERE id = ? AND owner_username = ?', [$id, $ownerUsername]);
}

/** One place, for the public pages. Inactive places are not returned. */
function get_public_location(PDO $pdo, int $id): ?array
{
    return q_one($pdo, 'SELECT * FROM parking_locations WHERE id = ? AND is_active = 1', [$id]);
}

/**
 * Search active places. Filters: 'city' (partial match), 'kind' ('public'|'private'|'').
 * With a time window ($start/$end already validated, 'Y-m-d H:i:s') each result also carries
 * 'free_spaces' for that whole window and places with none left are omitted unless $includeFull.
 * Without a window 'free_spaces' is the number free right now.
 */
function search_locations(PDO $pdo, array $filters, ?array $window, DateTimeImmutable $now, bool $includeFull = false, int $limit = 50): array
{
    $sql = 'SELECT * FROM parking_locations WHERE is_active = 1';
    $params = [];
    $city = trim((string) ($filters['city'] ?? ''));
    if ($city !== '') {
        $sql .= " AND (city LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!' OR address LIKE ? ESCAPE '!')";
        $like = '%' . like_escape($city) . '%';
        array_push($params, $like, $like, $like);
    }
    $kind = (string) ($filters['kind'] ?? '');
    if (isset(LOCATION_KINDS[$kind])) {
        $sql .= ' AND kind = ?';
        $params[] = $kind;
    }
    $sql .= ' ORDER BY city, name LIMIT ?';
    $params[] = max(1, min(200, $limit));

    $rows = q_all($pdo, $sql, $params);
    if ($window !== null) {
        $from = $window['start'];
        $to = $window['end'];
    } else {
        $from = $now->format(PK_DT);
        $to = $now->modify('+1 minute')->format(PK_DT);
    }
    $out = [];
    foreach ($rows as $row) {
        $row['free_spaces'] = location_free_spaces($pdo, $row, $from, $to, $now);
        if ($row['free_spaces'] > 0 || $includeFull) {
            $out[] = $row;
        }
    }
    return $out;
}
