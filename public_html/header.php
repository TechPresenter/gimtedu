<?php
/**
 * Public site header. Called by page_header() - do not include directly.
 * SEO values come from $GLOBALS['seo']; menus from Admin > Menus (main, topbar).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$seo = $GLOBALS['seo'] ?? ['title' => site_name(), 'description' => setting('seo_default_description'), 'canonical' => abs_url(''), 'og_image' => '', 'og_type' => 'website', 'noindex' => false];
$fullTitle = seo_full_title();
$description = excerpt((string) ($seo['description'] ?: setting('seo_default_description')), 300);
// Social preview image: page image -> default sharing image setting -> built-in 1200x630 card. SVG is never used
// (Facebook, WhatsApp, LinkedIn and X do not render it) and a local file must exist.
$ogUsable = static function (string $p): bool {
    if ($p === '' || preg_match('/\.svg(\?|#|$)/i', $p)) {
        return false;
    }
    return (bool) preg_match('#^https?://#', $p) || (strpos($p, '..') === false && is_file(ROOT_PATH . '/' . ltrim($p, '/')));
};
$ogImage = (string) ($seo['og_image'] ?? '');
if (!$ogUsable($ogImage)) {
    $ogImage = (string) setting('seo_default_og_image');
}
if (!$ogUsable($ogImage)) {
    $ogImage = is_file(ROOT_PATH . '/assets/images/og-default.jpg') ? 'assets/images/og-default.jpg' : 'assets/images/logo.png';
}
// the alt text describes the picture; the built-in card and the logo show the foundation's logo and name, not the page
$ogImageAlt = $ogImage === 'assets/images/og-default.jpg'
    ? 'Prerit Welfare Foundation logo with the tagline Empowering Communities, Spreading Hope, Creating Change'
    : ($ogImage === 'assets/images/logo.png' ? 'Prerit Welfare Foundation logo' : '');
$ogImageUrl = preg_match('#^https?://#', $ogImage) ? $ogImage : abs_url(ltrim($ogImage, '/'));
$ogSize = !preg_match('#^https?://#', $ogImage) ? @getimagesize(ROOT_PATH . '/' . ltrim($ogImage, '/')) : false;
$ogLarge = !$ogSize || ($ogSize[0] >= 600 && $ogSize[0] !== $ogSize[1]);
$ogTitle = !empty($seo['is_home']) ? $fullTitle : (string) ($seo['title'] ?? $fullTitle);
$headerUser = current_user();
$mainMenu = menu_tree('main');
$topbarMenu = setting('topbar_enabled', '1') === '1' ? menu_tree('topbar') : [];
// "Programs & Events" dropdown (top bar on desktop, chip group in the mobile menu): categories + events/hackathon links
$programsNav = function_exists('programs_nav_items') ? programs_nav_items() : [];
$programsCats = array_values(array_filter($programsNav, function ($i) { return $i['group'] === 'category'; }));
$programsLinks = array_values(array_filter($programsNav, function ($i) { return $i['group'] !== 'category'; }));
$programsActive = (bool) array_filter($programsNav, function ($i) { return !empty($i['active']); }) || route_path() === 'programs';
if ($programsNav) {
    $GLOBALS['extraScripts'][] = asset('assets/js/modules/topbar-programs.js');
}
$contactEmail = setting('contact_email');
$contactPhone = setting('contact_phone');
$motion = setting('motion_effects_enabled', '1') === '1';
$unread = $headerUser ? unread_notification_count((int) $headerUser['id']) : 0;
$navActiveId = nav_active_top_id($mainMenu);
// Phones & tablets (< 1280px): the main menu's top-level items as a swipeable strip under the header bar
// (Admin > Settings > Header & footer). An item without its own link ("#" or empty) opens its first sub-item.
$navStrip = [];
if (setting('mobile_nav_strip', '1') === '1') {
    foreach ($mainMenu as $navItem) {
        $raw = trim((string) $navItem['raw_url']);
        $href = $navItem['url'];
        $newTab = $navItem['new_tab'];
        if ($raw === '' || $raw === '#') {
            if (!$navItem['children']) {
                continue;
            }
            $href = $navItem['children'][0]['url'];
            $newTab = $navItem['children'][0]['new_tab'];
        }
        $isActive = $navActiveId !== null && $navItem['id'] === $navActiveId;
        $hrefPath = trim((string) parse_url($href, PHP_URL_PATH), '/');
        $base = trim(base_path(), '/');
        if ($base !== '' && strpos($hrefPath, $base) === 0) {
            $hrefPath = trim(substr($hrefPath, strlen($base)), '/');
        }
        $navStrip[] = ['label' => $navItem['label'], 'url' => $href, 'new_tab' => $newTab, 'active' => $isActive,
            'page' => $isActive && $hrefPath === route_path()];
    }
}
?>
<!DOCTYPE html>
<html lang="en-IN"<?= $navStrip ? ' class="has-nav-strip"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($fullTitle) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <?php if (!empty($seo['noindex'])): ?><meta name="robots" content="noindex, nofollow"><?php else: ?><meta name="robots" content="index, follow, max-image-preview:large"><?php endif; ?>
    <link rel="canonical" href="<?= e($seo['canonical']) ?>">
    <meta name="theme-color" content="#146B43">
    <meta property="og:type" content="<?= e($seo['og_type'] ?? 'website') ?>">
    <meta property="og:site_name" content="<?= e(site_name()) ?>">
    <meta property="og:locale" content="en_IN">
    <meta property="og:title" content="<?= e($ogTitle) ?>">
    <meta property="og:description" content="<?= e(excerpt($description, 200)) ?>">
    <meta property="og:url" content="<?= e($seo['canonical']) ?>">
    <meta property="og:image" content="<?= e($ogImageUrl) ?>">
    <?php if ($ogSize): ?><meta property="og:image:width" content="<?= (int) $ogSize[0] ?>"><meta property="og:image:height" content="<?= (int) $ogSize[1] ?>"><?php endif; ?>
    <meta property="og:image:alt" content="<?= e($ogImageAlt !== '' ? $ogImageAlt : $ogTitle) ?>">
    <meta name="twitter:card" content="<?= $ogLarge ? 'summary_large_image' : 'summary' ?>">
    <?php if (setting('twitter_handle')): ?><meta name="twitter:site" content="@<?= e(ltrim(setting('twitter_handle'), '@')) ?>"><?php endif; ?>
    <meta name="twitter:title" content="<?= e($ogTitle) ?>">
    <meta name="twitter:description" content="<?= e(excerpt($description, 200)) ?>">
    <meta name="twitter:image" content="<?= e($ogImageUrl) ?>">
    <meta name="twitter:image:alt" content="<?= e($ogImageAlt !== '' ? $ogImageAlt : $ogTitle) ?>">
    <?php if (setting('google_site_verification')): ?><meta name="google-site-verification" content="<?= e(setting('google_site_verification')) ?>"><?php endif; ?>
    <link rel="icon" type="image/png" href="<?= e(setting('favicon_path') ? image_url(setting('favicon_path')) : asset('assets/images/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/images/apple-touch-icon.png')) ?>">
    <?php /* every Latin weight used above the fold is ~8 KB: preloading them avoids text reflowing when the font swaps in (layout shift) */ ?>
    <link rel="preload" href="<?= e(url('assets/fonts/poppins-latin-400-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= e(url('assets/fonts/poppins-latin-500-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= e(url('assets/fonts/poppins-latin-600-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="<?= e(url('assets/fonts/poppins-latin-800-normal.woff2')) ?>" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/enhance.css')) ?>">
    <?php foreach (glob(ROOT_PATH . '/assets/css/modules/*.css') ?: [] as $moduleCss): if (strpos(basename($moduleCss), 'admin-') === 0) { continue; } ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/modules/' . basename($moduleCss))) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= e(asset('assets/css/responsive.css')) ?>">
    <?php foreach (seo_schemas() as $schema) { echo json_ld($schema) . "\n"; } ?>
