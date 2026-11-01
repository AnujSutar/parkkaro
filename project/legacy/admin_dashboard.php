<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_name'])) {
    header("Location: login.php"); // Redirect to login page if not logged in
    exit;
}

// Get the logged-in admin's information
$adminName = $_SESSION['admin_name'];
$adminEmail = $_SESSION['admin_email'];

$host = "localhost";
$user = "root";
$password = "";
$database = "parking";

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch total earnings from the database (example)
$sql = "SELECT SUM(amount) AS total_earnings FROM bookings";
$result = $conn->query($sql);
$totalEarnings = 0;
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $totalEarnings = $row['total_earnings'];
}

// Fetch bookings data from the database
$bookingsResult = $conn->query("SELECT * FROM bookings ORDER BY booking_date DESC LIMIT 5");

// Fetch occupied slots (example)
$occupiedSlots = ['S1', 'S2', 'S3']; // Replace with actual logic to fetch from database

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>

    <!-- CSS Links -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body {
            padding-left: 270px;
            background-color: #f8f9fa;
        }

        .container {
            padding: 20px;
        }

        .card {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .left-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100%;
            width: 270px;
            background-color: #1e1e2f;
            color: #fff;
            padding-top: 60px;
            z-index: 999;
            overflow-y: auto;
        }

        .left-sidebar .nav-link {
            color: #fff;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: background 0.2s ease;
        }

        .left-sidebar .nav-link i {
            margin-right: 10px;
            font-size: 18px;
            width: 25px;
        }

        .left-sidebar .nav-link:hover {
            background-color: #343a40;
            border-left: 5px solid #0dcaf0;
            padding-left: 15px;
        }

        .sidebar-heading {
            text-align: center;
            font-size: 1.4rem;
            margin-bottom: 30px;
            color: #0dcaf0;
            font-weight: bold;
        }

        .slot-grid {
            display: grid;
            grid-template-columns: repeat(6, 50px);
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }

        .slot {
            width: 50px;
            height: 50px;
            background-color: #e0e0e0;
            border: 2px solid #ccc;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            border-radius: 5px;
        }

        .occupied {
            background-color: #dc3545;
            color: white;
        }

        .available {
            background-color: #28a745;
            color: white;
        }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="left-sidebar">
    <div class="sidebar-heading">Admin Panel</div>
    <a href="#summary" class="nav-link"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a href="#bookings" class="nav-link"><i class="fas fa-calendar-check"></i> Bookings</a>
    <a href="#earnings" class="nav-link"><i class="fas fa-money-bill-wave"></i> Earnings</a>
    <a href="#parking" class="nav-link"><i class="fas fa-th"></i> Parking Status</a>
    <a href="#settings" class="nav-link"><i class="fas fa-cog"></i> Settings</a>
    <a href="#profile" class="nav-link"><i class="fas fa-user-circle"></i> Profile</a>
    <a href="logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
</div>

<!-- MAIN CONTENT -->
<div class="container">
    <!-- Dashboard Summary -->
    <div id="summary" class="card p-4">
        <h4>Welcome, <?php echo htmlspecialchars($adminName); ?></h4>
        <p>Email: <strong><?php echo htmlspecialchars($adminEmail); ?></strong></p>
        <p>Total Earnings: <strong>₹<?php echo number_format($totalEarnings, 2); ?></strong></p>
    </div>

    <!-- Recent Bookings -->
    <div id="bookings" class="card p-4">
        <h5>Recent Bookings</h5>
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th>Ticket ID</th>
                    <th>Name</th>
                    <th>Slot</th>
                    <th>Amount</th>
                    <th>Vehicle</th>
                    <th>Booking Date</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $bookingsResult->fetch_assoc()) { ?>
                    <tr>
                        <td><?php echo $row['ticket_id']; ?></td>
                        <td><?php echo $row['name']; ?></td>
                        <td><?php echo $row['slot']; ?></td>
                        <td>₹<?php echo $row['amount']; ?></td>
                        <td><?php echo $row['vehicle_number']; ?></td>
                        <td><?php echo date("d M Y H:i", strtotime($row['booking_date'])); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <!-- Earnings Overview -->
    <div id="earnings" class="card p-4">
        <h5>Earnings Overview</h5>
        <canvas id="earningsChart"></canvas>
    </div>

    <!-- Parking Lot Grid -->
    <div id="parking" class="card p-4">
        <h5>Parking Lot Status (Today)</h5>
        <div class="slot-grid">
            <?php
            $allSlots = range(1, 30);
            foreach ($allSlots as $slotNum) {
                $slotName = "S" . $slotNum;
                $isOccupied = in_array($slotName, $occupiedSlots);
                $class = $isOccupied ? "slot occupied" : "slot available";
                echo "<div class='$class'>$slotName</div>";
            }
            ?>
        </div>
    </div>
</div>

<!-- Chart Script -->
<script>
    const ctx = document.getElementById('earningsChart').getContext('2d');
    const earningsChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            datasets: [{
                label: 'Earnings (₹)',
                data: [500, 750, 600, 900, 1200, 1100, 800],
                backgroundColor: '#0dcaf0'
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
</script>

</body>
</html>
