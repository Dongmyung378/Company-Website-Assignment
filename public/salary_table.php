<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();

$departments = $conn->query("SELECT department_id, department_name FROM department WHERE department_name != 'Executive' ORDER BY department_name ASC");
$positions = $conn->query("
    SELECT p.position_id, p.position_name
    FROM position p
    JOIN department d ON p.department_id = d.department_id
    WHERE d.department_name != 'Executive'
    ORDER BY p.position_name ASC
");

$selected_department = input_string($_GET, 'department');
$selected_position = input_string($_GET, 'position');
$min_amount = input_string($_GET, 'min_amount');
$max_amount = input_string($_GET, 'max_amount');
$view_type = input_string($_GET, 'view_type', 'annual');
foreach ([$selected_department, $selected_position] as $id) {
    if ($id !== '' && (!ctype_digit($id) || (int) $id < 1 || (int) $id > 2147483647)) {
        http_response_code(422);
        exit('Select a valid department or position.');
    }
}
foreach ([$min_amount, $max_amount] as $amount) {
    if ($amount !== '' && (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $amount))) {
        http_response_code(422);
        exit('Salary limits must be non-negative amounts with at most two decimal places.');
    }
}
if (!in_array($view_type, ['annual', 'monthly', 'quarterly'], true)
    || ($min_amount !== '' && $max_amount !== '' && (float) $min_amount > (float) $max_amount)) {
    http_response_code(422);
    exit('Select a valid report period and salary range.');
}
$order_by = 'net_salary DESC';

// base condition
$conditions = "WHERE d.department_name != 'Executive'";

if (!empty($selected_department)) {
    $conditions .= " AND d.department_id = " . intval($selected_department);
}

if (!empty($selected_position)) {
    $conditions .= " AND p.position_id = " . intval($selected_position);
}

$sql = "
    SELECT e.employee_id, e.name, d.department_name, p.position_name, e.salary,
           (e.salary * 0.15) AS bonus,
           (e.salary * 0.05) AS incentives,
           ((e.salary + (e.salary * 0.15) + (e.salary * 0.05)) * 0.20) AS tax,
           ((e.salary + (e.salary * 0.15) + (e.salary * 0.05)) * 0.10) AS insurance,
           ((e.salary + (e.salary * 0.15) + (e.salary * 0.05)) * 0.05) AS retirement,
            (
               (e.salary + (e.salary * 0.15) + (e.salary * 0.05)) -
               (((e.salary + (e.salary * 0.15) + (e.salary * 0.05)) * 0.20) +
               ((e.salary + (e.salary * 0.15) + (e.salary * 0.05)) * 0.10) +
               ((e.salary + (e.salary * 0.15) + (e.salary * 0.05)) * 0.05))
            ) AS net_salary
    FROM employees e
    JOIN department d ON e.department_id = d.department_id
    JOIN position p ON e.position_id = p.position_id
    $conditions
";

$sql .= " ORDER BY $order_by";
$result = $conn->query($sql);

$data = [];
$total_salary = 0;
$total_net_salary = 0;
$department_totals = [];
$department_counts = [];
$employee_count = 0;

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // monthly, quarterly
        $row['monthly_salary'] = $row['net_salary'] / 12;
        $row['quarterly_salary'] = $row['net_salary'] / 4;

        // by time
        $row['monthly_base_salary'] = $row['salary'] / 12;
        $row['quarterly_base_salary'] = $row['salary'] / 4;

        $row['monthly_bonus'] = $row['bonus'] / 12;
        $row['quarterly_bonus'] = $row['bonus'] / 4;

        $row['monthly_incentives'] = $row['incentives'] / 12;
        $row['quarterly_incentives'] = $row['incentives'] / 4;

        $row['monthly_tax'] = $row['tax'] / 12;
        $row['quarterly_tax'] = $row['tax'] / 4;

        $row['monthly_insurance'] = $row['insurance'] / 12;
        $row['quarterly_insurance'] = $row['insurance'] / 4;

        $row['monthly_retirement'] = $row['retirement'] / 12;
        $row['quarterly_retirement'] = $row['retirement'] / 4;

        // filtering base condition
        $amount_to_check = $row['net_salary'];
        if ($view_type === 'monthly') {
            $amount_to_check = $row['monthly_salary'];
        } elseif ($view_type === 'quarterly') {
            $amount_to_check = $row['quarterly_salary'];
        }

        if ((!empty($min_amount) && $amount_to_check < floatval($min_amount)) ||
            (!empty($max_amount) && $amount_to_check > floatval($max_amount))
        ) {
            continue;
        }

        // add data
        $data[] = $row;

        // salary by time
        if ($view_type === 'annual') {
            $base_salary = $row['salary'];
        } elseif ($view_type === 'monthly') {
            $base_salary = $row['monthly_base_salary'];
        } elseif ($view_type === 'quarterly') {
            $base_salary = $row['quarterly_base_salary'];
        }

        // total
        $total_salary += $base_salary;
        $total_net_salary += $amount_to_check;

        // total employees in department and salary
        $department_name = $row['department_name'];
        if (!isset($department_totals[$department_name])) {
            $department_totals[$department_name] = 0;
            $department_counts[$department_name] = 0;
        }
        $department_totals[$department_name] += $amount_to_check;
        $department_counts[$department_name] += 1;

        $employee_count++;
    }
}

