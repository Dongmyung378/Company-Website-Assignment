<p align="right"><strong>English</strong> | <a href="SECURITY.ko.md">한국어</a></p>

# Security changes and verification

Date: **2026-10-27**. [Project overview](../README.md).

The application is coursework stored in a repository, not an active deployed HR service. The review baseline is commit `02fdfe6b9bdcc57e700c9353fdc155095cf30221`. Changes preserve that history, the fictional CSV, the 18 public endpoints and core employee/leave/reporting features. Known unsafe request acceptance is intentionally tightened.

## Finding-to-fix traceability

| ID | Finding | Implemented control and verification |
| --- | --- | --- |
| F01 | Leave request ownership could be forged | Owner comes from the current session; a foreign employee ID is rejected; HTTP regression checks victim records |
| F02 | Reflected HTML/script injection | Strict report-period/date/type validation, context-appropriate escaping and a fixed directory Reset URL; three HTTP and three real-browser rechecks |
| F03 | Missing CSRF and current-password checks | Session-bound tokens on every mutation and login, Origin/Fetch Metadata checks, password reauthentication; logout and expired-record deletion require explicit POST |
| F04 | Stale roles, deleted accounts and reusable password sessions | Current employee/role/version lookup on every protected request; password or department changes revoke old credential versions |
| F05 | Shared initial password, reset on setup and weak login controls | Random individual temporary passwords, mandatory first change, 15-character minimum, bounded failure throttling and idempotent credential provisioning |
| F06 | Approval could trust forged metadata or debit repeatedly | Load and lock stored Pending request; compute calendar duration; conditional balance debit and terminal status in one transaction; replay and parallel-approval regressions |
| F07 | Unchanged login session ID and missing lifecycle controls | Strict cookie-only sessions, ID rotation, HttpOnly/SameSite, HTTPS Secure flag, idle/absolute expiry |
| F08 | Spreadsheet formulas in payroll CSV | Prefix dangerous formula-like text with an apostrophe, including leading ASCII whitespace/control cases; test both formula-like and normal text |
| F09 | Raw exceptions, diagnostics and malformed parameter types | Private logging, generic 4xx/500/503 responses, scalar/type checks; test invalid arrays, DB outage and injected second-write failure |
| F10 | Stale employee/salary identity in multiple tabs | Reload exact target under lock and compare `row_version`; conflicting updates return 409 without a second salary change |
| F11 | Deletion audit fields narrower than employee data | Match source widths; deletion procedure owns rollback/commit; test 100-character employee/operator/position, 50-character NIN and 255-character reason |
| F12 | Incorrect month-boundary absence totals | Select overlapping approved ranges and count only the month intersection; Jan 31–Feb 2 reports one and two days |
| F13 | Partial employee/contact writes and ID races | Auto-increment IDs and transactions; test failed contact rollback plus actual parallel employee creation on two PHP processes |
| F14 | Timestamp division miscounted daylight-saving dates | Strict date-only parsing and inclusive calendar arithmetic; spring/fall DST, leap-day and year-boundary checks in three timezones |
| F15 | Root/empty defaults and unbounded runtime requirements | Fail-closed environment template, separate restricted runtime user and tested patched runtime; web account cannot create schema objects |
| F16 | PHP 8.5 CSV escape deprecations | Explicit empty escape argument for import/export; round trips for quotes, backslashes, newlines and UTF-8; CSV response excludes PHP diagnostics |

## Recorded results

| Validation | Result | Evidence |
| --- | --- | --- |
| Automated HTTP, workflow, concurrency and helper checks | **83 / 83 passed** | [Regression JSON](verification/regression.json) |
| Upgrade from the original schema and data | **49 / 49 passed** | [Migration JSON](verification/migration.json) |
| Previously executable reflected-XSS paths in headless Edge | **3 / 3 blocked** | [Browser JSON](verification/browser.json) |
| Optional coursework schema repeated setup | **18 / 18 passed** | [Optional-schema JSON](verification/optional-schema.json) |
| Runtime privileges/data after setup and migration reruns | **10 / 10 passed** | [Rerun JSON](verification/runtime-rerun.json) |
| PHP syntax | **46 / 46 files passed** | [Lint JSON](verification/php-lint.json) |

