"""Additional authentication, request-shape, expiry and private-error checks.

Invoked by security_regression.py with its explicitly disposable database only.
Auxiliary servers inherit the restricted runtime identity and bind loopback.
"""
from __future__ import annotations

from contextlib import contextmanager
import json
import secrets
import socket
import subprocess
import time


def _clean_response(response):
    return not any(marker in response['body'] for marker in (
        'Warning:', 'Fatal error:', 'Deprecated:', 'Stack trace:',
        'mysqli_sql_exception', 'mysqli->__construct', 'Company-Website-Assignment',
    ))


@contextmanager
def _server(t, port, label, overrides):
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', port))
    base = f'http://127.0.0.1:{port}'
    environment = dict(t.ENV, APP_ORIGIN=base, **overrides)
    sessions = t.ROOT / 'var' / ('test-auth-' + label + '-sessions')
    sessions.mkdir(parents=True, exist_ok=True)
    log = (t.ROOT / 'var' / ('test-auth-' + label + '-server.log')).open('w', encoding='utf-8')
    process = None
    try:
        process = subprocess.Popen(t.PHP + [
            '-d', 'display_errors=0', '-d', 'session.upload_progress.enabled=0',
            '-d', 'session.save_path=' + str(sessions), '-S', f'127.0.0.1:{port}',
            '-t', str(t.ROOT / 'public'),
        ], cwd=t.ROOT, env=environment, stdout=log, stderr=log,
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        ready = False
        for _ in range(80):
            if process.poll() is not None:
                break
            try:
                t.Client(base=base).request('/login.php')
                ready = True
                break
            except OSError:
                time.sleep(.1)
        if not ready:
            raise RuntimeError('The isolated auxiliary PHP server did not start.')
        yield base
    finally:
        if process is not None:
            if process.poll() is None:
                process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
            t.check('Owned auxiliary server stopped: ' + label, process.poll() is not None)
        log.close()


def run(t):
    password = secrets.token_urlsafe(24)
    administrator_id, _, _ = t.fixture('Regression Extra Admin', True, password)
    employee_id, _, _ = t.fixture('Regression Extra Employee', False, password)
    administrator, employee = t.Client(), t.Client()
    administrator.login(administrator_id, password)
    employee.login(employee_id, password)

    malformed = [
        ('salary query array', administrator.request('/salary_table.php?view_type%5B%5D=annual')),
        ('holiday POST array', employee.post('/holiday_requests.php', {
            'request_holiday': '1', 'employee_id': employee_id, 'leave_type[]': 'Annual',
            'start_date': '2027-05-03', 'end_date': '2027-05-04',
        })),
        ('login POST array', t.Client().post('/login.php', {
            'employee_id[]': employee_id, 'password': password,
        })),
    ]
    for label, response in malformed:
        t.check('F09 malformed ' + label + ' returns a private 400',
                response['status'] == 400 and _clean_response(response), status=response['status'])

    confirmation = administrator.request('/logout.php')
    still_authenticated = administrator.request('/admin_main.php')
    t.check('F03 GET logout displays confirmation and preserves authentication',
            confirmation['status'] == 200 and 'name="_csrf"' in confirmation['body']
            and still_authenticated['status'] == 200)
    rejected = administrator.request('/logout.php', {})
    t.check('F03 logout POST without a token is rejected without signing out',
            rejected['status'] == 403 and administrator.request('/admin_main.php')['status'] == 200)
    signed_out = administrator.post('/logout.php', {})
    after_logout = administrator.request('/admin_main.php')
    t.check('Valid logout POST signs out and clears authenticated access',
            signed_out['status'] == 302 and signed_out['headers'].get('Location') == 'login.php'
            and after_logout['status'] == 302 and after_logout['headers'].get('Location') == 'login.php')

    try:
        with _server(t, 18039, 'unavailable', {'DB_NAME': 'nonexistent_' + secrets.token_hex(8) + '_test'}) as base:
            unavailable = t.Client(base=base).request('/login.php')
            t.check('F09 unavailable database returns a generic private 503',
                    unavailable['status'] == 503 and _clean_response(unavailable)
                    and 'service is temporarily unavailable' in unavailable['body'].lower(),
                    status=unavailable['status'])
    except (OSError, RuntimeError) as error:
        t.check('F09 isolated unavailable-database server can run', False, error_type=type(error).__name__)

    try:
        with _server(t, 18040, 'expiry', {'SESSION_IDLE_SECONDS': '1', 'SESSION_ABSOLUTE_SECONDS': '3'}) as base:
            idle, active = t.Client(base=base), t.Client(base=base)
            idle_login = idle.login(employee_id, password)
            active_login = active.login(employee_id, password)
            start = time.monotonic()
            idle_status = None
            absolute_status = None
            successful_activity = 0
            # Integer server timestamps have a one-second boundary. Test eventual
            # expiry with margin, never an exact wall-clock transition instant.
            while time.monotonic() - start < 5.2:
                response = active.request('/main.php')
                if response['status'] == 302 and response['headers'].get('Location') == 'login.php':
                    absolute_status = 302
                elif response['status'] == 200:
                    successful_activity += 1
                if idle_status is None and time.monotonic() - start >= 2.2:
                    response = idle.request('/main.php')
                    idle_status = response['status'] if response['headers'].get('Location') == 'login.php' else 0
                if absolute_status == 302 and idle_status is not None:
                    break
                time.sleep(.35)
            t.check('F07 idle session expires without activity',
                    idle_login['status'] == 302 and idle_status == 302,
                    idle_limit_seconds=1, status=idle_status)
            t.check('F07 absolute session expires despite repeated activity',
                    active_login['status'] == 302 and absolute_status == 302 and successful_activity >= 2,
                    absolute_limit_seconds=3, successful_requests=successful_activity, status=absolute_status)
    except (OSError, RuntimeError) as error:
        t.check('F07 isolated expiry server can run', False, error_type=type(error).__name__)

    helper = subprocess.run(t.PHP + [str(t.ROOT / 'tests/helper_checks.php')], cwd=t.ROOT, env=t.ENV,
                            capture_output=True, text=True, encoding='utf-8', timeout=30)
    try:
        evidence = json.loads(helper.stdout) if helper.returncode == 0 else None
    except json.JSONDecodeError:
        evidence = None
    if not isinstance(evidence, dict) or not isinstance(evidence.get('checks'), list):
        t.check('Shared date, CSV and password helper checks execute', False, exit_code=helper.returncode)
    else:
        for item in evidence['checks']:
            t.check(item['name'], item['passed'])
