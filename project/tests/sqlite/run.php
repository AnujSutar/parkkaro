<?php
/**
 * SQLite-backed tests for the Parkkaro reservation / payment / earnings code.
 *
 *   php tests/sqlite/run.php
 *
 * What this DOES prove: the real PHP functions (validation, capacity maths, payment
 * verification, earnings queries, authorisation scoping) behave correctly against a real SQL
 * engine, and the multi-process test shows no overbooking with the locking used here.
 *
 * What this does NOT prove (needs MySQL/MariaDB, see tests/integration/mysql_migration_test.php):
 *   - that the MySQL migration file executes on a real server,
 *   - MySQL-specific behaviour (SELECT ... FOR UPDATE row locks, information_schema checks),
 *   - the live Razorpay API. The gateway HTTP calls are replaced by a fake HTTP client; signatures
 *     are computed with the documented algorithm and a TEST secret, so the verification code is
 *     exercised for real, but no real gateway was contacted.
 */
declare(strict_types=1);

putenv('APP_KEY=sqlite-test-key');
putenv('APP_TIMEZONE=Asia/Kolkata');

require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/dbx.php';
require __DIR__ . '/../../src/booking.php';
require __DIR__ . '/../../src/reservations.php';
require __DIR__ . '/../../src/locations.php';
require __DIR__ . '/../../src/payments.php';
require __DIR__ . '/../../src/earnings.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIPPED: PHP pdo_sqlite is not installed.\n");
    exit(2);
}

$tz = new DateTimeZone('Asia/Kolkata');
$NOW = new DateTimeImmutable('2026-10-20 10:00:00', $tz);
$tmpDir = sys_get_temp_dir() . '/parkkaro_tests_' . getmypid();
@mkdir($tmpDir, 0700, true);
$parentPid = getmypid();
register_shutdown_function(static function () use ($tmpDir, $parentPid): void {
    if (getmypid() !== $parentPid) {
        return; // forked test children must not delete the shared databases
    }
    foreach (glob($tmpDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmpDir);
});

$schemaFile = __DIR__ . '/schema.sql';
$schema = (string) file_get_contents($schemaFile);
[$legacySql, $migrationSql] = explode('-- ---- part 2: the additive migration ----', $schema, 2);

function connect_file(string $file): PDO
{
    $p = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 20,
    ]);
    return $p;
}

$dbCounter = 0;
/** A fresh, migrated database file. */
function fresh_db(): PDO
{
    global $tmpDir, $schema, $dbCounter;
    $dbCounter++;
    $file = "$tmpDir/db$dbCounter.sqlite";
    $p = connect_file($file);
    $p->exec($schema);
    $p->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    return $p;
}

function db_file(PDO $p): string
{
    $row = $p->query('PRAGMA database_list')->fetch(PDO::FETCH_ASSOC);
    return (string) $row['file'];
}

function mk_user(PDO $p, string $email, string $car = 'MH09AB1234', string $first = 'Asha'): int
{
    q_run($p, 'INSERT INTO users (first_name, last_name, email, car_number, password) VALUES (?, ?, ?, ?, ?)', [$first, 'Patil', $email, $car, 'x']);
    return (int) $p->lastInsertId();
}

function mk_admin(PDO $p, string $username, string $zone = 'Zone A'): void
{
    q_run($p, 'INSERT INTO admin_users (username, password, admin_zone) VALUES (?, ?, ?)', [$username, 'x', $zone]);
}

function loc_input(array $over = []): array
{
    return $over + ['name' => 'Shahu Garage', 'kind' => 'private', 'city' => 'Kolhapur', 'address' => '12 Station Road, Kolhapur',
        'description' => 'Covered parking', 'capacity' => '2', 'hourly_rate' => '30', 'lat' => '16.7050', 'lng' => '74.2433',
        'open_from' => '', 'open_until' => ''];
}

/** Create a location through the real validation + insert path. Returns its id. */
function mk_loc(PDO $p, string $owner, array $over = [], string $zone = 'Zone A'): int
{
    global $NOW;
    [$errors, $clean] = validate_location_input(loc_input($over));
    if ($errors) {
        throw new RuntimeException('test setup: ' . implode(' ', $errors));
    }
    $r = create_location($p, $owner, $zone, $clean, $NOW);
    if (!$r['ok']) {
        throw new RuntimeException('test setup: ' . $r['error']);
    }
    return $r['id'];
}

function win(string $start, string $end): array
{
    return ['start' => $start, 'end' => $end];
}

/* ===================================================================== */
T::group('Migration files: additive only and in sync');
$mysql = (string) file_get_contents(__DIR__ . '/../../database/migrations/001_parkkaro_core.mysql.sql');
$code = implode("\n", array_filter(explode("\n", $mysql), static fn(string $l): bool => !preg_match('/^\s*--/', $l)));
T::ok(preg_match('/\b(DROP|TRUNCATE|RENAME|DELETE|UPDATE)\b/i', $code) !== 1, 'executable part has no DROP / TRUNCATE / RENAME / DELETE / UPDATE');
T::ok(preg_match_all('/\bALTER TABLE `?(\w+)`?/i', $code, $m) > 0 && count(array_unique($m[1])) === 1 && $m[1][0] === 'bookings', 'the only table ALTERed is bookings');
T::ok(preg_match_all('/ADD (COLUMN|INDEX)/i', $code) === 9, 'exactly 7 columns + 2 indexes are added');
T::ok(preg_match('/CREATE TABLE(?! IF NOT EXISTS)/i', $code) !== 1, 'every CREATE TABLE uses IF NOT EXISTS');
T::ok(substr_count($code, 'information_schema') === 9, 'every ALTER is guarded by an information_schema check');
$expect = [
    'parking_locations' => ['owner_username', 'admin_zone', 'name', 'kind', 'city', 'address', 'description', 'lat', 'lng', 'capacity', 'hourly_rate', 'open_from', 'open_until', 'is_active', 'created_at', 'updated_at'],
    'payments' => ['booking_id', 'user_id', 'provider', 'provider_order_id', 'provider_payment_id', 'amount', 'currency', 'status', 'verification', 'verified_at', 'recorded_by', 'failure_reason', 'created_at', 'updated_at'],
    'payment_events' => ['provider', 'event_id', 'event_type', 'payload_sha256', 'received_at'],
    'schema_migrations' => ['version', 'applied_at'],
    'bookings' => ['user_id', 'location_id', 'start_time', 'end_time', 'status', 'hold_expires_at', 'cancelled_at'],
];
$drift = [];
foreach ($expect as $table => $cols) {
    foreach ($cols as $c) {
        if (!str_contains($mysql, "`$c`")) {
            $drift[] = "mysql:$table.$c";
        }
    }
    if ($table !== 'bookings' && !str_contains($schema, "CREATE TABLE $table")) {
        $drift[] = "sqlite:$table";
    }
}
$sqliteMig = $migrationSql;
foreach ($expect['bookings'] as $c) {
    if (!preg_match('/ALTER TABLE bookings ADD COLUMN ' . $c . '\b/', $sqliteMig)) {
        $drift[] = "sqlite:bookings.$c";
    }
}
T::same([], $drift, 'MySQL migration and SQLite twin declare the same new tables and columns');

