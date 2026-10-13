<?php
/**
 * Events (/events) — campus events from the communication module's `events` table (show_on_website = 1; drafts never).
 *
 *  - Tabs: Upcoming (default — not yet ended, soonest first; cancelled ones stay listed with a "Cancelled" badge) and
 *    Past (?tab=past — ended or completed, newest first).
 *  - Filters (server-side, deep-linkable, no JS needed): ?category= (pills with live counts), ?month=YYYY-MM (months
 *    that have events in the tab), ?q= (title, summary, venue, organiser), ?page=N (8 per page).
 *  - Cards: photo + date tile, category, date · time, venue, registration status from the real data (open / only N
 *    seats left / fully booked / closed / cancelled / completed / happening now), seats bar in the detailed view.
 *  - Grid / List / Compact / Detailed view modes (grid by default), "Next up" strip with a live countdown, links to the
 *    academic calendar (/academics#calendar), the notice board and campus life.
 *  - SEO: unique titles, canonical (tab + page only), noindex for filtered / empty pages, BreadcrumbList + ItemList.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/notices_events.php';

$now = ne_now();
$perPage = 8;

/* ------------------------------------------------------------------ input */
// ne_get(): scalar values only (?q[]=x etc. are ignored instead of raising "Array to string conversion")
$tab = ne_get('tab') === 'past' ? 'past' : 'upcoming';
$q = mb_substr(trim((string) preg_replace('/\s+/u', ' ', ne_get('q'))), 0, 80);
$words = array_slice(array_values(array_filter(explode(' ', $q), fn ($w) => mb_strlen($w) >= 2)), 0, 6);
$month = preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', ne_get('month')) ? ne_get('month') : '';
$categoryIn = strtolower(ne_get('category'));
$page = max(1, (int) ne_get('page'));
$catMeta = ne_event_category_meta();
$category = isset($catMeta[$categoryIn]) ? $categoryIn : '';

/* ------------------------------------------------------------------ query building */
$searchSql = '';
$searchArgs = [];
foreach ($words as $w) {
    $like = '%' . addcslashes($w, '%_\\') . '%';
    $searchSql .= ' AND (e.title LIKE ? OR e.excerpt LIKE ? OR e.venue LIKE ? OR e.organizer LIKE ?)';
    array_push($searchArgs, $like, $like, $like, $like);
}
$monthSql = '';
$monthArgs = [];
if ($month !== '') {
    $monthSql = ' AND e.start_datetime >= ? AND e.start_datetime <= ?';
    $monthArgs = [$month . '-01 00:00:00', date('Y-m-t 23:59:59', strtotime($month . '-01'))];
}
$catSql = $category !== '' ? ' AND e.category = ?' : '';
$catArgs = $category !== '' ? [$category] : [];

[$tabWhere, $tabArgs] = ne_event_where($tab, $now);
$catCounts = ne_try(fn () => db_pairs("SELECT e.category, COUNT(*) FROM events e WHERE $tabWhere $searchSql $monthSql GROUP BY e.category", array_merge($tabArgs, $searchArgs, $monthArgs)), []);
$months = ne_try(fn () => db_pairs("SELECT DATE_FORMAT(e.start_datetime, '%Y-%m') AS ym, COUNT(*) FROM events e WHERE $tabWhere $searchSql $catSql GROUP BY ym ORDER BY ym " . ($tab === 'past' ? 'DESC' : 'ASC'),
    array_merge($tabArgs, $searchArgs, $catArgs)), []);
$tabTotals = [];
foreach (['upcoming', 'past'] as $t) {
    [$w, $a] = ne_event_where($t, $now);
    $tabTotals[$t] = (int) ne_try(fn () => db_value("SELECT COUNT(*) FROM events e WHERE $w $searchSql $monthSql $catSql", array_merge($a, $searchArgs, $monthArgs, $catArgs)), 0);
}
$total = $tabTotals[$tab];
$pg = paginate($total, $page, $perPage);
$outOfRange = $page > $pg['pages'] && $total > 0;
if ($outOfRange) {
    // ?page beyond the last page → the last real page (keeps every filter)
    redirect(ne_query_url('events', array_filter(['tab' => $tab === 'past' ? 'past' : null, 'category' => $category ?: null, 'month' => $month ?: null, 'q' => $q !== '' ? $q : null, 'page' => $pg['pages']])), 302);
}
$events = $total ? ne_try(fn () => db_all('SELECT ' . ne_event_columns() . " FROM events e WHERE $tabWhere $searchSql $monthSql $catSql
    ORDER BY e.start_datetime " . ($tab === 'past' ? 'DESC' : 'ASC') . ', e.id LIMIT ' . (int) $pg['per_page'] . ' OFFSET ' . (int) $pg['offset'],
    array_merge($tabArgs, $searchArgs, $monthArgs, $catArgs)), []) : [];

