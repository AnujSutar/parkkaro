<?php
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/booking.php';
require_once __DIR__ . '/src/users.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/admin_accounts.php';
start_secure_session();

$error = '';
$success = false;
$adminMode = (($_GET['type'] ?? '') === 'admin');   // register.php = user, register.php?type=admin = admin
$adminName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($adminMode) {
        $r = register_admin_public(pdo(), $_POST);
        if ($r['ok']) {
            $success = true;
            $adminName = $r['username'];
        } else {
            $error = implode(' ', $r['errors']);
        }
    } else {
        [$errors, $clean] = validate_registration($_POST);
        if ($errors) {
            $error = implode(' ', $errors);
        } elseif (!register_user(pdo(), $clean)) {
            $error = 'Email already registered!';
        } else {
            $success = true;
        }
    }
}
require_once __DIR__ . '/src/auth_ui.php';

auth_page_start($adminMode ? 'Admin registration' : 'User registration', $adminMode
    ? ['heading' => 'List your parking place', 'text' => 'Register as a parking owner and start taking reservations.',
       'points' => ['Add your places with price and number of spaces', 'See only your own reservations', 'Earnings count verified payments only']]
    : ['heading' => 'Create your driver account', 'text' => 'Reserve parking in Kolhapur, Ajara and Gadhinglaj in a few taps.',
       'points' => ['Find free and reservable parking', 'Pay online and keep your reservations in one place', 'Get directions to your space']]);
?>
    <h2><?= $adminMode ? 'Admin Registration' : 'User Registration' ?></h2>
    <?= auth_tabs([['I am a driver (User)', 'register.php', !$adminMode], ['I own parking (Admin)', 'register.php?type=admin', $adminMode]]) ?>

    <?php if ($error !== '') echo "<div class='msg error'>" . e($error) . "</div>"; ?>
    <?php if ($success && $adminMode) echo "<div class='msg success'>Admin account created! Log in with the username you chose: <a href='login1.php'>Admin login</a></div>"; ?>
    <?php if ($success && !$adminMode) echo "<div class='msg success'>Registered successfully! <a href='index.php?login=true'>Log in to your account</a></div>"; ?>

    <?php if ($adminMode && admin_registration_open()): ?>
    <form method="post" action="register.php?type=admin" autocomplete="off">
        <?= csrf_field() ?>
        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="e.g. anuj sutar" required minlength="3" maxlength="50">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Choose Password" required minlength="8" maxlength="72"
               title="At least 8 characters with a letter and a digit.">
        <input type="password" name="password_confirm" placeholder="Repeat Password" required minlength="8" maxlength="72" aria-label="Repeat password">
        <?php if (admin_signup_code() !== ''): ?><label for="signup_code">Admin sign-up code</label><input type="text" id="signup_code" name="signup_code" placeholder="Admin sign-up code" required><?php endif; ?>
        <input type="submit" value="Register as Admin">
    </form>
    <p class="hint">At least 8 characters with a letter and a digit. Admins register their own parking places and see only their own reservations and earnings.</p>
    <?php elseif ($adminMode): ?>
        <div class='msg error'>Administrator registration is switched off on this site.</div>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <label for="first_name">First name</label><input type="text" id="first_name" name="first_name" placeholder="First Name" required>
        <label for="last_name">Last name</label><input type="text" id="last_name" name="last_name" placeholder="Last Name" required>
        <label for="email">Email</label><input type="email" id="email" name="email" placeholder="Email Address" required>
        <label for="car_number">Car number</label><input type="text" id="car_number" name="car_number" placeholder="e.g. MH09AB1234" required>
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Choose Password" required
               pattern="(?=.*[A-Z])(?=.*[\W_]).{6,}"
               title="At least 6 characters, 1 uppercase letter, and 1 special character.">
        <input type="submit" value="Register">
    </form>
    <p class="hint">At least 6 characters, with 1 uppercase letter and 1 special character.</p>
    <?php endif; ?>

    <p class="links">Already have an account? <a href="index.php?login=true">User login</a> &middot; <a href="login1.php">Admin login</a></p>
<?php auth_page_end();
