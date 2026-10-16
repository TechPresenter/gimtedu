<?php
/**
 * Front controller. Every clean URL (/about/, /campaign/<slug>/, /dashboard/donations/ ...) arrives here
 * through .htaccess. The request is resolved to a row in `pages` and rendered by templates/<template>.php.
 *
 * Special routes: /sitemap.xml, /robots.txt, /api/help/, /api/<name>/ (names listed in PUBLIC_API below)
 * Old addresses (*.php, WordPress paths) are answered with 301 redirects from the `redirects` table.
 */

require_once __DIR__ . '/functions.php';

$path = route_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ---------------------------------------------------------------------
// Special routes
// ---------------------------------------------------------------------
// SEO sitemaps module: sitemap index + child sitemaps, robots.txt and the IndexNow key file (it exits when it answers).
// The two blocks below are the fallback for a site without includes/seo-sitemap.php.
if (function_exists('seo_sitemap_route')) {
    seo_sitemap_route($path);
}
if ($path === 'sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach (sitemap_entries() as $entry) {
        echo '  <url><loc>' . e($entry['loc']) . '</loc>' . ($entry['lastmod'] ? '<lastmod>' . e(date('Y-m-d', strtotime($entry['lastmod']))) . '</lastmod>' : '') . "</url>\n";
    }
    echo '</urlset>';
    exit;
}
if ($path === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    if (setting('seo_noindex_site') === '1') {
        echo "User-agent: *\nDisallow: /\n";
        exit;
    }
    // Sign-in, registration and password pages send <meta name="robots" content="noindex">: they must stay
    // crawlable, or search engines never see that tag and may list the bare URLs.
    echo "User-agent: *\nDisallow: /admin/\nDisallow: /dashboard/\nDisallow: /donation-receipt/\nDisallow: /donation-success/\nDisallow: /donation-failed/\n"
        . "Disallow: /api/\nDisallow: /uploads/documents/\nDisallow: /uploads/certificates/\n";
    $extra = trim(str_replace("\r", '', setting('robots_txt_extra')));
    if ($extra !== '') {
        echo $extra . "\n";
    }
    echo "\nSitemap: " . abs_url('sitemap.xml') . "\n";
    exit;
}
if ($path === 'api/help') {
    help_api();
}
// Module JSON endpoints: only the names listed in PUBLIC_API are public (a helper that merely happens to be called
// api_something is never reachable). The handler sends its own JSON and exits; other methods get 405 here.
const PUBLIC_API = [
    'chat'    => ['fn' => 'api_chat', 'methods' => ['GET', 'POST']],   // includes/assistant.php
    'collect' => ['fn' => 'api_collect', 'methods' => ['POST']],       // includes/analytics.php
];
if (preg_match('#^api/([a-z][a-z0-9\-]{1,30})$#', $path, $apiMatch)) {
    $api = PUBLIC_API[$apiMatch[1]] ?? null;
    if ($api && function_exists($api['fn'])) {
        $apiMethods = in_array('GET', $api['methods'], true) ? array_merge($api['methods'], ['HEAD']) : $api['methods'];
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $apiMethods, true)) {
            http_response_code(405);
            header('Allow: ' . implode(', ', $apiMethods));
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'error' => 'method']);
            exit;
        }
        call_user_func($api['fn']);
        exit;
    }
    http_response_code(404);
    if (function_exists('seo_x_robots')) {
        seo_x_robots();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

if (!is_file(ROOT_PATH . '/install.lock') && !db_value("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'administrator'")) {
    // Fresh installation without an administrator yet: point the owner to the installer.
    if (is_file(ROOT_PATH . '/install.php')) {
        header('Location: ' . url('/install.php'), true, 302);
        exit;
    }
}

// ---------------------------------------------------------------------
// Canonical form: lower-case, trailing slash (GET only)
// ---------------------------------------------------------------------
// SEO: 301 for repeated slashes (//about/ -> /about/) and similar variants; never touches POST (exits when it redirects)
if (function_exists('seo_normalise_request')) {
    seo_normalise_request($path, (string) ($_SERVER['REQUEST_URI'] ?? '/'), $method);
}
$rawPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');

// Old or changed addresses -> permanent redirect
if ($path !== '' && ($redirect = find_redirect($path))) {
    if (function_exists('seo_normalise_redirect_response')) {
        seo_normalise_redirect_response($redirect, $query);   // 410 Gone, or the 301/302 (exits)
    }
    $target = preg_match('#^https?://#i', $redirect['target_url']) ? $redirect['target_url'] : url($redirect['target_url']);
    header('Location: ' . $target . ($query !== '' && strpos($target, '?') === false ? '?' . $query : ''), true, (int) $redirect['status_code'] === 302 ? 302 : 301);
    exit;
}

// The homepage has one address: /home/, /Home and /home redirect to /
if (($method === 'GET' || $method === 'HEAD') && strtolower($path) === 'home') {
    header('Location: ' . url('/') . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}

if ($method === 'GET' && $path !== '' && (substr($rawPath, -1) !== '/' || $path !== strtolower($path)) && !preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
    // only safe slugs are canonicalised: a decoded backslash, tab or other character must never reach the Location header
    if (preg_match('#^[a-z0-9][a-z0-9\-_/]*$#i', $path) && ($path === strtolower($path) || resolve_route(strtolower($path)))) {
        header('Location: ' . url('/' . strtolower($path) . '/') . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
}

// ---------------------------------------------------------------------
// Resolve and render
// ---------------------------------------------------------------------
$route = resolve_route($path);
if (!$route) {
    not_found();
}
$GLOBALS['route'] = $route;
$page = $route['page'];

$template = preg_replace('/[^a-z0-9_]/', '', (string) $page['template']);
$file = ROOT_PATH . '/templates/' . $template . '.php';
if (!is_file($file)) {
    $file = ROOT_PATH . '/templates/default.php';
}
require $file;
