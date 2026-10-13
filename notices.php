<?php
/**
 * Notice Board (/notices) — dynamic notice board from the communication module's `notices` table.
 *
 *  - Only website-visible notices for public audiences (everyone / students / parents / alumni / department / course /
 *    semester — never internal faculty or staff notices), publish date reached; drafts and scheduled notices never show.
 *  - Current notices by default (published, not expired) — pinned first, then newest. "Archive" (?archived=1) lists
 *    expired / archived notices, newest expiry first.
 *  - Filters (all server-side, deep-linkable, work without JS): ?category=examination (pills with live counts),
 *    ?q= keyword search (title + text), ?from=YYYY-MM-DD&to=YYYY-MM-DD (publication date), ?page=N (10 per page).
 *  - Grid / List / Compact / Detailed view modes (view_mode_toggle(), remembered per visitor; list by default).
 *  - Sidebar: latest updates (RSS-like) + the real RSS 2.0 feed (?feed=rss, honours ?category=), upcoming events,
 *    help card with the CTA system.
 *  - SEO: unique titles per category / archive / page, canonical without search/date params, noindex for searches,
 *    date ranges, the archive and empty or out-of-range pages, BreadcrumbList + CollectionPage/ItemList JSON-LD.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/notices_events.php';

$now = ne_now();
$perPage = 10;
// list view by default (brief); an explicit per-listing setting (view_mode_notices) still wins over the global default
$viewDefault = in_array((string) setting('view_mode_notices', ''), ['grid', 'list', 'compact', 'detailed'], true) ? (string) setting('view_mode_notices') : 'list';

/* ------------------------------------------------------------------ input */
// ne_get(): scalar values only (?q[]=x etc. are ignored instead of raising "Array to string conversion")
$archived = ne_get('archived') === '1';
$isFeed = ne_get('feed') === 'rss';
$q = mb_substr(trim((string) preg_replace('/\s+/u', ' ', ne_get('q'))), 0, 80);
$words = array_slice(array_values(array_filter(explode(' ', $q), fn ($w) => mb_strlen($w) >= 2)), 0, 6);
$validDate = function ($v): string {
    $v = (string) $v;
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
    return $d && $d->format('Y-m-d') === $v ? $v : '';
};
$from = $validDate(ne_get('from'));
$to = $validDate(ne_get('to'));
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$categoryIn = strtolower(ne_get('category'));
$page = max(1, (int) ne_get('page'));

