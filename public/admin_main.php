<?php
require __DIR__ . '/../config/connection.php';
session_start();

// Check login
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}
// check this employee`s department is executive
if ($_SESSION['department'] !== 2) {
    header("Location: main.php");
    exit();
}

$sql = "SELECT employees.name
        FROM holiday_requests
        JOIN employees ON holiday_requests.employee_id = employees.employee_id
        WHERE holiday_requests.status = 'Pending'";

$result = $conn->query($sql);

$alertMessage = "";
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $alertMessage .=  "Have a holiday request.";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        const alertMessage = "<?php echo $alertMessage; ?>";
        if (alertMessage) {
            alert(alertMessage);
        }
    </script>
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
            color: #FFD700;
        }

        .button3 {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background-color: #353535;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .button2 {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background-color: #0000CD;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .button1 {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background-color: #8A2BE2;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .button {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background-color: #428af5;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
            transition: background-color 0.2s;
        }

        .button3:hover {
            background-color: #000000;
        }

        .button2:hover {
            background-color: #191970;
        }

        .button1:hover {
            background-color: #4B0082;
        }

        .button:hover {
            background-color: #224e8f;
        }

        /* logout and chang password */
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
        <h2>Employee Management System</h2>
        <div>
            <a href="add_employee.php" class="button3">Add Employee</a>
            <a href="list_employees.php" class="button3">View Employees</a>
            <a href="update_employee.php" class="button3">Update Employee</a>
            <a href="delete_employee.php" class="button3">Delete Employee</a>
        </div>
        <div>
            <a href="check_holiday_requests.php" class="button2">Check Holiday</a>
            <a href="check_absence.php" class="button2">Check Absence</a>
            <a href="salary_table.php" class="button2">Check Salary</a>
        </div>
        <div>
            <a href="holiday_requests.php" class="button1">Holiday Request</a>
            <a href="check_my_holiday.php" class="button1">Holiday Status</a>
        </div>
        <div>
            <a href="happybirthday.php" class="button">Birthday Card</a>
        </div>
        <a href="logout.php" class="logout-button">Logout</a>
        <a href="change_password.php" class="logout-button">Change Password</a>
    </div>
</body>

</html>
