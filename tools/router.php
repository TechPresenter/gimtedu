<?php
/**
 * Router for PHP's built-in server (development only) - mirrors the .htaccess rewrites.
 *   php -S 127.0.0.1:8000 tools/router.php
 *
 * Public URL normalisation (GET/HEAD, outside /admin /api /assets /portal /storage), all 301:
 *   /about.php → /about, /index.php → /          one URL per page
 *   /about/    → /about                          no trailing slash (except the home page)
 *   /About     → admin redirect rule, else /about   lower case (certificate / marksheet numbers keep their case)
 * Unknown URLs end in 404.php, which consults the redirect manager (rules, 410 Gone) before answering 404.
 */
$root = dirname(__DIR__);
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$rawPath = explode('?', $requestUri, 2)[0];
$uri = urldecode(parse_url($requestUri, PHP_URL_PATH) ?: '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';

// Block sensitive paths (same rules as .htaccess)
if (preg_match('#^/(config|database|storage|tools|app|frontend|includes|node_modules|docs|\.git)(/|$)#', $uri)
    || preg_match('#(^|/)\.(?!well-known)#', $uri) || preg_match('#\.(sql|md|lock|log|sh|cjs|json)$#i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
// Never execute scripts inside uploads
if (str_starts_with($uri, '/assets/uploads/') && preg_match('/\.(php\d?|phtml|phar)$/i', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}
// Website JavaScript error reporter (contract URL) → /api/integrations/client-error
if ($uri === '/api/public/client-error') {
    $_GET['r'] = 'integrations/client-error';
}
// API
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $root . '/api/index.php';
    return true;
}
// Pretty marksheet verification URL (QR codes) → GET /api/marksheets/verify/{no}
if (preg_match('#^/verify-marksheet/([A-Za-z0-9\-]+)/?$#', $uri, $m)) {
    $_GET['r'] = 'marksheets/verify/' . $m[1];
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $root . '/api/index.php';
    return true;
}

// Public URL normalisation (301)
if (in_array($method, ['GET', 'HEAD'], true) && !preg_match('#^/(admin|api|assets|portal|storage)(/|$)#i', $uri)) {
    $to = $rawPath;
    if (preg_match('#^/([a-z0-9\-]+)\.php$#i', $uri, $m) && is_file($root . '/' . strtolower($m[1]) . '.php') && !in_array(strtolower($m[1]), ['sitemap', 'robots'], true)) {
        $to = strtolower($m[1]) === 'index' ? '/' : '/' . $m[1];
    }
    if ($to !== '/' && str_ends_with($to, '/')) {
        $to = rtrim($to, '/') ?: '/';
    }
    if (preg_match('/[A-Z]/', $uri) && !preg_match('#^/verify-(certificate|marksheet)/#i', $uri)) {
        // an admin rule for the mixed-case URL wins; otherwise the lower-case URL (one hop for /Old-Page/ too)
        require_once $root . '/app/init.php';
        require_once $root . '/app/services/seo.php';
        $rule = seo_redirect_match(seo_site_path($to), (string) ($_SERVER['QUERY_STRING'] ?? ''));
        if ($rule && (int) $rule['status_code'] !== 410 && ($loc = seo_redirect_location($rule, (string) ($_SERVER['QUERY_STRING'] ?? '')))) {
            seo_redirect_hit((int) $rule['id']);
            seo_send_redirect($loc, (int) $rule['status_code']);
        }
        $lower = strtolower($to);
        // upper case only inside %xx escapes (/%41bout): lower-case the decoded path instead
        $to = $lower !== $to ? $lower : seo_encode_path(strtolower(rawurldecode($to)));
    }
    if ($to !== $rawPath) {
        // never a protocol-relative target: "//evil.example/X" or "/\evil.example/X" must stay on this site
        $to = '/' . ltrim($to, "/\\ \t");
        header('Location: ' . str_replace(["\r", "\n"], '', $to . $qs), true, 301);
        return true;
    }
}

// Static files & real PHP files
$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    if (str_ends_with($file, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $uri;
        chdir(dirname($file));
        require $file;
        return true;
    }
    return false; // let the built-in server serve static assets
}
// Admin SPA: every non-file route under /admin is handled by admin/index.php
if ($uri === '/admin' || str_starts_with($uri, '/admin/')) {
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require $root . '/admin/index.php';
    return true;
}
// Student & Faculty portal SPA: every non-file route under /portal is handled by portal/index.php
if ($uri === '/portal' || str_starts_with($uri, '/portal/')) {
    $_SERVER['SCRIPT_NAME'] = '/portal/index.php';
    require $root . '/portal/index.php';
    return true;
}
// Public website pretty URLs
$routes = [
    '#^/verify-certificate/([A-Za-z0-9\-/]+)/?$#' => ['verify-certificate.php', 'no'],
    '#^/programs/([a-z0-9\-]+)/?$#' => ['program.php', 'slug'],
    '#^/admissions-open/([a-z0-9\-]+)/?$#' => ['admissions-open.php', 'program'],
    '#^/blog/([a-z0-9\-]+)/?$#' => ['post.php', 'slug'],
    '#^/events/([a-z0-9\-]+)/?$#' => ['event.php', 'slug'],
    '#^/notices/([a-z0-9\-]+)/?$#' => ['notice.php', 'slug'],
    '#^/gallery/([a-z0-9\-]+)/?$#' => ['gallery.php', 'slug'],
    '#^/page/([a-z0-9\-]+)/?$#' => ['page.php', 'slug'],
];
// SEO files: sitemap index, child sitemaps, robots.txt
if ($uri === '/sitemap.xml') {
    $uri = '/sitemap.php';
} elseif (preg_match('#^/sitemap-([a-z]+)\.xml$#', $uri, $m)) {
    $_GET['type'] = $m[1];
    $uri = '/sitemap.php';
} elseif ($uri === '/robots.txt') {
    $uri = '/robots.php';
}
foreach ($routes as $re => [$script, $param]) {
    if (preg_match($re, $uri, $m)) {
        $_GET[$param] = $m[1];
        $uri = '/' . $script;
        break;
    }
}
$candidate = $uri === '/' ? '/index.php' : (str_ends_with($uri, '.php') ? $uri : rtrim($uri, '/') . '.php');
if (is_file($root . $candidate)) {
    $_SERVER['SCRIPT_NAME'] = $candidate;
    chdir($root);
    require $root . $candidate;
    return true;
}
// CMS page by slug fallback
if (preg_match('#^/([a-z0-9\-]+)/?$#', $uri, $m) && is_file($root . '/page.php')) {
    $_GET['slug'] = $m[1];
    $_SERVER['SCRIPT_NAME'] = '/page.php';
    require $root . '/page.php';
    return true;
}
// Unknown URL: 404.php consults the redirect manager first (admin rules, 410 Gone) and logs the 404.
http_response_code(404);
if (is_file($root . '/404.php')) {
    require $root . '/404.php';
} else {
    echo 'Not found';
}
return true;
