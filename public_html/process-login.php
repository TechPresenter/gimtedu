<?php
require_once __DIR__ . '/functions.php';

csrf_verify('login.php');
$next = safe_next($_POST['next'] ?? '', 'member-dashboard.php');
$back = 'login.php?next=' . urlencode($next);

[$data, $errors] = validate($_POST, ['email' => 'required|email', 'password' => 'required|max:72']);
if ($errors) {
    back_with_errors($errors, $back, $_POST);
}

if (too_many_attempts('user_login_failed', $data['email'])) {
    flash('error', 'Too many failed login attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes or reset your password.');
    redirect($back);
}

$user = db_row('SELECT * FROM users WHERE email = ?', [$data['email']]);
// Always run password_verify so that response time does not reveal whether the email exists.
$hash = $user['password_hash'] ?? '$2y$10$4bdiROiNjSam8WeuyJx5quqftYki1rBymBiquzDFbCR4fMnHd9tKK';
$valid = password_verify($_POST['password'] ?? '', $hash) && $user;

if (!$valid) {
    log_activity('user_login_failed', $data['email']);
    back_with_errors(['password' => 'The email or password is incorrect.'], $back, ['email' => $data['email']]);
}
if ($user['status'] !== 'active') {
    flash('error', 'Your account has been blocked. Please contact us for help.');
    redirect($back);
}
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    db_update('users', ['password_hash' => password_hash($_POST['password'], PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
}

login_user($user);
flash('success', 'Welcome back, ' . $user['name'] . '!');
redirect($next);
