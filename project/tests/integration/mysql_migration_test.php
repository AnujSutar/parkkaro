<?php
/**
 * REAL MySQL/MariaDB test of migrations 001 and 002. NOT RUN in the development sandbox (no MySQL server there).
 * Run it on your XAMPP machine against a THROWAWAY database, never your real one:
 *
 *   mysql -u root -e "CREATE DATABASE parkaro_migtest"
 *   set DB_NAME=parkaro_migtest   (Windows cmd)   |   DB_NAME=parkaro_migtest php ...  (Linux/mac)
 *   php tests/integration/mysql_migration_test.php
 *   mysql -u root -e "DROP DATABASE parkaro_migtest"
 *
 * It refuses to run unless the database name contains "test".
 * It checks: legacy rows survive and become 'confirmed'; running the migration twice changes nothing;
 * the new tables and columns exist; the unique keys reject duplicate payment ids.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit("CLI only.\n"); }
require_once __DIR__ . '/../TestCase.php';
require_once __DIR__ . '/../../src/bootstrap.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = db();
$name = (string) $db->query('SELECT DATABASE()')->fetch_row()[0];
if (stripos($name, 'test') === false) {
    exit("Refusing to run on database '$name': the name must contain 'test'. Use a throwaway database.\n");
}

function run_file(mysqli $db, string $file): void
{
    $db->multi_query((string) file_get_contents($file));
    do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
    if ($db->errno) { throw new RuntimeException($db->error); }
}
$count = static fn(string $sql): int => (int) $db->query($sql)->fetch_row()[0];

T::group('legacy data survives');
foreach (['payment_events', 'payments', 'parking_locations', 'schema_migrations', 'bookings', 'users', 'admin_users', 'slots', 'slot_status_history', 'tickets'] as $t) {
    $db->query("DROP TABLE IF EXISTS `$t`");   // throwaway DB only (guarded above)
}
run_file($db, __DIR__ . '/../../database/schema.sql');
$db->query("INSERT INTO bookings (location_name, admin_zone, name, email, car_number, hours, cost, booking_time, `timestamp`) VALUES
  ('Old Lot', 'Zone K', 'Old One', 'o1@example.com', 'MH09ZZ0001', 2, 40, '2026-01-05 10:00:00', '2026-01-05 09:00:00'),
  ('Old Lot', 'Zone K', 'Old Two', 'o2@example.com', 'MH09ZZ0002', 1, 20, '2026-01-06 11:00:00', '2026-01-06 10:00:00')");
$before = $db->query('SELECT id, location_name, name, email, car_number, hours, cost, booking_time FROM bookings ORDER BY id')->fetch_all(MYSQLI_ASSOC);
T::same(2, count($before), 'setup: 2 legacy bookings');

$mig = __DIR__ . '/../../database/migrations/001_parkkaro_core.mysql.sql';
run_file($db, $mig);
$after = $db->query('SELECT id, location_name, name, email, car_number, hours, cost, booking_time FROM bookings ORDER BY id')->fetch_all(MYSQLI_ASSOC);
T::same($before, $after, 'every legacy column of every legacy row is identical after the migration');
T::same(2, $count("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed' AND location_id IS NULL AND start_time IS NULL AND user_id IS NULL"), 'legacy rows became status=confirmed, new columns NULL');

T::group('new objects exist');
foreach (['schema_migrations', 'parking_locations', 'payments', 'payment_events'] as $t) {
    T::same(1, $count("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'"), "table $t");
}
foreach (['user_id', 'location_id', 'start_time', 'end_time', 'status', 'hold_expires_at', 'cancelled_at'] as $c) {
    T::same(1, $count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = '$c'"), "bookings.$c");
}
T::same(1, $count("SELECT COUNT(*) FROM schema_migrations WHERE version = '001_parkkaro_core'"), 'migration recorded');

T::group('idempotent');
run_file($db, $mig);
run_file($db, $mig);
T::same(1, $count('SELECT COUNT(*) FROM schema_migrations'), 'three runs: still one schema_migrations row');
T::same(2, $count('SELECT COUNT(*) FROM bookings'), 'three runs: still 2 bookings');
T::same(1, $count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND INDEX_NAME = 'idx_bk_user'"), 'index not duplicated');

T::group('unique keys');
$now = date('Y-m-d H:i:s');
$ins = static fn(string $order, string $pay) => $db->query("INSERT INTO payments (booking_id, provider, provider_order_id, provider_payment_id, amount, status, created_at, updated_at) VALUES (1, 'razorpay', '$order', " . ($pay === '' ? 'NULL' : "'$pay'") . ", 40, 'created', '$now', '$now')");
T::ok($ins('order_A', 'pay_A') === true, 'first payment row inserts');
T::ok($ins('order_A', '') === false && $db->errno === 1062, 'duplicate provider order id is rejected (1062)');
T::ok($ins('order_B', 'pay_A') === false && $db->errno === 1062, 'duplicate provider payment id is rejected (1062)');
T::ok($ins('order_C', '') === true && $ins('order_D', '') === true, 'several rows without a payment id are allowed (NULLs)');

T::group('locking syntax works on this server');
$db->begin_transaction();
$db->query("INSERT INTO parking_locations (owner_username, name, city, hourly_rate, created_at, updated_at) VALUES ('o', 'L', 'C', 10, '$now', '$now')");
$id = $db->insert_id;
T::same(1, (int) $db->query("SELECT COUNT(*) FROM parking_locations WHERE id = $id FOR UPDATE")->fetch_row()[0], 'SELECT ... FOR UPDATE on parking_locations');
$db->rollback();

T::group('migration 002 (payment methods + notifications)');
$m2 = __DIR__ . '/../../database/migrations/002_notifications_payment_modes.mysql.sql';
$beforeLoc = $count('SELECT COUNT(*) FROM parking_locations');
$beforeBk = $count('SELECT COUNT(*) FROM bookings');
$beforePay = $count('SELECT COUNT(*) FROM payments');
run_file($db, $m2);
run_file($db, $m2);
run_file($db, $m2);
$col = static fn(string $t, string $c): int => $count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'");
T::same([1, 1, 1, 1], [$col('parking_locations', 'accepts_online'), $col('parking_locations', 'accepts_cash'), $col('bookings', 'contact_phone'), $col('bookings', 'notify_via')], 'new columns exist once after three runs');
T::same([$beforeLoc, $beforeBk, $beforePay], [$count('SELECT COUNT(*) FROM parking_locations'), $count('SELECT COUNT(*) FROM bookings'), $count('SELECT COUNT(*) FROM payments')], 'no rows lost or added');
T::same(1, $count("SELECT COUNT(*) FROM schema_migrations WHERE version = '002_notifications_payment_modes'"), 'recorded once');
T::same(0, $count('SELECT COUNT(*) FROM parking_locations WHERE accepts_online <> 1 OR accepts_cash <> 1'), 'existing places keep accepting both ways');
$db->query("INSERT INTO notifications (booking_id, channel, status, created_at, updated_at) VALUES (1, 'email', 'sent', '$now', '$now')");
T::ok($db->query("INSERT INTO notifications (booking_id, channel, status, created_at, updated_at) VALUES (1, 'email', 'sending', '$now', '$now')") === false && $db->errno === 1062, 'a second row for the same booking + channel is rejected (1062): no duplicate messages');
T::finish();
