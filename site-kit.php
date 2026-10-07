<?php
/**
 * Website Kit — developer reference for the public website platform (React islands + CSS3 motion + PHP helpers).
 * Every island, vanilla data-attribute behaviour and CSS effect is demonstrated here with realistic GIMT content;
 * page builders copy from this file. Dev only (404 in production), noindex, linked from nowhere.
 * Docs: docs/WEBSITE.md
 */
require __DIR__ . '/includes/config.php';
if (!is_dev()) {
    http_response_code(404);
    if (is_file(__DIR__ . '/404.php')) {
        require __DIR__ . '/404.php';
    } else {
        echo 'Not found';
    }
    exit;
}

/* ---------------------------------------------------------------- demo data (DB first, realistic fallbacks) */
$programs = [];
try {
    $programs = db_all("SELECT * FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name");
} catch (Throwable $e) {
}
$banners = site_banners('home_hero');
$slides = $banners ? array_map(fn ($b) => [
    'image' => site_image($b['desktop_image'], 'assets/images/site/hero-campus-students.jpg'),
    'mobileImage' => $b['mobile_image'] ? site_image($b['mobile_image']) : null,
    'eyebrow' => 'Shaping Future Leaders', 'title' => $b['title'], 'highlight' => $b['highlight'], 'text' => $b['subheading'],
    'cta' => $b['cta_text'] ? ['label' => $b['cta_text'], 'url' => cms_link($b['cta_url'])] : null,
    'cta2' => $b['cta2_text'] ? ['label' => $b['cta2_text'], 'url' => cms_link($b['cta2_url'])] : null,
], $banners) : [
    ['image' => asset('assets/images/site/hero-campus-students.jpg'), 'eyebrow' => 'Shaping Future Leaders', 'title' => 'Shape Your Future.', 'highlight' => 'Build Your Legacy.',
     'text' => 'Global Institute of Management & Technology — empowering students with industry-focused education, technology, innovation and career opportunities.',
     'cta' => ['label' => 'Apply for Admission', 'url' => site_url('apply')], 'cta2' => ['label' => 'Explore Programs', 'url' => site_url('programs')]],
    ['image' => asset('assets/images/site/campus-main.jpg'), 'eyebrow' => 'Admissions 2026-27', 'title' => 'A Campus Built', 'highlight' => 'for Ambition.',
     'text' => 'Smart classrooms, advanced labs, a digital library and a green, safe campus in Knowledge Park, Greater Noida.',
     'cta' => ['label' => 'Take a Campus Tour', 'url' => site_url('campus')], 'cta2' => ['label' => 'Admission Process', 'url' => site_url('admissions')]],
    ['image' => asset('assets/images/site/placement-student.jpg'), 'eyebrow' => 'Placements', 'title' => 'Careers That', 'highlight' => 'Begin Here.',
     'text' => '150+ recruiters, dedicated training and placement cell, internships with leading companies across India.',
     'cta' => ['label' => 'View Placement Record', 'url' => site_url('placement')], 'cta2' => ['label' => 'Talk to a Counsellor', 'url' => site_url('contact')]],
];
$testimonials = [
    ['name' => 'Ananya Sharma', 'role' => 'Business Analyst', 'company' => 'Deloitte', 'program' => 'MBA, Batch of 2025', 'rating' => 5, 'photo' => asset('assets/images/site/leader-2.jpg'),
     'quote' => 'The case-study driven MBA curriculum and live projects with industry mentors gave me the confidence to crack my dream role. Faculty here genuinely care about your growth.'],
    ['name' => 'Rohit Verma', 'role' => 'Software Engineer', 'company' => 'Infosys', 'program' => 'B.Tech CSE, Batch of 2025', 'rating' => 5,
     'quote' => 'Hackathons, the coding club and the AI lab kept me building every semester. The placement cell prepared us for every round — from aptitude to technical interviews.'],
    ['name' => 'Priya Nair', 'role' => 'Digital Marketing Executive', 'company' => 'Amazon', 'program' => 'BBA, Batch of 2024', 'rating' => 4,
     'quote' => 'From the first week of orientation to the final placement drive, GIMT felt like a second home. The certifications I completed alongside my BBA made a real difference.'],
    ['name' => 'Mohammed Arif', 'role' => 'Data Analyst', 'company' => 'HCLTech', 'program' => 'BCA, Batch of 2025', 'rating' => 5,
     'quote' => 'Hands-on Python and Power BI projects, plus a supportive faculty, helped me land an analytics role straight out of college.'],
];
$recruiters = array_map(fn ($n) => ['name' => $n], ['TCS', 'Infosys', 'Wipro', 'HCLTech', 'Amazon', 'Deloitte', 'Capgemini', 'Microsoft', 'Accenture', 'Cognizant', 'ICICI Bank', 'HDFC Bank', 'KPMG', 'Tech Mahindra', 'Genpact', 'EY']);
$faqs = [
    ['q' => 'What is the admission process at GIMT?', 'a' => "Choose your program, submit the online application, upload your documents and pay the registration fee.\n\nOur admission team verifies your documents within 3 working days and shares the offer letter by email and SMS.", 'category' => 'Admissions'],
    ['q' => 'What are the eligibility criteria for UG and PG programs?', 'a' => 'UG programs require 10+2 or equivalent with a minimum of 50% marks (45% for reserved categories). PG programs require a recognised bachelor’s degree with at least 50% marks.', 'category' => 'Admissions'],
    ['q' => 'Do you offer scholarships?', 'a' => 'Yes. Merit scholarships of up to 50% of the tuition fee are available, along with sports, single-girl-child and economically weaker section scholarships.', 'category' => 'Fees'],
    ['q' => 'Can I pay the fee in instalments?', 'a' => 'Tuition fees can be paid semester-wise or in two instalments per year. Education-loan assistance is available through partner banks.', 'category' => 'Fees'],
    ['q' => 'Is hostel accommodation available?', 'a' => 'Separate, secure hostels for boys and girls with Wi-Fi, mess, laundry, gym and 24×7 security are available on campus.', 'category' => 'Campus'],
    ['q' => 'Can I visit the campus before admission?', 'a' => 'Absolutely. Campus visits run Monday to Saturday, 9 AM to 5 PM. Book a slot through the contact page or call our helpline.', 'category' => 'Campus'],
    ['q' => 'What is the placement record?', 'a' => '92% of eligible students from the 2025 batch were placed, with 150+ recruiters visiting the campus. The highest package was ₹18 LPA.', 'category' => 'Placements'],
    ['q' => 'How can I contact the admission office?', 'a' => 'Call +91 9955446477, email admissions@gimt.ac.in or use the enquiry form — a counsellor will call you back within 24 hours.', 'category' => 'Admissions'],
];
$events = [
    ['15 Oct', 'Industry Connect: AI in Business', 'Seminar Hall · 11:00 AM', 'Event', 'badge-blue'],
    ['22 Oct', 'Placement Drive — Capgemini', 'Placement Cell · 9:30 AM', 'Placement', 'badge-green'],
    ['05 Nov', 'Mid-Semester Examinations Begin', 'All departments', 'Examination', 'badge-amber'],
    ['14 Nov', 'Techvista 2026 — Annual Tech Fest', 'Main Auditorium · 2 days', 'Event', 'badge-blue'],
    ['28 Nov', 'Admissions Open: Spring Certificates', 'Admission Office', 'Admission', 'badge-purple'],
    ['10 Dec', 'Annual Sports Meet', 'Sports Complex · 8:00 AM', 'Event', 'badge-blue'],
];
$eventCards = array_map(fn ($ev) => '<article class="group relative flex gap-4 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm transition hover:border-brand-200 hover:shadow-card">'
    . '<span class="date-tile"><span class="date-tile-day">' . e(explode(' ', $ev[0])[0]) . '</span><span class="date-tile-mon">' . e(explode(' ', $ev[0])[1]) . '</span></span>'
    . '<span class="min-w-0"><span class="badge ' . $ev[4] . '">' . e($ev[3]) . '</span><a href="' . e(site_url('events')) . '" class="stretched-link mt-1 block font-semibold leading-snug text-brand-900">' . e($ev[1]) . '</a>'
    . '<span class="mt-1 flex items-center gap-1 text-xs text-slate-500">' . icon('map-pin', 'h-3.5 w-3.5') . e($ev[2]) . '</span></span></article>', $events);
