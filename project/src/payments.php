<?php
/**
 * Payments: gateway integration points, verification and the ledger.
 *
 * THE RULE: a payment counts as "money received" only when a payments row has
 * status = 'succeeded', and the row says HOW it was verified:
 *   gateway_api      the checkout signature was valid (HMAC with our secret, using OUR stored order id)
 *                    AND the gateway's own API reported the payment as "captured" for the right amount;
 *   gateway_webhook  a webhook whose HMAC signature is valid reported "captured" for the right amount;
 *   admin_cash       the admin who owns the place recorded cash received at the lot;
 *   admin_upi        the owner recorded a UPI payment that went straight to the owner ("UPI received by owner").
 * Nothing in this file marks a payment successful because a browser says so, and there is no
 * "test mode" switch that fakes success. If the gateway is not configured, online payment is simply
 * unavailable (cash at the lot still works).
 *
 * Amounts are decided by the server when the order is created and are compared again when the
 * gateway reports the payment. Money is whole rupees in our tables; the gateway uses paise (x100).
 *
 * The gateway talks through the PaymentGateway interface. RazorpayGateway implements it (India;
 * UPI / cards / netbanking). Another provider can be added by implementing the same interface.
 */
declare(strict_types=1);

require_once __DIR__ . '/reservations.php';

const PAY_CREATED   = 'created';
const PAY_SUCCEEDED = 'succeeded';
const PAY_FAILED    = 'failed';
const PAY_REFUNDED  = 'refunded';

const VERIFY_API     = 'gateway_api';
const VERIFY_WEBHOOK = 'gateway_webhook';
const VERIFY_CASH    = 'admin_cash';
const VERIFY_UPI     = 'admin_upi';   // UPI paid straight to the owner and confirmed by the owner (NOT a gateway payment)

class PaymentGatewayException extends RuntimeException
{
}

interface PaymentGateway
{
    /** Short provider key stored in payments.provider, e.g. 'razorpay'. */
    public function name(): string;

    /** True when API keys are present (online payment can be offered). */
    public function isConfigured(): bool;

    /** True when a webhook secret is present (webhooks can be verified). */
    public function webhookConfigured(): bool;

    /** Public key id the browser checkout needs (not secret). */
    public function publicKey(): string;

    /**
     * Create an order for $amountPaise. Returns ['id' => string, 'amount' => int, 'currency' => string].
     * @throws PaymentGatewayException
     */
    public function createOrder(int $amountPaise, string $currency, string $receipt): array;

    /** Verify the signature the checkout returned for (orderId, paymentId). Constant-time comparison. */
    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool;

    /**
     * Ask the gateway for the real state of a payment.
     * Returns ['id','order_id','amount' (paise),'currency','status'].
     * @throws PaymentGatewayException
     */
    public function fetchPayment(string $paymentId): array;

    /** Verify a webhook: HMAC-SHA256 over the RAW request body with the webhook secret. */
    public function verifyWebhookSignature(string $rawBody, string $signature): bool;
}

/**
 * Razorpay (https://razorpay.com/docs/). Algorithms as documented by Razorpay:
 *   - Orders API:   POST https://api.razorpay.com/v1/orders  (basic auth key_id:key_secret, amount in paise)
 *   - Checkout signature: HMAC-SHA256( order_id . "|" . razorpay_payment_id, key_secret ), hex
 *   - Webhook signature:  HMAC-SHA256( raw request body, webhook_secret ), hex, header X-Razorpay-Signature
 *   - Webhook de-duplication: header X-Razorpay-Event-Id
 * The HTTP part is injectable so tests can run without the network.
 */
final class RazorpayGateway implements PaymentGateway
{
    public const API_BASE = 'https://api.razorpay.com/v1';

    /** @var callable(string,string,?array,string,string):array{0:int,1:string} */
    private $http;

    public function __construct(
        private string $keyId,
        private string $keySecret,
        private string $webhookSecret,
        ?callable $http = null
    ) {
        $this->http = $http ?? [self::class, 'curlHttp'];
    }

    public static function fromEnv(?callable $http = null): self
    {
        return new self(
            (string) env('RAZORPAY_KEY_ID', ''),
            (string) env('RAZORPAY_KEY_SECRET', ''),
            (string) env('RAZORPAY_WEBHOOK_SECRET', ''),
            $http
        );
    }

