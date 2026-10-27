<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();
$message = '';
$created = $_SESSION['employee_created'] ?? null;
unset($_SESSION['employee_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        $fields = business_employee_fields($_POST, true);
        $email = str_replace(' ', '.', $fields['name']) . '@kilburnazon.com';
        if (preg_match_all('/./us', $email) > 100) {
            throw new WorkflowException('Full name is too long to generate a company email address.');
        }
        $conn->begin_transaction();
        $transaction = true;
        business_check_assignment($conn, $fields['position_id'], $fields['department_id'], $fields['office_id']);
        $stmt = $conn->prepare('INSERT INTO employees (name, salary, email, dob, home_address, hired_date, contract, nin, department_id, office_id, position_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssssiii', $fields['name'], $fields['salary'], $email, $fields['dob'], $fields['home_address'], $fields['hired_date'], $fields['contract'], $fields['nin'], $fields['department_id'], $fields['office_id'], $fields['position_id']);
        $stmt->execute();
        $employeeId = (int) $conn->insert_id;
        $stmt->close();
        business_save_contact($conn, $employeeId, $fields);
        $temporaryPassword = provision_temporary_password($conn, $employeeId);
        $conn->commit();
        $transaction = false;
        $_SESSION['employee_created'] = ['employee_id' => $employeeId, 'temporary_password' => $temporaryPassword];
        header('Location: add_employee.php');
        exit();
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to add the employee.');
    }
}

$departments = $conn->query('SELECT department_id, department_name FROM department ORDER BY department_name ASC');
$positions = $conn->query('SELECT position_id, position_name, department_id FROM position ORDER BY position_name ASC');
$offices = $conn->query('SELECT office_id, office_name FROM office ORDER BY office_name ASC');
$formValue = static function (string $key, string $default = ''): string {
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default;
};
ui_page_start('Add employee', 'Create an employee profile and a secure first sign-in.', ['current' => $current]);
?>
<?php if ($message !== '') ui_notice($message, 'error'); ?>
<?php if (is_array($created)): ?>
    <section class="panel" aria-labelledby="created-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Employee account')); ?></p><h2 id="created-title"><?php echo e(ui_t('Employee added successfully')); ?></h2></div><span class="badge badge-success"><?php echo e(ui_t('Created')); ?></span></div>
        <p><?php echo e(ui_t('Employee ID:')); ?> <strong><?php echo e($created['employee_id']); ?></strong></p>
        <p><?php echo e(ui_t('Temporary password (shown once):')); ?> <code><?php echo e($created['temporary_password']); ?></code></p>
        <p class="muted"><?php echo e(ui_t('Share this password privately with the employee. They must change it when signing in. It will disappear when you leave or refresh this page.')); ?></p>
        <a class="button button-secondary" href="employee_detail.php?id=<?php echo e($created['employee_id']); ?>"><?php echo e(ui_t('View employee profile')); ?></a>
    </section>
