<?php
/**
 * Site search (/search?q=…&type=…&page=…) — programs, CMS pages (incl. policies), news, events, notices, faculty
 * (public fields only), FAQs and galleries; a type is searched only when its public page exists (cp_search_types()).
 * All results: grouped, top hits per type with counts and "View all" links; one type: paginated list (10 per page).
 * Every word must match (AND); title matches rank first; matches are highlighted. Empty state with suggestions.
 * Always noindex,follow (canonical /search). The header search overlay submits here.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/partials/content.php';

$types = cp_search_types();
$q = trim((string) preg_replace('/\s+/u', ' ', cp_query('q', 100)));
$type = cp_query('type', 20) ?: 'all';
$type = isset($types[$type]) ? $type : 'all';
$pageNo = max(1, (int) cp_query('page', 6));
$words = cp_search_words($q);
$perPage = 10;
$perGroup = 4;

$counts = [];
$groups = [];
$pg = null;
if ($words) {
    foreach (array_keys($types) as $t) {
        if ($type === 'all') {
            $res = cp_search_type($t, $words, $perGroup);
            $counts[$t] = $res['total'];
            if ($res['rows']) {
                $groups[$t] = $res['rows'];
            }
        } elseif ($t !== $type) {
            $counts[$t] = cp_search_type($t, $words, 0)['total'];
        }
    }
    if ($type !== 'all') {
        $total = cp_search_type($type, $words, 0)['total'];
        $pg = paginate($total, $pageNo, $perPage);
        $res = $total ? cp_search_type($type, $words, $perPage, $pg['offset']) : ['rows' => []];
        $counts[$type] = $total;
        if ($res['rows']) {
            $groups[$type] = $res['rows'];
        }
    }
}
$total = array_sum($counts);
$link = function (array $params): string {
    $params = array_filter($params, fn ($v) => $v !== '' && $v !== null && $v !== 'all' && $v !== 1);
    return site_url('search') . ($params ? '?' . http_build_query($params) : '');
};
header('X-Robots-Tag: noindex, follow');
$c = cp_contact();

// Suggestions for an empty result: program names that resemble the query + popular topics.
$didYouMean = [];
if ($words && !$total) {
    foreach (site_programs() as $p) {
        foreach ([(string) $p['name'], (string) ($p['short_name'] ?? '')] as $cand) {
            if ($cand === '') {
                continue;
            }
            similar_text(mb_strtolower($q), mb_strtolower($cand), $pct);
            $lev = levenshtein(mb_strtolower(mb_substr($q, 0, 60)), mb_strtolower(mb_substr($cand, 0, 60)));
            if ($pct >= 55 || ($lev <= 2 && mb_strlen($q) >= 3)) {
                $didYouMean[$cand] = $pct;
            }
        }
    }
    arsort($didYouMean);
    $didYouMean = array_slice(array_keys($didYouMean), 0, 4);
}
$topics = array_values(array_unique(array_merge(
    array_slice(array_values(array_filter(array_map(fn ($p) => (string) ($p['short_name'] ?? ''), site_programs()))), 0, 5),
    ['Admissions', 'Fees', 'Scholarship', 'Hostel', 'Placement', 'Eligibility']
)));

$title = $q !== '' ? 'Search results for “' . $q . '”' : 'Search the website';
site_header([
    'title' => $q !== '' ? 'Search: ' . $q : 'Search',
    'description' => 'Search programs, admissions information, policies, faculty, FAQs and news on the ' . institute_name() . ' website.',
    'active' => 'search',
    'robots' => 'noindex,follow',
    'canonical' => absolute_url('search'),
    'loader' => false,
]);

$form = '<form action="' . e(site_url('search')) . '" method="get" role="search" class="w-full max-w-2xl">'
    . '<label for="search-q" class="sr-only">Search the website</label>'
    . '<div class="flex items-center gap-2 rounded-full border border-warm-2 bg-white p-1.5 pl-5 shadow-card focus-within:ring-2 focus-within:ring-accent-500">'
    . icon('search', 'h-5 w-5 shrink-0 text-slate-400')
    . '<input id="search-q" name="q" type="search" value="' . e($q) . '" maxlength="100" placeholder="Search programs, admissions, faculty, policies…" class="h-11 w-0 min-w-0 flex-1 border-0 bg-transparent text-base text-brand-900 placeholder:text-slate-400 focus:outline-none focus:ring-0"' . ($q === '' ? ' autofocus' : '') . '>'
    . ($type !== 'all' ? '<input type="hidden" name="type" value="' . e($type) . '">' : '')
    . '<button type="submit" class="btn btn-explore shrink-0">Search</button></div></form>';
page_hero($title, '', ['Search'], '', $words ? number_format($total) . ' ' . ($total === 1 ? 'result' : 'results') . ' across the website.' : 'Find programs, admission details, policies, faculty, FAQs and more.', [],
    ['actions' => $form, 'highlight' => false, 'size' => 'md']);
?>

<?= section_open(['id' => 'results', 'padding' => 'tight', 'class' => '!pb-20']) ?>
  <?php if ($q !== '' && !$words): ?>
    <p class="mb-6 rounded-2xl bg-amber-50 px-5 py-4 text-sm text-amber-950 ring-1 ring-amber-200" role="status">Please type at least two letters to search.</p>
  <?php endif; ?>

  <?php if ($words): ?>
    <nav class="pill-row scrollbar-none mb-8" aria-label="Filter results by type">
      <a href="<?= e($link(['q' => $q])) ?>" class="chip<?= $type === 'all' ? ' chip-active' : '' ?>"<?= $type === 'all' ? ' aria-current="page"' : '' ?>>All results <span class="chip-count"><?= number_format($total) ?></span></a>
      <?php foreach ($types as $t => $meta): if (empty($counts[$t]) && $t !== $type) continue; ?>
        <a href="<?= e($link(['q' => $q, 'type' => $t])) ?>" class="chip<?= $type === $t ? ' chip-active' : '' ?>"<?= $type === $t ? ' aria-current="page"' : '' ?>><?= icon($meta['icon'], 'h-4 w-4') ?><?= e($meta['label']) ?> <span class="chip-count"><?= number_format((int) ($counts[$t] ?? 0)) ?></span></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($groups): ?>
      <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
        <div class="min-w-0 space-y-12 lg:col-span-8">
          <?php foreach ($groups as $t => $rows): ?>
            <section aria-labelledby="res-<?= e($t) ?>">
              <div class="mb-4 flex items-end justify-between gap-4">
                <h2 id="res-<?= e($t) ?>" class="flex items-center gap-3 font-display text-xl font-extrabold text-brand-900">
                  <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-accent-50 text-accent-600"><?= icon($types[$t]['icon'], 'h-5 w-5') ?></span><?= e($types[$t]['label']) ?>
                  <span class="text-sm font-semibold text-slate-400">(<?= number_format((int) $counts[$t]) ?>)</span>
                </h2>
                <?php if ($type === 'all' && $counts[$t] > count($rows)): ?>
                  <a href="<?= e($link(['q' => $q, 'type' => $t])) ?>" class="link-arrow shrink-0 text-sm">View all <?= number_format((int) $counts[$t]) ?><span class="sr-only"> <?= e(strtolower($types[$t]['label'])) ?> results</span><?= icon('arrow-right', 'h-4 w-4') ?></a>
                <?php endif; ?>
              </div>
              <ol class="space-y-3"<?= $type !== 'all' && $pg ? ' start="' . (int) $pg['from'] . '"' : '' ?>>
                <?php foreach ($rows as $r): ?>
                  <li class="group relative flex gap-4 rounded-[20px] border border-warm-2 bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">
                    <span class="hidden h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-warm text-brand-800 transition group-hover:bg-brand-900 group-hover:text-white sm:inline-flex"><?= icon($r['icon'], 'h-5 w-5') ?></span>
                    <div class="min-w-0 flex-1">
                      <h3 class="font-display text-base font-bold leading-snug text-brand-900 sm:text-lg [&_mark]:bg-accent-100 [&_mark]:text-brand-900"><a href="<?= e($r['url']) ?>" class="stretched-link"><?= cp_highlight($r['title'], $words) ?></a></h3>
                      <?php if ($r['meta'] !== ''): ?><p class="mt-0.5 text-xs font-semibold uppercase tracking-wide text-accent-700"><?= e($r['meta']) ?></p><?php endif; ?>
                      <?php if ($r['snippet'] !== ''): ?><p class="mt-2 text-sm leading-relaxed text-slate-600 [&_mark]:bg-accent-100 [&_mark]:text-brand-900"><?= cp_highlight($r['snippet'], $words) ?></p><?php endif; ?>
                      <p class="mt-2 truncate text-xs text-slate-400"><?= e(rawurldecode((string) preg_replace('#^https?://[^/]+#', '', (string) $r['url']))) ?></p>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          <?php endforeach; ?>
          <?php if ($pg): ?>
            <p class="text-center text-sm text-slate-500">Showing <?= (int) $pg['from'] ?>–<?= (int) $pg['to'] ?> of <?= number_format((int) $pg['total']) ?></p>
            <?= cp_pagination($pg, fn ($n) => $link(['q' => $q, 'type' => $type, 'page' => $n]), 'Search results pages') ?>
          <?php endif; ?>
        </div>
        <aside class="lg:col-span-4" aria-label="More help">
          <div class="space-y-5 lg:sticky lg:top-28">
            <?= cp_help_card($c) ?>
            <?php if (cp_route_ok('/faq')): ?>
              <a href="<?= e(site_url('faq?q=' . rawurlencode($q))) ?>#faq-list" class="group flex items-center gap-4 rounded-[22px] border border-warm-2 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-card">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-accent-50 text-accent-600"><?= icon('circle-help', 'h-5 w-5') ?></span>
                <span class="min-w-0 flex-1"><span class="block font-semibold text-brand-900">Search the FAQs</span><span class="block text-sm text-slate-500">Quick answers about admissions, fees and campus life</span></span><?= icon('arrow-right', 'h-4 w-4 text-slate-400 transition group-hover:translate-x-0.5') ?></a>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    <?php else: ?>
      <div class="mx-auto max-w-3xl rounded-[24px] border border-dashed border-warm-2 bg-warm/60 p-8 text-center sm:p-10" role="status">
        <span class="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-full bg-white text-accent-600 shadow-sm"><?= icon('search', 'h-7 w-7') ?></span>
        <h2 class="mt-4 font-display text-xl font-extrabold text-brand-900">No results for “<?= e($q) ?>”</h2>
        <p class="mt-2 text-slate-600">Check the spelling, try fewer or more general words, or pick a suggestion below.</p>
        <?php if ($didYouMean): ?>
          <p class="mt-5 text-sm font-semibold text-brand-900">Did you mean:</p>
          <div class="mt-2 flex flex-wrap justify-center gap-2">
            <?php foreach ($didYouMean as $s): ?><a href="<?= e($link(['q' => $s])) ?>" class="chip"><?= e($s) ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="mt-6 flex flex-wrap justify-center gap-3">
          <?= button_link('Explore Courses', '/programs', 'explore') ?>
          <a href="<?= e(site_url('contact')) ?>#message" class="btn btn-callback" data-modal-open="#enquiry-modal"><?= icon('phone', 'h-4 w-4') ?>Request a Callback</a>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$words || !$total): ?>
    <div class="mt-12 grid gap-10 lg:grid-cols-12">
      <div class="lg:col-span-5">
        <h2 class="font-display text-xl font-extrabold text-brand-900">Popular searches</h2>
        <div class="mt-4 flex flex-wrap gap-2">
          <?php foreach ($topics as $t): ?><a href="<?= e($link(['q' => $t])) ?>" class="chip"><?= icon('search', 'h-3.5 w-3.5') ?><?= e($t) ?></a><?php endforeach; ?>
        </div>
      </div>
      <div class="lg:col-span-7">
        <h2 class="font-display text-xl font-extrabold text-brand-900">Popular pages</h2>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
          <?php foreach (array_slice(cp_popular_links(), 0, 6) as [$label, $url, $ic, $text]): ?>
            <li><a href="<?= e(site_url(ltrim($url, '/'))) ?>" class="group flex h-full items-start gap-3 rounded-[18px] border border-warm-2 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">
              <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-800 transition group-hover:bg-brand-900 group-hover:text-white"><?= icon($ic, 'h-5 w-5') ?></span>
              <span class="min-w-0"><span class="block font-semibold text-brand-900"><?= e($label) ?></span><span class="block text-sm text-slate-500"><?= e($text) ?></span></span></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>
<?= section_close() ?>
<?php site_footer();
