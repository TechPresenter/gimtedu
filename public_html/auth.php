<?php
/**
 * Accounts, roles, permissions and sign-in for everyone (members and staff).
 *
 * - Passwords: password_hash()/password_verify(), re-hashed when the algorithm changes.
 * - Sessions: regenerated on login; users.session_version signs a user out everywhere.
 * - Remember me: selector + hashed validator cookie, rotated on use, 30 days.
 * - Rate limits: failed attempts are counted in activity_logs (per IP and per account).
 * - Staff (admin panel) access comes only from roles with is_staff = 1, assigned by an administrator.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

const REMEMBER_COOKIE = 'pwf_remember';
const REMEMBER_DAYS = 30;

// ---------------------------------------------------------------------
// Current user
// ---------------------------------------------------------------------

/** The signed-in user row (never includes the password hash or 2FA secret), or null. */
function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user !== false && !$refresh) {
        return $user;
    }
    $user = null;
    if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }
    if (!empty($_SESSION['uid'])) {
        $row = db_row('SELECT * FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
        if ($row && $row['status'] === 'active' && (int) $row['session_version'] === (int) ($_SESSION['uver'] ?? 0)) {
            $user = public_user_fields($row);
        } else {
            unset($_SESSION['uid'], $_SESSION['uver'], $_SESSION['roles'], $_SESSION['perms']);
        }
    }
    if ($user === null && !empty($_COOKIE[REMEMBER_COOKIE])) {
        $user = login_from_remember_cookie();
    }
    return $user;
}

