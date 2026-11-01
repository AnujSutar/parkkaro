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
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register | Smart Parking</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', sans-serif;
            background: #0f0f0f;
            color: #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .container {
            background: #1a1a1a;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0, 255, 100, 0.3);
            width: 400px;
        }

        h2 {
            text-align: center;
            margin-bottom: 25px;
            color: #00ff88;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            margin: 10px 0;
            border: none;
            border-radius: 10px;
            background-color: #2a2a2a;
            color: #f0f0f0;
            font-size: 15px;
        }

        input[type="submit"] {
            width: 100%;
            padding: 12px;
            margin-top: 15px;
            background-color: #00ff88;
            color: #000;
            font-weight: bold;
            font-size: 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: 0.3s;
        }

        input[type="submit"]:hover {
            background-color: #00cc6e;
        }

        p {
            text-align: center;
            margin-top: 15px;
        }

        a {
            color: #00ff88;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .message {
            text-align: center;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .message.error {
            color: #ff4d4d;
        }

        .message.success {
            color: #00ff88;
        }
    </style>
</head>
<body>
<div class="container">
    <h2><?= $adminMode ? 'Admin Registration' : 'User Registration' ?></h2>
    <p style="margin-top:0">
        <?php if ($adminMode): ?><a href="register.php">I am a driver (User)</a> &middot; <strong>I own parking (Admin)</strong>
        <?php else: ?><strong>I am a driver (User)</strong> &middot; <a href="register.php?type=admin">I own parking (Admin)</a><?php endif; ?>
    </p>

    <?php if ($error !== '') echo "<div class='message error'>" . e($error) . "</div>"; ?>
    <?php if ($success && $adminMode) echo "<div class='message success'>Admin account created! Log in with the username you chose: <a href='login1.php'>Admin login</a></div>"; ?>
    <?php if ($success && !$adminMode) echo "<div class='message success'>Registered successfully! <a href='index.php?login=true'>Log in to your account</a></div>"; ?>

    <?php if ($adminMode && admin_registration_open()): ?>
    <form method="post" action="register.php?type=admin" autocomplete="off">
        <?= csrf_field() ?>
        <input type="text" name="username" placeholder="Username (e.g. anuj sutar)" required minlength="3" maxlength="50">
        <input type="password" name="password" placeholder="Choose Password" required minlength="8" maxlength="72"
               title="At least 8 characters with a letter and a digit.">
        <input type="password" name="password_confirm" placeholder="Repeat Password" required minlength="8" maxlength="72">
        <?php if (admin_signup_code() !== ''): ?><input type="text" name="signup_code" placeholder="Admin sign-up code" required><?php endif; ?>
        <input type="submit" value="Register as Admin">
    </form>
    <p style="font-size:13px">Admins register their own parking places and see only their own reservations and earnings.</p>
    <?php elseif ($adminMode): ?>
        <div class='message error'>Administrator registration is switched off on this site.</div>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="text" name="first_name" placeholder="First Name" required>
        <input type="text" name="last_name" placeholder="Last Name" required>
        <input type="email" name="email" placeholder="Email Address" required>
        <input type="text" name="car_number" placeholder="Car Number" required>
        <input type="password" name="password" placeholder="Choose Password" required
               pattern="(?=.*[A-Z])(?=.*[\W_]).{6,}"
               title="At least 6 characters, 1 uppercase letter, and 1 special character.">
        <input type="submit" value="Register">
    </form>
    <?php endif; ?>

    <p>Already have an account? <a href="index.php?login=true">User login</a> &middot; <a href="login1.php">Admin login</a></p>
</div>
</body>
</html>
