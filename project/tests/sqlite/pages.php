<?php
/**
 * Page-level tests: the REAL new pages run on PHP's built-in web server, against a seeded SQLite database.
 *
 *   php tests/sqlite/pages.php
 *
 * tests/sqlite/prepend.php (loaded only by this test) swaps in the SQLite connection, a fake payment
 * gateway (no network) and header-based sessions. So this proves the pages' own code (includes, forms, CSRF,
 * escaping, redirects, ownership checks, the pay -> callback -> confirmed flow) works end to end.
 * It does NOT prove anything about MySQL itself, Apache, real browsers, or the live Razorpay service.
 * The older mysqli-based pages (dashboard1.php, view_bookings.php, ticket pages) cannot run on SQLite and are
 * covered by the MySQL checklist in docs/PARKKARO_UPGRADE.md.
 */
declare(strict_types=1);

putenv('APP_KEY=pages-test-key');
putenv('APP_TIMEZONE=Asia/Kolkata');
require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/dbx.php';
require __DIR__ . '/../../src/booking.php';
require __DIR__ . '/../../src/locations.php';
require __DIR__ . '/../../src/payments.php';
require_once __DIR__ . '/../../src/notify.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIPPED: PHP pdo_sqlite is not installed.\n");
    exit(2);
}

$root = realpath(__DIR__ . '/../..');
$tmp = sys_get_temp_dir() . '/parkkaro_pages_' . getmypid();
@mkdir($tmp, 0700, true);
$dbFile = "$tmp/pages.sqlite";
$fakeState = "$tmp/gateway.json";
$stderrFile = "$tmp/server.err";
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($tmp);
});

/* ---------- database + seed ---------- */
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 10]);
$pdo->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
$tz = new DateTimeZone('Asia/Kolkata');
$real = new DateTimeImmutable('now', $tz);
foreach ([['owner', 'Zone K'], ['rival', 'Zone R']] as [$u, $z]) {
    q_run($pdo, 'INSERT INTO admin_users (username, password, admin_zone) VALUES (?, ?, ?)', [$u, 'x', $z]);
}
foreach ([['u1@example.com', 'MH09AA1111', 'Asha'], ['u2@example.com', 'MH09BB2222', 'Bhim']] as [$mail, $car, $first]) {
    q_run($pdo, 'INSERT INTO users (first_name, last_name, email, car_number, password) VALUES (?, ?, ?, ?, ?)', [$first, 'Patil', $mail, $car, 'x']);
}
function seed_loc(PDO $pdo, string $owner, string $zone, array $over): int
{
    global $real;
    [$e, $c] = validate_location_input($over + ['kind' => 'public', 'city' => 'Kolhapur', 'address' => '1 Test Road, Kolhapur', 'description' => '',
        'capacity' => '1', 'hourly_rate' => '20', 'lat' => '16.7050', 'lng' => '74.2433', 'open_from' => '', 'open_until' => '']);
    if ($e) {
        throw new RuntimeException(implode(' ', $e));
    }
    return create_location($pdo, $owner, $zone, $c, $real)['id'];
}
$L1 = seed_loc($pdo, 'owner', 'Zone K', ['name' => 'Shahu Garage', 'kind' => 'private', 'capacity' => '1', 'hourly_rate' => '40']);
$L2 = seed_loc($pdo, 'owner', 'Zone K', ['name' => 'Bus Stand Lot', 'kind' => 'public', 'capacity' => '5', 'hourly_rate' => '20', 'city' => 'Ajara', 'lat' => '16.1170', 'lng' => '74.2110']);
$L3 = seed_loc($pdo, 'owner', 'Zone K', ['name' => 'Day Garage', 'kind' => 'private', 'capacity' => '2', 'hourly_rate' => '30', 'open_from' => '08:00', 'open_until' => '20:00']);
$LR = seed_loc($pdo, 'rival', 'Zone R', ['name' => 'Rival Lot', 'kind' => 'public', 'capacity' => '3', 'hourly_rate' => '100']);

/* ---------- server ---------- */
$port = random_int(30000, 39999);
$base = "http://127.0.0.1:$port";
$env = ['PK_TEST_DB' => $dbFile, 'PK_FAKE_STATE' => $fakeState, 'APP_KEY' => 'pages-test-key', 'APP_TIMEZONE' => 'Asia/Kolkata',
        'DB_HOST' => '127.0.0.1', 'DB_USER' => 'nobody', 'DB_PASS' => 'x', 'DB_NAME' => 'none',
        'RAZORPAY_KEY_ID' => '', 'RAZORPAY_KEY_SECRET' => '', 'RAZORPAY_WEBHOOK_SECRET' => '',
        'PK_NOTIFY_LOG' => "$tmp/notify.log", 'PK_NOTIFY_FAIL' => "$tmp/notify.fail"];
$cmd = sprintf('php -S 127.0.0.1:%d -t %s -d auto_prepend_file=%s', $port, escapeshellarg($root), escapeshellarg(__DIR__ . '/prepend.php'));
$proc = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderrFile, 'w']], $pipes, $root, $env + getenv());
register_shutdown_function(static function () use ($proc): void { proc_terminate($proc); });
for ($i = 0; $i < 60 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}

/* ---------- helpers ---------- */
function req(string $method, string $path, array $post = [], array $opt = []): array
{
    global $base;
    $ch = curl_init($base . '/' . $path);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (isset($opt['raw'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['raw']);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
    }
    if (!empty($opt['jar'])) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $opt['jar']);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $opt['jar']);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hl = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hl), $m);
    return ['status' => $status, 'location' => $m[1] ?? '', 'body' => substr($raw, $hl)];
}

/** A logged-in persona: cookie jar + fake-login header. */
function persona(string $kind, string $value): array
{
    global $tmp;
    return ['jar' => tempnam($tmp, 'jar'), 'headers' => [$kind === 'user' ? "X-Test-User: $value" : "X-Test-Admin: $value"]];
}

function token(array $who, string $page): string
{
    $r = req('GET', $page, [], $who);
    return preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $r['body'], $m) ? $m[1] : '';
}

function row(string $sql, array $params = []): ?array
{
    global $pdo;
    return q_one($pdo, $sql, $params);
}

function val(string $sql, array $params = [])
{
    global $pdo;
    return q_val($pdo, $sql, $params);
}

function sign(string $order, string $payment): string
{
    return hash_hmac('sha256', $order . '|' . $payment, 'test_key_secret');
}

function gateway_set_payment(string $paymentId, string $orderId, int $amountPaise, string $status = 'captured'): void
{
    global $fakeState;
    $s = is_file($fakeState) && filesize($fakeState) > 0 ? json_decode((string) file_get_contents($fakeState), true) : ['orders' => 0, 'payments' => []];
    $s['payments'][$paymentId] = ['id' => $paymentId, 'order_id' => $orderId, 'amount' => $amountPaise, 'currency' => 'INR', 'status' => $status];
    file_put_contents($fakeState, json_encode($s));
}

$u1 = persona('user', '1');
$u2 = persona('user', '2');
$owner = persona('admin', 'owner|Zone K');
$rival = persona('admin', 'rival|Zone R');
$tomorrow = $real->modify('+1 day')->setTime(10, 0);
$t = static fn(int $h, int $m = 0): string => $tomorrow->setTime($h, $m)->format('Y-m-d\TH:i');
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);

/* ===================================================================== */
T::group('find_parking.php (public search)');
$r = req('GET', 'find_parking.php');
T::ok($r['status'] === 200 && str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'Bus Stand Lot') && str_contains($r['body'], 'Rival Lot'), 'anyone can search; all active places are listed');
T::ok(str_contains($r['body'], 'Private') && str_contains($r['body'], 'Public'), 'private and public places are labelled');
$r = req('GET', 'find_parking.php?q=Day');
T::ok(str_contains($r['body'], 'Day Garage') && !str_contains($r['body'], 'Shahu Garage'), 'name filter works');
$r = req('GET', 'find_parking.php?kind=public');
T::ok(str_contains($r['body'], 'Bus Stand Lot') && !str_contains($r['body'], 'Shahu Garage'), 'type filter: public only');
$r = req('GET', 'find_parking.php?kind=private');
T::ok(str_contains($r['body'], 'Shahu Garage') && !str_contains($r['body'], 'Bus Stand Lot'), 'type filter: private only');
$r = req('GET', 'find_parking.php?q=' . urlencode('<script>alert(1)</script>'));
T::ok(!str_contains($r['body'], '<script>alert(1)</script>') && str_contains($r['body'], '&lt;script&gt;'), 'search text is escaped when shown again (no XSS)');
$r = req('GET', 'find_parking.php?' . http_build_query(['start' => $t(21), 'end' => $t(22)]));
T::ok(str_contains($r['body'], 'Closed during those times') && str_contains($r['body'], 'Shahu Garage'), 'a place closed at the chosen hours says so; 24h places stay reservable');
$r = req('GET', 'find_parking.php?' . http_build_query(['start' => $t(12), 'end' => $t(10)]));
T::ok(str_contains($r['body'], 'end time must be after the start time'), 'end before start shows an error');

