<?php
/**
 * Photo gallery.
 *   /gallery              albums grid (cover mosaic, title, photo/video count, date, description), category filter
 *                         (?category=, noindex), server-side pagination (?page=, rel prev/next), latest stories.
 *   /gallery/{slug}       album view (the router passes ?slug=): hero, GalleryLightbox island over the photos (arrows,
 *                         ←/→ keys, swipe, zoom, thumbnails, captions), videos (YouTube / Vimeo / MP4 in the lightbox),
 *                         share links, related albums. Unknown or unpublished album → 404.
 * Data: galleries (status = 'published', admin sort order), gallery_items + media (path, size, alt text, captions).
 * SEO: route /gallery (admin SEO record) on the listing, canonical URLs, CollectionPage / ImageGallery + BreadcrumbList
 * JSON-LD, lazy images with dimensions. Helpers: includes/partials/news_gallery.php.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/news_gallery.php';

$slug = strtolower(trim((string) (is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '')));

/* ================================================================== album view */
if ($slug !== '') {
    $album = ng_album_by_slug($slug);
    if (!$album) {
        ng_not_found('album');
    }
    [$photos, $videos] = ng_album_items($album);
    $title = (string) $album['title'];
    $catLabel = !empty($album['category']) ? ng_album_category_label($album['category']) : '';
    $dateLabel = !empty($album['event_date']) ? date('d M Y', strtotime($album['event_date'])) : '';
    $cover = $album['cover'] !== '' ? $album['cover'] : ($photos[0]['path'] ?? 'assets/images/site/campus-building.jpg');
    $second = null;
    foreach ($photos as $p) {
        if ($p['path'] !== $cover) {
            $second = $p['path'];
            break;
        }
    }
    $coverAlt = $title;
    foreach ($photos as $p) {
        if ($p['path'] === $cover) {
            $coverAlt = $p['alt'];
            break;
        }
    }
    $nP = count($photos);
    $nV = count($videos);
    $countLabel = $nP . ' photo' . ($nP === 1 ? '' : 's') . ($nV ? ' · ' . $nV . ' video' . ($nV === 1 ? '' : 's') : '');
    $desc = trim((string) $album['description']);
    $metaDesc = ($desc !== '' ? $desc . ' ' : '') . $countLabel . ' from ' . $title . ' at GIMT, ' . setting('city', 'Greater Noida') . ($dateLabel ? ' (' . $dateLabel . ')' : '') . '.';
    $canonical = ng_gallery_abs($album['slug']);
    // related: same category first, then the admin order
    $others = ng_albums(null, 24, 0, [(int) $album['id']]);
    usort($others, fn ($a, $b) => [(string) $b['category'] === (string) $album['category'], (int) $a['sort_order'], (int) $a['id']] <=> [(string) $a['category'] === (string) $album['category'], (int) $b['sort_order'], (int) $b['id']]);
    $related = array_slice($others, 0, 3);

    $images = [];
    foreach (array_slice($photos, 0, 60) as $p) {
        $images[] = array_filter(['@type' => 'ImageObject', 'contentUrl' => ng_abs($p['path']), 'caption' => $p['caption'] !== '' ? $p['caption'] : $p['alt'],
            'width' => $p['width'], 'height' => $p['height']], fn ($v) => $v !== null && $v !== '');
    }
    $schema = [
        ng_breadcrumb_ld([['Home', ''], ['Gallery', 'gallery'], [$title, null]], $canonical),
        array_filter(['@context' => 'https://schema.org', '@type' => 'ImageGallery', 'name' => $title, 'description' => $metaDesc, 'url' => $canonical,
            'datePublished' => !empty($album['event_date']) ? $album['event_date'] : null, 'image' => $images ?: null,
            'isPartOf' => ['@type' => 'CollectionPage', 'name' => 'Photo Gallery', 'url' => ng_gallery_abs()],
            'publisher' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'url' => absolute_url('')]], fn ($v) => $v !== null),
    ];
    site_header([
        'title' => $title . ' — Photo Gallery',
        'description' => str_limit($metaDesc, 300),
        'active' => 'gallery',
        'seo' => ['route', '/gallery/' . $album['slug']], // admin SEO record of this URL (Website → SEO → Routes), if any
        'canonical' => $canonical,
        'image' => $cover,
        'preload' => [site_image($cover)],
        'schema' => $schema,
    ]);
    $chips = [['icon' => 'images', 'label' => $nP . ' photo' . ($nP === 1 ? '' : 's')]];
    if ($nV) {
        $chips[] = ['icon' => 'video', 'label' => $nV . ' video' . ($nV === 1 ? '' : 's')];
    }
    if ($dateLabel) {
        $chips[] = ['icon' => 'calendar-days', 'label' => $dateLabel];
    }
    page_hero($title, trim($catLabel . ($catLabel !== '' && $dateLabel ? ' · ' : '') . $dateLabel), ['Gallery' => '/gallery', $title], $cover,
        $desc !== '' ? $desc : 'Photos from ' . $title . ' at GIMT.', $chips, [
            'eyebrow' => 'Photo Album', 'eyebrow_icon' => 'images', 'image_alt' => $coverAlt, 'image2' => $second ?: false,
            'badge' => ['icon' => 'images', 'text' => $countLabel],
            'actions' => ($nP ? '<a href="#photos" class="btn btn-explore">' . icon('images', 'h-4 w-4') . 'View Photos</a>' : '')
                . button_link('All Albums', '/gallery', 'brochure', ['icon' => '', 'icon_left' => 'layout-grid']),
        ]);
    [$layout, $cols, $layoutClass] = ng_photo_layout($photos);
    ?>

