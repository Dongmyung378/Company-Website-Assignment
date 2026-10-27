<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();

$employee_id_text = input_string($_GET, 'id');
$employee_id = ctype_digit($employee_id_text) && strlen($employee_id_text) <= 10 ? (int) $employee_id_text : 0;
if ($employee_id <= 0 || $employee_id > 2147483647) {
    http_response_code(422);
    exit('Invalid employee ID.');
}
$stmt = $conn->prepare('SELECT e.employee_id, e.name, e.salary, e.email, e.dob, e.home_address,
    e.hired_date, e.contract, e.nin, d.department_name, o.office_name, p.position_name,
    ec.emergency_name, ec.emergency_relationship, ec.emergency_phone
    FROM employees e JOIN department d ON e.department_id = d.department_id
    JOIN office o ON e.office_id = o.office_id JOIN position p ON p.position_id = e.position_id
    LEFT JOIN emergency_table ec ON e.employee_id = ec.employee_id WHERE e.employee_id = ?');
$stmt->bind_param('i', $employee_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();
if (!$employee) {
    http_response_code(404);
    ui_page_start('Employee not found', 'This employee record is no longer available.', ['current' => $current]);
    echo '<div class="panel empty-state"><h2>We could not find this person</h2><p>Return to the directory to find a current employee.</p><a class="button" href="list_employees.php">Back to directory</a></div>';
    ui_page_end();
    exit;
}
$displayValue = static fn ($value): string => e($value !== null && $value !== '' ? $value : 'Not provided');
preg_match('/^\s*(\S)/u', (string) $employee['name'], $initial);

ui_page_start('Employee profile', 'People / Employee directory / Profile', [
    'current' => $current,
    'actions' => '<a class="button button-secondary" href="list_employees.php">Back to directory</a><a class="button" href="update_employee.php?employee_id=' . e($employee_id) . '">Edit employee</a>',
]);
?>
<section class="panel profile-heading" aria-labelledby="employee-name">
    <div class="avatar" aria-hidden="true"><?php echo e(strtoupper($initial[1] ?? '?')); ?></div>
    <div><p class="eyebrow">Employee #<?php echo e($employee['employee_id']); ?></p><h2 id="employee-name"><?php echo e($employee['name']); ?></h2><p class="muted"><?php echo e($employee['position_name']); ?> / <?php echo e($employee['department_name']); ?></p></div>
    <span class="badge badge-neutral"><?php echo e($employee['office_name']); ?></span>
</section>
<div class="grid-2">
    <section class="panel" aria-labelledby="employment-heading">
        <div class="panel-header"><div><p class="eyebrow">At work</p><h2 id="employment-heading">Employment details</h2></div></div>
        <dl class="detail-grid">
            <div class="detail-item"><dt>Department</dt><dd><?php echo $displayValue($employee['department_name']); ?></dd></div>
            <div class="detail-item"><dt>Position</dt><dd><?php echo $displayValue($employee['position_name']); ?></dd></div>
            <div class="detail-item"><dt>Office</dt><dd><?php echo $displayValue($employee['office_name']); ?></dd></div>
            <div class="detail-item"><dt>Joined</dt><dd><?php echo $displayValue($employee['hired_date']); ?></dd></div>
            <div class="detail-item"><dt>Contract</dt><dd><?php echo $displayValue($employee['contract']); ?></dd></div>
            <div class="detail-item"><dt>Annual salary</dt><dd>&pound; <?php echo $displayValue($employee['salary']); ?></dd></div>
        </dl>
    </section>
    <section class="panel" aria-labelledby="contact-heading">
        <div class="panel-header"><div><p class="eyebrow">Getting in touch</p><h2 id="contact-heading">Contact information</h2></div></div>
        <dl class="detail-grid">
            <div class="detail-item field-full"><dt>Work email</dt><dd><?php echo $displayValue($employee['email']); ?></dd></div>
            <div class="detail-item field-full"><dt>Home address</dt><dd><?php echo $displayValue($employee['home_address']); ?></dd></div>
        </dl>
    </section>
    <section class="panel" aria-labelledby="personal-heading">
        <div class="panel-header"><div><p class="eyebrow">Employee record</p><h2 id="personal-heading">Personal information</h2></div><span class="badge badge-neutral">Administrator access</span></div>
        <dl class="detail-grid">
            <div class="detail-item"><dt>Date of birth</dt><dd><?php echo $displayValue($employee['dob']); ?></dd></div>
            <div class="detail-item"><dt>National Insurance number</dt><dd><?php echo $displayValue($employee['nin']); ?></dd></div>
        </dl>
    </section>
    <section class="panel" aria-labelledby="emergency-heading">
        <div class="panel-header"><div><p class="eyebrow">In case of emergency</p><h2 id="emergency-heading">Emergency contact</h2></div></div>
        <dl class="detail-grid">
            <div class="detail-item"><dt>Contact name</dt><dd><?php echo $displayValue($employee['emergency_name']); ?></dd></div>
            <div class="detail-item"><dt>Relationship</dt><dd><?php echo $displayValue($employee['emergency_relationship']); ?></dd></div>
            <div class="detail-item field-full"><dt>Phone number</dt><dd><?php echo $displayValue($employee['emergency_phone']); ?></dd></div>
        </dl>
    </section>
</div>
<?php ui_page_end(); ?>