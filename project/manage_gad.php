<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/slots.php';
require_admin();   // this page had no login check before

$data = slot_page_data(db(), 'Gadhinglaj');
$search = $data['search'];
$statusFilter = $data['statusFilter'];
$slotsResult = $data['slotsResult'];
$totalPages = $data['totalPages'];
$availableSlots = $data['availableSlots'];
$bookedSlots = $data['bookedSlots'];
$historyQuery = $data['historyQuery'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Parking Slots - Gadhinglaj</title>
    <style>
        /* Basic styles for layout */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
        }

        /* Sidebar styles - fixed */
        .sidebar {
            width: 200px;
            background-color: #333;
            color: white;
            padding: 20px;
            position: fixed; /* Fixed sidebar */
            height: 100%; /* Full height */
            top: 0;
            left: 0;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 40px;
        }

        .sidebar a {
            display: block;
            color: white;
            padding: 15px;
            text-decoration: none;
            margin-bottom: 10px;
        }

        .sidebar a:hover {
            background-color: #444;
        }

        /* Main content styles */
        .main-content {
            margin-left: 250px; /* Push content to the right of the fixed sidebar */
            padding: 30px;
            width: calc(100% - 250px); /* Adjust width to fit the remaining space */
        }

        /* Table styles */
        table {
            width: 100%;
            margin-top: 30px;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px;
            text-align: left;
            border: 1px solid #ddd;
        }

        th {
            background-color: #333;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        /* Pagination styles */
        .pagination {
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px;
            margin: 0 5px;
            text-decoration: none;
            background-color: #333;
            color: white;
            border-radius: 5px;
        }

        .pagination a:hover {
            background-color: #444;
        }

        /* Form styles */
        select, input[type="text"] {
            padding: 8px;
            margin-top: 10px;
        }

        /* Bulk update form */
        .bulk-update {
            margin-top: 20px;
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>Admin Panel</h2>
        <a href="dashboards/gadhi1_dashboard.php">Dashboard</a>
        <a href="manage_slots.php">Manage Slots</a>
        <a href="#">Bookings</a>
        <a href="#">Users</a>
        <a href="#">Reports</a>
        <a href="logout.php">Logout</a>
    </div>

    <div class="main-content">
        <h1>Manage Parking Slots - Gadhinglaj</h1>

        <!-- Search and Filter Form -->
        <form method="GET">
            <input type="text" name="search" placeholder="Search Slot Number" value="<?= e($search) ?>">
            <select name="status_filter">
                <option value="">All Statuses</option>
                <option value="available" <?= $statusFilter == 'available' ? 'selected' : '' ?>>Available</option>
                <option value="booked" <?= $statusFilter == 'booked' ? 'selected' : '' ?>>Booked</option>
            </select>
            <button type="submit">Filter</button>
        </form>

        <!-- Slot Availability Analytics -->
        <h2>Slot Availability Analytics</h2>
        <canvas id="slotAvailabilityChart" width="400" height="200"></canvas>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            var ctx = document.getElementById('slotAvailabilityChart').getContext('2d');
            var slotAvailabilityChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Available', 'Booked'],
                    datasets: [{
                        label: 'Slot Availability',
                        data: [<?= $availableSlots ?>, <?= $bookedSlots ?>],
                        backgroundColor: ['#10b981', '#f97316'],
                        borderColor: ['#10b981', '#f97316'],
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        </script>

        <!-- Bulk Update Form -->
        <form method="POST" class="bulk-update">
            <?= csrf_field() ?>
            <table>
                <thead>
                    <tr>
                        <th>Select</th>
                        <th>Slot Number</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $slotsResult->fetch_assoc()): ?>
                        <tr>
                            <td><input type="checkbox" name="selected_slots[]" value="<?= (int) $row['id'] ?>"></td>
                            <td><?= e($row['slot_number']) ?></td>
                            <td><?= e(ucfirst($row['status'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <select name="bulk_status">
                <option value="available">Available</option>
                <option value="booked">Booked</option>
            </select>
            <button type="submit">Bulk Update</button>
        </form>

        <!-- Pagination -->
        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>

        <!-- Slot Status History -->
        <?php if ($historyQuery): ?>
            <h3>Slot Status History</h3>
            <table>
                <thead>
                    <tr>
                        <th>Old Status</th>
                        <th>New Status</th>
                        <th>Change Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($historyRow = $historyQuery->fetch_assoc()): ?>
                        <tr>
                            <td><?= e(ucfirst($historyRow['old_status'])) ?></td>
                            <td><?= e(ucfirst($historyRow['new_status'])) ?></td>
                            <td><?= e($historyRow['change_time']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>

</body>
</html>
