<?php
/**
 * Notice detail (/notices/{slug}) — one public notice: category, pinned / new / archived status, publication + expiry
 * dates, audience, sanitised rich description, attachment download card (real upload URL, type, size), share links
 * (copy link, native share, WhatsApp, LinkedIn, X, Facebook, email), print, related notices, upcoming events,
 * breadcrumbs, NewsArticle + BreadcrumbList JSON-LD. Expired notices stay reachable but are clearly marked "Archived"
 * (noindex). Drafts, scheduled, internal (faculty / staff) and unknown notices → 404.php.
 * Each human visit adds one to notices.views (once per session; bots and previews are ignored).
 */
require_once __DIR__ . '/app/init.php';
require_once __DIR__ . '/includes/partials/notices_events.php';

$slug = strtolower(ne_get('slug'));
$now = ne_now();
$notice = null;
if (preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    [$w, $a] = ne_notice_where(null, $now);
    $notice = ne_try(fn () => db_row('SELECT ' . ne_notice_columns() . ' ' . ne_notice_from() . " WHERE n.slug = ? AND $w LIMIT 1", array_merge([$now, $slug], $a)));
}
if (!$notice) {
    ne_not_found('notice');
}

require __DIR__ . '/includes/config.php';

/* ------------------------------------------------------------------ view counter (admin "Website views" KPI) */
$ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua !== '' && !preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|curl|wget|python|scrapy|httpclient|facebookexternalhit|whatsapp/i', $ua)) {
    $seen = (array) ($_SESSION['ne_viewed_notices'] ?? []);
    if (!in_array((int) $notice['id'], $seen, true)) {
        // updated_at = updated_at keeps the ON UPDATE timestamp (sitemap lastmod) untouched
        ne_try(fn () => db_exec('UPDATE notices SET views = views + 1, updated_at = updated_at WHERE id = ?', [(int) $notice['id']]));
        $_SESSION['ne_viewed_notices'] = array_slice(array_merge($seen, [(int) $notice['id']]), -100);
    }
}

/* ------------------------------------------------------------------ data */
$cat = ne_notice_category((string) $notice['category']);
$archived = !empty($notice['is_archived']);
$pinned = !$archived && !empty($notice['is_pinned']);
$pub = ne_notice_date($notice);
$pubTs = strtotime($pub) ?: time();
$expTs = !empty($notice['expiry_date']) ? strtotime((string) $notice['expiry_date']) : null;
$att = ne_attachment($notice['attachment'] ?? null, (string) $notice['title']);
$body = ne_rich($notice['description'] ?? '');
$excerpt = ne_excerpt($notice['description'] ?? '', 160);
$url = absolute_url('notices/' . $notice['slug']);
$catUrl = ne_query_url('notices', ['category' => $notice['category']]);
$related = ne_latest_notices(4, [(int) $notice['id']], (string) $notice['category']);
if (count($related) < 4) {
    $related = array_merge($related, ne_latest_notices(4 - count($related), array_merge([(int) $notice['id']], array_map(fn ($r) => (int) $r['id'], $related))));
}
$events = ne_upcoming_events(3);
$modified = !empty($notice['updated_at']) && strtotime((string) $notice['updated_at']) > $pubTs ? (string) $notice['updated_at'] : $pub;

