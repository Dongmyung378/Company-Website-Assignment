<?php

require __DIR__ . '/../../config/connection.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

function runDatabaseStatement(mysqli $conn, string $label, string $sql, bool $announce = true): void
{
    if ($conn->query($sql)) {
        if ($announce) {
            echo htmlspecialchars($label) . ' is ready.<br>';
        }
        return;
    }

    http_response_code(500);
    exit('Unable to create ' . htmlspecialchars($label) . ': ' . htmlspecialchars($conn->error));
}

runDatabaseStatement($conn, 'employee_holiday_insert trigger', 'DROP TRIGGER IF EXISTS employee_holiday_insert', false);

$createHolidayTriggerSQL = "
    CREATE TRIGGER employee_holiday_insert
    AFTER INSERT ON employees
    FOR EACH ROW
    BEGIN
        INSERT INTO holiday_balance (
            employee_id, annual_leave, sick_leave, personal_leave
        ) VALUES (
            NEW.employee_id, 28, 10, 5
        );
    END
";
runDatabaseStatement($conn, 'employee_holiday_insert trigger', $createHolidayTriggerSQL);

runDatabaseStatement($conn, 'delete_employee_with_log procedure', 'DROP PROCEDURE IF EXISTS delete_employee_with_log', false);

$createProcedureSQL = "
    CREATE PROCEDURE delete_employee_with_log(
        IN emp_id INT,
        IN deleted_by_id INT,
        IN deleted_by_name VARCHAR(100),
        IN reason TEXT
    )
    BEGIN
        DECLARE emp_name VARCHAR(100);
        DECLARE emp_salary VARCHAR(10);
        DECLARE emp_department VARCHAR(50);
        DECLARE emp_position VARCHAR(50);
        DECLARE emp_dob DATE;
        DECLARE emp_nin VARCHAR(50);

        SELECT
            e.name, e.salary, d.department_name, p.position_name, e.dob, e.nin
        INTO
            emp_name, emp_salary, emp_department, emp_position, emp_dob, emp_nin
        FROM employees e
        JOIN department d ON e.department_id = d.department_id
        JOIN position p ON e.position_id = p.position_id
        WHERE e.employee_id = emp_id;

        INSERT INTO delete_employee_logs (
            employee_id,
            employee_name,
            employee_salary,
            employee_department,
            employee_position,
            employee_dob,
            employee_nin,
            deleted_by_id,
            deleted_by_name,
            reason,
            deleted_time
        ) VALUES (
            emp_id,
            emp_name,
            emp_salary,
            emp_department,
            emp_position,
            emp_dob,
            emp_nin,
            deleted_by_id,
            deleted_by_name,
            reason,
            NOW()
        );

        DELETE FROM employees WHERE employee_id = emp_id;
    END
";
runDatabaseStatement($conn, 'delete_employee_with_log procedure', $createProcedureSQL);

runDatabaseStatement($conn, 'employee_password trigger', 'DROP TRIGGER IF EXISTS employee_password', false);

$initialPasswordHash = password_hash('0000', PASSWORD_BCRYPT);
$escapedPasswordHash = $conn->real_escape_string($initialPasswordHash);
$createPasswordTriggerSQL = "
    CREATE TRIGGER employee_password
    AFTER INSERT ON employees
    FOR EACH ROW
    BEGIN
        INSERT INTO login (employee_id, password_hash)
        VALUES (NEW.employee_id, '$escapedPasswordHash');
    END
";
runDatabaseStatement($conn, 'employee_password trigger', $createPasswordTriggerSQL);

$conn->close();
