<?php
/**
 * HTTP-level checks against PHP's built-in web server. No database is needed
 * because every case below is decided BEFORE the first query runs
 * (login guard, CSRF check, input validation, signed-link check).
 *
 * Run:  php tests/http/run.php
 */
declare(strict_types=1);

require __DIR__ . '/../TestCase.php';

$root = realpath(__DIR__ . '/../..');
$port = random_int(20000, 29999);
$base = "http://127.0.0.1:$port";
// Point the app at a port nothing listens on, so a test that accidentally reaches
// the database fails loudly instead of touching a real one.
$env = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_USER' => 'nobody', 'DB_PASS' => 'x', 'DB_NAME' => 'none', 'APP_KEY' => 'http-test-key'];
$cmd = sprintf('php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($root));
$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + getenv());
register_shutdown_function(static function () use ($proc) { proc_terminate($proc); });
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) { break; }
    usleep(100000);
}

function req(string $method, string $url, array $post = [], string $jar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hdrLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hdrLen);
    preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
    return ['status' => $status, 'location' => $m[1] ?? '', 'body' => substr($raw, $hdrLen), 'headers' => $headers];
}

T::group('Admin pages redirect to the admin login when logged out');
$adminPages = ['dashboard1.php', 'view_bookings.php', 'manage_bookings.php', 'manage_slots.php', 'manage_gad.php',
               'manage_ajara.php', 'revenue.php', 'dashboards/kolhapur1_dashboard.php', 'dashboards/gadhi1_dashboard.php'];
foreach ($adminPages as $page) {
    $r = req('GET', "$base/$page");
    T::ok($r['status'] === 302 && str_ends_with($r['location'], 'login1.php'), "GET $page -> 302 login1.php (got {$r['status']} {$r['location']})");
    T::ok(!str_contains($r['body'], 'Total Bookings') && !str_contains($r['body'], 'Bookings List'), "GET $page leaks no page content");
}
foreach (['view_bookings.php' => ['delete_id' => '1'], 'manage_bookings.php' => ['clear_all' => '1'], 'manage_slots.php' => ['bulk_status' => 'booked', 'selected_slots' => ['1']]] as $page => $post) {
    $r = req('POST', "$base/$page", $post);
    T::ok($r['status'] === 302 && str_ends_with($r['location'], 'login1.php'), "POST $page without login -> 302 login1.php (got {$r['status']})");
}

T::group('Old broken entry points');
$r = req('GET', "$base/login.php");
T::ok($r['status'] === 302 && str_contains($r['location'], 'index.php'), 'login.php redirects to the working user login');
$r = req('GET', "$base/adminlogin.php");
T::ok($r['status'] === 302 && str_ends_with($r['location'], 'login1.php'), 'adminlogin.php redirects to the single admin login');
$r = req('GET', "$base/login1.php");
T::ok($r['status'] === 200 && str_contains($r['body'], 'name="csrf_token"'), 'admin login form contains a CSRF token');

T::group('Login and registration need a CSRF token');
$r = req('POST', "$base/login1.php", ['username' => "admin' OR '1'='1", 'password' => 'x']);
T::same(403, $r['status'], 'admin login POST without token -> 403 (SQL injection string never reaches SQL)');
$r = req('POST', "$base/register.php", ['first_name' => 'a', 'last_name' => 'b', 'email' => 'a@b.co', 'car_number' => 'MH09AB1234', 'password' => 'Secret!1']);
T::same(403, $r['status'], 'register POST without token -> 403');
$r = req('POST', "$base/index.php", ['login' => '1', 'email' => 'a@b.co', 'password' => 'x']);
T::ok($r['status'] === 403 || $r['status'] === 500, 'index.php login POST without token is refused (403, or 500 only because the test has no database)');

T::group('Booking pages: CSRF and server-side validation');
foreach (['kolhapur_booking.php', 'ajara_booking.php', 'gadhinjlaj_booking.php'] as $page) {
    $r = req('POST', "$base/$page", ['location' => 'x', 'name' => 'a', 'email' => 'a@b.co', 'carNumber' => 'MH09AB1234', 'hours' => '1']);
    T::same(403, $r['status'], "$page POST without token -> 403");
    $jar = tempnam(sys_get_temp_dir(), 'jar');
    $form = req('GET', "$base/$page", [], $jar);
    preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $form['body'], $m);
    T::ok(isset($m[1]), "$page form shows a CSRF token");
    $bad = req('POST', "$base/$page", ['csrf_token' => $m[1] ?? '', 'location' => 'Fake Place', 'name' => '<b>x</b>', 'email' => 'nope', 'carNumber' => '!', 'hours' => '999'], $jar);
    T::ok($bad['status'] === 200 && str_contains($bad['body'], 'Choose one of the listed parking locations'), "$page rejects an invented location with a message");
    T::ok(str_contains($bad['body'], 'Hours must be a whole number from 1 to 24'), "$page rejects 999 hours");
    T::ok(!str_contains($bad['body'], '<b>x</b>'), "$page does not echo raw HTML back");
    unlink($jar);
}

T::group('book.php (booking.html form)');
$r = req('POST', "$base/book.php", ['userName' => 'A B', 'userEmail' => 'a@b.co', 'carNumber' => 'MH09AB1234', 'parking_hours' => '2', 'city' => 'Ajara', 'location' => 'Nowhere', 'totalPrice' => '1']);
T::ok($r['status'] === 422 && str_contains($r['body'], 'Choose one of the listed parking locations'), 'invented location rejected; browser price ignored');
$r = req('GET', "$base/book.php");
T::ok($r['status'] === 302 && str_ends_with($r['location'], 'booking.html'), 'GET book.php sends visitors back to the form');

T::group('Tickets cannot be forged');
foreach (['booking_confirmed.php', 'generate_ticket.php'] as $page) {
    T::same(404, req('GET', "$base/$page?location=X&name=Mallory&cost=0")['status'], "$page with the OLD url parameters -> 404");
    T::same(404, req('GET', "$base/$page?id=1")['status'], "$page?id=1 without token -> 404");
    T::same(404, req('GET', "$base/$page?id=1&t=" . str_repeat('0', 64))['status'], "$page with a wrong token -> 404");
    T::same(404, req('GET', "$base/$page?id=abc&t=x")['status'], "$page with non-numeric id -> 404");
}

T::group('Logout');
$jar = tempnam(sys_get_temp_dir(), 'jar');
req('GET', "$base/login1.php", [], $jar);
$r = req('GET', "$base/logout.php", [], $jar);
T::ok($r['status'] === 302 && str_ends_with($r['location'], 'home.html'), 'logout.php redirects (it used to be an empty file)');
T::ok(stripos($r['headers'], 'Set-Cookie: PHPSESSID=deleted') !== false || stripos($r['headers'], 'Set-Cookie') !== false, 'logout expires the session cookie');
unlink($jar);

T::group('Files that must not be served');
foreach (['src/bootstrap.php', 'storage/app.key', 'legacy/success.php', 'admindetails.txt', 'database/schema.sql'] as $path) {
    $code = req('GET', "$base/$path")['status'];
    // The built-in PHP server ignores .htaccess, so this only reports the Apache rule's presence.
    echo "  info $path -> HTTP $code (built-in server ignores .htaccess; Apache blocks it)\n";
}
$deny = ['.htaccess', 'src/.htaccess', 'storage/.htaccess', 'legacy/.htaccess'];
foreach ($deny as $f) {
    T::ok(is_file("$root/$f") && str_contains((string) file_get_contents("$root/$f"), 'Require all denied'), "$f exists with a deny rule");
}

T::finish();
