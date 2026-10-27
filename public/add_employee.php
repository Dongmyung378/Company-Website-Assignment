<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
$current = require_admin();
$message = '';
$created = $_SESSION['employee_created'] ?? null;
unset($_SESSION['employee_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        $fields = business_employee_fields($_POST, true);
        $email = str_replace(' ', '.', $fields['name']) . '@kilburnazon.com';
        if (preg_match_all('/./us', $email) > 100) {
            throw new WorkflowException('Full name is too long to generate a company email address.');
        }
        $conn->begin_transaction();
        $transaction = true;
        business_check_assignment($conn, $fields['position_id'], $fields['department_id'], $fields['office_id']);
        $stmt = $conn->prepare('INSERT INTO employees (name, salary, email, dob, home_address, hired_date, contract, nin, department_id, office_id, position_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssssiii', $fields['name'], $fields['salary'], $email, $fields['dob'], $fields['home_address'], $fields['hired_date'], $fields['contract'], $fields['nin'], $fields['department_id'], $fields['office_id'], $fields['position_id']);
        $stmt->execute();
        $employeeId = (int) $conn->insert_id;
        $stmt->close();
        business_save_contact($conn, $employeeId, $fields);
        $temporaryPassword = provision_temporary_password($conn, $employeeId);
        $conn->commit();
        $transaction = false;
        $_SESSION['employee_created'] = ['employee_id' => $employeeId, 'temporary_password' => $temporaryPassword];
        header('Location: add_employee.php');
        exit();
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to add the employee.');
    }
}

$departments = $conn->query('SELECT department_id, department_name FROM department ORDER BY department_name ASC');
$positions = $conn->query('SELECT position_id, position_name FROM position ORDER BY position_name ASC');
$offices = $conn->query('SELECT office_id, office_name FROM office ORDER BY office_name ASC');
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
        <?php if ($message): ?>
            <p role="alert"><?php echo e($message); ?></p>
        <?php endif; ?>
        <?php if (is_array($created)): ?>
            <p>Employee added successfully. Employee ID: <strong><?php echo e($created['employee_id']); ?></strong></p>
            <p>Temporary password (shown once): <code><?php echo e($created['temporary_password']); ?></code></p>
            <p>The employee must change this password when signing in.</p>
        <?php endif; ?>
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Full Name:</label>
                    <input type="text" name="name" maxlength="100" placeholder="Enter full name" required>
                </div>
                <div class="form-group">
                    <label for="dob">Date of Birth:</label>
                    <input type="date" name="dob" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="salary">Salary (£):</label>
                    <input type="text" name="salary" maxlength="15" placeholder="Enter salary" required>
                </div>
                <div class="form-group">
                    <label for="hired_date">Hired Date:</label>
                    <input type="date" name="hired_date" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="home_address">Home Address:</label>
                    <input type="text" name="home_address" maxlength="255" placeholder="Enter home address" required>
                </div>
                <div class="form-group">
                    <label for="nin">NIN:</label>
                    <input type="text" name="nin" maxlength="50" placeholder="Enter NIN">
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
                            <option value="<?php echo $row['department_id']; ?>"><?php echo e($row['department_name']); ?></option>
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
                            <option value="<?php echo $row['position_id']; ?>"><?php echo e($row['position_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="office_id">Office:</label>
                    <select name="office_id" required>
                        <option value="">Select Office</option>
                        <?php while ($row = $offices->fetch_assoc()): ?>
                            <option value="<?php echo $row['office_id']; ?>"><?php echo e($row['office_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>

            <h3>Emergency Contact Information</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="emergency_name">Emergency Contact Name:</label>
                    <input type="text" name="emergency_name" maxlength="100" placeholder="Enter emergency contact name">
                </div>
                <div class="form-group">
                    <label for="emergency_relationship">Relationship:</label>
                    <input type="text" name="emergency_relationship" maxlength="50" placeholder="Enter relationship">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="emergency_phone">Phone Number:</label>
                    <input type="text" name="emergency_phone" maxlength="50" placeholder="Enter phone number">
                </div>
            </div>

            <button type="submit">Add Employee</button>
        </form>
    </div>
</body>

</html>
