<?php
/**
 * "My profile" for the signed-in admin user.
 *   GET    /api/profile                        account, roles, security summary, recent logins, recent activity, remembered devices
 *   POST   /api/profile                        multipart/JSON {name, phone, designation, email, current_password (required when the email changes), avatar (file), remove_avatar}
 *   GET    /api/profile/logins?page=&status=   login history (paginated)
 *   GET    /api/profile/activity?page=&include_logins=1   my audit-trail entries (paginated; sign-ins excluded unless asked)
 *   DELETE /api/profile/sessions/{id}          revoke one remembered device
 *   DELETE /api/profile/sessions               revoke every remembered device except this browser
 * Password change: POST /api/auth/password · Theme: POST /api/auth/prefs.
 */
require_once APP_ROOT . '/app/services/system.php';
require_once APP_ROOT . '/app/upload.php';

/** Selector of the remember-me cookie of this browser (to flag / keep the current device). */
function profile_current_selector(): ?string
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    return $cookie && str_contains($cookie, ':') ? explode(':', $cookie, 2)[0] : null;
}

function profile_user(): array
{
    $u = db_row("SELECT u.*, d.name AS department_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?", [user_id()]);
    if (!$u) {
        api_error('Your account could not be found.', 404);
    }
    return $u;
}

function profile_public(array $u): array
{
    $roles = db_all('SELECT r.id, r.name, r.slug, r.color, r.is_super FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.is_super DESC, r.name', [$u['id']]);
    return [
        'id' => (int) $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'email' => $u['email'], 'phone' => $u['phone'], 'designation' => $u['designation'],
        'avatar' => $u['avatar'] ? upload_url($u['avatar']) : null, 'department' => $u['department_name'] ?? null, 'theme' => $u['theme'], 'status' => $u['status'],
        'roles' => array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'color' => $r['color'], 'is_super' => (bool) $r['is_super']], $roles),
        'created_at' => $u['created_at'], 'last_login_at' => $u['last_login_at'], 'last_login_ip' => $u['last_login_ip'],
        'password_changed_at' => $u['password_changed_at'], 'two_factor_enabled' => (bool) $u['two_factor_enabled'], 'must_change_password' => (bool) $u['must_change_password'],
    ];
}

function profile_sessions(int $userId): array
{
    $current = profile_current_selector();
    $rows = db_all("SELECT id, selector, ip_address, user_agent, created_at, expires_at FROM user_tokens WHERE user_id = ? AND type = 'remember' AND expires_at > NOW() ORDER BY created_at DESC", [$userId]);
    return array_map(fn ($t) => ['id' => (int) $t['id'], 'ip_address' => $t['ip_address'], 'browser' => browser_label($t['user_agent']), 'created_at' => $t['created_at'],
        'expires_at' => $t['expires_at'], 'current' => $current !== null && hash_equals((string) $t['selector'], $current)], $rows);
}

function profile_login_row(array $l): array
{
    return ['id' => (int) $l['id'], 'status' => $l['status'], 'reason' => $l['reason'], 'ip_address' => $l['ip_address'], 'browser' => browser_label($l['user_agent']), 'created_at' => $l['created_at']];
}

function profile_activity_row(array $a, array $labels): array
{
    return ['id' => (int) $a['id'], 'action' => $a['action'], 'module' => $a['module'], 'module_label' => $labels[$a['module']] ?? label_from_key((string) $a['module']),
        'description' => $a['description'], 'status' => $a['status'], 'ip_address' => $a['ip_address'], 'created_at' => $a['created_at']];
}

