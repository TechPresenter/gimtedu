<?php
/**
 * Copy this file to config/local.php (or run the web installer at /install/) and adjust.
 * config/local.php is ignored by git.
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
        'env' => 'production',
        'url' => 'https://www.gimt.ac.in',
        'key' => 'generate-a-64-character-random-string',
        'force_https' => true,
    ],
];
