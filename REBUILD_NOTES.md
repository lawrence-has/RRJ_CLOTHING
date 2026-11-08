# Rebuild notes

Source baseline: `rrj_clothing_system(4).zip` supplied in this conversation.

Preserved modules: customer storefront, account pages, shop/product detail, cart, checkout, wishlist, orders/tracking, staff dashboard, products, inventory, sales/orders, customers, reports, users, settings, and activity log.

Changes in v1.0:
- Monochrome RRJ visual refresh.
- Stronger PHP session settings and baseline response security headers.
- PDO native prepared statements and explicit fetch/error defaults.
- Conventional Laragon DB credential fallback and database name/port validation.
- Configurable currency formatting.
- Stronger product image upload validation.
- Fixed staff password-change handler.
- Admin-only user management and store settings.
- POST + CSRF for staff logout and user activation/deactivation.
- Upload directory script-execution protection.

Remaining:
- Convert all customer-side GET state changes to POST + CSRF.
- Add login throttling and password recovery.
- Confirm role-permission matrix and business rules.
- Complete end-to-end functional, security, and performance tests.


## v1.1 staff-only enhancement
- Replaced the bundled brand asset with the exact RRJ logo supplied for this revision.
- Refined the staff portal branding, dashboard welcome panel, staff identity chip, navigation, responsive layout, and live active-product metric.
- Staff authorization now checks the current database account status and role on each protected request; inactive or demoted accounts are logged out.
- Customer storefront code remains in the source archive, but the application entry point continues to route all visitors to staff login.
