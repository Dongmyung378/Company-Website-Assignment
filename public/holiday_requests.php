<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_login();
$employee_id = (int) $current['employee_id'];
$employee_data = $current;
$backUrl = $current['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php';
$leaveColumns = ['Annual' => 'annual_leave', 'Sick' => 'sick_leave', 'Personal' => 'personal_leave'];
$message = $_SESSION['holiday_submission_message'] ?? '';
$messageType = 'success';
unset($_SESSION['holiday_submission_message'], $_SESSION['holiday_balance']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        if (array_key_exists('employee_id', $_POST) && business_id($_POST, 'employee_id') !== $employee_id) {
            throw new WorkflowException('You can request leave only for your own account.', 403);
        }
        $leave_type = input_string($_POST, 'leave_type');
        if (!isset($leaveColumns[$leave_type])) {
            throw new WorkflowException('Select a valid leave type.');
        }
        $start_date = business_date($_POST, 'start_date', 'Start date');
        $end_date = business_date($_POST, 'end_date', 'End date');
        if ($end_date < $start_date) {
            throw new WorkflowException('End date cannot be earlier than start date.');
        }
        $days = calendar_days($start_date, $end_date);
        $reason = business_text($_POST, 'reason', 'Reason', 65535, false);
        if (strlen($reason) > 65535) {
            throw new WorkflowException('Reason must be at most 65535 bytes.');
        }
        $conn->begin_transaction();
        $transaction = true;
        $stmt = $conn->prepare('SELECT annual_leave, sick_leave, personal_leave FROM holiday_balance WHERE employee_id = ? FOR UPDATE');
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $balance = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$balance || (int) $balance[$leaveColumns[$leave_type]] < $days) {
            throw new WorkflowException('Insufficient leave balance for the requested dates.');
        }
        $stmt = $conn->prepare("INSERT INTO holiday_requests (employee_id, leave_type, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->bind_param('issss', $employee_id, $leave_type, $start_date, $end_date, $reason);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $transaction = false;
        $_SESSION['holiday_submission_message'] = 'Holiday request submitted successfully. Waiting for approval.';
        header('Location: holiday_requests.php');
        exit();
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to submit the holiday request.');
        $messageType = 'error';
    }
}
$stmt = $conn->prepare('SELECT annual_leave, sick_leave, personal_leave FROM holiday_balance WHERE employee_id = ?');
$stmt->bind_param('i', $employee_id);
$stmt->execute();
$holiday_balance = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$holiday_balance) {
    $message = 'Your leave balance is unavailable. Please contact the administrator.';
    $messageType = 'error';
}
$formValue = static function (string $key, string $default = ''): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
};
ui_page_start('Leave request', 'Plan your time away and send a request for approval.', ['current' => $current]);
?>
<?php if ($message !== '') ui_notice($message, $messageType); ?>
<?php if ($employee_data && $holiday_balance): ?>
    <div class="stat-grid">
        <?php foreach ($leaveColumns as $label => $column): ?>
            <section class="stat-card"><p class="stat-label"><?php echo e(ui_t($label . ' leave')); ?></p><p class="stat-value"><?php echo e($holiday_balance[$column]); ?><small> <?php echo e(ui_t('days')); ?></small></p><p class="stat-note"><?php echo e(ui_t('Available balance')); ?></p></section>
        <?php endforeach; ?>
    </div>
    <section class="panel" aria-labelledby="request-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('For {name}', ['name' => $employee_data['name']])); ?></p><h2 id="request-title"><?php echo e(ui_t('Request details')); ?></h2><p class="muted"><?php echo e(ui_t('Fields marked * are required. Your request is sent to an administrator for review.')); ?></p></div><a class="button button-secondary" href="check_my_holiday.php"><?php echo e(ui_t('View my requests')); ?></a></div>
        <form method="POST" action="" data-leave-form>
            <?php echo csrf_field(); ?>
            <div class="form-grid">
                <div class="field field-full"><label for="leave_type"><?php echo e(ui_t('Leave type *')); ?></label><select id="leave_type" name="leave_type" required><?php foreach ($leaveColumns as $label => $column): ?><option value="<?php echo e($label); ?>" data-balance="<?php echo e($holiday_balance[$column]); ?>" <?php echo $formValue('leave_type', 'Annual') === $label ? 'selected' : ''; ?>><?php echo e(ui_t('{type} ({count} days available)', ['type' => ui_t($label . ' leave'), 'count' => $holiday_balance[$column]])); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="start_date"><?php echo e(ui_t('Start date *')); ?></label><input type="date" id="start_date" name="start_date" min="1000-01-01" value="<?php echo e($formValue('start_date')); ?>" aria-describedby="date-hint" required></div>
                <div class="field"><label for="end_date"><?php echo e(ui_t('End date *')); ?></label><input type="date" id="end_date" name="end_date" min="1000-01-01" value="<?php echo e($formValue('end_date')); ?>" aria-describedby="date-hint" required></div>
                <div class="field field-full"><p id="date-hint" class="muted"><?php echo e(ui_t('Leave is counted in calendar days, including both dates and weekends. A request for the same start and end date uses 1 day.')); ?></p><output data-leave-preview aria-live="polite"><?php echo e(ui_t('Select your dates to preview the number of days.')); ?></output></div>
                <div class="field field-full"><label for="reason"><?php echo e(ui_t('Reason for leave')); ?></label><textarea id="reason" name="reason" rows="4" maxlength="16384" placeholder="<?php echo e(ui_t('Add any context that will help your administrator.')); ?>" aria-describedby="reason-hint"><?php echo e($formValue('reason')); ?></textarea><small id="reason-hint" class="muted"><?php echo e(ui_t('Optional. Keep your note brief.')); ?></small></div>
            </div>
            <div class="form-actions"><button type="submit" name="request_holiday" class="button"><?php echo e(ui_t('Submit request')); ?></button><a href="<?php echo e($backUrl); ?>" class="button button-secondary"><?php echo e(ui_t('Cancel')); ?></a></div>
            <p class="muted"><?php echo e(ui_t('Pending requests do not reduce your balance. The available balance is checked again when a request is approved.')); ?></p>
        </form>
    </section>
<?php else: ?>
    <section class="panel empty-state"><h2><?php echo e(ui_t('Leave balance unavailable')); ?></h2><p class="muted"><?php echo e(ui_t('An administrator needs to check your leave balance before you can submit a request.')); ?></p><a class="button button-secondary" href="<?php echo e($backUrl); ?>"><?php echo e(ui_t('Back to overview')); ?></a></section>
<?php endif; ?>
<?php ui_page_end(); ?>
