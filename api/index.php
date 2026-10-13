<?php
/**
 * GIMT SmartCampus JSON API front controller.
 * All requests to /api/* are routed here (see .htaccess) or via /api/index.php?r=path.
 */
define('API_REQUEST', true);

require __DIR__ . '/../app/init.php';
require APP_ROOT . '/app/auth.php';
require APP_ROOT . '/app/registry.php';
require APP_ROOT . '/app/lookups.php';
require APP_ROOT . '/app/crud.php';
require APP_ROOT . '/app/api.php';

header('Cache-Control: no-store');

api_dispatch();