T::group('Migration on a database that already has data');
$legacy = connect_file("$tmpDir/legacy.sqlite");
$legacy->exec($legacySql);
q_run($legacy, "INSERT INTO bookings (location_name, admin_zone, name, email, car_number, hours, cost, booking_time, `timestamp`) VALUES ('Ajara Market Parking','Zone B','Ravi','r@example.com','MH09AA0001',2,40,'2026-10-01 09:00:00','2026-10-01 09:00:00')");
q_run($legacy, "INSERT INTO bookings (location_name, admin_zone, name, email, car_number, hours, cost, booking_time, `timestamp`) VALUES ('Kolhapur Bus Stand Parking','Zone H','Sita','s@example.com','MH09AA0002',3,60,'2026-10-02 09:00:00','2026-10-02 09:00:00')");
mk_user($legacy, 'old@example.com');
$before = q_all($legacy, 'SELECT id, location_name, name, hours, cost, booking_time FROM bookings ORDER BY id');
$legacy->exec($migrationSql);
$after = q_all($legacy, 'SELECT id, location_name, name, hours, cost, booking_time FROM bookings ORDER BY id');
T::same($before, $after, 'old booking rows are unchanged by the migration');
T::same(2, (int) q_val($legacy, "SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND location_id IS NULL AND start_time IS NULL AND user_id IS NULL"), 'old rows get status confirmed, other new columns NULL');
T::same(1, (int) q_val($legacy, 'SELECT COUNT(*) FROM users'), 'users table untouched');

/* ===================================================================== */
T::group('peak_concurrency (pure)');
T::same(0, peak_concurrency([], '2026-10-20 10:00:00', '2026-10-20 12:00:00'), 'no bookings -> 0');
T::same(1, peak_concurrency([['2026-10-20 08:00:00', '2026-10-20 11:00:00']], '2026-10-20 10:00:00', '2026-10-20 12:00:00'), 'one overlapping booking -> 1');
T::same(0, peak_concurrency([['2026-10-20 08:00:00', '2026-10-20 10:00:00']], '2026-10-20 10:00:00', '2026-10-20 12:00:00'), 'ends exactly when the window starts -> no overlap');
T::same(0, peak_concurrency([['2026-10-20 12:00:00', '2026-10-20 14:00:00']], '2026-10-20 10:00:00', '2026-10-20 12:00:00'), 'starts exactly when the window ends -> no overlap');
T::same(1, peak_concurrency([['2026-10-20 08:00:00', '2026-10-20 09:00:00'], ['2026-10-20 11:00:00', '2026-10-20 12:00:00']], '2026-10-20 08:00:00', '2026-10-20 12:00:00'), 'two back-to-back-ish bookings never overlap each other -> peak 1');
T::same(2, peak_concurrency([['2026-10-20 08:00:00', '2026-10-20 11:00:00'], ['2026-10-20 10:00:00', '2026-10-20 12:00:00']], '2026-10-20 08:00:00', '2026-10-20 12:00:00'), 'two overlapping bookings -> peak 2');
T::same(1, peak_concurrency([['2026-10-20 08:00:00', '2026-10-20 10:00:00'], ['2026-10-20 10:00:00', '2026-10-20 12:00:00']], '2026-10-20 08:00:00', '2026-10-20 12:00:00'), 'one leaves at 10:00 as the next arrives -> peak 1');

T::group('billed_hours and parse_local_datetime');
$t0 = new DateTimeImmutable('2026-10-20 10:00:00', $tz);
T::same(1, billed_hours($t0, $t0->modify('+30 minutes')), '30 minutes -> 1 hour');
T::same(1, billed_hours($t0, $t0->modify('+60 minutes')), '60 minutes -> 1 hour');
T::same(2, billed_hours($t0, $t0->modify('+61 minutes')), '61 minutes -> 2 hours');
T::same(2, billed_hours($t0, $t0->modify('+90 minutes')), '90 minutes -> 2 hours');
T::same('2026-10-20 09:30:00', parse_local_datetime('2026-10-20T09:30', $tz)?->format(PK_DT), 'datetime-local format parsed');
T::same(null, parse_local_datetime('2026-02-30T09:30', $tz), 'impossible date rejected');
T::same(null, parse_local_datetime('tomorrow', $tz), 'free text rejected');
T::same(null, parse_local_datetime("2026-10-20T09:30'; DROP TABLE bookings;--", $tz), 'SQL text rejected');

T::group('validate_reservation_window');
$open24 = ['open_from' => null, 'open_until' => null];
[$e, $w] = validate_reservation_window(win('2026-10-20T12:00', '2026-10-20T14:30'), $open24, $NOW);
T::ok($e === [] && $w['hours'] === 3 && $w['start'] === '2026-10-20 12:00:00', 'a normal 2.5 hour stay is valid and billed 3 hours');
[$e] = validate_reservation_window(win('2026-10-20T14:00', '2026-10-20T12:00'), $open24, $NOW);
T::ok($e !== [], 'end before start rejected');
[$e] = validate_reservation_window(win('2026-10-20T12:00', '2026-10-20T12:00'), $open24, $NOW);
T::ok($e !== [], 'zero length rejected');
[$e] = validate_reservation_window(win('2026-10-20T12:00', '2026-10-20T12:20'), $open24, $NOW);
T::ok($e !== [], 'shorter than the 30 minute minimum rejected');
[$e] = validate_reservation_window(win('2026-10-20T12:00', '2026-10-21T12:01'), $open24, $NOW);
T::ok($e !== [], 'longer than 24 hours rejected');
[$e] = validate_reservation_window(win('2026-10-20T12:00', '2026-10-21T12:00'), $open24, $NOW);
T::ok($e === [], 'exactly 24 hours accepted');
[$e] = validate_reservation_window(win('2026-10-20T08:00', '2026-10-20T11:00'), $open24, $NOW);
T::ok($e !== [], 'start in the past rejected');
[$e] = validate_reservation_window(win('2026-10-20T09:57', '2026-10-20T11:00'), $open24, $NOW);
T::ok($e === [], 'start 3 minutes ago is inside the grace period');
[$e] = validate_reservation_window(win('2026-12-01T10:00', '2026-12-01T12:00'), $open24, $NOW);
T::ok($e !== [], 'more than 30 days ahead rejected');
[$e] = validate_reservation_window(win('', ''), $open24, $NOW);
T::ok(count($e) === 2, 'empty start and end both reported');
$hours = ['open_from' => '08:00:00', 'open_until' => '20:00:00'];
[$e] = validate_reservation_window(win('2026-10-21T08:00', '2026-10-21T20:00'), $hours, $NOW);
T::ok($e === [], 'stay exactly from opening to closing accepted');
[$e] = validate_reservation_window(win('2026-10-21T07:30', '2026-10-21T10:00'), $hours, $NOW);
T::ok($e !== [], 'starting before opening rejected');
[$e] = validate_reservation_window(win('2026-10-21T19:00', '2026-10-21T21:00'), $hours, $NOW);
T::ok($e !== [], 'ending after closing rejected');
[$e] = validate_reservation_window(win('2026-10-21T18:00', '2026-10-22T09:00'), $hours, $NOW);
T::ok($e !== [], 'overnight stay at a place with opening hours rejected');

/* ===================================================================== */
T::group('Locations: validation');
[$e, $c] = validate_location_input(loc_input());
T::ok($e === [] && $c['capacity'] === 2 && $c['hourly_rate'] === 30 && $c['lat'] === '16.705000' && $c['open_from'] === null, 'valid input accepted and normalised');
foreach ([
    'empty name' => ['name' => ''], 'bad kind' => ['kind' => 'secret'], 'zero capacity' => ['capacity' => '0'],
    'huge capacity' => ['capacity' => '501'], 'text capacity' => ['capacity' => 'many'], 'zero rate' => ['hourly_rate' => '0'],
    'negative rate' => ['hourly_rate' => '-5'], 'rate with decimals' => ['hourly_rate' => '12.5'], 'short address' => ['address' => 'ab'],
    'lat without lng' => ['lng' => ''], 'lat out of range' => ['lat' => '95'], 'lng not numeric' => ['lng' => 'east'],
    'only open_from' => ['open_from' => '08:00'], 'closing before opening' => ['open_from' => '20:00', 'open_until' => '08:00'],
    'bad time format' => ['open_from' => '8am', 'open_until' => '8pm'], 'html in name' => ['name' => '<script>x</script>'],
] as $label => $over) {
    [$e] = validate_location_input(loc_input($over));
    T::ok($e !== [], "rejected: $label");
}
[$e, $c] = validate_location_input(loc_input(['open_from' => '08:00', 'open_until' => '20:00']));
T::ok($e === [] && $c['open_from'] === '08:00:00' && $c['open_until'] === '20:00:00', 'opening hours accepted');