// average 
$average_net_salary = $employee_count > 0 ? $total_net_salary / $employee_count : 0;

// department average
$department_averages = [];
foreach ($department_totals as $department_name => $total) {
    $count = $department_counts[$department_name];
    $department_averages[$department_name] = $count > 0 ? $total / $count : 0;
}

// check CSV export query
if (input_string($_GET, 'export_csv') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=salary_report.csv');

    // create pointer
    $output = fopen('php://output', 'w');

    // CSV headers
    fputcsv($output, [
        'Employee ID',
        'Name',
        'Department',
        'Position',
        'Base Salary (' . ucfirst($view_type) . ')',
        'Bonus',
        'Incentives',
        'Tax',
        'Insurance',
        'Retirement',
        'Net Salary (' . ucfirst($view_type) . ')'
    ], ',', '"', '');

    // data export 
    foreach ($data as $row) {
        if ($view_type === 'annual') {
            $base_salary = $row['salary'];
            $net_salary = $row['net_salary'];
            $bonus = $row['bonus'];
            $incentives = $row['incentives'];
            $tax = $row['tax'];
            $insurance = $row['insurance'];
            $retirement = $row['retirement'];
        } elseif ($view_type === 'monthly') {
            $base_salary = $row['monthly_base_salary'];
            $net_salary = $row['monthly_salary'];
            $bonus = $row['monthly_bonus'];
            $incentives = $row['monthly_incentives'];
            $tax = $row['monthly_tax'];
            $insurance = $row['monthly_insurance'];
            $retirement = $row['monthly_retirement'];
        } elseif ($view_type === 'quarterly') {
            $base_salary = $row['quarterly_base_salary'];
            $net_salary = $row['quarterly_salary'];
            $bonus = $row['quarterly_bonus'];
            $incentives = $row['quarterly_incentives'];
            $tax = $row['quarterly_tax'];
            $insurance = $row['quarterly_insurance'];
            $retirement = $row['quarterly_retirement'];
        }

        fputcsv($output, [
            $row['employee_id'],
            csv_safe_text($row['name']),
            csv_safe_text($row['department_name']),
            csv_safe_text($row['position_name']),
            number_format($base_salary),
            number_format($bonus),
            number_format($incentives),
            number_format($tax),
            number_format($insurance),
            number_format($retirement),
            number_format($net_salary)
        ], ',', '"', '');
    }

    fclose($output);
    exit();
}
?>
<?php ui_page_start('Salary Report', 'Understand compensation, deductions and net pay across your teams.', ['current' => $current]); ?>
<section class="panel" aria-labelledby="salary-filters-title">
    <div class="panel-header"><div><p class="eyebrow">Report filters</p><h2 id="salary-filters-title">Build your salary report</h2><p class="muted">Filter by team and net pay, then export the current report to CSV.</p></div><span class="badge badge-neutral">GBP (£)</span></div>
    <form method="GET" action="salary_table.php" class="form-grid filter-grid">
        <div class="field"><label for="department">Department</label><select id="department" name="department"><option value="">All departments</option>
            <?php $department_label = 'All departments'; $departments->data_seek(0); while ($row = $departments->fetch_assoc()): ?>
                <?php if ($selected_department == $row['department_id']) $department_label = $row['department_name']; ?>
                <option value="<?php echo e($row['department_id']); ?>"<?php echo $selected_department == $row['department_id'] ? ' selected' : ''; ?>><?php echo e($row['department_name']); ?></option>
            <?php endwhile; ?>
        </select></div>
        <div class="field"><label for="position">Position</label><select id="position" name="position"><option value="">All positions</option>
            <?php $position_label = 'All positions'; $positions->data_seek(0); while ($row = $positions->fetch_assoc()): ?>
                <?php if ($selected_position == $row['position_id']) $position_label = $row['position_name']; ?>
                <option value="<?php echo e($row['position_id']); ?>"<?php echo $selected_position == $row['position_id'] ? ' selected' : ''; ?>><?php echo e($row['position_name']); ?></option>
            <?php endwhile; ?>
        </select></div>
        <div class="field"><label for="view_type">Reporting period</label><select id="view_type" name="view_type"><option value="annual"<?php echo $view_type === 'annual' ? ' selected' : ''; ?>>Annual</option><option value="monthly"<?php echo $view_type === 'monthly' ? ' selected' : ''; ?>>Monthly</option><option value="quarterly"<?php echo $view_type === 'quarterly' ? ' selected' : ''; ?>>Quarterly</option></select></div>
        <div class="field"><label for="min_amount">Minimum net pay (£)</label><input type="number" id="min_amount" step="0.01" min="0" name="min_amount" placeholder="No minimum" value="<?php echo e($min_amount); ?>" aria-describedby="salary-range-hint"></div>
        <div class="field"><label for="max_amount">Maximum net pay (£)</label><input type="number" id="max_amount" step="0.01" min="0" name="max_amount" placeholder="No maximum" value="<?php echo e($max_amount); ?>" aria-describedby="salary-range-hint"></div>
        <p id="salary-range-hint" class="field-hint field-full">The pay range applies to net pay for the reporting period you select. Monthly values are annual amounts divided by 12; quarterly values are annual amounts divided by 4.</p>
        <div class="form-actions field-full"><button type="submit" class="button">Apply filters</button><button type="submit" name="export_csv" value="1" class="button button-secondary">Export CSV</button><a class="button button-quiet" href="salary_table.php">Reset filters</a></div>
    </form>
