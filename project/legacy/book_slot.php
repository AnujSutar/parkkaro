<?php
session_start();
include "db.php";

if (!isset($_SESSION['user_id'])) {
    die("Please <a href='login.php'>login</a> first.");
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slot_id = $_POST['slot_id'];

    // Check if slot is already booked
    $stmt = $conn->prepare("SELECT is_booked FROM parking_slots WHERE id = ?");
    $stmt->bind_param("i", $slot_id);
    $stmt->execute();
    $stmt->bind_result($is_booked);
    $stmt->fetch();
    $stmt->close();

    if ($is_booked) {
        echo "Slot already booked!";
    } else {
        // Book the slot
        $stmt = $conn->prepare("UPDATE parking_slots SET is_booked = 1, booked_by = ?, booked_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $user_id, $slot_id);
        if ($stmt->execute()) {
            echo "Slot booked successfully!";
        } else {
            echo "Booking failed!";
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Book Parking Slot</title>
</head>
<body>
<h2>Available Parking Slots</h2>
<form method="POST">
    <select name="slot_id" required>
        <?php
        $sql = "SELECT id, slot_number FROM parking_slots WHERE is_booked = 0";
        $result = $conn->query($sql);
        while ($row = $result->fetch_assoc()) {
            echo "<option value='{$row['id']}'>{$row['slot_number']}</option>";
        }
        ?>
    </select>
    <button type="submit">Book Slot</button>
</form>
</body>
</html>
