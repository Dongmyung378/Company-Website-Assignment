"""Additional real HTTP workflow checks for security_regression's disposable DB.

The parent harness owns the server, credentials, result writer, and employee
cleanup. This module never reports passwords, hashes, or complete responses.
"""
from __future__ import annotations

import html
import re
import secrets


def _field(body: str, name: str) -> str | None:
    """Read one hidden form value without retaining it in test evidence."""
    match = re.search(r'name="' + re.escape(name) + r'"\s+value="([^"]*)"', body)
    return html.unescape(match[1]) if match else None


def _employee_form(ident, role, office, name, version, percentage="0"):
    return {
        'update_employee': '1', 'employee_id': ident, 'row_version': version,
        'name': name, 'salary_increase': percentage,
        'home_address': 'Synthetic workflow address', 'contract': 'Full-Time',
        'department_id': role['department_id'], 'position_id': role['position_id'],
        'office_id': office, 'emergency_name': 'Synthetic contact',
        'emergency_relationship': 'Test contact', 'emergency_phone': 'TEST-PHONE',
    }


def _create_employee(t, admin, role, office, tag):
    name = 'Workflow Created ' + tag
    before = t.rows('SELECT COUNT(*) n FROM employees WHERE name=?', [name])[0]['n']
    response = admin.post('/add_employee.php', {
        'name': name, 'salary': '43000.50', 'dob': '1990-01-01',
        'home_address': 'Synthetic new employee address', 'hired_date': '2025-01-01',
        'contract': 'Full-Time', 'nin': 'WORKFLOW-TEST-NIN',
        'department_id': role['department_id'], 'office_id': office,
        'position_id': role['position_id'], 'emergency_name': 'Synthetic New Contact',
        'emergency_relationship': 'Test contact', 'emergency_phone': 'TEST-ONLY-PHONE',
    })
    employees = t.rows('SELECT employee_id FROM employees WHERE name=? ORDER BY employee_id', [name])
    for employee in employees:
        t.FIXTURES.append(int(employee['employee_id']))
    created = int(employees[0]['employee_id']) if len(employees) == 1 else None
    t.check('F13 HTTP employee creation allocates one new employee ID',
            int(before) == 0 and response['status'] == 302 and created is not None,
            response_status=response['status'], matching_rows=len(employees))

    first_display = admin.request('/add_employee.php')
    match = re.search(r'Temporary password \(shown once\):\s*<code>([^<]+)</code>', first_display['body'])
    temporary = html.unescape(match[1]) if match else None
    second_display = admin.request('/add_employee.php')
    t.check('F05 new employee temporary credential is privately shown only once',
            first_display['status'] == 200 and second_display['status'] == 200
            and temporary is not None and re.fullmatch(r'[A-Za-z0-9_-]{32}', temporary) is not None
            and temporary not in second_display['body'] and 'Temporary password (shown once)' not in second_display['body'],
            first_response_status=first_display['status'], second_response_status=second_display['status'])

    if created is None:
        t.check('F13 employee, emergency contact, balance, and login commit together', False,
                reason='HTTP employee creation did not produce one employee')
        return None, temporary
    related = t.rows(
        'SELECT e.salary,e.row_version,c.emergency_name,c.emergency_relationship,c.emergency_phone,'
        'b.annual_leave,b.sick_leave,b.personal_leave,(l.password_hash IS NOT NULL) active,'
        'l.must_change_password,l.auth_version FROM employees e '
        'LEFT JOIN emergency_table c ON c.employee_id=e.employee_id '
        'LEFT JOIN holiday_balance b ON b.employee_id=e.employee_id '
        'LEFT JOIN login l ON l.employee_id=e.employee_id WHERE e.employee_id=?', [created])
    row = related[0] if len(related) == 1 else {}
    t.check('F13 employee, emergency contact, balance, and login commit together',
            row.get('salary') == '43000.50' and int(row.get('row_version') or 0) == 1
            and row.get('emergency_name') == 'Synthetic New Contact'
            and row.get('emergency_relationship') == 'Test contact'
            and row.get('emergency_phone') == 'TEST-ONLY-PHONE'
            and [int(row.get(key) or 0) for key in ['annual_leave', 'sick_leave', 'personal_leave']] == [28, 10, 5]
            and int(row.get('active') or 0) == 1 and int(row.get('must_change_password') or 0) == 1
            and int(row.get('auth_version') or 0) >= 1)
    return created, temporary


