<?php
require __DIR__ . '/../../config/connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$HolidayBalanceTableSQL = "
    CREATE TABLE IF NOT EXISTS holiday_balance (
        employee_id INT PRIMARY KEY,
        annual_leave INT DEFAULT 28,
        sick_leave INT DEFAULT 10,
        personal_leave INT DEFAULT 5,
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON DELETE CASCADE
    );
";

if ($conn->query($HolidayBalanceTableSQL) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}


$HolidayRequestsTableSQL = "
    CREATE TABLE IF NOT EXISTS holiday_requests (
        request_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT,
        leave_type ENUM('Annual', 'Sick', 'Personal'),
        start_date DATE,
        end_date DATE,
        reason TEXT,
        status ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON DELETE CASCADE
    );
";

if ($conn->query($HolidayRequestsTableSQL) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

$conn->close();
