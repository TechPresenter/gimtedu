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

/** Seconds a rotated session id keeps forwarding in-flight requests to its successor (see session_rotate_id()). */
const SESSION_ROTATE_GRACE = 120;

/** Seconds between periodic session id rotations of a signed-in session. */
const SESSION_ROTATE_EVERY = 900;

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
    session_follow_rotation();
}

/**
 * Rotates the session id WITHOUT breaking requests that are already in flight with the old cookie (the SPA fires several
 * API calls in parallel; deleting the old session made the slower ones fail with 401 "session expired"). The old session
 * is kept as a tombstone that forwards to the new id for SESSION_ROTATE_GRACE seconds and is refused afterwards — the
 * approach recommended in the PHP manual ("Session ID regeneration"). Sign-in still uses session_regenerate_id(true),
 * so a pre-login id can never reach the authenticated session (session fixation).
 */
function session_rotate_id(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
        return;
    }
    $oldId = session_id();
    $data = $_SESSION;
    unset($data['_rotated_at'], $data['_rotated_to']);
    $data['regenerated_at'] = time();
    session_write_close();

    // 1. successor: an unknown id is refused by strict mode, so PHP issues a fresh random one - that is our new id
    session_id((string) session_create_id());
    session_start();
    $newId = session_id();
    $_SESSION = $data;
    session_write_close();
    if ($newId === '' || $newId === $oldId) {
        session_id($oldId);
        session_start();
        return;
    }
    // 2. the old id becomes a short-lived tombstone that forwards to the successor
    session_id($oldId);
    session_start();
    $_SESSION = ['_rotated_at' => time(), '_rotated_to' => $newId] + $data;
    session_write_close();
    // 3. continue this request in the successor (sends the new session cookie)
    session_id($newId);
    session_start();
}

