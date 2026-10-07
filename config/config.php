<?php
/**
 * GIMT SmartCampus - application configuration.
 *
 * Values can be overridden (in order of precedence):
 *   1. Environment variables (GIMT_DB_HOST, GIMT_DB_NAME, GIMT_DB_USER, GIMT_DB_PASS, GIMT_ENV, GIMT_APP_KEY ...)
 *   2. config/local.php (created by the installer, never committed)
 *   3. The defaults below
 */

$defaults = [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'gimt_erp',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'      => 'GIMT SmartCampus Admin',
        'short'     => 'GIMT',
        // production | dev  (dev shows PHP errors and writes outgoing mail to storage/logs/mail.log)
        'env'       => 'production',
        // Absolute URL of the site root without trailing slash. Leave empty to auto-detect.
        'url'       => '',
        'timezone'  => 'Asia/Kolkata',
        // Secret used to sign remember-me cookies and verification hashes. The installer generates one.
        'key'       => 'change-this-key-on-install',
        'force_https' => false,
    ],
];

$local = [];
if (is_file(__DIR__ . '/local.php')) {
    $local = require __DIR__ . '/local.php';
}

$config = array_replace_recursive($defaults, is_array($local) ? $local : []);

$envMap = [
    'GIMT_DB_HOST' => ['db', 'host'],
    'GIMT_DB_PORT' => ['db', 'port'],
    'GIMT_DB_NAME' => ['db', 'name'],
    'GIMT_DB_USER' => ['db', 'user'],
    'GIMT_DB_PASS' => ['db', 'pass'],
    'GIMT_ENV'     => ['app', 'env'],
    'GIMT_URL'     => ['app', 'url'],
    'GIMT_APP_KEY' => ['app', 'key'],
];
foreach ($envMap as $env => [$section, $key]) {
    $value = getenv($env);
    if ($value !== false && $value !== '') {
        $config[$section][$key] = $value;
    }
}

return $config;
