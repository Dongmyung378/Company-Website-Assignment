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

try {
    $delete_query = "DELETE FROM delete_employee_logs WHERE deleted_time <= DATE_SUB(NOW(), INTERVAL 3 YEAR)";
    if (!$conn->query($delete_query)) {
        throw new Exception("Error deleting old records: " . $conn->error);
    }

    $query = "
        SELECT employee_id, employee_name, employee_salary, employee_department,
                employee_position, employee_dob, employee_nin, deleted_by_id, deleted_by_name,
                reason, deleted_time,
                CASE
                    WHEN deleted_time <= DATE_SUB(NOW(), INTERVAL 3 YEAR) THEN 'Yes'
                    ELSE 'No'
                END AS over_three_years
        FROM delete_employee_logs
        ORDER BY deleted_time DESC
    ";
    $result = $conn->query($query);
    if (!$result) {
        throw new Exception("Error fetching deleted employee records: " . $conn->error);
    }
} catch (Exception $e) {
    $error_message = $e->getMessage();
} finally {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f9f9f9;
            color: #333;
            margin: 20px;
        }

        h2 {
            text-align: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f4f4f4;
        }

        .no-records {
            text-align: center;
            color: #888;
            font-style: italic;
        }

        .back-button {
            display: block;
            margin: 0 auto 20px;
            padding: 10px 20px;
            text-align: center;
            color: #fff;
            background-color: #f44336;
            text-decoration: none;
            border-radius: 5px;
            width: 150px;
        }

        .back-button:hover {
            background-color: #d32f2f;
        }
    </style>
</head>

<body>
    <a href="admin_main.php" class="back-button">Back to Main</a>
    <h2>Deleted Employee Records</h2>

    <?php if (isset($error_message)): ?>
        <p class="no-records"><?php echo htmlspecialchars($error_message); ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Name</th>
                    <th>Salary</th>
                    <th>Department</th>
                    <th>Position</th>
                    <th>DOB</th>
                    <th>NIN</th>
                    <th>Executor ID</th>
                    <th>Executor Name</th>
                    <th>Reason</th>
                    <th>Deleted Date/Time</th>
                    <th>3+ Years</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['employee_id']); ?></td>
                            <td><?php echo htmlspecialchars($row['employee_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['employee_salary']); ?></td>
                            <td><?php echo htmlspecialchars($row['employee_department']); ?></td>
                            <td><?php echo htmlspecialchars($row['employee_position']); ?></td>
                            <td><?php echo htmlspecialchars($row['employee_dob']); ?></td>
                            <td><?php echo htmlspecialchars($row['employee_nin']); ?></td>
                            <td><?php echo htmlspecialchars($row['deleted_by_id']); ?></td>
                            <td><?php echo htmlspecialchars($row['deleted_by_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['reason']); ?></td>
                            <td><?php echo htmlspecialchars($row['deleted_time']); ?></td>
                            <td><?php echo htmlspecialchars($row['over_three_years']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="12" class="no-records">No deleted employee records found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>

</html>