route('GET', '/profile', function () {
    $u = profile_user();
    $uid = (int) $u['id'];
    $labels = activity_module_labels();
    $stats = db_row("SELECT COALESCE(SUM(status = 'success'), 0) AS success, COALESCE(SUM(status IN ('failed', 'locked', 'blocked')), 0) AS failed,
                            COUNT(DISTINCT CASE WHEN status = 'success' THEN ip_address END) AS ips
                     FROM login_logs WHERE user_id = ? AND created_at >= NOW() - INTERVAL 30 DAY", [$uid]);
    $lastFailed = db_row("SELECT ip_address, user_agent, created_at FROM login_logs WHERE user_id = ? AND status IN ('failed', 'locked') ORDER BY created_at DESC, id DESC LIMIT 1", [$uid]);
    $logins = db_all('SELECT id, status, reason, ip_address, user_agent, created_at FROM login_logs WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 8', [$uid]);
    $activity = db_all("SELECT id, action, module, description, status, ip_address, created_at FROM activity_logs WHERE user_id = ? AND action NOT IN ('login', 'logout') ORDER BY created_at DESC, id DESC LIMIT 8", [$uid]);
    $counts = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(created_at >= NOW() - INTERVAL 30 DAY), 0) AS month FROM activity_logs WHERE user_id = ? AND action NOT IN ('login', 'logout')", [$uid]);
    $pwAge = $u['password_changed_at'] ? (int) floor((time() - strtotime($u['password_changed_at'])) / 86400) : null;
    $sessions = profile_sessions($uid);

    // Simple security score shown as a checklist on the Security tab.
    $checks = [
        ['key' => 'password_age', 'label' => 'Password changed in the last 90 days', 'ok' => $pwAge !== null && $pwAge <= 90,
            'detail' => $pwAge === null ? 'Never changed since the account was created' : ($pwAge === 0 ? 'Changed today' : "Changed $pwAge day" . ($pwAge === 1 ? '' : 's') . ' ago')],
        ['key' => 'failed', 'label' => 'No failed sign-in attempts in 30 days', 'ok' => (int) $stats['failed'] === 0,
            'detail' => (int) $stats['failed'] ? $stats['failed'] . ' failed attempt' . ((int) $stats['failed'] === 1 ? '' : 's') . ($lastFailed ? ', last from ' . $lastFailed['ip_address'] : '') : 'All recent sign-ins succeeded'],
        ['key' => 'devices', 'label' => 'Few remembered devices', 'ok' => count($sessions) <= 3, 'detail' => count($sessions) . ' device' . (count($sessions) === 1 ? '' : 's') . ' stay signed in with "Remember me"'],
        ['key' => 'phone', 'label' => 'Recovery phone number on file', 'ok' => !empty($u['phone']), 'detail' => $u['phone'] ? 'Used by administrators to verify you' : 'Add a phone number on the Account tab'],
        ['key' => 'two_factor', 'label' => 'Two-factor authentication', 'ok' => (bool) $u['two_factor_enabled'], 'info' => !$u['two_factor_enabled'], 'detail' => $u['two_factor_enabled'] ? 'Enabled' : 'Available once your institute turns it on in Settings › Security'],
    ];
    $scored = array_filter($checks, fn ($c) => empty($c['info']));
    $score = (int) round(count(array_filter($scored, fn ($c) => $c['ok'])) * 100 / max(1, count($scored)));

    api_ok([
        'user' => profile_public($u),
        'security' => [
            'score' => $score, 'checks' => $checks, 'password_age_days' => $pwAge, 'logins_30d' => (int) $stats['success'], 'failed_30d' => (int) $stats['failed'],
            'distinct_ips_30d' => (int) $stats['ips'], 'last_failed' => $lastFailed ? ['ip_address' => $lastFailed['ip_address'], 'browser' => browser_label($lastFailed['user_agent']), 'created_at' => $lastFailed['created_at']] : null,
            'session_timeout_minutes' => (int) (session_timeout_seconds() / 60), 'current_ip' => client_ip(), 'current_browser' => browser_label(user_agent()),
            'signed_in_at' => isset($_SESSION['login_at']) ? date('Y-m-d H:i:s', (int) $_SESSION['login_at']) : null,
            'previous_login' => $_SESSION['previous_login'] ?? null,
        ],
        'stats' => ['actions_total' => (int) $counts['total'], 'actions_30d' => (int) $counts['month'], 'unread_notifications' => (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$uid]),
            'unread_messages' => (int) db_value('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0 AND deleted_by_recipient = 0', [$uid])],
        'logins' => array_map('profile_login_row', $logins),
        'activity' => array_map(fn ($a) => profile_activity_row($a, $labels), $activity),
        'sessions' => $sessions,
    ]);
});

