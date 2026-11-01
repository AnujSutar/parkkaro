<?php
/**
 * User registration and login helpers (shared by register.php and index.php).
 */
declare(strict_types=1);

/** Validate registration form data. Returns [errors, cleanValues]. */
function validate_registration(array $in): array
{
    $errors = [];
    $first = trim((string) ($in['first_name'] ?? ''));
    $last  = trim((string) ($in['last_name'] ?? ''));
    foreach (['First name' => $first, 'Last name' => $last] as $label => $v) {
        if ($v === '' || mb_strlen($v) > 50 || preg_match('/^[\p{L}][\p{L}\p{M} .\'\-]*$/u', $v) !== 1) {
            $errors[] = "$label must be 1-50 letters.";
        }
    }
    $email = trim((string) ($in['email'] ?? ''));
    if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Enter a valid email address.';
    }
    $car = normalize_car_number((string) ($in['car_number'] ?? ''));
    if (preg_match('/^[A-Z0-9]{4,12}$/', $car) !== 1) {
        $errors[] = 'Enter a valid car number (4-12 letters or digits).';
    }
    $password = (string) ($in['password'] ?? '');
    // Same rule the original register.php enforced.
    if (preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{6,}$/', $password) !== 1) {
        $errors[] = 'Password must be at least 6 characters long, contain at least 1 uppercase letter, and 1 special symbol.';
    }
    if (strlen($password) > 72) { // bcrypt only uses the first 72 bytes
        $errors[] = 'Password must be at most 72 characters.';
    }
    return [$errors, ['first_name' => $first, 'last_name' => $last, 'email' => $email,
                      'car_number' => $car, 'password' => $password]];
}

/** Insert a user. Returns true, or false when the email is already registered. */
function register_user($db, array $clean): bool // $db: mysqli (old pages/tests) or PDO
{
    if ($db instanceof PDO) {
        return register_user_pdo($db, $clean);
    }
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->bind_param('s', $clean['email']);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($exists) {
        return false;
    }
    $hash = password_hash($clean['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (password, first_name, last_name, email, car_number) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $hash, $clean['first_name'], $clean['last_name'], $clean['email'], $clean['car_number']);
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $ex) {
        if ($ex->getCode() === 1062) { // duplicate key: someone registered the same email at the same moment
            return false;
        }
        throw $ex;
    }
    $stmt->close();
    return true;
}

/** Check email + password. Returns the user row (id, first_name) or null. */
function authenticate_user($db, string $email, string $password): ?array // $db: mysqli or PDO
{
    if ($db instanceof PDO) {
        $st = $db->prepare('SELECT id, first_name, password FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        return ($user && password_verify($password, (string) $user['password'])) ? $user : null;
    }
    $stmt = $db->prepare('SELECT id, first_name, password FROM users WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($user && password_verify($password, (string) $user['password'])) {
        return $user;
    }
    return null;
}

/** PDO twin of register_user(): same checks, same table. */
function register_user_pdo(PDO $db, array $clean): bool
{
    $st = $db->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$clean['email']]);
    if ($st->fetch() !== false) {
        return false;
    }
    try {
        $db->prepare('INSERT INTO users (password, first_name, last_name, email, car_number) VALUES (?, ?, ?, ?, ?)')
           ->execute([password_hash($clean['password'], PASSWORD_DEFAULT), $clean['first_name'], $clean['last_name'], $clean['email'], $clean['car_number']]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') { // same email registered at the same moment
            return false;
        }
        throw $ex;
    }
    return true;
}
