<?php
/**
 * Creating administrator accounts (table `admin_users`: username, password, admin_zone).
 *
 * Admin login (login1.php) looks an admin up by `username`, so this is the ONE place that creates
 * those rows, and it collects exactly that field. There is deliberately NO public admin
 * registration: accounts are created by
 *   - an already logged-in admin (admin_create.php), or
 *   - the server owner on the command line (tools/create_admin.php), which is how the first admin is made.
 * Normal user registration (users table, e-mail login) never creates an administrator.
 */
declare(strict_types=1);

const ADMIN_USERNAME_MIN = 3;
const ADMIN_USERNAME_MAX = 50;   // admin_users.username is VARCHAR(50)
const ADMIN_ZONE_MAX = 20;       // admin_users.admin_zone is VARCHAR(20)

/**
 * Validate the "new admin" form. Returns [errors, clean].
 * Usernames may be plain names ("anuj", "anuj sutar") or e-mail-like (existing accounts use addresses such as ajara1@smartparking.com).
 */
function validate_admin_input(array $in): array
{
    $errors = [];
    $username = trim((string) ($in['username'] ?? ''));
    $zone = trim((string) ($in['admin_zone'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $confirm = (string) ($in['password_confirm'] ?? $password);

    $len = mb_strlen($username);
    if ($username === '') {
        $errors[] = 'Username is required.';
    } elseif ($len < ADMIN_USERNAME_MIN || $len > ADMIN_USERNAME_MAX) {
        $errors[] = 'Username must be ' . ADMIN_USERNAME_MIN . '-' . ADMIN_USERNAME_MAX . ' characters.';
    } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9._@+-]*( [A-Za-z0-9._@+-]+)*$/', $username) !== 1) {
        $errors[] = 'Username may contain letters, digits, single spaces between words, and . _ @ + - only, and must start with a letter or digit.';
    }

    if ($zone === '' || mb_strlen($zone) > ADMIN_ZONE_MAX || preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $zone) !== 1) {
        $errors[] = 'Zone is required (1-' . ADMIN_ZONE_MAX . ' letters, digits, space . _ -).';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 72) { // bcrypt only uses the first 72 bytes
        $errors[] = 'Password must be at most 72 characters.';
    } elseif (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
        $errors[] = 'Password must contain at least one letter and one digit.';
    } elseif (strcasecmp($password, $username) === 0) {
        $errors[] = 'Password must not be the same as the username.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    return [$errors, ['username' => $username, 'admin_zone' => $zone, 'password' => $password]];
}

/**
 * Insert the admin with a bcrypt hash (password_hash). Returns ['ok'=>true] or ['ok'=>false,'error'=>...].
 * Never changes or reads other admins' passwords. The duplicate check ignores case, so "Owner" and "owner"
 * cannot both exist (MySQL's default collation already treats them as the same login).
 */
function create_admin_account(PDO $pdo, array $clean): array
{
    $st = $pdo->prepare('SELECT 1 FROM admin_users WHERE LOWER(username) = LOWER(?)');
    $st->execute([$clean['username']]);
    if ($st->fetchColumn() !== false) {
        return ['ok' => false, 'error' => 'This username is already taken. Choose a different username.'];
    }
    try {
        $pdo->prepare('INSERT INTO admin_users (username, password, admin_zone) VALUES (?, ?, ?)')
            ->execute([$clean['username'], password_hash($clean['password'], PASSWORD_DEFAULT), $clean['admin_zone']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { // someone created the same username a moment ago (UNIQUE key)
            return ['ok' => false, 'error' => 'This username is already taken. Choose a different username.'];
        }
        throw $e;
    }
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* Public admin registration (register.php?type=admin)                 */
/* ------------------------------------------------------------------ */
/*
 * Anyone may register as an admin (a parking-place owner), but a self-registered admin is
 * ISOLATED: they get a brand-new private zone of their own and can never pick an existing one.
 * Zones are what the older pages use to decide whose bookings an admin sees, so this makes it
 * impossible to sign up and read or delete another zone's bookings or customers. The new modules
 * (parking places, reservations, earnings) are scoped by username as well.
 *   ADMIN_REGISTRATION=off   in .env  -> public admin registration is switched off (CLI / admin_create.php only)
 *   ADMIN_SIGNUP_CODE=secret in .env  -> a visitor must also enter this code (recommended on a public server)
 */

function admin_registration_open(): bool
{
    return strtolower(trim((string) env('ADMIN_REGISTRATION', 'on'))) !== 'off';
}

function admin_signup_code(): string
{
    return trim((string) env('ADMIN_SIGNUP_CODE', ''));
}

/** A zone name nobody uses yet, e.g. "Own-3f9a1c" (fits admin_users.admin_zone VARCHAR(20)). */
function new_private_zone(PDO $pdo): string
{
    for ($i = 0; $i < 20; $i++) {
        $zone = 'Own-' . bin2hex(random_bytes(3));
        $used = (int) q_val($pdo, 'SELECT COUNT(*) FROM admin_users WHERE admin_zone = ?', [$zone], 0)
              + (int) q_val($pdo, 'SELECT COUNT(*) FROM bookings WHERE admin_zone = ?', [$zone], 0);
        if ($used === 0) {
            return $zone;
        }
    }
    throw new RuntimeException('Could not allocate a private zone.');
}

/**
 * Handle the public admin sign-up form. Returns ['ok'=>true,'username'=>..] or ['ok'=>false,'errors'=>[...]].
 * $in: username, password, password_confirm, signup_code.
 */
function register_admin_public(PDO $pdo, array $in): array
{
    if (!admin_registration_open()) {
        return ['ok' => false, 'errors' => ['Administrator registration is switched off on this site. Ask the site owner to create your account.']];
    }
    $code = admin_signup_code();
    if ($code !== '' && !hash_equals($code, trim((string) ($in['signup_code'] ?? '')))) {
        return ['ok' => false, 'errors' => ['The admin sign-up code is wrong. Ask the site owner for it.']];
    }
    [$errors, $clean] = validate_admin_input([
        'username' => $in['username'] ?? '',
        'password' => $in['password'] ?? '',
        'password_confirm' => $in['password_confirm'] ?? '',
        'admin_zone' => 'Own-000000', // placeholder: the real zone is allocated below, never taken from the form
    ]);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $clean['admin_zone'] = new_private_zone($pdo);
    $r = create_admin_account($pdo, $clean);
    return $r['ok'] ? ['ok' => true, 'username' => $clean['username']] : ['ok' => false, 'errors' => [$r['error']]];
}
