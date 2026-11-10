<?php
/**
 * Private admin address (Settings › Security › "Private admin address", owner request 2026-11-10).
 *
 * When the setting `admin_secret_path` is set (e.g. "gimt-team-4821"), the admin panel (/admin/…) and the admin
 * sign-in API answer "page not found" to every browser that has not opened https://<site>/<secret> once. Opening that
 * address stores the cookie gimt_ak (≈ 13 months, HttpOnly) and forwards to /admin/. Signed-in staff keep working,
 * and password-reset links from e-mails (/admin/reset-password?token=…) stay open. Blank setting = /admin as before.
 * Hooks: page.php (the /<secret> address), admin/index.php (the SPA), api/routes/auth.php (POST /auth/login),
 * app/services/system.php (validation + the cookie for whoever switches it on).
 * Locked out (address forgotten)? Clear it on the server: UPDATE settings SET value = '' WHERE `key` = 'admin_secret_path'.
 */

const ADMIN_GATE_COOKIE = 'gimt_ak';

/** The private address slug, or '' when the admin panel is not hidden. */
function admin_gate_path(): string
{
    $p = strtolower(trim((string) setting('admin_secret_path', '')));
    return preg_match('/^[a-z0-9][a-z0-9-]{5,39}$/', $p) ? $p : '';
}

/** Cookie value for a slug (an HMAC: the cookie says nothing about the address). */
function admin_gate_token(string $path): string
{
    return hash_hmac('sha256', 'admin-gate|' . $path, 'gimt|' . (string) (app_config('app')['key'] ?? ''));
}

/** True when this browser may see the admin panel (feature off, gate cookie present, or signed in). */
function admin_gate_open(): bool
{
    $p = admin_gate_path();
    if ($p === '') {
        return true;
    }
    $c = (string) ($_COOKIE[ADMIN_GATE_COOKIE] ?? '');
    if ($c !== '' && hash_equals(admin_gate_token($p), $c)) {
        return true;
    }
    if (!function_exists('current_user')) {
        require_once APP_ROOT . '/app/auth.php';
    }
    return current_user() !== null;
}

/** Remember this browser (sent with the /<secret> visit and with the save that switches the feature on). */
function admin_gate_remember(?string $path = null): void
{
    $path ??= admin_gate_path();
    if ($path === '' || headers_sent()) {
        return;
    }
    $https = function_exists('request_is_https') ? request_is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(ADMIN_GATE_COOKIE, admin_gate_token($path), ['expires' => time() + 400 * 86400, 'path' => base_path() . '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[ADMIN_GATE_COOKIE] = admin_gate_token($path);
}

/** The full private address (for the settings screen). */
function admin_gate_url(): string
{
    $p = admin_gate_path();
    return $p === '' ? '' : absolute_url($p);
}