T::group('reserve.php');
$r = req('GET', "reserve.php?location=$L1");
T::ok($r['status'] === 302 && str_contains($r['location'], 'index.php?login=true'), 'logged out: redirected to the user login');
$r = req('GET', "reserve.php?location=$L1", [], $u1);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'name="csrf_token"') && str_contains($r['body'], 'value="MH09AA1111"'), 'form shows the place, a CSRF token and the car number from the account');
T::same(404, req('GET', 'reserve.php?location=99999', [], $u1)['status'], 'unknown place -> 404');
T::same(403, req('POST', 'reserve.php', ['location' => $L1, 'start' => $t(10), 'end' => $t(12), 'car_number' => 'MH09AA1111'], $u1)['status'], 'POST without CSRF token -> 403');
$tok = token($u1, "reserve.php?location=$L1");
$r = req('POST', 'reserve.php', ['csrf_token' => $tok, 'location' => $L1, 'start' => $t(10), 'end' => $t(12), 'car_number' => 'MH09AA1111'], $u1);
T::ok($r['status'] === 302 && preg_match('#pay\.php\?booking=(\d+)#', $r['location'], $m) === 1, 'valid reservation redirects to the payment page');
$B1 = (int) ($m[1] ?? 0);
$b = row('SELECT * FROM bookings WHERE id = ?', [$B1]);
T::ok($b['status'] === 'pending_payment' && (int) $b['user_id'] === 1 && (int) $b['location_id'] === $L1 && (int) $b['cost'] === 80, 'stored as pending_payment for user 1, Rs 80 = 2h x 40 (server price)');

/* ===================================================================== */
T::group('find_parking.php: free parking + map integration');
require_once __DIR__ . '/../../src/free_parking.php';
$cards = static function (string $html): array {
    $out = [];
    foreach (explode('<div class="card place"', $html) as $i => $chunk) {
        if ($i === 0) { continue; }
        $out[] = substr($chunk, 0, (int) strpos($chunk . '</div></div></div>', "\n    <script") ?: strlen($chunk));
    }
    return $out;
};
$r = req('GET', 'find_parking.php');
T::ok($r['status'] === 200 && str_contains($r['body'], 'id="fp-map"') && str_contains($r['body'], 'id="fp-locate"') && str_contains($r['body'], 'id="city"'), 'page has the map, the locate button and the city selector');
T::ok(str_contains($r['body'], 'leaflet@1.9.4/dist/leaflet.js') && str_contains($r['body'], 'tile.openstreetmap.org'), 'uses Leaflet + OpenStreetMap (no paid map API)');
T::ok(!str_contains($r['body'], 'watchPosition') && str_contains($r['body'], 'getCurrentPosition'), 'location is asked for once (getCurrentPosition), never tracked (no watchPosition)');
T::ok(!preg_match('/<script[^>]*>[^<]*(?:origin=-?\d)/', $r['body']), 'no hard-coded user/origin coordinates in the page script');
$free = fp_free_spots();
$byCity = ['Kolhapur' => null, 'Ajara' => null, 'Gadhinglaj' => null];
foreach ($free as $s) { $byCity[$s['city']] ??= $s; }
foreach ($byCity as $cn => $spot) {
    $r = req('GET', 'find_parking.php?city=' . $cn);
    $others = array_values(array_filter(array_keys($byCity), static fn ($x) => $x !== $cn));
    $otherSpot = $byCity[$others[0]];
    T::ok($r['status'] === 200 && str_contains($r['body'], $h($spot['name'])) && !str_contains($r['body'], $h($otherSpot['name'])) && str_contains($r['body'], '<option value="' . $cn . '" selected>'), "city=$cn: its free spots are listed and another city's are not");
}
$r = req('GET', 'find_parking.php?city=Ajara');
T::ok(str_contains($r['body'], 'Bus Stand Lot') && !str_contains($r['body'], 'Shahu Garage'), 'city=Ajara also lists the admin-managed place in Ajara and not the Kolhapur one');
$r = req('GET', 'find_parking.php?city=Kolhapur');
T::ok(str_contains($r['body'], 'Shahu Garage') && !str_contains($r['body'], 'Bus Stand Lot'), 'city=Kolhapur lists the admin-managed Kolhapur place');
$r = req('GET', 'find_parking.php?city=' . urlencode('<script>x</script>'));
T::ok($r['status'] === 200 && !str_contains($r['body'], '<script>x</script>'), 'an unknown / hostile city value is ignored safely');
$r = req('GET', 'find_parking.php?city=Ajara&q=zzzznomatch');
T::ok(str_contains($r['body'], 'No parking places match'), 'an empty result shows a clear message');

$r = req('GET', 'find_parking.php?kind=free');
$cs = $cards($r['body']);
T::ok(count($cs) === count($free) && !str_contains($r['body'], 'Shahu Garage'), 'kind=free lists only free parking (' . count($cs) . ' spots)');
$bad = 0; $noDir = 0;
foreach ($cs as $c) {
    if (str_contains($c, 'reserve.php') || str_contains($c, 'Book Parking') || str_contains($c, '/ hour') || str_contains($c, '&#8377;') || !str_contains($c, 'Free Parking') || !str_contains($c, 'Availability is unknown')) { $bad++; }
    if (!str_contains($c, 'href="https://www.google.com/maps/dir/?api=1&amp;destination=')) { $noDir++; }
}
T::same(0, $bad, 'every free card: "Free Parking" label, availability unknown, no price, no Book/Reserve link');
T::same(0, $noDir, 'every free card has a Google Maps directions link with only a destination');

$r = req('GET', 'find_parking.php?kind=public');
T::ok(!str_contains($r['body'], 'Free Parking</span>'), 'kind=public / private lists bookable places only (no free spots)');
$r = req('GET', 'find_parking.php?city=Kolhapur');
$kc = array_values(array_filter($cards($r['body']), static fn ($c) => str_contains($c, 'Shahu Garage')));
T::ok(count($kc) === 1 && str_contains($kc[0], 'href="reserve.php?location=' . $L1 . '&amp;start=&amp;end="') && str_contains($kc[0], 'Book Parking') && str_contains($kc[0], '&#8377;'), 'bookable card: price, Book Parking -> reserve.php with the right location id');
T::ok(str_contains($kc[0], 'destination=16.705%2C74.2433'), 'bookable directions use that place\'s own coordinates as destination');
T::ok(!str_contains($kc[0], 'origin='), 'directions carry no origin until the browser grants a location');
T::ok(str_contains($kc[0], 'data-lat="16.705"'), 'marker data comes from the database row');
// forged parameters cannot change what is bookable or priced
$r = req('GET', 'find_parking.php?city=Kolhapur&hourly_rate=1&free_spaces=99&price=0&capacity=99');
$kc = array_values(array_filter($cards($r['body']), static fn ($c) => str_contains($c, 'Shahu Garage')));
T::ok(count($kc) === 1 && !str_contains($kc[0], '&#8377;1 ') && str_contains($kc[0], '1 of 1 spaces free'), 'forged price/capacity parameters are ignored');
// full for the window of reservation B1 (capacity 1)
$r = req('GET', 'find_parking.php?' . http_build_query(['city' => 'Kolhapur', 'start' => $t(10), 'end' => $t(12)]));
$kc = array_values(array_filter($cards($r['body']), static fn ($c) => str_contains($c, 'Shahu Garage')));
T::ok(count($kc) === 1 && str_contains($kc[0], 'Full for those times') && !str_contains($kc[0], 'Book Parking') && !str_contains($kc[0], 'href="reserve.php'), 'a full place is shown as Full with no Book button');
// duplicates: an admin place hides the free spot with the same name
$dupName = $byCity['Kolhapur']['name'];
$LD = seed_loc($pdo, 'owner', 'Zone K', ['name' => $dupName, 'kind' => 'public', 'capacity' => '3', 'hourly_rate' => '10', 'lat' => (string) $byCity['Kolhapur']['lat'], 'lng' => (string) $byCity['Kolhapur']['lng']]);
$r = req('GET', 'find_parking.php?city=Kolhapur');
T::same(1, substr_count($r['body'], '<h3>' . $h($dupName) . ' '), 'a free spot that is also an admin-registered place is listed once (as the bookable one)');
q_run($pdo, 'UPDATE parking_locations SET is_active = 0 WHERE id = ?', [$LD]);
$r = req('GET', 'find_parking.php?city=Kolhapur');
T::ok(str_contains($r['body'], $h($dupName)) && !str_contains($r['body'], 'location=' . $LD), 'an inactive admin place is never bookable (the free spot shows instead)');
q_run($pdo, 'DELETE FROM parking_locations WHERE id = ?', [$LD]);
// every card key on the page is unique
$r = req('GET', 'find_parking.php');
preg_match_all('/data-key="([^"]+)"/', $r['body'], $km);
T::same(count($km[1]), count(array_unique($km[1])), 'no place appears twice on the page');

/* ===================================================================== */
T::group('Regression: a place in Ajara is listed under Ajara, never under Kolhapur');
$tokO2 = token($owner, 'admin_locations.php');
$save = static function (array $over) use ($owner, $tokO2): array {
    return req('POST', 'admin_locations.php', ['csrf_token' => $tokO2, 'action' => 'save', 'id' => '', 'name' => 'Ajara Test Lot', 'kind' => 'private', 'city' => 'Ajara',
        'address' => '2 Market Road, Ajara', 'description' => '', 'capacity' => '2', 'hourly_rate' => '25', 'lat' => '16.1170', 'lng' => '74.2110', 'open_from' => '', 'open_until' => ''] + [], $owner);
};
// 1. the form's city is a fixed list now
$r = req('GET', 'admin_locations.php', [], $owner);
T::ok(str_contains($r['body'], '<select id="city"') && str_contains($r['body'], '<option value="Ajara"'), 'admin form offers Kolhapur / Ajara / Gadhinglaj as a list');
T::ok(!str_contains($r['body'], 'placeholder="16.7050"') && !str_contains($r['body'], 'placeholder="74.2433"'), 'the form no longer suggests Kolhapur coordinates as an example');
// 2. a city that disagrees with the map position is refused
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO2, 'action' => 'save', 'id' => '', 'name' => 'Mismatch Lot', 'kind' => 'private', 'city' => 'Ajara', 'address' => '9 Road, Ajara', 'description' => '',
    'capacity' => '2', 'hourly_rate' => '25', 'lat' => '16.7050', 'lng' => '74.2433', 'open_from' => '', 'open_until' => ''], $owner);
