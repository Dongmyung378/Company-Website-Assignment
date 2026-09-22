<?php

$databaseConfig = require __DIR__ . '/database.php';

$conn = new mysqli(
    $databaseConfig['host'],
    $databaseConfig['username'],
    $databaseConfig['password'],
    $databaseConfig['database'],
    $databaseConfig['port']
);

if ($conn->connect_error) {
    http_response_code(500);
    exit('Database connection failed. Check the database configuration and try again.');
}

$conn->set_charset('utf8mb4');