$news = [
    ['GIMT signs MoU with NASSCOM FutureSkills', 'Students get free access to 40+ industry certifications in AI, cloud and cybersecurity.', 'assets/images/site/students-laptop.jpg', 'News · 02 Oct 2026'],
    ['Orientation 2026 welcomes 1,200 new students', 'A week of workshops, campus tours and mentor meet-ups kicked off the 2026-27 session.', 'assets/images/site/students-lawn.jpg', 'Campus · 18 Sep 2026'],
    ['MBA students win national case competition', 'Team Strategix bagged first place at the IIM Indore business case challenge.', 'assets/images/site/students-atrium.jpg', 'Achievement · 09 Sep 2026'],
    ['New AI & Data Science lab inaugurated', 'GPU workstations and an IoT bench for hands-on machine-learning projects.', 'assets/images/site/facility-labs.jpg', 'Infrastructure · 28 Aug 2026'],
    ['Placement season 2026 opens with 45 recruiters', 'Pre-placement talks from TCS, Deloitte and Amazon started this week.', 'assets/images/site/placement-student.jpg', 'Placements · 20 Aug 2026'],
];
$newsCards = array_map(fn ($n) => '<article class="card card-lift group flex h-full flex-col overflow-hidden"><div class="img-zoom aspect-[16/10]"><img src="' . e(asset($n[2])) . '" alt="" loading="lazy" class="h-full w-full object-cover"></div>'
    . '<div class="flex flex-1 flex-col p-5"><p class="text-xs font-semibold uppercase tracking-wider text-accent-600">' . e($n[3]) . '</p><h3 class="mt-1.5 text-lg font-bold leading-snug text-brand-900"><a href="' . e(site_url('blog')) . '" class="stretched-link">' . e($n[0]) . '</a></h3>'
    . '<p class="mt-2 text-sm text-slate-600">' . e($n[1]) . '</p><span class="link-arrow mt-auto pt-4">Read more' . icon('arrow-right', 'h-4 w-4') . '</span></div></article>', $news);
$why = [
    ['briefcase-business', 'Industry-Relevant Learning', 'Curriculum co-designed with recruiters, live projects every semester.', 'blue'],
    ['users', 'Expert Faculty & Mentorship', 'PhD faculty and industry mentors guiding every student.', 'green'],
    ['laptop', 'Digital Learning Resources', 'LMS, e-library and 24×7 access to recorded lectures.', 'cyan'],
    ['target', 'Career Preparation', 'Aptitude, soft-skills and interview training from year one.', 'amber'],
    ['microscope', 'Practical Exposure', 'Labs, industrial visits, internships and hackathons.', 'violet'],
    ['shield-check', 'Student Support', 'Counselling, scholarships and a dedicated student cell.', 'rose'],
    ['lightbulb', 'Innovation & Technology', 'Incubation centre, AI lab and startup mentoring.', 'blue'],
    ['trophy', 'Holistic Development', 'Clubs, sports, cultural fests and community service.', 'green'],
];
$gallery = [
    ['src' => asset('assets/images/site/campus-main.jpg'), 'alt' => 'GIMT main campus building', 'caption' => 'Main Campus', 'category' => 'Campus'],
    ['src' => asset('assets/images/site/facility-classroom.jpg'), 'alt' => 'Smart classroom', 'caption' => 'Smart Classrooms', 'category' => 'Facilities'],
    ['src' => asset('assets/images/site/facility-labs.jpg'), 'alt' => 'Computer laboratory', 'caption' => 'Advanced Laboratories', 'category' => 'Facilities'],
    ['src' => asset('assets/images/site/facility-library.jpg'), 'alt' => 'Digital library', 'caption' => 'Digital Library', 'category' => 'Facilities'],
    ['src' => asset('assets/images/site/facility-sports.jpg'), 'alt' => 'Sports complex', 'caption' => 'Sports & Recreation', 'category' => 'Student Life'],
    ['src' => asset('assets/images/site/facility-green-campus.jpg'), 'alt' => 'Green campus lawns', 'caption' => 'Green & Safe Campus', 'category' => 'Campus'],
    ['src' => asset('assets/images/site/students-atrium.jpg'), 'alt' => 'Students in the atrium', 'caption' => 'Student Atrium', 'category' => 'Student Life'],
];
$video = 'https://www.youtube.com/watch?v=ScMzIvxBSi4';
$enquiryEndpoint = base_url('api/public/enquiry');
$programOptions = array_map(fn ($p) => ['value' => $p['slug'], 'label' => $p['name']], $programs);

/** Small code sample block for the kit. */
$code = fn (string $src) => '<pre class="mt-4 overflow-x-auto rounded-xl bg-brand-950 p-4 text-[12.5px] leading-relaxed text-accent-200"><code>' . e($src) . '</code></pre>';
$kitHeading = fn (string $id, string $title, string $lead, string $eyebrow = 'Island') => '<div class="mb-8 max-w-3xl" id="' . e($id) . '"' . reveal_attr() . '><p class="eyebrow eyebrow-line mb-2">' . e($eyebrow) . '</p><h2 class="section-title">' . e($title) . '</h2><p class="section-lead">' . e($lead) . '</p></div>';

