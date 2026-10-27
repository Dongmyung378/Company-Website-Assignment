<?php
require_once __DIR__ . '/../config/bootstrap.php';
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

<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table th,
        table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        table th {
            background-color: #f4f4f4;
            font-weight: bold;
        }

        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        table tr:hover {
            background-color: #f1f1f1;
        }

        h2,
        h3 {
            font-family: Arial, sans-serif;
            color: #333;
        }

        ul {
            list-style-type: none;
            padding: 0;
            font-family: Arial, sans-serif;
            color: #333;
        }

        ul li {
            margin: 5px 0;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <h2>Monthly Holiday Report</h2>

    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <label for="month">Select Month:</label>
        <input type="month" name="month" value="<?php echo e($month); ?>" required>
        <button type="submit" name="check_holidays">Check Holidays</button>
    </form>

    <?php if ($message): ?>
        <p style="color: red;"><?php echo e($message); ?></p>
    <?php endif; ?>

    <?php if (!empty($holiday_data)): ?>
        <h3>Holiday Details</h3>
        <table>
            <tr>
                <th>Name</th>
                <th>Department</th>
                <th>Leave Type</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>
                <th>Days in Selected Month</th>
            </tr>
            <?php foreach ($holiday_data as $holiday): ?>
                <tr>
                    <td><?php echo e($holiday['name']); ?></td>
                    <td><?php echo e($holiday['department_name']); ?></td>
                    <td><?php echo e($holiday['leave_type']); ?></td>
                    <td><?php echo e($holiday['start_date']); ?></td>
                    <td><?php echo e($holiday['end_date']); ?></td>
                    <td><?php echo e($holiday['status']); ?></td>
                    <td><?php echo e($holiday['days_in_month']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <h3>Summary</h3>
        <ul>
            <li>Total Holiday Days: <?php echo $summary['total_days']; ?> days</li>
            <li>Annual Leave Days: <?php echo $summary['annual_leave']; ?> days</li>
            <li>Sick Leave Days: <?php echo $summary['sick_leave']; ?> days</li>
            <li>Personal Leave Days: <?php echo $summary['personal_leave']; ?> days</li>
        </ul>

        <h3>Department Average Holiday Days</h3>
        <table>
            <tr>
                <th>Department Name</th>
                <th>Average Days</th>
            </tr>
            <?php foreach ($department_summary as $dept_summary): ?>
                <tr>
                    <td><?php echo e($dept_summary['department_name']); ?></td>
                    <td><?php echo e($dept_summary['average_days']); ?> days</td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <button onclick="window.location.href='admin_main.php'">Back to Main</button>
</body>

</html>
