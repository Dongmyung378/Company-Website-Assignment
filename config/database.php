<?php
declare(strict_types=1);

require_once __DIR__ . '/environment.php';
company_load_environment();

$entry = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
$legacySetupDirectory = realpath(dirname(__DIR__) . '/database/setup');
$isSetup = PHP_SAPI === 'cli' && (
    (defined('COMPANY_SETUP_CONTEXT') && COMPANY_SETUP_CONTEXT === true)
    || ($legacySetupDirectory !== false && str_starts_with(str_replace('\\', '/', $entry), str_replace('\\', '/', $legacySetupDirectory) . '/'))
);
$username = company_env($isSetup ? 'DB_SETUP_USER' : 'DB_USER');
$password = company_env($isSetup ? 'DB_SETUP_PASSWORD' : 'DB_PASSWORD');
if ($username === null || trim($username) === '' || $password === null || $password === '') {
    throw new RuntimeException('Explicit nonempty database credentials are required for this application context.');
}
if (!$isSetup && strcasecmp(trim($username), 'root') === 0) {
    throw new RuntimeException('The application must use a restricted database account, not root.');
}
$port = company_env('DB_PORT', '3307');
if ($port === null || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    throw new RuntimeException('Invalid database port configuration.');
}
$host = company_env('DB_HOST', '127.0.0.1');
$database = company_env('DB_NAME', 'company_db');
if ($host === null || $host === '' || $database === null || !preg_match('/\A[A-Za-z0-9_]+\z/', $database)) {
    throw new RuntimeException('Invalid database connection configuration.');
}

return ['host' => $host, 'username' => $username, 'password' => $password, 'database' => $database, 'port' => (int) $port];
