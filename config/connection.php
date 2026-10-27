<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
require_once __DIR__ . '/environment.php';

try {
    $databaseConfig = require __DIR__ . '/database.php';
    company_configure_private_errors();
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn = new mysqli($databaseConfig['host'], $databaseConfig['username'], $databaseConfig['password'], $databaseConfig['database'], $databaseConfig['port']);
    $conn->set_charset('utf8mb4');
} catch (Throwable $error) {
    company_private_log($error);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection unavailable. Check the private log and environment configuration.\n");
        exit(1);
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('The service is temporarily unavailable. Please try again later.');
}
