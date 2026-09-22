<?php
require __DIR__ . '/../../config/connection.php';

$EmployeesTable = "
    CREATE TABLE IF NOT EXISTS employees (
        employee_id INT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        salary VARCHAR(20) NOT NULL,
        email VARCHAR(100) NOT NULL,
        dob DATE NOT NULL,
        home_address VARCHAR(255) NOT NULL,
        hired_date DATE NOT NULL,
        contract VARCHAR(50) NOT NULL,
        nin VARCHAR(50) NOT NULL,
        department_id INT NOT NULL,
        office_id INT NOT NULL,
        position_id INT NOT NULL,
        FOREIGN KEY (department_id) REFERENCES department(department_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (office_id) REFERENCES office(office_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE,
        FOREIGN KEY (position_id) REFERENCES `position`(position_id)
            ON DELETE CASCADE
            ON UPDATE CASCADE
    );
";

$conn->query($EmployeesTable);

$sql = "SELECT id, name, salary, email, dob, home_address, hired_date, contract, nin, department, office, position FROM materials";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employee_id = $row['id'];
        $name = $row['name'];
        $salary = $row['salary'];
        $email = $row['email'];
        $dob = $row['dob'];
        $home_address = $row['home_address'];
        $hired_date = $row['hired_date'];
        $contract = $row['contract'];
        $nin = $row['nin'];
        $department_name = $row['department'];
        $office_name = $row['office'];
        $position_name = $row['position'];

        $getDepartmentIDSQL = "SELECT department_id FROM department WHERE department_name = ?";
        $stmt1 = $conn->prepare($getDepartmentIDSQL);
        $stmt1->bind_param("s", $department_name);
        $stmt1->execute();
        $stmt1->bind_result($department_id);
        $stmt1->fetch();
        $stmt1->close();

        $getOfficeIDSQL = "SELECT office_id FROM office WHERE office_name = ?";
        $stmt2 = $conn->prepare($getOfficeIDSQL);
        $stmt2->bind_param("s", $office_name);
        $stmt2->execute();
        $stmt2->bind_result($office_id);
        $stmt2->fetch();
        $stmt2->close();

        $getPositionIDSQL = "SELECT position_id FROM position WHERE position_name = ?";
        $stmt3 = $conn->prepare($getPositionIDSQL);
        $stmt3->bind_param("s", $position_name);
        $stmt3->execute();
        $stmt3->bind_result($position_id);
        $stmt3->fetch();
        $stmt3->close();

        $insertEmployeeSQL = "
            INSERT INTO employees (
                employee_id, name, salary, email, dob, home_address, hired_date, contract, nin, department_id, office_id, position_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmt4 = $conn->prepare($insertEmployeeSQL);
        $stmt4->bind_param(
            "issssssssiii",
            $employee_id,
            $name,
            $salary,
            $email,
            $dob,
            $home_address,
            $hired_date,
            $contract,
            $nin,
            $department_id,
            $office_id,
            $position_id
        );

        if ($stmt4->execute()) {
            echo "Inserted employee: " . htmlspecialchars($name) . " (ID: $employee_id)<br>";
        } else {
            echo "Error inserting employee: " . $stmt4->error . "<br>";
        }

        $stmt4->close();
    }
} else {
    echo "No employee data found.<br>";
}

$conn->close();
