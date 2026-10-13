<?php
/**
 * News & Blog listing (/blog) — featured stories (page 1 of the unfiltered listing), category pills (blog_categories),
 * tag filter (?tag=), search (?q=), latest / most-read sort (?sort=popular), view modes (Grid / List / Compact /
 * Detailed, remembered per visitor), server-side pagination (?page=, rel prev/next) and a sidebar (categories with
 * counts, recent posts, popular topics, newsletter signup → POST /api/public/newsletter, admissions card).
 * Category pages: /blog?category={slug} (unknown slug → 404).
 * SEO: /blog page 1 uses the admin SEO record of route /blog; category pages + their pagination are indexable with a
 * self-referencing canonical; tag / search / sorted views are noindex,follow. CollectionPage + BreadcrumbList JSON-LD.
 * Helpers: includes/partials/news_gallery.php.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/news_gallery.php';

/* ------------------------------------------------------------------ request */
$categories = ng_categories();
$catSlug = strtolower(trim((string) (is_string($_GET['category'] ?? null) ? $_GET['category'] : '')));
$category = null;
if ($catSlug !== '') {
    foreach ($categories as $c) {
        if ($c['slug'] === $catSlug) {
            $category = $c;
        }
    }
    if (!$category) {
        ng_not_found('category');
    }
}
$tag = ng_clean_tag($_GET['tag'] ?? '');
$q = ng_clean_query($_GET['q'] ?? '');
$sort = ($_GET['sort'] ?? '') === 'popular' ? 'popular' : 'latest';
$rawPage = $_GET['page'] ?? '1';
$pageNo = is_string($rawPage) && ctype_digit($rawPage) ? max(1, (int) $rawPage) : 1;
$filtered = $tag !== '' || $q !== '' || $sort !== 'latest';
$isBase = !$category && !$filtered;
$params = ['category' => $category['slug'] ?? null, 'tag' => $tag, 'q' => $q, 'sort' => $sort];

/* ------------------------------------------------------------------ data */
$totals = ng_blog_totals();
// featured stories lead the unfiltered listing (page 1); they are excluded from its pages so nothing repeats
$featured = $isBase && $totals['posts'] > 6 ? ng_featured(4) : [];
$filters = ['category_id' => $category['id'] ?? null, 'tag' => $tag, 'q' => $q, 'exclude' => array_map(fn ($p) => (int) $p['id'], $featured)];
$total = ng_count_posts($filters);
$perPage = 10; // even: two columns in the grid view
$pg = paginate($total, $pageNo, $perPage);
if ($pageNo > $pg['pages']) {
    ng_not_found('page');
}
$posts = ng_posts($filters, $perPage, $pg['offset'], $sort);
if ($pg['page'] > 1) {
    $featured = [];
}
$lead = array_shift($featured);
$recent = ng_posts([], 5);
$topics = ng_popular_tags(14);

/* ------------------------------------------------------------------ SEO */
$pageUrl = fn (int $n) => ng_blog_url($params + ['page' => $n]);
$pageAbs = fn (int $n) => ng_blog_abs($params + ['page' => $n]);
$catName = $category['name'] ?? '';
$h1 = $category ? $catName : 'News & Blog';
$titleParts = [$category ? $catName . ' — News & Articles' : 'News & Blog'];
if ($tag !== '') {
    $titleParts[] = 'Tagged “' . $tag . '”';
}
if ($q !== '') {
    $titleParts[] = 'Search “' . $q . '”';
}
if ($sort === 'popular') {
    $titleParts[] = 'Most Read';
}
if ($pg['page'] > 1) {
    $titleParts[] = 'Page ' . $pg['page'];
}
$catDesc = rtrim(trim((string) ($category['description'] ?? '')), '.');
$description = $category
    // short admin descriptions ("Seminars, expert talks …") get the institute context search snippets need
    ? ($catDesc !== '' ? $catDesc . '. ' . (mb_strlen($catDesc) < 110 ? 'Latest ' . $catName . ' news and articles from GIMT, ' . setting('city', 'Greater Noida') . '.' : '') : 'Latest ' . $catName . ' news, stories and articles from Global Institute of Management & Technology (GIMT), Greater Noida.')
    : 'News, achievements, campus stories, events and articles from the students and faculty of Global Institute of Management & Technology (GIMT), Greater Noida.';