/** A request that still carries the cookie of a rotated session continues in the successor session (within the grace period). */
function session_follow_rotation(): void
{
    if (!isset($_SESSION['_rotated_at'])) {
        return;
    }
    $to = (string) ($_SESSION['_rotated_to'] ?? '');
    if ($to === '' || (int) $_SESSION['_rotated_at'] < time() - SESSION_ROTATE_GRACE || headers_sent()) {
        // stale cookie of a rotated session: never let it back in
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    session_write_close();
    session_id($to);
    session_start(); // strict mode: if the successor has gone (logout / GC), PHP starts a fresh, empty session
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

/**
 * Recent failed sign-ins for an identifier that matches no account (login_logs / student_login_logs), used to give
 * unknown identifiers the same "attempts remaining" / lockout answers as real accounts (no user enumeration).
 * @return array{count:int, minutes:int} failures within the lockout window and minutes until the simulated lock ends
 */
function login_unknown_failures(string $table, string $identifier, int $windowMinutes): array
{
    $table = $table === 'student_login_logs' ? 'student_login_logs' : 'login_logs';
    try {
        $row = db_row("SELECT COUNT(*) AS n, MAX(created_at) AS last_at FROM $table
                       WHERE identifier = ? AND status = 'failed' AND created_at > (NOW() - INTERVAL ? MINUTE)", [mb_substr($identifier, 0, 190), $windowMinutes]);
    } catch (Throwable $e) {
        return ['count' => 0, 'minutes' => $windowMinutes];
    }
    $last = !empty($row['last_at']) ? strtotime((string) $row['last_at']) : time();
    return ['count' => (int) ($row['n'] ?? 0), 'minutes' => max(1, (int) ceil(($last + $windowMinutes * 60 - time()) / 60))];
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
        // Answer exactly like a real account (same "attempts remaining" hint, same lockout), so the sign-in form never
        // reveals which emails / usernames exist.
        $max = max(1, (int) setting('max_login_attempts', 5));
        $sim = login_unknown_failures('login_logs', $identifier, $window);
        if ($sim['count'] >= $max) {
            record_login_event(null, $identifier, 'failed', 'Unknown user (lockout simulated)');
            return ['ok' => false, 'locked' => true, 'message' => "This account is temporarily locked after repeated failed attempts. Try again in {$sim['minutes']} minute(s)."];
        }
        record_login_event(null, $identifier, 'failed', 'Unknown user');
        if ($sim['count'] + 1 >= $max) {
            return ['ok' => false, 'locked' => true, 'message' => "Too many failed attempts. The account is locked for $window minutes."];
        }
        $left = $max - $sim['count'] - 1;
        return ['ok' => false, 'message' => $generic . ($left <= 2 ? " $left attempt(s) remaining before lockout." : '')];
    }
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        record_login_event((int) $user['id'], $identifier, 'locked', 'Account locked');
        $mins = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
        return ['ok' => false, 'locked' => true, 'message' => "This account is temporarily locked after repeated failed attempts. Try again in $mins minute(s)."];
    }
    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['failed_attempts'] + 1;
        $max = (int) setting('max_login_attempts', 5);
        $lock = $attempts >= $max ? date('Y-m-d H:i:s', time() + $window * 60) : null;
        db_update('users', ['failed_attempts' => $lock ? 0 : $attempts, 'locked_until' => $lock, 'last_failed_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);
        record_login_event((int) $user['id'], $identifier, 'failed', $lock ? 'Wrong password - account locked' : 'Wrong password');
        if ($lock) {
            notify('perm:security', 'security', 'Account locked', "Account {$user['email']} was locked after $max failed login attempts from $ip.", 'admin/security?tab=locked', 'shield-alert');
            return ['ok' => false, 'locked' => true, 'message' => "Too many failed attempts. The account is locked for $window minutes."];
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

/**
 * Fingerprint of a user's stored password hash, kept in the session at sign-in: when the password is changed or reset
 * anywhere (self-service, reset link, administrator, portal), every other signed-in browser of that user no longer
 * matches and is signed out on its next request. The hash itself never enters the session.
 */
function session_password_fp(?string $passwordHash): string
{
    return substr(hash_hmac('sha256', (string) $passwordHash, 'pwfp|' . (string) (app_config('app')['key'] ?? '')), 0, 32);
}

/** Re-bind the current session to the user's current password (after they changed it themselves in this session). */
function session_refresh_password_fp(): void
{
    if ($uid = user_id()) {
        $_SESSION['pw_fp'] = session_password_fp((string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]));
    }
}

/** Cookie "secure" flag: HTTPS directly or behind a TLS-terminating proxy. */
function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function login_user(array $user, bool $remember = false, string $via = 'password'): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    // read the stored hash again: attempt_login() may have just re-hashed it (password_needs_rehash)
    $_SESSION['pw_fp'] = session_password_fp((string) db_value('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]));
    $_SESSION['login_at'] = time();
    $_SESSION['last_activity'] = time();
    // the id was just regenerated: without this the very next request would rotate it again while the SPA's parallel
    // first requests still carry the previous cookie
    $_SESSION['regenerated_at'] = time();
    $_SESSION['ua_hash'] = hash('sha256', user_agent());
    unset($_SESSION['_csrf'], $_SESSION['password_change_reason']);
    $_SESSION['previous_login'] = ['at' => $user['last_login_at'], 'ip' => $user['last_login_ip']];
    $update = ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip(), 'failed_attempts' => 0, 'locked_until' => null];
    if (empty($user['must_change_password']) && password_is_expired($user)) {
        // Settings > Security > Password expiry: an expired password must be replaced before anything else
        $update['must_change_password'] = 1;
        $_SESSION['password_change_reason'] = 'expired';
        log_activity('password_expired', 'auth', $user['id'], 'Password older than ' . (int) setting('password_expiry_days', 0) . ' days - a new password is required', 'success');
    } elseif (!empty($user['must_change_password'])) {
        // still pending from an earlier sign-in: keep telling an expired password apart from a temporary / forced one
        $_SESSION['password_change_reason'] = password_is_expired($user) ? 'expired' : 'required';
    }
    db_update('users', $update, 'id = ?', [$user['id']]);
    record_login_event((int) $user['id'], $user['email'], 'success', $via === 'remember' ? 'Remember-me cookie' : '');
    log_activity('login', 'auth', $user['id'], $user['name'] . ' signed in' . ($via === 'remember' ? ' (remember me)' : ''));
    if ($remember) {
        issue_remember_token((int) $user['id']);
    }
}

/* ------------------------------------------------------------------
 * Mandatory password change (temporary passwords, "force change at next sign-in", password expiry)
 * ------------------------------------------------------------------ */

/** True when Settings > Security > password_expiry_days is on and the user's password is older than that. */
function password_is_expired(array $user): bool
{
    $days = (int) setting('password_expiry_days', 0);
    if ($days <= 0) {
        return false;
    }
    $changed = $user['password_changed_at'] ?? null;
    $changed = $changed ?: ($user['created_at'] ?? null); // never changed: count from account creation
    $ts = $changed ? strtotime((string) $changed) : false;
    return $ts !== false && $ts < time() - $days * 86400;
}

/** The signed-in admin user has to set a new password before using the application. */
function password_change_required(): bool
{
    $user = current_user();
    return $user !== null && !empty($user['must_change_password']);
}

/** 'expired' (password expiry policy) or 'required' (temporary password / forced by an administrator). */
function password_change_reason(): string
{
    return ($_SESSION['password_change_reason'] ?? '') === 'expired' ? 'expired' : 'required';
}

/**
 * Called by require_login(): while a password change is pending, every API answers 403 {code: password_change_required}
 * except the auth/* endpoints (session, password, logout, ping, prefs) the "Set a new password" screen needs; server-rendered
 * pages (print views) send the user to the admin app, which shows that screen.
 */
function enforce_password_change(): void
{
    if (!password_change_required()) {
        return;
    }
    if (defined('API_REQUEST')) {
        $path = function_exists('api_path') ? api_path() : '';
        if ($path === 'auth' || str_starts_with($path, 'auth/')) {
            return;
        }
        json_response([
            'ok' => false, 'code' => 'password_change_required',
            'message' => password_change_reason() === 'expired'
                ? 'Your password has expired. Please set a new password to continue.'
                : 'Please set a new password to continue.',
            'errors' => (object) [], 'data' => ['code' => 'password_change_required', 'reason' => password_change_reason()],
        ], 403);
    }
    if (is_ajax()) {
        json_error('Please set a new password to continue.', 403);
    }
    redirect(admin_url(''));
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
        'expires' => time() + $days * 86400, 'path' => base_path() . '/', 'secure' => request_is_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function clear_remember_token(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (is_string($cookie) && str_contains($cookie, ':')) {
        [$selector] = explode(':', $cookie, 2);
        db_delete('user_tokens', "selector = ? AND type = 'remember'", [$selector]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => base_path() . '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function login_from_remember_cookie(): bool
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || !str_contains($cookie, ':')) {
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
    // a device remembered before the password was last changed / reset must sign in with the new password
    if (!empty($user['password_changed_at']) && strtotime((string) $token['created_at']) < strtotime((string) $user['password_changed_at'])) {
        clear_remember_token();
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
    $user = current_user();
    if ($user && !isset($_SESSION['pw_fp'])) {
        $_SESSION['pw_fp'] = session_password_fp($user['password_hash']); // session started before this check existed
    }
    $pwOk = $user && hash_equals((string) $_SESSION['pw_fp'], session_password_fp($user['password_hash']));
    if ($idle > session_timeout_seconds() || !$uaOk || !$user || !$pwOk) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_timeout'] = true;
        return false;
    }
    // Background polling (notifications) must not keep an idle session alive.
    if (($_SERVER['HTTP_X_BACKGROUND'] ?? '') !== '1') {
        $_SESSION['last_activity'] = time();
    }
    if (time() - (int) ($_SESSION['regenerated_at'] ?? 0) > SESSION_ROTATE_EVERY) {
        session_rotate_id(); // grace-period rotation: parallel requests with the previous cookie keep working
    }
    return true;
}

function require_login(): void
{
    if (session_is_valid() || login_from_remember_cookie()) {
        enforce_password_change();
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

/**
 * Guard for server-rendered pages (print views). API endpoints use api_require().
 * Signs the visitor in first (require_login(): idle timeout, user-agent binding, remember-me, and the mandatory password
 * change - an expired / temporary password sends the user to the admin app's "Set a new password" screen instead of
 * rendering the document).
 */
function require_permission(string $module, string $action = 'view'): void
{
    require_login();
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

