<?php
declare(strict_types=1);
require_once __DIR__ . '/i18n.php';

/** Presentation helpers only. Authorization stays in each existing page controller. */
function ui_icon(string $name): string
{
    $paths = [
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'people' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.87M15 3.13a4 4 0 0 1 0 7.75"/><circle cx="9" cy="7" r="4"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18m-13 5h3m3 0h2"/>',
        'review' => '<path d="M9 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-4"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6"/>',
        'report' => '<path d="M3 3v18h18M7 16v-4m5 4V7m5 9v-6"/>',
        'payroll' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20m-15 5h3"/>',
        'birthday' => '<path d="M3 13h18v8H3zm0 4c2-3 4 3 6 0s4 3 6 0 4 3 6 0M6 13V9m6 4V9m6 4V9M6 5v1m6-2v2m6-1v1"/>',
        'archive' => '<path d="M4 8v13h16V8M9 12h6"/><rect x="2" y="3" width="20" height="5" rx="1"/>',
        'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4m7 14 5-5-5-5m5 5H9"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];
    return '<svg class="ui-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['overview']) . '</svg>';
}

function ui_notice(string $message, string $type = 'info'): void
{
    if ($message === '') return;
    $type = in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info';
    $role = $type === 'error' ? 'alert' : 'status';
    echo '<div class="notice notice-' . $type . '" role="' . $role . '">' . e(ui_t($message)) . '</div>';
}

function ui_page_start(string $title, string $description = '', array $options = []): void
{
    $auth = ($options['auth'] ?? false) === true;
    $current = $options['current'] ?? null;
    $GLOBALS['_ui_auth_page'] = $auth;
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $isAdmin = ($current['department_name'] ?? '') === 'Executive';
    $forced = (bool) ($current['must_change_password'] ?? false);
    $dashboard = $isAdmin ? 'admin_main.php' : 'main.php';
    $title = ui_t($title);
    $description = ui_t($description);
    preg_match('/^\s*(\S)/u', (string) ($current['name'] ?? 'K'), $initial);
    $brand = '<span class="brand-mark" aria-hidden="true">K<span></span></span><span class="brand-name">Kilburnazon<small>' . e(ui_t('Employee operations')) . '</small></span>';
    $cssVersion = substr(hash_file('sha256', __DIR__ . '/../public/assets/css/app.css'), 0, 12);
    $jsVersion = substr(hash_file('sha256', __DIR__ . '/../public/assets/js/app.js'), 0, 12);
    ?><!DOCTYPE html>
<html lang="<?php echo ui_language(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title><?php echo e($title); ?> | Kilburnazon</title>
    <link rel="icon" href="assets/images/brand-mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css?v=<?php echo $cssVersion; ?>">
    <script type="application/json" id="ui-messages"><?php echo json_encode(ui_client_messages(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="assets/js/app.js?v=<?php echo $jsVersion; ?>" defer></script>
</head>
<body class="<?php echo $auth ? 'auth-page' : 'app-page'; ?>">
<a class="skip-link" href="#main-content"><?php echo e(ui_t('Skip to content')); ?></a>
<?php if ($auth): ?>
<header class="auth-header"><div class="brand"><?php echo $brand; ?></div><?php echo ui_language_switch(); ?></header>
<main id="main-content" class="auth-layout">
<?php else: ?>
<button class="nav-backdrop" type="button" aria-label="<?php echo e(ui_t('Close navigation')); ?>" hidden></button>
<aside class="sidebar" id="workspace-navigation" aria-label="<?php echo e(ui_t('Workspace navigation')); ?>">
    <a class="brand sidebar-brand" href="<?php echo $forced ? 'change_password.php' : $dashboard; ?>"><?php echo $brand; ?></a>
    <div class="workspace-label"><span class="workspace-dot"></span><?php echo e(ui_t($isAdmin ? 'Administration' : 'Employee workspace')); ?></div>
    <nav class="sidebar-nav">
        <?php
        $groups = [];
        if (!$forced) {
            $groups['Workspace'] = [[$dashboard, 'Overview', 'overview', [$dashboard]]];
            if ($isAdmin) {
                $groups['Workspace'][] = ['list_employees.php', 'Employees', 'people', ['list_employees.php', 'employee_detail.php', 'add_employee.php', 'update_employee.php', 'delete_employee.php']];
                $groups['Management'] = [
                    ['check_holiday_requests.php', 'Leave approvals', 'review', ['check_holiday_requests.php']],
                    ['check_absence.php', 'Absence report', 'report', ['check_absence.php']],
                    ['salary_table.php', 'Payroll', 'payroll', ['salary_table.php']],
                    ['happybirthday.php', 'Birthdays', 'birthday', ['happybirthday.php']],
                    ['delete_records.php', 'Deletion records', 'archive', ['delete_records.php']],
                ];
            }
            $groups['My workspace'] = [
                ['holiday_requests.php', 'Request leave', 'calendar', ['holiday_requests.php']],
                ['check_my_holiday.php', 'My leave history', 'review', ['check_my_holiday.php']],
            ];
        }
        $groups['Account'] = [
            ['change_password.php', 'Change password', 'lock', ['change_password.php']],
            ['logout.php', 'Sign out', 'logout', ['logout.php']],
        ];
        foreach ($groups as $label => $items): ?>
            <div class="nav-group"><p class="nav-group-label"><?php echo e(ui_t($label)); ?></p>
            <?php foreach ($items as [$href, $label, $icon, $active]): ?>
                <a class="nav-link" href="<?php echo e($href); ?>"<?php if (in_array($page, $active, true)) echo ' aria-current="page"'; ?>><?php echo ui_icon($icon); ?><span><?php echo e(ui_t($label)); ?></span></a>
            <?php endforeach; ?></div>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><span class="sidebar-footer-mark" aria-hidden="true"><?php echo e(strtoupper($initial[1] ?? 'K')); ?></span><span><strong class="sidebar-user-name" title="<?php echo e($current['name'] ?? ''); ?>"><?php echo e($current['name'] ?? ui_t('Employee')); ?></strong><small><?php echo e(ui_t($isAdmin ? 'Executive' : 'Employee')); ?></small></span></div>
</aside>
<div class="app-workspace">
    <header class="topbar">
        <div class="topbar-start"><button class="nav-toggle icon-button" type="button" aria-label="<?php echo e(ui_t('Open navigation')); ?>" aria-controls="workspace-navigation" aria-expanded="false"><?php echo ui_icon('menu'); ?></button><span class="breadcrumb"><span class="breadcrumb-root"><?php echo e(ui_t('Workspace')); ?></span><span class="breadcrumb-separator" aria-hidden="true">/</span><strong><?php echo e($title); ?></strong></span></div>
        <div class="topbar-end"><?php echo ui_language_switch(); ?><div class="user-menu"><span class="user-copy"><strong><?php echo e($current['name'] ?? ui_t('Employee')); ?></strong><small><?php echo e(ui_t($isAdmin ? 'Executive' : 'Employee')); ?></small></span><span class="user-avatar" aria-hidden="true"><?php echo e(strtoupper($initial[1] ?? 'K')); ?></span></div></div>
    </header>
    <main id="main-content" class="page-content">
        <div class="page-heading"><div><p class="eyebrow"><?php echo e(ui_t($isAdmin ? 'People Operations' : 'My workspace')); ?></p><h1><?php echo e($title); ?></h1><?php if ($description !== ''): ?><p class="page-description"><?php echo e($description); ?></p><?php endif; ?></div><?php if (!empty($options['actions'])): ?><div class="heading-actions"><?php echo $options['actions']; ?></div><?php endif; ?></div>
<?php endif;
}

function ui_page_end(): void
{
    echo '</main>';
    if (!($GLOBALS['_ui_auth_page'] ?? false)) echo '</div>';
    echo '<div class="sr-only" id="ui-announcement" aria-live="polite"></div></body></html>';
}
