<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !defined('COMPANY_SETUP_CONTEXT') || COMPANY_SETUP_CONTEXT !== true) {
    http_response_code(404);
    exit;
}

function company_setup_statement(mysqli $db, string $sql, string $types = '', array $values = []): mysqli_stmt
{
    $statement = $db->prepare($sql);
    if ($types !== '') {
        $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    return $statement;
}

function company_setup_columns(mysqli $db, string $table): array
{
    $statement = company_setup_statement($db, 'SELECT COLUMN_NAME,IS_NULLABLE,EXTRA,CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?', 's', [$table]);
    $columns = [];
    foreach ($statement->get_result() as $column) {
        $columns[$column['COLUMN_NAME']] = $column;
    }
    $statement->close();
    return $columns;
}

function company_setup_upgrade(mysqli $db): void
{
    $employees = company_setup_columns($db, 'employees');
    if (!str_contains(strtolower($employees['employee_id']['EXTRA']), 'auto_increment')) {
        // Only the allocator attribute changes: preserve the INT type, IDs, and every FK.
        // MySQL otherwise rejects this change on an already referenced parent column.
        $foreignKeyChecks = (int)$db->query('SELECT @@SESSION.foreign_key_checks')->fetch_row()[0];
        $db->query('SET SESSION foreign_key_checks=0');
        try {
            $db->query('ALTER TABLE employees MODIFY employee_id INT NOT NULL AUTO_INCREMENT');
        } finally {
            $db->query('SET SESSION foreign_key_checks=' . $foreignKeyChecks);
        }
    }
    if (!isset($employees['row_version'])) {
        $db->query('ALTER TABLE employees ADD row_version INT NOT NULL DEFAULT 1');
    }
    $login = company_setup_columns($db, 'login');
    if ($login['password_hash']['IS_NULLABLE'] !== 'YES') {
        $db->query('ALTER TABLE login MODIFY password_hash VARCHAR(255) NULL');
    }
    if (!isset($login['auth_version'])) {
        $db->query('ALTER TABLE login ADD auth_version INT NOT NULL DEFAULT 1');
    }
    if (!isset($login['must_change_password'])) {
        $db->query('ALTER TABLE login ADD must_change_password BOOLEAN NOT NULL DEFAULT 1');
        // Preserve established passwords; only migrated defaults are forced to change below.
        $db->query('UPDATE login SET must_change_password=0 WHERE password_hash IS NOT NULL');
    }
    $logs = company_setup_columns($db, 'delete_employee_logs');
    $widths = ['employee_name'=>100,'employee_salary'=>20,'employee_department'=>100,'employee_position'=>100,'employee_nin'=>50,'deleted_by_name'=>100];
    $changes = [];
    foreach ($widths as $column => $width) {
        if ((int)$logs[$column]['CHARACTER_MAXIMUM_LENGTH'] < $width) {
            $changes[] = "MODIFY `$column` VARCHAR($width) NOT NULL";
        }
    }
    // Widen rather than truncate any retained historical reason.
    if ((int)$logs['reason']['CHARACTER_MAXIMUM_LENGTH'] < 65535) {
        $changes[] = 'MODIFY reason TEXT NULL';
    }
    if ($changes !== []) {
        $db->query('ALTER TABLE delete_employee_logs ' . implode(',', $changes));
    }
}

function company_setup_has_migration(mysqli $db, string $version): bool
{
    $statement = company_setup_statement($db, 'SELECT 1 FROM schema_migrations WHERE version=?', 's', [$version]);
    $exists = $statement->get_result()->num_rows !== 0;
    $statement->close();
    return $exists;
}

function company_setup_mark_migration(mysqli $db, string $version): void
{
    company_setup_statement($db, 'INSERT IGNORE INTO schema_migrations(version) VALUES(?)', 's', [$version])->close();
}

function company_setup_install_objects(mysqli $db): void
{
    $version = '20261027_secure_database_objects';
    $installed = company_setup_has_migration($db, $version);
    $statements = require __DIR__ . '/objects.php';
    $objects = [['TRIGGER','employee_holiday_insert'],['TRIGGER','employee_password'],['PROCEDURE','delete_employee_with_log']];
    foreach ($objects as $index => [$kind,$name]) {
        $query = $kind === 'TRIGGER'
            ? 'SELECT ACTION_STATEMENT AS body FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND trigger_name=?'
            : "SELECT ROUTINE_DEFINITION AS body FROM information_schema.routines WHERE routine_schema=DATABASE() AND routine_type='PROCEDURE' AND routine_name=?";
        $statement = company_setup_statement($db, $query, 's', [$name]);
        $existing = $statement->get_result()->fetch_assoc();
        $statement->close();
        if ($installed && $existing !== null) {
            continue;
        }
        $create = $statements[$index * 2 + 1];
        $body = substr($create, strpos($create, "\nBEGIN") + 1);
        $normalize = static fn(string $value): string => strtoupper(preg_replace('/\s+/', '', $value) ?? $value);
        if ($existing !== null && is_string($existing['body']) && $normalize($existing['body']) === $normalize($body)) {
            continue;
        }
        // Unchanged procedures are retained: DROP would discard routine-specific grants.
        $db->query($statements[$index * 2]);
        $db->query($create);
    }
    company_setup_mark_migration($db, $version);
}

function company_setup_read_csv(): array
{
    $path = __DIR__ . '/seeds/employees.csv';
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the fictional employee seed.');
    }
    try {
        $expected = ['id','name','position','department','salary','email','dob','office','home_address','hired_date','contract','nin','emergency_name','emergency_relationship','emergency_phone'];
        if (fgetcsv($handle, null, ',', '"', '') !== $expected) {
            throw new RuntimeException('Unexpected employee CSV header.');
        }
        $records = [];
        while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($record) !== count($expected) || !ctype_digit($record[0]) || (int)$record[0] < 1 || (int)$record[0] > 2147483647) {
                throw new RuntimeException('Invalid employee seed row.');
            }
            foreach ([6,9] as $index) {
                $date = DateTimeImmutable::createFromFormat('!d/m/Y', $record[$index]);
                if ($date === false || $date->format('d/m/Y') !== $record[$index]) {
                    throw new RuntimeException('Invalid date in employee seed.');
                }
                $record[$index] = $date->format('Y-m-d');
            }
            $record[0] = (int)$record[0];
            $records[] = $record;
        }
        return $records;
    } finally {
        fclose($handle);
    }
}

