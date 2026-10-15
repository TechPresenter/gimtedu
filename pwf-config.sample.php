<?php
/**
 * Prerit Welfare Foundation - PRIVATE configuration template.
 *
 * 1. Copy this file to  pwf-config.php
 * 2. Upload it ONE FOLDER ABOVE public_html (e.g. /home/u123456789/domains/your-domain/pwf-config.php)
 *    so it can never be downloaded from the web. (If your host does not allow that, put the same
 *    define() lines at the top of public_html/config.php instead.)
 * 3. Fill in the values below. Never share this file or paste its contents into chats or emails.
 *
 * Values defined here take priority over the defaults in public_html/config.php.
 */

// --- Site -------------------------------------------------------------------------------
define('APP_ENV', 'production');                                  // 'production' (live) or 'development'
define('BASE_URL', 'https://preritwelfarefoundation.org');        // your domain, https, no trailing slash

// --- Database (hPanel > Databases > Management) ------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'u000000000_prerit');                           // full name incl. the u123… prefix
define('DB_USER', 'u000000000_prerit');                           // full user name incl. the prefix
define('DB_PASS', 'CHANGE-ME');                                   // tip: letters and numbers only

// --- One-time installer -------------------------------------------------------------------
// A long random value (24+ characters). Open https://your-domain/install.php, enter this key and
// create the first administrator. After installation, set it back to '' and delete install.php.
define('INSTALL_KEY', 'CHANGE-ME-to-a-long-random-value');

// --- Email (hPanel > Emails: create info@your-domain first) -------------------------------
// Leave SMTP_HOST empty to use PHP mail().
define('MAIL_FROM', 'info@preritwelfarefoundation.org');
define('MAIL_FROM_NAME', 'Prerit Welfare Foundation');
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl');
define('SMTP_USER', 'info@preritwelfarefoundation.org');
define('SMTP_PASS', 'CHANGE-ME');

// --- Razorpay (only after the foundation's merchant account is approved) -------------------
// Start with TEST keys (rzp_test_...). Webhook URL: BASE_URL/payment-webhook.php
// Webhook events: payment.captured, payment.authorized, payment.failed, order.paid, refund.processed
define('RAZORPAY_KEY_ID', '');
define('RAZORPAY_KEY_SECRET', '');
define('RAZORPAY_WEBHOOK_SECRET', '');

// --- Only if the site is behind Cloudflare or another proxy -------------------------------
// define('TRUSTED_PROXY_HEADER', 'HTTP_CF_CONNECTING_IP');
