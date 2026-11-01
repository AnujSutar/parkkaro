<?php
$city = $_GET['city'];
$location = $_GET['location'];

$conn = new mysqli("localhost", "root", "", "parking");

$q = $conn->prepare("SELECT COUNT(*) as booked FROM bookings WHERE city=? AND location=?");
$q->bind_param("ss", $city, $location);
$q->execute();
$q->bind_result($booked);
$q->fetch();
$q->close();

$available = 5 - $booked;

if ($available > 0) {
  echo "✅ $available spots available";
} else {
  echo "❌ No spots available";
}
?>
