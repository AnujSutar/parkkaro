<?php
/**
 * Reservations with a time window and per-moment capacity.
 *
 * A reservation (a row in `bookings` with location_id set) occupies one space at its
 * location during [start_time, end_time). The end instant is free again, so one car
 * can leave at 10:00 and another arrive at 10:00.
 *
 * Which reservations take up space?
 *   - status 'confirmed'
 *   - status 'pending_payment' while hold_expires_at is still in the future
 *   (cancelled / expired ones do not)
 *
 * Capacity is respected at EVERY moment of the requested window: the new reservation
 * fits when  (highest number of overlapping reservations at any one instant) < capacity.
 * Counting every overlapping row would wrongly refuse e.g. capacity 1 with bookings 08-09
 * and 11-12 when someone asks for 09-11, so the check works on peak usage instead.
 *
 * Needs src/bootstrap.php, src/dbx.php and src/booking.php to be loaded.
 */
declare(strict_types=1);

require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/notify.php';

const BK_PENDING   = 'pending_payment';
const BK_CONFIRMED = 'confirmed';
const BK_CANCELLED = 'cancelled';
const BK_EXPIRED   = 'expired';

/** Minutes an unpaid reservation keeps its place. */
const PAYMENT_HOLD_MINUTES = 20;
/** Shortest / longest single reservation (the longest reuses the existing MAX_BOOKING_HOURS). */
const MIN_RESERVATION_MINUTES = 30;
/** How far ahead a reservation may start. */
const MAX_ADVANCE_DAYS = 30;
/** A start time this many minutes in the past is still accepted (clock differences, slow typists). */
const START_GRACE_MINUTES = 5;

/**
 * True when migration 001 has been applied (the new tables and bookings columns exist).
 * The older pages call this so that they keep working on a database that has not been
 * upgraded yet; the new pages call it to show a "run the migration" notice instead of an error.
 * Any failure (no pdo_mysql extension, no connection, missing table) simply means "not ready".
 */
function new_schema_ready(?PDO $pdo = null): bool
{
    static $cache = null;
    $explicit = $pdo !== null;   // a connection passed in (tests) is never cached
    if (!$explicit && $cache !== null) {
        return $cache;
    }
    try {
        $pdo = $pdo ?? pdo();
        $pdo->query('SELECT 1 FROM parking_locations LIMIT 1')->closeCursor();
        $pdo->query('SELECT 1 FROM payments LIMIT 1')->closeCursor();
        $pdo->query('SELECT user_id, location_id, start_time, end_time, status, hold_expires_at, cancelled_at FROM bookings LIMIT 1')->closeCursor();
        $ready = true;
    } catch (Throwable $ex) {
        $ready = false;
    }
    if (!$explicit) {
        $cache = $ready;
    }
    return $ready;
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no database)                                          */
/* ------------------------------------------------------------------ */