site_header([
    'title' => 'Website Kit',
    'description' => 'Developer reference for the GIMT website: React islands, CSS3 motion utilities and PHP render helpers.',
    'robots' => 'noindex,nofollow',
    'active' => 'site-kit',
    'preload' => [$slides[0]['image']],
]);

page_hero('Website Kit', 'Islands, motion & components for GIMT pages', ['Website Kit'], 'assets/images/site/students-atrium.jpg',
    'A living reference of every interactive island, CSS3 effect and PHP helper available to page builders. Server-rendered for SEO, progressively enhanced with React + TypeScript.',
    [['icon' => 'cpu', 'label' => 'React islands'], ['icon' => 'sparkles', 'label' => 'CSS3 motion library'], ['icon' => 'shield-check', 'label' => 'Accessible & reduced-motion aware'], ['icon' => 'rocket', 'label' => 'Lazy-loaded & fast']],
    ['eyebrow' => 'Developer reference · dev only', 'script' => 'Learn Grow Innovate Succeed',
     'actions' => button_link('Read docs/WEBSITE.md', '#islands', 'accent', ['shine' => true, 'magnetic' => true]) . button_link('CSS effects', '#effects', 'outline-light', ['icon' => ''])]);
?>

<!-- Table of contents -->
<nav class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/85 backdrop-blur" aria-label="Kit sections">
  <div class="container-site flex gap-2 overflow-x-auto py-3 scrollbar-none">
    <?php foreach (['hero' => 'HeroSlider', 'brand' => 'Brand & Typing', 'tiles' => 'Feature tiles', 'stats' => 'Stats', 'programs' => 'ProgramExplorer', 'carousel' => 'Carousel', 'steps' => 'ProgressSteps', 'testimonials' => 'Testimonials', 'recruiters' => 'LogoMarquee', 'autoscroll' => 'AutoScroll & Skeleton', 'faq' => 'Accordion', 'tabs' => 'Tabs', 'media' => 'Video & Gallery', 'forms' => 'Forms', 'effects' => 'CSS effects', 'cards' => 'Overlay cards'] as $id => $label): ?>
      <a href="#<?= $id ?>" class="chip shrink-0 !py-1.5 text-[13px]"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<!-- 1. Hero slider -->
<div id="islands"></div>
<?= section_open(['id' => 'hero', 'padding' => 'tight']) ?>
  <?= $kitHeading('hero-h', 'HeroSlider', 'Full-bleed slides from CMS banners (fallback demo slides): Ken-Burns images, overlay gradient, headline with highlight line, glass chips, campus-tour video, progress bullets, autoplay that pauses on hover/focus, swipe and ←/→ keys. Mounted eagerly; the fallback is the first slide.') ?>
<?= section_close() ?>
<?= hero_slider([
    'slides' => $slides,
    'chips' => [['icon' => 'briefcase-business', 'label' => 'Industry Focused'], ['icon' => 'users', 'label' => 'Expert Faculty'], ['icon' => 'building-2', 'label' => 'Modern Campus'], ['icon' => 'handshake', 'label' => 'Placement Support']],
    'stats' => [['value' => 10, 'suffix' => '+', 'label' => 'Programs', 'icon' => 'graduation-cap'], ['value' => 1000, 'suffix' => '+', 'label' => 'Students', 'icon' => 'users'], ['value' => 50, 'suffix' => '+', 'label' => 'Faculty', 'icon' => 'user-round'], ['value' => 95, 'suffix' => '%', 'label' => 'Student Satisfaction', 'icon' => 'shield-check']],
    'video' => ['url' => $video, 'label' => 'Watch Our Campus Tour', 'sublabel' => '2 min · Virtual walkthrough'],
    'tagline' => 'Admissions Open for 2026-27', 'taglineWords' => ['Learn', 'Grow', 'Innovate', 'Succeed'],
]) ?>
<?= section_open(['padding' => 'none']) ?>
  <?= $code("echo hero_slider(['slides' => \$slides, 'chips' => [...], 'stats' => [['value' => 1000, 'suffix' => '+', 'label' => 'Students', 'icon' => 'users']],\n    'video' => ['url' => 'https://www.youtube.com/watch?v=…', 'label' => 'Watch Our Campus Tour'], 'tagline' => 'Admissions Open for 2026-27']);") ?>
<?= section_close() ?>

<!-- 2. Brand, typography, TypingText -->
<?= section_open(['id' => 'brand']) ?>
  <?= $kitHeading('brand-h', 'Brand, typography & TypingText', 'Navy / royal blue / green palette (tailwind.preset.cjs), Plus Jakarta Sans headings, Inter body. TypingText rotates phrases with a caret (box sized to the longest phrase — no reflow).', 'Foundations') ?>
  <div class="grid gap-10 lg:grid-cols-2">
    <div class="min-w-0">
      <div class="grid grid-cols-3 gap-3 sm:grid-cols-6" data-stagger="50">
        <?php foreach (['brand-950' => '#071A3B', 'brand-900' => '#0B2A5B', 'brand-700' => '#183C7A', 'brand-500' => '#2F5FB0', 'accent-600' => '#22943F', 'accent-400' => '#45B86D'] as $n => $hex): ?>
          <div class="kit-swatch text-white" style="background:<?= $hex ?>"><span><?= $n ?></span><span class="opacity-70"><?= $hex ?></span></div>
        <?php endforeach; ?>
      </div>
      <p class="mt-8 font-display text-3xl font-extrabold leading-tight text-brand-900 sm:text-5xl">Shaping <span class="text-gradient">Future Leaders</span></p>
      <p class="mt-3 text-slate-600">Body copy in Inter — readable, neutral and fast (self-hosted WOFF2, preloaded).</p>
    </div>
    <div class="min-w-0 rounded-3xl border border-slate-100 bg-slate-50/80 p-6 sm:p-8"<?= reveal_attr('slide-right') ?>>
      <h3 class="font-display text-3xl font-extrabold leading-tight text-brand-900 sm:text-4xl">Build a career in<br><?= typing_text(['Management', 'Technology', 'Commerce', 'Data Science', 'Digital Marketing'], ['textClass' => 'text-gradient']) ?></h3>
      <p class="mt-4 flex items-center gap-3 text-sm text-slate-500">Typing indicator (CSS): <span class="typing-dots text-accent-600"><span></span><span></span><span></span></span></p>
      <?= $code("<h2>Build a career in <?= typing_text(['Management', 'Technology', 'Commerce'], ['textClass' => 'text-gradient']) ?></h2>") ?>
    </div>
  </div>
