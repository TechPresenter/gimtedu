<?php
/**
 * Branded "something went wrong" page (HTTP 500, noindex). Dependency-light on purpose: no database, no shared layout,
 * inline CSS + the static logo only, so it renders even when the application is failing. Rendered by
 * includes/errors.php (uncaught exceptions / fatal errors on public pages outside dev mode), by Apache
 * (ErrorDocument 500 /500.php) and when visited directly. In dev mode the error details are shown below the message.
 */
if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
}
$__h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$__contact = (array) ($GLOBALS['__site_error_contact'] ?? []);
$__phone = trim((string) ($__contact['phone'] ?? '')) ?: '+91 9955446477';
$__email = trim((string) ($__contact['email'] ?? '')) ?: 'info@gimt.ac.in';
$__name = trim((string) ($__contact['name'] ?? '')) ?: 'Global Institute of Management & Technology';
$__base = function_exists('base_path') ? base_path() : '';
$__uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$__retry = preg_match('#^/(?![/\\\\])[^\s"<>\\\\]*$#', $__uri) &&!preg_match('#/500(\.php)?$#', parse_url($__uri, PHP_URL_PATH) ?: '') ? $__uri : $__base . '/';
$__err = $GLOBALS['__site_error'] ?? null;
$__dev = $__err && function_exists('is_dev') && is_dev();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Something went wrong | <?= $__h($__name) ?></title>
<link rel="icon" type="image/svg+xml" href="<?= $__h($__base) ?>/assets/images/favicon.svg">
<style>
  :root { --navy: #0B2A5B; --flame: #F05A28; --fill: #D03E16; --warm: #F5F2EE; --warm2: #EDE8E2; --body: #4A5568; }
  * { box-sizing: border-box; }
  html, body { margin: 0; }
  body { min-height: 100vh; display: flex; flex-direction: column; background: var(--warm); color: var(--body); font: 16px/1.6 Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
  a { color: inherit; }
  .bar { padding: 18px 20px; }
  .bar img { height: 46px; width: auto; display: block; }
  main { flex: 1; display: grid; place-items: center; padding: 24px 16px 56px; }
  .card { position: relative; width: 100%; max-width: 640px; overflow: hidden; background: #fff; border: 1px solid var(--warm2); border-radius: 28px; padding: 40px 28px; box-shadow: 0 30px 60px -40px rgba(7, 26, 59, .45); text-align: center; }
  .card::before { content: ""; position: absolute; inset: -120px -120px auto auto; width: 260px; height: 260px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, rgba(240, 90, 40, .22), rgba(240, 90, 40, 0) 70%); }
  .code { position: relative; margin: 0; font: 800 clamp(64px, 18vw, 112px)/1 "Plus Jakarta Sans", Inter, system-ui, sans-serif; letter-spacing: -.04em; color: transparent; -webkit-text-stroke: 2px var(--flame); }
  h1 { position: relative; margin: 14px 0 8px; color: var(--navy); font: 800 clamp(24px, 4vw, 32px)/1.2 "Plus Jakarta Sans", Inter, system-ui, sans-serif; letter-spacing: -.02em; }
  p { position: relative; margin: 0 auto 0; max-width: 46ch; }
  .actions { position: relative; display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 28px; }
  .btn { display: inline-flex; align-items: center; gap: 8px; border-radius: 999px; padding: 12px 22px; font-weight: 600; font-size: 15px; text-decoration: none; transition: transform .2s ease, background-color .2s ease, color .2s ease; }
  .btn:hover { transform: translateY(-2px); }
  .btn:focus-visible { outline: 2px solid var(--flame); outline-offset: 3px; }
  .btn-fill { background: var(--fill); color: #fff; }
  .btn-fill:hover { background: #B83511; }
  .btn-line { border: 1.5px solid var(--navy); color: var(--navy); }
  .btn-line:hover { background: var(--navy); color: #fff; }
  .help { position: relative; margin-top: 28px; padding-top: 22px; border-top: 1px solid var(--warm2); font-size: 14px; }
  .help a { font-weight: 600; color: var(--navy); }
  pre { margin: 24px 0 0; max-height: 320px; overflow: auto; padding: 16px; border-radius: 14px; background: #0B2A5B; color: #fde4da; text-align: left; font-size: 12px; white-space: pre-wrap; }
  footer { padding: 18px 20px 28px; text-align: center; font-size: 13px; }
  @media (prefers-reduced-motion: reduce) { .btn { transition: none; } .btn:hover { transform: none; } }
</style>
</head>
<body>
<div class="bar"><a href="<?= $__h($__base) ?>/" aria-label="<?= $__h($__name) ?> home"><img src="<?= $__h($__base) ?>/assets/images/logo.svg" alt="<?= $__h($__name) ?>" width="134" height="46"></a></div>
<main id="main">
  <div class="card" role="alert">
    <p class="code" aria-hidden="true">500</p>
    <h1>Something went wrong on our side</h1>
    <p>We are sorry — this page could not be loaded right now. The problem has been logged and our team will look into it. Please try again in a moment.</p>
    <div class="actions">
      <a class="btn btn-fill" href="<?= $__h($__retry) ?>">Try again</a>
      <a class="btn btn-line" href="<?= $__h($__base) ?>/">Go to the homepage</a>
    </div>
    <div class="help">
      Need help right away? Call <a href="tel:<?= $__h(preg_replace('/[^\d+]/', '', $__phone)) ?>"><?= $__h($__phone) ?></a> or email <a href="mailto:<?= $__h($__email) ?>"><?= $__h($__email) ?></a>.
    </div>
    <?php if ($__dev): ?>
      <pre><?= $__h($__err instanceof Throwable ? (string) $__err : json_encode($__err, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    <?php endif; ?>
  </div>
</main>
<footer>&copy; <?= date('Y') ?> <?= $__h($__name) ?></footer>
</body>
</html>
