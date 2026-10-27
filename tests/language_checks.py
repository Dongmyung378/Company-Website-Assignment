"""Language HTTP regressions owned by the disposable security-test harness.

No standalone entry point: the parent supplies its server, fixture registry,
restricted environment, result writer, and cleanup. Responses and credentials
are never included in test evidence.
"""
from __future__ import annotations

import csv
from html.parser import HTMLParser
import io
import json
import re
import secrets
import subprocess
import time
from urllib.parse import parse_qs, urlencode, urlsplit


class _Page(HTMLParser):
    def __init__(self, body):
        super().__init__(convert_charrefs=True)
        self.language = ''
        self.headings = []
        self.visible = []
        self.display_attributes = []
        self.links = {}
        self.inputs = {}
        self.control_names = set()
        self.options = {}
        self.messages = {}
        self._heading = None
        self._select = None
        self._option = None
        self._hidden = None
        self._message_json = []
        self.feed(body)
        if self._message_json:
            try:
                self.messages = json.loads(''.join(self._message_json))
            except (ValueError, TypeError):
                pass

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'html':
            self.language = attrs.get('lang', '')
        if tag in ('script', 'style'):
            self._hidden = 'messages' if attrs.get('id') == 'ui-messages' else tag
        if tag == 'h1':
            self._heading = []
        if tag == 'a' and 'data-language-switch' in attrs:
            self.links[attrs.get('lang', '')] = attrs.get('href', '')
        if tag in ('input', 'select', 'textarea', 'button') and attrs.get('name'):
            self.control_names.add(attrs['name'])
            if tag == 'input':
                self.inputs.setdefault(attrs['name'], []).append(attrs.get('value', ''))
        if tag == 'select':
            self._select = attrs.get('name')
        if tag == 'option' and self._select:
            self._option = [attrs.get('value', ''), []]
        for attribute in ('placeholder', 'aria-label', 'title'):
            if attrs.get(attribute):
                self.display_attributes.append(attrs[attribute])

    def handle_endtag(self, tag):
        if tag in ('script', 'style'):
            self._hidden = None
        if tag == 'h1' and self._heading is not None:
            self.headings.append(' '.join(''.join(self._heading).split()))
            self._heading = None
        if tag == 'option' and self._select and self._option:
            value, text = self._option
            self.options.setdefault(self._select, []).append((value, ''.join(text).strip()))
            self._option = None
        if tag == 'select':
            self._select = None

    def handle_data(self, data):
        if self._hidden:
            if self._hidden == 'messages':
                self._message_json.append(data)
            return
        self.visible.append(data)
        if self._heading is not None:
            self._heading.append(data)
        if self._option is not None:
            self._option[1].append(data)


def _header(response, name):
    return next((value for key, value in response['headers'].items()
                 if key.lower() == name.lower()), '')


def _cookie(client, name):
    return next((cookie for cookie in client.jar if cookie.name == name), None)


def _is_language(response, language):
    return (_header(response, 'Content-Language') == language
            and _Page(response['body']).language == language)


def _php_string(value):
    return value.replace("\\'", "'").replace('\\\\', '\\')


