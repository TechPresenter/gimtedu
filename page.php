<?php
/**
 * CMS page renderer — every published row of `pages` (docs/CMS.md §2):
 *   /{slug}        system pages (privacy-policy, vision-mission …) — tools/router.php / .htaccess send any unknown
 *                  single-segment slug here
 *   /page/{slug}   custom pages created by editors
 * Each page is served at exactly one URL (cms_page_url()); the other spelling 301-redirects to it, and route pages
 * with their own template (/page/about → /about) redirect too. Old short URLs (/refund-policy when that page is missing or
 * unpublished, /privacy, /posh …) redirect to the matching policy (cp_page_aliases()).
 *
 * Templates: default (page hero + contained content) · full_width (wide content, blocks edge to edge) · landing (no
 * breadcrumb hero, reduced header/footer for campaigns) · sidebar (content + sticky enquiry sidebar).
 * A first `hero` block replaces the page hero. Every builder block type is rendered by cp_render_block().
 * Policy / legal pages get a sticky table of contents built from their h2s, "Last updated", reading time, a print button
 * and the "Policies & Disclosures" list. Drafts and scheduled pages 404 for visitors; signed-in users with CMS view
 * permission see them in preview mode (noindex) — automatically or with ?preview=1.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/partials/content.php';

$slug = strtolower(trim(cp_query('slug', 200), '/'));
$reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rel = '/' . trim(substr($reqPath, strlen(base_path())), '/');
$notFound = function (): void {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
};
if (!preg_match('/^[a-z0-9][a-z0-9-]{0,189}$/', $slug) || $slug === 'home') {
    $notFound();
}

$page = cp_page_find($slug);
$isPublic = $page && cp_page_is_public($page);
$canPreview = $page && is_logged_in() && can('cms', 'view');
$preview = $canPreview && (!$isPublic || isset($_GET['preview']));

if (!$page || (!$isPublic && !$preview)) {
    // Missing or unpublished (e.g. the short refund-policy page retired by an editor) → its replacement policy.
    $alias = cp_page_aliases()[$slug] ?? null;
    if ($alias && $alias !== $slug && ($target = cp_published_pages()[$alias] ?? null)) {
        redirect(base_url(ltrim(cms_page_url($target), '/')), 301);
    }
    $notFound();
}

// One URL per page: /page/x ↔ /x (and /page/about → /about) — keep any extra query parameters.
$canonPath = cms_page_url($page);
if (!$preview && $rel !== $canonPath && cp_route_ok($canonPath)) {
    $q = $_GET;
    unset($q['slug']);
    redirect(base_url(ltrim($canonPath, '/')) . ($q ? '?' . http_build_query($q) : ''), 301);
}
if ($preview) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
}

$template = in_array($page['template'] ?? 'default', ['default', 'full_width', 'landing', 'sidebar'], true) ? $page['template'] : 'default';
$isPolicy = cp_is_policy($page);
$isHub = $slug === 'policies';
$blocks = cms_page_blocks($page);
$heroBlock = $blocks && $blocks[0]['type'] === 'hero' ? array_shift($blocks)['data'] : null;
$rawContent = sanitize_html((string) ($page['content'] ?? ''));
// A leading "Last updated: 1 July 2026" line written by the editor is the page's stated date: show it in the meta row
// (instead of the database timestamp) and drop the duplicate paragraph.
$statedTs = null;
if ($stated = cp_stated_date_match($rawContent)) {
    [$statedTs, $matched] = $stated;
    $rawContent = substr($rawContent, strlen($matched));
}
[$content, $toc] = cp_prepare_html($rawContent);
$showToc = count($toc) >= ($isPolicy ? 2 : 4) && $template !== 'landing';
$c = cp_contact();

// Breadcrumbs: ancestors (published) → for policies without a parent, the Policies hub.
$ancestors = cp_page_ancestors($page);
$hub = cp_policy_hub();
if (!$ancestors && $isPolicy && $hub && !$isHub && (int) ($page['parent_id'] ?? 0) !== (int) $hub['id']) {
    $ancestors = [$hub];
}
$crumbs = [];
$ldCrumbs = [['Home', '']];
foreach ($ancestors as $a) {
    $crumbs[$a['title']] = cms_page_url($a);
    $ldCrumbs[] = [$a['title'], ltrim(cms_page_url($a), '/')];
}
$crumbs[] = $page['title'];
$ldCrumbs[] = [$page['title'], ltrim($canonPath, '/')];

// Render the builder blocks first (they collect FAQ items for the page's FAQPage JSON-LD).
$ctx = ['faq' => []];
$blocksHtml = '';
foreach ($blocks as $b) {
    $blocksHtml .= cp_render_block($b, $ctx);
}

$isEmpty = $content === '' && $blocksHtml === '' && !$isHub; // published but nothing to show yet → empty state, noindex
$updated = $page['updated_at'] ?: ($page['published_at'] ?: $page['created_at']);
$updatedTs = $statedTs ?? ($updated ? strtotime((string) $updated) : time());
$readText = $content . ' ' . cp_block_text(array_map(fn ($b) => $b['data'], $blocks));
$minutes = cp_reading_minutes($readText);
$description = cp_page_description($page);
$banner = trim((string) ($page['banner_image'] ?? ''));
$heroImage = $heroBlock ? trim((string) ($heroBlock['image'] ?? '')) : ($isPolicy ? '' : $banner);
$seoRef = cms_page_seo_ref($page);
$canonical = absolute_url(ltrim($canonPath, '/'));
$rootSlug = $ancestors ? (string) $ancestors[0]['slug'] : $slug;

$schema = [sa_breadcrumb_ld($ldCrumbs)];
if (!sa_seo_schema_has('WebPage', $seoRef[0], $seoRef[0] === 'page' ? (int) $seoRef[1] : null, $seoRef[0] === 'route' ? (string) $seoRef[1] : null)) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => $slug === 'about' ? 'AboutPage' : 'WebPage', 'name' => $page['title'], 'description' => $description,
        'url' => $canonical, 'inLanguage' => 'en-IN', 'dateModified' => date('c', $updatedTs),
        'datePublished' => date('c', strtotime((string) ($page['published_at'] ?: $page['created_at'] ?: 'now'))),
        'isPartOf' => ['@type' => 'WebSite', 'name' => institute_name(), 'url' => absolute_url('')],
        'publisher' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'url' => absolute_url('')]];
}
if ($ctx['faq']) {
    $schema[] = cp_faq_schema($ctx['faq']);
}

site_header([
    'title' => $page['title'],
    'description' => $description,
    'active' => $rootSlug,
    'seo' => $seoRef,
    'canonical' => $canonical,
    'robots' => $preview ? 'noindex,nofollow' : ($isEmpty ? 'noindex,follow' : null), // an empty page is thin content
    'image' => $banner !== '' ? site_image($banner) : null,
    'preload' => $heroImage !== '' ? [site_image($heroImage)] : [],
    'schema' => $schema,
    'og_type' => 'article',
    'header' => $template === 'landing' ? 'minimal' : 'full',
]);

$prose = cp_prose_class();
$updatedLabel = date('d F Y', $updatedTs);
?>
<?php if ($preview): ?>
<div class="border-b border-amber-300 bg-amber-50 text-amber-950" role="status">
  <div class="container-site flex flex-wrap items-center gap-x-4 gap-y-2 py-3 text-sm">
    <span class="inline-flex items-center gap-2 font-semibold"><?= icon('eye', 'h-4 w-4') ?>Preview</span>
    <span><?= $isPublic ? 'You are viewing the published page in preview mode.' : 'This page is ' . e(($page['status'] ?? '') === 'published' ? 'scheduled for ' . format_datetime($page['published_at']) : (in_array($page['status'] ?? '', ['', 'draft'], true) ? 'a draft' : 'marked “' . $page['status'] . '”')) . ' and is not visible to visitors.' ?></span>
    <?php if (can('cms', 'edit')): ?><a href="<?= e(admin_url('cms/pages/' . (int) $page['id'] . '/builder')) ?>" class="ml-auto inline-flex items-center gap-1.5 font-semibold underline underline-offset-2"><?= icon('pen-line', 'h-4 w-4') ?>Edit in admin</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php
/* ------------------------------------------------------------------ Hero */
if ($template === 'landing') {
    if ($heroBlock) {
        cp_hero_from_block($heroBlock, [], $page['title']);
    } else {
        page_hero($page['title'], (string) ($page['subtitle'] ?? ''), [], $banner, (string) ($page['excerpt'] ?? ''), [], ['image_alt' => $page['title']]);
    }
} elseif ($heroBlock) {
    cp_hero_from_block($heroBlock, $crumbs, $page['title']);
} else {
    page_hero($page['title'], (string) ($page['subtitle'] ?? ''), $crumbs, $heroImage, (string) ($page['excerpt'] ?? ''), [], array_filter([
        'eyebrow' => $isPolicy ? 'Policies & Disclosures' : null,
        'eyebrow_icon' => $isPolicy ? cp_policy_icon($slug) : null,
        'image_alt' => $page['title'],
        'highlight' => $isPolicy ? false : null,
    ], fn ($v) => $v !== null));
}

