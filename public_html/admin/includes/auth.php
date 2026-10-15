<?php
/**
 * Include at the top of EVERY admin page and action (except login.php).
 * Stops the request unless an active administrator is logged in.
 * Pages then call require_permission('...') for their own section.
 */
require_once __DIR__ . '/functions.php';

$admin = current_admin();
if (!$admin) {
    $isAction = $_SERVER['REQUEST_METHOD'] !== 'GET' || strpos($_SERVER['PHP_SELF'], '/actions/') !== false;
    if ($isAction) {
        http_response_code(403);
        exit('<!doctype html><meta charset="utf-8"><title>Not authorised</title><p style="font-family:sans-serif;padding:40px">'
            . 'Your admin session has expired or you are not logged in. <a href="' . e(admin_url('login.php')) . '">Log in again</a>.</p>');
    }
    flash('info', 'Please log in to the admin panel.');
    $next = basename($_SERVER['PHP_SELF']) . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    redirect('admin/login.php?next=' . urlencode($next));
}

// First login with the default password: force a password change.
$page = basename($_SERVER['PHP_SELF']);
if ($admin['must_change_password'] && !in_array($page, ['profile.php', 'user-save.php', 'logout.php'], true)) {
    flash('warning', 'For security, please set a new password before continuing.');
    admin_redirect('profile.php');
}

/** Stop unless the admin has the given permission. */
function require_permission(string $permission): void
{
    if (!admin_can($permission)) {
        admin_log('access_denied', 'Tried to access ' . basename($_SERVER['PHP_SELF']) . ' without "' . $permission . '" permission');
        http_response_code(403);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || strpos($_SERVER['PHP_SELF'], '/actions/') !== false) {
            flash('error', 'You do not have permission to perform this action.');
            admin_redirect('dashboard.php');
        }
        $adminTitle = 'Access denied';
        require __DIR__ . '/header.php';
        echo '<div class="panel text-center"><h2>Access denied</h2><p class="muted">Your role does not have access to this section. Please contact a super administrator.</p>'
            . '<a class="btn btn-primary" href="' . e(admin_url('dashboard.php')) . '">Back to dashboard</a></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

/** Admin action endpoints: POST + valid CSRF token only. */
function admin_post_only(string $back = 'dashboard.php'): void
{
    csrf_verify('admin/' . $back);
}