Fresh setup created 197 employees with balances, contacts and unique temporary credentials. Migration started from the original schema with 197 people, then retained 196 after a deliberate legitimate deletion. It preserved all remaining employee IDs, an edited salary, contacts, balances, two leave requests and one deletion log. One established non-default password hash remained unchanged; 195 legacy default-password accounts received distinct temporary passwords. Repeated migration/setup preserved passwords and records and did not resurrect the deleted employee. All eight existing foreign-key definitions were retained.

Two separate PHP processes and independent administrator sessions exercised real concurrent handlers. Two approvals of one request produced one approval and one conflict. Competing four-day approvals against a five-day balance left one day, with the second request still Pending. Two simultaneous employee creations produced different IDs and complete contact/account/balance records. These are focused concurrency checks, not a sustained load benchmark.

The repository publishes sanitized assertion reports only. Private credentials, browser profiles, cookies, raw HTTP responses, full initial audit files and personal machine logs are excluded. Initial source remains available in Git history. The original security review and full reproduction artifacts remain in the owner's private audit folder.

## Reproduce

Follow [database setup](../database/README.md) using a fresh disposable `company_regression_test` database, a new runtime username (e.g. `company_regression_app`), separate setup/runtime credentials and the supplied fictional seed. MySQL account names are server-wide; reuse of an existing runtime username is deliberately refused. Then run:

~~~sh
python tests/security_regression.py
~~~

Python 3.10+ standard-library tests start and stop their own loopback PHP servers. Set `PHP_BIN` if PHP is not on PATH. `PHP_ARGS_JSON` accepts additional options, for example a portable Windows runtime:

~~~powershell
$env:PHP_BIN = 'C:/tools/php/php.exe'
$env:PHP_ARGS_JSON = '["-n","-d","extension_dir=C:/tools/php/ext","-d","extension=mysqli"]'
python tests/security_regression.py
~~~

The automated harness uses its restricted account for web requests and its separate setup account only for synthetic fixtures and rollback/error injection. Database names must end in `_test`. It cleans its employee fixtures and stops its servers. Detailed output goes to ignored `var/security-regression.json`. The separate migration and headless-Edge runs are recorded above; they are not falsely counted as tests performed by this single command.

## Runtime advisories and startup settings

The isolated environment used PHP **8.5.12**, official Windows **8.5.12-1** package, and MySQL **8.4.13 LTS**. No globally installed PHP or existing MySQL service was modified. Use current vendor security updates, not an arbitrary PHP/MySQL 8.x build.

- PHP's [October 2026 notices](https://www.php.net/archive/2026.php) and core [OOM advisory](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) / [state advisory](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp) cover the runtime patches used as this baseline. Windows package rebuilds matter even when `php -v` is unchanged.
- Set `session.upload_progress.enabled=0` at startup while following the vendor guidance. This app has no upload-progress feature. The [sample server INI](../config/php-production.ini) contains the setting; the local/test launch commands also set it before request parsing.
- MySQL's [8.4.13 release notes](https://dev.mysql.com/doc/relnotes/mysql/8.4/en/news-8-4-13.html) and [October advisory](https://dev.mysql.com/community/security/advisories/2026-10-20/) describe the supported patched database baseline. The original audit's MySQL 8.0.45 was not used for final validation.

The work does not assert absence of every possible vulnerability. Production TLS/proxy configuration, server/OS patching, every spreadsheet application's formula interpretation, exhaustive fuzzing, real user data and PHP memory-corruption exploitation are outside these tests. Keep private filesystem permissions, backups and any real retention policy under deployment control.