T::group('Locations: ownership, capacity guard, search');
$p = fresh_db();
mk_admin($p, 'admin_a', 'Zone A');
mk_admin($p, 'admin_b', 'Zone B');
$locA = mk_loc($p, 'admin_a', ['name' => 'Shahu Garage', 'kind' => 'private', 'capacity' => '2']);
$locB = mk_loc($p, 'admin_b', ['name' => 'Bus Stand Lot', 'kind' => 'public', 'city' => 'Ajara', 'lat' => '16.1170', 'lng' => '74.2110', 'capacity' => '5', 'hourly_rate' => '20'], 'Zone B');
T::same(1, count(list_owner_locations($p, 'admin_a')), 'admin A sees only their own place');
T::same(null, get_owner_location($p, 'admin_a', $locB), "admin A cannot load admin B's place");
[, $clean] = validate_location_input(loc_input(['name' => 'Hacked']));
$r = update_location($p, 'admin_a', $locB, $clean, $NOW);
T::ok($r['ok'] === false, "admin A cannot update admin B's place");
T::same('Bus Stand Lot', q_val($p, 'SELECT name FROM parking_locations WHERE id = ?', [$locB]), "...and B's data is unchanged");
T::ok(set_location_active($p, 'admin_a', $locB, false, $NOW) === false, "admin A cannot deactivate B's place");
$dup = create_location($p, 'admin_a', 'Zone A', $clean = validate_location_input(loc_input())[1], $NOW);
T::ok($dup['ok'] === false, 'same owner cannot register the same name twice');
$pSame = fresh_db();
T::ok(create_location($pSame, 'admin_a', 'Zone A', $clean, $NOW)['ok'] === true && create_location($pSame, 'admin_b', 'Zone B', $clean, $NOW)['ok'] === true, 'two different owners may each use the same place name');

$u1 = mk_user($p, 'u1@example.com', 'MH09AB0001');
$u2 = mk_user($p, 'u2@example.com', 'MH09AB0002');
$u3 = mk_user($p, 'u3@example.com', 'MH09AB0003');
$res = create_reservation($p, $u1, $locA, win('2026-10-21T10:00', '2026-10-21T12:00'), $NOW);
T::ok($res['ok'], 'setup: one reservation at admin A\'s place');
[, $lower] = validate_location_input(loc_input(['capacity' => '1']));
T::ok(update_location($p, 'admin_a', $locA, $lower, $NOW)['ok'] === true, 'capacity 2 -> 1 allowed while only 1 space is ever used at once');
create_reservation($p, $u2, $locA, win('2026-10-21T10:00', '2026-10-21T12:00'), $NOW); // refused: capacity now 1
[, $up] = validate_location_input(loc_input(['capacity' => '3']));
update_location($p, 'admin_a', $locA, $up, $NOW);
T::ok(create_reservation($p, $u2, $locA, win('2026-10-21T10:00', '2026-10-21T12:00'), $NOW)['ok'], 'capacity raised to 3, second reservation accepted');
T::ok(create_reservation($p, $u3, $locA, win('2026-10-21T11:00', '2026-10-21T13:00'), $NOW)['ok'], 'third overlapping reservation accepted (peak 3)');
[, $down] = validate_location_input(loc_input(['capacity' => '2']));
$r = update_location($p, 'admin_a', $locA, $down, $NOW);
T::ok($r['ok'] === false && str_contains($r['error'], 'Capacity cannot go below'), 'capacity cannot be lowered below the reserved peak');
T::same(3, (int) q_val($p, 'SELECT capacity FROM parking_locations WHERE id = ?', [$locA]), '...and the capacity stayed at 3');

$found = search_locations($p, [], null, $NOW);
T::same(2, count($found), 'search without filters finds both places');
T::same(['Ajara'], array_column(search_locations($p, ['city' => 'ajara'], null, $NOW), 'city'), 'city filter is case-insensitive');
T::same(['private'], array_unique(array_column(search_locations($p, ['kind' => 'private'], null, $NOW), 'kind')), 'kind filter: private only');
T::same(['public'], array_unique(array_column(search_locations($p, ['kind' => 'public'], null, $NOW), 'kind')), 'kind filter: public only');
T::same(0, count(search_locations($p, ['city' => '%'], null, $NOW)), 'a % in the search is literal, not a wildcard');
T::same(0, count(search_locations($p, ['city' => "x' OR '1'='1"], null, $NOW)), 'SQL in the search box finds nothing');
T::same(3, (int) q_val($p, 'SELECT COUNT(*) FROM users'), 'users table still has its 3 rows after the hostile searches');
T::same(2, (int) q_val($p, 'SELECT COUNT(*) FROM parking_locations'), 'parking_locations still has its 2 rows after the hostile searches');
$w = ['start' => '2026-10-21 11:00:00', 'end' => '2026-10-21 12:00:00'];
$byName = array_column(search_locations($p, [], $w, $NOW, true), 'free_spaces', 'name');
T::same(0, $byName['Shahu Garage'], 'free_spaces for a fully reserved window is 0');
T::same(5, $byName['Bus Stand Lot'], 'free_spaces for an empty place equals its capacity');
T::same(['Bus Stand Lot'], array_column(search_locations($p, [], $w, $NOW), 'name'), 'full places are omitted from results by default');
set_location_active($p, 'admin_b', $locB, false, $NOW);
T::same(['Shahu Garage'], array_column(search_locations($p, [], null, $NOW, true), 'name'), 'deactivated place is hidden from search (only the active one is left)');
T::same(null, get_public_location($p, $locB), 'deactivated place cannot be opened publicly');
T::ok(create_reservation($p, $u1, $locB, win('2026-10-25T10:00', '2026-10-25T12:00'), $NOW)['ok'] === false, 'deactivated place cannot be reserved');

/* ===================================================================== */
T::group('Reservations: creation, price, legacy columns');
$p = fresh_db();
mk_admin($p, 'owner', 'Zone K');
$loc = mk_loc($p, 'owner', ['capacity' => '1', 'hourly_rate' => '30'], 'Zone K');
$u = mk_user($p, 'ravi@example.com', 'MH09XY1111', 'Ravi');
$r = create_reservation($p, $u, $loc, win('2026-10-20T14:00', '2026-10-20T16:30'), $NOW);
T::ok($r['ok'] === true, 'reservation created');
T::same(90, $r['amount'], '2.5 hours billed as 3 hours x Rs 30 = 90 (server-side price)');
T::same('2026-10-20 10:20:00', $r['hold_expires_at'], 'hold lasts ' . PAYMENT_HOLD_MINUTES . ' minutes from now');
$b = q_one($p, 'SELECT * FROM bookings WHERE id = ?', [$r['booking_id']]);
T::same('pending_payment', $b['status'], 'starts as pending_payment (never confirmed without payment)');
T::ok($b['user_id'] == $u && $b['location_id'] == $loc && $b['start_time'] === '2026-10-20 14:00:00' && $b['end_time'] === '2026-10-20 16:30:00', 'user, location and window stored');
T::ok($b['location_name'] === 'Shahu Garage' && $b['admin_zone'] === 'Zone K' && $b['name'] === 'Ravi Patil' && $b['car_number'] === 'MH09XY1111' && (int) $b['hours'] === 3 && (int) $b['cost'] === 90, 'old NOT NULL columns filled so old admin pages still work');
T::same(0, (int) q_val($p, 'SELECT COUNT(*) FROM payments'), 'no payment row exists yet');
$r2 = create_reservation($p, $u, $loc, ['start' => '2026-10-20T14:00', 'end' => '2026-10-20T16:00', 'car_number' => 'bad car!!'], $NOW);
T::ok($r2['ok'] === false, 'invalid car number rejected');
T::ok(create_reservation($p, 99999, $loc, win('2026-10-20T14:00', '2026-10-20T16:00'), $NOW)['ok'] === false, 'unknown user rejected');
T::ok(create_reservation($p, $u, 99999, win('2026-10-20T14:00', '2026-10-20T16:00'), $NOW)['ok'] === false, 'unknown location rejected');