T::ok(str_contains($r['body'], 'is in Kolhapur, but the city says Ajara') && (int) val("SELECT COUNT(*) FROM parking_locations WHERE name = 'Mismatch Lot'") === 0, 'Ajara chosen but Kolhapur coordinates: refused with a clear message, nothing saved');
// 3. the exact case reported: city typed as "Ajara, Kolhapur" (private place)
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO2, 'action' => 'save', 'id' => '', 'name' => 'Ajara Test Lot', 'kind' => 'private', 'city' => 'Ajara, Kolhapur', 'address' => '2 Market Road, Ajara', 'description' => '',
    'capacity' => '2', 'hourly_rate' => '25', 'lat' => '16.1170', 'lng' => '74.2110', 'open_from' => '', 'open_until' => ''], $owner);
$AID = (int) val("SELECT id FROM parking_locations WHERE name = 'Ajara Test Lot'");
T::ok($r['status'] === 302 && $AID > 0 && val('SELECT city FROM parking_locations WHERE id = ?', [$AID]) === 'Ajara', 'saved, and the city is stored as plain "Ajara"');
$cardsOf = static function (string $html) use ($cards): array { return $cards($html); };
foreach ([['city=Ajara&kind=private', true], ['city=Kolhapur&kind=private', false], ['city=Gadhinglaj&kind=private', false], ['kind=private', true]] as [$qs, $expect]) {
    $b = req('GET', 'find_parking.php?' . $qs)['body'];
    T::same($expect, str_contains($b, 'Ajara Test Lot'), "find_parking.php?$qs " . ($expect ? 'shows' : 'does not show') . ' the Ajara private place');
}
// 4. legacy rows saved before this fix: wrong/mixed city text but a real Ajara position -> still found under Ajara only
q_run($pdo, "UPDATE parking_locations SET city = 'Kolhapur' WHERE id = ?", [$AID]);
T::ok(str_contains(req('GET', 'find_parking.php?city=Ajara&kind=private')['body'], 'Ajara Test Lot') && !str_contains(req('GET', 'find_parking.php?city=Kolhapur&kind=private')['body'], 'Ajara Test Lot'), 'an old row whose city text says Kolhapur but whose map pin is in Ajara is listed under Ajara only');
// 5. a place with no coordinates falls back to the city text, first-named city wins
q_run($pdo, "UPDATE parking_locations SET city = 'Ajara, Kolhapur', lat = NULL, lng = NULL WHERE id = ?", [$AID]);
T::ok(str_contains(req('GET', 'find_parking.php?city=Ajara&kind=private')['body'], 'Ajara Test Lot') && !str_contains(req('GET', 'find_parking.php?city=Kolhapur&kind=private')['body'], 'Ajara Test Lot'), 'no coordinates: "Ajara, Kolhapur" is listed under Ajara (first-named city), not Kolhapur');
$j = json_decode(req('GET', 'parking_points.php')['body'], true);
T::ok(is_array($j) && !array_filter($j['bookable'], static fn ($b) => $b['name'] === 'Ajara Test Lot'), 'the Live map feed lists only places with coordinates');
q_run($pdo, 'DELETE FROM parking_locations WHERE id = ?', [$AID]);

T::group('reserve.php (continued)');
$r = req('POST', 'reserve.php', ['csrf_token' => $tok, 'location' => $L1, 'start' => $t(10), 'end' => $t(12), 'car_number' => 'MH09BB2222'], $u2);
T::same(403, $r['status'], "another user's CSRF token is not accepted (token is per session)");
$tok2 = token($u2, "reserve.php?location=$L1");
$r = req('POST', 'reserve.php', ['csrf_token' => $tok2, 'location' => $L1, 'start' => $t(11), 'end' => $t(13), 'car_number' => 'MH09BB2222'], $u2);
T::ok($r['status'] === 200 && str_contains($r['body'], 'no space left'), 'overlapping window at a full place is refused with a message');
$r = req('POST', 'reserve.php', ['csrf_token' => $tok2, 'location' => $L1, 'start' => $real->modify('-3 hours')->format('Y-m-d\TH:i'), 'end' => $t(13), 'car_number' => 'MH09BB2222'], $u2);
T::ok($r['status'] === 200 && str_contains($r['body'], 'in the past'), 'a start time in the past is refused');
T::same(1, (int) val('SELECT COUNT(*) FROM bookings WHERE location_id IS NOT NULL'), 'refused attempts created no rows');

T::group('pay.php');
$r = req('GET', "pay.php?booking=$B1", [], $u1);
T::ok($r['status'] === 200 && str_contains($r['body'], '&#8377;80') && str_contains($r['body'], 'online') && str_contains($r['body'], 'Paying cash'), 'own reservation: amount, online and cash options shown');
T::same(404, req('GET', "pay.php?booking=$B1", [], $u2)['status'], "another user cannot open someone else's payment page");
T::same(0, (int) val('SELECT COUNT(*) FROM payments'), 'viewing the page creates no payment');
T::same(403, req('POST', 'pay.php', ['booking' => $B1], $u1)['status'], 'starting a payment needs the CSRF token');
$tok = token($u1, "pay.php?booking=$B1");
$r = req('POST', 'pay.php', ['csrf_token' => $tok, 'booking' => $B1], $u1);
T::ok($r['status'] === 200 && str_contains($r['body'], 'checkout.razorpay.com/v1/checkout.js') && str_contains($r['body'], 'data-order_id="order_PG1"') && str_contains($r['body'], 'data-amount="8000"') && str_contains($r['body'], 'action="payment_callback.php"'), 'checkout form: gateway script, our order id, amount 8000 paise, posts back to our callback');
T::ok(!str_contains($r['body'], 'test_key_secret'), 'the gateway SECRET never appears in the page');
$p = row('SELECT * FROM payments WHERE booking_id = ?', [$B1]);
T::ok($p['status'] === 'created' && $p['provider_order_id'] === 'order_PG1' && (int) $p['amount'] === 80, 'payment row is only "created" (not paid) and uses the stored amount');
req('POST', 'pay.php', ['csrf_token' => $tok, 'booking' => $B1], $u1);
T::same(1, (int) val('SELECT COUNT(*) FROM payments'), 'pressing pay again reuses the same order');

T::group('my_dashboard.php before payment');
$r = req('GET', 'my_dashboard.php', [], $u1);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Awaiting payment') && str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'Pay now') && !str_contains($r['body'], 'booking_confirmed.php'), 'unpaid reservation: Pay now / Cancel, no ticket link');
T::ok(str_contains(req('GET', 'my_dashboard.php')['location'], 'index.php?login=true'), 'logged out -> redirected to login');

T::group('payment_callback.php (browser returns from checkout)');
$r = req('GET', 'payment_callback.php');
T::ok($r['status'] === 302 && str_contains($r['location'], 'my_dashboard.php'), 'GET just redirects');
$r = req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_PAGE0001', 'razorpay_order_id' => 'order_PG1', 'razorpay_signature' => str_repeat('b', 64)]);
T::ok($r['status'] === 303 && str_contains($r['location'], 'pay=failed'), 'forged signature -> failed');
T::same('pending_payment', val('SELECT status FROM bookings WHERE id = ?', [$B1]), '...reservation still unpaid');
gateway_set_payment('pay_PAGE0001', 'order_PG1', 8000, 'authorized');
$r = req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_PAGE0001', 'razorpay_order_id' => 'order_PG1', 'razorpay_signature' => sign('order_PG1', 'pay_PAGE0001')]);
T::ok(str_contains($r['location'], 'pay=pending'), 'valid signature but the gateway says only "authorized" -> pending');
T::same('created', val('SELECT status FROM payments WHERE booking_id = ?', [$B1]), '...not recorded as paid');
gateway_set_payment('pay_PAGE0001', 'order_PG1', 8000, 'captured');
$r = req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_PAGE0001', 'razorpay_order_id' => 'order_PG1', 'razorpay_signature' => sign('order_PG1', 'pay_PAGE0001')]);
T::ok($r['status'] === 303 && str_contains($r['location'], 'pay=confirmed') && str_contains($r['location'], "booking=$B1"), 'captured + valid signature -> confirmed');
$p = row('SELECT * FROM payments WHERE booking_id = ?', [$B1]);
T::ok($p['status'] === 'succeeded' && $p['verification'] === 'gateway_api' && $p['provider_payment_id'] === 'pay_PAGE0001' && $p['verified_at'] !== null, 'payment row: succeeded / gateway_api / payment id / time');
T::same('confirmed', val('SELECT status FROM bookings WHERE id = ?', [$B1]), 'reservation is confirmed');

T::group('my_dashboard.php after payment');
$r = req('GET', 'my_dashboard.php?pay=confirmed&booking=' . $B1, [], $u1);
T::ok(str_contains($r['body'], 'Upcoming and current') && str_contains($r['body'], 'Confirmed') && str_contains($r['body'], 'Payment received. Your reservation is confirmed.'), 'confirmed reservation and success message');
T::ok(preg_match('#booking_confirmed\.php\?id=' . $B1 . '&amp;t=[0-9a-f]{64}#', $r['body']) === 1 && preg_match('#generate_ticket\.php\?id=' . $B1 . '&amp;t=[0-9a-f]{64}#', $r['body']) === 1, 'ticket and PDF links carry the signed token');
T::ok(str_contains($r['body'], '&#8377;80'), 'total paid shows Rs 80');
$r = req('GET', 'my_dashboard.php?pay=' . urlencode('<script>x</script>'), [], $u1);
T::ok(!str_contains($r['body'], '<script>x</script>'), 'unknown ?pay= values are ignored, never echoed');
$r = req('GET', 'my_dashboard.php', [], $u2);
T::ok($r['status'] === 200 && !str_contains($r['body'], 'Shahu Garage'), "user 2's dashboard does not show user 1's reservation");

