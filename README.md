# Gaston Power Kenya Ltd — Generator Maintenance System

A PHP 8+ and MySQL application for tracking generators, customers, maintenance jobs, service history and staff activity.

## Features currently included
- Secure login, password hashing and authenticated password change
- Admin, technician and viewer roles
- Generator asset register and customer records
- Maintenance work orders, technician assignment and service notes
- Spare-parts register with low-stock indicators
- Dashboard statistics and audit logging
- Prepared SQL statements, CSRF protection, output escaping and login throttling

## Hosting requirements
- PHP 8.1+ with PDO MySQL
- MySQL 8+ or MariaDB 10.4+
- Apache-compatible hosting with .htaccess support

## Deploying to shared PHP/MySQL hosting
1. Create a MySQL database using the hosting control panel. Copy its exact database name, hostname, username and password.
2. Open phpMyAdmin for that database and import `database/schema.sql`. The SQL file imports into the database you selected; it does not try to create a database.
3. Copy `config/database.example.php` to `config/database.php` and set the exact database credentials supplied by your host.
4. Set a unique random `APP_KEY` of at least 32 characters. Do not commit real credentials to GitHub.
5. Upload the project files and folders into the website document root (often `htdocs` or `public_html`). Preserve folder structure.
6. Visit your domain to reach the sign-in page, then open `setup.php` once to create the first administrator.
7. Immediately delete `setup.php` from the hosting file manager after the administrator has been created.
8. Sign in. Create separate accounts for staff and grant only the access they need.

## InfinityFree note
InfinityFree database names, usernames and hosts are generated in the control panel. Do not assume the database is named `gaston_power` or that the host is `localhost`. Use the exact values shown in the hosting panel. Import the SQL into the database you created.

## Security before real business use
- Enable HTTPS and force secure cookies.
- Do not upload real customer information until the site has been reviewed and tested.
- Use unique administrator passwords and grant each staff member only the access they need.
- Keep a backup outside the web hosting account and test restoring it.
- Never commit `config/database.php`, passwords, database exports or customer records.
- This is a development foundation, not a certified production system. Complete staff management, operational testing and an independent security review before using it for company-critical records.

## Project status
Customer records, generator register, maintenance work orders, technician assignment, spare-parts register and password change are included. Staff account management, stock movement controls, reports and further production hardening remain planned.
