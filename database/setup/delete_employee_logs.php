<?php
require __DIR__ . '/../../config/connection.php';

// check error conditions
error_reporting(E_ALL);
// display error on browser
ini_set('display_errors', 1);

$EmployeeLogSQL = "
    CREATE TABLE IF NOT EXISTS delete_employee_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    employee_name VARCHAR(20) NOT NULL,
    employee_salary VARCHAR(20) NOT NULL,
    employee_department VARCHAR(20) NOT NULL,
    employee_position VARCHAR(20) NOT NULL,
    employee_dob DATE NOT NULL,
    employee_nin VARCHAR(20) NOT NULL,
    deleted_by_id INT NOT NULL,
    deleted_by_name VARCHAR(20) NOT NULL,
    reason VARCHAR(255),
    deleted_time DATETIME NOT NULL);
";

if ($conn->query($EmployeeLogSQL) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}
$conn->close();
