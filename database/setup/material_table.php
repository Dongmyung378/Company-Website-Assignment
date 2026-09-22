<?php
require __DIR__ . '/../../config/connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$MaterialsTableSQL = "
    CREATE TABLE IF NOT EXISTS materials (
        id INT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        position VARCHAR(100) NOT NULL,
        department VARCHAR(100) NOT NULL,
        salary VARCHAR(10) NOT NULL,
        email VARCHAR(100) NOT NULL,
        dob DATE NOT NULL,
        office VARCHAR(100) NOT NULL,
        home_address VARCHAR(255) NOT NULL,
        hired_date DATE NOT NULL,
        contract VARCHAR(50) NOT NULL,
        nin VARCHAR(50) NOT NULL,
        emergency_name VARCHAR(100),
        emergency_relationship VARCHAR(50),
        emergency_phone VARCHAR(50)
    );
";

$conn->query($MaterialsTableSQL);
$conn->close();
