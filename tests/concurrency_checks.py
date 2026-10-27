"""Exercise actual concurrent PHP handlers against the disposable fixture DB.

The parent HTTP server remains running. A second loopback PHP process permits
real parallel requests on platforms where the development server has one worker.
All credentials and complete responses remain private to this process.
"""
from __future__ import annotations

from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager, nullcontext
import secrets
import socket
import subprocess
import threading
import time


@contextmanager
def _fixture_row_lock(t, sql, params):
    """Hold only a fixture row, then roll back; never copy application logic."""
    signal = 'regression-' + secrets.token_hex(12)
    operations = [
        {'sql': sql, 'params': list(params)},
        {'sql': 'SELECT GET_LOCK(?,0) AS acquired', 'params': [signal]},
        {'sql': 'SELECT SLEEP(?) AS delay', 'params': ['2.5']},
    ]
    with ThreadPoolExecutor(max_workers=1) as pool:
        holder = pool.submit(t.db, '', operations=operations)
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if holder.done():
                holder.result()
                raise RuntimeError('Fixture lock ended before concurrent requests were ready.')
            state = t.rows('SELECT IS_USED_LOCK(?) AS owner', [signal])[0]
            if state['owner'] is not None:
                break
            time.sleep(.02)
        else:
            raise RuntimeError('Could not synchronize the fixture row lock.')
        try:
            yield
        finally:
            holder.result(timeout=20)


def _parallel(t, clients, bases, path, payloads, *, lock=None):
    # Token reads occur before the barrier so they cannot serialize mutations.
    prepared = [dict(payload, _csrf=client.token(path)) for client, payload in zip(clients, payloads)]
    barrier = threading.Barrier(len(clients) + 1)

    def request(index):
        barrier.wait(timeout=10)
        start = time.perf_counter()
        response = clients[index].request(path, prepared[index], headers={
            'Origin': bases[index], 'Sec-Fetch-Site': 'same-origin',
        })
        end = time.perf_counter()
        return response, start, end

    synchronization = _fixture_row_lock(t, *lock) if lock else nullcontext()
    with synchronization:
        with ThreadPoolExecutor(max_workers=len(clients)) as pool:
            futures = [pool.submit(request, index) for index in range(len(clients))]
            barrier.wait(timeout=10)
            results = [future.result(timeout=25) for future in futures]
    overlap = max(result[1] for result in results) < min(result[2] for result in results)
    return [result[0] for result in results], overlap


def _pending(t, employee_id, start, end, reason):
    record = t.db("INSERT INTO holiday_requests(employee_id,leave_type,start_date,end_date,reason,status) VALUES(?,'Annual',?,?,?,'Pending')",
                  [employee_id, start, end, reason])
    return int(record['id'])


def _same_request(t, clients, bases, password, tag):
    employee_id, _, _ = t.fixture('Parallel Same Leave ' + tag, False, password)
    request_id = _pending(t, employee_id, '2029-04-01', '2029-04-04', 'Parallel same request ' + tag)
    before = t.balance(employee_id)
    payload = {'update_status': '1', 'request_id': request_id, 'status': 'Approved'}
    responses, overlap = _parallel(t, clients, bases, '/check_holiday_requests.php', [payload, payload],
                                   lock=('SELECT request_id FROM holiday_requests WHERE request_id=? FOR UPDATE', [request_id]))
    statuses = sorted(response['status'] for response in responses)
    state = t.rows('SELECT status FROM holiday_requests WHERE request_id=?', [request_id])[0]['status']
    after = t.balance(employee_id)
    t.check('F06 simultaneous HTTP approvals process one pending request only once',
            overlap and statuses == [302, 409] and state == 'Approved' and after == before - 4,
            response_statuses=statuses, http_requests_overlapped=overlap,
            charged_days=before - after)


def _shared_balance(t, clients, bases, password, tag):
    employee_id, _, _ = t.fixture('Parallel Shared Balance ' + tag, False, password)
    t.db('UPDATE holiday_balance SET annual_leave=? WHERE employee_id=?', [5, employee_id])
    request_ids = [
        _pending(t, employee_id, '2029-05-01', '2029-05-04', 'Parallel balance first ' + tag),
        _pending(t, employee_id, '2029-05-11', '2029-05-14', 'Parallel balance second ' + tag),
    ]
    payloads = [{'update_status': '1', 'request_id': ident, 'status': 'Approved'} for ident in request_ids]
    responses, overlap = _parallel(t, clients, bases, '/check_holiday_requests.php', payloads,
                                   lock=('SELECT employee_id FROM holiday_balance WHERE employee_id=? FOR UPDATE', [employee_id]))
    statuses = sorted(response['status'] for response in responses)
    states = t.rows('SELECT status,COUNT(*) n FROM holiday_requests WHERE request_id IN (?,?) GROUP BY status', request_ids)
    counts = {row['status']: int(row['n']) for row in states}
    remaining = t.balance(employee_id)
    t.check('F06 simultaneous HTTP approvals cannot overdraw a shared leave balance',
            overlap and statuses == [302, 409] and counts == {'Approved': 1, 'Pending': 1} and remaining == 1,
            response_statuses=statuses, http_requests_overlapped=overlap,
            remaining_days=remaining, approved_requests=counts.get('Approved', 0), pending_requests=counts.get('Pending', 0))