$description = trim($description);
if ($pg['page'] > 1) {
    $description .= ' Page ' . $pg['page'] . ' of ' . $pg['pages'] . '.';
}
$canonical = $pageAbs($pg['page']);
$crumbs = $category ? ['News & Blog' => '/blog', $catName] : ($filtered ? ['News & Blog' => '/blog', $q !== '' ? 'Search results' : ($tag !== '' ? '#' . $tag : 'Most read')] : ['News & Blog']);
$crumbLd = [['Home', ''], ['News & Blog', $category || $filtered ? 'blog' : null]];
if ($category) {
    $crumbLd[] = [$catName, null];
} elseif ($filtered) {
    $crumbLd[] = [$q !== '' ? 'Search results' : ($tag !== '' ? '#' . $tag : 'Most read'), null];
}
$schema = [ng_breadcrumb_ld($crumbLd, $canonical)];
$listed = array_merge($lead ? [$lead] : [], $featured, $posts);
if (!$filtered && $listed) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => implode(' — ', $titleParts), 'description' => $description, 'url' => $canonical,
        'isPartOf' => ['@type' => 'WebSite', 'name' => institute_name(), 'url' => absolute_url('')],
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($listed), 'itemListElement' => array_map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $pg['offset'] + $i + 1,
            'url' => absolute_url('blog/' . $p['slug']), 'name' => $p['title']], $listed, array_keys($listed))]];
}
$heroImage = $lead['thumbnail'] ?? ($posts[0]['thumbnail'] ?? null);
ng_site_header([
    'title' => implode(' — ', $titleParts),
    'description' => $description,
    'active' => 'blog',
    'seo' => $isBase && $pg['page'] === 1 ? ['route', '/blog'] : null,
    'canonical' => $canonical,
    'robots' => $filtered || ($category && $total === 0) ? 'noindex,follow' : 'index,follow', // empty category pages are thin
    'image' => $heroImage ?: 'assets/images/site/news-campus.jpg',
    'preload' => $lead ? [site_image($lead['thumbnail'] ?? null, 'assets/images/site/news-campus.jpg')] : [],
    'schema' => $schema,
], ng_rel_links($pg, $pageAbs));

/* ------------------------------------------------------------------ hero */
$chips = [];
if ($category) {
    $chips[] = ['icon' => 'newspaper', 'label' => number_format((int) $category['posts']) . ' article' . ((int) $category['posts'] === 1 ? '' : 's')];
} elseif ($totals['posts']) {
    $chips[] = ['icon' => 'newspaper', 'label' => number_format($totals['posts']) . ' articles'];
    $usedCats = count(array_filter($categories, fn ($c) => (int) $c['posts'] > 0));
    if ($usedCats) {
        $chips[] = ['icon' => 'folder-open', 'label' => $usedCats . ' categories'];
    }
}
$latest = $category ? ng_blog_totals((int) $category['id'])['latest'] : $totals['latest'];
if ($latest) {
    $chips[] = ['icon' => 'calendar-days', 'label' => 'Updated ' . date('d M Y', strtotime($latest))];
}
page_hero($h1, $category ? 'Stories from GIMT News & Blog' : 'Stories, achievements and updates from campus', $crumbs, '',
    $category ? ($catDesc !== '' ? $catDesc . '.' : $description) : 'Admission updates, student achievements, placement news, events and articles from the GIMT community — all in one place.', $chips,
    ['align' => 'center', 'eyebrow' => $category ? 'Category' : 'Newsroom', 'eyebrow_icon' => $category ? 'folder-open' : 'newspaper', 'highlight' => $category ? false : 'Blog',
     'actions' => ng_search_form($q, $category['slug'] ?? null)]);
?>