<?= section_close() ?>

<!-- 3. Feature tiles -->
<?= section_open(['id' => 'tiles', 'bg' => 'light', 'pattern' => 'dots']) ?>
  <?= section_heading('Why Choose GIMT', 'feature_tile() inside a data-stagger grid: children reveal one after another; icons lift and turn navy on hover.', 'PHP helper · feature_tile()', 'center') ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70">
    <?php foreach ($why as [$ic, $t, $d, $tint]): ?><?= feature_tile($ic, $t, $d, ['tint' => $tint]) ?><?php endforeach; ?>
  </div>
  <div class="mt-6 grid gap-4 sm:grid-cols-3" data-stagger="70">
    <?= feature_tile('award', 'Academic Excellence', '', ['variant' => 'compact', 'tint' => 'green']) ?>
    <?= feature_tile('building-2', 'Modern Infrastructure', '', ['variant' => 'compact', 'tint' => 'cyan']) ?>
    <?= feature_tile('rocket', 'Career Development', '', ['variant' => 'compact', 'tint' => 'amber']) ?>
  </div>
  <?= $code("<div class=\"grid gap-5 sm:grid-cols-2 lg:grid-cols-4\" data-stagger=\"70\">\n  <?= feature_tile('users', 'Expert Faculty & Mentorship', 'PhD faculty and industry mentors…', ['tint' => 'green']) ?>\n</div>") ?>
<?= section_close() ?>

<!-- 4. Stats -->
<?= section_open(['id' => 'stats']) ?>
  <?= $kitHeading('stats-h', 'StatCounter & stat_card()', 'Count-up numbers when scrolled into view (ease-out), with cards, progress rings or bars. stat_card() is the dependency-free version using data-count.') ?>
  <?= stat_counter([
      ['value' => 1248, 'label' => 'Students on campus', 'icon' => 'users'],
      ['value' => 52, 'suffix' => '+', 'label' => 'Faculty members', 'icon' => 'graduation-cap'],
      ['value' => 150, 'suffix' => '+', 'label' => 'Recruiting partners', 'icon' => 'handshake'],
      ['value' => 18, 'prefix' => '₹', 'suffix' => ' LPA', 'label' => 'Highest package 2025', 'icon' => 'trending-up'],
  ]) ?>
  <div class="mt-10 grid gap-10 lg:grid-cols-2">
    <?= stat_counter([
        ['value' => 92, 'suffix' => '%', 'label' => 'Placement rate', 'caption' => 'Batch of 2025'],
        ['value' => 95, 'suffix' => '%', 'label' => 'Student satisfaction', 'caption' => 'Annual survey'],
        ['value' => 4.6, 'decimals' => 1, 'suffix' => '/5', 'label' => 'Faculty rating', 'progress' => 92, 'caption' => 'Student feedback'],
    ], ['variant' => 'rings', 'gridClass' => 'grid-cols-3']) ?>
    <?= stat_counter([
        ['value' => 88, 'suffix' => '%', 'label' => 'Management', 'progress' => 88],
        ['value' => 94, 'suffix' => '%', 'label' => 'Technology', 'progress' => 94],
        ['value' => 81, 'suffix' => '%', 'label' => 'Commerce', 'progress' => 81],
        ['value' => 76, 'suffix' => '%', 'label' => 'Computer Applications', 'progress' => 76],
    ], ['variant' => 'bars', 'gridClass' => 'sm:grid-cols-2']) ?>
  </div>
  <div class="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-stagger="80">
    <?= stat_card(10, 'Programs', 'graduation-cap', ['suffix' => '+', 'tint' => 'blue']) ?>
    <?= stat_card(1000, 'Students', 'users', ['suffix' => '+', 'tint' => 'green']) ?>
    <?= stat_card(50, 'Faculty', 'user-round', ['suffix' => '+', 'tint' => 'cyan']) ?>
    <?= stat_card(95, 'Student Satisfaction', 'shield-check', ['suffix' => '%', 'tint' => 'amber']) ?>
  </div>
  <?= $code("echo stat_counter([['value' => 92, 'suffix' => '%', 'label' => 'Placement rate']], ['variant' => 'rings']);   // cards | rings | bars | inline\necho stat_card(1000, 'Students', 'users', ['suffix' => '+']);   // vanilla data-count") ?>
<?= section_close() ?>

<!-- 5. Program explorer -->
<?= section_open(['id' => 'programs', 'bg' => 'brand-soft']) ?>
  <?= section_heading('Explore Our Academic Programs', 'ProgramExplorer: category pills with counts + search over server-rendered program_card()s; cards glide to their new positions (FLIP) when filtering.', 'Island · ProgramExplorer') ?>
  <?= program_explorer($programs, ['viewAll' => ['label' => 'View All Programs', 'url' => '/programs'], 'syncParam' => 'category']) ?>
  <div class="mt-16">
    <h3 class="mb-5 font-display text-xl font-bold text-brand-900">Tiles layout (programs page)</h3>
    <?= program_explorer(array_slice($programs, 0, 6), ['layout' => 'tiles', 'search' => false]) ?>
  </div>
  <?= $code("echo program_explorer(\$programs, ['viewAll' => ['label' => 'View All Programs', 'url' => '/programs'], 'syncParam' => 'category']);\necho program_explorer(\$programs, ['layout' => 'tiles', 'search' => false]);") ?>
<?= section_close() ?>

