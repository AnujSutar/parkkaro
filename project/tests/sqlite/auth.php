<?php
/**
 * Login / role-separation / navigation journeys, using REAL requests, REAL sessions and REAL cookies
 * (no fake-login headers). The project is served from a SUBFOLDER, like the documented XAMPP install:
 *      http://127.0.0.1:PORT/parkkaro_upgraded/project/
 *
 *   php tests/sqlite/auth.php
 *
 * SQLite stands in for MySQL (pdo() is swapped by tests/sqlite/prepend.php). Pages that still use
 * the old mysqli layer (dashboard1.php, view_bookings.php, manage_bookings.php, generate_ticket.php, city pages)
 * cannot run here: for those, only the redirect/guard that runs BEFORE they touch the database is tested.
 */
declare(strict_types=1);

require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/dbx.php';
require __DIR__ . '/../../src/admin_accounts.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIPPED: PHP pdo_sqlite is not installed.\n");
    exit(2);
}
$root = realpath(__DIR__ . '/../..');
$tmp = sys_get_temp_dir() . '/parkkaro_auth_' . getmypid();
@mkdir($tmp . '/parkkaro_upgraded', 0700, true);
symlink($root, $tmp . '/parkkaro_upgraded/project');
$dbFile = "$tmp/auth.sqlite";
$stderrFile = "$tmp/server.err";
register_shutdown_function(static function () use ($tmp): void {
    @unlink($tmp . '/parkkaro_upgraded/project');
    foreach (glob($tmp . '/*') ?: [] as $f) {
        is_file($f) && @unlink($f);
    }
    @rmdir($tmp . '/parkkaro_upgraded');
    @rmdir($tmp);
});

$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]);
$pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
$pdo->prepare('INSERT INTO users (first_name,last_name,email,car_number,password) VALUES (?,?,?,?,?)')
    ->execute(['Asha', 'Patil', 'asha@example.com', 'MH09AA1111', password_hash('Secret!1', PASSWORD_DEFAULT)]);
$pdo->prepare('INSERT INTO admin_users (username,password,admin_zone) VALUES (?,?,?)')->execute(['owner', 'ownerpass', 'Zone K']);        // legacy plaintext row
$pdo->prepare('INSERT INTO admin_users (username,password,admin_zone) VALUES (?,?,?)')->execute(['rival', password_hash('RivalPass!1', PASSWORD_DEFAULT), 'Zone R']); // hashed row
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
foreach ([['owner', 'Zone K', 'Owner Garage'], ['rival', 'Zone R', 'Rival Garage']] as [$o, $z, $n]) {
    $pdo->prepare("INSERT INTO parking_locations (owner_username,admin_zone,name,kind,city,address,description,capacity,hourly_rate,is_active,created_at,updated_at) VALUES (?,?,?,'private','Kolhapur','1 Road','',2,30,1,?,?)")
        ->execute([$o, $z, $n, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]);
}

$port = random_int(40000, 49999);
$origin = "http://127.0.0.1:$port";
$BASE = '/parkkaro_upgraded/project/';
$env = ['PK_TEST_DB' => $dbFile, 'PK_FAKE_STATE' => "$tmp/gw.json", 'APP_KEY' => 'auth-test-key', 'APP_TIMEZONE' => 'Asia/Kolkata',
        'DB_HOST' => '127.0.0.1', 'DB_USER' => 'nobody', 'DB_PASS' => 'x', 'DB_NAME' => 'none'];
$cmd = sprintf('php -S 127.0.0.1:%d -t %s -d auto_prepend_file=%s', $port, escapeshellarg($tmp), escapeshellarg(__DIR__ . '/prepend.php'));
$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, $tmp, $env + getenv());
register_shutdown_function(static function () use ($proc): void { proc_terminate($proc); });
for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

/** A browser: its own cookie jar. */
function browser(): string
{
    global $tmp;
    return tempnam($tmp, 'jar');
}

/** Request a path under the project base. Returns status, redirect target (absolute path) and body. */
function go(string $jar, string $method, string $path, array $post = []): array
{
    global $origin, $BASE;
    $url = str_starts_with($path, '/') ? $origin . $path : $origin . $BASE . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $loc = '';
    if (preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hl), $m)) {
        $loc = resolve($m[1], parse_url($url, PHP_URL_PATH));
    }
    return ['status' => $status, 'loc' => $loc, 'body' => substr($raw, $hl)];
}

/** Resolve a Location header the way a browser does, against the requested path. Returns an absolute path+query. */
function resolve(string $loc, string $from): string
{
    if (preg_match('#^https?://[^/]+(/.*)?$#', $loc, $m)) {
        return $m[1] ?? '/';
    }
    if (str_starts_with($loc, '/')) {
        return $loc;
    }
    return rtrim(dirname($from), '/') . '/' . $loc;
}

function tok(string $jar, string $path): string
{
    $r = go($jar, 'GET', $path);
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $r['body'], $m) ? $m[1] : '';
}

function user_login(string $jar, string $email = 'asha@example.com', string $pass = 'Secret!1', string $next = ''): array
{
    $t = tok($jar, 'index.php?login=true');
    return go($jar, 'POST', 'index.php?login=true', ['csrf_token' => $t, 'email' => $email, 'password' => $pass, 'next' => $next, 'login' => '1']);
}

function admin_login(string $jar, string $u = 'owner', string $p = 'ownerpass'): array
{
    $t = tok($jar, 'login1.php');
    return go($jar, 'POST', 'login1.php', ['csrf_token' => $t, 'username' => $u, 'password' => $p, 'login' => '1']);
}

function sid(string $jar): string
{
    return preg_match('/PHPSESSID\s+(\S+)/', (string) file_get_contents($jar), $m) ? $m[1] : '';
}

function hrefs(string $html): array
{
    preg_match_all('/<a [^>]*href="([^"#][^"]*)"/i', $html, $m);
    return array_values(array_unique(array_map('html_entity_decode', $m[1])));
}

