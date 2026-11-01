<?php
/**
 * Create an administrator account from the command line (this is how you create the FIRST admin).
 *
 *   php tools/create_admin.php --username=owner1 --zone="Zone A" --yes
 *
 * The password is asked for on the terminal (hidden where the OS allows it) or taken from the
 * environment variable PK_ADMIN_PASSWORD. It is stored with password_hash() and never printed.
 * Existing admins are never changed: a username that already exists is refused.
 * Back up the database first if you are cautious; this only INSERTs one row.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/dbx.php';
require_once __DIR__ . '/../src/admin_accounts.php';

$opts = getopt('', ['username:', 'zone:', 'yes']);
$username = (string) ($opts['username'] ?? '');
$zone = (string) ($opts['zone'] ?? '');
if ($username === '' || $zone === '') {
    exit("Usage: php tools/create_admin.php --username=NAME --zone=\"ZONE\" --yes\n");
}
$password = (string) (getenv('PK_ADMIN_PASSWORD') ?: '');
if ($password === '') {
    $hide = stripos(PHP_OS_FAMILY, 'Windows') === false;
    fwrite(STDOUT, 'Password for the new admin: ');
    $hide && @shell_exec('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
    $hide && @shell_exec('stty echo');
    fwrite(STDOUT, "\n");
}
[$errors, $clean] = validate_admin_input(['username' => $username, 'admin_zone' => $zone, 'password' => $password]);
if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}
if (!in_array('--yes', $argv, true)) {
    exit("Dry run: would create admin '{$clean['username']}' in zone '{$clean['admin_zone']}'. Add --yes to do it.\n");
}
$r = create_admin_account(pdo(), $clean);
if (!$r['ok']) {
    fwrite(STDERR, $r['error'] . "\n");
    exit(1);
}
echo "Admin '{$clean['username']}' created (zone '{$clean['admin_zone']}'). Log in at login1.php with that username.\n";
