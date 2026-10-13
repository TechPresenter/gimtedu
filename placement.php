<?php
/**
 * Placement Showcase (/placement) — verified placement record of a season (placement %, students placed, highest /
 * average / median CTC, hiring companies) with a season selector (?season=2025-26), department-wise placement bars,
 * package distribution, recent offers (first name + initial · program · company · CTC), top-recruiter wordmark wall,
 * training & mock-test highlights, the placement process, success stories and the "Hire from GIMT" recruiter enquiry.
 *
 * Data: placement_offers / placement_drives / placement_applications (via placement_dashboard(), cached — see
 * includes/partials/public_career.php), companies (is_recruiter), placement_trainings (+ participants),
 * alumni_success_stories + testimonials, CMS route page "placement" (subtitle, excerpt, banner). Nothing is invented:
 * a stat without data is hidden and every section has an empty state.
 * Recruiter enquiries post to /api/public/contact as enquiry_type=placement (Admin › Contact Messages, "Placement").
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/public_career.php';

$cms = pc_cms_page('placement');
$seasons = pc_placement_seasons();
$default = pc_default_season($seasons);
$season = $default;
$requested = trim((string) ($_GET['season'] ?? ''));
foreach ($seasons as $s) {
    if ($requested !== '' && $s['name'] === $requested) {
        $season = $s;
    }
}
$filtered = $season && $default && $season['id'] !== $default['id'];
$stats = $season ? pc_placement_stats($season) : [];
$offers = $season ? pc_recent_offers($season, 8) : [];
$recruiters = pc_recruiters(30);
$training = pc_training_overview();
$stories = pc_success_stories(8);
$programCount = count(site_programs());
$deptCount = count(site_program_departments());
$seasonName = $season['name'] ?? '';
[$heroImage, $heroInset] = pc_hero_images(($cms['banner_image'] ?? '') ?: 'assets/images/site/placement-student.jpg', 'assets/images/site/students-laptop.jpg');
$heroText = ($cms['excerpt'] ?? '') ?: 'Training, internships and campus placements with recruiters across IT, consulting, banking, analytics and FMCG — coordinated by the GIMT Training & Placement Cell.';
$seasonUrl = fn (array $s) => site_url('placement' . ($default && $s['id'] === $default['id'] ? '' : '?season=' . rawurlencode($s['name']))) . '#record';

// Hero chips: data-backed only.
$chips = [];
if ($recruiters['total']) {
    $chips[] = ['icon' => 'building-2', 'label' => $recruiters['total'] . ' recruiting companies'];
}
if (!empty($stats['offers'])) {
    $chips[] = ['icon' => 'file-check', 'label' => $stats['offers'] . ' offers in ' . $seasonName];
}
if ($training['completed']) {
    $chips[] = ['icon' => 'presentation', 'label' => $training['completed'] . ' training sessions this year'];
}
if (($training['by_type']['mock_test'] ?? 0) + ($training['by_type']['mock_interview'] ?? 0) > 0) {
    $chips[] = ['icon' => 'clipboard-check', 'label' => 'Mock tests & interviews'];
}

$header = [
    'title' => 'Placements ' . ($seasonName !== '' ? $seasonName . ' ' : '') . '— Statistics, Recruiters & Training',
    'description' => 'GIMT placement record' . ($seasonName !== '' ? ' for ' . $seasonName : '') . ': placement rate, highest, average and median packages, department-wise results, top recruiters, training and mock tests, and how to hire from GIMT.',
    'active' => 'placement',
    'image' => $heroImage,
    'preload' => [site_image($heroImage)],
    'schema' => [pc_breadcrumb_ld([['Home', ''], ['Placements', 'placement']])],
];
if ($filtered) {
    // A non-default season is a filtered view: own title, not indexed, links followed.
    $header += ['robots' => 'noindex,follow', 'canonical' => absolute_url('placement?season=' . rawurlencode($seasonName))];
} else {
    $header += ['seo' => ['route', '/placement'], 'canonical' => absolute_url('placement')];
}
site_header($header);

page_hero('Placements at GIMT', ($cms['subtitle'] ?? '') ?: 'Building Careers, Not Just Résumés', ['Placements'], $heroImage, $heroText, $chips, [
    'eyebrow' => 'Training & Placement Cell', 'eyebrow_icon' => 'briefcase-business', 'size' => 'lg', 'highlight' => 'Placements',
    'image_alt' => 'GIMT students preparing together for placement season', 'image2' => $heroInset ?? null,
    'badge' => !empty($stats['highest']) ? ['icon' => 'trophy', 'text' => 'Highest package ' . pc_lpa($stats['highest']) . ' · ' . $seasonName] : false,
    'actions' => button_link('Apply for Admission', '/apply', 'apply', ['size' => 'lg', 'shine' => true, 'magnetic' => true])
        . button_link('Hire from GIMT', '#hire', 'explore', ['size' => 'lg', 'icon' => 'handshake']),
]);
?>

<!-- Placement record of the selected season -->
<section id="record" class="relative z-10 -mt-8 scroll-mt-24 sm:-mt-12" aria-labelledby="record-title">
  <div class="container-site">
    <div class="relative isolate overflow-hidden rounded-3xl bg-brand-900 text-white shadow-pop"<?= reveal_attr('fade-up') ?>>
      <div class="pointer-events-none absolute inset-0 -z-10 [background-image:radial-gradient(55%_80%_at_100%_0%,rgba(240,90,40,.28),transparent_60%),radial-gradient(50%_70%_at_0%_100%,rgba(31,74,148,.6),transparent_65%)]" aria-hidden="true"></div>
      <div class="pattern-dots-light pattern-fade absolute inset-0 -z-10 opacity-50" aria-hidden="true"></div>
      <div class="p-6 sm:p-8 lg:p-10">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-widest text-accent-300 ring-1 ring-white/15">
              <?= icon('badge-check', 'h-3.5 w-3.5') ?>Verified from placement records
            </p>
            <h2 id="record-title" class="mt-3 font-display text-[clamp(1.6rem,1.2rem+1.4vw,2.3rem)] font-extrabold leading-tight text-white">
              Placement Record<?= $season ? ' <span class="whitespace-nowrap">' . e($seasonName) . '</span>' : '' ?>
            </h2>
            <?php if ($season): ?>
              <p class="mt-1.5 text-sm text-white/70"><?= $season['completed'] ? 'Season completed · ' . e(date('M Y', strtotime($season['start_date']))) . ' – ' . e(date('M Y', strtotime($season['end_date']))) : 'Season in progress — updated as new offers are recorded' ?></p>
            <?php endif; ?>
          </div>
          <?php if (count($seasons) > 1): ?>
            <nav aria-label="Placement season">
              <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-white/60">Season</p>
              <ul class="flex flex-wrap gap-2">
                <?php foreach ($seasons as $s): $on = $season && $s['id'] === $season['id']; ?>
                  <li><a href="<?= e($seasonUrl($s)) ?>" class="inline-flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-semibold ring-1 transition duration-200 focus-visible:ring-2 focus-visible:ring-accent-400 focus-visible:ring-offset-2 focus-visible:ring-offset-brand-900 <?= $on ? 'bg-white text-brand-900 ring-white' : 'text-white ring-white/25 hover:bg-white/10 hover:ring-white/50' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
                    <?= e($s['name']) ?><?= $s['completed'] ? '' : '<span class="text-[11px] font-medium opacity-75">(in progress)</span>' ?>
                  </a></li>
                <?php endforeach; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>

        <?php if ($stats && $stats['offers'] > 0): ?>
          <?php
          // Six tiles in one row from xl: a slightly smaller number keeps "₹25.75 LPA" inside its column at 1280 px.
          $tileCount = 2 + (int) ($stats['percent'] !== null) + (int) ($stats['highest'] !== null) + (int) ($stats['average'] !== null) + (int) ($stats['median'] !== null) - (int) !$stats['hiring'];
          $base = ['dark' => true] + ($tileCount >= 6 ? ['size' => 'text-[clamp(1.75rem,1.3rem+1.4vw,2.5rem)] xl:text-[1.75rem]'] : []);
          $tiles = [];
          if ($stats['percent'] !== null) {
              $tiles[] = pc_stat($stats['percent'], 'Placement rate', $base + ['suffix' => '%', 'decimals' => fmod($stats['percent'], 1.0) ? 1 : 0, 'note' => $stats['placed'] . ' of ' . $stats['eligible'] . ' eligible students']);
          }
          $tiles[] = pc_stat($stats['placed'], 'Students placed', $base + ['note' => $stats['offers'] . ' offer' . ($stats['offers'] === 1 ? '' : 's') . ' in total']);
          if ($stats['highest'] !== null) {
              $tiles[] = pc_stat($stats['highest'], 'Highest package', $base + ['prefix' => '₹', 'unit' => 'LPA', 'note' => 'Annual CTC']);
          }
          if ($stats['average'] !== null) {
              $tiles[] = pc_stat($stats['average'], 'Average package', $base + ['prefix' => '₹', 'unit' => 'LPA', 'note' => 'Across all offers']);
          }
          if ($stats['median'] !== null) {
              $tiles[] = pc_stat($stats['median'], 'Median package', $base + ['prefix' => '₹', 'unit' => 'LPA', 'note' => 'Half of offers at or above']);
          }
          if ($stats['hiring']) {
              $tiles[] = pc_stat($stats['hiring'], 'Hiring companies', $base + ['note' => $stats['visited'] ? $stats['visited'] . ' visited for drives' : '']);
          }
          ?>
          <ul class="mt-8 grid grid-cols-2 gap-x-6 gap-y-7 border-t border-white/10 pt-8 md:grid-cols-3 <?= count($tiles) >= 6 ? 'xl:grid-cols-6' : (count($tiles) === 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-4') ?>" data-stagger="70">
            <?php foreach ($tiles as $t): ?><li class="min-w-0 xl:border-l xl:border-white/10 xl:pl-6 xl:first:border-l-0 xl:first:pl-0"><?= $t ?></li><?php endforeach; ?>
          </ul>
          <p class="mt-7 flex items-start gap-2 text-xs leading-relaxed text-white/60"><?= icon('info', 'mt-0.5 h-3.5 w-3.5 shrink-0') ?>
            <span>Placement rate = students with at least one valid offer (offered, accepted or joined) ÷ students eligible for at least one campus drive of the season. Packages are annual CTC in lakh per annum (LPA) of valid offers made between <?= e(format_date($season['start_date'], 'd M Y')) ?> and <?= e(format_date($season['end_date'], 'd M Y')) ?>.</span>
          </p>
        <?php else: ?>
          <div class="mt-8 rounded-2xl border border-white/15 bg-white/5 p-6 text-center">
            <p class="font-display text-lg font-bold text-white">Placement statistics will appear here once offers are recorded</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-white/70">The Training &amp; Placement Cell publishes verified numbers only. Recruiters can already partner with us for the coming season.</p>
            <div class="mt-5 flex flex-wrap justify-center gap-3"><?= button_link('Hire from GIMT', '#hire', 'white', ['icon' => 'arrow-right']) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?= pc_page_nav(array_filter([
    'analytics' => $stats && $stats['offers'] > 0 ? 'Department-wise' : null,
    'offers' => $offers ? 'Recent Offers' : null,
    'recruiters' => 'Recruiters',
    'training' => 'Training & Mock Tests',
    'process' => 'Placement Process',
    'stories' => $stories ? 'Success Stories' : null,
    'hire' => 'Hire from GIMT',
])) ?>

<?php if ($stats && $stats['offers'] > 0): ?>
<!-- Department-wise placement + package distribution -->
<?= section_open(['id' => 'analytics', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Results by Department and Package', 'How the ' . $seasonName . ' season went for each department, and how offers spread across salary bands.', 'Placement Analytics', 'left', '', ['highlight' => 'Package']) ?>
  <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
    <article class="card p-6 sm:p-7 lg:col-span-7"<?= reveal_attr('fade-up') ?>>
      <header class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="font-display text-lg font-extrabold text-brand-900">Department-wise placement</h3>
        <p class="text-xs font-medium text-slate-500">Share of eligible students placed · <?= e($seasonName) ?></p>
      </header>
      <?php if ($stats['departments']): ?>
        <ul class="mt-6 space-y-5">
          <?php foreach ($stats['departments'] as $d): ?>
            <li>
              <div class="flex items-baseline justify-between gap-3">
                <span class="min-w-0 text-[15px] font-semibold leading-snug text-brand-900"><?= e($d['name']) ?></span>
                <span class="shrink-0 font-display text-lg font-extrabold text-brand-900"><?= e(pc_num($d['percent'], 1)) ?>%</span>
              </div>
              <span class="progress-track mt-2 !h-2.5" aria-hidden="true"><span class="progress-fill" style="--progress:<?= e((string) round(min(100, $d['percent']) / 100, 4)) ?>"></span></span>
              <p class="mt-1.5 text-[12.5px] text-slate-500">
                <?= (int) $d['placed'] ?> of <?= (int) $d['eligible'] ?> eligible placed<?= $d['average'] !== null ? ' · Average ' . e(pc_lpa($d['average'])) : '' ?><?= $d['highest'] !== null ? ' · Highest ' . e(pc_lpa($d['highest'])) : '' ?>
              </p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="mt-6 rounded-2xl bg-warm px-4 py-6 text-center text-sm text-slate-600">Department-wise results are calculated once eligible students are registered for this season's drives.</p>
      <?php endif; ?>
    </article>

    <?php $maxBand = max(1, ...array_map(fn ($b) => $b['count'], $stats['distribution'] ?: [['count' => 1]])); ?>
    <article class="card flex flex-col p-6 sm:p-7 lg:col-span-5"<?= reveal_attr('fade-up', 120) ?>>
      <header class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 class="font-display text-lg font-extrabold text-brand-900">Package distribution</h3>
        <p class="text-xs font-medium text-slate-500"><?= (int) $stats['offers'] ?> offers by annual CTC</p>
      </header>
      <ul class="mt-auto grid grid-cols-6 items-end gap-2 pt-6 sm:gap-3">
        <?php foreach ($stats['distribution'] as $b): $pct = round($b['count'] / $maxBand * 100, 2); ?>
          <li class="flex flex-col items-center gap-2 text-center">
            <span class="relative block h-[200px] w-full max-w-[56px] rounded-t-xl bg-warm" aria-hidden="true">
              <span class="absolute inset-x-0 bottom-0 rounded-t-xl bg-gradient-to-t from-brand-900 to-accent-500" style="height:<?= e((string) $pct) ?>%"></span>
              <span class="absolute inset-x-0 text-center font-display text-base font-extrabold text-brand-900" style="bottom:calc(<?= e((string) $pct) ?>% + 6px)"><?= (int) $b['count'] ?></span>
            </span>
            <span class="text-[11px] font-semibold leading-tight text-slate-600 sm:text-xs"><span class="sr-only"><?= (int) $b['count'] ?> offers at </span><?= e($b['label']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($stats['median'] !== null): ?>
        <p class="mt-5 rounded-xl bg-warm px-4 py-3 text-[13px] text-slate-600">Median offer <strong class="text-brand-900"><?= e(pc_lpa($stats['median'])) ?></strong> · average <strong class="text-brand-900"><?= e(pc_lpa($stats['average'])) ?></strong></p>
      <?php endif; ?>
    </article>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- Recent offers -->
<?= section_open(['id' => 'offers', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Recent Offers', 'The latest offers of the ' . ($seasonName ?: 'current') . ' season. Students are shown by first name and last initial only.', 'Offers ' . $seasonName) ?>
  <?php if ($offers): ?>
    <ul class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4" data-stagger="60">
      <?php foreach ($offers as $o): ?>
        <li class="flex h-full min-w-0 flex-col rounded-[20px] border border-warm-2 bg-white p-4 transition duration-300 hover:-translate-y-0.5 hover:shadow-soft sm:p-5">
          <span class="font-display text-[1.3rem] sm:text-[1.6rem] font-extrabold leading-none tracking-tight text-accent-700"><?= e(pc_lpa($o['ctc'])) ?></span>
          <span class="mt-3 block text-[14px] font-bold leading-snug text-brand-900 sm:text-[15px]"><?= e($o['company']) ?></span>
          <span class="mt-auto flex min-w-0 items-center gap-2 pt-3 text-[12.5px] leading-snug text-slate-600 sm:text-[13px]">
            <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-[11px] font-bold text-brand-800" aria-hidden="true"><?= e(site_initials($o['who'])) ?></span>
            <span class="min-w-0"><span class="block font-medium text-brand-900"><?= e($o['who']) ?></span><?php if ($o['program'] !== ''): ?><span class="block break-words text-slate-500"><span class="sr-only">Program: </span><?= e($o['program']) ?></span><?php endif; ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <?= pc_empty('file-check', 'No offers recorded yet for this season', 'Offers appear here as soon as the placement cell records them. Check an earlier season above or talk to the placement cell.', button_link('Talk to the Placement Cell', '/contact?type=placement#message', 'brochure')) ?>
  <?php endif; ?>
<?= section_close() ?>

<!-- Top recruiters -->
<?= section_open(['id' => 'recruiters', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Our Top Recruiters', $recruiters['total']
      ? $recruiters['total'] . ' companies recruit from GIMT' . ($recruiters['all_time_hiring'] ? ', and ' . $recruiters['all_time_hiring'] . ' of them have already made offers to our students' : '') . '. Ranked by offers made to GIMT students.'
      : 'Companies that recruit GIMT students.', 'Recruiters', 'left', button_link('Hire from GIMT', '#hire', 'callback', ['icon' => 'arrow-right']), ['highlight' => 'Recruiters']) ?>
  <?php if ($recruiters['items']): ?>
    <ul class="grid grid-cols-3 gap-2 sm:gap-3 md:grid-cols-4 xl:grid-cols-6" data-stagger="35">
      <?php foreach ($recruiters['items'] as $i => $c): ?><?= pc_company_tile($c, $i) ?><?php endforeach; ?>
    </ul>
    <p class="mt-5 text-xs text-slate-500">Company names and logos are trademarks of their respective owners and are shown to identify organisations that recruit GIMT students.</p>
  <?php else: ?>
    <?= pc_empty('building-2', 'Our recruiter list is being updated', 'Partner companies will be listed here. Want to hire GIMT graduates? Send us your requirement.', button_link('Hire from GIMT', '#hire', 'explore')) ?>
  <?php endif; ?>
<?= section_close() ?>

<!-- Training & mock tests -->
<?= section_open(['id' => 'training', 'bg' => 'light', 'pattern' => 'dots', 'class' => 'scroll-mt-24']) ?>
  <?php
  $tSes = $training['session']['name'] ?? '';
  $tKinds = array_unique(array_merge(array_keys($training['by_type']), array_column($training['upcoming'], 'type'), array_column($training['recent'], 'type')));
  $tLabels = [];
  foreach (pc_training_types() as $key => $meta) {
      if (in_array($key, $tKinds, true)) {
          $tLabels[] = $meta[3];
      }
  }
  $tLead = $tLabels
      ? 'Placement preparation runs through the year: ' . (count($tLabels) > 1 ? implode(', ', array_slice($tLabels, 0, -1)) . ' and ' . end($tLabels) : $tLabels[0]) . '.'
      : 'Aptitude, communication and domain training, mock tests and mock interviews prepare students for campus selection rounds.';
  ?>
  <?= section_heading('Training & Mock Tests', $tLead, 'Career Readiness' . ($tSes !== '' ? ' · ' . $tSes : '')) ?>
  <?php
  $tTiles = [];
  if ($training['completed']) {
      $tTiles[] = pc_stat($training['completed'], 'Sessions completed', ['note' => 'This academic year']);
  }
  if ($training['hours'] > 0) {
      $tTiles[] = pc_stat(round($training['hours']), 'Hours of training', ['note' => 'Completed sessions']);
  }
  if ($training['students']) {
      $tTiles[] = pc_stat($training['students'], 'Students trained', ['note' => $training['attendances'] . ' attendances']);
  }
  $mocks = ($training['by_type']['mock_test'] ?? 0) + ($training['by_type']['mock_interview'] ?? 0);
  if ($mocks) {
      $tTiles[] = pc_stat($mocks, 'Mock tests & interviews', ['note' => 'Completed this year']);
  }
  ?>
  <?php if ($tTiles): ?>
    <ul class="grid grid-cols-2 gap-4 <?= [1 => 'lg:grid-cols-2', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3'][count($tTiles)] ?? 'lg:grid-cols-4' ?>" data-stagger="70">
      <?php foreach ($tTiles as $t): ?><li class="rounded-[20px] border border-warm-2 bg-white p-5"><?= $t ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($training['upcoming'] || $training['recent']): ?>
    <div class="mt-10 grid grid-cols-1 gap-8 lg:grid-cols-2">
      <div>
        <h3 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('calendar-clock', 'h-5 w-5 text-accent-600') ?>Coming up</h3>
        <?php if ($training['upcoming']): ?>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-stagger="60">
            <?php foreach ($training['upcoming'] as $t): ?><?= pc_training_card($t) ?><?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-6 text-sm text-slate-600">The next training calendar will be announced soon.</p>
        <?php endif; ?>
      </div>
      <div>
        <h3 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('circle-check', 'h-5 w-5 text-accent-600') ?>Recently completed</h3>
        <?php if ($training['recent']): ?>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-stagger="60">
            <?php foreach ($training['recent'] as $t): ?><?= pc_training_card($t) ?><?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white px-5 py-6 text-sm text-slate-600">Completed sessions will be listed here.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php elseif (!$tTiles): ?>
    <?= pc_empty('presentation', 'The training calendar is being prepared', 'Aptitude, communication and domain training plus mock tests will be listed here once scheduled.') ?>
  <?php endif; ?>
<?= section_close() ?>

<!-- Placement process -->
<?= section_open(['id' => 'process', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('How Campus Placement Works', 'From the first training session to the joining date, the Training & Placement Cell runs every step with students and recruiters.', 'Placement Process') ?>
  <?= progress_steps([
      ['title' => 'Pre-placement training', 'text' => 'Aptitude, communication and domain sessions, mock tests and mock interviews.', 'icon' => 'presentation'],
      ['title' => 'Registration & eligibility', 'text' => 'Students register for drives; eligibility is checked by program, batch, CGPA and backlogs.', 'icon' => 'clipboard-check'],
      ['title' => 'Shortlisting', 'text' => 'Recruiters shortlist registered students for their selection rounds.', 'icon' => 'list-checks'],
      ['title' => 'Selection rounds', 'text' => 'Aptitude tests, technical or case rounds and HR interviews as set by each recruiter.', 'icon' => 'messages-square'],
      ['title' => 'Offer & acceptance', 'text' => 'Offer letters are issued through the placement cell; students accept or decline.', 'icon' => 'file-check'],
      ['title' => 'Joining & alumni connect', 'text' => 'Placed students join their recruiter and stay connected through the alumni network.', 'icon' => 'handshake'],
  ]) ?>
<?= section_close() ?>

<?php if ($stories): ?>
<!-- Success stories -->
<?= section_open(['id' => 'stories', 'bg' => 'light', 'class' => 'scroll-mt-24 overflow-hidden']) ?>
  <?= section_heading('Success Stories', 'Graduates and students on the training, mentoring and opportunities that shaped their careers.', 'In Their Words', 'left',
      '<a href="' . e(site_url('alumni')) . '#stories" class="link-arrow">Meet our alumni' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= testimonial_slider($stories, ['ariaLabel' => 'Placement and alumni success stories', 'autoplay' => 0]) ?>
<?= section_close() ?>
<?php endif; ?>

<!-- Hire from GIMT (recruiter enquiry) -->
<?= section_open(['id' => 'hire', 'bg' => 'navy', 'class' => 'scroll-mt-24 overflow-hidden']) ?>
  <div class="grid grid-cols-1 items-start gap-10 lg:grid-cols-12">
    <div class="lg:col-span-6"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-3 !text-accent-300">For Recruiters</p>
      <h2 class="section-title !text-white" data-text-reveal><?= scribble_text('Hire from GIMT', 'GIMT', 'orange') ?></h2>
      <p class="mt-4 max-w-xl text-[16px] leading-relaxed text-white/75">
        Partner with the Training &amp; Placement Cell to hire interns and graduates<?= $programCount ? ' from ' . (int) $programCount . ' program' . ($programCount === 1 ? '' : 's') : '' ?><?= $deptCount ? ' across ' . (int) $deptCount . ' department' . ($deptCount === 1 ? '' : 's') : '' ?>.
      </p>
      <ul class="mt-7 grid grid-cols-1 gap-3 sm:grid-cols-2" data-stagger="70">
        <?php foreach ([
            ['building-2', 'Campus, virtual or pool drives', 'We coordinate venue, registrations and schedules.'],
            ['list-checks', 'Targeted shortlists', 'Filter candidates by program, batch, CGPA and backlogs.'],
            ['briefcase-business', 'Internships to full-time', 'Internships, internships with PPO and full-time roles.'],
            ['handshake', 'One point of contact', 'From the job description to offers and joining.'],
        ] as [$ic, $t, $txt]): ?>
          <li class="flex gap-3 rounded-2xl border border-white/10 bg-white/[.06] p-4">
            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-accent-600 text-white"><?= icon($ic, 'h-5 w-5') ?></span>
            <span><span class="block font-semibold text-white"><?= e($t) ?></span><span class="mt-0.5 block text-[13px] leading-snug text-white/65"><?= e($txt) ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php $phone = (string) setting('phone', ''); $mail = (string) setting('email', ''); ?>
      <?php if ($phone !== '' || $mail !== ''): ?>
        <p class="mt-7 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/75">
          <?php if ($phone !== ''): ?><a href="<?= e(site_tel($phone)) ?>" class="inline-flex items-center gap-2 font-semibold text-white hover:text-accent-300"><?= icon('phone', 'h-4 w-4 text-accent-300') ?><?= e($phone) ?></a><?php endif; ?>
          <?php if ($mail !== ''): ?><a href="mailto:<?= e($mail) ?>" class="inline-flex items-center gap-2 font-semibold text-white hover:text-accent-300"><?= icon('mail', 'h-4 w-4 text-accent-300') ?><?= e($mail) ?></a><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
    <div class="text-slate-700 lg:col-span-6 [&_h3]:!text-brand-900"<?= reveal_attr('fade-up', 120) ?>>
      <?= enquiry_form([
          'endpoint' => base_url('api/public/contact'), 'theme' => 'light', 'id' => 'message',
          'title' => 'Recruiter Enquiry', 'subtitle' => 'Tell us about your hiring needs — the placement cell will get back to you.',
          'submitLabel' => 'Send Hiring Enquiry', 'successTitle' => 'Thank you!',
          'successMessage' => 'Your enquiry has reached the GIMT Training & Placement Cell. We will get back to you shortly.',
          'hidden' => ['enquiry_type' => 'placement', 'source' => 'recruiter', 'form' => 'recruiter'],
          'consent' => 'I agree to be contacted by the GIMT Training & Placement Cell about this enquiry.',
          'fields' => [
              ['name' => 'name', 'label' => 'Your Name', 'required' => true, 'col' => 'half', 'maxLength' => 150, 'autocomplete' => 'name'],
              ['name' => 'subject', 'label' => 'Company / Organisation', 'required' => true, 'col' => 'half', 'maxLength' => 190, 'autocomplete' => 'organization'],
              ['name' => 'email', 'label' => 'Work Email', 'type' => 'email', 'required' => true, 'col' => 'half', 'maxLength' => 190, 'autocomplete' => 'email'],
              ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'col' => 'half', 'maxLength' => 20, 'autocomplete' => 'tel'],
              ['name' => 'message', 'label' => 'Hiring requirement', 'type' => 'textarea', 'required' => true, 'minLength' => 10, 'maxLength' => 2000, 'rows' => 4,
               'placeholder' => 'Roles, number of positions, location, internship or full-time, preferred timeline'],
          ],
      ]) ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Related -->
<?= section_open(['id' => 'explore', 'padding' => 'tight', 'label' => 'Explore more']) ?>
  <h2 class="sr-only">Explore more</h2>
  <div class="grid grid-cols-1 gap-4 md:grid-cols-3" data-stagger="80">
    <?= feature_tile('users-round', 'Alumni Network', 'Mentors, alumni events, job openings and stories from GIMT graduates.', ['url' => '/alumni', 'tint' => 'navy']) ?>
    <?= feature_tile('graduation-cap', 'Explore Courses', 'UG, PG, diploma and certificate programs with fees and eligibility.', ['url' => '/programs', 'tint' => 'orange']) ?>
    <?= feature_tile('message-circle', 'Talk to the Placement Cell', 'Questions about internships, drives or training? Send us a message.', ['url' => '/contact?type=placement#message', 'tint' => 'sky']) ?>
  </div>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Start Your Career Journey at GIMT',
    'cta_text' => 'Industry-focused programs, year-round placement training and a recruiter network that grows every season.',
    'cta_highlight' => 'Career',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
