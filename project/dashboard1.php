<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/admin.php';
[$admin_username, $admin_zone] = require_admin();
$conn = db();

// After migration 001 (parking places, reservations, payments) the dashboard also shows verified earnings.
// On a database that has not been upgraded it behaves exactly as before.
$upgraded = payments_table_exists($conn);
$confirmedOnly = $upgraded ? " AND status = 'confirmed'" : '';   // unpaid / cancelled reservations are not bookings
$legacyOnly = $upgraded ? ' AND location_id IS NULL' : '';       // old-style bookings: no payment record exists for them

$total_bookings = (int) db_scalar($conn, 'SELECT COUNT(*) FROM bookings WHERE admin_zone = ?' . $confirmedOnly, 's', [$admin_zone]);

$today_bookings = (int) db_scalar(
    $conn,
    'SELECT COUNT(*) FROM bookings WHERE admin_zone = ? AND DATE(booking_time) = CURDATE()' . $confirmedOnly,
    's', [$admin_zone]
);

// Revenue for the CURRENT month of the CURRENT year (the old query used MONTH() only,
// so every earlier year's same month was added in too).
$total_revenue = (float) db_scalar(
    $conn,
    'SELECT SUM(cost) FROM bookings
     WHERE admin_zone = ? AND YEAR(booking_time) = YEAR(CURDATE()) AND MONTH(booking_time) = MONTH(CURDATE())' . $legacyOnly,
    's', [$admin_zone]
);

$verified_month = 0;
$awaiting_count = 0;
if ($upgraded) {
    require_once __DIR__ . '/src/dbx.php';
    require_once __DIR__ . '/src/earnings.php';
    $verified_month = earnings_this_month(pdo(), $admin_username, app_now());
    $awaiting_count = earnings_exposure(pdo(), $admin_username, app_now())['awaiting_count'];
}

$result_bookings = db_select($conn, 'SELECT * FROM bookings WHERE admin_zone = ? ORDER BY booking_time DESC', 's', [$admin_zone]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Candela&display=swap" rel="stylesheet">
    <style>
        /* Your provided CSS styles */
        body {
            margin: 0; padding: 0;
            font-family: 'Segoe UI', sans-serif;
            background: #f4f6f9;
            display: flex;
        }
        .sidebar {
            width: 250px;
            background-color: #1f2937;
            color: #fff;
            height: 100vh;
            padding: 20px 0;
            position: fixed;
        }
        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
        }
        .sidebar a {
            display: block;
            color: #fff;
            text-decoration: none;
            padding: 15px 30px;
            transition: 0.3s;
        }
        .sidebar a:hover {
            background-color: #374151;
        }
        .main {
            margin-left: 250px;
            padding: 30px;
            width: calc(100% - 250px);
        }
        .card-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .card {
            background-color: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card h3 {
            margin: 10px 0;
            font-size: 20px;
            color: #333;
        }
        .card p {
            font-size: 28px;
            font-weight: bold;
            color: #10b981;
        }
        table {
            width: 100%;
            margin-top: 40px;
            background: #fff;
            border-collapse: collapse;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 0 10px rgba(0,0,0,0.08);
        }
        table th, table td {
            padding: 14px;
            text-align: left;
        }
        table th {
            background-color: #1f2937;
            color: #fff;
        }
        table tr:nth-child(even) {
            background-color: #f1f5f9;
        }
        h1 {
            color: #1f2937;
            margin-bottom: 25px;
        }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar">
        <h2>Welcome, <?= e($admin_username) ?></h2>
        <a href="dashboard1.php">Admin Dashboard</a>
        <a href="admin_locations.php">Manage Parking Locations</a>
        <a href="admin_reservations.php">Reservations</a>
        <a href="admin_earnings.php">Earnings</a>
        <a href="admin_create.php">Add Admin Account</a>
        <a href="view_bookings.php">View Bookings</a>
        <a href="manage_bookings.php">Manage Bookings</a>
        <a href="logout.php">Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main">

        <!-- Dashboard Overview -->
        <h1>Dashboard</h1>
        <div class="card-container">
            <div class="card">
                <h3>Total Bookings</h3>
                <p><?= e($total_bookings) ?></p>
            </div>
            <div class="card">
                <h3>Bookings Today</h3>
                <p><?= e($today_bookings) ?></p>
            </div>
            <?php if ($upgraded): ?>
            <div class="card">
                <h3>Verified Earnings (This Month)</h3>
                <p>₹ <?= number_format($verified_month) ?></p>
                <a href="admin_earnings.php">Details</a>
            </div>
            <div class="card">
                <h3>Awaiting Payment</h3>
                <p><?= (int) $awaiting_count ?></p>
                <a href="admin_reservations.php?status=pending_payment">View</a>
            </div>
            <div class="card">
                <h3>Old Bookings Value (This Month)</h3>
                <p>₹ <?php echo number_format($total_revenue, 2); ?></p>
                <small>Bookings made before online payment; not verified</small>
            </div>
            <?php else: ?>
            <div class="card">
                <h3>Total Revenue (This Month)</h3>
                <p>₹ <?php echo number_format($total_revenue, 2); ?></p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Bookings Table -->
        <h2>Bookings</h2>
        <table>
            <thead>
                <tr>
                    <th>Booking ID</th>
                    <th>Location Name</th>
                    <th>User Name</th>
                    <th>Car Number</th>
                    <th>Booking Date & Time</th>
                    <?php if ($upgraded): ?><th>Status</th><?php endif; ?>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($booking = $result_bookings->fetch_assoc()): ?>
                    <tr>
                        <td><?= e($booking['id']) ?></td>
                        <td><?= e($booking['location_name']) ?></td>
                        <td><?= e($booking['name']) ?></td>
                        <td><?= e($booking['car_number']) ?></td>
                        <td><?= e($booking['booking_time']) ?></td>
                        <?php if ($upgraded): ?><td><?= e($booking['status'] ?? 'confirmed') ?></td><?php endif; ?>
                        <td>
                            <button class="action-btn" onclick="viewBookingDetails(<?= (int) $booking['id'] ?>)">View Details</button>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <script>
        // Function to search bookings
        function searchBookings() {
            const input = document.getElementById('search-bookings').value.toLowerCase();
            const rows = document.querySelectorAll('table tbody tr');

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const location = cells[1].innerText.toLowerCase();
                const user = cells[2].innerText.toLowerCase();
                const car = cells[3].innerText.toLowerCase();

                if (location.includes(input) || user.includes(input) || car.includes(input)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Function to view booking details
        function viewBookingDetails(bookingId) {
            alert('Viewing details for booking ID: ' + bookingId);
            // You can extend this functionality to open a modal or navigate to another page with booking details
        }
    </script>

</body>
</html>
