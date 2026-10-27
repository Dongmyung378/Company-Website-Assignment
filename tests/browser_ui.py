"""Optional real Edge UI checks against an explicitly disposable local database.

Requires websocket-client and a local Microsoft Edge binary. Uses the same private
process environment as security_regression.py, including PHP_BIN/PHP_ARGS_JSON.
Run after the HTTP regression suite; this script owns its fixtures and processes.
Only compact assertions and screenshots are saved, never credentials or responses.
"""
from __future__ import annotations

import argparse
import base64
from datetime import date, timedelta
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import time
import urllib.parse
import urllib.request

import security_regression as t


ROOT = Path(__file__).resolve().parents[1]
VAR = ROOT / 'var'
RESULT = {
    'checks': [], 'pages': [], 'screenshots': [],
    'limitations': [
        'Local headless Edge checks do not certify every browser or production deployment.',
        'Automated accessible-name and layout checks supplement visual review; they are not a full accessibility audit.',
    ],
}


class BrowserError(RuntimeError):
    """Messages are fixed harness descriptions and contain no request data."""


class ContextChanged(BrowserError):
    pass


def check(name: str, passed: bool, **evidence):
    RESULT['checks'].append({'name': name, 'passed': bool(passed), 'evidence': evidence})
    print(('PASS ' if passed else 'FAIL ') + name, flush=True)


def available(port: int):
    if not 1024 <= port <= 65535:
        raise BrowserError('Choose an unprivileged loopback port.')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', port))


def wait_http(url: str, process, timeout: float = 15):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise BrowserError('Owned local process exited before it became ready.')
        try:
            with opener.open(url, timeout=1) as response:
                return response.read()
        except OSError:
            time.sleep(.1)
    raise BrowserError('Owned local process did not become ready.')


def stop_owned(process):
    if process is None or process.poll() is not None:
        return
    if os.name == 'nt':
        # The PID is the Popen process created by this run, never a discovered PID.
        subprocess.run(['taskkill', '/PID', str(process.pid), '/T', '/F'],
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                       creationflags=subprocess.CREATE_NO_WINDOW, check=False)
    else:
        process.terminate()
    try:
        process.wait(timeout=10)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)


def remove_owned_directory(path: Path | None, parent: Path):
    if path is None or not path.exists():
        return
    resolved = path.resolve()
    expected_parent = parent.resolve()
    if resolved.parent != expected_parent or not resolved.name.startswith('run-'):
        raise BrowserError('Refusing to remove a directory outside this run.')
    shutil.rmtree(resolved)


