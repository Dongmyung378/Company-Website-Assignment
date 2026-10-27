<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
require_once __DIR__ . '/../lib/business.php';
$current = require_admin();
$month = date('Y-m');
$holiday_data = [];
$summary = ['total_days' => 0, 'annual_leave' => 0, 'sick_leave' => 0, 'personal_leave' => 0];
$department_summary = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $month = input_string($_POST, 'month');
        if (!preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $month) || !valid_date($month . '-01') || $month < '1000-01') {
            throw new WorkflowException('Select a valid month in YYYY-MM format.');
        }
        $firstDay = valid_date($month . '-01');
        $monthStart = $firstDay->format('Y-m-d');
        $monthEnd = $firstDay->modify('last day of this month')->format('Y-m-d');
        $stmt = $conn->prepare("SELECT e.name, e.department_id, d.department_name, hr.leave_type, hr.start_date, hr.end_date, hr.status FROM holiday_requests hr JOIN employees e ON hr.employee_id = e.employee_id JOIN department d ON e.department_id = d.department_id WHERE hr.start_date <= ? AND hr.end_date >= ? AND hr.status = 'Approved' ORDER BY hr.start_date, hr.request_id");
        $stmt->bind_param('ss', $monthEnd, $monthStart);
        $stmt->execute();
        $result = $stmt->get_result();
        $invalidRows = 0;
        $leaveColumns = ['Annual' => 'annual_leave', 'Sick' => 'sick_leave', 'Personal' => 'personal_leave'];
        while ($row = $result->fetch_assoc()) {
            if (!valid_date($row['start_date']) || !valid_date($row['end_date'])
                || $row['start_date'] < '1000-01-01' || $row['end_date'] < $row['start_date']
                || !isset($leaveColumns[$row['leave_type']])) {
                $invalidRows++;
                continue;
            }
            $days = calendar_days(max($monthStart, $row['start_date']), min($monthEnd, $row['end_date']));
            $row['days_in_month'] = $days;
            $holiday_data[] = $row;
            $summary['total_days'] += $days;
            $summary[$leaveColumns[$row['leave_type']]] += $days;
            $departmentId = (int) $row['department_id'];
            if (!isset($department_summary[$departmentId])) {
                $department_summary[$departmentId] = ['department_name' => $row['department_name'], 'total_days' => 0, 'average_days' => 0];
            }
            $department_summary[$departmentId]['total_days'] += $days;
        }
        $stmt->close();
        $counts = $conn->query('SELECT department_id, COUNT(*) AS employee_count FROM employees GROUP BY department_id');
        while ($count = $counts->fetch_assoc()) {
            $departmentId = (int) $count['department_id'];
            if (isset($department_summary[$departmentId])) {
                $department_summary[$departmentId]['average_days'] = round($department_summary[$departmentId]['total_days'] / (int) $count['employee_count'], 2);
            }
        }
        if ($invalidRows) {
            $message = $invalidRows . ' approved requests contain invalid stored dates or leave types and could not be included. Contact the administrator.';
        } elseif (!$holiday_data) {
            $message = 'No approved holiday requests found for the selected month.';
        }
    } catch (Throwable $error) {
        $message = business_report_failure($error, 'Unable to load the monthly holiday report.');
    }
}
?>
<?php ui_page_start('Monthly Holiday Report', 'Review approved leave and department totals for a selected month.', ['current' => $current]); ?>
<section class="panel" aria-labelledby="holiday-filters-title">
    <div class="panel-header"><div><p class="eyebrow">Report filters</p><h2 id="holiday-filters-title">Choose a month</h2></div><span class="badge badge-success">Approved leave only</span></div>
    <form method="POST" action="" class="form-grid">
        <?php echo csrf_field(); ?>
        <div class="field"><label for="month">Reporting month</label><input type="month" id="month" name="month" value="<?php echo e($month); ?>" min="1000-01" aria-describedby="month-hint" required><p class="field-hint" id="month-hint">Days count both the start and end date, including weekends. Only days within the selected month are included.</p></div>
        <div class="form-actions"><button type="submit" name="check_holidays" class="button">View report</button><a class="button button-secondary" href="check_absence.php">Reset</a></div>
    </form>
</section>
<?php if ($message !== ''): ?><?php ui_notice($message, !empty($invalidRows) || http_response_code() >= 400 ? 'warning' : 'info'); ?><?php endif; ?>
<?php if (!empty($holiday_data)): ?>
    <div class="stat-grid" aria-label="Holiday summary for <?php echo e($month); ?>">
        <div class="stat-card"><span class="stat-label">Total leave days</span><strong class="stat-value"><?php echo e($summary['total_days']); ?></strong><span class="stat-note"><?php echo e($month); ?> calendar days</span></div>
        <div class="stat-card"><span class="stat-label">Annual leave</span><strong class="stat-value"><?php echo e($summary['annual_leave']); ?></strong><span class="stat-note">Days in selected month</span></div>
        <div class="stat-card"><span class="stat-label">Sick leave</span><strong class="stat-value"><?php echo e($summary['sick_leave']); ?></strong><span class="stat-note">Days in selected month</span></div>
        <div class="stat-card"><span class="stat-label">Personal leave</span><strong class="stat-value"><?php echo e($summary['personal_leave']); ?></strong><span class="stat-note">Days in selected month</span></div>
    </div>
    <section class="panel" aria-labelledby="holiday-details-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e($month); ?></p><h2 id="holiday-details-title">Approved leave details</h2></div><span class="badge badge-neutral"><?php echo count($holiday_data); ?> requests</span></div>
        <div class="table-scroll"><table class="data-table"><caption class="sr-only">Approved leave overlapping <?php echo e($month); ?></caption><thead><tr><th scope="col">Employee</th><th scope="col">Department</th><th scope="col">Leave type</th><th scope="col">Start date</th><th scope="col">End date</th><th scope="col">Status</th><th scope="col">Days in month</th></tr></thead><tbody>
        <?php foreach ($holiday_data as $holiday): ?><tr><td><strong><?php echo e($holiday['name']); ?></strong></td><td><?php echo e($holiday['department_name']); ?></td><td><?php echo e($holiday['leave_type']); ?></td><td><?php echo e($holiday['start_date']); ?></td><td><?php echo e($holiday['end_date']); ?></td><td><span class="badge badge-success"><?php echo e($holiday['status']); ?></span></td><td><strong><?php echo e($holiday['days_in_month']); ?></strong></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="panel" aria-labelledby="department-leave-title">
        <div class="panel-header"><div><h2 id="department-leave-title">Leave by department</h2><p class="muted">Average days are calculated across all employees in each department.</p></div></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Department</th><th scope="col">Total leave days</th><th scope="col">Average days per employee</th></tr></thead><tbody>
        <?php foreach ($department_summary as $dept_summary): ?><tr><td><strong><?php echo e($dept_summary['department_name']); ?></strong></td><td><?php echo e($dept_summary['total_days']); ?></td><td><?php echo e($dept_summary['average_days']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php else: ?>
    <section class="panel empty-state"><p class="eyebrow">Monthly overview</p><h2><?php echo $_SERVER['REQUEST_METHOD'] === 'POST' ? 'No leave to display' : 'Your monthly report starts here'; ?></h2><p><?php echo $_SERVER['REQUEST_METHOD'] === 'POST' ? 'Choose another month to review approved leave for a different period.' : 'Choose a reporting month above to see approved leave, totals and department averages.'; ?></p></section>
<?php endif; ?>
<?php ui_page_end(); ?>
