<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
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
<?php ui_page_start('Sign out', 'End your current employee session.', ['current' => $current]); ?>
<section class="panel" aria-labelledby="signout-title">
    <p class="eyebrow"><?php echo e(ui_t('Your account')); ?></p><h2 id="signout-title"><?php echo e(ui_t('Confirm sign out')); ?></h2>
    <p><?php echo e(ui_t('Select Sign out to end this session, or Stay signed in to return to your workspace.')); ?></p>
    <form method="POST" action="logout.php">
        <?php echo csrf_field(); ?>
        <div class="form-actions"><button type="submit" class="button"><?php echo e(ui_t('Sign out')); ?></button><a href="<?php echo e($backUrl); ?>" class="button button-secondary"><?php echo e(ui_t('Stay signed in')); ?></a></div>
    </form>
</section>
<?php ui_page_end(); ?>
