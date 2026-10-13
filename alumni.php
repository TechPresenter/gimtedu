<?php
/**
 * Alumni Network (/alumni) — network numbers (verified alumni, companies, cities, countries, mentors), alumni success
 * stories published by the alumni office, where alumni work (aggregates), upcoming alumni events (Grid / List /
 * Compact / Detailed view modes) + recent gatherings, job-board highlights (approved, not expired), mentorship
 * programme, give-back information (no payments online) and the "Join the alumni network" registration form
 * (EnquiryForm island → POST /api/public/alumni-register → unverified alumni profile + alumni coordinator notification).
 *
 * Data: alumni, alumni_success_stories, alumni_events, alumni_jobs, alumni_mentorships, alumni_donations (counts only),
 * CMS route page "alumni" (subtitle, excerpt, banner). Helpers: includes/partials/public_career.php.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/public_career.php';
require_once APP_ROOT . '/app/services/alumni.php';

$cms = pc_cms_page('alumni');
$stats = pc_alumni_stats();
$foot = pc_alumni_footprint();
$stories = pc_success_stories(8, false);
$events = pc_alumni_events(6);
$past = pc_alumni_past_events(3);
$jobs = pc_alumni_jobs(24);
$mentoring = pc_mentorship();
$giving = pc_giving();
$programOptions = pc_alumni_program_options();
[$minYear, $maxYear] = pc_alumni_year_range();
$officeEmail = (string) (setting('alumni_email', '') ?: setting('email', ''));
$heroImage = ($cms['banner_image'] ?? '') ?: 'assets/images/site/graduation.jpg';
$heroText = ($cms['excerpt'] ?? '') ?: 'Our alumni network connects GIMT graduates across industries for mentoring, jobs, events and giving back.';
$eventTypes = alumni_event_types();
$eventModes = alumni_event_modes();
$jobTypes = alumni_job_types();

$chips = [];
if ($stats['total']) {
    $chips[] = ['icon' => 'users-round', 'label' => pc_round_label($stats['total']) . ' verified alumni'];
}
if ($stats['countries'] > 1) {
    $chips[] = ['icon' => 'globe', 'label' => $stats['countries'] . ' countries'];
}
if ($stats['mentors']) {
    $chips[] = ['icon' => 'handshake', 'label' => $stats['mentors'] . ' alumni mentors'];
}
if ($events) {
    $chips[] = ['icon' => 'calendar-days', 'label' => count($events) . ' upcoming event' . (count($events) === 1 ? '' : 's')];
}

// JSON-LD: breadcrumbs + upcoming alumni events.
$schema = [pc_breadcrumb_ld([['Home', ''], ['Alumni', 'alumni']])];
foreach ($events as $ev) {
    $online = $ev['mode'] === 'online';
    $place = ['@type' => 'Place', 'name' => (string) ($ev['venue'] ?: institute_name()), 'address' => ['@type' => 'PostalAddress', 'addressLocality' => setting('city', 'Greater Noida'), 'addressCountry' => 'IN']];
    $virtual = ['@type' => 'VirtualLocation', 'url' => !empty($ev['registration_link']) && preg_match('#^https?://#', (string) $ev['registration_link']) ? (string) $ev['registration_link'] : absolute_url('alumni')];
    $schema[] = array_filter([
        '@context' => 'https://schema.org', '@type' => 'Event', 'name' => (string) $ev['title'],
        'startDate' => date('c', strtotime((string) $ev['event_date'])), 'endDate' => $ev['end_date'] ? date('c', strtotime((string) $ev['end_date'])) : null,
        'eventStatus' => 'https://schema.org/EventScheduled',
        'eventAttendanceMode' => 'https://schema.org/' . ($online ? 'OnlineEventAttendanceMode' : ($ev['mode'] === 'hybrid' ? 'MixedEventAttendanceMode' : 'OfflineEventAttendanceMode')),
        'location' => $online ? $virtual : ($ev['mode'] === 'hybrid' ? [$place, $virtual] : $place),
        'description' => $ev['description'] ? pc_plain((string) $ev['description'], 300) : null,
        'image' => pc_image_ok($ev['image']) && !preg_match('#^https?://#i', (string) $ev['image']) ? absolute_url(ltrim((string) $ev['image'], '/')) : null,
        'organizer' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'url' => absolute_url('')],
    ], fn ($v) => $v !== null);
}

site_header([
    'title' => 'Alumni Network — Stories, Events, Jobs & Mentoring',
    'description' => 'The GIMT alumni network: success stories, alumni events and reunions, job openings shared by alumni, the mentorship programme, ways to give back, and how to join the network.',
    'active' => 'alumni',
    'seo' => ['route', '/alumni'],
    'canonical' => absolute_url('alumni'),
    'image' => $heroImage,
    'preload' => [site_image($heroImage)],
    'schema' => $schema,
]);

page_hero('GIMT Alumni Network', ($cms['subtitle'] ?? '') ?: 'Once a GIMTian, Always a GIMTian', ['Alumni'], $heroImage, $heroText, $chips, [
    'eyebrow' => 'Alumni Relations', 'eyebrow_icon' => 'users-round', 'size' => 'lg', 'highlight' => 'Alumni',
    'image_alt' => 'GIMT graduates celebrating at convocation',
    'badge' => $stats['first_batch'] ? ['icon' => 'graduation-cap', 'text' => 'Graduates since ' . (int) $stats['first_batch']] : false,
    'actions' => button_link('Join the Network', '#join', 'apply', ['size' => 'lg', 'shine' => true, 'magnetic' => true, 'icon' => 'user-plus'])
        . button_link('Alumni Events', '#events', 'brochure', ['size' => 'lg', 'icon_left' => 'calendar-days']),
]);
?>

<!-- Network in numbers -->
<section id="network" class="relative z-10 -mt-8 scroll-mt-24 sm:-mt-12" aria-labelledby="network-title">
  <div class="container-site">
    <div class="relative isolate overflow-hidden rounded-3xl bg-brand-900 text-white shadow-pop"<?= reveal_attr('fade-up') ?>>
      <div class="pointer-events-none absolute inset-0 -z-10 [background-image:radial-gradient(55%_80%_at_100%_0%,rgba(240,90,40,.28),transparent_60%),radial-gradient(50%_70%_at_0%_100%,rgba(31,74,148,.6),transparent_65%)]" aria-hidden="true"></div>
      <div class="pattern-dots-light pattern-fade absolute inset-0 -z-10 opacity-50" aria-hidden="true"></div>
      <div class="p-6 sm:p-8 lg:p-10">
        <h2 id="network-title" class="font-display text-[clamp(1.5rem,1.15rem+1.2vw,2.1rem)] font-extrabold leading-tight text-white">The Network in Numbers</h2>
        <p class="mt-1.5 text-sm text-white/70">Live from the alumni office's records — verified profiles only.</p>
        <?php
        $tiles = [];
        if ($stats['total']) {
            $tiles[] = pc_stat($stats['total'], 'Verified alumni', ['dark' => true, 'note' => $stats['batches'] ? $stats['batches'] . ' graduating batches' : '']);
        }
        if ($stats['companies']) {
            $tiles[] = pc_stat($stats['companies'], 'Companies', ['dark' => true, 'note' => 'Where alumni work today']);
        }
        if ($stats['cities']) {
            $tiles[] = pc_stat($stats['cities'], 'Cities', ['dark' => true, 'note' => 'Where alumni live and work']);
        }
        if ($stats['countries']) {
            $tiles[] = pc_stat($stats['countries'], $stats['countries'] === 1 ? 'Country' : 'Countries', ['dark' => true, 'note' => 'Global footprint']);
        }
        if ($stats['mentors']) {
            $tiles[] = pc_stat($stats['mentors'], 'Alumni mentors', ['dark' => true, 'note' => 'Guiding current students']);
        }
        ?>
        <?php if ($tiles): ?>
          <ul class="mt-7 grid grid-cols-2 gap-x-6 gap-y-7 border-t border-white/10 pt-7 md:grid-cols-3 <?= count($tiles) >= 5 ? 'xl:grid-cols-5' : 'xl:grid-cols-4' ?>" data-stagger="70">
            <?php foreach ($tiles as $t): ?><li class="min-w-0 xl:border-l xl:border-white/10 xl:pl-6 xl:first:border-l-0 xl:first:pl-0"><?= $t ?></li><?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="mt-6 rounded-2xl border border-white/15 bg-white/5 p-5 text-sm text-white/75">Network numbers appear here once alumni profiles are verified. Be among the first — <a href="#join" class="font-semibold text-accent-300 underline underline-offset-2">join the network</a>.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?= pc_page_nav(array_filter([
    'stories' => $stories ? 'Success Stories' : null,
    'footprint' => $foot['companies'] ? 'Where Alumni Work' : null,
    'events' => 'Events',
    'jobs' => 'Job Board',
    'mentorship' => 'Mentorship',
    'give-back' => 'Give Back',
    'join' => 'Join the Network',
])) ?>

<!-- Success stories / notable alumni -->
<?= section_open(['id' => 'stories', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Notable Alumni & Success Stories', 'Journeys of GIMT graduates — in their own words, published by the alumni office.', 'Success Stories', 'left', '', ['highlight' => 'Success']) ?>
  <?php if ($stories): ?>
    <?php $lead = array_shift($stories); ?>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <article class="relative flex flex-col overflow-hidden rounded-[28px] bg-brand-900 p-7 text-white sm:p-9 lg:col-span-5"<?= reveal_attr('slide-left') ?>>
        <div class="pointer-events-none absolute inset-0 [background-image:radial-gradient(60%_70%_at_100%_0%,rgba(240,90,40,.3),transparent_60%)]" aria-hidden="true"></div>
        <span class="relative font-display text-6xl leading-none text-accent-400" aria-hidden="true">“</span>
        <?php if (!empty($lead['title'])): ?><h3 class="relative font-display text-xl font-extrabold leading-snug text-white sm:text-2xl"><?= e($lead['title']) ?></h3><?php endif; ?>
        <blockquote class="relative mt-4 text-[16px] leading-relaxed text-white/80"><?= e($lead['quote']) ?></blockquote>
        <div class="relative mt-auto flex items-center gap-4 pt-8">
          <?php if (!empty($lead['photo'])): ?>
            <img src="<?= e($lead['photo']) ?>" alt="" loading="lazy" decoding="async" width="56" height="56" class="h-14 w-14 shrink-0 rounded-full object-cover ring-2 ring-white/30">
          <?php else: ?>
            <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-accent-600 font-display text-lg font-extrabold text-white" aria-hidden="true"><?= e(site_initials($lead['name'])) ?></span>
          <?php endif; ?>
          <p class="min-w-0"><span class="block font-display text-[16px] font-bold text-white"><?= e($lead['name']) ?></span>
            <span class="block text-[13px] text-white/70"><?= e($lead['meta'] ?? '') ?></span></p>
        </div>
      </article>
      <?php if ($stories): ?>
        <ul class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:col-span-7" data-stagger="70">
          <?php foreach (array_slice($stories, 0, 4) as $s): ?><?= pc_story_card($s) ?><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <?php if (count($stories) > 4): ?>
      <ul class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3" data-stagger="70">
        <?php foreach (array_slice($stories, 4, 6) as $s): ?><?= pc_story_card($s) ?><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php else: ?>
    <?= pc_empty('quote', 'Alumni stories are on their way', 'The alumni office is collecting stories from our graduates. Have one to share? Join the network and tell us about your journey.', button_link('Join the Network', '#join', 'apply')) ?>
  <?php endif; ?>
<?= section_close() ?>

<?php if ($foot['companies']): ?>
<!-- Where alumni work -->
<?= section_open(['id' => 'footprint', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Where Our Alumni Work', 'Employers, industries and cities with the most GIMT graduates — counts of verified alumni profiles.', 'Alumni Footprint') ?>
  <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
    <article class="card p-6 sm:p-7 lg:col-span-7"<?= reveal_attr('fade-up') ?>>
      <h3 class="font-display text-lg font-extrabold text-brand-900">Top employers</h3>
      <ul class="mt-5 flex flex-wrap gap-2.5">
        <?php foreach ($foot['companies'] as $c): ?>
          <li class="inline-flex items-center gap-2 rounded-full border border-warm-2 bg-warm px-3.5 py-1.5 text-[13.5px] font-semibold text-brand-900"><?= e($c['label']) ?><span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-slate-600"><?= (int) $c['n'] ?><span class="sr-only"> alumni</span></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($foot['industries']): $maxInd = max(1, (int) $foot['industries'][0]['n']); ?>
        <h3 class="mt-8 font-display text-lg font-extrabold text-brand-900">Industries</h3>
        <ul class="mt-4 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
          <?php foreach ($foot['industries'] as $ind): ?>
            <li>
              <div class="flex items-baseline justify-between gap-3 text-[13.5px]"><span class="min-w-0 truncate font-medium text-slate-700"><?= e($ind['label']) ?></span><span class="shrink-0 font-bold text-brand-900"><?= (int) $ind['n'] ?></span></div>
              <span class="progress-track mt-1.5 !h-1.5" aria-hidden="true"><span class="progress-fill" style="--progress:<?= e((string) round((int) $ind['n'] / $maxInd, 4)) ?>"></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
    <article class="card p-6 sm:p-7 lg:col-span-5"<?= reveal_attr('fade-up', 120) ?>>
      <?php if ($foot['countries']): ?>
        <h3 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('globe', 'h-5 w-5 text-accent-600') ?>Around the world</h3>
        <ul class="mt-4 divide-y divide-slate-100">
          <?php foreach ($foot['countries'] as $c): ?>
            <li class="flex items-center justify-between gap-3 py-2.5 text-[14px]"><span class="font-medium text-slate-700"><?= e($c['label']) ?></span><span class="font-display font-extrabold text-brand-900"><?= (int) $c['n'] ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($foot['cities']): ?>
        <h3 class="mt-7 flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('map-pin', 'h-5 w-5 text-accent-600') ?>Top cities</h3>
        <ul class="mt-4 flex flex-wrap gap-2">
          <?php foreach ($foot['cities'] as $c): ?><li class="rounded-full bg-brand-50 px-3 py-1 text-[13px] font-semibold text-brand-800"><?= e($c['label']) ?> · <?= (int) $c['n'] ?><span class="sr-only"> alumni</span></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- Alumni events -->
<?= section_open(['id' => 'events', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Alumni Events', 'Reunions, chapter meets, webinars and mentoring sessions — come back, reconnect and give back.', 'Upcoming', 'left', $events ? view_mode_toggle('alumni_events') : '') ?>
  <?php if ($events): ?>
    <?= view_mode_open('alumni_events', ['class' => 'lg:[&[data-view=grid]]:grid-cols-3']) ?>
      <?php foreach ($events as $ev): ?>
        <?php
        $ts = strtotime((string) $ev['event_date']);
        $link = !empty($ev['registration_link']) && preg_match('#^https?://#i', (string) $ev['registration_link']) ? (string) $ev['registration_link'] : '';
        $modeLabel = $eventModes[$ev['mode']] ?? label_from_key((string) $ev['mode']);
        $typeLabel = $eventTypes[$ev['type']] ?? label_from_key((string) $ev['type']);
        ?>
        <article class="view-item flex flex-col">
          <div class="vm-media">
            <?php if ($evImg = pc_image_ok($ev['image'])): ?>
              <img src="<?= e(site_image($evImg)) ?>" alt="" loading="lazy" decoding="async" width="640" height="400">
            <?php else: ?>
              <span class="absolute inset-0 flex items-center justify-center bg-brand-900 [background-image:radial-gradient(70%_90%_at_100%_0%,rgba(240,90,40,.35),transparent_60%)]" aria-hidden="true">
                <span class="text-center text-white"><span class="block font-display text-5xl font-extrabold leading-none"><?= e(date('d', $ts)) ?></span><span class="mt-1 block text-sm font-bold uppercase tracking-[0.2em] text-accent-300"><?= e(date('M Y', $ts)) ?></span></span>
              </span>
            <?php endif; ?>
          </div>
          <div class="vm-body flex-1">
            <p class="flex flex-wrap items-center gap-2 text-[12.5px] font-semibold text-slate-500">
              <span class="badge badge-orange"><?= e($typeLabel) ?></span>
              <span class="badge <?= $ev['mode'] === 'online' ? 'badge-cyan' : ($ev['mode'] === 'hybrid' ? 'badge-purple' : 'badge-blue') ?>"><?= e($modeLabel) ?></span>
            </p>
            <h3 class="font-display text-[17px] font-bold leading-snug text-brand-900"><?= e($ev['title']) ?></h3>
            <p class="flex flex-wrap gap-x-4 gap-y-1 text-[13px] text-slate-600">
              <span class="inline-flex items-center gap-1.5"><?= icon('calendar-days', 'h-4 w-4 text-accent-700') ?><time datetime="<?= e(date('c', $ts)) ?>"><?= e(date('D, d M Y · g:i A', $ts)) ?></time></span>
              <?php if (!empty($ev['venue'])): ?><span class="inline-flex min-w-0 items-center gap-1.5"><?= icon($ev['mode'] === 'online' ? 'video' : 'map-pin', 'h-4 w-4 shrink-0 text-accent-700') ?><span class="truncate"><?= e($ev['venue']) ?></span></span><?php endif; ?>
            </p>
            <?php if (pc_plain($ev['description']) !== ''): ?><p class="vm-text line-clamp-3 text-[14px] leading-relaxed text-slate-600"><?= e(pc_plain($ev['description'], 220)) ?></p><?php endif; ?>
            <div class="vm-extra text-[13px] text-slate-600">
              <?php if (!empty($ev['organiser'])): ?><p><strong class="text-brand-900">Organiser:</strong> <?= e($ev['organiser']) ?></p><?php endif; ?>
              <?php if (!empty($ev['capacity'])): ?><p><strong class="text-brand-900">Capacity:</strong> <?= (int) $ev['capacity'] ?> guests</p><?php endif; ?>
            </div>
            <div class="mt-auto pt-3">
              <?php if ($link !== ''): ?>
                <a href="<?= e($link) ?>" target="_blank" rel="noopener nofollow" class="link-arrow text-[14px]">Register<?= icon('external-link', 'h-4 w-4') ?><span class="sr-only"> for <?= e($ev['title']) ?> (opens in a new tab)</span></a>
              <?php else: ?>
                <a href="#join" class="link-arrow text-[14px]">Join the network for an invite<?= icon('arrow-right', 'h-4 w-4') ?></a>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    <?= view_mode_close() ?>
  <?php else: ?>
    <?= pc_empty('calendar-days', 'No alumni events scheduled right now', 'Reunions, chapter meets and webinars are announced here. Join the network to receive invitations, or browse campus events.',
        button_link('Join the Network', '#join', 'apply') . button_link('Campus Events', '/events', 'brochure')) ?>
  <?php endif; ?>
  <?php if ($past): ?>
    <div class="mt-12"<?= reveal_attr() ?>>
      <h3 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('history', 'h-5 w-5 text-accent-600') ?>Recent gatherings</h3>
      <ul class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        <?php foreach ($past as $p): $pts = strtotime((string) $p['event_date']); ?>
          <li class="flex items-center gap-4 rounded-2xl border border-warm-2 bg-warm p-4">
            <span class="date-tile !h-14 !w-14"><span class="date-tile-day"><?= e(date('d', $pts)) ?></span><span class="date-tile-mon"><?= e(date('M y', $pts)) ?></span></span>
            <span class="min-w-0"><span class="block font-semibold leading-snug text-brand-900"><?= e($p['title']) ?></span>
              <span class="block text-[12.5px] text-slate-600"><?= e($eventTypes[$p['type']] ?? label_from_key((string) $p['type'])) ?><?= (int) $p['attendees_count'] ? ' · ' . (int) $p['attendees_count'] . ' attended' : '' ?></span></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?= section_close() ?>

<!-- Job board highlights -->
<?= section_open(['id' => 'jobs', 'bg' => 'light', 'pattern' => 'dots', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Alumni Job Board', $jobs['total']
      ? $jobs['total'] . ' open role' . ($jobs['total'] === 1 ? '' : 's') . ' shared by alumni and partner companies, reviewed by the alumni office.'
      : 'Openings shared by alumni and partner companies, reviewed by the alumni office.', 'Opportunities') ?>
  <?php if ($jobs['items']): ?>
    <?php $firstJobs = array_slice($jobs['items'], 0, 6); $moreJobs = array_slice($jobs['items'], 6); ?>
    <ul class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" data-stagger="60">
      <?php foreach ($firstJobs as $j): ?><?= pc_job_card($j, $jobTypes) ?><?php endforeach; ?>
    </ul>
    <?php if ($moreJobs): ?>
      <details class="group mt-5">
        <summary class="btn btn-outline mx-auto flex w-max cursor-pointer list-none items-center gap-2 [&::-webkit-details-marker]:hidden">
          <span class="group-open:hidden">Show <?= count($moreJobs) ?> more open role<?= count($moreJobs) === 1 ? '' : 's' ?></span><span class="hidden group-open:inline">Show fewer roles</span>
          <?= icon('chevron-down', 'h-4 w-4 transition-transform duration-200 group-open:rotate-180') ?>
        </summary>
        <ul class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <?php foreach ($moreJobs as $j): ?><?= pc_job_card($j, $jobTypes) ?><?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
    <?php if ($jobs['total'] > count($jobs['items'])): ?>
      <p class="mt-4 text-sm text-slate-600">Showing the <?= count($jobs['items']) ?> most recent of <?= (int) $jobs['total'] ?> open roles — <a href="<?= e(site_url('contact?type=placement')) ?>#message" class="font-semibold text-accent-700 underline decoration-accent-200 underline-offset-2 hover:text-brand-900">ask the placement cell</a> for the full list.</p>
    <?php endif; ?>
    <p class="mt-6 text-sm text-slate-600">Hiring at your company? Share the opening with the Training &amp; Placement Cell — <a href="<?= e(site_url('placement')) ?>#hire" class="font-semibold text-accent-700 underline decoration-accent-200 underline-offset-2 hover:text-brand-900">send a hiring enquiry</a>.</p>
  <?php else: ?>
    <?= pc_empty('briefcase-business', 'No open roles right now', 'New openings shared by alumni appear here after review. Recruiters can reach the placement cell directly.', button_link('Hire from GIMT', '/placement#hire', 'explore')) ?>
  <?php endif; ?>
<?= section_close() ?>

<!-- Mentorship -->
<?= section_open(['id' => 'mentorship', 'class' => 'scroll-mt-24 overflow-hidden']) ?>
  <div class="grid grid-cols-1 items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-6"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-3">Mentorship Programme</p>
      <h2 class="section-title" data-text-reveal><?= scribble_text('Guide the Next Generation', 'Next', 'orange') ?></h2>
      <p class="section-lead">Alumni mentors are paired with current students for career guidance, interview preparation and industry insight — one conversation can change a career path.</p>
      <ol class="mt-7 space-y-4">
        <?php foreach ([
            ['user-plus', 'Volunteer as a mentor', 'Tick "I would like to mentor students" when you join the network, or tell the alumni office.'],
            ['handshake', 'Get paired with a student', 'The alumni office matches you by program, industry and focus area.'],
            ['messages-square', 'Meet on your schedule', 'Online or on campus — sessions are logged so progress is tracked.'],
        ] as $i => [$ic, $t, $txt]): ?>
          <li class="flex gap-4">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent-50 text-accent-700"><?= icon($ic, 'h-5 w-5') ?></span>
            <span><span class="block font-semibold text-brand-900"><?= ($i + 1) . '. ' . e($t) ?></span><span class="mt-0.5 block text-[14px] text-slate-600"><?= e($txt) ?></span></span>
          </li>
        <?php endforeach; ?>
      </ol>
      <div class="mt-8"><?= button_link('Become a Mentor', '#join', 'explore', ['icon' => 'arrow-right']) ?></div>
    </div>
    <div class="lg:col-span-6"<?= reveal_attr('fade-up', 120) ?>>
      <?php
      $mTiles = [];
      if ($stats['mentors']) {
          $mTiles[] = pc_stat($stats['mentors'], 'Alumni mentors');
      }
      if ($mentoring['active']) {
          $mTiles[] = pc_stat($mentoring['active'], 'Active pairings');
      }
      if ($mentoring['completed']) {
          $mTiles[] = pc_stat($mentoring['completed'], 'Mentorships completed');
      }
      if ($mentoring['sessions']) {
          $mTiles[] = pc_stat($mentoring['sessions'], 'Sessions held');
      }
      ?>
      <div class="rounded-[28px] border border-warm-2 bg-warm p-6 sm:p-8">
        <?php if ($mTiles): ?>
          <ul class="grid grid-cols-2 gap-6" data-stagger="70">
            <?php foreach ($mTiles as $t): ?><li><?= $t ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if ($mentoring['areas']): ?>
          <h3 class="<?= $mTiles ? 'mt-8' : '' ?> text-xs font-bold uppercase tracking-wider text-slate-500">Popular focus areas</h3>
          <ul class="mt-3 flex flex-wrap gap-2">
            <?php foreach ($mentoring['areas'] as $a): ?><li class="rounded-full bg-white px-3.5 py-1.5 text-[13px] font-semibold text-brand-900 ring-1 ring-warm-2"><?= e($a) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <?php if (!$mTiles && !$mentoring['areas']): ?>
          <p class="text-sm text-slate-600">The mentorship programme is opening its first cohort. Volunteer when you join the network and the alumni office will be in touch.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?= section_close() ?>

<!-- Give back -->
<?= section_open(['id' => 'give-back', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Give Back to GIMT', 'Alumni contributions fund scholarships, learning resources and campus initiatives. Contributions are handled directly by the alumni office — no online payment is taken on this website.', 'Giving', 'left', '', ['highlight' => 'Give']) ?>
  <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
    <div class="lg:col-span-8">
      <?php $causeIcons = ['Scholarship Fund' => 'graduation-cap', 'Infrastructure Development' => 'building-2', 'Library & Learning Resources' => 'library', 'Research & Innovation' => 'microscope', 'Sports & Wellness' => 'dumbbell', 'Incubation & Startups' => 'rocket', 'Alumni Endowment' => 'landmark', 'General Development Fund' => 'hand-coins']; ?>
      <?php if ($giving['causes']): ?>
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3" data-stagger="60">
          <?php foreach ($giving['causes'] as $i => $c): ?>
            <li class="flex h-full flex-col rounded-[20px] border border-warm-2 bg-white p-5">
              <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl <?= ['tint-orange', 'tint-navy', 'tint-sky', 'tint-lavender', 'tint-aqua', 'tint-blush'][$i % 6] ?>"><?= icon($causeIcons[$c['label']] ?? 'heart', 'h-5 w-5') ?></span>
              <h3 class="mt-3 font-display text-[16px] font-bold text-brand-900"><?= e($c['label']) ?></h3>
              <p class="mt-1 text-[13px] text-slate-600">Supported by <?= (int) $c['donors'] ?> alumni donor<?= (int) $c['donors'] === 1 ? '' : 's' ?></p>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <?php foreach (array_slice(alumni_donation_purposes(), 0, 6) as $i => $label): ?>
            <li class="flex items-center gap-3 rounded-[20px] border border-warm-2 bg-white p-5"><span class="inline-flex h-10 w-10 items-center justify-center rounded-xl tint-orange"><?= icon($causeIcons[$label] ?? 'heart', 'h-5 w-5') ?></span><span class="font-semibold text-brand-900"><?= e($label) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($giving['campaigns']): ?>
        <p class="mt-6 text-sm text-slate-600"><span class="font-semibold text-brand-900">Recent campaigns:</span> <?= e(implode(' · ', $giving['campaigns'])) ?></p>
      <?php endif; ?>
    </div>
    <aside class="lg:col-span-4"<?= reveal_attr('fade-up', 120) ?>>
      <div class="h-full rounded-[24px] bg-brand-900 p-6 text-white sm:p-7">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-accent-600"><?= icon('gift', 'h-6 w-6') ?></span>
        <h3 class="mt-4 font-display text-xl font-extrabold text-white">How to contribute</h3>
        <?php if ($giving['donors']): ?>
          <p class="mt-2 text-sm text-white/75"><?= (int) $giving['donors'] ?> alumni have contributed <?= (int) $giving['contributions'] ?> time<?= $giving['contributions'] === 1 ? '' : 's' ?> so far.</p>
        <?php endif; ?>
        <ol class="mt-5 space-y-3 text-[14px] text-white/80">
          <li class="flex gap-3"><span class="font-display font-extrabold text-accent-300">1</span>Write to the alumni office with the cause you would like to support.</li>
          <li class="flex gap-3"><span class="font-display font-extrabold text-accent-300">2</span>The office shares the institute's official payment details with you directly.</li>
          <li class="flex gap-3"><span class="font-display font-extrabold text-accent-300">3</span>You receive a numbered donation receipt once your contribution is received.</li>
        </ol>
        <?php if ($officeEmail !== ''): ?>
          <a href="mailto:<?= e($officeEmail) ?>?subject=<?= rawurlencode('Alumni contribution enquiry') ?>" class="btn btn-white mt-6 w-full"><?= icon('mail', 'h-4 w-4') ?>Email the Alumni Office</a>
        <?php else: ?>
          <?= button_link('Contact the Alumni Office', '/contact?type=general#message', 'white', ['class' => 'mt-6 w-full']) ?>
        <?php endif; ?>
      </div>
    </aside>
  </div>
<?= section_close() ?>

<!-- Join the alumni network -->
<?= section_open(['id' => 'join', 'bg' => 'navy', 'class' => 'scroll-mt-24 overflow-hidden']) ?>
  <div class="grid grid-cols-1 items-start gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-3 !text-accent-300">Join the Network</p>
      <h2 class="section-title !text-white" data-text-reveal><?= scribble_text('Stay Connected With GIMT', 'Connected', 'orange') ?></h2>
      <p class="mt-4 max-w-xl text-[16px] leading-relaxed text-white/75">Graduated from GIMT? Register once — the alumni office verifies your details and adds you to the network.</p>
      <ul class="mt-7 space-y-3" data-stagger="70">
        <?php foreach ([
            ['calendar-days', 'Invitations to reunions, chapter meets and webinars'],
            ['briefcase-business', 'Job openings shared by fellow alumni'],
            ['handshake', 'Mentor students or find a mentor yourself'],
            ['newspaper', 'News from campus and your department'],
        ] as [$ic, $t]): ?>
          <li class="flex items-center gap-3 text-[15px] text-white/85"><span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 text-accent-300 ring-1 ring-white/15"><?= icon($ic, 'h-4 w-4') ?></span><?= e($t) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="mt-7 flex items-start gap-2 text-xs leading-relaxed text-white/60"><?= icon('shield-check', 'mt-0.5 h-4 w-4 shrink-0') ?>Your details are used by the alumni office only and are never shown publicly without your consent.</p>
    </div>
    <div class="text-slate-700 lg:col-span-7 [&_h3]:!text-brand-900"<?= reveal_attr('fade-up', 120) ?>>
      <?php
      $years = [];
      for ($y = $maxYear; $y >= $minYear; $y--) {
          $years[] = ['value' => (string) $y, 'label' => (string) $y];
      }
      $statusOptions = [];
      foreach (alumni_employment_statuses() as $k => $label) {
          $statusOptions[] = ['value' => $k, 'label' => $label];
      }
      ?>
      <?= enquiry_form([
          'endpoint' => base_url('api/public/alumni-register'), 'theme' => 'light', 'id' => 'join-form',
          'title' => 'Alumni Registration', 'subtitle' => 'Takes about two minutes. Fields marked * are required.',
          'submitLabel' => 'Join the Alumni Network', 'successTitle' => 'Welcome back!',
          'successMessage' => 'Your alumni registration has been received. The alumni office will verify your details and get in touch.',
          'hidden' => ['source' => 'alumni_page'],
          'consent' => 'I confirm these details are correct and agree to be contacted by the GIMT alumni office.',
          'fields' => [
              ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'col' => 'half', 'maxLength' => 150, 'autocomplete' => 'name'],
              ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'col' => 'half', 'maxLength' => 190, 'autocomplete' => 'email'],
              ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'col' => 'half', 'maxLength' => 20, 'autocomplete' => 'tel', 'placeholder' => 'With country code if outside India'],
              ['name' => 'batch_year', 'label' => 'Batch (passing-out year)', 'type' => 'select', 'required' => true, 'col' => 'half', 'options' => $years, 'placeholder' => 'Select year'],
              ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'options' => $programOptions, 'placeholder' => 'Select your program'],
              ['name' => 'employment_status', 'label' => 'Current status', 'type' => 'select', 'required' => true, 'col' => 'half', 'options' => $statusOptions, 'value' => 'employed', 'placeholder' => 'Select status'],
              ['name' => 'company', 'label' => 'Company / Organisation', 'col' => 'half', 'maxLength' => 190, 'autocomplete' => 'organization', 'help' => 'Required if employed or running a business.'],
              ['name' => 'designation', 'label' => 'Designation', 'col' => 'half', 'maxLength' => 150, 'autocomplete' => 'organization-title'],
              ['name' => 'city', 'label' => 'City', 'required' => true, 'col' => 'half', 'maxLength' => 80, 'autocomplete' => 'address-level2'],
              ['name' => 'country', 'label' => 'Country', 'col' => 'half', 'maxLength' => 80, 'autocomplete' => 'country-name', 'value' => 'India'],
              ['name' => 'linkedin_url', 'label' => 'LinkedIn profile (optional)', 'col' => 'half', 'maxLength' => 255, 'placeholder' => 'https://www.linkedin.com/in/your-name',
               'pattern' => '^https://([a-z]{2,3}\\.)?linkedin\\.com/(in|pub)/.+', 'patternMessage' => 'Enter your LinkedIn profile link, e.g. https://www.linkedin.com/in/your-name'],
              ['name' => 'mentor', 'label' => 'I would like to mentor current GIMT students', 'type' => 'checkbox'],
          ],
      ]) ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Related -->
<?= section_open(['id' => 'explore', 'padding' => 'tight', 'label' => 'Explore more']) ?>
  <h2 class="sr-only">Explore more</h2>
  <div class="grid grid-cols-1 gap-4 md:grid-cols-3" data-stagger="80">
    <?= feature_tile('briefcase-business', 'Placements', 'Placement record, recruiters, training and how to hire from GIMT.', ['url' => '/placement', 'tint' => 'navy']) ?>
    <?= feature_tile('calendar-days', 'Campus Events', 'Fests, seminars, workshops and convocations on campus.', ['url' => '/events', 'tint' => 'orange']) ?>
    <?= feature_tile('newspaper', 'News & Stories', 'The latest from GIMT classrooms, labs and campus life.', ['url' => '/blog', 'tint' => 'sky']) ?>
  </div>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Inspire the Next Batch of GIMTians',
    'cta_text' => 'Know someone ready for their next step? Programs for the new session are open for applications.',
    'cta_highlight' => 'Inspire',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
