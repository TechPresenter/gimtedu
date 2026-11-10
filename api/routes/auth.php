<?php
/**
 * Authentication & session endpoints.
 *   GET  /api/auth/session            current session (user, permissions, csrf token, boot settings)
 *   POST /api/auth/login              {identifier, password, remember}
 *                                     → session payload, or {two_factor: {step: verify|setup, methods, expires_in, account}, csrf_token}
 *                                     when the account needs its authenticator code / has to set two-factor up (nobody is
 *                                     signed in yet — finish with the /auth/2fa/* calls below)
 *   POST /api/auth/2fa/verify         {code, method: totp|recovery} → session payload (+ recovery_codes_left after a recovery code)
 *   POST /api/auth/2fa/setup          (pending "setup" sign-in) → {secret, otpauth_uri, issuer, account}
 *   POST /api/auth/2fa/setup/confirm  {code} → turns two-factor on and signs in: session payload + recovery_codes[10]
 *   POST /api/auth/2fa/cancel         drop the pending sign-in
 *   POST /api/auth/logout
 *   POST /api/auth/forgot             {email}
 *   GET  /api/auth/reset/{selector}?token=...   validate reset link
 *   POST /api/auth/reset              {selector, token, password, password_confirmation}
 *   POST /api/auth/password           {current_password, password, password_confirmation}
 *                                     (also completes a mandatory change: while users.must_change_password = 1 every
 *                                     other API answers 403 {code: password_change_required} - see enforce_password_change())
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
            // why the "Set a new password" screen is shown: expired (password expiry policy) | required (temporary / forced)
            'password_change_reason' => $user['must_change_password'] ? password_change_reason() : null,
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
    // private admin address (Settings › Security): the admin sign-in answers "not found" until it was opened
    require_once APP_ROOT . '/app/services/admin_gate.php';
    if (!admin_gate_open()) {
        api_error('Not found.', 404);
    }
    $in = api_input();
    $identifier = trim(api_str($in['identifier'] ?? $in['email'] ?? null));
    $password = api_str($in['password'] ?? null);
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
    if (!empty($result['two_factor'])) {
        api_ok(['two_factor' => $result['two_factor'], 'csrf_token' => csrf_token()], $result['message']);
    }
    api_ok(auth_session_payload(), $result['message']);
}, ['auth' => false]);

/* ------------------------------------------------------------------ two-factor sign-in step (app/services/two_factor.php) */

/** The pending sign-in or a 401 that sends the browser back to the password form. */
function auth_tf_pending(?string $step = null): array
{
    require_once APP_ROOT . '/app/services/two_factor.php';
    if (!rate_limit('tf-signin:' . client_ip(), 120, 900)) { // per IP: a whole campus may share one address; wrong codes are capped per sign-in (TF_MAX_WRONG)
        api_error('Too many attempts. Please wait 15 minutes and sign in again.', 429);
    }
    $p = tf_pending();
    if (!$p || ($step !== null && $p['pending']['step'] !== $step)) {
        api_error('Your sign-in expired. Enter your password again.', 401);
    }
    return $p;
}

/** Wrong code during sign-in: 422 on the field, or 429 (pending sign-in dropped) after too many. */
function auth_tf_wrong(array $user, string $what, string $message): void
{
    if (tf_pending_wrong($user, $what)) {
        api_error('Too many wrong codes. Sign in again with your password.', 429);
    }
    api_error($message, 422, ['code' => $message]);
}

route('POST', '/auth/2fa/verify', function () {
    ['pending' => $pending, 'user' => $user] = auth_tf_pending('verify');
    $method = api_str(api_param('method')) === 'recovery' ? 'recovery' : 'totp';
    $code = api_str(api_param('code'));
    if (!tf_user_enabled($user)) {
        tf_pending_clear();
        api_error('Your sign-in expired. Enter your password again.', 401);
    }
    $r = tf_check_code($user, $code, $method);
    if (!$r['ok']) {
        auth_tf_wrong($user, $method === 'recovery' ? 'recovery code' : 'two-factor code', $method === 'recovery'
            ? 'That recovery code is not valid or was already used.'
            : 'That code is not correct or was already used. Enter the newest code from your authenticator app.');
    }
    tf_finish_login($user, $pending, $method === 'recovery' ? 'recovery_code' : 'two_factor');
    $out = auth_session_payload();
    if ($method === 'recovery') {
        log_activity('two_factor_recovery_used', 'auth', (int) $user['id'], 'Signed in with a recovery code (' . $r['left'] . ' left)');
        notify((int) $user['id'], 'security', 'Recovery code used', 'A recovery code was used to sign in to your account from ' . client_ip() . '. ' . $r['left'] . ' code' . ($r['left'] === 1 ? '' : 's') . ' left. If this was not you, change your password now.', 'admin/profile?tab=security', 'shield-alert');
        $out['recovery_codes_left'] = $r['left'];
    }
    api_ok($out, 'Welcome back, ' . $user['name'] . '!');
}, ['auth' => false]);

