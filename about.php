<?php
/**
 * About GIMT (/about) — "GIMT at a glance" (definitional facts), story + live stats, vision/mission/values, departments, why GIMT,
 * campus photo tiles (GalleryLightbox), approvals & affiliations, CTA (the leadership team is on /leadership).
 * Content: CMS page "about" (subtitle, banner, rich text) and "leadership" (people blocks) when published; settings
 * vision / mission / core_values / student_satisfaction; live counts from students, faculty, programs, departments.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$cms = sa_cms_page('about');
$counts = sa_counts();
$departments = sa_departments();
$heroImage = $cms['banner_image'] ?? null ?: 'assets/images/site/campus-building.jpg';
$story = sa_page_html($cms);
$tour = trim((string) setting('campus_tour_video_url', ''));
// no founding year anywhere unless Settings has one (owner decision 2026-11-17: "Founded in 2008" was wrong)
$established = $counts['established'];
$satisfaction = $counts['satisfaction'];
$glance = sa_glance_sentences();
// departments with their programs (links: department landing pages, /certifications or the single program)
$deptList = array_values(array_filter($departments, fn ($d) => !empty($d['programs'])));

$campusTiles = [
    // neutral facilities only (owner decision 2026-11-17: no hostels, sports grounds or green-campus claims)
    ['src' => asset('assets/images/site/facility-classroom.jpg'), 'alt' => 'Students in a GIMT classroom', 'caption' => 'Classrooms'],
    ['src' => asset('assets/images/site/facility-labs.jpg'), 'alt' => 'Students working in the computer lab', 'caption' => 'Computer Lab'],
    ['src' => asset('assets/images/site/facility-library.jpg'), 'alt' => 'Library with book stacks and reading tables', 'caption' => 'Library'],
];
$why = [
    ['map-pin', 'Sector 18, Noida', 'blue'], ['briefcase-business', 'Industry-Relevant Curriculum', 'green'],
    ['users', 'Faculty Mentorship', 'cyan'], ['microscope', 'Practical Learning', 'violet'],
    ['rocket', 'Career Guidance', 'amber'], ['award', 'Merit Scholarships', 'blue'],
    ['shield-check', 'Student Support', 'rose'], ['handshake', 'Placement Assistance', 'green'],
];

$heroText = $cms['excerpt'] ?? '' ?: 'Global Institute of Management & Technology (GIMT) is committed to providing high-quality, industry-oriented education with a focus on innovation, research and holistic development.';
site_header([
    'title' => 'About Us — Our Story, Vision & Mission',
    'description' => site_meta_description('About ' . institute_name() . ' (' . setting('institute_short_name', 'GIMT') . '), ' . setting('city', 'Noida') . ': ' . sa_offer_text() . '. Vision, mission, departments and why students choose GIMT.'),
    'active' => 'about',
    'seo' => ['route', '/about'],
    'image' => $heroImage,
    'preload' => [site_image($heroImage)],
    // AboutPage about the site organisation (@id: name, founding year, address, logo and profiles live on that entity)
    'schema' => array_values(array_filter([
        sa_breadcrumb_ld([['Home', ''], ['About Us', null]]),
        sa_seo_schema_has('AboutPage', 'route', null, '/about') ? null : ['@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About ' . setting('institute_short_name', 'GIMT'),
            'url' => absolute_url('about'), 'mainEntity' => seo_ld_org_ref()],
    ])),
]);

page_hero('About GIMT', $cms['subtitle'] ?? '' ?: 'Empowering Minds. Shaping Futures.', ['About Us'], $heroImage, $heroText, [
    ['icon' => 'graduation-cap', 'label' => 'Academic Excellence'],
    ['icon' => 'cog', 'label' => 'Industry-Focused Learning'],
    ['icon' => 'users', 'label' => 'Experienced Faculty'],
    ['icon' => 'award', 'label' => 'Merit Scholarships'],
], ['image_alt' => 'GIMT building in Sector 18, Noida']);
?>

<!-- GIMT at a glance (definitional facts from Settings + active programs + the owner's admission decisions) -->
<section class="relative z-10 mt-8 sm:mt-10" aria-labelledby="glance-title">
  <div class="container-site">
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-pop sm:p-8">
      <div class="grid gap-6 lg:grid-cols-12 lg:items-center">
        <div class="lg:col-span-8">
          <p class="eyebrow mb-2">At a glance</p>
          <h2 id="glance-title" class="font-display text-2xl font-extrabold text-brand-900"><?= e(setting('institute_short_name', 'GIMT')) ?> at a Glance</h2>
          <div class="mt-3 space-y-2 text-[15.5px] leading-relaxed text-slate-600"><?php foreach ($glance as $g): ?><p><?= e($g) ?></p><?php endforeach; ?></div>
        </div>
        <div class="flex flex-wrap gap-3 lg:col-span-4 lg:justify-end">
          <?= button_link('Explore Programs', '/programs', 'explore', ['size' => 'sm']) ?>
          <?= button_link('Apply Now', '/apply', 'apply', ['size' => 'sm']) ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Our story + live stats -->
<?= section_open(['id' => 'story', 'class' => 'overflow-hidden']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12 lg:gap-8">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Our Story</p>
      <h2 class="section-title">A Journey Towards Excellence</h2>
      <?php if ($story !== ''): ?>
        <div class="prose-site mt-4 max-w-none text-slate-600"><?= $story ?></div>
      <?php else: ?>
        <p class="section-lead">GIMT offers <?= e(sa_offer_text()) ?>. We combine academic rigour and practical learning to prepare our students for their careers.</p>
        <p class="mt-3 text-slate-600"><?= $counts['students'] ? e(number_format($counts['students'])) . ' students learn' : 'Students learn' ?> across <?= (int) $counts['programs'] ?> programs in <?= (int) $counts['departments'] ?> departments<?= $counts['faculty'] ? ', mentored by ' . (int) $counts['faculty'] . ' faculty members' . ($counts['phd'] ? ' — ' . (int) $counts['phd'] . ' of them doctorates' : '') : '' ?>.</p>
      <?php endif; ?>
      <div class="mt-7"><?= button_link('Our Departments', '#departments', 'navy', ['magnetic' => true]) ?></div>
    </div>
    <div class="lg:col-span-4"<?= reveal_attr('zoom-in', 100) ?>>
      <div class="rounded-3xl border border-slate-100 bg-white p-2 shadow-card sm:p-3 [&_.stat-cards]:gap-3 [&_.stat-cards]:border-0 [&_.stat-cards]:bg-transparent [&_.stat-cards]:px-3 [&_.stat-cards]:py-4 [&_.stat-cards]:shadow-none lg:[&_.stat-cards]:flex-col lg:[&_.stat-cards]:items-start xl:[&_.stat-cards]:flex-row xl:[&_.stat-cards]:items-center [&_.stat-icon]:h-11 [&_.stat-icon]:w-11 [&_.stat-icon]:rounded-xl [&_.stat-value]:text-[1.45rem] sm:[&_.stat-value]:text-[1.6rem] [&_.stat-label]:mt-1 [&_.stat-label]:text-[13px] [&_.stat-label]:leading-snug [&_.stat-group]:gap-0 [&_.stat-item:nth-child(-n+2)]:border-b [&_.stat-item:nth-child(odd)]:border-r [&_.stat-item]:border-slate-100">
        <?php
        // the 2x2 card grid keeps four cards; a figure of 0 (e.g. no students recorded yet) is never shown
        $lc = sa_level_counts();
        $statCards = array_values(array_filter([
            $counts['students'] > 0 ? ['value' => sa_round($counts['students'])[0], 'suffix' => sa_round($counts['students'])[1], 'label' => 'Students', 'icon' => 'users'] : null,
            $counts['faculty'] > 0 ? ['value' => sa_round($counts['faculty'])[0], 'suffix' => sa_round($counts['faculty'])[1], 'label' => 'Faculty Members', 'icon' => 'user-round'] : null,
            $counts['programs'] > 0 ? ['value' => $counts['programs'], 'label' => 'Programs', 'icon' => 'book-open'] : null,
            $counts['departments'] > 0 ? ['value' => (int) $counts['departments'], 'label' => 'Departments', 'icon' => 'school'] : null,
            $satisfaction !== null ? ['value' => $satisfaction, 'decimals' => fmod($satisfaction, 1.0) ? 1 : 0, 'suffix' => '%', 'label' => 'Student Satisfaction', 'icon' => 'trophy'] : null,
            $lc['UG'] + $lc['PG'] + $lc['Diploma'] > 0 ? ['value' => $lc['UG'] + $lc['PG'] + $lc['Diploma'], 'label' => 'Degree & Diploma Programs', 'icon' => 'graduation-cap'] : null,
            $lc['Certificate'] > 0 ? ['value' => $lc['Certificate'], 'label' => 'Certificate Courses', 'icon' => 'badge-check'] : null,
        ]));
        $statCards = array_slice($statCards, 0, count($statCards) >= 4 ? 4 : 2);
        ?>
        <?= $statCards ? stat_counter($statCards, ['variant' => 'cards', 'gridClass' => 'grid-cols-2']) : '' ?>
      </div>
    </div>
    <div class="relative lg:col-span-3"<?= reveal_attr('slide-right', 150) ?>>
      <div class="img-zoom overflow-hidden rounded-3xl shadow-pop">
        <img src="<?= e(asset('assets/images/site/students-laptop.jpg')) ?>" alt="GIMT students working together on a laptop" loading="lazy" decoding="async"<?= seo_ld_img_dims('assets/images/site/students-laptop.jpg') ?> class="aspect-[4/3] w-full object-cover lg:aspect-[3/4]">
      </div>
      <?php if ($established): ?><span class="glass float-slow absolute -bottom-5 left-4 hidden items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold text-brand-900 sm:inline-flex"><?= icon('calendar-check', 'h-5 w-5 text-accent-600') ?>Since <?= (int) $established ?></span><?php endif; ?>
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

<?php if ($deptList): ?>
<!-- Departments (replaces the founding-year timeline — owner decision 2026-11-17) -->
<?= section_open(['id' => 'departments', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Our Departments', count($deptList) . ' departments offering ' . sa_offer_text() . '.', 'Academics', 'left', '<a href="' . e(site_url('programs')) . '" class="link-arrow">All programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-stagger="70">
    <?php foreach ($deptList as $d): $n = count($d['programs']); ?>
      <li class="group relative flex h-full gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:shadow-card">
        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl tint-navy"><?= icon(sa_department_icon($d), 'h-6 w-6') ?></span>
        <span class="min-w-0">
          <?php if (!empty($d['url'])): ?><a href="<?= e($d['url']) ?>" class="stretched-link font-display text-[16px] font-bold leading-snug text-brand-900 group-hover:text-accent-800"><?= e($d['name']) ?></a><?php else: ?><span class="font-display text-[16px] font-bold leading-snug text-brand-900"><?= e($d['name']) ?></span><?php endif; ?>
          <span class="mt-1 block text-sm text-slate-600"><?= $n ?> program<?= $n === 1 ? '' : 's' ?>: <?= e(str_limit(implode(', ', array_map(fn ($p) => ($p['short_name'] ?? '') ?: $p['name'], $d['programs'])), 120)) ?></span>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
<?= section_close() ?>
<?php endif; ?>

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
      <p class="section-lead">Classes are held at <?= e(trim((string) setting('address', '')) ?: 'our centre in ' . setting('city', 'Noida')) ?>, with classrooms, a computer lab and a library.</p>
      <?php if ($counts['classrooms'] || $counts['labs']): ?>
        <ul class="mt-5 flex flex-wrap gap-2 text-sm">
          <?php if ($counts['classrooms']): ?><li class="chip !cursor-default"><?= icon('presentation', 'h-4 w-4 text-brand-600') ?><?= (int) $counts['classrooms'] ?> classrooms</li><?php endif; ?>
          <?php if ($counts['labs']): ?><li class="chip !cursor-default"><?= icon('cpu', 'h-4 w-4 text-brand-600') ?><?= (int) $counts['labs'] ?> labs</li><?php endif; ?>
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

<!-- Leadership: removed from the About page (owner request 2026-11-11); the leadership team is on /leadership -->

<!-- Approvals & affiliations (verified list from settings — the section is left out when none is entered) -->
<?php $approvals = sa_approvals(); $nAppr = count($approvals);
// literal Tailwind classes per count: grid-cols-1 grid-cols-2 sm:grid-cols-2 sm:grid-cols-3 lg:grid-cols-2 lg:grid-cols-3 lg:grid-cols-4 lg:grid-cols-5
$apprGrid = [1 => 'grid-cols-1 lg:grid-cols-2', 2 => 'grid-cols-2 lg:grid-cols-2', 3 => 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-3', 4 => 'grid-cols-2 lg:grid-cols-4'][$nAppr] ?? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5';
if ($approvals): ?>
<?= section_open(['id' => 'approvals', 'padding' => 'tight', 'class' => 'scroll-mt-24']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Our Approvals &amp; Affiliations</p>
      <h2 class="section-title">Recognized and Trusted</h2>
      <p class="section-lead">GIMT’s programs are offered with the approvals and university affiliation listed here.</p>
      <?php if ($aff = trim((string) setting('affiliation', ''))): ?><p class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-50 px-4 py-2 text-sm font-semibold text-brand-800"><?= icon('badge-check', 'h-4 w-4 text-accent-600') ?><?= e($aff) ?></p><?php endif; ?>
    </div>
    <ul class="grid gap-y-8 <?= $apprGrid ?> lg:col-span-8 lg:divide-x lg:divide-slate-200" data-stagger="80">
      <?php foreach ($approvals as $i => $a): ?><?= sa_emblem($a, $i) ?><?php endforeach; ?>
    </ul>
  </div>
<?= section_close() ?>
<?php endif; ?>

<?php site_footer([
    'cta_title' => 'Join GIMT and Be Part of a Global Community',
    'cta_text' => 'Take the next step towards a brighter future with industry-focused education and limitless opportunities.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Programs', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
