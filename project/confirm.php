<?php
// Get booking details from the URL parameters
$location = $_GET['location'] ?? '';
$adminZone = $_GET['adminZone'] ?? '';
$name = $_GET['name'] ?? '';
$email = $_GET['email'] ?? '';
$carNumber = $_GET['carNumber'] ?? '';
$hours = $_GET['hours'] ?? '';
$cost = $_GET['cost'] ?? '';

// Location database (sample)
$locations = [
    ["name" => "Ajara Main Market", "admin_zone" => "Zone A", "lat" => 16.6100, "lng" => 74.2120],
    ["name" => "Ajara Bus Stand", "admin_zone" => "Zone B", "lat" => 16.6115, "lng" => 74.2080],
    ["name" => "Ajara Railway Station", "admin_zone" => "Zone C", "lat" => 16.6125, "lng" => 74.2050],

    ["name" => "Gadhinglaj Market", "admin_zone" => "Zone A", "lat" => 16.2365, "lng" => 74.2465],
    ["name" => "Gadhinglaj Bus Stand", "admin_zone" => "Zone B", "lat" => 16.2370, "lng" => 74.2440],
    ["name" => "Gadhinglaj Railway Station", "admin_zone" => "Zone C", "lat" => 16.2380, "lng" => 74.2410],

    ["name" => "Kolhapur Railway Station", "admin_zone" => "Zone A", "lat" => 16.7053, "lng" => 74.2430],
    ["name" => "Shahu Market", "admin_zone" => "Zone B", "lat" => 16.7070, "lng" => 74.2465],
    ["name" => "Kolhapur Bus Stand", "admin_zone" => "Zone C", "lat" => 16.7090, "lng" => 74.2410],
];

// Find coordinates
$lat = $lng = null;

foreach ($locations as $loc) {
    if ($loc["name"] === $location) {
        $lat = $loc["lat"];
        $lng = $loc["lng"];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Booking Confirmation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
        background: #f0f2f5;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
    }

    .confirmation-box {
        background: #fff;
        padding: 30px 40px;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        max-width: 600px;
        width: 90%;
        text-align: center;
    }

    h1 {
        color: #28a745;
        margin-bottom: 10px;
    }

    h2 {
        color: #333;
        margin-bottom: 25px;
    }

    p {
        font-size: 16px;
        color: #444;
        margin: 10px 0;
    }

    .btn-group {
        margin-top: 30px;
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .btn-group a button {
        padding: 12px 18px;
        font-size: 16px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.3s ease;
    }

    .btn-group a:first-child button {
        background-color: #007bff;
        color: white;
    }

    .btn-group a:first-child button:hover {
        background-color: #0056b3;
    }

    .btn-group a:last-child button {
        background-color: #6c757d;
        color: white;
    }

    .btn-group a:last-child button:hover {
        background-color: #5a6268;
    }

    @media (max-width: 480px) {
        .confirmation-box {
            padding: 25px 20px;
        }
    }
  </style>
</head>
<body>
  <div class="confirmation-box">
    <h1>Booking Confirmed!</h1>
    <h2>Parking Details</h2>
    <p><strong>Name:</strong> <?= htmlspecialchars($name) ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars($email) ?></p>
    <p><strong>Car Number:</strong> <?= htmlspecialchars($carNumber) ?></p>
    <p><strong>Location:</strong> <?= htmlspecialchars($location) ?></p>
    <p><strong>Admin Zone:</strong> <?= htmlspecialchars($adminZone) ?></p>
    <p><strong>Hours Booked:</strong> <?= htmlspecialchars($hours) ?></p>
    <p><strong>Total Cost:</strong> ₹<?= htmlspecialchars($cost) ?></p>

    <div class="btn-group">
      <?php if ($lat && $lng): ?>
      <a href="https://www.google.com/maps?q=<?= $lat ?>,<?= $lng ?>" target="_blank">
        <button>View on Google Maps</button>
      </a>
      <a href="generate_ticket.php?location=<?php echo urlencode($location); ?>&adminZone=<?php echo urlencode($adminZone); ?>&name=<?php echo urlencode($name); ?>&email=<?php echo urlencode($email); ?>&carNumber=<?php echo urlencode($carNumber); ?>&hours=<?php echo urlencode($hours); ?>&cost=<?php echo urlencode($cost); ?>" target="_blank">
    <button>Download PDF Ticket</button>
      <?php endif; ?>
      <a href="home.html">
        <button>Go to Home</button>
      </a>
    </div>
  </div>
</body>
</html>
