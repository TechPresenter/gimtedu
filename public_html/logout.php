<?php
require_once __DIR__ . '/functions.php';

$token = (string) ($_GET['token'] ?? $_POST['csrf_token'] ?? '');
if (is_logged_in() && $token !== '' && hash_equals(csrf_token(), $token)) {
    log_activity('user_logout', '', null, current_user_id());
    logout_user();
    flash('success', 'You have been logged out.');
}
redirect('index.php');
