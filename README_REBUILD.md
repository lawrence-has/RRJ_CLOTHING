# RRJ Clothing Staff Management System — Rebuild v1.1

A separate rebuild workspace based on the supplied RRJ source ZIP. The original project remains unchanged as the reference baseline. This release is configured as a **staff-only management portal**: public visitors and customer accounts cannot open the management dashboard or staff modules. Staff and administrators sign in through the Staff Portal; administrator-only pages remain restricted to the admin role.

## Technology
- PHP 8.1+ (8.2+ recommended)
- MySQL 8 / MariaDB with InnoDB
- HTML5, CSS3, JavaScript ES6+
- Existing RRJ logo and customer/staff modules

## Included in this release
- Legacy customer shopping, wishlist, cart, checkout, order, and account code retained in the source, but the application entry point routes visitors to the staff portal; it is not exposed as a public customer storefront in this staff-only build.
- Existing staff dashboard, product, inventory, sales/orders, customers, reports, user, settings, and activity modules retained.
- Applied the supplied official RRJ logo to the staff login and management interface.
- Refined the responsive staff dashboard, navigation, staff identity display, and operational overview.
- Added a live active-product count to the dashboard.
- Rechecked staff account status and role against the database on every protected staff request, preventing inactive or demoted accounts from continuing with an existing session.
- Refreshed the RRJ black, white, and red staff interface palette.
- Stronger PHP session configuration and baseline response security headers.
- PDO exception mode, associative fetches, and native prepared statements.
- Database name/port validation and conventional Laragon credential fallback.
- Currency formatting uses the configured currency symbol.
- Product uploads validate real image signatures, enforce a 5 MB limit, use random filenames, and set safer permissions.
- Staff password-change handler repaired.
- User management and store settings restricted to administrators.
- Staff logout and user activation/deactivation use POST + CSRF.
- Uploaded product media folder blocks direct script execution.

## Known remaining work
This is a first hardening release, not a production-ready certification. Customer-side state-changing routes (including add/update/remove/clear cart, wishlist toggle, and customer logout) still include legacy GET requests and must be migrated to POST + CSRF. End-to-end testing with the target Laragon/MySQL installation is also required before use with real customer data.

## Install with Laragon
1. Keep the original project and database unchanged as a backup.
2. Extract this folder to `C:\laragon\www\RRJ_Clothing_Rebuilt`.
3. Start Apache and MySQL.
4. Open `http://localhost/RRJ_Clothing_Rebuilt/install.php`.
5. Enter the MySQL host, port, username, password, and database name verified in HeidiSQL.
   - Typical Laragon defaults: host `127.0.0.1`, port `3306`, user `root`, blank password. Use your actual settings.
6. Test the connection and prepare the database.
7. Open `http://localhost/RRJ_Clothing_Rebuilt/`.

## Database safety
The installer and schema bootstrap do not drop or truncate existing tables. For a genuinely clean install, choose a new database name such as `rrj_clothing_rebuild`. Do not delete your Laragon MySQL data directory.

## Access model
- **Staff:** dashboard, products, inventory, sales/orders, customer records, reports, and activity log.
- **Administrator:** all staff functions plus user management and store settings.
- **Customer / public visitor:** no access to staff management pages. Direct requests are redirected to the staff login.

## Initial demo administrator
- Email: `admin@rrjclothing.com`
- Password: `admin123`

Change this password immediately after first login. Do not use demo credentials with real customer data.
