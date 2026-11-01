<?php
/**
 * Ticket confirmation messages: e-mail (SMTP), WhatsApp and SMS (Twilio).
 *
 * Rules:
 *  - A message is sent ONLY for a reservation that is already 'confirmed' (i.e. after a verified payment).
 *  - Once per booking and channel: the notifications table has UNIQUE(booking_id, channel) and a row is
 *    claimed BEFORE sending, so a repeated webhook / callback / refresh can never send twice.
 *  - Failures never break payment or confirmation: they are logged (error_log + notifications.last_error)
 *    and retried up to NOTIFY_MAX_ATTEMPTS times the next time deliver is called.
 *  - A channel that is not configured in .env is recorded as 'skipped', not as an error.
 *  - Needs migration 002 (contact_phone, notify_via, notifications). Without it nothing is sent and nothing breaks.
 */
declare(strict_types=1);

require_once __DIR__ . '/dbx.php';
require_once __DIR__ . '/free_parking.php';

const NOTIFY_CHANNELS = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];
const NOTIFY_MAX_ATTEMPTS = 3;

final class NotifyException extends RuntimeException
{
}

interface NotifyTransport
{
    public function isConfigured(string $channel): bool;

    /** @throws NotifyException */
    public function send(string $channel, string $to, string $subject, string $text, string $html, array $vars = []): void;
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Is migration 002 applied? (checked with harmless SELECTs, per connection) */
function notify_schema_ready(PDO $pdo): bool
{
    static $cache = [];
    $key = spl_object_id($pdo);
    if (!isset($cache[$key])) {
        try {
            $pdo->query('SELECT contact_phone, notify_via FROM bookings LIMIT 1')->closeCursor();
            $pdo->query('SELECT booking_id, channel, status FROM notifications LIMIT 1')->closeCursor();
            $pdo->query('SELECT accepts_online, accepts_cash FROM parking_locations LIMIT 1')->closeCursor();
            $cache[$key] = true;
        } catch (Throwable $ex) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/**
 * Normalise a mobile number to E.164. 10-digit Indian numbers (starting 6-9) get +91.
 * Returns null when it does not look like a phone number.
 */
function normalize_phone(string $raw): ?string
{
    $s = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';
    if ($s === '') {
        return null;
    }
    $plus = $s[0] === '+';
    $digits = preg_replace('/\D/', '', $s) ?? '';
    if ($plus) {
        return preg_match('/^\d{8,15}$/', $digits) === 1 && $digits[0] !== '0' ? '+' . $digits : null;
    }
    if (preg_match('/^0?([6-9]\d{9})$/', $digits, $m) === 1) {
        return '+91' . $m[1];
    }
    if (preg_match('/^91([6-9]\d{9})$/', $digits, $m) === 1) {
        return '+91' . $m[1];
    }
    return null;
}

/** 'email,whatsapp' -> ['email','whatsapp'] limited to known channels. E-mail is always included. */
function parse_notify_via(?string $v): array
{
    $out = ['email'];
    foreach (explode(',', (string) $v) as $c) {
        $c = trim($c);
        if (isset(NOTIFY_CHANNELS[$c]) && !in_array($c, $out, true)) {
            $out[] = $c;
        }
    }
    return $out;
}

function notify_base_url(): string
{
    $configured = trim((string) env('APP_BASE_URL', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host) !== 1) {
        $host = 'localhost';
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . $host . rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
}

/** The transport chosen by .env; tests pass one to replace it (so no real network call is ever made). */
function notify_transport(?NotifyTransport $override = null): NotifyTransport
{
    static $injected = null;
    if ($override !== null) {
        $injected = $override;
    }
    return $injected ?? new EnvNotifyTransport();
}

/**
 * Reservations confirmed during this request are queued by confirm_booking_in_tx() (which runs INSIDE a
 * database transaction) and sent by notify_flush() AFTER the transaction has committed.
 */
function notify_queue(?int $bookingId = null, bool $clear = false): array
{
    static $queue = [];
    if ($clear) {
        $out = array_keys($queue);
        $queue = [];
        return $out;
    }
    if ($bookingId !== null) {
        $queue[$bookingId] = true;
    }
    return array_keys($queue);
}

/** Send the queued confirmations. Call only when no transaction is open. Never throws. */
function notify_flush(PDO $pdo, DateTimeImmutable $now): void
{
    foreach (notify_queue(null, true) as $id) {
        deliver_booking_confirmation($pdo, (int) $id, $now);
    }
}

/* ------------------------------------------------------------------ */
/* Message text                                                        */
/* ------------------------------------------------------------------ */

/** @return array{subject:string,text:string,html:string,short:string} */
function build_ticket_message(array $b, array $loc, string $ticketUrl, string $pdfUrl, string $directionsUrl, string $payNote = 'Paid'): array
{
    $start = (new DateTimeImmutable((string) $b['start_time']))->format('d M Y, H:i');
    $end = (new DateTimeImmutable((string) $b['end_time']))->format('d M Y, H:i');
    $place = (string) $b['location_name'];
    $addr = trim((string) ($loc['address'] ?? '') . ', ' . (string) ($loc['city'] ?? ''), ', ');
    $amount = 'Rs ' . (int) $b['cost'];
    $subject = 'Parking confirmed: ' . $place . ', ' . $start;

    $lines = [
        'Hi ' . (string) $b['name'] . ', your parking is confirmed and paid.',
        '',
        'Place: ' . $place,
        'Address: ' . $addr,
        'From: ' . $start,
        'Until: ' . $end,
        'Car: ' . (string) $b['car_number'],
        'Amount: ' . $amount,
        'Payment: ' . $payNote,
        'Reservation no: ' . (int) $b['id'],
        '',
        'Ticket: ' . $ticketUrl,
        'PDF ticket: ' . $pdfUrl,
    ];
    if ($directionsUrl !== '') {
        $lines[] = 'Directions: ' . $directionsUrl;
    }
    $lines[] = '';
    $lines[] = 'Parkaro';
    $text = implode("\n", $lines);

    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:520px;margin:auto;color:#1d2327">'
        . '<h2 style="margin:0 0 8px">Parking confirmed</h2><p>Hi ' . $h((string) $b['name']) . ', your parking is confirmed and paid.</p>'
        . '<table style="border-collapse:collapse;width:100%">'
        . '<tr><td style="padding:6px 0;color:#5b6368">Place</td><td><strong>' . $h($place) . '</strong><br>' . $h($addr) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#5b6368">From</td><td>' . $h($start) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#5b6368">Until</td><td>' . $h($end) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#5b6368">Car</td><td>' . $h((string) $b['car_number']) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#5b6368">Amount</td><td>' . $h($amount) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#5b6368">Payment</td><td>' . $h($payNote) . '</td></tr>'
        . '<tr><td style="padding:6px 0;color:#5b6368">Reservation no</td><td>' . (int) $b['id'] . '</td></tr></table>'
        . '<p><a href="' . $h($ticketUrl) . '" style="background:#1456d9;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;display:inline-block">View ticket</a> '
        . '<a href="' . $h($pdfUrl) . '">Download PDF</a>'
        . ($directionsUrl !== '' ? ' &middot; <a href="' . $h($directionsUrl) . '">Get directions</a>' : '') . '</p>'
        . '<p style="color:#5b6368;font-size:13px">Parkaro</p></div>';

    // WhatsApp / SMS: one short message
    $short = 'Parkaro: parking confirmed at ' . $place . ', ' . $start . ' to ' . $end . ', car ' . (string) $b['car_number']
        . ', ' . $amount . ', ' . $payNote . '. Ref #' . (int) $b['id'] . '. Ticket: ' . $ticketUrl;
    // numbered values for an approved WhatsApp template ({{1}} ... {{8}}), see docs/NOTIFICATIONS_SETUP.md
    $vars = ['1' => (string) $b['name'], '2' => $place, '3' => $start, '4' => $end, '5' => (string) $b['car_number'],
             '6' => $amount, '7' => (string) (int) $b['id'], '8' => $ticketUrl];
    return ['subject' => $subject, 'text' => $text, 'html' => $html, 'short' => $short, 'vars' => $vars];
}

/* ------------------------------------------------------------------ */
/* Delivery (once per booking + channel)                                */
/* ------------------------------------------------------------------ */

/**
 * Send the confirmation for one booking on every channel it asked for. Safe to call any number of times
 * and from any path (callback, webhook, cash). Never throws.
 *
 * @return array<string,string> channel => sent|failed|skipped|already|not_confirmed
 */
function deliver_booking_confirmation(PDO $pdo, int $bookingId, DateTimeImmutable $now): array
{
    $result = [];
    try {
        if (!notify_schema_ready($pdo)) {
            return $result;
        }
        $b = q_one($pdo, 'SELECT * FROM bookings WHERE id = ? AND location_id IS NOT NULL', [$bookingId]);
        if ($b === null || (string) $b['status'] !== 'confirmed') {
            return ['email' => 'not_confirmed'];
        }
        $loc = q_one($pdo, 'SELECT * FROM parking_locations WHERE id = ?', [(int) $b['location_id']]) ?? [];
        $transport = notify_transport();
        $channels = parse_notify_via($b['notify_via'] ?? null);
        $nowStr = $now->format(PK_DT);

        $base = notify_base_url();
        $ticketUrl = $base . '/' . ticket_url('booking_confirmed.php', $bookingId);
        $pdfUrl = $base . '/' . ticket_url('generate_ticket.php', $bookingId);
        $dir = '';
        if (function_exists('fp_directions_url') && isset($loc['lat'], $loc['lng'])) {
            $dir = (string) fp_directions_url($loc['lat'], $loc['lng']);
        }
        $payRow = q_one($pdo, "SELECT provider FROM payments WHERE booking_id = ? AND status = 'succeeded' ORDER BY id DESC", [$bookingId]);
        if ($payRow === null) {
            return ['email' => 'not_confirmed'];   // no verified payment on record: never send a "paid" ticket
        }
        $prov = (string) $payRow['provider'];
        $payNote = $prov === 'cash' ? 'Paid in cash at the place' : ($prov === 'upi_owner' ? 'UPI received by owner' : 'Paid online');
        $msg = build_ticket_message($b, $loc, $ticketUrl, $pdfUrl, $dir, $payNote);

        foreach ($channels as $channel) {
            // claim the (booking, channel) row; whoever inserts it first sends
            $claimed = false;
            try {
                q_run($pdo, "INSERT INTO notifications (booking_id, channel, status, attempts, created_at, updated_at) VALUES (?, ?, 'sending', 0, ?, ?)",
                    [$bookingId, $channel, $nowStr, $nowStr]);
                $claimed = true;
            } catch (PDOException $ex) {
                if ((string) $ex->getCode() !== '23000') {
                    throw $ex;
                }
                // row exists: retry only a failed one that has attempts left
                $upd = q_run($pdo, "UPDATE notifications SET status = 'sending', updated_at = ? WHERE booking_id = ? AND channel = ? AND status = 'failed' AND attempts < ?",
                    [$nowStr, $bookingId, $channel, NOTIFY_MAX_ATTEMPTS]);
                $claimed = $upd->rowCount() === 1;
            }
            if (!$claimed) {
                $result[$channel] = 'already';
                continue;
            }

            $to = $channel === 'email' ? (string) $b['email'] : (string) ($b['contact_phone'] ?? '');
            $status = 'sent';
            $err = null;
            if ($to === '') {
                $status = 'skipped';
                $err = 'no recipient';
            } elseif (!$transport->isConfigured($channel)) {
                $status = 'skipped';
                $err = 'channel not configured in .env';
            } else {
                try {
                    $transport->send($channel, $to, $msg['subject'], $channel === 'email' ? $msg['text'] : $msg['short'], $channel === 'email' ? $msg['html'] : '', $msg['vars']);
                } catch (Throwable $ex) {
                    $status = 'failed';
                    $err = mb_substr(preg_replace('/\s+/', ' ', $ex->getMessage()) ?? 'error', 0, 250);
                    error_log("[parkaro] $channel confirmation for booking $bookingId failed: $err");
                }
            }
            q_run($pdo, 'UPDATE notifications SET status = ?, attempts = attempts + 1, last_error = ?, updated_at = ? WHERE booking_id = ? AND channel = ?',
                [$status, $err, $nowStr, $bookingId, $channel]);
            $result[$channel] = $status;
        }
    } catch (Throwable $ex) {
        error_log('[parkaro] notification error: ' . get_class($ex) . ': ' . $ex->getMessage());
    }
    return $result;
}

/* ------------------------------------------------------------------ */
/* Delivery status for the admin screens, and authorised retry          */
/* ------------------------------------------------------------------ */

/**
 * What happened to the ticket messages of some bookings.
 * 'sent' only means the provider ACCEPTED the message; nobody can promise it reached the phone or inbox.
 *
 * @param int[] $bookingIds
 * @return array<int, array{requested:string[], rows:array<string,array{status:string,attempts:int,error:?string}>}>
 */
function notify_status_map(PDO $pdo, array $bookingIds): array
{
    $out = [];
    $ids = array_values(array_unique(array_map('intval', $bookingIds)));
    if (!$ids || !notify_schema_ready($pdo)) {
        return $out;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (q_all($pdo, "SELECT id, notify_via FROM bookings WHERE id IN ($in)", $ids) as $r) {
        $out[(int) $r['id']] = ['requested' => parse_notify_via($r['notify_via'] ?? null), 'rows' => []];
    }
    foreach (q_all($pdo, "SELECT booking_id, channel, status, attempts, last_error FROM notifications WHERE booking_id IN ($in)", $ids) as $r) {
        $out[(int) $r['booking_id']]['rows'][(string) $r['channel']] = [
            'status' => (string) $r['status'], 'attempts' => (int) $r['attempts'], 'error' => $r['last_error'] === null ? null : (string) $r['last_error'],
        ];
    }
    return $out;
}

/** Does any requested channel of this booking still need attention (failed, skipped, stuck, or never attempted)? */
function notify_needs_retry(array $info): bool
{
    foreach ($info['requested'] as $ch) {
        $st = $info['rows'][$ch]['status'] ?? null;
        if ($st !== 'sent') {
            return true;
        }
    }
    return false;
}

/**
 * The owner of the place asks for the failed / skipped / stuck messages of one confirmed reservation to be sent
 * again (for instance after fixing .env). Channels already sent are never repeated. Nothing is sent for a
 * reservation that is not confirmed with a recorded payment.
 *
 * @return array{ok:bool,error?:string,result?:array<string,string>}
 */
function notify_retry_for_owner(PDO $pdo, string $adminUsername, int $bookingId, DateTimeImmutable $now): array
{
    if (!notify_schema_ready($pdo)) {
        return ['ok' => false, 'error' => 'Ticket messages need migration 002 (see docs/NOTIFICATIONS_SETUP.md).'];
    }
    $b = q_one(
        $pdo,
        'SELECT b.id, b.status FROM bookings b JOIN parking_locations l ON l.id = b.location_id WHERE b.id = ? AND l.owner_username = ?',
        [$bookingId, $adminUsername]
    );
    if ($b === null) {
        return ['ok' => false, 'error' => 'Reservation not found.'];
    }
    if ((string) $b['status'] !== 'confirmed' || (int) q_val($pdo, "SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'succeeded'", [$bookingId], 0) < 1) {
        return ['ok' => false, 'error' => 'Messages are only sent for confirmed, paid reservations.'];
    }
    $nowStr = $now->format(PK_DT);
    $stale = $now->modify('-15 minutes')->format(PK_DT);
    q_run(
        $pdo,
        "UPDATE notifications SET status = 'failed', attempts = 0, updated_at = ?
         WHERE booking_id = ? AND (status IN ('failed', 'skipped') OR (status = 'sending' AND updated_at < ?))",
        [$nowStr, $bookingId, $stale]
    );
    return ['ok' => true, 'result' => deliver_booking_confirmation($pdo, $bookingId, $now)];
}

/* ------------------------------------------------------------------ */
/* Transport: SMTP e-mail + Twilio (SMS and WhatsApp)                    */
/* ------------------------------------------------------------------ */

final class EnvNotifyTransport implements NotifyTransport
{
    /** @var callable|null  function(string $method, string $url, array $form, string $user, string $pass): array{0:int,1:string} */
    private $http;

    public function __construct(?callable $http = null, private ?array $conf = null)
    {
        $this->http = $http;
    }

    private function c(string $k): string
    {
        return $this->conf !== null ? (string) ($this->conf[$k] ?? '') : (string) env($k, '');
    }

    public function isConfigured(string $channel): bool
    {
        return match ($channel) {
            'email' => $this->c('MAIL_HOST') !== '' && $this->c('MAIL_FROM') !== '',
            'sms' => $this->twilioBase() && $this->c('TWILIO_SMS_FROM') !== '',
            'whatsapp' => $this->twilioBase() && $this->c('TWILIO_WHATSAPP_FROM') !== '',
            default => false,
        };
    }

    private function twilioBase(): bool
    {
        return $this->c('TWILIO_ACCOUNT_SID') !== '' && $this->c('TWILIO_AUTH_TOKEN') !== '';
    }

    public function send(string $channel, string $to, string $subject, string $text, string $html, array $vars = []): void
    {
        if ($channel === 'email') {
            $this->smtp($to, $subject, $text, $html);
            return;
        }
        if ($channel !== 'sms' && $channel !== 'whatsapp') {
            throw new NotifyException('unknown channel');
        }
        if (preg_match('/^\+\d{8,15}$/', $to) !== 1) {
            throw new NotifyException('invalid phone number');
        }
        $from = $channel === 'sms' ? $this->c('TWILIO_SMS_FROM') : $this->c('TWILIO_WHATSAPP_FROM');
        if ($channel === 'whatsapp') {
            $from = 'whatsapp:' . preg_replace('/^whatsapp:/', '', $from);
            $to = 'whatsapp:' . $to;
        }
        $form = ['To' => $to, 'Body' => $text];
        $template = $channel === 'whatsapp' ? $this->c('TWILIO_WHATSAPP_CONTENT_SID') : '';
        if ($template !== '' && $vars) {   // approved WhatsApp template (needed to message customers outside a 24-hour chat)
            unset($form['Body']);
            $form['ContentSid'] = $template;
            $form['ContentVariables'] = json_encode($vars, JSON_UNESCAPED_UNICODE);
        }
        // a Messaging Service SID ("MG...") is sent as MessagingServiceSid, anything else as From
        if (str_starts_with($from, 'MG')) {
            $form['MessagingServiceSid'] = $from;
        } else {
            $form['From'] = $from;
        }
        $sid = $this->c('TWILIO_ACCOUNT_SID');
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
        [$status, $body] = ($this->http ?? [$this, 'curl'])('POST', $url, $form, $sid, $this->c('TWILIO_AUTH_TOKEN'));
        if ($status < 200 || $status >= 300) {
            $msg = json_decode($body, true)['message'] ?? ('HTTP ' . $status);
            throw new NotifyException('Twilio: ' . (string) $msg);
        }
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $form, string $user, string $pass): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_USERPWD => $user . ':' . $pass, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $body = curl_exec($ch);
        if ($body === false) {
            $e = curl_error($ch);
            curl_close($ch);
            throw new NotifyException('network: ' . $e);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $body];
    }

    /* --- minimal SMTP client (plain / STARTTLS / SSL, AUTH LOGIN or PLAIN) --- */

    private function smtp(string $to, string $subject, string $text, string $html): void
    {
        $clean = static fn (string $s): string => trim(str_replace(["\r", "\n", "\0"], ' ', $s));
        $to = $clean($to);
        $from = $clean($this->c('MAIL_FROM'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new NotifyException('invalid e-mail address');
        }
        $host = $this->c('MAIL_HOST');
        $secure = strtolower($this->c('MAIL_SECURE') ?: 'tls');   // tls (STARTTLS) | ssl | none
        $port = (int) ($this->c('MAIL_PORT') ?: ($secure === 'ssl' ? 465 : 587));
        $scheme = $secure === 'ssl' ? 'ssl://' : 'tcp://';
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 10);
        if (!$fp) {
            throw new NotifyException("SMTP connect failed: $errstr");
        }
        stream_set_timeout($fp, 10);
        $read = static function () use ($fp): array {
            $code = 0;
            $all = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $all .= $line;
                $code = (int) substr($line, 0, 3);
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
            return [$code, $all];
        };
        $cmd = static function (string $c, array $okCodes) use ($fp, $read): string {
            if ($c !== '') {
                fwrite($fp, $c . "\r\n");
            }
            [$code, $all] = $read();
            if (!in_array($code, $okCodes, true)) {
                throw new NotifyException('SMTP error ' . $code . ': ' . trim(preg_replace('/\s+/', ' ', $all) ?? ''));
            }
            return $all;
        };
        try {
            $cmd('', [220]);
            $helo = 'parkaro.local';
            $caps = $cmd('EHLO ' . $helo, [250]);
            if ($secure === 'tls') {
                $cmd('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new NotifyException('SMTP: could not start TLS');
                }
                $caps = $cmd('EHLO ' . $helo, [250]);
            }
            $user = $this->c('MAIL_USER');
            if ($user !== '') {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334]);
                $cmd(base64_encode($this->c('MAIL_PASS')), [235]);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $fromName = $clean($this->c('MAIL_FROM_NAME') ?: 'Parkaro');
            $boundary = 'pk' . bin2hex(random_bytes(8));
            $enc = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
            $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
            $headers = [
                'Date: ' . date('r'),
                'From: ' . $enc($fromName) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $enc($clean($subject)),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];
            $part = static fn (string $type, string $body): string => 'Content-Type: ' . $type . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            $data = implode("\r\n", $headers) . "\r\n\r\n"
                . '--' . $boundary . "\r\n" . $part('text/plain', $text)
                . '--' . $boundary . "\r\n" . $part('text/html', $html !== '' ? $html : nl2br(htmlspecialchars($text)))
                . '--' . $boundary . "--\r\n";
            // base64 lines never start with "." but dot-stuff anyway
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            fwrite($fp, $data . ".\r\n");
            [$code, $all] = $read();
            if ($code !== 250) {
                throw new NotifyException('SMTP rejected the message: ' . $code);
            }
            @fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }
}
