<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
$current = require_login();
$employee_id = (int) $current['employee_id'];
$employee_data = $current;
$backUrl = $current['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php';
$leaveColumns = ['Annual' => 'annual_leave', 'Sick' => 'sick_leave', 'Personal' => 'personal_leave'];
$message = $_SESSION['holiday_submission_message'] ?? '';
unset($_SESSION['holiday_submission_message'], $_SESSION['holiday_balance']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        if (array_key_exists('employee_id', $_POST) && business_id($_POST, 'employee_id') !== $employee_id) {
            throw new WorkflowException('You can request leave only for your own account.', 403);
        }
        $leave_type = input_string($_POST, 'leave_type');
        if (!isset($leaveColumns[$leave_type])) {
            throw new WorkflowException('Select a valid leave type.');
        }
        $start_date = business_date($_POST, 'start_date', 'Start date');
        $end_date = business_date($_POST, 'end_date', 'End date');
        if ($end_date < $start_date) {
            throw new WorkflowException('End date cannot be earlier than start date.');
        }
        $days = calendar_days($start_date, $end_date);
        $reason = business_text($_POST, 'reason', 'Reason', 65535, false);
        if (strlen($reason) > 65535) {
            throw new WorkflowException('Reason must be at most 65535 bytes.');
        }
        $conn->begin_transaction();
        $transaction = true;
        $stmt = $conn->prepare('SELECT annual_leave, sick_leave, personal_leave FROM holiday_balance WHERE employee_id = ? FOR UPDATE');
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $balance = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$balance || (int) $balance[$leaveColumns[$leave_type]] < $days) {
            throw new WorkflowException('Insufficient leave balance for the requested dates.');
        }
        $stmt = $conn->prepare("INSERT INTO holiday_requests (employee_id, leave_type, start_date, end_date, reason, status) VALUES (?, ?, ?, ?, ?, 'Pending')");
        $stmt->bind_param('issss', $employee_id, $leave_type, $start_date, $end_date, $reason);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $transaction = false;
        $_SESSION['holiday_submission_message'] = 'Holiday request submitted successfully. Waiting for approval.';
        header('Location: holiday_requests.php');
        exit();
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to submit the holiday request.');
    }
}
$stmt = $conn->prepare('SELECT annual_leave, sick_leave, personal_leave FROM holiday_balance WHERE employee_id = ?');
$stmt->bind_param('i', $employee_id);
$stmt->execute();
$holiday_balance = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$holiday_balance) {
    $message = 'Your leave balance is unavailable. Please contact the administrator.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>

</head>

<body>
    <h1>Holiday Request Form</h1>

    <?php if ($message): ?>
        <p style="color: red;"><?php echo e($message); ?></p>
    <?php endif; ?>

    <?php if ($employee_data && $holiday_balance): ?>
        <h3>Employee Information</h3>
        <p>Name: <?php echo e($employee_data['name']); ?></p>

        <h3>Holiday Balance</h3>
        <ul>
            <li>Annual Leave: <?php echo e($holiday_balance['annual_leave']); ?> days</li>
            <li>Sick Leave: <?php echo e($holiday_balance['sick_leave']); ?> days</li>
            <li>Personal Leave: <?php echo e($holiday_balance['personal_leave']); ?> days</li>
        </ul>

        <h3>Request Holiday</h3>
        <form method="POST" action="">
            <?php echo csrf_field(); ?>

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
        <a href="<?php echo e($backUrl); ?>">Back to Main</a>
    </div>
</body>

</html>
