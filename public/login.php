<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';

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
<?php ui_page_start('Employee Login', 'Your people, connected.', ['auth' => true]); ?>
<section class="auth-intro" aria-labelledby="welcome-title">
    <p class="eyebrow">Your Kilburnazon workspace</p>
    <h2 id="welcome-title">A better day<br>starts here.</h2>
    <p>A clear view of your time off, your requests and your everyday employee essentials.</p>
    <div class="summary-row"><span>01</span><div><strong>Your balance, at a glance</strong><p>See your available days before planning time away.</p></div></div>
    <div class="summary-row"><span>02</span><div><strong>Every request, easy to follow</strong><p>Track your leave from submission to a decision.</p></div></div>
    <div class="summary-row"><span>03</span><div><strong>Your account, in your hands</strong><p>Manage your password and sign out when you are done.</p></div></div>
</section>
<section class="panel auth-form" aria-labelledby="login-title">
    <p class="eyebrow">Welcome back</p>
    <h1 id="login-title">Sign in to your workspace</h1>
    <p class="muted">Enter your employee ID and password to continue.</p>
    <?php if ($error !== ''): ?>
        <?php ui_notice($error, 'error'); ?>
    <?php endif; ?>
    <form method="POST" action="login.php" class="form-grid">
        <?php echo csrf_field(); ?>
        <div class="field field-full">
            <label for="employee_id">Employee ID</label>
            <input type="text" id="employee_id" name="employee_id" placeholder="Enter your employee ID" value="<?php echo e($employee_id); ?>" inputmode="numeric" autocomplete="username" maxlength="10" required>
        </div>
        <div class="field field-full">
            <label for="password">Password</label>
            <div class="password-field"><input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required></div>
        </div>
        <div class="form-actions field-full"><button type="submit" class="button">Sign in</button></div>
    </form>
    <div class="auth-links"><p class="field-hint">First time here? Use the temporary password provided by your administrator. You will be asked to choose a new passphrase.</p><p class="field-hint">Need help signing in? Contact your administrator.</p></div>
</section>
<?php ui_page_end(); ?>
