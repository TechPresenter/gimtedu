<?php
/**
 * Authentication & session endpoints.
 *   GET  /api/auth/session            current session (user, permissions, csrf token, boot settings)
 *   POST /api/auth/login              {identifier, password, remember}
 *   POST /api/auth/logout
 *   POST /api/auth/forgot             {email}
 *   GET  /api/auth/reset/{selector}?token=...   validate reset link
 *   POST /api/auth/reset              {selector, token, password, password_confirmation}
 *   POST /api/auth/password           {current_password, password, password_confirmation}
 *   POST /api/auth/prefs              {theme?, academic_session_id?}
 *   POST /api/auth/ping               keep the session alive
 */

/** Boot payload consumed by the SPA. */
function auth_session_payload(): array
{
    $user = current_user();
    $base = [
        'authenticated' => (bool) $user,
        'csrf_token' => csrf_token(),
        'app' => [
            'name' => app_config('app')['name'],
            'version' => APP_VERSION,
            'institute_name' => institute_name(),
            'short_name' => setting('institute_short_name', 'GIMT'),
            'tagline' => setting('tagline', ''),
            'logo' => logo_url(),
            'logo_white' => logo_url(true),
            'logo_icon' => setting('favicon') ? upload_url(setting('favicon')) : asset('assets/images/logo-icon.svg'),
            'currency_symbol' => setting('currency_symbol', '₹'),
            'date_format' => setting('date_format', 'd M Y'),
            'base_path' => base_path(),
            'site_url' => base_url(''),
            'session_timeout' => session_timeout_seconds(),
            'password_policy' => [
                'min_length' => (int) setting('password_min_length', 8),
                'uppercase' => setting('password_require_uppercase', '1') === '1',
                'number' => setting('password_require_number', '1') === '1',
                'special' => setting('password_require_special', '1') === '1',
            ],
        ],
    ];
    if (!$user) {
        return $base;
    }
    $perms = [];
    $super = is_super_admin();
    foreach (permission_modules() as $module => $def) {
        $actions = [];
        foreach ($def['actions'] as $a) {
            if (can($module, $a)) {
                $actions[] = $a;
            }
        }
        if ($actions) {
            $perms[$module] = $actions;
        }
    }
    $sessions = db_all('SELECT id, name, is_current, status FROM academic_sessions ORDER BY start_date DESC');
    return $base + [
        'user' => [
            'id' => (int) $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'email' => $user['email'], 'phone' => $user['phone'],
            'avatar' => $user['avatar'] ? upload_url($user['avatar']) : null, 'designation' => $user['designation'], 'roles' => $user['role_names'],
            'theme' => $user['theme'], 'faculty_id' => $user['faculty_id'] ? (int) $user['faculty_id'] : null,
            'last_login_at' => $_SESSION['previous_login']['at'] ?? null, 'last_login_ip' => $_SESSION['previous_login']['ip'] ?? null,
            'must_change_password' => (bool) $user['must_change_password'],
        ],
        'is_super' => $super,
        'permissions' => (object) $perms,
        'sessions' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'is_current' => (bool) $s['is_current'], 'status' => $s['status']], $sessions),
        'current_session_id' => current_session_id(),
    ];
}

route('GET', '/auth/session', function () {
    if (!session_is_valid()) {
        login_from_remember_cookie();
    }
    api_ok(auth_session_payload());
}, ['auth' => false]);

