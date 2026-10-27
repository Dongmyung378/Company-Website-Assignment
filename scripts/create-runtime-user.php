<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('COMPANY_SETUP_CONTEXT', true);
require __DIR__ . '/../database/setup_support.php';
umask(0077);
ini_set('display_errors', '0');

$user = null;
$host = '127.0.0.1';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php scripts/create-runtime-user.php [--user=company_app] [--host=127.0.0.1]\nUses DB_SETUP_USER/PASSWORD. Refuses to replace an existing account.\nUses DB_PASSWORD when set, otherwise generates and privately exports a new password.\n");
        exit(0);
    }
    if (str_starts_with($argument, '--user=')) {
        $user = substr($argument, 7);
    } elseif (str_starts_with($argument, '--host=')) {
        $host = substr($argument, 7);
    } else {
        fwrite(STDERR, "Unknown option; use --help.\n");
        exit(1);
    }
}
$db = null;
$created = false;
$privatePath = null;
try {
    require_once __DIR__ . '/../config/environment.php';
    company_load_environment();
    $user ??= company_env('DB_USER', 'company_app');
    if ($user === null || !preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) || strtolower($user) === 'root' || !in_array($host, ['127.0.0.1','localhost','::1'], true)) {
        throw new RuntimeException('Use a non-root account name and a loopback host.');
    }
    [$db,$config] = company_setup_connection(false);
    $configuredPassword = getenv('DB_PASSWORD');
    $password = $configuredPassword !== false && $configuredPassword !== ''
        ? $configuredPassword
        : rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $target = "'" . $db->real_escape_string($user) . "'@'" . $db->real_escape_string($host) . "'";
    $database = '`' . $config['database'] . '`';
    $privatePath = company_setup_write_private([
        'generated_at'=>gmdate(DATE_ATOM),
        'DB_HOST'=>$config['host'], 'DB_PORT'=>$config['port'],
        'DB_NAME'=>$config['database'], 'DB_USER'=>$user, 'DB_PASSWORD'=>$password,
        'mysql_account_host'=>$host,
    ], 'runtime-db');
    // CREATE USER deliberately omits IF NOT EXISTS and never changes an established password.
    $db->query('CREATE USER ' . $target . " IDENTIFIED BY '" . $db->real_escape_string($password) . "'");
    $created = true;
    foreach (['department','office','position'] as $table) {
        $db->query("GRANT SELECT ON $database.`$table` TO $target");
    }
    foreach (['employees','emergency_table','holiday_balance','holiday_requests','login'] as $table) {
        $db->query("GRANT SELECT,INSERT,UPDATE ON $database.`$table` TO $target");
    }
    $db->query("GRANT SELECT,DELETE ON $database.`delete_employee_logs` TO $target");
    $db->query("GRANT SELECT,INSERT,DELETE ON $database.`login_attempts` TO $target");
    $db->query("GRANT EXECUTE ON PROCEDURE $database.`delete_employee_with_log` TO $target");
    fwrite(STDOUT, 'Runtime account created with application table/routine privileges only. Private connection details: ' . $privatePath . "\n");
} catch (Throwable $error) {
    if ($created && $db instanceof mysqli) {
        try { $db->query('DROP USER ' . $target); } catch (Throwable) {}
    }
    if ($privatePath !== null) {
        unlink($privatePath);
    }
    error_log('Runtime account creation failed: ' . $error->getMessage());
    fwrite(STDERR, "Unable to create the runtime account. Existing accounts are never overwritten; check the CLI error log.\n");
    exit(1);
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}
