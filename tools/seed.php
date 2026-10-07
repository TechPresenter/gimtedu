<?php
/**
 * Run one or more seed files against the configured database (development helper).
 *
 *   php tools/seed.php 22_fees              runs database/seed/22_fees.php
 *   php tools/seed.php 30_library 31_hostel
 *
 * Unlike tools/install.php this never drops tables, so it is safe to use while other data exists.
 * Seeders should be re-runnable: clear the demo rows of the tables they own before inserting.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require __DIR__ . '/../app/init.php';
require APP_ROOT . '/app/registry.php';
require APP_ROOT . '/app/installer.php';

$names = array_slice($argv, 1);
if (!$names) {
    fwrite(STDERR, "Usage: php tools/seed.php <seed-name> [...]\n");
    exit(1);
}
foreach ($names as $name) {
    $file = APP_ROOT . '/database/seed/' . basename($name, '.php') . '.php';
    if (!is_file($file)) {
        fwrite(STDERR, "Seed not found: $file\n");
        exit(1);
    }
    $fn = require $file;
    if (!is_callable($fn)) {
        fwrite(STDERR, "Seed file does not return a callable: $file\n");
        exit(1);
    }
    $start = microtime(true);
    $fn(['demo' => true, 'admin_email' => 'admin@gimt.ac.in', 'admin_password' => 'Admin@12345', 'admin_name' => 'Administrator']);
    fwrite(STDOUT, sprintf("  ✓ %s (%.1fs)\n", basename($file), microtime(true) - $start));
}
