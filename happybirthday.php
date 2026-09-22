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

$today = date('Y-m-d');
$currentMonth = date('m');
$currentDay = date('d');
$passed_birthdays = [];
$upcoming_birthdays = [];
$message = '';

try {
    $query = "
        SELECT e.employee_id, e.name, e.department_id, d.department_name, e.dob,
                DATE_FORMAT(e.dob, '%d') AS dob_day,
                DATE_FORMAT(e.dob, '%d %b') AS formatted_dob
        FROM employees e
        JOIN department d ON e.department_id = d.department_id
        WHERE MONTH(e.dob) = ?
        ORDER BY dob_day ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $currentMonth);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $dobDay = intval($row['dob_day']);
            if ($dobDay < $currentDay) {
                $passed_birthdays[] = $row;
            } elseif ($dobDay >= $currentDay) {
                $upcoming_birthdays[] = $row;
            }
        }
    } else {
        $message = "No employee has birthday this month.";
    }
} catch (Exception $e) {
    $message = "error: " . $e->getMessage();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <button onclick="window.location.href='admin_main.php'">Back to Main</button>
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        table,
        th,
        td {
            border: 1px solid black;
        }

        th,
        td {
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #f2f2f2;
        }
    </style>
</head>

<body>
    <h1>Birthday List In This Month</h1>

    <?php if (!empty($message)) : ?>
        <p><?php echo $message; ?></p>
    <?php endif; ?>

    <h2>Passed birthdays in this month</h2>
    <?php if (!empty($passed_birthdays)) : ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Birthday</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($passed_birthdays as $employee) : ?>
                    <tr>
                        <td><?php echo htmlspecialchars($employee['employee_id']); ?></td>
                        <td><?php echo htmlspecialchars($employee['name']); ?></td>
                        <td><?php echo htmlspecialchars($employee['department_name']); ?></td>
                        <td><?php echo htmlspecialchars($employee['formatted_dob']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p>There is no passing birthdays.</p>
    <?php endif; ?>

    <h2>Upcoming birthdays</h2>
    <?php if (!empty($upcoming_birthdays)) : ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Birthday</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming_birthdays as $employee) : ?>
                    <tr>
                        <td><?php echo htmlspecialchars($employee['employee_id']); ?></td>
                        <td><?php echo htmlspecialchars($employee['name']); ?></td>
                        <td><?php echo htmlspecialchars($employee['department_name']); ?></td>
                        <td><?php echo htmlspecialchars($employee['formatted_dob']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p>There is no upcoming birthdays in this month.</p>
    <?php endif; ?>
</body>

</html>