    public function name(): string
    {
        return 'razorpay';
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    public function webhookConfigured(): bool
    {
        return $this->webhookSecret !== '';
    }

    public function publicKey(): string
    {
        return $this->keyId;
    }

    public function createOrder(int $amountPaise, string $currency, string $receipt): array
    {
        if (!$this->isConfigured()) {
            throw new PaymentGatewayException('Online payment is not configured.');
        }
        [$status, $body] = ($this->http)('POST', self::API_BASE . '/orders',
            ['amount' => $amountPaise, 'currency' => $currency, 'receipt' => substr($receipt, 0, 40)],
            $this->keyId, $this->keySecret);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !isset($data['id'], $data['amount'], $data['currency'])
            || !is_string($data['id']) || preg_match('/^[A-Za-z0-9_]{6,64}$/', $data['id']) !== 1
            || (int) $data['amount'] !== $amountPaise || $data['currency'] !== $currency) {
            error_log('[parkaro] razorpay createOrder failed, HTTP ' . $status);
            throw new PaymentGatewayException('The payment gateway did not accept the order.');
        }
        return ['id' => $data['id'], 'amount' => (int) $data['amount'], 'currency' => (string) $data['currency']];
    }

    public function verifyCheckoutSignature(string $orderId, string $paymentId, string $signature): bool
    {
        if ($this->keySecret === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret);
        return hash_equals($expected, strtolower($signature));
    }

    public function fetchPayment(string $paymentId): array
    {
        if (!$this->isConfigured() || preg_match('/^[A-Za-z0-9_]{6,64}$/', $paymentId) !== 1) {
            throw new PaymentGatewayException('Cannot look up this payment.');
        }
        [$status, $body] = ($this->http)('GET', self::API_BASE . '/payments/' . $paymentId, null, $this->keyId, $this->keySecret);
        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || !isset($data['id'], $data['status'], $data['amount'], $data['currency'])) {
            error_log('[parkaro] razorpay fetchPayment failed, HTTP ' . $status);
            throw new PaymentGatewayException('Could not confirm the payment with the gateway.');
        }
        return [
            'id'       => (string) $data['id'],
            'order_id' => (string) ($data['order_id'] ?? ''),
            'amount'   => (int) $data['amount'],
            'currency' => (string) $data['currency'],
            'status'   => (string) $data['status'],
        ];
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        if ($this->webhookSecret === '' || $signature === '') {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), strtolower($signature));
    }

    /** Real HTTP client (cURL, TLS verification ON, short timeouts). @return array{0:int,1:string} */
    public static function curlHttp(string $method, string $url, ?array $json, string $user, string $pass): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $user . ':' . $pass,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ];
        if ($json !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new PaymentGatewayException('Could not reach the payment gateway: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $body];
    }
}

/**
 * The gateway chosen by .env (Razorpay today). Unconfigured -> isConfigured() is false.
 * Tests may pass a gateway once to replace it (so no real network call is ever made).
 */
function payment_gateway(?PaymentGateway $override = null): PaymentGateway
{
    static $injected = null;
    if ($override !== null) {
        $injected = $override;
    }
    return $injected ?? RazorpayGateway::fromEnv();
}

function rupees_to_paise(int $rupees): int
{
    return $rupees * 100;
}

/* ------------------------------------------------------------------ */
/* Online payment: start                                               */
/* ------------------------------------------------------------------ */

/**
 * Create (or reuse) a gateway order for the user's own pending reservation.
 *
 * @return array{ok:true,order_id:string,amount_paise:int,currency:string,key_id:string,booking:array}
 *       |array{ok:false,code:string,error:string}
 */
