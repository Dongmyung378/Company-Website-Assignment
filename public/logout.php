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
<?php ui_page_start('Sign Out', 'Finish your session securely.', ['current' => $current]); ?>
<section class="panel" aria-labelledby="signout-title">
    <p class="eyebrow">Your account</p><h2 id="signout-title">Ready to sign out?</h2>
    <p>You are about to sign out of your employee workspace. You can sign in again whenever you need to.</p>
    <form method="POST" action="logout.php">
        <?php echo csrf_field(); ?>
        <div class="form-actions"><button type="submit" class="button">Sign out</button><a href="<?php echo e($backUrl); ?>" class="button button-secondary">Stay signed in</a></div>
    </form>
</section>
<?php ui_page_end(); ?>
