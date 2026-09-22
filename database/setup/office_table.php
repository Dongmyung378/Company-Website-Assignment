<?php
require __DIR__ . '/../../config/connection.php';

$createOfficeTable = "
    CREATE TABLE IF NOT EXISTS office (
        office_id INT AUTO_INCREMENT PRIMARY KEY,
        office_name VARCHAR(100) NOT NULL UNIQUE
    );
";

if ($conn->query($createOfficeTable) === TRUE) {
    echo "Table created successfully.<br>";
} else {
    die("Error creating table: " . $conn->error);
}

$sql = "SELECT DISTINCT office FROM materials";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $office_name = $row['office'];

        $insertOfficeSQL = "INSERT IGNORE INTO office (office_name) VALUES (?)";
        $stmt = $conn->prepare($insertOfficeSQL);
        $stmt->bind_param("s", $office_name);

        if ($stmt->execute()) {
            echo "Inserted office: " . htmlspecialchars($office_name) . "<br>";
        } else {
            echo "Error inserting office: " . $stmt->error . "<br>";
        }

        $stmt->close();
    }
} else {
    echo "No office information found.<br>";
}

$conn->close();