function start_online_payment(PDO $pdo, PaymentGateway $gateway, int $userId, int $bookingId, DateTimeImmutable $now): array
{
    if (!$gateway->isConfigured()) {
        return ['ok' => false, 'code' => 'not_configured', 'error' => 'Online payment is not set up on this site.'];
    }
    $nowStr = $now->format(PK_DT);
    $loadBooking = static function (PDO $pdo) use ($bookingId, $userId): ?array {
        return q_one($pdo, 'SELECT * FROM bookings WHERE id = ? AND user_id = ? AND location_id IS NOT NULL', [$bookingId, $userId]);
    };

    $b = $loadBooking($pdo);
    if ($b === null) {
        return ['ok' => false, 'code' => 'not_found', 'error' => 'Reservation not found.'];
    }
    if ($b['status'] !== BK_PENDING || (string) $b['hold_expires_at'] <= $nowStr) {
        return ['ok' => false, 'code' => 'not_payable', 'error' => 'This reservation is no longer waiting for payment. Please make a new reservation.'];
    }
    $amount = (int) $b['cost'];
    if ($amount < 1) {
        return ['ok' => false, 'code' => 'not_payable', 'error' => 'This reservation has no amount to pay.'];
    }
    $placeRow = q_one($pdo, 'SELECT * FROM parking_locations WHERE id = ?', [(int) $b['location_id']]);
    if ($placeRow !== null && array_key_exists('accepts_online', $placeRow) && (int) $placeRow['accepts_online'] !== 1) {
        return ['ok' => false, 'code' => 'online_not_accepted', 'error' => 'This place does not take online payments. Please pay at the place.'];
    }

    // Reuse an order that was already created for this reservation (page reload, second click).
    $existing = q_one(
        $pdo,
        "SELECT * FROM payments WHERE booking_id = ? AND provider = ? AND status = 'created' AND provider_order_id IS NOT NULL ORDER BY id DESC",
        [$bookingId, $gateway->name()]
    );
    if ($existing !== null && (int) $existing['amount'] === $amount) {
        return ['ok' => true, 'order_id' => (string) $existing['provider_order_id'], 'amount_paise' => rupees_to_paise($amount),
                'currency' => (string) $existing['currency'], 'key_id' => $gateway->publicKey(), 'booking' => $b];
    }

    try {
        $order = $gateway->createOrder(rupees_to_paise($amount), 'INR', 'booking_' . $bookingId);
    } catch (PaymentGatewayException $ex) {
        return ['ok' => false, 'code' => 'gateway_error', 'error' => 'The payment gateway is not reachable right now. Please try again in a moment.'];
    }

    return with_write_tx($pdo, static function (PDO $pdo) use ($loadBooking, $gateway, $order, $amount, $userId, $bookingId, $now, $nowStr): array {
        $b = $loadBooking($pdo);
        if ($b === null || $b['status'] !== BK_PENDING || (string) $b['hold_expires_at'] <= $nowStr || (int) $b['cost'] !== $amount) {
            return ['ok' => false, 'code' => 'not_payable', 'error' => 'This reservation changed while the payment was being prepared. Please try again.'];
        }
        q_run(
            $pdo,
            "INSERT INTO payments (booking_id, user_id, provider, provider_order_id, amount, currency, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'created', ?, ?)",
            [$bookingId, $userId, $gateway->name(), $order['id'], $amount, $order['currency'], $nowStr, $nowStr]
        );
        return ['ok' => true, 'order_id' => $order['id'], 'amount_paise' => $order['amount'], 'currency' => $order['currency'],
                'key_id' => $gateway->publicKey(), 'booking' => $b];
    });
}

/* ------------------------------------------------------------------ */
/* Recording the outcome (the ONLY code that writes 'succeeded')       */
/* ------------------------------------------------------------------ */

/**
 * Record a verified successful online payment and confirm the reservation.
 * Idempotent: the same payment reported twice changes nothing the second time.
 * Opens its own transaction; use record_gateway_success_in_tx() when one is already open.
 *
 * @param ?int $amountPaise amount the gateway reported (null only when the caller has already
 *                          compared it, as the API-verified checkout path does)
 * @return array{ok:bool,code:string,booking_id?:int,booking_confirmed?:bool,needs_review?:bool}
 */
function record_gateway_success(
    PDO $pdo, string $provider, string $orderId, string $paymentId, ?int $amountPaise,
    string $currency, string $verification, DateTimeImmutable $now
): array {
    try {
        $r = with_write_tx($pdo, static fn(PDO $pdo): array => record_gateway_success_in_tx(
            $pdo, $provider, $orderId, $paymentId, $amountPaise, $currency, $verification, $now
        ));
        notify_flush($pdo, $now);   // ticket e-mail / WhatsApp / SMS, only now that the payment is committed
        return $r;
    } catch (PDOException $ex) {
        if ((string) $ex->getCode() === '23000') { // same payment id already stored on another order
            error_log("[parkaro] duplicate payment id $paymentId rejected");
            return ['ok' => false, 'code' => 'duplicate_payment'];
        }
        throw $ex;
    }
}

