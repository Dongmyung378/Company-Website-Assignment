<?php
require __DIR__ . '/../../config/connection.php';

$DepartmentTableSQL = "
    CREATE TABLE IF NOT EXISTS department (
        department_id INT AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(100) NOT NULL UNIQUE
    );
";
if ($conn->query($DepartmentTableSQL) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    die("Error creating table: " . $conn->error);
}

$PositionTableSQL = "
    CREATE TABLE IF NOT EXISTS position (
        position_id INT AUTO_INCREMENT PRIMARY KEY,
        position_name VARCHAR(100) NOT NULL UNIQUE,
        department_id INT,
        FOREIGN KEY (department_id) REFERENCES department(department_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
    );
";
if ($conn->query($PositionTableSQL) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    die("Error creating table: " . $conn->error);
}

$sql = "SELECT DISTINCT department, position FROM materials";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $department_name = $row['department'];
        $position_name = $row['position'];

        $getDepartmentIDSQL = "SELECT department_id FROM department WHERE department_name = ?";
        $stmt2 = $conn->prepare($getDepartmentIDSQL);
        $stmt2->bind_param("s", $department_name);
        $stmt2->execute();
        $stmt2->bind_result($department_id);
        $stmt2->fetch();
        $stmt2->close();

        $insertPositionSQL = "INSERT IGNORE INTO position (position_name, department_id) VALUES (?, ?)";
        $stmt3 = $conn->prepare($insertPositionSQL);
        $stmt3->bind_param("si", $position_name, $department_id);

        if ($stmt3->execute()) {
            echo "Inserted position: " . htmlspecialchars($position_name) . " under department: " . htmlspecialchars($department_name) . "<br>";
        } else {
            echo "Error inserting position: " . $stmt3->error . "<br>";
        }

        $stmt3->close();
    }
} else {
    echo "No departments or positions found.<br>";
}

$conn->close();
