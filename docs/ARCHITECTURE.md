<p align="right"><strong>English</strong> | <a href="ARCHITECTURE.ko.md">한국어</a></p>

# Architecture

[Project overview](../README.md)

## Request lifecycle

~~~mermaid
sequenceDiagram
    actor Browser
    participant Page as public/*.php
    participant Guard as config/bootstrap.php
    participant DB as MySQL
    Browser->>Page: GET page or POST form
    Page->>Guard: Start hardened session and load current account
    Guard->>DB: Verify account, role and credential version
    DB-->>Guard: Current access context
    Page->>Guard: Verify CSRF for POST
    Page->>DB: Validated query or atomic workflow
    DB-->>Page: Result
    Page-->>Browser: Escaped HTML, safe CSV or redirect
~~~

The original 18 PHP endpoints remain page controllers. `config/bootstrap.php` centralizes authorization, sessions, CSRF and output helpers. `lib/business.php` validates employee and leave fields. Database setup is CLI-only and uses a separate schema account. The web account has no schema or user-management privileges.

## Presentation and language boundaries

`lib/ui.php` renders the shared navigation, page frame and language control. `lib/i18n.php` defaults to English and loads reviewed Korean source-message catalogs only when Korean is selected. A non-authentication preference cookie keeps the chosen display language; switching language does not modify employee records or access rights. Shared HR vocabulary is canonical across pages. `public/assets/js/app.js` receives only the small set of translated client messages needed for previews, validation and controls.

The local CSS/SVG design is checked against computed foreground/background colors, control boundaries and responsive layouts. Language-specific blank sign-in previews are separated in `docs/images/`; English documentation uses the English preview and Korean documentation uses the Korean preview. See [interface verification](INTERFACE.md).

## Identity and access

An ordinary employee can manage only personal leave and credentials. Executive membership, looked up by department name, grants the administration workspace. No seed-order department ID is assumed. Every protected request joins the current employee, department and credential record. A deleted/inactive account, a mismatched `auth_version`, or an expired session is denied. Employee department changes and password changes increment the credential version; password change re-establishes the current session and invalidates other sessions.

Sessions rotate IDs on authentication and password change, use strict cookie-only mode, HttpOnly and SameSite=Lax, and set Secure for HTTPS. Defaults are 30 minutes idle and 8 hours absolute. CSRF tokens are random and session-bound. POSTs also check Origin when supplied and reject cross-site Fetch Metadata. An absent Origin is not a bypass for the token requirement.

The credential table is not a login audit. `login_attempts` records hashed account/IP keys for bounded failure limits. Verification holds account/IP advisory locks to keep concurrent attempts from bypassing the counters. Old entries are pruned. Temporary passwords are generated per account and require a first change; a database trigger initially creates an inactive NULL-hash account so raw SQL inserts do not acquire a known password.

## Data integrity

| Operation | Integrity boundary |
| --- | --- |
| Create employee | Auto-increment ID and one transaction for employee, contact, trigger-created balance/account and credential provisioning |
| Update employee | Reload exact ID under lock; compare `row_version`; update employee/contact atomically; increment credential version for department changes |
| Approve leave | Lock saved Pending request; derive owner, type and calendar duration; conditionally debit sufficient balance and make one terminal status transition |
| Delete employee | Stored procedure owns its transaction, snapshots full-width source fields, deletes employee and commits; any error rolls back |
| Monthly absence | Select overlapping approved date ranges and count only their intersection with the chosen month |

Pending requests do not reserve allowance; administrators can see multiple pending requests, but an approval with insufficient remaining balance is rejected and remains Pending. Rejection never debits balance. Leave days are inclusive calendar dates, including weekends, preserving the coursework rule. Dates are normalized to UTC date-only values so daylight-saving transitions do not change day counts.

`employees.salary` retains the original text representation and existing values. Percentage changes require a numeric salary. Reporting preserves the original simplified bonus/deduction formulas. Payroll CSV uses an explicit empty escape parameter and prefixes dangerous formula-like text with an apostrophe; this intentionally changes exported text representation for spreadsheet safety.

## Setup and history

Current schema and database objects live in `database/schema.php` and `database/objects.php`. Setup imports the fictional CSV once, records migration versions, and does not restore intentionally deleted people on rerun. Migration upgrades existing tables without reseeding. Existing non-default password hashes remain intact; known legacy default hashes are replaced with unique temporary credentials and old sessions are revoked.

The deletion audit records are retained. The former automatic three-year deletion on report GET is now an explicit CSRF-protected POST action. Initial source and experiment/audit history remain in Git or the owner's private audit artifacts, not as public executable vulnerable copies. See [security and verification](SECURITY.md) and [database operations](../database/README.md).

## Boundaries

No framework, cloud service, mail sender or external authentication service is required. This remains an educational local HR application. A production service would additionally need an independent role model, MFA/recovery policy, managed secrets/backups, deployment-specific TLS monitoring and a business-reviewed data retention policy. Those are outside this repository's verification claim.
