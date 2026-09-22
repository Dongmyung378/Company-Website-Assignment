<?php
require __DIR__ . '/config/connection.php';
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

$employee_id = isset($_GET['employee_id']) ? trim($_GET['employee_id']) : '';
$department = isset($_GET['department']) ? trim($_GET['department']) : '';
$position = isset($_GET['position']) ? trim($_GET['position']) : '';
$office = isset($_GET['office']) ? trim($_GET['office']) : '';
$hired_date = isset($_GET['hired_date']) ? trim($_GET['hired_date']) : '';

function fetchDistinctValues($conn, $column, $table)
{
    $sql = "SELECT DISTINCT `$column` FROM `$table` ORDER BY `$column` ASC";
    $result = $conn->query($sql);
    $values = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $value = $row[$column];
            if (!empty($value)) {
                $values[] = htmlspecialchars($value);
            }
        }
    }
    return $values;
}

$departments = fetchDistinctValues($conn, 'department_name', 'department');
$positions = fetchDistinctValues($conn, 'position_name', 'position');
$offices = fetchDistinctValues($conn, 'office_name', 'office');

$sql = "
    SELECT DISTINCT
        e.employee_id,
        e.name,
        e.salary,
        e.email,
        e.dob,
        e.home_address,
        e.hired_date,
        e.contract,
        e.nin,
        d.department_name,
        o.office_name,
        p.position_name
    FROM 
        employees e
    JOIN 
        department d ON e.department_id = d.department_id
    JOIN 
        office o ON e.office_id = o.office_id
    JOIN 
        position p ON e.position_id = p.position_id
";

$conditions = [];
$params = [];
$types = '';

if ($employee_id !== '') {
    $conditions[] = "e.employee_id = ?";
    $params[] = $employee_id;
    $types .= 'i';
}

if ($department !== '') {
    $conditions[] = "d.department_name = ?";
    $params[] = $department;
    $types .= 's';
}

if ($position !== '') {
    $conditions[] = "p.position_name = ?";
    $params[] = $position;
    $types .= 's';
}

if ($office !== '') {
    $conditions[] = "o.office_name = ?";
    $params[] = $office;
    $types .= 's';
}

if ($hired_date !== '') {
    $conditions[] = "e.hired_date >= ?";
    $params[] = $hired_date;
    $types .= 's';
}

if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= "
    GROUP BY e.employee_id
    ORDER BY e.hired_date ASC
";
$stmt = $conn->prepare($sql);

if ($stmt) {
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    die("Failed to prepare statement: " . $conn->error);
}

$stmt->close();
$conn->close();
?>

<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="assets/css/employee-list.css">
    <button class="back-button" onclick="window.location.href='admin_main.php'">Back to Main</button>
</head>

<body>
    <h1>Employee Directory</h1>

    <form id="search-form" method="GET" action="">
        <div class="form-row">
            <div class="form-group">
                <label for="employee_id">Employee ID:</label>
                <input type="text" name="employee_id" placeholder="Search by Employee ID" value="<?php echo htmlspecialchars($employee_id); ?>">
            </div>
        </div>

        <select name="department">
            <option value="">All Departments</option>
            <?php foreach ($departments as $dept): ?>
                <option value="<?php echo $dept; ?>" <?php if ($dept === $department) echo 'selected'; ?>>
                    <?php echo $dept; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="position">
            <option value="">All Positions</option>
            <?php foreach ($positions as $pos): ?>
                <option value="<?php echo $pos; ?>" <?php if ($pos === $position) echo 'selected'; ?>>
                    <?php echo $pos; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="office">
            <option value="">All Offices</option>
            <?php foreach ($offices as $off): ?>
                <option value="<?php echo $off; ?>" <?php if ($off === $office) echo 'selected'; ?>>
                    <?php echo $off; ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="hired_date" value="<?php echo htmlspecialchars($hired_date); ?>">

        <button type="submit">Search</button>
        <button type="button" onclick="window.location.href='<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>'">Reset</button>
    </form>

    <div id="employee-list">
        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <a href="employee_detail.php?id=<?php echo $row['employee_id']; ?>" class="card">
                    <img src="assets/images/default-avatar.jpg" alt="Photo of <?php echo htmlspecialchars($row['name']); ?>">
                    <h3><?php echo htmlspecialchars($row['name']); ?></h3>
                    <p><strong>Department:</strong> <?php echo htmlspecialchars($row['department_name']); ?></p>
                    <p><strong>Position:</strong> <?php echo htmlspecialchars($row['position_name']); ?></p>
                    <p><strong>Office:</strong> <?php echo htmlspecialchars($row['office_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($row['email']); ?></p>
                    <p><strong>Start Date:</strong> <?php echo htmlspecialchars($row['hired_date']); ?></p>
                </a>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No employees found.</p>
        <?php endif; ?>
    </div>
</body>

</html>
