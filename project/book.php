<?php
// Receives the booking.html form. Previously it only redirected to confirm.php with
// whatever the browser sent (including the price) and saved nothing.
// Now: validate on the server, work out the price on the server, save the booking
// (with the capacity check), then show the verified confirmation page.
//
// No CSRF token here on purpose: booking.html is a static page (it cannot hold a
// per-session token) and guests have no login whose actions could be hijacked.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';

// Price policy of this form (matches the Rs 50/hour shown by booking.html).
// NOTE: the city pages (kolhapur/ajara/gadhinglaj_booking.php) charge Rs 20/hour.
// Which rate is right is a business decision, so both are kept as they were.
$costPerHour = 50;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: booking.html');
    exit;
}

[$errors, $clean] = validate_booking_input([
    'name'       => $_POST['userName'] ?? '',
    'email'      => $_POST['userEmail'] ?? '',
    'car_number' => $_POST['carNumber'] ?? '',
    'hours'      => $_POST['parking_hours'] ?? '',
    'location'   => $_POST['location'] ?? '',
], booking_locations('form'), $costPerHour);
// The phone number is not stored: the bookings table has no column for it.

if (!$errors) {
    $result = create_booking(db(), $clean);
    if ($result['ok']) {
        header('Location: ' . ticket_url('booking_confirmed.php', $result['id']));
        exit;
    }
    $errors[] = $result['error'];
}
http_response_code(422);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Booking not saved</title></head>
<body style="font-family:Segoe UI,sans-serif;max-width:520px;margin:60px auto">
    <h2>We could not save your booking</h2>
    <ul>
        <?php foreach ($errors as $msg): ?><li><?= e($msg) ?></li><?php endforeach; ?>
    </ul>
    <p><a href="booking.html">&larr; Back to the booking form</a></p>
</body>
</html>
