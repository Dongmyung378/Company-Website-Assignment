<?php

// Validation shared by employee and leave workflows. SQL errors remain server-side.
class WorkflowException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }
}

function business_text(array $input, string $key, string $label, int $maximum, bool $required = true): string
{
    $value = trim(input_string($input, $key));
    $length = preg_match_all('/./us', $value);
    if ($length === false || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
        throw new WorkflowException($label . ' contains invalid characters.');
    }
    if (($required && $value === '') || $length > $maximum) {
        throw new WorkflowException($label . ' is required and must be at most ' . $maximum . ' characters.');
    }
    return $value;
}

function business_id(array $input, string $key, string $label = 'Employee ID'): int
{
    $value = input_string($input, $key);
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($id === false) {
        throw new WorkflowException($label . ' must be a positive whole number.');
    }
    return $id;
}

function business_date(array $input, string $key, string $label): string
{
    $value = input_string($input, $key);
    $date = valid_date($value);
    if (!$date || $date->format('Y') < '1000') {
        throw new WorkflowException($label . ' must be a valid date in YYYY-MM-DD format.');
    }
    return $value;
}

function business_employee_fields(array $input, bool $newEmployee): array
{
    $fields = [
        'name' => business_text($input, 'name', 'Full name', 100),
        'home_address' => business_text($input, 'home_address', 'Home address', 255),
        'contract' => input_string($input, 'contract'),
        'department_id' => business_id($input, 'department_id', 'Department'),
        'office_id' => business_id($input, 'office_id', 'Office'),
        'position_id' => business_id($input, 'position_id', 'Position'),
        'emergency_name' => business_text($input, 'emergency_name', 'Emergency contact name', 100, false),
        'emergency_relationship' => business_text($input, 'emergency_relationship', 'Emergency relationship', 50, false),
        'emergency_phone' => business_text($input, 'emergency_phone', 'Emergency phone', 50, false),
    ];
    if (!in_array($fields['contract'], ['Full-Time', 'Part-Time', 'Freelance'], true)) {
        throw new WorkflowException('Select a valid contract type.');
    }
    if ($newEmployee) {
        $salary = input_string($input, 'salary');
        if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $salary)) {
            throw new WorkflowException('Salary must be a nonnegative amount with at most 12 whole digits and two decimal places.');
        }
        $fields['salary'] = $salary;
        $fields['dob'] = business_date($input, 'dob', 'Date of birth');
        $fields['hired_date'] = business_date($input, 'hired_date', 'Hired date');
        $fields['nin'] = business_text($input, 'nin', 'NIN', 50, false);
        if ($fields['dob'] > date('Y-m-d') || $fields['hired_date'] < $fields['dob']) {
            throw new WorkflowException('Date of birth must not be in the future or after the hired date.');
        }
    }
    return $fields;
}

function business_check_assignment(mysqli $conn, int $positionId, int $departmentId, int $officeId): void
{
    $stmt = $conn->prepare('SELECT COUNT(*) FROM position p JOIN office o ON o.office_id = ? WHERE p.position_id = ? AND p.department_id = ?');
    $stmt->bind_param('iii', $officeId, $positionId, $departmentId);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    if ((int) $count !== 1) {
        throw new WorkflowException('Select an existing office and a position belonging to the selected department.');
    }
}

function business_save_contact(mysqli $conn, int $employeeId, array $fields): void
{
    $stmt = $conn->prepare('INSERT INTO emergency_table (employee_id, emergency_name, emergency_relationship, emergency_phone) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE emergency_name = VALUES(emergency_name), emergency_relationship = VALUES(emergency_relationship), emergency_phone = VALUES(emergency_phone)');
    $stmt->bind_param('isss', $employeeId, $fields['emergency_name'], $fields['emergency_relationship'], $fields['emergency_phone']);
    $stmt->execute();
    $stmt->close();
}

function business_report_failure(Throwable $error, string $message): string
{
    if ($error instanceof WorkflowException) {
        http_response_code($error->httpStatus);
        return $error->getMessage();
    }
    error_log($message . ' [' . get_class($error) . ']');
    http_response_code(500);
    return $message . ' Please try again or contact the administrator.';
}
