<?php
/**
 * Website user authentication (donors, volunteers, members).
 * Admin authentication lives in admin/includes/auth.php.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** The logged-in user row, or null. */
function current_user(bool $refresh = false): ?array
{
    static $user = false;
    if ($user === false || $refresh) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $user = db_row("SELECT id, name, email, phone, address, city, state, pincode, status, created_at, last_login_at
                            FROM users WHERE id = ? AND status = 'active'", [(int) $_SESSION['user_id']]);
            if (!$user) {
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function current_user_id(): ?int
{
    $user = current_user();
    return $user ? (int) $user['id'] : null;
}

/** Send visitors to the login page if they are not signed in. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $next = current_page() . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
        flash('info', 'Please log in to continue.');
        redirect('login.php?next=' . urlencode($next));
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_login_at'] = time();
    db_update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $user['id']]);
    log_activity('user_login', 'User logged in: ' . $user['email'], null, (int) $user['id']);
}

function logout_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['user_login_at']);
    session_regenerate_id(true);
}

/**
 * Brute-force protection: has this IP (or email) failed too often recently?
 * Failed attempts are stored in activity_logs.
 */
function too_many_attempts(string $action, string $identifier = ''): bool
{
    $since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
    $byIp = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND ip_address = ? AND created_at > ?',
        [$action, client_ip(), $since]);
    if ($byIp >= LOGIN_MAX_ATTEMPTS * 2) {
        return true;
    }
    if ($identifier !== '') {
        $byId = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND details = ? AND created_at > ?',
            [$action, $identifier, $since]);
        return $byId >= LOGIN_MAX_ATTEMPTS;
    }
    return $byIp >= LOGIN_MAX_ATTEMPTS;
}

/** Simple rate limit for public forms (per IP per hour). */
function form_rate_limited(string $form, int $maxPerHour = 10): bool
{
    $since = date('Y-m-d H:i:s', time() - 3600);
    $count = (int) db_value('SELECT COUNT(*) FROM activity_logs WHERE action = ? AND ip_address = ? AND created_at > ?',
        ['form_' . $form, client_ip(), $since]);
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

/** Only allow redirects to pages on this site. */
function safe_next(?string $next, string $default = 'member-dashboard.php'): string
{
    $next = (string) $next;
    if ($next !== '' && preg_match('#^[a-z0-9\-]+\.php(\?[A-Za-z0-9=&%_\-.]*)?$#i', $next)) {
        return $next;
    }
    return $default;
}
