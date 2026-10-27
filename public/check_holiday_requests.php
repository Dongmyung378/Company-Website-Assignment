<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
$current = require_admin();
$message = $_SESSION['holiday_decision_message'] ?? '';
unset($_SESSION['holiday_decision_message']);
$leaveColumns = ['Annual' => 'annual_leave', 'Sick' => 'sick_leave', 'Personal' => 'personal_leave'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        $requestId = business_id($_POST, 'request_id', 'Request ID');
        $decision = input_string($_POST, 'status');
        if (!in_array($decision, ['Approved', 'Rejected'], true)) {
            throw new WorkflowException('Select Approve or Reject.');
        }
        $conn->begin_transaction();
        $transaction = true;
        $stmt = $conn->prepare('SELECT employee_id, leave_type, start_date, end_date, status FROM holiday_requests WHERE request_id = ? FOR UPDATE');
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$request) {
            throw new WorkflowException('Holiday request not found.', 404);
        }
        if ($request['status'] !== 'Pending') {
            throw new WorkflowException('This request has already been processed. Refresh the list.', 409);
        }
        if ($decision === 'Approved') {
            $column = $leaveColumns[$request['leave_type']] ?? null;
            if (!$column || !valid_date($request['start_date']) || !valid_date($request['end_date'])
                || $request['start_date'] < '1000-01-01' || $request['end_date'] < $request['start_date']) {
                throw new WorkflowException('This request contains invalid leave data and cannot be approved.', 409);
            }
            $days = calendar_days($request['start_date'], $request['end_date']);
            $employeeId = (int) $request['employee_id'];
            // Only fixed column names from the allowlist above enter SQL.
            $stmt = $conn->prepare("UPDATE holiday_balance SET $column = $column - ? WHERE employee_id = ? AND $column >= ?");
            $stmt->bind_param('iii', $days, $employeeId, $days);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new WorkflowException('The employee has insufficient leave balance. The request remains pending.', 409);
            }
            $stmt->close();
        }
        $stmt = $conn->prepare("UPDATE holiday_requests SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE request_id = ? AND status = 'Pending'");
        $stmt->bind_param('si', $decision, $requestId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new WorkflowException('This request was processed by another administrator.', 409);
        }
        $stmt->close();
        $conn->commit();
        $transaction = false;
        $_SESSION['holiday_decision_message'] = 'Holiday request updated successfully.';
        header('Location: check_holiday_requests.php');
        exit();
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to update the holiday request.');
    }
}
$result = $conn->query("SELECT hr.request_id, hr.employee_id, e.name, hr.leave_type, hr.start_date, hr.end_date, hr.reason, hr.status FROM holiday_requests hr JOIN employees e ON hr.employee_id = e.employee_id WHERE hr.status = 'Pending' ORDER BY hr.start_date, hr.request_id");
?>
<!DOCTYPE html>
<html lang="en">

<body>
    <h2>Holiday Requests</h2>

    <?php if ($message): ?>
        <p style="color: red;"><?php echo e($message); ?></p>
    <?php endif; ?>

    <?php if ($result && $result->num_rows > 0): ?>
        <table>
            <tr>
                <th>Request ID</th>
                <th>Employee ID</th>
                <th>Name</th>
                <th>Leave Type</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php while ($row = $result->fetch_assoc()): ?>
                <?php
                $request_id = $row['request_id'];
                $employee_id = $row['employee_id'];
                $leave_type = $row['leave_type'];
                ?>
                <tr>
                    <td><?php echo e($request_id); ?></td>
                    <td><?php echo e($employee_id); ?></td>
                    <td><?php echo e($row['name']); ?></td>
                    <td><?php echo e($leave_type); ?></td>
                    <td><?php echo e($row['start_date']); ?></td>
                    <td><?php echo e($row['end_date']); ?></td>
                    <td><?php echo e($row['reason']); ?></td>
                    <td><?php echo e($row['status']); ?></td>
                    <td>
                        <form method="POST" action="">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="request_id" value="<?php echo e($request_id); ?>">
                            <select name="status" required>
                                <option value="Approved">Approve</option>
                                <option value="Rejected">Reject</option>
                            </select>
                            <button type="submit" name="update_status">Update Status</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
        </table>
    <?php else: ?>
        <p>No pending holiday requests.</p>
    <?php endif; ?>
    <button onclick="window.location.href='admin_main.php'">Back to Main</button>

</body>

</html>