function public_user_fields(array $row): array
{
    unset($row['password_hash'], $row['totp_secret']);
    $row['phone'] = $row['mobile'];   // older templates use "phone"
    return $row;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function current_user_id(): ?int
{
    $u = current_user();
    return $u ? (int) $u['id'] : null;
}

/** Send visitors to the login page if they are not signed in. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('info', 'Please log in to continue.');
        redirect('/login/?next=' . urlencode(request_uri_path()));
    }
    return $user;
}

/** Path + query of the current request, for "next" redirects. */
function request_uri_path(): string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $base = base_path();
    if ($base !== '' && strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
    return '/' . ltrim($uri, '/');
}

// ---------------------------------------------------------------------
// Roles & permissions
// ---------------------------------------------------------------------

/** Role slugs of a user. */
function user_roles(?int $userId = null): array
{
    $userId = $userId ?? current_user_id();
    if (!$userId) {
        return [];
    }
    static $cache = [];
    if (!isset($cache[$userId])) {
        $cache[$userId] = array_column(db_rows('SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.sort_order', [$userId]), 'slug');
    }
    return $cache[$userId];
}

function user_has_role(string $slug, ?int $userId = null): bool
{
    return in_array($slug, user_roles($userId), true);
}

function is_administrator(?int $userId = null): bool
{
    return user_has_role('administrator', $userId);
}

/** Permission keys granted through the user's roles. */
function user_permissions(?int $userId = null): array
{
    $userId = $userId ?? current_user_id();
    if (!$userId) {
        return [];
    }
    static $cache = [];
    if (!isset($cache[$userId])) {
        $cache[$userId] = array_column(db_rows(
            'SELECT DISTINCT p.perm_key FROM user_roles ur
               JOIN role_permissions rp ON rp.role_id = ur.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE ur.user_id = ?', [$userId]), 'perm_key');
    }
    return $cache[$userId];
}

/** Server-side permission check. Administrators can do everything. */
function user_can(string $permission, ?int $userId = null): bool
{
    $userId = $userId ?? current_user_id();
    if (!$userId) {
        return false;
    }
    return is_administrator($userId) || in_array($permission, user_permissions($userId), true);
}

/** Does the user hold any staff role (may open /admin/)? */
function is_staff(?int $userId = null): bool
{
    $userId = $userId ?? current_user_id();
    if (!$userId) {
        return false;
    }
    return (int) db_value('SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.is_staff = 1', [$userId]) > 0;
}

function role_id(string $slug): ?int
{
    $id = db_value('SELECT id FROM roles WHERE slug = ?', [$slug]);
    return $id ? (int) $id : null;
}

function assign_role(int $userId, string $slug, ?int $assignedBy = null): void
{
    $roleId = role_id($slug);
    if ($roleId) {
        db_query('INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)', [$userId, $roleId, $assignedBy]);
    }
}

function remove_role(int $userId, string $slug): void
{
    $roleId = role_id($slug);
    if ($roleId) {
        db_query('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?', [$userId, $roleId]);
    }
}

/** Roles a visitor may pick on the public registration form. */
function self_register_roles(): array
{
    return db_rows('SELECT id, name, slug, description FROM roles WHERE allow_self_register = 1 AND is_staff = 0 ORDER BY sort_order');
}

// ---------------------------------------------------------------------
// Users
// ---------------------------------------------------------------------

/** Keep the last 10 digits of an Indian mobile number ("+91 98765-43210" => "9876543210"). */
function normalize_mobile(?string $mobile): string
{
    $digits = preg_replace('/\D+/', '', (string) $mobile);
    if (strlen($digits) > 10 && (strpos($digits, '91') === 0 || strpos($digits, '0') === 0)) {
        $digits = substr($digits, -10);
    }
    return $digits;
}

/** Find a user by email address or mobile number (for login and password reset). */
function find_user_by_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    if (strpos($login, '@') !== false) {
        return db_row('SELECT * FROM users WHERE email = ?', [mb_strtolower($login)]);
    }
    $mobile = normalize_mobile($login);
    return strlen($mobile) === 10 ? db_row('SELECT * FROM users WHERE mobile = ?', [$mobile]) : null;
}

/** Password policy: 8+ characters with at least one letter and one number. */
function password_problem(string $password, string $name = '', string $email = ''): ?string
{
    if (mb_strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (mb_strlen($password) > 128) {
        return 'Password is too long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    $lower = mb_strtolower($password);
    $local = mb_strtolower((string) strtok($email, '@'));
    if (($local !== '' && strlen($local) >= 4 && strpos($lower, $local) !== false) || in_array($lower, ['password1', 'password123', '12345678a', 'qwerty123', 'admin123', 'welcome123'], true)) {
        return 'Please choose a less predictable password.';
    }
    return null;
}

/** Create a user and give them roles. Returns the new id. */
function create_user(array $data, array $roleSlugs = [], ?int $assignedBy = null): int
{
    $id = db_insert('users', [
        'name'                  => $data['name'],
        'email'                 => mb_strtolower($data['email']),
        'mobile'                => !empty($data['mobile']) ? normalize_mobile($data['mobile']) : null,
        'password_hash'         => password_hash($data['password'], PASSWORD_DEFAULT),
        'status'                => $data['status'] ?? 'active',
        'email_verified_at'     => $data['email_verified_at'] ?? null,
        'city'                  => $data['city'] ?? null,
        'communication_consent' => !empty($data['communication_consent']) ? 1 : 0,
        'privacy_accepted_at'   => !empty($data['privacy_accepted']) ? date('Y-m-d H:i:s') : null,
    ]);
    foreach ($roleSlugs as $slug) {
        assign_role($id, $slug, $assignedBy);
    }
    return $id;
}

// ---------------------------------------------------------------------
// Login / logout
// ---------------------------------------------------------------------

/**
 * Check credentials with rate limiting. Generic error messages avoid revealing whether an account exists.
 * Returns ['ok' => true, 'user' => row] or ['ok' => false, 'error' => message, 'code' => ...].
 */
function attempt_login(string $login, string $password, bool $staffOnly = false): array
{
    $generic = 'The email/mobile number or password is incorrect.';
    $key = mb_strtolower(trim($login));
    if (too_many_attempts('login_failed', $key)) {
        return ['ok' => false, 'code' => 'locked', 'error' => 'Too many unsuccessful attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again, or reset your password.'];
    }
    $user = find_user_by_login($login);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        if (!$user) {
            password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'); // equalise timing
        }
        log_activity('login_failed', $key, $user ? (int) $user['id'] : null);
        return ['ok' => false, 'code' => 'invalid', 'error' => $generic];
    }
    if ($user['status'] !== 'active') {
        log_activity('login_failed', $key, (int) $user['id']);
        return ['ok' => false, 'code' => 'inactive', 'error' => 'This account is not active. Please contact us if you think this is a mistake.'];
    }
    if ($staffOnly && !is_staff((int) $user['id'])) {
        log_activity('login_failed', $key, (int) $user['id']);
        return ['ok' => false, 'code' => 'not_staff', 'error' => $generic];
    }
    if (setting('require_email_verification', '1') === '1' && empty($user['email_verified_at']) && !is_staff((int) $user['id'])) {
        return ['ok' => false, 'code' => 'unverified', 'user' => $user,
            'error' => 'Please verify your email address first. We can send you a new verification link.'];
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
    }
    return ['ok' => true, 'user' => $user];
}

/** Start a session for a verified user. */
function login_user(array $user, bool $remember = false): void
{
    $userId = (int) $user['id'];
    session_regenerate_id(true);
    $row = db_row('SELECT id, session_version, last_login_at, email FROM users WHERE id = ?', [$userId]);
    $_SESSION['uid'] = $userId;
    $_SESSION['uver'] = (int) $row['session_version'];
    $_SESSION['login_at'] = time();
    $_SESSION['last_seen'] = time();
    unset($_SESSION['totp_ok']);

    // Genuine security signal: failed sign-in attempts on this account since the last successful login
    $since = $row['last_login_at'] ?: date('Y-m-d H:i:s', time() - 30 * 86400);
    $failed = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action = 'login_failed' AND user_id = ? AND created_at > ?", [$userId, $since]);
    if ($failed > 0) {
        $_SESSION['security_notice'] = ['failed' => $failed, 'since' => $since];
        add_notification($userId, 'Unsuccessful sign-in attempts', $failed . ' unsuccessful sign-in attempt' . ($failed > 1 ? 's were' : ' was')
            . ' made on your account since ' . format_date($since, 'd M Y, h:i A') . '. If this was not you, change your password.', '/dashboard/password/', 'security');
    }

    db_update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => client_ip()], 'id = :id', ['id' => $userId]);
    log_activity('login', 'Signed in', $userId, is_staff($userId));
    if ($remember) {
        issue_remember_cookie($userId);
    }
    current_user(true);
}

/** End this session (and forget this device). */
function logout_user(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        [$selector] = array_pad(explode(':', (string) $_COOKIE[REMEMBER_COOKIE], 2), 2, '');
        db_query('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
        clear_remember_cookie();
    }
    $uid = $_SESSION['uid'] ?? null;
    $_SESSION = [];
    session_regenerate_id(true);
    if ($uid) {
        log_activity('logout', 'Signed out', (int) $uid);
    }
}

/** Sign a user out on every device (after a password change/reset or by an administrator). */
function logout_everywhere(int $userId): void
{
    db_query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$userId]);
    db_query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
    if ((int) ($_SESSION['uid'] ?? 0) === $userId) {
        $_SESSION['uver'] = (int) db_value('SELECT session_version FROM users WHERE id = ?', [$userId]);
    }
}

