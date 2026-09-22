<?php
session_start();
require __DIR__ . '/../config/connection.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// check this employee`s department is executive
if ($_SESSION['department'] !== 2) {
    header("Location: main.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$month = date('Y-m');
$holiday_data = [];
$summary = [
    'total_days' => 0,
    'annual_leave' => 0,
    'sick_leave' => 0,
    'personal_leave' => 0,
];
$department_summary = [];
$message = '';

// 월별 휴가 조회
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['check_holidays'])) {
    $month = $_POST['month'];

    // 선택한 월의 승인된 휴가 내역 조회
    $getHolidaySQL = "
        SELECT e.name, e.department_id, d.department_name, hr.leave_type, hr.start_date, hr.end_date, hr.status
        FROM holiday_requests hr
        JOIN employees e ON hr.employee_id = e.employee_id
        JOIN department d ON e.department_id = d.department_id
        WHERE DATE_FORMAT(hr.start_date, '%Y-%m') = ? AND hr.status = 'Approved'
        ORDER BY hr.start_date
    ";
    $stmt = $conn->prepare($getHolidaySQL);
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $holiday_data[] = $row;

            // 휴가 일수 계산
            $start_date = strtotime($row['start_date']);
            $end_date = strtotime($row['end_date']);
            $holiday_days = ($end_date - $start_date) / (60 * 60 * 24) + 1;

            // 총 휴가 일수
            $summary['total_days'] += $holiday_days;

            // 휴가 유형별 일수 계산
            switch ($row['leave_type']) {
                case 'Annual':
                    $summary['annual_leave'] += $holiday_days;
                    break;
                case 'Sick':
                    $summary['sick_leave'] += $holiday_days;
                    break;
                case 'Personal':
                    $summary['personal_leave'] += $holiday_days;
                    break;
            }

            // 부서별 휴가 일수 계산
            $department_id = $row['department_id'];
            $department_name = $row['department_name'];
            if (!isset($department_summary[$department_id])) {
                $department_summary[$department_id] = [
                    'department_name' => $department_name,
                    'total_days' => 0,
                ];
            }
            $department_summary[$department_id]['total_days'] += $holiday_days;
        }
    } else {
        $message = "No approved holiday requests found for the selected month.";
    }

    // 부서별 평균 휴가일 계산
    foreach ($department_summary as $department_id => &$dept_summary) {
        // 부서 전체 직원 수 조회
        $getDeptEmployeeCountSQL = "
            SELECT COUNT(*) as employee_count
            FROM employees
            WHERE department_id = ?
        ";
        $stmt = $conn->prepare($getDeptEmployeeCountSQL);
        $stmt->bind_param("i", $department_id);
        $stmt->execute();
        $stmt->bind_result($employee_count);
        $stmt->fetch();
        $stmt->close();

        // 부서별 평균 휴가일 계산
        if ($employee_count > 0) {
            $dept_summary['average_days'] = round($dept_summary['total_days'] / $employee_count, 2);
        } else {
            $dept_summary['average_days'] = 0;
        }
    }
}

$conn->close();
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
        <label for="month">Select Month:</label>
        <input type="month" name="month" value="<?php echo $month; ?>" required>
        <button type="submit" name="check_holidays">Check Holidays</button>
    </form>

    <?php if ($message): ?>
        <p style="color: red;"><?php echo htmlspecialchars($message); ?></p>
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
            </tr>
            <?php foreach ($holiday_data as $holiday): ?>
                <tr>
                    <td><?php echo htmlspecialchars($holiday['name']); ?></td>
                    <td><?php echo htmlspecialchars($holiday['department_name']); ?></td>
                    <td><?php echo $holiday['leave_type']; ?></td>
                    <td><?php echo $holiday['start_date']; ?></td>
                    <td><?php echo $holiday['end_date']; ?></td>
                    <td><?php echo $holiday['status']; ?></td>
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
            <?php foreach ($department_summary as &$dept_summary): ?>
                <tr>
                    <td><?php echo htmlspecialchars($dept_summary['department_name']); ?></td>
                    <td><?php echo htmlspecialchars($dept_summary['average_days']); ?> days</td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <button onclick="window.location.href='admin_main.php'">Back to Main</button>
</body>

</html>
