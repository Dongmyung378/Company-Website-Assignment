<?php
declare(strict_types=1);

require_once __DIR__ . '/connection.php';

set_exception_handler(static function (Throwable $error): void {
    company_private_log($error);
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo function_exists('ui_t') ? ui_t('Unable to complete this request. Please try again later.') : 'Unable to complete this request. Please try again later.';
});

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function company_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) parse_url(company_env('APP_ORIGIN', '') ?? '', PHP_URL_SCHEME)) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    if (session_save_path() === '' && ini_get('session.save_handler') === 'files') {
        $sessionDirectory = dirname(__DIR__) . '/var/sessions';
        if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true)) {
            throw new RuntimeException('Unable to create private session storage.');
        }
        session_save_path($sessionDirectory);
    }
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => company_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) {
        throw new RuntimeException('Unable to start a secure session.');
    }
}

function e(mixed $value): string
{
    return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function abort_request(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    exit(function_exists('ui_t') ? ui_t($message) : $message);
}

function input_string(array $input, string $key, string $default = ''): string
{
    if (!isset($input[$key])) {
        return $default;
    }
    if (!is_scalar($input[$key]) || strlen((string) $input[$key]) > 16384) {
        abort_request(400, 'Invalid request data.');
    }
    return (string) $input[$key];
}

function csrf_token(): string
{
    if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function company_request_origin(): string
{
    $configured = company_env('APP_ORIGIN', '') ?? '';
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('APP_ORIGIN must contain an HTTP(S) origin only.');
        }
        return strtolower(rtrim($configured, '/'));
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/\A(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(?::[0-9]+)?\z/', $host)) {
        abort_request(400, 'Invalid request host.');
    }
    return (company_is_https() ? 'https://' : 'http://') . strtolower($host);
}

function require_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        abort_request(405, 'This action requires a POST request.');
    }
    $submitted = $_POST['_csrf'] ?? null;
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        abort_request(403, 'The form expired or could not be verified. Reload the page and try again.');
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null && (!is_string($origin) || strtolower(rtrim($origin, '/')) !== company_request_origin())) {
        abort_request(403, 'The request origin could not be verified.');
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        abort_request(403, 'The request origin could not be verified.');
    }
}

function company_session_limit(string $name, int $default): int
{
    $value = company_env($name);
    return $value !== null && ctype_digit($value) && (int) $value > 0 && (int) $value <= 2592000 ? (int) $value : $default;
}

function company_destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'] ?? 'Lax']);
    }
    session_destroy();
}

function company_login_redirect(): never
{
    company_destroy_session();
    header('Location: login.php');
    exit();
}

/** Every request revalidates account existence, role and credential version. */
function require_login(bool $allowPasswordChange = false): array
{
    global $conn;
    if (($_SESSION['logged_in'] ?? false) !== true || !isset($_SESSION['employee_id'], $_SESSION['_auth_version'], $_SESSION['_created_at'], $_SESSION['_last_activity'])) {
        company_login_redirect();
    }
    $now = time();
    if ($now - (int) $_SESSION['_last_activity'] > company_session_limit('SESSION_IDLE_SECONDS', 1800) || $now - (int) $_SESSION['_created_at'] > company_session_limit('SESSION_ABSOLUTE_SECONDS', 28800)) {
        company_login_redirect();
    }
    $stmt = $conn->prepare('SELECT e.employee_id,e.department_id,e.name,d.department_name,l.auth_version,l.must_change_password FROM employees e JOIN department d ON d.department_id=e.department_id JOIN login l ON l.employee_id=e.employee_id WHERE e.employee_id=? AND l.password_hash IS NOT NULL');
    $stmt->execute([(int) $_SESSION['employee_id']]);
    $employee = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$employee || (int) $employee['auth_version'] !== (int) $_SESSION['_auth_version']) {
        company_login_redirect();
    }
    foreach (['employee_id', 'department_id', 'auth_version', 'must_change_password'] as $key) {
        $employee[$key] = (int) $employee[$key];
    }
    $_SESSION['logged_in'] = true;
    $_SESSION['employee_id'] = $employee['employee_id'];
    $_SESSION['name'] = $employee['name'];
    $_SESSION['department'] = $employee['department_id'];
    $_SESSION['_last_activity'] = $now;
    if (!$allowPasswordChange && $employee['must_change_password']) {
        header('Location: change_password.php');
        exit();
    }
    return $employee;
}

function require_admin(): array
{
    $employee = require_login();
    if ($employee['department_name'] !== 'Executive') {
        header('Location: main.php');
        exit();
    }
    return $employee;
}

function company_password_algorithm(): string|int
{
    return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true) ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function password_validation_error(string $password): string
{
    $maximum = company_password_algorithm() === PASSWORD_BCRYPT ? 72 : 1024;
    if (str_contains($password, "\0") || strlen($password) > $maximum) {
        return 'The password is too long for this server. Use at most ' . $maximum . ' UTF-8 bytes.';
    }
    if (preg_match('/\A.{15,}\z/us', $password) !== 1 || trim($password) === '') {
        return 'Use a passphrase of at least 15 characters.';
    }
    if (in_array(strtolower($password), ['passwordpassword', 'passwordpassword1', '123456789012345', '1234567890123456', 'qwertyuiopasdfgh', 'letmeinletmeinletmein'], true)) {
        return 'Choose a less predictable passphrase.';
    }
    return '';
}

