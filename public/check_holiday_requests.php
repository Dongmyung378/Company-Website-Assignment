<?php
require __DIR__ . '/../config/connection.php';
session_start();

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

$message = '';

$getRequestsSQL = "
    SELECT hr.request_id, hr.employee_id, e.name, hr.leave_type, hr.start_date, hr.end_date, hr.reason, hr.status
    FROM holiday_requests hr
    JOIN employees e ON hr.employee_id = e.employee_id
    WHERE hr.status = 'Pending'
";
$result = $conn->query($getRequestsSQL);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $request_id = $_POST['request_id'];
    $new_status = $_POST['status'];
    $leave_type = $_POST['leave_type'];
    $employee_id = $_POST['employee_id'];
    $date_diff = intval($_POST['date_diff']);

    $updateStatusSQL = "UPDATE holiday_requests SET status = ?, updated_at = NOW() WHERE request_id = ?";
    $stmt = $conn->prepare($updateStatusSQL);
    $stmt->bind_param("si", $new_status, $request_id);

    if ($stmt->execute()) {
        $message = "Holiday request updated successfully.";

        if ($new_status === 'Approved') {
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

            $updateBalanceSQL = "
                UPDATE holiday_balance
                SET $leave_column = GREATEST($leave_column - ?, 0)
                WHERE employee_id = ?
            ";
            $stmt = $conn->prepare($updateBalanceSQL);
            $stmt->bind_param("ii", $date_diff, $employee_id);
            $stmt->execute();
        }

        $stmt->close();

        echo "<script>
            alert('$message');
            window.location.href = 'check_holiday_requests.php';
        </script>";
        exit();
    } else {
        $message = "Error updating holiday request status.";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<body>
    <h2>Holiday Requests</h2>

    <?php if ($message): ?>
        <p style="color: red;"><?php echo htmlspecialchars($message); ?></p>
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
                $date_diff = (strtotime($row['end_date']) - strtotime($row['start_date'])) / (60 * 60 * 24) + 1;
                ?>
                <tr>
                    <td><?php echo $request_id; ?></td>
                    <td><?php echo $employee_id; ?></td>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo $leave_type; ?></td>
                    <td><?php echo $row['start_date']; ?></td>
                    <td><?php echo $row['end_date']; ?></td>
                    <td><?php echo htmlspecialchars($row['reason']); ?></td>
                    <td><?php echo $row['status']; ?></td>
                    <td>
                        <form method="POST" action="">
                            <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                            <input type="hidden" name="leave_type" value="<?php echo $leave_type; ?>">
                            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">
                            <input type="hidden" name="date_diff" value="<?php echo $date_diff; ?>">
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
