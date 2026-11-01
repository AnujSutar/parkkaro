<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';
start_secure_session();

// Price policy (unchanged from the original page): Rs 20 per hour, set on the server.
$costPerHour = 20;
$page = handle_booking_page('ajara', $costPerHour);
$locations = $page['locations'];
$selectedLocation = $page['selectedLocation'];
$name = $page['name'];
$email = $page['email'];
$carNumber = $page['carNumber'];
$hours = $page['hours'];
$cost = $page['cost'];
$errorMessage = $page['errorMessage'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajara Parking Booking</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        /* General Body Styling */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f1e1; /* Soft beige background */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            box-sizing: border-box;
        }

        /* Navbar Styling */
        .navbar {
            background-color: #000000; /* Black background for navbar */
            padding: 15px;
            text-align: center;
            width: 100%;
        }

        .navbar a {
            color: #ffffff; /* White text color */
            padding: 10px 15px;
            text-decoration: none;
            margin: 0 10px;
            font-size: 18px;
            transition: background 0.3s ease;
        }

        .navbar a:hover {
            background-color: #444444; /* Slightly lighter black for hover effect */
            border-radius: 5px;
        }

        /* Form Container Styling */
        .form-container {
            background: #ffffff; /* White background for the form section */
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 0 12px rgba(0, 0, 0, 0.1);
            width: 400px;
            min-width: 320px;
            margin-top: 70px; /* Add space for the navbar */
        }

        h2 {
            text-align: center;
            color: #000000; /* Black text */
        }

        /* Map Styling */
        #map {
            height: 300px;
            margin-top: 20px;
            border-radius: 8px;
            border: 2px solid #444444; /* Dark gray border for the map */
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
            background: #000000; /* Black button */
            color: white;
            border: none;
            font-size: 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        button:hover {
            background: #444444; /* Lighter black on hover */
        }

        /* Message Box Styling */
        .message {
            margin-top: 15px;
            padding: 12px;
            border-radius: 5px;
            font-weight: bold;
        }

        .error {
            background-color: #fbe7e7; /* Soft red background */
            color: #b91c1c;
            border-left: 4px solid #ef4444;
        }

        /* Footer Styling */
        .bottom-bar {
            background-color: #000000; /* Black background for bottom bar */
            color: white;
            text-align: center;
            padding: 12px;
            font-size: 14px;
            margin-top: 20px;
        }

        /* Ensures the page is responsive */
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
    <a href="live_map.php">Live Map</a>

    <a href="contact.html">Support</a>
</div>

<!-- Form Section (Centered) -->
<div class="form-container">
    <h2>Book Parking in Ajara</h2>

    <!-- Error Message if any -->
    <?php if ($errorMessage): ?>
        <div class="message error"><?= htmlspecialchars($errorMessage) ?></div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
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
        <input type="number" name="hours" min="1" max="<?= MAX_BOOKING_HOURS ?>" required value="<?= $hours ?>">

        <label for="cost">Cost (₹20/hr)</label>
        <input type="text" value="₹<?= $cost ?>" readonly>

        <button type="submit">Submit Booking</button>
    </form>

    <div id="map"></div>
</div>

<!-- Footer -->
<div class="bottom-bar">
    <p>&copy; 2025 Ajara Parking System | All rights reserved</p>
</div>

<!-- Map Script -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const map = L.map('map').setView([16.2745, 74.1375], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19
    }).addTo(map);

    const markers = <?= json_encode($locations) ?>;
    markers.forEach(loc => {
        L.marker([loc.lat, loc.lng])
            .addTo(map)
            .bindPopup(`<strong>${loc.name}</strong><br>Zone: ${loc.admin_zone}`);
    });
</script>
</body>
</html>
