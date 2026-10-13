<?php
/**
 * News / blog article (/blog/{slug} — the router passes ?slug=). Hero (breadcrumbs, category, type, title, excerpt,
 * author, date, reading time, reads) + cover image, rich content (sanitised, styled typography, heading anchors), table
 * of contents for long posts, share buttons (Facebook, X, LinkedIn, WhatsApp, email; copy link + native share with JS),
 * tags, author box, previous / next article, sticky sidebar (contents, share, admissions card), related articles,
 * newsletter signup (POST /api/public/newsletter) and the admission CTA band.
 * Visibility: published posts whose publish time has passed (and due scheduled posts); drafts, future posts and unknown
 * slugs are 404 for visitors. Signed-in staff with blog.view see a noindex preview of drafts.
 * SEO: seo_settings entity "post" (title/description/robots/OG via the header), og:type article + article:* meta,
 * canonical /blog/{slug}, BlogPosting + BreadcrumbList JSON-LD. Views are counted once per session.
 * Helpers: includes/partials/news_gallery.php.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/news_gallery.php';

$slug = strtolower(trim((string) (is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '')));
$post = $slug !== '' ? ng_post_by_slug($slug) : null;
$preview = false;
if (!$post && $slug !== '' && ($post = ng_post_preview($slug))) {
    $preview = true;
}
if (!$post) {
    ng_not_found('post');
}

/* ------------------------------------------------------------------ data */
$id = (int) $post['id'];
[$body, $toc, $words] = ng_article_html($post['content'] ?? '');
$mins = ng_reading_minutes((int) $post['words']); // same estimate as the listing cards
$author = ng_author($post);
[$typeLabel, $typeIcon] = ng_type_meta($post['type'] ?? null);
$tags = ng_tags($post['tags'] ?? '');
$url = absolute_url('blog/' . $post['slug']);
$imgPath = trim((string) ($post['thumbnail'] ?? '')) ?: 'assets/images/site/news-campus.jpg';
$img = site_image($imgPath);
$imgAlt = ng_media_alt($post['thumbnail'] ?? null) ?? $post['title'];
$imgSize = ng_image_size($imgPath);
$lowRes = $imgSize && $imgSize[0] < 900; // the cover frame is up to 1152 px wide
$imgDisplayH = $lowRes ? (int) round($imgSize[1] * 1.5) : 0; // upscale at most 1.5×
$excerpt = ng_excerpt($post, 300);
$pubTs = strtotime((string) $post['pub_date']) ?: time();
$modTs = max($pubTs, strtotime((string) $post['mod_date']) ?: $pubTs);
$updated = $modTs - $pubTs > 86400;
$older = ng_adjacent($post, 'older');
$newer = ng_adjacent($post, 'newer');
$related = ng_related($post, 3);
$showToc = count($toc) >= 3 || (count($toc) >= 2 && $words >= 700);
$catUrl = !empty($post['category_slug']) ? ng_blog_url(['category' => $post['category_slug']]) : null;
$authorPosts = 0;
if (!$author['org'] && !empty($post['author_id'])) {
    [$vw, $va] = ng_visible_where();
    $authorPosts = (int) ng_try(fn () => db_value("SELECT COUNT(*) FROM blog_posts p WHERE p.author_id = ? AND $vw", array_merge([(int) $post['author_id']], $va)), 0);
}
if (!$preview) {
    ng_count_view($id);
}

/* ------------------------------------------------------------------ SEO */
$crumbLd = [['Home', ''], ['News & Blog', 'blog']];
if ($catUrl) {
    $crumbLd[] = [$post['category_name'], 'blog?category=' . rawurlencode($post['category_slug'])];
}
$crumbLd[] = [$post['title'], null];
$schema = [ng_breadcrumb_ld($crumbLd, $url)];
if (!ng_seo_schema_has(['BlogPosting'], 'post', $id)) {
    $schema[] = array_filter([
        '@context' => 'https://schema.org', '@type' => 'BlogPosting', '@id' => $url . '#article', 'url' => $url,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'headline' => mb_substr((string) $post['title'], 0, 110), 'description' => $excerpt, 'image' => [ng_abs($imgPath)],
        'datePublished' => date('c', $pubTs), 'dateModified' => date('c', $modTs),
        'author' => $author['org'] ? ['@type' => 'Organization', 'name' => institute_name(), 'url' => absolute_url('')]
                                   : array_filter(['@type' => 'Person', 'name' => $author['name'], 'jobTitle' => $author['role'] ?: null, 'worksFor' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name()]]),
        'publisher' => ['@type' => 'Organization', 'name' => institute_name(), 'logo' => ['@type' => 'ImageObject', 'url' => absolute_url('assets/images/logo.svg')]],
        'articleSection' => $post['category_name'] ?: null, 'keywords' => $tags ? implode(', ', $tags) : null,
        'wordCount' => $words ?: null, 'inLanguage' => 'en-IN',
    ], fn ($v) => $v !== null && $v !== '');
}
$head = '<meta property="article:published_time" content="' . e(date('c', $pubTs)) . '">'
    . '<meta property="article:modified_time" content="' . e(date('c', $modTs)) . '">'
    . (!empty($post['category_name']) ? '<meta property="article:section" content="' . e($post['category_name']) . '">' : '');
