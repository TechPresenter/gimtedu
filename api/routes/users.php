<?php
/**
 * User administration endpoints (CRUD itself is /api/crud/users).
 *   GET  /api/users/stats                       KPI counts + users per role
 *   GET  /api/users/{id}/detail                 profile, roles, login history, recent activity, remember-me sessions
 *   POST /api/users/{id}/status                 {status: active|inactive}
 *   POST /api/users/bulk-status                 {ids: [], status}
 *   POST /api/users/{id}/unlock
 *   POST /api/users/{id}/reset-password         {mode: temporary|link, password?, must_change?}
 *   POST /api/users/{id}/revoke-sessions        sign out remembered devices
 *   GET  /api/users/{id}/print                  printable access profile
 */
require_once APP_ROOT . '/app/services/system.php';

function users_target(int $id): array
{
    $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        api_error('User not found. It may have been deleted.', 404);
    }
    return $u;
}

/** Only super admins may act on super admin accounts. */
function users_guard_super(array $u): void
{
    if ((int) $u['id'] !== user_id() && !is_super_admin() && system_user_is_super((int) $u['id'])) {
        api_error('Only a Super Admin can change another Super Admin account.', 403);
    }
}

/** Activate / deactivate with all safety rules. Returns null on success or an error message. */
function users_set_status(array $u, string $status): ?string
{
    if ($u['status'] === $status) {
        return null;
    }
    if ($status === 'inactive') {
        if ((int) $u['id'] === user_id()) {
            return 'You cannot deactivate your own account.';
        }
        if (system_user_is_super((int) $u['id']) && system_super_admin_count((int) $u['id']) === 0) {
            return 'This is the last active Super Admin and cannot be deactivated.';
        }
    }
    db_update('users', ['status' => $status], 'id = ?', [$u['id']]);
    if ($status === 'inactive') {
        db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$u['id']]);
    }
    log_activity('update', 'users', $u['id'], ($status === 'active' ? 'Activated' : 'Deactivated') . ' user account ' . $u['name'] . ' (' . $u['username'] . ')');
    return null;
}

function users_unlock(array $u): void
{
    db_update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
    log_activity('unlock', 'users', $u['id'], 'Unlocked account ' . $u['name'] . ' (' . $u['email'] . ')');
}