class Browser:
    def __init__(self, websocket_module, endpoint: str, origin: str):
        self.ws = websocket_module.create_connection(
            endpoint, origin=origin, timeout=15, http_proxy_host=None,
            http_no_proxy=['127.0.0.1', 'localhost'])
        self.seq = 0
        self.token = None
        self.responses = []
        self.exceptions = []
        self.posts = []
        self.loader = None
        self.call('Page.enable')
        self.call('Network.enable')
        self.call('Runtime.enable')
        self.call('Fetch.enable', {'patterns': [{'urlPattern': '*'}]})

    def send(self, method, params=None):
        self.seq += 1
        self.ws.send(json.dumps({'id': self.seq, 'method': method, 'params': params or {}}))
        return self.seq

    def event(self, event):
        method, params = event.get('method'), event.get('params', {})
        if method == 'Fetch.requestPaused':
            url = params['request']['url']
            allowed = url == t.BASE or url.startswith(t.BASE + '/')
            self.send('Fetch.continueRequest' if allowed else 'Fetch.failRequest',
                      {'requestId': params['requestId'],
                       **({} if allowed else {'errorReason': 'BlockedByClient'})})
        elif method == 'Network.responseReceived':
            response = params['response']
            if response['url'].startswith(t.BASE + '/'):
                self.responses.append({
                    'path': urllib.parse.urlsplit(response['url']).path,
                    'status': int(response['status']), 'type': params.get('type'),
                    'loader': params.get('loaderId'),
                })
        elif method == 'Runtime.exceptionThrown':
            details = params.get('exceptionDetails', {})
            self.exceptions.append({
                'type': details.get('exception', {}).get('className', 'JavaScriptError'),
                'script': urllib.parse.urlsplit(details.get('url', '')).path,
                'line': details.get('lineNumber'),
            })
        elif method == 'Network.requestWillBeSent':
            request = params['request']
            if request.get('method') == 'POST' and request['url'].startswith(t.BASE + '/'):
                # Inspect action presence without retaining the CSRF token or form data.
                fields = urllib.parse.parse_qs(request.get('postData', ''), keep_blank_values=True)
                self.posts.append({
                    'path': urllib.parse.urlsplit(request['url']).path,
                    'search_action': 'search_employee' in fields,
                    'csrf_present': bool(fields.get('_csrf', [''])[0]),
                })

    def call(self, method, params=None):
        wanted = self.send(method, params)
        while True:
            event = json.loads(self.ws.recv())
            self.event(event)
            if event.get('id') != wanted:
                continue
            if 'error' in event:
                message = str(event['error'].get('message', '')).lower()
                if any(term in message for term in ('context', 'navigated', 'target closed')):
                    raise ContextChanged('Navigation replaced the execution context.')
                raise BrowserError('CDP command failed: ' + method)
            return event.get('result', {})

    def evaluate(self, expression, *, await_promise=False):
        result = self.call('Runtime.evaluate', {
            'expression': expression, 'returnByValue': True, 'awaitPromise': await_promise,
        })
        if 'exceptionDetails' in result:
            details = result['exceptionDetails']
            description = str(details.get('text', '')).lower()
            if any(term in description for term in ('context', 'navigated')):
                raise ContextChanged('Navigation replaced the execution context.')
            raise BrowserError('A harness JavaScript expression failed.')
        return result.get('result', {}).get('value')

    def pause(self, milliseconds=100):
        try:
            self.evaluate('new Promise(resolve => setTimeout(resolve, ' + str(milliseconds) + '))',
                          await_promise=True)
        except ContextChanged:
            pass

    def reset_observations(self):
        self.responses.clear()
        self.exceptions.clear()
        self.posts.clear()
        self.loader = None

    def wait_ready(self, path: str, previous_token=None, selector=None):
        expected_path = urllib.parse.urlsplit(path).path
        predicate = ('location.pathname === ' + json.dumps(expected_path)
                     + ' && document.readyState === "complete" && !!document.querySelector("main")')
        if previous_token:
            predicate += ' && window.__uiHarnessDocument !== ' + json.dumps(previous_token)
        if selector:
            predicate += ' && !!document.querySelector(' + json.dumps(selector) + ')'
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            try:
                if self.evaluate('Boolean(' + predicate + ')'):
                    self.pause(120)  # Drain completion/resource events after the deferred app script.
                    self.token = secrets.token_hex(12)
                    self.evaluate('window.__uiHarnessDocument = ' + json.dumps(self.token))
                    return
            except ContextChanged:
                pass
            self.pause(80)
        raise BrowserError('Expected page did not finish navigating.')

    def navigate(self, path: str):
        previous = self.token
        self.reset_observations()
        response = self.call('Page.navigate', {'url': t.BASE + path})
        if response.get('errorText'):
            raise BrowserError('Local page navigation failed.')
        self.loader = response.get('loaderId')
        self.wait_ready(path, previous)

    def identity(self, client=None):
        self.call('Network.clearBrowserCookies')
        if client is not None:
            sid = client.sid()
            if not sid:
                raise BrowserError('Fixture login did not establish a session.')
            result = self.call('Network.setCookie', {
                'name': 'PHPSESSID', 'value': sid, 'url': t.BASE, 'path': '/',
                'httpOnly': True, 'sameSite': 'Lax',
            })
            if not result.get('success'):
                raise BrowserError('Fixture session could not be installed.')

    def viewport(self, width, height, mobile):
        self.call('Emulation.setDeviceMetricsOverride', {
            'width': width, 'height': height, 'deviceScaleFactor': 1,
            'mobile': mobile, 'screenWidth': width, 'screenHeight': height,
        })
        self.call('Emulation.setTouchEmulationEnabled', {'enabled': mobile})

    def fill(self, values):
        self.evaluate('''(() => {
            for (const [selector, value] of Object.entries(''' + json.dumps(values) + ''')) {
                const node = document.querySelector(selector);
                if (!node) throw new Error('Missing harness control');
                node.value = String(value);
                node.dispatchEvent(new Event('input', {bubbles:true}));
                node.dispatchEvent(new Event('change', {bubbles:true}));
            }
        })()''')

    def click(self, selector):
        point = self.evaluate('''(() => {
            const node = document.querySelector(''' + json.dumps(selector) + ''');
            if (!node || node.disabled) return null;
            node.scrollIntoView({block:'center', inline:'center', behavior:'instant'});
            const box = node.getBoundingClientRect();
            return box.width && box.height ? {x:box.x + box.width/2, y:box.y + box.height/2} : null;
        })()''')
        if not point:
            raise BrowserError('A browser click target was unavailable.')
        self.pause(35)
        self.call('Input.dispatchMouseEvent', dict(type='mouseMoved', **point))
        self.call('Input.dispatchMouseEvent', dict(type='mousePressed', button='left', clickCount=1, **point))
        self.call('Input.dispatchMouseEvent', dict(type='mouseReleased', button='left', clickCount=1, **point))

    def submit_and_wait(self, selector, path, required_selector=None):
        previous = self.token
        self.reset_observations()
        self.click(selector)
        self.wait_ready(path, previous, required_selector)

    def screenshot(self, name: str):
        self.evaluate('window.scrollTo({top:0, left:0, behavior:"instant"})')
        self.pause(100)
        result = self.call('Page.captureScreenshot', {
            'format': 'png', 'captureBeyondViewport': False, 'fromSurface': True,
        })
        destination = VAR / 'ui-screenshots' / (name + '.png')
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_bytes(base64.b64decode(result['data']))
        RESULT['screenshots'].append(str(destination.relative_to(ROOT)).replace('\\', '/'))

    def close(self):
        try:
            self.call('Browser.close')
        except Exception:
            pass
        finally:
            self.ws.close()


