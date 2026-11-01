<?php
require_once __DIR__ . '/src/bootstrap.php';
start_secure_session();

if (is_admin_logged_in()) {
    header('Location: ' . ADMIN_HOME);
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $admin = find_admin(pdo(), $username);

    // Same message for "no such user" and "wrong password" so usernames cannot be probed.
    if ($admin && admin_password_matches($password, (string) $admin['password'])) {
        login_admin_session((string) $admin['username'], (string) $admin['admin_zone']);
        header('Location: ' . ADMIN_HOME);
        exit;
    }
    $message = '<p style="color:#c0392b">Invalid credentials.</p>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page with Map</title>

    <!-- Candela font (fallback to sans-serif) -->
    <link href="https://fonts.googleapis.com/css2?family=Candela&display=swap" rel="stylesheet">

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .wrapper {
            display: flex;
            width: 700px;
            height: 400px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            border-radius: 10px;
            overflow: hidden;
        }

        .login-container {
            background-color: white;
            width: 50%;
            padding: 25px;
            margin: 5px;
        }

        .logo {
            text-align: center;
            font-size: 36px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 20px;
            font-family: 'Candela', sans-serif;
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
        }

        form div {
            margin-bottom: 15px;
        }

        label {
            font-size: 14px;
            color: #555;
            display: block;
            margin-bottom: 5px;
        }

        input {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }

        button {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background-color: #0056b3;
        }

        .register-link {
            text-align: center;
            margin-top: 15px;
        }

        .error {
            color: red;
            font-size: 14px;
            text-align: center;
            margin-top: 10px;
        }

        .success {
            color: green;
            font-size: 14px;
            text-align: center;
            margin-top: 10px;
        }

        #map {
            margin : 5px;
            width: 50%;
            height: 100%;
            padding:5px
        }
    </style>
</head>
<body>

    <div class="wrapper">
        <!-- Login Form -->
        <div class="login-container">
            <div class="logo">Parkaro</div>
            <h2>Login for Admins</h2>
            <p style="text-align:center;font-size:13px;margin-bottom:12px"><a href="index.php?login=true">Not an admin? User login</a> &middot; <a href="register.php?type=admin">Register as admin</a> &middot; <a href="home.html">Home</a></p>
            
            <form action="" method="POST">
                <?= csrf_field() ?>
                <div>
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>

                <div>
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div>
                    <button type="submit" name="login">Login</button>
                </div>
            </form>

            <?= $message ?>


        </div>

        <!-- Leaflet Map -->
        <div id="map"></div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        // Initialize the map
        var map = L.map('map').setView([28.6139, 77.2090], 13); // Centered on New Delhi

        // Add OpenStreetMap tiles
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        // Optional marker
        L.marker([28.6139, 77.2090]).addTo(map)
            .bindPopup('Welcome to Parkaro')
            .openPopup();
    </script>

</body>
</html>
