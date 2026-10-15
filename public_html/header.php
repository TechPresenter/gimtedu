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
$ogImage = $seo['og_image'] ?: setting('seo_default_og_image');
$ogImageUrl = $ogImage ? (preg_match('#^https?://#', $ogImage) ? $ogImage : abs_url($ogImage)) : abs_url('assets/images/logo.png');
$headerUser = current_user();
$mainMenu = menu_tree('main');
$topbarMenu = setting('topbar_enabled', '1') === '1' ? menu_tree('topbar') : [];
$contactEmail = setting('contact_email');
$contactPhone = setting('contact_phone');
$motion = setting('motion_effects_enabled', '1') === '1';
$unread = $headerUser ? unread_notification_count((int) $headerUser['id']) : 0;
?>
<!DOCTYPE html>
<html lang="en-IN">
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
    <meta property="og:title" content="<?= e($seo['title'] ?? $fullTitle) ?>">
    <meta property="og:description" content="<?= e(excerpt($description, 200)) ?>">
    <meta property="og:url" content="<?= e($seo['canonical']) ?>">
    <meta property="og:image" content="<?= e($ogImageUrl) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <?php if (setting('twitter_handle')): ?><meta name="twitter:site" content="@<?= e(ltrim(setting('twitter_handle'), '@')) ?>"><?php endif; ?>
    <meta name="twitter:title" content="<?= e($seo['title'] ?? $fullTitle) ?>">
    <meta name="twitter:description" content="<?= e(excerpt($description, 200)) ?>">
    <meta name="twitter:image" content="<?= e($ogImageUrl) ?>">
    <?php if (setting('google_site_verification')): ?><meta name="google-site-verification" content="<?= e(setting('google_site_verification')) ?>"><?php endif; ?>
    <link rel="icon" type="image/png" href="<?= e(setting('favicon_path') ? image_url(setting('favicon_path')) : asset('assets/images/favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= e(asset('assets/images/apple-touch-icon.png')) ?>">
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
<div class="topbar">
    <div class="container topbar-inner">
        <div class="topbar-left">
            <span class="topbar-welcome"><?= e(setting('topbar_text', 'Welcome to ' . site_name())) ?></span>
            <?php if ($contactEmail): ?><a class="topbar-item hide-md" href="mailto:<?= e($contactEmail) ?>"><?= icon('mail') ?><?= e($contactEmail) ?></a><?php endif; ?>
            <?php if ($contactPhone): ?><a class="topbar-item hide-md" href="tel:<?= e(preg_replace('/[^\d+]/', '', $contactPhone)) ?>"><?= icon('phone') ?><?= e($contactPhone) ?></a><?php endif; ?>
            <?php if (fraud_alert_enabled()): ?><button type="button" class="topbar-alert" data-modal-open="fraud-alert"><?= icon('alert-triangle') ?>Fraud alert</button><?php endif; ?>
        </div>
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

<header class="site-header" id="site-header">
    <div class="container header-inner">
        <a class="brand" href="<?= e(url('/')) ?>" aria-label="<?= e(site_name()) ?> - home"><?= brand_lockup('header') ?></a>

        <nav class="main-nav" id="main-nav" aria-label="Main navigation">
            <div class="nav-mobile-head">
                <a class="brand brand-sm" href="<?= e(url('/')) ?>"><?= brand_lockup('mobile') ?></a>
                <button type="button" class="nav-close" data-nav-toggle aria-label="Close menu"><?= icon('x') ?></button>
            </div>
            <ul class="nav-list">
                <?php foreach ($mainMenu as $navItem):
                    $navChildren = $navItem['children'];
                    $navMega = count($navChildren) > 7;
                    $navActive = menu_item_active($navItem); ?>
                    <li class="nav-item<?= $navChildren ? ' has-dropdown' : '' ?><?= $navMega ? ' is-mega' : '' ?><?= $navActive ? ' active' : '' ?>">
                        <a href="<?= e($navItem['url']) ?>"<?= $navActive ? ' aria-current="page"' : '' ?><?= $navItem['new_tab'] ? ' target="_blank" rel="noopener"' : '' ?>><?= e($navItem['label']) ?></a>
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
                                <?php if ($navMega): ?>
                                    <div class="mega-aside">
                                        <strong><?= e(setting('site_tagline_en')) ?></strong>
                                        <p class="hindi"><?= e(setting('site_tagline')) ?></p>
                                        <a class="btn btn-donate btn-sm" href="<?= e(page_url('donate')) ?>"><?= icon('heart') ?>Donate Now</a>
                                    </div>
                                <?php endif; ?>
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
            <div class="nav-mobile-apply">
                <?php foreach ($topbarMenu as $item): ?>
                    <a class="apply-chip" href="<?= e($item['url']) ?>"><?= $item['icon'] ? icon($item['icon']) : '' ?><?= e($item['label']) ?></a>
                <?php endforeach; ?>
            </div>
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
    <div class="scroll-progress" aria-hidden="true"><span></span></div>
</header>

<main id="main" tabindex="-1">
<?= render_toasts() ?>