def _first_password_change(t, ident, temporary):
    if ident is None or temporary is None:
        t.check('F05 HTTP-created employee rejects the former shared default', False,
                reason='New employee temporary credential unavailable')
        t.check('F05 HTTP-created employee completes the first-password-change workflow', False,
                reason='New employee temporary credential unavailable')
        return

    known_default = t.Client()
    rejected = known_default.login(ident, '0000')
    inaccessible = known_default.request('/check_my_holiday.php')
    t.check('F05 HTTP-created employee rejects the former shared default',
            rejected['status'] != 302 and inaccessible['status'] == 302
            and 'login.php' in inaccessible['headers'].get('Location', ''),
            response_status=rejected['status'])

    employee = t.Client()
    first = employee.login(ident, temporary)
    blocked = employee.request('/holiday_requests.php')
    required = first['status'] == 302 and 'change_password.php' in first['headers'].get('Location', '')
    required = required and blocked['status'] == 302 and 'change_password.php' in blocked['headers'].get('Location', '')
    replacement = secrets.token_urlsafe(24)
    if not required:
        t.check('F05 HTTP-created employee completes the first-password-change workflow', False,
                reason='Temporary credential did not enter the required-change flow')
        return
    changed = employee.post('/change_password.php', {
        'current_password': temporary, 'new_password': replacement, 'confirm_password': replacement,
    })
    account = t.rows('SELECT must_change_password FROM login WHERE employee_id=?', [ident])
    fresh = t.Client()
    signed_in = fresh.login(ident, replacement)
    business = fresh.request('/holiday_requests.php')
    expired = t.Client().login(ident, temporary)
    t.check('F05 HTTP-created employee completes the first-password-change workflow',
            changed['status'] in (200, 302) and account and int(account[0]['must_change_password']) == 0
            and signed_in['status'] == 302 and business['status'] == 200 and expired['status'] != 302,
            change_response_status=changed['status'], business_response_status=business['status'])


def _employee_updates(t, admin, password, tag):
    a_id, role, office = t.fixture('Workflow Update A ' + tag, False, password)
    b_id, _, _ = t.fixture('Workflow Update B ' + tag, False, password)
    t.db('UPDATE employees SET salary=? WHERE employee_id=?', ['70000', b_id])
    tab_a = admin.post('/update_employee.php', {'search_employee': '1', 'employee_id': a_id})
    tab_b = admin.post('/update_employee.php', {'search_employee': '1', 'employee_id': b_id})
    version_a = _field(tab_a['body'], 'row_version')
    version_b = _field(tab_b['body'], 'row_version')
    t.check('F10 employee search forms provide independent record versions',
            tab_a['status'] == 200 and tab_b['status'] == 200
            and version_a is not None and version_b is not None)
    payload = _employee_form(a_id, role, office, 'Workflow Updated A ' + tag, version_a or '0', '10')
    result = admin.post('/update_employee.php', payload)
    records = t.rows('SELECT employee_id,salary,row_version FROM employees WHERE employee_id IN (?,?)', [a_id, b_id])
    by_id = {int(row['employee_id']): row for row in records}
    contact = t.rows('SELECT emergency_name FROM emergency_table WHERE employee_id=?', [a_id])
    t.check('F10 submitting the first tab updates its exact employee and current salary',
            result['status'] == 302 and float(by_id[a_id]['salary']) == 33000
            and float(by_id[b_id]['salary']) == 70000
            and int(by_id[a_id]['row_version']) == int(version_a or 0) + 1
            and len(contact) == 1 and contact[0]['emergency_name'] == 'Synthetic contact',
            response_status=result['status'])
    stale = admin.post('/update_employee.php', payload)
    after = t.rows('SELECT salary,row_version FROM employees WHERE employee_id=?', [a_id])[0]
    t.check('F10 stale employee form returns conflict without a second raise',
            stale['status'] == 409 and float(after['salary']) == 33000
            and int(after['row_version']) == int(by_id[a_id]['row_version']),
            response_status=stale['status'])
    return a_id, role, office


