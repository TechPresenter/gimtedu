<?php
/**
 * About GIMT (/about) — story + live stats, vision/mission/values, journey (department milestones), why GIMT,
 * campus photo tiles (GalleryLightbox), leadership (+ heads of department), approvals & affiliations, CTA.
 * Content: CMS page "about" (subtitle, banner, rich text) and "leadership" (people blocks) when published; settings
 * vision / mission / core_values / student_satisfaction; live counts from students, faculty, programs, departments.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$cms = sa_cms_page('about');
$counts = sa_counts();
$departments = sa_departments();
$leaders = sa_leaders();
$heroImage = $cms['banner_image'] ?? null ?: 'assets/images/site/campus-building.jpg';
$story = sa_page_html($cms);
$tour = trim((string) setting('campus_tour_video_url', ''));
$established = $counts['established'] ?: 2008;
$satisfaction = $counts['satisfaction'];

// Milestones: founding year + the year each department was established (departments table) + today.
$milestones = [];
foreach ($departments as $d) {
    if ($d['established_year']) {
        $milestones[(int) $d['established_year']][] = $d['name'];
    }
}
ksort($milestones);
$steps = [];
$first = true;
foreach ($milestones as $year => $names) {
    $steps[] = ['title' => (string) $year, 'icon' => $first ? 'landmark' : 'school',
        'text' => ($first ? 'GIMT founded in Greater Noida with the ' : 'Launched the ') . implode(' and ', $names) . '.'];
    $first = false;
}
if (!$steps) {
    $steps[] = ['title' => (string) $established, 'icon' => 'landmark', 'text' => 'GIMT founded in Knowledge Park, Greater Noida.'];
}
$firstYear = $milestones ? array_key_first($milestones) : $established;
$firstCount = $milestones ? count($milestones[$firstYear]) : 0;
$journeyLead = 'Founded in ' . $firstYear . ($firstCount ? ' with ' . ($firstCount === 1 ? 'one department' : $firstCount . ' departments') : '')
    . ', GIMT has grown into a multi-disciplinary institute' . ($counts['departments'] > 1 ? ' of ' . $counts['departments'] . ' departments' : '')
    . ' — adding programs that answer what industry needs next.';
$sessionName = sa_session()['name'] ?? date('Y');
$steps[] = ['title' => 'Today · ' . $sessionName, 'icon' => 'trophy',
    'text' => number_format($counts['students']) . ' students learn across ' . $counts['programs'] . ' programs in ' . $counts['departments'] . ' departments.'];

$campusTiles = [
    ['src' => asset('assets/images/site/facility-classroom.jpg'), 'alt' => 'Smart classroom with interactive panels', 'caption' => 'Smart Classrooms'],
    ['src' => asset('assets/images/site/facility-labs.jpg'), 'alt' => 'Students in an advanced computer laboratory', 'caption' => 'Advanced Laboratories'],
    ['src' => asset('assets/images/site/facility-library.jpg'), 'alt' => 'Digital library with book stacks and reading tables', 'caption' => 'Digital Library'],
    ['src' => asset('assets/images/site/facility-hostel.jpg'), 'alt' => 'Hostel common room', 'caption' => 'Hostel Facilities'],
    ['src' => asset('assets/images/site/facility-sports.jpg'), 'alt' => 'Outdoor sports courts', 'caption' => 'Sports & Recreation'],
    ['src' => asset('assets/images/site/facility-green-campus.jpg'), 'alt' => 'Tree-lined green campus', 'caption' => 'Green & Safe Campus'],
];
$why = [
    ['building-2', 'Modern Infrastructure', 'blue'], ['briefcase-business', 'Industry-Relevant Curriculum', 'green'],
    ['users', 'Expert Faculty & Mentorship', 'cyan'], ['microscope', 'Practical Learning', 'violet'],
    ['rocket', 'Career Development', 'amber'], ['globe', 'Global Exposure', 'blue'],
    ['shield-check', 'Student Support', 'rose'], ['lightbulb', 'Innovation & Research', 'green'],
];
$hods = array_values(array_filter($departments, fn ($d) => !empty($d['hod_id'])));

$heroText = $cms['excerpt'] ?? '' ?: 'Global Institute of Management & Technology (GIMT) is committed to providing high-quality, industry-oriented education with a focus on innovation, research and holistic development.';
site_header([
    'title' => 'About Us — Our Story, Vision & Leadership',
    'description' => 'About Global Institute of Management & Technology (GIMT), Greater Noida — our story since ' . $established . ', vision and mission, leadership, campus, approvals and why students choose GIMT.',
    'active' => 'about',
    'seo' => ['route', '/about'],
    'image' => $heroImage,
    'preload' => [site_image($heroImage)],
    'schema' => array_values(array_filter([
        sa_breadcrumb_ld([['Home', ''], ['About Us', null]]),
        sa_seo_schema_has('AboutPage', 'route', null, '/about') ? null : ['@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About GIMT', 'url' => absolute_url('about'),
         'mainEntity' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'foundingDate' => (string) $established, 'url' => absolute_url(''),
             'numberOfEmployees' => ['@type' => 'QuantitativeValue', 'value' => $counts['faculty']]]],
    ])),
]);

page_hero('About GIMT', $cms['subtitle'] ?? '' ?: 'Empowering Minds. Shaping Futures.', ['About Us'], $heroImage, $heroText, [
    ['icon' => 'graduation-cap', 'label' => 'Academic Excellence'],
    ['icon' => 'cog', 'label' => 'Industry-Focused Learning'],
    ['icon' => 'users', 'label' => 'Experienced Faculty'],
    ['icon' => 'globe', 'label' => 'Global Opportunities'],
], ['image_alt' => 'GIMT campus building in Knowledge Park, Greater Noida']);
?>

<!-- Our story + live stats -->
<?= section_open(['id' => 'story', 'class' => 'overflow-hidden']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12 lg:gap-8">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Our Story</p>
      <h2 class="section-title">A Journey Towards Excellence</h2>
      <?php if ($story !== ''): ?>
        <div class="prose-site mt-4 max-w-none text-slate-600"><?= $story ?></div>
      <?php else: ?>
        <p class="section-lead">Established in <?= (int) $established ?> with a vision to create a world-class institution, GIMT has grown into a leading centre for management and technology education. We combine academic rigour, industry collaboration and practical learning to prepare our students for successful careers in a dynamic global environment.</p>
        <p class="mt-3 text-slate-600">Today <?= e(number_format($counts['students'])) ?> students learn across <?= (int) $counts['programs'] ?> programs in <?= (int) $counts['departments'] ?> departments, mentored by <?= (int) $counts['faculty'] ?> faculty members<?= $counts['phd'] ? ' — ' . (int) $counts['phd'] . ' of them doctorates' : '' ?>.</p>
      <?php endif; ?>
      <div class="mt-7"><?= button_link('Discover Our Journey', '#journey', 'navy', ['magnetic' => true]) ?></div>
    </div>
    <div class="lg:col-span-4"<?= reveal_attr('zoom-in', 100) ?>>
      <div class="rounded-3xl border border-slate-100 bg-white p-2 shadow-card sm:p-3 [&_.stat-cards]:gap-3 [&_.stat-cards]:border-0 [&_.stat-cards]:bg-transparent [&_.stat-cards]:px-3 [&_.stat-cards]:py-4 [&_.stat-cards]:shadow-none lg:[&_.stat-cards]:flex-col lg:[&_.stat-cards]:items-start xl:[&_.stat-cards]:flex-row xl:[&_.stat-cards]:items-center [&_.stat-icon]:h-11 [&_.stat-icon]:w-11 [&_.stat-icon]:rounded-xl [&_.stat-value]:text-[1.45rem] sm:[&_.stat-value]:text-[1.6rem] [&_.stat-label]:mt-1 [&_.stat-label]:text-[13px] [&_.stat-label]:leading-snug [&_.stat-group]:gap-0 [&_.stat-item:nth-child(-n+2)]:border-b [&_.stat-item:nth-child(odd)]:border-r [&_.stat-item]:border-slate-100">
        <?= stat_counter([
            ['value' => sa_round($counts['students'])[0], 'suffix' => sa_round($counts['students'])[1], 'label' => 'Students', 'icon' => 'users'],
            ['value' => sa_round($counts['faculty'])[0], 'suffix' => sa_round($counts['faculty'])[1], 'label' => 'Faculty Members', 'icon' => 'user-round'],
            ['value' => $counts['programs'], 'label' => 'Programs', 'icon' => 'book-open'],
            ['value' => $satisfaction, 'decimals' => fmod($satisfaction, 1.0) ? 1 : 0, 'suffix' => '%', 'label' => 'Student Satisfaction', 'icon' => 'trophy'],
        ], ['variant' => 'cards', 'gridClass' => 'grid-cols-2']) ?>
      </div>
    </div>
    <div class="relative lg:col-span-3"<?= reveal_attr('slide-right', 150) ?>>
      <div class="img-zoom overflow-hidden rounded-3xl shadow-pop">
        <img src="<?= e(asset('assets/images/site/students-lawn.jpg')) ?>" alt="GIMT students studying together on the campus lawn" loading="lazy" decoding="async" width="827" height="487" class="aspect-[4/3] w-full object-cover lg:aspect-[3/4]">
      </div>
      <span class="glass float-slow absolute -bottom-5 left-4 hidden items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold text-brand-900 sm:inline-flex"><?= icon('calendar-check', 'h-5 w-5 text-accent-600') ?>Since <?= (int) $established ?></span>
    </div>
  </div>
<?= section_close() ?>

<!-- Vision / Mission / Values -->
<?= section_open(['id' => 'vision', 'bg' => 'brand-soft', 'padding' => 'tight', 'class' => 'scroll-mt-24']) ?>
  <h2 class="sr-only">Vision, Mission and Values</h2>
  <div class="grid gap-5 md:grid-cols-3" data-stagger="90">
    <?php foreach (sa_vision_mission() as $vm): ?>
      <article id="<?= e($vm['id'] === 'vision' ? 'our-vision' : $vm['id']) ?>" class="group relative flex h-full gap-4 overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-6 shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">
        <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full tint-<?= e($vm['tint']) ?> transition duration-500 group-hover:scale-110"><?= icon($vm['icon'], 'h-7 w-7') ?></span>
        <div class="relative z-[1] min-w-0">
          <h3 class="font-display text-xl font-extrabold text-brand-900"><?= e($vm['title']) ?></h3>
          <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($vm['text']) ?></p>
        </div>
        <span class="pointer-events-none absolute -bottom-4 -right-3 text-brand-100 transition duration-700 group-hover:-translate-y-1 group-hover:rotate-6" aria-hidden="true"><?= icon($vm['deco'], 'h-24 w-24') ?></span>
      </article>
    <?php endforeach; ?>
  </div>
<?= section_close() ?>

<!-- Journey (department milestones) -->
<?= section_open(['id' => 'journey', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Milestones That Shaped GIMT', $journeyLead, 'Our Journey') ?>
  <?= progress_steps($steps, ['orientation' => count($steps) > 6 ? 'vertical' : 'auto', 'cta' => ['label' => 'Explore Our Programs', 'url' => '/programs']]) ?>
<?= section_close() ?>

<!-- Why GIMT -->
<section class="relative isolate overflow-hidden bg-slate-50/70" aria-labelledby="why-title">
  <div class="container-site lg:grid lg:grid-cols-12">
    <div class="py-16 sm:py-20 lg:col-span-7 lg:pr-10"<?= reveal_attr() ?>>
      <p class="eyebrow mb-2">Why GIMT</p>
      <h2 id="why-title" class="section-title">Education That Makes a Difference</h2>
      <p class="section-lead">We provide a holistic learning environment that prepares students for a successful career with a perfect blend of academic knowledge, practical exposure and industry connections.</p>
      <div class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-4" data-stagger="60">
        <?php foreach ($why as [$ic, $label, $tint]): ?><?= sa_icon_tile($ic, $label, $tint) ?><?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="relative min-h-[320px] overflow-hidden sm:min-h-[380px] lg:absolute lg:inset-y-0 lg:right-0 lg:min-h-0 lg:w-5/12"<?= reveal_attr('fade-in', 100) ?>>
    <img src="<?= e(asset('assets/images/site/students-atrium.jpg')) ?>" alt="Students walking through the GIMT atrium" loading="lazy" decoding="async" width="722" height="595" class="absolute inset-0 h-full w-full object-cover object-left">
    <div class="absolute inset-y-0 left-0 hidden w-24 bg-gradient-to-r from-slate-50 to-transparent lg:block" aria-hidden="true"></div>
    <span class="glass-dark absolute bottom-6 right-6 hidden rounded-2xl px-5 py-4 font-display text-lg font-extrabold leading-tight text-white sm:block">Learn · Grow<br><span class="text-accent-300">Innovate · Succeed</span></span>
  </div>
</section>

<!-- Our campus -->
<?= section_open(['id' => 'campus']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Our Campus</p>
      <h2 class="section-title">A Place to Learn, Grow and Thrive</h2>
      <p class="section-lead">Our modern campus is equipped with state-of-the-art classrooms, laboratories, library, hostels, sports facilities and more to provide a vibrant and inclusive learning environment.</p>
      <?php if ($counts['classrooms'] || $counts['labs']): ?>
        <ul class="mt-5 flex flex-wrap gap-2 text-sm">
          <?php if ($counts['classrooms']): ?><li class="chip !cursor-default"><?= icon('presentation', 'h-4 w-4 text-brand-600') ?><?= (int) $counts['classrooms'] ?> smart classrooms</li><?php endif; ?>
          <?php if ($counts['labs']): ?><li class="chip !cursor-default"><?= icon('cpu', 'h-4 w-4 text-brand-600') ?><?= (int) $counts['labs'] ?> specialised labs</li><?php endif; ?>
        </ul>
      <?php endif; ?>
      <div class="mt-7">
        <?php if ($tour !== ''): ?>
          <a href="<?= e($tour) ?>" data-lightbox data-caption="GIMT Virtual Campus Tour" class="btn btn-navy" data-magnetic="0.25"><?= icon('circle-play', 'h-4 w-4') ?>Take a Virtual Tour<?= icon('arrow-right', 'h-4 w-4') ?></a>
        <?php else: ?>
          <?= button_link('Explore Our Campus', '/campus', 'navy', ['magnetic' => true]) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="lg:col-span-8"<?= reveal_attr('fade-up', 100) ?>>
      <?= sa_gallery_grid($campusTiles, ['columns' => 3, 'ariaLabel' => 'GIMT campus facilities', 'class' => '[&_.gallery-tile]:aspect-[2/1]']) ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Leadership -->
<?= section_open(['id' => 'leadership', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Our Leadership</p>
      <h2 class="section-title">Guiding Towards a Brighter Future</h2>
      <p class="section-lead">Our leadership team is committed to academic excellence, innovation and the holistic development of our students.</p>
      <div class="mt-7"><?= button_link('View All Members', '/faculty', 'outline') ?></div>
    </div>
    <div class="grid gap-5 sm:grid-cols-3 lg:col-span-8" data-stagger="90">
      <?php foreach (array_slice($leaders, 0, 3) as $l): ?>
        <article class="group overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">
          <div class="img-zoom aspect-[2/1] overflow-hidden bg-slate-100 sm:aspect-[4/3] lg:aspect-[16/10]"><img src="<?= e($l['image']) ?>" alt="<?= e($l['name'] . ', ' . $l['role']) ?>" loading="lazy" decoding="async" class="h-full w-full object-cover object-top"></div>
          <div class="p-5">
            <h3 class="font-display text-base font-extrabold text-brand-900"><?= e($l['name']) ?></h3>
            <p class="text-sm font-semibold text-accent-800"><?= e($l['role']) ?></p>
            <?php if ($l['bio'] !== ''): ?><p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e(str_limit($l['bio'], 140)) ?></p><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (count($leaders) > 3): ?>
    <div class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70">
      <?php foreach (array_slice($leaders, 3) as $l): ?>
        <article class="flex items-center gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
          <img src="<?= e($l['image']) ?>" alt="<?= e($l['name']) ?>" loading="lazy" class="h-14 w-14 shrink-0 rounded-full object-cover">
          <div class="min-w-0"><h3 class="font-display text-sm font-bold text-brand-900"><?= e($l['name']) ?></h3><p class="text-xs font-semibold text-accent-800"><?= e($l['role']) ?></p></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($hods): ?>
    <div class="mt-14"<?= reveal_attr() ?>>
      <h3 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('school', 'h-5 w-5 text-accent-600') ?>Academic Leadership — Heads of Department</h3>
      <ul class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" data-stagger="60">
        <?php foreach ($hods as $d): $hn = sa_person_name($d, 'hod_'); ?>
          <li class="group relative flex items-center gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">
            <?= sa_avatar($d['hod_photo'], $hn, (int) $d['hod_id'], 'h-14 w-14 text-base') ?>
            <div class="min-w-0">
              <p class="font-display text-[15px] font-bold leading-snug text-brand-900"><?= e($hn) ?></p>
              <p class="text-xs font-semibold text-accent-800"><?= e($d['hod_designation']) ?></p>
              <a href="<?= e(site_url('academics#dept-' . strtolower($d['code']))) ?>" class="stretched-link mt-0.5 block truncate text-xs text-slate-500 group-hover:text-brand-700"><?= e($d['name']) ?></a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
<?= section_close() ?>

<!-- Approvals & affiliations -->
<?= section_open(['id' => 'approvals', 'padding' => 'tight', 'class' => 'scroll-mt-24']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Our Approvals &amp; Affiliations</p>
      <h2 class="section-title">Recognized and Trusted</h2>
      <p class="section-lead">GIMT is approved and affiliated with leading educational bodies, ensuring quality education and global recognition.</p>
      <?php if ($aff = trim((string) setting('affiliation', ''))): ?><p class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-800"><?= icon('badge-check', 'h-4 w-4 text-accent-600') ?><?= e($aff) ?></p><?php endif; ?>
    </div>
    <ul class="grid grid-cols-2 gap-y-8 sm:grid-cols-3 lg:col-span-8 lg:grid-cols-5 lg:divide-x lg:divide-slate-200" data-stagger="80">
      <?php foreach (sa_approvals() as $i => $a): ?><?= sa_emblem($a, $i) ?><?php endforeach; ?>
    </ul>
  </div>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Join GIMT and Be Part of a Global Community',
    'cta_text' => 'Take the next step towards a brighter future with industry-focused education and limitless opportunities.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Programs', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