/** Body of record_gateway_success(); the caller must already be inside with_write_tx(). */
function record_gateway_success_in_tx(
    PDO $pdo, string $provider, string $orderId, string $paymentId, ?int $amountPaise,
    string $currency, string $verification, DateTimeImmutable $now
): array {
    if (!in_array($verification, [VERIFY_API, VERIFY_WEBHOOK], true)) {
        throw new InvalidArgumentException('Unknown verification method');
    }
    $pay = q_one($pdo, 'SELECT * FROM payments WHERE provider = ? AND provider_order_id = ?', [$provider, $orderId]);
    if ($pay === null) {
        return ['ok' => false, 'code' => 'unknown_order'];
    }
    $bookingId = (int) $pay['booking_id'];

    if ($pay['status'] === PAY_SUCCEEDED) {
        if ((string) $pay['provider_payment_id'] === $paymentId) {
            $b = q_one($pdo, 'SELECT status FROM bookings WHERE id = ?', [$bookingId]);
            $confirmed = $b !== null && $b['status'] === BK_CONFIRMED;
            if ($confirmed) {
                notify_queue($bookingId);   // a repeated report lets a failed ticket message be retried (never a second copy)
            }
            return ['ok' => true, 'code' => 'already_recorded', 'booking_id' => $bookingId, 'booking_confirmed' => $confirmed, 'needs_review' => !$confirmed];
        }
        error_log("[parkaro] order $orderId already settled by another payment; ignoring $paymentId");
        return ['ok' => false, 'code' => 'order_already_paid'];
    }
    if ($pay['status'] === PAY_REFUNDED) {
        return ['ok' => false, 'code' => 'refunded'];
    }
    if ($currency !== (string) $pay['currency']
        || ($amountPaise !== null && $amountPaise !== rupees_to_paise((int) $pay['amount']))) {
        q_run($pdo, "UPDATE payments SET status = 'failed', failure_reason = ?, updated_at = ? WHERE id = ? AND status <> 'succeeded'",
            ['amount_or_currency_mismatch', $now->format(PK_DT), (int) $pay['id']]);
        error_log("[parkaro] payment $paymentId for order $orderId rejected: amount/currency mismatch");
        return ['ok' => false, 'code' => 'amount_mismatch'];
    }

    $nowStr = $now->format(PK_DT);
    q_run(
        $pdo,
        "UPDATE payments SET status = 'succeeded', provider_payment_id = ?, verification = ?, verified_at = ?,
                failure_reason = NULL, updated_at = ? WHERE id = ?",
        [$paymentId, $verification, $nowStr, $nowStr, (int) $pay['id']]
    );
    $booking = q_one($pdo, 'SELECT * FROM bookings WHERE id = ?', [$bookingId]);
    $confirmed = $booking !== null && confirm_booking_in_tx($pdo, $booking, $now);
    return ['ok' => true, 'code' => 'recorded', 'booking_id' => $bookingId, 'booking_confirmed' => $confirmed, 'needs_review' => !$confirmed];
}

/** The gateway reported a failed attempt. Never downgrades a succeeded payment. */
function record_gateway_failure(PDO $pdo, string $provider, string $orderId, string $reason, DateTimeImmutable $now): bool
{
    $st = q_run(
        $pdo,
        "UPDATE payments SET status = 'failed', failure_reason = ?, updated_at = ?
         WHERE provider = ? AND provider_order_id = ? AND status = 'created'",
        [mb_substr($reason, 0, 255), $now->format(PK_DT), $provider, $orderId]
    );
    return $st->rowCount() === 1;
}

/* ------------------------------------------------------------------ */
/* Online payment: the browser comes back from checkout                */
/* ------------------------------------------------------------------ */

/**
 * Handle the POST Razorpay's checkout sends to our callback URL
 * (razorpay_payment_id, razorpay_order_id, razorpay_signature).
 * The browser's word is NOT enough: we use OUR stored order id, check the signature, and then
 * ask the gateway for the payment's real status and amount.
 *
 * @return array{ok:bool,status:string,message:string,booking_id?:int}
 *         status: 'confirmed' | 'pending' (not settled yet, the webhook will finish it) | 'review' | 'failed'
 */
