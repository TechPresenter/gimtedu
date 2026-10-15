<?php
/**
 * Include at the top of EVERY admin page and action (except login.php):
 *     require_once __DIR__ . '/includes/auth.php';      (pages)
 *     require_once dirname(__DIR__) . '/includes/auth.php';  (actions/)
 * Stops the request unless a staff member is signed in (password + optional 2-step code),
 * enforces the inactivity timeout, then pages call require_permission('...').
 */
require_once __DIR__ . '/functions.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$admin = current_admin();
$adminSelf = basename($_SERVER['SCRIPT_NAME']);
$adminIsAction = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || strpos($_SERVER['SCRIPT_NAME'], '/actions/') !== false;

$adminFail = function (string $message) use ($adminIsAction): void {
    if ($adminIsAction) {
        http_response_code(403);
        exit('<!doctype html><meta charset="utf-8"><title>Not authorised</title><p style="font-family:sans-serif;padding:40px">'
            . e($message) . ' <a href="' . e(admin_url('login.php')) . '">Sign in</a>.</p>');
    }
    flash('info', $message);
    $next = $_SERVER['REQUEST_URI'] ?? '';
    redirect('admin/login.php' . ($next !== '' ? '?next=' . urlencode($next) : ''));
};

if (!$admin) {
    $adminFail(is_logged_in() ? 'Your account does not have access to the admin panel.' : 'Please sign in to the admin panel.');
}
// Remember-me cookies restore member access only: staff must sign in with their password.
if (!empty($_SESSION['remembered']) || empty($_SESSION['staff_auth'])) {
    $adminFail('Please confirm your password to open the admin panel.');
}
// Two-step verification (if enabled for this account)
if (!empty($admin['totp_enabled']) && empty($_SESSION['totp_ok'])) {
    redirect('admin/login.php?step=2fa');
}
// Inactivity timeout
if (!empty($_SESSION['admin_last_activity']) && time() - (int) $_SESSION['admin_last_activity'] > ADMIN_SESSION_TIMEOUT) {
    unset($_SESSION['staff_auth'], $_SESSION['totp_ok'], $_SESSION['admin_last_activity']);
    $adminFail('Your admin session expired after a period of inactivity.');
}
$_SESSION['admin_last_activity'] = time();

// Forced password change (e.g. after an administrator set a temporary password)
if (!empty($admin['must_change_password']) && !in_array($adminSelf, ['profile.php', 'logout.php'], true)) {
    flash('warning', 'For security, please set a new password before continuing.');
    admin_redirect('profile.php');
}

/** Stop unless the signed-in staff member has the permission. */
function require_permission(string $permission): void
{
    if (admin_can($permission)) {
        return;
    }
    admin_log('access_denied', 'Tried to access ' . basename($_SERVER['SCRIPT_NAME']) . ' without "' . $permission . '" permission');
    http_response_code(403);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' || strpos($_SERVER['SCRIPT_NAME'], '/actions/') !== false) {
        flash('error', 'You do not have permission to perform this action.');
        admin_redirect('dashboard.php');
    }
    admin_page_start('Access denied');
    echo '<div class="panel text-center"><h2>Access denied</h2><p class="muted">Your role does not include this section. Please contact an administrator.</p>'
        . '<a class="btn btn-primary" href="' . e(admin_url('dashboard.php')) . '">Back to dashboard</a></div>';
    admin_page_end();
    exit;
}

/** POST-only admin actions with a valid CSRF token. $back is an admin page, e.g. 'campaigns.php'. */
function admin_post_only(string $back = 'dashboard.php'): void
{
    csrf_verify('admin/' . $back);
}
