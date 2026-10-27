<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();

$q = trim(input_string($_GET, 'q'));
$employee_id = input_string($_GET, 'employee_id');
$department = input_string($_GET, 'department');
$position = input_string($_GET, 'position');
$office = input_string($_GET, 'office');
$hired_date = input_string($_GET, 'hired_date');
$pageText = input_string($_GET, 'page', '1');
if (($employee_id !== '' && (!ctype_digit($employee_id) || strlen($employee_id) > 10 || (int) $employee_id < 1 || (int) $employee_id > 2147483647))
    || !ctype_digit($pageText) || strlen($pageText) > 10 || (int) $pageText < 1 || (int) $pageText > 2147483647
    || ($hired_date !== '' && valid_date($hired_date) === null)
    || strlen($q) > 400 || strlen($department) > 400 || strlen($position) > 400 || strlen($office) > 400) {
    http_response_code(422);
    exit(ui_t('Enter valid employee filters and a positive page number.'));
}

// Only fixed identifiers are passed to this lookup; all visitor filters are bound below.
function fetchDistinctValues($conn, $column, $table)
{
    $allowed = ['department' => 'department_name', 'position' => 'position_name', 'office' => 'office_name'];
    if (($allowed[$table] ?? null) !== $column) {
        throw new InvalidArgumentException('Unsupported employee filter.');
    }
    $result = $conn->query("SELECT DISTINCT `$column` FROM `$table` ORDER BY `$column` ASC");
    $values = [];
    while ($row = $result->fetch_assoc()) {
        if ($row[$column] !== null && $row[$column] !== '') {
            $values[] = $row[$column];
        }
    }
    return $values;
}

$departments = fetchDistinctValues($conn, 'department_name', 'department');
$positions = fetchDistinctValues($conn, 'position_name', 'position');
$offices = fetchDistinctValues($conn, 'office_name', 'office');
$joins = ' FROM employees e JOIN department d ON e.department_id = d.department_id
    JOIN office o ON e.office_id = o.office_id JOIN position p ON e.position_id = p.position_id';