route('POST', '/auth/2fa/setup', function () {
    ['user' => $user] = auth_tf_pending('setup');
    $secret = tf_new_secret();
    $_SESSION['tf_pending']['setup_secret'] = chatbot_encrypt($secret);
    api_ok(tf_setup_payload($secret, $user));
}, ['auth' => false]);

route('POST', '/auth/2fa/setup/confirm', function () {
    ['pending' => $pending, 'user' => $user] = auth_tf_pending('setup');
    $secret = chatbot_decrypt((string) ($pending['setup_secret'] ?? ''));
    if ($secret === '') {
        api_error('Start the setup again: scan the new QR code.', 422, ['code' => 'Start the setup again: scan the new QR code.']);
    }
    $step = tf_match_code($secret, api_str(api_param('code')));
    if ($step === null) {
        auth_tf_wrong($user, 'two-factor setup code', 'That code is not correct. Enter the code your authenticator app shows now.');
    }
    $codes = tf_enable((int) $user['id'], $secret, $step);
    log_activity('two_factor_enabled', 'auth', (int) $user['id'], 'Turned on two-factor authentication at sign-in (required by the institute)');
    tf_finish_login(db_row('SELECT * FROM users WHERE id = ?', [(int) $user['id']]), $pending, 'two_factor');
    api_ok(auth_session_payload() + ['recovery_codes' => $codes], 'Two-factor authentication is on. Save your recovery codes.');
}, ['auth' => false]);

route('POST', '/auth/2fa/cancel', function () {
    require_once APP_ROOT . '/app/services/two_factor.php';
    tf_pending_clear();
    api_ok(['csrf_token' => csrf_token()], 'Sign-in cancelled.');
}, ['auth' => false]);

route('POST', '/auth/logout', function () {
    logout_user();
    session_start_secure();
    api_ok(['csrf_token' => csrf_token()], 'You have been signed out.');
}, ['auth' => false]);

route('POST', '/auth/forgot', function () {
    $email = strtolower(trim(api_str(api_param('email'))));
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
    $valid = (bool) auth_reset_token($p['selector'], api_str($_GET['token'] ?? null));
    api_ok(['valid' => $valid]);
}, ['auth' => false]);

route('POST', '/auth/reset', function () {
    $in = api_input();
    $row = auth_reset_token(api_str($in['selector'] ?? null), api_str($in['token'] ?? null));
    if (!$row) {
        api_error('This password reset link is invalid or has expired. Please request a new one.', 410);
    }
    $password = api_str($in['password'] ?? null);
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    if ($password !== api_str($in['password_confirmation'] ?? null)) {
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
    // a hijacked session must not be able to guess the current password (and take the account over) by trial and error
    $rlKey = 'auth-password:' . (int) $user['id'];
    $rl = db_row('SELECT hits, reset_at FROM rate_limits WHERE rl_key = ?', [$rlKey]);
    if ($rl && strtotime($rl['reset_at']) > time() && (int) $rl['hits'] >= 10) {
        api_error('Too many incorrect attempts. Please try again in 15 minutes.', 429);
    }
    if (!password_verify(api_str($in['current_password'] ?? null), $user['password_hash'])) {
        rate_limit($rlKey, 10, 900);
        api_error('Your current password is incorrect.', 422, ['current_password' => 'Your current password is incorrect.']);
    }
    $password = api_str($in['password'] ?? null);
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    if ($password !== api_str($in['password_confirmation'] ?? null)) {
        api_error('Passwords do not match.', 422, ['password_confirmation' => 'Passwords do not match.']);
    }
    if (password_verify($password, $user['password_hash'])) {
        api_error('Choose a password different from your current one.', 422, ['password' => 'Choose a password different from your current one.']);
    }
    $wasRequired = (bool) $user['must_change_password'];
    db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => date('Y-m-d H:i:s'), 'must_change_password' => 0], 'id = ?', [$user['id']]);
    db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$user['id']]);
    session_refresh_password_fp(); // this browser stays signed in; every other session of the account is signed out
    $reason = password_change_reason();
    unset($_SESSION['password_change_reason']);
    log_activity('update', 'auth', $user['id'], $wasRequired ? 'Set a new password at sign-in (' . ($reason === 'expired' ? 'expired password' : 'password change required') . ')' : 'Changed account password');
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
