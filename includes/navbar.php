<?php
/**
 * Design v3 navigation: floating warm-grey pill header (logo · nav with ▾ carets · orange Apply Now pill · round search
 * + menu buttons), Programs mega menu (category columns Undergraduate | Postgraduate + Diploma | Certificate Courses by
 * group, compact links, navy counselling card, "View all programs" row), dropdowns, side drawer (menu button, all
 * breakpoints) and search overlay. Included by
 * includes/header.php ($page['header'] = 'minimal' renders only logo · phone · Apply Now for campaign pages).
 *
 * Menu items come from Website CMS > Menus (location "header"; items may carry icon + description, `is_mega` turns an
 * item into a mega menu — with CMS children it shows them as columns, without children it is auto-filled from the
 * programs/departments tables). Sensible defaults are used when the menu is empty. The side drawer uses the CMS
 * "mobile" menu when one is active, else the header menu.
 * Theme: one round Light → Dark → System button in the bar (site_theme_toggle(['variant' => 'cycle']), md+), the
 * Light / Dark / System segmented control in the minimal campaign header (md+, it has no drawer) and in the drawer
 * ("Appearance"); light + white logo variants (.logo-on-light / .logo-on-dark) switched by the theme.
 * Layout (2026-10-26): menu from 1024 px (items 5–6 from 1280, 7+ from 1536), the menu button (side drawer) at every
 * width; the institute name beside the logo comes from Settings (institute_name()).
 * Behaviour: frontend/site/core/{header,menu,mega,overlays}.ts (+ assets/js/main.js no-bundle fallback). Without JS
 * the panels open on :hover/:focus-within, drawer groups are <details>, every link works.
 * Hooks (keep): #site-header, [data-nav-item] [data-nav-toggle] [data-nav-panel], [data-mega] [data-mega-filter]
 * [data-mega-item] [data-mega-count], #mobile-menu [data-menu-open] [data-menu-close], #site-search [data-search-open]
 * [data-search-close] #site-search-input.
 */
$name = $name ?? institute_name();
$active = $active ?? 'home';
$phone = $phone ?? setting('phone', '+91 9955446477');
$email = $email ?? setting('email', 'info@gimt.ac.in');
$ctaText = $ctaText ?? setting('header_cta_text', 'Apply Now');
$ctaUrl = $ctaUrl ?? setting('header_cta_url', '/apply');
$headerVariant = $headerVariant ?? 'full';
$caret = icon('chevron-down', 'h-3.5 w-3.5');
$logoAlt = e(setting('brand_name', 'Global IMT')) . ' logo';
// institute name beside the logo (Settings › General): "Global Institute of" / "Management & Technology"
$nameLines = preg_match('/^(.*?\bof)\s+(.+)$/iu', $name, $nm) ? [$nm[1], $nm[2]] : [$name, ''];
$logoText = '<span class="slt-1">' . e($nameLines[0]) . '</span>' . ($nameLines[1] !== '' ? '<span class="slt-2">' . e($nameLines[1]) . '</span>' : '');
// light + white logo; the theme shows one of them (.logo-on-light / .logo-on-dark). The white one is lazy, so the light
// theme never downloads it; with the theme toggle switched off and a light default it is not rendered at all.
$darkLogo = function_exists('site_theme_toggle_enabled') && (site_theme_toggle_enabled() || site_theme_default() !== 'light');
$logo = '<img src="' . e(logo_url()) . '" alt="' . $logoAlt . '" class="logo-on-light h-12 w-auto lg:h-14 xl:h-[60px]" width="120" height="60">'
    . ($darkLogo ? '<img src="' . e(logo_url(true)) . '" alt="' . $logoAlt . '" class="logo-on-dark h-12 w-auto lg:h-14 xl:h-[60px]" width="120" height="60" loading="lazy" decoding="async">' : '');

