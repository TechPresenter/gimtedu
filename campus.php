<?php
/**
 * Campus Life (/campus) — facilities (image-zoom tiles with live counts), virtual campus tour (VideoLightbox when the
 * campus_tour_video_url setting is set), photo gallery (GalleryLightbox: CMS gallery items + bundled photos), hostel
 * & transport highlights (hostel / transport modules), clubs & societies, campus events (events + calendar),
 * testimonials (TestimonialSlider, only when the CMS testimonials table has active entries), CTA.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$counts = sa_counts();
$seats = sa_classroom_seats();
$library = sa_library_summary();
$hostel = sa_hostel_summary();
$transport = sa_transport_summary();
$spaces = sa_learning_spaces();
$gallery = sa_gallery(13);
$gallery = array_slice($gallery, 0, max(1, count($gallery) - ((count($gallery) + 3) % 4))); // full rows in the 4-column bento
$events = sa_events(4);
$testimonials = sa_testimonials(8);
$tour = trim((string) setting('campus_tour_video_url', ''));
$auditorium = current(array_filter($spaces, fn ($s) => $s['type'] === 'auditorium')) ?: null;

$facilities = [
    ['facility-classroom.jpg', 'Smart Classrooms', 'Air-conditioned classrooms with projectors and interactive panels for engaging, discussion-led lectures.',
        $counts['classrooms'] ? $counts['classrooms'] . ' classrooms' . ($seats ? ' · ' . number_format($seats) . ' seats' : '') : 'Projector-equipped rooms', 'presentation'],
    ['facility-labs.jpg', 'Advanced Laboratories', 'Computer, AI & data science and electronics labs with modern workstations and licensed software.',
        $counts['labs'] ? $counts['labs'] . ' specialised labs' : 'Modern workstations', 'cpu'],
    ['facility-library.jpg', 'Digital Library', 'Text and reference books, journals and e-resources in a quiet, air-conditioned reading hall.',
        $library ? number_format($library['titles']) . ' titles · ' . number_format($library['copies']) . ' volumes' : 'Books, journals & e-resources', 'library'],
    ['facility-hostel.jpg', 'Hostel Facilities', 'Separate, secure hostels for boys and girls with mess, Wi-Fi and round-the-clock support.',
        $hostel ? $hostel['count'] . ' hostels · ' . number_format($hostel['beds']) . ' beds' : 'Boys & girls hostels', 'bed-double'],
    ['facility-sports.jpg', 'Sports & Recreation', 'Courts for basketball, volleyball and badminton, indoor games and an annual inter-department sports meet.',
        'Indoor & outdoor sports', 'trophy'],
    ['facility-green-campus.jpg', 'Green & Safe Campus', 'Tree-lined lawns, CCTV coverage, 24×7 security and a ragging-free, inclusive environment.',
        $auditorium ? $auditorium['name'] . ' · ' . (int) $auditorium['capacity'] . ' seats' : '24×7 security', 'shield-check'],
];
$clubs = [
    ['code-xml', 'Coding Club', 'Hackathons, competitive programming and open-source sprints.', 'blue'],
    ['rocket', 'Entrepreneurship Cell', 'Startup bootcamps, pitch days and founder talks.', 'amber'],
    ['palette', 'Cultural Society', 'Dance, drama and the annual cultural fest.', 'rose'],
    ['trophy', 'Sports Club', 'Inter-college tournaments and fitness programmes.', 'green'],
    ['megaphone', 'Literary & Debating', 'Debates, MUNs, quizzing and the campus magazine.', 'violet'],
    ['image', 'Photography & Media', 'Campus photography, short films and social media.', 'cyan'],
    ['heart', 'NSS & Community Service', 'Literacy drives, blood donation and cleanliness campaigns.', 'rose'],
    ['briefcase-business', 'Finance & Marketing Club', 'Stock-market games, case competitions and brand challenges.', 'navy'],
];

$heroImg = 'assets/images/site/students-lawn.jpg';
site_header([
    'title' => 'Campus Life — Facilities, Hostel & Student Clubs',
    'description' => 'Campus life at GIMT Greater Noida: smart classrooms, labs, digital library' . ($hostel ? ', ' . $hostel['count'] . ' hostels' : ', hostels') . ($transport ? ', ' . $transport['routes'] . ' bus routes' : ', transport') . ', sports, student clubs, events and a green, safe campus.',
    'active' => 'campus',
    'seo' => ['route', '/campus'],
    'image' => $heroImg,
    'preload' => [asset($heroImg)],
    'schema' => [sa_breadcrumb_ld([['Home', ''], ['Campus Life', null]])],
]);

page_hero('Campus Life', 'Learn. Live. Belong.', ['Campus Life'], $heroImg,
    'A green, safe and vibrant campus in Knowledge Park, Greater Noida — with modern facilities, hostels, transport and a calendar full of clubs, fests and sports.',
    [
        ['icon' => 'presentation', 'label' => 'Smart Classrooms'],
        ['icon' => 'bed-double', 'label' => 'Hostel & Mess'],
        ['icon' => 'trophy', 'label' => 'Sports & Fitness'],
        ['icon' => 'party-popper', 'label' => 'Clubs & Events'],
    ],
    ['image_alt' => 'Students studying together on the GIMT campus lawn',
     'actions' => button_link('Explore Facilities', '#facilities', 'accent', ['shine' => true]) . ($tour !== '' ? '<a href="' . e($tour) . '" data-lightbox data-caption="GIMT Virtual Campus Tour" class="btn btn-outline-light">' . icon('circle-play', 'h-4 w-4') . 'Virtual Campus Tour</a>' : button_link('Photo Gallery', '#gallery', 'outline-light', ['icon' => 'images']))]);
?>

<!-- At a glance -->
<?= section_open(['id' => 'glance', 'padding' => 'tight']) ?>
  <h2 class="sr-only">Campus at a glance</h2>
  <?= stat_counter(array_values(array_filter([
      $counts['classrooms'] ? ['value' => $counts['classrooms'], 'label' => 'Smart classrooms', 'icon' => 'presentation'] : null,
      $counts['labs'] ? ['value' => $counts['labs'], 'label' => 'Specialised labs', 'icon' => 'cpu'] : null,
      $hostel ? ['value' => $hostel['beds'], 'label' => 'Hostel beds', 'icon' => 'bed-double'] : ['value' => $counts['students'], 'label' => 'Students on campus', 'icon' => 'users'],
      $transport ? ['value' => $transport['routes'], 'label' => 'Bus routes', 'icon' => 'bus'] : ($library ? ['value' => $library['copies'], 'label' => 'Library volumes', 'icon' => 'library'] : null),
  ])), ['variant' => 'cards']) ?>
<?= section_close() ?>

<!-- Facilities -->
<?= section_open(['id' => 'facilities', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('World-Class Facilities', 'Everything you need to learn, create, compete and unwind — on one green campus.', 'Our Campus', 'left', '<a href="' . e(site_url('academics#facilities')) . '" class="link-arrow">Labs &amp; library details' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
    <?php foreach ($facilities as [$img, $title, $text, $fact, $ic]): ?>
      <article class="group relative overflow-hidden rounded-2xl bg-brand-900 shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">
        <div class="img-zoom aspect-[16/9] overflow-hidden"><img src="<?= e(asset('assets/images/site/' . $img)) ?>" alt="<?= e($title . ' at GIMT') ?>" loading="lazy" decoding="async" class="h-full w-full object-cover"></div>
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
      <p class="section-lead"><?= $tour !== '' ? 'Walk through our academic blocks, labs, library, hostels and sports facilities in a short guided video.' : 'Walk through our academic blocks, labs, library, hostels and sports facilities with a student ambassador. Campus visits run Monday to Saturday.' ?></p>
      <ul class="mt-6 space-y-3 text-sm text-slate-600">
        <li class="flex gap-3"><?= icon('map-pin', 'mt-0.5 h-5 w-5 shrink-0 text-accent-600') ?><span><?= e(setting('address', 'Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh 201310, India')) ?></span></li>
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
          <div class="img-zoom overflow-hidden rounded-3xl shadow-pop"><img src="<?= e(asset('assets/images/site/campus-building.jpg')) ?>" alt="GIMT campus building in Knowledge Park" loading="lazy" decoding="async" width="1260" height="580" class="aspect-[16/9] w-full object-cover"></div>
          <span class="glass float-slow absolute -bottom-5 left-5 inline-flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold text-brand-900"><span class="live-dot pulse-ring" aria-hidden="true"></span>Campus visits open for <?= e(sa_session()['name'] ?? date('Y')) ?> admissions</span>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Gallery -->
<?= section_open(['id' => 'gallery', 'bg' => 'light', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Life on Campus', 'Moments from classrooms, labs, hostels, sports fields and festivals. Select a photo to view it full screen.', 'Photo Gallery') ?>
  <?= sa_gallery_grid($gallery, ['layout' => 'bento', 'columns' => 4, 'ariaLabel' => 'GIMT campus photo gallery']) ?>
<?= section_close() ?>

<!-- Hostel & transport -->
<?= section_open(['id' => 'hostel', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Hostel & Transport', 'A home away from home, and a safe ride to campus from across Delhi NCR.', 'Living at GIMT') ?>
  <div class="grid gap-6 lg:grid-cols-2">
    <article class="group overflow-hidden rounded-3xl border border-slate-200/80 bg-white shadow-card"<?= reveal_attr('slide-left') ?>>
      <div class="img-zoom relative aspect-[16/7] overflow-hidden"><img src="<?= e(asset('assets/images/site/campus-hostel.jpg')) ?>" alt="GIMT hostel building" loading="lazy" decoding="async" class="h-full w-full object-cover">
        <?php if ($hostel && $hostel['fee_from']): ?><span class="glass absolute bottom-4 left-4 rounded-xl px-3 py-2 text-sm font-semibold text-brand-900">From <?= e(money($hostel['fee_from'])) ?> / year</span><?php endif; ?>
      </div>
      <div class="p-6 sm:p-7">
        <h3 class="flex items-center gap-2 font-display text-xl font-extrabold text-brand-900"><?= icon('bed-double', 'h-5 w-5 text-accent-600') ?>Hostel &amp; Mess</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">Separate hostels for boys and girls with resident wardens, nutritious mess food, study areas and 24×7 security.</p>
        <?php if ($hostel): ?>
          <ul class="mt-5 grid grid-cols-3 gap-3 text-center">
            <li class="rounded-xl bg-slate-50 p-3"><span class="block font-display text-xl font-extrabold text-brand-900"><?= (int) $hostel['count'] ?></span><span class="text-xs text-slate-500">Hostels</span></li>
            <li class="rounded-xl bg-slate-50 p-3"><span class="block font-display text-xl font-extrabold text-brand-900" data-count="<?= (int) $hostel['rooms'] ?>"><?= number_format($hostel['rooms']) ?></span><span class="text-xs text-slate-500">Rooms</span></li>
            <li class="rounded-xl bg-slate-50 p-3"><span class="block font-display text-xl font-extrabold text-brand-900" data-count="<?= (int) $hostel['beds'] ?>"><?= number_format($hostel['beds']) ?></span><span class="text-xs text-slate-500">Beds</span></li>
          </ul>
          <?php if ($hostel['amenities']): ?>
            <ul class="mt-5 flex flex-wrap gap-2">
              <?php foreach ($hostel['amenities'] as $a): ?><li class="inline-flex items-center gap-1.5 rounded-full bg-accent-50 px-3 py-1 text-xs font-semibold text-accent-800"><?= icon('check', 'h-3.5 w-3.5') ?><?= e($a) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <ul class="mt-5 divide-y divide-slate-100 text-sm">
            <?php foreach ($hostel['hostels'] as $h): ?>
              <li class="flex items-center justify-between gap-3 py-2.5"><span class="font-semibold text-slate-800"><?= e($h['name']) ?></span><span class="badge <?= $h['type'] === 'girls' ? 'badge-purple' : 'badge-blue' ?>"><?= e(ucfirst($h['type'])) ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php if (!empty($hostel['hostels'][0]['visiting_hours'])): ?><p class="mt-3 flex items-center gap-2 text-xs text-slate-500"><?= icon('clock', 'h-3.5 w-3.5') ?>Visiting hours: <?= e((string) $hostel['hostels'][0]['visiting_hours']) ?></p><?php endif; ?>
        <?php else: ?>
          <?= check_list(['Separate boys and girls hostels', 'Hygienic mess with balanced meals', 'Wi-Fi, laundry and power backup', 'Resident wardens and CCTV security'], 'mt-5 text-sm') ?>
        <?php endif; ?>
      </div>
    </article>
    <article id="transport" class="relative scroll-mt-24 overflow-hidden rounded-3xl bg-brand-900 p-6 text-white shadow-soft sm:p-7"<?= reveal_attr('slide-right', 100) ?>>
      <div class="pattern-layer pattern-grid-light pattern-fade animate-pattern-drift" aria-hidden="true"></div>
      <div class="blob -right-16 -top-16 h-56 w-56 bg-accent-500/25" aria-hidden="true"></div>
      <div class="relative">
        <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-accent-300 ring-1 ring-white/15"><?= icon('bus', 'h-7 w-7') ?></span>
        <h3 class="mt-4 font-display text-xl font-extrabold text-white">Campus Transport</h3>
        <p class="mt-2 text-sm leading-relaxed text-white/75">GPS-ready college buses with trained drivers connect Delhi, Noida, Greater Noida and Ghaziabad to the campus every working day.</p>
        <?php if ($transport): ?>
          <ul class="mt-6 grid grid-cols-3 gap-3 text-center">
            <li class="rounded-xl bg-white/10 p-3 ring-1 ring-white/10"><span class="block font-display text-2xl font-extrabold" data-count="<?= (int) $transport['routes'] ?>"><?= (int) $transport['routes'] ?></span><span class="text-xs text-white/70">Routes</span></li>
            <li class="rounded-xl bg-white/10 p-3 ring-1 ring-white/10"><span class="block font-display text-2xl font-extrabold" data-count="<?= (int) $transport['vehicles'] ?>"><?= (int) $transport['vehicles'] ?></span><span class="text-xs text-white/70">Buses</span></li>
            <li class="rounded-xl bg-white/10 p-3 ring-1 ring-white/10"><span class="block font-display text-2xl font-extrabold" data-count="<?= (int) $transport['stops'] ?>"><?= (int) $transport['stops'] ?></span><span class="text-xs text-white/70">Pick-up stops</span></li>
          </ul>
          <?php if ($transport['areas']): ?>
            <h4 class="mt-6 font-sans text-xs font-bold uppercase tracking-wider text-white/60">Areas covered</h4>
            <ul class="mt-3 flex flex-wrap gap-2">
              <?php foreach ($transport['areas'] as $a): ?><li class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-white ring-1 ring-white/10"><?= icon('map-pin', 'h-3.5 w-3.5 text-accent-300') ?><?= e($a) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <dl class="mt-6 grid gap-3 text-sm sm:grid-cols-2">
            <?php if ($transport['first_pickup']): ?><div class="flex items-center gap-2"><?= icon('clock', 'h-4 w-4 text-accent-300') ?><dt class="text-white/70">First pick-up</dt><dd class="font-semibold"><?= e(format_time($transport['first_pickup'])) ?></dd></div><?php endif; ?>
            <?php if ($transport['fare_min']): ?><div class="flex items-center gap-2"><?= icon('wallet', 'h-4 w-4 text-accent-300') ?><dt class="text-white/70">Annual fare</dt><dd class="font-semibold"><?= e(money($transport['fare_min'])) ?><?= $transport['fare_max'] > $transport['fare_min'] ? '–' . e(money($transport['fare_max'])) : '' ?></dd></div><?php endif; ?>
          </dl>
        <?php else: ?>
          <?= check_list(['Routes across Delhi, Noida, Greater Noida and Ghaziabad', 'Trained drivers and attendants', 'Morning pick-up and evening drop'], 'mt-5 text-sm text-white/85') ?>
        <?php endif; ?>
        <div class="mt-7"><?= button_link('Ask About Your Route', '/contact?type=general#message', 'accent', ['size' => 'sm']) ?></div>
      </div>
    </article>
  </div>
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
      <p class="section-lead">Tech fests, sports meets, cultural nights, guest lectures and industry conclaves fill the campus calendar.</p>
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
