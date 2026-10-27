<p align="right"><strong>English</strong> | <a href="README.ko.md">한국어</a></p>

# Database setup and migration

[Project overview](../README.md)

## Credentials and prerequisites

Use a supported patched MySQL release (verified: 8.4.13 LTS) and PHP with MySQLi. Serve only `public/`; every setup entrypoint refuses HTTP execution.

Copy `.env.example` to the repository root `.env`. For a local exercise, `DB_SETUP_USER`/`DB_SETUP_PASSWORD` may identify an existing local MySQL administrator with a nonempty password. The setup account needs database creation/DDL, triggers, routine creation and the ability to provision and grant the restricted runtime user. **Never use that account as `DB_USER`.** Production should use separately controlled, short-lived migration credentials rather than retaining administrator credentials in the web process.

Set `DB_USER` to a new non-root application username, such as `company_app`, and `DB_PASSWORD` to a different long random password. Use the correct local `DB_PORT` (the example uses 3307; a standard MySQL installation often uses 3306). Set `APP_ORIGIN=http://127.0.0.1:8000` for the README development command. Environment variables take precedence over `.env`.

## Fresh installation

Run from the repository root:

~~~sh
php scripts/setup.php
php scripts/create-runtime-user.php
~~~

The first command creates the schema and imports 197 fictional employees once. It installs two employee provisioning triggers and the deletion stored procedure. Every seeded account receives a different random temporary password and is forced to change it on first sign-in. CLI output contains a **path**, not passwords: the local `var/private/credentials-*.json` file contains an `accounts` array with `employee_id` and `temporary_password`. Keep it private, use it only for initial delivery, and remove it when no longer needed. It is excluded from Git.

The second command creates `DB_USER` at a loopback host using the configured `DB_PASSWORD`. It refuses to overwrite an existing MySQL account. If no password was configured, it generates one in `var/private/runtime-db-*.json`; copy that value into your private runtime configuration before starting the application. A username supplied by `.env` is respected. Optional flags are `--user=...` and `--host=127.0.0.1` (also `localhost` or `::1`).

If a deliberately configured account already exists, inspect its grants with your MySQL administrator; do not repeatedly run account creation or reset an established password. The setup/migration command itself is safe to rerun: it preserves existing credentials, leave balances, requests, edited employee records and deletion history, and does not recreate deleted employees.

## Existing installation

Back up the database first. Point the separate setup credentials at the existing database and run:

~~~sh
php scripts/migrate.php
~~~

Migration preserves existing people, IDs, salaries, contacts, leave records and audit logs. It adds automatic ID allocation, employee record versions, credential versions, first-change flags and failed-login records, and widens deletion snapshot fields. Known legacy password `0000` hashes are replaced with unique temporary passwords exported privately; non-default hashes remain unchanged. Old legacy sessions no longer satisfy the current session guard.

Accounts deliberately created with a NULL password hash remain inactive. Explicit provisioning is available when the account owner needs first access:

~~~sh
php scripts/migrate.php --provision-inactive
~~~

This affects only inactive accounts; it does not reset established passwords. Both setup commands coordinate through a database advisory lock. MySQL DDL commits independently, so a migration failure must be diagnosed from private CLI logs and completed on a backed-up database; the whole schema migration is not represented as a single rollback-able transaction.

## Runtime permissions

The runtime-user script grants only the current database's application permissions:

| Objects | Privileges |
| --- | --- |
| `department`, `office`, `position` | SELECT |
| `employees`, `emergency_table`, `holiday_balance`, `holiday_requests`, `login` | SELECT, INSERT, UPDATE |
| `delete_employee_logs` | SELECT, DELETE for explicit expired-record cleanup |
| `login_attempts` | SELECT, INSERT, DELETE |
| `delete_employee_with_log` | EXECUTE |

It grants no schema, user-management, FILE or GRANT OPTION privileges. Employee removal runs through the transaction-owning, definer-security procedure. Keep the routine owner a managed schema account and do not drop it while its routines are in use.

## Data and retained coursework

`database/seeds/employees.csv` remains unchanged. The importer specifies CSV delimiter, enclosure and an explicit empty escape parameter, including on PHP 8.5. `schema_migrations` records schema/security and initial-seed versions. `database/schema.php`, `objects.php` and `setup_support.php` contain the current implementation.

The 14 historical `database/setup/*.php` filenames are retained as CLI compatibility entrypoints into the safe setup/migration implementation. They no longer reset passwords or repeat seed inserts. Use the single documented setup command rather than the old ordered sequence. `extra_tables.php` and `--with-extensions` retain the optional commerce/delivery schema, which the employee UI does not use.

Restrict filesystem access to `.env` and `var/` to the account running this application. POSIX permissions are applied to private exports; configure Windows ACLs explicitly for shared machines. Never upload private exports, databases or logs as portfolio assets. Initial code remains in Git history, which is not rewritten.