<section id="photos" class="section scroll-mt-24 bg-white" aria-labelledby="photos-title">
  <div class="container-site">
    <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="eyebrow mb-2"><?= e($catLabel !== '' ? $catLabel : 'Album') ?></p>
        <h2 id="photos-title" class="section-title !text-[clamp(1.6rem,1.1rem+1.6vw,2.2rem)]">Photos</h2>
        <?php if ($nP): ?><p class="mt-2 text-sm text-slate-600"><?= e($countLabel) ?> · Select a photo to open the viewer — use the arrow keys or swipe to browse.</p><?php endif; ?>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-semibold text-brand-900">Share album</span>
        <?= ng_share_buttons($canonical, $title . ' — GIMT Photo Gallery') ?>
      </div>
    </div>
    <?php if ($nP): ?>
      <?= ng_photo_grid($photos, ['columns' => $cols, 'layout' => $layout, 'class' => $layoutClass, 'ariaLabel' => $title . ' — photos']) ?>
    <?php elseif (!$nV): ?>
      <div class="empty-state rounded-[22px] border border-dashed border-warm-line bg-warm-50">
        <span class="empty-state-icon"><?= icon('images', 'h-6 w-6') ?></span>
        <h3 class="mt-4 font-display text-lg font-bold text-brand-900">Photos are being added to this album</h3>
        <p class="mt-2 max-w-md text-sm text-slate-600">Please check back soon, or browse our other albums.</p>
        <a href="<?= e(ng_gallery_url()) ?>" class="btn btn-explore btn-sm mt-5">Browse all albums<?= icon('arrow-right', 'h-4 w-4') ?></a>
      </div>
    <?php endif; ?>

    <?php if ($nV): ?>
      <div class="<?= $nP ? 'mt-14' : '' ?>">
        <h2 class="flex items-center gap-2 font-display text-xl font-extrabold text-brand-900"><?= icon('circle-play', 'h-5 w-5 text-accent-700') ?>Videos</h2>
        <div class="mt-5"><?= ng_video_grid($videos, site_image($cover)) ?></div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($related): ?>
<?= section_open(['id' => 'more-albums', 'bg' => 'light']) ?>
  <?= section_heading('More Albums', 'More moments from campus life, events and celebrations at GIMT.', 'Keep exploring', 'left', button_link('View All Albums', '/gallery', 'brochure', ['icon' => 'arrow-right'])) ?>
  <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
    <?php foreach ($related as $a): ?><?= ng_album_card($a) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<?= ng_share_script() ?>
<?php site_footer([
        'cta_title' => 'See It for Yourself — Visit Our Campus',
        'cta_text' => 'Walk through our classrooms, labs, library and hostels, meet faculty and get your admission questions answered.',
        'cta_highlight' => 'Campus',
        'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
        'cta_secondary' => ['label' => 'Book a Campus Visit', 'url' => '/contact?type=campus_visit#message', 'icon' => 'calendar-check'],
    ]);
    exit;
}