<!-- 6. Carousel -->
<?= section_open(['id' => 'carousel']) ?>
  <?= section_heading('News & Events', 'Carousel: scroll-snap track (native touch swipe), mouse drag, arrows, dots, keyboard and optional autoplay. Slides are server-rendered HTML.', 'Island · Carousel', 'left', '<a href="' . e(site_url('blog')) . '" class="link-arrow">All news' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= carousel($newsCards, ['ariaLabel' => 'Latest news', 'autoplay' => 6000, 'perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3]]) ?>
  <div class="mt-14">
    <h3 class="mb-5 font-display text-xl font-bold text-brand-900">Program cards carousel</h3>
    <?= carousel(array_map(fn ($p) => program_card($p), array_slice($programs, 0, 8)), ['ariaLabel' => 'Featured programs', 'perView' => ['base' => 1.15, 'sm' => 2, 'lg' => 4], 'gap' => 20]) ?>
  </div>
  <?= $code("echo carousel(array_map(fn (\$p) => program_card(\$p), \$programs), ['perView' => ['base' => 1.15, 'sm' => 2, 'lg' => 4], 'autoplay' => 6000]);") ?>
<?= section_close() ?>

<!-- 7. Progress steps -->
<?= section_open(['id' => 'steps', 'bg' => 'light']) ?>
  <?= section_heading('The Admission Journey', 'ProgressSteps: the connector line draws across when visible (horizontal ≥ lg) or fills as you scroll (vertical on mobile); steps light up as the line reaches them.', 'Island · ProgressSteps') ?>
  <?= progress_steps([
      ['title' => 'Explore Programs', 'text' => 'Compare courses, eligibility and fees.', 'icon' => 'search'],
      ['title' => 'Submit Application', 'text' => 'Fill the online form in 10 minutes.', 'icon' => 'file-text'],
      ['title' => 'Upload Documents', 'text' => 'Marksheets, ID proof and photograph.', 'icon' => 'upload'],
      ['title' => 'Verification & Approval', 'text' => 'Documents verified in 3 working days.', 'icon' => 'clipboard-check'],
      ['title' => 'Enrollment & Fee Payment', 'text' => 'Pay online and receive your student ID.', 'icon' => 'wallet'],
  ], ['cta' => ['label' => 'Start Your Application', 'url' => '/apply']]) ?>
  <?= $code("echo progress_steps([['title' => 'Explore Programs', 'text' => '…', 'icon' => 'search'], …], ['cta' => ['label' => 'Start Your Application', 'url' => '/apply']]);") ?>
<?= section_close() ?>

<!-- 8. Testimonials (dark, animated gradient) -->
<?= section_open(['id' => 'testimonials', 'bg' => 'gradient', 'pattern' => 'grid']) ?>
  <div class="blob -right-20 top-10 -z-[1] h-80 w-80 bg-accent-500/20" aria-hidden="true"></div>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Island · TestimonialSlider</p>
      <h2 class="section-title">Student &amp; Alumni Success Stories</h2>
      <p class="section-lead">Direction-aware slide + fade, avatar navigation, swipe, autoplay progress line. On a .bg-animated-gradient section with an animated grid pattern and a morphing blob.</p>
    </div>
    <div class="lg:col-span-8"><?= testimonial_slider($testimonials, ['theme' => 'dark']) ?></div>
  </div>
  <?= $code("echo section_open(['bg' => 'gradient', 'pattern' => 'grid']);\necho testimonial_slider(\$items, ['theme' => 'dark', 'autoplay' => 7000]);") ?>
<?= section_close() ?>

<!-- 9. Recruiters -->
<?= section_open(['id' => 'recruiters']) ?>
  <?= section_heading('Our Recruiters', 'LogoMarquee: two rows in opposite directions, grayscale → colour on hover, pauses on hover/focus and off-screen, with a pause button. Wordmark placeholders when no logo image is supplied.', 'Island · LogoMarquee', 'center') ?>
  <?= logo_marquee($recruiters) ?>
  <div class="mt-10" data-marquee-host>
    <p class="mb-3 text-center text-sm font-semibold text-slate-500">Vanilla CSS marquee (<code>.marquee</code> + <code>data-marquee</code>) — no React</p>
    <div class="marquee marquee-fade" data-marquee="35"><div class="marquee-track">
      <?php foreach (['AICTE Approved', 'UGC Recognized', 'NAAC Accredited', 'ISO 9001:2015', 'Affiliated to State University', 'NIRF Participant'] as $b): ?>
        <span class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-brand-900"><?= icon('badge-check', 'h-4 w-4 text-accent-600') ?><?= e($b) ?></span>
      <?php endforeach; ?>
    </div></div>
  </div>
  <?= $code("echo logo_marquee([['name' => 'TCS'], ['name' => 'Infosys', 'logo' => site_image('…')], …], ['rows' => 2, 'speed' => 45]);") ?>
<?= section_close() ?>

<!-- 10. Auto-scroll + skeleton -->
<?= section_open(['id' => 'autoscroll', 'bg' => 'light']) ?>
  <div class="grid gap-10 lg:grid-cols-2">
    <div class="min-w-0">
      <?= $kitHeading('autoscroll-h', 'AutoScrollCards', 'Continuously scrolling events/notices (seamless loop, pauses on hover/focus, pause button; a normal scroll list for reduced motion).') ?>
      <?= auto_scroll_cards($eventCards, ['ariaLabel' => 'Upcoming events', 'height' => 400, 'speed' => 26]) ?>
    </div>
    <div>
      <?= $kitHeading('skeleton-h', 'SkeletonList', 'Shimmer placeholders that resolve into cards from a JSON endpoint (api_ok envelope), with empty and error states. Shown here without an endpoint (loading state).') ?>
      <?= skeleton_list(['variant' => 'event', 'count' => 4]) ?>
      <div class="mt-6 grid grid-cols-3 gap-3" aria-hidden="true"><span class="skeleton h-24 rounded-2xl"></span><span class="skeleton h-24 rounded-2xl"></span><span class="skeleton h-24 rounded-2xl"></span></div>
    </div>
  </div>
  <div class="mt-10"><?= auto_scroll_cards(array_slice($newsCards, 0, 4), ['direction' => 'horizontal', 'ariaLabel' => 'News ticker', 'speed' => 40]) ?></div>
  <?= $code("echo auto_scroll_cards(\$eventCardsHtml, ['direction' => 'vertical', 'height' => 400]);\necho skeleton_list(['endpoint' => base_url('api/public/news'), 'variant' => 'card', 'count' => 3, 'viewAll' => ['label' => 'All news', 'url' => '/blog']]);") ?>
<?= section_close() ?>

<!-- 11. FAQ -->
<?= section_open(['id' => 'faq']) ?>
  <?= section_heading('Frequently Asked Questions', 'Accordion: animated height (grid-rows), single/multi open, search with highlighting, category pills. Fallback = native <details> with the vanilla height animation.', 'Island · Accordion', 'left', '<a href="' . e(site_url('faq')) . '" class="link-arrow">View all FAQs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= faq_accordion($faqs, ['columns' => 2, 'search' => true, 'categories' => true]) ?>
  <div class="mt-12 max-w-2xl">
    <h3 class="mb-4 font-display text-xl font-bold text-brand-900">Vanilla &lt;details&gt; accordion (no React)</h3>
    <div class="space-y-3" data-accordion="single">
      <details class="acc-item" open><summary>Where is GIMT located?</summary><div><p>Plot No. 123, Knowledge Park, Greater Noida, Uttar Pradesh — 10 minutes from the Knowledge Park II metro station.</p></div></details>
      <details class="acc-item"><summary>Is transport available?</summary><div><p>College buses cover Noida, Greater Noida, Ghaziabad and East Delhi on 14 routes.</p></div></details>
    </div>
  </div>
  <?= $code("echo faq_accordion(\$faqs, ['columns' => 2, 'search' => true, 'categories' => true]);   // items: q, a, category\n<div data-accordion=\"single\"><details class=\"acc-item\"><summary>Q</summary><div><p>A</p></div></details></div>") ?>
<?= section_close() ?>

<!-- 12. Tabs -->
<?= section_open(['id' => 'tabs', 'bg' => 'light']) ?>
  <?= section_heading('Academic Calendar & Fees', 'Tabs: WAI-ARIA tabs with roving focus (←/→/Home/End), sliding indicator and fading panels. Panels are server-rendered HTML.', 'Island · Tabs') ?>
  <?php
  $feeTable = function (array $rows) {
      $h = '<div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="data-table"><thead><tr><th>Program</th><th>Duration</th><th class="text-right">Fee / year</th></tr></thead><tbody>';
      foreach ($rows as $p) {
          $h .= '<tr><td class="font-semibold text-brand-900">' . e($p['name']) . '</td><td>' . e($p['duration_label']) . '</td><td class="text-right">' . e(money($p['fee_per_year'])) . '</td></tr>';
      }
      return $h . '</tbody></table></div>';
  };
  $byLevel = fn (array $levels) => array_values(array_filter($programs, fn ($p) => in_array($p['level'], $levels, true)));
  echo content_tabs(
      [['key' => 'ug', 'label' => 'Undergraduate', 'icon' => 'graduation-cap'], ['key' => 'pg', 'label' => 'Postgraduate', 'icon' => 'school'], ['key' => 'cert', 'label' => 'Diploma & Certificate', 'icon' => 'award']],
      [$feeTable($byLevel(['UG'])), $feeTable($byLevel(['PG', 'PhD'])), $feeTable($byLevel(['Diploma', 'Certificate']))],
      ['syncHash' => false]
  );
  ?>
  <div class="mt-10 max-w-3xl">
    <?= content_tabs([['key' => 'vision', 'label' => 'Our Vision'], ['key' => 'mission', 'label' => 'Our Mission'], ['key' => 'values', 'label' => 'Our Values']], [
        '<p class="text-slate-600">To be a globally recognized institution for excellence in education and innovation.</p>',
        '<p class="text-slate-600">To empower students with knowledge, skills and values for a better future.</p>',
        '<p class="text-slate-600">Integrity, inclusivity, curiosity and service to society.</p>',
    ], ['variant' => 'underline']) ?>
  </div>
  <?= $code("echo content_tabs([['key' => 'ug', 'label' => 'Undergraduate', 'icon' => 'graduation-cap'], …], [\$ugHtml, \$pgHtml, …], ['variant' => 'pills']);") ?>
<?= section_close() ?>

<!-- 13. Media -->
<?= section_open(['id' => 'media']) ?>
  <?= $kitHeading('media-h', 'VideoLightbox & GalleryLightbox', 'Video trigger variants (thumb / button / chip) open an accessible modal (focus trap, Esc, privacy-friendly YouTube embed). Gallery: hover zoom, captions, lightbox with swipe, click-to-zoom with pointer panning and thumbnails.') ?>
  <div class="grid items-center gap-8 lg:grid-cols-2">
    <?= video_lightbox(['url' => $video, 'title' => 'GIMT Virtual Campus Tour', 'label' => 'Virtual Campus Tour', 'sublabel' => 'Explore our world-class facilities', 'poster' => asset('assets/images/site/campus-building.jpg')]) ?>
    <div class="space-y-6">
      <div><?= video_lightbox(['url' => $video, 'label' => 'Watch Our Campus Tour', 'sublabel' => '2 minutes', 'variant' => 'button']) ?></div>
      <div class="relative overflow-hidden rounded-2xl p-6" style="background:url('<?= e(asset('assets/images/site/students-lawn.jpg')) ?>') center/cover">
        <?= video_lightbox(['url' => $video, 'label' => 'Student life in 60 seconds', 'variant' => 'chip']) ?>
      </div>
      <p class="text-sm text-slate-600">Vanilla (no React): <a href="<?= e($video) ?>" data-lightbox class="link">open video with data-lightbox</a> · <a href="<?= e(asset('assets/images/site/campus-library.jpg')) ?>" data-lightbox="kit-photos" data-caption="Library & Learning Centre" class="link">image lightbox</a> · <a href="<?= e(asset('assets/images/site/campus-hostel.jpg')) ?>" data-lightbox="kit-photos" data-caption="Hostel facility" class="link">(gallery of 2)</a></p>
    </div>
  </div>
  <div class="mt-12"><?= gallery_grid($gallery, ['layout' => 'bento', 'columns' => 4, 'filter' => true]) ?></div>
  <?= $code("echo video_lightbox(['url' => 'https://www.youtube.com/watch?v=…', 'label' => 'Virtual Campus Tour', 'poster' => asset('assets/images/site/campus-building.jpg')]);\necho gallery_grid(\$photos, ['layout' => 'bento', 'columns' => 4, 'filter' => true]);   // items: src, thumb, alt, caption, category") ?>
<?= section_close() ?>

<!-- 14. Forms -->
<?= section_open(['id' => 'forms', 'bg' => 'brand-soft']) ?>
  <?= $kitHeading('forms-h', 'EnquiryForm', 'Inline validation mirroring the server rules, CSRF header, honeypot, loading spinner, server field errors and an animated success check. Posts to the endpoint prop (api/routes/public.php — next phase), so submitting here shows the error state until that endpoint exists.') ?>
  <div class="grid gap-8 lg:grid-cols-12">
    <div class="lg:col-span-5">
      <?= enquiry_form([
          'endpoint' => $enquiryEndpoint, 'theme' => 'dark', 'compact' => true, 'icon' => 'graduation-cap',
          'title' => 'Need Help Choosing a Program?', 'subtitle' => 'Get free guidance from our admission counsellors.', 'submitLabel' => 'Get Free Counselling',
          'hidden' => ['source' => 'site-kit', 'form' => 'counselling'],
          'fields' => [
              ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150, 'autocomplete' => 'name'],
              ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true, 'autocomplete' => 'tel'],
              ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email'],
              ['name' => 'program', 'label' => 'Program Interest', 'type' => 'select', 'options' => $programOptions],
          ],
      ]) ?>
    </div>
    <div class="lg:col-span-7">
      <?= enquiry_form([
          'endpoint' => base_url('api/public/contact'), 'theme' => 'light', 'title' => 'Send Us a Message', 'submitLabel' => 'Send Message',
          'consent' => 'I agree to the terms and allow GIMT to contact me about my enquiry.',
          'fields' => [
              ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'col' => 'half'],
              ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'col' => 'half'],
              ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'col' => 'half'],
              ['name' => 'type', 'label' => 'Enquiry Type', 'type' => 'select', 'required' => true, 'col' => 'half', 'options' => ['Admission Enquiry', 'Program Information', 'Scholarships', 'Campus Visit', 'Other']],
              ['name' => 'message', 'label' => 'Your Message', 'type' => 'textarea', 'required' => true, 'minLength' => 10, 'maxLength' => 2000],
          ],
      ]) ?>
    </div>
  </div>
  <?= $code("echo enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'theme' => 'dark', 'compact' => true, 'title' => 'Need Help Choosing a Program?',\n    'fields' => [['name' => 'name', 'label' => 'Full Name', 'required' => true], ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true], …]]);") ?>
