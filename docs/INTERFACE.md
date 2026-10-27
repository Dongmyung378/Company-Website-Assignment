<p align="right"><strong>English</strong> | <a href="INTERFACE.ko.md">한국어</a></p>

# Interface and everyday workflows

[Project overview](../README.md)

The shared interface covers the 17 rendered PHP screens; `index.php` remains a redirect. It uses local CSS, JavaScript and SVG assets without a frontend build or external font/CDN requests. The original page endpoints, dataset, security controls and coursework payroll rules remain in place.

## What changed

| Area | User-facing behavior |
| --- | --- |
| Navigation | Persistent desktop navigation, current-page indicator, keyboard skip link, mobile menu with Escape handling and focus restoration |
| Overview | Live employee, pending-request, current-absence and office counts; inline review notice replaces an interrupting browser alert |
| Employee home | Personal leave balances, recent request decisions and direct self-service links |
| Employee directory | Name/email search, expandable advanced filters, stable alphabetical ordering and 12 results per page with filters preserved |
| Profiles | Grouped work, contact, personal and emergency information with a direct link to edit the exact employee |
| Employee forms | Required-field guidance, department-specific position choices, date limits and clearer validation feedback |
| Leave | Inclusive calendar-day preview, expected remaining balance and readable status badges/history |
| Reports | Labelled filters, summary cards, responsive tables and the same salary CSV export/calculations |
| Credentials | Password visibility buttons and matching-password feedback; first-change and current-password requirements remain enforced |
| Submission | Confirmation before destructive actions and a duplicate-submit guard that preserves the clicked POST action |

The directory searches `%`, `_` and `!` literally using bound SQL parameters. A large valid page number resolves to the last available page; invalid scalar/array or out-of-range input is rejected. Advanced filters open automatically when active.

The department filter and leave preview are browser assistance, not authorization. The server still validates position membership, account state, date ranges, balances, CSRF, record versions and transactions. JavaScript-disabled use retains server rendering and validation. Wide tables scroll inside their panels rather than widening the entire page. Reduced-motion preferences and print layouts are supported.

## Local access

Follow the existing [setup guide](../database/README.md). Login information is delivered through private local credential exports, never a fixed public password in source. An owner's local demo account and access note are runtime data excluded from Git. An administrator must change a temporary password at first sign-in.

## Verification

Recorded results: **97/97 HTTP/workflow checks**, **219/219 browser assertions**, **41 page/viewport combinations**, **12 reviewed screenshots**, and **47/47 PHP syntax checks**. All screens were checked at 1440px and 390px, with additional 360px and 768px coverage.

Current results are stored separately from the earlier security-update evidence so that the initial record remains available:

- [HTTP, security and workflow regression](verification/ui-regression.json): the previous 83 assertions plus 14 checks for search, escaping, pagination, direct edit prefill and administrator restrictions.
- [PHP syntax checks](verification/ui-php-lint.json): all 47 PHP source files, including the shared UI helper.
- [Real-browser UI checks](verification/ui-browser.json): every rendered screen at 1440px desktop and 390px mobile widths, with additional 360px and 768px checks. It checks page structure, unique IDs, labelled controls, document overflow, script/resource failures, navigation, calendar preview and actual CSRF-protected form clicks.

Re-run the standard suite against a disposable `_test` database:

~~~sh
python tests/security_regression.py
~~~

The optional browser suite additionally needs a local Microsoft Edge installation and the `websocket-client` Python package. It uses an isolated browser profile and test accounts, and stops its own servers/browser afterward:

~~~sh
python tests/browser_ui.py
~~~

Use `--edge /path/to/msedge` or `EDGE_BIN` for another Edge installation. Both scripts respect `PHP_BIN` and `PHP_ARGS_JSON`; see [verification setup](SECURITY.md#reproduce). Screenshots are written only to ignored `var/ui-screenshots/`, not published with credentials or browser profiles.

The layout checks and visual review cover the listed viewports in local Edge. They are not a claim of complete accessibility certification, all-browser compatibility or every possible content length. Existing functionality is also checked through HTTP and concurrent requests, independently of presentation.
