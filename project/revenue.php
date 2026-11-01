<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/slots.php';
require_admin();   // before: no check, or a check on a session key that nothing ever set

$location = 'Ajara';
$data = slot_dashboard_data(db(), $location);
$totalSlots = $data['totalSlots'];
$bookedSlots = $data['bookedSlots'];
$availableSlots = $data['availableSlots'];
$todayBookings = $data['todayBookings'];
$bookings = $data['bookings'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Ajara Admin Dashboard</title>
    <style>
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

<div class="sidebar">
    <h2>Ajara Admin</h2>
    <a href="#">Dashboard</a>
    <a href="manage_ajara.php">Manage Slots</a>
    <a href="revenue.php">Revenue</a>
    <a href="logout.php">Logout</a>
</div>

<div class="main">
    <h1>Smart Parking - Ajara Dashboard</h1>

    <div class="card-container">
        <div class="card">
            <h3>Total Slots</h3>
            <p><?= (int) $totalSlots ?></p>
        </div>
        <div class="card">
            <h3>Booked Slots</h3>
            <p><?= (int) $bookedSlots ?></p>
        </div>
        <div class="card">
            <h3>Available Slots</h3>
            <p><?= (int) $availableSlots ?></p>
        </div>
        <div class="card">
            <h3>Today's Bookings</h3>
            <p><?= (int) $todayBookings ?></p>
        </div>
    </div>

    <h2 style="margin-top: 50px;">Latest Bookings</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>User Email</th>
            <th>Slot</th>
            <th>Status</th>
            <th>Time</th>
        </tr>
        <?php while($row = $bookings->fetch_assoc()): ?>
            <tr>
                <td><?= e($row['id']) ?></td>
                <td><?= e($row['user_email']) ?></td>
                <td><?= e($row['slot_number']) ?></td>
                <td><?= e(ucfirst($row['status'])) ?></td>
                <td><?= e($row['timestamp']) ?></td>
            </tr>
        <?php endwhile; ?>
    </table>
</div>

</body>
</html>
