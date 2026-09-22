<?php

$databaseConfig = require __DIR__ . '/../../config/database.php';

$conn = new mysqli(
    $databaseConfig['host'],
    $databaseConfig['username'],
    $databaseConfig['password'],
    '',
    $databaseConfig['port']
);

if ($conn->connect_error) {
    http_response_code(500);
    exit('MySQL connection failed. Check the database configuration and try again.');
}

$databaseName = $databaseConfig['database'];

if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
    http_response_code(500);
    exit('DB_NAME may only contain letters, numbers, and underscores.');
}

$sql = "CREATE DATABASE IF NOT EXISTS `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

if ($conn->query($sql)) {
    echo 'Database is ready.';
} else {
    http_response_code(500);
    echo 'Unable to create the database: ' . htmlspecialchars($conn->error);
}

$conn->close();