$B = static fn(string $p): string => $BASE . $p;   // absolute path of a project page
// pages that still talk to MySQL through mysqli: their links are checked for existence on disk only
$mysqliOnly = ['dashboard1.php', 'view_bookings.php', 'manage_bookings.php', 'manage_slots.php', 'manage_gad.php', 'manage_ajara.php', 'revenue.php',
               'kolhapur.php', 'ajara.php', 'gad.php', 'live_map.php', 'booking.html', 'generate_ticket.php', 'booking_confirmed.php'];

/* ===================================================================== */
T::group('1. Homepage: both login options are visible and lead somewhere real');
$v = browser();
$home = go($v, 'GET', 'home.html');
T::same(200, $home['status'], 'home.html loads from the subfolder');
$links = hrefs($home['body']);
T::ok(in_array('index.php?login=true', $links, true), 'homepage has "User Login" -> index.php?login=true');
T::ok(in_array('register.php', $links, true), 'homepage has "Register"');
T::ok(in_array('login1.php', $links, true), 'homepage has "Admin Login" -> login1.php');
T::ok(str_contains($home['body'], 'User Login') && str_contains($home['body'], 'Admin Login'), 'the two options are labelled User Login and Admin Login');
T::ok(!in_array('login.php', $links, true), 'no ambiguous plain "Login" link left');
$landing = go($v, 'GET', 'landingLogin.html');
T::ok(in_array('login1.php', hrefs($landing['body']), true) && in_array('index.php?login=true', hrefs($landing['body']), true), 'the "Login as" chooser page links to both logins');

T::group('2. Every link on the public pages works under the subfolder');
$checked = 0;
$bad = [];
foreach (['home.html', 'landingLogin.html', 'index.php?login=true', 'index.php', 'register.php', 'login1.php', 'find_parking.php'] as $page) {
    $r = go($v, 'GET', $page);
    foreach (hrefs($r['body']) as $h) {
        if (preg_match('#^(https?:|mailto:|javascript:|//)#i', $h)) {
            continue;
        }
        $target = resolve($h, $BASE . $page);
        $file = basename((string) parse_url($target, PHP_URL_PATH));
        $checked++;
        if (in_array($file, $mysqliOnly, true)) {
            if (!is_file($root . '/' . $file)) {
                $bad[] = "$page -> $h (file missing)";
            }
            continue;
        }
        $x = go($v, 'GET', $target);
        $hops = 0;
        while (in_array($x['status'], [301, 302, 303], true) && $x['loc'] !== '' && $hops++ < 4) {
            $x = go($v, 'GET', $x['loc']);
        }
        if ($x['status'] >= 400) {
            $bad[] = "$page -> $h ({$x['status']})";
        }
    }
}
T::same([], $bad, "all $checked local links on the public pages resolve (200 after redirects)");
T::ok($checked > 15, 'the link check really looked at the links');

T::group('3. Registration');
$t = tok($v, 'index.php');
$r = go($v, 'POST', 'index.php', ['csrf_token' => $t, 'register' => '1', 'first_name' => 'Bhim', 'last_name' => 'Rao', 'email' => 'bhim@example.com', 'car_number' => 'mh09 bb 2222', 'password' => 'Passw0rd!']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Registered successfully') && str_contains($r['body'], 'index.php?login=true'), 'valid registration succeeds and links to the user login');
$u = $pdo->query("SELECT * FROM users WHERE email = 'bhim@example.com'")->fetch();
T::ok($u && $u['car_number'] === 'MH09BB2222' && str_starts_with($u['password'], '$2y$') && $u['password'] !== 'Passw0rd!', 'stored with a normalised car number and a bcrypt hash');
$r = go($v, 'POST', 'index.php', ['csrf_token' => $t, 'register' => '1', 'first_name' => 'Bhim', 'last_name' => 'Rao', 'email' => 'bhim@example.com', 'car_number' => 'MH09BB2222', 'password' => 'Passw0rd!']);
T::ok(str_contains($r['body'], 'Email already registered'), 'the same email cannot register twice');
$r = go($v, 'POST', 'index.php', ['csrf_token' => $t, 'register' => '1', 'first_name' => 'Cy', 'last_name' => 'Rao', 'email' => 'cy@example.com', 'car_number' => 'MH09CC3333', 'password' => 'weak']);
T::ok(str_contains($r['body'], 'Password must be') && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email='cy@example.com'")->fetchColumn() === 0, 'weak password rejected, nothing stored');
$r = go($v, 'POST', 'register.php', ['csrf_token' => tok($v, 'register.php'), 'first_name' => 'Dee', 'last_name' => 'Rao', 'email' => 'dee@example.com', 'car_number' => 'MH09DD4444', 'password' => 'Passw0rd!']);
T::ok(str_contains($r['body'], 'Registered successfully') && (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email='dee@example.com'")->fetchColumn() === 1, 'register.php also works');
T::same(403, go($v, 'POST', 'register.php', ['first_name' => 'No', 'last_name' => 'Token', 'email' => 'nt@example.com', 'car_number' => 'MH09EE5555', 'password' => 'Passw0rd!'])['status'], 'registration without a CSRF token -> 403');

T::group('4. Invalid credentials are rejected');
$j = browser();
$r = user_login($j, 'asha@example.com', 'wrong-password');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid email or password'), 'user: wrong password');
$r = user_login($j, 'nobody@example.com', 'Secret!1');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid email or password'), 'user: unknown email (same message, no user probing)');
$r = user_login($j, "x' OR '1'='1", 'x');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid email or password'), 'user: SQL-injection string');
T::ok(go($j, 'GET', 'my_dashboard.php')['status'] === 302, '...and still no access to the dashboard afterwards');
$r = admin_login($j, 'owner', 'wrong');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'admin: wrong password');
$r = admin_login($j, 'ghost', 'ownerpass');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'admin: unknown username');
$r = admin_login($j, 'owner', '');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'admin: empty password');
$r = admin_login($j, 'rival', 'ownerpass');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), "admin: another admin's password does not work");
T::same(403, go($j, 'POST', 'index.php?login=true', ['email' => 'asha@example.com', 'password' => 'Secret!1', 'login' => '1'])['status'], 'user login without CSRF token -> 403');
T::same(403, go($j, 'POST', 'login1.php', ['username' => 'owner', 'password' => 'ownerpass'])['status'], 'admin login without CSRF token -> 403');
T::ok(go($j, 'GET', 'admin_locations.php')['loc'] === $B('login1.php'), '...no admin access after failed admin logins');