T::group('Reservations: capacity over time');
$p = fresh_db();
mk_admin($p, 'owner');
$loc = mk_loc($p, 'owner', ['capacity' => '1']);
$ids = [];
for ($i = 1; $i <= 6; $i++) {
    $ids[$i] = mk_user($p, "c$i@example.com", "MH09CC000$i");
}
$ok = static fn(int $u, string $s, string $e): bool => create_reservation($p, $ids[$u], $loc, win($s, $e), $NOW)['ok'];
T::ok($ok(1, '2026-10-21T08:00', '2026-10-21T09:00'), '08-09 accepted');
T::ok($ok(2, '2026-10-21T11:00', '2026-10-21T12:00'), '11-12 accepted (capacity 1, different time)');
T::ok($ok(3, '2026-10-21T09:00', '2026-10-21T11:00'), '09-11 fits exactly between them (back-to-back is not overlap)');
T::ok(!$ok(4, '2026-10-21T08:30', '2026-10-21T09:30'), '08:30-09:30 refused (overlaps 08-09 and 09-11)');
T::ok(!$ok(4, '2026-10-21T07:00', '2026-10-21T08:30'), '07:00-08:30 refused (overlaps the first 30 minutes of 08-09)');
T::ok($ok(4, '2026-10-21T07:00', '2026-10-21T08:00'), '07-08 accepted (ends as 08-09 starts)');
T::ok($ok(5, '2026-10-21T12:00', '2026-10-21T13:00'), '12-13 accepted (starts as 11-12 ends)');
T::ok(!$ok(6, '2026-10-21T06:00', '2026-10-21T14:00'), 'a long stay across all of them refused');
T::ok($ok(6, '2026-10-21T14:00', '2026-10-21T15:00'), 'later slot still free');

T::group('Reservations: capacity 2, peak not count');
$p = fresh_db();
mk_admin($p, 'owner');
$loc = mk_loc($p, 'owner', ['capacity' => '2']);
for ($i = 1; $i <= 5; $i++) {
    $ids[$i] = mk_user($p, "d$i@example.com", "MH09DD000$i");
}
$ok = static fn(int $u, string $s, string $e): bool => create_reservation($p, $ids[$u], $loc, win($s, $e), $NOW)['ok'];
T::ok($ok(1, '2026-10-21T08:00', '2026-10-21T10:00'), 'A 08-10');
T::ok($ok(2, '2026-10-21T10:00', '2026-10-21T12:00'), 'B 10-12');
T::ok($ok(3, '2026-10-21T08:00', '2026-10-21T12:00'), 'C 08-12 accepted: it overlaps A and B but never both at once (peak stays 2)');
T::ok(!$ok(4, '2026-10-21T09:00', '2026-10-21T11:00'), 'D 09-11 refused: at 09:00 A and C are both there');
T::ok($ok(4, '2026-10-21T12:00', '2026-10-21T13:00'), 'D 12-13 accepted');

T::group('Reservations: holds, expiry, cancellation, same car');
$p = fresh_db();
mk_admin($p, 'owner');
$loc = mk_loc($p, 'owner', ['capacity' => '1']);
$ua = mk_user($p, 'a@example.com', 'MH09HH0001');
$ub = mk_user($p, 'b@example.com', 'MH09HH0002');
$w1 = win('2026-10-20T15:00', '2026-10-20T17:00');
$a = create_reservation($p, $ua, $loc, $w1, $NOW);
T::ok($a['ok'], 'A holds the only space');
T::ok(create_reservation($p, $ub, $loc, $w1, $NOW->modify('+5 minutes'))['ok'] === false, 'B refused while A\'s hold is running');
$later = $NOW->modify('+21 minutes');
$bRes = create_reservation($p, $ub, $loc, $w1, $later);
T::ok($bRes['ok'], 'B accepted once A\'s unpaid hold has expired');
T::same('expired', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$a['booking_id']]), "A's reservation was marked expired");
$c = cancel_unpaid_reservation($p, $ub, $bRes['booking_id'], $later);
T::ok($c['ok'], 'user cancels own unpaid reservation');
T::ok(create_reservation($p, $ua, $loc, $w1, $later)['ok'], 'the cancelled space can be reserved again immediately');
T::ok(cancel_unpaid_reservation($p, $ua, $bRes['booking_id'], $later)['ok'] === false, "user A cannot cancel B's reservation");
T::ok(cancel_unpaid_reservation($p, $ub, $bRes['booking_id'], $later)['ok'] === false, 'an already cancelled reservation cannot be cancelled again');
$p2 = fresh_db();
mk_admin($p2, 'owner');
$loc2 = mk_loc($p2, 'owner', ['capacity' => '5']);
$uc = mk_user($p2, 'c@example.com', 'MH09JJ0001');
T::ok(create_reservation($p2, $uc, $loc2, win('2026-10-21T10:00', '2026-10-21T12:00'), $NOW)['ok'], 'first reservation for a car');
T::ok(create_reservation($p2, $uc, $loc2, win('2026-10-21T11:00', '2026-10-21T13:00'), $NOW)['ok'] === false, 'the same car cannot hold two overlapping reservations');
T::ok(create_reservation($p2, $uc, $loc2, win('2026-10-21T12:00', '2026-10-21T13:00'), $NOW)['ok'], '...but can book right after the first ends');

/* ===================================================================== */
/* Payments                                                            */
/* ===================================================================== */
$KEY_ID = 'rzp_test_KEYID';
$KEY_SECRET = 'test_key_secret';
$WH_SECRET = 'test_webhook_secret';

/** A fake Razorpay HTTP server shared by the test and the gateway object. No network is used. */
final class FakeHttp
{
    public array $orders = [];
    public array $calls = [];
    /** payment id => the record the fake gateway returns for GET /payments/{id} */
    public array $payments = [];
    public bool $down = false;
    public ?int $orderAmountOverride = null;

    public function __construct(public string $user, public string $pass)
    {
    }

    public function __invoke(string $method, string $url, ?array $json, string $user, string $pass): array
    {
        $this->calls[] = "$method $url";
        if ($user !== $this->user || $pass !== $this->pass) {
            return [401, '{"error":"auth"}'];
        }
        if ($this->down) {
            throw new PaymentGatewayException('network down');
        }
        if ($method === 'POST' && str_ends_with($url, '/orders')) {
            $this->orders[] = $json;
            return [200, json_encode(['id' => 'order_TEST' . count($this->orders), 'amount' => $this->orderAmountOverride ?? $json['amount'], 'currency' => $json['currency']])];
        }
        if ($method === 'GET' && preg_match('#/payments/([A-Za-z0-9_]+)$#', $url, $m)) {
            $rec = $this->payments[$m[1]] ?? null;
            return $rec === null ? [404, '{}'] : [200, json_encode($rec)];
        }
        return [400, '{}'];
    }
}

