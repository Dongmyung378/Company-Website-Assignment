<?php
require_once __DIR__ . '/../config/bootstrap.php';

$error = '';
$employee_id = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    abort_request(405, 'Unsupported request method.');
}
if (($_SESSION['logged_in'] ?? false) === true) {
    $current = require_login(true);
    header('Location: ' . ($current['must_change_password'] ? 'change_password.php' : ($current['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php')));
    exit();
}
if ($method === 'POST') {
    require_csrf();
    $employee_id = trim(input_string($_POST, 'employee_id'));
    $password = input_string($_POST, 'password');
    $rateLimited = false;
    $employee = company_authenticate($employee_id, $password, $rateLimited);
    if ($employee !== null) {
        if (password_needs_rehash($employee['password_hash'], company_password_algorithm())) {
            $stmt = $conn->prepare('UPDATE login SET password_hash=? WHERE employee_id=? AND password_hash=?');
            $stmt->execute([company_hash_password($password), $employee['employee_id'], $employee['password_hash']]);
            $stmt->close();
        }
        company_establish_session($employee);
        header('Location: ' . ((int) $employee['must_change_password'] === 1 ? 'change_password.php' : ($employee['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php')));
        exit();
    }
    if ($rateLimited) {
        http_response_code(429);
        header('Retry-After: 900');
    }
    $error = 'Unable to sign in. Check your details or try again later.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: url('assets/images/company-illustration.jpg') no-repeat left center fixed;
            background-size: contain;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            height: 100vh;
            margin: 0;
            padding-right: 120px;
        }

        .login-container {
            background: #660099;
            padding: 30px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            text-align: center;
            border: 2px solid #000000;
        }

        h2 {
            margin-bottom: 20px;
            color: #FFFF00;
            font-size: 1.5em;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1em;
            box-sizing: border-box;
        }

        .button {
            width: 100%;
            padding: 12px 15px;
            background-color: #FFFF00;
            color: #660099;
            border: none;
            border-radius: 8px;
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .button:hover {
            background-color: #808000;
        }

        .error {
            color: #FF0000;
            font-size: 0.9em;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>Employee Login</h2>
        <form method="POST" action="login.php">
            <?php echo csrf_field(); ?>
            <input type="text" name="employee_id" placeholder="Enter Employee ID" value="<?php echo e($employee_id); ?>" inputmode="numeric" autocomplete="username" maxlength="10" required>
            <input type="password" name="password" placeholder="Enter Password" autocomplete="current-password" required>
            <button type="submit" class="button">Login</button>
        </form>
        <?php if (isset($error) && !empty($error)): ?>
            <p class="error"><?php echo e($error); ?></p>
        <?php endif; ?>
    </div>
</body>

</html>
