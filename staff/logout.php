<?php
require_once __DIR__ . '/../config/config.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}
csrf_verify();
logout_user();
flash('success', 'You have been logged out.');
redirect('login.php');
