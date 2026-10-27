<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();
$message = $_SESSION['employee_delete_message'] ?? '';
$messageType = 'success';
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
        $messageType = 'error';
    }
}
ui_page_start('Delete employee', 'Remove an employee record and retain an audit of the deletion.', ['current' => $current]);
?>
<?php if ($message !== '') ui_notice($message, $messageType); ?>
<section class="panel" aria-labelledby="before-deletion-title">
    <div class="panel-header"><div><p class="eyebrow">Employee administration</p><h2 id="before-deletion-title">Review before deleting</h2></div><span class="badge badge-danger">Permanent action</span></div>
    <p>Deleting an employee removes their active record and account access. Confirm the employee ID before you continue.</p>
    <p class="muted">The deletion log records the employee, your administrator account, the time, and the reason you provide.</p>
    <div class="form-actions"><a class="button button-secondary" href="list_employees.php">Find employee ID</a><a class="button button-quiet" href="delete_records.php">View deletion history</a></div>
</section>
<section class="panel" aria-labelledby="delete-title">
    <div class="panel-header"><div><h2 id="delete-title">Employee to remove</h2><p class="muted">Fields marked * are required.</p></div></div>
    <form method="POST" action="" data-confirm="Permanently delete this employee and remove their account access? Check the employee ID before continuing.">
        <?php echo csrf_field(); ?>
        <div class="form-grid">
            <div class="field"><label for="employee_id">Employee ID *</label><input type="number" id="employee_id" name="employee_id" min="1" max="2147483647" step="1" placeholder="Enter the exact employee ID" required></div>
            <div class="field"><label for="deleted_reason">Reason for deletion</label><input type="text" id="deleted_reason" name="deleted_reason" maxlength="255" placeholder="e.g. Employment ended" aria-describedby="reason-hint"><small id="reason-hint" class="muted">Optional. Up to 255 characters, saved in the deletion log.</small></div>
        </div>
        <div class="form-actions"><button type="submit" class="button button-danger">Delete employee</button><a href="admin_main.php" class="button button-secondary">Cancel</a></div>
    </form>
</section>
<?php ui_page_end(); ?>
