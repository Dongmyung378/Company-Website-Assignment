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
    echo "Error creating table: " . $conn->error . "<br>";
}

$sql = "SELECT DISTINCT department FROM materials";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $department_name = $row['department'];

        $insertDepartmentSQL = "INSERT IGNORE INTO department (department_name) VALUES (?)";
        $stmt = $conn->prepare($insertDepartmentSQL);
        $stmt->bind_param("s", $department_name);

        if ($stmt->execute()) {
            echo "Inserted department: " . htmlspecialchars($department_name) . "<br>";
        } else {
            echo "Error inserting department: " . $stmt->error . "<br>";
        }

        $stmt->close();
    }
} else {
    echo "No departments found.<br>";
}

$conn->close();