/* ------------------------------------------------------------------ SEO */
$ogImage = 'assets/images/site/news-campus.jpg';
$article = array_filter([
    '@context' => 'https://schema.org', '@type' => 'NewsArticle',
    'headline' => mb_substr((string) $notice['title'], 0, 110),
    'description' => $excerpt ?: null,
    'datePublished' => ne_iso($pub), 'dateModified' => ne_iso($modified), 'expires' => $expTs ? date('c', $expTs) : null,
    'articleSection' => $cat['label'],
    'url' => $url, 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
    'image' => [ne_abs_image($ogImage)],
    'author' => ne_org_ld(), 'publisher' => ne_org_ld(),
    'isAccessibleForFree' => true, 'inLanguage' => 'en-IN',
    'associatedMedia' => $att ? array_filter(['@type' => 'MediaObject', 'name' => $att['type'], 'contentUrl' => preg_match('#^https?://#', $att['url']) ? $att['url'] : absolute_url(ltrim(substr($att['url'], strlen(base_path())), '/')),
        'encodingFormat' => $att['ext'] === 'pdf' ? 'application/pdf' : null, 'contentSize' => $att['size']]) : null,
], fn ($v) => $v !== null && $v !== '');
ne_site_header([
    'title' => $notice['title'] . ($archived ? ' (Archived)' : ''),
    'description' => $excerpt !== '' ? $excerpt : $cat['label'] . ' notice from ' . institute_name() . ', published ' . date('d M Y', $pubTs) . '.',
    'active' => 'notices',
    'seo' => ['notice', (int) $notice['id']],
    'canonical' => $url,
    'robots' => $archived ? 'noindex,follow' : 'index,follow',
    'og_type' => 'article',
    'image' => $ogImage,
    'schema' => [ne_breadcrumb_ld([['Home', ''], ['Notice Board', 'notices'], [$cat['label'], 'notices?category=' . rawurlencode((string) $notice['category'])], [(string) $notice['title'], null]]), $article],
], [
    ['rel' => 'alternate', 'type' => 'application/rss+xml', 'title' => setting('institute_short_name', 'GIMT') . ' Notices', 'href' => ne_abs_url('notices', ['feed' => 'rss'])],
]);

$chips = [['icon' => 'calendar', 'label' => 'Published ' . date('d M Y', $pubTs)]];
if ($expTs) {
    $chips[] = ['icon' => $archived ? 'history' : 'calendar-clock', 'label' => ($archived ? 'Expired ' : 'Valid till ') . date('d M Y', $expTs)];
}
$chips[] = ['icon' => 'users', 'label' => 'For: ' . ne_audience_label($notice)];
?>
<div class="[&_.page-hero-title]:!text-[clamp(1.85rem,1.25rem+2.2vw,3rem)]">
<?php page_hero(
    (string) $notice['title'],
    '',
    ['Notice Board' => '/notices', $cat['label'] => 'notices?category=' . rawurlencode((string) $notice['category']), str_limit((string) $notice['title'], 48)],
    '',
    '',
    $chips,
    ['eyebrow' => $cat['label'] . ' notice', 'eyebrow_icon' => $cat['icon'], 'highlight' => false]
); ?>
</div>

