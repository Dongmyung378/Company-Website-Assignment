<?php
require __DIR__ . '/../config/connection.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// check this employee`s department is executive
if ($_SESSION['department'] !== 2) {
    header("Location: main.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $name_for_email = str_replace(' ', '.', $name);
    $email = $name_for_email . "@kilburnazon.com";
    $salary = $_POST['salary'];
    $dob = $_POST['dob'];
    $home_address = $_POST['home_address'];
    $hired_date = $_POST['hired_date'];
    $contract = $_POST['contract'];
    $nin = $_POST['nin'];
    $department_id = intval($_POST['department_id']);
    $office_id = intval($_POST['office_id']);
    $position_id = intval($_POST['position_id']);
    $emergency_name = isset($_POST['emergency_name']) && !empty(trim($_POST['emergency_name'])) ? $_POST['emergency_name'] : "";
    $emergency_relationship = isset($_POST['emergency_relationship']) && !empty(trim($_POST['emergency_relationship'])) ? $_POST['emergency_relationship'] : "";
    $emergency_phone = isset($_POST['emergency_phone']) && !empty(trim($_POST['emergency_phone'])) ? $_POST['emergency_phone'] : "";

    $checkPositionSQL = "
        SELECT COUNT(*) FROM position
        WHERE position_id = ? AND department_id = ?
    ";
    $stmt = $conn->prepare($checkPositionSQL);
    $stmt->bind_param("ii", $position_id, $department_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    if ($count == 0) {
        echo "<script>
        alert('Error: Please select a valid position.');
        window.history.back();
        </script>";
        exit();
    }

    $getMaxIDSQL = "
        SELECT MAX(employee_id)
        FROM employees
        WHERE position_id = ?
    ";
    $stmt = $conn->prepare($getMaxIDSQL);
    $stmt->bind_param("i", $position_id);
    $stmt->execute();
    $stmt->bind_result($max_id);
    $stmt->fetch();
    $stmt->close();

    $employee_id = ($max_id === null) ? str_pad($position_id * 100000, 8, '0', STR_PAD_LEFT) : str_pad($max_id + 1, 8, '1', STR_PAD_LEFT);

    $insertEmployeeSQL = "
        INSERT INTO employees (
            employee_id, name, salary, email, dob, home_address, hired_date, contract, nin, department_id, office_id, position_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt1 = $conn->prepare($insertEmployeeSQL);
    $stmt1->bind_param(
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

    if ($stmt1->execute()) {
        $insertEmergencyContactSQL = "
            INSERT INTO emergency_table (
                employee_id, emergency_name, emergency_relationship, emergency_phone
            ) VALUES (?, ?, ?, ?)
        ";
        $stmt2 = $conn->prepare($insertEmergencyContactSQL);
        $stmt2->bind_param("isss", $employee_id, $emergency_name, $emergency_relationship, $emergency_phone);

        if ($stmt2->execute()) {
            echo "<script>
                alert('Employee successfully added.');
                window.location.href = 'admin_main.php';
            </script>";
        } else {
            echo "<script>
                alert('Error Please check the database.');
                window.location.href = 'admin_main.php';
            </script>";
        }
        $stmt2->close();

        echo "<script>
            alert('Employee successfully added.');
            window.location.href = 'admin_main.php';
        </script>";
    } else {
        echo "<script>
            alert('Error adding employee. Please try again.');
            window.location.href = 'admin_main.php';
        </script>";
    }

    $stmt1->close();
}

$departments = $conn->query("SELECT department_id, department_name FROM department ORDER BY department_name ASC");
$positions = $conn->query("SELECT position_id, position_name FROM position ORDER BY position_name ASC");
$offices = $conn->query("SELECT office_id, office_name FROM office ORDER BY office_name ASC");

$conn->close();
?>
<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="assets/css/add-employee.css">
</head>

<body>
    <div class="form-container">
        <div class="back-button-container">
            <a href="admin_main.php" class="back-button">Back to Main</a>
        </div>
        <h2>Add New Employee</h2>
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Full Name:</label>
                    <input type="text" name="name" placeholder="Enter full name" required>
                </div>
                <div class="form-group">
                    <label for="dob">Date of Birth:</label>
                    <input type="date" name="dob" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="salary">Salary (£):</label>
                    <input type="text" name="salary" placeholder="Enter salary" required>
                </div>
                <div class="form-group">
                    <label for="hired_date">Hired Date:</label>
                    <input type="date" name="hired_date" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="home_address">Home Address:</label>
                    <input type="text" name="home_address" placeholder="Enter home address" required>
                </div>
                <div class="form-group">
                    <label for="nin">NIN:</label>
                    <input type="text" name="nin" placeholder="Enter NIN">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="contract">Contract Type:</label>
                    <select name="contract" required>
                        <option value="Full-Time">Full-Time</option>
                        <option value="Part-Time">Part-Time</option>
                        <option value="Freelance">Freelance</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="department_id">Department:</label>
                    <select name="department_id" required>
                        <option value="">Select Department</option>
                        <?php while ($row = $departments->fetch_assoc()): ?>
                            <option value="<?php echo $row['department_id']; ?>"><?php echo htmlspecialchars($row['department_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="position_id">Position:</label>
                    <select name="position_id" required>
                        <option value="">Select Position</option>
                        <?php while ($row = $positions->fetch_assoc()): ?>
                            <option value="<?php echo $row['position_id']; ?>"><?php echo htmlspecialchars($row['position_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="office_id">Office:</label>
                    <select name="office_id" required>
                        <option value="">Select Office</option>
                        <?php while ($row = $offices->fetch_assoc()): ?>
                            <option value="<?php echo $row['office_id']; ?>"><?php echo htmlspecialchars($row['office_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <h3>Emergency Contact Information</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="emergency_name">Emergency Contact Name:</label>
                    <input type="text" name="emergency_name" placeholder="Enter emergency contact name">
                </div>
                <div class="form-group">
                    <label for="emergency_relationship">Relationship:</label>
                    <input type="text" name="emergency_relationship" placeholder="Enter relationship">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="emergency_phone">Phone Number:</label>
                    <input type="text" name="emergency_phone" placeholder="Enter phone number">
                </div>
            </div>

            <button type="submit">Add Employee</button>
        </form>
    </div>
</body>

</html>
