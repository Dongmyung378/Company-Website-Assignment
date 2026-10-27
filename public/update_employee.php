<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../lib/business.php';
require_once __DIR__ . '/../lib/ui.php';
$current = require_admin();
$employee_data = null;
$message = $_SESSION['employee_update_message'] ?? '';
$messageType = 'success';
unset($_SESSION['employee_update_message'], $_SESSION['employee_data']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $transaction = false;
    try {
        $employeeId = business_id($_POST, 'employee_id');
        if (isset($_POST['update_employee'])) {
            $version = business_id($_POST, 'row_version', 'Record version');
            $fields = business_employee_fields($_POST, false);
            $percentage = input_string($_POST, 'salary_increase', '0');
            $percentage = $percentage === '' ? '0' : $percentage;
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $percentage)
                || !is_finite((float) $percentage) || (float) $percentage < -100 || (float) $percentage > 10000) {
                throw new WorkflowException('Salary increase must be a percentage between -100 and 10000, with at most two decimal places.');
            }
            $conn->begin_transaction();
            $transaction = true;
            $stmt = $conn->prepare('SELECT * FROM employees WHERE employee_id = ? FOR UPDATE');
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $record = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$record) {
                throw new WorkflowException('Employee not found.', 404);
            }
            if ((int) $record['row_version'] !== $version) {
                throw new WorkflowException('This employee was changed after you opened the form. Search again before updating.', 409);
            }
            business_check_assignment($conn, $fields['position_id'], $fields['department_id'], $fields['office_id']);
            $salary = $record['salary'];
            if ((float) $percentage !== 0.0) {
                if (!is_numeric($salary)) {
                    throw new WorkflowException('A salary percentage change requires a numeric current salary.');
                }
                $newSalary = round((float) $salary * (1 + (float) $percentage / 100), 2);
                if (!is_finite($newSalary) || $newSalary < 0 || $newSalary > 999999999999.99) {
                    throw new WorkflowException('The resulting salary is outside the supported range.');
                }
                $salary = number_format($newSalary, 2, '.', '');
            }
            $stmt = $conn->prepare('UPDATE employees SET name = ?, salary = ?, home_address = ?, contract = ?, department_id = ?, position_id = ?, office_id = ?, row_version = row_version + 1 WHERE employee_id = ? AND row_version = ?');
            $stmt->bind_param('ssssiiiii', $fields['name'], $salary, $fields['home_address'], $fields['contract'], $fields['department_id'], $fields['position_id'], $fields['office_id'], $employeeId, $version);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new WorkflowException('The employee changed while updating. Search again.', 409);
            }
            $stmt->close();
            business_save_contact($conn, $employeeId, $fields);
            if ((int) $record['department_id'] !== $fields['department_id']) {
                $stmt = $conn->prepare('UPDATE login SET auth_version = auth_version + 1 WHERE employee_id = ?');
                $stmt->bind_param('i', $employeeId);
                $stmt->execute();
                if ($stmt->affected_rows !== 1) {
                    throw new RuntimeException('Missing account during role change.');
                }
                $stmt->close();
            }
            $conn->commit();
            $transaction = false;
            $_SESSION['employee_update_message'] = 'Employee information updated successfully.';
            header('Location: update_employee.php');
            exit();
        } elseif (isset($_POST['search_employee'])) {
            $stmt = $conn->prepare('SELECT e.*, ec.emergency_name, ec.emergency_relationship, ec.emergency_phone FROM employees e LEFT JOIN emergency_table ec ON e.employee_id = ec.employee_id WHERE e.employee_id = ?');
            $stmt->bind_param('i', $employeeId);
            $stmt->execute();
            $employee_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$employee_data) {
                throw new WorkflowException('Employee not found.', 404);
            }
        } else {
            throw new WorkflowException('Select a valid employee action.');
        }
    } catch (Throwable $error) {
        if ($transaction) {
            $conn->rollback();
        }
        $message = business_report_failure($error, 'Unable to update the employee.');
        $messageType = 'error';
    }
} elseif (array_key_exists('employee_id', $_GET)) {
    try {
        $employeeId = business_id($_GET, 'employee_id');
        $stmt = $conn->prepare('SELECT e.*, ec.emergency_name, ec.emergency_relationship, ec.emergency_phone FROM employees e LEFT JOIN emergency_table ec ON e.employee_id = ec.employee_id WHERE e.employee_id = ?');
        $stmt->bind_param('i', $employeeId);
        $stmt->execute();
        $employee_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$employee_data) {
            throw new WorkflowException('Employee not found.', 404);
        }
    } catch (Throwable $error) {
        $message = business_report_failure($error, 'Unable to load the employee.');
        $messageType = 'error';
    }
}
$departments = $conn->query('SELECT department_id, department_name FROM department ORDER BY department_name ASC');
$positions = $conn->query('SELECT position_id, position_name, department_id FROM position ORDER BY position_name ASC');
$offices = $conn->query('SELECT office_id, office_name FROM office ORDER BY office_name ASC');
ui_page_start('Update employee', 'Find a colleague and keep their employee record current.', ['current' => $current]);
?>
<?php if ($message !== '') ui_notice($message, $messageType); ?>
<section class="panel" aria-labelledby="find-employee-title">
    <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Find a record')); ?></p><h2 id="find-employee-title"><?php echo e(ui_t('Search by employee ID')); ?></h2><p class="muted"><?php echo e(ui_t('You can also open a record from the employee directory.')); ?></p></div><a class="button button-secondary" href="list_employees.php"><?php echo e(ui_t('Browse directory')); ?></a></div>
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <div class="form-grid"><div class="field"><label for="search_employee_id"><?php echo e(ui_t('Employee ID *')); ?></label><input type="number" id="search_employee_id" name="employee_id" min="1" max="2147483647" step="1" value="<?php echo e($employee_data['employee_id'] ?? ''); ?>" placeholder="<?php echo e(ui_t('e.g. 1001')); ?>" required></div></div>
        <div class="form-actions"><button type="submit" name="search_employee" class="button"><?php echo e(ui_t('Find employee')); ?></button></div>
    </form>
