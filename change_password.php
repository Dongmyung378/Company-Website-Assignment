<?php
require __DIR__ . '/config/connection.php';
session_start();

$department = $_SESSION['department'];

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

$employee_id = $_SESSION['employee_id'];

$new_password_err = $confirm_password_err = $success_msg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password)) {
        $new_password_err = 'Enter New Password.';
    } elseif (strlen($new_password) < 3) {
        $new_password_err = 'Password length should be longer than 3.';
    }

    if (empty($confirm_password)) {
        $confirm_password_err = 'Enter Password again.';
    } elseif ($new_password !== $confirm_password) {
        $confirm_password_err = 'Incorret Password.';
    }

    if (empty($new_password_err) && empty($confirm_password_err)) {
        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

        $update_stmt = $conn->prepare("UPDATE login SET password_hash = ? WHERE employee_id = ?");
        $update_stmt->bind_param('si', $new_password_hash, $employee_id);

        if ($update_stmt->execute()) {
            $success_msg = 'Success and Back to main.';
        } else {
            $new_password_err = 'Please Retry.';
        }
        $update_stmt->close();
    }

    $conn->close();
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
    <style>
        .error {
            color: red;
        }

        .success {
            text-align: center;
            font-size: 40px;
            color: green;
        }

        body {
            font-family: Arial, sans-serif;
        }

        form {
            max-width: 400px;
            margin: auto;
        }

        input[type="password"] {
            width: 100%;
            padding: 8px;
            margin: 4px 0;
        }

        label {
            display: block;
            margin-top: 8px;
        }

        button {
            padding: 8px 16px;
            margin-top: 12px;
        }

        h1 {
            text-align: center;
        }
    </style>
</head>

<body>
    <button onclick="BackToPage('<?php echo $department; ?>')">Back to Main</button>
    <h1>Change Password!</h1>
    <?php if (!empty($success_msg)): ?>
        <p class="success"><?php echo htmlspecialchars($success_msg); ?></p>
        <script>
            setTimeout(function() {
                BackToPage('<?php echo $department; ?>');
            }, 1500);
        </script>
    <?php endif; ?>

    <form method="POST" action="">
        <label for="new_password">New Password:</label>
        <input type="password" name="new_password" required>
        <?php if (!empty($new_password_err)): ?>
            <span class="error"><?php echo htmlspecialchars($new_password_err); ?></span>
        <?php endif; ?>

        <label for="confirm_password">Check Password:</label>
        <input type="password" name="confirm_password" required>
        <?php if (!empty($confirm_password_err)): ?>
            <span class="error"><?php echo htmlspecialchars($confirm_password_err); ?></span>
        <?php endif; ?>

        <button type="submit">Change Password</button>
    </form>
</body>

</html>