/* ------------------------------------------------------------------ Meta row (last updated · reading time · print) */
$metaRow = $template === 'landing' ? '' : '<div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-500">'
    . '<span class="inline-flex items-center gap-1.5">' . icon('calendar-check', 'h-4 w-4 text-accent-600') . 'Last updated <time datetime="' . e(date('Y-m-d', $updatedTs)) . '" class="font-semibold text-brand-900">' . e($updatedLabel) . '</time></span>'
    . ($minutes > 1 ? '<span class="inline-flex items-center gap-1.5">' . icon('clock', 'h-4 w-4 text-accent-600') . $minutes . ' min read</span>' : '')
    . ($isPolicy ? '<button type="button" data-print class="no-print hidden items-center gap-1.5 rounded-full border border-warm-2 bg-white px-3.5 py-1.5 font-semibold text-brand-900 transition hover:border-brand-900 [.js_&]:inline-flex">' . icon('printer', 'h-4 w-4') . 'Print this page</button>' : '')
    . '</div>';
?>

<?php if ($content !== '' || $isHub): ?>
<?php if ($template === 'sidebar'): ?>
<?= section_open(['id' => 'content', 'padding' => 'normal', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
    <article class="min-w-0 lg:col-span-8">
      <?= $metaRow ?>
      <div class="<?= e($prose) ?> mt-6"><?= $content ?></div>
    </article>
    <aside class="lg:col-span-4" aria-label="Enquire">
      <div class="space-y-5 lg:sticky lg:top-28">
        <?= enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'theme' => 'lead', 'compact' => true, 'captureUtm' => true,
            'title' => 'Request a Callback', 'titleHighlight' => 'Callback', 'subtitle' => 'Our counsellor will call you within one working day.',
            'submitLabel' => 'Request Callback', 'hidden' => ['source' => 'page-' . $slug, 'form' => 'callback'],
            'consent' => 'I agree to be contacted by GIMT about admissions by phone, SMS, email or WhatsApp.',
            'fields' => [
                ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150, 'autocomplete' => 'name'],
                ['name' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'prefix' => '+91', 'pattern' => '^[6-9][0-9]{9}$', 'patternMessage' => 'Enter a valid 10-digit mobile number.', 'autocomplete' => 'tel-national', 'maxLength' => 10],
                ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'autocomplete' => 'email'],
            ]]) ?>
        <?= cp_help_card($c) ?>
      </div>
    </aside>
  </div>