def _dictionary_checks(t):
    paths = sorted((t.ROOT / 'lib' / 'translations').glob('*.ko.php'))
    utf8_ok = bool(paths)
    no_middle_dot = True
    for path in paths:
        try:
            source = path.read_bytes().decode('utf-8', errors='strict')
            utf8_ok = utf8_ok and '\ufffd' not in source
            no_middle_dot = no_middle_dot and '\u00b7' not in source
        except UnicodeError:
            utf8_ok = False
    script = "$out=[];foreach(glob(getcwd().'/lib/translations/*.ko.php')?:[] as $p){$out[basename($p)]=require $p;}echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);"
    process = subprocess.run(t.PHP + ['-r', script], cwd=t.ROOT, env=t.ENV,
                             capture_output=True, text=True, encoding='utf-8', timeout=20)
    try:
        files = json.loads(process.stdout)
    except (ValueError, TypeError):
        files = {}
    valid = (process.returncode == 0 and bool(files)
             and all(isinstance(mapping, dict) and mapping
                     and all(isinstance(key, str) and isinstance(value, str)
                             for key, value in mapping.items()) for mapping in files.values()))
    t.check('Language dictionaries load as valid UTF-8 string maps without database bootstrap',
            valid and utf8_ok, dictionary_count=len(files))
    t.check('Language dictionaries use clear Korean text without middle-dot punctuation',
            valid and no_middle_dot)
    merged = {}
    for name, mapping in files.items():
        if name != 'common.ko.php' and isinstance(mapping, dict):
            merged.update(mapping)
    merged.update(files.get('common.ko.php', {}))
    required = {'Leave request': '휴가 신청', 'Leave approvals': '휴가 승인 관리',
                'My leave history': '내 휴가 내역', 'Annual leave': '연차 휴가',
                'Sick leave': '병가', 'Personal leave': '개인 휴가'}
    t.check('Language dictionaries preserve the required Korean HR terms and leave meanings',
            all(merged.get(key) == value for key, value in required.items())
            and all('휴가' in merged.get(key, '') and '결근' not in merged.get(key, '')
                    for key in ('Absence report', 'Monthly leave report')))
    placeholder = re.compile(r'\{([A-Za-z][A-Za-z0-9_]*)\}')
    mismatches = [(name, key) for name, mapping in files.items() if isinstance(mapping, dict)
                  for key, value in mapping.items()
                  if set(placeholder.findall(key)) != set(placeholder.findall(value))]
    t.check('Every Korean template retains its English source placeholder names',
            valid and not mismatches, mismatched_keys=[key for _, key in mismatches])
    required_keys = set()
    literal = r"'((?:\\.|[^'\\])*)'"
    for path in list((t.ROOT / 'public').glob('*.php')) + [t.ROOT / 'lib' / 'ui.php']:
        source = path.read_text(encoding='utf-8')
        for match in re.finditer(r'\bui_t\(\s*' + literal + r'\s*(?=[,)])', source):
            required_keys.add(_php_string(match[1]))
        for match in re.finditer(r'\bui_page_start\(\s*' + literal + r'\s*,\s*' + literal, source):
            required_keys.update(_php_string(value) for value in match.groups())
        for match in re.finditer(r'\bui_notice\(\s*' + literal, source):
            required_keys.add(_php_string(match[1]))
    missing = sorted(required_keys - merged.keys())
    t.check('Korean dictionaries cover literal page copy, descriptions, and shared UI labels',
            valid and not missing, missing_keys=missing)
    return merged


