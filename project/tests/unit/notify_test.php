<?php
/** Run:  php tests/unit/notify_test.php     (no database, no internet; SMTP is tested against a local fake server) */
declare(strict_types=1);

putenv('APP_KEY=notify-test');
require __DIR__ . '/../TestCase.php';
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/notify.php';

T::group('phone numbers');
foreach ([['9876543210', '+919876543210'], ['98765 43210', '+919876543210'], ['09876543210', '+919876543210'], ['+91 98765-43210', '+919876543210'], ['919876543210', '+919876543210'],
          ['+14155238886', '+14155238886'], ['1234567890', null], ['12345', null], ['', null], ['abcdefghij', null], ['+0123456789', null], ['98765432101', null], ['+91' . str_repeat('9', 20), null]] as [$in, $exp]) {
    T::same($exp, normalize_phone($in), "normalize_phone('$in')");
}

T::group('channels');
T::same(['email'], parse_notify_via(null), 'no choice = e-mail only');
T::same(['email', 'whatsapp', 'sms'], parse_notify_via('whatsapp,sms'), 'e-mail is always included');
T::same(['email', 'sms'], parse_notify_via('sms,telegram,sms'), 'unknown channels are dropped, duplicates removed');

T::group('Twilio requests (HTTP faked)');
$calls = [];
$http = static function (string $m, string $url, array $form, string $u, string $p) use (&$calls): array { $calls[] = compact('m', 'url', 'form', 'u', 'p'); return [201, '{"sid":"SM1"}']; };
$conf = ['TWILIO_ACCOUNT_SID' => 'ACtest', 'TWILIO_AUTH_TOKEN' => 'tok', 'TWILIO_SMS_FROM' => '+15005550006', 'TWILIO_WHATSAPP_FROM' => 'whatsapp:+14155238886'];
$t = new EnvNotifyTransport($http, $conf);
T::ok($t->isConfigured('sms') && $t->isConfigured('whatsapp') && !$t->isConfigured('email'), 'configured channels are detected from the settings');
$t->send('sms', '+919876543210', 's', 'hello', '');
T::same('https://api.twilio.com/2010-04-01/Accounts/ACtest/Messages.json', $calls[0]['url'], 'SMS goes to the Twilio Messages endpoint of the account');
T::same(['To' => '+919876543210', 'Body' => 'hello', 'From' => '+15005550006'], $calls[0]['form'], 'SMS form: To, Body, From');
T::same(['ACtest', 'tok'], [$calls[0]['u'], $calls[0]['p']], 'HTTP basic auth with the account SID and token');
$t->send('whatsapp', '+919876543210', 's', 'hello', '');
T::same(['To' => 'whatsapp:+919876543210', 'Body' => 'hello', 'From' => 'whatsapp:+14155238886'], $calls[1]['form'], 'WhatsApp numbers are prefixed with "whatsapp:"');
$t2 = new EnvNotifyTransport($http, $conf + ['TWILIO_WHATSAPP_CONTENT_SID' => 'HXabc']);
$t2->send('whatsapp', '+919876543210', 's', 'hello', '', ['1' => 'Asha', '2' => 'Lot']);
T::ok(!isset($calls[2]['form']['Body']) && $calls[2]['form']['ContentSid'] === 'HXabc' && json_decode($calls[2]['form']['ContentVariables'], true) === ['1' => 'Asha', '2' => 'Lot'], 'with a template id, WhatsApp sends the approved template and its variables');
$t3 = new EnvNotifyTransport($http, ['TWILIO_ACCOUNT_SID' => 'AC', 'TWILIO_AUTH_TOKEN' => 't', 'TWILIO_SMS_FROM' => 'MGservice1']);
$t3->send('sms', '+919876543210', 's', 'hi', '');
T::ok(($calls[3]['form']['MessagingServiceSid'] ?? '') === 'MGservice1' && !isset($calls[3]['form']['From']), 'a Messaging Service SID is sent as MessagingServiceSid');
foreach (['9876543210', '+91abc', "+91987654321\n0", 'whatsapp:+91'] as $bad) {
    $threw = false;
    try { $t->send('sms', $bad, 's', 'x', ''); } catch (NotifyException $e) { $threw = true; }
    T::ok($threw, 'an invalid number is refused before any request: ' . json_encode($bad));
}
T::same(4, count($calls), '...and no request was made for them');
$tf = new EnvNotifyTransport(static fn () => [401, '{"message":"Authenticate"}'], $conf);
$msg = '';
try { $tf->send('sms', '+919876543210', 's', 'x', ''); } catch (NotifyException $e) { $msg = $e->getMessage(); }
T::same('Twilio: Authenticate', $msg, 'a provider error becomes a NotifyException with the provider message');
T::ok(!(new EnvNotifyTransport($http, []))->isConfigured('sms'), 'no credentials = not configured');