<?= section_close() ?>

<!-- 15. CSS effects -->
<?= section_open(['id' => 'effects']) ?>
  <?= $kitHeading('effects-h', 'CSS3 effects & data attributes', 'Utilities in assets/css/src/style.css (+ -webkit- keyframes) and vanilla behaviours from the site bundle. All transform/opacity based and static for prefers-reduced-motion.', 'Motion library') ?>

  <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
    <!-- glass over image -->
    <div class="relative isolate flex min-h-[220px] items-end overflow-hidden rounded-2xl p-5"<?= reveal_attr('zoom-in') ?>>
      <img src="<?= e(asset('assets/images/site/campus-building.jpg')) ?>" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover" loading="lazy">
      <div class="glass w-full rounded-xl p-4"><p class="font-display font-bold text-brand-900">.glass</p><p class="text-xs text-slate-600">backdrop-blur + translucent + border</p></div>
    </div>
    <div class="relative isolate flex min-h-[220px] flex-col justify-end gap-3 overflow-hidden rounded-2xl p-5"<?= reveal_attr('zoom-in', 80) ?>>
      <img src="<?= e(asset('assets/images/site/students-lawn.jpg')) ?>" alt="" class="absolute inset-0 -z-10 h-full w-full object-cover" loading="lazy">
      <?= glass_chip('briefcase-business', '.glass-dark chip') ?> <?= glass_chip('sparkles', '.glass-light chip', 'light') ?>
    </div>
    <div class="bg-animated-gradient flex min-h-[220px] flex-col justify-end rounded-2xl p-5 text-white"<?= reveal_attr('zoom-in', 160) ?>>
      <p class="font-display text-lg font-bold">.bg-animated-gradient</p><p class="text-sm text-white/70">An oversized gradient layer slides (transform only).</p>
    </div>
    <div class="pattern-dots animate-pattern-drift flex min-h-[180px] flex-col justify-end rounded-2xl border border-slate-200 p-5"<?= reveal_attr() ?>><p class="font-display font-bold text-brand-900">.pattern-dots .animate-pattern-drift</p></div>
    <div class="pattern-grid animate-pattern-drift flex min-h-[180px] flex-col justify-end rounded-2xl border border-slate-200 p-5"<?= reveal_attr('fade-up', 80) ?>><p class="font-display font-bold text-brand-900">.pattern-grid</p></div>
    <div class="relative flex min-h-[180px] items-center justify-center gap-6 overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-5"<?= reveal_attr('fade-up', 160) ?>>
      <span class="blob left-6 top-6 h-24 w-24 bg-accent-400/50"></span>
      <span class="float-slow inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-900 text-white shadow-soft"><?= icon('graduation-cap', 'h-7 w-7') ?></span>
      <span class="float-rotate inline-flex h-12 w-12 items-center justify-center rounded-full bg-accent-600 text-white"><?= icon('sparkles', 'h-6 w-6') ?></span>
      <span class="spin-slow inline-flex h-10 w-10 items-center justify-center rounded-xl border-2 border-dashed border-brand-300"></span>
      <span class="absolute bottom-3 left-5 text-xs font-semibold text-slate-500">.blob · .float-slow · .float-rotate · .spin-slow</span>
    </div>
  </div>

  <div class="mt-10 grid gap-6 md:grid-cols-3">
    <a href="<?= e(site_url('campus')) ?>" class="group card card-lift overflow-hidden"<?= reveal_attr('fade-up') ?>>
      <div class="img-zoom overlay-gradient aspect-[4/3]"><img src="<?= e(asset('assets/images/site/facility-sports.jpg')) ?>" alt="" loading="lazy" class="h-full w-full object-cover"></div>
      <div class="p-5"><p class="font-display font-bold text-brand-900">.card-lift + .img-zoom + .overlay-gradient</p></div>
    </a>
    <div class="tilt-hover card flex flex-col justify-center p-6"<?= reveal_attr('fade-up', 80) ?>><p class="font-display font-bold text-brand-900">.tilt-hover</p><p class="mt-1 text-sm text-slate-500">CSS-only perspective tilt on hover.</p></div>
    <div class="card flex flex-col justify-center p-6" data-tilt="8"<?= reveal_attr('fade-up', 160) ?>><p class="font-display font-bold text-brand-900">[data-tilt="8"]</p><p class="mt-1 text-sm text-slate-500">Follows the pointer (fine pointers only).</p></div>
  </div>

  <div class="mt-10 flex flex-wrap items-center gap-4"<?= reveal_attr() ?>>
    <?= button_link('Magnetic + shine', '#effects', 'accent', ['magnetic' => true, 'shine' => true, 'size' => 'lg']) ?>
    <?= button_link('Ripple (.btn)', '#effects', 'primary', ['icon' => '']) ?>
    <?= button_link('Navy', '#effects', 'navy') ?>
    <?= button_link('Outline', '#effects', 'outline', ['icon' => '']) ?>
    <button type="button" class="btn btn-secondary" data-modal-open="#kit-modal"><?= icon('layers', 'h-4 w-4') ?>Open modal (data-modal-open)</button>
    <a href="#effects" class="underline-grow font-semibold text-brand-800">.underline-grow link</a>
    <a href="#effects" class="link-arrow">.link-arrow<?= icon('arrow-right', 'h-4 w-4') ?></a>
    <span class="inline-flex items-center gap-2 text-sm text-slate-600"><span class="pulse-ring inline-flex h-3 w-3 rounded-full bg-accent-500 text-accent-500"></span>.pulse-ring</span>
    <span class="glow inline-flex rounded-full bg-accent-600 px-4 py-1.5 text-sm font-semibold text-white">.glow</span>
    <span class="bounce-subtle inline-flex rounded-full bg-brand-50 px-4 py-1.5 text-sm font-semibold text-brand-800">.bounce-subtle</span>
    <span class="text-gradient font-display text-2xl font-extrabold">.text-gradient</span>
  </div>

  <div class="mt-12 grid gap-6 lg:grid-cols-2">
    <div class="relative h-72 overflow-hidden rounded-2xl"<?= reveal_attr('zoom-in') ?>>
      <img src="<?= e(asset('assets/images/site/campus-academic-block.jpg')) ?>" alt="Academic block" class="parallax-media" data-parallax="0.2" loading="lazy">
      <div class="absolute inset-0 bg-gradient-to-t from-brand-950/80 to-transparent"></div>
      <p class="absolute bottom-5 left-5 font-display text-lg font-bold text-white">[data-parallax="0.2"] on .parallax-media</p>
    </div>
    <div class="space-y-5 rounded-2xl border border-slate-200 p-6"<?= reveal_attr('zoom-in', 100) ?>>
      <p class="font-display font-bold text-brand-900">Counters, progress &amp; skeleton (vanilla)</p>
      <p class="font-display text-4xl font-extrabold text-brand-900"><span data-count="28.4" data-prefix="₹" data-suffix=" L" data-decimals="1">₹28.4 L</span> <span class="text-base font-medium text-slate-500">fees collected</span></p>
      <div><div class="mb-1.5 flex justify-between text-sm"><span>Seats filled — MBA</span><span class="font-semibold">86%</span></div><div class="progress-track"><span data-progress="86"></span></div></div>
      <div><div class="mb-1.5 flex justify-between text-sm"><span>Seats filled — B.Tech CSE</span><span class="font-semibold">72%</span></div><div class="progress-track"><span data-progress="72"></span></div></div>
      <div class="flex gap-3" aria-hidden="true"><span class="skeleton h-12 w-12 rounded-full"></span><span class="flex-1 space-y-2 pt-1"><span class="skeleton block h-3 w-2/3 rounded"></span><span class="skeleton block h-3 w-1/2 rounded"></span></span></div>
    </div>
  </div>

  <div class="mt-12">
    <p class="mb-4 font-display font-bold text-brand-900">Scroll-reveal variants (data-reveal)</p>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
      <?php foreach (['fade-up', 'fade-in', 'fade-down', 'zoom-in', 'slide-left', 'slide-right', 'blur-in'] as $i => $fx): ?>
        <div class="rounded-2xl bg-brand-50 p-5 text-center text-sm font-semibold text-brand-800"<?= reveal_attr($fx, $i * 60) ?>><?= e($fx) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?= $code("<div data-reveal=\"zoom-in\" data-reveal-delay=\"150\">…</div>     <div data-stagger=\"80\">children reveal in sequence</div>\n<img class=\"parallax-media\" data-parallax=\"0.2\">   <a class=\"btn btn-accent btn-shine\" data-magnetic>…</a>   <div data-tilt=\"8\">\n<span data-count=\"1248\" data-suffix=\"+\">1,248+</span>   <span data-progress=\"72\"></span>   <a href=\"video-or-image\" data-lightbox>") ?>
