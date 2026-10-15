<?php
require_once __DIR__ . '/functions.php';

csrf_verify('register.php');
if (honeypot_triggered()) {
    redirect('register.php');
}

[$data, $errors] = validate($_POST, [
    'name'             => 'required|min:2|max:120',
    'email'            => 'required|email',
    'phone'            => 'required|phone',
    'password'         => 'required|password',
    'password_confirm' => 'required|same:password',
    'accept_terms'     => 'accepted',
], ['password_confirm' => 'Password confirmation', 'accept_terms' => 'Terms & Conditions']);

if ($errors) {
    back_with_errors($errors, 'register.php', $_POST);
}
if (form_rate_limited('register', 5)) {
    flash('error', 'Too many accounts were created from your network recently. Please try again later.');
    redirect('register.php');
}
if (db_value('SELECT id FROM users WHERE email = ?', [$data['email']])) {
    back_with_errors(['email' => 'An account with this email already exists. Please log in or reset your password.'], 'register.php', $_POST);
}

$userId = db_insert('users', [
    'name'          => $data['name'],
    'email'         => $data['email'],
    'phone'         => $data['phone'],
    'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
    'status'        => 'active',
]);
log_activity('form_register', 'New user #' . $userId, null, $userId);

$user = db_row('SELECT * FROM users WHERE id = ?', [$userId]);
login_user($user);

send_mail($data['email'], 'Welcome to ' . site_name(),
    '<p>Dear ' . e($data['name']) . ',</p><p>Your account has been created. You can now track your donations, download receipts, and follow your applications and certificates from your dashboard.</p>'
    . '<p><a class="btn" href="' . e(abs_url('member-dashboard.php')) . '">Go to my dashboard</a></p>');

flash('success', 'Welcome, ' . $data['name'] . '! Your account has been created.');
redirect('member-dashboard.php');
