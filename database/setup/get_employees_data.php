<?php
require __DIR__ . '/../../config/connection.php';

$csvFile = __DIR__ . '/../seeds/employees.csv';
if (($handle = fopen($csvFile, "r")) !== FALSE) {
    fgetcsv($handle);

    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        $id = $data[0];
        $name = $data[1];
        $position = $data[2];
        $department = $data[3];
        $salary = $data[4];
        $email = $data[5];
        $dob = $data[6];
        $office = $data[7];
        $home_address = $data[8];
        $hired_date = $data[9];
        $contract = $data[10];
        $nin = $data[11];
        $emergency_name = $data[12];
        $emergency_relationship = $data[13];
        $emergency_phone = $data[14];

        $sql = "INSERT INTO materials (
            id, name, position, department, salary, email, dob, office, home_address, hired_date, contract, nin, emergency_name, emergency_relationship, emergency_phone
        ) VALUES (
            ?, ?, ?, ?, ?, ?, STR_TO_DATE(?, '%d/%m/%Y'), ?, ?, STR_TO_DATE(?, '%d/%m/%Y'), ?, ?, ?, ?, ?
        )";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "issssssssssssss",
            $id,
            $name,
            $position,
            $department,
            $salary,
            $email,
            $dob,
            $office,
            $home_address,
            $hired_date,
            $contract,
            $nin,
            $emergency_name,
            $emergency_relationship,
            $emergency_phone
        );

        $stmt->execute();
    }

    fclose($handle);
} else {
    echo "Can`t open a CSV file.";
}

$conn->close();