SNAPSHOT = r'''(() => {
    const controls = [...document.querySelectorAll('input,select,textarea')]
        .filter(node => !['hidden','submit','button','reset'].includes(node.type));
    const named = node => Boolean(node.getAttribute('aria-label')?.trim()
        || [...(node.labels || [])].some(label => label.textContent.trim())
        || (node.getAttribute('aria-labelledby') || '').split(/\s+/)
            .some(id => document.getElementById(id)?.textContent.trim()));
    const ids = [...document.querySelectorAll('[id]')].map(node => node.id);
    return {
        h1: document.querySelectorAll('h1').length,
        main: document.querySelectorAll('main').length,
        viewportMeta: document.querySelector('meta[name=viewport]')?.content || '',
        viewportWidth: innerWidth,
        documentWidth: Math.max(document.documentElement.scrollWidth, document.body.scrollWidth),
        unnamedControls: controls.filter(node => !named(node)).map(node => node.name || node.id || node.tagName),
        unnamedButtons: [...document.querySelectorAll('button')].filter(node =>
            !node.textContent.trim() && !node.getAttribute('aria-label')?.trim()).length,
        duplicateIds: [...new Set(ids.filter((id, index) => ids.indexOf(id) !== index))],
        sidebar: Boolean(document.querySelector('.sidebar')),
        activeLinks: document.querySelectorAll('.sidebar a[aria-current=page]').length,
        sidebarInert: document.querySelector('.sidebar')?.inert ?? null,
        scrollableTables: [...document.querySelectorAll('.table-scroll')].filter(node => node.scrollWidth > node.clientWidth).length,
    };
})()'''


