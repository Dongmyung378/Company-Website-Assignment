<?php

return [
    'DROP TRIGGER IF EXISTS employee_holiday_insert',
    <<<'OBJECT_SQL'
CREATE TRIGGER employee_holiday_insert AFTER INSERT ON employees FOR EACH ROW
BEGIN
    INSERT INTO holiday_balance (employee_id,annual_leave,sick_leave,personal_leave)
    VALUES (NEW.employee_id,28,10,5);
END
OBJECT_SQL,
    'DROP TRIGGER IF EXISTS employee_password',
    <<<'OBJECT_SQL'
CREATE TRIGGER employee_password AFTER INSERT ON employees FOR EACH ROW
BEGIN
    INSERT INTO login (employee_id,password_hash,auth_version,must_change_password)
    VALUES (NEW.employee_id,NULL,1,1);
END
OBJECT_SQL,
    'DROP PROCEDURE IF EXISTS delete_employee_with_log',
    <<<'OBJECT_SQL'
CREATE PROCEDURE delete_employee_with_log (
    IN p_employee_id INT, IN p_deleted_by_id INT,
    IN p_deleted_by_name VARCHAR(100), IN p_reason TEXT
)
SQL SECURITY DEFINER
BEGIN
    DECLARE v_name VARCHAR(100);
    DECLARE v_salary VARCHAR(20);
    DECLARE v_department VARCHAR(100);
    DECLARE v_position VARCHAR(100);
    DECLARE v_dob DATE;
    DECLARE v_nin VARCHAR(50);
    DECLARE v_found BOOLEAN DEFAULT TRUE;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_found=FALSE;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
    IF CHAR_LENGTH(p_reason)>255 THEN
        SIGNAL SQLSTATE '22001' SET MESSAGE_TEXT='Deletion reason is too long.', MYSQL_ERRNO=1406;
    END IF;
    SELECT e.name,e.salary,d.department_name,p.position_name,e.dob,e.nin
    INTO v_name,v_salary,v_department,v_position,v_dob,v_nin
    FROM employees e JOIN department d USING(department_id)
    JOIN `position` p USING(position_id)
    WHERE e.employee_id=p_employee_id FOR UPDATE;
    IF NOT v_found THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Employee not found.', MYSQL_ERRNO=1644;
    END IF;
    INSERT INTO delete_employee_logs (
        employee_id,employee_name,employee_salary,employee_department,employee_position,
        employee_dob,employee_nin,deleted_by_id,deleted_by_name,reason,deleted_time
    ) VALUES (
        p_employee_id,v_name,v_salary,v_department,v_position,v_dob,v_nin,
        p_deleted_by_id,p_deleted_by_name,p_reason,NOW()
    );
    DELETE FROM employees WHERE employee_id=p_employee_id;
    COMMIT;
END
OBJECT_SQL,
];
