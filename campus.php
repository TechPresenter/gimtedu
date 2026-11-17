<?php
/**
 * Campus Life (/campus) — facilities (image-zoom tiles with live counts), virtual campus tour (VideoLightbox when the
 * campus_tour_video_url setting is set), photo gallery (GalleryLightbox: CMS gallery items + bundled photos), clubs &
 * societies, campus events (events + calendar). No hostel / transport / sports-ground / green-campus claims (owner
 * decision 2026-11-17: the institute works from Wave One, 36th Floor, Sector 18, Noida),
 * testimonials (TestimonialSlider, only when the CMS testimonials table has active entries), CTA.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$counts = sa_counts();
$seats = sa_classroom_seats();
$library = sa_library_summary();
$spaces = sa_learning_spaces();
$gallery = sa_gallery(13);
$gallery = array_slice($gallery, 0, max(1, count($gallery) - ((count($gallery) + 3) % 4))); // full rows in the 4-column bento
$events = sa_events(4);
$testimonials = sa_testimonials(8);
$tour = trim((string) setting('campus_tour_video_url', ''));

$facilities = [
    ['facility-classroom.jpg', 'Classrooms', 'Classrooms for lectures, discussions and presentations.',
        $counts['classrooms'] ? $counts['classrooms'] . ' classrooms' . ($seats ? ' · ' . number_format($seats) . ' seats' : '') : 'Lectures & discussions', 'presentation'],
    ['facility-labs.jpg', 'Computer Lab', 'Workstations for practical sessions in computers, data and office tools.',
        $counts['labs'] ? $counts['labs'] . ' lab' . ($counts['labs'] === 1 ? '' : 's') : 'Practical sessions', 'cpu'],
    ['facility-library.jpg', 'Library', 'Text and reference books for study and coursework.',
        $library ? number_format($library['titles']) . ' titles · ' . number_format($library['copies']) . ' volumes' : 'Books for study', 'library'],
];
$clubs = [
    ['code-xml', 'Coding Club', 'Hackathons, competitive programming and open-source sprints.', 'blue'],
    ['rocket', 'Entrepreneurship Cell', 'Startup bootcamps, pitch days and founder talks.', 'amber'],
    ['palette', 'Cultural Society', 'Dance, drama and the annual cultural fest.', 'rose'],
    ['megaphone', 'Literary & Debating', 'Debates, MUNs, quizzing and the campus magazine.', 'violet'],
    ['image', 'Photography & Media', 'Campus photography, short films and social media.', 'cyan'],
    ['heart', 'NSS & Community Service', 'Literacy drives, blood donation and cleanliness campaigns.', 'rose'],
    ['briefcase-business', 'Finance & Marketing Club', 'Stock-market games, case competitions and brand challenges.', 'navy'],
];

$heroImg = 'assets/images/site/students-atrium.jpg';
site_header([
    'title' => 'Campus Life — Classrooms, Library & Student Clubs',
    'description' => 'Campus life at GIMT, Sector 18, Noida: classrooms, a computer lab and library, student clubs and events. Book a visit during office hours.',
    'active' => 'campus',
    'seo' => ['route', '/campus'],
    'image' => $heroImg,
    'preload' => [asset($heroImg)],
    'schema' => [sa_breadcrumb_ld([['Home', ''], ['Campus Life', null]])],
]);

page_hero('Campus Life', 'Learn. Live. Belong.', ['Campus Life'], $heroImg,
    'Classes, clubs and events at ' . (trim((string) setting('address', '')) ?: 'GIMT, ' . setting('city', 'Noida')) . '.',
    [
        ['icon' => 'presentation', 'label' => 'Classrooms'],
        ['icon' => 'cpu', 'label' => 'Computer Lab'],
        ['icon' => 'library', 'label' => 'Library'],
        ['icon' => 'party-popper', 'label' => 'Clubs & Events'],
    ],
    ['image_alt' => 'GIMT students between classes',
     'actions' => button_link('Explore Facilities', '#facilities', 'accent', ['shine' => true]) . ($tour !== '' ? '<a href="' . e($tour) . '" data-lightbox data-caption="GIMT Virtual Campus Tour" class="btn btn-outline-light">' . icon('circle-play', 'h-4 w-4') . 'Virtual Campus Tour</a>' : button_link('Photo Gallery', '#gallery', 'outline-light', ['icon' => 'images']))]);
?>

<!-- At a glance -->
<?= section_open(['id' => 'glance', 'padding' => 'tight']) ?>
  <h2 class="sr-only">Campus at a glance</h2>
  <?php // figures of 0 (e.g. no students recorded yet) are never shown
  $glanceStats = array_values(array_filter([
      $counts['classrooms'] ? ['value' => $counts['classrooms'], 'label' => 'Classrooms', 'icon' => 'presentation'] : null,
      $counts['labs'] ? ['value' => $counts['labs'], 'label' => 'Labs', 'icon' => 'cpu'] : null,
      $counts['students'] > 0 ? ['value' => $counts['students'], 'label' => 'Students', 'icon' => 'users'] : null,
      $library && $library['copies'] > 0 ? ['value' => $library['copies'], 'label' => 'Library volumes', 'icon' => 'library'] : null,
  ])); ?>
  <?php // 4-digit figures fit two columns at 390 px (same sizing as the About page stat cards) ?>
  <div class="[&_.stat-value]:text-[1.45rem] sm:[&_.stat-value]:text-[1.6rem]"><?= $glanceStats ? stat_counter($glanceStats, ['variant' => 'cards']) : '' ?></div>
<?= section_close() ?>

<!-- Facilities -->
<?= section_open(['id' => 'facilities', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Facilities', 'Where you learn at GIMT, Sector 18, Noida.', 'Our Campus', 'left', '<a href="' . e(site_url('academics#facilities')) . '" class="link-arrow">Labs &amp; library details' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
    <?php foreach ($facilities as [$img, $title, $text, $fact, $ic]): ?>
      <article class="group relative overflow-hidden rounded-2xl bg-brand-900 shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">
        <div class="img-zoom aspect-[16/9] overflow-hidden"><img src="<?= e(asset('assets/images/site/' . $img)) ?>" alt="<?= e($title . ' at GIMT') ?>"<?= seo_ld_img_dims('assets/images/site/' . $img) ?> loading="lazy" decoding="async" class="h-full w-full object-cover"></div>
        <div class="relative p-5 text-white">
          <span class="absolute -top-6 right-5 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-accent-600 text-white shadow-soft ring-4 ring-brand-900 transition duration-500 group-hover:-rotate-6 group-hover:scale-105"><?= icon($ic, 'h-6 w-6') ?></span>
          <h3 class="font-display text-lg font-extrabold text-white"><?= e($title) ?></h3>
          <p class="mt-1.5 text-sm leading-relaxed text-white/75"><?= e($text) ?></p>
          <p class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-accent-300 ring-1 ring-white/15"><?= icon('circle-check', 'h-3.5 w-3.5') ?><?= e($fact) ?></p>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?= section_close() ?>

<!-- Virtual tour / campus visit -->
<?= section_open(['id' => 'tour', 'class' => 'scroll-mt-20']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2"><?= $tour !== '' ? 'Virtual Campus Tour' : 'Visit Us' ?></p>
      <h2 class="section-title"><?= $tour !== '' ? 'See GIMT Before You Arrive' : 'Experience the Campus in Person' ?></h2>
      <p class="section-lead"><?= $tour !== '' ? 'See our classrooms, computer lab and library in a short video.' : 'Visit us to see our classrooms, computer lab and library and to meet the admissions team. Visits run during office hours.' ?></p>
      <ul class="mt-6 space-y-3 text-sm text-slate-600">
        <li class="flex gap-3"><?= icon('map-pin', 'mt-0.5 h-5 w-5 shrink-0 text-accent-600') ?><span><?= e(setting('address', 'Wave One, 36th Floor, L-2A, Pocket G, Sector 18, Noida, Uttar Pradesh - 201301')) ?></span></li>
        <li class="flex gap-3"><?= icon('clock', 'mt-0.5 h-5 w-5 shrink-0 text-accent-600') ?><span><?= e(setting('office_hours', 'Mon - Sat, 9:00 AM - 6:00 PM')) ?></span></li>
      </ul>
      <div class="mt-7 flex flex-wrap gap-3">
        <?= button_link('Book a Campus Visit', '/contact?type=campus_visit#message', 'navy', ['magnetic' => true]) ?>
        <a href="tel:<?= e(preg_replace('/\s+/', '', (string) setting('phone', '+91 9955446477'))) ?>" class="btn btn-outline"><?= icon('phone', 'h-4 w-4') ?><?= e(setting('phone', '+91 9955446477')) ?></a>
      </div>
    </div>
    <div class="lg:col-span-7"<?= reveal_attr('zoom-in', 100) ?>>
      <?php if ($tour !== ''): ?>
        <?= video_lightbox(['url' => $tour, 'title' => 'GIMT Virtual Campus Tour', 'label' => 'Virtual Campus Tour', 'sublabel' => 'Explore our facilities', 'poster' => asset('assets/images/site/campus-building.jpg'), 'className' => 'rounded-3xl shadow-pop']) ?>
      <?php else: ?>
        <div class="relative">
          <div class="img-zoom overflow-hidden rounded-3xl shadow-pop"><img src="<?= e(asset('assets/images/site/campus-building.jpg')) ?>" alt="GIMT campus building in Noida" loading="lazy" decoding="async" width="1260" height="580" class="aspect-[16/9] w-full object-cover"></div>
          <span class="glass float-slow absolute -bottom-5 left-5 inline-flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold text-brand-900"><span class="live-dot pulse-ring" aria-hidden="true"></span>Campus visits open for <?= e(sa_session()['name'] ?? date('Y')) ?> admissions</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Gallery -->
<?= section_open(['id' => 'gallery', 'bg' => 'light', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Life at GIMT', 'Moments from classrooms, labs and events. Select a photo to view it full screen.', 'Photo Gallery') ?>
  <?= sa_gallery_grid($gallery, ['layout' => 'bento', 'columns' => 4, 'ariaLabel' => 'GIMT campus photo gallery']) ?>
<?= section_close() ?>

<!-- Clubs & societies -->
<?= section_open(['id' => 'clubs', 'bg' => 'brand-soft', 'pattern' => 'dots', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Clubs & Societies', 'Find your people. Student-run clubs host workshops, competitions and festivals all year round.', 'Beyond the Classroom', 'center') ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="60">
    <?php foreach ($clubs as [$ic, $t, $d, $tint]): ?><?= feature_tile($ic, $t, $d, ['tint' => $tint]) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>

<!-- Events -->
<?= section_open(['id' => 'events', 'class' => 'scroll-mt-20']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Events &amp; Festivals</p>
      <h2 class="section-title">Always Something Happening</h2>
      <p class="section-lead">Tech fests, cultural nights, guest lectures and workshops fill the calendar.</p>
      <div class="mt-7"><?= button_link('Full Academic Calendar', '/academics#calendar', 'outline', ['icon' => 'calendar-days']) ?></div>
    </div>
    <div class="lg:col-span-8">
      <?php if ($events): ?>
        <div class="grid gap-4 sm:grid-cols-2" data-stagger="70">
          <?php foreach ($events as $ev): ?><?= sa_event_card($ev) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-slate-50/60"><span class="empty-state-icon"><?= icon('calendar-days', 'h-6 w-6') ?></span><p class="mt-3 font-semibold text-brand-900">New events will be announced soon.</p><a href="<?= e(site_url('academics#calendar')) ?>" class="btn btn-secondary btn-sm mt-4">View Academic Calendar</a></div>
      <?php endif; ?>
    </div>
  </div>
<?= section_close() ?>

<?php if ($testimonials): ?>
<!-- Testimonials (CMS) -->
<?= section_open(['id' => 'stories', 'bg' => 'gradient', 'pattern' => 'grid']) ?>
  <div class="blob -right-20 top-10 -z-[1] h-80 w-80 bg-accent-500/20" aria-hidden="true"></div>
  <div class="mb-10 max-w-2xl"<?= reveal_attr('fade-up') ?>>
    <p class="eyebrow mb-2">Student Voices</p>
    <h2 class="section-title !text-white">Life at GIMT, in Their Words</h2>
    <p class="section-lead !text-white/75">Students, alumni and parents on the friendships, mentors and opportunities that shaped their journey.</p>
  </div>
  <?= testimonial_slider($testimonials, ['theme' => 'dark', 'ariaLabel' => 'Student testimonials']) ?>
<?= section_close() ?>
<?php endif; ?>

<?php site_footer([
    'cta_title' => 'Experience Campus Life at GIMT',
    'cta_text' => 'Visit the campus, meet our faculty and students, and see where your future begins.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Book a Campus Visit', 'url' => '/contact?type=campus_visit#message', 'icon' => 'map-pin'],
]);
