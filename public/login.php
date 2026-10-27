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
<?php ui_page_start('Employee operations sign-in', 'Access your employee services and account.', ['auth' => true]); ?>
<section class="auth-intro" aria-labelledby="welcome-title">
    <p class="eyebrow"><?php echo e(ui_t('Kilburnazon employee portal')); ?></p>
    <h2 id="welcome-title"><?php echo e(ui_t('Employee operations')); ?></h2>
    <p><?php echo e(ui_t('Access your leave balance, requests and account settings in one workspace.')); ?></p>
    <div class="summary-row"><span>01</span><div><strong><?php echo e(ui_t('Leave balances')); ?></strong><p><?php echo e(ui_t('Check available annual, sick and personal leave.')); ?></p></div></div>
    <div class="summary-row"><span>02</span><div><strong><?php echo e(ui_t('Request tracking')); ?></strong><p><?php echo e(ui_t('Submit leave requests and review their status.')); ?></p></div></div>
    <div class="summary-row"><span>03</span><div><strong><?php echo e(ui_t('Account security')); ?></strong><p><?php echo e(ui_t('Change your password and manage your sign-in session.')); ?></p></div></div>
</section>
<section class="panel auth-form" aria-labelledby="login-title">
    <p class="eyebrow"><?php echo e(ui_t('Employee access')); ?></p>
    <h1 id="login-title"><?php echo e(ui_t('Employee operations sign-in')); ?></h1>
    <p class="muted"><?php echo e(ui_t('Enter your employee ID and password to continue.')); ?></p>
    <?php if ($error !== ''): ?><?php ui_notice($error, 'error'); ?><?php endif; ?>
    <form method="POST" action="login.php" class="form-grid">
        <?php echo csrf_field(); ?>
        <div class="field field-full">
            <label for="employee_id"><?php echo e(ui_t('Employee ID')); ?></label>
            <input type="text" id="employee_id" name="employee_id" placeholder="<?php echo e(ui_t('Enter your employee ID')); ?>" value="<?php echo e($employee_id); ?>" inputmode="numeric" autocomplete="username" maxlength="10" required>
        </div>
        <div class="field field-full">
            <label for="password"><?php echo e(ui_t('Password')); ?></label>
            <div class="password-field"><input type="password" id="password" name="password" placeholder="<?php echo e(ui_t('Enter your password')); ?>" autocomplete="current-password" required></div>
        </div>
        <div class="form-actions field-full"><button type="submit" class="button"><?php echo e(ui_t('Sign in')); ?></button></div>
    </form>
    <div class="auth-links"><p class="field-hint"><?php echo e(ui_t('For your first sign-in, use the temporary password provided by your administrator. You must then choose a new passphrase.')); ?></p><p class="field-hint"><?php echo e(ui_t('For sign-in assistance, contact your administrator.')); ?></p></div>
</section>
<?php ui_page_end(); ?>
