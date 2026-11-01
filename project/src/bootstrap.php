<?php
/**
 * Shared bootstrap for the Parkaro parking system.
 *
 * Every page includes this file (directly, or through db.php). It provides:
 *   - configuration (.env file or environment variables, with XAMPP defaults)
 *   - one database connection helper:           db()
 *   - safe HTML output helper:                  e()
 *   - hardened session start:                   start_secure_session()
 *   - CSRF protection:                          csrf_field() / csrf_check()
 *   - authentication guards:                    require_admin() / require_user()
 *   - signed ticket links (no login needed):    ticket_url() / ticket_token_valid()
 *
 * Nothing in here prints credentials or database error text to the browser.
 */
declare(strict_types=1);

if (defined('PARKARO_BOOTSTRAPPED')) {
    return;
}
define('PARKARO_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));

/* ------------------------------------------------------------------ */
/* Configuration                                                       */
/* ------------------------------------------------------------------ */

/** Read KEY=VALUE lines from .env once. Real environment variables win. */
function env(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $file = [];
        $path = APP_ROOT . '/.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $file[trim($k)] = trim(trim($v), "\"'");
            }
        }
    }
    $fromEnv = getenv($key);
    if ($fromEnv !== false) {
        return $fromEnv;
    }
    return $file[$key] ?? $default;
}

/** Secret used to sign ticket links. Created once and stored outside Git. */
function app_key(): string
{
    $fromEnv = env('APP_KEY');
    if ($fromEnv !== null && $fromEnv !== '') {
        return $fromEnv;
    }
    $dir = APP_ROOT . '/storage';
    $path = $dir . '/app.key';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_readable($path)) {
        file_put_contents($path, bin2hex(random_bytes(32)), LOCK_EX);
    }
    return trim((string) file_get_contents($path));
}

/* ------------------------------------------------------------------ */
/* Error handling: log details, show a generic message                 */
/* ------------------------------------------------------------------ */

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(static function (Throwable $ex): void {
        error_log('[parkaro] ' . get_class($ex) . ': ' . $ex->getMessage());
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'Something went wrong. Please try again later.';
        exit;
    });
}

/* ------------------------------------------------------------------ */
/* Database                                                            */
/* ------------------------------------------------------------------ */

/** One shared mysqli connection, created on first use. Throws on failure. */
function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }
    // Make mysqli throw exceptions instead of returning false silently.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli(
        env('DB_HOST', 'localhost'),
        env('DB_USER', 'root'),
        env('DB_PASS', ''),
        env('DB_NAME', 'parkaro')
    );
    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * PDO connection used by the newer modules (locations, reservations, payments,
 * earnings). It talks to the same MySQL database as db() but through PDO, so the
 * same SQL can also be run against SQLite in the automated tests.
 * Tests pass their own PDO once: pdo($sqlitePdo). The older pages keep using db().
 */
function pdo(?PDO $override = null): PDO
{
    static $conn = null;
    if ($override !== null) {
        $conn = $override;
    }
    if ($conn instanceof PDO) {
        return $conn;
    }
    $dsn = 'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME', 'parkaro') . ';charset=utf8mb4';
    $conn = new PDO($dsn, (string) env('DB_USER', 'root'), (string) env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $conn;
}

/* ------------------------------------------------------------------ */
/* Output escaping                                                     */
/* ------------------------------------------------------------------ */

/** Escape a value for safe use in HTML text or attributes. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ------------------------------------------------------------------ */
/* Sessions                                                            */
/* ------------------------------------------------------------------ */

