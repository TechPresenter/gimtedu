<?php
/**
 * /robots.txt (tools/router.php and .htaccess map it here).
 * Rules: Website CMS → SEO → Robots & sitemap (setting robots_txt); when empty, the recommended defaults from
 * seo_default_robots_txt(): allow the website, disallow /admin/, /api/, /portal/, /storage/, /search and filtered or
 * tracking query URLs, plus the Sitemap line (added to custom rules when missing). "Discourage search engines"
 * (Settings → Integrations → Search engines) serves "Disallow: /" instead.
 */
require __DIR__ . '/app/init.php';
require_once APP_ROOT . '/app/services/seo.php';

$txt = seo_robots_txt();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo $txt;
}