route('POST', '/profile', function () {
    $u = profile_user();
    $in = api_input();
    $data = [
        'name' => trim((string) ($in['name'] ?? $u['name'])),
        'phone' => trim((string) ($in['phone'] ?? (string) $u['phone'])),
        'designation' => trim((string) ($in['designation'] ?? (string) $u['designation'])),
        'email' => strtolower(trim((string) ($in['email'] ?? $u['email']))),
    ];
    $errors = validate($data, [
        'name' => 'required|min:2|max:150',
        'phone' => 'phone|max:30',
        'designation' => 'max:120',
        'email' => 'required|email|max:190|unique:users,email,' . (int) $u['id'],
    ], ['name' => 'Full name', 'email' => 'Email address']);
    if (isset($errors['email']) && str_contains($errors['email'], 'already exists')) {
        $errors['email'] = 'This email address is already used by another account.';
    }
    if (!isset($errors['name']) && !preg_match("/^[\p{L}\p{M} .'\-]+$/u", $data['name'])) {
        $errors['name'] = 'Name may only contain letters, spaces, dots, hyphens and apostrophes.';
    }
    $emailChanged = $data['email'] !== strtolower((string) $u['email']);
    if ($emailChanged && !isset($errors['email'])) {
        if (!password_verify((string) ($in['current_password'] ?? ''), $u['password_hash'])) {
            $errors['current_password'] = 'Enter your current password to change your email address.';
        }
    }
    $avatar = null;
    if (!empty($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $res = upload_file($_FILES['avatar'], 'image', 'users');
        if (!$res['ok']) {
            $errors['avatar'] = $res['error'];
        } else {
            $avatar = $res['path'];
        }
    }
    if ($errors) {
        if ($avatar) {
            delete_upload($avatar);
        }
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $update = ['name' => $data['name'], 'phone' => $data['phone'] ?: null, 'designation' => $data['designation'] ?: null, 'email' => $data['email']];
    $oldAvatar = $u['avatar'];
    if ($avatar) {
        $update['avatar'] = $avatar;
    } elseif (!empty($in['remove_avatar']) && $in['remove_avatar'] !== '0') {
        $update['avatar'] = null;
    }
    db_update('users', $update, 'id = ?', [$u['id']]);
    if (array_key_exists('avatar', $update) && $oldAvatar && $oldAvatar !== ($update['avatar'] ?? null)) {
        delete_upload($oldAvatar);
    }
    $changed = [];
    foreach (['name' => 'name', 'phone' => 'phone', 'designation' => 'designation', 'email' => 'email'] as $k => $label) {
        if ((string) $update[$k] !== (string) $u[$k]) {
            $changed[] = $label;
        }
    }
    if (array_key_exists('avatar', $update)) {
        $changed[] = $update['avatar'] ? 'profile photo' : 'removed profile photo';
    }
    if ($emailChanged) {
        notify((int) $u['id'], 'security', 'Email address changed', 'Your sign-in email was changed from ' . $u['email'] . ' to ' . $data['email'] . '. If this was not you, contact the administrator.', 'admin/profile', 'shield-alert');
    }
    log_activity('update', 'profile', $u['id'], 'Updated own profile' . ($changed ? ' (' . implode(', ', $changed) . ')' : ''));
    api_ok(['user' => profile_public(profile_user())], $changed ? 'Profile updated successfully.' : 'No changes to save.');
});

route('GET', '/profile/logins', function () {
    [$page, $perPage] = api_pagination(15);
    $where = 'user_id = ?';
    $args = [user_id()];
    $status = (string) ($_GET['status'] ?? '');
    if ($status === 'success') {
        $where .= " AND status = 'success'";
    } elseif ($status === 'failed') {
        $where .= " AND status IN ('failed', 'locked', 'blocked')";
    }
    $total = (int) db_value("SELECT COUNT(*) FROM login_logs WHERE $where", $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all("SELECT id, status, reason, ip_address, user_agent, created_at FROM login_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    api_ok(['rows' => array_map('profile_login_row', $rows), 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'], 'per_page' => $p['per_page']]);
});

route('GET', '/profile/activity', function () {
    [$page, $perPage] = api_pagination(15);
    $labels = activity_module_labels();
    // Sign-ins are listed on the Security tab; include them here only on request.
    $where = 'user_id = ?' . (empty($_GET['include_logins']) ? " AND action NOT IN ('login', 'logout')" : '');
    $total = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE $where", [user_id()]);
    $p = paginate($total, $page, $perPage);
    $rows = db_all("SELECT id, action, module, description, status, ip_address, created_at FROM activity_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", [user_id()]);
    api_ok(['rows' => array_map(fn ($a) => profile_activity_row($a, $labels), $rows), 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'], 'per_page' => $p['per_page']]);
});

route('DELETE', '/profile/sessions/{id:\d+}', function ($p) {
    $row = db_row("SELECT id, selector, ip_address, user_agent FROM user_tokens WHERE id = ? AND user_id = ? AND type = 'remember'", [(int) $p['id'], user_id()]);
    if (!$row) {
        api_error('That device is no longer signed in.', 404);
    }
    db_delete('user_tokens', 'id = ?', [$row['id']]);
    $current = profile_current_selector();
    if ($current !== null && hash_equals((string) $row['selector'], $current)) {
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => base_path() . '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    log_activity('revoke', 'profile', user_id(), 'Signed out remembered device ' . browser_label($row['user_agent']) . ' (' . $row['ip_address'] . ')');
    api_ok(['sessions' => profile_sessions((int) user_id())], 'Device signed out.');
});

route('DELETE', '/profile/sessions', function () {
    $current = profile_current_selector();
    $n = $current !== null
        ? db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember' AND selector <> ?", [user_id(), $current])
        : db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [user_id()]);
    log_activity('revoke', 'profile', user_id(), "Signed out $n other remembered device" . ($n === 1 ? '' : 's'));
    api_ok(['revoked' => $n, 'sessions' => profile_sessions((int) user_id())], $n ? "Signed out $n other device" . ($n === 1 ? '' : 's') . '.' : 'No other devices were signed in.');
});
