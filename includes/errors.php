<?php
/**
 * Public-website error handling (unit content_pages).
 *
 * Outside dev mode an uncaught exception or a fatal error on a public page discards the partial output, logs the error
 * (log_system → system_logs, plus the PHP error log) and renders the branded, dependency-light 500.php with HTTP 500.
 * In dev mode nothing is registered, so the detailed error from app/init.php stays visible.
 *
 * Meant to be required once by includes/config.php (right after app/init.php); the content_pages pages also require it
 * themselves. Safe to require more than once. Not used by the API (it has its own JSON error handling) or the CLI.
 */
if (!defined('APP_ROOT')) { // opened directly (/includes/errors.php) — not a page
    http_response_code(404);
    exit;
}
if (defined('SITE_ERRORS_LOADED')) {
    return;
}
define('SITE_ERRORS_LOADED', true);

/** Render the 500 page for $e (exception) or $fatal (error_get_last() array). Runs at most once per request. */
function site_error_render(?Throwable $e = null, ?array $fatal = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $message = $e ? get_class($e) . ': ' . $e->getMessage() : (string) ($fatal['message'] ?? 'Fatal error');
    $file = $e ? $e->getFile() : (string) ($fatal['file'] ?? '');
    $line = $e ? $e->getLine() : (int) ($fatal['line'] ?? 0);
    $url = (string) ($_SERVER['REQUEST_URI'] ?? '');
    error_log('[GIMT] Public page error: ' . $message . ' in ' . $file . ':' . $line . ' (' . $url . ')' . ($e ? "\n" . $e->getTraceAsString() : ''));
    try {
        if (function_exists('log_system')) {
            log_system('error', 'Public page error: ' . mb_substr($message, 0, 900), ['url' => mb_substr($url, 0, 500), 'file' => $file, 'line' => $line,
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET', 'ip' => function_exists('client_ip') ? client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '')]);
        }
    } catch (Throwable $ignored) {
    }
    // Contact details for the error page — only from settings when the database itself is not the problem.
    $GLOBALS['__site_error_contact'] = null;
    if (!($e instanceof PDOException) && function_exists('setting')) {
        try {
            $GLOBALS['__site_error_contact'] = ['phone' => (string) setting('phone', ''), 'email' => (string) setting('email', ''), 'name' => (string) setting('institute_name', '')];
        } catch (Throwable $ignored) {
        }
    }
    while (ob_get_level() > (defined('SITE_ERRORS_OB_LEVEL') ? SITE_ERRORS_OB_LEVEL : 0)) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
    }
    $GLOBALS['__site_error'] = $e ?? $fatal;
    include __DIR__ . '/../500.php';
}

if (PHP_SAPI !== 'cli' && !defined('API_REQUEST') && function_exists('is_dev') && !is_dev()) {
    define('SITE_ERRORS_OB_LEVEL', ob_get_level());
    ob_start(); // the page is buffered so a failure half-way can be replaced by the error page
    set_exception_handler(function (Throwable $e): void {
        site_error_render($e);
    });
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            site_error_render(null, $err);
        }
    });
}
