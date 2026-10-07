<?php
/**
 * Main navigation bar: logo, CMS-driven menu (with Programs mega menu), search, Apply Now,
 * mobile drawer and search overlay. Included by includes/header.php.
 * Menu items come from Website CMS > Menus (location "header"); a sensible default is used when empty.
 */
$name = $name ?? institute_name();
$active = $active ?? 'home';
$phone = $phone ?? setting('phone', '+91 9955446477');
$ctaText = $ctaText ?? setting('header_cta_text', 'Apply Now');
$ctaUrl = $ctaUrl ?? setting('header_cta_url', '/apply');
$menu = site_menu('header');
if (!$menu) {
    $menu = [
        ['title' => 'Home', 'url' => '/', 'children' => []],
        ['title' => 'About', 'url' => '/about', 'children' => [
            ['title' => 'About GIMT', 'url' => '/about', 'children' => []], ['title' => 'Vision & Mission', 'url' => '/about#vision', 'children' => []],
            ['title' => 'Leadership', 'url' => '/about#leadership', 'children' => []], ['title' => 'Our Faculty', 'url' => '/faculty', 'children' => []], ['title' => 'Approvals & Affiliations', 'url' => '/about#approvals', 'children' => []],
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
// Programs mega menu auto-filled from the programs table when it has no children
$programGroups = [];
try {
    foreach (db_all("SELECT name, short_name, slug, level FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name") as $p) {
        $group = in_array($p['level'], ['UG'], true) ? 'Undergraduate' : (in_array($p['level'], ['PG', 'PhD'], true) ? 'Postgraduate' : 'Diploma & Certificate');
        $programGroups[$group][] = $p;
    }
} catch (Throwable $e) {
}
$isActive = function (array $item) use ($active): bool {
    $u = trim(parse_url((string) ($item['url'] ?? ''), PHP_URL_PATH) ?? '', '/');
    $u = $u === '' ? 'home' : $u;
    return $u === $active || ($u !== 'home' && str_starts_with($active, $u . '/'));
};
?>
<header id="site-header" class="sticky top-0 z-40 border-b border-slate-200/70 bg-white/95 backdrop-blur transition-shadow">
  <div class="container-site flex h-[76px] items-center gap-4">
    <a href="<?= e(site_url('')) ?>" class="flex shrink-0 items-center gap-3" aria-label="<?= e($name) ?> - Home">
      <img src="<?= e(logo_url()) ?>" alt="Global IMT logo" class="h-12 w-auto sm:h-14" width="132" height="56">
      <span class="hidden font-display text-[11.5px] font-extrabold uppercase leading-tight tracking-wide text-brand-900 xl:block">Global Institute of<br>Management &amp; Technology</span>
    </a>

    <nav class="ml-auto hidden items-center lg:flex" aria-label="Main">
      <?php foreach ($menu as $item):
          $hasKids = !empty($item['children']) || (!empty($item['is_mega']) && $programGroups);
          $act = $isActive($item) || array_filter($item['children'] ?? [], $isActive);
          ?>
        <div class="group relative">
          <a href="<?= e(cms_link($item['url'] ?? '#')) ?>" class="nav-link inline-flex items-center gap-1 <?= $act ? 'nav-link-active' : '' ?>" <?= $act ? 'aria-current="page"' : '' ?> <?= !empty($item['target']) && $item['target'] === '_blank' ? 'target="_blank" rel="noopener"' : '' ?>>
            <?= e($item['title']) ?><?php if ($hasKids): ?><?= icon('chevron-down', 'h-3.5 w-3.5 opacity-60 transition group-hover:rotate-180') ?><?php endif; ?>
          </a>
          <?php if (!empty($item['is_mega']) && $hasKids): ?>
            <div class="invisible absolute left-1/2 top-full z-50 w-[680px] -translate-x-1/2 pt-3 opacity-0 transition duration-200 group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100">
              <div class="grid grid-cols-3 gap-6 rounded-2xl border border-slate-100 bg-white p-6 shadow-pop">
                <?php if (!empty($item['children'])): ?>
                  <?php foreach ($item['children'] as $col): ?>
                    <div><p class="mb-3 text-xs font-bold uppercase tracking-wider text-accent-600"><?= e($col['title']) ?></p>
                      <ul class="space-y-1.5"><?php foreach ($col['children'] as $c): ?><li><a class="block rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-900" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a></li><?php endforeach; ?></ul></div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <?php foreach ($programGroups as $gname => $list): ?>
                    <div><p class="mb-3 text-xs font-bold uppercase tracking-wider text-accent-600"><?= e($gname) ?></p>
                      <ul class="space-y-1"><?php foreach ($list as $p): ?><li><a class="block rounded-lg px-2 py-1.5 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-900" href="<?= e(site_url('programs/' . $p['slug'])) ?>"><span class="font-semibold text-brand-900"><?= e($p['short_name']) ?></span><span class="block truncate text-xs text-slate-500"><?= e($p['name']) ?></span></a></li><?php endforeach; ?></ul></div>
                  <?php endforeach; ?>
                <?php endif; ?>
                <div class="col-span-3 -mx-6 -mb-6 mt-1 flex items-center justify-between rounded-b-2xl bg-slate-50 px-6 py-3 text-sm">
                  <span class="text-slate-600">Not sure which program fits you? Talk to our counsellors.</span>
                  <a href="<?= e(site_url('programs')) ?>" class="font-semibold text-brand-800 hover:text-accent-600">View all programs →</a>
                </div>
              </div>
            </div>
          <?php elseif (!empty($item['children'])): ?>
            <div class="invisible absolute left-0 top-full z-50 min-w-[230px] pt-3 opacity-0 transition duration-200 group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100">
              <ul class="rounded-2xl border border-slate-100 bg-white p-2 shadow-pop">
                <?php foreach ($item['children'] as $c): ?><li><a class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-900" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </nav>

    <div class="ml-auto flex items-center gap-2 lg:ml-2">
      <button type="button" class="btn-icon" data-search-open aria-label="Search the website"><?= icon('search', 'h-5 w-5') ?></button>
      <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent btn-sm hidden sm:inline-flex"><?= e($ctaText) ?><?= icon('arrow-right', 'h-4 w-4') ?></a>
      <button type="button" class="btn-icon lg:hidden" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu"><?= icon('menu', 'h-6 w-6') ?></button>
    </div>
  </div>
</header>

<!-- Mobile navigation drawer -->
<div id="mobile-menu" class="fixed inset-0 z-50 hidden lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
  <div class="absolute inset-0 bg-slate-900/50" data-menu-close></div>
  <div class="absolute inset-y-0 right-0 flex w-[86%] max-w-sm flex-col bg-white shadow-2xl">
    <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
      <img src="<?= e(logo_url()) ?>" alt="Global IMT" class="h-11 w-auto">
      <button type="button" class="btn-icon" data-menu-close aria-label="Close menu"><?= icon('x', 'h-6 w-6') ?></button>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Mobile">
      <?php foreach ($menu as $item): $kids = $item['children'] ?? []; ?>
        <?php if ($kids || (!empty($item['is_mega']) && $programGroups)): ?>
          <details class="group">
            <summary class="flex cursor-pointer list-none items-center justify-between rounded-xl px-3 py-3 text-[15px] font-semibold text-brand-900 hover:bg-slate-50"><?= e($item['title']) ?><?= icon('chevron-down', 'h-4 w-4 transition group-open:rotate-180') ?></summary>
            <div class="mb-2 ml-3 border-l border-slate-200 pl-3">
              <a class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" href="<?= e(cms_link($item['url'])) ?>">Overview</a>
              <?php if ($kids && !empty($item['is_mega'])): foreach ($kids as $col): foreach ($col['children'] as $c): ?>
                <a class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a>
              <?php endforeach; endforeach; elseif ($kids): foreach ($kids as $c): ?>
                <a class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" href="<?= e(cms_link($c['url'])) ?>"><?= e($c['title']) ?></a>
              <?php endforeach; else: foreach ($programGroups as $list): foreach ($list as $p): ?>
                <a class="block rounded-lg px-3 py-2 text-sm text-slate-600 hover:bg-slate-50" href="<?= e(site_url('programs/' . $p['slug'])) ?>"><?= e($p['short_name']) ?></a>
              <?php endforeach; endforeach; endif; ?>
            </div>
          </details>
        <?php else: ?>
          <a href="<?= e(cms_link($item['url'])) ?>" class="block rounded-xl px-3 py-3 text-[15px] font-semibold <?= $isActive($item) ? 'bg-brand-50 text-brand-900' : 'text-brand-900 hover:bg-slate-50' ?>"><?= e($item['title']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="space-y-2 border-t border-slate-100 p-4">
      <a href="<?= e(cms_link($ctaUrl)) ?>" class="btn btn-accent w-full"><?= e($ctaText) ?></a>
      <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" class="btn btn-secondary w-full"><?= icon('phone', 'h-4 w-4') ?><?= e($phone) ?></a>
    </div>
  </div>
</div>

<!-- Site search overlay -->
<div id="site-search" class="fixed inset-0 z-[60] hidden" role="dialog" aria-modal="true" aria-label="Search">
  <div class="absolute inset-0 bg-brand-950/70 backdrop-blur-sm" data-search-close></div>
  <form action="<?= e(site_url('search')) ?>" method="get" class="relative mx-auto mt-[12vh] w-[92%] max-w-2xl">
    <label for="site-search-input" class="sr-only">Search</label>
    <div class="flex items-center gap-3 rounded-2xl bg-white p-2 pl-5 shadow-pop">
      <?= icon('search', 'h-5 w-5 text-slate-400') ?>
      <input id="site-search-input" name="q" type="search" placeholder="Search programs, news, events, notices…" class="h-12 flex-1 border-0 text-base focus:ring-0" autocomplete="off">
      <button class="btn btn-primary">Search</button>
    </div>
  </form>
</div>