function fake_gateway(string $keyId, string $secret, string $whSecret, FakeHttp $http): RazorpayGateway
{
    return new RazorpayGateway($keyId, $secret, $whSecret, $http);
}

function sign_checkout(string $orderId, string $paymentId, string $secret): string
{
    return hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
}

/** A world with an owner, a place (cap 1, Rs 50/h), a user and a pending reservation 14:00-16:00 (Rs 100). */
function pay_world(): array
{
    global $NOW;
    $p = fresh_db();
    mk_admin($p, 'owner', 'Zone K');
    mk_admin($p, 'other', 'Zone Z');
    $loc = mk_loc($p, 'owner', ['capacity' => '1', 'hourly_rate' => '50'], 'Zone K');
    $u = mk_user($p, 'payer@example.com', 'MH09PP0001', 'Payer');
    $r = create_reservation($p, $u, $loc, win('2026-10-20T14:00', '2026-10-20T16:00'), $NOW);
    return [$p, $loc, $u, $r['booking_id']];
}

T::group('Online payment: starting');
$state = new FakeHttp($KEY_ID, $KEY_SECRET);
$gw = fake_gateway($KEY_ID, $KEY_SECRET, $WH_SECRET, $state);
[$p, $loc, $u, $bid] = pay_world();
$unconfigured = new RazorpayGateway('', '', '', static fn() => [500, '']);
$r = start_online_payment($p, $unconfigured, $u, $bid, $NOW);
T::ok($r['ok'] === false && $r['code'] === 'not_configured', 'gateway without keys: online payment unavailable (no fake success)');
T::same(0, (int) q_val($p, 'SELECT COUNT(*) FROM payments'), '...and no payment row is created');
$r = start_online_payment($p, $gw, $u, $bid, $NOW);
T::ok($r['ok'] === true && $r['amount_paise'] === 10000 && $r['order_id'] === 'order_TEST1' && $r['key_id'] === $KEY_ID, 'order created for Rs 100 = 10000 paise (amount from the database)');
T::same(['amount' => 10000, 'currency' => 'INR', 'receipt' => "booking_$bid"], $state->orders[0], 'the gateway was asked for exactly that amount');
$pay = q_one($p, 'SELECT * FROM payments WHERE booking_id = ?', [$bid]);
T::ok($pay['status'] === 'created' && (int) $pay['amount'] === 100 && $pay['provider_order_id'] === 'order_TEST1' && $pay['verified_at'] === null, 'payment row is only "created", not succeeded');
$r = start_online_payment($p, $gw, $u, $bid, $NOW->modify('+1 minute'));
T::ok($r['ok'] && $r['order_id'] === 'order_TEST1' && count($state->orders) === 1, 'clicking again reuses the same order');
$other = mk_user($p, 'someone@example.com', 'MH09PP0002');
T::ok(start_online_payment($p, $gw, $other, $bid, $NOW)['ok'] === false, "another user cannot pay for someone else's reservation");
T::ok(start_online_payment($p, $gw, $u, $bid, $NOW->modify('+30 minutes'))['ok'] === false, 'an expired hold cannot start a payment');
$bad = fake_gateway($KEY_ID, $KEY_SECRET, $WH_SECRET, new FakeHttp('wrong', 'wrong'));
[$p3, , $u3b, $bid3] = pay_world();
T::ok(start_online_payment($p3, $bad, $u3b, $bid3, $NOW)['ok'] === false, 'gateway rejecting the order -> no payment row, friendly error');
T::same(0, (int) q_val($p3, 'SELECT COUNT(*) FROM payments'), '...no payment row after a gateway error');
$state3 = new FakeHttp($KEY_ID, $KEY_SECRET);
$state3->orderAmountOverride = 1;
$tampered = fake_gateway($KEY_ID, $KEY_SECRET, $WH_SECRET, $state3);
T::ok(start_online_payment($p3, $tampered, $u3b, $bid3, $NOW)['ok'] === false, 'a gateway order with a different amount than we asked for is rejected');

T::group('Online payment: checkout callback');
$mkOrder = static function () use ($KEY_ID, $KEY_SECRET, $WH_SECRET, $NOW): array {
    $state = new FakeHttp($KEY_ID, $KEY_SECRET);
    $gw = fake_gateway($KEY_ID, $KEY_SECRET, $WH_SECRET, $state);
    [$p, $loc, $u, $bid] = pay_world();
    $o = start_online_payment($p, $gw, $u, $bid, $NOW);
    return [$p, $gw, $state, $u, $bid, $o['order_id'], $loc];
};
// 1. bad signature
[$p, $gw, $state, $u, $bid, $oid] = $mkOrder();
$state->payments['pay_GOOD0001'] = ['id' => 'pay_GOOD0001', 'order_id' => $oid, 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured'];
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => $oid, 'razorpay_signature' => str_repeat('a', 64)], $NOW);
T::ok($r['ok'] === false, 'wrong signature is refused');
T::same('created', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...payment stays "created"');
T::same('pending_payment', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), '...reservation stays unconfirmed');
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => $oid], $NOW);
T::ok($r['ok'] === false, 'missing signature refused');
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => "pay_x'; DROP TABLE payments;--", 'razorpay_order_id' => $oid, 'razorpay_signature' => str_repeat('a', 64)], $NOW);
T::ok($r['ok'] === false, 'malformed payment id refused before any query');
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => 'order_UNKNOWN9', 'razorpay_signature' => sign_checkout('order_UNKNOWN9', 'pay_GOOD0001', $KEY_SECRET)], $NOW);
T::ok($r['ok'] === false, 'a correctly signed response for an order we never created is refused');
// 2. signature made with the wrong secret
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_GOOD0001', 'attacker_secret')], $NOW);
T::ok($r['ok'] === false, 'signature computed with another secret refused');
// 3. valid signature but the gateway says only "authorized": not money yet
$state->payments['pay_AUTH0001'] = ['id' => 'pay_AUTH0001', 'order_id' => $oid, 'amount' => 10000, 'currency' => 'INR', 'status' => 'authorized'];
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_AUTH0001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_AUTH0001', $KEY_SECRET)], $NOW);
T::ok($r['ok'] === true && $r['status'] === 'pending', 'valid signature but only "authorized": reported as pending');
T::same('created', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...and NOT recorded as paid');
// 4. gateway unreachable
$state->down = true;
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_GOOD0001', $KEY_SECRET)], $NOW);
T::ok($r['ok'] === true && $r['status'] === 'pending', 'gateway unreachable: pending, not paid, not failed');
T::same('created', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...payment still "created"');
$state->down = false;
// 5. gateway says captured but for a different amount
$state->payments['pay_SHORT001'] = ['id' => 'pay_SHORT001', 'order_id' => $oid, 'amount' => 100, 'currency' => 'INR', 'status' => 'captured'];
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_SHORT001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_SHORT001', $KEY_SECRET)], $NOW);
T::ok($r['ok'] === false, 'captured amount (Rs 1) differs from the order amount (Rs 100): refused');
T::same('failed', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...row marked failed (amount mismatch)');
T::same('pending_payment', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), '...reservation not confirmed');
// 6. a later genuine payment on the same order is still accepted
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_GOOD0001', $KEY_SECRET)], $NOW->modify('+2 minutes'));
T::ok($r['ok'] === true && $r['status'] === 'confirmed', 'a correct captured payment is confirmed');
$pay = q_one($p, 'SELECT * FROM payments WHERE booking_id = ?', [$bid]);
T::ok($pay['status'] === 'succeeded' && $pay['verification'] === 'gateway_api' && $pay['provider_payment_id'] === 'pay_GOOD0001' && $pay['verified_at'] === '2026-10-20 10:02:00' && $pay['failure_reason'] === null, 'payment row: succeeded, verified via gateway_api, with payment id and time');
T::same('confirmed', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), 'reservation is now confirmed');
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_GOOD0001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_GOOD0001', $KEY_SECRET)], $NOW->modify('+3 minutes'));
T::ok($r['ok'] && $r['status'] === 'confirmed', 'replaying the same callback is harmless');
T::same(1, (int) q_val($p, "SELECT COUNT(*) FROM payments WHERE status = 'succeeded'"), '...still exactly one successful payment');
// 7. a second, different payment for the same (already paid) order
$state->payments['pay_DOUBLE01'] = ['id' => 'pay_DOUBLE01', 'order_id' => $oid, 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured'];
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_DOUBLE01', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_DOUBLE01', $KEY_SECRET)], $NOW->modify('+4 minutes'));
T::ok($r['ok'] === false, 'a second payment for an already paid order is not recorded');
T::same('pay_GOOD0001', q_val($p, 'SELECT provider_payment_id FROM payments WHERE booking_id = ?', [$bid]), '...the original payment id is kept');
// 8. a gateway record that belongs to a different order
[$p, $gw, $state, $u, $bid, $oid] = $mkOrder();
$state->payments['pay_OTHER001'] = ['id' => 'pay_OTHER001', 'order_id' => 'order_SOMEONEELSE', 'amount' => 10000, 'currency' => 'INR', 'status' => 'captured'];
$r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => 'pay_OTHER001', 'razorpay_order_id' => $oid, 'razorpay_signature' => sign_checkout($oid, 'pay_OTHER001', $KEY_SECRET)], $NOW);
T::ok($r['ok'] === false, 'a payment that the gateway says belongs to another order is refused');
T::same(0, (int) q_val($p, "SELECT COUNT(*) FROM payments WHERE status = 'succeeded'"), '...nothing recorded as paid');