function complete_checkout_callback(PDO $pdo, PaymentGateway $gateway, array $post, DateTimeImmutable $now): array
{
    $paymentId = (string) ($post['razorpay_payment_id'] ?? '');
    $orderId = (string) ($post['razorpay_order_id'] ?? '');
    $signature = (string) ($post['razorpay_signature'] ?? '');
    $idPattern = '/^[A-Za-z0-9_]{6,64}$/';
    if (preg_match($idPattern, $paymentId) !== 1 || preg_match($idPattern, $orderId) !== 1 || preg_match('/^[A-Fa-f0-9]{64}$/', $signature) !== 1) {
        return ['ok' => false, 'status' => 'failed', 'message' => 'The payment response was incomplete.'];
    }

    $pay = q_one($pdo, 'SELECT * FROM payments WHERE provider = ? AND provider_order_id = ?', [$gateway->name(), $orderId]);
    if ($pay === null) {
        return ['ok' => false, 'status' => 'failed', 'message' => 'Unknown payment order.'];
    }
    $ourOrderId = (string) $pay['provider_order_id'];

    if (!$gateway->verifyCheckoutSignature($ourOrderId, $paymentId, $signature)) {
        error_log("[parkaro] bad checkout signature for order $ourOrderId");
        return ['ok' => false, 'status' => 'failed', 'message' => 'The payment could not be verified.'];
    }

    if ($pay['status'] === PAY_SUCCEEDED && (string) $pay['provider_payment_id'] === $paymentId) {
        $b = q_one($pdo, 'SELECT status FROM bookings WHERE id = ?', [(int) $pay['booking_id']]);
        $confirmed = $b !== null && $b['status'] === BK_CONFIRMED;
        if ($confirmed) {
            notify_queue((int) $pay['booking_id']);
            notify_flush($pdo, $now);
        }
        return ['ok' => true, 'status' => $confirmed ? 'confirmed' : 'review', 'booking_id' => (int) $pay['booking_id'],
                'message' => $confirmed ? 'Payment received. Your reservation is confirmed.' : 'Payment received, but the reservation needs a manual check.'];
    }

    try {
        $remote = $gateway->fetchPayment($paymentId);
    } catch (PaymentGatewayException $ex) {
        return ['ok' => true, 'status' => 'pending', 'booking_id' => (int) $pay['booking_id'],
                'message' => 'We could not confirm the payment with the gateway yet. If money was deducted, the reservation will be confirmed automatically shortly.'];
    }
    if ($remote['order_id'] !== $ourOrderId || $remote['id'] !== $paymentId) {
        error_log("[parkaro] gateway payment $paymentId does not belong to order $ourOrderId");
        return ['ok' => false, 'status' => 'failed', 'message' => 'The payment could not be verified.'];
    }
    if ($remote['status'] === 'failed') {
        record_gateway_failure($pdo, $gateway->name(), $ourOrderId, 'gateway reported failed', $now);
        return ['ok' => false, 'status' => 'failed', 'booking_id' => (int) $pay['booking_id'], 'message' => 'The payment failed. You were not charged. You can try again while the reservation is held.'];
    }
    if ($remote['status'] !== 'captured') {
        // 'authorized' (not captured yet) or 'created': money is not settled, so nothing is marked paid.
        return ['ok' => true, 'status' => 'pending', 'booking_id' => (int) $pay['booking_id'],
                'message' => 'Your payment is being processed. The reservation will be confirmed as soon as the gateway settles it.'];
    }

    $r = record_gateway_success($pdo, $gateway->name(), $ourOrderId, $paymentId, $remote['amount'], $remote['currency'], VERIFY_API, $now);
    if (!$r['ok']) {
        return ['ok' => false, 'status' => 'failed', 'message' => 'The payment could not be recorded. Please contact support with your payment id ' . $paymentId . '.'];
    }
    return [
        'ok' => true,
        'status' => !empty($r['booking_confirmed']) ? 'confirmed' : 'review',
        'booking_id' => (int) $r['booking_id'],
        'message' => !empty($r['booking_confirmed'])
            ? 'Payment received. Your reservation is confirmed.'
            : 'Payment received, but your hold had expired and the space was taken. The place owner will refund you; keep payment id ' . $paymentId . '.',
    ];
}

/* ------------------------------------------------------------------ */
/* Online payment: webhook                                             */
/* ------------------------------------------------------------------ */

/**
 * Process a gateway webhook. $rawBody must be the untouched request body.
 *
 * @return array{0:int,1:string} [HTTP status, short text]. 200 means "stop retrying".
 */