<?php endif; ?>
<form method="POST" action="">
    <?php echo csrf_field(); ?>
    <section class="panel" aria-labelledby="employee-details-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('01 / Employee details')); ?></p><h2 id="employee-details-title"><?php echo e(ui_t('Personal information')); ?></h2><p class="muted"><?php echo e(ui_t('Fields marked * are required. Company email is generated from the employee\'s name.')); ?></p></div></div>
        <div class="form-grid">
            <div class="field"><label for="name"><?php echo e(ui_t('Full name *')); ?></label><input type="text" id="name" name="name" maxlength="100" value="<?php echo e($formValue('name')); ?>" autocomplete="name" required><small class="muted"><?php echo e(ui_t('Up to 100 characters; the generated email must also fit 100 characters.')); ?></small></div>
            <div class="field"><label for="dob"><?php echo e(ui_t('Date of birth *')); ?></label><input type="date" id="dob" name="dob" min="1000-01-01" max="<?php echo e(date('Y-m-d')); ?>" value="<?php echo e($formValue('dob')); ?>" required></div>
            <div class="field"><label for="salary"><?php echo e(ui_t('Annual salary (£) *')); ?></label><input type="text" id="salary" name="salary" inputmode="decimal" maxlength="15" pattern="(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?" value="<?php echo e($formValue('salary')); ?>" placeholder="<?php echo e(ui_t('e.g. 35000.00')); ?>" aria-describedby="salary-hint" required><small id="salary-hint" class="muted"><?php echo e(ui_t('A nonnegative amount, up to 12 whole digits and 2 decimal places.')); ?></small></div>
            <div class="field"><label for="hired_date"><?php echo e(ui_t('Hire date *')); ?></label><input type="date" id="hired_date" name="hired_date" min="1000-01-01" value="<?php echo e($formValue('hired_date')); ?>" aria-describedby="hired-date-hint" required><small id="hired-date-hint" class="muted"><?php echo e(ui_t('Must be on or after the employee\'s date of birth.')); ?></small></div>
            <div class="field"><label for="home_address"><?php echo e(ui_t('Home address *')); ?></label><input type="text" id="home_address" name="home_address" maxlength="255" value="<?php echo e($formValue('home_address')); ?>" autocomplete="street-address" required></div>
            <div class="field"><label for="nin"><?php echo e(ui_t('National Insurance number')); ?></label><input type="text" id="nin" name="nin" maxlength="50" value="<?php echo e($formValue('nin')); ?>"><small class="muted"><?php echo e(ui_t('Optional. Up to 50 characters.')); ?></small></div>
        </div>
    </section>
    <section class="panel" aria-labelledby="assignment-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('02 / Assignment')); ?></p><h2 id="assignment-title"><?php echo e(ui_t('Role and workplace')); ?></h2></div></div>
        <div class="form-grid">
            <div class="field"><label for="contract"><?php echo e(ui_t('Contract type *')); ?></label><select id="contract" name="contract" required><?php foreach (['Full-Time', 'Part-Time', 'Freelance'] as $contract): ?><option value="<?php echo e($contract); ?>" <?php echo $formValue('contract', 'Full-Time') === $contract ? 'selected' : ''; ?>><?php echo e(ui_t($contract)); ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="department_id"><?php echo e(ui_t('Department *')); ?></label><select id="department_id" name="department_id" required><option value=""><?php echo e(ui_t('Select a department')); ?></option><?php while ($row = $departments->fetch_assoc()): ?><option value="<?php echo e($row['department_id']); ?>" <?php echo $formValue('department_id') === (string) $row['department_id'] ? 'selected' : ''; ?>><?php echo e(ui_t($row['department_name'])); ?></option><?php endwhile; ?></select></div>
            <div class="field"><label for="position_id"><?php echo e(ui_t('Position *')); ?></label><select id="position_id" name="position_id" data-department-select="department_id" aria-describedby="position-hint" required><option value=""><?php echo e(ui_t('Select a position')); ?></option><?php while ($row = $positions->fetch_assoc()): ?><option value="<?php echo e($row['position_id']); ?>" data-department-id="<?php echo e($row['department_id']); ?>" <?php echo $formValue('position_id') === (string) $row['position_id'] ? 'selected' : ''; ?>><?php echo e(ui_t($row['position_name'])); ?></option><?php endwhile; ?></select><small id="position-hint" class="muted"><?php echo e(ui_t('Choose a department first to see its positions.')); ?></small></div>
            <div class="field"><label for="office_id"><?php echo e(ui_t('Office *')); ?></label><select id="office_id" name="office_id" required><option value=""><?php echo e(ui_t('Select an office')); ?></option><?php while ($row = $offices->fetch_assoc()): ?><option value="<?php echo e($row['office_id']); ?>" <?php echo $formValue('office_id') === (string) $row['office_id'] ? 'selected' : ''; ?>><?php echo e($row['office_name']); ?></option><?php endwhile; ?></select></div>
        </div>
    </section>
    <section class="panel" aria-labelledby="emergency-title">
        <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('03 / Emergency contact')); ?></p><h2 id="emergency-title"><?php echo e(ui_t('Emergency contact details')); ?></h2><p class="muted"><?php echo e(ui_t('These details are optional.')); ?></p></div></div>
        <div class="form-grid">
            <div class="field"><label for="emergency_name"><?php echo e(ui_t('Contact name')); ?></label><input type="text" id="emergency_name" name="emergency_name" maxlength="100" value="<?php echo e($formValue('emergency_name')); ?>"></div>
            <div class="field"><label for="emergency_relationship"><?php echo e(ui_t('Relationship')); ?></label><input type="text" id="emergency_relationship" name="emergency_relationship" maxlength="50" value="<?php echo e($formValue('emergency_relationship')); ?>"></div>
            <div class="field"><label for="emergency_phone"><?php echo e(ui_t('Phone number')); ?></label><input type="tel" id="emergency_phone" name="emergency_phone" maxlength="50" value="<?php echo e($formValue('emergency_phone')); ?>"></div>
        </div>
        <div class="form-actions"><button type="submit" class="button"><?php echo e(ui_t('Create employee')); ?></button><a href="admin_main.php" class="button button-secondary"><?php echo e(ui_t('Cancel')); ?></a></div>
    </section>
</form>
<?php ui_page_end(); ?>