def inspect_page(browser: Browser, label: str, viewport: str, path: str, *, auth=False):
    snapshot = browser.evaluate(SNAPSHOT)
    metrics = browser.call('Page.getLayoutMetrics')
    snapshot['layoutWidth'] = metrics.get('cssLayoutViewport', metrics.get('layoutViewport', {})).get('clientWidth')
    errors = [item for item in browser.responses if item['status'] >= 400
              and (browser.loader is None or item['loader'] == browser.loader)]
    documents = [item for item in browser.responses if item['type'] == 'Document'
                 and item['path'] == urllib.parse.urlsplit(path).path]
    evidence = {
        'page': label, 'viewport': viewport, 'path': urllib.parse.urlsplit(path).path,
        **snapshot, 'resourceErrors': [{'path': item['path'], 'status': item['status']} for item in errors],
        'scriptExceptions': list(browser.exceptions),
        'documentStatus': documents[-1]['status'] if documents else None,
    }
    RESULT['pages'].append(evidence)
    prefix = viewport + ' ' + label
    check(prefix + ' landmarks and viewport', snapshot['h1'] == 1 and snapshot['main'] == 1
          and 'width=device-width' in snapshot['viewportMeta'])
    check(prefix + ' no document overflow', snapshot['documentWidth'] <= snapshot['viewportWidth'] + 1,
          viewport_width=snapshot['viewportWidth'], document_width=snapshot['documentWidth'])
    check(prefix + ' named controls and unique IDs', not snapshot['unnamedControls']
          and not snapshot['unnamedButtons'] and not snapshot['duplicateIds'],
          unnamed_controls=snapshot['unnamedControls'], duplicate_ids=snapshot['duplicateIds'])
    check(prefix + ' no script or resource failures', not browser.exceptions and not errors
          and bool(documents) and documents[-1]['status'] == 200,
          resource_error_count=len(errors), script_exception_count=len(browser.exceptions))
    if not auth:
        check(prefix + ' current navigation', snapshot['sidebar'] and snapshot['activeLinks'] == 1
              and snapshot['sidebarInert'] == (snapshot['viewportWidth'] <= 900))


def password_toggle(browser: Browser, viewport: str):
    browser.click('.password-toggle')
    shown = browser.evaluate('document.querySelector("#password").type === "text" && document.querySelector(".password-toggle").getAttribute("aria-pressed") === "true"')
    browser.click('.password-toggle')
    hidden = browser.evaluate('document.querySelector("#password").type === "password" && document.querySelector(".password-toggle").getAttribute("aria-pressed") === "false"')
    check(viewport + ' password visibility toggle', shown and hidden)


def mobile_menu(browser: Browser):
    initial = browser.evaluate('document.querySelector(".sidebar").inert && document.querySelector(".nav-toggle").getAttribute("aria-expanded") === "false"')
    browser.click('.nav-toggle')
    opened = browser.evaluate('!document.querySelector(".sidebar").inert && document.querySelector(".nav-toggle").getAttribute("aria-expanded") === "true" && !document.querySelector(".nav-backdrop").hidden')
    browser.call('Input.dispatchKeyEvent', {'type': 'keyDown', 'key': 'Escape', 'code': 'Escape', 'windowsVirtualKeyCode': 27})
    browser.call('Input.dispatchKeyEvent', {'type': 'keyUp', 'key': 'Escape', 'code': 'Escape', 'windowsVirtualKeyCode': 27})
    closed = browser.evaluate('document.querySelector(".sidebar").inert && document.querySelector(".nav-toggle").getAttribute("aria-expanded") === "false" && document.querySelector(".nav-backdrop").hidden && document.activeElement === document.querySelector(".nav-toggle")')
    check('Mobile menu opens and Escape restores inert state and focus', initial and opened and closed)


