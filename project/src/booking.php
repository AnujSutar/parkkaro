<?php
/**
 * Booking rules: location catalogue, input validation and the "create booking"
 * routine that keeps availability and insertion consistent.
 *
 * validate_booking_input() is pure (no database), so it is easy to unit test.
 * create_booking() needs a mysqli connection.
 */
declare(strict_types=1);

/** Maximum number of cars parked at one location at the same moment. */
const PARKING_CAPACITY = 10;
/** Longest single booking, in hours. (Business rule assumed; change here.) */
const MAX_BOOKING_HOURS = 24;

/**
 * Catalogue of bookable locations, copied from the original city pages.
 * The server decides zone, latitude and longitude from the chosen name, so a
 * visitor cannot invent a location or a zone.
 */
function booking_locations(string $set): array
{
    $sets = [
        // kolhapur_booking.php
        'kolhapur' => [
            ['name' => 'Kolhapur Bus Stand Parking', 'admin_zone' => 'Zone H', 'lat' => 16.7050, 'lng' => 74.2425],
            ['name' => 'Kolhapur Market Parking',    'admin_zone' => 'Zone I', 'lat' => 16.7075, 'lng' => 74.2400],
            ['name' => 'Kolhapur ST Depot Parking',  'admin_zone' => 'Zone J', 'lat' => 16.7100, 'lng' => 74.2440],
        ],
        // ajara_booking.php
        'ajara' => [
            ['name' => 'Ajara Bus Stand Parking', 'admin_zone' => 'Zone A', 'lat' => 16.2745, 'lng' => 74.1375],
            ['name' => 'Ajara Market Parking',    'admin_zone' => 'Zone B', 'lat' => 16.2760, 'lng' => 74.1395],
            ['name' => 'Ajara ST Depot Parking',  'admin_zone' => 'Zone C', 'lat' => 16.2780, 'lng' => 74.1410],
        ],
        // gadhinjlaj_booking.php
        'gadhinglaj' => [
            ['name' => 'Gadhinglaj Bus Stand Parking', 'admin_zone' => 'Zone D', 'lat' => 16.2261, 'lng' => 74.1951],
            ['name' => 'Gadhinglaj Market Parking',    'admin_zone' => 'Zone E', 'lat' => 16.2300, 'lng' => 74.1905],
            ['name' => 'Gadhinglaj ST Depot Parking',  'admin_zone' => 'Zone G', 'lat' => 16.2345, 'lng' => 74.1980],
        ],
        // booking.html + book.php (the single "choose city" form); names come from confirm.php
        'form' => [
            ['name' => 'Ajara Main Market',          'admin_zone' => 'Zone A', 'lat' => 16.6100, 'lng' => 74.2120],
            ['name' => 'Ajara Bus Stand',            'admin_zone' => 'Zone B', 'lat' => 16.6115, 'lng' => 74.2080],
            ['name' => 'Ajara Railway Station',      'admin_zone' => 'Zone C', 'lat' => 16.6125, 'lng' => 74.2050],
            ['name' => 'Gadhinglaj Market',          'admin_zone' => 'Zone A', 'lat' => 16.2365, 'lng' => 74.2465],
            ['name' => 'Gadhinglaj Bus Stand',       'admin_zone' => 'Zone B', 'lat' => 16.2370, 'lng' => 74.2440],
            ['name' => 'Gadhinglaj Railway Station', 'admin_zone' => 'Zone C', 'lat' => 16.2380, 'lng' => 74.2410],
            ['name' => 'Kolhapur Railway Station',   'admin_zone' => 'Zone A', 'lat' => 16.7053, 'lng' => 74.2430],
            ['name' => 'Shahu Market',               'admin_zone' => 'Zone B', 'lat' => 16.7070, 'lng' => 74.2465],
            ['name' => 'Kolhapur Bus Stand',         'admin_zone' => 'Zone C', 'lat' => 16.7090, 'lng' => 74.2410],
        ],
    ];
    if (!isset($sets[$set])) {
        throw new InvalidArgumentException('Unknown location set');
    }
    return $sets[$set];
}

/** Normalise a car number: upper case, no spaces or dashes. */
function normalize_car_number(string $raw): string
{
    return strtoupper(preg_replace('/[\s\-]+/', '', trim($raw)) ?? '');
}

/**
 * Validate and clean booking input.
 *
 * @param array $in         raw values: name, email, car_number, hours, location
 * @param array $locations  catalogue from booking_locations()
 * @param int   $ratePerHour price per hour in rupees (decided by the server)
 * @return array{0: string[], 1: array}  [errors, cleanValues]
 */
