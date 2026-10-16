<?php
/**
 * Bootstrap: configuration, database, security headers, session and all helper modules.
 * Every entry point (index.php, admin pages, webhook, installer) starts with:
 *     require_once __DIR__ . '/functions.php';
 */

define('PWF_APP', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/mail.php';

date_default_timezone_set(TIMEZONE);
mb_internal_encoding('UTF-8');
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

// ---------------------------------------------------------------------
// Session (hardened cookie, strict mode)
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !defined('PWF_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------
// Helper modules (core first; feature modules may use everything above them)
// ---------------------------------------------------------------------
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/secrets.php';
require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/cms.php';
require_once __DIR__ . '/includes/forms.php';
require_once __DIR__ . '/includes/widgets.php';
require_once __DIR__ . '/includes/programs.php';
require_once __DIR__ . '/includes/campaigns.php';
require_once __DIR__ . '/includes/recruitment.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/info.php';
require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/member.php';
require_once __DIR__ . '/includes/home.php';
require_once __DIR__ . '/includes/gateways.php';
require_once __DIR__ . '/includes/assistant.php';
require_once __DIR__ . '/includes/analytics.php';
require_once __DIR__ . '/includes/programhub.php';
require_once __DIR__ . '/includes/letters.php';
// SEO modules (Upgrade 2). Every call into them is guarded with function_exists(), so a missing file only switches
// that feature off: core (head tags, graph, robots, canonicals), sitemaps/robots.txt/URL normalisation, images,
// admin, tags/consent/verification, E-E-A-T, content migrations.
foreach (['seo.php', 'seo-sitemap.php', 'seo-images.php', 'seo-admin.php', 'seo-tags.php', 'seo-eeat.php', 'seo-content.php'] as $pwfSeoFile) {
    if (is_file(__DIR__ . '/includes/' . $pwfSeoFile)) {
        require_once __DIR__ . '/includes/' . $pwfSeoFile;
    }
}
unset($pwfSeoFile);

// ---------------------------------------------------------------------
// An install.php from an older package (it does not define PWF_INSTALLER_VERSION) printed server and database details
// even after installation. Upgrade packages do not replace install.php, so refuse such a file once the site is installed.
// ---------------------------------------------------------------------
if (defined('PWF_INSTALLER') && !defined('PWF_INSTALLER_VERSION') && PHP_SAPI !== 'cli') {
    $pwfInstalled = is_file(__DIR__ . '/install.lock');
    if (!$pwfInstalled) {
        try {
            $pwfInstalled = (int) db_value("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'administrator'") > 0;
        } catch (Throwable $e) {
            $pwfInstalled = false;
        }
    }
    if ($pwfInstalled) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        exit("The website is already installed. This install.php is from an older package: please delete it from the server.\n");
    }
    unset($pwfInstalled);
}

// ---------------------------------------------------------------------
// Data-safe upgrades: pending migrations (includes/migrations.php and module files) run once, automatically,
// on the first request after new files are uploaded. The installer runs them itself.
// ---------------------------------------------------------------------
if (!defined('PWF_INSTALLER')) {
    try {
        run_pending_migrations();
    } catch (Throwable $e) {
        error_log('Migrations: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Security headers
// ---------------------------------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $gaHosts = '';
    if (!defined('PWF_INSTALLER')) {
        try {
            // SEO tags module: Google hosts only when a valid GA4 or GTM id is configured (one tag mode at a time)
            $gaHosts = function_exists('seo_csp_google_hosts')
                ? (seo_csp_google_hosts() ? seo_csp_google_sources()['script-src'] : '')
                : (setting('ga_measurement_id') !== '' ? ' https://www.googletagmanager.com' : '');
        } catch (Throwable $e) {
            $gaHosts = '';
        }
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(self "https://checkout.razorpay.com" "https://api.razorpay.com" "https://sdk.cashfree.com" "https://api.cashfree.com" "https://sandbox.cashfree.com")');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' https://checkout.razorpay.com https://*.razorpay.com https://sdk.cashfree.com" . $gaHosts . "; "
        . "style-src 'self' 'unsafe-inline' https://sdk.cashfree.com; img-src 'self' data: blob: https:; font-src 'self'; "
        . "connect-src 'self' https://*.razorpay.com https://*.cashfree.com" . ($gaHosts ? ' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com' : '') . "; "
        . "frame-src 'self' https://*.razorpay.com https://*.cashfree.com https://www.google.com https://maps.google.com https://www.youtube-nocookie.com https://www.youtube.com; "
        . "form-action 'self' https://*.razorpay.com https://*.cashfree.com; base-uri 'self'; object-src 'none'; frame-ancestors 'self'" . (is_https() ? '; upgrade-insecure-requests' : ''));
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