T::group('5. Logged-out visitors cannot open protected pages');
$v = browser();
foreach (['my_dashboard.php', 'reserve.php?location=1', 'pay.php?booking=1'] as $page) {
    $r = go($v, 'GET', $page);
    T::ok($r['status'] === 302 && str_starts_with($r['loc'], $B('index.php?login=true')), "$page -> user login");
    T::ok(!str_contains($r['body'], 'Owner Garage'), "$page shows nothing before redirecting");
}
foreach (['admin_locations.php', 'admin_reservations.php', 'admin_earnings.php', 'dashboard1.php', 'view_bookings.php', 'manage_bookings.php'] as $page) {
    $r = go($v, 'GET', $page);
    T::ok($r['status'] === 302 && $r['loc'] === $B('login1.php'), "$page -> admin login");
}
$r = go($v, 'GET', 'admin_dashboard.php');
T::ok($r['status'] === 302 && $r['loc'] === $B('dashboard1.php'), 'admin_dashboard.php (old duplicate) forwards to the one admin dashboard...');
T::same($B('login1.php'), go($v, 'GET', $r['loc'])['loc'], '...which sends a logged-out visitor to the admin login');
T::ok(go($v, 'GET', 'dashboard.php')['loc'] === $B('my_dashboard.php'), 'old dashboard.php forwards to my_dashboard.php');
T::ok(go($v, 'GET', 'login.php')['loc'] === $B('index.php?login=true'), 'login.php forwards to the user login');
T::ok(go($v, 'GET', 'adminlogin.php')['loc'] === $B('login1.php'), 'adminlogin.php forwards to the admin login');

T::group('6. User login -> my_dashboard.php');
$u1 = browser();
go($u1, 'GET', 'index.php?login=true');
$before = sid($u1);
$r = user_login($u1);
T::ok($r['status'] === 302 && $r['loc'] === $B('my_dashboard.php'), 'valid user login redirects to my_dashboard.php');
T::ok($before !== '' && sid($u1) !== '' && sid($u1) !== $before, 'session id was regenerated at login');
$r = go($u1, 'GET', 'my_dashboard.php');
T::ok($r['status'] === 200 && str_contains($r['body'], 'My dashboard') && str_contains($r['body'], 'Hi, Asha'), 'dashboard opens and greets the user');
T::same($B('my_dashboard.php'), go($u1, 'GET', 'index.php?login=true')['loc'], 'a logged-in user opening the login page is sent to the dashboard');
// "next": return to the page that required login, but only safe pages
$x = browser();
$r = go($x, 'GET', 'reserve.php?location=1');
$next = (string) (parse_url($r['loc'], PHP_URL_QUERY) ?? '');
parse_str($next, $q);
T::same('reserve.php?location=1', $q['next'] ?? '', 'the login page is told which page the visitor wanted');
$r = user_login($x, 'asha@example.com', 'Secret!1', $q['next'] ?? '');
T::same($B('reserve.php?location=1'), $r['loc'], 'after login the visitor returns to that page');
foreach (['http://evil.example/steal', '//evil.example', 'admin_earnings.php', '../x.php', 'javascript:alert(1)', 'logout.php'] as $evil) {
    $y = browser();
    $r = user_login($y, 'asha@example.com', 'Secret!1', $evil);
    T::same($B('my_dashboard.php'), $r['loc'], "unsafe next '$evil' is ignored -> my_dashboard.php");
}

T::group('7. A normal user cannot reach admin pages');
foreach (['admin_locations.php', 'admin_reservations.php', 'admin_earnings.php', 'dashboard1.php', 'view_bookings.php', 'manage_bookings.php', 'revenue.php'] as $page) {
    $r = go($u1, 'GET', $page);
    T::ok($r['status'] === 302 && $r['loc'] === $B('login1.php') && !str_contains($r['body'], 'Garage'), "user session on $page -> admin login, no data");
}
$t = tok($u1, 'reserve.php?location=1');
$r = go($u1, 'POST', 'admin_locations.php', ['csrf_token' => $t, 'action' => 'save', 'id' => '0', 'name' => 'Sneaky', 'kind' => 'public', 'city' => 'X Town', 'address' => '9 Bad Road', 'capacity' => '1', 'hourly_rate' => '5']);
T::ok($r['loc'] === $B('login1.php') && (int) $pdo->query("SELECT COUNT(*) FROM parking_locations WHERE name='Sneaky'")->fetchColumn() === 0, 'a user cannot even POST a new parking place');

T::group('8. Navigation shows the right links for the role');
$r = go($u1, 'GET', 'find_parking.php');
$l = hrefs($r['body']);
T::ok(in_array('home.html', $l, true) && in_array('find_parking.php', $l, true) && in_array('my_dashboard.php', $l, true) && in_array('logout.php', $l, true), 'user nav: Home, Find parking, My dashboard, Logout');
T::ok(!array_filter($l, static fn($h) => str_starts_with($h, 'admin_') || $h === 'dashboard1.php' || $h === 'login1.php'), 'user nav has no admin links');
$r = go(browser(), 'GET', 'find_parking.php');
$l = hrefs($r['body']);
T::ok(in_array('index.php?login=true', $l, true) && in_array('login1.php', $l, true) && !in_array('my_dashboard.php', $l, true), 'visitor nav: User login, Register, Admin login (no dashboard)');

