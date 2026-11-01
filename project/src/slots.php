<?php
/**
 * Slot management shared by manage_slots.php (Kolhapur), manage_gad.php (Gadhinglaj)
 * and manage_ajara.php (Ajara). Uses the `slots` and `slot_status_history` tables
 * exactly as the original pages did.
 */
declare(strict_types=1);

const SLOT_STATUSES = ['available', 'booked'];
const SLOTS_PER_PAGE = 10;

/**
 * Change the status of several slots of one location, writing a history row for each.
 * The history row is written BEFORE the update, so old_status really is the old
 * status (the original code logged it after the update, so old = new).
 * Returns how many slots were changed.
 */
function update_slot_statuses(mysqli $db, string $location, array $slotIds, string $newStatus): int
{
    if (!in_array($newStatus, SLOT_STATUSES, true)) {
        return 0;
    }
    $ids = [];
    foreach ($slotIds as $raw) {
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $ids[$id] = $id;
        }
    }
    if (!$ids) {
        return 0;
    }

    $changed = 0;
    $db->begin_transaction();
    try {
        $history = $db->prepare(
            'INSERT INTO slot_status_history (slot_id, old_status, new_status, change_time)
             SELECT id, status, ?, NOW() FROM slots WHERE id = ? AND location = ?'
        );
        $update = $db->prepare('UPDATE slots SET status = ? WHERE id = ? AND location = ?');
        foreach ($ids as $id) {
            $history->bind_param('sis', $newStatus, $id, $location);
            $history->execute();
            $update->bind_param('sis', $newStatus, $id, $location);
            $update->execute();
            $changed += $update->affected_rows > 0 ? 1 : 0;
        }
        $history->close();
        $update->close();
        $db->commit();
    } catch (Throwable $ex) {
        $db->rollback();
        throw $ex;
    }
    return $changed;
}

/**
 * Everything a slot-management page needs. Handles the bulk-update POST (with CSRF).
 */
function slot_page_data(mysqli $db, string $location): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        csrf_check();
        if (isset($_POST['bulk_status'], $_POST['selected_slots']) && is_array($_POST['selected_slots'])) {
            update_slot_statuses($db, $location, $_POST['selected_slots'], (string) $_POST['bulk_status']);
        }
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * SLOTS_PER_PAGE;
    $search = trim((string) ($_GET['search'] ?? ''));
    $statusFilter = (string) ($_GET['status_filter'] ?? '');
    if (!in_array($statusFilter, SLOT_STATUSES, true)) {
        $statusFilter = '';
    }

    $sql = 'SELECT * FROM slots WHERE location = ?';
    $types = 's';
    $params = [$location];
    if ($search !== '') {
        $sql .= ' AND slot_number LIKE ?';
        $types .= 's';
        $params[] = '%' . addcslashes($search, '%_\\') . '%';
    }
    if ($statusFilter !== '') {
        $sql .= ' AND status = ?';
        $types .= 's';
        $params[] = $statusFilter;
    }
    // LIMIT/OFFSET must be bound as integers ('ii'); the old pages bound them as strings.
    $sql .= ' LIMIT ? OFFSET ?';
    $types .= 'ii';
    $params[] = SLOTS_PER_PAGE;
    $params[] = $offset;
    $slotsResult = db_select($db, $sql, $types, $params);

    $totalSlots = (int) db_scalar($db, 'SELECT COUNT(*) FROM slots WHERE location = ?', 's', [$location]);
    $availableSlots = (int) db_scalar($db, "SELECT COUNT(*) FROM slots WHERE location = ? AND status = 'available'", 's', [$location]);
    $bookedSlots = (int) db_scalar($db, "SELECT COUNT(*) FROM slots WHERE location = ? AND status = 'booked'", 's', [$location]);

    $historyQuery = null;
    $slotId = filter_var($_GET['slot_id'] ?? null, FILTER_VALIDATE_INT);
    if ($slotId !== false && $slotId !== null && $slotId > 0) {
        $historyQuery = db_select(
            $db,
            'SELECT h.* FROM slot_status_history h JOIN slots s ON s.id = h.slot_id
             WHERE h.slot_id = ? AND s.location = ? ORDER BY h.change_time DESC',
            'is', [$slotId, $location]
        );
    }

    return [
        'search' => $search, 'statusFilter' => $statusFilter, 'slotsResult' => $slotsResult,
        'totalPages' => max(1, (int) ceil($totalSlots / SLOTS_PER_PAGE)),
        'availableSlots' => $availableSlots, 'bookedSlots' => $bookedSlots, 'historyQuery' => $historyQuery,
    ];
}

/**
 * Numbers and recent bookings for the per-city slot dashboards
 * (dashboards/kolhapur1_dashboard.php, dashboards/gadhi1_dashboard.php, revenue.php).
 *
 * NOTE: these pages were written for an older slot-based schema (bookings.location,
 * user_email, slot_number, status, timestamp). That is not the schema the current
 * booking pages write to (location_name, car_number, ...). The queries are kept as
 * they were, only made safe; confirm the schema before relying on these pages.
 */
function slot_dashboard_data(mysqli $db, string $location): array
{
    return [
        'totalSlots'    => (int) db_scalar($db, 'SELECT COUNT(*) FROM slots WHERE location = ?', 's', [$location]),
        'bookedSlots'   => (int) db_scalar($db, "SELECT COUNT(*) FROM slots WHERE location = ? AND status = 'booked'", 's', [$location]),
        'availableSlots' => (int) db_scalar($db, "SELECT COUNT(*) FROM slots WHERE location = ? AND status = 'available'", 's', [$location]),
        'todayBookings' => (int) db_scalar($db, 'SELECT COUNT(*) FROM bookings WHERE DATE(timestamp) = CURDATE() AND location = ?', 's', [$location]),
        'bookings'      => db_select($db, 'SELECT * FROM bookings WHERE location = ? ORDER BY timestamp DESC LIMIT 10', 's', [$location]),
    ];
}
