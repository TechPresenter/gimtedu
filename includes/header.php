<?php if (is_file(__DIR__ . '/partials/theme.php')) { require_once __DIR__ . '/partials/theme.php'; } ?>
<?php
/**
 * Public website <head> + loading screen + reading progress + announcement ticker + admission bar + floating header
 * (Design v3).
 * Rendered via site_header($page) — keys documented on site_header() in includes/config.php, plus (SEO platform):
 *   head       array of extra <head> tags: raw HTML strings, or ['rel' => 'prev', 'href' => …] (<link>) /
 *              ['property'|'name' => …, 'content' => …] (<meta>); empty hrefs/contents are skipped
 *   canonical  string (absolute URL or path) | false = no canonical tag (error pages; also automatic for 4xx/5xx)
 *   robots     merged with the admin SEO record — the most restrictive directive wins (noindex beats index)
 *   schema     the page's own JSON-LD; an admin seo_settings.schema_json entity of the same family is then skipped
 *              (BlogPosting replaces NewsArticle, Course, Event …); 'schema_admin' => false skips the admin schema;
 *              'breadcrumb_schema' => false disables the automatic BreadcrumbList
 *   image_alt  alt text for og:image / twitter:image
 * SEO + tracking logic: app/services/seo.php (meta, JSON-LD, redirects, error reporter) and includes/partials/consent.php
 * (Consent Mode v2 + consent-gated GA4 / GTM / Meta Pixel). Theme hooks: includes/partials/theme.php (theme unit).
 * Loads the website bundle (React islands + TS enhancements) through site_assets_head() — dev server or built manifest.
 */
