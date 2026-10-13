<?php
/**
 * Faculty directory (/faculty) — public profiles of faculty members (show_on_website = 1): department filter pills,
 * search, progressive "load more", profile modal with a short bio. Only public-safe fields are rendered (name,
 * designation, department, qualification, specialization, experience, bio, research interests, publications, photo,
 * LinkedIn) — never salary, phone, personal email, bank details or address.
 * Deep links: ?department=cse&q=analytics (also the no-JS fallback via a GET form).
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$faculty = sa_faculty();
$counts = sa_counts();
$depts = [];
foreach ($faculty as $f) {
    if ($f['department_code']) {
        $code = strtolower($f['department_code']);
        $depts[$code] ??= ['code' => $code, 'name' => $f['department_name'], 'n' => 0];
        $depts[$code]['n']++;
    }
}
uasort($depts, fn ($a, $b) => strcmp($a['name'], $b['name']));
$dept = strtolower(trim((string) ($_GET['department'] ?? '')));
$dept = isset($depts[$dept]) ? $dept : 'all';
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($q))));
$perPage = 12;

$short = fn (string $name) => trim(preg_replace('/^(Department of|School of)\s+/i', '', $name));
$searchText = fn (array $f) => mb_strtolower(implode(' ', [sa_person_name($f), $f['designation'], $f['department_name'], $f['qualification'], $f['specialization'], $f['research_interests']]));
$matches = function (array $f) use ($dept, $words, $searchText): bool {
    if ($dept !== 'all' && strtolower((string) $f['department_code']) !== $dept) {
        return false;
    }
    $t = $searchText($f);
    foreach ($words as $w) {
        if (!str_contains($t, $w)) {
            return false;
        }
    }
    return true;
};
$matching = count(array_filter($faculty, $matches));
$avgExp = (int) round($counts['experience']);

$heroImg = 'assets/images/site/students-atrium.jpg';
site_header([
    'title' => 'Our Faculty — Professors & Mentors',
    'description' => 'Meet the ' . count($faculty) . ' faculty members of GIMT Greater Noida — professors, heads of department and mentors across management, technology, commerce, computer applications and sciences.',
    'active' => 'faculty',
    'seo' => ['route', '/faculty'],
    'image' => $heroImg,
    'preload' => [asset($heroImg)],
    'schema' => array_values(array_filter([
        sa_breadcrumb_ld([['Home', ''], ['About', 'about'], ['Faculty', null]]),
        sa_seo_schema_has('ItemList', 'route', null, '/faculty') ? null : ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Faculty of ' . institute_name(), 'numberOfItems' => count($faculty),
         'itemListElement' => array_map(fn ($f, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter(['@type' => 'Person', 'name' => sa_person_name($f), 'jobTitle' => $f['designation'],
             'worksFor' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name()], 'knowsAbout' => $f['specialization'] ?: null, 'sameAs' => $f['linkedin_url'] ?: null])], array_slice($faculty, 0, 60), array_keys(array_slice($faculty, 0, 60)))],
    ])),
]);

page_hero('Our Faculty', 'Mentors Who Inspire Excellence', ['About' => '/about', 'Faculty'], $heroImg,
    'Experienced academics, researchers and industry practitioners who teach, mentor and guide every GIMT student towards their goals.',
    [
        ['icon' => 'users', 'label' => count($faculty) . ' Faculty Members'],
        ['icon' => 'graduation-cap', 'label' => $counts['phd'] . ' PhD Holders'],
        ['icon' => 'briefcase', 'label' => $avgExp . '+ Yrs Avg. Experience'],
        ['icon' => 'book-open', 'label' => sa_round_label($counts['publications']) . ' Publications'],
    ],
    ['image_alt' => 'GIMT students and faculty on campus']);
?>

<?= section_open(['id' => 'directory', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Faculty Directory', 'Filter by department or search by name, subject or research area. Select a profile to read more.', 'Meet the Team') ?>

  <?php if ($faculty): ?>
    <div class="mb-8 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
      <nav class="pill-row scrollbar-none" aria-label="Filter faculty by department">
        <a href="<?= e(site_url('faculty') . ($q !== '' ? '?q=' . rawurlencode($q) : '') . '#directory') ?>" class="chip<?= $dept === 'all' ? ' chip-active' : '' ?>" data-fac-dept="all"<?= $dept === 'all' ? ' aria-current="true"' : '' ?>><?= icon('layout-grid', 'h-4 w-4') ?>All<span class="chip-count<?= $dept === 'all' ? ' is-active' : '' ?>"><?= count($faculty) ?></span></a>
        <?php foreach ($depts as $d): $on = $dept === $d['code']; ?>
          <a href="<?= e(site_url('faculty?department=' . $d['code']) . ($q !== '' ? '&q=' . rawurlencode($q) : '') . '#directory') ?>" class="chip<?= $on ? ' chip-active' : '' ?>" data-fac-dept="<?= e($d['code']) ?>" title="<?= e($d['name']) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= e($short($d['name'])) ?><span class="chip-count<?= $on ? ' is-active' : '' ?>"><?= (int) $d['n'] ?></span></a>
        <?php endforeach; ?>
      </nav>
      <form action="<?= e(site_url('faculty')) ?>#directory" method="get" role="search" class="flex shrink-0 items-center gap-2" data-fac-form>
        <?php if ($dept !== 'all'): ?><input type="hidden" name="department" value="<?= e($dept) ?>" data-fac-dept-input><?php endif; ?>
        <label class="search-field">
          <?= icon('search', 'h-4 w-4 shrink-0 text-slate-400') ?>
          <span class="sr-only">Search faculty</span>
          <input type="search" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Search name, subject, research…" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0" data-fac-search autocomplete="off">
        </label>
        <button type="submit" class="btn btn-primary btn-sm [.js_&]:hidden">Search</button>
      </form>
    </div>

    <p class="mb-5 text-sm text-slate-500" aria-live="polite" data-fac-status>Showing <strong class="text-brand-900" data-fac-count><?= $matching ?></strong> of <?= count($faculty) ?> faculty members</p>

    <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-fac-grid>
      <?php $shown = 0; foreach ($faculty as $f): $ok = $matches($f); if ($ok) { $shown++; } ?>
        <li class="<?= !$ok ? 'hidden' : ($shown > $perPage ? '[.js_&]:hidden' : '') ?>" data-fac-item data-dept="<?= e(strtolower((string) $f['department_code'])) ?>" data-search="<?= e($searchText($f)) ?>">
          <?= sa_faculty_card($f, ['modal' => true]) ?>
        </li>
      <?php endforeach; ?>
    </ul>

    <div class="<?= $matching ? 'hidden' : '' ?> empty-state mt-2 rounded-2xl border border-dashed border-slate-200 bg-white" data-fac-empty>
      <span class="empty-state-icon"><?= icon('search', 'h-6 w-6') ?></span>
      <p class="mt-3 font-semibold text-brand-900">No faculty members match your search.</p>
      <p class="mt-1 text-sm text-slate-500">Try another name or subject, or browse all departments.</p>
      <a href="<?= e(site_url('faculty')) ?>#directory" class="btn btn-secondary btn-sm mt-4" data-fac-reset>Show all faculty</a>
    </div>

    <div class="mt-10 text-center">
      <button type="button" class="btn btn-outline hidden" data-fac-more><?= icon('plus', 'h-4 w-4') ?><span data-fac-more-label>Load more faculty</span></button>
    </div>
  <?php else: ?>
    <div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white">
      <span class="empty-state-icon"><?= icon('users', 'h-6 w-6') ?></span>
      <p class="mt-3 font-semibold text-brand-900">Faculty profiles are being updated.</p>
      <p class="mt-1 text-sm text-slate-500">Please check back soon or explore our academic departments.</p>
      <a href="<?= e(site_url('academics')) ?>#departments" class="btn btn-secondary btn-sm mt-4">View Departments</a>
    </div>
  <?php endif; ?>
<?= section_close() ?>

<!-- Why our faculty -->
<?= section_open(['id' => 'expertise']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Teaching Excellence</p>
      <h2 class="section-title">Academics With Industry Insight</h2>
      <p class="section-lead">Our faculty combine doctoral research with consulting and corporate experience, so classroom learning stays connected to how organisations really work.</p>
      <?= check_list(['Faculty development programmes every semester', 'Mentor assigned to every student from day one', 'Guest faculty and practitioners from partner companies', 'Research guidance for student projects and publications'], 'mt-6') ?>
      <div class="mt-7 flex flex-wrap gap-3"><?= button_link('Explore Departments', '/academics#departments', 'navy') ?><?= button_link('Research at GIMT', '/academics#research', 'outline', ['icon' => '']) ?></div>
    </div>
    <div class="lg:col-span-7"<?= reveal_attr('fade-up', 100) ?>>
      <?= stat_counter([
          ['value' => count($faculty), 'label' => 'Faculty members', 'icon' => 'users'],
          ['value' => $counts['phd'], 'label' => 'Doctorate holders', 'icon' => 'graduation-cap'],
          ['value' => $avgExp, 'suffix' => '+ yrs', 'label' => 'Average experience', 'icon' => 'briefcase'],
          ['value' => sa_round($counts['publications'])[0], 'suffix' => sa_round($counts['publications'])[1], 'label' => 'Research publications', 'icon' => 'book-open'],
      ], ['variant' => 'cards', 'gridClass' => 'grid-cols-1 sm:grid-cols-2']) ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Profile modal (vanilla data-modal; body filled from the card's <template>) -->
<div id="faculty-profile" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="faculty-profile-title" data-modal>
  <div class="modal-scrim" data-modal-close></div>
  <div class="modal-card !max-w-2xl sm:!p-8">
    <button type="button" class="absolute right-3 top-3 inline-flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-slate-600 transition hover:rotate-90 hover:bg-brand-900 hover:text-white" data-modal-close aria-label="Close profile"><?= icon('x', 'h-4 w-4') ?></button>
    <div id="faculty-profile-body"><h2 id="faculty-profile-title" class="sr-only">Faculty profile</h2></div>
  </div>
</div>

<script>
/* Faculty directory: department pills + search + load more + profile modal content (progressive enhancement). */
(function () {
  var grid = document.querySelector('[data-fac-grid]');
  if (!grid) return;
  var items = Array.prototype.slice.call(grid.querySelectorAll('[data-fac-item]'));
  var pills = Array.prototype.slice.call(document.querySelectorAll('[data-fac-dept]'));
  var input = document.querySelector('[data-fac-search]');
  var form = document.querySelector('[data-fac-form]');
  var more = document.querySelector('[data-fac-more]');
  var moreLabel = document.querySelector('[data-fac-more-label]');
  var countEl = document.querySelector('[data-fac-count]');
  var empty = document.querySelector('[data-fac-empty]');
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches || navigator.webdriver;
  var PAGE = <?= (int) $perPage ?>;
  var state = { dept: <?= js_json($dept) ?>, q: input ? input.value : '', limit: PAGE };
  var timer;

  function words(s) { return s.toLowerCase().split(/\s+/).filter(Boolean); }
  function apply(animate) {
    var w = words(state.q), shown = 0, total = 0;
    items.forEach(function (el) {
      el.classList.remove('[.js_&]:hidden');
      var ok = (state.dept === 'all' || el.getAttribute('data-dept') === state.dept) &&
        w.every(function (x) { return el.getAttribute('data-search').indexOf(x) !== -1; });
      if (ok) total++;
      var visible = ok && total <= state.limit;
      var wasHidden = el.classList.contains('hidden');
      el.classList.toggle('hidden', !visible);
      if (visible && wasHidden && animate && !reduce) {
        el.style.setProperty('--filter-delay', Math.min(shown, 8) * 45 + 'ms');
        el.classList.remove('filter-in'); void el.offsetWidth; el.classList.add('filter-in');
      }
      if (visible) shown++;
    });
    if (countEl) countEl.textContent = String(total);
    if (empty) empty.classList.toggle('hidden', total > 0);
    if (more) {
      var left = total - shown;
      more.classList.toggle('hidden', left <= 0);
      if (moreLabel) moreLabel.textContent = 'Load more faculty (' + left + ' more)';
    }
  }
  function syncUrl() {
    try {
      var url = new URL(window.location.href);
      if (state.dept === 'all') url.searchParams.delete('department'); else url.searchParams.set('department', state.dept);
      if (state.q.trim() === '') url.searchParams.delete('q'); else url.searchParams.set('q', state.q.trim());
      window.history.replaceState(null, '', url);
    } catch (e) {}
  }
  pills.forEach(function (p) {
    p.addEventListener('click', function (e) {
      e.preventDefault();
      state.dept = p.getAttribute('data-fac-dept');
      state.limit = PAGE;
      pills.forEach(function (o) {
        var on = o === p;
        o.classList.toggle('chip-active', on);
        var c = o.querySelector('.chip-count'); if (c) c.classList.toggle('is-active', on);
        if (on) o.setAttribute('aria-current', 'true'); else o.removeAttribute('aria-current');
      });
      apply(true); syncUrl();
    });
  });
  if (input) {
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { state.q = input.value; state.limit = PAGE; apply(true); syncUrl(); }, 160);
    });
  }
  if (form) form.addEventListener('submit', function (e) { e.preventDefault(); state.q = input ? input.value : ''; state.limit = PAGE; apply(true); syncUrl(); });
  if (more) more.addEventListener('click', function () { state.limit += PAGE; apply(true); });
  var reset = document.querySelector('[data-fac-reset]');
  if (reset) reset.addEventListener('click', function (e) {
    e.preventDefault();
    state.dept = 'all'; state.q = ''; state.limit = PAGE;
    if (input) input.value = '';
    pills.forEach(function (o) { var on = o.getAttribute('data-fac-dept') === 'all'; o.classList.toggle('chip-active', on); var c = o.querySelector('.chip-count'); if (c) c.classList.toggle('is-active', on); if (on) o.setAttribute('aria-current', 'true'); else o.removeAttribute('aria-current'); });
    apply(true); syncUrl();
  });
  // profile modal: copy the card's <template> into the dialog before the vanilla modal opens it
  var body = document.getElementById('faculty-profile-body');
  grid.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-faculty-open]') : null;
    if (!btn || !body) return;
    var tpl = document.getElementById('faculty-tpl-' + btn.getAttribute('data-faculty-open'));
    if (tpl) { body.innerHTML = ''; body.appendChild(tpl.content.cloneNode(true)); }
  }, true);
  apply(false);
})();
</script>

<?php site_footer([
    'cta_title' => 'Learn From Mentors Who Care',
    'cta_text' => 'Join a campus where faculty know every student by name and guide them all the way to their career.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Programs', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
