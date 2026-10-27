"""HTTP and database regression checks against an explicitly disposable local DB.

Standard library only. A prepared, migrated database ending in _test is mandatory.
Uses runtime DB_USER for the web server and DB_SETUP_USER only for CLI fixtures.
No credentials or complete HTTP responses are included in the JSON report.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import html
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
ENV = dict(os.environ, COMPANY_TEST_DATABASE='YES')
if (ROOT / '.env').is_file():
    for line in (ROOT / '.env').read_text(encoding='utf-8').splitlines():
        match = re.fullmatch(r'(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)', line.strip())
        if not match:
            continue
        value = match[2].strip()
        if len(value) >= 2 and value[0] in ('"', "'") and value[-1] == value[0]:
            value = value[1:-1]
        else:
            value = re.sub(r'\s+#.*$', '', value).rstrip()
        ENV.setdefault(match[1], value)
PHP = [os.environ.get('PHP_BIN') or shutil.which('php') or 'php'] + json.loads(os.environ.get('PHP_ARGS_JSON', '[]'))
RESULT = {'checks': [], 'limitations': ['Loopback HTTP tests do not certify a production deployment or every possible input.']}
FIXTURES: list[int] = []
BASE = ''


def db(sql: str, params=(), *, operations=None):
    request = {'sql': sql, 'params': list(params)} if operations is None else {'transaction': operations}
    proc = subprocess.run(PHP + [str(ROOT / 'tests/db.php')], input=json.dumps(request), env=ENV,
                          capture_output=True, text=True, encoding='utf-8', timeout=30)
    if proc.returncode:
        raise RuntimeError('Fixture SQL failed: ' + proc.stderr[:1500])
    return json.loads(proc.stdout)


def rows(sql: str, params=()):
    return db(sql, params)['rows']


def check(name: str, passed: bool, **evidence):
    RESULT['checks'].append({'name': name, 'passed': bool(passed), 'evidence': evidence})
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)


def count_requests(ident: int):
    return int(rows('SELECT COUNT(*) n FROM holiday_requests WHERE employee_id=?', [ident])[0]['n'])


def balance(ident: int):
    return int(rows('SELECT annual_leave FROM holiday_balance WHERE employee_id=?', [ident])[0]['annual_leave'])


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, response, code, message, headers, newurl):
        return None


class Client:
    def __init__(self, base=None):
        self.base = base or BASE
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                                                  urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def request(self, path, data=None, headers=None):
        assert path.startswith('/') and not path.startswith('//')
        req = urllib.request.Request(self.base + path, data=urllib.parse.urlencode(data).encode() if data is not None else None,
                                     headers=headers or {})
        try:
            response = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        return {'status': response.code, 'headers': dict(response.headers.items()),
                'body': response.read().decode('utf-8', errors='strict')}

    def token(self, path):
        result = self.request(path)
        match = re.search(r'name="_csrf"\s+value="([^"]+)"', result['body'])
        if not match and path != '/change_password.php':
            # A request list may become empty after processing its only item.
            fallback = self.request('/change_password.php')
            match = re.search(r'name="_csrf"\s+value="([^"]+)"', fallback['body'])
        if not match:
            raise RuntimeError(f'No CSRF field at {path}: HTTP {result["status"]}')
        return html.unescape(match[1])

    def post(self, path, data, *, headers=None):
        return self.request(path, dict(data, _csrf=self.token(path)), headers)

    def login(self, ident, password):
        return self.post('/login.php', {'employee_id': ident, 'password': password})

    def sid(self):
        return next((cookie.value for cookie in self.jar if cookie.name == 'PHPSESSID'), None)


def fixture(label: str, admin: bool, password: str, forced=False):
    role = rows("SELECT p.position_id,p.department_id FROM position p JOIN department d USING(department_id) WHERE "
                + ("d.department_name='Executive'" if admin else "d.department_name<>'Executive'")
                + ' ORDER BY p.position_id LIMIT 1')[0]
    office = rows('SELECT office_id FROM office ORDER BY office_id LIMIT 1')[0]['office_id']
    record = db('INSERT INTO employees(name,salary,email,dob,home_address,hired_date,contract,nin,department_id,office_id,position_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [label, '30000', label.replace(' ', '').lower() + '@example.invalid', '1990-01-01',
                 'Synthetic test address', '2025-01-01', 'Full-Time', 'TEST-ONLY', role['department_id'], office, role['position_id']])
    ident = int(record['id'])
    FIXTURES.append(ident)
    proc = subprocess.run(PHP + [str(ROOT / 'tests/db.php')], input=json.dumps({'hash': password}), env=ENV,
                          capture_output=True, text=True, encoding='utf-8', timeout=30, check=True)
    hashed = json.loads(proc.stdout)['hash']
    db('UPDATE login SET password_hash=?,must_change_password=?,auth_version=auth_version+1 WHERE employee_id=?',
       [hashed, int(forced), ident])
    return ident, role, office


def security_checks():
    password = secrets.token_urlsafe(24)
    admin_id, admin_role, office = fixture('Regression Admin', True, password)
    user_id, normal_role, _ = fixture('Regression Employee', False, password)
    other_id, _, _ = fixture('Regression Other', False, password)
    admin, user = Client(), Client()
    admin.request('/login.php')
    before_sid = admin.sid()
    result = admin.login(admin_id, password)
    check('F07 session ID rotates on authentication', result['status'] == 302 and admin.sid() != before_sid)
    check('Employee login retains normal workflow', user.login(user_id, password)['status'] == 302)
    cookie = next(cookie for cookie in admin.jar if cookie.name == 'PHPSESSID')
    check('F07 local cookie is HttpOnly and SameSite Lax',
          any(key.lower() == 'httponly' for key in cookie._rest)
          and any(key.lower() == 'samesite' and value == 'Lax' for key, value in cookie._rest.items()))

    protected = ['admin_main.php', 'add_employee.php', 'update_employee.php', 'delete_employee.php', 'list_employees.php',
                 'employee_detail.php?id=' + str(user_id), 'check_holiday_requests.php', 'check_absence.php',
                 'salary_table.php', 'happybirthday.php', 'delete_records.php']
    anonymous = Client()
    check('All eleven administrator routes reject anonymous clients',
          all(anonymous.request('/' + path)['status'] == 302 for path in protected))
    check('All eleven administrator routes reject ordinary employees',
          all(user.request('/' + path)['status'] in (302, 403) for path in protected))
    check('All eleven administrator routes remain available to administrators',
          all(admin.request('/' + path)['status'] == 200 for path in protected))

    count_before = count_requests(other_id)
    result = user.post('/holiday_requests.php', {'employee_id': other_id, 'leave_type': 'Annual',
                       'start_date': '2027-01-31', 'end_date': '2027-02-02', 'reason': 'Ownership regression'})
    check('F01 foreign employee ID cannot create a victim leave request', count_requests(other_id) == count_before,
          response_status=result['status'])
    # A forged subject may be rejected or ignored; both preserve the session-derived owner.
    requests = rows('SELECT request_id FROM holiday_requests WHERE employee_id=?', [user_id])
    if not requests:
        user.post('/holiday_requests.php', {'leave_type': 'Annual', 'start_date': '2027-01-31',
                  'end_date': '2027-02-02', 'reason': 'Calendar regression'})
        requests = rows('SELECT request_id FROM holiday_requests WHERE employee_id=?', [user_id])
    check('Valid employee leave request succeeds', len(requests) == 1)
    request_id = int(requests[0]['request_id'])
    before_balance = balance(user_id)
    result = admin.post('/check_holiday_requests.php', {'update_status': '1', 'request_id': request_id, 'status': 'Approved',
                        'employee_id': other_id, 'date_diff': '-500', 'leave_type': 'Personal'})
    after_balance = balance(user_id)
    state = rows('SELECT status FROM holiday_requests WHERE request_id=?', [request_id])[0]['status']
    check('F06 approval derives owner, leave type and three calendar days from stored request',
          state == 'Approved' and after_balance == before_balance - 3 and balance(other_id) == 28)
    admin.post('/check_holiday_requests.php', {'update_status': '1', 'request_id': request_id, 'status': 'Approved'})
    check('F06 approval replay cannot debit twice', balance(user_id) == after_balance)
    admin.post('/check_holiday_requests.php', {'update_status': '1', 'request_id': '2147483647', 'status': 'Approved',
               'employee_id': user_id, 'date_diff': '1', 'leave_type': 'Annual'})
    check('F06 nonexistent request cannot change balances', balance(user_id) == after_balance)

    for month, expected in [('2027-01', 1), ('2027-02', 2)]:
        report = admin.post('/check_absence.php', {'month': month})
        # Locate the employee's table row, avoiding any unrelated seeded totals.
        row = next((match for match in re.findall(r'<tr[^>]*>(.*?)</tr>', report['body'], re.S)
                    if 'Regression Employee' in match), '')
        cells = [html.unescape(re.sub('<[^>]+>', '', cell)).strip() for cell in re.findall(r'<td[^>]*>(.*?)</td>', row, re.S)]
        check('F12 overlapping absence days in ' + month, report['status'] == 200 and str(expected) in cells,
              expected_days=expected, employee_row=cells)

    attacks = [('/salary_table.php?view_type=' + urllib.parse.quote('<script>window.__regressionXSS=1</script>'), None),
               ('/check_absence.php', {'month': '\"><script>window.__regressionXSS=1</script>'}),
               ('/holiday_requests.php', {'leave_type': '<script>window.__regressionXSS=1</script>',
                 'start_date': '2027-03-01', 'end_date': '2027-03-01', 'reason': 'Invalid enum'})]
    for path, data in attacks:
        client = user if path == '/holiday_requests.php' else admin
        result = client.post(path, data) if data is not None else client.request(path)
        check('F02 untrusted markup rejected at ' + path.split('?')[0],
              result['status'] in (400, 422) and '<script>window.__regressionXSS=1</script>' not in result['body'])
    reset = admin.request('/list_employees.php/\'%3Balert(1)%3B%27')
    check('F02 directory Reset uses a fixed safe URL', "window.location.href='list_employees.php'" in reset['body']
          or reset['status'] == 404)

    # CSRF validation must happen before data mutation, including the login and logout flows.
    mutations = ['add_employee.php', 'update_employee.php', 'delete_employee.php', 'holiday_requests.php',
                 'check_holiday_requests.php', 'change_password.php', 'delete_records.php', 'logout.php']
    check('F03 all eight mutation routes reject missing CSRF tokens',
          all(admin.request('/' + path, {'action': 'invalid'})['status'] == 403 for path in mutations))
    check('F03 login rejects missing CSRF token', Client().request('/login.php', {'employee_id': user_id, 'password': password})['status'] == 403)
    result = user.post('/change_password.php', {'current_password': password, 'new_password': secrets.token_urlsafe(24),
                       'confirm_password': 'ignored'}, headers={'Origin': 'https://foreign.invalid'})
    check('F03 foreign Origin is rejected even with a valid token', result['status'] == 403)

    user2 = Client()
    user2.login(user_id, password)
    new_password = secrets.token_urlsafe(24)
    bad = user.post('/change_password.php', {'current_password': 'incorrect current password',
                    'new_password': new_password, 'confirm_password': new_password})
    check('F03 password change requires the current password', bad['status'] in (400, 422))
    short = user.post('/change_password.php', {'current_password': password, 'new_password': 'abc', 'confirm_password': 'abc'})
    check('F05 short replacement password is rejected', short['status'] in (400, 422))
    changed = user.post('/change_password.php', {'current_password': password, 'new_password': new_password,
                        'confirm_password': new_password})
    check('Valid current-password change succeeds', changed['status'] in (200, 302)
          and Client().login(user_id, new_password)['status'] == 302)
    check('F04 changing a password revokes other existing sessions', user2.request('/check_my_holiday.php')['status'] == 302)

    forced_id, _, _ = fixture('Regression First Login', False, password, forced=True)
    forced = Client()
    first = forced.login(forced_id, password)
    check('F05 first sign-in requires changing the temporary password', first['status'] == 302
          and 'change_password.php' in first['headers'].get('Location', ''))
    check('F05 temporary-password account cannot open business pages',
          'change_password.php' in forced.request('/check_my_holiday.php')['headers'].get('Location', ''))

    stale_id, _, _ = fixture('Regression Stale Admin', True, password)
    stale = Client()
    stale.login(stale_id, password)
    db('UPDATE employees SET department_id=?,position_id=?,row_version=row_version+1 WHERE employee_id=?',
       [normal_role['department_id'], normal_role['position_id'], stale_id])
    check('F04 a demoted account loses administrator access on its next request',
          stale.request('/employee_detail.php?id=' + str(other_id))['status'] in (302, 403))
    db('DELETE FROM employees WHERE employee_id=?', [stale_id])
    check('F04 a deleted account loses access on its next request', stale.request('/main.php')['status'] == 302)

    # CSV is data, never a PHP diagnostic response or executable formula cell.
    db('UPDATE employees SET name=? WHERE employee_id=?', ['=1+1', other_id])
    exported = admin.request('/salary_table.php?export_csv=1')
    records = list(csv.reader(io.StringIO(exported['body'])))
    formula_row = next((row for row in records if row and row[0] == str(other_id)), [])
    check('F08 spreadsheet formula-like text is neutralized in CSV', len(formula_row) > 1 and formula_row[1].startswith("'="))
    check('F16 CSV export has eleven fields and contains no PHP diagnostics',
          exported['status'] == 200 and records and all(len(row) == 11 for row in records)
          and not re.search(r'Deprecated:|Warning:|Fatal error:|Stack trace:', exported['body']))

    # Failed logins must reach the configured throttle rather than retrying indefinitely.
    limited = Client()
    statuses = [limited.login(forced_id, 'wrong password').get('status') for _ in range(11)]
    check('F05 repeated failures are throttled', 429 in statuses, response_codes=statuses)


def main():
    global BASE
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=18037)
    parser.add_argument('--output', type=Path, default=ROOT / 'var/security-regression.json')
    args = parser.parse_args()
    if ENV.get('COMPANY_TEST_DATABASE') != 'YES' or not ENV.get('DB_NAME', '').endswith('_test'):
        raise SystemExit('Configure a disposable database whose DB_NAME ends in _test.')
    if not ENV.get('DB_SETUP_USER') or not ENV.get('DB_SETUP_PASSWORD'):
        raise SystemExit('DB_SETUP_USER and DB_SETUP_PASSWORD are required for private fixture setup.')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', args.port))
    BASE = f'http://127.0.0.1:{args.port}'
    ENV['APP_ORIGIN'] = BASE
    ENV['APP_SESSION_PATH'] = str(ROOT / 'var/test-sessions')
    (ROOT / 'var/test-sessions').mkdir(parents=True, exist_ok=True)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    stderr = (ROOT / 'var/test-server.log').open('w', encoding='utf-8')
    proc = subprocess.Popen(PHP + ['-d', 'display_errors=0', '-d', 'session.upload_progress.enabled=0',
                                  '-d', 'session.save_path=' + ENV['APP_SESSION_PATH'],
                                  '-S', f'127.0.0.1:{args.port}', '-t', str(ROOT / 'public')],
                            cwd=ROOT, env=ENV, stdout=stderr, stderr=stderr)
    try:
        for _ in range(60):
            try:
                response = Client().request('/login.php')
                if response['status'] == 200: break
            except OSError: pass
            time.sleep(.1)
        else: raise RuntimeError('Local test server did not become ready. See var/test-server.log.')
        RESULT['runtime'] = {'php': subprocess.check_output(PHP + ['-r', 'echo PHP_VERSION;'], text=True).strip(),
                             'mysql': rows('SELECT VERSION() v')[0]['v'], 'http': BASE}
        security_checks()
        import workflow_checks
        workflow_checks.run(sys.modules[__name__])
        import integrity_checks
        integrity_checks.run(sys.modules[__name__])
        import concurrency_checks
        concurrency_checks.run(sys.modules[__name__])
        import auth_checks
        auth_checks.run(sys.modules[__name__])
    except Exception as error:
        check('Regression suite completes without harness errors', False, error_type=type(error).__name__)
        raise
    finally:
        # Only remove rows created by this run, even if a check fails.
        try:
            for ident in FIXTURES:
                db('DELETE FROM login_attempts WHERE account_key=?', [hashlib.sha256(str(ident).encode()).hexdigest()])
                db('DELETE FROM employees WHERE employee_id=?', [ident])
                db('DELETE FROM delete_employee_logs WHERE employee_id=? OR deleted_by_id=?', [ident, ident])
        finally:
            proc.terminate()
            proc.wait(timeout=15)
            stderr.close()
            RESULT['server_stopped'] = proc.poll() is not None
            RESULT['passed'] = sum(item['passed'] for item in RESULT['checks'])
            RESULT['total'] = len(RESULT['checks'])
            args.output.write_text(json.dumps(RESULT, indent=2, ensure_ascii=False), encoding='utf-8')
    raise SystemExit(0 if RESULT['total'] and RESULT['passed'] == RESULT['total'] else 1)


if __name__ == '__main__':
    main()
