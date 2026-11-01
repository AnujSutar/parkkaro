<?php
session_start();  // Start the session

// Assuming the admin's zone and username are stored in the session after login
if (!isset($_SESSION['admin_zone']) || !isset($_SESSION['admin_username'])) {
    // Redirect to login page if the admin is not logged in
    header("Location: login.php");
    exit();
}

$admin_zone = $_SESSION['admin_zone']; // Get the logged-in admin's zone
$admin_username = $_SESSION['admin_username']; // Get the logged-in admin's username

// Connect to the database
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "parkaro";  // Replace with your actual database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Query to get the total number of bookings for the admin's zone
$sql_total = "SELECT COUNT(*) AS total FROM bookings WHERE admin_zone = '$admin_zone'";
$result_total = $conn->query($sql_total);
$total_bookings = $result_total->fetch_assoc()['total'];

// Query to get the count of bookings for today
$sql_today = "SELECT COUNT(*) AS today FROM bookings WHERE admin_zone = '$admin_zone' AND DATE(booking_time) = CURDATE()";
$result_today = $conn->query($sql_today);
$today_bookings = $result_today->fetch_assoc()['today'];

// Query to get the total revenue this month for the admin's zone
$sql_revenue = "SELECT SUM(cost) AS revenue FROM bookings WHERE admin_zone = '$admin_zone' AND MONTH(booking_time) = MONTH(CURRENT_DATE)";
$result_revenue = $conn->query($sql_revenue);
$total_revenue = $result_revenue->fetch_assoc()['revenue'] ?? 0;  // Set to 0 if no revenue

// Get all bookings for the admin's zone
$sql_bookings = "SELECT * FROM bookings WHERE admin_zone = '$admin_zone' ORDER BY booking_time DESC";
$result_bookings = $conn->query($sql_bookings);

// Close the connection
$conn->close();
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
        <h2>Welcome, <?php echo $admin_username; ?></h2>
        <a href="dashboard1.php">Dashboard</a>
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
                <p><?php echo $total_bookings; ?></p>
            </div>
            <div class="card">
                <h3>Bookings Today</h3>
                <p><?php echo $today_bookings; ?></p>
            </div>
            <div class="card">
                <h3>Total Revenue (This Month)</h3>
                <p>₹ <?php echo number_format($total_revenue, 2); ?></p>
            </div>
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
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($booking = $result_bookings->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $booking['id']; ?></td>
                        <td><?php echo $booking['location_name']; ?></td>
                        <td><?php echo $booking['name']; ?></td>
                        <td><?php echo $booking['car_number']; ?></td>
                        <td><?php echo $booking['booking_time']; ?></td>
                        <td>
                            <button class="action-btn" onclick="viewBookingDetails(<?php echo $booking['id']; ?>)">View Details</button>
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