foreach ($tags as $t) {
    $head .= '<meta property="article:tag" content="' . e($t) . '">';
}
$head .= '<meta name="twitter:image:alt" content="' . e($imgAlt) . '">';
ng_site_header([
    'title' => $post['title'],
    'description' => $excerpt !== '' ? $excerpt : 'News and stories from Global Institute of Management & Technology (GIMT), Greater Noida.',
    'active' => 'blog',
    'seo' => $preview ? null : ['post', $id],
    'canonical' => $url,
    'robots' => $preview ? 'noindex,nofollow' : 'index,follow',
    'og_type' => 'article',
    'image' => $imgPath,
    'preload' => [$img],
    'schema' => $schema,
], $head);

$metaDate = '<time datetime="' . e(date('c', $pubTs)) . '">' . e(date('d M Y', $pubTs)) . '</time>';
?>

<?php if ($preview): ?>
  <div class="bg-[#FFF4B3] text-brand-900" role="status">
    <div class="container-site flex flex-wrap items-center gap-2 py-2.5 text-sm font-semibold"><?= icon('eye', 'h-4 w-4') ?>Preview — this <?= e($post['status'] ?? 'draft') ?> post is visible only to signed-in staff and is not indexed.</div>
  </div>
<?php endif; ?>

<article aria-labelledby="article-title">
  <header class="page-hero page-hero-light">
    <div class="page-hero-paper" aria-hidden="true"></div>
    <span class="geo geo-dots right-[3%] top-24 hidden h-24 w-28 md:block" style="--d:1s;--rot:0deg" aria-hidden="true"></span>
    <span class="geo geo-square bottom-28 left-[3%] hidden h-4 w-4 sm:block" style="--d:2s;--rot:18deg" aria-hidden="true"></span>
    <div class="container-site relative pb-28 pt-8 sm:pb-32 sm:pt-10 lg:pb-36"><div class="mx-auto max-w-6xl">
      <nav aria-label="Breadcrumb" class="animate-fade-down">
        <ol class="page-hero-crumbs">
          <li><a href="<?= e(site_url('')) ?>" class="underline-grow">Home</a></li>
          <li aria-hidden="true"><?= icon('chevron-right', 'h-3.5 w-3.5 opacity-60') ?></li>
          <li><a href="<?= e(site_url('blog')) ?>" class="underline-grow">News &amp; Blog</a></li>
          <?php if ($catUrl): ?>
            <li aria-hidden="true"><?= icon('chevron-right', 'h-3.5 w-3.5 opacity-60') ?></li>
            <li><a href="<?= e($catUrl) ?>" class="underline-grow"><?= e($post['category_name']) ?></a></li>
          <?php endif; ?>
          <li aria-hidden="true" class="hidden sm:block"><?= icon('chevron-right', 'h-3.5 w-3.5 opacity-60') ?></li>
          <li class="hidden max-w-xs truncate font-medium sm:block md:max-w-md" aria-current="page"><?= e($post['title']) ?></li>
        </ol>
      </nav>
      <div class="max-w-4xl">
        <p class="flex flex-wrap items-center gap-2.5 animate-fade-up">
          <?php if ($catUrl): ?><a href="<?= e($catUrl) ?>" class="page-hero-eyebrow !mb-0 transition hover:border-brand-900"><span class="page-hero-eyebrow-icon"><?= icon('folder-open', 'h-3.5 w-3.5') ?></span><?= e($post['category_name']) ?></a><?php endif; ?>
          <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-[12.5px] font-bold text-brand-900 ring-1 ring-inset ring-warm-2"><?= icon($typeIcon, 'h-3.5 w-3.5 text-accent-700') ?><?= e($typeLabel) ?></span>
        </p>
        <h1 id="article-title" class="page-hero-title mt-5 !text-[clamp(1.85rem,1.2rem+2.5vw,3.2rem)] !leading-[1.12]" data-text-reveal><?= e($post['title']) ?></h1>
        <?php if ($excerpt !== ''): ?><p class="page-hero-text !max-w-3xl animate-fade-up delay-1"><?= e($excerpt) ?></p><?php endif; ?>
        <div class="mt-7 flex flex-wrap items-center gap-x-7 gap-y-4 animate-fade-up delay-2">
          <div class="flex items-center gap-3">
            <?= ng_avatar($author['avatar'], $author['name'], 'h-11 w-11 text-sm') ?>
            <p class="leading-tight"><span class="block text-sm font-bold text-brand-900"><span class="font-medium text-slate-500">By </span><?= e($author['name']) ?></span><?php if ($author['role'] !== ''): ?><span class="mt-0.5 block text-xs text-slate-500"><?= e($author['role']) ?></span><?php endif; ?></p>
          </div>
          <ul class="flex flex-wrap items-center gap-x-5 gap-y-2 text-[13.5px] text-slate-600">
            <li class="inline-flex items-center gap-1.5"><?= icon('calendar-days', 'h-4 w-4 text-accent-700') ?><span class="sr-only">Published </span><?= $metaDate ?></li>
            <li class="inline-flex items-center gap-1.5"><?= icon('clock', 'h-4 w-4 text-accent-700') ?><?= $mins ?> min read</li>
            <?php if ($updated): ?><li class="inline-flex items-center gap-1.5"><?= icon('refresh-cw', 'h-4 w-4 text-accent-700') ?>Updated <time datetime="<?= e(date('c', $modTs)) ?>"><?= e(date('d M Y', $modTs)) ?></time></li><?php endif; ?>
            <?php if ((int) $post['views'] > 0): ?><li class="inline-flex items-center gap-1.5"><?= icon('eye', 'h-4 w-4 text-accent-700') ?><?= e(number_format((int) $post['views'])) ?> reads</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div></div>
  </header>

  <div class="container-site relative z-[1] -mt-20 sm:-mt-24 lg:-mt-28">
    <figure class="mx-auto max-w-6xl">
      <?php if ($lowRes): // small source image: shown near its own size on a soft blurred backdrop instead of stretched (blurry) across the frame ?>
        <div class="relative aspect-[16/10] overflow-hidden rounded-[28px] bg-navy-deep shadow-[0_34px_70px_-36px_rgba(7,26,59,.55)] sm:aspect-[16/8] lg:aspect-[16/7]" style="--ng-h: <?= (int) $imgDisplayH ?>px">
          <img src="<?= e($img) ?>" alt="" aria-hidden="true" class="absolute inset-0 hidden h-full w-full scale-110 object-cover opacity-60 blur-2xl sm:block" width="<?= (int) $imgSize[0] ?>" height="<?= (int) $imgSize[1] ?>" decoding="async">
          <span class="absolute inset-0 hidden bg-gradient-to-br from-navy-deep/40 via-transparent to-navy-deep/50 sm:block" aria-hidden="true"></span>
          <img src="<?= e($img) ?>" alt="<?= e($imgAlt) ?>" class="absolute inset-0 h-full w-full object-cover sm:inset-auto sm:left-1/2 sm:top-1/2 sm:h-[min(86%,var(--ng-h))] sm:w-auto sm:max-w-[90%] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-[18px] sm:object-contain sm:shadow-[0_24px_60px_-20px_rgba(7,26,59,.75)]" width="<?= (int) $imgSize[0] ?>" height="<?= (int) $imgSize[1] ?>" fetchpriority="high" decoding="async">
        </div>
      <?php else: ?>
        <div class="overflow-hidden rounded-[28px] bg-warm-2 shadow-[0_34px_70px_-36px_rgba(7,26,59,.55)]">
          <img src="<?= e($img) ?>" alt="<?= e($imgAlt) ?>" class="aspect-[16/10] w-full object-cover sm:aspect-[16/8] lg:aspect-[16/7]" width="1200" height="525" fetchpriority="high" decoding="async">
        </div>
      <?php endif; ?>
    </figure>
  </div>

  <div class="container-site pb-16 pt-10 sm:pt-14 lg:pb-20">
    <div class="mx-auto grid max-w-6xl gap-10 lg:grid-cols-12 lg:gap-12">
      <div class="min-w-0 lg:col-span-8">
        <?php if ($showToc): ?>
          <details class="mb-8 rounded-[18px] border border-warm-2 bg-warm-50 p-5 lg:hidden" data-animate>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-display text-[15px] font-bold text-brand-900 [&::-webkit-details-marker]:hidden">
              <span class="inline-flex items-center gap-2"><?= icon('list-ordered', 'h-4 w-4 text-accent-700') ?>On this page</span><?= icon('chevron-down', 'h-4 w-4') ?>
            </summary>
            <nav aria-label="Table of contents" class="pt-1"><?= ng_toc($toc, 'toc-mobile') ?></nav>
          </details>
        <?php endif; ?>

        <?php if ($body !== ''): ?>
          <div class="prose-site !text-[16.5px] !leading-[1.8] sm:!text-[17px] [&>p:first-child]:text-[1.13rem] [&>p:first-child]:text-slate-700 [&_h2]:scroll-mt-28 [&_h3]:scroll-mt-28 [&_iframe]:aspect-video [&_iframe]:h-auto [&_iframe]:w-full [&_iframe]:rounded-2xl [&_img]:h-auto [&_img]:max-w-full [&_li]:marker:text-accent-600 [&_table]:block [&_table]:overflow-x-auto sm:[&_table]:table">
            <?= $body ?>
          </div>
        <?php else: ?>
          <p class="rounded-[18px] border border-dashed border-warm-line bg-warm-50 p-6 text-slate-600"><?= e($excerpt !== '' ? $excerpt : 'The full story will be published here shortly.') ?></p>
        <?php endif; ?>

        <?php if ($tags): ?>
          <div class="mt-10 flex flex-wrap items-center gap-2 border-t border-warm-2 pt-6">
            <span class="mr-1 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-900"><?= icon('tags', 'h-4 w-4 text-accent-700') ?>Tagged</span>
            <?php foreach ($tags as $t): ?><a href="<?= e(ng_blog_url(['tag' => mb_strtolower($t)])) ?>" class="rounded-full bg-warm px-3 py-1.5 text-[13px] font-medium text-slate-700 transition hover:bg-brand-900 hover:text-white">#<?= e($t) ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-[18px] border border-warm-2 bg-white p-4 sm:p-5 lg:hidden">
          <p class="font-display text-[15px] font-bold text-brand-900">Share this article</p>
          <?= ng_share_buttons($url, $post['title']) ?>
        </div>

        <!-- Author -->
        <section class="mt-10 flex flex-col gap-5 rounded-[22px] border border-warm-2 bg-warm-50 p-6 sm:flex-row sm:items-center" aria-labelledby="author-name">
          <?= $author['org'] ? '<img src="' . e(logo_url()) . '" alt="" class="h-16 w-16 shrink-0 rounded-2xl bg-white object-contain p-2 ring-1 ring-warm-2" width="64" height="64" loading="lazy">' : ng_avatar($author['avatar'], $author['name'], 'h-16 w-16 text-lg') ?>
          <div class="min-w-0 flex-1">
            <p class="text-[11.5px] font-bold uppercase tracking-[0.14em] text-accent-700"><?= $author['org'] ? 'Published by' : 'Written by' ?></p>
            <h2 id="author-name" class="mt-1 font-display text-lg font-extrabold text-brand-900"><?= e($author['org'] ? institute_name() : $author['name']) ?></h2>
            <?php if ($author['org']): ?>
              <p class="mt-1 text-sm leading-relaxed text-slate-600">Official news and updates from <?= e(institute_name()) ?>, <?= e((string) setting('city', 'Greater Noida')) ?>.</p>
            <?php else: ?>
              <p class="mt-0.5 text-sm text-slate-600"><?= e(trim(($author['role'] !== '' ? $author['role'] . ' · ' : '') . setting('institute_short_name', 'GIMT'))) ?></p>
              <?php if ($authorPosts > 1): ?><p class="mt-2 text-sm text-slate-600"><?= (int) $authorPosts ?> articles published on GIMT News &amp; Blog.</p><?php endif; ?>
            <?php endif; ?>
          </div>
        </section>

        <?php if ($older || $newer): ?>
          <nav class="mt-8 grid gap-4 sm:grid-cols-2" aria-label="More articles">
            <?php if ($older): ?>
              <a href="<?= e(ng_post_url($older['slug'])) ?>" class="group flex h-full flex-col rounded-[18px] border border-warm-2 bg-white p-5 transition hover:-translate-y-0.5 hover:border-brand-900">
                <span class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-[0.12em] text-slate-500"><?= icon('arrow-left', 'h-3.5 w-3.5 transition group-hover:-translate-x-1') ?>Previous article</span>
                <span class="mt-2 line-clamp-2 font-display text-[15px] font-bold leading-snug text-brand-900 group-hover:text-accent-800"><?= e($older['title']) ?></span>
              </a>
            <?php else: ?><span class="hidden sm:block" aria-hidden="true"></span><?php endif; ?>
            <?php if ($newer): ?>
              <a href="<?= e(ng_post_url($newer['slug'])) ?>" class="group flex h-full flex-col rounded-[18px] border border-warm-2 bg-white p-5 text-right transition hover:-translate-y-0.5 hover:border-brand-900">
                <span class="inline-flex items-center justify-end gap-1.5 text-xs font-bold uppercase tracking-[0.12em] text-slate-500">Next article<?= icon('arrow-right', 'h-3.5 w-3.5 transition group-hover:translate-x-1') ?></span>
                <span class="mt-2 line-clamp-2 font-display text-[15px] font-bold leading-snug text-brand-900 group-hover:text-accent-800"><?= e($newer['title']) ?></span>
              </a>
            <?php endif; ?>
          </nav>
        <?php endif; ?>
      </div>

      <aside class="lg:col-span-4" aria-label="Article tools">
        <div class="space-y-6 lg:sticky lg:top-28">
          <?php if ($showToc): ?>
            <nav class="hidden rounded-[22px] border border-warm-2 bg-white p-6 lg:block" aria-label="Table of contents">
              <p class="inline-flex items-center gap-2 font-display text-base font-extrabold text-brand-900"><?= icon('list-ordered', 'h-4 w-4 text-accent-700') ?>On this page</p>
              <?= ng_toc($toc, 'toc-desktop') ?>
            </nav>
          <?php endif; ?>
          <section class="hidden rounded-[22px] border border-warm-2 bg-white p-6 lg:block" aria-labelledby="share-title">
            <h2 id="share-title" class="font-display text-base font-extrabold text-brand-900">Share this article</h2>
            <?= ng_share_buttons($url, $post['title'], 'mt-4') ?>
          </section>
          <?= ng_admission_card() ?>
        </div>
      </aside>
    </div>
  </div>