T::group('Cancelling an unpaid reservation');
$tok1 = token($u1, "reserve.php?location=$L2");
$r = req('POST', 'reserve.php', ['csrf_token' => $tok1, 'location' => $L2, 'start' => $t(14), 'end' => $t(15), 'car_number' => 'MH09AA1111'], $u1);
preg_match('#booking=(\d+)#', $r['location'], $m);
$B2 = (int) ($m[1] ?? 0);
T::ok($B2 > 0 && val('SELECT status FROM bookings WHERE id = ?', [$B2]) === 'pending_payment', 'second reservation (Bus Stand Lot) is pending');
T::same(403, req('POST', 'my_dashboard.php', ['cancel_booking' => $B2], $u1)['status'], 'cancel without CSRF token -> 403');
$tok2 = token($u2, "reserve.php?location=$L2"); // any page with a form carries this session's token
$r = req('POST', 'my_dashboard.php', ['csrf_token' => $tok2, 'cancel_booking' => $B2], $u2);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Reservation not found'), "user 2 cannot cancel user 1's reservation");
T::same('pending_payment', val('SELECT status FROM bookings WHERE id = ?', [$B2]), '...it is unchanged');
$tok1 = token($u1, 'my_dashboard.php');
$r = req('POST', 'my_dashboard.php', ['csrf_token' => $tok1, 'cancel_booking' => $B2], $u1);
T::ok($r['status'] === 302 && str_contains($r['location'], 'cancelled=1'), 'owner cancels own unpaid reservation');
T::same('cancelled', val('SELECT status FROM bookings WHERE id = ?', [$B2]), '...status is cancelled');
$r = req('POST', 'my_dashboard.php', ['csrf_token' => $tok1, 'cancel_booking' => $B1], $u1);
T::ok($r['status'] === 200 && str_contains($r['body'], 'still waiting for payment'), 'a PAID reservation cannot be cancelled (no refunds implemented)');
T::same('confirmed', val('SELECT status FROM bookings WHERE id = ?', [$B1]), '...it stays confirmed');

T::group('payment_webhook.php');
$tokU2 = token($u2, "reserve.php?location=$L2");
$r = req('POST', 'reserve.php', ['csrf_token' => $tokU2, 'location' => $L2, 'start' => $t(16), 'end' => $t(18), 'car_number' => 'MH09BB2222'], $u2);
preg_match('#booking=(\d+)#', $r['location'], $m);
$B3 = (int) ($m[1] ?? 0);
$tokU2 = token($u2, "pay.php?booking=$B3");
req('POST', 'pay.php', ['csrf_token' => $tokU2, 'booking' => $B3], $u2);
$order3 = (string) val('SELECT provider_order_id FROM payments WHERE booking_id = ?', [$B3]);
T::ok($order3 !== '' && (int) val('SELECT amount FROM payments WHERE booking_id = ?', [$B3]) === 40, 'setup: user 2 has an order for Rs 40');
$event = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_WEB00001', 'order_id' => $order3, 'amount' => 4000, 'currency' => 'INR', 'status' => 'captured']]]]);
$wsig = hash_hmac('sha256', $event, 'test_webhook_secret');
$hdr = static fn(string $sig, string $id): array => ['raw' => $event, 'headers' => ['Content-Type: application/json', "X-Razorpay-Signature: $sig", "X-Razorpay-Event-Id: $id"]];
T::same(405, req('GET', 'payment_webhook.php')['status'], 'GET -> 405');
$r = req('POST', 'payment_webhook.php', [], $hdr(str_repeat('0', 64), 'evt_p1'));
T::ok($r['status'] === 400 && $r['body'] === 'bad signature', 'wrong signature -> 400');
$r = req('POST', 'payment_webhook.php', [], ['raw' => $event, 'headers' => ['Content-Type: application/json']]);
T::same(400, $r['status'], 'no signature header -> 400');
T::same('pending_payment', val('SELECT status FROM bookings WHERE id = ?', [$B3]), 'rejected webhooks changed nothing');
$r = req('POST', 'payment_webhook.php', [], $hdr($wsig, 'evt_p2'));
T::ok($r['status'] === 200 && $r['body'] === 'recorded', 'valid signed captured event -> recorded');
$p = row('SELECT * FROM payments WHERE booking_id = ?', [$B3]);
T::ok($p['status'] === 'succeeded' && $p['verification'] === 'gateway_webhook' && val('SELECT status FROM bookings WHERE id = ?', [$B3]) === 'confirmed', 'payment succeeded via webhook and the reservation is confirmed');
$r = req('POST', 'payment_webhook.php', [], $hdr($wsig, 'evt_p2'));
T::ok($r['status'] === 200 && $r['body'] === 'duplicate event ignored', 'the same event delivered again is ignored');
T::same(1, (int) val('SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = ?', [$B3, 'succeeded']), '...still one successful payment');

/* ===================================================================== */
T::group('Admin pages need an admin');
foreach (['admin_locations.php', 'admin_reservations.php', 'admin_earnings.php'] as $page) {
    $r = req('GET', $page);
    T::ok($r['status'] === 302 && str_ends_with($r['location'], 'login1.php'), "GET $page logged out -> login1.php");
    T::ok(!str_contains($r['body'], 'Shahu Garage'), "$page leaks nothing to anonymous visitors");
}
T::ok(str_ends_with(req('POST', 'admin_locations.php', ['action' => 'toggle', 'id' => $L1, 'active' => '0'])['location'], 'login1.php'), 'POST admin_locations.php logged out -> login1.php');
T::ok(str_ends_with(req('POST', 'admin_reservations.php', ['confirm_payment' => $B1, 'method' => 'cash', 'received' => '1'])['location'], 'login1.php'), 'POST admin_reservations.php logged out -> login1.php');
T::ok(str_ends_with(req('GET', 'admin_locations.php', [], $u1)['location'], 'login1.php'), 'a normal user session is not an admin session');

T::group('admin_locations.php');
$r = req('GET', 'admin_locations.php', [], $owner);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'Bus Stand Lot') && str_contains($r['body'], 'Day Garage') && !str_contains($r['body'], 'Rival Lot'), "owner sees their 3 places, not the rival's");
$r = req('GET', 'admin_locations.php', [], $rival);
T::ok(str_contains($r['body'], 'Rival Lot') && !str_contains($r['body'], 'Shahu Garage'), 'rival sees only their own');
$newPlace = ['action' => 'save', 'id' => '0', 'name' => 'New Roof Parking', 'kind' => 'private', 'city' => 'Gadhinglaj', 'address' => '5 Hill Road, Gadhinglaj',
             'description' => 'Rooftop', 'capacity' => '4', 'hourly_rate' => '25', 'lat' => '', 'lng' => '', 'open_from' => '', 'open_until' => ''];
T::same(403, req('POST', 'admin_locations.php', $newPlace, $owner)['status'], 'adding a place without CSRF token -> 403');
$tokO = token($owner, 'admin_locations.php');
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO] + $newPlace, $owner);
T::ok($r['status'] === 302 && str_contains($r['location'], 'saved=added'), 'valid place is added');
$np = row("SELECT * FROM parking_locations WHERE name = 'New Roof Parking'");
T::ok($np !== null && $np['owner_username'] === 'owner' && $np['admin_zone'] === 'Zone K' && (int) $np['capacity'] === 4 && (int) $np['hourly_rate'] === 25, "stored with the admin's username and zone taken from the SESSION");
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO, 'owner_username' => 'rival'] + $newPlace + [], $owner);
T::ok(str_contains($r['body'], 'already have a place with this name'), 'duplicate name for the same owner is refused (a forged owner_username field is ignored)');
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO, 'name' => 'Free Lot', 'hourly_rate' => '0'] + $newPlace, $owner);
T::ok($r['status'] === 200 && str_contains($r['body'], 'hourly rate must be a whole number'), 'a price of 0 is rejected with a message');
T::same(0, (int) val("SELECT COUNT(*) FROM parking_locations WHERE name = 'Free Lot'"), '...and nothing was stored');
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO, 'name' => '<script>alert(1)</script>'] + $newPlace, $owner);
T::ok(!str_contains($r['body'], '<script>alert(1)</script>'), 'hostile name is rejected and never echoed raw');
// cross-tenant attacks
$tokR = token($rival, 'admin_locations.php');
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokR, 'action' => 'save', 'id' => (string) $L1, 'name' => 'HACKED', 'kind' => 'public', 'city' => 'X Town', 'address' => '99 Evil Street',
    'description' => '', 'capacity' => '1', 'hourly_rate' => '1', 'lat' => '', 'lng' => '', 'open_from' => '', 'open_until' => ''], $rival);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Place not found'), "rival cannot update the owner's place");