def _create_employees(t, clients, bases, role, office, tag):
    names = ['Parallel Created A ' + tag, 'Parallel Created B ' + tag]
    payloads = [{
        'name': name, 'salary': '36000.25', 'dob': '1991-02-03',
        'home_address': 'Synthetic concurrent creation address', 'hired_date': '2025-01-01',
        'contract': 'Full-Time', 'nin': 'PARALLEL-TEST-NIN',
        'department_id': role['department_id'], 'position_id': role['position_id'], 'office_id': office,
        'emergency_name': 'Synthetic Parallel Contact', 'emergency_relationship': 'Test contact',
        'emergency_phone': 'TEST-PARALLEL-PHONE',
    } for name in names]
    try:
        responses, overlap = _parallel(t, clients, bases, '/add_employee.php', payloads)
    finally:
        # Register committed rows even if an HTTP request raised, so parent cleanup stays complete.
        inserted = t.rows('SELECT employee_id FROM employees WHERE name IN (?,?)', names)
        for row in inserted:
            ident = int(row['employee_id'])
            if ident not in t.FIXTURES:
                t.FIXTURES.append(ident)
    ids = [int(row['employee_id']) for row in inserted]
    related = t.rows(
        'SELECT e.employee_id,'
        '(SELECT COUNT(*) FROM emergency_table c WHERE c.employee_id=e.employee_id AND c.emergency_name=?) contacts,'
        '(SELECT COUNT(*) FROM holiday_balance b WHERE b.employee_id=e.employee_id AND b.annual_leave=28 AND b.sick_leave=10 AND b.personal_leave=5) balances,'
        '(SELECT COUNT(*) FROM login l WHERE l.employee_id=e.employee_id AND l.password_hash IS NOT NULL AND l.must_change_password=1) logins '
        'FROM employees e WHERE e.name IN (?,?)', ['Synthetic Parallel Contact', *names])
    complete = len(related) == 2 and all(
        [int(row[key]) for key in ['contacts', 'balances', 'logins']] == [1, 1, 1] for row in related)
    statuses = sorted(response['status'] for response in responses)
    t.check('F13 simultaneous HTTP employee creation assigns distinct IDs and complete related rows',
            overlap and statuses == [302, 302] and len(ids) == 2 and len(set(ids)) == 2 and complete,
            response_statuses=statuses, http_requests_overlapped=overlap,
            created_employees=len(ids), distinct_ids=len(set(ids)), complete_related_records=complete)
    # Consume the private one-time displays without copying credentials into any report.
    for client in clients:
        client.request('/add_employee.php')


def run(t):
    database = t.rows('SELECT DATABASE() AS name')[0]['name']
    if not isinstance(database, str) or not database.endswith('_test'):
        raise RuntimeError('Concurrency checks require an explicitly disposable _test database.')
    if t.ENV.get('COMPANY_TEST_DATABASE') != 'YES':
        raise RuntimeError('Concurrency checks require the explicit fixture database flag.')
    with socket.socket() as reserved:
        reserved.bind(('127.0.0.1', 0))
        port = reserved.getsockname()[1]
    secondary_base = f'http://127.0.0.1:{port}'
    environment = dict(t.ENV, APP_ORIGIN=secondary_base)
    session_path = str(t.ROOT / 'var/test-sessions')
    environment['APP_SESSION_PATH'] = session_path
    log = (t.ROOT / 'var/concurrency-server.log').open('w', encoding='utf-8')
    server = None
    try:
        server = subprocess.Popen(t.PHP + [
            '-d', 'display_errors=0', '-d', 'session.upload_progress.enabled=0',
            '-d', 'session.save_path=' + session_path, '-S', f'127.0.0.1:{port}', '-t', str(t.ROOT / 'public'),
        ], cwd=t.ROOT, env=environment, stdout=log, stderr=log,
            creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        probe = t.Client(base=secondary_base)
        for _ in range(100):
            if server.poll() is not None:
                raise RuntimeError('The secondary local PHP server exited during startup.')
            try:
                if probe.request('/login.php')['status'] == 200:
                    break
            except OSError:
                pass
            time.sleep(.1)
        else:
            raise RuntimeError('The secondary local PHP server did not become ready.')
        tag = secrets.token_hex(4)
        password = secrets.token_urlsafe(24)
        first_id, _, _ = t.fixture('Parallel First Admin ' + tag, True, password)
        second_id, _, _ = t.fixture('Parallel Second Admin ' + tag, True, password)
        _, role, office = t.fixture('Parallel Assignment ' + tag, False, password)
        bases = [t.BASE, secondary_base]
        clients = [t.Client(base=base) for base in bases]
        authenticated = [client.login(ident, password)['status'] for client, ident in zip(clients, [first_id, second_id])]
        independent = authenticated == [302, 302] and clients[0].sid() is not None and clients[0].sid() != clients[1].sid()
        t.check('Concurrency regressions use two PHP processes and independent administrator sessions',
                independent, login_response_statuses=authenticated)
        if independent:
            _same_request(t, clients, bases, password, tag)
            _shared_balance(t, clients, bases, password, tag)
            _create_employees(t, clients, bases, role, office, tag)
    finally:
        try:
            if server is not None:
                if server.poll() is None:
                    server.terminate()
                    try:
                        server.wait(timeout=15)
                    except subprocess.TimeoutExpired:
                        server.kill()
                        server.wait(timeout=5)
                t.check('Concurrency regression secondary PHP server is stopped', server.poll() is not None)
        finally:
            log.close()
