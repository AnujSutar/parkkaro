<?php
// Admin: earnings from VERIFIED payments only (daily, monthly, custom range), plus money that is
// not earned yet. See src/earnings.php for exactly what is counted.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/earnings.php';
require_once __DIR__ . '/src/ui.php';

[$admin, $zone] = require_admin();
$now = app_now();
$tz = $now->getTimezone();
$ready = new_schema_ready();

$today = $now->format('Y-m-d');
$monthStart = $now->modify('first day of this month')->format('Y-m-d');
$presets = [
    'today'     => ['Today', $today, $today],
    'month'     => ['This month', $monthStart, $today],
    'lastmonth' => ['Last month', $now->modify('first day of last month')->format('Y-m-d'), $now->modify('last day of last month')->format('Y-m-d')],
    'year'      => ['This year', $now->format('Y') . '-01-01', $today],
];

$from = $monthStart;
$to = $today;
$presetKey = (string) ($_GET['p'] ?? '');
$rangeError = '';
if (isset($presets[$presetKey])) {
    [, $from, $to] = $presets[$presetKey];
} elseif (isset($_GET['from']) || isset($_GET['to'])) {
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));
    $presetKey = 'custom';
}
$bounds = earnings_range($from, $to, $tz);
if ($bounds === null) {
    $rangeError = 'Choose a valid date range (start on or before the end, at most one year).';
    $from = $monthStart;
    $to = $today;
    $bounds = earnings_range($from, $to, $tz);
}

$summary = $exposure = $todayTotal = $monthTotal = null;
$byMonth = [];
if ($ready) {
    $summary = earnings_summary(pdo(), $admin, $bounds[0], $bounds[1]);
    $exposure = earnings_exposure(pdo(), $admin, $now);
    $b = earnings_range($today, $today, $tz);
    $todayTotal = earnings_summary(pdo(), $admin, $b[0], $b[1])['total'];
    $b = earnings_range($monthStart, $today, $tz);
    $monthTotal = earnings_summary(pdo(), $admin, $b[0], $b[1])['total'];
    foreach ($summary['by_day'] as $d) {
        $m = substr($d['day'], 0, 7);
        $byMonth[$m] = ($byMonth[$m] ?? ['count' => 0, 'total' => 0]);
        $byMonth[$m]['count'] += $d['count'];
        $byMonth[$m]['total'] += $d['total'];
    }
}

ui_page_start('Earnings', 'admin', 'earnings', $admin);
?>
<h1>Earnings</h1>
<?php if ($rangeError !== '') { echo ui_msg('err', $rangeError); } ?>
<?php if (!$ready): ?>
    <?= ui_schema_missing_notice() ?>
<?php else: ?>
    <?= ui_msg('warn', 'Only verified payments are counted: money confirmed by the payment gateway, or cash you recorded yourself. Unpaid reservations and the old, unverified booking prices are not income here.') ?>

    <div class="stats">
        <div class="stat"><div class="n"><?= ui_money((int) $todayTotal) ?></div><div class="l">Earned today</div></div>
        <div class="stat"><div class="n"><?= ui_money((int) $monthTotal) ?></div><div class="l">Earned this month</div></div>
        <div class="stat warn"><div class="n"><?= ui_money((int) $exposure['awaiting_total']) ?></div><div class="l">Awaiting payment (<?= (int) $exposure['awaiting_count'] ?>) &ndash; not earned</div></div>
    </div>

    <form class="card" method="get" action="admin_earnings.php">
        <div>
            <?php foreach ($presets as $key => [$label]): ?>
                <a class="btn small<?= $presetKey === $key || ($presetKey === '' && $key === 'month') ? '' : ' alt' ?>" href="admin_earnings.php?p=<?= e($key) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="row">
            <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
            <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        </div>
        <button class="btn" type="submit">Show range</button>
    </form>

    <h2>Selected range: <?= e($from) ?> to <?= e($to) ?></h2>
    <div class="stats">
        <div class="stat"><div class="n"><?= ui_money($summary['total']) ?></div><div class="l">Total earned (<?= (int) $summary['count'] ?> payments)</div></div>
        <div class="stat"><div class="n"><?= ui_money($summary['online_total']) ?></div><div class="l">Online, gateway-verified (<?= (int) $summary['online_count'] ?>)</div></div>
        <div class="stat"><div class="n"><?= ui_money($summary['cash_total']) ?></div><div class="l">Cash / UPI received by you (<?= (int) $summary['cash_count'] ?>)</div></div>
    </div>

    <?php if ($summary['count'] === 0): ?>
        <div class="card">No verified payments in this range.</div>
    <?php else: ?>
        <h2>By day</h2>
        <div class="tablewrap"><table><tr><th>Day</th><th>Payments</th><th class="right">Earned</th></tr>
            <?php foreach ($summary['by_day'] as $d): ?>
                <tr><td><?= e($d['day']) ?></td><td><?= (int) $d['count'] ?></td><td class="right"><?= ui_money($d['total']) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <h2>By month</h2>
        <div class="tablewrap"><table><tr><th>Month</th><th>Payments</th><th class="right">Earned</th></tr>
            <?php foreach ($byMonth as $m => $v): ?>
                <tr><td><?= e($m) ?></td><td><?= (int) $v['count'] ?></td><td class="right"><?= ui_money($v['total']) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
        <h2>By parking place</h2>
        <div class="tablewrap"><table><tr><th>Place</th><th>Payments</th><th class="right">Earned</th></tr>
            <?php foreach ($summary['by_location'] as $l): ?>
                <tr><td><?= e($l['name']) ?></td><td><?= (int) $l['count'] ?></td><td class="right"><?= ui_money($l['total']) ?></td></tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>

    <?php if ($exposure['review']): ?>
        <h2>Payments that need your attention</h2>
        <?= ui_msg('err', 'These customers paid, but their reservation could not be confirmed (the hold had lapsed and the space was taken). The money is NOT counted as earnings. Please refund them.') ?>
        <div class="tablewrap"><table><tr><th>Payment</th><th>Reservation</th><th>Customer</th><th>Amount</th><th>Received</th></tr>
            <?php foreach ($exposure['review'] as $r): ?>
                <tr>
                    <td><?= e($r['provider']) ?> <?= e($r['provider_payment_id'] ?? '') ?></td>
                    <td>#<?= (int) $r['booking_id'] ?> at <?= e($r['location_name']) ?> (<?= e($r['booking_status']) ?>)</td>
                    <td><?= e($r['name']) ?><div class="muted"><?= e($r['email']) ?></div></td>
                    <td><?= ui_money((int) $r['amount']) ?></td>
                    <td><?= ui_dt($r['verified_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table></div>
    <?php endif; ?>
<?php endif; ?>
</div></body></html>
