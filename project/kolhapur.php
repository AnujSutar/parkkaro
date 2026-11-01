<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Kolhapur Parking Locations</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    body {
      font-family: Arial, sans-serif;
      margin: 0;
      padding: 0;
    }
    header {
      background-color: #333;
      color: white;
      padding: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 1000;
    }
    header h2 {
      margin: 0;
      font-weight: bold;
    }
    nav {
      display: flex;
    }
    .nav-link {
      color: white;
      text-decoration: none;
      margin-left: 10px;
      padding: 6px 12px;
      border: 1px solid white;
      border-radius: 4px;
    }
    .nav-link:hover {
      background-color: white;
      color: #333;
    }
    .nav-link.active {
      background-color: white;
      color: #333;
      font-weight: bold;
    }
    .nav-link i {
      margin-right: 5px;
    }
    .container {
      padding: 20px;
    }
    .location-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr); /* Two columns */
      gap: 20px;
      margin-bottom: 20px;
    }
    .location {
      background-color: #f5f5f5;
      padding: 15px;
      border-radius: 10px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .location button {
      background-color: #4CAF50;
      color: white;
      border: none;
      padding: 10px 15px;
      border-radius: 5px;
      cursor: pointer;
    }
    .location button:hover {
      background-color: #45a049;
    }
    #map {
      height: 400px;
      margin: 20px 0;
    }
    .card-container {
      display: flex;
      flex-wrap: wrap;
      gap: 20px;
      justify-content: center;
    }
    .card {
      background-color: #fff;
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 20px;
      width: 250px;
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
      transition: transform 0.2s;
    }
    .card:hover {
      transform: translateY(-5px);
    }
    .card h4 {
      margin: 10px 0;
      font-size: 18px;
      color: #333;
    }
    .card p {
      font-size: 14px;
      color: #666;
    }
    .card .book-btn {
      background-color: #4CAF50;
      color: white;
      border: none;
      padding: 10px;
      width: 100%;
      border-radius: 5px;
      cursor: pointer;
      text-align: center;
    }
    .card .book-btn:hover {
      background-color: #45a049;
    }
footer {
  background-color: #333;
  color: white;
  padding: 15px;
  text-align: center;
  width: 100%;
}

  </style>
</head>
<body>
  <header>
    <div><h2>Kolhapur Parking Locations</h2></div>
    <nav>
      <a class="nav-link" href="home.html"><i class="fas fa-home"></i> Home</a>
      <a class="nav-link" href="live_map.php"><i class="fas fa-map"></i> Live Map</a>

    </nav>
  </header>

  <div class="container">
    <h3>Available Parking Locations</h3>
    <div class="location-grid" id="locationList"></div>

    <div id="map"></div>

    <h3>Private Parking Locations</h3>
    <div class="card-container">
  <div class="card">
    <h4>Kolhapur Shivaji Statue</h4>
    <p>This location offers a safe and secured parking space for your vehicle.</p>
    <a href="kolhapur_booking.php?location=Kolhapur%20Shivaji%20Statue">
      <button class="book-btn">Book Now</button>
    </a>
  </div>
  <div class="card">
    <h4>Kolhapur Market</h4>
    <p>Conveniently located with easy access to the main road.</p>
    <a href="kolhapur_booking.php?location=Kolhapur%20Market">
      <button class="book-btn">Book Now</button>
    </a>
  </div>
  <div class="card">
    <h4>Kolhapur Railway Station</h4>
    <p>Ample space for long-term parking with security monitoring.</p>
    <a href="kolhapur_booking.php?location=Kolhapur%20Railway%20Station">
      <button class="book-btn">Book Now</button>
    </a>
  </div>
</div>

  </div>

  <footer>
    This project is created for College Mega Project.
  </footer>

  <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
  <script>
  // 13 parking locations with their latitudes and longitudes
  const locations = [
    { name: "Kolhapur Shivaji Statue", lat: 16.7055, lng: 74.2354 },
    { name: "Kolhapur Market", lat: 16.7040, lng: 74.2340 },
    { name: "Kolhapur Railway Station", lat: 16.7048, lng: 74.2205 },
    { name: "Kolhapur Mahalaxmi Temple", lat: 16.7090, lng: 74.2215 },
    { name: "Kolhapur Bus Stand", lat: 16.7102, lng: 74.2220 },
    { name: "Kolhapur Mahalaxmi Shopping Complex", lat: 16.7085, lng: 74.2255 },
    { name: "Kolhapur Gokul Shirgaon", lat: 16.7180, lng: 74.2600 },
    { name: "Kolhapur Central Library", lat: 16.7063, lng: 74.2335 },
    { name: "Kolhapur Police Station", lat: 16.7093, lng: 74.2300 },
    { name: "Kolhapur RTO", lat: 16.7120, lng: 74.2275 },
    { name: "Kolhapur Railway Crossing", lat: 16.7108, lng: 74.2180 },
    { name: "Kolhapur Town Hall", lat: 16.7112, lng: 74.2290 },
    { name: "Kolhapur Sports Complex", lat: 16.7128, lng: 74.2145 },
  ];

  // Function to calculate distance between two latitudes and longitudes
  function getDistanceFromLatLonInKm(lat1, lon1, lat2, lon2) {
    const R = 6371; // Radius of the Earth in km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a =
      Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
      Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return (R * c).toFixed(2); // distance in km
  }

  // Get nearest parking spots
  function getNearestLocations(userLat, userLng) {
    const distances = locations.map(loc => ({
      ...loc,
      distance: getDistanceFromLatLonInKm(userLat, userLng, loc.lat, loc.lng)
    }));

    // Sort locations by distance
    distances.sort((a, b) => a.distance - b.distance);

    // Get the nearest 6 parking spots
    return distances.slice(0, 6);
  }

  // Display nearest parking locations
  function displayLocations(lat, lng) {
    const container = document.getElementById("locationList");
    container.innerHTML = "";

    const nearestLocations = getNearestLocations(lat, lng);

    nearestLocations.forEach(loc => {
      const div = document.createElement("div");
      div.className = "location";
      div.innerHTML = `
        <span>${loc.name}</span>
        <div>
          <span class="distance">${loc.distance} km</span>
          <a href="https://www.google.com/maps/dir/?api=1&destination=${loc.lat},${loc.lng}" target="_blank">
            <button>Get Directions</button>
          </a>
        </div>
      `;
      container.appendChild(div);
    });
  }

  // Get user's current location
  navigator.geolocation.getCurrentPosition(position => {
    const lat = position.coords.latitude;
    const lng = position.coords.longitude;
    displayLocations(lat, lng);
    createMap(lat, lng);
  }, () => {
    alert("Location access denied. Using default location (Kolhapur).")
    displayLocations(16.7055, 74.2354);
    createMap(16.7055, 74.2354);
  });

  // Create the map with Leaflet
  function createMap(lat, lng) {
    const map = L.map("map").setView([lat, lng], 13);

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    }).addTo(map);

    L.marker([lat, lng]).addTo(map).bindPopup("You are here").openPopup();
  }
</script>

</body>
</html>