route('GET', '/users/stats', function () {
    api_require('users', 'view');
    $r = db_row("SELECT COUNT(*) total, COALESCE(SUM(status = 'active'), 0) active, COALESCE(SUM(status = 'inactive'), 0) inactive,
                        COALESCE(SUM(locked_until IS NOT NULL AND locked_until > NOW()), 0) locked, COALESCE(SUM(last_login_at IS NULL), 0) never,
                        COALESCE(SUM(last_login_at >= CURDATE()), 0) today, COALESCE(SUM(must_change_password = 1), 0) must_change,
                        COALESCE(SUM(last_login_at >= NOW() - INTERVAL 7 DAY), 0) week
                 FROM users");
    $roles = db_all('SELECT r.id, r.name, r.color, r.is_super, COUNT(ur.user_id) users FROM roles r LEFT JOIN user_roles ur ON ur.role_id = r.id GROUP BY r.id, r.name, r.color, r.is_super ORDER BY r.is_super DESC, r.id');
    foreach ($roles as &$role) {
        $role['id'] = (int) $role['id'];
        $role['users'] = (int) $role['users'];
        $role['is_super'] = (bool) $role['is_super'];
    }
    unset($role);
    api_ok(array_map('intval', $r) + ['roles' => $roles]);
});

route('GET', '/users/form-options', function () {
    if (!can('users', 'create') && !can('users', 'edit')) {
        api_error('You do not have permission to manage users.', 403);
    }
    $roles = db_all('SELECT r.id, r.name, r.description, r.color, r.is_super, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) users_count FROM roles r ORDER BY r.is_super DESC, r.id');
    foreach ($roles as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_super'] = (bool) $r['is_super'];
        $r['users_count'] = (int) $r['users_count'];
        $r['assignable'] = is_super_admin();
        if (!$r['assignable'] && !$r['is_super']) {
            $r['assignable'] = true;
            foreach (role_permission_keys($r['id']) as $k) {
                [$mod, $act] = explode('.', $k, 2);
                if (!can($mod, $act)) {
                    $r['assignable'] = false;
                    break;
                }
            }
        }
    }
    unset($r);
    api_ok(['roles' => $roles, 'departments' => lookup_options('departments')]);
});

route('GET', '/users/{id:\d+}/detail', function ($p) {
    api_require('users', 'view');
    $m = crud_module('users');
    $row = crud_find($m, (int) $p['id']);
    if (!$row) {
        api_error('User not found. It may have been deleted.', 404);
    }
    $id = (int) $p['id'];
    $logins = db_all('SELECT id, status, reason, ip_address, user_agent, created_at FROM login_logs WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 15', [$id]);
    foreach ($logins as &$l) {
        $l['id'] = (int) $l['id'];
        $l['browser'] = browser_label($l['user_agent']);
        unset($l['user_agent']);
    }
    unset($l);
    $labels = activity_module_labels();
    $activity = db_all('SELECT id, action, module, record_id, description, status, ip_address, created_at FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 15', [$id]);
    foreach ($activity as &$a) {
        $a['id'] = (int) $a['id'];
        $a['module_label'] = $labels[$a['module']] ?? label_from_key($a['module']);
    }
    unset($a);
    $tokens = db_all("SELECT id, ip_address, user_agent, created_at, expires_at FROM user_tokens WHERE user_id = ? AND type = 'remember' AND expires_at > NOW() ORDER BY created_at DESC", [$id]);
    foreach ($tokens as &$t) {
        $t['id'] = (int) $t['id'];
        $t['browser'] = browser_label($t['user_agent']);
        unset($t['user_agent']);
    }
    unset($t);
    $stats = db_row("SELECT COALESCE(SUM(status = 'success'), 0) logins, COALESCE(SUM(status IN ('failed','locked')), 0) failed, COUNT(DISTINCT ip_address) ips
                     FROM login_logs WHERE user_id = ? AND created_at >= NOW() - INTERVAL 30 DAY", [$id]);
    $stats['actions'] = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND created_at >= NOW() - INTERVAL 30 DAY', [$id]);
    $perms = [];
    foreach ($row['role_list'] ?? [] as $r) {
        if (!empty($r['is_super'])) {
            $perms = ['*'];
            break;
        }
        $perms = array_merge($perms, role_permission_keys((int) $r['id']));
    }
    api_ok([
        'user' => crud_public_row($m, $row), 'logins' => $logins, 'activity' => $activity, 'tokens' => $tokens,
        'stats' => array_map('intval', $stats), 'permissions_count' => $perms === ['*'] ? null : count(array_unique($perms)),
        'can_manage' => (int) $row['id'] === user_id() || is_super_admin() || !$row['is_super'],
    ]);
});

route('POST', '/users/{id:\d+}/status', function ($p) {
    api_require('users', 'edit');
    $in = api_validate(['status' => 'required|in:active,inactive']);
    $u = users_target((int) $p['id']);
    users_guard_super($u);
    if ($err = users_set_status($u, $in['status'])) {
        api_error($err, 422);
    }
    api_ok(['status' => $in['status']], $in['status'] === 'active' ? "{$u['name']} can sign in again." : "{$u['name']} has been deactivated and signed out of remembered devices.");
});

route('POST', '/users/bulk-status', function () {
    api_require('users', 'edit');
    $in = api_validate(['status' => 'required|in:active,inactive', 'ids' => 'required']);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $in['ids']))));
    $done = 0;
    $skipped = [];
    foreach ($ids as $id) {
        $u = db_row('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) {
            continue;
        }
        if (!is_super_admin() && system_user_is_super($id)) {
            $skipped[] = $u['name'] . ': only a Super Admin can change this account';
            continue;
        }
        if ($err = users_set_status($u, $in['status'])) {
            $skipped[] = $u['name'] . ': ' . $err;
            continue;
        }
        $done++;
    }
    $msg = "$done user(s) " . ($in['status'] === 'active' ? 'activated' : 'deactivated') . '.' . ($skipped ? ' ' . count($skipped) . ' skipped.' : '');
    api_ok(['updated' => $done, 'skipped' => $skipped], $msg);
});

route('POST', '/users/{id:\d+}/unlock', function ($p) {
    if (!can('users', 'edit') && !can('security', 'edit')) {
        api_error('You do not have permission to unlock accounts.', 403);
    }
    $u = users_target((int) $p['id']);
    users_guard_super($u);
    users_unlock($u);
    api_ok(null, "{$u['name']}'s account has been unlocked.");
});

