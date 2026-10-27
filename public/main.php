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

ui_page_start('A little space for you', 'Welcome back, ' . $current['name'] . '. Manage your time off and keep up with your requests.', [
    'current' => $current,
    'actions' => '<a class="button" href="holiday_requests.php">+ Request leave</a>',
]);
?>
<?php if (!$balance): ?>
<div class="notice notice-warning" role="status">Your leave balance is unavailable. Please contact your administrator.</div>
<?php endif; ?>
<div class="stat-grid">
    <?php foreach (['annual_leave' => 'Annual leave', 'sick_leave' => 'Sick leave', 'personal_leave' => 'Personal leave'] as $column => $label): ?>
    <div class="stat-card"><span class="stat-label"><?php echo e($label); ?></span><strong class="stat-value"><?php echo $balance ? e($balance[$column]) : '&mdash;'; ?><small> days</small></strong><span class="stat-note">Available balance</span></div>
    <?php endforeach; ?>
</div>

<section class="panel" aria-labelledby="requests-heading">
    <div class="panel-header"><div><p class="eyebrow">Stay in the loop</p><h2 id="requests-heading">Your latest requests</h2></div><a class="button button-quiet" href="check_my_holiday.php">View all requests</a></div>
    <?php if ($requests): ?>
    <div class="table-scroll">
        <table class="data-table">
            <thead><tr><th scope="col">Leave type</th><th scope="col">Start date</th><th scope="col">End date</th><th scope="col">Status</th><th scope="col">Last updated</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <?php $statusClass = ['Pending' => 'badge-warning', 'Approved' => 'badge-success', 'Rejected' => 'badge-danger'][$request['status']] ?? 'badge-neutral'; ?>
                <tr><td><strong><?php echo e($request['leave_type']); ?> leave</strong><small class="muted">Request #<?php echo e($request['request_id']); ?></small></td><td><?php echo e($request['start_date']); ?></td><td><?php echo e($request['end_date']); ?></td><td><span class="badge <?php echo $statusClass; ?>"><?php echo e($request['status']); ?></span></td><td><?php echo e($request['updated_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><h3>Your next break starts here</h3><p>You have not submitted any leave requests yet.</p><a class="button" href="holiday_requests.php">Request leave</a></div>
    <?php endif; ?>
</section>

<div class="grid-2">
    <a class="action-card" href="holiday_requests.php"><span class="eyebrow">Plan ahead</span><h3>Make time for what matters</h3><p>Choose your leave type and dates, then send a request for review.</p><span>Request leave &rarr;</span></a>
    <a class="action-card" href="change_password.php"><span class="eyebrow">Your account</span><h3>Keep your account secure</h3><p>Update your password whenever you need to.</p><span>Change password &rarr;</span></a>
</div>
<p class="muted">Leave balances reflect approved requests. Pending requests remain subject to review.</p>
<?php ui_page_end(); ?>