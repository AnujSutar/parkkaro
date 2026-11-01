<?php
// Admin: reservations at MY parking places, and recording cash received at the lot.
// Only reservations at places this admin owns are listed or changeable.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/reservations.php';
require_once __DIR__ . '/src/payments.php';
require_once __DIR__ . '/src/notify.php';
require_once __DIR__ . '/src/ui.php';

[$admin, $zone] = require_admin();
$now = app_now();
$ready = new_schema_ready();

$statusFilter = (string) ($_GET['status'] ?? '');
if (!in_array($statusFilter, ['', BK_PENDING, BK_CONFIRMED, BK_CANCELLED, BK_EXPIRED], true)) {
    $statusFilter = '';
}

$flash = [];
$msgReady = $ready && notify_schema_ready(pdo());
$confirmId = (int) ($_GET['confirm'] ?? 0);
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['confirm_payment'])) {
    csrf_check();
    $bid = (int) $_POST['confirm_payment'];
    $method = (string) ($_POST['method'] ?? '');
    $amountSeen = isset($_POST['amount']) && ctype_digit((string) $_POST['amount']) ? (int) $_POST['amount'] : null;
    if (!in_array($method, ['cash', 'upi'], true)) {
        $flash[] = ['err', 'Choose how the customer paid: cash, or UPI received by the owner.'];
        $confirmId = $bid;
    } elseif (empty($_POST['received'])) {
        $flash[] = ['err', 'Tick the box to confirm that you have really received the money. Nothing was recorded.'];
        $confirmId = $bid;
    } elseif (isset($_POST['amount']) && $amountSeen === null) {
        $flash[] = ['err', 'The amount is not valid. Nothing was recorded.'];
        $confirmId = $bid;
    } else {
        $r = record_cash_payment(pdo(), $admin, $bid, $now, $method, $amountSeen);
        if ($r['ok']) {
            header('Location: ' . app_url('admin_reservations.php?paid=' . $bid));
            exit;
        }
        $flash[] = ['err', $r['error'] ?? 'Could not record the payment.'];
        $confirmId = $bid;
    }
}
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['retry_notify'])) {
    csrf_check();
    $r = notify_retry_for_owner(pdo(), $admin, (int) $_POST['retry_notify'], $now);
    if ($r['ok']) {
        $bad = array_keys(array_filter($r['result'] ?? [], static fn ($v) => in_array($v, ['failed', 'skipped'], true)));
        header('Location: ' . app_url('admin_reservations.php?retried=' . (int) $_POST['retry_notify'] . ($bad ? '&still=' . implode(',', array_map('rawurlencode', $bad)) : '')));
        exit;
    }
    $flash[] = ['err', $r['error'] ?? 'Could not retry.'];
}
if (isset($_GET['paid'])) {
    $flash[] = ['ok', 'Payment recorded and reservation ' . (int) $_GET['paid'] . ' confirmed. It now counts in your earnings. Check the "Ticket messages" column below to see what was sent.'];
}
if (isset($_GET['retried'])) {
    $still = array_filter(array_map('trim', explode(',', (string) ($_GET['still'] ?? ''))), static fn ($c) => isset(NOTIFY_CHANNELS[$c]));
    $flash[] = $still
        ? ['warn', 'Retried reservation ' . (int) $_GET['retried'] . ', but these channels are still not sent: ' . implode(', ', $still) . '. See the reason in the table.']
        : ['ok', 'Retried reservation ' . (int) $_GET['retried'] . '. Channels already sent were not repeated.'];
}

// Review screen for one reservation (owner-scoped: someone else's reservation looks like "not found").
$review = null;
$reviewLoc = null;
if ($ready && $confirmId > 0) {
    $review = q_one(
        pdo(),
        'SELECT b.*, l.address AS loc_address, l.city AS loc_city FROM bookings b JOIN parking_locations l ON l.id = b.location_id WHERE b.id = ? AND l.owner_username = ?',
        [$confirmId, $admin]
    );
    if ($review === null) {
        $flash[] = ['err', 'Reservation not found.'];
        $confirmId = 0;
    } else {
        $reviewLoc = q_one(pdo(), 'SELECT * FROM parking_locations WHERE id = ?', [(int) $review['location_id']]);
    }
}

$rows = $ready ? list_owner_reservations(pdo(), $admin, $statusFilter, $now) : [];
$nstat = $msgReady ? notify_status_map(pdo(), array_map(static fn ($r) => (int) $r['id'], $rows)) : [];
$nowStr = $now->format(PK_DT);