/* ------------------------------------------------------------------ page data */
[$uw, $ua] = ne_event_where('upcoming', $now);
$upcomingAll = (int) ne_try(fn () => db_value("SELECT COUNT(*) FROM events e WHERE $uw AND e.status = 'published'", $ua), 0);
$thisMonth = (int) ne_try(fn () => db_value("SELECT COUNT(*) FROM events e WHERE $uw AND e.status = 'published' AND e.start_datetime <= ?", array_merge($ua, [date('Y-m-t 23:59:59')])), 0);
[$pw, $pa] = ne_event_where('past', $now);
// "held this session" only counts events since the current academic session started (else: all past events)
$sess = ne_try(fn () => current_session(), []) ?: [];
$sessStart = !empty($sess['start_date']) ? (string) $sess['start_date'] : '';
$pastAll = (int) ne_try(fn () => db_value("SELECT COUNT(*) FROM events e WHERE $pw" . ($sessStart !== '' ? ' AND e.start_datetime >= ?' : ''),
    array_merge($pa, $sessStart !== '' ? [$sessStart . ' 00:00:00'] : [])), 0);
$next = ne_upcoming_events(1)[0] ?? null;
$filtered = $words !== [] || $month !== '' || $category !== '';
$catInfo = $category !== '' ? ne_event_category($category) : null;

$state = ['tab' => $tab === 'past' ? 'past' : null, 'category' => $category ?: null, 'month' => $month ?: null, 'q' => $q !== '' ? $q : null];
$urlWith = fn (array $over = []) => ne_query_url('events', array_merge($state, $over));
$pageUrl = fn (int $p) => $urlWith(['page' => $p]) . '#listing';

/* ------------------------------------------------------------------ SEO */
$title = ($catInfo ? $catInfo['label'] . ' Events' : ($tab === 'past' ? 'Past Events' : 'Events & Activities')) . ($catInfo && $tab === 'past' ? ' — Past' : '') . ($pg['page'] > 1 ? ' — Page ' . $pg['page'] : '');
$descr = $tab === 'past'
    ? 'Past events at ' . institute_name() . ' — seminars, workshops, webinars, fests, sports meets and celebrations held this session.'
    : 'Upcoming events at ' . institute_name() . ': seminars, workshops, webinars, fests, sports, placement drives and admission counselling — dates, venues and online registration.';
$canonical = ne_abs_url('events', ['tab' => $tab === 'past' ? 'past' : null, 'page' => $outOfRange ? null : $pg['page']]);
ne_site_header([
    'title' => $title,
    'description' => $descr,
    'active' => 'events',
    'seo' => !$filtered && $tab === 'upcoming' && $pg['page'] === 1 ? ['route', '/events'] : null,
    'canonical' => $canonical,
    'robots' => $filtered || $outOfRange || $total === 0 ? 'noindex,follow' : 'index,follow',
    'image' => 'assets/images/site/students-lawn.jpg',
    'preload' => [asset('assets/images/site/students-lawn.jpg')],
    'schema' => [
        ne_breadcrumb_ld($tab === 'past' ? [['Home', ''], ['Events', 'events'], ['Past Events', null]] : [['Home', ''], ['Events', null]]),
        ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $title, 'numberOfItems' => $total,
         'itemListElement' => array_map(fn ($e, $i) => ['@type' => 'ListItem', 'position' => $pg['offset'] + $i + 1, 'url' => absolute_url('events/' . $e['slug']), 'name' => (string) $e['title']], $events, array_keys($events))],
    ],
], [
    // rel prev/next in <head> (the shared header has no slot yet — see ne_site_header())
    ['rel' => 'prev', 'href' => !$outOfRange && $pg['page'] > 1 ? ne_abs_url('events', array_merge($state, ['page' => $pg['page'] - 1])) : null],
    ['rel' => 'next', 'href' => !$outOfRange && $pg['page'] < $pg['pages'] ? ne_abs_url('events', array_merge($state, ['page' => $pg['page'] + 1])) : null],
]);

