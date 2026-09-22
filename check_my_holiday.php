<?php
require __DIR__ . '/config/connection.php';
session_start();

$department = $_SESSION['department'];

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

$employee_id = '';
$holiday_requests = [];
$message = '';


$employee_id = $_SESSION['employee_id'];

if (!empty($employee_id) && is_numeric($employee_id)) {
    $getRequestsSQL = "
        SELECT request_id, leave_type, start_date, end_date, reason, status, updated_at
        FROM holiday_requests
        WHERE employee_id = ?
        ORDER BY updated_at DESC
    ";
    $stmt = $conn->prepare($getRequestsSQL);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $holiday_requests[] = $row;
        }
    } else {
        $message = "No holiday requests found for the given employee ID.";
    }

    $stmt->close();
} else {
    $message = "Invalid employee ID. Please enter a valid number.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        function BackToPage(department) {
            let url;
            switch (department) {
                case '2':
                    url = 'admin_main.php';
                    break;
                default:
                    url = 'main.php';
                    break;
            }
            window.location.href = url;
        }
    </script>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f9f9f9;
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
            color: #333333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th,
        td {
            border: 1px solid #dddddd;
            text-align: center;
            padding: 10px;
        }

        th {
            background-color: #f2f2f2;
            color: #333333;
        }

        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tr:hover {
            background-color: #f1f1f1;
        }

        .buttons {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <h2>Check Your Holiday Request Status</h2>

        <?php if ($message): ?>
            <p class="message"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if (!empty($holiday_requests)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Leave Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($holiday_requests as $request): ?>
                        <tr>
                            <td><?php echo $request['request_id']; ?></td>
                            <td><?php echo $request['leave_type']; ?></td>
                            <td><?php echo $request['start_date']; ?></td>
                            <td><?php echo $request['end_date']; ?></td>
                            <td><?php echo htmlspecialchars($request['reason'] ?: 'No reason provided'); ?></td>
                            <td><?php echo $request['status']; ?></td>
                            <td><?php echo $request['updated_at']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="buttons">
            <button onclick="BackToPage('<?php echo $department; ?>')">Back to Main</button>
        </div>
    </div>
</body>

</html>