function validate_booking_input(array $in, array $locations, int $ratePerHour): array
{
    $errors = [];

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 100
        || preg_match('/^[\p{L}][\p{L}\p{M} .\'\-]*$/u', $name) !== 1) {
        $errors[] = 'Enter a valid name (2-100 letters).';
    }

    $email = trim((string) ($in['email'] ?? ''));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address.';
    }

    $car = normalize_car_number((string) ($in['car_number'] ?? ''));
    if (preg_match('/^[A-Z0-9]{4,12}$/', $car) !== 1) {
        $errors[] = 'Enter a valid car number (4-12 letters or digits).';
    }

    $hoursRaw = $in['hours'] ?? '';
    $hours = filter_var($hoursRaw, FILTER_VALIDATE_INT);
    if ($hours === false || $hours < 1 || $hours > MAX_BOOKING_HOURS) {
        $errors[] = 'Hours must be a whole number from 1 to ' . MAX_BOOKING_HOURS . '.';
        $hours = 0;
    }

    $match = null;
    $wanted = (string) ($in['location'] ?? '');
    foreach ($locations as $loc) {
        if ($loc['name'] === $wanted) {
            $match = $loc;
            break;
        }
    }
    if ($match === null) {
        $errors[] = 'Choose one of the listed parking locations.';
    }

    $clean = [
        'name'       => $name,
        'email'      => $email,
        'car_number' => $car,
        'hours'      => (int) $hours,
        'location'   => $match['name'] ?? '',
        'admin_zone' => $match['admin_zone'] ?? '',
        'lat'        => (string) ($match['lat'] ?? '0'),
        'lng'        => (string) ($match['lng'] ?? '0'),
        'cost'       => $ratePerHour * (int) $hours,
    ];
    return [$errors, $clean];
}

/** Cars currently parked: bookings whose booked hours have not run out yet. */
function count_active_bookings(mysqli $db, string $location): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM bookings
         WHERE location_name = ? AND DATE_ADD(booking_time, INTERVAL hours HOUR) > NOW()'
    );
    $stmt->bind_param('s', $location);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count;
}

/**
 * Check capacity and insert the booking as one protected step.
 *
 * Two visitors pressing "Book" at the same moment could both see "1 spot left".
 * A MySQL named lock (one per location) makes the check and the insert run one
 * visitor at a time. It works for both InnoDB and MyISAM tables.
 *
 * @return array{ok: bool, id?: int, error?: string}
 */
