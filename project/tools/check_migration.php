<?php
/**
 * Read-only preflight / verify for migration 001. Changes NOTHING.
 *   php tools/check_migration.php
 * Shows which tables and columns exist, row counts of existing tables, and whether
 * the migration has been recorded. Run it before (to see what will be added) and after (to verify).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../src/bootstrap.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = db();

$one = static function (string $sql, array $p = []) use ($db) {
    $st = $db->prepare($sql);
    if ($p) { $st->bind_param(str_repeat('s', count($p)), ...$p); }
    $st->execute();
    $r = $st->get_result()->fetch_row();
    return $r[0] ?? null;
};
$tableExists = static fn(string $t): bool => (int) $one('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]) > 0;
$colExists = static fn(string $t, string $c): bool => (int) $one('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]) > 0;

echo "Database: " . $one('SELECT DATABASE()') . "   Server: " . $db->server_info . "\n\n";
$problems = 0;

echo "Existing tables (never modified except bookings, which only gains columns):\n";
foreach (['users', 'admin_users', 'bookings', 'slots', 'tickets'] as $t) {
    if ($tableExists($t)) {
        echo sprintf("  %-18s present, %d rows, engine %s\n", $t, (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetch_row()[0], $one('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$t]));
    } else {
        echo sprintf("  %-18s MISSING%s\n", $t, $t === 'bookings' ? '  <- the migration needs this table; import database/schema.sql first' : '');
        if ($t === 'bookings') { $problems++; }
    }
}

echo "\nNew tables:\n";
$newTables = ['schema_migrations', 'parking_locations', 'payments', 'payment_events'];
foreach ($newTables as $t) {
    echo sprintf("  %-18s %s\n", $t, $tableExists($t) ? 'present' : 'not yet created');
}

echo "\nNew columns on bookings:\n";
$cols = ['user_id', 'location_id', 'start_time', 'end_time', 'status', 'hold_expires_at', 'cancelled_at'];
$missing = 0;
if ($tableExists('bookings')) {
    foreach ($cols as $c) {
        $has = $colExists('bookings', $c);
        $missing += $has ? 0 : 1;
        echo sprintf("  %-18s %s\n", $c, $has ? 'present' : 'not yet added');
    }
}

$recorded = $tableExists('schema_migrations') && (int) $one("SELECT COUNT(*) FROM schema_migrations WHERE version = '001_parkkaro_core'") > 0;
echo "\nRecorded in schema_migrations: " . ($recorded ? 'yes' : 'no') . "\n";

if ($tableExists('bookings') && $colExists('bookings', 'status')) {
    echo "\nLegacy bookings by status (old rows should all be 'confirmed'):\n";
    foreach ($db->query('SELECT status, COUNT(*) c FROM bookings GROUP BY status')->fetch_all(MYSQLI_ASSOC) as $r) {
        echo sprintf("  %-18s %d\n", $r['status'], $r['c']);
    }
}

echo "\nVerdict: ";
if ($problems) {
    echo "NOT READY (see above).\n";
    exit(1);
}
$newPresent = count(array_filter($newTables, $tableExists));
if ($newPresent === 0 && $missing === count($cols)) {
    echo "migration not applied yet. Back up, then run database/migrations/001_parkkaro_core.mysql.sql.\n";
} elseif ($newPresent === count($newTables) && $missing === 0 && $recorded) {
    echo "migration fully applied.\n";
} elseif ($newPresent === count($newTables) && $missing === 0) {
    echo "all objects exist but the migration is not recorded (possibly done by hand). Running the SQL file again is safe.\n";
} else {
    echo "PARTIALLY applied. Running the SQL file again is safe; it only adds what is missing.\n";
}

// ---- migration 002 (payment methods per place + ticket notifications) ----
echo "\nMigration 002 (optional: payment methods per place, ticket e-mail/WhatsApp/SMS):\n";
$m2 = [
    'parking_locations.accepts_online' => $colExists('parking_locations', 'accepts_online'),
    'parking_locations.accepts_cash'   => $colExists('parking_locations', 'accepts_cash'),
    'bookings.contact_phone'           => $colExists('bookings', 'contact_phone'),
    'bookings.notify_via'              => $colExists('bookings', 'notify_via'),
    'table notifications'              => $tableExists('notifications'),
];
foreach ($m2 as $k => $v) {
    echo sprintf("  %-34s %s\n", $k, $v ? 'present' : 'not yet added');
}
echo 'Migration 002: ' . (count(array_filter($m2)) === count($m2) ? "fully applied.\n" : "not applied. Back up, then: php tools/apply_migration.php 002 --yes\n");