T::group('9. Admin login -> admin dashboard');
$a1 = browser();
go($a1, 'GET', 'login1.php');
$before = sid($a1);
$r = admin_login($a1);
T::ok($r['status'] === 302 && $r['loc'] === $B('dashboard1.php'), 'valid admin login (legacy plaintext row) redirects to dashboard1.php');
T::ok(sid($a1) !== '' && sid($a1) !== $before, 'session id regenerated');
$a2 = browser();
$r = admin_login($a2, 'rival', 'RivalPass!1');
T::ok($r['loc'] === $B('dashboard1.php'), 'valid admin login (hashed row) redirects to dashboard1.php');
T::ok(go($a1, 'GET', 'login1.php')['loc'] === $B('dashboard1.php'), 'an admin opening the login page again is sent to the dashboard');
foreach (['admin_locations.php' => 'Owner Garage', 'admin_reservations.php' => 'Reservations', 'admin_earnings.php' => 'Earnings'] as $page => $needle) {
    $r = go($a1, 'GET', $page);
    T::ok($r['status'] === 200 && str_contains($r['body'], $needle), "admin can open $page");
}
$l = hrefs(go($a1, 'GET', 'admin_locations.php')['body']);
T::ok(in_array('dashboard1.php', $l, true) && in_array('admin_locations.php', $l, true) && in_array('admin_reservations.php', $l, true) && in_array('admin_earnings.php', $l, true) && in_array('logout.php', $l, true), 'admin nav: Admin Dashboard, Manage Parking Locations, Reservations, Earnings, Logout');
T::ok(!in_array('my_dashboard.php', $l, true) && !in_array('find_parking.php', $l, true), 'admin nav has no user-dashboard links');
$bad = [];
foreach ($l as $h) {
    if (preg_match('#^(https?:|mailto:|javascript:)#', $h) || $h === 'logout.php') { continue; } // logout is exercised in section 13
    $file = basename((string) parse_url($h, PHP_URL_PATH));
    if (in_array($file, $mysqliOnly, true)) { is_file($root . '/' . $file) || $bad[] = $h; continue; }
    $x = go($a1, 'GET', $h);
    if ($x['status'] >= 400) { $bad[] = "$h ({$x['status']})"; }
}
T::same([], $bad, 'every admin navigation link resolves');

T::group('10. An admin is not a user (and the reverse)');
foreach (['my_dashboard.php', 'reserve.php?location=1', 'pay.php?booking=1'] as $page) {
    $r = go($a1, 'GET', $page);
    T::ok($r['status'] === 302 && str_starts_with($r['loc'], $B('index.php?login=true')), "admin session on $page -> user login");
}
$s = browser();
user_login($s);
T::ok(go($s, 'GET', 'my_dashboard.php')['status'] === 200, 'setup: logged in as user');
admin_login($s);
T::ok(go($s, 'GET', 'admin_locations.php')['status'] === 200, 'then logging in as admin in the same browser: admin pages open');
T::ok(go($s, 'GET', 'my_dashboard.php')['status'] === 302, '...and the user session is gone (one role at a time)');
user_login($s);
T::ok(go($s, 'GET', 'my_dashboard.php')['status'] === 200 && go($s, 'GET', 'admin_locations.php')['status'] === 302, 'logging in as user ends the admin session');

T::group('11. Admins cannot see each other\'s data');
$r = go($a1, 'GET', 'admin_locations.php');
T::ok(str_contains($r['body'], 'Owner Garage') && !str_contains($r['body'], 'Rival Garage'), 'owner sees only their own place');
$r = go($a2, 'GET', 'admin_locations.php');
T::ok(str_contains($r['body'], 'Rival Garage') && !str_contains($r['body'], 'Owner Garage'), 'rival sees only their own place');
$rid = (int) $pdo->query("SELECT id FROM parking_locations WHERE name='Owner Garage'")->fetchColumn();
$r = go($a2, 'GET', "admin_locations.php?edit=$rid");
T::ok(!str_contains($r['body'], 'Owner Garage') && !str_contains($r['body'], 'Edit parking place'), 'rival cannot open the owner\'s place for editing');
$t = tok($a2, 'admin_locations.php');
go($a2, 'POST', 'admin_locations.php', ['csrf_token' => $t, 'action' => 'toggle', 'id' => (string) $rid, 'active' => '0']);
T::same(1, (int) $pdo->query("SELECT is_active FROM parking_locations WHERE id = $rid")->fetchColumn(), "rival cannot switch off the owner's place");
// a real reservation + cash payment at the owner's place, then isolation of reservations and earnings
$t = tok($u1, "reserve.php?location=$rid");
$st = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('+1 day')->setTime(10, 0);
$r = go($u1, 'POST', 'reserve.php', ['csrf_token' => $t, 'location' => $rid, 'start' => $st->format('Y-m-d\TH:i'), 'end' => $st->modify('+2 hours')->format('Y-m-d\TH:i'), 'car_number' => 'MH09AA1111']);
preg_match('#booking=(\d+)#', $r['loc'], $m);
$bk = (int) ($m[1] ?? 0);
T::ok($bk > 0, 'setup: a user reserved a space at the owner\'s place');
$t = tok($a1, "admin_reservations.php?confirm=$bk");
$r = go($a1, 'POST', 'admin_reservations.php', ['csrf_token' => $t, 'confirm_payment' => (string) $bk, 'method' => 'cash', 'received' => '1']);
T::ok(str_contains($r['loc'], 'paid='), 'owner records the cash');
T::ok(str_contains(go($a1, 'GET', 'admin_earnings.php')['body'], '&#8377;60'), 'owner earnings include the verified cash (Rs 60)');
T::ok(str_contains(go($a1, 'GET', 'admin_reservations.php')['body'], 'Asha'), 'owner sees the reservation');
$r = go($a2, 'GET', 'admin_reservations.php');
T::ok(!str_contains($r['body'], 'Asha') && !str_contains($r['body'], 'asha@example.com'), 'rival does not see that reservation or customer');
T::ok(!str_contains(go($a2, 'GET', 'admin_earnings.php')['body'], '&#8377;60'), "rival's earnings do not include it");
$t = tok($a2, 'admin_locations.php');
$r = go($a2, 'POST', 'admin_reservations.php', ['csrf_token' => $t, 'confirm_payment' => (string) $bk, 'method' => 'cash', 'received' => '1']);
T::ok(str_contains($r['body'], 'not found') || str_contains($r['body'], 'already recorded'), 'rival cannot act on the owner\'s reservation');
$r = go($u1, 'GET', 'my_dashboard.php');
T::ok(str_contains($r['body'], 'Owner Garage') && str_contains($r['body'], 'Confirmed') && str_contains($r['body'], '&#8377;60'), 'the user sees own confirmed reservation and amount');
$other = browser();
user_login($other, 'bhim@example.com', 'Passw0rd!');
$r = go($other, 'GET', 'my_dashboard.php');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Hi, Bhim') && !str_contains($r['body'], 'Owner Garage'), "a second user does not see the first user's reservation");
T::same(404, go($other, 'GET', "pay.php?booking=$bk")['status'], "...and cannot open its payment page");

