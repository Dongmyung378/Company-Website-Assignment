<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
$current = require_admin();
$message = $_SESSION['employee_delete_message'] ?? '';
unset($_SESSION['employee_delete_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $employeeId = business_id($_POST, 'employee_id');
        $deletedById = (int) $current['employee_id'];
        $deletedByName = $current['name'];
        $reason = business_text($_POST, 'deleted_reason', 'Reason for deletion', 255, false);
        $reason = $reason === '' ? 'No reason provided' : $reason;
        $stmt = $conn->prepare('CALL delete_employee_with_log(?, ?, ?, ?)');
        $stmt->bind_param('iiss', $employeeId, $deletedById, $deletedByName, $reason);
        $stmt->execute();
        $stmt->close();
        while ($conn->more_results() && $conn->next_result()) {
            $extra = $conn->store_result();
            if ($extra instanceof mysqli_result) {
                $extra->free();
            }
        }
        $_SESSION['employee_delete_message'] = 'Employee deleted and log saved successfully.';
        header('Location: delete_employee.php');
        exit();
    } catch (Throwable $error) {
        if ($error instanceof mysqli_sql_exception && $error->getCode() === 1644) {
            $error = new WorkflowException('Employee not found or cannot be deleted.', 404);
        }
        $message = business_report_failure($error, 'Unable to delete the employee.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="stylesheet" href="assets/css/delete-employee.css">
</head>

<body>
    <div class="form-container">
        <a href="admin_main.php" class="back-button">Back to Main</a>
        <a href="delete_records.php" class="enter-button">Check deleted employees</a>
        <h1>Delete Employee</h1>
        <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="employee_id">Employee ID:</label>
                    <input type="number" id="employee_id" name="employee_id" min="1" placeholder="Enter Employee ID" required>
                </div>
                <div class="form-group">
                    <label for="deleted_reason">Reason for Deletion:</label>
                    <input type="text" id="deleted_reason" name="deleted_reason" maxlength="255" placeholder="Enter Reason">
                </div>
            </div>
            <button type="submit">Delete Employee</button>
        </form>
        <?php if ($message): ?>
            <p role="status"><?php echo e($message); ?></p>
        <?php endif; ?>
    </div>
</body>

</html>