T::same('Shahu Garage', val('SELECT name FROM parking_locations WHERE id = ?', [$L1]), "...the owner's place is unchanged");
req('POST', 'admin_locations.php', ['csrf_token' => $tokR, 'action' => 'toggle', 'id' => (string) $L1, 'active' => '0'], $rival);
T::same(1, (int) val('SELECT is_active FROM parking_locations WHERE id = ?', [$L1]), "rival cannot switch off the owner's place");
$r = req('GET', "admin_locations.php?edit=$L1", [], $rival);
T::ok(!str_contains($r['body'], 'Shahu Garage') && !str_contains($r['body'], 'Edit parking place'), "rival opening ?edit= for the owner's place gets an empty add-form, not the owner's data");
$r = req('GET', "admin_locations.php?edit=$L1", [], $owner);
T::ok(str_contains($r['body'], 'Edit parking place') && str_contains($r['body'], 'value="Shahu Garage"'), 'owner editing their own place sees its values');
// switching off hides from search, existing reservations stay
req('POST', 'admin_locations.php', ['csrf_token' => $tokO, 'action' => 'toggle', 'id' => (string) $L2, 'active' => '0'], $owner);
T::same(0, (int) val('SELECT is_active FROM parking_locations WHERE id = ?', [$L2]), 'owner switches a place off');
T::ok(!str_contains(req('GET', 'find_parking.php')['body'], 'Bus Stand Lot'), '...it disappears from public search');
T::same('confirmed', val('SELECT status FROM bookings WHERE id = ?', [$B3]), '...existing confirmed reservations there stay valid');
T::same(404, req('GET', "reserve.php?location=$L2", [], $u1)['status'], '...and it can no longer be reserved');
req('POST', 'admin_locations.php', ['csrf_token' => $tokO, 'action' => 'toggle', 'id' => (string) $L2, 'active' => '1'], $owner);
T::ok(str_contains(req('GET', 'find_parking.php')['body'], 'Bus Stand Lot'), 'switched back on: visible again');
// capacity guard through the page
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO, 'action' => 'save', 'id' => (string) $L1, 'name' => 'Shahu Garage', 'kind' => 'private', 'city' => 'Kolhapur', 'address' => '1 Test Road, Kolhapur',
    'description' => '', 'capacity' => '1', 'hourly_rate' => '45', 'lat' => '', 'lng' => '', 'open_from' => '', 'open_until' => ''], $owner);
T::ok($r['status'] === 302 && (int) val('SELECT hourly_rate FROM parking_locations WHERE id = ?', [$L1]) === 45, 'owner can change the price');
T::same(80, (int) val('SELECT cost FROM bookings WHERE id = ?', [$B1]), '...and an existing reservation keeps the price it was made at');

T::group('admin_reservations.php and cash');
$r = req('GET', 'admin_reservations.php', [], $owner);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'Bhim'), "owner sees reservations at their places (and the customers' names)");
$r = req('GET', 'admin_reservations.php', [], $rival);
T::ok($r['status'] === 200 && !str_contains($r['body'], 'Shahu Garage') && !str_contains($r['body'], 'Bhim') && !str_contains($r['body'], 'u2@example.com'), "rival sees none of the owner's reservations or customers");
$r = req('GET', 'admin_reservations.php?status=confirmed', [], $owner);
T::ok(str_contains($r['body'], 'Shahu Garage') && !str_contains($r['body'], 'Cancelled</span>'), 'status filter works');
// a pending reservation at Day Garage to pay in cash
$tokU1 = token($u1, "reserve.php?location=$L3");
$r = req('POST', 'reserve.php', ['csrf_token' => $tokU1, 'location' => $L3, 'start' => $t(13), 'end' => $t(15), 'car_number' => 'MH09AA1111'], $u1);
preg_match('#booking=(\d+)#', $r['location'], $m);
$B4 = (int) ($m[1] ?? 0);
T::ok($B4 > 0 && (int) val('SELECT cost FROM bookings WHERE id = ?', [$B4]) === 60, 'setup: pending reservation at Day Garage, Rs 60');
$r = req('GET', 'admin_reservations.php?status=pending_payment', [], $owner);
T::ok(str_contains($r['body'], 'Confirm Cash/UPI Payment'), 'unpaid reservation offers "Confirm Cash/UPI Payment"');
T::same(403, req('POST', 'admin_reservations.php', ['confirm_payment' => $B4, 'method' => 'cash', 'received' => '1'], $owner)['status'], 'recording cash without CSRF token -> 403');
$tokR = token($rival, 'admin_locations.php'); // rival has no reservations page form
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tokR, 'confirm_payment' => $B4, 'method' => 'cash', 'received' => '1'], $rival);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Reservation not found'), "rival cannot record cash for the owner's reservation");
T::same(0, (int) val('SELECT COUNT(*) FROM payments WHERE booking_id = ?', [$B4]), '...no payment row was written');
$tokO = token($owner, "admin_reservations.php?confirm=$B4");   // the review screen carries the form
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tokO, 'confirm_payment' => $B4, 'method' => 'cash', 'received' => '1'], $owner);
T::ok($r['status'] === 302 && str_contains($r['location'], 'paid='), 'owner records the cash');
$p = row('SELECT * FROM payments WHERE booking_id = ?', [$B4]);
T::ok($p['provider'] === 'cash' && $p['verification'] === 'admin_cash' && $p['recorded_by'] === 'owner' && (int) $p['amount'] === 60 && val('SELECT status FROM bookings WHERE id = ?', [$B4]) === 'confirmed', 'cash row labelled admin_cash by "owner", amount from the booking; reservation confirmed');
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tokO, 'confirm_payment' => $B4, 'method' => 'cash', 'received' => '1'], $owner);
T::ok($r['status'] === 200 && str_contains($r['body'], 'already recorded'), 'recording it a second time is refused');
T::same(1, (int) val('SELECT COUNT(*) FROM payments WHERE booking_id = ?', [$B4]), '...still one payment row');

T::group('admin_earnings.php');
$expectTotal = 80 + 40 + 60; // online (callback) + online (webhook) + cash
$sumDb = (int) val("SELECT SUM(p.amount) FROM payments p JOIN bookings b ON b.id = p.booking_id JOIN parking_locations l ON l.id = b.location_id WHERE l.owner_username = 'owner' AND p.status = 'succeeded' AND b.status = 'confirmed'");
T::same($expectTotal, $sumDb, 'setup: the database holds Rs 180 of verified payments for the owner');
$r = req('GET', 'admin_earnings.php', [], $owner);
T::ok($r['status'] === 200 && str_contains($r['body'], '&#8377;180') && str_contains($r['body'], 'Earned today') && str_contains($r['body'], 'Earned this month'), 'owner: today and month totals show Rs 180');
T::ok(str_contains($r['body'], '&#8377;120') && str_contains($r['body'], '&#8377;60') && str_contains($r['body'], 'Cash / UPI received by you (1)'), 'online Rs 120 and cash Rs 60 are split');
T::ok(str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'Bus Stand Lot') && str_contains($r['body'], 'Day Garage') && str_contains($r['body'], 'By day') && str_contains($r['body'], 'By month'), 'per-place, per-day and per-month tables are present');
$r = req('GET', 'admin_earnings.php', [], $rival);
T::ok($r['status'] === 200 && !str_contains($r['body'], '&#8377;180') && !str_contains($r['body'], 'Shahu Garage') && str_contains($r['body'], 'No verified payments'), 'rival sees none of the owner\'s money');
foreach (['today', 'month', 'lastmonth', 'year'] as $preset) {
    T::same(200, req('GET', "admin_earnings.php?p=$preset", [], $owner)['status'], "preset $preset renders");
}
$r = req('GET', 'admin_earnings.php?p=lastmonth', [], $owner);
T::ok(str_contains($r['body'], 'No verified payments'), 'last month has no payments for this test data');
$r = req('GET', 'admin_earnings.php?from=2026-13-01&to=nonsense', [], $owner);
T::ok($r['status'] === 200 && str_contains($r['body'], 'valid date range'), 'an invalid range shows an error and falls back to this month');
$r = req('GET', 'admin_earnings.php?from=' . $real->format('Y-m-d') . '&to=' . $real->format('Y-m-d'), [], $owner);
T::ok(str_contains($r['body'], '&#8377;180'), 'a custom single-day range works');
// an unpaid reservation is shown as "awaiting", never as earned
$tokU2 = token($u2, "reserve.php?location=$L2");
req('POST', 'reserve.php', ['csrf_token' => $tokU2, 'location' => $L2, 'start' => $t(19), 'end' => $t(20), 'car_number' => 'MH09BB2222'], $u2);
$r = req('GET', 'admin_earnings.php', [], $owner);
T::ok(str_contains($r['body'], '&#8377;180') && str_contains($r['body'], 'Awaiting payment (1)'), 'a new unpaid reservation appears under "awaiting payment" and does not change earnings');


/* ===================================================================== */
T::group('Payment methods per place (owner settings, enforced on the server)');
$notifyLog = "$tmp/notify.log";
$tokO3 = token($owner, 'admin_locations.php');
$mk = static function (string $name, array $modes) use ($owner, $tokO3): int {
    $post = ['csrf_token' => $tokO3, 'action' => 'save', 'id' => '0', 'name' => $name, 'kind' => 'private', 'city' => 'Kolhapur', 'address' => '7 Test Lane, Kolhapur',
        'description' => '', 'capacity' => '3', 'hourly_rate' => '30', 'lat' => '16.7050', 'lng' => '74.2433', 'open_from' => '', 'open_until' => '', 'pay_modes_present' => '1'] + $modes;
    $r = req('POST', 'admin_locations.php', $post, $owner);
    return (int) val('SELECT id FROM parking_locations WHERE name = ?', [$name]);
};
$PO = $mk('Online Only Lot', ['pay_online' => '1']);
$PC = $mk('Cash Only Lot', ['pay_cash' => '1']);
$PB = $mk('Both Ways Lot', ['pay_online' => '1', 'pay_cash' => '1']);
T::same([1, 0], [(int) val('SELECT accepts_online FROM parking_locations WHERE id = ?', [$PO]), (int) val('SELECT accepts_cash FROM parking_locations WHERE id = ?', [$PO])], 'owner saves a place that accepts online payments only');
T::same([0, 1], [(int) val('SELECT accepts_online FROM parking_locations WHERE id = ?', [$PC]), (int) val('SELECT accepts_cash FROM parking_locations WHERE id = ?', [$PC])], 'owner saves a place that accepts cash only');
$r = req('POST', 'admin_locations.php', ['csrf_token' => $tokO3, 'action' => 'save', 'id' => '0', 'name' => 'No Way To Pay', 'kind' => 'private', 'city' => 'Kolhapur', 'address' => '8 Test Lane, Kolhapur',
    'description' => '', 'capacity' => '1', 'hourly_rate' => '30', 'lat' => '', 'lng' => '', 'open_from' => '', 'open_until' => '', 'pay_modes_present' => '1'], $owner);
