<?php
/**
 * Database integration tests (booking capacity, concurrency, slot history, users).
 *
 * NOT part of the quick checks: it needs a real MySQL/MariaDB server and an
 * ISOLATED, THROW-AWAY database whose name ends in "_test". It refuses to run
 * against anything else, because it empties the tables it uses.
 *
 *   1. In phpMyAdmin create an empty database, e.g.  parkaro_test
 *   2. Run (PowerShell):
 *        $env:TEST_DB_NAME="parkaro_test"; php tests/integration/db_test.php
 *      Optional: TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS (defaults: localhost, root, empty)
 *
 * Requires the pcntl extension for the concurrency test (Linux/macOS/WSL);
 * on plain Windows PHP that single test is skipped and says so.
 */
declare(strict_types=1);

$name = getenv('TEST_DB_NAME') ?: '';
if ($name === '' || !preg_match('/_test$/', $name)) {
    fwrite(STDERR, "SKIPPED: set TEST_DB_NAME to a throw-away database whose name ends in _test.\n");
    exit(2);
}
$cfg = ['host' => getenv('TEST_DB_HOST') ?: 'localhost', 'user' => getenv('TEST_DB_USER') ?: 'root', 'pass' => getenv('TEST_DB_PASS') ?: ''];
putenv('DB_HOST=' . $cfg['host']); putenv('DB_USER=' . $cfg['user']); putenv('DB_PASS=' . $cfg['pass']); putenv('DB_NAME=' . $name);
putenv('APP_KEY=integration-test-key');

require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/booking.php';
require __DIR__ . '/../../src/users.php';
require __DIR__ . '/../../src/slots.php';

function connect(): mysqli
{
    global $cfg, $name;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $c = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $name);
    $c->set_charset('utf8mb4');
    return $c;
}

$db = connect();
$db->multi_query(file_get_contents(__DIR__ . '/../../database/schema.sql'));
while ($db->more_results() && $db->next_result()) { /* drain */ }
foreach (['bookings', 'slots', 'slot_status_history', 'users'] as $t) {
    $db->query("DELETE FROM `$t`");
}

function booking(string $loc, int $hours = 2, string $car = 'MH09AB1234'): array
{
    return ['name' => 'Test User', 'email' => 't@example.com', 'car_number' => $car, 'hours' => $hours,
            'location' => $loc, 'admin_zone' => 'Zone T', 'lat' => '16.7', 'lng' => '74.2', 'cost' => 20 * $hours];
}

T::group('Capacity');
for ($i = 1; $i <= 3; $i++) {
    $r = create_booking($db, booking('Test Lot A', 2, "MH09AB000$i"), 3);
    T::ok($r['ok'] === true && $r['id'] > 0, "booking $i of 3 accepted");
}
$r = create_booking($db, booking('Test Lot A'), 3);
T::ok($r['ok'] === false && str_contains($r['error'], 'no slots'), 'the 4th booking is refused');
T::ok(create_booking($db, booking('Test Lot B'), 3)['ok'], 'another location is not affected');

T::group('Bookings expire (the old code counted every booking ever made)');
$db->query("INSERT INTO bookings (location_name, admin_zone, name, email, car_number, hours, cost, booking_time, `timestamp`)
            VALUES ('Test Lot C','Zone T','Old','o@example.com','MH09OLD0001',2,40, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 5 HOUR)");
T::same(0, count_active_bookings($db, 'Test Lot C'), 'a 2-hour booking made 5 hours ago is no longer active');
T::ok(create_booking($db, booking('Test Lot C'), 1)['ok'], 'so a capacity-1 lot can be booked again');
T::ok(!create_booking($db, booking('Test Lot C'), 1)['ok'], 'and is then full');

T::group('Saved record');
$id = create_booking($db, booking('Test Lot D', 5, 'MH09AB9999'), 3)['id'];
$row = find_booking($db, $id);
T::same('MH09AB9999', $row['car_number'], 'find_booking returns the stored car number');
T::same(100, (int) $row['cost'], 'stored cost = server computed cost');
T::same(null, find_booking($db, 999999), 'unknown id returns null');

T::group('Concurrency: 8 simultaneous requests for the last 3 places');
if (!function_exists('pcntl_fork')) {
    echo "  SKIP pcntl extension not available on this PHP\n";
} else {
    $pids = [];
    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $c = connect();
            $r = create_booking($c, booking('Test Lot E', 2, 'MH09RACE00' . $i), 3, 10);
            exit($r['ok'] ? 0 : 1);
        }
        $pids[] = $pid;
    }
    $ok = 0;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $ok += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }
    $db2 = connect();
    T::same(3, $ok, 'exactly 3 of 8 racing requests succeed');
    T::same(3, count_active_bookings($db2, 'Test Lot E'), 'exactly 3 rows were stored');
}

T::group('Lock contention is reported, not ignored');
$holder = connect();
$lock = 'parkaro_' . md5('Test Lot F');
$holder->query("SELECT GET_LOCK('$lock', 1)");
$r = create_booking($db, booking('Test Lot F'), 3, 1);
T::ok($r['ok'] === false && str_contains($r['error'], 'busy'), 'when the location lock is held, the request is told to retry');
$holder->query("SELECT RELEASE_LOCK('$lock')");
T::ok(create_booking($db, booking('Test Lot F'), 3, 1)['ok'], 'after the lock is released booking works');

T::group('Slot history records the OLD status');
$db->query("INSERT INTO slots (location, slot_number, status) VALUES ('Kolhapur','K1','available'),('Ajara','A1','available')");
$kId = (int) $db->query("SELECT id FROM slots WHERE slot_number='K1'")->fetch_row()[0];
$aId = (int) $db->query("SELECT id FROM slots WHERE slot_number='A1'")->fetch_row()[0];
T::same(1, update_slot_statuses($db, 'Kolhapur', [$kId], 'booked'), 'one slot changed');
$h = $db->query("SELECT old_status, new_status FROM slot_status_history WHERE slot_id = $kId")->fetch_assoc();
T::same(['old_status' => 'available', 'new_status' => 'booked'], $h, 'history shows available -> booked (old code logged booked -> booked)');
T::same(0, update_slot_statuses($db, 'Kolhapur', [$aId], 'booked'), 'a slot of another location cannot be changed from the Kolhapur page');
T::same(0, update_slot_statuses($db, 'Kolhapur', [$kId], 'hacked'), 'an unknown status is refused');

T::group('Users');
$u = ['first_name' => 'Test', 'last_name' => 'User', 'email' => 'unit@example.com', 'car_number' => 'MH09AB1234', 'password' => 'Secret!1'];
T::ok(register_user($db, $u), 'registration succeeds');
T::ok(!register_user($db, $u), 'the same email cannot register twice');
$stored = $db->query("SELECT password FROM users WHERE email='unit@example.com'")->fetch_row()[0];
T::ok($stored !== 'Secret!1' && password_verify('Secret!1', $stored), 'password is stored hashed');
T::ok(authenticate_user($db, 'unit@example.com', 'Secret!1') !== null, 'correct password logs in');
T::ok(authenticate_user($db, 'unit@example.com', 'wrong') === null, 'wrong password rejected');
T::ok(authenticate_user($db, "x' OR '1'='1", 'x') === null, 'SQL injection string finds no user');

T::finish();
