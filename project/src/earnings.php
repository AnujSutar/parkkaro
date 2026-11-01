<?php
/**
 * Earnings reports for an admin (place owner).
 *
 * "Earned" means: a payments row with status 'succeeded' (so it was verified, see src/payments.php)
 * whose reservation is 'confirmed' and sits at a place THIS admin owns. Nothing else is counted:
 * not unpaid reservations, not pending holds, not the old unverified `bookings.cost` values.
 * Cash recorded by the admin and gateway-verified money are reported separately and together.
 *
 * Date filter: by the day the payment was verified, [$from, $to] inclusive, both 'Y-m-d'.
 */
declare(strict_types=1);

require_once __DIR__ . '/reservations.php';
require_once __DIR__ . '/payments.php';

/** Normalise a 'Y-m-d' range to datetime bounds [from 00:00:00, day after $to 00:00:00). Returns null when invalid. */
function earnings_range(string $from, string $to, DateTimeZone $tz): ?array
{
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
    if ($f === false || $t === false || $f > $t || $f->format('Y-m-d') !== $from || $t->format('Y-m-d') !== $to) {
        return null;
    }
    if ($t->getTimestamp() - $f->getTimestamp() > 366 * 86400) {
        return null; // keep reports to about a year
    }
    return [$f->format(PK_DT), $t->modify('+1 day')->format(PK_DT)];
}

/**
 * @return array{
 *   total:int, count:int, online_total:int, online_count:int, cash_total:int, cash_count:int,
 *   by_location: array<int, array{location_id:int,name:string,count:int,total:int}>,
 *   by_day: array<int, array{day:string,count:int,total:int}>
 * }
 */
function earnings_summary(PDO $pdo, string $ownerUsername, string $fromDt, string $toDt): array
{
    $base = "FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN parking_locations l ON l.id = b.location_id
             WHERE l.owner_username = ? AND p.status = 'succeeded' AND b.status = 'confirmed'
               AND p.verified_at >= ? AND p.verified_at < ?";
    $args = [$ownerUsername, $fromDt, $toDt];

    $out = ['total' => 0, 'count' => 0, 'online_total' => 0, 'online_count' => 0, 'cash_total' => 0, 'cash_count' => 0,
            'by_location' => [], 'by_day' => []];

    foreach (q_all($pdo, "SELECT p.verification, COUNT(*) AS n, SUM(p.amount) AS s $base GROUP BY p.verification", $args) as $r) {
        $n = (int) $r['n'];
        $s = (int) $r['s'];
        $out['count'] += $n;
        $out['total'] += $s;
        if ($r['verification'] === VERIFY_CASH || $r['verification'] === VERIFY_UPI) {   // money received by the owner in person
            $out['cash_count'] += $n;
            $out['cash_total'] += $s;
        } else {
            $out['online_count'] += $n;
            $out['online_total'] += $s;
        }
    }

    foreach (q_all($pdo, "SELECT l.id AS location_id, l.name AS name, COUNT(*) AS n, SUM(p.amount) AS s $base GROUP BY l.id, l.name ORDER BY s DESC, l.name", $args) as $r) {
        $out['by_location'][] = ['location_id' => (int) $r['location_id'], 'name' => (string) $r['name'], 'count' => (int) $r['n'], 'total' => (int) $r['s']];
    }
    foreach (q_all($pdo, "SELECT SUBSTR(p.verified_at, 1, 10) AS d, COUNT(*) AS n, SUM(p.amount) AS s $base GROUP BY SUBSTR(p.verified_at, 1, 10) ORDER BY d", $args) as $r) {
        $out['by_day'][] = ['day' => (string) $r['d'], 'count' => (int) $r['n'], 'total' => (int) $r['s']];
    }
    return $out;
}

/**
 * Money that is NOT earned yet, shown next to the report so nobody mistakes it for income:
 *  - awaiting_payment: reservations whose hold is still running (unpaid)
 *  - needs_review: payments received but the reservation could not be confirmed (customer should be refunded)
 *
 * @return array{awaiting_count:int,awaiting_total:int,review: array<int, array>}
 */
function earnings_exposure(PDO $pdo, string $ownerUsername, DateTimeImmutable $now): array
{
    $awaiting = q_one(
        $pdo,
        "SELECT COUNT(*) AS n, COALESCE(SUM(b.cost), 0) AS s
         FROM bookings b JOIN parking_locations l ON l.id = b.location_id
         WHERE l.owner_username = ? AND b.status = 'pending_payment' AND b.hold_expires_at > ?",
        [$ownerUsername, $now->format(PK_DT)]
    ) ?? ['n' => 0, 's' => 0];

    $review = q_all(
        $pdo,
        "SELECT p.id AS payment_id, p.booking_id, p.amount, p.provider, p.provider_payment_id, p.verified_at,
                b.name, b.email, b.status AS booking_status, l.name AS location_name
         FROM payments p
         JOIN bookings b ON b.id = p.booking_id
         JOIN parking_locations l ON l.id = b.location_id
         WHERE l.owner_username = ? AND p.status = 'succeeded' AND b.status <> 'confirmed'
         ORDER BY p.verified_at DESC LIMIT 100",
        [$ownerUsername]
    );
    return ['awaiting_count' => (int) $awaiting['n'], 'awaiting_total' => (int) $awaiting['s'], 'review' => $review];
}

/** Verified earnings of the current calendar month, for the dashboard card. */
function earnings_this_month(PDO $pdo, string $ownerUsername, DateTimeImmutable $now): int
{
    $from = $now->modify('first day of this month')->setTime(0, 0)->format(PK_DT);
    $to = $now->modify('first day of next month')->setTime(0, 0)->format(PK_DT);
    return earnings_summary($pdo, $ownerUsername, $from, $to)['total'];
}