function company_setup_seed(mysqli $db): void
{
    foreach (company_setup_read_csv() as $record) {
        company_setup_statement($db, 'INSERT INTO materials(id,name,position,department,salary,email,dob,office,home_address,hired_date,contract,nin,emergency_name,emergency_relationship,emergency_phone) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', 'issssssssssssss', $record)->close();
    }
    // Stable fresh-database IDs preserve the documented Executive=2 role rule.
    $departments = $db->query("SELECT DISTINCT department FROM materials ORDER BY CASE department WHEN 'Operations' THEN 0 WHEN 'Executive' THEN 1 ELSE 2 END,department");
    foreach ($departments as $row) {
        company_setup_statement($db, 'INSERT IGNORE INTO department(department_name) VALUES(?)', 's', [$row['department']])->close();
    }
    $db->query('INSERT IGNORE INTO office(office_name) SELECT DISTINCT office FROM materials ORDER BY office');
    $db->query('INSERT IGNORE INTO `position`(position_name,department_id) SELECT DISTINCT m.position,d.department_id FROM materials m JOIN department d ON d.department_name=m.department ORDER BY d.department_id,m.position');
    $db->query('INSERT INTO employees(employee_id,name,salary,email,dob,home_address,hired_date,contract,nin,department_id,office_id,position_id) SELECT m.id,m.name,m.salary,m.email,m.dob,m.home_address,m.hired_date,m.contract,m.nin,d.department_id,o.office_id,p.position_id FROM materials m JOIN department d ON d.department_name=m.department JOIN office o ON o.office_name=m.office JOIN `position` p ON p.position_name=m.position AND p.department_id=d.department_id ORDER BY m.id');
    $db->query('INSERT INTO emergency_table(employee_id,emergency_name,emergency_relationship,emergency_phone) SELECT m.id,m.emergency_name,m.emergency_relationship,m.emergency_phone FROM materials m JOIN employees e ON e.employee_id=m.id');
}

