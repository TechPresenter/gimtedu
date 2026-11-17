<?php
/**
 * IndexNow key file: /{key}.txt (tools/router.php and .htaccess map /<8–128 chars>.txt here when no such file exists).
 * Answers only the configured key while IndexNow is switched on (Settings → Integrations → IndexNow): UTF-8 text/plain
 * whose content is the key. Any other request gets the normal 404 page (redirect rules, 410 and the 404 log apply).
 * See app/services/seo_indexnow.php.
 */
require __DIR__ . '/app/init.php';
require_once APP_ROOT . '/app/services/seo.php';
require_once APP_ROOT . '/app/services/seo_indexnow.php';

seo_host_redirect();
$path = seo_site_path();
$key = seo_indexnow_key();
$ok = seo_indexnow_enabled() && $key !== '' && preg_match('#^/([A-Za-z0-9\-]{8,128})\.txt$#', $path, $m) && hash_equals($key, $m[1]);
if (!$ok) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}
seo_bot_hit($path);
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=300');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo $key;
}
