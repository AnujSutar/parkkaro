<?php
// Server-to-server notifications from the payment gateway (set this URL in the gateway dashboard).
// Authenticated ONLY by the HMAC signature of the raw body; no session, no cookies.
// Junk requests are turned away before the database is touched. Events are de-duplicated
// (payment_events table), so redelivery is harmless. See src/payments.php for the rules.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/payments.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('POST only');
}
$raw = (string) file_get_contents('php://input', false, null, 0, 1048577);
if (strlen($raw) > 1048576) {
    http_response_code(413);
    exit('too large');
}

$gateway = payment_gateway();
$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
$eventId = (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');

if (!$gateway->webhookConfigured()) {
    http_response_code(503);
    exit('webhook not configured');
}
if (!$gateway->verifyWebhookSignature($raw, $signature)) {
    http_response_code(400);
    exit('bad signature');
}

[$status, $text] = handle_gateway_webhook(pdo(), $gateway, $raw, $signature, $eventId, app_now());
http_response_code($status);
echo $text;
