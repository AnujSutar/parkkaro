<?php
/**
 * TEST ONLY. Loaded by tests/sqlite/pages.php through PHP's  -d auto_prepend_file  before every page,
 * so the real pages can be exercised without MySQL, without the gateway and without the old mysqli login:
 *   - pdo() is pointed at the SQLite test database,
 *   - payment_gateway() is a Razorpay object whose HTTP client is a file-backed fake (no network),
 *   - the headers  X-Test-User: <id>  /  X-Test-Admin: <username>|<zone>  create a logged-in session.
 * Nothing in the application loads this file; it is never part of a normal request.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/dbx.php';
require_once __DIR__ . '/../../src/payments.php';
require_once __DIR__ . '/../../src/notify.php';

final class FileFakeHttp
{
    public function __construct(private string $file)
    {
    }

    private function load(): array
    {
        $raw = is_file($this->file) ? (string) file_get_contents($this->file) : '';
        return $raw === '' ? ['orders' => 0, 'payments' => []] : (array) json_decode($raw, true);
    }

    public function __invoke(string $method, string $url, ?array $json, string $user, string $pass): array
    {
        if ($user !== 'rzp_test_KEYID' || $pass !== 'test_key_secret') {
            return [401, '{}'];
        }
        $state = $this->load();
        if ($method === 'POST' && str_ends_with($url, '/orders')) {
            $state['orders']++;
            file_put_contents($this->file, json_encode($state));
            return [200, json_encode(['id' => 'order_PG' . $state['orders'], 'amount' => $json['amount'], 'currency' => $json['currency']])];
        }
        if ($method === 'GET' && preg_match('#/payments/([A-Za-z0-9_]+)$#', $url, $m)) {
            return isset($state['payments'][$m[1]]) ? [200, json_encode($state['payments'][$m[1]])] : [404, '{}'];
        }
        return [400, '{}'];
    }
}

/** TEST ONLY: records every message "sent" to a JSON-lines file; channels listed in PK_NOTIFY_FAIL (a file) throw. */
final class FileFakeNotify implements NotifyTransport
{
    public function __construct(private string $log, private string $failFile)
    {
    }

    public function isConfigured(string $channel): bool
    {
        $off = is_file($this->failFile . '.off') ? explode(',', trim((string) file_get_contents($this->failFile . '.off'))) : [];
        return !in_array($channel, $off, true);
    }

    public function send(string $channel, string $to, string $subject, string $text, string $html, array $vars = []): void
    {
        $fail = is_file($this->failFile) ? explode(',', trim((string) file_get_contents($this->failFile))) : [];
        if (in_array($channel, $fail, true)) {
            throw new NotifyException('simulated provider outage');
        }
        file_put_contents($this->log, json_encode(['channel' => $channel, 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html, 'vars' => $vars]) . "\n", FILE_APPEND | LOCK_EX);
    }
}

$db = new PDO('sqlite:' . getenv('PK_TEST_DB'), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 10,
]);
pdo($db);
payment_gateway(new RazorpayGateway('rzp_test_KEYID', 'test_key_secret', 'test_webhook_secret', new FileFakeHttp((string) getenv('PK_FAKE_STATE'))));

if (getenv('PK_NOTIFY_LOG')) {
    notify_transport(new FileFakeNotify((string) getenv('PK_NOTIFY_LOG'), (string) getenv('PK_NOTIFY_FAIL')));
}

if (!empty($_SERVER['HTTP_X_TEST_USER'])) {
    start_secure_session();
    $_SESSION['user_id'] = (int) $_SERVER['HTTP_X_TEST_USER'];
    $_SESSION['first_name'] = 'Tester';
}
if (!empty($_SERVER['HTTP_X_TEST_ADMIN'])) {
    start_secure_session();
    [$_SESSION['admin_username'], $_SESSION['admin_zone']] = explode('|', $_SERVER['HTTP_X_TEST_ADMIN']) + [1 => 'Zone T'];
}