function handle_gateway_webhook(PDO $pdo, PaymentGateway $gateway, string $rawBody, string $signature, string $eventId, DateTimeImmutable $now): array
{
    $r = handle_gateway_webhook_core($pdo, $gateway, $rawBody, $signature, $eventId, $now);
    if ($r[0] === 200) {
        notify_flush($pdo, $now);   // after the webhook's transaction has committed
    } else {
        notify_queue(null, true);
    }
    return $r;
}

function handle_gateway_webhook_core(PDO $pdo, PaymentGateway $gateway, string $rawBody, string $signature, string $eventId, DateTimeImmutable $now): array
{
    if (!$gateway->webhookConfigured()) {
        return [503, 'webhook not configured'];
    }
    if (!$gateway->verifyWebhookSignature($rawBody, $signature)) {
        return [400, 'bad signature'];
    }
    $data = json_decode($rawBody, true);
    if (!is_array($data) || !isset($data['event']) || !is_string($data['event'])) {
        return [400, 'bad payload'];
    }
    $event = $data['event'];
    $bodyHash = hash('sha256', $rawBody);
    if ($eventId === '' || strlen($eventId) > 100) {
        $eventId = 'sha256:' . $bodyHash; // fallback when the delivery carries no event id
    }

    try {
        return with_write_tx($pdo, static function (PDO $pdo) use ($gateway, $data, $event, $eventId, $bodyHash, $now): array {
            try {
                q_run(
                    $pdo,
                    'INSERT INTO payment_events (provider, event_id, event_type, payload_sha256, received_at) VALUES (?, ?, ?, ?, ?)',
                    [$gateway->name(), $eventId, mb_substr($event, 0, 60), $bodyHash, $now->format(PK_DT)]
                );
            } catch (PDOException $ex) {
                if ((string) $ex->getCode() === '23000') {
                    return [200, 'duplicate event ignored'];
                }
                throw $ex;
            }

            $entity = $data['payload']['payment']['entity'] ?? null;
            if (!is_array($entity) || !isset($entity['id'], $entity['order_id'])) {
                return [200, 'event ignored'];
            }
            $orderId = (string) $entity['order_id'];
            $paymentId = (string) $entity['id'];

            if ($event === 'payment.captured' || $event === 'order.paid') {
                if (($entity['status'] ?? '') !== 'captured') {
                    return [200, 'not captured, ignored'];
                }
                try {
                    $r = record_gateway_success_in_tx($pdo, $gateway->name(), $orderId, $paymentId, (int) ($entity['amount'] ?? -1),
                        (string) ($entity['currency'] ?? ''), VERIFY_WEBHOOK, $now);
                } catch (PDOException $ex) {
                    if ((string) $ex->getCode() === '23000') { // payment id already stored on another order
                        return [200, 'rejected: duplicate_payment'];
                    }
                    throw $ex;
                }
                return [200, $r['ok'] ? 'recorded' : 'rejected: ' . $r['code']];
            }
            if ($event === 'payment.failed') {
                record_gateway_failure($pdo, $gateway->name(), $orderId, (string) ($entity['error_description'] ?? 'payment failed'), $now);
                return [200, 'failure noted'];
            }
            return [200, 'event ignored'];
        });
    } catch (Throwable $ex) {
        error_log('[parkaro] webhook processing failed: ' . $ex->getMessage());
        return [500, 'processing error']; // non-2xx makes the gateway retry later
    }
}

/* ------------------------------------------------------------------ */
/* Cash at the lot (recorded by the owning admin)                      */
/* ------------------------------------------------------------------ */

/**
 * The place owner records that the customer paid at the place, in cash or by UPI sent straight to the owner.
 * Allowed only for a reservation at one of THAT admin's places that is still unpaid AND whose hold is still
 * running (an expired hold is never confirmed silently: the owner is told what to do instead).
 * The row is labelled provider 'cash' / verification 'admin_cash', or provider 'upi_owner' / 'admin_upi',
 * and carries the admin's username, so reports show it separately from gateway-verified money.
 * The amount is ALWAYS the stored reservation cost; $expectedAmount (what the owner saw on the review screen)
 * is only a guard: if it differs from the stored cost nothing is recorded.
 *
 * @param string $method 'cash' or 'upi'
 * @return array{ok:bool,error?:string}
 */
