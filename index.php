<?php
/**
 * RRJ Clothing — Staff-Only Management Portal
 * This application is a staff-only management portal. Customer/public access is not enabled in this build.
 * Redirect all root requests to the staff portal.
 */
require_once __DIR__ . '/config/config.php';

// If already logged in as staff, go straight to dashboard
$u = current_user();
if ($u && in_array($u['role'], ['admin', 'staff'], true)) {
    redirect('staff/dashboard.php');
}

// Everyone else → staff login
redirect('staff/login.php');
