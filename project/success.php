<?php
session_start();
if (!isset($_SESSION['booking'])) {
    header("Location: bookings.php");
    exit;
}
$booking = $_SESSION['booking'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Booking Success</title>
  <style>
    body { font-family: Arial; background: #f2f2f2; text-align: center; padding: 100px; }
    .box {
      background: white; padding: 40px;
      border-radius: 12px; box-shadow: 0 0 10px rgba(0,0,0,0.1);
      display: inline-block;
    }
    h2 { color: green; }
    a button {
      margin: 10px;
      padding: 12px 25px;
      border: none;
      background: #4CAF50;
      color: white;
      font-size: 16px;
      cursor: pointer;
      border-radius: 5px;
    }
    a button.download {
      background: #2196F3;
    }
  </style>
</head>
<body>
  <div class="box">
    <h2>✅ Booking Successful!</h2>
    <p>Slot #<?= $booking['slot']; ?> at <?= $booking['location']; ?>, <?= $booking['city']; ?> has been booked.</p>
    <p><strong>Car No:</strong> <?= $booking['car_number']; ?><br>
       <strong>Email:</strong> <?= $booking['email']; ?><br>
       <strong>Date:</strong> <?= $booking['date']; ?></p>

    <a href="bookings.php"><button>🔙 Go to Home</button></a>
    <a href="bookings.php?download=1"><button class="download">⬇️ Download Ticket (.txt)</button></a>
  </div>
</body>
</html>
