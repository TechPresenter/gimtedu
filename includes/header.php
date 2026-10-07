<?php
/** Public website <head> + top bar + main navigation. Rendered via site_header($page). */
$page = $GLOBALS['__site_page'] ?? [];
$name = institute_name();
$seoRef = $page['seo'] ?? null;
$seo = $seoRef ? seo_lookup($seoRef[0] ?? null, isset($seoRef[1]) && is_numeric($seoRef[1]) ? (int) $seoRef[1] : null, $seoRef[0] === 'route' ? (string) $seoRef[1] : null) : null;
$title = $seo['meta_title'] ?? (isset($page['title']) ? $page['title'] . ' | ' . setting('institute_short_name', 'GIMT') : $name . ' | ' . setting('tagline', 'Education • Innovation • Opportunity'));
$description = $seo['meta_description'] ?? ($page['description'] ?? 'Global Institute of Management & Technology (GIMT), Greater Noida - industry-focused UG, PG, diploma and certificate programs in management, technology, commerce and computer applications.');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$canonical = $seo['canonical_url'] ?? ($page['canonical'] ?? absolute_url(ltrim(substr($path, strlen(base_path())), '/')));
$ogImage = $seo['og_image'] ?? ($page['image'] ?? null);
$ogImageUrl = $ogImage ? (preg_match('#^https?://#', $ogImage) ? $ogImage : absolute_url(ltrim($ogImage, '/'))) : absolute_url('assets/images/site/hero-campus-students.jpg');
$robots = $seo['robots'] ?? ($page['robots'] ?? 'index,follow');
$active = $page['active'] ?? trim(substr($path, strlen(base_path())), '/');
$active = $active === '' ? 'home' : $active;
$topbar = site_announcements('topbar');
$phone = setting('phone', '+91 9955446477');
$email = setting('email', 'info@gimt.ac.in');
$ctaText = setting('header_cta_text', 'Apply Now');
$ctaUrl = setting('header_cta_url', '/apply');
$ga = setting('google_analytics_id');
$gtm = setting('gtm_id');
$pixel = setting('meta_pixel_id');
$orgSchema = [
    '@context' => 'https://schema.org', '@type' => 'CollegeOrUniversity', 'name' => $name, 'alternateName' => setting('brand_name', 'Global IMT'),
    'url' => absolute_url(''), 'logo' => absolute_url('assets/images/logo.svg'), 'email' => $email, 'telephone' => $phone,
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => setting('address'), 'addressLocality' => setting('city', 'Greater Noida'), 'addressRegion' => setting('state', 'Uttar Pradesh'), 'postalCode' => setting('pincode', '201310'), 'addressCountry' => 'IN'],
    'sameAs' => array_values(social_links()),
];
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e(str_limit($description, 300)) ?>">
  <?php if (!empty($seo['meta_keywords'])): ?><meta name="keywords" content="<?= e($seo['meta_keywords']) ?>"><?php endif; ?>
  <meta name="robots" content="<?= e($robots) ?>">
  <link rel="canonical" href="<?= e($canonical) ?>">
  <meta name="theme-color" content="#0B2A5B">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta property="og:type" content="<?= e($page['og_type'] ?? 'website') ?>">
  <meta property="og:site_name" content="<?= e($name) ?>">
  <meta property="og:title" content="<?= e($seo['og_title'] ?? $title) ?>">
  <meta property="og:description" content="<?= e(str_limit($seo['og_description'] ?? $description, 300)) ?>">
  <meta property="og:url" content="<?= e($canonical) ?>">
  <meta property="og:image" content="<?= e($ogImageUrl) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <?php if ($v = setting('search_console_verification')): ?><meta name="google-site-verification" content="<?= e($v) ?>"><?php endif; ?>
  <link rel="icon" type="image/svg+xml" href="<?= e(setting('favicon') ? upload_url(setting('favicon')) : asset('assets/images/favicon.svg')) ?>">
  <link rel="preload" href="<?= e(base_url('assets/fonts/plus-jakarta-sans-latin-800-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= e(base_url('assets/fonts/inter-latin-400-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
  <script type="application/ld+json"><?= json_encode($orgSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <?php if (!empty($seo['schema_json'])): ?><script type="application/ld+json"><?= str_replace('</', '<\/', $seo['schema_json']) ?></script><?php endif; ?>
  <?php foreach ((array) ($page['schema'] ?? []) as $schema): ?><script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script><?php endforeach; ?>
  <?php if ($gtm): ?><script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer',<?= js_json($gtm) ?>);</script><?php endif; ?>
  <?php if ($ga): ?><script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($ga) ?>"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= js_json($ga) ?>);</script><?php endif; ?>
  <?php if ($pixel): ?><script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',<?= js_json($pixel) ?>);fbq('track','PageView');</script><?php endif; ?>
</head>
<body class="<?= e($page['body_class'] ?? '') ?>">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow-lg">Skip to main content</a>

<?php if (setting('show_topbar', '1') === '1'): ?>
<div class="bg-brand-900 text-[12.5px] text-white/85">
  <div class="container-site flex h-10 items-center justify-between gap-4">
    <div class="flex min-w-0 items-center gap-5">
      <span class="flex min-w-0 items-center gap-1.5 truncate font-medium text-white"><?= icon('graduation-cap', 'h-4 w-4 shrink-0 text-accent-400') ?><span class="truncate"><?= e($topbar[0]['title'] ?? setting('admissions_open_text', 'Admissions Open for 2026-27')) ?></span></span>
      <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" class="hidden items-center gap-1.5 hover:text-white md:flex"><?= icon('phone', 'h-3.5 w-3.5') ?><?= e($phone) ?></a>
      <a href="mailto:<?= e($email) ?>" class="hidden items-center gap-1.5 hover:text-white lg:flex"><?= icon('mail', 'h-3.5 w-3.5') ?><?= e($email) ?></a>
    </div>
    <div class="flex shrink-0 items-center gap-4">
      <a href="<?= e(site_url('student-portal')) ?>" class="hidden items-center gap-1.5 hover:text-white sm:flex"><?= icon('user', 'h-3.5 w-3.5') ?>Student Login</a>
      <a href="<?= e(site_url('faculty-portal')) ?>" class="hidden items-center gap-1.5 hover:text-white sm:flex"><?= icon('user-round', 'h-3.5 w-3.5') ?>Faculty Login</a>
      <a href="<?= e(admin_url('login')) ?>" class="flex items-center gap-1.5 hover:text-white"><?= icon('lock', 'h-3.5 w-3.5') ?>Admin Login</a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/navbar.php'; ?>

<main id="main">
