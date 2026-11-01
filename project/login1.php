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
    $message = "<div class='msg error'>Invalid credentials.</div>";
}
require_once __DIR__ . '/src/auth_ui.php';

auth_page_start('Admin login', ['heading' => 'Manage your parking', 'text' => 'Log in to your parking-owner dashboard.',
    'points' => ['Add and edit your parking places', 'See your reservations and record cash payments', 'Earnings from verified payments']]);
?>
    <h2>Login for Admins</h2>
    <?= auth_tabs([['I am a driver (User)', 'index.php?login=true', false], ['I own parking (Admin)', 'login1.php', true]]) ?>
    <?= $message ?>
    <form action="" method="POST">
        <?= csrf_field() ?>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autocomplete="username">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
        <button type="submit" name="login">Login</button>
    </form>
    <p class="links"><a href="index.php?login=true">Not an admin? User login</a> &middot; <a href="register.php?type=admin">Register as admin</a> &middot; <a href="home.html">Home</a></p>
<?php auth_page_end();