</section>
<?php if ($employee_data): ?>
    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="row_version" value="<?php echo e($employee_data['row_version']); ?>">
        <input type="hidden" name="employee_id" value="<?php echo e($employee_data['employee_id']); ?>">
        <section class="panel" aria-labelledby="profile-title">
            <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Employee #{id}', ['id' => $employee_data['employee_id']])); ?></p><h2 id="profile-title"><?php echo e($employee_data['name']); ?></h2><p class="muted"><?php echo e($employee_data['email']); ?></p></div><a class="button button-quiet" href="employee_detail.php?id=<?php echo e($employee_data['employee_id']); ?>"><?php echo e(ui_t('View full profile')); ?></a></div>
            <div class="form-grid">
                <div class="field"><label for="name"><?php echo e(ui_t('Full name *')); ?></label><input type="text" id="name" name="name" maxlength="100" value="<?php echo e($employee_data['name']); ?>" autocomplete="name" required></div>
                <div class="field"><label for="salary_increase"><?php echo e(ui_t('Salary adjustment (%)')); ?></label><input type="number" id="salary_increase" step="0.01" name="salary_increase" min="-100" max="10000" placeholder="0" aria-describedby="salary-hint"><small id="salary-hint" class="muted"><?php echo e(ui_t('Current salary: £{salary}. Leave blank to keep it; use a negative percentage to reduce it.', ['salary' => is_numeric($employee_data['salary']) ? number_format((float) $employee_data['salary'], 2) : $employee_data['salary']])); ?></small></div>
                <div class="field"><label for="home_address"><?php echo e(ui_t('Home address *')); ?></label><input type="text" id="home_address" name="home_address" maxlength="255" value="<?php echo e($employee_data['home_address']); ?>" autocomplete="street-address" required></div>
                <div class="field"><label for="contract"><?php echo e(ui_t('Contract type *')); ?></label><select id="contract" name="contract" required><?php foreach (['Full-Time', 'Part-Time', 'Freelance'] as $contract): ?><option value="<?php echo e($contract); ?>" <?php echo $employee_data['contract'] === $contract ? 'selected' : ''; ?>><?php echo e(ui_t($contract)); ?></option><?php endforeach; ?></select></div>
                <div class="field"><label for="department_id"><?php echo e(ui_t('Department *')); ?></label><select id="department_id" name="department_id" required><?php while ($row = $departments->fetch_assoc()): ?><option value="<?php echo e($row['department_id']); ?>" <?php echo (int) $row['department_id'] === (int) $employee_data['department_id'] ? 'selected' : ''; ?>><?php echo e(ui_t($row['department_name'])); ?></option><?php endwhile; ?></select></div>
                <div class="field"><label for="position_id"><?php echo e(ui_t('Position *')); ?></label><select id="position_id" name="position_id" data-department-select="department_id" aria-describedby="position-hint" required><option value=""><?php echo e(ui_t('Select a position')); ?></option><?php while ($row = $positions->fetch_assoc()): ?><option value="<?php echo e($row['position_id']); ?>" data-department-id="<?php echo e($row['department_id']); ?>" <?php echo (int) $row['position_id'] === (int) $employee_data['position_id'] ? 'selected' : ''; ?>><?php echo e(ui_t($row['position_name'])); ?></option><?php endwhile; ?></select><small id="position-hint" class="muted"><?php echo e(ui_t('Positions follow the selected department. Check the position after changing departments.')); ?></small></div>
                <div class="field"><label for="office_id"><?php echo e(ui_t('Office *')); ?></label><select id="office_id" name="office_id" required><?php while ($row = $offices->fetch_assoc()): ?><option value="<?php echo e($row['office_id']); ?>" <?php echo (int) $row['office_id'] === (int) $employee_data['office_id'] ? 'selected' : ''; ?>><?php echo e($row['office_name']); ?></option><?php endwhile; ?></select></div>
            </div>
        </section>
        <section class="panel" aria-labelledby="emergency-title">
            <div class="panel-header"><div><p class="eyebrow"><?php echo e(ui_t('Emergency contact')); ?></p><h2 id="emergency-title"><?php echo e(ui_t('Emergency contact details')); ?></h2><p class="muted"><?php echo e(ui_t('These details are optional. Fields marked * above are required.')); ?></p></div></div>
            <div class="form-grid">
                <div class="field"><label for="emergency_name"><?php echo e(ui_t('Contact name')); ?></label><input type="text" id="emergency_name" name="emergency_name" maxlength="100" value="<?php echo e($employee_data['emergency_name'] ?? ''); ?>"></div>
                <div class="field"><label for="emergency_relationship"><?php echo e(ui_t('Relationship')); ?></label><input type="text" id="emergency_relationship" name="emergency_relationship" maxlength="50" value="<?php echo e($employee_data['emergency_relationship'] ?? ''); ?>"></div>
                <div class="field"><label for="emergency_phone"><?php echo e(ui_t('Phone number')); ?></label><input type="tel" id="emergency_phone" name="emergency_phone" maxlength="50" value="<?php echo e($employee_data['emergency_phone'] ?? ''); ?>"></div>
            </div>
            <div class="form-actions"><button type="submit" name="update_employee" class="button"><?php echo e(ui_t('Save changes')); ?></button><a href="employee_detail.php?id=<?php echo e($employee_data['employee_id']); ?>" class="button button-secondary"><?php echo e(ui_t('Cancel')); ?></a></div>
            <p class="muted"><?php echo e(ui_t('If another administrator changes this record, search again to load their latest changes before saving.')); ?></p>
        </section>
    </form>
<?php else: ?>
    <section class="panel empty-state"><h2><?php echo e(ui_t('Select an employee record')); ?></h2><p class="muted"><?php echo e(ui_t('Search above to edit personal details, job assignments, salary, and emergency contacts.')); ?></p></section>
<?php endif; ?>
<?php ui_page_end(); ?>