T::group('12. Support tickets (existing feature) still work for a logged-in user');
$t = tok($u1, 'index.php');
$r = go($u1, 'POST', 'index.php', ['csrf_token' => $t, 'submit_ticket' => '1', 'subject' => 'Gate stuck', 'message' => 'The barrier did not open']);
T::ok(str_contains($r['body'], 'Ticket submitted successfully') && str_contains($r['body'], 'Gate stuck'), 'ticket submitted and listed');
T::ok(!str_contains(go($other, 'GET', 'index.php')['body'], 'Gate stuck'), "another user does not see it");

T::group('13. Logout clears the session');
$copy = tempnam($tmp, 'jar');
copy($u1, $copy);                                   // a copy of the cookie, to replay after logout
$r = go($u1, 'GET', 'logout.php');
T::ok($r['status'] === 302 && $r['loc'] === $B('home.html'), 'user logout -> public homepage');
T::ok(go($u1, 'GET', 'my_dashboard.php')['status'] === 302, 'user is logged out');
T::ok(go($copy, 'GET', 'my_dashboard.php')['status'] === 302, 'replaying the OLD session cookie does not log back in (session destroyed server-side)');
$copy = tempnam($tmp, 'jar');
copy($a1, $copy);
$r = go($a1, 'GET', 'logout.php');
T::ok($r['loc'] === $B('home.html'), 'admin logout -> public homepage');
T::ok(go($a1, 'GET', 'admin_earnings.php')['loc'] === $B('login1.php'), 'admin is logged out');
T::ok(go($copy, 'GET', 'admin_earnings.php')['loc'] === $B('login1.php'), 'replaying the OLD admin cookie does not work');
T::ok(go($home = browser(), 'GET', 'home.html')['status'] === 200, 'homepage is public');

T::group('13b. Admin accounts: creation and login use the same field (username)');
// --- validation (pure) ---
$ok = ['username' => 'new.admin1', 'admin_zone' => 'Zone K', 'password' => 'Str0ngPass', 'password_confirm' => 'Str0ngPass'];
T::same([], validate_admin_input($ok)[0], 'valid input passes');
T::same([], validate_admin_input(['username' => 'ajara9@smartparking.com'] + $ok)[0], 'e-mail style usernames (as in existing data) are allowed');
T::same([], validate_admin_input(['username' => 'anuj sutar'] + $ok)[0], 'a name with a space ("anuj sutar") is allowed');
T::same([], validate_admin_input(['username' => 'anuj'] + $ok)[0], 'a single name ("anuj") is allowed');
$bads = ['' => 'required', 'ab' => 'characters', str_repeat('a', 51) => 'characters', 'has  double' => 'letters, digits', 'trail.' . '#' => 'letters, digits', '<script>' => 'letters, digits', "x'; DROP TABLE admin_users;--" => 'letters, digits', '-lead' => 'start with'];
foreach ($bads as $name => $needle) {
    $e = validate_admin_input(['username' => $name] + $ok)[0];
    T::ok($e && str_contains(implode(' ', $e), $needle), 'username "' . substr($name, 0, 20) . '" rejected (' . $needle . ')');
}
T::ok(str_contains(implode(' ', validate_admin_input(['password' => 'short1', 'password_confirm' => 'short1'] + $ok)[0]), 'at least 8'), 'short password rejected');
T::ok(str_contains(implode(' ', validate_admin_input(['password' => 'onlyletters', 'password_confirm' => 'onlyletters'] + $ok)[0]), 'letter and one digit'), 'password without a digit rejected');
T::ok(str_contains(implode(' ', validate_admin_input(['password_confirm' => 'Different1'] + $ok)[0]), 'do not match'), 'mismatched confirmation rejected');
T::ok(validate_admin_input(['admin_zone' => ''] + $ok)[0] !== [] && validate_admin_input(['admin_zone' => str_repeat('z', 21)] + $ok)[0] !== [], 'empty or over-long zone rejected');

