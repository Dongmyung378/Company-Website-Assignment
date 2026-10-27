"""HTTP checks for directory search, pagination, and profile editing links.

The parent security regression harness owns the disposable database, server,
credentials, result writer, and cleanup. This module reports no credentials or
complete response bodies and makes no changes outside its registered fixtures.
"""
from __future__ import annotations

import html
from html.parser import HTMLParser
import secrets
from urllib.parse import parse_qs, urlencode, urlsplit


class _Page(HTMLParser):
    def __init__(self, body: str):
        super().__init__(convert_charrefs=True)
        self.cards: list[int] = []
        self.next_href: str | None = None
        self.inputs: dict[str, list[str]] = {}
        self.hidden: dict[str, list[str]] = {}
        self.profile_edit_href: str | None = None
        self.feed(body)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'a':
            href = attrs.get('href') or ''
            classes = (attrs.get('class') or '').split()
            if 'employee-card' in classes:
                query = parse_qs(urlsplit(href).query)
                ident = query.get('id', [''])[0]
                if urlsplit(href).path == 'employee_detail.php' and ident.isdigit():
                    self.cards.append(int(ident))
            if 'next' in (attrs.get('rel') or '').split():
                self.next_href = href
            if urlsplit(href).path == 'update_employee.php' and 'employee_id' in parse_qs(urlsplit(href).query):
                self.profile_edit_href = href
        if tag == 'input' and attrs.get('name'):
            name = attrs['name']
            value = attrs.get('value') or ''
            self.inputs.setdefault(name, []).append(value)
            if attrs.get('type') == 'hidden':
                self.hidden.setdefault(name, []).append(value)


def _directory(client, filters):
    response = client.request('/list_employees.php?' + urlencode(filters))
    return response, _Page(response['body'])