function company_setup_private_directory(): string
{
    $directory = dirname(__DIR__) . '/var/private';
    if (is_link($directory) || is_link(dirname($directory))) {
        throw new RuntimeException('Private credential directory must not be a symbolic link.');
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private credential directory.');
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $identity = getenv('USERDOMAIN') . '\\' . getenv('USERNAME');
        if (!preg_match('/^[A-Za-z0-9_. -]+\\\\[A-Za-z0-9_. -]+$/', $identity)) {
            throw new RuntimeException('Cannot determine the current Windows account for private credentials.');
        }
        $process = proc_open(['icacls', str_replace('/', '\\', $directory), '/inheritance:r', '/grant:r', $identity . ':(OI)(CI)F'], [1=>['pipe','w'],2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to restrict the private credential directory ACL.');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Unable to restrict the private credential directory ACL.');
        }
    } elseif (!chmod($directory, 0700)) {
        throw new RuntimeException('Unable to restrict the private credential directory permissions.');
    }
    return $directory;
}

function company_setup_write_private(array $content, string $kind = 'credentials'): string
{
    $directory = company_setup_private_directory();
    $path = $directory . '/' . $kind . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.json';
    $handle = fopen($path, 'x+b');
    if ($handle === false) {
        throw new RuntimeException('Unable to create a private credential export.');
    }
    try {
        if (PHP_OS_FAMILY !== 'Windows' && !chmod($path, 0600)) {
            throw new RuntimeException('Unable to restrict credential file permissions.');
        }
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Unable to write the private credential export.');
        }
    } finally {
        fclose($handle);
    }
    return $path;
}

function company_setup_provision(mysqli $db, bool $includeInactive, bool $revokeLegacy): array
{
    $accounts = [];
    $rows = $db->query('SELECT employee_id,password_hash FROM login ORDER BY employee_id FOR UPDATE');
    foreach ($rows as $row) {
        $hash = $row['password_hash'];
        $isLegacy = $revokeLegacy && $hash !== null && password_verify('0000', $hash);
        if (!$isLegacy && !($includeInactive && $hash === null)) {
            continue;
        }
        $password = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $newHash = password_hash($password, $algorithm);
        company_setup_statement($db, 'UPDATE login SET password_hash=?,auth_version=auth_version+1,must_change_password=1 WHERE employee_id=?', 'si', [$newHash,(int)$row['employee_id']])->close();
        $accounts[] = ['employee_id'=>(int)$row['employee_id'],'temporary_password'=>$password];
    }
    return $accounts;
}