</article>

<?php if ($related): ?>
<?= section_open(['id' => 'related', 'bg' => 'light']) ?>
  <?= section_heading('Related Articles', 'More stories from the GIMT community you may enjoy.', 'Keep reading', 'left',
      button_link($catUrl ? 'More in ' . $post['category_name'] : 'All articles', $catUrl ?: '/blog', 'brochure', ['icon' => 'arrow-right'])) ?>
  <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
    <?php foreach ($related as $r): ?><?= ng_post_card($r, ['show_extra' => false]) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- Newsletter -->
<section class="bg-white py-14 sm:py-16" aria-labelledby="newsletter-title">
  <div class="container-site">
    <div class="relative isolate grid items-center gap-8 overflow-hidden rounded-[28px] border border-warm-2 bg-mesh-warm px-6 py-10 sm:px-10 lg:grid-cols-12 lg:px-14"<?= reveal_attr('fade-up') ?>>
      <div class="lg:col-span-6">
        <p class="eyebrow eyebrow-line mb-3">Newsletter</p>
        <h2 id="newsletter-title" class="font-display text-[1.65rem] font-extrabold leading-tight text-brand-900 sm:text-3xl">Campus news, straight to your inbox</h2>
        <p class="mt-3 max-w-lg text-slate-600">Admission deadlines, events, results and student achievements from GIMT — a short update whenever something important happens.</p>
      </div>
      <div class="lg:col-span-6"><?= ng_newsletter_form('blog-post', 'band') ?></div>
    </div>
  </div>
</section>

<?= ng_share_script() ?>
<?php site_footer([
    'cta_title' => 'Write Your Own Success Story at GIMT',
    'cta_text' => 'Industry-focused programs, mentors who care and a campus full of opportunities. Admissions are open.',
    'cta_highlight' => 'Success',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
