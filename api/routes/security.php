<?php
/**
 * Security centre (login activity list = /api/crud/login_logs, blocked IPs = /api/crud/blocked_ips).
 *   GET    /api/security/overview               KPIs, 14-day attempts chart, top failing IPs, locked accounts, policy
 *   GET    /api/security/checklist              hardening checklist with pass / warn / fail states
 *   GET    /api/security/tokens                 active remember-me sessions (paginated, ?user_id=&q=)
 *   DELETE /api/security/tokens/{id}            revoke one remembered device
 *   POST   /api/security/tokens/revoke-user     {user_id}  revoke every remembered device of a user
 *   POST   /api/security/unlock/{userId}
 *   POST   /api/security/policy                 quick policy settings (same keys as Settings › Security)
 *   GET    /api/security/report                 printable security audit report
 */
require_once APP_ROOT . '/app/services/system.php';

const SECURITY_POLICY_KEYS = ['password_min_length', 'password_require_uppercase', 'password_require_number', 'password_require_special', 'password_expiry_days',
    'max_login_attempts', 'lockout_minutes', 'max_ip_attempts', 'session_timeout', 'remember_me_days', 'two_factor_enabled'];

function security_policy_values(): array
{
    $out = [];
    foreach (SECURITY_POLICY_KEYS as $k) {
        $out[$k] = (string) setting($k, '');
    }
    return $out;
}

route('GET', '/security/overview', function () {
    api_require('security', 'view');
    $k = db_row("SELECT COALESCE(SUM(status = 'success' AND created_at >= NOW() - INTERVAL 1 DAY), 0) success_24h,
                        COALESCE(SUM(status IN ('failed','locked','blocked') AND created_at >= NOW() - INTERVAL 1 DAY), 0) failed_24h,
                        COALESCE(SUM(status IN ('failed','locked','blocked') AND created_at >= NOW() - INTERVAL 7 DAY), 0) failed_7d,
                        COALESCE(SUM(status IN ('failed','locked','blocked') AND created_at >= NOW() - INTERVAL 14 DAY AND created_at < NOW() - INTERVAL 7 DAY), 0) failed_prev_7d,
                        COUNT(DISTINCT CASE WHEN created_at >= NOW() - INTERVAL 7 DAY THEN ip_address END) ips_7d
                 FROM login_logs WHERE created_at >= NOW() - INTERVAL 14 DAY");
    $locked = db_all("SELECT id, name, email, username, avatar, locked_until, failed_attempts, last_failed_login_at FROM users WHERE locked_until IS NOT NULL AND locked_until > NOW() ORDER BY locked_until DESC");
    foreach ($locked as &$l) {
        $l['id'] = (int) $l['id'];
        $l['failed_attempts'] = (int) $l['failed_attempts'];
    }
    unset($l);
    $atRisk = db_all("SELECT u.id, u.name, u.email, u.avatar, COUNT(*) attempts, MAX(l.created_at) last_attempt FROM login_logs l JOIN users u ON u.id = l.user_id
                      WHERE l.status = 'failed' AND l.created_at >= NOW() - INTERVAL 1 DAY AND (u.locked_until IS NULL OR u.locked_until <= NOW())
                      GROUP BY u.id, u.name, u.email, u.avatar HAVING attempts >= 2 ORDER BY attempts DESC LIMIT 5");
    $topIps = db_all("SELECT l.ip_address, COUNT(*) attempts, COUNT(DISTINCT l.identifier) accounts, MAX(l.created_at) last_seen,
                             (SELECT COUNT(*) FROM blocked_ips b WHERE b.ip_address = l.ip_address AND (b.expires_at IS NULL OR b.expires_at > NOW())) AS blocked
                      FROM login_logs l WHERE l.status IN ('failed','blocked','locked') AND l.created_at >= NOW() - INTERVAL 7 DAY AND l.ip_address IS NOT NULL
                      GROUP BY l.ip_address ORDER BY attempts DESC LIMIT 6");
    foreach ($topIps as &$ip) {
        $ip['attempts'] = (int) $ip['attempts'];
        $ip['accounts'] = (int) $ip['accounts'];
        $ip['blocked'] = (bool) $ip['blocked'];
    }
    unset($ip);
    $reasons = db_all("SELECT COALESCE(reason, status) reason, COUNT(*) c FROM login_logs WHERE status IN ('failed','locked','blocked') AND created_at >= NOW() - INTERVAL 30 DAY GROUP BY COALESCE(reason, status) ORDER BY c DESC LIMIT 6");
    api_ok([
        'kpis' => [
            'success_24h' => (int) $k['success_24h'], 'failed_24h' => (int) $k['failed_24h'], 'failed_7d' => (int) $k['failed_7d'], 'failed_prev_7d' => (int) $k['failed_prev_7d'],
            'ips_7d' => (int) $k['ips_7d'], 'locked' => count($locked),
            'blocked_ips' => (int) db_value('SELECT COUNT(*) FROM blocked_ips WHERE expires_at IS NULL OR expires_at > NOW()'),
            'tokens' => (int) db_value("SELECT COUNT(*) FROM user_tokens WHERE type = 'remember' AND expires_at > NOW()"),
        ],
        'series' => system_daily_series('login_logs', ['Successful' => ['success'], 'Failed' => ['failed'], 'Locked / blocked' => ['locked', 'blocked']], 14),
        'reasons' => array_map(fn ($r) => ['reason' => $r['reason'], 'count' => (int) $r['c']], $reasons),
        'locked' => $locked, 'at_risk' => $atRisk, 'top_ips' => $topIps,
        'policy' => security_policy_values(), 'your_ip' => client_ip(),
        'can' => ['edit' => can('security', 'edit'), 'settings' => can('settings', 'view')],
    ]);
});

route('GET', '/security/checklist', function () {
    api_require('security', 'view');
    $items = security_checklist();
    $scored = array_filter($items, fn ($i) => $i['status'] !== 'info');
    $points = array_sum(array_map(fn ($i) => $i['status'] === 'pass' ? 1 : ($i['status'] === 'warn' ? 0.5 : 0), $scored));
    api_ok(['items' => $items, 'score' => $scored ? (int) round($points / count($scored) * 100) : 100,
        'counts' => ['pass' => count(array_filter($items, fn ($i) => $i['status'] === 'pass')), 'warn' => count(array_filter($items, fn ($i) => $i['status'] === 'warn')),
            'fail' => count(array_filter($items, fn ($i) => $i['status'] === 'fail')), 'info' => count(array_filter($items, fn ($i) => $i['status'] === 'info'))]]);
});

route('GET', '/security/tokens', function () {
    api_require('security', 'view');
    [$page, $perPage] = api_pagination(10);
    $where = "t.type = 'remember' AND t.expires_at > NOW()";
    $args = [];
    if (!empty($_GET['user_id'])) {
        $where .= ' AND t.user_id = ?';
        $args[] = (int) $_GET['user_id'];
    }
    if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
        $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR t.ip_address LIKE ?)';
        array_push($args, "%$q%", "%$q%", "%$q%");
    }
    $total = (int) db_value("SELECT COUNT(*) FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $rows = db_all("SELECT t.id, t.user_id, t.ip_address, t.user_agent, t.created_at, t.expires_at, u.name AS user_name, u.email AS user_email, u.avatar AS user_avatar
                    FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE $where ORDER BY t.created_at DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $args);
    $currentSelector = explode(':', (string) ($_COOKIE[REMEMBER_COOKIE] ?? ''))[0];
    $currentId = $currentSelector !== '' ? (int) db_value("SELECT id FROM user_tokens WHERE selector = ? AND type = 'remember'", [$currentSelector]) : 0;
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['user_id'] = (int) $r['user_id'];
        $r['browser'] = browser_label($r['user_agent']);
        $r['current'] = $r['id'] === $currentId;
        unset($r['user_agent']);
    }
    unset($r);
    api_ok(['rows' => $rows, 'total' => $pg['total'], 'page' => $pg['page'], 'per_page' => $pg['per_page'], 'pages' => $pg['pages']]);
});

