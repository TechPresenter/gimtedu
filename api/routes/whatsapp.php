<?php
/**
 * WhatsApp Automation & Student Communication (Interakt) — every /api/whatsapp/* route.
 * The routes are split by feature into api/routes/whatsapp/*.php (loaded in name order); the domain logic lives in
 * app/services/whatsapp.php (core: contacts, send queue, Interakt client, webhooks, event bus, worker) and the
 * feature services app/services/whatsapp_*.php, which the core service loads.
 */
require_once APP_ROOT . '/app/services/whatsapp.php';

$waRouteFiles = glob(__DIR__ . '/whatsapp/*.php') ?: [];
sort($waRouteFiles);
foreach ($waRouteFiles as $waRouteFile) {
    require $waRouteFile;
}
unset($waRouteFiles, $waRouteFile);