if ($headerVariant === 'minimal'): ?>
<header id="site-header" class="site-header" data-autohide="off">
  <div class="container-site">
    <div class="site-header-bar">
      <a href="<?= e(site_url('')) ?>" class="site-logo flex shrink-0 items-center gap-3" aria-label="<?= e($name) ?> - Home"><?= $logo ?><span class="site-logo-text sm:!block"><?= $logoText ?></span></a>
      <div class="ml-auto flex items-center gap-2 sm:gap-6">
        <?= function_exists('site_theme_toggle') ? site_theme_toggle(['variant' => 'header', 'class' => 'hidden md:inline-flex']) : '' ?>
        <?php /* the full number from 1024 px; below that a round call button (the spaced number + theme switch + Apply Now do not fit) */ ?>
        <a href="<?= e(site_tel($phone)) ?>" class="header-phone sm:hidden lg:inline-flex"><?= e($phone) ?></a>
        <a href="<?= e(site_tel($phone)) ?>" class="header-round lg:hidden" aria-label="Call <?= e($phone) ?>"><?= icon('phone', 'h-[18px] w-[18px]') ?></a>
        <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent btn-shine btn-gleam header-cta px-5 lg:px-7" data-magnetic="0.2"><?= e($ctaText) ?></a>
      </div>
    </div>
  </div>
</header>
<?php return; endif;

