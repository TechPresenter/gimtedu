<?php
/**
 * Authentication, sessions and role-based access control for the admin panel.
 *
 *   require_login()                      redirect to login when not authenticated
 *   current_user() / user_id()
 *   can('students', 'edit')              permission check (super admins always pass)
 *   require_permission('students')       page guard (renders 403 page)
 *   ajax_require_permission('students', 'delete')   JSON 403 for AJAX endpoints
 */

require_once __DIR__ . '/init.php';

const PERMISSION_ACTIONS = ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'publish', 'manage'];

/* ------------------------------------------------------------------
 * Session
 * ------------------------------------------------------------------ */

function session_start_secure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_name('GIMTSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path() . '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

session_start_secure();

/** Idle timeout in seconds (Settings > Security > Session timeout, minutes). */
function session_timeout_seconds(): int
{
    return max(5, (int) setting('session_timeout', 30)) * 60;
}

/* ------------------------------------------------------------------
 * Current user
 * ------------------------------------------------------------------ */

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $user = db_row("SELECT u.*, (SELECT GROUP_CONCAT(r.name ORDER BY r.id SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id) AS role_names
                    FROM users u WHERE u.id = ? AND u.status = 'active'", [(int) $id]);
    return $user ?: null;
}

function user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/* ------------------------------------------------------------------
 * Login / logout
 * ------------------------------------------------------------------ */

function is_ip_blocked(string $ip): bool
{
    try {
        return (bool) db_value('SELECT COUNT(*) FROM blocked_ips WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())', [$ip]);
    } catch (Throwable $e) {
        return false;
    }
}

function record_login_event(?int $userId, string $identifier, string $status, string $reason = ''): void
{
    db_insert('login_logs', [
        'user_id' => $userId, 'identifier' => mb_substr($identifier, 0, 190), 'status' => $status, 'reason' => $reason ?: null,
        'ip_address' => client_ip(), 'user_agent' => user_agent(),
    ]);
}

/**
 * Attempt to authenticate. Returns ['ok' => bool, 'message' => string].
 * Applies IP blocking, IP rate limiting, per-account lockout and password re-hashing.
 */
function attempt_login(string $identifier, string $password, bool $remember = false): array
{
    $ip = client_ip();
    $generic = 'Invalid credentials. Please check your email/username and password.';
    if (is_ip_blocked($ip)) {
        record_login_event(null, $identifier, 'blocked', 'IP blocked');
        return ['ok' => false, 'message' => 'Access from your network has been blocked. Contact the administrator.'];
    }
    $window = (int) setting('lockout_minutes', 15);
    $ipFailures = (int) db_value("SELECT COUNT(*) FROM login_logs WHERE ip_address = ? AND status = 'failed' AND created_at > (NOW() - INTERVAL ? MINUTE)", [$ip, $window]);
    if ($ipFailures >= (int) setting('max_ip_attempts', 20)) {
        record_login_event(null, $identifier, 'blocked', 'Too many attempts from IP');
        return ['ok' => false, 'message' => "Too many login attempts. Please try again in $window minutes."];
    }

    $user = db_row('SELECT * FROM users WHERE (email = ? OR username = ?) LIMIT 1', [$identifier, $identifier]);
    if (!$user) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // equalise timing
        record_login_event(null, $identifier, 'failed', 'Unknown user');
        return ['ok' => false, 'message' => $generic];
    }
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        record_login_event((int) $user['id'], $identifier, 'locked', 'Account locked');
        $mins = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['ok' => false, 'message' => "This account is temporarily locked after repeated failed attempts. Try again in $mins minute(s)."];
    }
    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['failed_attempts'] + 1;
        $max = (int) setting('max_login_attempts', 5);
        $lock = $attempts >= $max ? date('Y-m-d H:i:s', time() + $window * 60) : null;
        db_update('users', ['failed_attempts' => $lock ? 0 : $attempts, 'locked_until' => $lock, 'last_failed_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        record_login_event((int) $user['id'], $identifier, 'failed', $lock ? 'Wrong password - account locked' : 'Wrong password');
        if ($lock) {
            notify('perm:security', 'security', 'Account locked', "Account {$user['email']} was locked after $max failed login attempts from $ip.", 'admin/security.php', 'shield-alert');
            return ['ok' => false, 'message' => "Too many failed attempts. The account is locked for $window minutes."];
        }
        $left = $max - $attempts;
        return ['ok' => false, 'message' => $generic . ($left <= 2 ? " $left attempt(s) remaining before lockout." : '')];
    }
    if ($user['status'] !== 'active') {
        record_login_event((int) $user['id'], $identifier, 'failed', 'Inactive account');
        return ['ok' => false, 'message' => 'Your account is inactive. Please contact the administrator.'];
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
    }
    login_user($user, $remember);
    return ['ok' => true, 'message' => 'Welcome back, ' . $user['name'] . '!'];
}

function login_user(array $user, bool $remember = false, string $via = 'password'): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['login_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['ua_hash'] = hash('sha256', user_agent());
    unset($_SESSION['_csrf']);
    $_SESSION['previous_login'] = ['at' => $user['last_login_at'], 'ip' => $user['last_login_ip']];
    db_update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip(), 'failed_attempts' => 0, 'locked_until' => null], 'id = ?', [$user['id']]);
    record_login_event((int) $user['id'], $user['email'], 'success', $via === 'remember' ? 'Remember-me cookie' : '');
    log_activity('login', 'auth', $user['id'], $user['name'] . ' signed in' . ($via === 'remember' ? ' (remember me)' : ''));
    if ($remember) {
        issue_remember_token((int) $user['id']);
    }
}

