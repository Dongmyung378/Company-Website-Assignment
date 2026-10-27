<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();

$today = date('Y-m-d');
$currentMonth = date('m');
$currentDay = date('d');
$passed_birthdays = [];
$upcoming_birthdays = [];
$message = '';

try {
    $query = "
        SELECT e.employee_id, e.name, e.department_id, d.department_name, e.dob,
                DATE_FORMAT(e.dob, '%d') AS dob_day,
                DATE_FORMAT(e.dob, '%d %b') AS formatted_dob
        FROM employees e
        JOIN department d ON e.department_id = d.department_id
        WHERE MONTH(e.dob) = ?
        ORDER BY dob_day ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $currentMonth);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $dobDay = intval($row['dob_day']);
            if ($dobDay < $currentDay) {
                $passed_birthdays[] = $row;
            } elseif ($dobDay >= $currentDay) {
                $upcoming_birthdays[] = $row;
            }
        }
    } else {
        $message = "No employee birthdays this month. There is always another reason to celebrate your team.";
    }
} catch (Exception $e) {
    error_log('Birthday report failed: ' . $e->getMessage());
    http_response_code(500);
    $message = 'The birthday report is temporarily unavailable. Please try again later.';
}

$conn->close();
?>
<?php ui_page_start('Employee Birthdays', 'Make room for a little celebration this ' . date('F') . '.', ['current' => $current]); ?>
<div class="stat-grid">
    <div class="stat-card"><span class="stat-label">This month</span><strong class="stat-value"><?php echo count($passed_birthdays) + count($upcoming_birthdays); ?></strong><span class="stat-note">Birthdays in <?php echo e(date('F')); ?></span></div>
    <div class="stat-card"><span class="stat-label">Today and upcoming</span><strong class="stat-value"><?php echo count($upcoming_birthdays); ?></strong><span class="stat-note">More reasons to celebrate</span></div>
    <div class="stat-card"><span class="stat-label">Already celebrated</span><strong class="stat-value"><?php echo count($passed_birthdays); ?></strong><span class="stat-note">Earlier this month</span></div>
</div>
<?php if ($message !== ''): ?><?php ui_notice($message, http_response_code() >= 400 ? 'error' : 'info'); ?><?php endif; ?>
<section class="panel" aria-labelledby="upcoming-birthdays-title">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(date('F Y')); ?></p><h2 id="upcoming-birthdays-title">Today and upcoming</h2></div><span class="badge badge-success"><?php echo count($upcoming_birthdays); ?> birthdays</span></div>
    <?php if ($upcoming_birthdays): ?>
        <div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Employee ID</th><th scope="col">Employee</th><th scope="col">Department</th><th scope="col">Birthday</th><th scope="col">Coming up</th></tr></thead><tbody>
        <?php foreach ($upcoming_birthdays as $employee): ?><tr><td><?php echo e($employee['employee_id']); ?></td><td><strong><?php echo e($employee['name']); ?></strong></td><td><?php echo e($employee['department_name']); ?></td><td><?php echo e($employee['formatted_dob']); ?></td><td><span class="badge <?php echo (int) $employee['dob_day'] === (int) $currentDay ? 'badge-success' : 'badge-neutral'; ?>"><?php echo (int) $employee['dob_day'] === (int) $currentDay ? 'Today' : 'This month'; ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?><div class="empty-state"><h3>All caught up on celebrations</h3><p>There are no more employee birthdays this month. Check back next month for the next celebration.</p></div><?php endif; ?>
</section>
<section class="panel" aria-labelledby="past-birthdays-title">
    <div class="panel-header"><div><p class="eyebrow">Earlier this month</p><h2 id="past-birthdays-title">Already celebrated</h2></div><span class="badge badge-neutral"><?php echo count($passed_birthdays); ?> birthdays</span></div>
    <?php if ($passed_birthdays): ?>
        <div class="table-scroll"><table class="data-table"><thead><tr><th scope="col">Employee ID</th><th scope="col">Employee</th><th scope="col">Department</th><th scope="col">Birthday</th></tr></thead><tbody>
        <?php foreach ($passed_birthdays as $employee): ?><tr><td><?php echo e($employee['employee_id']); ?></td><td><strong><?php echo e($employee['name']); ?></strong></td><td><?php echo e($employee['department_name']); ?></td><td><?php echo e($employee['formatted_dob']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php else: ?><div class="empty-state"><h3>The month is still ahead</h3><p>No employee birthdays have passed this month.</p></div><?php endif; ?>
</section>
<?php ui_page_end(); ?>
