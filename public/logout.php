<?php
require_once __DIR__ . '/../config/bootstrap.php';
$current = require_login(true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    require_csrf();
    company_destroy_session();
    header('Location: login.php');
    exit();
}
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST');
    abort_request(405, 'Unsupported request method.');
}
$backUrl = $current['must_change_password'] ? 'change_password.php' : ($current['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php');
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Sign out</title></head>
<body>
    <h1>Sign out</h1>
    <p>Sign out of your employee account?</p>
    <form method="POST" action="logout.php">
        <?php echo csrf_field(); ?>
        <button type="submit">Sign out</button>
        <a href="<?php echo e($backUrl); ?>">Back</a>
    </form>
</body>
</html>
