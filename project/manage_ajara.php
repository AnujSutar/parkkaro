<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/slots.php';
require_admin();   // this page had no login check before

$data = slot_page_data(db(), 'Ajara');
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
    <title>Manage Parking Slots</title>
    <style>
        body { font-family: Arial; background: #f4f4f4; margin: 0; }
        .sidebar { width: 200px; background: #333; color: #fff; position: fixed; height: 100%; padding: 20px; }
        .sidebar a { display: block; padding: 15px; color: white; text-decoration: none; }
        .sidebar a:hover { background: #444; }
        .main-content { margin-left: 220px; padding: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #333; color: #fff; }
        tr:nth-child(even) { background: #f9f9f9; }
        .pagination a { margin: 0 5px; padding: 8px 12px; background: #333; color: #fff; border-radius: 5px; text-decoration: none; }
        .pagination a:hover { background: #444; }
        select, input[type="text"] { padding: 8px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel</h2>
        <a href="revenue.php">Dashboard</a>
        <a href="manage_ajara.php">Manage Slots</a>
        <a href="#">Bookings</a>
        <a href="#">Users</a>
        <a href="#">Reports</a>
        <a href="logout.php">Logout</a>
    </div>

    <div class="main-content">
        <h1>Manage Parking Slots - Ajara</h1>

        <form method="GET">
            <input type="text" name="search" placeholder="Search Slot Number" value="<?= e($search) ?>">
            <select name="status_filter">
                <option value="">All</option>
                <option value="available" <?= $statusFilter == 'available' ? 'selected' : '' ?>>Available</option>
                <option value="booked" <?= $statusFilter == 'booked' ? 'selected' : '' ?>>Booked</option>
            </select>
            <button type="submit">Filter</button>
        </form>

        <h2>Slot Availability Analytics</h2>
        <canvas id="slotAvailabilityChart" width="400" height="200"></canvas>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            const ctx = document.getElementById('slotAvailabilityChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Available', 'Booked'],
                    datasets: [{
                        label: 'Slots',
                        data: [<?= $availableSlots ?>, <?= $bookedSlots ?>],
                        backgroundColor: ['#10b981', '#f97316']
                    }]
                },
                options: { scales: { y: { beginAtZero: true } } }
            });
        </script>

        <form method="POST">
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

        <div class="pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>

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
                    <?php while ($row = $historyQuery->fetch_assoc()): ?>
                        <tr>
                            <td><?= e(ucfirst($row['old_status'])) ?></td>
                            <td><?= e(ucfirst($row['new_status'])) ?></td>
                            <td><?= e($row['change_time']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