function logout_user(): void
{
    if ($uid = user_id()) {
        log_activity('logout', 'auth', $uid, 'Signed out');
        try {
            record_login_event($uid, current_user()['email'] ?? '', 'logout');
        } catch (Throwable $e) {
        }
    }
    clear_remember_token();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ------------------------------------------------------------------
 * Remember me (selector/validator split token, validator stored hashed)
 * ------------------------------------------------------------------ */

const REMEMBER_COOKIE = 'gimt_remember';

function issue_remember_token(int $userId): void
{
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $days = (int) setting('remember_me_days', 30);
    db_insert('user_tokens', [
        'user_id' => $userId, 'type' => 'remember', 'selector' => $selector,
        'token_hash' => hash('sha256', $validator), 'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
        'ip_address' => client_ip(), 'user_agent' => user_agent(),
    ]);
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => time() + $days * 86400, 'path' => base_path() . '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function clear_remember_token(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if ($cookie && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        db_delete('user_tokens', "selector = ? AND type = 'remember'", [$selector]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => base_path() . '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function login_from_remember_cookie(): bool
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!$cookie || !str_contains($cookie, ':')) {
        return false;
    }
    [$selector, $validator] = explode(':', $cookie, 2);
    $token = db_row("SELECT * FROM user_tokens WHERE selector = ? AND type = 'remember' AND expires_at > NOW()", [$selector]);
    if (!$token || !hash_equals($token['token_hash'], hash('sha256', $validator))) {
        clear_remember_token();
        return false;
    }
    $user = db_row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$token['user_id']]);
    db_delete('user_tokens', 'id = ?', [$token['id']]); // rotate on every use
    if (!$user) {
        return false;
    }
    login_user($user, true, 'remember');
    return true;
}

/* ------------------------------------------------------------------
 * Guards
 * ------------------------------------------------------------------ */

/** Validates session freshness. Returns false (and clears the session) when expired. */
function session_is_valid(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $idle = time() - (int) ($_SESSION['last_activity'] ?? 0);
    $uaOk = hash_equals($_SESSION['ua_hash'] ?? '', hash('sha256', user_agent()));
    if ($idle > session_timeout_seconds() || !$uaOk || !current_user()) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_timeout'] = true;
        return false;
    }
    // Background polling (notifications) must not keep an idle session alive.
    if (($_SERVER['HTTP_X_BACKGROUND'] ?? '') !== '1') {
        $_SESSION['last_activity'] = time();
    }
    if (time() - (int) ($_SESSION['regenerated_at'] ?? 0) > 900) {
        session_regenerate_id(true);
        $_SESSION['regenerated_at'] = time();
    }
    return true;
}

function require_login(): void
{
    if (session_is_valid()) {
        return;
    }
    if (login_from_remember_cookie()) {
        return;
    }
    if (is_ajax()) {
        json_error('Your session has expired. Please sign in again.', 401);
    }
    $target = $_SERVER['REQUEST_URI'] ?? '';
    redirect(admin_url('login') . ($target ? '?redirect=' . urlencode($target) : ''));
}

/* ------------------------------------------------------------------
 * Roles & permissions
 * ------------------------------------------------------------------ */

function user_role_ids(?int $userId = null): array
{
    $userId = $userId ?? user_id();
    return $userId ? array_map('intval', db_column('SELECT role_id FROM user_roles WHERE user_id = ?', [$userId])) : [];
}

function is_super_admin(?int $userId = null): bool
{
    static $cache = [];
    $userId = $userId ?? user_id();
    if (!$userId) {
        return false;
    }
    if (!isset($cache[$userId])) {
        $cache[$userId] = (bool) db_value('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_super = 1', [$userId]);
    }
    return $cache[$userId];
}

/** ['students' => ['view' => true, 'create' => true, ...], ...] for the current user. */
function user_permissions(?int $userId = null): array
{
    static $cache = [];
    $userId = $userId ?? user_id();
    if (!$userId) {
        return [];
    }
    if (!isset($cache[$userId])) {
        $rows = db_all('SELECT DISTINCT p.module, p.action FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ?', [$userId]);
        $perms = [];
        foreach ($rows as $r) {
            $perms[$r['module']][$r['action']] = true;
        }
        $cache[$userId] = $perms;
    }
    return $cache[$userId];
}

/** Permission check. 'manage' implies every action on that module. */
function can(string $module, string $action = 'view'): bool
{
    if (!user_id()) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $perms = user_permissions();
    return !empty($perms[$module][$action]) || !empty($perms[$module]['manage']);
}

/** True when the user can view at least one of the given modules. */
function can_any(array $modules, string $action = 'view'): bool
{
    foreach ($modules as $m) {
        if (can($m, $action)) {
            return true;
        }
    }
    return false;
}

/** Guard for server-rendered pages (print views). API endpoints use api_require(). */
function require_permission(string $module, string $action = 'view'): void
{
    if (can($module, $action)) {
        return;
    }
    log_activity('denied', $module, null, "Access denied: $action $module", 'failed');
    if (is_ajax()) {
        json_error('You do not have permission to perform this action.', 403);
    }
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Access denied</title><link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '">'
        . '<div class="min-h-screen grid place-items-center bg-slate-50 p-6"><div class="card max-w-md w-full p-8">'
        . empty_state('shield-x', 'You don\'t have access to this page', 'Your role does not include the "' . e($action) . '" permission for ' . e(label_from_key($module)) . '.',
            '<a href="' . e(admin_url('')) . '" class="btn btn-primary">Back to dashboard</a>') . '</div></div>';
    exit;
}

function ajax_require_permission(string $module, string $action = 'view'): void
{
    if (!can($module, $action)) {
        json_error('You do not have permission to perform this action.', 403);
    }
}

