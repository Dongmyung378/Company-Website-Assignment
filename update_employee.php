<?php
require __DIR__ . '/config/connection.php';
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

$employee_data = null;
$message = '';

$departments = $conn->query("SELECT department_id, department_name FROM department ORDER BY department_name ASC");
$positions = $conn->query("SELECT position_id, position_name FROM position ORDER BY position_name ASC");
$offices = $conn->query("SELECT office_id, office_name FROM office ORDER BY office_name ASC");

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_employee'])) {
    $employee_id = $_POST['employee_id'];

    $sql = "
        SELECT e.*, d.department_name, o.office_name, p.position_name, ec.emergency_name, ec.emergency_relationship, ec.emergency_phone
        FROM employees e
        JOIN department d ON e.department_id = d.department_id
        JOIN office o ON e.office_id = o.office_id
        JOIN position p ON e.position_id = p.position_id
        LEFT JOIN emergency_table ec ON e.employee_id = ec.employee_id
        WHERE e.employee_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $employee_data = $result->fetch_assoc();
        $_SESSION['employee_data'] = $employee_data;
    } else {
        $message = "Employee not found.";
    }
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_employee'])) {
    $employee_data = $_SESSION['employee_data'] ?? null;

    if ($employee_data === null) {
        $message = "Error: Employee data is not available. Please search for the employee again.";
    } else {
        $employee_id = $_POST['employee_id'];
        $name = $_POST['name'];
        $salary_increase = floatval($_POST['salary_increase']);
        $home_address = $_POST['home_address'];
        $contract = $_POST['contract'];
        $department_id = intval($_POST['department_id']);
        $position_id = intval($_POST['position_id']);
        $office_id = intval($_POST['office_id']);
        $emergency_name = $_POST['emergency_name'] ?? '';
        $emergency_relationship = $_POST['emergency_relationship'] ?? '';
        $emergency_phone = $_POST['emergency_phone'] ?? '';

        $sql = "SELECT COUNT(*) FROM position WHERE position_id = ? AND department_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $position_id, $department_id);
        $stmt->execute();
        $stmt->bind_result($valid);
        $stmt->fetch();
        $stmt->close();

        if ($valid == 0) {
            $message = "Selected position does not match.";
        } else {
            $current_salary = (float)$employee_data['salary'];
            $new_salary = $current_salary * (1 + ($salary_increase / 100));
            $new_salary_string = (string) round($new_salary);

            $sql = "
                UPDATE employees
                SET name = ?, salary = ?, home_address = ?, contract = ?, department_id = ?, position_id = ?, office_id = ?
                WHERE employee_id = ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssiiii", $name, $new_salary_string, $home_address, $contract, $department_id, $position_id, $office_id, $employee_id);
            $stmt->execute();

            $sql = "
                INSERT INTO emergency_table (employee_id, emergency_name, emergency_relationship, emergency_phone)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                emergency_name = VALUES(emergency_name),
                emergency_relationship = VALUES(emergency_relationship),
                emergency_phone = VALUES(emergency_phone)
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("isss", $employee_id, $emergency_name, $emergency_relationship, $emergency_phone);
            $stmt->execute();

            echo "<script>alert('Employee information updated successfully.'); window.location.href = 'admin_main.php';</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="stylesheet" href="assets/css/update-employee.css">
</head>

<body>
    <div class="container">
        <button class="btn-back" onclick="window.location.href='admin_main.php'">Back to Main</button>
        <h2>Update Employee Information</h2>

        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="search-form">
            <div class="form-group">
                <label for="employee_id">Enter Employee ID:</label>
                <input type="text" name="employee_id" placeholder="e.g., 12345678" required>
                <button type="submit" name="search_employee" class="btn-search">Search</button>
            </div>
        </form>

        <?php if ($employee_data): ?>
            <form method="POST" action="" class="update-form">
                <input type="hidden" name="employee_id" value="<?php echo $employee_data['employee_id']; ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($employee_data['name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Salary Increase (%):</label>
                        <input type="number" step="0.01" name="salary_increase" placeholder="Enter increase percentage">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Home Address:</label>
                        <input type="text" name="home_address" value="<?php echo htmlspecialchars($employee_data['home_address']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Contract Type:</label>
                        <select name="contract" required>
                            <option value="Full-Time" <?php if ($employee_data['contract'] == 'Full-Time') echo 'selected'; ?>>Full-Time</option>
                            <option value="Part-Time" <?php if ($employee_data['contract'] == 'Part-Time') echo 'selected'; ?>>Part-Time</option>
                            <option value="Freelance" <?php if ($employee_data['contract'] == 'Freelance') echo 'selected'; ?>>Freelance</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Department:</label>
                        <select name="department_id" required>
                            <?php while ($row = $departments->fetch_assoc()): ?>
                                <option value="<?php echo $row['department_id']; ?>" <?php if ($row['department_id'] == $employee_data['department_id']) echo 'selected'; ?>><?php echo htmlspecialchars($row['department_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Position:</label>
                        <select name="position_id" required>
                            <?php while ($row = $positions->fetch_assoc()): ?>
                                <option value="<?php echo $row['position_id']; ?>" <?php if ($row['position_id'] == $employee_data['position_id']) echo 'selected'; ?>><?php echo htmlspecialchars($row['position_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Office:</label>
                        <select name="office_id" required>
                            <?php while ($row = $offices->fetch_assoc()): ?>
                                <option value="<?php echo $row['office_id']; ?>" <?php if ($row['office_id'] == $employee_data['office_id']) echo 'selected'; ?>><?php echo htmlspecialchars($row['office_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <h3>Emergency Contact Information</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label>Emergency Contact Name:</label>
                        <input type="text" name="emergency_name" value="<?php echo htmlspecialchars($employee_data['emergency_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Relationship:</label>
                        <input type="text" name="emergency_relationship" value="<?php echo htmlspecialchars($employee_data['emergency_relationship'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="text" name="emergency_phone" value="<?php echo htmlspecialchars($employee_data['emergency_phone'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" name="update_employee" class="btn-submit">Update Employee</button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>
