<?php
require __DIR__ . '/config/connection.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

if ($_SESSION['department'] !== 2) {
    header("Location: main.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$departments = $conn->query("SELECT department_id, department_name FROM department WHERE department_name != 'Executive' ORDER BY department_name ASC");
$positions = $conn->query("
    SELECT p.position_id, p.position_name
    FROM position p
    JOIN department d ON p.department_id = d.department_id
    WHERE d.department_name != 'Executive'
    ORDER BY p.position_name ASC
");

$selected_department = $_GET['department'] ?? '';
$selected_position = $_GET['position'] ?? '';
$min_amount = $_GET['min_amount'] ?? '';
$max_amount = $_GET['max_amount'] ?? '';
$view_type = $_GET['view_type'] ?? 'annual'; // default value
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
if (isset($_GET['export_csv']) && $_GET['export_csv'] == '1') {
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
    ]);

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
            $row['name'],
            $row['department_name'],
            $row['position_name'],
            number_format($base_salary),
            number_format($bonus),
            number_format($incentives),
            number_format($tax),
            number_format($insurance),
            number_format($retirement),
            number_format($net_salary)
        ]);
    }

    fclose($output);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <button onclick="window.location.href='admin_main.php'">Back to Main</button>
</head>

<body>
    <h2>Salary Report</h2>

    <form method="GET" action="">
        <label for="department">Select Department:</label>
        <select name="department">
            <option value="">All</option>
            <?php
            // pointer reset
            $departments->data_seek(0);
            while ($row = $departments->fetch_assoc()): ?>
                <option value="<?php echo $row['department_id']; ?>" <?php if ($selected_department == $row['department_id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($row['department_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label for="position">Select Position:</label>
        <select name="position">
            <option value="">All</option>
            <?php
            // pointer reset
            $positions->data_seek(0);
            while ($row = $positions->fetch_assoc()): ?>
                <option value="<?php echo $row['position_id']; ?>" <?php if ($selected_position == $row['position_id']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($row['position_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label for="min_amount">Minimum Amount:</label>
        <input type="number" step="0.01" name="min_amount" value="<?php echo htmlspecialchars($min_amount); ?>">

        <label for="max_amount">Maximum Amount:</label>
        <input type="number" step="0.01" name="max_amount" value="<?php echo htmlspecialchars($max_amount); ?>">

        <label for="view_type">View Type:</label>
        <select name="view_type">
            <option value="annual" <?php if ($view_type == 'annual') echo 'selected'; ?>>Annual</option>
            <option value="monthly" <?php if ($view_type == 'monthly') echo 'selected'; ?>>Monthly</option>
            <option value="quarterly" <?php if ($view_type == 'quarterly') echo 'selected'; ?>>Quarterly</option>
        </select>

        <button type="submit">Filter</button>
        <button type="submit" name="export_csv" value="1">Export to CSV</button>
    </form>

    <hr>

    <?php if (!empty($data)): ?>
        <h3>Filtered Results</h3>
        <ul>
            <li>Total Salary (<?php echo ucfirst($view_type); ?>): £<?php echo number_format($total_salary); ?></li>
            <li>Total Net Salary (<?php echo ucfirst($view_type); ?>): £<?php echo number_format($total_net_salary); ?></li>
            <li>Average Net Salary (<?php echo ucfirst($view_type); ?>): £<?php echo number_format($average_net_salary); ?></li>
        </ul>

        <h4>Department Totals</h4>
        <ul>
            <?php foreach ($department_totals as $department_name => $total): ?>
                <li>
                    <?php echo htmlspecialchars($department_name); ?>:
                    Total: £<?php echo number_format($total); ?>,
                    Average Net Salary: £<?php echo number_format($department_averages[$department_name]); ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <table border="1">
            <tr>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Position</th>
                <th>Base Salary (<?php echo ucfirst($view_type); ?>)</th>
                <th>Bonus</th>
                <th>Incentives</th>
                <th>Tax</th>
                <th>Insurance</th>
                <th>Retirement</th>
                <th>Net Salary (<?php echo ucfirst($view_type); ?>)</th>
            </tr>
            <?php foreach ($data as $row): ?>
                <tr>
                    <td><?php echo $row['employee_id']; ?></td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                    <td><?php echo htmlspecialchars($row['position_name']); ?></td>
                    <td>
                        £<?php
                            if ($view_type == 'annual') {
                                echo number_format($row['salary']);
                            } elseif ($view_type == 'monthly') {
                                echo number_format($row['monthly_base_salary']);
                            } elseif ($view_type == 'quarterly') {
                                echo number_format($row['quarterly_base_salary']);
                            }
                            ?>
                    </td>
                    <td>
                        £<?php
                            if ($view_type == 'annual') {
                                echo number_format($row['bonus']);
                            } elseif ($view_type == 'monthly') {
                                echo number_format($row['monthly_bonus']);
                            } elseif ($view_type == 'quarterly') {
                                echo number_format($row['quarterly_bonus']);
                            }
                            ?>
                    </td>
                    <td>
                        £<?php
                            if ($view_type == 'annual') {
                                echo number_format($row['incentives']);
                            } elseif ($view_type == 'monthly') {
                                echo number_format($row['monthly_incentives']);
                            } elseif ($view_type == 'quarterly') {
                                echo number_format($row['quarterly_incentives']);
                            }
                            ?>
                    </td>
                    <td>
                        £<?php
                            if ($view_type == 'annual') {
                                echo number_format($row['tax']);
                            } elseif ($view_type == 'monthly') {
                                echo number_format($row['monthly_tax']);
                            } elseif ($view_type == 'quarterly') {
                                echo number_format($row['quarterly_tax']);
                            }
                            ?>
                    </td>
                    <td>
                        £<?php
                            if ($view_type == 'annual') {
                                echo number_format($row['insurance']);
                            } elseif ($view_type == 'monthly') {
                                echo number_format($row['monthly_insurance']);
                            } elseif ($view_type == 'quarterly') {
                                echo number_format($row['quarterly_insurance']);
                            }
                            ?>
                    </td>
                    <td>
                        £<?php
                            if ($view_type == 'annual') {
                                echo number_format($row['retirement']);
                            } elseif ($view_type == 'monthly') {
                                echo number_format($row['monthly_retirement']);
                            } elseif ($view_type == 'quarterly') {
                                echo number_format($row['quarterly_retirement']);
                            }
                            ?>
                    </td>
                    <td>
                        <strong>
                            £<?php
                                if ($view_type == 'annual') {
                                    echo number_format($row['net_salary']);
                                } elseif ($view_type == 'monthly') {
                                    echo number_format($row['monthly_salary']);
                                } elseif ($view_type == 'quarterly') {
                                    echo number_format($row['quarterly_salary']);
                                }
                                ?>
                        </strong>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No employees found or filtered results are empty.</p>
    <?php endif; ?>
</body>

</html>