def department_options(browser: Browser, viewport: str):
    result = browser.evaluate(r'''(() => {
        const department = document.querySelector('#department_id');
        const position = document.querySelector('#position_id');
        const departments = [...department.options].filter(option => option.value);
        const failures = [];
        for (const option of departments) {
            department.value = option.value;
            department.dispatchEvent(new Event('change', {bubbles:true}));
            const selectable = [...position.options].filter(item => item.value && !item.disabled && !item.hidden);
            if (position.disabled || !selectable.length || selectable.some(item => item.dataset.departmentId !== option.value)) failures.push(option.value);
            if (selectable.length) position.value = selectable[0].value;
        }
        department.value = '';
        department.dispatchEvent(new Event('change', {bubbles:true}));
        return {departments:departments.length, failures, blankDisabled:position.disabled && !position.value};
    })()''')
    check(viewport + ' positions follow selected department', result['departments'] > 0
          and not result['failures'] and result['blankDisabled'], tested_departments=result['departments'])


def leave_preview(browser: Browser, viewport: str):
    browser.call('Emulation.setTimezoneOverride', {'timezoneId': 'America/New_York'})
    try:
        browser.fill({'#leave_type': 'Annual', '#start_date': '2027-03-13', '#end_date': '2027-03-15'})
        across_dst = browser.evaluate('document.querySelector("[data-leave-preview]").textContent.startsWith("3 calendar days") && !document.querySelector("#end_date").validity.customError')
        browser.fill({'#end_date': '2027-03-13'})
        single_day = browser.evaluate('document.querySelector("[data-leave-preview]").textContent.startsWith("1 calendar day,")')
        browser.fill({'#end_date': '2027-03-12'})
        reversed_dates = browser.evaluate('document.querySelector("#end_date").validity.customError')
        browser.fill({'#start_date': '2027-03-01', '#end_date': '2027-12-31'})
        insufficient = browser.evaluate('document.querySelector("#end_date").validity.customError && document.querySelector("[data-leave-preview]").textContent.includes("balance")')
        check(viewport + ' leave calendar preview is inclusive across DST', across_dst and single_day)
        check(viewport + ' leave preview rejects reversed and insufficient dates', reversed_dates and insufficient)
    finally:
        browser.call('Emulation.setTimezoneOverride', {'timezoneId': ''})


def edit_prefill(browser: Browser, user_id: int, role, viewport: str):
    values = browser.evaluate('''(() => {
        const form = document.querySelector('form input[name=row_version]')?.form;
        return {
            present: Boolean(form),
            validRequired: form ? [...form.querySelectorAll('[required]')].every(node => node.checkValidity()) : false,
            employee: form?.querySelector('[name=employee_id]')?.value,
            department: form?.querySelector('[name=department_id]')?.value,
            position: form?.querySelector('[name=position_id]')?.value,
            positionEnabled: form ? !form.querySelector('[name=position_id]').selectedOptions[0]?.disabled : false,
        };
    })()''')
    check(viewport + ' employee edit prefill is valid', values['present'] and values['validRequired']
          and values['employee'] == str(user_id) and values['department'] == str(role['department_id'])
          and values['position'] == str(role['position_id']) and values['positionEnabled'])


def search_submit(browser: Browser, admin, user_id: int):
    browser.identity(admin)
    browser.navigate('/update_employee.php')
    browser.fill({'#search_employee_id': user_id})
    browser.submit_and_wait('button[name=search_employee]', '/update_employee.php', 'input[name=row_version]')
    submitted = any(item['path'] == '/update_employee.php' and item['search_action']
                    and item['csrf_present'] for item in browser.posts)
    loaded = browser.evaluate('document.querySelector("input[name=row_version]")?.form?.querySelector("input[name=employee_id]")?.value')
    check('Real employee-search click preserves POST action and CSRF', submitted and loaded == str(user_id))


