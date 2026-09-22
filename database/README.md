<p align="right">
  <strong>English</strong> · <a href="README.ko.md">한국어</a>
</p>

# Database Setup

[← Back to the project overview](../README.md)

The setup scripts transform the fictional CSV source into the normalized application schema. Run them once, in order, against an empty local database.

## Configuration

Connection values come from `config/database.php` and can be overridden with `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` environment variables. The defaults use MySQL on port `3307` and database name `company_db`.

## Initialization sequence

Run each command from the repository root in the listed order:

```bash
php database/setup/set_database.php
php database/setup/material_table.php
php database/setup/get_employees_data.php
php database/setup/department_table.php
php database/setup/position_table.php
php database/setup/office_table.php
php database/setup/employee_table.php
php database/setup/emergency_table.php
php database/setup/holiday_tables.php
php database/setup/set_holiday.php
php database/setup/delete_employee_logs.php
php database/setup/login_table.php
php database/setup/set_triggers_and_procedure.php
```

The optional extension schema can be installed separately:

```bash
php database/setup/extra_tables.php
```

The final required step needs MySQL permissions for `TRIGGER` and `CREATE ROUTINE`.

## Seed behavior

- Source: `seeds/employees.csv`
- All people and contact details are fictional.
- Seeded login rows receive the initial password `0000` as a bcrypt hash.
- Seeded leave balances start with 28 annual, 10 sick, and 5 personal days.

## Optional extension schema

`extra_tables.php` creates learning-oriented commerce, payment, inventory, customer, and delivery tables. The current people-operations UI does not read or write these tables.

## Resetting locally

The setup sequence is intended for a fresh database. To reset it, remove the local `company_db` database using your normal MySQL administration tool, then repeat the sequence. The setup scripts intentionally remain outside the `public/` document root and should be executed only from the command line.
