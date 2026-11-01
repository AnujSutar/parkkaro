<?php
/**
 * Applies database/migrations/001_parkkaro_core.mysql.sql (or 002_notifications_payment_modes.mysql.sql: pass 002) using the app's own DB settings (.env).
 *   1. BACK UP the database first (phpMyAdmin > Export).
 *   2. php tools/apply_migration.php --yes
 * The SQL is additive and idempotent (see the header of the SQL file). Nothing is dropped.
 * Without --yes this only prints what it would do.
 * Same result as pasting the file into phpMyAdmin's SQL tab.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
// php tools/apply_migration.php [001|002] --yes     (default 001)
$which = '001';
foreach ($argv as $a) {
    if (preg_match('/^00[12]$/', $a)) {
        $which = $a;
    }
}
$files = ['001' => '001_parkkaro_core.mysql.sql', '002' => '002_notifications_payment_modes.mysql.sql'];
$file = __DIR__ . '/../database/migrations/' . $files[$which];
if (!is_file($file)) {
    exit("Migration file not found: $file\n");
}
if (!in_array('--yes', $argv, true)) {
    exit("Dry run: would apply $file\nBack up the database, then run again with --yes\nCheck first with: php tools/check_migration.php\n");
}
require_once __DIR__ . '/../src/bootstrap.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = db();
$sql = (string) file_get_contents($file);

try {
    $db->multi_query($sql);
    do {
        if ($res = $db->store_result()) {
            $res->free();
        }
    } while ($db->more_results() && $db->next_result());
} catch (mysqli_sql_exception $e) {
    fwrite(STDERR, 'Migration stopped: ' . $e->getMessage() . "\nNothing was dropped. Fix the cause and run it again (it is safe to repeat).\n");
    exit(1);
}
echo "Migration applied. Verify with: php tools/check_migration.php\n";
