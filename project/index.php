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
require_once __DIR__ . '/src/auth_ui.php';

$loggedIn = !empty($_SESSION['user_id']);
if ($loggedIn) {
    auth_page_start('Support', []);
} elseif (isset($_GET['login'])) {
    auth_page_start('User login', ['heading' => 'Welcome back', 'text' => 'Log in to see your reservations and find parking.',
        'points' => ['Reserve a space for your time window', 'Pay online, confirmed after verification', 'Open your reservations in My dashboard']]);
} else {
    auth_page_start('User registration', ['heading' => 'Create your driver account', 'text' => 'Reserve parking in Kolhapur, Ajara and Gadhinglaj in a few taps.',
        'points' => ['Find free and reservable parking', 'Pay online and keep your reservations in one place', 'Get directions to your space']]);
}
?>
    <?php if ($loggedIn): ?><h2>Support</h2><?php endif; ?>

    <?php if ($error !== '') { echo "<div class='msg error'>" . e($error) . "</div>"; } ?>
    <?php if ($success === 'registered') { echo "<div class='msg success'>Registered successfully! <a href='index.php?login=true'>Login here</a></div>"; } elseif ($success !== '') { echo "<div class='msg success'>" . e($success) . "</div>"; } ?>
    <?php if (!empty($_SESSION['user_id'])): ?>
        <p class="links">Signed in as <?= e($_SESSION['first_name'] ?? '') ?> · <a href="home.html">Home</a> · <a href="find_parking.php">Find parking</a> · <a href="my_dashboard.php">My dashboard</a> · <a href="logout.php">Logout</a></p>
    <?php endif; ?>

    <?php if (empty($_SESSION['user_id']) && !isset($_GET['login'])): ?>
        <h2>User Registration</h2>
        <?= auth_tabs([['I am a driver (User)', 'index.php', true], ['I own parking (Admin)', 'register.php?type=admin', false]]) ?>
        <form method="POST">
            <?= csrf_field() ?>
            <label for="first_name">First name</label><input type="text" id="first_name" name="first_name" placeholder="First Name" required>
            <label for="last_name">Last name</label><input type="text" id="last_name" name="last_name" placeholder="Last Name" required>
            <label for="r_email">Email</label><input type="email" id="r_email" name="email" placeholder="Email Address" required>
            <label for="car_number">Car number</label><input type="text" id="car_number" name="car_number" placeholder="e.g. MH09AB1234" required>
            <label for="r_password">Password</label><input type="password" id="r_password" name="password" placeholder="Password" required>
            <input type="submit" name="register" value="Register">
        </form>
    <?php endif; ?>

    <!-- User Login Form -->
    <?php if (isset($_GET['login'])): ?>
        <h2>User Login</h2>
        <?= auth_tabs([['I am a driver (User)', 'index.php?login=true', true], ['I own parking (Admin)', 'login1.php', false]]) ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e(safe_next($_GET['next'] ?? '')) ?>">
            <label for="l_email">Email</label><input type="email" id="l_email" name="email" placeholder="Email Address" required>
            <label for="l_password">Password</label><input type="password" id="l_password" name="password" placeholder="Password" required>
            <input type="submit" name="login" value="Login">
        </form>
        <p class="links">New here? <a href="index.php">Register</a> &middot; Administrator? <a href="login1.php">Admin login</a> &middot; <a href="home.html">Home</a></p>
    <?php endif; ?>

    <!-- User Ticket Submission Form -->
    <?php if (!empty($_SESSION['user_id'])): ?>
        <h3>Submit a support ticket</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="text" name="subject" placeholder="Ticket Subject" required>
            <textarea name="message" placeholder="Describe your issue..." required></textarea>
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
<?php auth_page_end();