T::group('SMTP (against a local fake server; STARTTLS/SSL are NOT exercised)');
$port = random_int(41000, 48999);
$tr = sys_get_temp_dir() . '/pk_smtp_' . getmypid() . '.txt';
@unlink($tr);
$proc = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port, $tr], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
fgets($pipes[1]);
$mail = new EnvNotifyTransport(null, ['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port, 'MAIL_SECURE' => 'none', 'MAIL_USER' => 'user@example.com', 'MAIL_PASS' => 'p@ss', 'MAIL_FROM' => 'noreply@example.com', 'MAIL_FROM_NAME' => 'Parkaro']);
T::ok($mail->isConfigured('email'), 'e-mail counts as configured with host + from address');
$mail->send('email', "cust@example.com", "Parking confirmed: Lot\r\nBcc: evil@example.com", "Line one\n.dot line\nLast", '<p>Hi</p>');
proc_close($proc);
$log = (string) file_get_contents($tr);
T::ok(str_contains($log, 'C: AUTH LOGIN') && str_contains($log, 'C: ' . base64_encode('user@example.com')) && str_contains($log, 'C: ' . base64_encode('p@ss')), 'logs in with AUTH LOGIN using the .env user and password');
T::ok(str_contains($log, 'C: MAIL FROM:<noreply@example.com>') && str_contains($log, 'C: RCPT TO:<cust@example.com>'), 'envelope sender and recipient are correct');
T::ok(!str_contains($log, "\nC: Bcc:") && !preg_match('/^C: Bcc:/m', $log), 'a header-injection attempt in the subject does not create a Bcc header');
T::ok(str_contains($log, 'S: 250 queued') && str_contains($log, 'C: QUIT'), 'the server accepted the message and the client quit cleanly');
T::ok(str_contains($log, 'Content-Type: multipart/alternative') && str_contains($log, 'text/plain') && str_contains($log, 'text/html'), 'the message has a plain-text and an HTML part');
@unlink($tr);
// wrong password: error, never silent
$port2 = random_int(41000, 48999);
$proc = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', (string) $port2, $tr, 'reject-auth'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
fgets($pipes[1]);
$bad = new EnvNotifyTransport(null, ['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => (string) $port2, 'MAIL_SECURE' => 'none', 'MAIL_USER' => 'u@example.com', 'MAIL_PASS' => 'wrong', 'MAIL_FROM' => 'noreply@example.com']);
$msg = '';
try { $bad->send('email', 'cust@example.com', 's', 't', 'h'); } catch (NotifyException $e) { $msg = $e->getMessage(); }
proc_close($proc);
T::ok(str_contains($msg, 'SMTP error 535'), 'rejected credentials raise a clear error: ' . $msg);
foreach (['not-an-email', "a@b.com\r\nRCPT TO:<x@y.com>"] as $badTo) {
    $threw = false;
    try { $mail->send('email', $badTo, 's', 't', 'h'); } catch (NotifyException $e) { $threw = true; }
    T::ok($threw, 'an invalid recipient is refused before connecting: ' . json_encode($badTo));
}
$down = new EnvNotifyTransport(null, ['MAIL_HOST' => '127.0.0.1', 'MAIL_PORT' => '1', 'MAIL_SECURE' => 'none', 'MAIL_FROM' => 'a@example.com']);
$threw = false;
try { $down->send('email', 'c@example.com', 's', 't', 'h'); } catch (NotifyException $e) { $threw = true; }
T::ok($threw, 'an unreachable mail server raises an error (the engine records it as failed)');

T::group('message content');
$b = ['id' => 42, 'name' => 'Asha <b>Patil</b>', 'car_number' => 'MH09AB1234', 'cost' => 90, 'start_time' => '2026-11-01 10:00:00', 'end_time' => '2026-11-01 13:00:00', 'location_name' => 'Shahu "Garage"'];
$m = build_ticket_message($b, ['address' => '1 Road', 'city' => 'Kolhapur'], 'https://x.test/booking_confirmed.php?id=42&t=abc', 'https://x.test/generate_ticket.php?id=42&t=abc', 'https://www.google.com/maps/dir/?api=1&destination=1%2C2', 'Paid online');
T::ok(str_contains($m['text'], 'Reservation no: 42') && str_contains($m['text'], 'Rs 90') && str_contains($m['text'], 'Paid online') && str_contains($m['text'], '01 Nov 2026, 10:00') && str_contains($m['text'], 'MH09AB1234'), 'text: reference, amount, status, time, car');
T::ok(!str_contains($m['html'], '<b>Patil</b>') && str_contains($m['html'], 'Asha &lt;b&gt;Patil&lt;/b&gt;') && str_contains($m['html'], 'Shahu &quot;Garage&quot;'), 'HTML part escapes customer-controlled text');
T::same(1, array_key_first($m['vars']), 'template variables are numbered from 1');
T::ok(count($m['vars']) === 8 && $m['vars']['7'] === '42', 'eight template variables, the 7th is the reference');
T::finish();