function company_setup_connection(bool $createDatabase): array
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $config = require dirname(__DIR__) . '/config/database.php';
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $config['database'])) {
        throw new RuntimeException('DB_NAME must contain 1-64 letters, digits, or underscores.');
    }
    $db = new mysqli($config['host'], $config['username'], $config['password'], '', $config['port']);
    $db->set_charset('utf8mb4');
    if ($createDatabase) {
        $db->query('CREATE DATABASE IF NOT EXISTS `' . $config['database'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
    $db->select_db($config['database']);
    return [$db,$config];
}

function company_setup_main(array $arguments, bool $migrateOnly): int
{
    umask(0077);
    ini_set('display_errors', '0');
    $options = array_slice($arguments, 1);
    if (in_array('--help', $options, true)) {
        fwrite(STDOUT, 'Usage: php scripts/' . ($migrateOnly ? 'migrate' : 'setup') . ".php [--provision-inactive] [--with-extensions]\nUses DB_SETUP_USER/PASSWORD. Existing passwords and historical records are preserved.\n");
        return 0;
    }
    if (array_diff($options, ['--provision-inactive','--with-extensions']) !== []) {
        fwrite(STDERR, "Unknown setup option; use --help.\n");
        return 1;
    }
    $db = null;
    $lockName = null;
    $privatePath = null;
    $committed = false;
    try {
        [$db,$config] = company_setup_connection(!$migrateOnly);
        $lockName = 'company_setup_' . substr(hash('sha256', $config['database']), 0, 48);
        $statement = company_setup_statement($db, 'SELECT GET_LOCK(?,30) AS acquired', 's', [$lockName]);
        if ((int)$statement->get_result()->fetch_assoc()['acquired'] !== 1) {
            throw new RuntimeException('Another schema setup is running.');
        }
        $statement->close();
        foreach (require __DIR__ . '/schema.php' as $sql) {
            $db->query($sql);
        }
        company_setup_upgrade($db);
        company_setup_install_objects($db);
        if (in_array('--with-extensions', $options, true)) {
            foreach (require __DIR__ . '/extension_schema.php' as $sql) {
                $db->query($sql);
            }
        }
        $seedVersion = '20261027_initial_demo_seed';
        $securityVersion = '20261027_unique_credentials';
        $seedRecorded = company_setup_has_migration($db, $seedVersion);
        $hasData = false;
        foreach (['materials','employees','delete_employee_logs'] as $table) {
            if ((int)$db->query("SELECT COUNT(*) AS n FROM `$table`")->fetch_assoc()['n'] !== 0) {
                $hasData = true;
            }
        }
        $seedFresh = !$migrateOnly && !$seedRecorded && !$hasData;
        $revokeLegacy = !company_setup_has_migration($db, $securityVersion);
        $db->begin_transaction();
        if ($seedFresh) {
            company_setup_seed($db);
        }
        $db->query('INSERT IGNORE INTO holiday_balance(employee_id,annual_leave,sick_leave,personal_leave) SELECT employee_id,28,10,5 FROM employees');
        $db->query('INSERT IGNORE INTO login(employee_id,password_hash,auth_version,must_change_password) SELECT employee_id,NULL,1,1 FROM employees');
        $accounts = company_setup_provision($db, $seedFresh || in_array('--provision-inactive', $options, true), $revokeLegacy);
        if ($accounts !== []) {
            $privatePath = company_setup_write_private(['generated_at'=>gmdate(DATE_ATOM),'database'=>$config['database'],'accounts'=>$accounts]);
        }
        // A pre-existing database is never reseeded, even after legitimate employee deletions.
        company_setup_mark_migration($db, $seedVersion);
        company_setup_mark_migration($db, $securityVersion);
        $db->commit();
        $committed = true;
        fwrite(STDOUT, ($migrateOnly ? 'Migration' : 'Setup') . " complete. Existing accounts and history preserved.\n");
        if ($privatePath !== null) {
            fwrite(STDOUT, 'Unique temporary credentials (' . count($accounts) . ') written privately to: ' . $privatePath . "\n");
        } else {
            fwrite(STDOUT, "No account passwords changed.\n");
        }
        return 0;
    } catch (Throwable $error) {
        if ($db instanceof mysqli && !$committed) {
            try { $db->rollback(); } catch (Throwable) {}
        }
        if ($privatePath !== null && !$committed) {
            unlink($privatePath);
        }
        error_log('Company database setup failed: ' . $error->getMessage());
        fwrite(STDERR, "Database setup failed. Review the CLI error log and database configuration; no credentials are printed.\n");
        return 1;
    } finally {
        if ($db instanceof mysqli) {
            if ($lockName !== null) {
                try { company_setup_statement($db, 'SELECT RELEASE_LOCK(?)', 's', [$lockName])->close(); } catch (Throwable) {}
            }
            $db->close();
        }
    }
}