T::ok(str_contains($r['body'], 'at least one way for customers to pay') && (int) val("SELECT COUNT(*) FROM parking_locations WHERE name = 'No Way To Pay'") === 0, 'a place with no payment method at all is refused');
$r = req('GET', "admin_locations.php?edit=$PO", [], $owner);
T::ok(str_contains($r['body'], 'name="pay_online" value="1" checked') && !str_contains($r['body'], 'name="pay_cash" value="1" checked'), 'the edit form shows the saved methods');
$tokR3 = token($rival, 'admin_locations.php');
req('POST', 'admin_locations.php', ['csrf_token' => $tokR3, 'action' => 'save', 'id' => (string) $PO, 'name' => 'Online Only Lot', 'kind' => 'private', 'city' => 'Kolhapur', 'address' => '7 Test Lane, Kolhapur',
    'description' => '', 'capacity' => '3', 'hourly_rate' => '30', 'lat' => '', 'lng' => '', 'open_from' => '', 'open_until' => '', 'pay_modes_present' => '1', 'pay_cash' => '1'], $rival);
T::same([1, 0], [(int) val('SELECT accepts_online FROM parking_locations WHERE id = ?', [$PO]), (int) val('SELECT accepts_cash FROM parking_locations WHERE id = ?', [$PO])], "another owner cannot change this place's payment methods");
$r = req('GET', 'find_parking.php?kind=private&city=Kolhapur');
T::ok(str_contains($r['body'], 'Online Only Lot') && str_contains($r['body'], 'Pay: online (UPI, card, net banking)</div>') && str_contains($r['body'], 'Pay: at the place</div>') && str_contains($r['body'], 'Pay: online (UPI, card, net banking) or at the place</div>'), 'Find parking shows the accepted methods of each place');

$d = static fn (int $day, int $h): string => $real->modify("+$day days")->setTime($h, 0)->format('Y-m-d\TH:i');
$reserveAt = static function (array $who, int $loc, string $s, string $e, string $car, array $extra = []) use ($real): int {
    $tk = token($who, "reserve.php?location=$loc");
    $r = req('POST', 'reserve.php', ['csrf_token' => $tk, 'location' => $loc, 'start' => $s, 'end' => $e, 'car_number' => $car] + $extra, $who);
    return preg_match('#booking=(\d+)#', $r['location'], $m) === 1 ? (int) $m[1] : 0;
};
$bC = $reserveAt($u1, $PC, $d(3, 10), $d(3, 12), 'MH09AA1111');
$bO = $reserveAt($u1, $PO, $d(4, 10), $d(4, 12), 'MH09AA1111');
T::ok($bC > 0 && $bO > 0, 'setup: reservations at the cash-only and the online-only place');
$r = req('GET', "pay.php?booking=$bC", [], $u1);
T::ok(str_contains($r['body'], 'payment at the place only') && str_contains($r['body'], 'Paying cash') && !str_contains($r['body'], 'Pay &#8377;60 online') && !str_contains($r['body'], 'Pay ₹60 online'), 'cash-only place: no online button, cash instructions shown');
$tk = token($u1, "reserve.php?location=$PC");   // the cash-only pay page has no form, so a forged POST borrows a token from another page
$r = req('POST', 'pay.php', ['csrf_token' => $tk, 'booking' => $bC], $u1);
T::ok(str_contains($r['body'], 'does not take online payments') && !str_contains($r['body'], 'checkout.razorpay.com') && (int) val('SELECT COUNT(*) FROM payments WHERE booking_id = ?', [$bC]) === 0, 'forcing an online payment at a cash-only place is refused and creates no payment');
$r = req('GET', "pay.php?booking=$bO", [], $u1);
T::ok(!str_contains($r['body'], 'Paying cash') && str_contains($r['body'], 'online'), 'online-only place: no cash instructions shown');
$tokOR = token($owner, "admin_locations.php");   // the review screen of an online-only place has no form, so a forged POST borrows a token
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tokOR, 'confirm_payment' => (string) $bO, 'method' => 'cash', 'received' => '1'], $owner);
T::ok(str_contains($r['body'], 'online payments only') && val('SELECT status FROM bookings WHERE id = ?', [$bO]) === 'pending_payment' && (int) val('SELECT COUNT(*) FROM payments WHERE booking_id = ?', [$bO]) === 0, 'recording cash at an online-only place is refused on the server');

/* ===================================================================== */
T::group('Ticket confirmation: e-mail, WhatsApp, SMS');
$readLog = static function () use ($notifyLog): array {
    return is_file($notifyLog) ? array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($notifyLog)))) : [];
};
$base0 = count($readLog());
$baseRows = (int) val('SELECT COUNT(*) FROM notifications');
// phone validation
$tk = token($u2, "reserve.php?location=$PB");
$r = req('POST', 'reserve.php', ['csrf_token' => $tk, 'location' => $PB, 'start' => $d(5, 10), 'end' => $d(5, 12), 'car_number' => 'MH09BB2222', 'phone' => '12345', 'notify_whatsapp' => '1'], $u2);
T::ok($r['status'] === 200 && str_contains($r['body'], 'valid mobile number'), 'a bad mobile number is refused when WhatsApp / SMS is chosen');
T::same(0, (int) val("SELECT COUNT(*) FROM bookings WHERE car_number = 'MH09BB2222' AND location_id = ?", [$PB]), '...and no reservation was created');
$bN = $reserveAt($u2, $PB, $d(5, 10), $d(5, 12), 'MH09BB2222', ['phone' => '98765 43210', 'notify_whatsapp' => '1', 'notify_sms' => '1']);
$bk = row('SELECT * FROM bookings WHERE id = ?', [$bN]);
T::ok($bk['contact_phone'] === '+919876543210' && $bk['notify_via'] === 'whatsapp,sms', 'the number is stored in international form with the chosen channels');
T::same($base0, count($readLog()), 'nothing is sent for an unpaid reservation');
T::same($baseRows, (int) val('SELECT COUNT(*) FROM notifications'), '...and no delivery rows exist yet');
// failed + forged + authorized payments send nothing
$tk = token($u2, "pay.php?booking=$bN");
req('POST', 'pay.php', ['csrf_token' => $tk, 'booking' => $bN], $u2);
$ord = (string) val('SELECT provider_order_id FROM payments WHERE booking_id = ?', [$bN]);
req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_NTF00001', 'razorpay_order_id' => $ord, 'razorpay_signature' => str_repeat('c', 64)]);
gateway_set_payment('pay_NTF00001', $ord, 6000, 'failed');
req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_NTF00001', 'razorpay_order_id' => $ord, 'razorpay_signature' => sign($ord, 'pay_NTF00001')]);
gateway_set_payment('pay_NTF00001', $ord, 6000, 'authorized');
req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_NTF00001', 'razorpay_order_id' => $ord, 'razorpay_signature' => sign($ord, 'pay_NTF00001')]);
T::same($base0, count($readLog()), 'forged signature, failed and merely-authorized payments send no ticket');
// success through the verified callback
gateway_set_payment('pay_NTF00001', $ord, 6000, 'captured');
$cb = ['razorpay_payment_id' => 'pay_NTF00001', 'razorpay_order_id' => $ord, 'razorpay_signature' => sign($ord, 'pay_NTF00001')];
$r = req('POST', 'payment_callback.php', $cb);
T::ok(str_contains($r['location'], 'pay=confirmed'), 'verified callback confirms the reservation');
$log = array_slice($readLog(), $base0);
$by = [];
foreach ($log as $m) { $by[$m['channel']] = $m; }
T::same(['email', 'sms', 'whatsapp'], (function () use ($by) { $k = array_keys($by); sort($k); return $k; })(), 'e-mail, WhatsApp and SMS were each sent once');
T::same('u2@example.com', $by['email']['to'] ?? '', 'the e-mail goes to the customer\'s account address');
T::same(['+919876543210', '+919876543210'], [$by['sms']['to'] ?? '', $by['whatsapp']['to'] ?? ''], 'WhatsApp and SMS go to the number given');
$all = json_encode($log);
T::ok(str_contains($by['email']['text'], 'Both Ways Lot') && str_contains($by['email']['text'], 'MH09BB2222') && str_contains($by['email']['text'], 'Rs 60') && str_contains($by['email']['text'], 'Paid online') && str_contains($by['email']['text'], "Reservation no: $bN") && preg_match('#booking_confirmed\.php\?id=' . $bN . '&t=[0-9a-f]{64}#', $by['email']['text']) === 1, 'the e-mail has place, car, amount, payment status, reference and the signed ticket link');
T::ok(str_contains($by['sms']['text'], 'Both Ways Lot') && str_contains($by['sms']['text'], 'Rs 60') && str_contains($by['sms']['text'], "Ref #$bN"), 'the SMS / WhatsApp text carries the key details');
T::ok(!str_contains($all, 'test_key_secret') && !str_contains($all, 'test_webhook_secret') && !str_contains($all, 'pay_NTF00001') && !str_contains($all, $ord), 'no secret, payment id or gateway order id appears in any message');
T::same(['sent', 'sent', 'sent'], array_column(q_all($pdo, 'SELECT status FROM notifications WHERE booking_id = ? ORDER BY channel', [$bN]), 'status'), 'delivery is recorded as sent for all three channels');
// retried callback + duplicate webhook: still exactly one message per channel
req('POST', 'payment_callback.php', $cb);
req('POST', 'payment_callback.php', $cb);
$ev = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_NTF00001', 'order_id' => $ord, 'amount' => 6000, 'currency' => 'INR', 'status' => 'captured']]]]);
$wh = static fn (string $id): array => ['raw' => $ev, 'headers' => ['Content-Type: application/json', 'X-Razorpay-Signature: ' . hash_hmac('sha256', $ev, 'test_webhook_secret'), "X-Razorpay-Event-Id: $id"]];
req('POST', 'payment_webhook.php', [], $wh('evt_n1'));
req('POST', 'payment_webhook.php', [], $wh('evt_n1'));
req('POST', 'payment_webhook.php', [], $wh('evt_n2'));
T::same($base0 + 3, count($readLog()), 'repeated callbacks and duplicate webhooks send NO second message');
T::same(3, (int) val('SELECT COUNT(*) FROM notifications WHERE booking_id = ?', [$bN]), '...and create no extra delivery rows');