/* ------------------------------------------------------------------ query building */
$scopeWhere = function (string $scope) use ($now): array {
    return ne_notice_where($scope, $now);
};
$filterSql = '';
$filterArgs = [];
foreach ($words as $w) {
    $like = '%' . addcslashes($w, '%_\\') . '%';
    // the description holds rich text: search its visible text, not the markup
    $filterSql .= ' AND (n.title LIKE ? OR ' . ne_text_sql('n.description') . ' LIKE ?)';
    array_push($filterArgs, $like, $like);
}
if ($from !== '') {
    $filterSql .= ' AND COALESCE(n.publish_date, n.created_at) >= ?';
    $filterArgs[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $filterSql .= ' AND COALESCE(n.publish_date, n.created_at) <= ?';
    $filterArgs[] = $to . ' 23:59:59';
}

$scope = $archived ? 'archive' : 'current';
[$baseWhere, $baseArgs] = $scopeWhere($scope);
// category pills: live counts in this scope with the search / date filters applied
$catCounts = ne_try(fn () => db_pairs("SELECT n.category, COUNT(*) FROM notices n WHERE $baseWhere $filterSql GROUP BY n.category", array_merge($baseArgs, $filterArgs)), []);
$knownCats = array_keys(ne_notice_category_meta());
$category = preg_match('/^[a-z_-]{2,30}$/', $categoryIn) && (in_array($categoryIn, $knownCats, true) || isset($catCounts[$categoryIn])) ? $categoryIn : '';
$catSql = $category !== '' ? ' AND n.category = ?' : '';
$catArgs = $category !== '' ? [$category] : [];

// totals of both scopes (for the Current / Archive switch) with every filter applied
$scopeTotals = [];
foreach (['current', 'archive'] as $s) {
    [$w, $a] = $scopeWhere($s);
    $scopeTotals[$s] = (int) ne_try(fn () => db_value("SELECT COUNT(*) FROM notices n WHERE $w $filterSql $catSql", array_merge($a, $filterArgs, $catArgs)), 0);
}
$total = $scopeTotals[$scope];
$pg = paginate($total, $page, $perPage);
$outOfRange = $page > $pg['pages'] && $total > 0;
if ($outOfRange && !$isFeed) {
    // ?page beyond the last page → the last real page (keeps every filter)
    redirect(ne_query_url('notices', array_filter(['category' => $category ?: null, 'q' => $q !== '' ? $q : null, 'from' => $from ?: null, 'to' => $to ?: null, 'archived' => $archived ? '1' : null, 'page' => $pg['pages']])), 302);
}
$order = $archived ? 'COALESCE(n.expiry_date, n.publish_date, n.created_at) DESC, n.id DESC' : 'n.is_pinned DESC, COALESCE(n.publish_date, n.created_at) DESC, n.id DESC';
$notices = $total ? ne_try(fn () => db_all('SELECT ' . ne_notice_columns() . ' ' . ne_notice_from() . " WHERE $baseWhere $filterSql $catSql ORDER BY $order LIMIT " . (int) $pg['per_page'] . ' OFFSET ' . (int) $pg['offset'],
    array_merge([$now], $baseArgs, $filterArgs, $catArgs)), []) : [];

/* ------------------------------------------------------------------ RSS 2.0 feed (?feed=rss[&category=]) */
if ($isFeed) {
    $items = ne_latest_notices(30, [], $category !== '' ? $category : null);
    $catLabel = $category !== '' ? ne_notice_category($category)['label'] . ' ' : '';
    $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $self = ne_abs_url('notices', ['feed' => 'rss', 'category' => $category ?: null]);
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>';
    echo '<title>' . $x(setting('institute_short_name', 'GIMT') . ' ' . $catLabel . 'Notices') . '</title>';
    echo '<link>' . $x(ne_abs_url('notices', ['category' => $category ?: null])) . '</link>';
    echo '<description>' . $x('Latest ' . strtolower($catLabel) . 'notices and announcements from ' . institute_name() . '.') . '</description>';
    echo '<language>en-in</language><atom:link href="' . $x($self) . '" rel="self" type="application/rss+xml"/>';
    if ($items) {
        echo '<lastBuildDate>' . date(DATE_RSS, strtotime(ne_notice_date($items[0])) ?: time()) . '</lastBuildDate>';
    }
    foreach ($items as $n) {
        $link = absolute_url('notices/' . $n['slug']);
        $att = ne_attachment($n['attachment'] ?? null, (string) $n['title']);
        echo '<item><title>' . $x($n['title']) . '</title><link>' . $x($link) . '</link><guid isPermaLink="true">' . $x($link) . '</guid>'
            . '<pubDate>' . date(DATE_RSS, strtotime(ne_notice_date($n)) ?: time()) . '</pubDate>'
            . '<category>' . $x(ne_notice_category((string) $n['category'])['label']) . '</category>'
            . '<description>' . $x(ne_excerpt($n['description'] ?? '', 400)) . '</description>'
            . ($att && $att['bytes'] ? '<enclosure url="' . $x(preg_match('#^https?://#', $att['url']) ? $att['url'] : absolute_url(ltrim(substr($att['url'], strlen(base_path())), '/'))) . '" length="' . (int) $att['bytes'] . '" type="' . $x(['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$att['ext']] ?? 'application/octet-stream') . '"/>' : '')
            . '</item>';
    }
    echo '</channel></rss>';
    exit;
}

/* ------------------------------------------------------------------ page data */
$liveTotal = $archived || $category !== '' || $filterSql !== '' ? (int) ne_try(function () use ($scopeWhere) {
    [$w, $a] = $scopeWhere('current');
    return db_value("SELECT COUNT(*) FROM notices n WHERE $w", $a);
}, 0) : $scopeTotals['current'];
[$cw, $ca] = $scopeWhere('current');
$pinnedTotal = (int) ne_try(fn () => db_value("SELECT COUNT(*) FROM notices n WHERE $cw AND n.is_pinned = 1", $ca), 0);
$lastUpdate = ne_try(fn () => db_value("SELECT MAX(COALESCE(n.publish_date, n.created_at)) FROM notices n WHERE $cw", $ca));
$latest = ne_latest_notices(6);
$events = ne_upcoming_events(3);
$catInfo = $category !== '' ? ne_notice_category($category) : null;
$filtered = $words !== [] || $from !== '' || $to !== '';

// URL helpers (keep every active filter)
$state = ['category' => $category ?: null, 'q' => $q !== '' ? $q : null, 'from' => $from ?: null, 'to' => $to ?: null, 'archived' => $archived ? '1' : null];
$urlWith = fn (array $over = []) => ne_query_url('notices', array_merge($state, $over));
$pageUrl = fn (int $p) => $urlWith(['page' => $p]) . '#board';

/* ------------------------------------------------------------------ SEO */
$title = ($catInfo ? $catInfo['label'] . ' Notices' : ($archived ? 'Notice Archive' : 'Notice Board')) . ($catInfo && $archived ? ' — Archive' : '') . ($pg['page'] > 1 ? ' — Page ' . $pg['page'] : '');
$descr = $catInfo
    ? 'Latest ' . strtolower($catInfo['label']) . ' notices and announcements from ' . institute_name() . ' — publication dates, validity and downloadable circulars.'
    : ($archived ? 'Archive of expired notices and circulars from ' . institute_name() . ' — past admission, examination, holiday and event announcements.'
        : 'Official notice board of ' . institute_name() . ': admission notices, examination schedules, results, holidays, events, scholarships and important deadlines with downloadable circulars.');
$noindex = $filtered || $archived || $outOfRange || ($total === 0);
$canonical = ne_abs_url('notices', ['category' => $category ?: null, 'archived' => $archived ? '1' : null, 'page' => $outOfRange ? null : $pg['page']]);
$schema = [
    ne_breadcrumb_ld(array_values(array_filter([['Home', ''], $catInfo || $archived ? ['Notice Board', 'notices'] : ['Notice Board', null],
        $catInfo ? [$catInfo['label'], null] : ($archived ? ['Archive', null] : null)]))),
    ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $title, 'description' => $descr, 'url' => $canonical,
     'isPartOf' => ['@type' => 'WebSite', 'name' => institute_name(), 'url' => absolute_url('')],
     'mainEntity' => ['@type' => 'ItemList', 'numberOfItems' => $total, 'itemListElement' => array_map(fn ($n, $i) => ['@type' => 'ListItem', 'position' => $pg['offset'] + $i + 1,
         'url' => absolute_url('notices/' . $n['slug']), 'name' => (string) $n['title']], $notices, array_keys($notices))]],
];
// <head> extras (the shared header has no slot yet): rel prev/next of this listing + the RSS feed
ne_site_header([
    'title' => $title,
    'description' => $descr,
    'active' => 'notices',
    'seo' => !$catInfo && !$archived && !$filtered && $pg['page'] === 1 ? ['route', '/notices'] : null,
    'canonical' => $canonical,
    'robots' => $noindex ? 'noindex,follow' : 'index,follow',
    'schema' => $schema,
], [
    ['rel' => 'prev', 'href' => !$outOfRange && $pg['page'] > 1 ? ne_abs_url('notices', array_merge($state, ['page' => $pg['page'] - 1])) : null],
    ['rel' => 'next', 'href' => !$outOfRange && $pg['page'] < $pg['pages'] ? ne_abs_url('notices', array_merge($state, ['page' => $pg['page'] + 1])) : null],
    ['rel' => 'alternate', 'type' => 'application/rss+xml', 'title' => setting('institute_short_name', 'GIMT') . ($catInfo ? ' ' . $catInfo['label'] : '') . ' Notices',
     'href' => ne_abs_url('notices', ['feed' => 'rss', 'category' => $category ?: null])],
]);

$crumbs = $catInfo ? ['Notice Board' => '/notices', $catInfo['label']] : ($archived ? ['Notice Board' => '/notices', 'Archive'] : ['Notice Board']);
$chips = [['icon' => 'megaphone', 'label' => number_format($liveTotal) . ' current notice' . ($liveTotal === 1 ? '' : 's')]];
if ($pinnedTotal) {
    $chips[] = ['icon' => 'bookmark', 'label' => $pinnedTotal . ' pinned'];
}
if ($lastUpdate) {
    $chips[] = ['icon' => 'clock', 'label' => 'Updated ' . date('d M Y', strtotime((string) $lastUpdate))];
}
page_hero(
    $catInfo ? $catInfo['label'] . ' Notices' : ($archived ? 'Notice Archive' : 'Notice Board'),
    $archived ? 'Past announcements & circulars' : 'Announcements, circulars & important dates',
    $crumbs,
    '',
    $archived ? 'Expired notices stay available here for reference — exam schedules, results, holidays and admission announcements from earlier in the session.'
        : 'Official updates for students, parents and applicants — admissions, examinations, results, holidays, events, scholarships and deadlines, with downloadable circulars.',
    $chips,
    ['eyebrow' => 'Official updates', 'eyebrow_icon' => 'megaphone', 'highlight' => $catInfo ? $catInfo['label'] : ($archived ? 'Archive' : 'Board')]
);

$catOrder = array_values(array_unique(array_merge(array_values(array_filter($knownCats, fn ($k) => isset($catCounts[$k]))), array_keys($catCounts))));
if ($category !== '' && !in_array($category, $catOrder, true)) {
    $catOrder[] = $category;
}
$scopeTotal = array_sum(array_map('intval', $catCounts));
?>

<?= section_open(['id' => 'board', 'bg' => 'light', 'padding' => 'tight', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-8 lg:grid-cols-12">
    <div class="min-w-0 lg:col-span-8">
      <!-- Search + date range -->
      <form method="get" action="<?= e(site_url('notices')) ?>#board" role="search" aria-label="Search notices" class="rounded-[22px] border border-warm-2 bg-white p-4 sm:p-5">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-[minmax(0,1fr)_9.5rem_9.5rem_auto] md:items-end">
          <div class="col-span-2 md:col-span-1">
            <label for="ne-q" class="form-label">Search notices</label>
            <div class="relative">
              <?= icon('search', 'pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400') ?>
              <input id="ne-q" type="search" name="q" value="<?= e($q) ?>" maxlength="80" class="form-input !pl-11" placeholder="Title or keyword, e.g. admit card" autocomplete="off">
            </div>
          </div>
          <div>
            <label for="ne-from" class="form-label">Published from</label>
            <input id="ne-from" type="date" name="from" value="<?= e($from) ?>" max="<?= e(date('Y-m-d')) ?>" class="form-input">
          </div>
          <div>
            <label for="ne-to" class="form-label">Published to</label>
            <input id="ne-to" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>" class="form-input">
          </div>
          <div class="col-span-2 flex gap-2 md:col-span-1">
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
            <?php if ($archived): ?><input type="hidden" name="archived" value="1"><?php endif; ?>
            <button type="submit" class="btn btn-explore w-full md:w-auto"><?= icon('search', 'h-4 w-4') ?>Search</button>
          </div>
        </div>
      </form>

      <!-- Category pills -->
      <nav class="pill-row scrollbar-none mt-5" aria-label="Filter notices by category">
        <a href="<?= e(ne_query_url('notices', array_merge($state, ['category' => null])) . '#board') ?>" class="chip !px-4 !py-2 !text-sm<?= $category === '' ? ' chip-active' : '' ?>"<?= $category === '' ? ' aria-current="page"' : '' ?>>All<span class="chip-count<?= $category === '' ? ' is-active' : '' ?>"><?= $scopeTotal ?></span></a>
        <?php foreach ($catOrder as $key): $c = ne_notice_category($key); $on = $category === $key; ?>
          <a href="<?= e(ne_query_url('notices', array_merge($state, ['category' => $key])) . '#board') ?>" class="chip !px-4 !py-2 !text-sm<?= $on ? ' chip-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($c['icon'], 'h-4 w-4') ?><?= e($c['label']) ?><span class="chip-count<?= $on ? ' is-active' : '' ?>"><?= (int) ($catCounts[$key] ?? 0) ?></span></a>
        <?php endforeach; ?>
      </nav>

      <!-- Results header: scope switch, active filters, view modes -->
      <div class="mt-6 flex flex-col gap-4 border-b border-warm-2 pb-4 sm:flex-row sm:items-center sm:justify-between">
        <nav class="inline-flex w-max rounded-full border border-slate-200 bg-white p-1" aria-label="Current or archived notices">
          <a href="<?= e(ne_query_url('notices', array_merge($state, ['archived' => null])) . '#board') ?>" class="inline-flex h-9 items-center gap-1.5 rounded-full px-4 text-[13px] font-semibold transition <?= !$archived ? 'bg-brand-900 text-white' : 'text-slate-600 hover:text-brand-900' ?>"<?= !$archived ? ' aria-current="page"' : '' ?>><?= icon('megaphone', 'h-4 w-4') ?>Current<span class="opacity-75">(<?= $scopeTotals['current'] ?>)</span></a>
          <a href="<?= e(ne_query_url('notices', array_merge($state, ['archived' => '1'])) . '#board') ?>" class="inline-flex h-9 items-center gap-1.5 rounded-full px-4 text-[13px] font-semibold transition <?= $archived ? 'bg-brand-900 text-white' : 'text-slate-600 hover:text-brand-900' ?>"<?= $archived ? ' aria-current="page"' : '' ?>><?= icon('archive', 'h-4 w-4') ?>Show archived<span class="opacity-75">(<?= $scopeTotals['archive'] ?>)</span></a>
        </nav>
        <div class="hidden [.js_&]:block"><?= view_mode_toggle('notices', ['grid', 'list', 'compact', 'detailed'], $viewDefault) ?></div>
      </div>

      <div class="mt-4 flex flex-wrap items-center gap-2 text-sm text-slate-600" aria-live="polite">
        <p class="mr-1">
          <?php if ($total): ?>
            Showing <strong class="text-brand-900"><?= $pg['from'] ?>–<?= $pg['to'] ?></strong> of <strong class="text-brand-900"><?= $total ?></strong> <?= $archived ? 'archived' : 'current' ?> <?= $catInfo ? e(strtolower($catInfo['label'])) . ' ' : '' ?>notice<?= $total === 1 ? '' : 's' ?>
          <?php else: ?>
            No matching notices
          <?php endif; ?>
        </p>
        <?php
        $remove = [];
        if ($q !== '') {
            $remove[] = ['Search: “' . $q . '”', $urlWith(['q' => null, 'page' => null])];
        }
        if ($from !== '') {
            $remove[] = ['From ' . date('d M Y', strtotime($from)), $urlWith(['from' => null, 'page' => null])];
        }
        if ($to !== '') {
            $remove[] = ['To ' . date('d M Y', strtotime($to)), $urlWith(['to' => null, 'page' => null])];
        }
        if ($catInfo) {
            $remove[] = [$catInfo['label'], $urlWith(['category' => null, 'page' => null])];
        }
        foreach ($remove as [$label, $href]): ?>
          <a href="<?= e($href) ?>#board" class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-900 ring-1 ring-slate-200 hover:ring-brand-900"><?= e($label) ?><?= icon('x', 'h-3.5 w-3.5') ?><span class="sr-only"> — remove filter</span></a>
        <?php endforeach; ?>
        <?php if (count($remove) > 1): ?><a href="<?= e(ne_query_url('notices', ['archived' => $archived ? '1' : null])) ?>#board" class="text-xs font-semibold text-accent-700 underline-offset-2 hover:underline">Clear all</a><?php endif; ?>
      </div>

      <div class="mt-5">
        <?php if ($notices): ?>
          <?= view_mode_open('notices', ['class' => 'group/vm', 'default' => $viewDefault]) ?>
            <?php foreach ($notices as $n): ?><?= ne_notice_item($n, ['heading' => 'h2', 'highlight' => $words]) ?><?php endforeach; ?>
          <?= view_mode_close() ?>
          <?= ne_pagination($pg, $pageUrl, 'Notice board pages') ?>
        <?php else:
            $actions = '';
            if (!$archived && $scopeTotals['archive'] > 0) {
                $actions .= button_link('View ' . $scopeTotals['archive'] . ' archived', ne_query_url('notices', array_merge($state, ['archived' => '1'])) . '#board', 'brochure', ['icon_left' => 'archive', 'icon' => '']);
            } elseif ($archived && $scopeTotals['current'] > 0) {
                $actions .= button_link('View ' . $scopeTotals['current'] . ' current', ne_query_url('notices', array_merge($state, ['archived' => null])) . '#board', 'brochure', ['icon_left' => 'megaphone', 'icon' => '']);
            }
            if ($filtered || $category !== '') {
                $actions .= button_link('Clear filters', ne_query_url('notices', ['archived' => $archived ? '1' : null]) . '#board', 'explore', ['icon' => '']);
            }
            echo $outOfRange
                ? ne_empty('file-text', 'This page does not exist', 'The notice board has fewer pages than requested.', button_link('Go to page 1', $pageUrl(1), 'explore', ['icon' => '']))
                : ne_empty($catInfo ? $catInfo['icon'] : 'megaphone',
                    $filtered ? 'No notices match your search' : ($catInfo ? 'No ' . ($archived ? 'archived ' : 'current ') . strtolower($catInfo['label']) . ' notices' : ($archived ? 'The archive is empty' : 'No current notices')),
                    $filtered ? 'Try a different keyword or a wider date range.' : ($archived ? 'Expired notices will appear here.' : 'New announcements are posted here as soon as they are published.'),
                    $actions);
        endif; ?>
      </div>
    </div>

    <!-- Sidebar -->
    <aside class="space-y-6 lg:col-span-4" aria-label="Notice board sidebar">
      <div class="space-y-6 lg:sticky lg:top-28">
        <?php
        $latestBody = $latest
            ? '<ul class="mt-2 divide-y divide-warm-2">' . implode('', array_map('ne_notice_line', $latest)) . '</ul>'
            : '<p class="mt-3 text-sm text-slate-600">No current notices yet.</p>';
        echo ne_side_card('Latest Updates', 'bell-ring', $latestBody,
            '<div class="flex flex-wrap items-center justify-between gap-3"><a href="' . e(site_url('notices')) . '#board" class="link-arrow text-sm">All notices' . icon('arrow-right', 'h-4 w-4') . '</a>'
            . '<a href="' . e(ne_query_url('notices', ['feed' => 'rss', 'category' => $category ?: null])) . '" class="inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-3 py-1.5 text-xs font-semibold text-accent-700 ring-1 ring-inset ring-accent-200 hover:bg-accent-100">' . icon('rss', 'h-3.5 w-3.5') . 'RSS feed<span class="sr-only"> of ' . ($catInfo ? e(strtolower($catInfo['label'])) . ' ' : '') . 'notices</span></a></div>');
        $eventsBody = $events
            ? '<ul class="mt-2 divide-y divide-warm-2">' . implode('', array_map('ne_event_line', $events)) . '</ul>'
            : '<p class="mt-3 text-sm text-slate-600">No upcoming events are scheduled right now.</p>';
        echo ne_side_card('Upcoming Events', 'calendar-days', $eventsBody,
            '<div class="flex flex-wrap items-center justify-between gap-3"><a href="' . e(site_url('events')) . '" class="link-arrow text-sm">All events' . icon('arrow-right', 'h-4 w-4') . '</a>'
            . (ne_route_exists('academics') ? '<a href="' . e(site_url('academics')) . '#calendar" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-900 hover:text-accent-700">' . icon('calendar-range', 'h-3.5 w-3.5') . 'Academic calendar</a>' : '') . '</div>');
        echo ne_help_card('Have a question about a notice?', 'Contact the office for clarifications on examinations, admissions, fees or campus schedules.');
        ?>
      </div>
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