require_once APP_ROOT . '/app/services/seo.php';
require_once __DIR__ . '/partials/consent.php';
$page = $GLOBALS['__site_page'] ?? [];
// Redirect manager: a 404 consults the admin rules (301/302/410), lower-case and trailing-slash variants and is logged;
// a live page only follows explicit admin 3xx rules. Runs before any output.
$httpStatus = seo_redirect_on_render((int) (http_response_code() ?: 200));
$name = institute_name();
$seoRef = $page['seo'] ?? null;
$seo = $seoRef ? seo_lookup($seoRef[0] ?? null, isset($seoRef[1]) && is_numeric($seoRef[1]) ? (int) $seoRef[1] : null, $seoRef[0] === 'route' ? (string) $seoRef[1] : null) : null;
$meta = seo_page_meta($page, $seo, $httpStatus);
$title = $meta['title'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$active = $page['active'] ?? trim(substr($path, strlen(base_path())), '/');
$active = $active === '' ? 'home' : $active;
$phone = setting('phone', '+91 9955446477');
$email = setting('email', 'info@gimt.ac.in');
$ctaText = $page['header_cta']['label'] ?? setting('header_cta_text', 'Apply Now');
$ctaUrl = $page['header_cta']['url'] ?? setting('header_cta_url', '/apply');
$headerVariant = ($page['header'] ?? 'full') === 'minimal' ? 'minimal' : 'full';
$cursor = $page['cursor'] ?? (setting('site_cursor', '0') === '1');
$whatsapp = setting('whatsapp_number');
$bodyClass = trim('site-v2 ' . ($page['body_class'] ?? '') . ($whatsapp ? ' has-whatsapp' : '') . ($headerVariant === 'minimal' ? ' header-minimal' : ''));
// Announcement strip: page option (bool or "A | B | C" items) → setting show_topbar
$announce = $page['announce'] ?? (setting('show_topbar', '1') === '1');
$announceItems = is_string($announce) ? site_announcement_items($announce) : ($announce ? site_announcement_items() : []);
?><!doctype html>
<html lang="en"<?= function_exists('site_theme_html_attrs') ? site_theme_html_attrs() : '' ?><?= ($page['motion'] ?? true) === false ? ' data-motion="off"' : '' ?>>
<head>
  <meta charset="utf-8">
  <?= function_exists('site_theme_head') ? site_theme_head() : '' ?>
  <?php /* cross-document page transitions (@view-transition in keyframes-webkit.css): Chrome aborts one when the viewport
     changes size mid-navigation (scrollbar appears, window resized, mobile URL bar) and rejects its promises — handle them
     here, before the first render, so no "Uncaught (in promise) InvalidStateError: Transition was aborted" reaches the console */ ?>
  <script<?= site_nonce_attr() ?>>(function(w){function n(){}function g(e){var t=e&&e.viewTransition;if(t){t.ready&&t.ready.catch(n);t.finished&&t.finished.catch(n);t.updateCallbackDone&&t.updateCallbackDone.catch(n)}}w.addEventListener("pagereveal",g);w.addEventListener("pageswap",g)})(window)</script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($meta['description']) ?>">
  <?php if ($meta['keywords']): ?><meta name="keywords" content="<?= e($meta['keywords']) ?>">
  <?php endif; ?>
  <meta name="robots" content="<?= e($meta['robots']) ?>">
  <?php if ($meta['canonical']): ?><link rel="canonical" href="<?= e($meta['canonical']) ?>">
  <?php endif; ?>
  <?php foreach ($meta['hreflang'] as $lang => $href): ?><link rel="alternate" hreflang="<?= e($lang) ?>" href="<?= e($href) ?>">
  <?php endforeach; ?>
  <?php if (!function_exists('site_theme_head')): ?><meta name="theme-color" content="#F5F2EE">
  <?php endif; ?>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <?php foreach ($meta['og'] as $prop => $value): ?><meta property="<?= e($prop) ?>" content="<?= e($value) ?>">
  <?php endforeach; ?>
  <?php foreach ($meta['twitter'] as $prop => $value): ?><meta name="<?= e($prop) ?>" content="<?= e($value) ?>">
  <?php endforeach; ?>
<?= site_csp_tag(seo_head_extras($page['head'] ?? [])) ?>
<?= seo_verification_meta() ?>
  <link rel="icon" type="image/svg+xml" href="<?= e(setting('favicon') ? upload_url(setting('favicon')) : asset('assets/images/favicon.svg')) ?>">
<?php require_once APP_ROOT . '/app/services/site_brand.php'; /* admin brand colours & fonts (Settings › Website) */ ?>
  <?php foreach (site_font_preloads() as $fontHref): ?><link rel="preload" href="<?= e($fontHref) ?>" as="font" type="font/woff2" crossorigin>
  <?php endforeach; ?>
  <?php foreach ((array) ($page['preload'] ?? []) as $pre): $href = is_array($pre) ? ($pre['href'] ?? '') : $pre; if ($href === '') continue; ?><link rel="preload" as="image" href="<?= e($href) ?>"<?= is_array($pre) && !empty($pre['media']) ? ' media="' . e($pre['media']) . '"' : '' ?> fetchpriority="high">
  <?php endforeach; ?>
  <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
<?= site_brand_style() ?>
<?= consent_head() ?>
<?= site_csp_tag(seo_error_reporter_head()) ?>
<?= site_csp_tag(seo_sentry_head()) ?>
  <?= site_assets_head() ?>
<?= seo_jsonld_html($page, $seo, $title, $httpStatus) ?>
</head>
<body class="<?= e($bodyClass) ?>"<?= $cursor ? ' data-cursor' : '' ?>>
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[110] focus:rounded-full focus:bg-white focus:px-4 focus:py-2 focus:shadow-lg">Skip to main content</a>

<?php if (($page['loader'] ?? false) !== false): ?>
<div id="site-loader" aria-hidden="true">
  <img src="<?= e(logo_url()) ?>" alt="" class="loader-logo h-16 w-auto" width="134" height="64">
  <span class="loader-bar"><span></span></span>
</div>
<?php endif; ?>
<div class="read-progress" data-read-progress aria-hidden="true"><span data-read-progress-bar></span></div>
<?php /* CMS › Announcements of type "banner": dismissible alert bars above everything else (closures, deadlines) */ ?>
<?= function_exists('site_alert_bars') ? site_alert_bars() : '' ?>

<?php if ($admissionBar = ($page['admission_bar'] ?? (setting('site_admission_bar', '0') === '1'))): ?>
<?= admission_bar(is_array($admissionBar) ? $admissionBar : []) ?>
<?php endif; ?>
<?php if ($announceItems): ?>
<?= announcement_strip($announceItems, $headerVariant === 'full' ? ['links' =>
    '<a href="' . e(site_tel($phone)) . '" class="inline-flex items-center gap-1.5">' . icon('phone', 'h-3.5 w-3.5 text-accent-600') . e($phone) . '</a>'
    . '<a href="' . e(site_url('portal/student/login')) . '">Student Login</a><a href="' . e(site_url('portal/faculty/login')) . '">Faculty Login</a>'] : []) /* no public Admin link (client request 2026-10-26): staff open /admin/ directly */ ?>
<?php endif; ?>

<?php include __DIR__ . '/navbar.php'; ?>

<main id="main">
