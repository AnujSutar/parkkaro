<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';
require_once __DIR__ . '/src/admin.php';
[$adminUsername, $adminZone] = require_admin();
$conn = db();

$formErrors = handle_admin_booking_actions($conn, $adminZone);

$filter = trim((string) ($_GET['filter'] ?? ''));
$bookings = list_zone_bookings($conn, $adminZone, $filter);
$totalBookings = (int) db_scalar($conn, 'SELECT COUNT(*) FROM bookings WHERE admin_zone = ?', 's', [$adminZone]);
$maxSlots = 50; // Assumption from the original code: every zone has 50 parking slots
$availableSlots = max(0, $maxSlots - $totalBookings);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manage Bookings - <?= e($adminUsername) ?></title>
    <style>
        body {
            margin: 0;
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
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            flex: 1;
            
            background-color: #fff;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        .card h3 {
            margin: 10px 0;
            color: #333;
        }
        .card p {
            font-size: 28px;
            font-weight: bold;
            color: #10b981;
        }
        form, .form-inline {
            margin: 20px 0;
        }
        input[type="text"], input[type="number"] {
            padding: 8px;
            margin-right: 10px;
        }
        input[type="submit"] {
            padding: 8px 15px;
            background-color: #1f2937;
            color: #fff;
            border: none;
            border-radius: 5px;
        }
        table {
            width: 100%;
            margin-top: 20px;
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
    </style>
</head>
<body>
<div class="sidebar">
        <h2>View</h2>
        <a href="dashboard1.php">Dashboard</a>
        <a href="view_bookings.php">View Bookings</a>
        <a href="manage_bookings.php">Manage Bookings</a>
        <a href="logout.php">Logout</a>
    </div>

    <div class="main">
        <h1>View bookings (<?= e($adminZone) ?>)</h1>

        


        


        <?php foreach ($formErrors as $msg): ?><p style="color:#c0392b"><?= e($msg) ?></p><?php endforeach; ?>
        <h2>Bookings List</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Location</th>
                <th>Name</th>
                <th>Email</th>
                <th>Car Number</th>
                <th>Hours</th>
                <th>Cost</th>
                <th>Date</th>
                <th>Action</th>
            </tr>
            <?php while($row = $bookings->fetch_assoc()): ?>
                <tr>
                    <td><?= e($row['id']) ?></td>
                    <td><?= e($row['location_name']) ?></td>
                    <td><?= e($row['name']) ?></td>
                    <td><?= e($row['email']) ?></td>
                    <td><?= e($row['car_number']) ?></td>
                    <td><?= e($row['hours']) ?></td>
                    <td><?= e($row['cost']) ?></td>
                    <td><?= e($row['booking_time']) ?></td>
                    <td><form method="POST" style="display:inline" onsubmit="return confirm('Delete this booking?')"><?= csrf_field() ?><button type="submit" name="delete_id" value="<?= (int) $row['id'] ?>">Delete</button></form></td>
                </tr>
            <?php endwhile; ?>
        </table>
        <hr>


    </div>
</body>
</html>
