<?php

// The setup runner executes these statements with its separate migration account.
return [
    <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS materials (
    id INT PRIMARY KEY, name VARCHAR(100) NOT NULL, position VARCHAR(100) NOT NULL,
    department VARCHAR(100) NOT NULL, salary VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL, dob DATE NOT NULL, office VARCHAR(100) NOT NULL,
    home_address VARCHAR(255) NOT NULL, hired_date DATE NOT NULL,
    contract VARCHAR(50) NOT NULL, nin VARCHAR(50) NOT NULL,
    emergency_name VARCHAR(100), emergency_relationship VARCHAR(50), emergency_phone VARCHAR(50)
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS department (
    department_id INT AUTO_INCREMENT PRIMARY KEY, department_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS `position` (
    position_id INT AUTO_INCREMENT PRIMARY KEY, position_name VARCHAR(100) NOT NULL UNIQUE,
    department_id INT, FOREIGN KEY (department_id) REFERENCES department(department_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS office (
    office_id INT AUTO_INCREMENT PRIMARY KEY, office_name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS employees (
    employee_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL, salary VARCHAR(20) NOT NULL, email VARCHAR(100) NOT NULL,
    dob DATE NOT NULL, home_address VARCHAR(255) NOT NULL, hired_date DATE NOT NULL,
    contract VARCHAR(50) NOT NULL, nin VARCHAR(50) NOT NULL,
    department_id INT NOT NULL, office_id INT NOT NULL, position_id INT NOT NULL,
    row_version INT NOT NULL DEFAULT 1,
    FOREIGN KEY (department_id) REFERENCES department(department_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (office_id) REFERENCES office(office_id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (position_id) REFERENCES `position`(position_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS emergency_table (
    employee_id INT PRIMARY KEY, emergency_name VARCHAR(100),
    emergency_relationship VARCHAR(50), emergency_phone VARCHAR(50),
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS holiday_balance (
    employee_id INT PRIMARY KEY, annual_leave INT NOT NULL DEFAULT 28,
    sick_leave INT NOT NULL DEFAULT 10, personal_leave INT NOT NULL DEFAULT 5,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS holiday_requests (
    request_id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT,
    leave_type ENUM('Annual','Sick','Personal'), start_date DATE, end_date DATE, reason TEXT,
    status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS delete_employee_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL,
    employee_name VARCHAR(100) NOT NULL, employee_salary VARCHAR(20) NOT NULL,
    employee_department VARCHAR(100) NOT NULL, employee_position VARCHAR(100) NOT NULL,
    employee_dob DATE NOT NULL, employee_nin VARCHAR(50) NOT NULL,
    deleted_by_id INT NOT NULL, deleted_by_name VARCHAR(100) NOT NULL,
    reason VARCHAR(255), deleted_time DATETIME NOT NULL
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS login (
    employee_id INT PRIMARY KEY, password_hash VARCHAR(255) NULL,
    auth_version INT NOT NULL DEFAULT 1, must_change_password BOOLEAN NOT NULL DEFAULT 1,
    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
SQL,
    <<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_key CHAR(64) NOT NULL, ip_key CHAR(64) NOT NULL,
    attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX (account_key, attempted_at), INDEX (ip_key, attempted_at), INDEX (attempted_at)
) ENGINE=InnoDB
SQL,
];