def _leave_decisions(t, admin, password, ident, tag):
    employee = t.Client()
    employee.login(ident, password)
    before_requests = t.count_requests(ident)
    malformed = [
        ('2028-02-30', '2028-03-01'),
        ('2028-04-02', '2028-04-01'),
    ]
    statuses = []
    for start, end in malformed:
        response = employee.post('/holiday_requests.php', {
            'leave_type': 'Annual', 'start_date': start, 'end_date': end,
            'reason': 'Invalid workflow dates ' + tag,
        })
        statuses.append(response['status'])
    t.check('F14 leave submission rejects impossible and reversed calendar dates',
            all(status in (400, 422) for status in statuses) and t.count_requests(ident) == before_requests,
            response_statuses=statuses)

    reason = 'Workflow insufficient balance ' + tag
    submitted = employee.post('/holiday_requests.php', {
        'leave_type': 'Annual', 'start_date': '2028-04-01', 'end_date': '2028-04-20', 'reason': reason,
    })
    pending = t.rows('SELECT request_id FROM holiday_requests WHERE employee_id=? AND reason=?', [ident, reason])
    if len(pending) != 1:
        t.check('F06 insufficient approval leaves its request pending and balance intact', False,
                reason='Valid pending request was not created', response_status=submitted['status'])
        t.check('F06 rejection is terminal and never debits leave balance', False,
                reason='Valid pending request was not created')
        return
    request_id = int(pending[0]['request_id'])
    t.db('UPDATE holiday_balance SET annual_leave=? WHERE employee_id=?', [5, ident])
    insufficient = admin.post('/check_holiday_requests.php', {
        'update_status': '1', 'request_id': request_id, 'status': 'Approved',
    })
    status = t.rows('SELECT status FROM holiday_requests WHERE request_id=?', [request_id])[0]['status']
    t.check('F06 insufficient approval leaves its request pending and balance intact',
            submitted['status'] == 302 and insufficient['status'] == 409 and status == 'Pending'
            and t.balance(ident) == 5,
            response_status=insufficient['status'])
    rejected = admin.post('/check_holiday_requests.php', {
        'update_status': '1', 'request_id': request_id, 'status': 'Rejected',
    })
    replay = admin.post('/check_holiday_requests.php', {
        'update_status': '1', 'request_id': request_id, 'status': 'Approved',
    })
    status = t.rows('SELECT status FROM holiday_requests WHERE request_id=?', [request_id])[0]['status']
    t.check('F06 rejection is terminal and never debits leave balance',
            rejected['status'] == 302 and replay['status'] == 409 and status == 'Rejected'
            and t.balance(ident) == 5,
            rejection_response_status=rejected['status'], replay_response_status=replay['status'])


