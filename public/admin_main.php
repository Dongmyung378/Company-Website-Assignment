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

ui_page_start('Workforce overview', 'Employee records and leave approvals for your organization.', [
    'current' => $current,
    'actions' => '<a class="button" href="add_employee.php">' . ui_icon('plus') . e(ui_t('Add employee')) . '</a>',
]);
?>
<section class="company-banner" aria-label="<?php echo e(ui_t('Kilburnazon company overview')); ?>">
    <div class="company-banner-copy">
        <p class="eyebrow">Kilburnazon</p>
        <h2><?php echo e(ui_t('Employee management, in one place.')); ?></h2>
        <p><?php echo e(ui_t('Keep employee records current and review leave requests from your team.')); ?></p>
        <div class="company-banner-links">
            <a class="button button-secondary" href="list_employees.php"><?php echo e(ui_t('Open directory')); ?></a>
            <a class="button button-quiet" href="check_holiday_requests.php"><?php echo e(ui_t('Review requests')); ?> <?php echo ui_icon('arrow'); ?></a>
        </div>
    </div>
    <figure class="company-banner-art">
        <img src="assets/images/company-illustration.jpg" width="1024" height="1024" alt="<?php echo e(ui_t('Kilburnazon delivery company illustration, Manchester, UK.')); ?>" loading="eager" decoding="async">
    </figure>
</section>
<div class="stat-grid">
    <a class="stat-card" href="list_employees.php">
        <span class="stat-label"><?php echo e(ui_t('Total employees')); ?></span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['employee_count'])); ?></strong>
        <span class="stat-note"><?php echo e(ui_t('Current employee records')); ?></span>
    </a>
    <a class="stat-card" href="check_holiday_requests.php">
        <span class="stat-label"><?php echo e(ui_t('Pending requests')); ?></span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['pending_count'])); ?></strong>
        <span class="stat-note"><?php echo e(ui_t('Awaiting an approval decision')); ?></span>
    </a>
    <a class="stat-card" href="check_absence.php">
        <span class="stat-label"><?php echo e(ui_t('Away today')); ?></span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['away_count'])); ?></strong>
        <span class="stat-note"><?php echo e(ui_t('Approved leave covering today')); ?></span>
    </a>
    <a class="stat-card" href="list_employees.php">
        <span class="stat-label"><?php echo e(ui_t('Office locations')); ?></span>
        <strong class="stat-value"><?php echo e(number_format((int) $overview['office_count'])); ?></strong>
        <span class="stat-note"><?php echo e(ui_t('Locations with assigned employees')); ?></span>
    </a>
</div>

<?php if ((int) $overview['pending_count'] > 0): ?>
<div class="notice notice-warning" role="status">
    <div><strong><?php echo e(ui_t((int) $overview['pending_count'] === 1 ? '{count} leave request is awaiting approval.' : '{count} leave requests are awaiting approval.', ['count' => number_format((int) $overview['pending_count'])])); ?></strong>
    <p><?php echo e(ui_t('Review the request dates and available leave balance before approving.')); ?></p></div>
    <a class="button button-secondary" href="check_holiday_requests.php"><?php echo e(ui_t('Review requests')); ?></a>
</div>
<?php endif; ?>