$chips = [['icon' => 'calendar-days', 'label' => $upcomingAll . ' upcoming event' . ($upcomingAll === 1 ? '' : 's')]];
if ($thisMonth) {
    $chips[] = ['icon' => 'calendar-check', 'label' => $thisMonth . ' this month'];
}
if ($pastAll) {
    $chips[] = ['icon' => 'history', 'label' => $pastAll . ($sessStart !== '' ? ' held in ' . ($sess['name'] ?? 'this session') : ' past event' . ($pastAll === 1 ? '' : 's'))];
}
$actions = (ne_route_exists('academics') ? button_link('Academic Calendar', '/academics#calendar', 'brochure', ['icon_left' => 'calendar-range', 'icon' => '']) : '')
    . button_link('Notice Board', '/notices', 'explore');
page_hero(
    $tab === 'past' ? 'Past Events' : 'Events & Activities',
    $tab === 'past' ? 'Highlights from earlier in the session' : 'Seminars, workshops, fests & campus life',
    $tab === 'past' ? ['Events' => '/events', 'Past Events'] : ['Events'],
    'assets/images/site/students-lawn.jpg',
    'Industry talks, hands-on workshops, webinars, cultural nights, sports meets and placement drives — find what’s happening at GIMT and register online.',
    $chips,
    ['eyebrow' => 'Campus calendar', 'eyebrow_icon' => 'calendar-days', 'highlight' => $tab === 'past' ? 'Events' : 'Activities', 'actions' => $actions,
     'image_alt' => 'Students on the GIMT campus lawn', 'image2' => 'assets/images/site/facility-sports.jpg',
     'badge' => $next ? ['icon' => 'calendar-clock', 'text' => 'Next: ' . date('d M', strtotime((string) $next['start_datetime'])) . ' · ' . str_limit((string) $next['title'], 30)] : false]
);
?>

<?php if ($next && $tab === 'upcoming' && !$filtered && $pg['page'] === 1):
    $ns = ne_event_state($next);
    $nw = ne_event_when($next); ?>