</section>
<div class="summary-row report-summary"><span class="badge badge-neutral"><?php echo e(ucfirst($view_type)); ?></span><span class="badge badge-neutral"><?php echo e($department_label); ?></span><span class="badge badge-neutral"><?php echo e($position_label); ?></span><span class="muted"><?php echo $employee_count; ?> employees match your filters</span></div>
<div class="stat-grid" aria-label="Salary report totals">
    <div class="stat-card"><span class="stat-label">Employees</span><strong class="stat-value"><?php echo $employee_count; ?></strong><span class="stat-note">In this report</span></div>
    <div class="stat-card"><span class="stat-label">Total base salary</span><strong class="stat-value">£<?php echo number_format($total_salary); ?></strong><span class="stat-note"><?php echo e(ucfirst($view_type)); ?> compensation</span></div>
    <div class="stat-card"><span class="stat-label">Total net pay</span><strong class="stat-value">£<?php echo number_format($total_net_salary); ?></strong><span class="stat-note">After deductions</span></div>
    <div class="stat-card"><span class="stat-label">Average net pay</span><strong class="stat-value">£<?php echo number_format($average_net_salary); ?></strong><span class="stat-note">Per employee, <?php echo e($view_type); ?></span></div>
</div>
<?php if ($data): ?>
    <section class="panel" aria-labelledby="salary-detail-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ucfirst($view_type)); ?> report</p><h2 id="salary-detail-title">Employee compensation</h2><p class="muted">Sorted by net pay, highest first. All amounts are shown in pounds.</p></div><span class="badge badge-success"><?php echo $employee_count; ?> employees</span></div>
        <div class="table-scroll"><table class="data-table"><caption class="sr-only"><?php echo e(ucfirst($view_type)); ?> employee salary breakdown in GBP</caption><thead><tr><th scope="col">Employee ID</th><th scope="col">Employee</th><th scope="col">Department</th><th scope="col">Position</th><th scope="col">Base salary</th><th scope="col">Bonus</th><th scope="col">Incentives</th><th scope="col">Tax</th><th scope="col">Insurance</th><th scope="col">Retirement</th><th scope="col">Net pay</th></tr></thead><tbody>
        <?php foreach ($data as $row): ?>
            <?php
                $prefix = $view_type === 'annual' ? '' : $view_type . '_';
                $base_key = $view_type === 'annual' ? 'salary' : $prefix . 'base_salary';
                $net_key = $view_type === 'annual' ? 'net_salary' : $prefix . 'salary';
            ?>
            <tr><td><?php echo e($row['employee_id']); ?></td><td><strong><?php echo e($row['name']); ?></strong></td><td><?php echo e($row['department_name']); ?></td><td><?php echo e($row['position_name']); ?></td><td>£<?php echo number_format($row[$base_key]); ?></td><td>£<?php echo number_format($row[$prefix . 'bonus']); ?></td><td>£<?php echo number_format($row[$prefix . 'incentives']); ?></td><td>£<?php echo number_format($row[$prefix . 'tax']); ?></td><td>£<?php echo number_format($row[$prefix . 'insurance']); ?></td><td>£<?php echo number_format($row[$prefix . 'retirement']); ?></td><td><strong>£<?php echo number_format($row[$net_key]); ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
    <section class="panel" aria-labelledby="department-salary-title">
        <div class="panel-header"><div><h2 id="department-salary-title">Net pay by department</h2><p class="muted">Totals and averages reflect the filters currently applied.</p></div></div>
        <div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Department</th><th scope="col">Employees</th><th scope="col">Total net pay</th><th scope="col">Average net pay</th></tr></thead><tbody>
        <?php foreach ($department_totals as $department_name => $total): ?><tr><td><strong><?php echo e($department_name); ?></strong></td><td><?php echo $department_counts[$department_name]; ?></td><td>£<?php echo number_format($total); ?></td><td>£<?php echo number_format($department_averages[$department_name]); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
<?php else: ?>
    <section class="panel empty-state"><p class="eyebrow">Salary report</p><h2>No matching employees</h2><p>Try widening the net pay range or choosing another department or position.</p><a class="button button-secondary" href="salary_table.php">Clear filters</a></section>
<?php endif; ?>
<?php ui_page_end(); ?>