T::group('Online payment: webhook');
$wh = static function (PDO $p, RazorpayGateway $gw, array $payload, ?string $secret = null, string $eventId = 'evt_1', ?DateTimeImmutable $at = null) use ($WH_SECRET, $NOW): array {
    $raw = json_encode($payload);
    $sig = hash_hmac('sha256', $raw, $secret ?? $WH_SECRET);
    return handle_gateway_webhook($p, $gw, $raw, $sig, $eventId, $at ?? $NOW);
};
$captured = static fn(string $oid, string $pid, int $amount = 10000, string $status = 'captured', string $event = 'payment.captured'): array => [
    'entity' => 'event', 'event' => $event,
    'payload' => ['payment' => ['entity' => ['id' => $pid, 'order_id' => $oid, 'amount' => $amount, 'currency' => 'INR', 'status' => $status]]],
];
[$p, $gw, $state, $u, $bid, $oid] = $mkOrder();
T::same([400, 'bad signature'], handle_gateway_webhook($p, $gw, json_encode($captured($oid, 'pay_W0000001')), str_repeat('0', 64), 'evt_x', $NOW), 'webhook with a wrong signature: 400');
T::same([400, 'bad signature'], handle_gateway_webhook($p, $gw, json_encode($captured($oid, 'pay_W0000001')), '', 'evt_x', $NOW), 'webhook without a signature: 400');
T::same([400, 'bad signature'], $wh($p, $gw, $captured($oid, 'pay_W0000001'), 'wrong_secret'), 'webhook signed with another secret: 400');
$noSecret = new RazorpayGateway($KEY_ID, $KEY_SECRET, '', static fn() => [500, '']);
T::same([503, 'webhook not configured'], handle_gateway_webhook($p, $noSecret, '{}', 'abc', 'e', $NOW), 'webhook secret not configured: 503 (never accepts unsigned events)');
T::same(0, (int) q_val($p, 'SELECT COUNT(*) FROM payment_events'), 'rejected webhooks leave no event rows');
T::same(0, (int) q_val($p, "SELECT COUNT(*) FROM payments WHERE status = 'succeeded'"), '...and no money recorded');
T::same([200, 'rejected: amount_mismatch'], $wh($p, $gw, $captured($oid, 'pay_W0000002', 500), null, 'evt_2'), 'signed webhook with the wrong amount is rejected');
T::same('failed', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...payment row marked failed');
T::same([200, 'not captured, ignored'], $wh($p, $gw, $captured($oid, 'pay_W0000003', 10000, 'authorized'), null, 'evt_3'), 'payment.captured with status authorized is ignored');
T::same([200, 'rejected: unknown_order'], $wh($p, $gw, $captured('order_NOTOURS01', 'pay_W0000004'), null, 'evt_4'), 'event for an unknown order: acknowledged, nothing recorded');
T::same([200, 'recorded'], $wh($p, $gw, $captured($oid, 'pay_W0000005'), null, 'evt_5'), 'signed, captured, right amount: recorded');
$pay = q_one($p, 'SELECT * FROM payments WHERE booking_id = ?', [$bid]);
T::ok($pay['status'] === 'succeeded' && $pay['verification'] === 'gateway_webhook' && $pay['provider_payment_id'] === 'pay_W0000005', 'row: succeeded via gateway_webhook');
T::same('confirmed', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), 'reservation confirmed by the webhook');
T::same([200, 'duplicate event ignored'], $wh($p, $gw, $captured($oid, 'pay_W0000005'), null, 'evt_5'), 'the same event id delivered again is ignored');
T::same([200, 'recorded'], $wh($p, $gw, $captured($oid, 'pay_W0000005', 10000, 'captured', 'order.paid'), null, 'evt_6'), 'order.paid for the same payment: idempotent');
T::same(1, (int) q_val($p, "SELECT COUNT(*) FROM payments WHERE status = 'succeeded'"), '...still exactly one successful payment');
T::same([200, 'failure noted'], $wh($p, $gw, ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_W0000009', 'order_id' => $oid, 'error_description' => 'late failure']]]], null, 'evt_7'), 'a late payment.failed event');
T::same('succeeded', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...never downgrades a succeeded payment');
T::same([400, 'bad payload'], handle_gateway_webhook($p, $gw, 'not json', hash_hmac('sha256', 'not json', $WH_SECRET), 'e8', $NOW), 'signed but not JSON: 400');
T::same(['evt_2', 'evt_3', 'evt_4', 'evt_5', 'evt_6', 'evt_7'], array_column(q_all($p, 'SELECT event_id FROM payment_events ORDER BY id'), 'event_id'), 'every accepted (signed) event is logged exactly once; the duplicate and the rejected-signature calls are not');
// failed attempt then success on the same order
[$p, $gw, $state, $u, $bid, $oid] = $mkOrder();
$wh($p, $gw, ['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_F0000001', 'order_id' => $oid, 'error_description' => 'card declined']]]], null, 'evt_f1');
T::same('failed', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), 'payment.failed marks the attempt failed');
T::same([200, 'recorded'], $wh($p, $gw, $captured($oid, 'pay_F0000002'), null, 'evt_f2'), 'a retry on the same order that succeeds is accepted');
T::same('confirmed', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), '...and confirms the reservation');

