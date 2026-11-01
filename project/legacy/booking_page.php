<?php
session_start();
include('db.php');

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Fetch available slots
$sql = "SELECT * FROM slots WHERE status = 'available' AND location = 'Kolhapur'";
$result = $conn->query($sql);

// Handle booking
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['slot_id'])) {
    $user_id = $_SESSION['user_id'];
    $slot_id = $_POST['slot_id'];
    $status = 'booked';

    // Update the slot status and assign it to the user
    $updateSql = "UPDATE slots SET status = ?, booked_by = ? WHERE id = ?";
    $stmt = $conn->prepare($updateSql);
    $stmt->bind_param("sii", $status, $user_id, $slot_id);
    if ($stmt->execute()) {
        echo "Booking successful!";
    } else {
        echo "Error booking the slot!";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Page - Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f4f4; }
        .sidebar { 
            width: 250px; 
            background-color: #333; 
            color: white; 
            padding: 20px; 
            position: fixed; 
            height: 100%; 
            top: 0; 
            left: 0; 
        }
        .sidebar h2 { text-align: center; color: white; margin-bottom: 20px; }
        .sidebar a { 
            display: block; 
            color: white; 
            padding: 15px; 
            text-decoration: none; 
            margin-bottom: 10px; 
        }
        .sidebar a:hover { background-color: #444; }
        .main-content { 
            margin-left: 250px; 
            padding: 30px; 
            width: calc(100% - 250px); 
        }
        table { 
            width: 100%; 
            margin-top: 30px; 
            border-collapse: collapse; 
        }
        th, td { 
            padding: 10px; 
            text-align: left; 
            border: 1px solid #ddd; 
        }
        th { background-color: #333; color: white; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        button { 
            background-color: #333; 
            color: white; 
            padding: 8px 16px; 
            border: none; 
            cursor: pointer; 
        }
        button:hover { background-color: #444; }
    </style>
</head>
<body>

    <!-- Sidebar (Admin Navigation) -->
    <div class="sidebar">
        <h2>Kolhapur Admin</h2>
        <a href="#">Dashboard</a>
        <a href="../manage_slots.php">Manage Slots</a>
        <a href="#">Users</a>
        <a href="#">Bookings</a>
        <a href="#">Revenue</a>
        <a href="logout.php">Logout</a>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <h1>Available Parking Slots</h1>

        <table>
            <thead>
                <tr>
                    <th>Slot Number</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['slot_number'] ?></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="slot_id" value="<?= $row['id'] ?>">
                                <button type="submit">Book</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    </div>

</body>
</html>