<?= section_close() ?>
<?php else: ?>
<?= section_open(['id' => 'content', 'padding' => 'tight', 'class' => 'scroll-mt-24 !pb-16 sm:!pb-20']) ?>
  <?php if ($showToc): ?>
  <div class="grid gap-8 lg:grid-cols-12 lg:gap-12">
    <aside class="no-print lg:col-span-4 xl:col-span-3" aria-label="On this page">
      <div class="lg:sticky lg:top-28">
        <details class="group rounded-[20px] border border-warm-2 bg-warm/60 lg:hidden" data-toc>
          <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 font-display font-bold text-brand-900 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2"><?= icon('list', 'h-4 w-4 text-accent-600') ?>On this page</span><?= icon('chevron-down', 'h-4 w-4 transition group-open:rotate-180') ?>
          </summary>
          <?= cp_toc_list($toc, 'px-3 pb-4') ?>
        </details>
        <nav class="hidden rounded-[22px] border border-warm-2 bg-white p-5 shadow-sm lg:block" aria-label="Table of contents" data-toc>
          <p class="mb-3 flex items-center gap-2 px-2 text-xs font-bold uppercase tracking-[0.14em] text-slate-500"><?= icon('list', 'h-4 w-4 text-accent-600') ?>On this page</p>
          <?= cp_toc_list($toc) ?>
        </nav>
        <?php if ($isPolicy): ?><div class="mt-5 hidden lg:block"><?= cp_help_card($c, true) ?></div><?php endif; ?>
      </div>
    </aside>
    <article class="min-w-0 lg:col-span-8 xl:col-span-9">
      <div class="max-w-3xl">
        <?= $metaRow ?>
        <div class="<?= e($prose) ?> mt-6"><?= $content ?></div>
      </div>
    </article>
  </div>
  <?php else: ?>
  <article class="mx-auto <?= $template === 'full_width' ? 'max-w-5xl' : 'max-w-3xl' ?>">
    <?php if ($content !== ''): ?>
      <?= $metaRow ?>
      <div class="<?= e($prose) ?> mt-6"><?= $content ?></div>
    <?php endif; ?>
  </article>
  <?php endif; ?>
  <?php if ($isHub): ?>
    <?= cp_policy_grid(cp_policy_pages()) ?>
  <?php endif; ?>
