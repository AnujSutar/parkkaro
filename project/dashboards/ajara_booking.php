<?php
$servername = "localhost";
$username = "root";
$password = "";
$database = "parkaro";

// Create connection
$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$locations = [
    ["name" => "Ajra Bus Stand Parking", "admin_zone" => "Zone A", "lat" => 16.1984, "lng" => 74.3874],
    ["name" => "Ajra Market Parking", "admin_zone" => "Zone B", "lat" => 16.2001, "lng" => 74.3845],
    ["name" => "Ajra ST Depot Parking", "admin_zone" => "Zone C", "lat" => 16.2025, "lng" => 74.3820],
];

$costPerHour = 20;
$selectedLocation = $_POST["location"] ?? "";
$name = $_POST["name"] ?? "";
$email = $_POST["email"] ?? "";
$carNumber = $_POST["carNumber"] ?? "";
$hours = isset($_POST["hours"]) ? (int)$_POST["hours"] : 1;
$cost = $costPerHour * $hours;
$adminZone = "";
$lat = 0;
$lng = 0;
$errorMessage = "";

function getAvailableSpots($conn, $locationName) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE location_name = ?");
    $stmt->bind_param("s", $locationName);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return max(0, 10 - $count); // Max 10 spots
}

if ($selectedLocation) {
    foreach ($locations as $loc) {
        if ($loc["name"] === $selectedLocation) {
            $adminZone = $loc["admin_zone"];
            $lat = $loc["lat"];
            $lng = $loc["lng"];
            break;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["name"])) {
    $availableSpots = getAvailableSpots($conn, $selectedLocation);

    if ($availableSpots > 0) {
        $stmt = $conn->prepare("INSERT INTO bookings (location_name, admin_zone, lat, lng, name, email, car_number, hours, cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssi", $selectedLocation, $adminZone, $lat, $lng, $name, $email, $carNumber, $hours, $cost);

        if ($stmt->execute()) {
            header("Location: booking_confirmed.php?" . http_build_query([
                'location' => $selectedLocation,
                'adminZone' => $adminZone,
                'name' => $name,
                'email' => $email,
                'carNumber' => $carNumber,
                'hours' => $hours,
                'cost' => $cost
            ]));
            exit();
        } else {
            $errorMessage = "Error saving booking. Please try again.";
        }
    } else {
        $errorMessage = "Sorry, no slots available at this location.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajra Parking Booking</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f1e1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            box-sizing: border-box;
        }

        .navbar {
            background-color: #000000;
            padding: 15px;
            text-align: center;
            width: 100%;
        }

        .navbar a {
            color: #ffffff;
            padding: 10px 15px;
            text-decoration: none;
            margin: 0 10px;
            font-size: 18px;
            transition: background 0.3s ease;
        }

        .navbar a:hover {
            background-color: #444444;
            border-radius: 5px;
        }

        .form-container {
            background: #ffffff;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.1);
            width: 400px;
            min-width: 320px;
            margin-top: 70px;
        }

        h2 {
            text-align: center;
            color: #000000;
        }

        #map {
            height: 300px;
            margin-top: 20px;
            border-radius: 8px;
            border: 2px solid #444444;
        }

        label {
            display: block;
            margin-top: 15px;
            color: #333;
        }

        input, select {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 14px;
        }

        button {
            margin-top: 20px;
            width: 100%;
            padding: 12px;
            background: #000000;
            color: white;
            border: none;
            font-size: 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #444444;
        }

        .message {
            margin-top: 15px;
            padding: 12px;
            border-radius: 5px;
            font-weight: bold;
        }

        .error {
            background-color: #fbe7e7;
            color: #b91c1c;
            border-left: 4px solid #ef4444;
        }

        .bottom-bar {
            background-color: #000000;
            color: white;
            text-align: center;
            padding: 12px;
            font-size: 14px;
            margin-top: 20px;
        }

        @media screen and (max-width: 600px) {
            .form-container {
                width: 90%;
                padding: 15px;
            }

            .navbar a {
                font-size: 16px;
            }

            button {
                font-size: 14px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>

<!-- Navigation -->
<div class="navbar">
    <a href="home.html">Home</a>
    <a href="live-map.html">Live Map</a>
    <a href="contact.html">Support</a>
</div>

<!-- Form Section (Centered) -->
<div class="form-container">
    <h2>Book Parking in Ajra</h2>

    <!-- Error Message if any -->
    <?php if ($errorMessage): ?>
        <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post">
        <label for="location">Select Location</label>
        <select name="location" required>
            <option value="">-- Choose --</option>
            <?php foreach ($locations as $loc): ?>
                <option value="<?= htmlspecialchars($loc["name"]) ?>" <?= ($selectedLocation === $loc["name"]) ? "selected" : "" ?>>
                    <?= htmlspecialchars($loc["name"]) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="name">Name</label>
        <input type="text" name="name" required value="<?= htmlspecialchars($name) ?>">

        <label for="email">Email</label>
        <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>">

        <label for="carNumber">Car Number</label>
        <input type="text" name="carNumber" required value="<?= htmlspecialchars($carNumber) ?>">

        <label for="hours">Time (in hours)</label>
        <input type="number" name="hours" min="1" required value="<?= $hours ?>">

        <label for="cost">Cost (₹20/hr)</label>
        <input type="text" value="₹<?= $cost ?>" readonly>

        <button type="submit">Submit Booking</button>
    </form>
</div>

<!-- Map Section -->
<div id="map"></div>

<!-- Footer -->
<div class="bottom-bar">
    <p>&copy; 2025 Parkaro. All Rights Reserved.</p>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    var map = L.map('map').setView([16.1984, 74.3874], 13); // Default map center at Ajra

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    }).addTo(map);

    L.marker([16.1984, 74.3874]).addTo(map)
        .bindPopup("Ajra Bus Stand Parking")
        .openPopup();

    L.marker([16.2001, 74.3845]).addTo(map)
        .bindPopup("Ajra Market Parking");

    L.marker([16.2025, 74.3820]).addTo(map)
        .bindPopup("Ajra ST Depot Parking");
</script>

</body>
</html>
