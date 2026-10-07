<?php
/**
 * GIMT SmartCampus Admin - single page application shell.
 * Serves the compiled React app from admin/build (see frontend/). All /admin/* routes resolve here.
 */
require __DIR__ . '/../app/init.php';

$manifestFile = __DIR__ . '/build/.vite/manifest.json';
$nonce = base64_encode(random_bytes(16));
$base = base_path();

header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com; object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'");
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-cache, must-revalidate');

$entryJs = null;
$entryCss = [];
if (is_file($manifestFile)) {
    $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
    $entry = $manifest['index.html'] ?? null;
    if ($entry) {
        $entryJs = $base . '/admin/build/' . $entry['file'];
        foreach ($entry['css'] ?? [] as $css) {
            $entryCss[] = $base . '/admin/build/' . $css;
        }
    }
}
$boot = ['basePath' => $base, 'apiBase' => $base . '/api'];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0B2A5B">
  <title>GIMT SmartCampus Admin</title>
  <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/images/favicon.svg')) ?>">
  <?php foreach ($entryCss as $css): ?><link rel="stylesheet" href="<?= e($css) ?>">
  <?php endforeach; ?>
  <script nonce="<?= e($nonce) ?>">
    window.__GIMT__ = <?= js_json($boot) ?>;
    try { var t = localStorage.getItem('gimt.theme'); if (t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches)) document.documentElement.classList.add('dark'); } catch (e) {}
  </script>
</head>
<body class="bg-slate-50 text-slate-800 antialiased">
  <div id="root"></div>
  <?php if ($entryJs): ?>
  <script type="module" src="<?= e($entryJs) ?>"></script>
  <?php else: ?>
  <div style="font-family:system-ui;max-width:640px;margin:80px auto;padding:24px;border:1px solid #e2e8f0;border-radius:16px;color:#0B2A5B">
    <h1 style="margin:0 0 8px;font-size:20px">Admin build not found</h1>
    <p style="margin:0;color:#475569">Build the admin interface once with <code>cd frontend &amp;&amp; npm install &amp;&amp; npm run build</code>. The compiled files are written to <code>admin/build/</code> and are served without Node.js.</p>
  </div>
  <?php endif; ?>
  <noscript>GIMT SmartCampus Admin requires JavaScript.</noscript>
</body>
</html>