<?= section_close() ?>

<!-- 16. Overlay cards + CTA -->
<?= section_open(['id' => 'cards', 'bg' => 'light']) ?>
  <?= section_heading('Campus Life, Placements & News', 'overlay_card(): navy gradient overlay, image zoom on hover, arrow nudge.', 'PHP helper · overlay_card()') ?>
  <div class="grid gap-6 md:grid-cols-3" data-stagger="90">
    <?= overlay_card('Campus Life', 'Experience a vibrant and inclusive campus.', '/campus', 'assets/images/site/students-lawn.jpg') ?>
    <?= overlay_card('Placements', 'Strong industry connections and career opportunities.', '/placement', 'assets/images/site/placement-student.jpg', 'View Placement') ?>
    <?= overlay_card('News & Events', 'Stay updated with the latest news, events and happenings.', '/blog', 'assets/images/site/news-campus.jpg', 'View All') ?>
  </div>
  <div class="mt-12 grid items-center gap-10 lg:grid-cols-2">
    <div<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">About GIMT</p>
      <h2 class="section-title">Education That Makes a Difference</h2>
      <p class="section-lead">Global Institute of Management &amp; Technology is committed to high-quality, industry-oriented education with a focus on innovation, research and holistic development.</p>
      <?= check_list(['World-class academic programs', 'Modern infrastructure and learning environment', 'Experienced faculty and industry experts', 'Strong placement support and career guidance'], 'mt-6') ?>
      <div class="mt-7"><?= button_link('Discover GIMT', '/about', 'navy', ['magnetic' => true]) ?></div>
    </div>
    <div class="relative"<?= reveal_attr('slide-right') ?>>
      <div class="img-zoom overflow-hidden rounded-3xl shadow-pop"><img src="<?= e(asset('assets/images/site/campus-building.jpg')) ?>" alt="GIMT campus" loading="lazy" class="aspect-[4/3] w-full object-cover"></div>
      <div class="glass float-slow absolute -bottom-6 -left-4 hidden rounded-2xl p-4 sm:block"><p class="text-xs font-semibold uppercase tracking-wider text-accent-700">Our Vision</p><p class="mt-1 max-w-[14rem] text-sm text-brand-900">To be a globally recognized institution for excellence in education and innovation.</p></div>
    </div>
  </div>
  <?= $code("echo overlay_card('Placements', 'Strong industry connections…', '/placement', 'assets/images/site/placement-student.jpg', 'View Placement');\necho check_list(['World-class academic programs', …]);   echo cta_band([...]);   // the footer renders cta_band() by default") ?>
<?= section_close() ?>

<!-- Generic modal (vanilla data-modal) -->
<div id="kit-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="kit-modal-title" data-modal>
  <div class="modal-scrim" data-modal-close></div>
  <div class="modal-card">
    <h2 id="kit-modal-title" class="font-display text-xl font-bold text-brand-900">Book a campus visit</h2>
    <p class="mt-2 text-sm text-slate-600">Generic modal: <code>data-modal-open="#id"</code> on the trigger, <code>data-modal</code> on the dialog, <code>data-modal-close</code> to close. Focus is trapped; Esc closes.</p>
    <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-secondary" data-modal-close>Close</button><?= button_link('Contact us', '/contact', 'accent') ?></div>
  </div>
</div>

<?php site_footer(['cta_title' => 'Start Your Academic Journey at GIMT', 'cta_text' => 'Applications for the 2026-27 session are open. Seats are limited.']); ?>
