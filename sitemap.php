<?php
/**
 * XML sitemaps (tools/router.php and .htaccess map the URLs here):
 *   /sitemap.xml                 sitemap index → the child sitemaps that currently have URLs
 *   /sitemap-pages.xml           routes + CMS pages      /sitemap-programs.xml   programs
 *   /sitemap-posts.xml           news + category pages   /sitemap-events.xml     events
 *   /sitemap-notices.xml         notices                 /sitemap-galleries.xml  photo albums
 *   /sitemap-images.xml          images of the pages above (setting sitemap_images)
 * Only indexable canonical URLs (cms_sitemap_urls(): no noindex / excluded / draft / landing / error / admin / portal
 * URLs), with lastmod. Cached in storage/cache/seo (rebuilt when content changes, at most hourly otherwise), ETag + 304.
 */
require __DIR__ . '/app/init.php';
require_once APP_ROOT . '/app/services/seo.php';

$name = strtolower(trim(api_str($_GET['type'] ?? null)));   // api_str: ?type[]=x is '' (no Array to string warning)
if ($name === '' || $name === 'index') {
    seo_send_xml(seo_sitemap_index_xml());
}
$xml = preg_match('/^[a-z]{3,20}$/', $name) ? seo_sitemap_xml($name) : null;
if ($xml === null) {
    http_response_code(404);
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<error>Unknown sitemap</error>' . "\n";
    exit;
}
seo_send_xml($xml);
