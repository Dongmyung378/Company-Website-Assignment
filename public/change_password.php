<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_login(true);
$backUrl = $current['department_name'] === 'Executive' ? 'admin_main.php' : 'main.php';
$current_password_err = $new_password_err = $confirm_password_err = $success_msg = '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    abort_request(405, 'Unsupported request method.');
}
if ($method === 'POST') {
    require_csrf();
    $currentPassword = input_string($_POST, 'current_password');
    $newPassword = input_string($_POST, 'new_password');
    $confirmation = input_string($_POST, 'confirm_password');
    $new_password_err = password_validation_error($newPassword);
    if ($newPassword !== $confirmation) {
        $confirm_password_err = 'The new passwords do not match.';
    }
    if ($currentPassword === '') {
        $current_password_err = 'Enter your current password or temporary password.';
    } elseif ($newPassword === $currentPassword) {
        $new_password_err = 'Choose a different password from your current password.';
    }
    if ($current_password_err === '' && $new_password_err === '' && $confirm_password_err === '') {
        $rateLimited = false;
        $verified = company_authenticate((string) $current['employee_id'], $currentPassword, $rateLimited);
        if ($verified === null) {
            $current_password_err = 'Unable to verify your current password. Check your details or try again later.';
            if ($rateLimited) {
                http_response_code(429);
                header('Retry-After: 900');
            }
        } elseif ((int) $verified['auth_version'] !== (int) $current['auth_version']) {
            company_login_redirect();
        } else {
            $stmt = $conn->prepare('UPDATE login SET password_hash=?,must_change_password=0,auth_version=auth_version+1 WHERE employee_id=? AND auth_version=? AND password_hash=?');
            $stmt->execute([company_hash_password($newPassword), $current['employee_id'], $current['auth_version'], $verified['password_hash']]);
            if ($stmt->affected_rows !== 1) {
                $stmt->close();
                company_login_redirect();
            }
            $stmt->close();
            $current['auth_version']++;
            $current['must_change_password'] = 0;
            company_establish_session($current);
            $success_msg = 'Your password has been changed. Other signed-in sessions have been revoked.';
        }
    }
    if ($success_msg === '' && http_response_code() !== 429) {
        http_response_code(422);
    }
}
?>
<?php ui_page_start('Change password', 'Keep your employee account secure with a personal passphrase.', ['current' => $current]); ?>
<div class="grid-2">
    <section class="panel" aria-labelledby="password-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Account security')); ?></p><h2 id="password-title"><?php echo e(ui_t('Choose a new passphrase')); ?></h2></div><span class="badge badge-neutral"><?php echo e(ui_t('Employee {id}', ['id' => $current['employee_id']])); ?></span></div>
        <?php if ($current['must_change_password']): ?>
            <?php ui_notice('Choose a new passphrase before continuing. Use your temporary password as the current password.', 'info'); ?>
        <?php endif; ?>
        <?php if ($success_msg !== ''): ?>
            <?php ui_notice($success_msg, 'success'); ?>
            <p><a class="button" href="<?php echo e($backUrl); ?>"><?php echo e(ui_t('Continue to workspace')); ?></a></p>
            <script>
                setTimeout(function() {
                    window.location.href = <?php echo json_encode($backUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                }, 1500);
            </script>
        <?php else: ?>
            <?php if ($current_password_err !== '' || $new_password_err !== '' || $confirm_password_err !== ''): ?>
                <?php ui_notice('Your password could not be changed. Review the highlighted fields below.', 'error'); ?>
            <?php endif; ?>
            <form method="POST" action="" class="form-grid">
                <?php echo csrf_field(); ?>
                <div class="field field-full">
                    <label for="current_password"><?php echo e(ui_t('Current password')); ?></label>
                    <div class="password-field"><input type="password" id="current_password" name="current_password" autocomplete="current-password"<?php echo $current_password_err !== '' ? ' aria-invalid="true" aria-describedby="current-password-error"' : ''; ?> required></div>
                    <?php if ($current_password_err !== ''): ?><p class="field-hint" id="current-password-error" role="alert"><?php echo e(ui_t($current_password_err)); ?></p><?php endif; ?>
                </div>
                <div class="field field-full">
                    <label for="new_password"><?php echo e(ui_t('New passphrase')); ?></label>
                    <div class="password-field"><input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="15" aria-describedby="new-password-hint<?php echo $new_password_err !== '' ? ' new-password-error' : ''; ?>"<?php echo $new_password_err !== '' ? ' aria-invalid="true"' : ''; ?> required></div>
                    <p class="field-hint" id="new-password-hint"><?php echo e(ui_t('Use at least 15 characters and choose a passphrase different from your current password.')); ?></p>
                    <?php if ($new_password_err !== ''): ?><p class="field-hint" id="new-password-error" role="alert"><?php echo e(ui_t($new_password_err)); ?></p><?php endif; ?>
                </div>
                <div class="field field-full">
                    <label for="confirm_password"><?php echo e(ui_t('Confirm new passphrase')); ?></label>
                    <div class="password-field"><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="15"<?php echo $confirm_password_err !== '' ? ' aria-invalid="true" aria-describedby="confirm-password-error"' : ''; ?> required></div>
                    <?php if ($confirm_password_err !== ''): ?><p class="field-hint" id="confirm-password-error" role="alert"><?php echo e(ui_t($confirm_password_err)); ?></p><?php endif; ?>
                </div>
                <div class="form-actions field-full"><button type="submit" class="button"><?php echo e(ui_t('Update password')); ?></button><?php if (!$current['must_change_password']): ?><a class="button button-secondary" href="<?php echo e($backUrl); ?>"><?php echo e(ui_t('Cancel')); ?></a><?php endif; ?></div>
            </form>
        <?php endif; ?>
    </section>
    <aside class="panel" aria-labelledby="passphrase-help-title">
        <p class="eyebrow"><?php echo e(ui_t('Passphrase guidance')); ?></p><h2 id="passphrase-help-title"><?php echo e(ui_t('Create a secure passphrase')); ?></h2>
        <p><?php echo e(ui_t('Use a long passphrase that is unique to this account. Several unrelated words can be easier to remember.')); ?></p>
        <p class="muted"><?php echo e(ui_t('After a successful change, your other signed-in sessions are revoked.')); ?></p>
        <a class="button button-quiet" href="logout.php"><?php echo e(ui_t('Sign out of this account')); ?></a>
    </aside>
</div>
<?php ui_page_end(); ?>
