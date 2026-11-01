<?php
// The payment gateway's checkout posts here when the customer returns from paying.
//
// This request comes from the gateway's page, not from our own form, so it carries no CSRF token and the
// session cookie may not be sent. It is authenticated by the gateway's SIGNATURE instead, and even a valid
// signature is not trusted on its own: complete_checkout_callback() looks the order up in OUR database,
// checks the signature against OUR stored order id, and asks the gateway for the payment's real status
// and amount before anything is marked as paid. See src/payments.php.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/payments.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: ' . app_url('my_dashboard.php'));
    exit;
}

$gateway = payment_gateway();
if (!$gateway->isConfigured()) {
    http_response_code(503);
    exit('Online payment is not configured.');
}

$result = complete_checkout_callback(pdo(), $gateway, $_POST, app_now());

$status = in_array($result['status'], ['confirmed', 'pending', 'review', 'failed'], true) ? $result['status'] : 'failed';
// Only a fixed keyword and a number go back in the URL; my_dashboard.php chooses the wording.
header('Location: ' . app_url('my_dashboard.php?' . http_build_query(['pay' => $status, 'booking' => (int) ($result['booking_id'] ?? 0)])), true, 303);
exit;
