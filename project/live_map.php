<?php
// (This page used to open a database connection it never used, with credentials in the code.)

$locations = [
    ["name" => "Gadhinglaj Bus Stand Parking", "admin_zone" => "Zone A", "lat" => 16.2268, "lng" => 74.3506],
    ["name" => "Gadhinglaj Market Parking", "admin_zone" => "Zone B", "lat" => 16.2225, "lng" => 74.3522],
    ["name" => "Gadhinglaj ST Depot Parking", "admin_zone" => "Zone C", "lat" => 16.2249, "lng" => 74.3487],
    ["name" => "Gadhinglaj Police Station Parking", "admin_zone" => "Zone D", "lat" => 16.2250, "lng" => 74.3495],
    ["name" => "Gadhinglaj Petrol Pump Parking", "admin_zone" => "Zone E", "lat" => 16.2257, "lng" => 74.3515],
    ["name" => "Ajara Market Parking", "admin_zone" => "Zone F", "lat" => 16.0536, "lng" => 74.2552],
    ["name" => "Ajara Bus Stand Parking", "admin_zone" => "Zone G", "lat" => 16.0564, "lng" => 74.2583],
    ["name" => "Ajara ST Depot Parking", "admin_zone" => "Zone H", "lat" => 16.0550, "lng" => 74.2570],
    ["name" => "Ajara Temple Parking", "admin_zone" => "Zone I", "lat" => 16.0512, "lng" => 74.2605],
    ["name" => "Ajara Petrol Pump Parking", "admin_zone" => "Zone J", "lat" => 16.0540, "lng" => 74.2565],
    ["name" => "Kolhapur City Bus Stand Parking", "admin_zone" => "Zone K", "lat" => 16.7056, "lng" => 74.2436],
    ["name" => "Kolhapur Railway Station Parking", "admin_zone" => "Zone L", "lat" => 16.7074, "lng" => 74.2335],
    ["name" => "Kolhapur Rani Channamma Parking", "admin_zone" => "Zone M", "lat" => 16.6998, "lng" => 74.2358],
    ["name" => "Kolhapur Market Parking", "admin_zone" => "Zone N", "lat" => 16.7048, "lng" => 74.2386],
    ["name" => "Kolhapur Mahalaxmi Temple Parking", "admin_zone" => "Zone O", "lat" => 16.6876, "lng" => 74.2274],
    ["name" => "Gadhinglaj Old Bus Stand Parking", "admin_zone" => "Zone P", "lat" => 16.2238, "lng" => 74.3482],
    ["name" => "Ajara Bazaar Parking", "admin_zone" => "Zone Q", "lat" => 16.0559, "lng" => 74.2535],
    ["name" => "Kolhapur Shivaji Park Parking", "admin_zone" => "Zone R", "lat" => 16.7005, "lng" => 74.2378],
    ["name" => "Gadhinglaj New Market Parking", "admin_zone" => "Zone S", "lat" => 16.2273, "lng" => 74.3499],
    ["name" => "Ajara Bus Station Parking", "admin_zone" => "Zone T", "lat" => 16.0547, "lng" => 74.2573],
    ["name" => "Gadhinglaj Temples Parking", "admin_zone" => "Zone U", "lat" => 16.2243, "lng" => 74.3490],
    ["name" => "Ajara Mall Parking", "admin_zone" => "Zone V", "lat" => 16.0573, "lng" => 74.2559],
    ["name" => "Kolhapur Khasbag Parking", "admin_zone" => "Zone W", "lat" => 16.7102, "lng" => 74.2324],
    ["name" => "Gadhinglaj Vegetable Market Parking", "admin_zone" => "Zone X", "lat" => 16.2228, "lng" => 74.3496],
    ["name" => "Ajara Petrol Station Parking", "admin_zone" => "Zone Y", "lat" => 16.0555, "lng" => 74.2591],
    ["name" => "Kolhapur Rajarampuri Parking", "admin_zone" => "Zone Z", "lat" => 16.7185, "lng" => 74.2269],
    ["name" => "Gadhinglaj Plaza Parking", "admin_zone" => "Zone AA", "lat" => 16.2223, "lng" => 74.3484],
    ["name" => "Ajara District Parking", "admin_zone" => "Zone AB", "lat" => 16.0532, "lng" => 74.2547],
    ["name" => "Kolhapur Bus Stand East Parking", "admin_zone" => "Zone AC", "lat" => 16.7069, "lng" => 74.2439],
    ["name" => "Gadhinglaj Cinema Parking", "admin_zone" => "Zone AD", "lat" => 16.2246, "lng" => 74.3500],
    ["name" => "Ajara Market Square Parking", "admin_zone" => "Zone AE", "lat" => 16.0544, "lng" => 74.2567],
    ["name" => "Kolhapur Shahu Maharaj Parking", "admin_zone" => "Zone AF", "lat" => 16.7052, "lng" => 74.2346],
    ["name" => "Gadhinglaj Bank Parking", "admin_zone" => "Zone AG", "lat" => 16.2254, "lng" => 74.3492],
    ["name" => "Ajara College Parking", "admin_zone" => "Zone AH", "lat" => 16.0581, "lng" => 74.2600],
    ["name" => "Kolhapur Central Mall Parking", "admin_zone" => "Zone AI", "lat" => 16.7100, "lng" => 74.2351],
    ["name" => "Gadhinglaj Central Park Parking", "admin_zone" => "Zone AJ", "lat" => 16.2271, "lng" => 74.3481],
    ["name" => "Ajara Main Road Parking", "admin_zone" => "Zone AK", "lat" => 16.0533, "lng" => 74.2575],
    ["name" => "Kolhapur Bus Stand West Parking", "admin_zone" => "Zone AL", "lat" => 16.7076, "lng" => 74.2429],
    ["name" => "Gadhinglaj Educational Complex Parking", "admin_zone" => "Zone AM", "lat" => 16.2220, "lng" => 74.3483],
    ["name" => "Ajara Residential Parking", "admin_zone" => "Zone AN", "lat" => 16.0568, "lng" => 74.2589]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Live Parking Map</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
            background: #f4f1e1;
            display: flex;
        }
        /* Vertical navigation bar */
        .sidebar {
            width: 200px;
            height: 100vh;
            background-color: #333;
            padding-top: 20px;
            position: fixed;
            left: 0;
        }
        .sidebar a {
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            display: block;
            font-size: 18px;
            text-align: center;
            border-bottom: 1px solid #ddd;
        }
        .sidebar a:hover {
            background-color: #575757;
        }
        .sidebar a.active {
            background-color: #4CAF50;
        }
        #map {
            margin-left: 200px;
            height: 100vh;
            width: calc(100% - 200px);
        }
        .bottom-bar {
            background-color: #000;
            color: #fff;
            text-align: center;
            padding: 12px;
            position: absolute;
            bottom: 0;
            width: 100%;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<div class="sidebar">
    <a href="index.html" class="active">Home</a>
    <a href="live_map.php">Live Map</a>

</div>

<!-- Map -->
<div id="map"></div>

<!-- Footer -->
<div class="bottom-bar">
    <p>&copy; 2025 Parking System | All rights reserved</p>
</div>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const map = L.map('map').setView([16.22, 74.35], 12); // Initial center

    // Tile layer
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Add markers
    const locations = <?php echo json_encode($locations); ?>;
    locations.forEach(loc => {
        L.marker([loc.lat, loc.lng])
            .addTo(map)
            .bindPopup(`<strong>${loc.name}</strong><br>Admin Zone: ${loc.admin_zone}`);
    });
</script>

</body>
</html>