<section class="relative z-[2] -mt-6 sm:-mt-8" aria-label="Next event">
  <div class="container-site">
    <div class="flex flex-col gap-4 rounded-[22px] bg-brand-900 p-5 text-white/80 shadow-pop sm:p-6 lg:flex-row lg:items-center lg:gap-8"<?= reveal_attr('fade-up') ?>>
      <div class="flex min-w-0 flex-1 items-center gap-4">
        <?= ne_date_tile($nw, '!bg-accent-600 [&_.date-tile-mon]:!text-white/85') ?>
        <div class="min-w-0">
          <p class="text-xs font-bold uppercase tracking-[0.16em] text-accent-300"><?= $ns['phase'] === 'ongoing' ? 'Happening now' : 'Next up' ?></p>
          <p class="mt-1 font-display text-lg font-bold leading-snug text-white sm:text-xl"><a href="<?= e(ne_event_url($next)) ?>" class="hover:text-accent-300"><?= e((string) $next['title']) ?></a></p>
          <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm"><span class="inline-flex items-center gap-1.5"><?= icon('clock', 'h-4 w-4 text-accent-300') ?><?= e($nw['date'] . ' · ' . $nw['time']) ?></span><?php if (trim((string) $next['venue']) !== ''): ?><span class="inline-flex items-center gap-1.5"><?= icon(ne_event_online($next['venue']) ? 'video' : 'map-pin', 'h-4 w-4 text-accent-300') ?><?= e((string) $next['venue']) ?></span><?php endif; ?></p>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-3 lg:justify-end">
        <?php if ($ns['phase'] === 'upcoming' && ($cd = countdown($ns['start'], ['label' => 'Starts in']))): ?><span class="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white"><?= $cd ?></span><?php endif; ?>
        <?php if ($ns['reg'] === 'open'): ?><?= button_link('Register Now', ne_event_url($next, '#register'), 'apply', ['size' => 'sm']) ?><?php endif; ?>
        <a href="<?= e(ne_event_url($next)) ?>" class="btn btn-outline-light btn-sm">Details<span class="sr-only"> of <?= e((string) $next['title']) ?></span></a>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?= section_open(['id' => 'listing', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <nav class="inline-flex w-max rounded-full border border-slate-200 bg-white p-1" aria-label="Upcoming or past events">
      <?php foreach (['upcoming' => ['Upcoming', 'calendar-days'], 'past' => ['Past events', 'history']] as $t => [$label, $ic]): $on = $tab === $t; ?>
        <a href="<?= e(ne_query_url('events', array_merge($state, ['tab' => $t === 'past' ? 'past' : null, 'month' => null])) . '#listing') ?>" class="inline-flex h-10 items-center gap-1.5 rounded-full px-4 text-sm font-semibold transition <?= $on ? 'bg-brand-900 text-white' : 'text-slate-600 hover:text-brand-900' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($ic, 'h-4 w-4') ?><?= e($label) ?><span class="opacity-75">(<?= $tabTotals[$t] ?>)</span></a>
      <?php endforeach; ?>
    </nav>
    <div class="hidden [.js_&]:block"><?= view_mode_toggle('events', ['grid', 'list', 'compact', 'detailed'], view_mode_default('events', 'grid')) ?></div>
  </div>

  <form method="get" action="<?= e(site_url('events')) ?>#listing" role="search" aria-label="Search events" class="mt-5 rounded-[22px] border border-warm-2 bg-white p-4 sm:p-5">
    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end">
      <div>
        <label for="ev-q" class="form-label">Search events</label>
        <div class="relative">
          <?= icon('search', 'pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400') ?>
          <input id="ev-q" type="search" name="q" value="<?= e($q) ?>" maxlength="80" class="form-input !pl-11" placeholder="Event, venue or organiser" autocomplete="off">
        </div>
      </div>
      <div>
        <label for="ev-month" class="form-label">Month</label>
        <select id="ev-month" name="month" class="form-input">
          <option value="">All months</option>
          <?php foreach ($months as $ym => $n): ?>
            <option value="<?= e((string) $ym) ?>"<?= $ym === $month ? ' selected' : '' ?>><?= e(date('F Y', strtotime($ym . '-01'))) ?> (<?= (int) $n ?>)</option>
          <?php endforeach; ?>
          <?php if ($month !== '' && !isset($months[$month])): ?><option value="<?= e($month) ?>" selected><?= e(date('F Y', strtotime($month . '-01'))) ?> (0)</option><?php endif; ?>
        </select>
      </div>
      <div class="flex gap-2">
        <?php if ($tab === 'past'): ?><input type="hidden" name="tab" value="past"><?php endif; ?>
        <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-explore w-full sm:w-auto"><?= icon('search', 'h-4 w-4') ?>Search</button>
      </div>
    </div>
  </form>

  <nav class="pill-row scrollbar-none mt-5" aria-label="Filter events by category">
    <a href="<?= e(ne_query_url('events', array_merge($state, ['category' => null])) . '#listing') ?>" class="chip !px-4 !py-2 !text-sm<?= $category === '' ? ' chip-active' : '' ?>"<?= $category === '' ? ' aria-current="page"' : '' ?>>All<span class="chip-count<?= $category === '' ? ' is-active' : '' ?>"><?= array_sum(array_map('intval', $catCounts)) ?></span></a>
    <?php foreach ($catMeta as $key => [$label, $ic]): if (!isset($catCounts[$key]) && $category !== $key) continue; $on = $category === $key; ?>
      <a href="<?= e(ne_query_url('events', array_merge($state, ['category' => $key])) . '#listing') ?>" class="chip !px-4 !py-2 !text-sm<?= $on ? ' chip-active' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($ic, 'h-4 w-4') ?><?= e($label) ?><span class="chip-count<?= $on ? ' is-active' : '' ?>"><?= (int) ($catCounts[$key] ?? 0) ?></span></a>
    <?php endforeach; ?>
  </nav>

  <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-warm-2 pt-4 text-sm text-slate-600" aria-live="polite">
    <p class="mr-1">
      <?php if ($total): ?>
        Showing <strong class="text-brand-900"><?= $pg['from'] ?>–<?= $pg['to'] ?></strong> of <strong class="text-brand-900"><?= $total ?></strong> <?= $tab === 'past' ? 'past' : 'upcoming' ?> <?= $catInfo ? e(strtolower($catInfo['label'])) . ' ' : '' ?>event<?= $total === 1 ? '' : 's' ?>
      <?php else: ?>
        No matching events
      <?php endif; ?>
    </p>
    <?php
    $remove = [];
    if ($q !== '') {
        $remove[] = ['Search: “' . $q . '”', $urlWith(['q' => null, 'page' => null])];
    }
    if ($month !== '') {
        $remove[] = [date('F Y', strtotime($month . '-01')), $urlWith(['month' => null, 'page' => null])];
    }
    if ($catInfo) {
        $remove[] = [$catInfo['label'], $urlWith(['category' => null, 'page' => null])];
    }
    foreach ($remove as [$label, $href]): ?>
      <a href="<?= e($href) ?>#listing" class="inline-flex items-center gap-1 rounded-full bg-white px-3 py-1 text-xs font-semibold text-brand-900 ring-1 ring-slate-200 hover:ring-brand-900"><?= e($label) ?><?= icon('x', 'h-3.5 w-3.5') ?><span class="sr-only"> — remove filter</span></a>
    <?php endforeach; ?>
    <?php if (count($remove) > 1): ?><a href="<?= e(ne_query_url('events', ['tab' => $tab === 'past' ? 'past' : null])) ?>#listing" class="text-xs font-semibold text-accent-700 underline-offset-2 hover:underline">Clear all</a><?php endif; ?>
  </div>

  <div class="mt-6">
    <?php if ($events): ?>
      <?= view_mode_open('events', ['class' => 'group/vm', 'default' => view_mode_default('events', 'grid')]) ?>
        <?php foreach ($events as $e): ?><?= ne_event_card($e, ['heading' => 'h2']) ?><?php endforeach; ?>
      <?= view_mode_close() ?>
      <?= ne_pagination($pg, $pageUrl, 'Event pages') ?>
    <?php else:
        $other = $tab === 'past' ? 'upcoming' : 'past';
        $acts = '';
        if ($tabTotals[$other] > 0) {
            $acts .= button_link('See ' . $tabTotals[$other] . ' ' . ($other === 'past' ? 'past' : 'upcoming') . ' event' . ($tabTotals[$other] === 1 ? '' : 's'), ne_query_url('events', array_merge($state, ['tab' => $other === 'past' ? 'past' : null, 'month' => null])) . '#listing', 'brochure', ['icon' => '']);
        }
        if ($filtered) {
            $acts .= button_link('Clear filters', ne_query_url('events', ['tab' => $tab === 'past' ? 'past' : null]) . '#listing', 'explore', ['icon' => '']);
        }
        echo $outOfRange
            ? ne_empty('calendar-days', 'This page does not exist', 'There are fewer pages of events than requested.', button_link('Go to page 1', $pageUrl(1), 'explore', ['icon' => '']))
            : ne_empty('calendar-days', $filtered ? 'No events match your filters' : ($tab === 'past' ? 'No past events yet' : 'No upcoming events right now'),
                $filtered ? 'Try another category, month or keyword.' : ($tab === 'past' ? 'Completed events will appear here with their highlights.' : 'New events are announced here and on the notice board — check back soon.'), $acts);
    endif; ?>
  </div>
<?= section_close() ?>

<?php
$links = [];
if (ne_route_exists('academics')) {
    $links[] = feature_tile('calendar-range', 'Academic Calendar', 'Holidays, examinations, vacations and key dates of the ' . (current_session()['name'] ?? 'current') . ' session.', ['url' => '/academics#calendar', 'tint' => 'orange', 'heading' => 'h3']);
}
$links[] = feature_tile('megaphone', 'Notice Board', 'Event announcements, circulars, examination schedules and holiday notices.', ['url' => '/notices?category=event', 'tint' => 'navy', 'heading' => 'h3']);
if (ne_route_exists('campus')) {
    $links[] = feature_tile('trees', 'Campus Life', 'Clubs, sports, cultural activities and facilities that make every day at GIMT memorable.', ['url' => '/campus', 'tint' => 'orange', 'heading' => 'h3']);
}
?>
<?= section_open(['bg' => 'white', 'padding' => 'tight', 'label' => 'Related pages']) ?>
  <?= section_heading('Plan Your Campus Calendar', '', 'Keep up', 'left') ?>
  <div class="grid gap-5 sm:grid-cols-2 <?= count($links) >= 3 ? "lg:grid-cols-3" : "lg:grid-cols-2" ?>" data-stagger="80"><?= implode('', $links) ?></div>
<?= section_close() ?>

<?php
site_footer([
    'cta_title' => 'Experience Campus Life at GIMT',
    'cta_text' => 'Join a campus where learning goes beyond the classroom — ' . (string) setting('admissions_open_text', 'Admissions Open for 2026-27') . '.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
