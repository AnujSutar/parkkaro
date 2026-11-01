<?php
/**
 * One-time helper: replace plaintext admin passwords in `admin_users` with hashes.
 *
 *   1. BACK UP the database first (phpMyAdmin > Export).
 *   2. Run from the project folder:   php tools/migrate_admin_passwords.php --yes
 *
 * It never prints passwords. It does NOT change the table structure: if the
 * `password` column is shorter than 255 characters it stops and prints the ALTER
 * statement for you to review and run yourself.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
if (!in_array('--yes', $argv, true)) {
    exit("Dry run only. Back up the database, then run again with --yes\n");
}
require_once __DIR__ . '/../src/bootstrap.php';
$db = db();

$len = (int) db_scalar(
    $db,
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'password'",
    '', [], 0
);
if ($len < 255) {
    exit("admin_users.password is only $len characters wide; hashes need 255.\n"
       . "Review and run this yourself, then re-run this script:\n"
       . "  ALTER TABLE admin_users MODIFY password VARCHAR(255) NOT NULL;\n");
}

$rows = $db->query('SELECT username, password FROM admin_users');
$update = $db->prepare('UPDATE admin_users SET password = ? WHERE username = ? AND password = ?');
$done = 0;
$skipped = 0;
foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
    if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', (string) $row['password']) === 1) {
        $skipped++;
        continue;
    }
    $hash = password_hash((string) $row['password'], PASSWORD_DEFAULT);
    $update->bind_param('sss', $hash, $row['username'], $row['password']);
    $update->execute();
    $done += $update->affected_rows;
}
echo "Hashed $done admin password(s); $skipped were already hashed.\n";