T::group('Late payments: hold expired before the money arrived');
[$p, $gw, $state, $u, $bid, $oid, $loc] = $mkOrder();
$late = $NOW->modify('+45 minutes');
$res = $wh($p, $gw, $captured($oid, 'pay_L0000001'), null, 'evt_l1', $late);
T::same([200, 'recorded'], $res, 'webhook after the hold expired, space still free');
T::same('confirmed', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), '...reservation confirmed (space was still free)');
[$p, $gw, $state, $u, $bid, $oid, $loc] = $mkOrder();
$late = $NOW->modify('+45 minutes');
$other = mk_user($p, 'sniper@example.com', 'MH09SN0001');
T::ok(create_reservation($p, $other, $loc, win('2026-10-20T14:00', '2026-10-20T16:00'), $late)['ok'], 'someone else took the space after the hold lapsed');
T::same([200, 'recorded'], $wh($p, $gw, $captured($oid, 'pay_L0000002'), null, 'evt_l2', $late), 'the late payment is still recorded as money received');
T::same('succeeded', q_val($p, 'SELECT status FROM payments WHERE booking_id = ?', [$bid]), '...payment row is succeeded (the money is real)');
T::ok(q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]) !== 'confirmed', '...but the reservation is NOT confirmed (no space)');
$exp = earnings_exposure($p, 'owner', $late);
T::same(1, count($exp['review']), '...and it is listed for review / refund');
[$from, $to] = earnings_range('2026-10-01', '2026-10-31', $tz);
T::same(0, earnings_summary($p, 'owner', $from, $to)['total'], '...and it is NOT counted as earnings');

T::group('Cash payments (recorded by the owning admin)');
[$p, $loc, $u, $bid] = pay_world();
T::ok(record_cash_payment($p, 'other', $bid, $NOW)['ok'] === false, "another admin cannot record cash for this owner's reservation");
T::same(0, (int) q_val($p, 'SELECT COUNT(*) FROM payments'), '...nothing was written');
T::ok(record_cash_payment($p, 'owner', 99999, $NOW)['ok'] === false, 'unknown booking refused');
$r = record_cash_payment($p, 'owner', $bid, $NOW->modify('+3 minutes'));
T::ok($r['ok'] === true, 'owner records cash');
$pay = q_one($p, 'SELECT * FROM payments WHERE booking_id = ?', [$bid]);
T::ok($pay['provider'] === 'cash' && $pay['status'] === 'succeeded' && $pay['verification'] === 'admin_cash' && $pay['recorded_by'] === 'owner' && (int) $pay['amount'] === 100 && $pay['provider_payment_id'] === null, 'row labelled cash / admin_cash / by owner, amount from the booking');
T::same('confirmed', q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]), 'reservation confirmed');
T::ok(record_cash_payment($p, 'owner', $bid, $NOW)['ok'] === false, 'recording cash twice is refused');
T::same(1, (int) q_val($p, 'SELECT COUNT(*) FROM payments'), '...still one payment row');
[$p, $loc, $u, $bid] = pay_world();
$late = $NOW->modify('+45 minutes');
$other = mk_user($p, 'sniper2@example.com', 'MH09SN0002');
create_reservation($p, $other, $loc, win('2026-10-20T14:00', '2026-10-20T16:00'), $late);
$r = record_cash_payment($p, 'owner', $bid, $late);
T::ok($r['ok'] === false && str_contains($r['error'], 'refund'), 'lapsed hold + space taken: cash is not recorded');
T::same(0, (int) q_val($p, 'SELECT COUNT(*) FROM payments'), '...and the rolled-back transaction left no payment row');

T::group('Owner-received UPI, amount guard and expired holds (record_cash_payment)');
[$p, $loc, $u, $bid] = pay_world();
$r = record_cash_payment($p, 'owner', $bid, $NOW, 'upi', 1);
T::ok($r['ok'] === false && str_contains($r['error'], 'does not match') && (int) q_val($p, 'SELECT COUNT(*) FROM payments') === 0, 'a wrong expected amount is refused and nothing is written');
T::ok(record_cash_payment($p, 'owner', $bid, $NOW, 'bitcoin')['ok'] === false, 'an unknown method is refused');
$r = record_cash_payment($p, 'owner', $bid, $NOW->modify('+2 minutes'), 'upi', 100);
T::ok($r['ok'] === true, 'owner confirms UPI received (amount guard matches the stored cost)');
$pay = q_one($p, 'SELECT * FROM payments WHERE booking_id = ?', [$bid]);
T::ok($pay['provider'] === 'upi_owner' && $pay['verification'] === 'admin_upi' && $pay['recorded_by'] === 'owner' && (int) $pay['amount'] === 100, 'row labelled upi_owner / admin_upi, by the owner, stored amount');
T::same('UPI received by owner', payment_method_label('upi_owner'), 'label says "UPI received by owner", never "online"');
T::ok(record_cash_payment($p, 'owner', $bid, $NOW, 'cash')['ok'] === false && (int) q_val($p, 'SELECT COUNT(*) FROM payments') === 1, 'a second confirmation (even with another method) is refused');
[$p, $loc, $u, $bid] = pay_world();
$r = record_cash_payment($p, 'owner', $bid, $NOW->modify('+45 minutes'), 'cash');
T::ok($r['ok'] === false && str_contains($r['error'], 'hold on this reservation has ended') && (int) q_val($p, 'SELECT COUNT(*) FROM payments') === 0 && q_val($p, 'SELECT status FROM bookings WHERE id = ?', [$bid]) !== 'confirmed', 'a lapsed hold is not confirmed even if the space is free; the owner is told why');