def _long_deletion(t, password, normal_role, office, tag):
    operator_id, _, _ = t.fixture('Workflow Delete Operator ' + tag, True, password)
    employee_id, _, _ = t.fixture('Workflow Delete Target ' + tag, False, password)
    operator_name = ('Workflow operator ' + tag).ljust(100, 'O')
    employee_name = ('Workflow employee ' + tag).ljust(100, 'E')
    position_name = ('Workflow position ' + tag).ljust(100, 'P')
    nin = ('WORKFLOW-' + tag).ljust(50, 'N')
    reason = ('Workflow deletion reason ' + tag).ljust(255, 'R')
    position = t.db('INSERT INTO `position`(position_name,department_id) VALUES(?,?)',
                    [position_name, normal_role['department_id']])
    position_id = int(position['id'])
    try:
        t.db('UPDATE employees SET name=? WHERE employee_id=?', [operator_name, operator_id])
        t.db('UPDATE employees SET name=?,nin=?,position_id=? WHERE employee_id=?',
             [employee_name, nin, position_id, employee_id])
        t.db('INSERT INTO emergency_table(employee_id,emergency_name,emergency_relationship,emergency_phone) VALUES(?,?,?,?)',
             [employee_id, 'Synthetic Delete Contact', 'Test contact', 'TEST-DELETE-PHONE'])
        operator = t.Client()
        signed_in = operator.login(operator_id, password)
        result = operator.post('/delete_employee.php', {'employee_id': employee_id, 'deleted_reason': reason})
        snapshots = t.rows('SELECT employee_name,employee_salary,employee_position,employee_nin,deleted_by_id,deleted_by_name,reason FROM delete_employee_logs WHERE employee_id=?', [employee_id])
        snapshot = snapshots[0] if len(snapshots) == 1 else {}
        t.check('F11 HTTP deletion preserves full-width employee and operator snapshots',
                signed_in['status'] == 302 and result['status'] == 302 and len(snapshots) == 1
                and snapshot.get('employee_name') == employee_name
                and snapshot.get('employee_position') == position_name
                and snapshot.get('employee_nin') == nin
                and snapshot.get('employee_salary') == '30000'
                and int(snapshot.get('deleted_by_id') or 0) == operator_id
                and snapshot.get('deleted_by_name') == operator_name and snapshot.get('reason') == reason,
                response_status=result['status'], snapshot_rows=len(snapshots),
                employee_name_length=len(employee_name), position_name_length=len(position_name),
                operator_name_length=len(operator_name), nin_length=len(nin), reason_length=len(reason))
        child_counts = [int(t.rows('SELECT COUNT(*) n FROM ' + table + ' WHERE employee_id=?', [employee_id])[0]['n'])
                        for table in ['employees', 'login', 'holiday_balance', 'emergency_table']]
        t.check('F11 successful audited deletion removes only its employee and dependent records',
                result['status'] == 302 and child_counts == [0, 0, 0, 0]
                and len(t.rows('SELECT employee_id FROM employees WHERE employee_id=?', [operator_id])) == 1,
                remaining_child_counts=child_counts)
        repeated = operator.post('/delete_employee.php', {'employee_id': employee_id, 'deleted_reason': 'Repeated workflow deletion'})
        log_count = int(t.rows('SELECT COUNT(*) n FROM delete_employee_logs WHERE employee_id=?', [employee_id])[0]['n'])
        t.check('F11 deleting an unknown employee cannot create a misleading audit entry',
                repeated['status'] == 404 and log_count == 1,
                response_status=repeated['status'], snapshot_rows=log_count)
    finally:
        # Restore only our fixture's reference before removing the synthetic dimension row.
        t.db('UPDATE employees SET position_id=? WHERE employee_id=?', [normal_role['position_id'], employee_id])
        t.db('DELETE FROM `position` WHERE position_id=? AND position_name=?', [position_id, position_name])


def run(t):
    database = t.rows('SELECT DATABASE() AS name')[0]['name']
    if not isinstance(database, str) or not database.endswith('_test'):
        raise RuntimeError('Workflow checks require an explicitly disposable _test database.')
    tag = secrets.token_hex(4)
    password = secrets.token_urlsafe(24)
    admin_id, _, office = t.fixture('Workflow Admin ' + tag, True, password)
    admin = t.Client()
    login = admin.login(admin_id, password)
    if login['status'] != 302:
        raise RuntimeError('Workflow fixture administrator could not sign in.')
    a_id, normal_role, office = _employee_updates(t, admin, password, tag)
    created, temporary = _create_employee(t, admin, normal_role, office, tag)
    _first_password_change(t, created, temporary)
    _leave_decisions(t, admin, password, a_id, tag)
    _long_deletion(t, password, normal_role, office, tag)