<?php if ($lead): ?>
<!-- Featured stories -->
<section class="relative bg-white pb-6 pt-10 sm:pt-14" aria-labelledby="featured-title">
  <div class="container-site">
    <div class="mb-6 flex items-end justify-between gap-4">
      <div>
        <p class="eyebrow eyebrow-line mb-2">Featured</p>
        <h2 id="featured-title" class="font-display text-2xl font-extrabold text-brand-900 sm:text-[1.75rem]">Top stories from campus</h2>
      </div>
    </div>
    <div class="grid gap-6 lg:grid-cols-12">
      <div class="lg:col-span-8"<?= reveal_attr('fade-up') ?>><?= ng_featured_card($lead) ?></div>
      <?php if ($featured): ?>
        <div class="rounded-[28px] border border-warm-2 bg-warm-50 p-6 lg:col-span-4"<?= reveal_attr('fade-up', 120) ?>>
          <h3 class="flex items-center gap-2 font-display text-base font-extrabold text-brand-900"><?= icon('star', 'h-4 w-4 text-accent-600') ?>Editor’s picks</h3>
          <ul class="mt-5 space-y-5">
            <?php foreach ($featured as $f): ?><?= ng_post_row($f, 'border-b border-warm-2 pb-5 last:border-0 last:pb-0') ?><?php endforeach; ?>
          </ul>
          <a href="#latest" class="link-arrow mt-6 text-sm">Browse all stories<?= icon('arrow-down', 'h-4 w-4') ?></a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Listing -->
