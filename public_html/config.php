<?php
/**
 * Prerit Welfare Foundation - site configuration.
 *
 * Edit the values below after uploading the site.
 *
 * SECURITY TIP: on shared hosting you can keep your real passwords outside
 * public_html. Create the file  ../pwf-config.php  (one folder ABOVE
 * public_html) and define any of the constants below in it, e.g.
 *
 *     <?php
 *     define('DB_PASS', 'your-real-password');
 *     define('RAZORPAY_KEY_SECRET', 'your-secret');
 *
 * Values in that file take priority over the defaults here.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$pwfPrivateConfig = dirname(__DIR__) . '/pwf-config.php';
if (is_file($pwfPrivateConfig)) {
    require $pwfPrivateConfig;
}

// ---------------------------------------------------------------------
// General
// ---------------------------------------------------------------------
defined('APP_ENV')   || define('APP_ENV', 'production');          // 'production' or 'development'
defined('BASE_URL')  || define('BASE_URL', 'https://preritwelfarefoundation.org'); // no trailing slash
defined('TIMEZONE')  || define('TIMEZONE', 'Asia/Kolkata');

// ---------------------------------------------------------------------
// Database (MySQL / MariaDB)
// ---------------------------------------------------------------------
defined('DB_HOST') || define('DB_HOST', 'localhost');
defined('DB_PORT') || define('DB_PORT', 3306);
defined('DB_NAME') || define('DB_NAME', 'your_database_name');
defined('DB_USER') || define('DB_USER', 'your_database_user');
defined('DB_PASS') || define('DB_PASS', 'your_database_password');

// ---------------------------------------------------------------------
// Email. If SMTP_HOST is empty, PHP mail() is used.
// Hostinger example: smtp.hostinger.com, port 465, encryption 'ssl'
// ---------------------------------------------------------------------
defined('MAIL_ENABLED')    || define('MAIL_ENABLED', true);
defined('MAIL_FROM')       || define('MAIL_FROM', 'info@preritwelfarefoundation.org');
defined('MAIL_FROM_NAME')  || define('MAIL_FROM_NAME', 'Prerit Welfare Foundation');
defined('SMTP_HOST')       || define('SMTP_HOST', '');
defined('SMTP_PORT')       || define('SMTP_PORT', 465);
defined('SMTP_ENCRYPTION') || define('SMTP_ENCRYPTION', 'ssl');   // 'ssl', 'tls' or ''
defined('SMTP_USER')       || define('SMTP_USER', '');
defined('SMTP_PASS')       || define('SMTP_PASS', '');

// ---------------------------------------------------------------------
// Payment gateway: Razorpay (https://razorpay.com)
// Use TEST keys (rzp_test_...) until you are ready to go live.
// Integrate only after the foundation's merchant account is approved.
// Webhook URL to configure in the Razorpay dashboard:
//     BASE_URL/payment-webhook.php
// Events: payment.captured, payment.authorized, payment.failed, order.paid, refund.processed
// ---------------------------------------------------------------------
defined('RAZORPAY_KEY_ID')         || define('RAZORPAY_KEY_ID', '');
defined('RAZORPAY_KEY_SECRET')     || define('RAZORPAY_KEY_SECRET', '');
defined('RAZORPAY_WEBHOOK_SECRET') || define('RAZORPAY_WEBHOOK_SECRET', '');
defined('RAZORPAY_API_BASE')       || define('RAZORPAY_API_BASE', 'https://api.razorpay.com/v1');

// ---------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------
defined('SESSION_NAME')          || define('SESSION_NAME', 'pwf_session');
defined('ADMIN_SESSION_TIMEOUT') || define('ADMIN_SESSION_TIMEOUT', 3600);   // seconds of inactivity
defined('LOGIN_MAX_ATTEMPTS')    || define('LOGIN_MAX_ATTEMPTS', 5);
defined('LOGIN_LOCK_MINUTES')    || define('LOGIN_LOCK_MINUTES', 15);
defined('MAX_UPLOAD_MB')         || define('MAX_UPLOAD_MB', 5);
// Only if the site is behind Cloudflare or another proxy: the $_SERVER key that
// holds the visitor's real IP, e.g. 'HTTP_CF_CONNECTING_IP'. Leave empty otherwise.
defined('TRUSTED_PROXY_HEADER')  || define('TRUSTED_PROXY_HEADER', '');
// One-time installer key: set a long random value, open /install.php, enter it to create the first
// administrator. After installation the installer locks itself; you can then clear this value.
defined('INSTALL_KEY')           || define('INSTALL_KEY', '');

// ---------------------------------------------------------------------
// Paths (normally no need to change)
// ---------------------------------------------------------------------
define('ROOT_PATH', __DIR__);
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