route('POST', '/users/{id:\d+}/reset-password', function ($p) {
    api_require('users', 'edit');
    $u = users_target((int) $p['id']);
    users_guard_super($u);
    $in = api_input();
    $mode = (string) ($in['mode'] ?? 'temporary');
    if (!in_array($mode, ['temporary', 'link'], true)) {
        api_error('Choose how to reset the password.', 422, ['mode' => 'Choose how to reset the password.']);
    }
    if ($mode === 'link') {
        if ($u['status'] !== 'active') {
            api_error('Activate the account before sending a reset link.', 422);
        }
        if (!rate_limit('admin-reset-link:' . $u['id'], 5, 3600)) {
            api_error('Too many reset links were sent for this user. Try again later.', 429);
        }
        db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'reset'", [$u['id']]);
        $selector = bin2hex(random_bytes(9));
        $token = bin2hex(random_bytes(32));
        db_insert('user_tokens', ['user_id' => $u['id'], 'type' => 'reset', 'selector' => $selector, 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'ip_address' => client_ip(), 'user_agent' => user_agent()]);
        $link = absolute_url('admin/reset-password?selector=' . $selector . '&token=' . $token);
        $sent = send_template_mail('password_reset', $u['email'], ['name' => $u['name'], 'reset_link' => $link], ['related_type' => 'user', 'related_id' => $u['id']]);
        log_activity('password_reset_link', 'users', $u['id'], 'Sent a password reset link to ' . $u['email'], $sent ? 'success' : 'failed');
        if (!$sent) {
            $err = db_value("SELECT error FROM message_logs WHERE channel = 'email' AND recipient = ? ORDER BY id DESC LIMIT 1", [$u['email']]);
            api_error('The reset email could not be sent' . ($err ? ': ' . $err : '. Check Settings › Email & SMS.'), 422);
        }
        $logged = is_dev() || setting('mail_driver', 'smtp') === 'log';
        api_ok(['mode' => 'link', 'email' => $u['email'], 'logged' => $logged, 'expires_minutes' => 60],
            $logged ? 'Reset link generated. Development mode: the email was written to storage/logs/mail.log.' : "A password reset link has been emailed to {$u['email']}. It expires in 60 minutes.");
    }
    $password = trim((string) ($in['password'] ?? ''));
    $generated = false;
    if ($password === '') {
        $password = system_generate_password();
        $generated = true;
    }
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    $mustChange = !array_key_exists('must_change', $in) || !empty($in['must_change']);
    db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'password_changed_at' => date('Y-m-d H:i:s'),
        'must_change_password' => $mustChange ? 1 : 0, 'failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
    if ((int) $u['id'] !== user_id()) {
        db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type IN ('remember','reset')", [$u['id']]);
    }
    log_activity('password_reset', 'users', $u['id'], 'Set a temporary password for ' . $u['name'] . ($mustChange ? ' (must change at next sign-in)' : ''), 'success', ['generated' => $generated]);
    notify((int) $u['id'], 'security', 'Your password was reset', 'An administrator set a new password for your account' . ($mustChange ? '. You will be asked to change it after signing in.' : '.'), 'admin/profile', 'key-round');
    api_ok(['mode' => 'temporary', 'password' => $generated ? $password : null, 'must_change' => $mustChange],
        'Temporary password set for ' . $u['name'] . '.' . ($mustChange ? ' They must change it at next sign-in.' : ''));
});

route('POST', '/users/{id:\d+}/revoke-sessions', function ($p) {
    if (!can('users', 'edit') && !can('security', 'edit')) {
        api_error('You do not have permission to revoke sessions.', 403);
    }
    $u = users_target((int) $p['id']);
    users_guard_super($u);
    $n = db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$u['id']]);
    log_activity('revoke', 'security', $u['id'], "Signed {$u['name']} out of $n remembered device(s)");
    api_ok(['revoked' => $n], $n ? "$n remembered device(s) signed out." : 'No remembered devices to sign out.');
});

route('GET', '/users/{id:\d+}/print', function ($p) {
    api_require('users', 'view');
    $m = crud_module('users');
    $u = crud_find($m, (int) $p['id']);
    if (!$u) {
        api_error('User not found.', 404);
    }
    system_print_user_profile($u);
    exit;
});