/** One line per channel: what happened to the ticket message. "Sent" = accepted by the provider, not proof of delivery. */
function admin_notify_cell(array $info, bool $confirmed): string
{
    if (!$confirmed) {
        return '<span class="muted">none (not paid)</span>';
    }
    $names = ['email' => 'E-mail', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS'];
    $out = '';
    foreach (['email', 'whatsapp', 'sms'] as $ch) {
        $label = $names[$ch];
        if (!in_array($ch, $info['requested'], true)) {
            $out .= '<div class="muted">' . $label . ': not requested (customer gave no number / did not tick it)</div>';
            continue;
        }
        $row = $info['rows'][$ch] ?? null;
        $st = $row['status'] ?? 'none';
        if ($st === 'sent') {
            $out .= '<div>' . $label . ': <span class="badge ok">Sent</span> <span class="muted">accepted by provider</span></div>';
        } elseif ($st === 'failed') {
            $out .= '<div>' . $label . ': <span class="badge bad">Failed</span> <span class="muted">' . e((string) ($row['error'] ?? 'error')) . ' (try ' . (int) $row['attempts'] . ')</span></div>';
        } elseif ($st === 'skipped') {
            $out .= '<div>' . $label . ': <span class="badge warn">Not sent</span> <span class="muted">' . e((string) ($row['error'] ?? '')) . '</span></div>';
        } elseif ($st === 'sending') {
            $out .= '<div>' . $label . ': <span class="badge warn">In progress</span></div>';
        } else {
            $out .= '<div>' . $label . ': <span class="badge warn">Not sent yet</span></div>';
        }
    }
    return $out;
}

ui_page_start('Reservations', 'admin', 'reservations', $admin);
?>
<h1>Reservations at my places</h1>
<?php foreach ($flash as [$kind, $text]) { echo ui_msg($kind, $text); } ?>
<?php if (!$ready): ?>
    <?= ui_schema_missing_notice() ?>
<?php else: ?>
    <form class="card" method="get" action="admin_reservations.php">
        <label for="status">Show</label>
        <select id="status" name="status" onchange="this.form.submit()">
            <option value="">All reservations</option>
            <?php foreach ([BK_PENDING => 'Awaiting payment', BK_CONFIRMED => 'Confirmed', BK_CANCELLED => 'Cancelled', BK_EXPIRED => 'Expired'] as $k => $label): ?>
                <option value="<?= e($k) ?>"<?= $statusFilter === $k ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn small" type="submit">Filter</button></noscript>
    </form>
    <?php if (!$msgReady): ?>
        <?= ui_msg('warn', 'Ticket messages are switched off because database migration 002 has not been applied (see docs/NOTIFICATIONS_SETUP.md). Confirming payments still works, but customers will not be e-mailed their ticket; they can still open it in their dashboard.') ?>
    <?php else:
        $off = [];
        foreach (NOTIFY_CHANNELS as $ch => $label) {
            if (!notify_transport()->isConfigured($ch)) { $off[] = $label; }
        }
        if ($off) { echo ui_msg('warn', 'Not configured in .env on this server: ' . implode(', ', $off) . '. Customers will not receive those messages (they are listed as "Not sent" below). Fill in the settings from docs/NOTIFICATIONS_SETUP.md, then use "Retry messages". Customers can always open their ticket in their dashboard.'); }
    endif; ?>
    <?php if ($review !== null): ?>
        <?php
            $hasPaid = (int) q_val(pdo(), "SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'succeeded'", [(int) $review['id']], 0) > 0;
            $holdOk = (string) $review['status'] === BK_PENDING && (string) $review['hold_expires_at'] > $nowStr;
            $cashOk = !empty($reviewLoc['accepts_cash'] ?? 1);
        ?>
        <div class="card">
            <h2 style="margin-top:0">Confirm Cash/UPI Payment &ndash; reservation <?= (int) $review['id'] ?></h2>
            <table>
                <tr><th>Reservation no</th><td><?= (int) $review['id'] ?></td></tr>
                <tr><th>Customer</th><td><?= e($review['name']) ?> <span class="muted"><?= e($review['email']) ?></span></td></tr>
                <tr><th>Parking place</th><td><?= e($review['location_name']) ?><div class="muted"><?= e($review['loc_address']) ?>, <?= e($review['loc_city']) ?></div></td></tr>
                <tr><th>Vehicle</th><td><?= e($review['car_number']) ?></td></tr>
                <tr><th>Time window</th><td><?= ui_dt($review['start_time']) ?> to <?= ui_dt($review['end_time']) ?> (<?= (int) $review['hours'] ?> h)</td></tr>
                <tr><th>Amount due</th><td><strong><?= ui_money((int) $review['cost']) ?></strong> <span class="muted">(set by the system from the reservation, not editable)</span></td></tr>
                <tr><th>Status</th><td><?= ui_badge((string) $review['status']) ?><?= $holdOk ? ' <span class="muted">held until ' . ui_dt((string) $review['hold_expires_at'], 'H:i') . '</span>' : '' ?></td></tr>
            </table>
            <?php if ($hasPaid): ?>
                <?= ui_msg('warn', 'A payment is already recorded for this reservation. Nothing more can be confirmed.') ?>
            <?php elseif (!$holdOk): ?>
                <?= ui_msg('err', 'The hold on this reservation has ended (or it was cancelled), so it cannot be confirmed and the space may now be free for others. Do not take payment for it. If you already received the money, refund it and ask the customer to make a new reservation.') ?>
            <?php elseif (!$cashOk): ?>
                <?= ui_msg('warn', 'This place is set to online payments only. Tick "At the place" in the place settings first if you accept cash or UPI at the place.') ?>
            <?php else: ?>
                <form method="post" action="admin_reservations.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="confirm_payment" value="<?= (int) $review['id'] ?>">
                    <input type="hidden" name="amount" value="<?= (int) $review['cost'] ?>">
                    <fieldset style="border:1px solid #ddd;border-radius:8px;margin:12px 0;padding:10px 14px">
                        <legend>How did the customer pay you?</legend>
                        <label style="display:block;font-weight:normal"><input type="radio" name="method" value="cash" required> Cash</label>
                        <label style="display:block;font-weight:normal"><input type="radio" name="method" value="upi" required> UPI paid directly to me (UPI received by owner)</label>
                    </fieldset>
                    <label style="display:block;font-weight:normal"><input type="checkbox" name="received" value="1" required>
                        I confirm that I have actually received <?= ui_money((int) $review['cost']) ?> for this reservation.</label>
                    <p class="muted">This is recorded under your name with the time. It is not a verified online payment. The reservation is confirmed, counted in your earnings, and the ticket is sent to the customer.</p>
                    <button class="btn" type="submit">Confirm payment received</button>
                    <a href="admin_reservations.php">Back</a>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <p class="muted">Online payments confirm a reservation by themselves once the gateway verifies the money. For cash, or UPI sent straight to you, use "Confirm Cash/UPI Payment" only after you have really received the money. Messages say "Sent" only when the provider accepted them; that is not proof the customer read them.</p>

    <?php if (!$rows): ?>
        <div class="card">No reservations yet.</div>
    <?php else: ?>
        <div class="tablewrap"><table>
            <tr><th>#</th><th>Place</th><th>Customer</th><th>When</th><th>Amount</th><th>Status</th><th>Payment</th><?php if ($msgReady): ?><th>Ticket messages</th><?php endif; ?><th></th></tr>
            <?php foreach ($rows as $r):
                $unpaid = (int) $r['paid'] === 0 && in_array($r['status'], [BK_PENDING, BK_EXPIRED], true);
            ?>
                <tr>
                    <td><?= (int) $r['id'] ?></td>
                    <td><?= e($r['location_name']) ?></td>
                    <td><?= e($r['name']) ?><div class="muted"><?= e($r['email']) ?> &middot; <?= e($r['car_number']) ?></div></td>
                    <td><?= ui_dt($r['start_time']) ?><div class="muted">to <?= ui_dt($r['end_time']) ?></div></td>
                    <td><?= ui_money((int) $r['cost']) ?><div class="muted">paid <?= ui_money((int) $r['paid']) ?></div></td>
                    <td><?= ui_badge((string) $r['status']) ?></td>
                    <td><?php
                        if ($r['pay_provider'] !== null) {
                            echo e(payment_method_label((string) $r['pay_provider']));
                        } elseif ($r['status'] === BK_PENDING && (string) $r['hold_expires_at'] > $nowStr) {
                            echo '<span class="badge warn">Awaiting payment</span><div class="muted">held until ' . ui_dt((string) $r['hold_expires_at'], 'H:i') . '</div>';
                        } elseif ($r['status'] === BK_EXPIRED || $r['status'] === BK_PENDING) {
                            echo '<span class="badge muted">Expired</span><div class="muted">cannot be confirmed</div>';
                        } else {
                            echo '<span class="muted">&ndash;</span>';
                        }
                    ?></td>
                    <?php if ($msgReady): $info = $nstat[(int) $r['id']] ?? ['requested' => ['email'], 'rows' => []]; ?>
                    <td><?= admin_notify_cell($info, $r['status'] === BK_CONFIRMED && $r['pay_provider'] !== null) ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($unpaid && $r['status'] === BK_PENDING && (string) $r['hold_expires_at'] > $nowStr): ?>
                            <a class="btn small" href="admin_reservations.php?confirm=<?= (int) $r['id'] ?>">Confirm Cash/UPI Payment</a>
                        <?php endif; ?>
                        <?php if ($msgReady && $r['status'] === BK_CONFIRMED && $r['pay_provider'] !== null && notify_needs_retry($info)): ?>
                            <form class="inline" method="post" action="admin_reservations.php">
                                <?= csrf_field() ?>
                                <button class="btn small alt" type="submit" name="retry_notify" value="<?= (int) $r['id'] ?>">Retry messages</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>
<?php endif; ?>
</div></body></html>
