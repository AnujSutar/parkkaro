<?php
// Reserve a space at one parking place for a time window. Needs a logged-in user.
// The reservation is created as "awaiting payment" and holds the space for a few minutes;
// it is only confirmed after a verified payment (pay.php).
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/locations.php';
require_once __DIR__ . '/src/notify.php';
require_once __DIR__ . '/src/ui.php';

$userId = require_user();
$now = app_now();
$ready = new_schema_ready();

$locationId = (int) ($_POST['location'] ?? $_GET['location'] ?? 0);
$loc = $ready ? get_public_location(pdo(), $locationId) : null;

$startIn = trim((string) ($_POST['start'] ?? $_GET['start'] ?? ''));
$endIn = trim((string) ($_POST['end'] ?? $_GET['end'] ?? ''));
$errors = [];

$carIn = '';
$phoneIn = trim((string) ($_POST['phone'] ?? ''));
$waIn = !empty($_POST['notify_whatsapp']);
$smsIn = !empty($_POST['notify_sms']);
$msgReady = $ready && notify_schema_ready(pdo());
if ($msgReady && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // remember the number used last time (never the channel choice: that is asked for each time)
    $phoneIn = (string) q_val(pdo(), "SELECT contact_phone FROM bookings WHERE user_id = ? AND contact_phone IS NOT NULL ORDER BY id DESC LIMIT 1", [$userId], '');
}
if ($ready) {
    $account = q_one(pdo(), 'SELECT car_number FROM users WHERE id = ?', [$userId]);
    $carIn = (string) ($account['car_number'] ?? '');
}

if ($ready && $loc !== null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $carIn = trim((string) ($_POST['car_number'] ?? ''));
    $result = create_reservation(pdo(), $userId, $locationId, ['start' => $startIn, 'end' => $endIn, 'car_number' => $carIn,
        'phone' => $phoneIn, 'notify_whatsapp' => $waIn ? '1' : '', 'notify_sms' => $smsIn ? '1' : ''], $now);
    if ($result['ok']) {
        header('Location: ' . app_url('pay.php?booking=' . (int) $result['booking_id']));
        exit;
    }
    $errors = $result['errors'];
}

if ($ready && $loc === null) {
    http_response_code(404);
}
ui_page_start('Reserve a space', 'user', 'find');
?>
<h1>Reserve a space</h1>
<?php if (!$ready): ?>
    <?= ui_schema_missing_notice() ?>
<?php elseif ($loc === null): ?>
    <div class="card">This parking place was not found or is not taking reservations. <a href="find_parking.php">Back to search</a></div>
<?php else: ?>
    <div class="card">
        <h3 style="margin-top:0"><?= e($loc['name']) ?> <?= ui_badge((string) $loc['kind']) ?></h3>
        <div><?= e($loc['address']) ?>, <?= e($loc['city']) ?> <?= ui_map_link($loc['lat'], $loc['lng']) ?></div>
        <div class="muted"><?= ($loc['open_from'] && $loc['open_until']) ? 'Open ' . e(substr((string) $loc['open_from'], 0, 5)) . ' to ' . e(substr((string) $loc['open_until'], 0, 5)) . ' (the whole stay must be on one day inside these hours)' : 'Open 24 hours' ?>
            &middot; <?= ui_money((int) $loc['hourly_rate']) ?> per started hour</div>
        <div class="muted">You can pay: <?= e(implode(' or ', array_filter([!empty($loc['accepts_online'] ?? 1) ? 'online (UPI, card, net banking)' : '', !empty($loc['accepts_cash'] ?? 1) ? 'at the place' : '']))) ?></div>
    </div>

    <?php foreach ($errors as $err) { echo ui_msg('err', $err); } ?>

    <form class="card" method="post" action="reserve.php" id="reserveForm">
        <?= csrf_field() ?>
        <input type="hidden" name="location" value="<?= (int) $loc['id'] ?>">
        <div class="row">
            <div><label for="start">Arrive</label>
                <input type="datetime-local" id="start" name="start" required value="<?= e($startIn) ?>"></div>
            <div><label for="end">Leave</label>
                <input type="datetime-local" id="end" name="end" required value="<?= e($endIn) ?>"></div>
            <div><label for="car_number">Car number</label>
                <input type="text" id="car_number" name="car_number" required maxlength="14" value="<?= e($carIn) ?>"></div>
        </div>
        <?php if ($msgReady): ?>
        <fieldset style="border:1px solid #ddd;border-radius:8px;margin:12px 0;padding:10px 14px">
            <legend>Send my ticket to</legend>
            <div class="muted">E-mail (your account address) &ndash; always, once the payment is verified.</div>
            <div class="row">
                <div><label for="phone">Mobile number (for WhatsApp / SMS)</label>
                    <input type="tel" id="phone" name="phone" maxlength="20" placeholder="e.g. 9876543210" value="<?= e($phoneIn) ?>"></div>
            </div>
            <label style="display:block;font-weight:normal"><input type="checkbox" name="notify_whatsapp" value="1"<?= $waIn ? ' checked' : '' ?>> WhatsApp message</label>
            <label style="display:block;font-weight:normal"><input type="checkbox" name="notify_sms" value="1"<?= $smsIn ? ' checked' : '' ?>> SMS</label>
        </fieldset>
        <?php endif; ?>
        <p class="muted">Minimum <?= (int) MIN_RESERVATION_MINUTES ?> minutes, maximum <?= (int) MAX_BOOKING_HOURS ?> hours, up to <?= (int) MAX_ADVANCE_DAYS ?> days ahead.
            Every started hour is charged. <strong id="estimate"></strong></p>
        <p class="muted">After you reserve, the space is held for <?= (int) PAYMENT_HOLD_MINUTES ?> minutes while you pay. The reservation is confirmed only when the payment is verified.</p>
        <button class="btn" type="submit">Reserve and continue to payment</button>
    </form>
    <script>
    // Convenience only: the real price is always calculated again on the server.
    (function () {
        var rate = <?= (int) $loc['hourly_rate'] ?>;
        var s = document.getElementById('start'), e = document.getElementById('end'), out = document.getElementById('estimate');
        function update() {
            var a = new Date(s.value), b = new Date(e.value);
            if (!s.value || !e.value || isNaN(a) || isNaN(b) || b <= a) { out.textContent = ''; return; }
            var hours = Math.max(1, Math.ceil((b - a) / 3600000));
            out.textContent = 'Estimated price: ₹' + (hours * rate).toLocaleString('en-IN') + ' (' + hours + ' h)';
        }
        s.addEventListener('input', update); e.addEventListener('input', update); update();
    })();
    </script>
<?php endif; ?>
<?php ui_page_end();
