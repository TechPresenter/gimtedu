<?php
require_once __DIR__ . '/includes/functions.php';

$token = (string) ($_GET['token'] ?? '');
if (current_admin() && $token !== '' && hash_equals(csrf_token(), $token)) {
    admin_log('admin_logout', '');
    admin_logout();
    flash('success', 'You have been logged out of the admin panel.');
}
admin_redirect('login.php');