route('POST', '/auth/login', function () {
    $in = api_input();
    $identifier = trim((string) ($in['identifier'] ?? $in['email'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $errors = [];
    if ($identifier === '') {
        $errors['identifier'] = 'Enter your email or username.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }
    if ($errors) {
        api_error('Please enter your credentials.', 422, $errors);
    }
    $result = attempt_login($identifier, $password, !empty($in['remember']));
    if (!$result['ok']) {
        api_error($result['message'], 401);
    }
    api_ok(auth_session_payload(), $result['message']);
}, ['auth' => false]);

route('POST', '/auth/logout', function () {
    logout_user();
    session_start_secure();
    api_ok(['csrf_token' => csrf_token()], 'You have been signed out.');
}, ['auth' => false]);

route('POST', '/auth/forgot', function () {
    $email = strtolower(trim((string) api_param('email', '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Enter a valid email address.', 422, ['email' => 'Enter a valid email address.']);
    }
    if (!rate_limit('forgot:' . client_ip(), 5, 900)) {
        api_error('Too many reset requests. Please try again in 15 minutes.', 429);
    }
    $user = db_row("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
    if ($user) {
        db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'reset'", [$user['id']]);
        $selector = bin2hex(random_bytes(9));
        $token = bin2hex(random_bytes(32));
        db_insert('user_tokens', ['user_id' => $user['id'], 'type' => 'reset', 'selector' => $selector, 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'ip_address' => client_ip(), 'user_agent' => user_agent()]);
        $link = absolute_url('admin/reset-password?selector=' . $selector . '&token=' . $token);
        send_template_mail('password_reset', $user['email'], ['name' => $user['name'], 'reset_link' => $link], ['related_type' => 'user', 'related_id' => $user['id']]);
        log_activity('password_reset_requested', 'auth', $user['id'], 'Password reset requested for ' . $user['email'], 'success');
    }
    api_ok(null, 'If an account exists for that email, a password reset link has been sent. The link is valid for 60 minutes.');
}, ['auth' => false]);

function auth_reset_token(string $selector, string $token): ?array
{
    $row = db_row("SELECT * FROM user_tokens WHERE selector = ? AND type = 'reset' AND used_at IS NULL AND expires_at > NOW()", [$selector]);
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $token))) {
        return null;
    }
    return $row;
}

route('GET', '/auth/reset/{selector}', function ($p) {
    $valid = (bool) auth_reset_token($p['selector'], (string) ($_GET['token'] ?? ''));
    api_ok(['valid' => $valid]);
}, ['auth' => false]);

route('POST', '/auth/reset', function () {
    $in = api_input();
    $row = auth_reset_token((string) ($in['selector'] ?? ''), (string) ($in['token'] ?? ''));
    if (!$row) {
        api_error('This password reset link is invalid or has expired. Please request a new one.', 410);
    }
    $password = (string) ($in['password'] ?? '');
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    if ($password !== (string) ($in['password_confirmation'] ?? '')) {
        api_error('Passwords do not match.', 422, ['password_confirmation' => 'Passwords do not match.']);
    }
    db_transaction(function () use ($row, $password) {
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => date('Y-m-d H:i:s'), 'failed_attempts' => 0, 'locked_until' => null, 'must_change_password' => 0], 'id = ?', [$row['user_id']]);
        db_update('user_tokens', ['used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
        db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$row['user_id']]);
    });
    log_activity('password_reset', 'auth', $row['user_id'], 'Password reset via email link');
    api_ok(null, 'Your password has been reset. You can now sign in.');
}, ['auth' => false]);

route('POST', '/auth/password', function () {
    $in = api_input();
    $user = db_row('SELECT * FROM users WHERE id = ?', [user_id()]);
    if (!password_verify((string) ($in['current_password'] ?? ''), $user['password_hash'])) {
        api_error('Your current password is incorrect.', 422, ['current_password' => 'Your current password is incorrect.']);
    }
    $password = (string) ($in['password'] ?? '');
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    if ($password !== (string) ($in['password_confirmation'] ?? '')) {
        api_error('Passwords do not match.', 422, ['password_confirmation' => 'Passwords do not match.']);
    }
    if (password_verify($password, $user['password_hash'])) {
        api_error('Choose a password different from your current one.', 422, ['password' => 'Choose a password different from your current one.']);
    }
    db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => date('Y-m-d H:i:s'), 'must_change_password' => 0], 'id = ?', [$user['id']]);
    db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$user['id']]);
    log_activity('update', 'auth', $user['id'], 'Changed account password');
    api_ok(null, 'Password updated successfully.');
});

route('POST', '/auth/prefs', function () {
    $in = api_input();
    if (isset($in['theme']) && in_array($in['theme'], ['light', 'dark', 'system'], true)) {
        db_update('users', ['theme' => $in['theme']], 'id = ?', [user_id()]);
    }
    if (isset($in['academic_session_id'])) {
        $sid = (int) $in['academic_session_id'];
        if (!db_value('SELECT id FROM academic_sessions WHERE id = ?', [$sid])) {
            api_error('Unknown academic session.', 422);
        }
        $_SESSION['academic_session_id'] = $sid;
    }
    api_ok(['current_session_id' => $_SESSION['academic_session_id'] ?? current_session_id()], 'Preferences saved.');
});

route('POST', '/auth/ping', function () {
    $_SESSION['last_activity'] = time();
    api_ok(['expires_in' => session_timeout_seconds()]);
});
