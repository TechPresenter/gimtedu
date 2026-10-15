<?php
/**
 * Public site header. Before including, a page may set:
 *   $pageTitle, $pageDescription, $bodyClass, $extraScripts (array of URLs), $ogImage
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$siteName = site_name();
$fullTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle . ' | ' . $siteName : $siteName . ' - ' . setting('site_tagline_en', 'Together, let us build a better tomorrow');
$metaDescription = $pageDescription ?? setting('site_description');
$headerUser = current_user();
$menu = main_menu();
$contactEmail = setting('contact_email');
$contactPhone = setting('contact_phone');
$canonical = abs_url(current_page() . (isset($_GET['slug']) && is_string($_GET['slug']) ? '?slug=' . urlencode($_GET['slug']) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($fullTitle) ?></title>
    <meta name="description" content="<?= e(excerpt($metaDescription, 300)) ?>">
    <meta name="theme-color" content="#1a7f46">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($fullTitle) ?>">
    <meta property="og:description" content="<?= e(excerpt($metaDescription, 200)) ?>">
    <meta property="og:image" content="<?= e(abs_url($ogImage ?? 'assets/images/logo.png')) ?>">
    <link rel="icon" type="image/png" href="<?= e(asset('assets/images/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/images/apple-touch-icon.png')) ?>">
    <link rel="preload" href="<?= e(url('assets/fonts/poppins-latin-600-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/responsive.css')) ?>">
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'NGO',
        'name' => $siteName,
        'url' => BASE_URL,
        'logo' => abs_url('assets/images/logo.png'),
        'email' => $contactEmail,
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'New Delhi', 'postalCode' => '110051', 'addressCountry' => 'IN'],
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<a class="skip-link" href="#main">Skip to content</a>

<div class="topbar">
    <div class="container topbar-inner">
        <div class="topbar-left">
            <span class="topbar-welcome"><span>Welcome</span> to <?= e($siteName) ?></span>
            <span class="topbar-item"><?= icon('map-pin') ?><?= e(setting('contact_address')) ?></span>
            <?php if ($contactEmail): ?>
                <a class="topbar-item hide-sm" href="mailto:<?= e($contactEmail) ?>"><?= icon('mail') ?><?= e($contactEmail) ?></a>
            <?php endif; ?>
            <?php if ($contactPhone): ?>
                <a class="topbar-item hide-sm" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contactPhone)) ?>"><?= icon('phone') ?><?= e($contactPhone) ?></a>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <?php foreach (social_links() as $socialKey => $social): ?>
                <a class="social-sm" href="<?= e($social['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="<?= e($social['label']) ?>"><?= icon($socialKey) ?></a>
            <?php endforeach; ?>
            <?php if ($headerUser): ?>
                <a class="topbar-item" href="<?= e(url('member-dashboard.php')) ?>"><?= icon('user') ?>My Account</a>
                <a class="topbar-item" href="<?= e(url('logout.php?token=' . csrf_token())) ?>"><?= icon('log-out') ?>Logout</a>
            <?php else: ?>
                <a class="topbar-item" href="<?= e(url('login.php')) ?>"><?= icon('lock') ?>Login</a>
                <a class="topbar-item hide-sm" href="<?= e(url('register.php')) ?>">Register</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<header class="site-header" id="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="<?= e($siteName) ?> - Home">
            <img src="<?= e(site_logo()) ?>" alt="<?= e($siteName) ?> logo" width="72" height="72">
            <span class="brand-text"><strong><?= e($siteName) ?></strong><small><?= e(setting('site_tagline')) ?></small></span>
        </a>

        <button class="nav-toggle" type="button" aria-controls="main-nav" aria-expanded="false" aria-label="Open menu">
            <?= icon('menu', 'icon-open') ?><?= icon('x', 'icon-close') ?>
        </button>

        <nav class="main-nav" id="main-nav" aria-label="Main navigation">
            <ul class="nav-list">
                <?php foreach ($menu as $navItem): $hasChildren = !empty($navItem['children']); ?>
                    <li class="nav-item<?= $hasChildren ? ' has-dropdown' : '' ?><?= menu_is_active($navItem) ? ' active' : '' ?>">
                        <a href="<?= e(safe_link($navItem['url'] ?? 'index.php')) ?>"<?= $hasChildren ? ' aria-haspopup="true"' : '' ?>><?= e($navItem['label'] ?? '') ?></a>
                        <?php if ($hasChildren): ?>
                            <button class="dropdown-toggle" type="button" aria-expanded="false" aria-label="Show <?= e($navItem['label']) ?> submenu"><?= icon('chevron-down') ?></button>
                            <ul class="dropdown">
                                <?php foreach ($navItem['children'] as $navChild): ?>
                                    <li><a href="<?= e(safe_link($navChild['url'] ?? 'index.php')) ?>"><?= e($navChild['label'] ?? '') ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item nav-mobile-only">
                    <?php if ($headerUser): ?>
                        <a href="<?= e(url('member-dashboard.php')) ?>">My Account</a>
                    <?php else: ?>
                        <a href="<?= e(url('login.php')) ?>">Login / Register</a>
                    <?php endif; ?>
                </li>
            </ul>
            <a class="btn btn-donate nav-donate" href="<?= e(url('donate.php')) ?>"><?= icon('heart') ?>Donate Now</a>
        </nav>
    </div>
</header>

<main id="main">
<?= render_flash() ?>