function start_secure_session(): void
{
    if (PHP_SAPI === 'cli') {
        $_SESSION = $_SESSION ?? [];
        return;
    }
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Call right after a successful login to prevent session fixation. */
function login_session_regenerate(): void
{
    if (PHP_SAPI !== 'cli') {
        session_regenerate_id(true);
    }
}

/** Fully end the session (used by logout.php). */
function destroy_session(): void
{
    start_secure_session();
    $_SESSION = [];
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

/* ------------------------------------------------------------------ */
/* CSRF protection                                                     */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to place inside every state-changing <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** True when the submitted token matches the one stored in the session. */
function csrf_valid(?string $submitted): bool
{
    start_secure_session();
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($submitted) && $expected !== '' && hash_equals($expected, $submitted);
}

/** Stop the request with 403 unless the POST carries a valid CSRF token. */
function csrf_check(): void
{
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or missing security token. Go back, refresh the page and try again.');
    }
}

/* ------------------------------------------------------------------ */
/* Authentication guards                                               */
/* ------------------------------------------------------------------ */

/*
 * ROLE POLICY (one role per session)
 *   User  session keys : user_id, first_name
 *   Admin session keys : admin_username, admin_zone
 * Logging in as one role removes the other role's keys from the session, so a browser
 * is never user AND admin at the same time. Logout destroys the whole session.
 */

function is_admin_logged_in(): bool
{
    start_secure_session();
    return !empty($_SESSION['admin_username']) && !empty($_SESSION['admin_zone']);
}

function is_user_logged_in(): bool
{
    start_secure_session();
    return !empty($_SESSION['user_id']);
}

/** Start a USER session (after the password was verified). Ends any admin session. */
function login_user_session(int $userId, string $firstName): void
{
    start_secure_session();
    login_session_regenerate(); // new session id: prevents session fixation
    unset($_SESSION['admin_username'], $_SESSION['admin_zone']);
    $_SESSION['user_id'] = $userId;
    $_SESSION['first_name'] = $firstName;
}

/** Start an ADMIN session (after the password was verified). Ends any user session. */
function login_admin_session(string $username, string $zone): void
{
    start_secure_session();
    login_session_regenerate();
    unset($_SESSION['user_id'], $_SESSION['first_name']);
    $_SESSION['admin_username'] = $username;
    $_SESSION['admin_zone'] = $zone;
}

/** Where users land after logging in (and after registering and logging in). */
const USER_HOME = 'my_dashboard.php';
/** Canonical admin landing page (the zone dashboard every admin page links back to). */
const ADMIN_HOME = 'dashboard1.php';

/**
 * A page a user was sent away from because they were logged out. Only these user pages are
 * accepted, so the value can never become an open redirect or an admin page.
 */
function safe_next(?string $next): string
{
    $next = (string) $next;
    if (preg_match('/^(find_parking|reserve|pay|my_dashboard)\.php(\?[A-Za-z0-9_=&.%-]{0,200})?$/', $next) === 1) {
        return $next;
    }
    return '';
}

/** Look an admin up by username (PDO). Returns username, password, admin_zone or null. */
function find_admin(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare('SELECT username, password, admin_zone FROM admin_users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Pages for admins only. Returns [username, zone] of the logged-in admin.
 * Anyone else (logged out, or a normal user) is sent to the admin login page.
 */
function require_admin(): array
{
    if (!is_admin_logged_in()) {
        header('Location: ' . app_url('login1.php'));
        exit;
    }
    return [(string) $_SESSION['admin_username'], (string) $_SESSION['admin_zone']];
}

/**
 * Pages for registered users only. Returns the user id. Anyone else (logged out, or an admin)
 * is sent to the user login, which brings them back here afterwards.
 */
function require_user(): int
{
    start_secure_session();
    if (empty($_SESSION['user_id'])) {
        $url = 'index.php?login=true';
        $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && safe_next($here) !== '') {
            $url .= '&next=' . rawurlencode($here);
        }
        header('Location: ' . app_url($url));
        exit;
    }
    return (int) $_SESSION['user_id'];
}

/** Build a URL relative to the site root even when called from dashboards/. */
function app_url(string $path): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if (substr($dir, -11) === '/dashboards') {
        $dir = substr($dir, 0, -11);
    }
    return rtrim($dir, '/') . '/' . ltrim($path, '/');
}

/** Admin passwords: hashed rows use password_verify; old plaintext rows still
 *  work until tools/migrate_admin_passwords.php has been run. */
function admin_password_matches(string $input, string $stored): bool
{
    if ($input === '' || $stored === '') {
        return false; // an empty stored password must never let an empty login through
    }
    if (preg_match('/^\$(2[aby]|argon2i|argon2id)\$/', $stored) === 1) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

/* ------------------------------------------------------------------ */
/* Signed ticket links (guests can book without an account)            */
/* ------------------------------------------------------------------ */

function ticket_token(int $bookingId): string
{
    return hash_hmac('sha256', 'ticket:' . $bookingId, app_key());
}

function ticket_token_valid(int $bookingId, ?string $token): bool
{
    return is_string($token) && hash_equals(ticket_token($bookingId), $token);
}

function ticket_url(string $page, int $bookingId): string
{
    return $page . '?' . http_build_query(['id' => $bookingId, 't' => ticket_token($bookingId)]);
}

/* ------------------------------------------------------------------ */
/* Small query helpers                                                 */
/* ------------------------------------------------------------------ */

/** Run a prepared SELECT and return the result set. $types e.g. 'si'. */
function db_select(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_result
{
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/** First column of the first row (e.g. a COUNT or SUM), or $default when NULL. */
function db_scalar(mysqli $db, string $sql, string $types = '', array $params = [], $default = 0)
{
    $row = db_select($db, $sql, $types, $params)->fetch_row();
    return ($row === null || $row[0] === null) ? $default : $row[0];
}