// provider outage: payment and booking stay correct, failure recorded, retry is safe and sends only what failed
file_put_contents("$tmp/notify.fail", 'whatsapp,sms');
$bF = $reserveAt($u1, $PB, $d(6, 10), $d(6, 11), 'MH09AA1111', ['phone' => '9123456789', 'notify_whatsapp' => '1', 'notify_sms' => '1']);
$tk = token($u1, "pay.php?booking=$bF");
req('POST', 'pay.php', ['csrf_token' => $tk, 'booking' => $bF], $u1);
$ordF = (string) val('SELECT provider_order_id FROM payments WHERE booking_id = ?', [$bF]);
$evF = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_NTF00002', 'order_id' => $ordF, 'amount' => 3000, 'currency' => 'INR', 'status' => 'captured']]]]);
$whF = static fn (string $id): array => ['raw' => $evF, 'headers' => ['Content-Type: application/json', 'X-Razorpay-Signature: ' . hash_hmac('sha256', $evF, 'test_webhook_secret'), "X-Razorpay-Event-Id: $id"]];
$r = req('POST', 'payment_webhook.php', [], $whF('evt_f1'));
T::ok($r['status'] === 200 && val('SELECT status FROM bookings WHERE id = ?', [$bF]) === 'confirmed' && val('SELECT status FROM payments WHERE booking_id = ?', [$bF]) === 'succeeded', 'provider outage: the webhook still answers 200 and the booking and payment are correct');
$rows = [];
foreach (q_all($pdo, 'SELECT channel, status, attempts, last_error FROM notifications WHERE booking_id = ?', [$bF]) as $x) { $rows[$x['channel']] = $x; }
T::same(['sent', 'failed', 'failed'], [$rows['email']['status'] ?? '', $rows['whatsapp']['status'] ?? '', $rows['sms']['status'] ?? ''], 'e-mail sent; WhatsApp and SMS recorded as failed');
T::ok(str_contains((string) $rows['whatsapp']['last_error'], 'simulated provider outage') && (int) $rows['whatsapp']['attempts'] === 1, 'the failure reason and attempt count are stored');
unlink("$tmp/notify.fail");
$before = count($readLog());
req('POST', 'payment_webhook.php', [], $whF('evt_f2'));   // gateway retries later
$rows = [];
foreach (q_all($pdo, 'SELECT channel, status, attempts FROM notifications WHERE booking_id = ?', [$bF]) as $x) { $rows[$x['channel']] = $x; }
T::same(['sent', 'sent', 'sent'], [$rows['email']['status'], $rows['whatsapp']['status'], $rows['sms']['status']], 'the retry delivers the failed channels');
T::same($before + 2, count($readLog()), '...and sends exactly two more messages (the e-mail is not repeated)');
T::same(1, (int) $rows['email']['attempts'], 'the e-mail was attempted only once');

// cash confirmation by the owner: e-mail only (no number given), labelled as cash
$bCash = $reserveAt($u2, $PB, $d(7, 10), $d(7, 12), 'MH09BB2222');
$before = count($readLog());
$tokOR = token($owner, "admin_reservations.php?confirm=$bCash");
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tokOR, 'confirm_payment' => (string) $bCash, 'method' => 'cash', 'received' => '1'], $owner);
T::ok($r['status'] === 302 && val('SELECT status FROM bookings WHERE id = ?', [$bCash]) === 'confirmed', 'owner records cash: reservation confirmed');
$new = array_slice($readLog(), $before);
T::ok(count($new) === 1 && $new[0]['channel'] === 'email' && str_contains($new[0]['text'], 'Paid in cash at the place'), 'cash confirmation: one e-mail, labelled as paid in cash');
req('POST', 'admin_reservations.php', ['csrf_token' => $tokOR, 'confirm_payment' => (string) $bCash, 'method' => 'cash', 'received' => '1'], $owner);
T::same($before + 1, count($readLog()), 'a second click on "record cash" sends nothing more');

// unpaid, expired and cancelled reservations never produce a message
$before = count($readLog());
$bU = $reserveAt($u1, $PB, $d(8, 10), $d(8, 11), 'MH09AA1111', ['phone' => '9123456789', 'notify_sms' => '1']);
$tk = token($u1, 'my_dashboard.php');
req('POST', 'my_dashboard.php', ['csrf_token' => $tk, 'cancel_booking' => (string) $bU], $u1);
T::same($before, count($readLog()), 'a cancelled / unpaid reservation sends nothing');
T::same([], deliver_booking_confirmation($pdo, $bU, $real) === ['email' => 'not_confirmed'] ? [] : ['unexpected'], 'delivering for an unconfirmed booking is refused by the engine itself');

// a channel that is not configured is skipped, never an error
file_put_contents("$tmp/notify.fail.off", 'sms');
$bS = $reserveAt($u2, $PB, $d(9, 10), $d(9, 11), 'MH09BB2222', ['phone' => '9123456780', 'notify_sms' => '1']);
$tk = token($u2, "pay.php?booking=$bS");
req('POST', 'pay.php', ['csrf_token' => $tk, 'booking' => $bS], $u2);
$ordS = (string) val('SELECT provider_order_id FROM payments WHERE booking_id = ?', [$bS]);
gateway_set_payment('pay_NTF00003', $ordS, 3000, 'captured');
$r = req('POST', 'payment_callback.php', ['razorpay_payment_id' => 'pay_NTF00003', 'razorpay_order_id' => $ordS, 'razorpay_signature' => sign($ordS, 'pay_NTF00003')]);
$st = [];
foreach (q_all($pdo, 'SELECT channel, status, last_error FROM notifications WHERE booking_id = ?', [$bS]) as $x) { $st[$x['channel']] = $x; }
T::ok(str_contains($r['location'], 'pay=confirmed') && ($st['email']['status'] ?? '') === 'sent' && ($st['sms']['status'] ?? '') === 'skipped' && str_contains((string) $st['sms']['last_error'], 'not configured'), 'SMS not configured: recorded as skipped, booking and e-mail unaffected');
unlink("$tmp/notify.fail.off");