function create_booking(mysqli $db, array $clean, int $capacity = PARKING_CAPACITY, int $lockTimeout = 5): array
{
    $lockName = 'parkaro_' . md5($clean['location']);

    $stmt = $db->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->bind_param('si', $lockName, $lockTimeout);
    $stmt->execute();
    $stmt->bind_result($got);
    $stmt->fetch();
    $stmt->close();
    if ((int) $got !== 1) {
        return ['ok' => false, 'error' => 'The system is busy. Please try again in a moment.'];
    }

    try {
        $db->begin_transaction();

        if (count_active_bookings($db, $clean['location']) >= $capacity) {
            $db->rollback();
            return ['ok' => false, 'error' => 'Sorry, no slots available at this location right now.'];
        }

        $stmt = $db->prepare(
            'INSERT INTO bookings
               (location_name, admin_zone, lat, lng, name, email, car_number, hours, cost, booking_time, timestamp)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->bind_param(
            'sssssssii',
            $clean['location'], $clean['admin_zone'], $clean['lat'], $clean['lng'],
            $clean['name'], $clean['email'], $clean['car_number'], $clean['hours'], $clean['cost']
        );
        $stmt->execute();
        $id = (int) $db->insert_id;
        $stmt->close();

        $db->commit();
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $ex) {
        try {
            $db->rollback();
        } catch (Throwable $ignored) {
        }
        error_log('[parkaro] create_booking failed: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'Could not save the booking. Please try again.'];
    } finally {
        $rel = $db->prepare('SELECT RELEASE_LOCK(?)');
        $rel->bind_param('s', $lockName);
        $rel->execute();
        $rel->close();
    }
}

/**
 * Load one booking by id (used by confirmation page and PDF ticket).
 *
 * After migration 001 the row also carries start_time, end_time and status. On a database that has
 * not been upgraded those columns do not exist (MySQL error 1054); the original column list is used
 * then, so the old pages keep working. Callers treat a missing 'status' as "confirmed".
 */
function find_booking(mysqli $db, int $id): ?array
{
    $columns = 'id, location_name, admin_zone, lat, lng, name, email, car_number, hours, cost, booking_time';
    foreach ([$columns . ', start_time, end_time, status', $columns] as $list) {
        try {
            $stmt = $db->prepare("SELECT $list FROM bookings WHERE id = ?");
        } catch (mysqli_sql_exception $ex) {
            if ($ex->getCode() === 1054 && $list !== $columns) {
                continue; // columns from migration 001 are not there yet
            }
            throw $ex;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
    return null;
}

/** Tickets are only issued for confirmed bookings. Old rows (no status column) count as confirmed. */
function booking_is_confirmed(array $booking): bool
{
    return ($booking['status'] ?? 'confirmed') === 'confirmed';
}

/**
 * Admin-side validation for "Add booking" (zone is taken from the session,
 * not from the form). Returns [errors, clean].
 */
function validate_admin_booking_input(array $in): array
{
    $errors = [];
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        $errors[] = 'Enter a name (up to 100 characters).';
    }
    $email = trim((string) ($in['email'] ?? ''));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address.';
    }
    $car = normalize_car_number((string) ($in['car'] ?? ''));
    if (preg_match('/^[A-Z0-9]{4,12}$/', $car) !== 1) {
        $errors[] = 'Enter a valid car number.';
    }
    $location = trim((string) ($in['location'] ?? ''));
    if ($location === '' || mb_strlen($location) > 100) {
        $errors[] = 'Enter a location name (up to 100 characters).';
    }
    $hours = filter_var($in['hours'] ?? '', FILTER_VALIDATE_INT);
    if ($hours === false || $hours < 1 || $hours > MAX_BOOKING_HOURS) {
        $errors[] = 'Hours must be a whole number from 1 to ' . MAX_BOOKING_HOURS . '.';
        $hours = 0;
    }
    $cost = filter_var($in['cost'] ?? '', FILTER_VALIDATE_INT);
    if ($cost === false || $cost < 0 || $cost > 100000) {
        $errors[] = 'Cost must be a whole number of rupees (0 or more).';
        $cost = 0;
    }
    $coords = [];
    foreach (['lat' => [-90, 90], 'lng' => [-180, 180]] as $k => [$min, $max]) {
        $raw = trim((string) ($in[$k] ?? ''));
        if ($raw === '') {
            $coords[$k] = '0';
        } elseif (is_numeric($raw) && (float) $raw >= $min && (float) $raw <= $max) {
            $coords[$k] = (string) (float) $raw;
        } else {
            $errors[] = ucfirst($k === 'lat' ? 'latitude' : 'longitude') . ' must be a valid coordinate.';
            $coords[$k] = '0';
        }
    }
    return [$errors, [
        'name' => $name, 'email' => $email, 'car' => $car, 'location' => $location,
        'hours' => (int) $hours, 'cost' => (int) $cost, 'lat' => $coords['lat'], 'lng' => $coords['lng'],
    ]];
}

/**
 * Everything a city booking page (kolhapur/ajara/gadhinglaj) needs.
 * Handles the POST: CSRF check -> validate -> capacity check + insert -> redirect.
 *
 * @return array{locations: array, selectedLocation: string, name: string, email: string,
 *               carNumber: string, hours: int, cost: int, errorMessage: string}
 */
function handle_booking_page(string $locationSet, int $ratePerHour): array
{
    $locations = booking_locations($locationSet);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $src = $isPost ? $_POST : [];

    $selectedLocation = (string) ($src['location'] ?? ($_GET['location'] ?? ''));
    $name = (string) ($src['name'] ?? '');
    $email = (string) ($src['email'] ?? '');
    $carNumber = (string) ($src['carNumber'] ?? '');
    $hoursInput = $src['hours'] ?? 1;
    $hours = (int) $hoursInput;
    $errorMessage = '';

    if ($isPost) {
        csrf_check();
        [$errors, $clean] = validate_booking_input([
            'name' => $name, 'email' => $email, 'car_number' => $carNumber,
            'hours' => $hoursInput, 'location' => $selectedLocation,
        ], $locations, $ratePerHour);

        if ($errors) {
            $errorMessage = implode(' ', $errors);
        } else {
            $result = create_booking(db(), $clean);
            if ($result['ok']) {
                header('Location: ' . ticket_url('booking_confirmed.php', $result['id']));
                exit;
            }
            $errorMessage = $result['error'];
        }
    }

    return [
        'locations' => $locations, 'selectedLocation' => $selectedLocation, 'name' => $name,
        'email' => $email, 'carNumber' => $carNumber, 'hours' => max(1, min(MAX_BOOKING_HOURS, $hours)),
        'cost' => $ratePerHour * max(1, min(MAX_BOOKING_HOURS, $hours)), 'errorMessage' => $errorMessage,
    ];
}

/**
 * HTML for the PDF ticket. Every value is escaped: a name like "<img src=...>"
 * must not turn into PDF markup. Only the saved booking row is used.
 */
function ticket_html(array $b): string
{
    return '
    <h1 style="color: #28a745;">Parkaro</h1>
    <h2 style="color: #28a745;">Parking Booking Ticket</h2>
    <hr>
    <p><strong>Booking No:</strong> ' . (int) $b['id'] . '</p>
    <p><strong>Location:</strong> ' . e($b['location_name']) . '</p>
    <p><strong>Admin Zone:</strong> ' . e($b['admin_zone']) . '</p>
    <p><strong>Customer Name:</strong> ' . e($b['name']) . '</p>
    <p><strong>Email:</strong> ' . e($b['email']) . '</p>
    <p><strong>Car Number:</strong> ' . e($b['car_number']) . '</p>
    ' . (!empty($b['start_time']) && !empty($b['end_time'])
        ? '<p><strong>Parking from:</strong> ' . e(substr((string) $b['start_time'], 0, 16)) . '</p>
    <p><strong>Parking until:</strong> ' . e(substr((string) $b['end_time'], 0, 16)) . '</p>'
        : '') . '
    <p><strong>Duration:</strong> ' . (int) $b['hours'] . ' hours</p>
    <p><strong>Total Cost:</strong> &#8377;' . (int) $b['cost'] . '</p>
    <hr>
    <p>Thank you for using our parking service!</p>
';
}