<section id="latest" class="section section-light scroll-mt-24" aria-labelledby="latest-title">
  <div class="container-site">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="eyebrow mb-2"><?= $q !== '' ? 'Search' : ($tag !== '' ? 'Topic' : 'Browse') ?></p>
        <h2 id="latest-title" class="section-title !text-[clamp(1.6rem,1.1rem+1.6vw,2.2rem)]">
          <?php if ($q !== ''): ?>Results for “<?= e($q) ?>”<?php elseif ($tag !== ''): ?>Articles tagged #<?= e($tag) ?><?php elseif ($category): ?>Latest in <?= e($catName) ?><?php else: ?>Latest News &amp; Articles<?php endif; ?>
        </h2>
      </div>
      <p class="text-sm text-slate-600"><?= $total ? number_format($total) . ' article' . ($total === 1 ? '' : 's') . ($lead ? ' · ' . (count($featured) + 1) . ' featured above' : '') . ($sort === 'popular' ? ' · most read first' : ' · newest first') : 'No articles found' ?></p>
    </div>

    <?php if ($categories): ?>
      <nav class="pill-row scrollbar-none mt-7" aria-label="News categories">
        <a href="<?= e(ng_blog_url(['sort' => $sort])) ?>#latest" class="chip<?= !$category ? ' chip-active' : '' ?>"<?= !$category ? ' aria-current="page"' : '' ?>>All<span class="chip-count<?= !$category ? ' is-active' : '' ?>"><?= (int) $totals['posts'] ?></span></a>
        <?php foreach ($categories as $c): if ((int) $c['posts'] === 0 && ($category['id'] ?? 0) !== $c['id']) continue; $on = ($category['id'] ?? 0) === $c['id']; ?>
          <a href="<?= e(ng_blog_url(['category' => $c['slug'], 'sort' => $sort])) ?>#latest" class="chip<?= $on ? ' chip-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($c['name']) ?><span class="chip-count<?= $on ? ' is-active' : '' ?>"><?= (int) $c['posts'] ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <?php if ($tag !== '' || $q !== ''): ?>
          <span class="font-medium text-slate-600">Filters:</span>
          <?php if ($q !== ''): ?><a href="<?= e(ng_blog_url(['category' => $params['category'], 'tag' => $tag, 'sort' => $sort])) ?>#latest" class="inline-flex items-center gap-1.5 rounded-full bg-brand-900 py-1.5 pl-3.5 pr-2.5 text-[13px] font-semibold text-white transition hover:bg-brand-800">“<?= e(str_limit($q, 40)) ?>”<?= icon('x', 'h-3.5 w-3.5') ?><span class="sr-only">Remove search filter</span></a><?php endif; ?>
          <?php if ($tag !== ''): ?><a href="<?= e(ng_blog_url(['category' => $params['category'], 'q' => $q, 'sort' => $sort])) ?>#latest" class="inline-flex items-center gap-1.5 rounded-full bg-brand-900 py-1.5 pl-3.5 pr-2.5 text-[13px] font-semibold text-white transition hover:bg-brand-800">#<?= e($tag) ?><?= icon('x', 'h-3.5 w-3.5') ?><span class="sr-only">Remove tag filter</span></a><?php endif; ?>
          <a href="<?= e(ng_blog_url(['category' => $params['category']])) ?>#latest" class="font-semibold text-accent-700 underline-offset-4 hover:underline">Clear all</a>
        <?php endif; ?>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="view-toggle" role="group" aria-label="Sort articles">
          <a href="<?= e(ng_blog_url(['sort' => 'latest'] + $params)) ?>#latest" class="view-toggle-btn<?= $sort === 'latest' ? ' is-active' : '' ?>"<?= $sort === 'latest' ? ' aria-current="true"' : '' ?>><?= icon('clock', 'h-4 w-4') ?><span class="ml-1.5">Latest</span></a>
          <a href="<?= e(ng_blog_url(['sort' => 'popular'] + $params)) ?>#latest" class="view-toggle-btn<?= $sort === 'popular' ? ' is-active' : '' ?>"<?= $sort === 'popular' ? ' aria-current="true"' : '' ?>><?= icon('trending-up', 'h-4 w-4') ?><span class="ml-1.5">Most read</span></a>
        </div>
        <?php if ($posts): ?><div class="hidden [.js_&]:block"><?= view_mode_toggle('news') ?></div><?php endif; ?>
      </div>
    </div>

    <div class="mt-8 grid gap-10 lg:grid-cols-12 lg:gap-8 xl:gap-12">
      <div class="min-w-0 lg:col-span-8">
        <?php if ($posts): ?>
          <?= view_mode_open('news', ['class' => 'sm:[&[data-view=detailed]_.view-item]:grid sm:[&[data-view=detailed]_.view-item]:grid-cols-[minmax(0,280px)_minmax(0,1fr)] sm:[&[data-view=detailed]_.vm-media]:aspect-auto sm:[&[data-view=detailed]_.vm-media]:min-h-full']) // detailed = wide rows with tags + reads (the shared single-column detailed cards are too tall inside the 8/12 column) ?>
            <?php foreach ($posts as $p): ?><?= ng_post_card($p) ?><?php endforeach; ?>
          <?= view_mode_close() ?>
          <?= ng_pagination($pg, $pageUrl, 'News pagination', 'articles') ?>
        <?php elseif ($filtered || $category): ?>
          <div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-white">
            <span class="empty-state-icon"><?= icon('search-check', 'h-6 w-6') ?></span>
            <?php if ($filtered): ?>
              <h3 class="mt-4 font-display text-lg font-bold text-brand-900">No articles match <?= $q !== '' ? '“' . e($q) . '”' : 'these filters' ?></h3>
              <p class="mt-2 max-w-md text-sm text-slate-600">Try a different keyword, check the spelling, or browse a popular topic below.</p>
            <?php else: ?>
              <h3 class="mt-4 font-display text-lg font-bold text-brand-900">No <?= e($catName) ?> articles yet</h3>
              <p class="mt-2 max-w-md text-sm text-slate-600">New stories in this category will appear here as soon as they are published. Meanwhile, read our latest news or browse a popular topic below.</p>
            <?php endif; ?>
            <div class="mt-5 flex flex-wrap justify-center gap-2.5">
              <a href="<?= e(ng_blog_url()) ?>#latest" class="btn btn-explore btn-sm">View all articles<?= icon('arrow-right', 'h-4 w-4') ?></a>
              <?php if ($category && ($tag !== '' || $q !== '')): ?><a href="<?= e(ng_blog_url(['category' => $category['slug']])) ?>#latest" class="btn btn-brochure btn-sm">All <?= e($catName) ?> articles</a><?php endif; ?>
            </div>
            <?php if ($topics): ?>
              <ul class="mt-6 flex max-w-lg flex-wrap justify-center gap-2">
                <?php foreach (array_slice($topics, 0, 8) as $t): ?><li><a href="<?= e(ng_blog_url(['tag' => $t['key']])) ?>#latest" class="inline-block rounded-full bg-warm px-3 py-1.5 text-[13px] font-medium text-slate-700 transition hover:bg-brand-900 hover:text-white">#<?= e($t['tag']) ?></a></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        <?php elseif (!$lead): ?>
          <div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-white">
            <span class="empty-state-icon"><?= icon('newspaper', 'h-6 w-6') ?></span>
            <h3 class="mt-4 font-display text-lg font-bold text-brand-900">News and stories are on their way</h3>
            <p class="mt-2 max-w-md text-sm text-slate-600">Our team publishes admission updates, achievements and campus stories here. Subscribe to the newsletter to hear first, or explore our programs meanwhile.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-2.5"><?= button_link('Explore Courses', '/programs', 'explore', ['size' => 'sm']) ?><?= button_link('Contact Us', '/contact', 'brochure', ['size' => 'sm', 'icon' => '']) ?></div>
          </div>
        <?php else: ?>
          <p class="rounded-[22px] border border-dashed border-warm-line bg-white p-6 text-center text-sm text-slate-600">That is everything for now — the stories above are our latest. New articles appear here as they are published.</p>
        <?php endif; ?>
      </div>

      <!-- Sidebar -->
      <aside class="lg:col-span-4" aria-label="More from the newsroom">
        <div class="space-y-6">
          <?php if ($categories): ?>
            <section class="rounded-[22px] border border-warm-2 bg-white p-6" aria-labelledby="side-cats">
              <h2 id="side-cats" class="font-display text-base font-extrabold text-brand-900">Categories</h2>
              <ul class="mt-3 space-y-0.5">
                <?php foreach ($categories as $c): $on = ($category['id'] ?? 0) === $c['id']; if ((int) $c['posts'] === 0 && !$on) continue; ?>
                  <li><a href="<?= e(ng_blog_url(['category' => $c['slug']])) ?>#latest" class="group flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-[14.5px] font-medium transition <?= $on ? 'bg-brand-900 text-white' : 'text-slate-700 hover:bg-warm hover:text-brand-900' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
                    <span class="flex items-center gap-2"><?= icon('folder', 'h-4 w-4 ' . ($on ? 'text-accent-300' : 'text-slate-400 group-hover:text-accent-600')) ?><?= e($c['name']) ?></span>
                    <span class="rounded-full px-2 py-0.5 text-xs font-bold <?= $on ? 'bg-white/15 text-white' : 'bg-warm text-slate-600' ?>"><?= (int) $c['posts'] ?><span class="sr-only"> articles</span></span></a></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>

          <?php if ($recent): ?>
            <section class="rounded-[22px] border border-warm-2 bg-white p-6" aria-labelledby="side-recent">
              <h2 id="side-recent" class="font-display text-base font-extrabold text-brand-900">Recent posts</h2>
              <ul class="mt-4 space-y-4"><?php foreach ($recent as $r): ?><?= ng_post_row($r) ?><?php endforeach; ?></ul>
            </section>
          <?php endif; ?>

          <?php if ($topics): ?>
            <section class="rounded-[22px] border border-warm-2 bg-white p-6" aria-labelledby="side-topics">
              <h2 id="side-topics" class="font-display text-base font-extrabold text-brand-900">Popular topics</h2>
              <ul class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($topics as $t): $on = $tag === $t['key']; ?>
                  <li><a href="<?= e(ng_blog_url(['tag' => $t['key']])) ?>#latest" class="inline-block rounded-full px-3 py-1.5 text-[13px] font-medium transition <?= $on ? 'bg-brand-900 text-white' : 'bg-warm text-slate-700 hover:bg-brand-900 hover:text-white' ?>"<?= $on ? ' aria-current="true"' : '' ?>>#<?= e($t['tag']) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>

          <section class="rounded-[22px] border border-warm-2 bg-warm-50 p-6" aria-labelledby="side-news">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-accent-50 text-accent-700 ring-1 ring-inset ring-accent-100"><?= icon('mail-plus', 'h-5 w-5') ?></span>
            <h2 id="side-news" class="mt-4 font-display text-base font-extrabold text-brand-900">Get campus news in your inbox</h2>
            <p class="mt-1.5 text-sm leading-relaxed text-slate-600">Admission deadlines, events and student achievements — a short update when it matters.</p>
            <?= ng_newsletter_form('blog-sidebar') ?>
          </section>

          <?= ng_admission_card() ?>
        </div>
      </aside>
    </div>
  </div>
</section>

<?php site_footer([
    'cta_title' => 'Be Part of the Next Success Story',
    'cta_text' => 'Join a campus where students build, compete, lead and get placed. Admissions are open for industry-focused UG, PG and diploma programs.',
    'cta_highlight' => 'Success',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
