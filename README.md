# Gaston Power Kenya Ltd — Generator Maintenance System

A PHP 8+ and MySQL application for tracking generators, customers, maintenance jobs, service history and staff activity.

## Current foundation
- Secure PHP sessions and password-hashed accounts
- Role-based access (admin, technician, viewer)
- Prepared SQL statements and server-side validation
- CSRF protection on state-changing forms
- Login throttling and audit logging
- Dashboard foundation and generator records schema

## Requirements
- PHP 8.1+ with PDO MySQL
- MySQL 8+ or MariaDB 10.4+
- Apache/Nginx (or UwAmp/XAMPP for local development)

## Local installation
1. Create a MySQL database in your hosting control panel (the provider may assign a prefixed database name).
2. Open that database in phpMyAdmin and import `database/schema.sql`.
3. Copy `config/database.example.php` to `config/database.php` and set your database host, name, username and password. Never commit real credentials.
4. Set a long random `APP_KEY` in `config/database.php`.
5. Point your web server document root to this project folder. For production, ideally point it to `public/`.
6. Open `setup.php` locally to create the first administrator, then delete `setup.php` immediately.
7. Sign in at `login.php`.

## Important security notes
- Use HTTPS in production.
- Keep PHP, MySQL and the server patched.
- Do not upload database credentials or real customer information to GitHub.
- Restrict database permissions to the application database only.
- Back up the database regularly and test restoration.
- Configure production secrets as environment variables or in a file outside the public web root.
- This starter is a foundation; perform security testing and review before using it with real company data.

## Default roles
- **Admin:** manages users and all records.
- **Technician:** views assigned work and updates maintenance records (module to be added).
- **Viewer:** read-only access (module to be added).

## Project status
Phase 1: application foundation, authentication and database schema. Customer records, generator register, maintenance work orders, technician assignment, spare-parts register and password change are now included. Staff administration, stock movement controls, reports and full production hardening remain planned.