/* ================================================================== albums listing */
$categories = ng_album_categories();
$catKey = strtolower(trim((string) (is_string($_GET['category'] ?? null) ? $_GET['category'] : '')));
$category = null;
if ($catKey !== '') {
    foreach ($categories as $c) {
        if (strtolower($c['key']) === $catKey) {
            $category = $c;
        }
    }
    if (!$category) {
        ng_not_found('album');
    }
}
$rawPage = $_GET['page'] ?? '1';
$pageNo = is_string($rawPage) && ctype_digit($rawPage) ? max(1, (int) $rawPage) : 1;
$perPage = 12;
$total = ng_album_total($category['key'] ?? null);
$pg = paginate($total, $pageNo, $perPage);
if ($pageNo > $pg['pages']) {
    ng_not_found('album');
}
$albums = ng_albums($category['key'] ?? null, $perPage, $pg['offset']);
$totals = ng_gallery_totals();
$stories = ng_posts([], 3);
$isBase = !$category;

$params = ['category' => $category['key'] ?? null];
$pageUrl = fn (int $n) => ng_gallery_url(null, $params + ['page' => $n]);
$pageAbs = fn (int $n) => ng_gallery_abs(null, $params + ['page' => $n]);
$canonical = $pageAbs($pg['page']);
$titleText = 'Photo Gallery' . ($category ? ' — ' . $category['label'] : '') . ($pg['page'] > 1 ? ' — Page ' . $pg['page'] : '');
$description = 'Photo albums from Global Institute of Management & Technology (GIMT), ' . setting('city', 'Greater Noida') . ' — campus, labs, events, sports, cultural fests and convocation'
    . ($category ? ' (' . $category['label'] . ')' : '') . '.' . ($pg['page'] > 1 ? ' Page ' . $pg['page'] . ' of ' . $pg['pages'] . '.' : '');
$schema = [ng_breadcrumb_ld($category ? [['Home', ''], ['Gallery', 'gallery'], [$category['label'], null]] : [['Home', ''], ['Gallery', null]], $canonical)];
if ($isBase && $albums) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'Photo Gallery', 'description' => $description, 'url' => $canonical,
        'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => count($albums), 'itemListElement' => array_map(fn ($a, $i) => array_filter(['@type' => 'ListItem', 'position' => $pg['offset'] + $i + 1,
            'url' => ng_gallery_abs($a['slug']), 'name' => $a['title'], 'image' => $a['cover'] !== '' ? ng_abs($a['cover']) : null]), $albums, array_keys($albums))]];
}
$heroCover = $albums[0]['cover'] ?? '';
$heroSecond = $albums[1]['cover'] ?? ($albums[0]['previews'][0]['path'] ?? null);
ng_site_header([
    'title' => $titleText,
    'description' => $description,
    'active' => 'gallery',
    'seo' => $isBase && $pg['page'] === 1 ? ['route', '/gallery'] : null,
    'canonical' => $canonical,
    'robots' => $category ? 'noindex,follow' : 'index,follow',
    'image' => $heroCover !== '' ? $heroCover : 'assets/images/site/students-atrium.jpg',
    'preload' => [site_image($heroCover !== '' ? $heroCover : 'assets/images/site/students-atrium.jpg')],
    'schema' => $schema,
], ng_rel_links($pg, $pageAbs));