def run(t):
    database = t.rows('SELECT DATABASE() AS name')[0]['name']
    if not isinstance(database, str) or not database.endswith('_test'):
        raise RuntimeError('Language checks require an explicitly disposable _test database.')
    translations = _dictionary_checks(t)
    tag = secrets.token_hex(5)
    password = secrets.token_urlsafe(24)
    admin_name, employee_name = 'Language Admin ' + tag, 'Language Employee ' + tag
    admin_id, _, _ = t.fixture(admin_name, True, password)
    employee_id, role, office = t.fixture(employee_name, False, password)
    for index, (leave_type, status) in enumerate((('Annual', 'Pending'), ('Sick', 'Approved'), ('Personal', 'Rejected'))):
        date = '2027-09-' + str(7 + index).zfill(2)
        t.db('INSERT INTO holiday_requests(employee_id,leave_type,start_date,end_date,reason,status) VALUES(?,?,?,?,?,?)',
             [employee_id, leave_type, date, date, 'Language regression ' + tag, status])

    anonymous = t.Client()
    default = anonymous.request('/login.php', headers={'Accept-Language': 'ko-KR,ko;q=0.9'})
    t.check('English remains the default even when the browser prefers Korean',
            default['status'] == 200 and _is_language(default, 'en')
            and _Page(default['body']).headings == ['Employee operations sign-in'])
    korean = t.Client()
    selected = korean.request('/login.php?lang=ko')
    preference = _cookie(korean, 'ui_language')
    rest = {key.lower(): value for key, value in preference._rest.items()} if preference else {}
    t.check('Selecting Korean sets a persistent HttpOnly SameSite Lax preference and Korean metadata',
            selected['status'] == 200 and _is_language(selected, 'ko')
            and preference is not None and preference.value == 'ko' and preference.path == '/'
            and preference.expires is not None and preference.expires > time.time()
            and 'httponly' in rest and str(rest.get('samesite', '')).lower() == 'lax'
            and _Page(selected['body']).headings == [translations.get('Employee operations sign-in')])
    invalid_login = korean.post('/login.php', {'employee_id': admin_id, 'password': 'incorrect test-only password'})
    login_message = 'Unable to sign in. Check your details or try again later.'
    t.check('Invalid credentials display a Korean notice without exposing account details',
            invalid_login['status'] == 200 and _is_language(invalid_login, 'ko')
            and translations.get(login_message, '') in ''.join(_Page(invalid_login['body']).visible)
            and login_message not in ''.join(_Page(invalid_login['body']).visible)
            and admin_name not in invalid_login['body'])
    before_login_sid = korean.sid()
    signed_in = korean.login(admin_id, password)
    after_login_sid = korean.sid()
    workspace = korean.request('/admin_main.php')
    t.check('Korean selection survives CSRF login and session rotation with the correct account',
            signed_in['status'] == 302 and bool(before_login_sid) and bool(after_login_sid)
            and before_login_sid != after_login_sid and _is_language(workspace, 'ko')
            and admin_name in workspace['body'] and _cookie(korean, 'ui_language').value == 'ko')
    before_count = int(t.rows('SELECT COUNT(*) AS n FROM holiday_requests WHERE employee_id=?', [admin_id])[0]['n'])
    invalid_leave = korean.post('/holiday_requests.php', {'request_holiday': '1', 'leave_type': 'Unknown',
                                'start_date': '2027-10-03', 'end_date': '2027-10-04'})
    after_count = int(t.rows('SELECT COUNT(*) AS n FROM holiday_requests WHERE employee_id=?', [admin_id])[0]['n'])
    leave_message = 'Select a valid leave type.'
    t.check('Korean persists through navigation and valid-CSRF invalid leave submission',
            invalid_leave['status'] == 400 and _is_language(invalid_leave, 'ko')
            and translations.get(leave_message, '') in ''.join(_Page(invalid_leave['body']).visible)
            and leave_message not in ''.join(_Page(invalid_leave['body']).visible)
            and before_count == after_count and korean.sid() == after_login_sid)
    changed = korean.request('/admin_main.php?lang=en')
    t.check('Switching back to English retains the authenticated account and session',
            changed['status'] == 200 and _is_language(changed, 'en')
            and admin_name in changed['body'] and korean.sid() == after_login_sid
            and _cookie(korean, 'ui_language').value == 'en')

    malformed = []
    marker = '<script>window.__languageXSS=1</script>'
    for language in ('unsupported', marker):
        fresh = t.Client()
        response = fresh.request('/login.php?' + urlencode({'lang': language}),
                                 headers={'Accept-Language': 'ko'})
        malformed.append(response['status'] == 200 and _is_language(response, 'en')
                         and marker not in response['body']
                         and _Page(response['body']).headings == ['Employee operations sign-in'])
    t.check('Unknown or markup language values safely use English for fresh clients', all(malformed))
    array_client = t.Client()
    array = array_client.request('/login.php?' + urlencode({'lang[]': marker}))
    t.check('Array language parameters safely use English without reflecting executable markup',
            array['status'] == 200 and _is_language(array, 'en') and marker not in array['body'])

    dimensions = t.rows('SELECT d.department_name,p.position_name,o.office_name FROM department d '
                        'JOIN position p ON p.department_id=d.department_id '
                        'JOIN office o ON o.office_id=? WHERE p.position_id=?', [office, role['position_id']])[0]
    filters = dict(q=employee_name, department=dimensions['department_name'], position=dimensions['position_name'],
                   office=dimensions['office_name'], hired_date='2025-01-01', page='1')
    directory = korean.request('/list_employees.php?' + urlencode(dict(filters, export_csv='1')))
    links = _Page(directory['body']).links
    links_ok = directory['status'] == 200 and set(links) == {'en', 'ko'}
    for language, href in links.items():
        parsed = urlsplit(href)
        query = parse_qs(parsed.query)
        links_ok = (links_ok and parsed.path == 'list_employees.php' and not parsed.netloc
                    and query.get('lang') == [language] and 'export_csv' not in query
                    and all(query.get(key) == [value] for key, value in filters.items()))
    t.check('Language links retain directory search and filters while dropping CSV export actions', links_ok)

    clients = {'en': {'admin': korean, 'anonymous': t.Client()},
               'ko': {'admin': t.Client(), 'anonymous': t.Client()}}
    for language in ('en', 'ko'):
        normal = t.Client()
        normal.request('/login.php?lang=' + language)
        result = normal.login(employee_id, password)
        if result['status'] != 302:
            raise RuntimeError('Language employee fixture could not sign in.')
        clients[language]['employee'] = normal
        if language == 'ko':
            clients[language]['admin'].request('/login.php?lang=ko')
            result = clients[language]['admin'].login(admin_id, password)
            if result['status'] != 302:
                raise RuntimeError('Language administrator fixture could not sign in.')
            clients[language]['anonymous'].request('/login.php?lang=ko')
    denied = [clients['ko']['employee'].request(path) for path in
              ('/admin_main.php', '/list_employees.php', '/add_employee.php', '/check_holiday_requests.php',
               '/employee_detail.php?id=' + str(employee_id), '/update_employee.php?employee_id=' + str(employee_id))]
    t.check('Korean selection does not grant ordinary employees administrator access',
            all(response['status'] in (302, 403) and admin_name not in response['body'] for response in denied),
            response_statuses=[response['status'] for response in denied])

    routes = [('/login.php', 'anonymous', 'Employee operations sign-in'),
              ('/main.php', 'employee', 'My leave overview'),
              ('/admin_main.php', 'admin', 'Workforce overview'),
              ('/add_employee.php', 'admin', 'Add employee'),
              ('/update_employee.php?employee_id=' + str(employee_id), 'admin', 'Update employee'),
              ('/delete_employee.php', 'admin', 'Delete employee'),
              ('/list_employees.php', 'admin', 'Employee directory'),
              ('/employee_detail.php?id=' + str(employee_id), 'admin', 'Employee profile'),
              ('/holiday_requests.php', 'admin', 'Leave request'),
              ('/check_holiday_requests.php', 'admin', 'Leave approvals'),
              ('/check_my_holiday.php', 'employee', 'My leave history'),
              ('/check_absence.php', 'admin', 'Monthly leave report'),
              ('/salary_table.php', 'admin', 'Salary report'),
              ('/happybirthday.php', 'admin', 'Employee birthdays'),
              ('/delete_records.php', 'admin', 'Employee deletion records'),
              ('/change_password.php', 'admin', 'Change password'),
              ('/logout.php', 'admin', 'Sign out')]
    rendered = {}
    for language in ('en', 'ko'):
        failures = []
        for path, identity, title in routes:
            response = clients[language][identity].request(path)
            page = _Page(response['body'])
            rendered[language, path.split('?')[0]] = (response, page)
            expected = title if language == 'en' else translations.get(title)
            if (response['status'] != 200 or not _is_language(response, language)
                    or not expected or page.headings != [expected]):
                failures.append(path.split('?')[0])
        t.check('All seventeen rendered pages return correct ' + language.upper() + ' titles and language metadata',
                not failures, failed_paths=failures, page_count=len(routes))
    placeholder = re.compile(r'\{[A-Za-z][A-Za-z0-9_]*\}')
    unresolved = [language + ':' + path for (language, path), (_, page) in rendered.items()
                  if placeholder.search(' '.join(page.visible + page.display_attributes))]
    t.check('Rendered labels, notices, and accessible attributes resolve all template placeholders',
            not unresolved, failed_paths=unresolved)
    client_json_ok = all(page.messages.get('locale') == language
                         and page.messages.get('Show password') == ('Show password' if language == 'en'
                                                                  else translations.get('Show password'))
                         and page.messages.get('Complete this required field.') ==
                         ('Complete this required field.' if language == 'en'
                          else translations.get('Complete this required field.'))
                         for (language, _), (_, page) in rendered.items())
    t.check('Every rendered page supplies client validation and password messages in its active language', client_json_ok)

    contracts = True
    for path in ('/login.php', '/update_employee.php', '/holiday_requests.php', '/check_holiday_requests.php'):
        en, ko = rendered['en', path][1], rendered['ko', path][1]
        contracts = contracts and en.control_names == ko.control_names
        for field in en.options:
            contracts = contracts and [value for value, _ in en.options[field]] == [value for value, _ in ko.options.get(field, [])]
    en_update, ko_update = rendered['en', '/update_employee.php'][1], rendered['ko', '/update_employee.php'][1]
    contracts = (contracts and en_update.inputs.get('employee_id') == ko_update.inputs.get('employee_id')
                 and str(employee_id) in ko_update.inputs.get('employee_id', [])
                 and en_update.inputs.get('name') == ko_update.inputs.get('name') == [employee_name]
                 and en_update.inputs.get('row_version') == ko_update.inputs.get('row_version'))
    leave = rendered['ko', '/holiday_requests.php'][1].options.get('leave_type', [])
    contracts = (contracts and [value for value, _ in leave] == ['Annual', 'Sick', 'Personal']
                 and all(translations[value] in label for value, label in leave))
    t.check('Language changes preserve business form names, enum values, record versions, and employee data', contracts)
    history = ''.join(rendered['ko', '/check_my_holiday.php'][1].visible)
    t.check('Korean leave history accurately displays all stored leave types and decision states',
            employee_name in history and all(translations[value] in history
                for value in ('Annual', 'Sick', 'Personal', 'Pending', 'Approved', 'Rejected')))

    exports = [clients[language]['admin'].request('/salary_table.php?export_csv=1') for language in ('en', 'ko')]
    records = [list(csv.reader(io.StringIO(response['body']))) for response in exports]
    t.check('Korean display selection keeps CSV content and its eleven English data columns unchanged',
            all(response['status'] == 200 and _header(response, 'Content-Type').startswith('text/csv') for response in exports)
            and exports[0]['body'] == exports[1]['body'] and records[0] and records[0][0][0] == 'Employee ID'
            and all(len(row) == 11 for rows in records for row in rows))
    ko_admin = clients['ko']['admin']
    malformed_existing = [ko_admin.request('/admin_main.php?' + query)
                          for query in ('lang=unsupported', 'lang%5B%5D=ko')]
    t.check('Malformed language parameters preserve an existing valid Korean preference and authentication',
            all(response['status'] == 200 and _is_language(response, 'ko') and admin_name in response['body']
                for response in malformed_existing))
    signed_out = ko_admin.post('/logout.php', {})
    after_logout = ko_admin.request('/login.php')
    t.check('CSRF sign-out ends authentication while preserving the Korean display choice',
            signed_out['status'] == 302 and after_logout['status'] == 200 and _is_language(after_logout, 'ko')
            and ko_admin.request('/admin_main.php')['status'] == 302)
