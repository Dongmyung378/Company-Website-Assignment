<?php
require_once __DIR__ . '/../config/bootstrap.php';
$current = require_admin();

$employee_id_text = input_string($_GET, 'id');
$employee_id = ctype_digit($employee_id_text) && strlen($employee_id_text) <= 10 ? (int) $employee_id_text : 0;

if ($employee_id <= 0 || $employee_id > 2147483647) {
    http_response_code(422);
    die("Invalid employee ID.");
}

$sql = "
    SELECT 
        e.employee_id,
        e.name,
        e.salary,
        e.email,
        e.dob,
        e.home_address,
        e.hired_date,
        e.contract,
        e.nin,
        d.department_name,
        o.office_name,
        p.position_name,
        ec.emergency_name,
        ec.emergency_relationship,
        ec.emergency_phone
    FROM 
        employees e
    JOIN 
        department d ON e.department_id = d.department_id
    JOIN 
        office o ON e.office_id = o.office_id
    JOIN
        position p ON p.position_id = e.position_id
    LEFT JOIN 
        emergency_table ec ON e.employee_id = ec.employee_id
    WHERE 
        e.employee_id = ?
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $employee = $result->fetch_assoc();
    } else {
        http_response_code(404);
        exit("No employee information found for that ID.");
    }
} else {
    throw new RuntimeException('Unable to prepare the employee details.');
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($employee['name']); ?> - Employee profile</title>
    <link rel="stylesheet" href="assets/css/employee-detail.css">
</head>

<body>
    <div class="detail-container">
        <div class="detail-header">
            <img src="assets/images/default-avatar.jpg" alt="Photo: <?php echo e($employee['name']); ?>">
            <h1><?php echo e($employee['name']); ?></h1>
        </div>
        <div class="detail-info">
            <h2>Personal Details</h2>
            <p><strong>ID:</strong> <?php echo e($employee['employee_id']); ?></p>
            <p><strong>Name:</strong> <?php echo e($employee['name']); ?></p>
            <p><strong>Department:</strong> <?php echo e($employee['department_name']); ?></p>
            <p><strong>Position:</strong> <?php echo e($employee['position_name']); ?></p>
            <p><strong>Salary:</strong> £ <?php echo e($employee['salary']); ?></p>
            <p><strong>Email:</strong> <?php echo e($employee['email']); ?></p>
            <p><strong>Date of Birth (DOB):</strong> <?php echo e(date("Y-m-d", strtotime($employee['dob']))); ?></p>
            <p><strong>Office:</strong> <?php echo e($employee['office_name']); ?></p>
            <p><strong>Home Address:</strong> <?php echo e($employee['home_address']); ?></p>
            <p><strong>Hired Date:</strong> <?php echo e($employee['hired_date']); ?></p>
            <p><strong>Contract:</strong> <?php echo e($employee['contract']); ?></p>
            <p><strong>NIN:</strong> <?php echo e($employee['nin']); ?></p>
            <h3>Emergency Contact</h3>
            <p><strong>Contact Name:</strong> <?php echo e($employee['emergency_name']); ?></p>
            <p><strong>Relationship:</strong> <?php echo e($employee['emergency_relationship']); ?></p>
            <p><strong>Phone Number:</strong> <?php echo e($employee['emergency_phone']); ?></p>
            <a href="list_employees.php" class="back-button">Back</a>
        </div>
    </div>
</body>

</html>
