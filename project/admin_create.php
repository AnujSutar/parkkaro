<?php
// Admin: create another administrator account (username + password + zone).
// Not public: only a logged-in admin can open it. The first admin is created with tools/create_admin.php.
require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/dbx.php';
require_once __DIR__ . '/src/admin_accounts.php';
require_once __DIR__ . '/src/ui.php';

[$admin, $zone] = require_admin();

$errors = [];
$created = '';
$form = ['username' => '', 'admin_zone' => $zone];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    // The zone is always the creator's own zone: an admin can add colleagues to THEIR zone, never to someone else's.
    [$errors, $clean] = validate_admin_input(['admin_zone' => $zone] + $_POST);
    $form = ['username' => $clean['username'], 'admin_zone' => $clean['admin_zone']];
    if (!$errors) {
        $r = create_admin_account(pdo(), $clean);
        if ($r['ok']) {
            $created = $clean['username'];
            $form = ['username' => '', 'admin_zone' => $zone];
        } else {
            $errors[] = $r['error'];
        }
    }
}

ui_page_start('Add admin account', 'admin', 'addadmin', $admin);
?>
<h1>Add an administrator</h1>
<?php if ($created !== '') { echo ui_msg('ok', 'Administrator "' . $created . '" was created. They can now log in at Admin Login with this username and the password you set.'); } ?>
<?php foreach ($errors as $err) { echo ui_msg('err', $err); } ?>
<form class="card" method="post" action="admin_create.php" autocomplete="off">
    <?= csrf_field() ?>
    <div class="row">
        <div><label for="username">Username</label><input type="text" id="username" name="username" required minlength="<?= (int) ADMIN_USERNAME_MIN ?>" maxlength="<?= (int) ADMIN_USERNAME_MAX ?>" value="<?= e($form['username']) ?>"></div>
        <div><label>Zone</label><input type="text" value="<?= e($zone) ?>" disabled></div>
    </div>
    <div class="row">
        <div><label for="password">Password</label><input type="password" id="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"></div>
        <div><label for="password_confirm">Repeat password</label><input type="password" id="password_confirm" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password"></div>
    </div>
    <p class="muted">The username is what the person types at Admin Login (for example "anuj" or "anuj sutar"; letters, digits, spaces and . _ @ + - ; 3-50 characters; must be unique). The new admin joins your zone (<?= e($zone) ?>). The password needs 8+ characters with a letter and a digit and is stored hashed. Share it with them privately.</p>
    <button class="btn" type="submit">Create administrator</button>
</form>
</div></body></html>