$menu = site_menu('header');
if (!$menu) {
    $menu = [
        ['title' => 'Home', 'url' => '/', 'children' => []],
        ['title' => 'Programs', 'url' => '/programs', 'is_mega' => 1, 'children' => []],
        ['title' => 'Admissions', 'url' => '/admissions', 'children' => [
            ['title' => 'Admissions Open ' . (current_session()['name'] ?? '2026-27'), 'url' => '/admissions-open', 'icon' => 'sparkles', 'description' => 'Programs, fees and how to apply', 'children' => []],
            ['title' => 'Admission Process', 'url' => '/admissions#process', 'icon' => 'clipboard-check', 'description' => 'Five simple steps to join GIMT', 'children' => []],
            ['title' => 'Apply Online', 'url' => '/apply', 'icon' => 'file-text', 'description' => 'Start your application in minutes', 'children' => []],
            ['title' => 'Fees & Scholarships', 'url' => '/admissions#fees', 'icon' => 'wallet', 'description' => 'Fee structure, instalments & awards', 'children' => []],
            ['title' => 'Eligibility & Documents', 'url' => '/admissions#eligibility', 'icon' => 'file-check', 'description' => 'Who can apply and what to upload', 'children' => []],
            ['title' => 'Important Dates', 'url' => '/admissions#dates', 'icon' => 'calendar-days', 'description' => 'Deadlines for the ' . (current_session()['name'] ?? '2026-27') . ' session', 'children' => []],
            ['title' => 'FAQs', 'url' => '/faq', 'icon' => 'message-circle', 'description' => 'Answers to common questions', 'children' => []],
        ]],
        ['title' => 'Academics', 'url' => '/academics', 'children' => [
            ['title' => 'Academics Overview', 'url' => '/academics', 'icon' => 'school', 'description' => 'Departments, pedagogy and calendar', 'children' => []],
            ['title' => 'Departments', 'url' => '/academics#departments', 'icon' => 'building-2', 'description' => 'Schools and departments at GIMT', 'children' => []],
            ['title' => 'Our Faculty', 'url' => '/faculty', 'icon' => 'graduation-cap', 'description' => 'Experienced academics & mentors', 'children' => []],
            ['title' => 'Academic Calendar', 'url' => '/academics#calendar', 'icon' => 'calendar-days', 'description' => 'Semesters, exams and holidays', 'children' => []],
            ['title' => 'Research & Innovation', 'url' => '/research', 'icon' => 'microscope', 'description' => 'Projects, publications and incubation', 'children' => []],
            ['title' => 'Verify Certificate', 'url' => '/verify-certificate', 'icon' => 'circle-check', 'description' => 'Check a GIMT certificate online', 'children' => []],
        ]],
        ['title' => 'Campus Life', 'url' => '/campus', 'children' => [
            ['title' => 'Campus & Facilities', 'url' => '/campus', 'icon' => 'building', 'description' => 'Labs, library, hostel and sports', 'children' => []],
            ['title' => 'Student Life & Clubs', 'url' => '/campus-life', 'icon' => 'palette', 'description' => 'Clubs, fests and student activities', 'children' => []],
            ['title' => 'Placements', 'url' => '/placement', 'icon' => 'briefcase-business', 'description' => 'Training, drives and recruiters', 'children' => []],
            ['title' => 'Alumni', 'url' => '/alumni', 'icon' => 'users', 'description' => 'Our global alumni network', 'children' => []],
            ['title' => 'Photo Gallery', 'url' => '/gallery', 'icon' => 'image', 'description' => 'Campus moments in pictures', 'children' => []],
        ]],
        ['title' => 'News & Events', 'url' => '/blog', 'children' => [
            ['title' => 'News & Blog', 'url' => '/blog', 'icon' => 'newspaper', 'description' => 'Stories, achievements and articles', 'children' => []],
            ['title' => 'Events', 'url' => '/events', 'icon' => 'calendar-check', 'description' => 'Seminars, fests and workshops', 'children' => []],
            ['title' => 'Notice Board', 'url' => '/notices', 'icon' => 'megaphone', 'description' => 'Circulars, exam and holiday notices', 'children' => []],
        ]],
        ['title' => 'About', 'url' => '/about', 'children' => [
            ['title' => 'About GIMT', 'url' => '/about', 'icon' => 'landmark', 'description' => 'Our story, campus and values', 'children' => []],
            ['title' => 'Vision & Mission', 'url' => '/vision-mission', 'icon' => 'target', 'description' => 'What drives us every day', 'children' => []],
            ['title' => 'Leadership', 'url' => '/leadership', 'icon' => 'users', 'description' => 'Chairman, Director and Deans', 'children' => []],
            ['title' => 'Approvals & Affiliations', 'url' => '/about#approvals', 'icon' => 'badge-check', 'description' => 'Recognitions of our programs', 'children' => []],
            ['title' => 'Mandatory Disclosure', 'url' => '/mandatory-disclosure', 'icon' => 'file-text', 'description' => 'Statutory information about GIMT', 'children' => []],
            ['title' => 'Policies', 'url' => '/policies', 'icon' => 'shield-check', 'description' => 'Anti-ragging, grievance & POSH', 'children' => []],
            // the top-level Contact item shows in the bar only from 1536 px
            ['title' => 'Contact Us', 'url' => '/contact', 'icon' => 'map-pin', 'description' => 'Address, phone and directions', 'children' => []],
        ]],
        ['title' => 'Contact', 'url' => '/contact', 'children' => []],
    ];
}
$programs = site_programs();
$levels = site_program_levels();
// certificate courses are many: the mega menu and the drawer show one card / link per certificate group instead
$degreePrograms = array_values(array_filter($programs, fn ($p) => $p['level'] !== 'Certificate'));
$certGroups = [];
foreach (site_cert_programs() as $p) {
    $certGroups[site_cert_group_key($p) ?: 'other'][] = $p;
}
$certGroupMeta = fn (string $k): array => site_cert_groups()[$k] ?? ['label' => 'Certificate Courses', 'icon' => 'badge-check', 'hue' => 'cert', 'blurb' => 'Short skill courses with a GIMT certificate.'];
$certGroupUrl = fn (string $k): string => site_url('certifications' . ($k !== 'other' ? '?group=' . rawurlencode($k) : '')) . '#courses';
$isActive = function (array $item) use ($active): bool {
    $u = trim(parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH) ?? '', '/');
    $u = $u === '' ? 'home' : $u;
    return $u === $active || ($u !== 'home' && str_starts_with($active, $u . '/'));
};
$navLink = function (array $c, int $i) {
    $target = ($c['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '';
    return '<a class="nav-sublink" style="--i:' . $i . '" href="' . e(cms_link($c['url'] ?? '#')) . '"' . $target . '>'
        . (!empty($c['icon']) ? '<span class="nav-sublink-icon">' . icon($c['icon'], 'h-[18px] w-[18px]') . '</span>' : '')
        . '<span class="min-w-0"><span class="nav-sublink-title">' . e($c['title']) . '</span>'
        . (!empty($c['description']) ? '<span class="nav-sublink-desc">' . e($c['description']) . '</span>' : '') . '</span></a>';
};
// Programs mega menu (auto-filled): category columns — Undergraduate | Postgraduate + Diploma | Certificate Courses (one
// link per certificate group) — compact links (short name + full name + duration), a navy counselling card and a footer row
$megaPrograms = function (string $panelId) use ($degreePrograms, $certGroups, $certGroupMeta, $certGroupUrl, $phone) {
    $perList = 6;   // links per list before "+ N more"
    $link = fn (array $p): string => '<li><a href="' . e(site_url('programs/' . $p['slug'])) . '" class="megax-link ' . level_hue($p['level']) . '" title="' . e($p['name']) . '">'
        . '<span class="megax-link-text"><span class="megax-link-name">' . e($p['short_name'] ?: $p['name']) . '</span><span class="megax-link-sub">' . e($p['name']) . '</span></span>'
        . (!empty($p['duration_label']) ? '<span class="megax-link-meta">' . e($p['duration_label']) . '</span>' : '') . '</a></li>';
    $list = function (string $title, string $hue, array $rows, string $allUrl) use ($link, $perList): string {
        if (!$rows) {
            return '';
        }
        $more = count($rows) - $perList;
        return '<div class="megax-list ' . $hue . '"><p class="megax-title"><span class="hue-dot" aria-hidden="true"></span>' . e($title) . '<span class="megax-count">' . count($rows) . '</span></p>'
            . '<ul>' . implode('', array_map($link, array_slice($rows, 0, $perList))) . '</ul>'
            . ($more > 0 ? '<a href="' . e($allUrl) . '" class="megax-more">+ ' . $more . ' more' . icon('arrow-right', 'h-3.5 w-3.5') . '</a>' : '') . '</div>';
    };
    $byLevel = ['UG' => [], 'PG' => [], 'PhD' => [], 'Diploma' => []];
    foreach ($degreePrograms as $p) {
        $byLevel[isset($byLevel[$p['level']]) ? $p['level'] : 'Diploma'][] = $p;
    }
    $cols = [];
    $cols[] = $list('Undergraduate', 'hue-ug', $byLevel['UG'], site_url('programs?category=ug') . '#find');
    $cols[] = $list('Postgraduate', 'hue-pg', array_merge($byLevel['PG'], $byLevel['PhD']), site_url('programs?category=pg') . '#find')
        . $list('Diploma', 'hue-diploma', $byLevel['Diploma'], site_url('programs?category=diploma') . '#find');
    if ($certGroups) {
        $total = array_sum(array_map('count', $certGroups));
        $c = '<div class="megax-list hue-cert"><p class="megax-title"><span class="hue-dot" aria-hidden="true"></span>Certificate Courses<span class="megax-count">' . $total . '</span></p><ul>';
        foreach ($certGroups as $k => $rows) {
            $g = $certGroupMeta($k);
            $n = count($rows);
            $c .= '<li><a href="' . e($certGroupUrl($k)) . '" class="megax-link megax-group hue-' . e($g['hue']) . '"><span class="megax-group-icon">' . icon($g['icon'], 'h-4 w-4') . '</span>'
                . '<span class="megax-link-text"><span class="megax-link-name">' . e($g['label']) . '</span><span class="megax-link-sub">' . $n . ' course' . ($n === 1 ? '' : 's') . '</span></span>'
                . icon('chevron-right', 'megax-arrow h-4 w-4') . '</a></li>';
        }
        $cols[] = $c . '</ul><a href="' . e(site_url('certifications')) . '" class="megax-more">All certificate courses' . icon('arrow-right', 'h-3.5 w-3.5') . '</a></div>';
    }
    $cols = array_values(array_filter($cols));
    $admit = trim((string) setting('admissions_open_text', 'Admissions Open for 2026-27'));
    $h = '<div class="megax" style="--megax-cols:' . max(1, count($cols)) . '">';
    $h .= '<div class="megax-cols nav-stagger">' . implode('', array_map(fn ($c, $i) => '<div class="megax-col" style="--i:' . $i . '">' . $c . '</div>', $cols, array_keys($cols))) . '</div>';
    $h .= '<aside class="megax-aside" aria-label="Admission help">'
        . ($admit !== '' ? '<p class="megax-aside-eyebrow">' . e($admit) . '</p>' : '')
        . '<p class="megax-aside-title">Not sure which program fits you?</p>'
        . '<p class="megax-aside-text">Free guidance from our counsellors on courses, eligibility, fees and scholarships.</p>'
        . '<a href="' . e(site_url('apply')) . '" class="btn btn-accent btn-sm w-full">Apply Now' . icon('arrow-right', 'h-4 w-4') . '</a>'
        . '<a href="' . e(site_url('programs?brochure=1')) . '" target="_blank" rel="noopener" class="megax-aside-link">' . icon('download', 'h-4 w-4') . 'Download brochure<span class="sr-only"> (opens in a new tab)</span></a>'
        . ($phone ? '<a href="' . e(site_tel((string) $phone)) . '" class="megax-aside-link">' . icon('phone', 'h-4 w-4') . e($phone) . '</a>' : '')
        . '</aside>';
    $h .= '<div class="megax-foot">'
        . '<a href="' . e(site_url('programs') . '#compare') . '" class="megax-foot-link">' . icon('table', 'h-4 w-4') . 'Compare programs</a>'
        . '<a href="' . e(site_url('admissions') . '#fees') . '" class="megax-foot-link">' . icon('wallet', 'h-4 w-4') . 'Fees &amp; scholarships</a>'
        . '<a href="' . e(site_url('programs')) . '" class="megax-viewall">View all programs' . icon('arrow-right', 'h-4 w-4') . '</a></div>';
    return $h . '</div>';
};
// bar items: everything except Home; more than 6 → the rest only from 2xl (all are in the drawer anyway)
$barItems = array_values(array_filter($menu, fn ($m) => trim(parse_url((string) ($m['url'] ?? ''), PHP_URL_PATH) ?? '', '/') !== '' || !empty($m['children'])));
$searchSuggestions = [['Programs', '/programs', 'graduation-cap'], ['Admissions', '/admissions', 'clipboard-check'], ['Fee Structure', '/admissions#fees', 'wallet'], ['Scholarships', '/admissions#scholarships', 'award'], ['Placements', '/placement', 'briefcase-business'], ['Contact', '/contact', 'phone']];
?>
<header id="site-header" class="site-header" data-autohide="mobile">
  <div class="container-site">
    <div class="site-header-bar">
      <a href="<?= e(site_url('')) ?>" class="site-logo flex shrink-0 items-center gap-3" aria-label="<?= e($name) ?> - Home">
        <?= $logo ?>
        <span class="site-logo-text"><?= $logoText ?></span>
      </a>

      <nav class="mx-auto hidden lg:block" aria-label="Main">
        <ul class="flex items-center gap-0.5">
          <?php foreach ($barItems as $mi => $item):
              $kids = $item['children'] ?? [];
              $mega = !empty($item['is_mega']) && ($kids || $programs);
              $hasPanel = $mega || $kids;
              $act = $isActive($item) || (bool) array_filter($kids, $isActive);
              $panelId = 'nav-panel-' . $mi;
              ?>
            <li class="nav-item<?= $mega ? ' is-mega' : '' ?><?= $mi >= 6 ? ' !hidden 2xl:!flex' : ($mi >= 4 ? ' !hidden xl:!flex' : '') ?>"<?= $hasPanel ? ' data-nav-item' : '' ?>>
              <a href="<?= e(cms_link($item['url'] ?? '#')) ?>" class="nav-link<?= $act ? ' nav-link-active' : '' ?>"<?= $act ? ' aria-current="page"' : '' ?><?= ($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['title']) ?></a>
              <?php if ($hasPanel): ?>
                <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="<?= $panelId ?>"><span class="sr-only"><?= e($item['title']) ?> menu</span><?= $caret ?></button>
              <?php endif; ?>
              <?php if ($mega && !$kids): ?>
                <div class="nav-panel nav-panel-mega" id="<?= $panelId ?>" data-nav-panel><?= $megaPrograms($panelId) ?></div>
              <?php elseif ($mega): $flat = !array_filter($kids, fn ($c) => !empty($c['children'])); ?>
                <div class="nav-panel nav-panel-mega" id="<?= $panelId ?>" data-nav-panel>
                  <div class="nav-panel-inner"><div class="nav-stagger grid grid-cols-12 gap-6 p-6">
                    <?php if ($flat): ?>
                      <div class="col-span-12" style="--i:0"><div class="grid grid-cols-3 gap-x-2 gap-y-0.5"><?php foreach ($kids as $k => $c): ?><?= $navLink($c, $k) ?><?php endforeach; ?></div></div>
                    <?php else: $span = max(3, intdiv(12, max(1, count($kids)))); /* Tailwind: col-span-3 col-span-4 col-span-6 col-span-12 */ ?>
                      <?php foreach ($kids as $ci => $col): ?>
                        <div class="col-span-<?= $span ?>" style="--i:<?= $ci ?>"><p class="mega-heading"><?= e($col['title']) ?></p><div class="space-y-0.5"><?php foreach ($col['children'] ?? [] as $k => $c): ?><?= $navLink($c, $k) ?><?php endforeach; ?></div></div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <div class="flex justify-center px-6 pb-6"><a href="<?= e(cms_link($item['url'] ?? '/programs')) ?>" class="mega-viewall">View all<?= icon('arrow-right', 'h-4 w-4') ?></a></div></div>
                </div>
              <?php elseif ($kids): ?>
                <div class="nav-panel nav-panel-dropdown" id="<?= $panelId ?>" data-nav-panel>
                  <div class="nav-panel-inner nav-stagger p-2"><?php foreach ($kids as $k => $c): ?><?= $navLink($c, $k) ?><?php endforeach; ?></div>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <!-- search · theme · Apply Now · menu (opens the side drawer at every width) -->
      <div class="ml-auto flex items-center gap-2 lg:ml-0">
        <button type="button" class="header-round" data-search-open aria-label="Search the website (Ctrl+K)" aria-controls="site-search" aria-haspopup="dialog"><?= icon('search', 'h-[19px] w-[19px]') ?></button>
        <?= function_exists('site_theme_toggle') ? site_theme_toggle(['variant' => 'cycle', 'class' => 'hidden md:inline-flex']) : '' ?>
        <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent btn-shine btn-gleam header-cta hidden px-6 sm:inline-flex" data-magnetic="0.2"><?= e($ctaText) ?></a>
        <button type="button" class="header-round" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu"><?= icon('menu', 'h-5 w-5') ?></button>
      </div>
    </div>
  </div>
</header>

<?php
// drawer: an icon per top-level item (the CMS item's icon, else one picked from its path)
$drawerIcon = function (array $item): string {
    if (!empty($item['icon'])) {
        return (string) $item['icon'];
    }
    $path = strtolower(trim(parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH) ?? '', '/'));
    return ['' => 'house', 'programs' => 'graduation-cap', 'admissions' => 'clipboard-check', 'apply' => 'clipboard-check', 'academics' => 'school',
        'faculty' => 'users', 'campus' => 'building-2', 'placement' => 'briefcase-business', 'alumni' => 'users', 'blog' => 'newspaper',
        'events' => 'calendar-days', 'notices' => 'newspaper', 'about' => 'landmark', 'contact' => 'phone'][$path] ?? 'circle-dot';
};
$drawerWa = site_whatsapp_url();
$drawerAdmit = trim((string) setting('admissions_open_text', ''));
$drawerDeadline = site_admissions_deadline();
?>
<!-- Side drawer (menu button, every width): logo · admissions chip · icon menu ·
     compact footer (Apply Now + call + WhatsApp, portal logins + theme) — redesigned 2026-10-26 -->
<div id="mobile-menu" class="drawer hidden" role="dialog" aria-modal="true" aria-label="Menu">
  <div class="drawer-backdrop" data-menu-close></div>
  <div class="drawer-panel">
    <div class="drawer-head">
      <a href="<?= e(site_url('')) ?>" class="shrink-0" aria-label="<?= e($name) ?> - Home">
        <img src="<?= e(logo_url()) ?>" alt="<?= $logoAlt ?>" class="logo-on-light h-10 w-auto" loading="lazy" width="84" height="40">
        <?php if ($darkLogo): ?><img src="<?= e(logo_url(true)) ?>" alt="<?= $logoAlt ?>" class="logo-on-dark h-10 w-auto" loading="lazy" width="84" height="40"><?php endif; ?>
      </a>
      <div class="flex items-center gap-2">
        <?php $themeToggle = function_exists('site_theme_toggle') ? site_theme_toggle(['variant' => 'header']) : ''; if ($themeToggle !== ''): ?>
          <div class="drawer-appearance"><?= $themeToggle ?></div>
        <?php endif; ?>
        <button type="button" class="header-round drawer-close" data-menu-close aria-label="Close menu"><?= icon('x', 'h-5 w-5') ?></button>
      </div>
    </div>
    <?php $di = 0; if ($drawerAdmit !== ''): ?>
    <a href="<?= e(site_url('admissions')) ?>" class="drawer-admit drawer-item" style="--i:0">
      <span class="drawer-admit-dot" aria-hidden="true"></span>
      <span class="min-w-0 flex-1"><span class="block truncate font-bold"><?= e($drawerAdmit) ?></span><?php if ($drawerDeadline): ?><span class="block text-[12px] font-medium opacity-80">Last date to apply: <?= e(date('j M Y', $drawerDeadline)) ?></span><?php endif; ?></span>
      <?= icon('arrow-right', 'h-4 w-4 shrink-0') ?>
    </a>
    <?php endif; ?>
    <nav class="drawer-nav" aria-label="Site menu">
      <div class="space-y-1" data-accordion="single">
        <?php foreach ((site_menu('mobile') ?: $menu) as $item): $kids = $item['children'] ?? []; $mega = !empty($item['is_mega']); $di++; $on = $isActive($item) || (bool) array_filter($kids, $isActive); ?>
          <?php if ($kids || ($mega && $programs)): ?>
            <details class="drawer-group drawer-item" style="--i:<?= $di ?>">
              <summary class="drawer-link<?= $on ? ' is-active' : '' ?>"><span class="drawer-link-main"><span class="drawer-ico"><?= icon($drawerIcon($item), 'h-[18px] w-[18px]') ?></span><?= e($item['title']) ?></span><?= icon('chevron-down', 'drawer-chev h-[18px] w-[18px]') ?></summary>
              <div class="drawer-sub">
                <a class="drawer-sublink font-semibold text-brand-900" href="<?= e(cms_link($item['url'])) ?>"><?= $mega ? 'All programs' : 'Overview' ?><?= icon('arrow-right', 'h-3.5 w-3.5') ?></a>
                <?php if ($kids && $mega): foreach ($kids as $col): foreach (($col['children'] ?? []) ?: [$col] as $c): ?>
                  <a class="drawer-sublink" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a>
                <?php endforeach; endforeach; elseif ($kids): foreach ($kids as $c): ?>
                  <a class="drawer-sublink" href="<?= e(cms_link($c['url'])) ?>"><?= !empty($c['icon']) ? icon($c['icon'], 'h-4 w-4 text-accent-600') : '' ?><?= e($c['title']) ?></a>
                <?php endforeach; else: foreach ($levels as $code => $label): $list = array_filter($programs, fn ($p) => $p['level'] === $code); ?>
                  <p class="drawer-label <?= level_hue($code) ?>"><span class="hue-dot" aria-hidden="true"></span><?= e($label) ?></p>
                  <?php if ($code === 'Certificate'): foreach ($certGroups as $k => $rows): $g = $certGroupMeta($k); ?><a class="drawer-sublink" href="<?= e($certGroupUrl($k)) ?>"><span class="drawer-badge hue-<?= e($g['hue']) ?>"><?= count($rows) ?> course<?= count($rows) === 1 ? '' : 's' ?></span><span class="truncate"><?= e($g['label']) ?></span></a><?php endforeach; else: ?>
                  <?php foreach ($list as $p): ?><a class="drawer-sublink" href="<?= e(site_url('programs/' . $p['slug'])) ?>"><span class="drawer-badge <?= dept_hue($p) ?>"><?= e(str_limit($p['short_name'], 12)) ?></span><span class="truncate"><?= e($p['name']) ?></span></a><?php endforeach; endif; ?>
                <?php endforeach; endif; ?>
              </div>
            </details>
          <?php else: ?>
            <a href="<?= e(cms_link($item['url'])) ?>" class="drawer-link drawer-item<?= $on ? ' is-active' : '' ?>" style="--i:<?= $di ?>"<?= $on ? ' aria-current="page"' : '' ?>><span class="drawer-link-main"><span class="drawer-ico"><?= icon($drawerIcon($item), 'h-[18px] w-[18px]') ?></span><?= e($item['title']) ?></span><?= icon('chevron-right', 'drawer-chev h-[18px] w-[18px]') ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </nav>
    <div class="drawer-foot">
      <div class="flex items-center gap-2 drawer-item" style="--i:<?= ++$di ?>">
        <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent btn-shine btn-gleam h-12 flex-1"><?= e($ctaText) ?><?= icon('arrow-right', 'h-4 w-4') ?></a>
        <a href="<?= e(site_tel($phone)) ?>" class="drawer-action" aria-label="Call admissions: <?= e($phone) ?>" title="<?= e($phone) ?>"><?= icon('phone', 'h-5 w-5') ?></a>
        <?php if ($drawerWa): ?><a href="<?= e($drawerWa) ?>" class="drawer-action drawer-action-wa" target="_blank" rel="noopener" aria-label="Chat on WhatsApp (opens in a new tab)"><?= icon('whatsapp-brand', 'h-5 w-5') ?></a><?php endif; ?>
      </div>
      <p class="drawer-logins drawer-item" style="--i:<?= ++$di ?>"><?= icon('log-in', 'h-4 w-4 shrink-0 text-slate-400') ?><a href="<?= e(site_url('portal/student/login')) ?>">Student Login</a><span aria-hidden="true">·</span><a href="<?= e(site_url('portal/faculty/login')) ?>">Faculty Login</a></p>
    </div>
  </div>
</div>

<!-- Site search overlay (Ctrl/⌘ + K) -->
<div id="site-search" class="search-overlay hidden" role="dialog" aria-modal="true" aria-label="Search">
  <div class="search-backdrop" data-search-close></div>
  <div class="search-panel">
    <form action="<?= e(site_url('search')) ?>" method="get" role="search">
      <label for="site-search-input" class="sr-only">Search</label>
      <div class="flex items-center gap-3 rounded-full bg-white p-2 pl-6 shadow-pop">
        <?= icon('search', 'h-5 w-5 shrink-0 text-slate-400') ?>
        <input id="site-search-input" name="q" type="search" placeholder="Search programs, news, events, notices…" class="h-12 min-w-0 flex-1 border-0 bg-transparent text-base focus:outline-none focus:ring-0" autocomplete="off">
        <button type="button" class="voice-btn" data-voice-input="#site-search-input" data-voice-submit aria-label="Search by voice" title="Search by voice" hidden><?= icon('mic', 'h-5 w-5') ?></button>
        <kbd class="hidden rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] font-semibold text-slate-500 sm:inline">Esc</kbd>
        <button class="btn btn-primary">Search</button>
      </div>
    </form>
    <div class="mt-5">
      <p class="mb-2.5 text-xs font-semibold uppercase tracking-wider text-white/60">Popular searches</p>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($searchSuggestions as [$label, $url, $ic]): ?><a href="<?= e(site_url(ltrim($url, '/'))) ?>" class="search-suggest"><?= icon($ic, 'h-3.5 w-3.5') ?><?= e($label) ?></a><?php endforeach; ?>
      </div>
    </div>
    <button type="button" class="mx-auto mt-8 flex items-center gap-2 text-sm text-white/70 hover:text-white" data-search-close><?= icon('x', 'h-4 w-4') ?>Close</button>
  </div>
</div>
