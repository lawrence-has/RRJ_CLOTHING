# RRJ Clothing Staff Management System — MySQL Rebuild

## What changed
- This package is configured as a staff-only management portal. Public visitors and customer accounts are redirected to the staff login; management pages require an active staff or administrator account.
- The supplied RRJ logo is installed in the application branding.
- Added `install.php`, a connection test and database preparation wizard.
- The installer saves the selected host, port, username, password, and database in `config/database.local.php`.
- Updated `config/config.php` to use installer settings while still allowing environment variables to override them.
- The application retains its existing schema bootstrap and creates missing tables without dropping existing tables.
- Existing customer, staff, product, inventory, order, payment-status, report, and activity-log code has been retained.
- Added Apache protection for the local credential file.

## Fresh Laragon installation
1. Back up your current project folder and database files.
2. Extract this ZIP into `C:\laragon\www\rrj_clothing_system`.
3. Open Laragon and start MySQL. Confirm that it is actually running.
4. Open `http://localhost/rrj_clothing_system/install.php`.
5. Enter the host, port, username, and password that successfully connect to your MySQL server. Typical Laragon defaults are host `127.0.0.1`, port `3306`, user `root`, blank password—but use your actual settings.
6. Select database name `rrj_clothing`, then click **Test Connection & Prepare Database**.
7. Open `http://localhost/rrj_clothing_system/`. The application routes to the staff login and creates missing tables and seeds demo data only when the relevant tables are empty.

## Existing data
The installer uses `CREATE DATABASE IF NOT EXISTS` and the application uses `CREATE TABLE IF NOT EXISTS`. It does not drop tables or reset existing records. For a genuinely fresh database, use a new database name only if you intentionally want an empty installation. Do not delete your existing Laragon data directory.

## Troubleshooting
- Error 2003 / 10061: MySQL is not running on the host/port. Start MySQL in Laragon and verify the TCP listening port with `netstat -ano | findstr :3306` (substitute the port).
- Error 1045: MySQL rejected the username/password. Test those credentials in HeidiSQL or the MySQL client first.
- `could not find driver`: enable `pdo_mysql` in the PHP version used by Laragon and restart Apache.
- Error 1044: the MySQL account lacks permission to create the database or access it. Use a MySQL administrator account for initial setup.

## Demo accounts
Only seeded when the users table is empty:
- Admin: `admin@rrjclothing.com` / `admin123`
- Customer: `juan@email.com` / `123456`
- Customer: `maria@email.com` / `123456`

Change demo passwords before using real customer data. This build has not been end-to-end tested against your local Laragon server; your MySQL service must be running and credentials valid for installation.
