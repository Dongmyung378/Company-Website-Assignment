# Database Setup

[← Back to the project overview](../README.md)

The setup scripts transform the fictional CSV source into the normalized application schema. Run them once, in order, against an empty local database.

## Configuration

Connection values come from `config/database.php` and can be overridden with `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME` environment variables. The defaults use MySQL on port `3307` and database name `company_db`.

Start the application from the repository root:

```bash
php -S 127.0.0.1:8000
```

## Initialization sequence

Open each route in the listed order:

1. [`/database/setup/set_database.php`](http://127.0.0.1:8000/database/setup/set_database.php)
2. [`/database/setup/material_table.php`](http://127.0.0.1:8000/database/setup/material_table.php)
3. [`/database/setup/get_employees_data.php`](http://127.0.0.1:8000/database/setup/get_employees_data.php)
4. [`/database/setup/department_table.php`](http://127.0.0.1:8000/database/setup/department_table.php)
5. [`/database/setup/position_table.php`](http://127.0.0.1:8000/database/setup/position_table.php)
6. [`/database/setup/office_table.php`](http://127.0.0.1:8000/database/setup/office_table.php)
7. [`/database/setup/employee_table.php`](http://127.0.0.1:8000/database/setup/employee_table.php)
8. [`/database/setup/emergency_table.php`](http://127.0.0.1:8000/database/setup/emergency_table.php)
9. [`/database/setup/holiday_tables.php`](http://127.0.0.1:8000/database/setup/holiday_tables.php)
10. [`/database/setup/set_holiday.php`](http://127.0.0.1:8000/database/setup/set_holiday.php)
11. [`/database/setup/delete_employee_logs.php`](http://127.0.0.1:8000/database/setup/delete_employee_logs.php)
12. [`/database/setup/login_table.php`](http://127.0.0.1:8000/database/setup/login_table.php)
13. [`/database/setup/set_triggers_and_procedure.php`](http://127.0.0.1:8000/database/setup/set_triggers_and_procedure.php)
14. Optional: [`/database/setup/extra_tables.php`](http://127.0.0.1:8000/database/setup/extra_tables.php)

The final required step needs MySQL permissions for `TRIGGER` and `CREATE ROUTINE`.

## Seed behavior

- Source: `seeds/employees.csv`
- All people and contact details are fictional.
- Seeded login rows receive the initial password `0000` as a bcrypt hash.
- Seeded leave balances start with 28 annual, 10 sick, and 5 personal days.

## Optional extension schema

`extra_tables.php` creates learning-oriented commerce, payment, inventory, customer, and delivery tables. The current people-operations UI does not read or write these tables.

## Resetting locally

The setup sequence is intended for a fresh database. To reset it, remove the local `company_db` database using your normal MySQL administration tool, then repeat the sequence. Do not expose the `database/setup/` routes in a deployed environment.