$conditions = [];
$params = [];
$types = '';
if ($q !== '') {
    // Treat %, _ and ! as literal search characters, regardless of SQL backslash mode.
    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    $conditions[] = "(e.name LIKE ? ESCAPE '!' OR e.email LIKE ? ESCAPE '!')";
    $params[] = $pattern;
    $params[] = $pattern;
    $types .= 'ss';
}
if ($employee_id !== '') {
    $conditions[] = 'e.employee_id = ?';
    $params[] = (int) $employee_id;
    $types .= 'i';
}
foreach (['d.department_name' => $department, 'p.position_name' => $position, 'o.office_name' => $office] as $column => $value) {
    if ($value !== '') {
        $conditions[] = $column . ' = ?';
        $params[] = $value;
        $types .= 's';
    }
}
if ($hired_date !== '') {
    $conditions[] = 'e.hired_date >= ?';
    $params[] = $hired_date;
    $types .= 's';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$stmt = $conn->prepare('SELECT COUNT(*) AS total' . $joins . $where);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$pageSize = 12;
$totalPages = max(1, (int) ceil($total / $pageSize));
$page = min((int) $pageText, $totalPages);
$offset = ($page - 1) * $pageSize;
$stmt = $conn->prepare('SELECT e.employee_id, e.name, e.email, d.department_name, o.office_name, p.position_name'
    . $joins . $where . ' ORDER BY e.name ASC, e.employee_id ASC LIMIT ? OFFSET ?');
$resultParams = array_merge($params, [$pageSize, $offset]);
$stmt->bind_param($types . 'ii', ...$resultParams);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();
$filters = array_filter(compact('q', 'employee_id', 'department', 'position', 'office', 'hired_date'), static fn ($value) => $value !== '');
$pageUrl = static fn (int $target): string => 'list_employees.php?' . http_build_query(array_merge($filters, ['page' => $target]));

ui_page_start('Employee directory', 'Search employee records by name, email, department, position, or office.', [
    'current' => $current,
    'actions' => '<a class="button" href="add_employee.php">' . ui_icon('plus') . e(ui_t('Add employee')) . '</a>',
]);
?>
<section class="panel" aria-labelledby="filter-heading">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Employee records')); ?></p><h2 id="filter-heading"><?php echo e(ui_t('Search and filter')); ?></h2></div><span class="badge badge-neutral"><?php echo e(ui_t($total === 1 ? '{count} employee' : '{count} employees', ['count' => number_format($total)])); ?></span></div>
    <form method="GET" action="list_employees.php" role="search">
        <div class="search-row">
            <div class="field"><label for="directory-q"><?php echo e(ui_t('Name or email')); ?></label><input id="directory-q" type="search" name="q" value="<?php echo e($q); ?>" maxlength="400" placeholder="<?php echo e(ui_t('Search by name or work email')); ?>" autocomplete="off"></div>
            <button class="button" type="submit"><?php echo e(ui_t('Search employees')); ?></button>
            <button class="button button-secondary" type="button" onclick="window.location.href='list_employees.php'"><?php echo e(ui_t('Reset filters')); ?></button>
        </div>
        <details class="advanced-filters"<?php echo ($employee_id !== '' || $department !== '' || $position !== '' || $office !== '' || $hired_date !== '') ? ' open' : ''; ?>>
            <summary><?php echo e(ui_t('Advanced filters')); ?></summary>
            <div class="form-grid filter-grid">
                <div class="field"><label for="directory-id"><?php echo e(ui_t('Employee ID')); ?></label><input id="directory-id" type="number" name="employee_id" min="1" max="2147483647" step="1" placeholder="<?php echo e(ui_t('Any employee ID')); ?>" value="<?php echo e($employee_id); ?>"></div>
                <div class="field"><label for="directory-department"><?php echo e(ui_t('Department')); ?></label><select id="directory-department" name="department"><option value=""><?php echo e(ui_t('All departments')); ?></option><?php foreach ($departments as $dept): ?><option value="<?php echo e($dept); ?>" <?php echo $dept === $department ? 'selected' : ''; ?>><?php echo e(ui_t($dept)); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="directory-position"><?php echo e(ui_t('Position')); ?></label><select id="directory-position" name="position"><option value=""><?php echo e(ui_t('All positions')); ?></option><?php foreach ($positions as $pos): ?><option value="<?php echo e($pos); ?>" <?php echo $pos === $position ? 'selected' : ''; ?>><?php echo e(ui_t($pos)); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="directory-office"><?php echo e(ui_t('Office')); ?></label><select id="directory-office" name="office"><option value=""><?php echo e(ui_t('All offices')); ?></option><?php foreach ($offices as $off): ?><option value="<?php echo e($off); ?>" <?php echo $off === $office ? 'selected' : ''; ?>><?php echo e(ui_t($off)); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="directory-hired"><?php echo e(ui_t('Joined on or after')); ?></label><input id="directory-hired" type="date" name="hired_date" value="<?php echo e($hired_date); ?>"></div>
            </div>
        </details>
    </form>
</section>
<div class="panel-header"><div><h2><?php echo e(ui_t('Search results')); ?></h2><p class="muted" aria-live="polite"><?php if ($total): ?><?php echo e(ui_t('Showing {first} to {last} of {count} employees', ['first' => number_format($offset + 1), 'last' => number_format(min($offset + $pageSize, $total)), 'count' => number_format($total)])); ?><?php else: ?><?php echo e(ui_t('No employees match your filters')); ?><?php endif; ?></p></div><span class="muted"><?php echo e(ui_t('Sorted by name')); ?></span></div>
<?php if ($employees): ?>
<div class="employee-grid">
    <?php foreach ($employees as $employee): ?>
    <?php preg_match('/^\s*(\S)/u', (string) $employee['name'], $initial); ?>
    <a class="employee-card" href="employee_detail.php?id=<?php echo e($employee['employee_id']); ?>" aria-label="<?php echo e(ui_t('View profile for {name}', ['name' => $employee['name']])); ?>">
        <div class="avatar" aria-hidden="true"><?php echo e(strtoupper($initial[1] ?? '?')); ?></div>
        <h3><?php echo e($employee['name']); ?></h3>
        <p><?php echo e(ui_t($employee['position_name'])); ?></p>
        <span class="badge badge-neutral"><?php echo e(ui_t($employee['department_name'])); ?></span>
        <p class="muted"><?php echo e(ui_t($employee['office_name'])); ?></p>
        <p class="muted"><?php echo e($employee['email']); ?></p>
        <span><?php echo e(ui_t('View profile')); ?> <?php echo ui_icon('arrow'); ?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel empty-state"><h3><?php echo e(ui_t('No employees found')); ?></h3><p><?php echo e(ui_t('Try another name or email, or clear the filters to view all employees.')); ?></p><a class="button button-secondary" href="list_employees.php"><?php echo e(ui_t('Clear filters')); ?></a></div>
<?php endif; ?>
<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="<?php echo e(ui_t('Employee directory pages')); ?>">
    <?php if ($page > 1): ?><a class="button button-secondary" href="<?php echo e($pageUrl($page - 1)); ?>" rel="prev"><?php echo e(ui_t('Previous')); ?></a><?php endif; ?>
    <?php $firstPage = max(1, $page - 2); $lastPage = min($totalPages, $page + 2); ?>
    <?php if ($firstPage > 1): ?><a class="button button-secondary" href="<?php echo e($pageUrl(1)); ?>" aria-label="<?php echo e(ui_t('Page {number}', ['number' => 1])); ?>">1</a><?php if ($firstPage > 2): ?><span aria-hidden="true">&hellip;</span><?php endif; ?><?php endif; ?>
    <?php for ($target = $firstPage; $target <= $lastPage; $target++): ?>
        <?php if ($target === $page): ?><span class="button" aria-current="page" aria-label="<?php echo e(ui_t('Page {number}', ['number' => $target])); ?>"><?php echo e($target); ?></span><?php else: ?><a class="button button-secondary" href="<?php echo e($pageUrl($target)); ?>" aria-label="<?php echo e(ui_t('Page {number}', ['number' => $target])); ?>"><?php echo e($target); ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($lastPage < $totalPages): ?><?php if ($lastPage < $totalPages - 1): ?><span aria-hidden="true">&hellip;</span><?php endif; ?><a class="button button-secondary" href="<?php echo e($pageUrl($totalPages)); ?>" aria-label="<?php echo e(ui_t('Page {number}', ['number' => $totalPages])); ?>"><?php echo e($totalPages); ?></a><?php endif; ?>
    <?php if ($page < $totalPages): ?><a class="button button-secondary" href="<?php echo e($pageUrl($page + 1)); ?>" rel="next"><?php echo e(ui_t('Next')); ?> <?php echo ui_icon('arrow'); ?></a><?php endif; ?>
</nav>
<?php endif; ?>
<?php ui_page_end(); ?>
