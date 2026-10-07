<?php
/**
 * CLI installer.
 *
 *   php tools/install.php --fresh --demo
 *   GIMT_DB_NAME=gimt_test php tools/install.php --fresh --demo
 *
 * Options:
 *   --fresh            drop all existing tables first
 *   --demo             load demo data (students, fees, attendance, website content ...)
 *   --no-seed          schema only
 *   --admin-email=...  --admin-password=...  --admin-name=...
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../app/init.php';
require APP_ROOT . '/app/registry.php';
require APP_ROOT . '/app/installer.php';

$opts = getopt('', ['fresh', 'demo', 'no-seed', 'admin-email:', 'admin-password:', 'admin-name:']);
$log = function (string $m) {
    fwrite(STDOUT, '  ✓ ' . $m . PHP_EOL);
};

$db = app_config('db');
fwrite(STDOUT, "GIMT SmartCampus installer → database `{$db['name']}` on {$db['host']}\n");
$start = microtime(true);

if (isset($opts['fresh'])) {
    install_drop_all_tables();
    $log('dropped existing tables');
}
$n = install_schema($log);
$log("$n schema statements executed");

if (!isset($opts['no-seed'])) {
    install_seed([
        'demo' => isset($opts['demo']),
        'admin_email' => $opts['admin-email'] ?? 'admin@gimt.ac.in',
        'admin_password' => $opts['admin-password'] ?? 'Admin@12345',
        'admin_name' => $opts['admin-name'] ?? 'Administrator',
    ], $log);
}

fwrite(STDOUT, sprintf("Done in %.1fs\n", microtime(true) - $start));