def browser_login(browser: Browser, user_id: int, password: str):
    browser.identity()
    browser.navigate('/login.php')
    browser.fill({'#employee_id': user_id, '#password': password})
    browser.submit_and_wait('button[type=submit]', '/main.php')
    check('Real login click follows the CSRF-protected employee redirect',
          browser.evaluate('location.pathname === "/main.php" && document.querySelectorAll("h1").length === 1'))


def run_pages(browser: Browser, admin, user, forced, user_id, role, month):
    cases = [
        ('login', '/login.php', None, 'login'),
        ('admin overview', '/admin_main.php', admin, 'dashboard'),
        ('employee overview', '/main.php', user, None),
        ('employee directory', '/list_employees.php', admin, 'directory'),
        ('employee profile', '/employee_detail.php?id=' + str(user_id), admin, None),
        ('add employee', '/add_employee.php', admin, 'add'),
        ('update employee', '/update_employee.php?employee_id=' + str(user_id), admin, None),
        ('delete employee', '/delete_employee.php', admin, None),
        ('leave approvals', '/check_holiday_requests.php', admin, None),
        ('absence report', '/check_absence.php', admin, None),
        ('payroll', '/salary_table.php', admin, 'payroll'),
        ('birthdays', '/happybirthday.php', admin, None),
        ('deletion records', '/delete_records.php', admin, None),
        ('request leave', '/holiday_requests.php', user, 'leave'),
        ('leave history', '/check_my_holiday.php', user, None),
        ('change password', '/change_password.php', user, None),
        ('sign out', '/logout.php', user, None),
    ]
    for viewport, width, height, mobile in [('desktop', 1440, 1000, False), ('mobile', 390, 844, True)]:
        browser.viewport(width, height, mobile)
        for label, path, client, screenshot in cases:
            browser.identity(client)
            browser.navigate(path)
            if label == 'absence report':
                browser.fill({'#month': month})
                browser.submit_and_wait('button[name=check_holidays]', path, '.stat-grid')
            inspect_page(browser, label, viewport, path, auth=client is None)
            if screenshot:
                browser.screenshot(viewport + '-' + screenshot)
            if label == 'login':
                password_toggle(browser, viewport)
            elif label == 'admin overview' and mobile:
                mobile_menu(browser)
            elif label == 'add employee':
                department_options(browser, viewport)
            elif label == 'update employee':
                edit_prefill(browser, user_id, role, viewport)
            elif label == 'request leave':
                leave_preview(browser, viewport)
        browser.identity(forced)
        browser.navigate('/change_password.php')
        restricted = browser.evaluate('''(() => {
            const links = [...document.querySelectorAll('.sidebar a.nav-link')].map(node => node.getAttribute('href'));
            return links.length === 2 && links.includes('change_password.php') && links.includes('logout.php')
                && document.querySelector('#current_password')?.autocomplete === 'current-password'
                && document.querySelector('#new_password')?.minLength === 15;
        })()''')
        check(viewport + ' forced account shows only password and sign-out navigation', restricted)


