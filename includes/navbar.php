<?php
/**
 * Main navigation: sticky glass header (logo, CMS menu with animated dropdowns + Programs mega menu, search, Apply Now),
 * mobile drawer and search overlay. Included by includes/header.php.
 *
 * Menu items come from Website CMS > Menus (location "header"; items may carry icon + description, `is_mega` turns an
 * item into a mega menu whose children are columns). Sensible defaults are used when the menu is empty, and an empty
 * mega item is auto-filled from the programs table. Behaviour: frontend/site/core/{header,menu,overlays}.ts (works
 * without JS via :hover/:focus-within, <details> and plain links).
 */
$name = $name ?? institute_name();
$active = $active ?? 'home';
$phone = $phone ?? setting('phone', '+91 9955446477');
$email = $email ?? setting('email', 'info@gimt.ac.in');
$ctaText = $ctaText ?? setting('header_cta_text', 'Apply Now');
$ctaUrl = $ctaUrl ?? setting('header_cta_url', '/apply');
$menu = site_menu('header');
if (!$menu) {
    $menu = [
        ['title' => 'Home', 'url' => '/', 'children' => []],
        ['title' => 'About', 'url' => '/about', 'children' => [
            ['title' => 'About GIMT', 'url' => '/about', 'icon' => 'landmark', 'description' => 'Our story, campus and values', 'children' => []],
            ['title' => 'Vision & Mission', 'url' => '/about#vision', 'icon' => 'target', 'description' => 'What drives us every day', 'children' => []],
            ['title' => 'Leadership', 'url' => '/about#leadership', 'icon' => 'users', 'description' => 'Chairman, Director and Deans', 'children' => []],
            ['title' => 'Our Faculty', 'url' => '/faculty', 'icon' => 'graduation-cap', 'description' => 'Experienced academics & mentors', 'children' => []],
            ['title' => 'Approvals & Affiliations', 'url' => '/about#approvals', 'icon' => 'badge-check', 'description' => 'AICTE, UGC, NAAC & ISO', 'children' => []],
        ]],
        ['title' => 'Programs', 'url' => '/programs', 'is_mega' => 1, 'children' => []],
        ['title' => 'Admissions', 'url' => '/admissions', 'children' => []],
        ['title' => 'Academics', 'url' => '/academics', 'children' => []],
        ['title' => 'Campus Life', 'url' => '/campus', 'children' => []],
        ['title' => 'Placement', 'url' => '/placement', 'children' => []],
        ['title' => 'Alumni', 'url' => '/alumni', 'children' => []],
        ['title' => 'Blog', 'url' => '/blog', 'children' => []],
        ['title' => 'Contact', 'url' => '/contact', 'children' => []],
    ];
}
// Programs mega menu auto-filled from the programs table when the CMS item has no children
$programGroups = [];
try {
    foreach (db_all("SELECT name, short_name, slug, level, category, duration_label FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name") as $p) {
        $group = $p['level'] === 'UG' ? 'Undergraduate' : (in_array($p['level'], ['PG', 'PhD'], true) ? 'Postgraduate' : 'Diploma & Certificate');
        $programGroups[$group][] = $p;
    }
} catch (Throwable $e) {
}
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
$megaFeature = '<a href="' . e(site_url('admissions')) . '" class="mega-feature group" style="--i:0">'
    . '<img src="' . e(asset('assets/images/site/students-laptop.jpg')) . '" alt="" loading="lazy">'
    . '<span class="mb-auto inline-flex w-fit items-center gap-1.5 rounded-full bg-accent-600 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider">' . icon('sparkles', 'h-3 w-3') . e(setting('admissions_open_text', 'Admissions Open 2026-27')) . '</span>'
    . '<span class="font-display text-lg font-bold leading-snug">Find the program that fits your future</span>'
    . '<span class="mt-1 text-xs text-white/75">Industry-focused UG, PG, diploma &amp; certificate programs.</span>'
    . '<span class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-accent-300">Start your application' . icon('arrow-right', 'h-4 w-4 transition group-hover:translate-x-1') . '</span></a>';