/** Parse "2026-10-20T09:30" (datetime-local input) or "2026-10-20 09:30[:00]" in the app time zone. */
function parse_local_datetime(string $raw, DateTimeZone $tz): ?DateTimeImmutable
{
    $raw = trim($raw);
    foreach (['!Y-m-d\TH:i', '!Y-m-d H:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw, $tz);
        $errors = DateTimeImmutable::getLastErrors();
        if ($dt !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt;
        }
    }
    return null;
}

/**
 * Highest number of intervals that overlap at any single instant inside the window.
 * Intervals and window are 'Y-m-d H:i:s' strings; each interval is [start, end).
 *
 * @param array<int, array{0:string,1:string}> $intervals
 */
function peak_concurrency(array $intervals, string $windowStart, string $windowEnd): int
{
    $events = [];
    foreach ($intervals as [$start, $end]) {
        $s = max($start, $windowStart);
        $e = min($end, $windowEnd);
        if ($s >= $e) {
            continue; // does not overlap the window
        }
        $events[] = [$s, 1];
        $events[] = [$e, -1];
    }
    // At the same instant, departures (-1) are processed before arrivals (+1):
    // an interval ending at 10:00 does not overlap one starting at 10:00.
    usort($events, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $current = 0;
    $peak = 0;
    foreach ($events as [, $delta]) {
        $current += $delta;
        $peak = max($peak, $current);
    }
    return $peak;
}

/** Hours charged: every started hour counts (90 minutes = 2 hours). */
function billed_hours(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    $minutes = intdiv($end->getTimestamp() - $start->getTimestamp() + 59, 60);
    return max(1, (int) ceil($minutes / 60));
}

/**
 * Validate the requested time window against the booking rules and the location's opening hours.
 *
 * @param array $in       'start' and 'end' as typed by the user
 * @param array $location parking_locations row (needs open_from / open_until)
 * @return array{0: string[], 1: ?array{start:string,end:string,hours:int}}  [errors, window]
 */
function validate_reservation_window(array $in, array $location, DateTimeImmutable $now): array
{
    $tz = $now->getTimezone();
    $errors = [];
    $start = parse_local_datetime((string) ($in['start'] ?? ''), $tz);
    $end = parse_local_datetime((string) ($in['end'] ?? ''), $tz);
    if ($start === null) {
        $errors[] = 'Enter a valid start date and time.';
    }
    if ($end === null) {
        $errors[] = 'Enter a valid end date and time.';
    }
    if ($errors) {
        return [$errors, null];
    }

    if ($end <= $start) {
        return [['The end time must be after the start time.'], null];
    }
    $minutes = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
    if ($minutes < MIN_RESERVATION_MINUTES) {
        $errors[] = 'The minimum reservation is ' . MIN_RESERVATION_MINUTES . ' minutes.';
    }
    if ($minutes > MAX_BOOKING_HOURS * 60) {
        $errors[] = 'The longest reservation is ' . MAX_BOOKING_HOURS . ' hours.';
    }
    if ($start < $now->modify('-' . START_GRACE_MINUTES . ' minutes')) {
        $errors[] = 'The start time is in the past.';
    }
    if ($start > $now->modify('+' . MAX_ADVANCE_DAYS . ' days')) {
        $errors[] = 'You can reserve at most ' . MAX_ADVANCE_DAYS . ' days ahead.';
    }

    // Opening hours: only when the owner set both times. The stay must fit inside one day's opening window.
    $from = (string) ($location['open_from'] ?? '');
    $until = (string) ($location['open_until'] ?? '');
    if ($from !== '' && $until !== '') {
        $fromHm = substr($from, 0, 5);
        $untilHm = substr($until, 0, 5);
        if ($start->format('Y-m-d') !== $end->format('Y-m-d')
            || $start->format('H:i') < $fromHm || $end->format('H:i') > $untilHm) {
            $errors[] = "This place is open $fromHm to $untilHm; the whole stay must fall inside that time on a single day.";
        }
    }

    if ($errors) {
        return [$errors, null];
    }
    return [[], [
        'start' => $start->format(PK_DT),
        'end'   => $end->format(PK_DT),
        'hours' => billed_hours($start, $end),
    ]];
}

/* ------------------------------------------------------------------ */
/* Capacity                                                            */
/* ------------------------------------------------------------------ */

/**
 * Highest number of spaces in use at one moment between $start and $end.
 * $excludeBookingId lets a reservation ignore itself (used when re-confirming a paid one).
 */
function location_peak_usage(PDO $pdo, int $locationId, string $start, string $end, DateTimeImmutable $now, ?int $excludeBookingId = null): int
{
    $rows = q_all(
        $pdo,
        "SELECT start_time, end_time FROM bookings
         WHERE location_id = ? AND start_time < ? AND end_time > ?
           AND (status = 'confirmed' OR (status = 'pending_payment' AND hold_expires_at > ?))
           AND id <> ?",
        [$locationId, $end, $start, $now->format(PK_DT), $excludeBookingId ?? 0]
    );
    $intervals = [];
    foreach ($rows as $r) {
        $intervals[] = [(string) $r['start_time'], (string) $r['end_time']];
    }
    return peak_concurrency($intervals, $start, $end);
}

/** Free spaces for the whole window (capacity minus peak usage, never below 0). */
function location_free_spaces(PDO $pdo, array $location, string $start, string $end, DateTimeImmutable $now): int
{
    $peak = location_peak_usage($pdo, (int) $location['id'], $start, $end, $now);
    return max(0, (int) $location['capacity'] - $peak);
}

/** Mark unpaid reservations whose hold ran out as 'expired'. (Capacity logic does not depend on this running.) */
function expire_stale_holds(PDO $pdo, DateTimeImmutable $now): int
{
    $st = q_run(
        $pdo,
        "UPDATE bookings SET status = 'expired' WHERE status = 'pending_payment' AND hold_expires_at IS NOT NULL AND hold_expires_at <= ?",
        [$now->format(PK_DT)]
    );
    return $st->rowCount();
}

/* ------------------------------------------------------------------ */
/* Creating, confirming, cancelling                                    */
/* ------------------------------------------------------------------ */

/**
 * Reserve a space for a logged-in user. The reservation starts as 'pending_payment' and holds its
 * place for PAYMENT_HOLD_MINUTES; it becomes 'confirmed' only when a verified payment is recorded.
 *
 * @param array $input 'start', 'end', optional 'car_number' (defaults to the one on the user's account)
 * @return array{ok:true,booking_id:int,amount:int,hold_expires_at:string}|array{ok:false,errors:string[]}
 */
function create_reservation(PDO $pdo, int $userId, int $locationId, array $input, DateTimeImmutable $now): array
{
    $user = q_one($pdo, 'SELECT id, first_name, last_name, email, car_number FROM users WHERE id = ?', [$userId]);
    if ($user === null) {
        return ['ok' => false, 'errors' => ['Please log in again.']];
    }
    $car = normalize_car_number((string) (($input['car_number'] ?? '') !== '' ? $input['car_number'] : $user['car_number']));
    if (preg_match('/^[A-Z0-9]{4,12}$/', $car) !== 1) {
        return ['ok' => false, 'errors' => ['Enter a valid car number (4-12 letters or digits).']];
    }

    // Where to send the ticket besides the account e-mail (only used when migration 002 is applied).
    $via = [];
    foreach (['whatsapp', 'sms'] as $ch) {
        if (!empty($input['notify_' . $ch])) {
            $via[] = $ch;
        }
    }
    $phone = null;
    $phoneRaw = trim((string) ($input['phone'] ?? ''));
    if ($phoneRaw !== '' || $via) {
        $phone = normalize_phone($phoneRaw);
        if ($phone === null) {
            return ['ok' => false, 'errors' => ['Enter a valid mobile number (a 10-digit Indian number, or with the country code like +91...), or untick WhatsApp / SMS.']];
        }
    }

    return with_write_tx($pdo, static function (PDO $pdo) use ($user, $car, $locationId, $input, $now, $via, $phone): array {
        expire_stale_holds($pdo, $now);

        $loc = lock_location($pdo, $locationId);
        if ($loc === null || (int) $loc['is_active'] !== 1) {
            return ['ok' => false, 'errors' => ['This parking place is not available for reservations.']];
        }

        [$errors, $win] = validate_reservation_window($input, $loc, $now);
        if ($errors || $win === null) {
            return ['ok' => false, 'errors' => $errors];
        }

        // The same car cannot be in two places (or twice in one place) at once.
        $carClash = (int) q_val(
            $pdo,
            "SELECT COUNT(*) FROM bookings
             WHERE car_number = ? AND location_id IS NOT NULL AND start_time < ? AND end_time > ?
               AND (status = 'confirmed' OR (status = 'pending_payment' AND hold_expires_at > ?))",
            [$car, $win['end'], $win['start'], $now->format(PK_DT)],
            0
        );
        if ($carClash > 0) {
            return ['ok' => false, 'errors' => ['This car already has a reservation that overlaps these times.']];
        }

        $peak = location_peak_usage($pdo, $locationId, $win['start'], $win['end'], $now);
        if ($peak >= (int) $loc['capacity']) {
            return ['ok' => false, 'errors' => ['Sorry, there is no space left at this place for the whole time you asked for.']];
        }

        $amount = (int) $loc['hourly_rate'] * $win['hours'];
        $holdUntil = $now->modify('+' . PAYMENT_HOLD_MINUTES . ' minutes')->format(PK_DT);
        $nowStr = $now->format(PK_DT);
        $name = trim($user['first_name'] . ' ' . $user['last_name']);

        // The old NOT NULL columns (location_name, hours, cost, booking_time ...) are filled too, so the
        // older admin pages keep showing these reservations.
        q_run(
            $pdo,
            'INSERT INTO bookings
               (location_name, admin_zone, lat, lng, name, email, car_number, hours, cost, booking_time, `timestamp`,
                user_id, location_id, start_time, end_time, status, hold_expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (string) $loc['name'], (string) $loc['admin_zone'],
                $loc['lat'] === null ? '0' : (string) $loc['lat'], $loc['lng'] === null ? '0' : (string) $loc['lng'],
                $name, (string) $user['email'], $car, $win['hours'], $amount, $win['start'], $nowStr,
                (int) $user['id'], $locationId, $win['start'], $win['end'], BK_PENDING, $holdUntil,
            ]
        );
        $newId = (int) $pdo->lastInsertId();
        if ($phone !== null && notify_schema_ready($pdo)) {
            q_run($pdo, 'UPDATE bookings SET contact_phone = ?, notify_via = ? WHERE id = ?', [$phone, $via ? implode(',', $via) : null, $newId]);
        }
        return ['ok' => true, 'booking_id' => $newId, 'amount' => $amount, 'hold_expires_at' => $holdUntil];
    });
}

/**
 * Turn a paid reservation into a confirmed one. Call ONLY after a payment row has been marked
 * succeeded in the same transaction. Returns false when the place can no longer be given
 * (hold lapsed AND the space was taken meanwhile, or the booking period is over).
 */
function confirm_booking_in_tx(PDO $pdo, array $booking, DateTimeImmutable $now): bool
{
    if ($booking['status'] === BK_CONFIRMED) {
        notify_queue((int) $booking['id']);   // sent after the transaction commits; once-only is enforced by the notifications table
        return true;
    }
    $nowStr = $now->format(PK_DT);
    $holdValid = $booking['status'] === BK_PENDING && (string) $booking['hold_expires_at'] > $nowStr;
    if ($booking['location_id'] === null || $booking['end_time'] === null) {
        return false; // not a reservation made through the new flow
    }
    if (!$holdValid) {
        // The hold ran out (or the user cancelled) before the money arrived. Honour the payment only if
        // the space is still free; the lock on the location row makes this check safe.
        if ((string) $booking['end_time'] <= $nowStr) {
            return false;
        }
        $loc = lock_location($pdo, (int) $booking['location_id']);
        if ($loc === null) {
            return false;
        }
        $peak = location_peak_usage($pdo, (int) $loc['id'], (string) $booking['start_time'], (string) $booking['end_time'], $now, (int) $booking['id']);
        if ($peak >= (int) $loc['capacity']) {
            return false;
        }
    }
    q_run($pdo, "UPDATE bookings SET status = 'confirmed', hold_expires_at = NULL, cancelled_at = NULL WHERE id = ?", [(int) $booking['id']]);
    notify_queue((int) $booking['id']);
    return true;
}

/**
 * A user cancels their own UNPAID reservation (frees the space immediately).
 * Paid reservations cannot be cancelled here: refunds are not implemented, and the app does not pretend otherwise.
 *
 * @return array{ok:bool,error?:string}
 */
function cancel_unpaid_reservation(PDO $pdo, int $userId, int $bookingId, DateTimeImmutable $now): array
{
    return with_write_tx($pdo, static function (PDO $pdo) use ($userId, $bookingId, $now): array {
        $b = q_one($pdo, 'SELECT id, status, user_id FROM bookings WHERE id = ? AND user_id = ?', [$bookingId, $userId]);
        if ($b === null) {
            return ['ok' => false, 'error' => 'Reservation not found.'];
        }
        if ($b['status'] !== BK_PENDING) {
            return ['ok' => false, 'error' => 'Only reservations that are still waiting for payment can be cancelled here.'];
        }
        $paid = (int) q_val($pdo, "SELECT COUNT(*) FROM payments WHERE booking_id = ? AND status = 'succeeded'", [$bookingId], 0);
        if ($paid > 0) {
            return ['ok' => false, 'error' => 'This reservation has a payment recorded and cannot be cancelled here.'];
        }
        q_run($pdo, "UPDATE bookings SET status = 'cancelled', cancelled_at = ?, hold_expires_at = NULL WHERE id = ?", [$now->format(PK_DT), $bookingId]);
        return ['ok' => true];
    });
}

/* ------------------------------------------------------------------ */
/* Listings                                                            */
/* ------------------------------------------------------------------ */

/**
 * A user's reservations (newest first), each with what has been paid. Runs the hold sweeper first.
 * Only reservations made through the new flow (location_id set) appear.
 */
function list_user_reservations(PDO $pdo, int $userId, DateTimeImmutable $now): array
{
    expire_stale_holds($pdo, $now);
    return q_all(
        $pdo,
        "SELECT b.id, b.location_id, b.location_name, b.car_number, b.hours, b.cost, b.status,
                b.start_time, b.end_time, b.hold_expires_at,
                l.city, l.address, l.kind,
                COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.id AND p.status = 'succeeded'), 0) AS paid,
                (SELECT p2.provider FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = 'succeeded' ORDER BY p2.id DESC LIMIT 1) AS pay_provider
         FROM bookings b
         LEFT JOIN parking_locations l ON l.id = b.location_id
         WHERE b.user_id = ? AND b.location_id IS NOT NULL
         ORDER BY b.start_time DESC, b.id DESC",
        [$userId]
    );
}

/** Reservations for the locations owned by one admin, optionally limited to one status. */
function list_owner_reservations(PDO $pdo, string $ownerUsername, ?string $status, DateTimeImmutable $now, int $limit = 200): array
{
    expire_stale_holds($pdo, $now);
    $sql = "SELECT b.id, b.location_id, b.location_name, b.name, b.email, b.car_number, b.hours, b.cost, b.status,
                   b.start_time, b.end_time, b.hold_expires_at,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.booking_id = b.id AND p.status = 'succeeded'), 0) AS paid,
                (SELECT p2.provider FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = 'succeeded' ORDER BY p2.id DESC LIMIT 1) AS pay_provider
            FROM bookings b
            JOIN parking_locations l ON l.id = b.location_id
            WHERE l.owner_username = ?";
    $params = [$ownerUsername];
    if ($status !== null && $status !== '') {
        $sql .= ' AND b.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY b.start_time DESC, b.id DESC LIMIT ?';
    $params[] = max(1, min(500, $limit));
    return q_all($pdo, $sql, $params);
}
