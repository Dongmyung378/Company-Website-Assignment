<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
$current = require_admin();
$employee_data = null;
$message = $_SESSION['employee_update_message'] ?? '';
unset($_SESSION['employee_update_message'], $_SESSION['employee_data']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        $employeeId = business_id($_POST, 'employee_id');
        if (isset($_POST['update_employee'])) {
            $version = business_id($_POST, 'row_version', 'Record version');
            $fields = business_employee_fields($_POST, false);
            $percentage = input_string($_POST, 'salary_increase', '0');
            $percentage = $percentage === '' ? '0' : $percentage;
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $percentage)
                || !is_finite((float) $percentage) || (float) $percentage < -100 || (float) $percentage > 10000) {
                throw new WorkflowException('Salary increase must be a percentage between -100 and 10000, with at most two decimal places.');
            }
            $conn->begin_transaction();
            $transaction = true;
            $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id = ? FOR UPDATE');
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$record) {
                throw new WorkflowException('Employee not found.', 404);
            }
            if ((int) $record['row_version'] !== $version) {
                throw new WorkflowException('This employee was changed after you opened the form. Search again before updating.', 409);
            }
            business_check_assignment($conn, $fields['position_id'], $fields['department_id'], $fields['office_id']);
            $salary = $record['salary'];
            if ((float) $percentage !== 0.0) {
                if (!is_numeric($salary)) {
                    throw new WorkflowException('A salary percentage change requires a numeric current salary.');
                }
                $newSalary = round((float) $salary * (1 + (float) $percentage / 100), 2);
                if (!is_finite($newSalary) || $newSalary < 0 || $newSalary > 999999999999.99) {
                    throw new WorkflowException('The resulting salary is outside the supported range.');
                }
                $salary = number_format($newSalary, 2, '.', '');
            }
            $stmt = $conn->prepare('UPDATE employees SET name = ?, salary = ?, home_address = ?, contract = ?, department_id = ?, position_id = ?, office_id = ?, row_version = row_version + 1 WHERE employee_id = ? AND row_version = ?');
            $stmt->bind_param('ssssiiiii', $fields['name'], $salary, $fields['home_address'], $fields['contract'], $fields['department_id'], $fields['position_id'], $fields['office_id'], $employeeId, $version);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new WorkflowException('The employee changed while updating. Search again.', 409);
            }
            $stmt->close();
            business_save_contact($conn, $employeeId, $fields);
            if ((int) $record['department_id'] !== $fields['department_id']) {
                $stmt = $conn->prepare('UPDATE login SET auth_version = auth_version + 1 WHERE employee_id = ?');
                $stmt->bind_param('i', $employeeId);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('Missing account during role change.');
                }
                $stmt->close();
            }
            $conn->commit();
            $transaction = false;
            $_SESSION['employee_update_message'] = 'Employee information updated successfully.';
            header('Location: update_employee.php');
            exit();
        } elseif (isset($_POST['search_employee'])) {
            $stmt = $conn->prepare('SELECT e.*, ec.emergency_name, ec.emergency_relationship, ec.emergency_phone FROM employees e LEFT JOIN emergency_table ec ON e.employee_id = ec.employee_id WHERE e.employee_id = ?');
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $employee_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$employee_data) {
                throw new WorkflowException('Employee not found.', 404);
            }
        } else {
            throw new WorkflowException('Select a valid employee action.');
        }
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to update the employee.');
    }
}
$departments = $conn->query('SELECT department_id, department_name FROM department ORDER BY department_name ASC');
$positions = $conn->query('SELECT position_id, position_name FROM position ORDER BY position_name ASC');
$offices = $conn->query('SELECT office_id, office_name FROM office ORDER BY office_name ASC');
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
            <div class="message"><?php echo e($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="" class="search-form">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="employee_id">Enter Employee ID:</label>
                <input type="text" name="employee_id" placeholder="e.g., 12345678" required>
                <button type="submit" name="search_employee" class="btn-search">Search</button>
            </div>
        </form>

        <?php if ($employee_data): ?>
            <form method="POST" action="" class="update-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="row_version" value="<?php echo e($employee_data['row_version']); ?>">
                <input type="hidden" name="employee_id" value="<?php echo $employee_data['employee_id']; ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="name" value="<?php echo e($employee_data['name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Salary Increase (%):</label>
                        <input type="number" step="0.01" name="salary_increase" min="-100" max="10000" placeholder="Enter increase percentage">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Home Address:</label>
                        <input type="text" name="home_address" value="<?php echo e($employee_data['home_address']); ?>" required>
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
                                <option value="<?php echo $row['department_id']; ?>" <?php if ($row['department_id'] == $employee_data['department_id']) echo 'selected'; ?>><?php echo e($row['department_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Position:</label>
                        <select name="position_id" required>
                            <?php while ($row = $positions->fetch_assoc()): ?>
                                <option value="<?php echo $row['position_id']; ?>" <?php if ($row['position_id'] == $employee_data['position_id']) echo 'selected'; ?>><?php echo e($row['position_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Office:</label>
                        <select name="office_id" required>
                            <?php while ($row = $offices->fetch_assoc()): ?>
                                <option value="<?php echo $row['office_id']; ?>" <?php if ($row['office_id'] == $employee_data['office_id']) echo 'selected'; ?>><?php echo e($row['office_name']); ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <h3>Emergency Contact Information</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label>Emergency Contact Name:</label>
                        <input type="text" name="emergency_name" value="<?php echo e($employee_data['emergency_name'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Relationship:</label>
                        <input type="text" name="emergency_relationship" value="<?php echo e($employee_data['emergency_relationship'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label>Phone Number:</label>
                        <input type="text" name="emergency_phone" value="<?php echo e($employee_data['emergency_phone'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" name="update_employee" class="btn-submit">Update Employee</button>
            </form>
        <?php endif; ?>
    </div>
</body>

</html>