def run(t):
    if not t.rows('SELECT DATABASE() n')[0]['n'].endswith('_test'):
        raise RuntimeError('Disposable test database required.')
    tag = secrets.token_hex(5)
    password = secrets.token_urlsafe(24)
    admin_id, _, _ = t.fixture('UI Admin ' + tag, True, password)
    prefix = 'UI Search ' + tag
    names = [prefix + suffix for suffix in [' %_!', ' %x!', ' ax!', ' %_']]
    names += [prefix + ' Person ' + str(index).zfill(2) for index in range(11)]
    employee_ids = []
    for name in names:
        ident, _, _ = t.fixture(name, False, password)
        employee_ids.append(ident)
    target_id = employee_ids[0]
    target = t.rows('SELECT e.employee_id,e.name,e.email,e.row_version,d.department_name,'
                    'p.position_name,o.office_name FROM employees e '
                    'JOIN department d ON d.department_id=e.department_id '
                    'JOIN position p ON p.position_id=e.position_id '
                    'JOIN office o ON o.office_id=e.office_id WHERE e.employee_id=?', [target_id])[0]
    admin = t.Client()
    login = admin.login(admin_id, password)
    t.check('UI administrator fixture authenticates for directory checks', login['status'] == 302,
            response_status=login['status'])

    by_name, name_page = _directory(admin, {'q': names[0]})
    by_email, email_page = _directory(admin, {'q': target['email']})
    t.check('UI directory searches both employee names and work email',
            by_name['status'] == 200 and by_email['status'] == 200
            and name_page.cards == [target_id] and email_page.cards == [target_id])

    percent, percent_page = _directory(admin, {'q': prefix + ' %'})
    underscore, underscore_page = _directory(admin, {'q': prefix + ' %_'})
    exclamation, exclamation_page = _directory(admin, {'q': prefix + ' %_!'})
    t.check('UI directory treats percent signs as literal search text',
            percent['status'] == 200 and set(percent_page.cards) == {employee_ids[0], employee_ids[1], employee_ids[3]}
            and len(percent_page.cards) == 3)
    t.check('UI directory treats underscores as literal search text',
            underscore['status'] == 200 and set(underscore_page.cards) == {employee_ids[0], employee_ids[3]}
            and len(underscore_page.cards) == 2)
    t.check('UI directory searches literal exclamation marks without losing results',
            exclamation['status'] == 200 and exclamation_page.cards == [target_id])

    payload = '\"><script>window.__uiXSS=1</script>'
    reflected, reflected_page = _directory(admin, {'q': payload})
    t.check('UI directory preserves search text while escaping HTML attribute content',
            reflected['status'] == 200 and reflected_page.inputs.get('q') == [payload]
            and html.escape(payload, quote=True) in reflected['body']
            and '<script>window.__uiXSS=1</script>' not in reflected['body'])

    array_statuses = {}
    for name in ['q', 'employee_id', 'department', 'position', 'office', 'hired_date', 'page']:
        response, _ = _directory(admin, {name + '[]': 'invalid'})
        array_statuses[name] = response['status']
    t.check('UI directory rejects array inputs for every scalar search field',
            all(status == 400 for status in array_statuses.values()), response_statuses=array_statuses)

    invalid_statuses = []
    for page in ['0', '-1', '1.5', 'invalid', '2147483648']:
        response, _ = _directory(admin, {'q': prefix, 'page': page})
        invalid_statuses.append(response['status'])
    t.check('UI directory rejects invalid and out-of-range page numbers',
            all(status == 422 for status in invalid_statuses), response_statuses=invalid_statuses)

    placeholders = ','.join('?' for _ in employee_ids)
    ordered = [int(row['employee_id']) for row in t.rows(
        'SELECT employee_id FROM employees WHERE employee_id IN (' + placeholders + ') ORDER BY name ASC,employee_id ASC',
        employee_ids)]
    filters = {'q': prefix, 'department': target['department_name'], 'position': target['position_name'],
               'office': target['office_name'], 'hired_date': '2025-01-01'}
    first, first_page = _directory(admin, filters)
    second, second_page = _directory(admin, dict(filters, page=2))
    repeat, repeated_page = _directory(admin, filters)
    t.check('UI directory returns twelve employees per page in stable name and ID order',
            first['status'] == second['status'] == repeat['status'] == 200
            and len(first_page.cards) == 12 and len(second_page.cards) == 3
            and first_page.cards + second_page.cards == ordered
            and repeated_page.cards == first_page.cards
            and not (set(first_page.cards) & set(second_page.cards)),
            first_page_count=len(first_page.cards), second_page_count=len(second_page.cards))

    next_query = parse_qs(urlsplit(first_page.next_href or '').query)
    t.check('UI directory pagination preserves all active search and category filters',
            first_page.next_href is not None and urlsplit(first_page.next_href).path == 'list_employees.php'
            and all(next_query.get(key) == [value] for key, value in filters.items())
            and next_query.get('page') == ['2'])

    overflow, overflow_page = _directory(admin, dict(filters, page=2147483647))
    t.check('UI directory clamps a valid oversized page to its final available page',
            overflow['status'] == 200 and overflow_page.cards == ordered[12:]
            and overflow_page.next_href is None)
    filtered_id, filtered_id_page = _directory(admin, dict(filters, employee_id=target_id))
    t.check('UI directory retains the exact employee ID filter alongside new search',
            filtered_id['status'] == 200 and filtered_id_page.cards == [target_id])

    profile = admin.request('/employee_detail.php?id=' + str(target_id))
    profile_page = _Page(profile['body'])
    edit_url = profile_page.profile_edit_href
    edit = admin.request('/' + edit_url) if edit_url and edit_url.startswith('update_employee.php?') else {'status': 0, 'body': ''}
    edit_page = _Page(edit['body'])
    after_get = t.rows('SELECT row_version FROM employees WHERE employee_id=?', [target_id])[0]
    t.check('UI profile edit link loads the intended employee and its current record version',
            profile['status'] == 200 and edit['status'] == 200
            and edit_page.hidden.get('employee_id') == [str(target_id)]
            and edit_page.hidden.get('row_version') == [str(target['row_version'])]
            and edit_page.inputs.get('name') == [target['name']]
            and int(after_get['row_version']) == int(target['row_version']))

    employee = t.Client()
    signed_in = employee.login(target_id, password)
    denials = [employee.request(path) for path in ['/list_employees.php',
                '/employee_detail.php?id=' + str(target_id), '/update_employee.php?employee_id=' + str(target_id)]]
    t.check('UI directory, profiles, and direct edit links remain restricted to administrators',
            signed_in['status'] == 302 and all(response['status'] in (302, 403) for response in denials)
            and all('row_version' not in _Page(response['body']).hidden for response in denials),
            response_statuses=[response['status'] for response in denials])