def spot_checks(browser: Browser, admin, user):
    cases = [
        ('narrow mobile', 360, 800, [
            ('login', '/login.php', None), ('admin overview', '/admin_main.php', admin),
            ('employee directory', '/list_employees.php', admin),
            ('request leave', '/holiday_requests.php', user), ('payroll', '/salary_table.php', admin),
        ]),
        ('tablet', 768, 1024, [
            ('admin overview', '/admin_main.php', admin), ('employee directory', '/list_employees.php', admin),
        ]),
    ]
    for viewport, width, height, pages in cases:
        browser.viewport(width, height, True)
        for label, path, client in pages:
            browser.identity(client)
            browser.navigate(path)
            inspect_page(browser, label, viewport, path, auth=client is None)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=18047)
    parser.add_argument('--cdp-port', type=int, default=18049)
    parser.add_argument('--output', type=Path, default=VAR / 'ui-browser-results.json')
    parser.add_argument('--edge', type=Path, default=Path(os.environ.get(
        'EDGE_BIN', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe')))
    args = parser.parse_args()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    server = edge = browser = None
    server_log = edge_log = None
    profile = sessions = None
    original_fixture_count = len(t.FIXTURES)
    outcome = 1
    try:
        if not t.ENV.get('DB_NAME', '').endswith('_test'):
            raise BrowserError('Configure a disposable DB_NAME ending in _test.')
        if not t.ENV.get('DB_SETUP_USER') or not t.ENV.get('DB_SETUP_PASSWORD'):
            raise BrowserError('Private fixture setup credentials are required.')
        try:
            import websocket
        except ImportError:
            RESULT['limitations'].append('Optional websocket-client dependency is not installed.')
            RESULT['status'] = 'unavailable'
            return 2
        if not args.edge.is_file():
            RESULT['limitations'].append('A local Edge binary was not found; use --edge or EDGE_BIN.')
            RESULT['status'] = 'unavailable'
            return 2
        if args.port == args.cdp_port:
            raise BrowserError('HTTP and CDP ports must be different.')
        available(args.port)
        available(args.cdp_port)
        run_id = 'run-' + secrets.token_hex(8)
        profile = VAR / 'ui-browser-profile' / run_id
        sessions = VAR / 'ui-test-sessions' / run_id
        profile.mkdir(parents=True, exist_ok=False)
        sessions.mkdir(parents=True, exist_ok=False)
        t.BASE = f'http://127.0.0.1:{args.port}'
        t.ENV['APP_ORIGIN'] = t.BASE
        t.ENV['APP_SESSION_PATH'] = str(sessions)
        flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
        server_log = (VAR / 'ui-test-server.log').open('w', encoding='utf-8')
        server = subprocess.Popen(t.PHP + [
            '-d', 'display_errors=0', '-d', 'session.upload_progress.enabled=0',
            '-d', 'session.save_path=' + str(sessions),
            '-S', f'127.0.0.1:{args.port}', '-t', str(ROOT / 'public'),
        ], cwd=ROOT, env=t.ENV, stdout=server_log, stderr=server_log, creationflags=flags)
        wait_http(t.BASE + '/login.php', server)
        identity = t.rows('SELECT DATABASE() db')[0]['db']
        if identity != t.ENV['DB_NAME'] or not identity.endswith('_test'):
            raise BrowserError('Fixture database identity did not match the disposable configuration.')
        password = secrets.token_urlsafe(24)
        label_suffix = secrets.token_hex(4)
        admin_id, _, _ = t.fixture('UI Browser Admin ' + label_suffix, True, password)
        user_id, role, _ = t.fixture('UI Browser Employee ' + label_suffix, False, password)
        forced_id, _, _ = t.fixture('UI Browser First Login ' + label_suffix, False, password, forced=True)
        first_day = date.today().replace(day=1)
        month = first_day.strftime('%Y-%m')
        for status, start_offset in [('Approved', 0), ('Pending', 6)]:
            start = first_day + timedelta(days=start_offset)
            end = start + timedelta(days=2)
            t.db('INSERT INTO holiday_requests(employee_id,leave_type,start_date,end_date,reason,status) VALUES(?,?,?,?,?,?)',
                 [user_id, 'Annual', start.isoformat(), end.isoformat(), 'Synthetic browser UI fixture', status])
        t.db('UPDATE holiday_balance SET annual_leave=annual_leave-3 WHERE employee_id=?', [user_id])
        admin, user, forced = t.Client(), t.Client(), t.Client()
        login_results = [admin.login(admin_id, password), user.login(user_id, password), forced.login(forced_id, password)]
        if any(result['status'] != 302 for result in login_results):
            raise BrowserError('A synthetic fixture login failed.')
        origin = f'http://127.0.0.1:{args.cdp_port}'
        edge_log = (VAR / 'ui-browser.log').open('w', encoding='utf-8')
        edge = subprocess.Popen([
            str(args.edge), '--headless=new', '--disable-gpu', '--disable-background-networking',
            '--disable-component-update', '--disable-sync', '--no-first-run', '--no-default-browser-check',
            '--remote-debugging-address=127.0.0.1', f'--remote-debugging-port={args.cdp_port}',
            '--remote-allow-origins=' + origin, '--user-data-dir=' + str(profile),
            '--proxy-server=http://127.0.0.1:9', '--proxy-bypass-list=127.0.0.1',
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', 'about:blank',
        ], stdout=edge_log, stderr=edge_log, creationflags=flags)
        targets = json.loads(wait_http(origin + '/json/list', edge))
        target = next((item for item in targets if item.get('type') == 'page'), None)
        if target is None:
            raise BrowserError('Owned Edge did not expose a page target.')
        browser = Browser(websocket, target['webSocketDebuggerUrl'], origin)
        RESULT['runtime'] = {
            'http': t.BASE, 'cdp': origin, 'database': identity,
            'browser': browser.call('Browser.getVersion').get('product'),
            'php': subprocess.check_output(t.PHP + ['-r', 'echo PHP_VERSION;'], text=True).strip(),
        }
        run_pages(browser, admin, user, forced, user_id, role, month)
        spot_checks(browser, admin, user)
        browser.viewport(1440, 1000, False)
        search_submit(browser, admin, user_id)
        browser_login(browser, user_id, password)
        standard_pages = [item for item in RESULT['pages'] if item['viewport'] in ('desktop', 'mobile')]
        check('All 17 rendered pages checked at both viewport sizes', len(standard_pages) == 34)
        check('Twelve requested screenshots captured', len(RESULT['screenshots']) == 12)
        RESULT['status'] = 'complete'
        outcome = 0 if all(item['passed'] for item in RESULT['checks']) else 1
    except Exception as error:
        check('Browser suite completes without harness errors', False, error_type=type(error).__name__)
        if isinstance(error, BrowserError):
            RESULT['harness_error'] = str(error)
        RESULT['status'] = 'failed'
    finally:
        if browser is not None:
            browser.close()
        stop_owned(edge)
        stop_owned(server)
        RESULT['browser_stopped'] = edge is None or edge.poll() is not None
        RESULT['server_stopped'] = server is None or server.poll() is not None
        for log in (edge_log, server_log):
            if log is not None:
                log.close()
        cleanup_ok = True
        for ident in t.FIXTURES[original_fixture_count:]:
            try:
                t.db('DELETE FROM login_attempts WHERE account_key=?', [hashlib.sha256(str(ident).encode()).hexdigest()])
                t.db('DELETE FROM employees WHERE employee_id=?', [ident])
                t.db('DELETE FROM delete_employee_logs WHERE employee_id=? OR deleted_by_id=?', [ident, ident])
            except Exception:
                cleanup_ok = False
        RESULT['fixtures_cleaned'] = cleanup_ok
        if not cleanup_ok:
            outcome = 1
            check('Owned synthetic fixtures are cleaned', False)
        for directory, parent in [(profile, VAR / 'ui-browser-profile'), (sessions, VAR / 'ui-test-sessions')]:
            try:
                remove_owned_directory(directory, parent)
            except Exception:
                RESULT['limitations'].append('An owned private browser/session directory could not be removed.')
                outcome = 1
        RESULT['passed'] = sum(item['passed'] for item in RESULT['checks'])
        RESULT['total'] = len(RESULT['checks'])
        args.output.write_text(json.dumps(RESULT, indent=2, ensure_ascii=False), encoding='utf-8')
    return outcome


if __name__ == '__main__':
    raise SystemExit(main())