function record_cash_payment(PDO $pdo, string $adminUsername, int $bookingId, DateTimeImmutable $now, string $method = 'cash', ?int $expectedAmount = null): array
{
    if (!in_array($method, ['cash', 'upi'], true)) {
        return ['ok' => false, 'error' => 'Choose how the customer paid: cash, or UPI received by the owner.'];
    }
    try {
        $r = record_cash_payment_tx($pdo, $adminUsername, $bookingId, $now, $method, $expectedAmount);
        notify_flush($pdo, $now);   // after the commit; never inside the transaction
        return $r;
    } catch (RuntimeException $ex) {
        if ($ex->getMessage() === 'NO_SPACE') {
            notify_queue(null, true); // the transaction was rolled back: no payment row was kept
            return ['ok' => false, 'error' => 'The reservation lapsed and the space has been taken, so the payment was not recorded. Please refund the customer.'];
        }
        throw $ex;
    }
}

/** Plain-words label for a payments.provider value. */
function payment_method_label(?string $provider): string
{
    switch ((string) $provider) {
        case 'cash':      return 'Cash paid at the place';
        case 'upi_owner': return 'UPI received by owner';
        case 'razorpay':  return 'Verified online payment';
        case '':          return '';
        default:          return 'Verified online payment';
    }
}

function record_cash_payment_tx(PDO $pdo, string $adminUsername, int $bookingId, DateTimeImmutable $now, string $method = 'cash', ?int $expectedAmount = null): array
{
    return with_write_tx($pdo, static function (PDO $pdo) use ($adminUsername, $bookingId, $now, $method, $expectedAmount): array {
        $b = q_one($pdo, 'SELECT * FROM bookings WHERE id = ? AND location_id IS NOT NULL', [$bookingId]);
        if ($b === null) {
            return ['ok' => false, 'error' => 'Reservation not found.'];
        }
        $loc = lock_location($pdo, (int) $b['location_id']);
        if ($loc === null || $loc['owner_username'] !== $adminUsername) {
            return ['ok' => false, 'error' => 'Reservation not found.']; // same message: do not reveal other admins' bookings
        }
        // Re-read under the lock so two clicks cannot record the money twice.
        $b = q_one($pdo, 'SELECT * FROM bookings WHERE id = ?', [$bookingId]);
        if ((int) q_val($pdo, "SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'succeeded'", [$bookingId], 0) > 0) {
            return ['ok' => false, 'error' => 'A payment is already recorded for this reservation.'];
        }
        if ($b['status'] === BK_CANCELLED) {
            return ['ok' => false, 'error' => 'This reservation was cancelled, so a payment cannot be recorded for it.'];
        }
        $nowStr = $now->format(PK_DT);
        if ($b['status'] === BK_EXPIRED || $b['status'] !== BK_PENDING || (string) $b['hold_expires_at'] <= $nowStr) {
            return ['ok' => false, 'error' => 'The hold on this reservation has ended, so it cannot be confirmed any more and the space may already be free for others. '
                . 'Do not take payment for it; if you already received the money, refund it and ask the customer to make a new reservation.'];
        }
        if (array_key_exists('accepts_cash', $loc) && (int) $loc['accepts_cash'] !== 1) {
            return ['ok' => false, 'error' => 'This place is set to online payments only, so payment at the place cannot be recorded. Change the payment methods of the place first if you do accept it.'];
        }
        if ($expectedAmount !== null && $expectedAmount !== (int) $b['cost']) {
            return ['ok' => false, 'error' => 'The amount does not match this reservation (it is ' . (int) $b['cost'] . ' rupees). Nothing was recorded; reload the page and check again.'];
        }
        $provider = $method === 'upi' ? 'upi_owner' : 'cash';
        $verification = $method === 'upi' ? VERIFY_UPI : VERIFY_CASH;
        q_run(
            $pdo,
            "INSERT INTO payments (booking_id, user_id, provider, amount, currency, status, verification, verified_at, recorded_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'INR', 'succeeded', ?, ?, ?, ?, ?)",
            [$bookingId, $b['user_id'] === null ? null : (int) $b['user_id'], $provider, (int) $b['cost'], $verification, $nowStr, $adminUsername, $nowStr, $nowStr]
        );
        if (!confirm_booking_in_tx($pdo, $b, $now)) {
            // Undo the payment row: we must not record money for a space that cannot be given.
            throw new RuntimeException('NO_SPACE');
        }
        return ['ok' => true];
    });
}