// Tailwind (dynamic mega-menu column spans): col-span-3 col-span-4 col-span-9
$searchSuggestions = [['Programs', '/programs', 'graduation-cap'], ['Admissions', '/admissions', 'clipboard-check'], ['Fee Structure', '/admissions#fees', 'wallet'], ['Scholarships', '/admissions#scholarships', 'award'], ['Placements', '/placement', 'briefcase-business'], ['Contact', '/contact', 'phone']];
?>
<header id="site-header" class="site-header">
  <div class="site-header-bar">
    <div class="container-site relative flex h-[76px] items-center gap-4">
      <a href="<?= e(site_url('')) ?>" class="site-logo flex shrink-0 items-center gap-3" aria-label="<?= e($name) ?> - Home">
        <img src="<?= e(logo_url()) ?>" alt="Global IMT logo" class="h-12 w-auto sm:h-14" width="132" height="56">
        <span class="hidden font-display text-[11.5px] font-extrabold uppercase leading-tight tracking-wide text-brand-900 sm:block xl:hidden 2xl:block">Global Institute of<br>Management &amp; Technology</span>
      </a>

      <nav class="ml-auto hidden xl:block" aria-label="Main">
        <ul class="flex items-center">
          <?php foreach ($menu as $mi => $item):
              $kids = $item['children'] ?? [];
              $mega = !empty($item['is_mega']) && ($kids || $programGroups);
              $hasPanel = $mega || $kids;
              $act = $isActive($item) || (bool) array_filter($kids, $isActive);
              $panelId = 'nav-panel-' . $mi;
              ?>
            <li class="nav-item<?= $mega ? ' is-mega' : '' ?>"<?= $hasPanel ? ' data-nav-item' : '' ?>>
              <a href="<?= e(cms_link($item['url'] ?? '#')) ?>" class="nav-link<?= $act ? ' nav-link-active' : '' ?>"<?= $act ? ' aria-current="page"' : '' ?><?= ($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener"' : '' ?>><?= e($item['title']) ?></a>
              <?php if ($hasPanel): ?>
                <button type="button" class="nav-toggle" data-nav-toggle aria-expanded="false" aria-controls="<?= $panelId ?>"><span class="sr-only"><?= e($item['title']) ?> menu</span><?= icon('chevron-down', 'h-3.5 w-3.5') ?></button>
              <?php endif; ?>
              <?php if ($mega): ?>
                <div class="nav-panel nav-panel-mega" id="<?= $panelId ?>" data-nav-panel>
                  <div class="nav-panel-inner">
                    <div class="nav-stagger grid grid-cols-12 gap-6 p-6">
                      <div class="col-span-3" style="--i:0"><?= $megaFeature ?></div>
                      <?php if ($kids && !array_filter($kids, fn ($c) => !empty($c['children']))): /* flat CMS children: link grid */ ?>
                        <div class="col-span-9" style="--i:1"><div class="grid grid-cols-3 gap-x-2 gap-y-0.5"><?php foreach ($kids as $k => $c): ?><?= $navLink($c, $k) ?><?php endforeach; ?></div></div>
                      <?php elseif ($kids): $cols = count($kids); $span = max(3, intdiv(9, max(1, $cols))); ?>
                        <?php foreach ($kids as $ci => $col): ?>
                          <div class="col-span-<?= $span ?>" style="--i:<?= $ci + 1 ?>">
                            <p class="mega-heading"><?= e($col['title']) ?></p>
                            <div class="space-y-0.5"><?php foreach ($col['children'] ?? [] as $k => $c): ?><?= $navLink($c, $k) ?><?php endforeach; ?></div>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <?php foreach ($programGroups as $gname => $list): $wide = count($list) > 4; ?>
                          <div class="<?= $wide ? 'col-span-5' : 'col-span-2' ?>" style="--i:<?= $wide ? 1 : 2 ?>">
                            <p class="mega-heading"><?= e($gname) ?></p>
                            <div class="<?= $wide ? 'grid grid-cols-2 gap-x-2 gap-y-0.5' : 'space-y-0.5' ?>">
                              <?php foreach ($list as $k => $p): ?>
                                <?= $navLink(['title' => $p['short_name'], 'url' => '/programs/' . $p['slug'], 'icon' => program_icon($p), 'description' => trim(($p['category'] ?: program_level_label($p['level'])) . ($p['duration_label'] ? ' · ' . $p['duration_label'] : ''), ' ·')], $k) ?>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between gap-4 border-t border-slate-100 bg-slate-50/80 px-6 py-3.5 text-sm">
                      <span class="flex items-center gap-2 text-slate-600"><?= icon('message-circle', 'h-4 w-4 text-accent-600') ?>Not sure which program fits you? Talk to our counsellors — <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" class="font-semibold text-brand-900 hover:text-accent-600"><?= e($phone) ?></a></span>
                      <a href="<?= e(site_url('programs')) ?>" class="link-arrow">View all programs<?= icon('arrow-right', 'h-4 w-4') ?></a>
                    </div>
                  </div>
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

      <div class="ml-auto flex items-center gap-1.5 xl:ml-3">
        <button type="button" class="btn-icon h-10 w-10 rounded-xl" data-search-open aria-label="Search the website (Ctrl+K)" aria-controls="site-search"><?= icon('search', 'h-5 w-5') ?></button>
        <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent btn-sm btn-shine header-cta hidden px-4 py-2.5 sm:inline-flex" data-magnetic="0.2"><?= e($ctaText) ?><?= icon('arrow-right', 'h-4 w-4') ?></a>
        <button type="button" class="btn-icon h-10 w-10 rounded-xl xl:hidden" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu"><?= icon('menu', 'h-6 w-6') ?></button>
      </div>
    </div>
  </div>
</header>

<!-- Mobile navigation drawer -->
<div id="mobile-menu" class="drawer hidden" role="dialog" aria-modal="true" aria-label="Menu">
  <div class="drawer-backdrop" data-menu-close></div>
  <div class="drawer-panel">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
      <img src="<?= e(logo_url()) ?>" alt="Global IMT" class="h-11 w-auto" loading="lazy">
      <button type="button" class="btn-icon drawer-close h-10 w-10 rounded-xl" data-menu-close aria-label="Close menu"><?= icon('x', 'h-6 w-6') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Mobile">
      <div class="space-y-0.5" data-accordion="single">
        <?php $di = 0; foreach ($menu as $item): $kids = $item['children'] ?? []; $mega = !empty($item['is_mega']); $di++; ?>
          <?php if ($kids || ($mega && $programGroups)): ?>
            <details class="drawer-group drawer-item" style="--i:<?= $di ?>">
              <summary class="drawer-link"><?= e($item['title']) ?><?= icon('chevron-down', 'h-4 w-4 text-slate-400') ?></summary>
              <div class="mb-2 ml-3 border-l-2 border-slate-100 pl-2">
                <a class="drawer-sublink font-semibold text-brand-800" href="<?= e(cms_link($item['url'])) ?>">Overview<?= icon('arrow-right', 'h-3.5 w-3.5') ?></a>
                <?php if ($kids && $mega): foreach ($kids as $col): foreach (($col['children'] ?? []) ?: [$col] as $c): ?>
                  <a class="drawer-sublink" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a>
                <?php endforeach; endforeach; elseif ($kids): foreach ($kids as $c): ?>
                  <a class="drawer-sublink" href="<?= e(cms_link($c['url'])) ?>"><?= !empty($c['icon']) ? icon($c['icon'], 'h-4 w-4 text-brand-500') : '' ?><?= e($c['title']) ?></a>
                <?php endforeach; else: foreach ($programGroups as $gname => $list): ?>
                  <p class="px-3 pb-1 pt-2 text-[11px] font-bold uppercase tracking-wider text-accent-600"><?= e($gname) ?></p>
                  <?php foreach ($list as $p): ?><a class="drawer-sublink" href="<?= e(site_url('programs/' . $p['slug'])) ?>"><?= icon(program_icon($p), 'h-4 w-4 text-brand-500') ?><?= e($p['short_name']) ?></a><?php endforeach; ?>
                <?php endforeach; endif; ?>
              </div>
            </details>
          <?php else: ?>
            <a href="<?= e(cms_link($item['url'])) ?>" class="drawer-link drawer-item<?= $isActive($item) ? ' is-active' : '' ?>" style="--i:<?= $di ?>"<?= $isActive($item) ? ' aria-current="page"' : '' ?>><?= e($item['title']) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </nav>
    <div class="space-y-2 border-t border-slate-100 p-4">
      <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent w-full drawer-item" style="--i:<?= ++$di ?>"><?= e($ctaText) ?><?= icon('arrow-right', 'h-4 w-4') ?></a>
      <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" class="btn btn-secondary w-full drawer-item" style="--i:<?= ++$di ?>"><?= icon('phone', 'h-4 w-4') ?><?= e($phone) ?></a>
      <div class="flex items-center justify-center gap-4 pt-2 text-xs text-slate-500 drawer-item" style="--i:<?= ++$di ?>">
        <a href="<?= e(site_url('student-portal')) ?>" class="hover:text-brand-900">Student Login</a><span aria-hidden="true">·</span>
        <a href="<?= e(site_url('faculty-portal')) ?>" class="hover:text-brand-900">Faculty Login</a><span aria-hidden="true">·</span>
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
      <div class="flex items-center gap-3 rounded-2xl bg-white p-2 pl-5 shadow-pop">
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
