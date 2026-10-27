<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_login();
if ($current['department_name'] === 'Executive') {
    header('Location: admin_main.php');
    exit;
}
$employeeId = (int) $current['employee_id'];
$stmt = $conn->prepare('SELECT annual_leave, sick_leave, personal_leave FROM holiday_balance WHERE employee_id = ?');
$stmt->bind_param('i', $employeeId);
$stmt->execute();
$balance = $stmt->get_result()->fetch_assoc();
$stmt->close();
$stmt = $conn->prepare('SELECT request_id, leave_type, start_date, end_date, status, updated_at FROM holiday_requests WHERE employee_id = ? ORDER BY updated_at DESC, request_id DESC LIMIT 5');
$stmt->bind_param('i', $employeeId);
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

ui_page_start('My leave overview', 'View your leave balances, submit requests, and track approval decisions.', [
    'current' => $current,
    'actions' => '<a class="button" href="holiday_requests.php">' . ui_icon('plus') . e(ui_t('Request leave')) . '</a>',
]);
?>
<?php if (!$balance): ?>
<div class="notice notice-warning" role="status"><?php echo e(ui_t('Your leave balance is unavailable. Please contact your administrator.')); ?></div>
<?php endif; ?>
<div class="stat-grid">
    <?php foreach (['annual_leave' => 'Annual leave', 'sick_leave' => 'Sick leave', 'personal_leave' => 'Personal leave'] as $column => $label): ?>
    <div class="stat-card"><span class="stat-label"><?php echo e(ui_t($label)); ?></span><strong class="stat-value"><?php echo $balance ? e($balance[$column]) : '&mdash;'; ?><small> <?php echo e(ui_t('days')); ?></small></strong><span class="stat-note"><?php echo e(ui_t('Available balance')); ?></span></div>
    <?php endforeach; ?>
</div>

<section class="panel" aria-labelledby="requests-heading">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Leave history')); ?></p><h2 id="requests-heading"><?php echo e(ui_t('Recent leave requests')); ?></h2></div><a class="button button-quiet" href="check_my_holiday.php"><?php echo e(ui_t('View leave history')); ?></a></div>
    <?php if ($requests): ?>
    <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th scope="col"><?php echo e(ui_t('Leave type')); ?></th><th scope="col"><?php echo e(ui_t('Start date')); ?></th><th scope="col"><?php echo e(ui_t('End date')); ?></th><th scope="col"><?php echo e(ui_t('Status')); ?></th><th scope="col"><?php echo e(ui_t('Last updated')); ?></th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <?php $statusClass = ['Pending' => 'badge-warning', 'Approved' => 'badge-success', 'Rejected' => 'badge-danger'][$request['status']] ?? 'badge-neutral'; ?>
                <tr><td><strong><?php echo e(ui_t($request['leave_type'] . ' leave')); ?></strong><small class="muted"><?php echo e(ui_t('Request #{id}', ['id' => $request['request_id']])); ?></small></td><td><?php echo e($request['start_date']); ?></td><td><?php echo e($request['end_date']); ?></td><td><span class="badge <?php echo $statusClass; ?>"><?php echo e(ui_t($request['status'])); ?></span></td><td><?php echo e($request['updated_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><h3><?php echo e(ui_t('No leave requests')); ?></h3><p><?php echo e(ui_t('You have not submitted any leave requests yet.')); ?></p><a class="button" href="holiday_requests.php"><?php echo e(ui_t('Request leave')); ?></a></div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <a class="action-card" href="holiday_requests.php"><span class="eyebrow"><?php echo e(ui_t('Leave requests')); ?></span><h3><?php echo e(ui_t('Submit a leave request')); ?></h3><p><?php echo e(ui_t('Choose your leave type and dates, then send a request for approval.')); ?></p><span><?php echo e(ui_t('Request leave')); ?> <?php echo ui_icon('arrow'); ?></span></a>
    <a class="action-card" href="change_password.php"><span class="eyebrow"><?php echo e(ui_t('Account security')); ?></span><h3><?php echo e(ui_t('Change your password')); ?></h3><p><?php echo e(ui_t('Verify your current password and choose a new one.')); ?></p><span><?php echo e(ui_t('Change password')); ?> <?php echo ui_icon('arrow'); ?></span></a>
</div>
<p class="muted"><?php echo e(ui_t('Leave balances reflect approved requests. Pending requests remain subject to review.')); ?></p>
<?php ui_page_end(); ?>