/* ===================================================================== */
T::group('Earnings: only verified money, only my places');
$p = fresh_db();
mk_admin($p, 'owner', 'Zone K');
mk_admin($p, 'rival', 'Zone R');
$l1 = mk_loc($p, 'owner', ['name' => 'Alpha Lot', 'capacity' => '10', 'hourly_rate' => '40'], 'Zone K');
$l2 = mk_loc($p, 'owner', ['name' => 'Beta Lot', 'capacity' => '10', 'hourly_rate' => '25'], 'Zone K');
$lr = mk_loc($p, 'rival', ['name' => 'Rival Lot', 'capacity' => '10', 'hourly_rate' => '100'], 'Zone R');
$us = [];
for ($i = 1; $i <= 8; $i++) {
    $us[$i] = mk_user($p, "e$i@example.com", "MH09EE000$i");
}
$state = new FakeHttp($KEY_ID, $KEY_SECRET);
$gw = fake_gateway($KEY_ID, $KEY_SECRET, $WH_SECRET, $state);
$reserve = static fn(int $u, int $loc, string $s, string $e): int => create_reservation($p, $us[$u], $loc, win($s, $e), $NOW)['booking_id'];
$payOnline = static function (int $bid, string $pid, DateTimeImmutable $at) use ($p, $gw, $KEY_SECRET, $state): void {
    $uid = (int) q_val($p, 'SELECT user_id FROM bookings WHERE id = ?', [$bid]);
    $o = start_online_payment($p, $gw, $uid, $bid, $at);
    $state->payments[$pid] = ['id' => $pid, 'order_id' => $o['order_id'], 'amount' => $o['amount_paise'], 'currency' => 'INR', 'status' => 'captured'];
    $r = complete_checkout_callback($p, $gw, ['razorpay_payment_id' => $pid, 'razorpay_order_id' => $o['order_id'], 'razorpay_signature' => sign_checkout($o['order_id'], $pid, $KEY_SECRET)], $at);
    if ($r['status'] !== 'confirmed') {
        throw new RuntimeException('test setup: payment not confirmed ' . json_encode($r));
    }
};
$b1 = $reserve(1, $l1, '2026-10-20T11:00', '2026-10-20T13:00');       // Alpha 2h x 40 = 80, paid online on 20 Oct
$b2 = $reserve(2, $l1, '2026-10-20T11:00', '2026-10-20T12:00');       // Alpha 1h x 40 = 40, paid cash on 20 Oct
$b4 = $reserve(4, $l2, '2026-10-20T15:00', '2026-10-20T16:00');       // Beta unpaid -> its hold will lapse
$b5 = $reserve(5, $lr, '2026-10-20T11:00', '2026-10-20T13:00');       // rival 2h x 100 = 200 paid online
$b6 = $reserve(6, $l1, '2026-10-20T20:00', '2026-10-20T21:00');       // Alpha unpaid -> its hold will lapse
$payOnline($b1, 'pay_E0000001', $NOW);
record_cash_payment($p, 'owner', $b2, $NOW->modify('+1 minute'));
$payOnline($b5, 'pay_E0000005', $NOW);
// the next day (21 Oct): a new reservation is made and paid, and one more is left unpaid with its hold still running
$pastNow = $NOW->modify('+1 day');
$b3 = create_reservation($p, $us[3], $l2, win('2026-10-22T15:00', '2026-10-22T18:00'), $pastNow)['booking_id']; // Beta 3h x 25 = 75
$payOnline($b3, 'pay_E0000003', $pastNow);
$b7 = create_reservation($p, $us[7], $l2, win('2026-10-23T15:00', '2026-10-23T17:00'), $pastNow);   // Beta 2h x 25 = 50, still unpaid
[$from, $to] = earnings_range('2026-10-01', '2026-10-31', $tz);
$s = earnings_summary($p, 'owner', $from, $to);
T::same(80 + 40 + 75, $s['total'], 'total = 80 online + 40 cash + 75 online = 195 (rival\'s 200 and unpaid ones excluded)');
T::same(3, $s['count'], 'three paid reservations counted');
T::same([155, 2, 40, 1], [$s['online_total'], $s['online_count'], $s['cash_total'], $s['cash_count']], 'online and cash reported separately');
T::same(['Alpha Lot' => 120, 'Beta Lot' => 75], array_column($s['by_location'], 'total', 'name'), 'per-location totals');
T::same(['2026-10-20' => 120, '2026-10-21' => 75], array_column($s['by_day'], 'total', 'day'), 'per-day totals by verification date');
[$f2, $t2] = earnings_range('2026-10-21', '2026-10-21', $tz);
T::same(75, earnings_summary($p, 'owner', $f2, $t2)['total'], 'single-day range works');
[$f3, $t3] = earnings_range('2026-11-01', '2026-11-30', $tz);
T::same(0, earnings_summary($p, 'owner', $f3, $t3)['total'], 'a range with no payments is 0');
T::same(200, earnings_summary($p, 'rival', $from, $to)['total'], 'the rival sees only their own 200');
T::same(0, earnings_summary($p, 'nobody', $from, $to)['total'], 'an unknown owner sees nothing');
$ex = earnings_exposure($p, 'owner', $pastNow);
T::same([1, 50], [$ex['awaiting_count'], $ex['awaiting_total']], 'awaiting payment: only the reservation whose hold is still running (Rs 50), not earnings');
T::same([], $ex['review'], 'nothing needs review in this scenario');
T::same(195, earnings_this_month($p, 'owner', $NOW), 'earnings_this_month = October total');
T::same(null, earnings_range('2026-10-31', '2026-10-01', $tz), 'reversed range rejected');
T::same(null, earnings_range('2026-13-01', '2026-13-02', $tz), 'invalid date rejected');
T::same(null, earnings_range('2020-01-01', '2026-10-01', $tz), 'ranges longer than a year rejected');
// A payment row without a confirmed booking must not count
q_run($p, "UPDATE bookings SET status = 'cancelled' WHERE id = ?", [$b3]);
T::same(120, earnings_summary($p, 'owner', $from, $to)['total'], 'a payment whose booking is not confirmed is not counted as earned');
q_run($p, "UPDATE payments SET status = 'refunded' WHERE booking_id = ?", [$b1]);
T::same(40, earnings_summary($p, 'owner', $from, $to)['total'], 'a refunded payment is not counted');

T::group('Dashboards: what a user sees');
$mine = list_user_reservations($p, $us[1], $NOW);
T::same(1, count($mine), 'user 1 sees exactly their own reservation');
T::same(0, (int) $mine[0]['paid'], 'user 1 sees Rs 0 paid: their Rs 80 payment was refunded above, so it no longer counts');
$u3 = list_user_reservations($p, $us[3], $pastNow);
T::same([75, 'cancelled'], [(int) $u3[0]['paid'], $u3[0]['status']], 'user 3 sees the Rs 75 they paid, even though the reservation was cancelled above (money received is shown honestly)');
$all = list_user_reservations($p, $us[2], $NOW);
T::same(40, (int) $all[0]['paid'], 'user 2 (cash) sees Rs 40 paid');
$ownerRes = list_owner_reservations($p, 'owner', null, $NOW);
T::ok(!in_array('Rival Lot', array_column($ownerRes, 'location_name'), true), "owner's reservation list never contains the rival's place");
$pendingOnly = list_owner_reservations($p, 'owner', 'pending_payment', $pastNow);
T::ok($pendingOnly !== [] && count(array_unique(array_column($pendingOnly, 'status'))) === 1, 'status filter works');

/* ===================================================================== */
T::group('Concurrency: 8 processes race for the last 3 spaces (SQLite locking)');
if (!function_exists('pcntl_fork')) {
    echo "  SKIP pcntl extension not available\n";
} else {
    $p = fresh_db();
    mk_admin($p, 'owner');
    $loc = mk_loc($p, 'owner', ['capacity' => '3', 'hourly_rate' => '10']);
    $uids = [];
    for ($i = 1; $i <= 8; $i++) {
        $uids[$i] = mk_user($p, "r$i@example.com", "MH09RR000$i");
    }
    $file = db_file($p);
    unset($p);
    $pids = [];
    $ready = "$tmpDir/go";
    for ($i = 1; $i <= 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            // child: own connection, wait for the starting signal, then try to reserve the same window
            $c = connect_file($file);
            while (!file_exists($ready)) {
                usleep(1000);
            }
            try {
                $r = create_reservation($c, $uids[$i], $loc, win('2026-10-21T10:00', '2026-10-21T12:00'), $NOW);
                exit($r['ok'] ? 10 : 11);
            } catch (Throwable $ex) {
                fwrite(STDERR, 'child error: ' . $ex->getMessage() . "\n");
                exit(12);
            }
        }
        $pids[] = $pid;
    }
    usleep(200000);
    touch($ready);
    $codes = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    $accepted = count(array_filter($codes, fn($c) => $c === 10));
    $refused = count(array_filter($codes, fn($c) => $c === 11));
    $errors = count(array_filter($codes, fn($c) => $c === 12));
    T::same([3, 5, 0], [$accepted, $refused, $errors], "exactly 3 accepted, 5 refused, 0 errors (got $accepted / $refused / $errors)");
    $check = connect_file($file);
    T::same(3, (int) q_val($check, "SELECT COUNT(*) FROM bookings WHERE location_id = ? AND status = 'pending_payment'", [$loc]), 'the database holds exactly 3 reservations');
}

T::group('Hostile input is stored literally, never executed');
$p = fresh_db();
mk_admin($p, 'owner');
$evil = "Robert'); DROP TABLE bookings;-- <b>x</b>";
[$e, $c] = validate_location_input(loc_input(['name' => 'Garage O\'Neil & Sons', 'description' => $evil]));
T::ok($e === [], 'apostrophes and ampersands in a name are allowed');
$id = create_location($p, 'owner', 'Zone A', $c, $NOW)['id'];
T::same($evil, q_val($p, 'SELECT description FROM parking_locations WHERE id = ?', [$id]), 'description with SQL text stored exactly as typed');
T::same(1, (int) q_val($p, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'bookings'"), 'the bookings table still exists');

T::finish();
