<?php
// Shows a booking that was really saved. The page is addressed by booking id plus
// a signed token (see ticket_url()), so the details come from the database and
// cannot be forged or altered through the URL.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId < 1 || !ticket_token_valid($bookingId, $_GET['t'] ?? null)) {
    http_response_code(404);
    exit('Booking not found.');
}
$booking = find_booking(db(), $bookingId);
if ($booking === null || !booking_is_confirmed($booking)) {   // unpaid / cancelled reservations get no ticket
    http_response_code(404);
    exit('Booking not found.');
}

$location = $booking['location_name'];
$adminZone = $booking['admin_zone'];
$name = $booking['name'];
$email = $booking['email'];
$carNumber = $booking['car_number'];
$hours = (int) $booking['hours'];
$cost = (int) $booking['cost'];
$lat = $booking['lat'];
$lng = $booking['lng'];
// Payment method and address (new-flow reservations only; the older rows simply show neither).
$payMethod = '';
$placeAddress = '';
try {
    require_once __DIR__ . '/src/dbx.php';
    require_once __DIR__ . '/src/payments.php';
    $pr = q_one(pdo(), "SELECT p.provider, l.address, l.city FROM bookings b LEFT JOIN payments p ON p.booking_id = b.id AND p.status = 'succeeded' LEFT JOIN parking_locations l ON l.id = b.location_id WHERE b.id = ? ORDER BY p.id DESC LIMIT 1", [$bookingId]);
    if ($pr !== null) {
        $payMethod = payment_method_label($pr['provider'] ?? null);
        $placeAddress = trim((string) ($pr['address'] ?? '') . ', ' . (string) ($pr['city'] ?? ''), ', ');
    }
} catch (Throwable $ex) {
    // schema not upgraded: show the ticket without these two lines
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed</title>
    <style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
        background: #f8f9fa;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
    }

    .confirmation-container {
        max-width: 600px;
        width: 90%;
        background-color: #ffffff;
        padding: 40px 30px;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        text-align: center;
    }

    h1 {
        color: #28a745;
        font-size: 28px;
        margin-bottom: 20px;
    }

    h2 {
        color: #343a40;
        font-size: 22px;
        margin-bottom: 25px;
    }

    p {
        font-size: 16px;
        color: #555;
        margin: 10px 0;
        line-height: 1.6;
    }

    .buttons {
        margin-top: 30px;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .buttons a button {
        padding: 12px 18px;
        font-size: 16px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .buttons a:first-child button {
        background-color: #007bff;
        color: #ffffff;
    }

    .buttons a:first-child button:hover {
        background-color: #0056b3;
    }

    .buttons a:last-child button {
        background-color: #6c757d;
        color: #ffffff;
    }

    .buttons a:last-child button:hover {
        background-color: #5a6268;
    }

    @media (max-width: 480px) {
        .confirmation-container {
            padding: 25px 20px;
        }

        h1 {
            font-size: 24px;
        }

        h2 {
            font-size: 20px;
        }

        p {
            font-size: 15px;
        }

        .buttons a button {
            font-size: 15px;
        }
    }
</style>


</head>
<body>
    <div class="confirmation-container">
        <h1>Booking Confirmed!</h1>
        <h2>Parking Details:</h2>
        <p><strong>Location:</strong> <?= e($location) ?></p>
        <p><strong>Reservation no:</strong> <?= (int) $bookingId ?></p>
        <?php if ($placeAddress !== ''): ?><p><strong>Address:</strong> <?= e($placeAddress) ?></p><?php endif; ?>
        <p><strong>Admin Zone:</strong> <?= e($adminZone) ?></p>
        <p><strong>Customer Name:</strong> <?= e($name) ?></p>
        <p><strong>Email:</strong> <?= e($email) ?></p>
        <p><strong>Car Number:</strong> <?= e($carNumber) ?></p>
        <?php if (!empty($booking['start_time']) && !empty($booking['end_time'])): ?>
        <p><strong>Parking from:</strong> <?= e(substr((string) $booking['start_time'], 0, 16)) ?></p>
        <p><strong>Parking until:</strong> <?= e(substr((string) $booking['end_time'], 0, 16)) ?></p>
        <?php endif; ?>
        <p><strong>Duration:</strong> <?= e($hours) ?> hours</p>
        <p><strong>Total Cost:</strong> ₹<?= e($cost) ?></p>
        <?php if ($payMethod !== ''): ?><p><strong>Payment:</strong> <?= e($payMethod) ?></p><?php endif; ?>

        <!-- Buttons -->
        <div class="buttons">
            <!-- Button to redirect to Google Maps -->
            <a href="https://www.google.com/maps?q=<?= e(urlencode((string) $lat) . ',' . urlencode((string) $lng)) ?>" target="_blank">
                <button>Go to Google Maps</button>
            </a>
            <!-- Button to download PDF ticket -->
<a href="<?= e(ticket_url('generate_ticket.php', $bookingId)) ?>" target="_blank">
    <button>Download PDF Ticket</button>
</a>


            <!-- Button to go back to the home page -->
            <a href="home.html"> <!-- Change the URL if needed -->
                <button>Go Home</button>
            </a>
        </div>
    </div>
</body>
</html>
