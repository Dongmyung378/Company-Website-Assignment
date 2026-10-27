<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();

$overview = $conn->query("SELECT
    (SELECT COUNT(*) FROM employees) AS employee_count,
    (SELECT COUNT(*) FROM holiday_requests WHERE status = 'Pending') AS pending_count,
    (SELECT COUNT(DISTINCT employee_id) FROM holiday_requests WHERE status = 'Approved' AND start_date <= CURRENT_DATE() AND end_date >= CURRENT_DATE()) AS away_count,
    (SELECT COUNT(DISTINCT office_id) FROM employees) AS office_count")->fetch_assoc();
$pending = $conn->query("SELECT hr.request_id, hr.leave_type, hr.start_date, hr.end_date, e.name
    FROM holiday_requests hr JOIN employees e ON e.employee_id = hr.employee_id
    WHERE hr.status = 'Pending' ORDER BY hr.updated_at ASC, hr.request_id ASC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
$recent = $conn->query('SELECT e.employee_id, e.name, e.hired_date, d.department_name, p.position_name
    FROM employees e JOIN department d ON d.department_id = e.department_id
    JOIN position p ON p.position_id = e.position_id
    WHERE e.hired_date <= CURRENT_DATE() ORDER BY e.hired_date DESC, e.employee_id DESC LIMIT 4')->fetch_all(MYSQLI_ASSOC);
$conn->close();

ui_page_start('Your people, at a glance', 'Welcome back, ' . $current['name'] . '. Here is what is happening across your team.', [
    'current' => $current,
    'actions' => '<a class="button" href="add_employee.php">+ Add employee</a>',
]);
?>
<div class="stat-grid">
    <a class="stat-card" href="list_employees.php">
        <span class="stat-label">Total employees</span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['employee_count'])); ?></strong>
        <span class="stat-note">People in your organization</span>
    </a>
    <a class="stat-card" href="check_holiday_requests.php">
        <span class="stat-label">Pending requests</span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['pending_count'])); ?></strong>
        <span class="stat-note">Awaiting your review</span>
    </a>
    <a class="stat-card" href="check_absence.php">
        <span class="stat-label">Away today</span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['away_count'])); ?></strong>
        <span class="stat-note">Employees on approved leave</span>
    </a>
    <a class="stat-card" href="list_employees.php">
        <span class="stat-label">Office locations</span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['office_count'])); ?></strong>
        <span class="stat-note">With employees assigned</span>
    </a>
</div>

<?php if ((int) $overview['pending_count'] > 0): ?>
<div class="notice notice-warning" role="status">
    <div><strong><?php echo e($overview['pending_count']); ?> leave <?php echo (int) $overview['pending_count'] === 1 ? 'request needs' : 'requests need'; ?> your attention.</strong>
    <p>Review the dates and available balances before making a decision.</p></div>
    <a class="button button-secondary" href="check_holiday_requests.php">Review requests</a>
</div>
<?php endif; ?>

<div class="grid-2">
    <section class="panel" aria-labelledby="pending-heading">
        <div class="panel-header"><div><p class="eyebrow">Needs attention</p><h2 id="pending-heading">Leave requests</h2></div><a class="button button-quiet" href="check_holiday_requests.php">View all</a></div>
        <?php if ($pending): ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th scope="col">Employee</th><th scope="col">Leave dates</th><th scope="col">Status</th></tr></thead>
                <tbody>
                <?php foreach ($pending as $request): ?>
                    <tr><td><strong><?php echo e($request['name']); ?></strong><small class="muted"><?php echo e($request['leave_type']); ?> leave</small></td>
                        <td><?php echo e($request['start_date']); ?><small class="muted">to <?php echo e($request['end_date']); ?></small></td>
                        <td><span class="badge badge-warning">Pending</span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><h3>You are all caught up</h3><p>There are no leave requests waiting for review.</p></div>
        <?php endif; ?>
    </section>
    <section class="panel" aria-labelledby="team-heading">
        <div class="panel-header"><div><p class="eyebrow">Your growing team</p><h2 id="team-heading">Latest joiners</h2></div><a class="button button-quiet" href="list_employees.php">Directory</a></div>
        <?php if ($recent): ?>
        <?php foreach ($recent as $employee): ?>
        <div class="summary-row">
            <div><a href="employee_detail.php?id=<?php echo e($employee['employee_id']); ?>"><strong><?php echo e($employee['name']); ?></strong></a><p class="muted"><?php echo e($employee['position_name']); ?> / <?php echo e($employee['department_name']); ?></p></div>
            <span class="badge badge-neutral"><?php echo e($employee['hired_date']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state"><h3>Build your team</h3><p>Add your first employee to get started.</p><a class="button" href="add_employee.php">Add employee</a></div>
        <?php endif; ?>
    </section>
</div>

<section aria-labelledby="quick-heading">
    <div class="panel-header"><div><p class="eyebrow">Everyday essentials</p><h2 id="quick-heading">Quick actions</h2></div></div>
    <div class="grid-3">
        <a class="action-card" href="list_employees.php"><span class="eyebrow">01 / People</span><h3>Employee directory</h3><p>Find people, view profiles, and keep employee details up to date.</p><span>Explore your team &rarr;</span></a>
        <a class="action-card" href="salary_table.php"><span class="eyebrow">02 / Reports</span><h3>Salary overview</h3><p>Review compensation by team and export the report you need.</p><span>Open salary report &rarr;</span></a>
        <a class="action-card" href="check_absence.php"><span class="eyebrow">03 / Time off</span><h3>Absence report</h3><p>Understand approved leave and absence across the organization.</p><span>View monthly report &rarr;</span></a>
    </div>
</section>
<section class="panel" aria-labelledby="personal-heading">
    <div class="panel-header"><div><h2 id="personal-heading">For you and your team</h2><p class="muted">Make space for time off and moments worth celebrating.</p></div></div>
    <div class="form-actions"><a class="button button-secondary" href="holiday_requests.php">Request leave</a><a class="button button-secondary" href="check_my_holiday.php">My leave status</a><a class="button button-secondary" href="happybirthday.php">Birthday cards</a><a class="button button-quiet" href="update_employee.php">Update an employee</a><a class="button button-quiet" href="delete_employee.php">Manage employee departures</a></div>
</section>
<?php ui_page_end(); ?>