// --- the page ---
$adm = browser();
admin_login($adm);                                        // owner (legacy plaintext row)
$ownerBefore = $pdo->query("SELECT password FROM admin_users WHERE username='owner'")->fetchColumn();
$rivalBefore = $pdo->query("SELECT password FROM admin_users WHERE username='rival'")->fetchColumn();
$page = go($adm, 'GET', 'admin_create.php');
T::ok($page['status'] === 200 && str_contains($page['body'], 'name="username"') && str_contains($page['body'], 'name="password"') && !str_contains($page['body'], 'name="admin_zone"'), 'admin_create.php shows Username and Password fields; the zone is fixed (not editable)');
T::ok(in_array('admin_create.php', hrefs($page['body']), true), 'it is in the admin navigation');
$post = static fn(array $over = []) => go($adm, 'POST', 'admin_create.php', array_merge(['csrf_token' => tok($adm, 'admin_create.php'), 'username' => 'newadmin', 'admin_zone' => 'Zone K', 'password' => 'NewPass123', 'password_confirm' => 'NewPass123'], $over));
$r = $post();
T::ok($r['status'] === 200 && str_contains($r['body'], 'Administrator &quot;newadmin&quot; was created'), 'admin account created with a username');
$row = $pdo->query("SELECT * FROM admin_users WHERE username='newadmin'")->fetch();
T::ok($row && $row['admin_zone'] === 'Zone K' && str_starts_with($row['password'], '$2y$') && $row['password'] !== 'NewPass123' && password_verify('NewPass123', $row['password']), 'stored in admin_users with a bcrypt hash (password_hash), zone saved');
T::same($ownerBefore, $pdo->query("SELECT password FROM admin_users WHERE username='owner'")->fetchColumn(), "existing admin 'owner' unchanged");
T::same($rivalBefore, $pdo->query("SELECT password FROM admin_users WHERE username='rival'")->fetchColumn(), "existing admin 'rival' unchanged");
$n = browser();
$r = admin_login($n, 'newadmin', 'NewPass123');
T::same($B('dashboard1.php'), $r['loc'], 'the new admin logs in at Admin Login with the same username and goes to dashboard1.php');
T::ok(go($n, 'GET', 'admin_locations.php')['status'] === 200, '...and has an admin session');
T::ok(!str_contains(go($n, 'GET', 'admin_locations.php')['body'], 'Owner Garage'), '...a new admin starts with no places of their own and sees nobody else\'s');
$r = admin_login(browser(), 'newadmin', 'WrongPass123');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'incorrect password rejected');
$r = admin_login(browser(), 'newadminx', 'NewPass123');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'nonexistent username rejected');
$r = admin_login(browser(), 'newadmin', 'NewPass123 ');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'password with an extra space rejected');
$r = $post();
T::ok(str_contains($r['body'], 'username is already taken'), 'duplicate username -> useful error');
$r = $post(['username' => 'NEWADMIN']);
T::ok(str_contains($r['body'], 'username is already taken'), 'duplicate ignoring upper/lower case -> same error');
$r = $post(['username' => 'owner']);
T::ok(str_contains($r['body'], 'username is already taken'), 'cannot re-create an existing (legacy) admin to overwrite its password');
T::same('ownerpass', $pdo->query("SELECT password FROM admin_users WHERE username='owner'")->fetchColumn(), '...the legacy account is untouched');
T::same(1, (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE LOWER(username)='newadmin'")->fetchColumn(), 'still exactly one row for that username');
$r = $post(['username' => 'x#y']);
T::ok(str_contains($r['body'], 'letters, digits') && str_contains($r['body'], 'value="x#y"'), 'invalid username: error shown and the typed value is kept');
$r = $post(['username' => '<script>alert(1)</script>']);
T::ok(!str_contains($r['body'], '<script>alert(1)</script>'), 'hostile username is never echoed raw');
$r = $post(['username' => 'weakpw', 'password' => 'abc', 'password_confirm' => 'abc']);
T::ok(str_contains($r['body'], 'at least 8') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='weakpw'")->fetchColumn() === 0, 'weak password rejected, nothing stored');
$r = $post(['username' => 'ajara9@smartparking.com', 'admin_zone' => 'Zone R']);
T::ok(str_contains($r['body'], 'was created'), 'e-mail style username accepted');
T::same('Zone K', $pdo->query("SELECT admin_zone FROM admin_users WHERE username='ajara9@smartparking.com'")->fetchColumn(), "a forged zone field is ignored: the new admin joins the CREATOR's zone, never another zone");
T::same(403, go($adm, 'POST', 'admin_create.php', ['username' => 'nocsrf', 'admin_zone' => 'Zone K', 'password' => 'NewPass123', 'password_confirm' => 'NewPass123'])['status'], 'creating an admin without a CSRF token -> 403');

// --- not public, not for users ---
T::same($B('login1.php'), go(browser(), 'GET', 'admin_create.php')['loc'], 'logged out: admin_create.php -> admin login');
$r = go(browser(), 'POST', 'admin_create.php', ['username' => 'anon', 'admin_zone' => 'Zone K', 'password' => 'NewPass123', 'password_confirm' => 'NewPass123']);
T::ok($r['loc'] === $B('login1.php') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='anon'")->fetchColumn() === 0, 'logged out: POST creates nothing');
$usr = browser();
user_login($usr);
T::same($B('login1.php'), go($usr, 'GET', 'admin_create.php')['loc'], 'a normal user cannot open admin_create.php');
$r = go($usr, 'POST', 'admin_create.php', ['csrf_token' => tok($usr, 'reserve.php?location=1'), 'username' => 'usersneak', 'admin_zone' => 'Zone K', 'password' => 'NewPass123', 'password_confirm' => 'NewPass123']);
T::ok($r['loc'] === $B('login1.php') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='usersneak'")->fetchColumn() === 0, 'a normal user cannot create an admin');

// --- users and admins stay separate ---
$admins = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
$t = tok(browser(), 'index.php');
$rj = browser();
$t = tok($rj, 'index.php');
$r = go($rj, 'POST', 'index.php', ['csrf_token' => $t, 'register' => '1', 'first_name' => 'Eve', 'last_name' => 'Rao', 'email' => 'eve@example.com', 'car_number' => 'MH09EE5555', 'password' => 'Passw0rd!']);
T::ok(str_contains($r['body'], 'Registered successfully'), 'normal user registration (no username field) still works');
T::same($admins, (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn(), 'registering a user creates NO admin row');
T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username IN ('eve@example.com','asha@example.com')")->fetchColumn(), 'no admin exists for any user e-mail');
$e = browser();
$r = user_login($e, 'eve@example.com', 'Passw0rd!');
T::same($B('my_dashboard.php'), $r['loc'], 'the new user logs in and lands on my_dashboard.php');
T::same($B('login1.php'), go($e, 'GET', 'admin_earnings.php')['loc'], '...and has no admin access');
$r = admin_login(browser(), 'asha@example.com', 'Secret!1');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'a normal user\'s e-mail + password does NOT work at Admin Login');
$r = admin_login(browser(), 'eve@example.com', 'Passw0rd!');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid credentials'), 'a freshly registered user cannot log in as admin');
$r = user_login(browser(), 'newadmin', 'NewPass123');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Invalid email or password'), 'an admin\'s credentials do NOT work at User Login');

// --- command line: how the first admin is created ---
$cli = static function (array $args, string $pw) use ($dbFile, $tmp, $root): array {
    $cmd = sprintf('PK_TEST_DB=%s PK_FAKE_STATE=%s PK_ADMIN_PASSWORD=%s php -d auto_prepend_file=%s %s %s 2>&1', escapeshellarg($dbFile), escapeshellarg("$tmp/gw.json"), escapeshellarg($pw),
        escapeshellarg(__DIR__ . '/prepend.php'), escapeshellarg($root . '/tools/create_admin.php'), $args ? implode(' ', array_map('escapeshellarg', $args)) : '');
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
};
[$c, $o] = $cli(['--username=cliadmin', '--zone=Zone C'], 'CliPass123');
T::ok($c === 0 && str_contains($o, 'Dry run') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='cliadmin'")->fetchColumn() === 0, 'CLI without --yes only previews');
[$c, $o] = $cli(['--username=cliadmin', '--zone=Zone C', '--yes'], 'CliPass123');
T::ok($c === 0 && str_contains($o, "Admin 'cliadmin' created") && !str_contains($o, 'CliPass123'), 'CLI --yes creates the admin and never prints the password');
$r = admin_login(browser(), 'cliadmin', 'CliPass123');
T::same($B('dashboard1.php'), $r['loc'], 'the CLI-created admin can log in at Admin Login');
[$c, $o] = $cli(['--username=cliadmin', '--zone=Zone C', '--yes'], 'CliPass123');
T::ok($c === 1 && str_contains($o, 'already taken'), 'CLI refuses a duplicate username');
[$c, $o] = $cli(['--username=cliadmin2', '--zone=Zone C', '--yes'], 'weak');
T::ok($c === 1 && str_contains($o, 'at least 8'), 'CLI refuses a weak password');

$r = $post(['username' => 'anuj sutar', 'password' => 'AnujPass123', 'password_confirm' => 'AnujPass123']);
T::ok(str_contains($r['body'], 'was created'), 'admin "anuj sutar" created through the page');
T::same($B('dashboard1.php'), admin_login(browser(), 'anuj sutar', 'AnujPass123')['loc'], '...logs in typing exactly "anuj sutar" + password -> dashboard1.php');
T::same($B('dashboard1.php'), admin_login(browser(), '  anuj sutar ', 'AnujPass123')['loc'], '...stray spaces around the name are ignored');
T::ok(str_contains(admin_login(browser(), 'anuj', 'AnujPass123')['body'], 'Invalid credentials'), '...but "anuj" alone is a different username and is rejected');

T::group('13c. Public registration: users AND admins can register on register.php, then log in');
$reg = browser();
$pu = go($reg, 'GET', 'register.php');
T::ok(str_contains($pu['body'], 'User Registration') && str_contains($pu['body'], 'name="email"') && str_contains($pu['body'], 'register.php?type=admin'), 'register.php is the user form and links to the admin form');
$pa = go($reg, 'GET', 'register.php?type=admin');
T::ok(str_contains($pa['body'], 'Admin Registration') && str_contains($pa['body'], 'name="username"') && !str_contains($pa['body'], 'name="email"') && !str_contains($pa['body'], 'name="admin_zone"'), 'register.php?type=admin shows a Username field, no e-mail and no zone choice');
T::ok(str_contains($pa['body'], 'register.php"') || str_contains($pa['body'], 'href="register.php"'), '...and links back to the user form');
T::ok(in_array('register.php?type=admin', hrefs(go(browser(), 'GET', 'login1.php')['body']), true), 'Admin Login page links to admin registration');
$regAdmin = static fn(string $jar, array $over = []) => go($jar, 'POST', 'register.php?type=admin', array_merge(['csrf_token' => tok($jar, 'register.php?type=admin'), 'username' => 'Meera Joshi', 'password' => 'Anuj1234', 'password_confirm' => 'Anuj1234'], $over));
$r = $regAdmin($reg);
T::ok(str_contains($r['body'], 'Admin account created') && str_contains($r['body'], 'login1.php'), 'an admin registers with a username');
$row = $pdo->query("SELECT * FROM admin_users WHERE username='Meera Joshi'")->fetch();
T::ok($row && str_starts_with($row['password'], '$2y$') && preg_match('/^Own-[0-9a-f]{6}$/', $row['admin_zone']) === 1, 'stored hashed, in a brand-new private zone (Own-xxxxxx)');
T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE admin_zone IN ('Zone K','Zone R') AND username='Meera Joshi'")->fetchColumn(), 'the new admin is NOT in an existing zone');
$ad = browser();
T::same($B('dashboard1.php'), admin_login($ad, 'Meera Joshi', 'Anuj1234')['loc'], 'the self-registered admin logs in at Admin Login -> dashboard1.php');
T::ok(str_contains(go($ad, 'GET', 'admin_locations.php')['body'], 'Register a new parking place'), '...and can manage their own parking places');
$b2 = go($ad, 'GET', 'admin_locations.php')['body'];
T::ok(!str_contains($b2, 'Owner Garage') && !str_contains($b2, 'Rival Garage'), '...but sees nobody else\'s places');
T::ok(!str_contains(go($ad, 'GET', 'admin_reservations.php')['body'], 'Asha') && !str_contains(go($ad, 'GET', 'admin_earnings.php')['body'], '&#8377;60'), '...nor their reservations, customers or earnings');
// they can add a colleague, but only into their own zone
$tk = tok($ad, 'admin_create.php');
go($ad, 'POST', 'admin_create.php', ['csrf_token' => $tk, 'username' => 'colleague1', 'admin_zone' => 'Zone K', 'password' => 'Colleag123', 'password_confirm' => 'Colleag123']);
T::same($row['admin_zone'], $pdo->query("SELECT admin_zone FROM admin_users WHERE username='colleague1'")->fetchColumn(), 'a self-registered admin cannot put a new admin into "Zone K" (their own zone is forced)');
// validation, duplicates, CSRF
$r = $regAdmin(browser(), ['username' => 'meera joshi']);
T::ok(str_contains($r['body'], 'username is already taken'), 'duplicate username (any case) rejected with a clear message');
$r = $regAdmin(browser(), ['username' => 'owner']);
T::ok(str_contains($r['body'], 'username is already taken'), 'cannot register an existing admin name');
$r = $regAdmin(browser(), ['username' => 'a', 'password' => 'x', 'password_confirm' => 'y']);
T::ok(str_contains($r['body'], 'Username must be') && str_contains($r['body'], 'at least 8'), 'bad username and weak password rejected');
T::same(403, go(browser(), 'POST', 'register.php?type=admin', ['username' => 'nocsrf1', 'password' => 'Anuj1234', 'password_confirm' => 'Anuj1234'])['status'], 'admin registration without CSRF token -> 403');
T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='nocsrf1'")->fetchColumn(), '...nothing stored');
// a forged zone in the public form is ignored
$r = $regAdmin(browser(), ['username' => 'forger1', 'admin_zone' => 'Zone K']);
T::ok(preg_match('/^Own-/', (string) $pdo->query("SELECT admin_zone FROM admin_users WHERE username='forger1'")->fetchColumn()) === 1, 'a forged admin_zone field in the public form is ignored');
// user registration on the same page still works and creates NO admin
$admins = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
$ur = browser();
$r = go($ur, 'POST', 'register.php', ['csrf_token' => tok($ur, 'register.php'), 'first_name' => 'Fay', 'last_name' => 'Rao', 'email' => 'fay@example.com', 'car_number' => 'MH09FF6666', 'password' => 'Passw0rd!']);
T::ok(str_contains($r['body'], 'Registered successfully'), 'user registration on register.php still works');
T::same($admins, (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn(), '...and creates no admin row');
$r = go($ur, 'POST', 'register.php', ['csrf_token' => tok($ur, 'register.php'), 'type' => 'admin', 'is_admin' => '1', 'role' => 'admin', 'first_name' => 'Gus', 'last_name' => 'Rao', 'email' => 'gus@example.com', 'car_number' => 'MH09GG7777', 'password' => 'Passw0rd!']);
T::same(0, (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username LIKE 'gus%'")->fetchColumn(), 'forged type/role/is_admin fields on the USER form never create an admin');
$fu = browser();
T::same($B('my_dashboard.php'), user_login($fu, 'fay@example.com', 'Passw0rd!')['loc'], 'the new user logs in -> my_dashboard.php');
T::same($B('login1.php'), go($fu, 'GET', 'admin_locations.php')['loc'], '...and is not an admin');
T::ok(str_contains(admin_login(browser(), 'fay@example.com', 'Passw0rd!')['body'], 'Invalid credentials'), '...and the user\'s credentials do not work at Admin Login');
T::ok(str_contains(user_login(browser(), 'Meera Joshi', 'Anuj1234')['body'], 'Invalid email or password'), 'the admin\'s credentials do not work at User Login');

// --- switches: ADMIN_REGISTRATION=off and ADMIN_SIGNUP_CODE are read from the environment (separate servers) ---
$spawn = static function (array $extra) use ($tmp, $dbFile, $env): array {
    $port = random_int(50000, 59999);
    $cmd = sprintf('php -S 127.0.0.1:%d -t %s -d auto_prepend_file=%s', $port, escapeshellarg($tmp), escapeshellarg(__DIR__ . '/prepend.php'));
    $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp, $extra + $env + getenv());
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) { usleep(100000); }
    return [$p, $port];
};
$form = static function (int $port, array $post, string $path = '/parkkaro_upgraded/project/register.php?type=admin') {
    $tokenPath = '/parkkaro_upgraded/project/register.php'; // the user form always carries a token (the admin form may be switched off)
    $jar = tempnam(sys_get_temp_dir(), 'j');
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $page = (string) curl_exec($ch);
    curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:$port$tokenPath");
    preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', (string) curl_exec($ch), $m);
    curl_setopt_array($ch, [CURLOPT_URL => "http://127.0.0.1:$port$path", CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf_token' => $m[1] ?? ''] + $post)]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    @unlink($jar);
    return [$page, $body];
};
[$p1, $port1] = $spawn(['ADMIN_REGISTRATION' => 'off']);
[$page, $body] = $form($port1, ['username' => 'offadmin', 'password' => 'Anuj1234', 'password_confirm' => 'Anuj1234']);
T::ok(str_contains($page, 'switched off') && !str_contains($page, 'name="username"'), 'ADMIN_REGISTRATION=off: the admin form is not offered');
T::ok(str_contains($body, 'switched off') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='offadmin'")->fetchColumn() === 0, '...and a forged POST creates nothing');
proc_terminate($p1);
[$p2, $port2] = $spawn(['ADMIN_SIGNUP_CODE' => 'letmein-42']);
[$page, $body] = $form($port2, ['username' => 'codeadmin', 'password' => 'Anuj1234', 'password_confirm' => 'Anuj1234', 'signup_code' => 'wrong']);
T::ok(str_contains($page, 'name="signup_code"'), 'ADMIN_SIGNUP_CODE set: the form asks for the sign-up code');
T::ok(str_contains($body, 'sign-up code is wrong') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='codeadmin'")->fetchColumn() === 0, '...a wrong code creates nothing');
[$page, $body] = $form($port2, ['username' => 'codeadmin', 'password' => 'Anuj1234', 'password_confirm' => 'Anuj1234', 'signup_code' => 'letmein-42']);
T::ok(str_contains($body, 'Admin account created') && (int) $pdo->query("SELECT COUNT(*) FROM admin_users WHERE username='codeadmin'")->fetchColumn() === 1, '...the right code creates the admin');
proc_terminate($p2);

T::group('14. No PHP errors were logged');
$log = (string) file_get_contents($stderrFile);
preg_match_all('/PHP (Warning|Notice|Fatal error|Deprecated|Parse error)[^\n]*/', $log, $bad);
T::same([], $bad[0], 'no PHP warnings / notices / fatal errors in the server log');

T::finish();
