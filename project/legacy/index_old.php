<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';
require_once __DIR__ . '/src/users.php';
start_secure_session();
$conn = pdo();

$error = '';
$success = '';

// Already logged in as a user and asked for the login form: go to the dashboard instead.
if (empty($_POST) && isset($_GET['login']) && !empty($_SESSION['user_id'])) {
    header('Location: ' . USER_HOME);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['register'])) {
        [$errors, $clean] = validate_registration($_POST);
        if ($errors) {
            $error = implode(' ', $errors);
        } elseif (!register_user($conn, $clean)) {
            $error = 'Email already registered!';
        } else {
            $success = 'registered';
        }
    }

    if (isset($_POST['login']) && empty($_SESSION['user_id'])) { // already-logged-in users are not logged in twice
        $user = authenticate_user($conn, trim((string) ($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''));
        if ($user) {
            login_user_session((int) $user['id'], (string) $user['first_name']);
            // Back to the page that asked for a login (reserve, pay ...), otherwise the user dashboard.
            header('Location: ' . (safe_next($_POST['next'] ?? '') ?: USER_HOME));
            exit;
        }
        // One message for unknown email and wrong password.
        $error = 'Invalid email or password!';
    }

    if (isset($_POST['submit_ticket']) && !empty($_SESSION['user_id'])) {
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 150 || $message === '' || mb_strlen($message) > 2000) {
            $error = 'Subject (max 150) and message (max 2000 characters) are required.';
        } else {
            $uid = (int) $_SESSION['user_id'];
            $conn->prepare('INSERT INTO tickets (user_id, subject, message) VALUES (?, ?, ?)')->execute([$uid, $subject, $message]);
            $success = 'Ticket submitted successfully!';
        }
    }

    // NOTE: nothing in the project ever sets $_SESSION['is_admin'], so this
    // branch is unreachable today. It is kept, but now safe (POST + CSRF + int id).
    if (isset($_POST['accept_ticket']) && !empty($_SESSION['is_admin'])) {
        $ticketId = (int) $_POST['accept_ticket'];
        $conn->prepare("UPDATE tickets SET status = 'accepted' WHERE id = ?")->execute([$ticketId]);
    }
}

$userTickets = null;
if (!empty($_SESSION['user_id'])) {
    $uid = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC');
    $stmt->execute([$uid]);
    $userTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$adminTickets = [];
if (!empty($_SESSION['is_admin'])) {
    $adminTickets = $conn->query("SELECT * FROM tickets WHERE status = 'pending'")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Parking - User Ticket System</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f4; color: #333; padding: 20px; }
        .container { width: 60%; margin: 0 auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        h2 { text-align: center; color: #00ff88; }
        input[type="text"], input[type="email"], input[type="password"], textarea { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 5px; }
        input[type="submit"] { width: 100%; padding: 12px; background-color: #00ff88; border: none; color: white; cursor: pointer; }
        input[type="submit"]:hover { background-color: #00cc6e; }
        .message { text-align: center; padding: 10px; color: white; border-radius: 5px; }
        .error { background-color: #ff4d4d; }
        .success { background-color: #00cc6e; }
        table { width: 100%; margin-top: 20px; border-collapse: collapse; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 10px; text-align: left; }
        th { background-color: #00ff88; color: white; }
    </style>
</head>
<body>

<div class="container">
    <h2>Smart Parking - User Ticket System</h2>

    <?php if ($error !== '') { echo "<div class='message error'>" . e($error) . "</div>"; } ?>
    <?php if ($success === 'registered') { echo "<div class='message success'>Registered successfully! <a href='index.php?login=true'>Login here</a></div>"; } elseif ($success !== '') { echo "<div class='message success'>" . e($success) . "</div>"; } ?>
    <?php if (!empty($_SESSION['user_id'])): ?>
        <p>Signed in as <?= e($_SESSION['first_name'] ?? '') ?> · <a href="home.html">Home</a> · <a href="find_parking.php">Find parking</a> · <a href="my_dashboard.php">My dashboard</a> · <a href="logout.php">Logout</a></p>
    <?php endif; ?>

    <!-- User Registration Form -->
    <?php if (empty($_SESSION['user_id']) && !isset($_GET['login'])): ?>
        <h3>User Registration</h3>
        <p>Already registered? <a href="index.php?login=true">User login</a> &middot; Administrator? <a href="login1.php">Admin login</a> &middot; <a href="register.php?type=admin">Register as admin</a></p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="text" name="first_name" placeholder="First Name" required><br>
            <input type="text" name="last_name" placeholder="Last Name" required><br>
            <input type="email" name="email" placeholder="Email Address" required><br>
            <input type="text" name="car_number" placeholder="Car Number" required><br>
            <input type="password" name="password" placeholder="Password" required><br>
            <input type="submit" name="register" value="Register">
        </form>
    <?php endif; ?>

    <!-- User Login Form -->
    <?php if (isset($_GET['login'])): ?>
        <h3>User Login</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e(safe_next($_GET['next'] ?? '')) ?>">
            <input type="email" name="email" placeholder="Email Address" required><br>
            <input type="password" name="password" placeholder="Password" required><br>
            <input type="submit" name="login" value="Login">
        </form>
        <p>New here? <a href="index.php">Register</a> &middot; Administrator? <a href="login1.php">Admin login</a> &middot; <a href="home.html">Home</a></p>
    <?php endif; ?>

    <!-- User Ticket Submission Form -->
    <?php if (!empty($_SESSION['user_id'])): ?>
        <h3>Submit Ticket</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="text" name="subject" placeholder="Ticket Subject" required><br>
            <textarea name="message" placeholder="Describe your issue..." required></textarea><br>
            <input type="submit" name="submit_ticket" value="Submit Ticket">
        </form>

        <!-- User Ticket View -->
        <h3>Your Tickets</h3>
        <?php if ($userTickets): ?>
            <table>
                <tr><th>ID</th><th>Subject</th><th>Status</th><th>Created At</th></tr>
                <?php foreach ($userTickets as $ticket): ?>
                    <tr>
                        <td><?= (int) $ticket['id'] ?></td>
                        <td><?= e($ticket['subject']) ?></td>
                        <td><?= e($ticket['status']) ?></td>
                        <td><?= e($ticket['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No tickets found.</p>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Admin Dashboard (view all tickets) -->
    <?php if (!empty($_SESSION['is_admin'])): ?>
        <h3>Admin: Pending Tickets</h3>
        <?php if ($adminTickets): ?>
            <table>
                <tr><th>ID</th><th>Subject</th><th>User</th><th>Email</th><th>Message</th><th>Action</th></tr>
                <?php foreach ($adminTickets as $ticket): ?>
                    <tr>
                        <td><?= (int) $ticket['id'] ?></td>
                        <td><?= e($ticket['subject']) ?></td>
                        <td><?= e($ticket['user_id']) ?></td>
                        <td><?= htmlspecialchars($ticket['email']) ?></td>
                        <td><?= htmlspecialchars($ticket['message']) ?></td>
                        <td><form method="POST" style="display:inline"><?= csrf_field() ?><button type="submit" name="accept_ticket" value="<?= (int) $ticket['id'] ?>">Accept</button></form></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No pending tickets.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>
