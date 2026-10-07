<?php
/**
 * Core bootstrap shared by the admin panel, the public website, AJAX endpoints and CLI tools.
 * Loads configuration, error handling, the database layer and all shared helper libraries.
 * It does NOT start a session - see auth.php (admin/API) and includes/config.php (website).
 */

if (defined('APP_ROOT')) {
    return;
}

define('APP_ROOT', dirname(__DIR__));
define('ADMIN_ROOT', APP_ROOT . '/admin');
define('STORAGE_PATH', APP_ROOT . '/storage');
define('UPLOAD_PATH', APP_ROOT . '/assets/uploads');
define('APP_VERSION', '1.0.0');

$GLOBALS['__app_config'] = require APP_ROOT . '/config/config.php';

function app_config(?string $section = null)
{
    $config = $GLOBALS['__app_config'];
    return $section === null ? $config : ($config[$section] ?? []);
}

function is_dev(): bool
{
    return (app_config('app')['env'] ?? 'production') === 'dev';
}

date_default_timezone_set(app_config('app')['timezone'] ?? 'Asia/Kolkata');
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', is_dev() ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(STORAGE_PATH . '/logs')) {
    @mkdir(STORAGE_PATH . '/logs', 0775, true);
}
ini_set('error_log', STORAGE_PATH . '/logs/php-errors.log');

set_exception_handler(function (Throwable $e) {
    error_log('[GIMT] Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e . PHP_EOL);
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    $isAjax = defined('API_REQUEST') || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'message' => is_dev() ? $e->getMessage() : 'Something went wrong. Please try again.']);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><div style="font-family:system-ui;padding:40px;color:#0B2A5B;max-width:720px">'
        . '<h1 style="margin:0 0 8px">Something went wrong</h1><p>An unexpected error occurred. The error has been logged.</p>'
        . (is_dev() ? '<pre style="white-space:pre-wrap;background:#f1f5f9;padding:16px;border-radius:8px;font-size:12px">' . htmlspecialchars((string) $e) . '</pre>' : '')
        . '</div>';
});

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/components.php';
require_once __DIR__ . '/upload.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/xlsx.php';

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
    if (!empty(app_config('app')['force_https']) && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
        header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }
    if (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
