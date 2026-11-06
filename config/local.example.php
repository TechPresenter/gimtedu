<?php
/**
 * Copy this file to config/local.php and adjust (fresh database: `php tools/install.php` from the command line - it
 * creates every table, so never run it against a live database).
 * config/local.php is ignored by git and must never be committed or exposed by the web server
 * (.htaccess denies /config; on nginx add `location ^~ /config/ { deny all; }`).
 *
 * Every value below can also come from an environment variable, which wins over this file:
 *   GIMT_DB_HOST, GIMT_DB_PORT, GIMT_DB_NAME, GIMT_DB_USER, GIMT_DB_PASS, GIMT_ENV, GIMT_URL, GIMT_APP_KEY
 *
 * Integration secrets are read from Admin > Settings or, preferably on a server, from environment variables (they are
 * never sent to the browser):
 *   GIMT_CAPTCHA_SITE_KEY / GIMT_CAPTCHA_SECRET      reCAPTCHA v3, Cloudflare Turnstile or hCaptcha for public forms
 *   GIMT_GA4_ID, GIMT_GTM_ID, GIMT_META_PIXEL_ID     analytics / tags (loaded only after cookie consent)
 *   GIMT_GSC_VERIFICATION, GIMT_BING_VERIFICATION    search-console site verification meta tags
 *   GIMT_GOOGLE_MAPS_API_KEY                         Maps Embed API key restricted to the site's domain (optional)
 *   GIMT_SENTRY_DSN                                  error monitoring (optional)
 *   GIMT_OPENAI_API_KEY / GIMT_ANTHROPIC_API_KEY / GIMT_GEMINI_API_KEY   AI chatbot provider key (optional)
 *   GIMT_CA_BUNDLE                                   path to a CA bundle when PHP cannot verify outbound HTTPS
 * SMTP, SMS / WhatsApp gateway and payment-gateway credentials are entered in Admin > Settings (stored server-side,
 * never shown again after saving). Bank / UPI details for fee payments: Admin > Settings > Payment > Bank & UPI details.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'gimt_erp',
        'user' => 'gimt',
        'pass' => 'secret',
    ],
    'app' => [
        // production hides PHP errors and sends real mail; dev writes outgoing mail to storage/logs/mail.log
        'env' => 'production',
        // canonical site URL (no trailing slash): drives canonical tags, sitemaps, QR codes and links in emails
        'url' => 'https://www.gimt.ac.in',
        // 64 random characters, e.g. php -r "echo bin2hex(random_bytes(32));" - signs remember-me cookies and
        // certificate / marksheet verification codes; changing it invalidates existing QR signatures
        'key' => 'generate-a-64-character-random-string',
        'force_https' => true,
        // development only: origin of `npm run dev:site` (Vite on :5174) to serve the website islands with HMR
        // 'site_dev_server' => 'http://127.0.0.1:5174',
    ],
];