function company_hash_password(#[SensitiveParameter] string $password): string
{
    // Bcrypt has a 72-byte boundary; never silently truncate on fallback servers.
    if (strlen($password) > (company_password_algorithm() === PASSWORD_BCRYPT ? 72 : 1024) || str_contains($password, "\0")) {
        throw new InvalidArgumentException('Password length is unsupported by the configured algorithm.');
    }
    return password_hash($password, company_password_algorithm());
}

function provision_temporary_password(mysqli $connection, int $employeeId): string
{
    $temporary = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $stmt = $connection->prepare('UPDATE login SET password_hash=?,must_change_password=1,auth_version=auth_version+1 WHERE employee_id=?');
    $stmt->execute([company_hash_password($temporary), $employeeId]);
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('Unable to provision the employee login.');
    }
    $stmt->close();
    return $temporary;
}

/** DB-backed, race-safe failure limits shared by sign-in and current-password checks. */
function company_authenticate(string $employeeId, #[SensitiveParameter] string $password, bool &$rateLimited): ?array
{
    global $conn;
    $rateLimited = false;
    $employeeId = trim($employeeId);
    $accountKey = hash('sha256', $employeeId);
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); // Never trust spoofable forwarding headers.
    $ipKey = hash('sha256', $ip);
    $lockNames = ['login-a-' . substr($accountKey, 0, 48), 'login-i-' . substr($ipKey, 0, 48)];
    sort($lockNames, SORT_STRING);
    $held = [];
    try {
        foreach ($lockNames as $lockName) {
            $lock = $conn->prepare('SELECT GET_LOCK(?,2) acquired');
            $lock->execute([$lockName]);
            $acquired = $lock->get_result()->fetch_assoc();
            $lock->close();
            if ((int) ($acquired['acquired'] ?? 0) !== 1) {
                $rateLimited = true;
                return null;
            }
            $held[] = $lockName;
        }
        $conn->query('DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP(6) - INTERVAL 1 DAY LIMIT 500');
        $count = $conn->prepare('SELECT (SELECT COUNT(*) FROM login_attempts WHERE account_key=? AND attempted_at>=UTC_TIMESTAMP(6)-INTERVAL 15 MINUTE) account_failures,(SELECT COUNT(*) FROM login_attempts WHERE ip_key=? AND attempted_at>=UTC_TIMESTAMP(6)-INTERVAL 15 MINUTE) ip_failures');
        $count->execute([$accountKey, $ipKey]);
        $failures = $count->get_result()->fetch_assoc();
        $count->close();
        if ((int) $failures['account_failures'] >= 10 || (int) $failures['ip_failures'] >= 50) {
            $rateLimited = true;
            return null;
        }
        $employee = null;
        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $employeeId) && (int) $employeeId <= 2147483647) {
            $stmt = $conn->prepare('SELECT e.employee_id,e.department_id,e.name,d.department_name,l.password_hash,l.auth_version,l.must_change_password FROM employees e JOIN department d ON d.department_id=e.department_id JOIN login l ON l.employee_id=e.employee_id WHERE e.employee_id=?');
            $stmt->execute([(int) $employeeId]);
            $employee = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
        $dummy = company_password_algorithm() === PASSWORD_BCRYPT
            ? '$2y$12$sY0n8s7ij.3LE2YrLdPnge7LqmhDJIl9DVDqz2Upw2BNDAWGYhrBG'
            : '$argon2id$v=19$m=65536,t=4,p=1$SE9EcmFaeEpKN0U3NElYSA$W7HYzn0mwn0R2n4yUCxQQpub2ucFrVuLM0Pmh4FIeUI';
        $hash = is_string($employee['password_hash'] ?? null) ? $employee['password_hash'] : $dummy;
        $bcrypt = str_starts_with($hash, '$2');
        $supported = $password !== '' && !str_contains($password, "\0") && strlen($password) <= ($bcrypt ? 72 : 1024);
        $verified = password_verify($supported ? $password : 'unsupported-password-input', $hash);
        if (!$employee || !is_string($employee['password_hash']) || !$supported || !$verified) {
            $stmt = $conn->prepare('INSERT INTO login_attempts(account_key,ip_key,attempted_at) VALUES(?,?,UTC_TIMESTAMP(6))');
            $stmt->execute([$accountKey, $ipKey]);
            $stmt->close();
            return null;
        }
        $stmt = $conn->prepare('DELETE FROM login_attempts WHERE account_key=?');
        $stmt->execute([$accountKey]);
        $stmt->close();
        return $employee;
    } finally {
        foreach (array_reverse($held) as $lockName) {
            $release = $conn->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
            $release->close();
        }
    }
}

function company_establish_session(array $employee): void
{
    $_SESSION = [];
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to rotate the session.');
    }
    $_SESSION = ['logged_in' => true, 'employee_id' => (int) $employee['employee_id'], 'name' => (string) $employee['name'], 'department' => (int) $employee['department_id'], '_auth_version' => (int) $employee['auth_version'], '_created_at' => time(), '_last_activity' => time(), '_csrf' => bin2hex(random_bytes(32))];
}

function valid_date(string $value): ?DateTimeImmutable
{
    if (!preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}\z/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $date->format('Y-m-d') === $value ? $date : null;
}

function calendar_days(DateTimeImmutable|string $start, DateTimeImmutable|string $end): int
{
    $start = valid_date($start instanceof DateTimeImmutable ? $start->format('Y-m-d') : $start);
    $end = valid_date($end instanceof DateTimeImmutable ? $end->format('Y-m-d') : $end);
    if ($start === null || $end === null || $end < $start) {
        throw new InvalidArgumentException('Invalid calendar date range.');
    }
    return (int) $start->diff($end)->days + 1;
}

function csv_safe_text(string $value): string
{
    return preg_match('/\A[\x00-\x20]*[=+@-]/u', $value) || preg_match('/\A[\t\r\n]/', $value) ? "'" . $value : $value;
}