<?= section_close() ?>
<?php endif; ?>
<?php endif; ?>

<?= $blocksHtml ?>

<?php if ($isEmpty): ?>
<?= section_open(['padding' => 'tight']) ?>
  <div class="mx-auto max-w-2xl rounded-[24px] border border-dashed border-warm-2 bg-warm/60 p-10 text-center">
    <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm"><?= icon('file-text', 'h-7 w-7') ?></span>
    <h2 class="mt-4 font-display text-xl font-extrabold text-brand-900">This page is being updated</h2>
    <p class="mt-2 text-slate-600">Its content will be published here shortly. Meanwhile our team is happy to help with any question.</p>
    <div class="mt-6 flex flex-wrap justify-center gap-3"><?= button_link('Contact Us', '/contact', 'explore') ?><a href="<?= e(cp_tel($c['phone'])) ?>" class="btn btn-callback"><?= icon('phone', 'h-4 w-4') ?><?= e($c['phone']) ?></a></div>
  </div>
<?= section_close() ?>
<?php endif; ?>

<?php
/* ------------------------------------------------------------------ Related content */
$policies = $isPolicy && !$isHub ? array_values(array_filter(cp_policy_pages(), fn ($p) => $p['slug'] !== $slug)) : [];
$related = !$isPolicy && !$isHub && $template !== 'landing' ? cp_related_pages($page) : [];
?>
<?php if ($content === '' && $blocksHtml !== '' && !$isPolicy && $template !== 'landing'): ?>
<div class="container-site py-6 text-sm text-slate-500"><span class="inline-flex items-center gap-1.5"><?= icon('calendar-check', 'h-4 w-4 text-accent-600') ?>Last updated <time datetime="<?= e(date('Y-m-d', $updatedTs)) ?>" class="font-semibold text-brand-900"><?= e($updatedLabel) ?></time></span></div>
<?php endif; ?>

<?php if ($policies): ?>
<?= section_open(['id' => 'policies', 'bg' => 'light', 'padding' => 'tight', 'class' => 'no-print', 'label' => 'Policies and disclosures']) ?>
  <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
      <p class="eyebrow mb-2">Policies &amp; Disclosures</p>
      <h2 class="font-display text-[clamp(1.5rem,1.1rem+1.4vw,2.1rem)] font-extrabold text-brand-900">Other institute policies</h2>
    </div>
    <?php if ($hub && !$isHub): ?><a href="<?= e(site_url(ltrim(cms_page_url($hub), '/'))) ?>" class="link-arrow text-sm">All policies &amp; disclosures<?= icon('arrow-right', 'h-4 w-4') ?></a><?php endif; ?>
  </div>
  <ul class="grid gap-x-6 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
    <?php foreach ($policies as $p): ?>
      <li><a href="<?= e(site_url(ltrim($p['url'], '/'))) ?>" class="group flex items-center gap-3 rounded-2xl px-3 py-2.5 font-medium text-brand-900 transition hover:bg-white hover:shadow-sm">
        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-accent-600 shadow-sm transition group-hover:bg-accent-600 group-hover:text-white"><?= icon($p['icon'], 'h-4 w-4') ?></span>
        <span class="min-w-0 flex-1"><?= e($p['title']) ?></span><?= icon('arrow-right', 'h-4 w-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-accent-600') ?></a></li>
    <?php endforeach; ?>
  </ul>
  <div class="mt-8 lg:hidden"><?= cp_help_card($c, true) ?></div>
