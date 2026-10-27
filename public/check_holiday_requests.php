<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();
$message = $_SESSION['holiday_decision_message'] ?? '';
$messageType = 'success';
unset($_SESSION['holiday_decision_message']);
$leaveColumns = ['Annual' => 'annual_leave', 'Sick' => 'sick_leave', 'Personal' => 'personal_leave'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        $requestId = business_id($_POST, 'request_id', 'Request ID');
        $decision = input_string($_POST, 'status');
        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new WorkflowException('Select Approve or Reject.');
        }
        $conn->begin_transaction();
        $transaction = true;
        $stmt = $conn->prepare('SELECT employee_id, leave_type, start_date, end_date, status FROM holiday_requests WHERE request_id = ? FOR UPDATE');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$request) {
            throw new WorkflowException('Holiday request not found.', 404);
        }
        if ($request['status'] !== 'Pending') {
            throw new WorkflowException('This request has already been processed. Refresh the list.', 409);
        }
        if ($decision === 'Approved') {
            $column = $leaveColumns[$request['leave_type']] ?? null;
            if (!$column || !valid_date($request['start_date']) || !valid_date($request['end_date'])
                || $request['start_date'] < '1000-01-01' || $request['end_date'] < $request['start_date']) {
                throw new WorkflowException('This request contains invalid leave data and cannot be approved.', 409);
            }
            $days = calendar_days($request['start_date'], $request['end_date']);
            $employeeId = (int) $request['employee_id'];
            // Only fixed column names from the allowlist above enter SQL.
            $stmt = $conn->prepare("UPDATE holiday_balance SET $column = $column - ? WHERE employee_id = ? AND $column >= ?");
            $stmt->bind_param('iii', $days, $employeeId, $days);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new WorkflowException('The employee has insufficient leave balance. The request remains pending.', 409);
            }
            $stmt->close();
        }
        $stmt = $conn->prepare("UPDATE holiday_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND status = 'Pending'");
        $stmt->bind_param('si', $decision, $requestId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new WorkflowException('This request was processed by another administrator.', 409);
        }
        $stmt->close();
        $conn->commit();
        $transaction = false;
        $_SESSION['holiday_decision_message'] = 'Holiday request updated successfully.';
        header('Location: check_holiday_requests.php');
        exit();
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to update the holiday request.');
        $messageType = 'error';
    }
}
$result = $conn->query("SELECT hr.request_id, hr.employee_id, e.name, hr.leave_type, hr.start_date, hr.end_date, hr.reason, hr.status FROM holiday_requests hr JOIN employees e ON hr.employee_id = e.employee_id WHERE hr.status = 'Pending' ORDER BY hr.start_date, hr.request_id");
ui_page_start('Leave approvals', 'Review pending requests and help the team plan their time away.', ['current' => $current]);
?>
<?php if ($message !== '') ui_notice($message, $messageType); ?>
<div class="stat-grid"><section class="stat-card"><p class="stat-label"><?php echo e(ui_t('Awaiting review')); ?></p><p class="stat-value"><?php echo e($result ? $result->num_rows : 0); ?></p><p class="stat-note"><?php echo e(ui_t('Pending leave requests')); ?></p></section><section class="stat-card"><p class="stat-label"><?php echo e(ui_t('How days are counted')); ?></p><p class="stat-value"><?php echo e(ui_t('Calendar days')); ?></p><p class="stat-note"><?php echo e(ui_t('Both dates and weekends are included')); ?></p></section></div>
<section class="panel" aria-labelledby="pending-title">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Leave management')); ?></p><h2 id="pending-title"><?php echo e(ui_t('Pending requests')); ?></h2><p class="muted"><?php echo e(ui_t('Each request can be processed once. Approval deducts days from the employee\'s available balance. A decision cannot be changed here.')); ?></p></div><span class="badge badge-warning"><?php echo e(ui_t('Needs review')); ?></span></div>
    <?php if ($result && $result->num_rows > 0): ?>
        <div class="table-scroll"><table class="data-table">
            <thead><tr><th scope="col"><?php echo e(ui_t('Employee')); ?></th><th scope="col"><?php echo e(ui_t('Leave type')); ?></th><th scope="col"><?php echo e(ui_t('Dates')); ?></th><th scope="col"><?php echo e(ui_t('Days')); ?></th><th scope="col"><?php echo e(ui_t('Reason')); ?></th><th scope="col"><?php echo e(ui_t('Status')); ?></th><th scope="col"><?php echo e(ui_t('Decision')); ?></th></tr></thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <?php
                    $request_id = $row['request_id'];
                    $employee_id = $row['employee_id'];
                    $leave_type = $row['leave_type'];
                    $validRequestDates = valid_date($row['start_date']) && valid_date($row['end_date']) && $row['end_date'] >= $row['start_date'];
                    ?>
                    <tr>
                        <td><a href="employee_detail.php?id=<?php echo e($employee_id); ?>"><strong><?php echo e($row['name']); ?></strong></a><br><span class="muted"><?php echo e(ui_t('Employee #{id}', ['id' => $employee_id])); ?></span></td>
                        <td><?php echo e(ui_t($leave_type)); ?><br><span class="muted"><?php echo e(ui_t('Request #{id}', ['id' => $request_id])); ?></span></td>
                        <td><time datetime="<?php echo e($row['start_date']); ?>"><?php echo e($row['start_date']); ?></time><br><span class="muted"><?php echo e(ui_t('to')); ?></span> <time datetime="<?php echo e($row['end_date']); ?>"><?php echo e($row['end_date']); ?></time></td>
                        <td><?php echo $validRequestDates ? e(calendar_days($row['start_date'], $row['end_date'])) : e(ui_t('Review dates')); ?></td>
                        <td><?php echo e($row['reason'] ?: ui_t('No reason provided')); ?></td>
                        <td><span class="badge badge-warning"><?php echo e(ui_t($row['status'])); ?></span></td>
                        <td><form method="POST" action="" data-confirm="<?php echo e(ui_t('Apply this leave decision? Approval deducts the calendar days from the employee\'s balance, and this decision cannot be changed here.')); ?>">
                            <?php echo csrf_field(); ?><input type="hidden" name="request_id" value="<?php echo e($request_id); ?>">
                            <div class="field"><label for="status-<?php echo e($request_id); ?>"><?php echo e(ui_t('Decision for request #{id}', ['id' => $request_id])); ?></label><select id="status-<?php echo e($request_id); ?>" name="status" required><option value=""><?php echo e(ui_t('Select a decision')); ?></option><option value="Approved"><?php echo e(ui_t('Approve')); ?></option><option value="Rejected"><?php echo e(ui_t('Reject')); ?></option></select></div>
                            <div class="form-actions"><button type="submit" name="update_status" class="button button-secondary"><?php echo e(ui_t('Save decision')); ?></button></div>
                        </form></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table></div>
    <?php else: ?>
        <div class="empty-state"><span class="badge badge-success"><?php echo e(ui_t('Review complete')); ?></span><h3><?php echo e(ui_t('No pending holiday requests')); ?></h3><p class="muted"><?php echo e(ui_t('New requests will appear here when employees submit them.')); ?></p><a class="button button-secondary" href="admin_main.php"><?php echo e(ui_t('Back to overview')); ?></a></div>
    <?php endif; ?>
</section>
<?php ui_page_end(); ?>
