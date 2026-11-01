<?php
session_start();

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Parkaro Dashboard</title>
  <link rel="stylesheet" href="style.css">
  <style>
    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background-color: #f4f4f4;
    }

    /* Navigation Bar */
    .navbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background-color: #1976d2;
      padding: 10px 20px;
      color: white;
    }

    .navbar .logo {
      font-size: 22px;
      font-weight: bold;
    }

    .navbar .nav-links a {
      color: white;
      text-decoration: none;
      margin-left: 20px;
      font-weight: 500;
    }

    .navbar .nav-links a:hover {
      text-decoration: underline;
    }

    .container {
      padding: 40px;
      max-width: 1000px;
      margin: auto;
    }

    .card {
      background: white;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 0 12px rgba(0, 0, 0, 0.1);
      margin-top: 30px;
      text-align: center;
    }

    .logout-btn {
      display: inline-block;
      margin-top: 20px;
      background-color: #f44336;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 500;
    }

    .logout-btn:hover {
      background-color: #d32f2f;
    }
  </style>
</head>
<body>

  <!-- Navigation Bar -->
  <div class="navbar">
    <div class="logo">Parkaro</div>
    <div class="nav-links">
      <a href="#">City Map</a>
      <a href="#">Menu</a>
      <a href="logout.php" class="logout-btn">Logout</a>
    </div>
  </div>

  <!-- Dashboard Content -->
  <div class="container">
    <div class="card">
      <h2>Dashboard</h2>
      <p>Welcome to Parkaro — your smart parking solution!</p>

      <ul style="text-align: left; max-width: 400px; margin: auto;">
        <li>🔍 Find Nearest Parking Slots</li>
        <li>📍 Live City Map</li>
        <li>🅿️ Book Paid Slots</li>
        <li>🧾 View Parking History</li>
      </ul>
    </div>
  </div>

</body>
</html>
