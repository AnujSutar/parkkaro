<?php
// Payment page for one reservation. A reservation becomes "confirmed" ONLY after a verified payment:
//   - online: the gateway's checkout posts back to payment_callback.php, which verifies the signature and
//     asks the gateway for the real status; a signed webhook (payment_webhook.php) is the backup;
//   - cash: the place owner records it in admin_reservations.php.
// Nothing on this page can mark a reservation as paid.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/payments.php';
require_once __DIR__ . '/src/ui.php';

$userId = require_user();
$now = app_now();
$ready = new_schema_ready();
$bookingId = (int) ($_POST['booking'] ?? $_GET['booking'] ?? 0);

$b = null;
if ($ready) {
    expire_stale_holds(pdo(), $now);
    $b = q_one(
        pdo(),
        'SELECT b.*, l.city, l.address FROM bookings b JOIN parking_locations l ON l.id = b.location_id WHERE b.id = ? AND b.user_id = ?',
        [$bookingId, $userId]
    );
}

// Which payment methods this place accepts (both when migration 002 is not applied yet).
$placeRow = $b !== null ? q_one(pdo(), 'SELECT * FROM parking_locations WHERE id = ?', [(int) $b['location_id']]) : null;
$acceptsOnline = !empty($placeRow['accepts_online'] ?? 1);
$acceptsCash = !empty($placeRow['accepts_cash'] ?? 1);

$gateway = payment_gateway();
$checkout = null;
$error = '';
if ($ready && $b !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $r = !$acceptsOnline
        ? ['ok' => false, 'error' => 'This place does not take online payments. Please pay at the place.']
        : start_online_payment(pdo(), $gateway, $userId, $bookingId, $now);
    if ($r['ok']) {
        $checkout = $r;
    } else {
        $error = $r['error'];
    }
}

if ($ready && $b === null) {
    http_response_code(404);
}
ui_page_start('Payment', 'user', 'mine');
?>
<h1>Payment</h1>
<?php if (!$ready): ?>
    <?= ui_schema_missing_notice() ?>
<?php elseif ($b === null): ?>
    <div class="card">Reservation not found. <a href="my_dashboard.php">Go to your dashboard</a></div>
<?php else:
    $held = $b['status'] === BK_PENDING && (string) $b['hold_expires_at'] > $now->format(PK_DT);
?>
    <div class="card">
        <h3 style="margin-top:0"><?= e($b['location_name']) ?> <?= ui_badge((string) $b['status']) ?></h3>
        <div><?= e($b['address']) ?>, <?= e($b['city']) ?></div>
        <div>From <strong><?= ui_dt($b['start_time']) ?></strong> to <strong><?= ui_dt($b['end_time']) ?></strong> &middot; car <?= e($b['car_number']) ?></div>
        <div>Amount to pay: <strong><?= ui_money((int) $b['cost']) ?></strong> (<?= (int) $b['hours'] ?> h)</div>
    </div>

    <?php if ($error !== '') { echo ui_msg('err', $error); } ?>

    <?php if ($b['status'] === BK_CONFIRMED): ?>
        <?= ui_msg('ok', 'This reservation is paid and confirmed.') ?>
        <p><a class="btn" href="<?= e(ticket_url('booking_confirmed.php', (int) $b['id'])) ?>">View ticket</a>
           <a class="btn alt" href="<?= e(ticket_url('generate_ticket.php', (int) $b['id'])) ?>">Download PDF ticket</a>
           <a href="my_dashboard.php">My dashboard</a></p>

    <?php elseif (!$held): ?>
        <?= ui_msg('warn', 'This reservation is no longer being held, so it cannot be paid. If you already paid, it will be handled automatically or by the place owner; check your dashboard.') ?>
        <p><a class="btn" href="find_parking.php">Search again</a> <a href="my_dashboard.php">My dashboard</a></p>

    <?php else: ?>
        <?= ui_msg('warn', 'Your space is held until ' . ui_dt((string) $b['hold_expires_at'], 'H:i') . '. Pay before then to confirm it.') ?>

        <?php if ($checkout !== null): ?>
            <div class="card">
                <p>Complete the payment in the secure window from the payment gateway. We confirm your reservation only after the gateway reports the money as received.</p>
                <form action="payment_callback.php" method="POST">
                    <script src="https://checkout.razorpay.com/v1/checkout.js"
                        data-key="<?= e($checkout['key_id']) ?>"
                        data-amount="<?= (int) $checkout['amount_paise'] ?>"
                        data-currency="<?= e($checkout['currency']) ?>"
                        data-order_id="<?= e($checkout['order_id']) ?>"
                        data-buttontext="Pay <?= (int) $b['cost'] ?> INR"
                        data-name="Parkaro"
                        data-description="<?= e('Parking: ' . $b['location_name']) ?>"
                        data-prefill.name="<?= e($b['name']) ?>"
                        data-prefill.email="<?= e($b['email']) ?>"
                        data-theme.color="#28a745"></script>
                </form>
            </div>
        <?php elseif (!$acceptsOnline): ?>
            <?= ui_msg('warn', 'This place takes payment at the place only (cash or UPI to the owner).') ?>
        <?php elseif ($gateway->isConfigured()): ?>
            <form class="card" method="post" action="pay.php">
                <?= csrf_field() ?>
                <input type="hidden" name="booking" value="<?= (int) $b['id'] ?>">
                <p>Pay online with UPI, card or net banking.</p>
                <button class="btn" type="submit">Pay <?= ui_money((int) $b['cost']) ?> online</button>
            </form>
        <?php else: ?>
            <?= ui_msg('warn', 'Online payment is not set up on this site yet.') ?>
        <?php endif; ?>

        <?php if ($acceptsCash): ?>
        <div class="card">
            <strong>Paying cash (or UPI to the owner) at the parking place?</strong>
            <p>Status: <strong>awaiting payment</strong>. Amount to pay the owner: <strong><?= ui_money((int) $b['cost']) ?></strong>.
               Your space is held until <strong><?= ui_dt((string) $b['hold_expires_at'], 'H:i') ?></strong>.</p>
            <p class="muted">Pay the owner in person and show this reservation (number <?= (int) $b['id'] ?>) before the hold ends. Your ticket is confirmed only after the owner records that they received the money; then it appears in your dashboard and is sent to your e-mail (and WhatsApp / SMS if you asked for it). Nothing on this page can confirm it for you.</p>
        </div>
        <?php elseif (!$acceptsOnline || !$gateway->isConfigured()): ?>
            <?= ui_msg('warn', 'No way of paying is available for this place right now. Please contact the place owner.') ?>
        <?php endif; ?>
        <p><a href="my_dashboard.php">My dashboard</a></p>
    <?php endif; ?>
<?php endif; ?>
<?php ui_page_end();
