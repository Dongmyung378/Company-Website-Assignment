<?php
session_start();
require __DIR__ . '/../config/connection.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$department = $_SESSION['department'];

error_reporting(E_ALL);
ini_set('display_errors', 1);

$employee_id = '';
$employee_data = null;
$holiday_balance = null;
$message = '';

$employee_id = $_SESSION['employee_id'];

$getEmployeeSQL = "SELECT * FROM employees WHERE employee_id = ?";
$stmt = $conn->prepare($getEmployeeSQL);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $employee_data = $result->fetch_assoc();

    $getBalanceSQL = "SELECT * FROM holiday_balance WHERE employee_id = ?";
    $stmt->close();

    $stmt = $conn->prepare($getBalanceSQL);
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $balance_result = $stmt->get_result();
    $holiday_balance = $balance_result->fetch_assoc();
    $_SESSION['holiday_balance'] = $holiday_balance;
}
$stmt->close();


if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_holiday'])) {
    $employee_id = $_POST['employee_id'];
    $leave_type = $_POST['leave_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $reason = $_POST['reason'] ?? '';

    $holiday_balance = $_SESSION['holiday_balance'] ?? null;
    if (strtotime($end_date) < strtotime($start_date)) {
        $message = "End date cannot be earlier than start date.";
    } else {
        $date_diff = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
        $leave_column = '';
        switch ($leave_type) {
            case 'Annual':
                $leave_column = 'annual_leave';
                break;
            case 'Sick':
                $leave_column = 'sick_leave';
                break;
            case 'Personal':
                $leave_column = 'personal_leave';
                break;
        }

        if ($holiday_balance[$leave_column] < $date_diff) {
            $message = "Insufficient $leave_type leave balance.";
        } else {
            $insertRequestSQL = "
                INSERT INTO holiday_requests (employee_id, leave_type, start_date, end_date, reason, status)
                VALUES (?, ?, ?, ?, ?, 'Pending')
            ";
            $stmt = $conn->prepare($insertRequestSQL);
            $stmt->bind_param("issss", $employee_id, $leave_type, $start_date, $end_date, $reason);

            if ($stmt->execute()) {
                echo "<script>
                    alert('Holiday request submitted successfully. Waiting for approval.');
                    window.location.href = 'main.php';
                </script>";
                exit();
            } else {
                $message = "Error submitting holiday request. Please try again.";
            }

            $stmt->close();
        }
    }
}
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
</head>

<body>
    <h1>Holiday Request Form</h1>

    <?php if ($message): ?>
        <p style="color: red;"><?php echo $message; ?></p>
    <?php endif; ?>

    <?php if ($employee_data): ?>
        <h3>Employee Information</h3>
        <p>Name: <?php echo htmlspecialchars($employee_data['name']); ?></p>

        <h3>Holiday Balance</h3>
        <ul>
            <li>Annual Leave: <?php echo $holiday_balance['annual_leave']; ?> days</li>
            <li>Sick Leave: <?php echo $holiday_balance['sick_leave']; ?> days</li>
            <li>Personal Leave: <?php echo $holiday_balance['personal_leave']; ?> days</li>
        </ul>

        <h3>Request Holiday</h3>
        <form method="POST" action="">
            <input type="hidden" name="employee_id" value="<?php echo $employee_data['employee_id']; ?>">

            <label for="leave_type">Leave Type:</label>
            <select name="leave_type" required>
                <option value="Annual">Annual Leave</option>
                <option value="Sick">Sick Leave</option>
                <option value="Personal">Personal Leave</option>
            </select>

            <label for="start_date">Start Date:</label>
            <input type="date" name="start_date" required>

            <label for="end_date">End Date:</label>
            <input type="date" name="end_date" required>

            <label for="reason">Reason (Optional):</label>
            <textarea name="reason"></textarea>

            <button type="submit" name="request_holiday">Submit Request</button>
        </form>
    <?php endif; ?>
    <div class="buttons">
        <button onclick="BackToPage('<?php echo $department; ?>')">Back to Main</button>
    </div>
</body>

</html>