<?= section_close() ?>
<?php elseif ($related): ?>
<?= section_open(['id' => 'related', 'bg' => 'light', 'padding' => 'tight', 'class' => 'no-print', 'label' => 'Related pages']) ?>
  <div class="mb-8"><p class="eyebrow mb-2">Keep exploring</p><h2 class="font-display text-[clamp(1.5rem,1.1rem+1.4vw,2.1rem)] font-extrabold text-brand-900">Related pages</h2></div>
  <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-stagger="70">
    <?php foreach ($related as $r): ?>
      <li class="group relative flex h-full flex-col rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-card">
        <h3 class="font-display text-lg font-extrabold text-brand-900"><a href="<?= e(site_url(ltrim($r['url'], '/'))) ?>" class="stretched-link"><?= e($r['title']) ?></a></h3>
        <?php if ($r['excerpt'] !== ''): ?><p class="mt-2 line-clamp-3 text-sm leading-relaxed text-slate-600"><?= e($r['excerpt']) ?></p><?php endif; ?>
        <span class="link-arrow mt-auto pt-4 text-sm" aria-hidden="true">Read more<?= icon('arrow-right', 'h-4 w-4') ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
<?= section_close() ?>
<?php endif; ?>

<?php if ($isPolicy): ?>
<style media="print">
  /* Printed policy = the document itself: no CTA band, footer, chat or enquiry widgets. */
  .footer-v2, .cta-band, #gimt-chat, #enquiry-modal, .page-hero-paper, .page-hero .geo { display: none !important; }
  #content { padding-top: 0 !important; }
  .page-hero { padding: 0 !important; background: none !important; }
</style>
<?php endif; ?>
<?php if ($showToc || $isPolicy): ?>
<script>
(function () {
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-print]') : null;
    if (b) { e.preventDefault(); window.print(); }
  });
  // Scroll-spy: the section whose heading was passed last (top above 35% of the viewport) is the current one.
  var links = [].slice.call(document.querySelectorAll('[data-toc] a[href^="#"]'));
  if (!links.length) return;
  var byId = {}, heads = [], current = null, queued = false;
  links.forEach(function (a) { var id = decodeURIComponent(a.getAttribute('href').slice(1)); (byId[id] = byId[id] || []).push(a); });
  Object.keys(byId).forEach(function (id) { var el = document.getElementById(id); if (el) heads.push(el); });
  function mark(id) {
    if (id === current) return;
    current = id;
    links.forEach(function (a) { a.removeAttribute('aria-current'); });
    (byId[id] || []).forEach(function (a) { a.setAttribute('aria-current', 'true'); });
  }
  function update() {
    queued = false;
    var line = window.innerHeight * 0.35, id = null;
    for (var i = 0; i < heads.length; i++) { if (heads[i].getBoundingClientRect().top <= line) id = heads[i].id; else break; }
    mark(id);
  }
  window.addEventListener('scroll', function () { if (!queued) { queued = true; window.requestAnimationFrame(update); } }, { passive: true });
  update();
})();
</script>
<?php endif; ?>
<?php
if ($template === 'landing') {
    site_footer(['footer' => 'minimal']);
} elseif ($isPolicy) {
    site_footer(['cta_title' => 'Questions About Our Policies?', 'cta_text' => 'Write to ' . $c['email'] . ' or call ' . $c['phone'] . ' (' . $c['hours'] . ') — our office will help you.',
        'cta_primary' => ['label' => 'Contact the Office', 'url' => '/contact'], 'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap']]);
} else {
    site_footer();
}
