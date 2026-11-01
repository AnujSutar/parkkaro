<?php
/** Run:  php tests/unit/run.php     (needs no database) */
declare(strict_types=1);

require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/booking.php';
require __DIR__ . '/../../src/users.php';

$locs = booking_locations('kolhapur');
$good = ['name' => 'Anuj Sutar', 'email' => 'anuj@example.com', 'car_number' => 'MH 09 AB 1234', 'hours' => '3', 'location' => 'Kolhapur Market Parking'];

T::group('Booking validation - normal case');
[$err, $c] = validate_booking_input($good, $locs, 20);
T::same([], $err, 'valid input has no errors');
T::same('MH09AB1234', $c['car_number'], 'car number is normalised');
T::same(60, $c['cost'], 'cost = hours x server rate (3 x 20)');
T::same('Zone I', $c['admin_zone'], 'zone comes from the server catalogue');
T::same('16.7075', $c['lat'], 'latitude comes from the catalogue');

T::group('Booking validation - hours boundaries');
foreach ([['1', true], ['24', true], ['0', false], ['25', false], ['-1', false], ['abc', false], ['1.5', false], ['', false], ['3 ', true]] as [$h, $okExpected]) {
    [$err] = validate_booking_input(['hours' => $h] + $good, $locs, 20);
    T::same($okExpected, $err === [], "hours='$h' " . ($okExpected ? 'accepted' : 'rejected'));
}

T::group('Booking validation - tampering');
[$err, $c] = validate_booking_input(['cost' => '1', 'totalPrice' => '1', 'admin_zone' => 'Zone Z'] + $good, $locs, 20);
T::same(60, $c['cost'], 'a cost sent by the browser is ignored');
T::same('Zone I', $c['admin_zone'], 'a zone sent by the browser is ignored');
[$err] = validate_booking_input(['location' => 'Ajara Market Parking'] + $good, $locs, 20);
T::ok($err !== [], 'a location from another city page is rejected');
[$err] = validate_booking_input(['location' => "x' OR '1'='1"] + $good, $locs, 20);
T::ok($err !== [], 'an invented location is rejected');
[$err] = validate_booking_input(['name' => '<script>alert(1)</script>'] + $good, $locs, 20);
T::ok($err !== [], 'HTML in the name is rejected');
[$err] = validate_booking_input(['email' => 'not-an-email'] + $good, $locs, 20);
T::ok($err !== [], 'bad email rejected');
[$err] = validate_booking_input(['email' => str_repeat('a', 250) . '@x.com'] + $good, $locs, 20);
T::ok($err !== [], 'over-long email rejected');
[$err] = validate_booking_input(['car_number' => "MH09'; DROP TABLE bookings;--"] + $good, $locs, 20);
T::ok($err !== [], 'SQL in car number rejected');
[$err] = validate_booking_input(['car_number' => 'AB'] + $good, $locs, 20);
T::ok($err !== [], 'too-short car number rejected');
[$err] = validate_booking_input([], $locs, 20);
T::ok(count($err) >= 5, 'empty form gives an error for every field');

T::group('Location catalogue');
foreach (['kolhapur', 'ajara', 'gadhinglaj', 'form'] as $set) {
    $l = booking_locations($set);
    T::ok(count($l) >= 3 && count(array_unique(array_column($l, 'name'))) === count($l), "$set: names present and unique");
}
try { booking_locations('nope'); T::ok(false, 'unknown set throws'); } catch (InvalidArgumentException $e) { T::ok(true, 'unknown set throws'); }

T::group('Admin "add booking" validation');
$adm = ['name' => 'Walk In', 'email' => 'w@example.com', 'car' => 'MH09AB1234', 'location' => 'Zone A', 'hours' => '2', 'cost' => '40', 'lat' => '', 'lng' => ''];
[$err, $c] = validate_admin_booking_input($adm);
T::same([], $err, 'valid admin input accepted');
T::same('0', $c['lat'], 'empty latitude defaults to 0');
foreach ([['cost', '-5'], ['cost', 'abc'], ['cost', '100001'], ['hours', '0'], ['hours', '25'], ['lat', '91'], ['lng', '181'], ['lat', 'north'], ['email', 'x']] as [$k, $v]) {
    [$err] = validate_admin_booking_input([$k => $v] + $adm);
    T::ok($err !== [], "$k='$v' rejected");
}

T::group('Registration validation');
$reg = ['first_name' => 'Anuj', 'last_name' => 'Sutar', 'email' => 'a@example.com', 'car_number' => 'MH09AB1234', 'password' => 'Secret!1'];
[$err] = validate_registration($reg);
T::same([], $err, 'valid registration accepted');
foreach (['secret!1', 'Secret11', 'Se!1', str_repeat('A!', 40)] as $pw) {
    [$err] = validate_registration(['password' => $pw] + $reg);
    T::ok($err !== [], 'weak/invalid password rejected: ' . (strlen($pw) > 20 ? 'too long' : $pw));
}
[$err] = validate_registration(['email' => "a@example.com'--"] + $reg);
T::ok($err !== [], 'SQL-ish email rejected');

T::group('Admin password check');
$hash = password_hash('S3cret!', PASSWORD_DEFAULT);
T::ok(admin_password_matches('S3cret!', $hash), 'correct password matches a hash');
T::ok(!admin_password_matches('wrong', $hash), 'wrong password does not match a hash');
T::ok(!admin_password_matches($hash, $hash), 'the hash itself is not accepted as a password');
T::ok(admin_password_matches('oldplain', 'oldplain'), 'legacy plaintext row still works until migrated');
T::ok(!admin_password_matches('x', 'oldplain'), 'legacy plaintext mismatch rejected');
T::ok(!admin_password_matches('', ''), 'empty password never matches an empty stored value');
T::ok(!admin_password_matches('', $hash), 'empty input never matches');

T::group('CSRF');
$_SESSION = [];
$token = csrf_token();
T::ok(strlen($token) === 64, 'token is 64 hex characters');
T::ok(csrf_valid($token), 'matching token accepted');
T::ok(!csrf_valid('0' . substr($token, 1)), 'altered token rejected');
T::ok(!csrf_valid(null), 'missing token rejected');
T::ok(!csrf_valid(''), 'empty token rejected');
$_SESSION = [];
T::ok(!csrf_valid($token), 'token from another session rejected');
T::ok(str_contains(csrf_field(), 'name="csrf_token"'), 'csrf_field() renders a hidden input');

T::group('Signed ticket links');
putenv('APP_KEY=unit-test-key');
$t = ticket_token(42);
T::ok(ticket_token_valid(42, $t), 'valid token accepted');
T::ok(!ticket_token_valid(43, $t), 'token for another booking id rejected');
T::ok(!ticket_token_valid(42, strtoupper($t) === $t ? strtolower($t) . 'x' : strtoupper($t)), 'tampered token rejected');
T::ok(!ticket_token_valid(42, null), 'missing token rejected');
T::ok(!ticket_token_valid(42, ''), 'empty token rejected');
T::ok(str_contains(ticket_url('generate_ticket.php', 7), 'id=7&t='), 'ticket_url carries id and token');

T::group('Output escaping');
T::same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'), 'tags and quotes escaped');
T::same('&#039;', e("'"), 'single quote escaped');
T::same('', e(null), 'null becomes empty string');

T::finish();
