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

## English first, Korean by choice

The default interface is English, regardless of the browser's Accept-Language header. Use the header selector to choose Korean. A host-only, HttpOnly, SameSite=Lax preference cookie preserves that choice across navigation, sign-in and sign-out. Changing a display language never changes permissions, employee IDs, stored status values or payroll numbers. The language links preserve current search filters; an unsaved-entry confirmation prevents an accidental reload from discarding form input.

English source strings and named placeholders are maintained in `lib/i18n.php` and `lib/translations/*.ko.php`. Shared HR terms have one canonical Korean translation. Annual leave means 연차 휴가, sick leave means 병가, and personal leave means 개인 휴가. The monthly report counts approved leave, so it is described as 월별 휴가 현황 rather than an unexcused-absence report. Full-Time/Part-Time are translated as 전일제/시간제, without claiming permanent employment status. UK National Insurance numbers are not described as Korean resident registration numbers.

All application labels, help, status badges, account notices and client-side previews have Korean text. Employee names, addresses and other entered data remain unchanged. Native browser date-picker dialogs follow the browser/OS locale. CSV exports deliberately retain the original English headers and values; the export screen explains that behavior.

## Readability and visual consistency

The original square Kilburnazon company illustration is shown without cropping on the sign-in page and the administrator and employee overviews. Its colors inform a navy and dark-green palette with a soft sage background. Body copy and captions stay on separate surfaces rather than covering the image. On mobile, the sign-in form comes first and the illustration follows.

The consolidated `app.css` defines shared spacing for panels, forms and task cards. Consistent SVG icons, restrained avatar colors and distinct white content surfaces give each task a clear visual boundary. Numeric salary columns align right; wide tables show a localized horizontal-scroll hint. The mobile menu keeps background content inert while it is open.

Computed foreground/background colors, including alpha composition, are checked against the [W3C text-contrast criteria](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) and [non-text contrast criteria](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html). These checks use 4.5:1 for normal text, 3:1 for large text, and 3:1 for meaningful control boundaries/icons. They are focused checks, not a claim of complete accessibility certification.

## Local access

Follow the existing [setup guide](../database/README.md). Login information is delivered through private local credential exports, never a fixed public password in source. An owner's local demo account and access note are runtime data excluded from Git. An administrator must change a temporary password at first sign-in.

## Verification

The current design passed **1,288/1,288 real-browser assertions** across **90 page/language/viewport combinations**, including **24 reviewed screenshots**. English and Korean were checked at 1440px and 390px; 320px, 360px and 768px have additional checks. The separate [HTTP, security, workflow and language suite](verification/company-design-regression.json) passed **121/121 assertions**.

Across those rendered cases, 22,244 text samples and 78 placeholders met their contrast thresholds. The minimum measured text contrast was **5.141:1**, placeholder contrast **5.461:1**, and control-boundary contrast **3.523:1** across 1,040 boundary samples. The audit also checked 882 functional SVG samples, 22,582 font-size samples and 98 button/icon containment samples. No unresolved paint stacks or failures remained. Hidden, inactive, inert and decorative content is excluded as described in the saved report.

- [Current HTTP/language evidence](verification/company-design-regression.json) covers all 17 screens in both languages, default-language behavior, locale persistence, placeholder coverage, unchanged English CSV, workflow integrity and access restrictions.
- [Current PHP syntax evidence](verification/company-design-php-lint.json) covers the five PHP files changed for this design. The earlier [full PHP check](verification/bilingual-php-lint.json) covers all 52 files.
- [Current browser and computed-style evidence](verification/company-design-browser.json) records contrast, layout, language selection, unsaved-entry handling, form clicks, calendar previews, resource/script errors and test-process cleanup.
- The earlier [bilingual HTTP/language evidence](verification/bilingual-regression.json) and [bilingual browser evidence](verification/bilingual-browser.json) remain available as prior checkpoints.
- Earlier [interface regression](verification/ui-regression.json), [browser evidence](verification/ui-browser.json) and [security-update evidence](SECURITY.md) remain available as the initial checkpoints.

Re-run the standard suite against a disposable `_test` database:

~~~sh
python tests/security_regression.py
~~~

The optional browser suite additionally needs a local Microsoft Edge installation and the `websocket-client` Python package. It uses an isolated browser profile and test accounts, and stops its own servers/browser afterward:

~~~sh
python tests/browser_ui.py
~~~

Use `--edge /path/to/msedge` or `EDGE_BIN` for another Edge installation. Both scripts respect `PHP_BIN` and `PHP_ARGS_JSON`; see [verification setup](SECURITY.md#reproduce). Screenshots are written to ignored `var/ui-screenshots/`. Only the blank English/Korean sign-in previews are selected for `docs/images/`; credentials and browser profiles are never published.

The layout checks and visual review cover the listed viewports in local Edge. They are not a claim of complete accessibility certification, all-browser compatibility or every possible content length. Existing functionality is also checked through HTTP and concurrent requests, independently of presentation.
