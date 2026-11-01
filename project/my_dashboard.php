<?php
// User dashboard: my reservations, what is waiting for payment, what I have paid, tickets.
// A user only ever sees rows where bookings.user_id is their own id.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/reservations.php';
require_once __DIR__ . '/src/payments.php';
require_once __DIR__ . '/src/ui.php';

$userId = require_user();
$now = app_now();
$nowStr = $now->format(PK_DT);
$ready = new_schema_ready();

$flash = [];
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['cancel_booking'])) {
    csrf_check();
    $r = cancel_unpaid_reservation(pdo(), $userId, (int) $_POST['cancel_booking'], $now);
    if ($r['ok']) {
        header('Location: ' . app_url('my_dashboard.php?cancelled=1'));
        exit;
    }
    $flash[] = ['err', $r['error'] ?? 'Could not cancel.'];
}

// Messages after coming back from payment: only fixed wording, chosen by a keyword.
$payMessages = [
    'confirmed' => ['ok', 'Payment received. Your reservation is confirmed.'],
    'pending'   => ['warn', 'Your payment is still being processed by the gateway. This page will show "Confirmed" once it is settled; nothing is confirmed until then.'],
    'review'    => ['warn', 'We received your payment but could not confirm the reservation (the hold had run out and the space was taken). Please contact the place owner for a refund.'],
    'failed'    => ['err', 'The payment could not be verified, so nothing was charged to this reservation. If money left your account, contact support with your payment id.'],
];
if (isset($_GET['pay'], $payMessages[$_GET['pay']])) {
    $flash[] = $payMessages[$_GET['pay']];
}
if (isset($_GET['cancelled'])) {
    $flash[] = ['ok', 'The reservation was cancelled and the space has been released.'];
}

$rows = $ready ? list_user_reservations(pdo(), $userId, $now) : [];
$upcoming = $awaiting = $past = [];
$totalPaid = 0;
foreach ($rows as $r) {
    $totalPaid += (int) $r['paid'];
    if ($r['status'] === BK_PENDING && (string) $r['hold_expires_at'] > $nowStr) {
        $awaiting[] = $r;
    } elseif ($r['status'] === BK_CONFIRMED && (string) $r['end_time'] > $nowStr) {
        $upcoming[] = $r;
    } else {
        $past[] = $r;
    }
}

/** Print one table of reservations. */
function dash_table(array $rows, string $kind, string $nowStr): void
{
    if (!$rows) {
        echo '<div class="card muted">Nothing here.</div>';
        return;
    }
    echo '<div class="tablewrap"><table><tr><th>#</th><th>Place</th><th>When</th><th>Car</th><th>Amount</th><th>Status</th><th></th></tr>';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        echo '<tr><td>' . $id . '</td>';
        echo '<td>' . e($r['location_name']) . '<div class="muted">' . e($r['address'] ?? '') . ', ' . e($r['city'] ?? '') . '</div></td>';
        echo '<td>' . ui_dt($r['start_time']) . '<div class="muted">to ' . ui_dt($r['end_time']) . '</div></td>';
        echo '<td>' . e($r['car_number']) . '</td>';
        echo '<td>' . ui_money((int) $r['cost']) . '<div class="muted">paid ' . ui_money((int) $r['paid']) . '</div></td>';
        echo '<td>' . ui_badge((string) $r['status']);
        if ($r['status'] === BK_CONFIRMED && !empty($r['pay_provider'])) {
            echo '<div class="muted">' . e(payment_method_label((string) $r['pay_provider'])) . '</div>';
        }
        if ($kind === 'awaiting') {
            echo '<div class="muted">held until ' . ui_dt((string) $r['hold_expires_at'], 'H:i') . '. Pay the owner at the place, or online if offered; your ticket appears here after the payment is confirmed.</div>';
        }
        if ($kind === 'past' && (int) $r['paid'] > 0 && $r['status'] !== BK_CONFIRMED) {
            echo '<div class="muted">Payment received but the reservation was not confirmed: ask the place owner for a refund.</div>';
        }
        echo '</td><td>';
        if ($kind === 'awaiting') {
            echo '<a class="btn small" href="pay.php?booking=' . $id . '">Pay now</a> ';
            echo '<form class="inline" method="post" action="my_dashboard.php" onsubmit="return confirm(\'Cancel this reservation?\')">'
                . csrf_field() . '<button class="btn small danger" type="submit" name="cancel_booking" value="' . $id . '">Cancel</button></form>';
        } elseif ($r['status'] === BK_CONFIRMED) {
            echo '<a class="btn small" href="' . e(ticket_url('booking_confirmed.php', $id)) . '">Ticket</a> '
                . '<a class="btn small alt" href="' . e(ticket_url('generate_ticket.php', $id)) . '">PDF</a>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
}

ui_page_start('My dashboard', 'user', 'mine');
?>
<h1>My dashboard</h1>
<?php foreach ($flash as [$kind, $text]) { echo ui_msg($kind, $text); } ?>
<?php if (!$ready): ?>
    <?= ui_schema_missing_notice() ?>
<?php else: ?>
    <div class="stats">
        <div class="stat"><div class="n"><?= count($upcoming) ?></div><div class="l">Upcoming reservations</div></div>
        <div class="stat warn"><div class="n"><?= count($awaiting) ?></div><div class="l">Awaiting payment</div></div>
        <div class="stat"><div class="n"><?= ui_money($totalPaid) ?></div><div class="l">Total paid</div></div>
        <div class="stat"><div class="n"><?= count($past) ?></div><div class="l">Past / closed</div></div>
    </div>
    <p><a class="btn" href="find_parking.php">Find parking</a></p>

    <h2>Awaiting payment</h2>
    <?php dash_table($awaiting, 'awaiting', $nowStr); ?>
    <h2>Upcoming and current</h2>
    <?php dash_table($upcoming, 'upcoming', $nowStr); ?>
    <h2>Past and closed</h2>
    <?php dash_table($past, 'past', $nowStr); ?>
<?php endif; ?>
<?php ui_page_end();
