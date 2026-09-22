<?php
session_start();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}
// check this employee`s department is executive
if ($_SESSION['department'] === 2) {
    header("Location: admin_main.php");
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #F0FFF0;
            color: #333;
            text-align: center;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }

        h2 {
            color: #9999FF;
        }

        .button {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background-color: #660099;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .button:hover {
            background-color: #4B0082;
        }

        .logout-button {
            display: inline-block;
            margin: 20px 0;
            padding: 10px 20px;
            background-color: #FF0000;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .logout-button:hover {
            background-color: #B22222;
        }
    </style>
</head>

<body>
    <div class="container">
        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</h1>
        <h2>Have a lovely day!</h2>
        <div>
            <a href="holiday_requests.php" class="button">Holiday Request</a>
            <a href="check_my_holiday.php" class="button">Holiday Status</a>
        </div>
        <a href="logout.php" class="logout-button">Logout</a>
        <a href="change_password.php" class="logout-button">Change Password</a>
    </div>
</body>

</html>