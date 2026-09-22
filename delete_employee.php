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

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = intval($_POST['employee_id']);
    $deleted_by_id = $_SESSION['employee_id'];
    $deleted_by_name = $_SESSION['name'];
    $deleted_reason = $_POST['deleted_reason'] ?? 'No reason provided';

    try {
        $conn->begin_transaction();

        $callProcedureSQL = "CALL delete_employee_with_log(?, ?, ?, ?)";
        $stmt = $conn->prepare($callProcedureSQL);
        $stmt->bind_param("iiss", $employee_id, $deleted_by_id, $deleted_by_name, $deleted_reason);

        if ($stmt->execute()) {
            $conn->commit();

            echo "<script>
                alert('Employee deleted and log saved successfully.');
                window.location.href = 'admin_main.php';
            </script>";
            exit();
        } else {
            throw new Exception("Failed to execute procedure: " . $stmt->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        echo $message;
    } finally {
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="stylesheet" href="assets/css/delete-employee.css">
</head>

<body>
    <div class="form-container">
        <a href="admin_main.php" class="back-button">Back to Main</a>
        <a href="delete_records.php" class="enter-button">Check deleted employees</a>
        <h1>Delete Employee</h1>
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="employee_id">Employee ID:</label>
                    <input type="number" id="employee_id" name="employee_id" placeholder="Enter Employee ID" required>
                </div>
                <div class="form-group">
                    <label for="deleted_reason">Reason for Deletion:</label>
                    <input type="text" id="deleted_reason" name="deleted_reason" placeholder="Enter Reason">
                </div>
            </div>
            <button type="submit">Delete Employee</button>
        </form>
        <?php if (!empty($message)): ?>
            <script>
                alert("<?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>");
            </script>
        <?php endif; ?>
    </div>
</body>

</html>