$chips = [];
if ($totals['albums']) {
    $chips[] = ['icon' => 'folder-open', 'label' => $totals['albums'] . ' album' . ($totals['albums'] === 1 ? '' : 's')];
}
if ($totals['photos']) {
    $chips[] = ['icon' => 'images', 'label' => number_format($totals['photos']) . ' photos'];
}
if ($totals['videos']) {
    $chips[] = ['icon' => 'video', 'label' => number_format($totals['videos']) . ' video' . ($totals['videos'] === 1 ? '' : 's')];
}
page_hero('Photo Gallery', $category ? $category['label'] . ' albums' : 'Moments from campus life at GIMT', $category ? ['Gallery' => '/gallery', $category['label']] : ['Gallery'],
    $heroCover !== '' ? $heroCover : 'assets/images/site/students-atrium.jpg',
    'Explore our campus, classrooms and labs, and relive the events, fests, sports meets and convocations that make student life at GIMT memorable.', $chips, [
        'eyebrow' => 'Campus Life', 'eyebrow_icon' => 'images', 'highlight' => 'Gallery', 'image_alt' => $heroCover !== '' ? (ng_media_alt($heroCover) ?? 'From the album ' . $albums[0]['title']) : 'Students in the atrium at GIMT',
        'image2' => $heroSecond ?: false,
        'badge' => $totals['photos'] ? ['icon' => 'images', 'text' => number_format($totals['photos']) . ' photos · ' . $totals['albums'] . ' albums'] : false,
        'actions' => button_link('Explore the Campus', '/campus', 'explore') . button_link('Book a Campus Visit', '/contact?type=campus_visit#message', 'callback', ['icon_left' => 'calendar-check', 'icon' => '']),
    ]);
?>

<section id="albums" class="section section-light scroll-mt-24" aria-labelledby="albums-title">
  <div class="container-site">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="eyebrow mb-2">Albums</p>
        <h2 id="albums-title" class="section-title !text-[clamp(1.6rem,1.1rem+1.6vw,2.2rem)]"><?= $category ? e($category['label']) . ' Albums' : 'Browse Our Albums' ?></h2>
      </div>
      <p class="text-sm text-slate-600"><?= $total ? $total . ' album' . ($total === 1 ? '' : 's') : 'No albums yet' ?></p>
    </div>

    <?php if (count($categories) > 1): ?>
      <nav class="pill-row scrollbar-none mt-7" aria-label="Album categories">
        <a href="<?= e(ng_gallery_url()) ?>#albums" class="chip<?= !$category ? ' chip-active' : '' ?>"<?= !$category ? ' aria-current="page"' : '' ?>>All<span class="chip-count<?= !$category ? ' is-active' : '' ?>"><?= (int) $totals['albums'] ?></span></a>
        <?php foreach ($categories as $c): $on = ($category['key'] ?? '') === $c['key']; ?>
          <a href="<?= e(ng_gallery_url(null, ['category' => $c['key']])) ?>#albums" class="chip<?= $on ? ' chip-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($c['label']) ?><span class="chip-count<?= $on ? ' is-active' : '' ?>"><?= (int) $c['count'] ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if ($albums): ?>
      <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="70">
        <?php foreach ($albums as $a): ?><?= ng_album_card($a) ?><?php endforeach; ?>
      </div>
      <?= ng_pagination($pg, $pageUrl, 'Gallery pagination', 'albums') ?>
    <?php else: ?>
      <div class="empty-state mt-8 rounded-[22px] border border-dashed border-warm-line bg-white">
        <span class="empty-state-icon"><?= icon('images', 'h-6 w-6') ?></span>
        <h3 class="mt-4 font-display text-lg font-bold text-brand-900">Photo albums are on their way</h3>
        <p class="mt-2 max-w-md text-sm text-slate-600">Our team is curating photos from campus, events and celebrations. Meanwhile, take a look around the campus or plan a visit.</p>
        <div class="mt-5 flex flex-wrap justify-center gap-2.5"><?= button_link('Explore the Campus', '/campus', 'explore', ['size' => 'sm']) ?><?= button_link('Book a Campus Visit', '/contact?type=campus_visit#message', 'callback', ['size' => 'sm', 'icon' => '']) ?></div>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($stories): ?>
<?= section_open(['id' => 'stories']) ?>
  <?= section_heading('Stories from Campus', 'The people and moments behind the pictures — news, achievements and events from GIMT.', 'News & Blog', 'left', button_link('All News & Articles', '/blog', 'brochure', ['icon' => 'arrow-right'])) ?>
  <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
    <?php foreach ($stories as $p): ?><?= ng_post_card($p, ['show_extra' => false]) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<?php site_footer([
    'cta_title' => 'See It for Yourself — Visit Our Campus',
    'cta_text' => 'Walk through our classrooms, labs, library and hostels, meet faculty and get your admission questions answered.',
    'cta_highlight' => 'Campus',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Book a Campus Visit', 'url' => '/contact?type=campus_visit#message', 'icon' => 'calendar-check'],
]);
