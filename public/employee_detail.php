<?php
require __DIR__ . '/../config/connection.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['department'] !== 2) {
    header("Location: main.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$employee_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($employee_id <= 0) {
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
        die("No employee information found for that ID.");
    }
} else {
    die("Failed to prepare the statement: " . $conn->error);
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($employee['name']); ?> · Employee profile</title>
    <link rel="stylesheet" href="assets/css/employee-detail.css">
</head>

<body>
    <div class="detail-container">
        <div class="detail-header">
            <img src="assets/images/default-avatar.jpg" alt="Photo: <?php echo htmlspecialchars($employee['name']); ?>">
            <h1><?php echo htmlspecialchars($employee['name']); ?></h1>
        </div>
        <div class="detail-info">
            <h2>Personal Details</h2>
            <p><strong>ID:</strong> <?php echo htmlspecialchars($employee['employee_id']); ?></p>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($employee['name']); ?></p>
            <p><strong>Department:</strong> <?php echo htmlspecialchars($employee['department_name']); ?></p>
            <p><strong>Position:</strong> <?php echo htmlspecialchars($employee['position_name']); ?></p>
            <p><strong>Salary:</strong> £ <?php echo htmlspecialchars($employee['salary']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($employee['email']); ?></p>
            <p><strong>Date of Birth (DOB):</strong> <?php echo htmlspecialchars(date("Y-m-d", strtotime($employee['dob']))); ?></p>
            <p><strong>Office:</strong> <?php echo htmlspecialchars($employee['office_name']); ?></p>
            <p><strong>Home Address:</strong> <?php echo htmlspecialchars($employee['home_address']); ?></p>
            <p><strong>Hired Date:</strong> <?php echo htmlspecialchars($employee['hired_date']); ?></p>
            <p><strong>Contract:</strong> <?php echo htmlspecialchars($employee['contract']); ?></p>
            <p><strong>NIN:</strong> <?php echo htmlspecialchars($employee['nin']); ?></p>
            <h3>Emergency Contact</h3>
            <p><strong>Contact Name:</strong> <?php echo htmlspecialchars($employee['emergency_name']); ?></p>
            <p><strong>Relationship:</strong> <?php echo htmlspecialchars($employee['emergency_relationship']); ?></p>
            <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($employee['emergency_phone']); ?></p>
            <a href="list_employees.php" class="back-button">Back</a>
        </div>
    </div>
</body>

</html>
