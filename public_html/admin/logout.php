<?php
/** Staff sign-out (POST + CSRF from the sidebar). */
require_once __DIR__ . '/includes/functions.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && csrf_valid()) {
    logout_user();
    flash('success', 'You have been signed out.');
}
redirect('admin/login.php');
