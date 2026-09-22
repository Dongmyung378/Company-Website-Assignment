<?php
require __DIR__ . '/../../config/connection.php';

try {
    $createLogintable = "
        CREATE TABLE IF NOT EXISTS login (
            employee_id INT NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            PRIMARY KEY (employee_id),
            FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
        );
    ";
    $conn->query($createLogintable);

    $selectEmployees = "SELECT employee_id FROM employees";
    $result = $conn->query($selectEmployees);

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $employee_id = $row['employee_id'];
            $default_password = password_hash('0000', PASSWORD_BCRYPT);

            $insertLogin = "
                INSERT INTO login (employee_id, password_hash)
                VALUES ('$employee_id', '$default_password')
                ON DUPLICATE KEY UPDATE password_hash='$default_password';
            ";
            $conn->query($insertLogin);
        }
    }
} catch (Exception $e) {
    echo $e->getMessage();
} finally {
    $conn->close();
}
