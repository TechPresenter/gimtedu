<?php
/**
 * Branded "page not found" page (HTTP 404, noindex,follow): search box (→ /search), pages that resemble the requested
 * path (CMS pages, programs, public routes — cp_404_suggestions()), popular destinations that exist, admissions CTA.
 * Rendered by tools/router.php / Apache (ErrorDocument 404) for unknown URLs and by page.php for unknown or unpublished
 * slugs; also safe to open directly.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/partials/content.php';

if (!headers_sent()) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, follow');
}
$reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$rel = '/' . trim(substr($reqPath, strlen(base_path())), '/');
$direct = in_array($rel, ['/404', '/404.php'], true);
// /Privacy-Policy → /privacy-policy (only when the lower-case URL really exists)
if (!$direct && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $rel !== strtolower($rel) && preg_match('#^/[A-Za-z0-9/-]+$#', $rel) && cp_route_ok(strtolower($rel)) && !headers_sent()) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    redirect(base_url(ltrim(strtolower($rel), '/')) . ($qs !== '' ? '?' . $qs : ''), 301);
}
$suggestions = $direct ? [] : cp_404_suggestions($rel);
$popular = cp_popular_links();
$terms = $direct ? '' : trim(mb_substr((string) preg_replace('/[^a-z0-9]+/i', ' ', (string) preg_replace('/\.(php|html?|aspx?)$/i', '', basename($rel))), 0, 60));
$c = cp_contact();
$shownPath = mb_strlen($rel) > 70 ? mb_substr($rel, 0, 67) . '…' : $rel;

site_header([
    'title' => 'Page Not Found',
    'description' => 'The page you were looking for could not be found. Search the ' . institute_name() . ' website or explore programs, admissions and contact details.',
    'robots' => 'noindex,follow',
    'loader' => false,
]);
?>
<section class="page-hero page-hero-light" aria-labelledby="nf-title">
  <div class="page-hero-paper" aria-hidden="true"></div>
  <?= geo_shapes('hero') ?>
  <div class="container-site relative grid items-center gap-10 py-14 sm:py-20 lg:grid-cols-12 lg:py-24">
    <div class="lg:col-span-7">
      <nav aria-label="Breadcrumb"><ol class="page-hero-crumbs"><li><a href="<?= e(site_url('')) ?>" class="underline-grow">Home</a></li><li aria-hidden="true"><?= icon('chevron-right', 'h-3.5 w-3.5 opacity-60') ?></li><li class="font-medium" aria-current="page">Page not found</li></ol></nav>
      <p class="eyebrow mb-3 mt-6">Error 404</p>
      <h1 id="nf-title" class="page-hero-title">We couldn’t find that page</h1>
      <p class="page-hero-text mt-4">
        <?php if (!$direct): ?>There is no page at <code class="break-all rounded-md bg-white px-1.5 py-0.5 text-[0.9em] font-semibold text-brand-900 ring-1 ring-warm-2"><?= e($shownPath) ?></code>. <?php endif; ?>It may have moved, or the link may be mistyped. Search the website or pick one of the pages below.
      </p>
      <form action="<?= e(site_url('search')) ?>" method="get" role="search" class="mt-8 max-w-xl">
        <label for="nf-q" class="sr-only">Search the website</label>
        <div class="flex items-center gap-2 rounded-full border border-warm-2 bg-white p-1.5 pl-5 shadow-card focus-within:ring-2 focus-within:ring-accent-500">
          <?= icon('search', 'h-5 w-5 shrink-0 text-slate-400') ?>
          <input id="nf-q" name="q" type="search" value="<?= e($terms) ?>" placeholder="Search programs, admissions, notices…" maxlength="100" class="h-11 w-0 min-w-0 flex-1 border-0 bg-transparent text-base text-brand-900 placeholder:text-slate-400 focus:outline-none focus:ring-0">
          <button type="submit" class="btn btn-explore shrink-0">Search</button>
        </div>
      </form>
      <div class="mt-6 flex flex-wrap gap-3">
        <?php if (cp_route_ok('/apply')): ?><?= button_link('Apply for Admission', '/apply', 'apply') ?><?php endif; ?>
        <?php if (cp_route_ok('/programs')): ?><?= button_link('Explore Courses', '/programs', 'explore') ?><?php endif; ?>
      </div>
    </div>
    <div class="relative hidden lg:col-span-5 lg:block" aria-hidden="true">
      <div class="relative mx-auto flex aspect-square max-w-sm items-center justify-center">
        <?= blob('-inset-10 h-[120%] w-[120%]', 'flame') ?>
        <span class="absolute inset-6 rounded-full border-2 border-dashed border-accent-300 spin-slow"></span>
        <span class="relative font-display text-[9rem] font-extrabold leading-none tracking-tighter text-brand-900">4<span class="text-accent-600">0</span>4</span>
      </div>
    </div>
  </div>
</section>

<?= section_open(['padding' => 'tight', 'class' => '!pb-6']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <?php if ($suggestions): ?>
    <div class="min-w-0 lg:col-span-5">
      <h2 class="font-display text-xl font-extrabold text-brand-900">Were you looking for…</h2>
      <ul class="mt-5 space-y-3">
        <?php foreach ($suggestions as $s): ?>
          <li><a href="<?= e(site_url(ltrim($s['url'], '/'))) ?>" class="group flex items-center gap-4 rounded-[18px] border border-warm-2 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-accent-300 hover:shadow-card">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><?= icon($s['type'] === 'Program' ? 'graduation-cap' : ($s['type'] === 'Policy' ? 'scroll-text' : 'file-text'), 'h-5 w-5') ?></span>
            <span class="min-w-0 flex-1"><span class="block font-semibold text-brand-900"><?= e($s['title']) ?></span><span class="block truncate text-xs text-slate-500"><?= e($s['type']) ?> · <?= e($s['url']) ?></span></span>
            <?= icon('arrow-right', 'h-4 w-4 shrink-0 text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-accent-600') ?></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    <div class="min-w-0 <?= $suggestions ? 'lg:col-span-7' : 'lg:col-span-12' ?>">
      <h2 class="font-display text-xl font-extrabold text-brand-900">Popular pages</h2>
      <ul class="mt-5 grid gap-3 sm:grid-cols-2<?= $suggestions ? '' : ' lg:grid-cols-4' ?>">
        <?php foreach ($popular as [$label, $url, $ic, $text]): ?>
          <li><a href="<?= e(site_url(ltrim($url, '/'))) ?>" class="group flex h-full items-start gap-3 rounded-[18px] border border-warm-2 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-800 transition group-hover:bg-brand-900 group-hover:text-white"><?= icon($ic, 'h-5 w-5') ?></span>
            <span class="min-w-0"><span class="block font-semibold text-brand-900"><?= e($label) ?></span><span class="block text-sm text-slate-500"><?= e($text) ?></span></span></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <p class="mt-10 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600">
    <?= icon('messages-square', 'h-4 w-4 text-accent-600') ?>Think this link should work? Tell us at
    <a class="font-semibold text-brand-900 underline underline-offset-2" href="mailto:<?= e($c['email']) ?>?subject=<?= rawurlencode('Broken link on the website') ?>&amp;body=<?= rawurlencode('The page ' . $rel . ' could not be found.') ?>"><?= e($c['email']) ?></a>
    or call <a class="font-semibold text-brand-900 underline underline-offset-2" href="<?= e(cp_tel($c['phone'])) ?>"><?= e($c['phone']) ?></a>.</p>
<?= section_close() ?>
<?php
site_footer([
    'cta_title' => 'Start Your Journey at GIMT',
    'cta_text' => 'Explore industry-focused programs, check eligibility and apply online in a few minutes.',
    'cta_primary' => cp_route_ok('/apply') ? ['label' => 'Apply for Admission', 'url' => '/apply'] : ['label' => 'Contact Us', 'url' => '/contact'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