/** Change a password, sign out other devices and tell the user by email. */
function set_user_password(int $userId, string $password): void
{
    db_update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = :id', ['id' => $userId]);
    logout_everywhere($userId);
    db_query('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    $user = db_row('SELECT name, email FROM users WHERE id = ?', [$userId]);
    log_activity('password_changed', 'Password changed', $userId);
    add_notification($userId, 'Password changed', 'Your password was changed on ' . date('d M Y, h:i A') . '. If you did not do this, contact us immediately.', '/contact/', 'security');
    if ($user) {
        send_mail($user['email'], 'Your password was changed - ' . site_name(),
            '<p>Dear ' . e($user['name']) . ',</p><p>The password for your ' . e(site_name()) . ' account was changed on ' . e(date('d M Y, h:i A')) . '. You have been signed out on other devices.</p>'
            . '<p>If you did not make this change, please reset your password at <a href="' . e(abs_url('forgot-password/')) . '">' . e(abs_url('forgot-password/')) . '</a> and contact us.</p>');
    }
}

// ---------------------------------------------------------------------
// Remember me
// ---------------------------------------------------------------------
function issue_remember_cookie(int $userId): void
{
    $selector = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    db_insert('remember_tokens', [
        'user_id'        => $userId,
        'selector'       => $selector,
        'validator_hash' => hash('sha256', $validator),
        'expires_at'     => date('Y-m-d H:i:s', time() + REMEMBER_DAYS * 86400),
    ]);
    if (random_int(1, 20) === 1) {
        db_query('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    }
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => time() + REMEMBER_DAYS * 86400, 'path' => base_path() ?: '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void
{
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => base_path() ?: '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Restore a session from a valid remember-me cookie (rotates the token). */
function login_from_remember_cookie(): ?array
{
    [$selector, $validator] = array_pad(explode(':', (string) $_COOKIE[REMEMBER_COOKIE], 2), 2, '');
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        clear_remember_cookie();
        return null;
    }
    $token = db_row('SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()', [$selector]);
    if (!$token || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        if ($token) {
            db_query('DELETE FROM remember_tokens WHERE user_id = ?', [$token['user_id']]); // possible theft: revoke all
        }
        clear_remember_cookie();
        return null;
    }
    $user = db_row("SELECT * FROM users WHERE id = ? AND status = 'active'", [$token['user_id']]);
    db_query('DELETE FROM remember_tokens WHERE id = ?', [$token['id']]);
    if (!$user) {
        clear_remember_cookie();
        return null;
    }
    // Staff must sign in with a password (and 2FA) to open the admin panel; remember-me only restores member access.
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['uver'] = (int) $user['session_version'];
    $_SESSION['login_at'] = time();
    $_SESSION['remembered'] = true;
    issue_remember_cookie((int) $user['id']);
    return public_user_fields($user);
}

// ---------------------------------------------------------------------
// Email verification & password reset (single use, expiring, hashed tokens)
// ---------------------------------------------------------------------
function create_email_verification(int $userId): string
{
    db_query('DELETE FROM email_verifications WHERE user_id = ? AND used_at IS NULL', [$userId]);
    $token = bin2hex(random_bytes(32));
    db_insert('email_verifications', [
        'user_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 48 * 3600),
    ]);
    return $token;
}

function send_verification_email(array $user): void
{
    $link = abs_url('verify-email/?token=' . create_email_verification((int) $user['id']));
    send_mail($user['email'], 'Verify your email - ' . site_name(),
        '<p>Dear ' . e($user['name']) . ',</p><p>Thank you for creating an account with ' . e(site_name()) . '. Please confirm your email address:</p>'
        . '<p><a class="btn" href="' . e($link) . '">Verify my email</a></p><p>This link is valid for 48 hours. If you did not create an account, you can ignore this email.</p>');
}

/** Returns the user id when the token is valid (and marks it used). */
function verify_email_token(string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $row = db_row('SELECT * FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)]);
    if (!$row) {
        return null;
    }
    db_query('UPDATE email_verifications SET used_at = NOW() WHERE id = ?', [$row['id']]);
    db_query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$row['user_id']]);
    log_activity('email_verified', 'Email verified', (int) $row['user_id']);
    return (int) $row['user_id'];
}

function create_password_reset(int $userId): string
{
    db_query('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    $token = bin2hex(random_bytes(32));
    db_insert('password_resets', [
        'user_id' => $userId, 'token_hash' => hash('sha256', $token),
        'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'ip_address' => client_ip(),
    ]);
    return $token;
}

/** Valid, unused reset row for a token, or null. */
function find_password_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return db_row('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)]);
}

// ---------------------------------------------------------------------
// Two-step login for staff (TOTP, RFC 6238 - works with any authenticator app)
// ---------------------------------------------------------------------
function totp_generate_secret(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    return $secret;
}

function base32_decode_str(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_code(string $secret, int $timeSlice): string
{
    $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $timeSlice), base32_decode_str($secret), true);
    $offset = ord($hash[19]) & 0xf;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | ((ord($hash[$offset + 1]) & 0xff) << 16) | ((ord($hash[$offset + 2]) & 0xff) << 8) | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current code and one step either side (clock drift). */
function totp_verify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return false;
    }
    $slice = (int) floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totp_code($secret, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

// ---------------------------------------------------------------------
// Notifications
// ---------------------------------------------------------------------
function add_notification(int $userId, string $title, string $message, ?string $link = null, string $type = 'info'): void
{
    try {
        db_insert('notifications', [
            'user_id' => $userId, 'title' => mb_substr($title, 0, 200), 'message' => mb_substr($message, 0, 1000),
            'link' => $link, 'type' => in_array($type, ['info', 'success', 'warning', 'security'], true) ? $type : 'info',
        ]);
    } catch (Throwable $e) {
        error_log('Notification failed: ' . $e->getMessage());
    }
}

function unread_notification_count(?int $userId = null): int
{
    $userId = $userId ?? current_user_id();
    return $userId ? (int) db_value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]) : 0;
}

// ---------------------------------------------------------------------
// Abuse protection
// ---------------------------------------------------------------------

/** Too many failures recently for this IP, or for this account identifier? */
function too_many_attempts(string $action, string $identifier = ''): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $byIp = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND ip_address = ? AND created_at > ?', [$action, client_ip(), $since]);
    if ($byIp >= LOGIN_MAX_ATTEMPTS * 2) {
        return true;
    }
    if ($identifier !== '') {
        $byId = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND details = ? AND created_at > ?', [$action, $identifier, $since]);
        return $byId >= LOGIN_MAX_ATTEMPTS;
    }
    return false;
}

/** Simple rate limit for public forms (per IP per hour). Record a submission with log_activity('form_<name>'). */
function form_rate_limited(string $form, int $maxPerHour = 10): bool
{
    $count = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND ip_address = ? AND created_at > ?',
        ['form_' . $form, client_ip(), date('Y-m-d H:i:s', time() - 3600)]);
    return $count >= $maxPerHour;
}

/** Honeypot field: real visitors leave it empty, bots fill it in. */
function honeypot_field(): string
{
    return '<div class="hp-field" aria-hidden="true"><label>Leave this empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
}

function honeypot_triggered(): bool
{
    return !empty($_POST['website']);
}

/** Only allow redirects to paths on this site, e.g. "/dashboard/donations/". */
function safe_next(?string $next, string $default = '/dashboard/'): string
{
    $next = (string) $next;
    if ($next !== '' && preg_match('#^/(?!/)[A-Za-z0-9\-_/.]*(\?[A-Za-z0-9=&%_\-.+]*)?$#', $next) && strpos($next, '..') === false
        && strpos($next, '/admin') !== 0) {
        return $next;
    }
    return $default;
}
