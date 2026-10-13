<?php
/**
 * GIMT Student & Faculty Portal - single page application shell (/portal/...).
 * Serves the compiled React app from portal/build (source: frontend/portal, build: npm run build:portal).
 * Every /portal/* route resolves here (portal/.htaccess, tools/router.php). Data comes from /api/portal/*.
 */
require __DIR__ . '/../app/init.php';

$manifestFile = __DIR__ . '/build/.vite/manifest.json';
$nonce = base64_encode(random_bytes(16));
$base = base_path();

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-cache, must-revalidate');

$entryJs = null;
$entryCss = [];
$preload = [];
if (is_file($manifestFile)) {
    $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
    $entry = $manifest['index.html'] ?? null;
    if ($entry) {
        $entryJs = $base . '/portal/build/' . $entry['file'];
        foreach ($entry['css'] ?? [] as $css) {
            $entryCss[] = $base . '/portal/build/' . $css;
        }
        foreach ($entry['imports'] ?? [] as $import) {
            if (!empty($manifest[$import]['file'])) {
                $preload[] = $base . '/portal/build/' . $manifest[$import]['file'];
            }
        }
    }
}

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$area = preg_match('#/portal/faculty(/|$)#', $path) ? 'Faculty Portal' : (preg_match('#/portal/student(/|$)#', $path) ? 'Student Portal' : 'Student & Faculty Portal');
$short = setting('institute_short_name', 'GIMT');
$boot = ['basePath' => $base, 'apiBase' => $base . '/api'];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0B2A5B">
  <meta name="description" content="<?= e($short . ' ' . $area . ' - attendance, results, fees, timetable and notices for ' . institute_name() . '.') ?>">
  <title><?= e($area . ' · ' . $short) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/images/favicon.svg')) ?>">
  <?php foreach ($entryCss as $css): ?><link rel="stylesheet" href="<?= e($css) ?>">
  <?php endforeach; ?>
  <?php foreach ($preload as $js): ?><link rel="modulepreload" href="<?= e($js) ?>">
  <?php endforeach; ?>
  <script nonce="<?= e($nonce) ?>">
    window.__GIMT__ = <?= js_json($boot) ?>;
    try { var t = localStorage.getItem('gimt.theme'); if (t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}
  </script>
</head>
<body>
  <div id="root"></div>
  <?php if ($entryJs): ?>
  <script type="module" src="<?= e($entryJs) ?>"></script>
  <?php else: ?>
  <div style="font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:24px;border:1px solid #EDE8E2;border-radius:16px;color:#0B2A5B;background:#fff">
    <h1 style="margin:0 0 8px;font-size:20px">Portal build not found</h1>
    <p style="margin:0;color:#4A5568">Build the portal once with <code>cd frontend &amp;&amp; npm install &amp;&amp; npm run build:portal</code>. The compiled files are written to <code>portal/build/</code> and are served without Node.js.</p>
  </div>
  <?php endif; ?>
  <noscript>The <?= e($short) ?> portal requires JavaScript.</noscript>
</body>
</html>
