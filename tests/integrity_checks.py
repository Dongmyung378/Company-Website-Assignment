"""Additional checks for rollback boundaries and restricted database privileges."""
from __future__ import annotations

import json
import secrets
import subprocess


def run(t):
    if not t.rows('SELECT DATABASE() n')[0]['n'].endswith('_test'):
        raise RuntimeError('Disposable test database required.')
    tag = secrets.token_hex(5)
    password = secrets.token_urlsafe(24)
    admin_id, _, office = t.fixture('Rollback Admin ' + tag, True, password)
    _, role, _ = t.fixture('Rollback Role ' + tag, False, password)
    admin = t.Client()
    admin.login(admin_id, password)
    tables = ['employees', 'emergency_table', 'login', 'holiday_balance']
    before = [int(t.rows('SELECT COUNT(*) n FROM ' + table)[0]['n']) for table in tables]
    trigger = 'test_contact_failure_' + tag
    marker = 'rollback-contact-' + tag
    t.db('CREATE TRIGGER `' + trigger + '` BEFORE INSERT ON emergency_table FOR EACH ROW '
         "BEGIN IF NEW.emergency_name='" + marker + "' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic second-write failure'; END IF; END")
    try:
        result = admin.post('/add_employee.php', {
            'name': 'Rollback Employee ' + tag, 'salary': '34000', 'dob': '1991-01-01',
            'home_address': 'Synthetic address', 'hired_date': '2025-01-01', 'contract': 'Full-Time',
            'nin': 'TEST-ONLY', 'department_id': role['department_id'], 'position_id': role['position_id'],
            'office_id': office, 'emergency_name': marker, 'emergency_relationship': 'Test', 'emergency_phone': '000',
        })
        after = [int(t.rows('SELECT COUNT(*) n FROM ' + table)[0]['n']) for table in tables]
        t.check('F13 failed contact write rolls back employee, login and leave balance',
                result['status'] == 500 and before == after, counts_before=before, counts_after=after)
        t.check('F09 a workflow SQL failure returns no SQL, paths or stack trace', result['status'] == 500
                and not any(text in result['body'] for text in ['Synthetic second-write failure', 'SQLSTATE',
                            'Stack trace:', str(t.ROOT), 'mysqli_sql_exception']))
    finally:
        t.db('DROP TRIGGER IF EXISTS `' + trigger + '`')
        # A failed assertion must not strand a successful unexpected fixture creation.
        for row in t.rows('SELECT employee_id FROM employees WHERE name=?', ['Rollback Employee ' + tag]):
            t.FIXTURES.append(int(row['employee_id']))

    table = 'test_privilege_probe_' + tag
    code = r'''
require 'config/connection.php';
$identity=$conn->query('SELECT CURRENT_USER()')->fetch_row()[0];
$grants=$conn->query('SHOW GRANTS')->fetch_all(MYSQLI_NUM);
try {$conn->query('CREATE TABLE %s (id INT)'); $denied=false;}
catch(mysqli_sql_exception $e){$denied=in_array($e->getCode(),[1044,1142],true);}
echo json_encode(['identity'=>$identity,'grants'=>$grants,'ddl_denied'=>$denied]);
''' % table
    result = subprocess.run(t.PHP + ['-r', code], cwd=t.ROOT, env=t.ENV, capture_output=True,
                            text=True, encoding='utf-8', timeout=30, check=True)
    probe = json.loads(result.stdout)
    grants = '\n'.join(row[0] for row in probe['grants'])
    t.check('F15 the web runtime account cannot create schema objects or administer users',
            probe['ddl_denied'] and not probe['identity'].lower().startswith('root@')
            and not any(word in grants.upper() for word in ['ALL PRIVILEGES', 'WITH GRANT OPTION', 'FILE,', 'CREATE USER']))
    if not probe['ddl_denied']:
        t.db('DROP TABLE IF EXISTS `' + table + '`')

    for label, overrides in [('root runtime', {'DB_USER': 'root'}), ('empty runtime password', {'DB_PASSWORD': ''})]:
        env = dict(t.ENV, **overrides)
        result = subprocess.run(t.PHP + ['-r', "require 'config/connection.php';"], cwd=t.ROOT, env=env,
                                capture_output=True, text=True, encoding='utf-8', timeout=15)
        t.check('F15 configuration rejects ' + label, result.returncode != 0
                and 'Database connection unavailable.' in result.stderr and 'Stack trace:' not in result.stderr)
