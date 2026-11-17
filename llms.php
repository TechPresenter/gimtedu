<?php
/**
 * /llms.txt and /llms-full.txt (llmstxt.org format) — tools/router.php and .htaccess map both URLs here
 * (/llms-full.txt with ?full=1). Generated from the database by seo_llms_txt() in app/services/seo_ai.php (programmes,
 * admissions / contact / policy pages, FAQs, contact settings), cached by a content fingerprint. Setting
 * llms_txt_enabled = 0 (or "Discourage search engines") → 404; llms_txt_custom replaces /llms.txt.
 * Any other URL that reaches this script (/llms, /llms.php) 301s to the real file, so there is one URL per document.
 */
require __DIR__ . '/app/init.php';
require_once APP_ROOT . '/app/services/seo.php';
require_once APP_ROOT . '/app/services/seo_ai.php';

seo_host_redirect();
$path = seo_site_path();
$full = $path === '/llms-full.txt' || ($path !== '/llms.txt' && api_str($_GET['full'] ?? null) === '1');
$canonicalPath = $full ? '/llms-full.txt' : '/llms.txt';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($path !== $canonicalPath) {
    seo_send_redirect(seo_path_url($canonicalPath), 301);
}
seo_bot_hit($canonicalPath);
if (!seo_llms_enabled()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    echo $method === 'HEAD' ? '' : "Not found\n";
    exit;
}
$txt = seo_llms_txt($full);
$etag = '"' . md5($txt) . '"';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);
header('Link: <' . absolute_url('sitemap.xml') . '>; rel="sitemap"');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
if ($method !== 'HEAD') {
    echo $txt;
}