/* ===================================================================== */
T::group('Owner confirms Cash / UPI received: review, guards, ticket, messages, retry');
$ownerPost = static function (int $bid, array $extra = [], ?array $who = null, ?string $tokenFrom = null) use ($owner): array {
    $who = $who ?? $owner;
    $tk = token($who, $tokenFrom ?? "admin_reservations.php?confirm=$bid");
    if ($tk === '') { $tk = token($who, 'admin_locations.php'); }
    return req('POST', 'admin_reservations.php', ['csrf_token' => $tk, 'confirm_payment' => (string) $bid] + $extra, $who);
};
$payCount = static fn (int $bid): int => (int) val("SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'succeeded'", [$bid]);
$bUpi = $reserveAt($u2, $PB, $d(12, 10), $d(12, 12), 'MH09UP0001', ['phone' => '9876501234', 'notify_whatsapp' => '1', 'notify_sms' => '1']);
$cost = (int) val('SELECT cost FROM bookings WHERE id = ?', [$bUpi]);
$before = count($readLog());
$r = req('GET', 'admin_reservations.php', [], $owner);
T::ok(str_contains($r['body'], 'Confirm Cash/UPI Payment') && str_contains($r['body'], 'Awaiting payment'), 'admin list: unpaid reservation shows "Awaiting payment" and the Confirm Cash/UPI Payment action');
$r = req('GET', "admin_reservations.php?confirm=$bUpi", [], $owner);
T::ok(str_contains($r['body'], "reservation $bUpi") && str_contains($r['body'], 'MH09UP0001') && str_contains($r['body'], 'Both Ways Lot') && str_contains($r['body'], '&#8377;' . $cost) && str_contains($r['body'], 'UPI received by owner') && str_contains($r['body'], 'name="received"'), 'review screen shows number, vehicle, place, amount, both methods and the "money received" checkbox');
$r = $ownerPost($bUpi, ['method' => 'upi']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'actually received') && $payCount($bUpi) === 0, 'without the "I received the money" tick nothing is recorded');
$r = $ownerPost($bUpi, ['received' => '1']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Choose how') && $payCount($bUpi) === 0, 'without choosing cash or UPI nothing is recorded');
$r = $ownerPost($bUpi, ['method' => 'bitcoin', 'received' => '1']);
T::ok($payCount($bUpi) === 0, 'an invented payment method is refused');
$r = $ownerPost($bUpi, ['method' => 'upi', 'received' => '1', 'amount' => '1']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'does not match') && $payCount($bUpi) === 0 && val('SELECT status FROM bookings WHERE id = ?', [$bUpi]) === 'pending_payment', 'a client-supplied wrong amount is refused; nothing recorded');
$r = $ownerPost($bUpi, ['method' => 'upi', 'received' => '1', 'amount' => (string) $cost], $rival);
T::ok(str_contains($r['body'], 'not found') && $payCount($bUpi) === 0, 'a different owner cannot confirm it');
$r = req('GET', "admin_reservations.php?confirm=$bUpi", [], $rival);
T::ok(!str_contains($r['body'], 'MH09UP0001') && !str_contains($r['body'], 'u2@example.com'), 'a different owner cannot even see its review screen');
T::same($before, count($readLog()), 'no ticket message was sent before the payment');
$r = $ownerPost($bUpi, ['method' => 'upi', 'received' => '1', 'amount' => (string) $cost]);
T::ok($r['status'] === 302 && str_contains($r['location'], 'paid=') && val('SELECT status FROM bookings WHERE id = ?', [$bUpi]) === 'confirmed', 'valid UPI-to-owner confirmation: reservation confirmed');
$pr = row('SELECT * FROM payments WHERE booking_id = ?', [$bUpi]);
T::ok($pr['provider'] === 'upi_owner' && $pr['verification'] === 'admin_upi' && $pr['recorded_by'] === 'owner' && (int) $pr['amount'] === $cost && $pr['verified_at'] !== null && $pr['provider_payment_id'] === null, 'payment row: UPI received by owner, recorded by "owner", stored amount, timestamp; not a gateway payment');
$new = array_slice($readLog(), $before);
$by = [];
foreach ($new as $m) { $by[$m['channel']] = $m; }
T::same(['email', 'sms', 'whatsapp'], (function () use ($by) { $k = array_keys($by); sort($k); return $k; })(), 'e-mail, WhatsApp and SMS were each sent once after the confirmation');
T::ok(str_contains($by['email']['text'], 'UPI received by owner') && str_contains($by['email']['text'], 'MH09UP0001') && str_contains($by['email']['text'], "Reservation no: $bUpi") && !str_contains($by['email']['text'], 'razorpay'), 'the message says "UPI received by owner" and carries reference, vehicle');
$r = $ownerPost($bUpi, ['method' => 'upi', 'received' => '1', 'amount' => (string) $cost]);
T::ok($r['status'] === 200 && str_contains($r['body'], 'already recorded') && $payCount($bUpi) === 1 && count($readLog()) === $before + 3, 'a second click: refused, still one payment, no further messages');
// customer sees it; others do not
$r = req('GET', 'my_dashboard.php', [], $u2);
T::ok(str_contains($r['body'], 'UPI received by owner') && preg_match('#booking_confirmed\.php\?id=' . $bUpi . '&amp;t=[0-9a-f]{64}#', $r['body']) === 1, 'the customer\'s dashboard shows the payment method and the ticket link');
$r = req('GET', 'my_dashboard.php', [], $u1);
T::ok(!str_contains($r['body'], 'MH09UP0001'), "another customer's dashboard does not show it");
$tok = ticket_token($bUpi);
T::same(404, req('GET', "booking_confirmed.php?id=$bUpi", [])['status'], 'ticket without the signed token -> 404');
T::same(404, req('GET', 'booking_confirmed.php?id=' . ($bUpi + 1) . '&t=' . $tok, [])['status'], 'changing the booking id while keeping the token -> 404');
// earnings: counted once
$sumB = (int) val("SELECT SUM(amount) FROM payments WHERE booking_id = ? AND status = 'succeeded'", [$bUpi]);
T::same($cost, $sumB, 'earnings basis: the payment is stored once, for the reservation amount');

// expired hold: refused with an explanation, nothing recorded or sent
$bEx = $reserveAt($u1, $PB, $d(13, 10), $d(13, 11), 'MH09EX0001', ['phone' => '9123456789', 'notify_sms' => '1']);
$pdo->exec("UPDATE bookings SET hold_expires_at = '2000-01-01 00:00:00' WHERE id = $bEx");
$before = count($readLog());
$r = req('GET', 'admin_reservations.php', [], $owner);
T::ok(!str_contains($r['body'], "admin_reservations.php?confirm=$bEx\""), 'an expired reservation has no Confirm action in the list');
$r = req('GET', "admin_reservations.php?confirm=$bEx", [], $owner);
T::ok(str_contains($r['body'], 'hold on this reservation has ended') && !str_contains($r['body'], 'name="received"'), 'its review screen explains why it cannot be confirmed and offers no form');
$r = $ownerPost($bEx, ['method' => 'cash', 'received' => '1', 'amount' => (string) (int) val('SELECT cost FROM bookings WHERE id = ?', [$bEx])]);
T::ok($r['status'] === 200 && str_contains($r['body'], 'hold on this reservation has ended') && str_contains($r['body'], 'refund') && $payCount($bEx) === 0 && val('SELECT status FROM bookings WHERE id = ?', [$bEx]) !== 'confirmed', 'a forced POST for an expired reservation is refused with instructions; nothing recorded');
T::same($before, count($readLog()), '...and nothing is sent');

// provider failure at confirmation, then the owner retries
file_put_contents("$tmp/notify.fail", 'email,sms');
$bRt = $reserveAt($u1, $PB, $d(14, 10), $d(14, 12), 'MH09RT0001', ['phone' => '9123456789', 'notify_sms' => '1']);
$costRt = (int) val('SELECT cost FROM bookings WHERE id = ?', [$bRt]);
$before = count($readLog());
$r = $ownerPost($bRt, ['method' => 'cash', 'received' => '1', 'amount' => (string) $costRt]);
T::ok($r['status'] === 302 && val('SELECT status FROM bookings WHERE id = ?', [$bRt]) === 'confirmed' && $payCount($bRt) === 1, 'provider outage: the cash payment and the confirmation still stand');
T::same(['failed', 'failed'], array_column(q_all($pdo, 'SELECT status FROM notifications WHERE booking_id = ? ORDER BY channel', [$bRt]), 'status'), 'e-mail and SMS recorded as failed');
$r = req('GET', 'admin_reservations.php', [], $owner);
T::ok(str_contains($r['body'], 'Failed') && str_contains($r['body'], 'simulated provider outage') && str_contains($r['body'], 'Retry messages') && str_contains($r['body'], 'Cash paid at the place'), 'the admin list shows the failure, its reason, a Retry button and "Cash paid at the place"');
unlink("$tmp/notify.fail");
$tkR = token($owner, 'admin_reservations.php');
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tkR, 'retry_notify' => (string) $bRt], $rival);
T::same($before, count($readLog()), 'a different owner cannot trigger a retry');
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tkR, 'retry_notify' => (string) $bRt], $owner);
T::ok($r['status'] === 302 && str_contains($r['location'], 'retried=') && count($readLog()) === $before + 2, 'the owner retries: both messages are now sent');
T::same(['sent', 'sent'], array_column(q_all($pdo, 'SELECT status FROM notifications WHERE booking_id = ? ORDER BY channel', [$bRt]), 'status'), '...and recorded as sent');
req('POST', 'admin_reservations.php', ['csrf_token' => $tkR, 'retry_notify' => (string) $bRt], $owner);
T::same($before + 2, count($readLog()), 'retrying again sends nothing twice');
// not configured -> "Not sent" with the reason; fix and retry
file_put_contents("$tmp/notify.fail.off", 'sms');
$bOff = $reserveAt($u2, $PB, $d(15, 10), $d(15, 11), 'MH09OF0001', ['phone' => '9876501234', 'notify_sms' => '1']);
$ownerPost($bOff, ['method' => 'upi', 'received' => '1', 'amount' => (string) (int) val('SELECT cost FROM bookings WHERE id = ?', [$bOff])]);
$r = req('GET', 'admin_reservations.php', [], $owner);
T::ok(str_contains($r['body'], 'Not sent') && str_contains($r['body'], 'channel not configured'), 'unconfigured channel: shown to the admin as "Not sent" with the reason');
unlink("$tmp/notify.fail.off");
$before = count($readLog());
$tkR = token($owner, 'admin_reservations.php');
req('POST', 'admin_reservations.php', ['csrf_token' => $tkR, 'retry_notify' => (string) $bOff], $owner);
$new = array_slice($readLog(), $before);
T::ok(count($new) === 1 && $new[0]['channel'] === 'sms', 'after fixing the configuration, Retry sends only the missing SMS (e-mail not repeated)');
// retry for an unpaid reservation
$bUn = $reserveAt($u1, $PB, $d(16, 10), $d(16, 11), 'MH09UN0001', ['phone' => '9123456789', 'notify_sms' => '1']);
$before = count($readLog());
$r = req('POST', 'admin_reservations.php', ['csrf_token' => $tkR, 'retry_notify' => (string) $bUn], $owner);
T::ok(str_contains($r['body'], 'only sent for confirmed') && count($readLog()) === $before, 'retry for an unpaid reservation sends nothing');
// customer pay page for a place that takes payment at the place
$r = req('GET', "pay.php?booking=$bUn", [], $u1);
T::ok(str_contains($r['body'], 'awaiting payment') && str_contains($r['body'], 'Pay the owner in person') && str_contains($r['body'], '&#8377;'), 'pay page: pending status, amount and instructions to pay the owner in person');
T::ok(str_contains($r['body'], 'only after the owner records') && str_contains($r['body'], 'my_dashboard.php') && !str_contains($r['body'], 'I have paid'), 'pay page: confirmation only after the owner records it, dashboard link, no self-confirm button');

T::group('No PHP errors were logged by any page');
$log = (string) file_get_contents($stderrFile);
preg_match_all('/PHP (Warning|Notice|Fatal error|Deprecated|Parse error)[^\n]*/', $log, $bad);
T::same([], $bad[0], 'no PHP warnings / notices / fatal errors in the server log');
preg_match_all('/\[parkaro\] \w+(Exception|Error): [^\n]*/', $log, $ex);
T::same([], $ex[0], 'no uncaught exceptions in the server log');

T::finish();
