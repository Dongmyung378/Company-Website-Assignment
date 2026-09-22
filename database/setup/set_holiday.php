<?php
require __DIR__ . '/../../config/connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function initializeHolidayBalance($conn)
{
    $getEmployeesSQL = "SELECT employee_id FROM employees";
    $result = $conn->query($getEmployeesSQL);

    if (!$result) {
        die("Error fetching employees: " . $conn->error);
    }

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $employee_id = $row['employee_id'];
            $count = 0;

            $checkBalanceSQL = "SELECT COUNT(*) as count FROM holiday_balance WHERE employee_id = ?";
            $stmt = $conn->prepare($checkBalanceSQL);

            if (!$stmt) {
                die("Prepare failed: " . $conn->error);
            }

            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count == 0) {
                $insertBalanceSQL = "
                    INSERT INTO holiday_balance (employee_id, annual_leave, sick_leave, personal_leave)
                    VALUES (?, 28, 10, 5)
                ";
                $stmt = $conn->prepare($insertBalanceSQL);

                if (!$stmt) {
                    die("Prepare failed: " . $conn->error);
                }

                $stmt->bind_param("i", $employee_id);

                if ($stmt->execute()) {
                    echo "Holiday balance initialized: $employee_id<br>";
                } else {
                    echo "Error initializing holiday balance: $employee_id - " . $stmt->error . "<br>";
                }

                $stmt->close();
            } else {
                echo "Holiday balance already exists for employee ID: $employee_id<br>";
            }
        }
    } else {
        echo "No employees found.<br>";
    }
}

initializeHolidayBalance($conn);

$conn->close();
