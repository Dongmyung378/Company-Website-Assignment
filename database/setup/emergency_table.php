<?php
require __DIR__ . '/../../config/connection.php';

$EmergencyTable = "
    CREATE TABLE IF NOT EXISTS emergency_table (
        employee_id INT NOT NULL,
        emergency_name VARCHAR(100),
        emergency_relationship VARCHAR(50),
        emergency_phone VARCHAR(50),
        PRIMARY KEY (employee_id),
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
    );
";

if ($conn->query($EmergencyTable) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    die("Error creating table: " . $conn->error);
}

$sql = "SELECT id, emergency_name, emergency_relationship, emergency_phone FROM materials";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $employee_id = $row['id'];
        $contact_name = $row['emergency_name'];
        $relationship = $row['emergency_relationship'];
        $phone_number = $row['emergency_phone'];

        $insertSQL = "INSERT IGNORE INTO emergency_table (employee_id, emergency_name, emergency_relationship, emergency_phone) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($insertSQL);
        $stmt->bind_param("isss", $employee_id, $contact_name, $relationship, $phone_number);

        if ($stmt->execute()) {
            echo "Inserted emergency contact for employee ID: $employee_id - " . htmlspecialchars($contact_name) . "<br>";
        } else {
            echo "Error inserting emergency contact: " . $stmt->error . "<br>";
        }

        $stmt->close();
    }
} else {
    echo "No emergency contacts found.<br>";
}

$conn->close();
