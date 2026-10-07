<?php
/**
 * Router for PHP's built-in server (development only) - mirrors the .htaccess rewrites.
 *   php -S 127.0.0.1:8000 tools/router.php
 */
$root = dirname(__DIR__);
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');

// Block sensitive paths
if (preg_match('#^/(config|database|storage|tools|app|frontend|node_modules|\.git)(/|$)#', $uri) || preg_match('#/\.(env|htaccess|git)#', $uri)) {
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
// API
if ($uri === '/api' || str_starts_with($uri, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $root . '/api/index.php';
    return true;
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
// Public website pretty URLs
$routes = [
    '#^/verify-certificate/([A-Za-z0-9\-/]+)/?$#' => ['verify-certificate.php', 'no'],
    '#^/programs/([a-z0-9\-]+)/?$#' => ['program.php', 'slug'],
    '#^/blog/([a-z0-9\-]+)/?$#' => ['post.php', 'slug'],
    '#^/events/([a-z0-9\-]+)/?$#' => ['event.php', 'slug'],
    '#^/notices/([a-z0-9\-]+)/?$#' => ['notice.php', 'slug'],
    '#^/gallery/([a-z0-9\-]+)/?$#' => ['gallery.php', 'slug'],
    '#^/page/([a-z0-9\-]+)/?$#' => ['page.php', 'slug'],
];
if ($uri === '/sitemap.xml') {
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
http_response_code(404);
if (is_file($root . '/404.php')) {
    require $root . '/404.php';
} else {
    echo 'Not found';
}
return true;
