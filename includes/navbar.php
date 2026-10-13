<?php
/**
 * Design v3 navigation: floating warm-grey pill header (logo · nav with ▾ carets · orange Apply Now pill · round search
 * + menu buttons), Programs mega menu (left "By Level / By Department" filter sidebar + program card grid + "View all
 * programs" pill), dropdowns, side drawer (menu button, all breakpoints) and search overlay. Included by
 * includes/header.php ($page['header'] = 'minimal' renders only logo · phone · Apply Now for campaign pages).
 *
 * Menu items come from Website CMS > Menus (location "header"; items may carry icon + description, `is_mega` turns an
 * item into a mega menu — with CMS children it shows them as columns, without children it is auto-filled from the
 * programs/departments tables). Sensible defaults are used when the menu is empty.
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
$caret = '<svg viewBox="0 0 10 6" class="h-[6px] w-[10px]" aria-hidden="true" focusable="false"><path d="M0 0h10L5 6z" fill="currentColor"/></svg>';
$logo = '<img src="' . e(logo_url()) . '" alt="' . e(setting('brand_name', 'Global IMT')) . ' logo" class="h-10 w-auto lg:h-12" width="100" height="48">';

if ($headerVariant === 'minimal'): ?>
<header id="site-header" class="site-header" data-autohide="off">
  <div class="container-site">
    <div class="site-header-bar">
      <a href="<?= e(site_url('')) ?>" class="site-logo flex shrink-0 items-center gap-3" aria-label="<?= e($name) ?> - Home"><?= $logo ?><span class="site-logo-text sm:!block">Global Institute of<br>Management &amp; Technology</span></a>
      <div class="ml-auto flex items-center gap-2 sm:gap-6">
        <a href="<?= e(site_tel($phone)) ?>" class="header-phone"><?= e($phone) ?></a>
        <a href="<?= e(site_tel($phone)) ?>" class="header-round sm:hidden" aria-label="Call <?= e($phone) ?>"><?= icon('phone', 'h-[18px] w-[18px]') ?></a>
        <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent header-cta px-5 lg:px-7" data-magnetic="0.2"><?= e($ctaText) ?></a>
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
            ['title' => 'Admission Process', 'url' => '/admissions#process', 'icon' => 'clipboard-check', 'description' => 'Five simple steps to join GIMT', 'children' => []],
            ['title' => 'Apply Online', 'url' => '/apply', 'icon' => 'file-text', 'description' => 'Start your application in minutes', 'children' => []],
            ['title' => 'Fees & Scholarships', 'url' => '/admissions#fees', 'icon' => 'wallet', 'description' => 'Fee structure, instalments & awards', 'children' => []],
            ['title' => 'Eligibility & Documents', 'url' => '/admissions#eligibility', 'icon' => 'file-check', 'description' => 'Who can apply and what to upload', 'children' => []],
            ['title' => 'Important Dates', 'url' => '/admissions#dates', 'icon' => 'calendar-days', 'description' => 'Deadlines for the ' . (current_session()['name'] ?? '2026-27') . ' session', 'children' => []],
        ]],
        ['title' => 'Academics', 'url' => '/academics', 'children' => [
            ['title' => 'Academics Overview', 'url' => '/academics', 'icon' => 'school', 'description' => 'Departments, pedagogy and calendar', 'children' => []],
            ['title' => 'Departments', 'url' => '/academics#departments', 'icon' => 'building-2', 'description' => 'Schools and departments at GIMT', 'children' => []],
            ['title' => 'Our Faculty', 'url' => '/faculty', 'icon' => 'graduation-cap', 'description' => 'Experienced academics & mentors', 'children' => []],
            ['title' => 'Academic Calendar', 'url' => '/academics#calendar', 'icon' => 'calendar-days', 'description' => 'Semesters, exams and holidays', 'children' => []],
        ]],
        ['title' => 'Campus Life', 'url' => '/campus', 'children' => [
            ['title' => 'Campus & Facilities', 'url' => '/campus', 'icon' => 'building', 'description' => 'Labs, library, hostel and sports', 'children' => []],
            ['title' => 'Placements', 'url' => '/placement', 'icon' => 'briefcase-business', 'description' => 'Training, drives and recruiters', 'children' => []],
            ['title' => 'Alumni', 'url' => '/alumni', 'icon' => 'users', 'description' => 'Our global alumni network', 'children' => []],
            ['title' => 'News & Blog', 'url' => '/blog', 'icon' => 'newspaper', 'description' => 'Stories from campus', 'children' => []],
        ]],
        ['title' => 'About', 'url' => '/about', 'children' => [
            ['title' => 'About GIMT', 'url' => '/about', 'icon' => 'landmark', 'description' => 'Our story, campus and values', 'children' => []],
            ['title' => 'Vision & Mission', 'url' => '/about#vision', 'icon' => 'target', 'description' => 'What drives us every day', 'children' => []],
            ['title' => 'Leadership', 'url' => '/about#leadership', 'icon' => 'users', 'description' => 'Chairman, Director and Deans', 'children' => []],
            ['title' => 'Approvals & Affiliations', 'url' => '/about#approvals', 'icon' => 'badge-check', 'description' => 'Recognitions of our programs', 'children' => []],
        ]],
        ['title' => 'Contact', 'url' => '/contact', 'children' => []],
    ];
}
$programs = site_programs();
$levels = site_program_levels();
$depts = site_program_departments();
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
// Programs mega menu (auto-filled): filter sidebar + program cards
$megaPrograms = function (string $panelId) use ($programs, $levels, $depts) {
    $filter = function (string $value, string $label, int $count, string $hue, bool $active = false) use ($panelId): string {
        return '<li><button type="button" class="mega-filter ' . $hue . ($active ? ' is-active' : '') . '" data-mega-filter="' . e($value) . '" aria-pressed="' . ($active ? 'true' : 'false') . '" aria-controls="' . $panelId . '-grid">'
            . '<span class="mega-filter-label"><span class="hue-dot" aria-hidden="true"></span><span class="min-w-0">' . e($label) . '</span></span><span class="mega-filter-count" aria-label="' . $count . ' programs">' . $count . '</span></button></li>';
    };
    $h = '<div class="mega" data-mega>';
    $h .= '<div class="mega-side" role="group" aria-label="Filter programs"><p class="mega-side-title">By Level</p><ul>';
    $h .= $filter('all', 'All Programs', count($programs), 'hue-all', true);
    foreach ($levels as $code => $label) {
        $h .= $filter('level-' . strtolower($code), $label, count(array_filter($programs, fn ($p) => $p['level'] === $code)), level_hue($code));
    }
    $h .= '</ul>';
    if ($depts) {
        $h .= '<p class="mega-side-title">By Department</p><ul>';
        foreach ($depts as $d) {
            $sample = current(array_filter($programs, fn ($p) => (int) $p['department_id'] === (int) $d['id'])) ?: [];
            $h .= $filter('dept-' . (int) $d['id'], $d['label'], (int) $d['count'], dept_hue($sample));
        }
        $h .= '</ul>';
    }
    $tagCounts = [];
    foreach ($programs as $p) {
        foreach (program_tags($p) as $t) {
            $tagCounts[$t['key']] = ['label' => $t['label'], 'n' => ($tagCounts[$t['key']]['n'] ?? 0) + 1];
        }
    }
    if ($tagCounts) {
        $h .= '<p class="mega-side-title">By Tag</p><ul>';
        foreach ($tagCounts as $k => $t) {
            $h .= $filter('tag-' . $k, $t['label'], $t['n'], $k === 'popular' ? 'hue-cert' : 'hue-mgt');
        }
        $h .= '</ul>';
    }
    $h .= '</div><div class="mega-main"><div class="mega-grid" id="' . $panelId . '-grid" aria-live="polite">';
    foreach ($programs as $p) {
        $fee = (float) ($p['fee_per_year'] ?? 0) > 0 ? money($p['fee_per_year']) . ' ' . ($p['fee_label'] ?: '/ Year') : '';
        $tags = program_tags($p);
        $h .= '<a href="' . e(site_url('programs/' . $p['slug'])) . '" class="mega-card ' . dept_hue($p) . '" data-mega-item="all level-' . e(strtolower((string) $p['level'])) . ' dept-' . (int) ($p['department_id'] ?? 0) . implode('', array_map(fn ($t) => ' tag-' . $t['key'], $tags)) . '">'
            . '<span class="mega-card-tag">' . e($p['short_name']) . '</span>'
            . '<span class="mega-card-head"><span class="mega-card-icon">' . icon(program_icon($p), 'h-4 w-4') . '</span><span class="level-chip ' . level_hue($p['level']) . '">' . e(program_level_label($p['level'])) . '</span></span>'
            . '<span class="mega-card-name">' . e($p['name']) . '</span>'
            . ($tags ? '<span class="mega-card-tags">' . implode('', array_map(fn ($t) => '<span class="tag-pill tag-' . $t['key'] . '">' . e($t['label']) . '</span>', $tags)) . '</span>' : '')
            . '<span class="mega-card-row">' . dept_chip($p) . (!empty($p['duration_label']) ? '<span class="mega-card-meta">' . icon('clock', 'h-3.5 w-3.5') . e($p['duration_label']) . '</span>' : '') . '</span>'
            . '<span class="mega-card-fee"><span>' . e($fee) . '</span>' . icon('arrow-right', 'mega-card-arrow h-4 w-4') . '</span></a>';
    }
    $h .= '<p class="mega-empty hidden" data-mega-empty>No programs in this category yet — <a href="' . e(site_url('contact')) . '" class="link">ask our counsellors</a>.</p></div>';
    $h .= '<div class="mega-foot">';
    foreach (['UG' => ['ug', 'Undergraduate'], 'PG' => ['pg', 'Postgraduate'], 'Diploma' => ['diploma', 'Diploma'], 'Certificate' => ['certificate', 'Certificate']] as $code => [$cat, $label]) {
        if (isset($levels[$code])) {
            $h .= '<a href="' . e(site_url('programs?category=' . $cat)) . '" class="level-link ' . level_hue($code) . '"><span class="hue-dot" aria-hidden="true"></span>' . e($label) . '</a>';
        }
    }
    $h .= '<a href="' . e(site_url('programs')) . '" class="mega-viewall">View all programs' . icon('arrow-right', 'h-4 w-4') . '</a></div></div></div>';
    return $h;
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
        <span class="site-logo-text">Global Institute of<br>Management &amp; Technology</span>
      </a>

      <nav class="ml-auto hidden xl:block" aria-label="Main">
        <ul class="flex items-center gap-0.5">
          <?php foreach ($barItems as $mi => $item):
              $kids = $item['children'] ?? [];
              $mega = !empty($item['is_mega']) && ($kids || $programs);
              $hasPanel = $mega || $kids;
              $act = $isActive($item) || (bool) array_filter($kids, $isActive);
              $panelId = 'nav-panel-' . $mi;
              ?>
            <li class="nav-item<?= $mega ? ' is-mega' : '' ?><?= $mi >= 6 ? ' !hidden 2xl:!flex' : '' ?>"<?= $hasPanel ? ' data-nav-item' : '' ?>>
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

      <div class="ml-auto flex items-center gap-2 xl:ml-3">
        <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent header-cta hidden px-6 sm:inline-flex" data-magnetic="0.2"><?= e($ctaText) ?></a>
        <button type="button" class="header-round" data-search-open aria-label="Search the website (Ctrl+K)" aria-controls="site-search"><?= icon('search', 'h-[19px] w-[19px]') ?></button>
        <button type="button" class="header-round" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu"><?= icon('menu', 'h-5 w-5') ?></button>
      </div>
    </div>
  </div>
</header>

<!-- Side drawer (menu button) -->
<div id="mobile-menu" class="drawer hidden" role="dialog" aria-modal="true" aria-label="Menu">
  <div class="drawer-backdrop" data-menu-close></div>
  <div class="drawer-panel">
    <div class="flex items-center justify-between px-5 pb-3 pt-5">
      <img src="<?= e(logo_url()) ?>" alt="Global IMT" class="h-10 w-auto" loading="lazy" width="84" height="40">
      <button type="button" class="header-round drawer-close bg-brand-50" data-menu-close aria-label="Close menu"><?= icon('x', 'h-5 w-5') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Site menu">
      <div class="space-y-0.5" data-accordion="single">
        <?php $di = 0; foreach ($menu as $item): $kids = $item['children'] ?? []; $mega = !empty($item['is_mega']); $di++; ?>
          <?php if ($kids || ($mega && $programs)): ?>
            <details class="drawer-group drawer-item" style="--i:<?= $di ?>">
              <summary class="drawer-link"><?= e($item['title']) ?><?= icon('chevron-down', 'h-5 w-5 text-slate-400') ?></summary>
              <div class="mb-2 ml-3 border-l border-slate-200 pl-2">
                <a class="drawer-sublink font-semibold text-brand-900" href="<?= e(cms_link($item['url'])) ?>"><?= $mega ? 'All programs' : 'Overview' ?><?= icon('arrow-right', 'h-3.5 w-3.5') ?></a>
                <?php if ($kids && $mega): foreach ($kids as $col): foreach (($col['children'] ?? []) ?: [$col] as $c): ?>
                  <a class="drawer-sublink" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a>
                <?php endforeach; endforeach; elseif ($kids): foreach ($kids as $c): ?>
                  <a class="drawer-sublink" href="<?= e(cms_link($c['url'])) ?>"><?= !empty($c['icon']) ? icon($c['icon'], 'h-4 w-4 text-accent-600') : '' ?><?= e($c['title']) ?></a>
                <?php endforeach; else: foreach ($levels as $code => $label): $list = array_filter($programs, fn ($p) => $p['level'] === $code); ?>
                  <p class="drawer-label <?= level_hue($code) ?>"><span class="hue-dot" aria-hidden="true"></span><?= e($label) ?></p>
                  <?php foreach ($list as $p): ?><a class="drawer-sublink" href="<?= e(site_url('programs/' . $p['slug'])) ?>"><span class="drawer-badge <?= dept_hue($p) ?>"><?= e(str_limit($p['short_name'], 12)) ?></span><span class="truncate"><?= e($p['name']) ?></span></a><?php endforeach; ?>
                <?php endforeach; endif; ?>
              </div>
            </details>
          <?php else: ?>
            <a href="<?= e(cms_link($item['url'])) ?>" class="drawer-link drawer-item<?= $isActive($item) ? ' is-active' : '' ?>" style="--i:<?= $di ?>"<?= $isActive($item) ? ' aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </nav>
    <div class="space-y-2.5 border-t border-slate-200 p-4">
      <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent w-full drawer-item" style="--i:<?= ++$di ?>"><?= e($ctaText) ?><?= icon('arrow-right', 'h-4 w-4') ?></a>
      <a href="<?= e(site_tel($phone)) ?>" class="btn btn-outline w-full drawer-item" style="--i:<?= ++$di ?>"><?= icon('phone', 'h-4 w-4') ?><?= e($phone) ?></a>
      <div class="flex items-center justify-center gap-4 pt-1 text-xs text-slate-500 drawer-item" style="--i:<?= ++$di ?>">
        <a href="<?= e(site_url('portal/student/login')) ?>" class="hover:text-brand-900">Student Login</a><span aria-hidden="true">·</span>
        <a href="<?= e(site_url('portal/faculty/login')) ?>" class="hover:text-brand-900">Faculty Login</a><span aria-hidden="true">·</span>
        <a href="<?= e(admin_url('login')) ?>" class="hover:text-brand-900">Admin</a>
      </div>
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
        <kbd class="hidden rounded-md border border-slate-200 px-1.5 py-0.5 text-[11px] font-semibold text-slate-400 sm:inline">Esc</kbd>
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