route('DELETE', '/security/tokens/{id:\d+}', function ($p) {
    api_require('security', 'edit');
    $t = db_row("SELECT t.*, u.name FROM user_tokens t JOIN users u ON u.id = t.user_id WHERE t.id = ? AND t.type = 'remember'", [(int) $p['id']]);
    if (!$t) {
        api_error('This session has already ended.', 404);
    }
    db_delete('user_tokens', 'id = ?', [$t['id']]);
    log_activity('revoke', 'security', $t['user_id'], "Revoked a remembered session of {$t['name']} (" . browser_label($t['user_agent']) . ', ' . $t['ip_address'] . ')');
    api_ok(null, "Session revoked. {$t['name']} will need to sign in again on that device.");
});

route('POST', '/security/tokens/revoke-user', function () {
    api_require('security', 'edit');
    $in = api_validate(['user_id' => 'required|integer|exists:users,id'], null, ['user_id' => 'User']);
    $name = db_value('SELECT name FROM users WHERE id = ?', [(int) $in['user_id']]);
    $n = db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [(int) $in['user_id']]);
    log_activity('revoke', 'security', $in['user_id'], "Signed $name out of $n remembered device(s)");
    api_ok(['revoked' => $n], "$n remembered device(s) of $name signed out.");
});

route('POST', '/security/unlock/{id:\d+}', function ($p) {
    api_require('security', 'edit');
    $u = db_row('SELECT * FROM users WHERE id = ?', [(int) $p['id']]);
    if (!$u) {
        api_error('User not found.', 404);
    }
    if (!is_super_admin() && system_user_is_super((int) $u['id'])) {
        api_error('Only a Super Admin can unlock another Super Admin account.', 403);
    }
    db_update('users', ['failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
    log_activity('unlock', 'security', $u['id'], 'Unlocked account ' . $u['name'] . ' (' . $u['email'] . ')');
    api_ok(null, "{$u['name']}'s account has been unlocked.");
});

route('POST', '/security/policy', function () {
    api_require('security', 'edit');
    $input = array_intersect_key(api_input(), array_flip(SECURITY_POLICY_KEYS));
    if (!$input) {
        api_error('Nothing to save.', 422);
    }
    $keys = settings_save_group('security', $input);
    if ($keys) {
        log_activity('update', 'security', null, 'Updated security policy: ' . implode(', ', array_map(fn ($k) => settings_fields('security')[$k]['label'] ?? $k, $keys)), 'success', ['keys' => $keys]);
    }
    api_ok(['policy' => security_policy_values()], $keys ? 'Security policy saved.' : 'No changes to save.');
});

route('GET', '/security/report', function () {
    api_require('security', 'view');
    system_print_security_report();
    exit;
});
