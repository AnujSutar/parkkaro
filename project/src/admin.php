<?php
/**
 * Actions shared by view_bookings.php and manage_bookings.php.
 * Every action is a POST with a CSRF token, and every query is limited to the
 * logged-in admin's zone with a prepared statement.
 */
declare(strict_types=1);

/**
 * True once migration 001 is fully in place: the payments table AND the new bookings columns that the
 * dashboard and the delete protection rely on. Until then the old behaviour is used unchanged.
 * (mysqli is in STRICT mode here, so a missing table or column throws and means "not upgraded yet".)
 */
function payments_table_exists(mysqli $db): bool
{
    try {
        $db->query('SELECT 1 FROM payments LIMIT 1')->free();
        $db->query('SELECT status, location_id, start_time, end_time FROM bookings LIMIT 1')->free();
        return true;
    } catch (mysqli_sql_exception $ex) {
        return false;
    }
}

/**
 * Process delete / clear-all / add-booking requests.
 *
 * Bookings that have a payment record are NEVER deleted here: they are the history that earnings
 * are calculated from, and deleting them would also hide money that was really received.
 * @return string[] error messages to show (empty when nothing went wrong)
 */
function handle_admin_booking_actions(mysqli $db, string $zone): array
{
    $errors = [];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return $errors;
    }
    csrf_check();

    $protect = payments_table_exists($db)
        ? ' AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = bookings.id)'
        : '';

    if (isset($_POST['delete_id'])) {
        $id = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $stmt = $db->prepare('DELETE FROM bookings WHERE id = ? AND admin_zone = ?' . $protect);
            $stmt->bind_param('is', $id, $zone);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted === 0 && $protect !== '' && (int) db_scalar($db, 'SELECT COUNT(*) FROM payments WHERE booking_id = ?', 'i', [$id]) > 0) {
                $errors[] = 'This booking has a payment record and cannot be deleted.';
            }
        }
    }

    if (isset($_POST['clear_all'])) {
        $stmt = $db->prepare('DELETE FROM bookings WHERE admin_zone = ?' . $protect);
        $stmt->bind_param('s', $zone);
        $stmt->execute();
        $stmt->close();
        if ($protect !== '') {
            $kept = (int) db_scalar(
                $db,
                'SELECT COUNT(*) FROM bookings WHERE admin_zone = ? AND EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = bookings.id)',
                's', [$zone]
            );
            if ($kept > 0) {
                $errors[] = "$kept booking(s) with payment records were kept; they cannot be deleted.";
            }
        }
    }

    if (isset($_POST['add_booking'])) {
        [$errors, $b] = validate_admin_booking_input($_POST);
        if (!$errors) {
            $stmt = $db->prepare(
                'INSERT INTO bookings (location_name, admin_zone, name, email, car_number, hours, cost, booking_time, lat, lng, timestamp)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW())'
            );
            $stmt->bind_param('sssssiiss', $b['location'], $zone, $b['name'], $b['email'], $b['car'],
                $b['hours'], $b['cost'], $b['lat'], $b['lng']);
            $stmt->execute();
            $stmt->close();
        }
    }
    return $errors;
}

/** Bookings of one zone, optionally filtered by car number or name (LIKE, escaped). */
function list_zone_bookings(mysqli $db, string $zone, string $filter): mysqli_result
{
    if ($filter === '') {
        return db_select($db, 'SELECT * FROM bookings WHERE admin_zone = ? ORDER BY booking_time DESC', 's', [$zone]);
    }
    // Escape LIKE wildcards typed by the user so "%" searches for a literal percent sign.
    $like = '%' . addcslashes($filter, '%_\\') . '%';
    return db_select(
        $db,
        'SELECT * FROM bookings WHERE admin_zone = ? AND (car_number LIKE ? OR name LIKE ?) ORDER BY booking_time DESC',
        'sss', [$zone, $like, $like]
    );
}