<?= section_open(['bg' => 'light', 'padding' => 'tight']) ?>
  <div class="grid gap-8 lg:grid-cols-12">
    <div class="min-w-0 lg:col-span-8">
      <article class="rounded-[22px] border border-warm-2 bg-white p-5 sm:p-8" aria-label="<?= e((string) $notice['title']) ?>">
        <?php if ($archived): ?>
          <div class="mb-6 flex gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700" role="note">
            <?= icon('archive', 'mt-0.5 h-5 w-5 shrink-0 text-slate-500') ?>
            <p><strong class="text-brand-900">Archived notice.</strong> This notice <?= $expTs ? 'expired on ' . e(date('d M Y', $expTs)) : 'is no longer current' ?> and is kept for reference only. Check the <a href="<?= e(site_url('notices')) ?>" class="font-semibold text-accent-700 underline underline-offset-2 hover:text-brand-900">current notices</a> for the latest updates.</p>
          </div>
        <?php elseif ($pinned): ?>
          <div class="mb-6 flex gap-3 rounded-2xl border border-accent-200 bg-accent-50 p-4 text-sm text-brand-900" role="note">
            <?= ne_pin_icon('mt-0.5 h-5 w-5 shrink-0 text-accent-700') ?>
            <p><strong>Important notice</strong> — pinned to the top of the notice board<?= $expTs ? ' until ' . e(date('d M Y', $expTs)) : '' ?>.</p>
          </div>
        <?php endif; ?>

        <div class="flex flex-wrap items-center gap-1.5"><?= ne_category_badge($cat) ?><?= ne_notice_badges($notice) ?></div>

        <?php if ($body !== ''): ?>
          <div class="prose-site mt-5"><?= $body ?></div>
        <?php else: ?>
          <p class="mt-5 text-slate-600">The full text of this notice is available in the attached document<?= $att ? '' : ' at the institute office' ?>.</p>
        <?php endif; ?>

        <?php if ($att): ?>
          <section class="mt-8" aria-labelledby="notice-att">
            <h2 id="notice-att" class="mb-3 flex items-center gap-2 font-display text-base font-bold text-brand-900"><?= icon('paperclip', 'h-4 w-4 text-accent-700') ?>Attachment</h2>
            <?= ne_attachment_card($att, (string) $notice['title']) ?>
          </section>
        <?php endif; ?>

        <div class="mt-8 space-y-5 border-t border-warm-2 pt-6">
          <?= ne_share($url, (string) $notice['title'], 'Share this notice') ?>
          <div class="flex flex-wrap gap-2.5">
            <a href="<?= e($catUrl) ?>#board" class="btn btn-brochure btn-sm"><?= icon('arrow-left', 'h-4 w-4') ?>More in <?= e($cat['label']) ?></a>
            <button type="button" class="btn btn-secondary btn-sm hidden no-print" data-ne-print><?= icon('printer', 'h-4 w-4') ?>Print notice</button>
          </div>
        </div>
      </article>

      <?php if ($related): ?>
        <section class="mt-8" aria-labelledby="notice-related">
          <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <h2 id="notice-related" class="font-display text-xl font-bold text-brand-900 sm:text-2xl">Related notices</h2>
            <a href="<?= e(site_url('notices')) ?>#board" class="link-arrow text-sm">View the notice board<?= icon('arrow-right', 'h-4 w-4') ?></a>
          </div>
          <?= view_mode_open('notices-related', ['class' => 'group/vm', 'default' => 'compact']) ?>
            <?php foreach ($related as $r): ?><?= ne_notice_item($r) ?><?php endforeach; ?>
          <?= view_mode_close() ?>
        </section>
      <?php endif; ?>
    </div>

    <aside class="space-y-6 lg:col-span-4" aria-label="About this notice">
      <section class="rounded-[22px] border border-warm-2 bg-white p-5 sm:p-6" aria-labelledby="notice-facts">
        <h2 id="notice-facts" class="font-display text-base font-bold text-brand-900">Notice details</h2>
        <dl class="mt-4 divide-y divide-warm-2 text-sm">
          <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Category</dt><dd class="text-right font-semibold"><a href="<?= e($catUrl) ?>#board" class="text-brand-900 hover:text-accent-700"><?= e($cat['label']) ?></a></dd></div>
          <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Published</dt><dd class="text-right font-semibold text-brand-900"><time datetime="<?= e(ne_iso($pub) ?? '') ?>"><?= e(date('d M Y', $pubTs)) ?></time></dd></div>
          <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500"><?= $archived ? 'Expired on' : 'Valid till' ?></dt><dd class="text-right font-semibold text-brand-900"><?= $expTs ? '<time datetime="' . e(date('c', $expTs)) . '">' . e(date('d M Y', $expTs)) . '</time>' : 'Until further notice' ?></dd></div>
          <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Intended for</dt><dd class="text-right font-semibold text-brand-900"><?= e(ne_audience_label($notice)) ?></dd></div>
          <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Status</dt><dd class="text-right font-semibold <?= $archived ? 'text-slate-600' : 'text-emerald-700' ?>"><?= $archived ? 'Archived' : 'Current' ?></dd></div>
          <?php if ($att): ?>
            <div class="flex justify-between gap-4 py-2.5"><dt class="text-slate-500">Attachment</dt><dd class="text-right"><a href="<?= e($att['url']) ?>" download="<?= e($att['name']) ?>" class="inline-flex items-center gap-1.5 font-semibold text-accent-700 hover:text-brand-900"><?= icon('download', 'h-4 w-4') ?><?= e($att['short']) ?><?= $att['size'] ? ' · ' . e($att['size']) : '' ?></a></dd></div>
          <?php endif; ?>
        </dl>
      </section>

      <?php if ($events): ?>
        <?= ne_side_card('Upcoming Events', 'calendar-days', '<ul class="mt-2 divide-y divide-warm-2">' . implode('', array_map('ne_event_line', $events)) . '</ul>',
            '<a href="' . e(site_url('events')) . '" class="link-arrow text-sm">All events' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
      <?php endif; ?>

      <?= ne_help_card() ?>
    </aside>
  </div>
<?= section_close() ?>

<?= ne_script() ?>
<?php
site_footer([
    'cta_title' => 'Planning to Join GIMT?',
    'cta_text' => (string) setting('admissions_open_text', 'Admissions Open for 2026-27') . ' — explore our programs, check eligibility and apply online.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
