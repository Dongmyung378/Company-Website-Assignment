<?php
require __DIR__ . '/config/connection.php';
session_start();

$error = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $employee_id = trim($_POST['employee_id']);
    $password = trim($_POST['password']);

    if (!empty($employee_id) && !empty($password)) {
        $sql = "SELECT e.employee_id, e.department_id, e.name, l.password_hash 
                FROM employees e 
                INNER JOIN login l ON e.employee_id = l.employee_id 
                WHERE e.employee_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $stmt->bind_result($id, $department, $name, $password_hash);
        $stmt->fetch();

        if ($id && password_verify($password, $password_hash)) {
            $_SESSION['logged_in'] = true;
            $_SESSION['employee_id'] = $id;
            $_SESSION['name'] = $name;
            $_SESSION['department'] = $department;

            if ($department === 2) {
                header("Location: admin_main.php");
                exit();
            } else {
                header("Location: main.php");
                exit();
            }
        } else {
            $error = "Invalid Employee ID or Password. Please try again.";
        }

        $stmt->close();
    } else {
        $error = "Please enter both Employee ID and Password.";
    }
}

$conn->close();
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
            <input type="text" name="employee_id" placeholder="Enter Employee ID" required>
            <input type="password" name="password" placeholder="Enter Password" required>
            <button type="submit" class="button">Login</button>
        </form>
        <?php if (isset($error) && !empty($error)): ?>
            <p class="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
    </div>
</body>

</html>