<div class="grid-2">
    <section class="panel" aria-labelledby="pending-heading">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Approvals')); ?></p><h2 id="pending-heading"><?php echo e(ui_t('Pending leave requests')); ?></h2></div><a class="button button-quiet" href="check_holiday_requests.php"><?php echo e(ui_t('Review all')); ?></a></div>
        <?php if ($pending): ?>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th scope="col"><?php echo e(ui_t('Employee')); ?></th><th scope="col"><?php echo e(ui_t('Leave dates')); ?></th><th scope="col"><?php echo e(ui_t('Status')); ?></th></tr></thead>
                <tbody>
                <?php foreach ($pending as $request): ?>
                    <tr><td><strong><?php echo e($request['name']); ?></strong><small class="muted"><?php echo e(ui_t($request['leave_type'] . ' leave')); ?></small></td>
                        <td><?php echo e($request['start_date']); ?><small class="muted"><?php echo e(ui_t('to {date}', ['date' => $request['end_date']])); ?></small></td>
                        <td><span class="badge badge-warning"><?php echo e(ui_t('Pending')); ?></span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><h3><?php echo e(ui_t('No pending requests')); ?></h3><p><?php echo e(ui_t('There are no leave requests waiting for review.')); ?></p></div>
        <?php endif; ?>
    </section>
    <section class="panel" aria-labelledby="team-heading">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Employee records')); ?></p><h2 id="team-heading"><?php echo e(ui_t('Recent hires')); ?></h2></div><a class="button button-quiet" href="list_employees.php"><?php echo e(ui_t('Open directory')); ?></a></div>
        <?php if ($recent): ?>
        <?php foreach ($recent as $employee): ?>
        <div class="summary-row">
            <div><a href="employee_detail.php?id=<?php echo e($employee['employee_id']); ?>"><strong><?php echo e($employee['name']); ?></strong></a><p class="muted"><?php echo e(ui_t($employee['position_name'])); ?> / <?php echo e(ui_t($employee['department_name'])); ?></p></div>
            <span class="badge badge-neutral"><?php echo e($employee['hired_date']); ?></span>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state"><h3><?php echo e(ui_t('No recent hires')); ?></h3><p><?php echo e(ui_t('Employees hired on or before today appear here.')); ?></p><a class="button" href="add_employee.php"><?php echo e(ui_t('Add employee')); ?></a></div>
        <?php endif; ?>
    </section>
</div>

<section class="task-section" aria-labelledby="quick-heading">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Management tools')); ?></p><h2 id="quick-heading"><?php echo e(ui_t('Common tasks')); ?></h2></div></div>
    <div class="grid-3">
        <a class="action-card" href="list_employees.php"><span class="eyebrow"><?php echo e(ui_t('People')); ?></span><h3><?php echo e(ui_t('Employee directory')); ?></h3><p><?php echo e(ui_t('Search employee records, review profiles, and open an employee for editing.')); ?></p><span><?php echo e(ui_t('Open directory')); ?> <?php echo ui_icon('arrow'); ?></span></a>
        <a class="action-card" href="salary_table.php"><span class="eyebrow"><?php echo e(ui_t('Compensation')); ?></span><h3><?php echo e(ui_t('Salary overview')); ?></h3><p><?php echo e(ui_t('Filter salary calculations by department or position and export a CSV report.')); ?></p><span><?php echo e(ui_t('Open salary report')); ?> <?php echo ui_icon('arrow'); ?></span></a>
        <a class="action-card" href="check_absence.php"><span class="eyebrow"><?php echo e(ui_t('Attendance')); ?></span><h3><?php echo e(ui_t('Absence report')); ?></h3><p><?php echo e(ui_t('Review approved leave days and department totals for a selected month.')); ?></p><span><?php echo e(ui_t('Open absence report')); ?> <?php echo ui_icon('arrow'); ?></span></a>
    </div>
    <div class="form-actions"><a class="button button-secondary" href="update_employee.php"><?php echo e(ui_t('Update an employee')); ?></a><a class="button button-quiet" href="delete_employee.php"><?php echo e(ui_t('Remove employee record')); ?></a></div>
</section>
<section class="panel" aria-labelledby="personal-heading">
    <div class="panel-header"><div><h2 id="personal-heading"><?php echo e(ui_t('Employee services')); ?></h2><p class="muted"><?php echo e(ui_t('Submit your own leave request, check its status, or review employee birthdays.')); ?></p></div></div>
    <div class="form-actions"><a class="button button-secondary" href="holiday_requests.php"><?php echo e(ui_t('Request leave')); ?></a><a class="button button-secondary" href="check_my_holiday.php"><?php echo e(ui_t('My leave status')); ?></a><a class="button button-secondary" href="happybirthday.php"><?php echo e(ui_t('Employee birthdays')); ?></a></div>
</section>
<?php ui_page_end(); ?>