</head>
<body class="<?= e($GLOBALS['bodyClass'] ?? '') ?>" data-motion="<?= $motion ? 'on' : 'off' ?>">
<a class="skip-link" href="#main">Skip to content</a>
<?= render_announcement_bar() ?>

<?php if (setting('topbar_enabled', '1') === '1'): ?>
<div class="topbar<?= $topbarMenu ? ' has-apply' : '' ?>">
    <div class="container topbar-inner">
        <div class="topbar-left">
            <span class="topbar-welcome"><?= e(setting('topbar_text', 'Welcome to ' . site_name())) ?></span>
            <?php if ($contactEmail): ?><a class="topbar-item hide-md" href="mailto:<?= e($contactEmail) ?>"><?= icon('mail') ?><?= e($contactEmail) ?></a><?php endif; ?>
            <?php if ($contactPhone): ?><a class="topbar-item hide-md" href="tel:<?= e(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= icon('phone') ?><?= e($contactPhone) ?></a><?php endif; ?>
            <?php if (fraud_alert_enabled()): ?><button type="button" class="topbar-alert" data-modal-open="fraud-alert"><?= icon('alert-triangle') ?>Fraud alert</button><?php endif; ?>
        </div>
        <?php if ($programsNav): ?>
            <nav class="topbar-programs<?= $programsActive ? ' is-active' : '' ?>" aria-label="Programs and events" data-pe-menu>
                <a class="pe-link" href="<?= e(page_url('programs')) ?>"<?= route_path() === 'programs' ? ' aria-current="page"' : '' ?>><?= icon('layers') ?><span>Programs &amp; Events</span></a>
                <button type="button" class="pe-toggle" aria-expanded="false" aria-controls="pe-menu" aria-label="Show Programs &amp; Events menu"><?= icon('chevron-down') ?></button>
                <div class="pe-menu" id="pe-menu">
                    <p class="pe-menu-title">Programme categories</p>
                    <ul class="pe-cat-list">
                        <?php foreach ($programsCats as $pe): ?>
                            <li><a href="<?= e($pe['url']) ?>"<?= $pe['active'] ? ' class="is-active" aria-current="page"' : '' ?>><span class="dd-icon"><?= icon($pe['icon'] !== '' ? $pe['icon'] : 'calendar') ?></span><span><?= e($pe['label']) ?></span></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="pe-menu-links">
                        <?php foreach ($programsLinks as $pe): ?>
                            <a href="<?= e($pe['url']) ?>"<?= $pe['active'] ? ' class="is-active" aria-current="page"' : '' ?>><?= icon($pe['icon'] !== '' ? $pe['icon'] : 'arrow-right') ?><span><?= e($pe['label']) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>
        <?php endif; ?>
        <?php if ($topbarMenu): ?>
            <nav class="topbar-apply" aria-label="Apply">
                <span class="apply-label"><?= icon('briefcase') ?>Apply:</span>
                <?php foreach ($topbarMenu as $item): ?>
                    <a class="apply-chip<?= menu_item_active($item) ? ' is-active' : '' ?>" href="<?= e($item['url']) ?>"<?= $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= $item['icon'] ? icon($item['icon']) : '' ?><span><?= e($item['label']) ?></span></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="topbar-right">
            <?php foreach (social_links() as $key => $link): ?>
                <a class="social-sm" href="<?= e($link['url']) ?>" target="_blank" rel="noopener" aria-label="<?= e($link['label']) ?>" data-tip="<?= e($link['label']) ?>"><?= icon($key) ?></a>
            <?php endforeach; ?>
            <?php if ($headerUser): ?>
                <a class="topbar-item topbar-account" href="<?= e(member_url()) ?>"><?= icon('user') ?><?= e(strtok($headerUser['name'], ' ')) ?></a>
            <?php else: ?>
                <a class="topbar-item topbar-account" href="<?= e(page_url('login')) ?>"><?= icon('lock') ?>Login</a><span class="topbar-sep" aria-hidden="true">/</span><a class="topbar-item" href="<?= e(page_url('register')) ?>">Register</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<header class="site-header<?= $headerUser ? ' has-user' : '' ?>" id="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>"><?= brand_lockup('header') ?><span class="sr-only"> - home</span></a>

        <nav class="main-nav" id="main-nav" aria-label="Main navigation">
            <div class="nav-mobile-head">
                <a class="brand brand-sm" href="<?= e(url('/')) ?>" aria-label="<?= e(site_name()) ?> - home"><?= brand_lockup('mobile') ?></a>
                <button type="button" class="nav-close" data-nav-toggle aria-label="Close menu"><?= icon('x') ?></button>
            </div>
            <?php if ($topbarMenu): ?>
                <div class="nav-mobile-apply" role="group" aria-labelledby="nav-apply-title">
                    <p class="nav-apply-title" id="nav-apply-title"><?= icon('briefcase') ?>Apply</p>
                    <div class="nav-apply-row" data-hscroll data-hscroll-active=".is-active">
                        <?php foreach ($topbarMenu as $item): $applyActive = menu_item_active($item); ?>
                            <a class="apply-chip<?= $applyActive ? ' is-active' : '' ?>" href="<?= e($item['url']) ?>"<?= $applyActive ? ' aria-current="page"' : '' ?><?= $item['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= $item['icon'] ? icon($item['icon']) : '' ?><span><?= e($item['label']) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($programsNav): ?>
                <div class="nav-mobile-apply nav-mobile-programs" role="group" aria-labelledby="nav-pe-title">
                    <p class="nav-apply-title" id="nav-pe-title"><?= icon('layers') ?><a href="<?= e(page_url('programs')) ?>">Programs &amp; Events</a></p>
                    <div class="nav-apply-row" data-hscroll data-hscroll-active=".is-active">
                        <?php foreach (array_merge($programsCats, $programsLinks) as $pe): ?>
                            <a class="apply-chip<?= $pe['active'] ? ' is-active' : '' ?>" href="<?= e($pe['url']) ?>"<?= $pe['active'] ? ' aria-current="page"' : '' ?>><?= icon($pe['icon'] !== '' ? $pe['icon'] : 'calendar') ?><span><?= e($pe['label']) ?></span></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <ul class="nav-list">
                <?php foreach ($mainMenu as $navItem):
                    $navChildren = $navItem['children'];
                    $navMega = count($navChildren) > 7;
                    $navActive = $navActiveId !== null && $navItem['id'] === $navActiveId;
                    $navIsPage = $navActive && trim((string) parse_url((string) $navItem['raw_url'], PHP_URL_PATH), '/') === route_path(); ?>
                    <li class="nav-item<?= $navChildren ? ' has-dropdown' : '' ?><?= $navMega ? ' is-mega' : '' ?><?= $navActive ? ' active' : '' ?>">
                        <a href="<?= e($navItem['url']) ?>"<?= $navActive ? ' aria-current="' . ($navIsPage ? 'page' : 'true') . '"' : '' ?><?= $navItem['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($navItem['label']) ?></a>
                        <?php if ($navChildren): ?>
                            <button type="button" class="dropdown-toggle" aria-expanded="false" aria-label="Show <?= e($navItem['label']) ?> menu"><?= icon('chevron-down') ?></button>
                            <div class="dropdown<?= $navMega ? ' mega' : '' ?>">
                                <ul class="dropdown-list">
                                    <?php foreach ($navChildren as $navChild): ?>
                                        <li><a href="<?= e($navChild['url']) ?>"<?= menu_item_active($navChild) ? ' class="active" aria-current="page"' : '' ?><?= $navChild['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>>
                                            <?php if ($navChild['icon']): ?><span class="dd-icon"><?= icon($navChild['icon']) ?></span><?php endif; ?>
                                            <span class="dd-text"><strong><?= e($navChild['label']) ?></strong><?php if ($navChild['description']): ?><small><?= e($navChild['description']) ?></small><?php endif; ?></span>
                                        </a></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <li class="nav-item nav-mobile-only">
                    <?php if ($headerUser): ?>
                        <a href="<?= e(member_url()) ?>"><?= icon('user') ?>My Dashboard</a>
                    <?php else: ?>
                        <a href="<?= e(page_url('login')) ?>"><?= icon('lock') ?>Login / Register</a>
                    <?php endif; ?>
                </li>
            </ul>
        </nav>

        <div class="header-actions">
            <?php if ($headerUser): ?>
                <a class="header-icon" href="<?= e(member_url('notifications')) ?>" aria-label="Notifications<?= $unread ? ' (' . $unread . ' unread)' : '' ?>" data-tip="Notifications">
                    <?= icon('bell') ?><?php if ($unread): ?><span class="badge-dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
                </a>
            <?php endif; ?>
            <a class="btn btn-donate btn-3d header-donate" href="<?= e(safe_link(setting('header_cta_url', '/donate/'))) ?>"><?= icon('heart', 'heartbeat') ?><span><?= e(setting('header_cta_label', 'Donate Now')) ?></span></a>
            <button type="button" class="nav-toggle" data-nav-toggle aria-controls="main-nav" aria-expanded="false" aria-label="Open menu">
                <span class="burger" aria-hidden="true"><span></span><span></span><span></span></span>
            </button>
        </div>
    </div>
    <?php if ($navStrip): ?>
        <nav class="nav-strip" id="nav-strip" aria-label="Quick links">
            <div class="nav-strip-scroller" data-hscroll data-hscroll-active=".is-active">
                <ul class="nav-strip-list">
                    <?php foreach ($navStrip as $pill): ?>
                        <li><a class="nav-pill<?= $pill['active'] ? ' is-active' : '' ?>" href="<?= e($pill['url']) ?>"<?= $pill['active'] ? ' aria-current="' . ($pill['page'] ? 'page' : 'true') . '"' : '' ?><?= $pill['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><span><?= e($pill['label']) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </nav>
    <?php endif; ?>
    <div class="scroll-progress" aria-hidden="true"><span></span></div>
</header>

<main id="main" tabindex="-1">
<?= render_toasts() ?>
