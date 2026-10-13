<?php
/**
 * Website Kit — developer reference for the public website platform ("Design v3": deep navy + orange-red + warm greys,
 * Plus Jakarta Sans / Inter; React islands + CSS3 motion + PHP helpers). Every token, component, island, floating
 * widget and motion utility is demonstrated with realistic GIMT content; page builders copy from this file.
 * Dev only (404 in production), noindex, linked from nowhere. Docs: docs/WEBSITE.md
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
$programs = site_programs();
$slides = [
    ['image' => asset('assets/images/site/hero-campus-students.jpg'), 'eyebrow' => 'Shaping Future Leaders', 'title' => 'Shape Your Future at GIMT', 'scribble' => 'Future',
     'text' => 'Industry-focused UG, PG, diploma and certificate programs in management, technology and commerce.',
     'cta' => ['label' => 'Apply for Admission', 'url' => site_url('apply')], 'cta2' => ['label' => 'Explore Courses', 'url' => site_url('programs')]],
    ['image' => asset('assets/images/site/campus-main.jpg'), 'eyebrow' => 'Admissions ' . (current_session()['name'] ?? '2026-27'), 'title' => 'A Campus Built for Ambition', 'scribble' => 'Ambition',
     'text' => 'Smart classrooms, labs, a digital library and a green campus in ' . setting('city', 'Greater Noida') . '.',
     'cta' => ['label' => 'Take a Campus Tour', 'url' => site_url('campus')], 'cta2' => ['label' => 'Admission Process', 'url' => site_url('admissions')]],
];
$stories = [
    ['name' => 'Ananya Sharma', 'program' => 'MBA', 'company' => 'Batch of 2025', 'photo' => asset('assets/images/site/leader-2.jpg'), 'quote' => 'Live projects with industry mentors gave me the confidence to crack my first role.'],
    ['name' => 'Rohit Verma', 'program' => 'B.Tech CSE', 'company' => 'Batch of 2025', 'quote' => 'Hackathons, the coding club and mock interviews kept me building every semester.'],
    ['name' => 'Priya Nair', 'program' => 'BBA', 'company' => 'Batch of 2024', 'quote' => 'From orientation to the final placement drive, GIMT felt like a second home.'],
    ['name' => 'Mohammed Arif', 'program' => 'BCA', 'company' => 'Batch of 2025', 'quote' => 'Hands-on Python and Power BI projects helped me land an analytics role.'],
];
// recruiter logos from the companies table (real logos first; a NULL logo renders the wordmark fallback)
$recruiters = [];
try {
    $recruiters = array_map(fn ($c) => ['name' => $c['name'], 'logo' => !empty($c['logo']) ? site_image($c['logo']) : null], db_all("SELECT name, logo FROM companies ORDER BY (logo IS NULL OR logo = ''), name LIMIT 12"));
} catch (Throwable $e) {
}
$recruiters = $recruiters ?: array_map(fn ($n) => ['name' => $n], ['ICICI Bank', 'HDFC Bank', 'Deloitte USI', 'Infosys', 'Wipro', 'TCS']);
$faqs = [
    ['q' => 'What is the admission process at GIMT?', 'a' => "Choose your program, submit the online application, upload your documents and pay the registration fee.\n\nThe admission team verifies documents and shares the offer letter by email.", 'category' => 'Admissions'],
    ['q' => 'Do you offer scholarships?', 'a' => 'Yes — merit, sports and other scholarships are available for eligible students (see Admissions › Scholarships).', 'category' => 'Fees'],
    ['q' => 'Can I visit the campus before admission?', 'a' => 'Campus visits run Monday to Saturday; book a slot through the contact page.', 'category' => 'Campus'],
    ['q' => 'Is hostel accommodation available?', 'a' => 'Separate hostels for boys and girls are available on campus.', 'category' => 'Campus'],
];
$gallery = [
    ['src' => asset('assets/images/site/campus-main.jpg'), 'alt' => 'GIMT main campus', 'caption' => 'Main Campus', 'category' => 'Campus'],
    ['src' => asset('assets/images/site/facility-classroom.jpg'), 'alt' => 'Smart classroom', 'caption' => 'Smart Classrooms', 'category' => 'Facilities'],
    ['src' => asset('assets/images/site/facility-labs.jpg'), 'alt' => 'Computer laboratory', 'caption' => 'Laboratories', 'category' => 'Facilities'],
    ['src' => asset('assets/images/site/facility-library.jpg'), 'alt' => 'Digital library', 'caption' => 'Digital Library', 'category' => 'Facilities'],
    ['src' => asset('assets/images/site/students-atrium.jpg'), 'alt' => 'Students in the atrium', 'caption' => 'Student Atrium', 'category' => 'Student Life'],
];
$video = 'https://www.youtube.com/watch?v=ScMzIvxBSi4';
$programOptions = array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name']], $programs);
$code = fn (string $src) => '<pre class="mt-5 overflow-x-auto rounded-2xl bg-brand-950 p-4 text-[12.5px] leading-relaxed text-accent-200"><code>' . e($src) . '</code></pre>';
$kitHeading = fn (string $id, string $title, string $lead, string $eyebrow = 'Island') => '<div class="mb-8 max-w-3xl" id="' . e($id) . '"><p class="eyebrow eyebrow-line mb-3">' . e($eyebrow) . '</p><h2 class="section-title" data-text-reveal>' . e($title) . '</h2><p class="section-lead">' . e($lead) . '</p></div>';

site_header([
    'title' => 'Website Kit', 'description' => 'Developer reference for the GIMT website (Design v3): tokens, CTA buttons, islands, motion and PHP helpers.',
    'robots' => 'noindex,nofollow', 'active' => 'site-kit', 'preload' => [asset('assets/images/site/students-atrium.jpg')],
    'admission_bar' => true,
]);
page_hero('Website Kit', 'Design v3 tokens, components & motion', ['Website Kit'], 'assets/images/site/students-atrium.jpg',
    'A living reference of every token, CTA button, island, floating widget and motion utility available to page builders. Server-rendered for SEO, progressively enhanced with React + TypeScript.',
    [['icon' => 'cpu', 'label' => 'React islands'], ['icon' => 'sparkles', 'label' => 'Restrained motion'], ['icon' => 'shield-check', 'label' => 'Accessible & reduced-motion aware']],
    ['eyebrow' => 'Developer reference · dev only', 'highlight' => 'Kit', 'script' => 'Learn Grow Lead',
     'actions' => button_link('Read docs/WEBSITE.md', '#tokens', 'apply', ['magnetic' => true]) . button_link('Motion effects', '#effects', 'outline', ['icon' => ''])]);
?>

<nav class="sticky top-[88px] z-30 border-y border-[#EDE8E2] bg-white/90 backdrop-blur" aria-label="Kit sections">
  <div class="container-site flex gap-2 overflow-x-auto py-3 scrollbar-none">
    <?php foreach (['tokens' => 'Tokens', 'buttons' => 'CTA buttons', 'headings' => 'Headings', 'cards' => 'Cards', 'stats' => 'Stats', 'hero' => 'HeroSlider', 'programs' => 'ProgramExplorer', 'carousel' => 'Carousel', 'stories' => 'Stories', 'faq' => 'FAQ & Tabs', 'forms' => 'Forms & lead card', 'views' => 'View modes', 'widgets' => 'Floating widgets', 'effects' => 'Motion', 'media' => 'Media'] as $id => $label): ?>
      <a href="#<?= $id ?>" class="chip shrink-0 !py-1.5 !text-[13px]"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</nav>

<!-- Tokens & typography -->
<?= section_open(['id' => 'tokens']) ?>
  <?= $kitHeading('tokens-h', 'Colour tokens & typography', 'CSS custom properties on :root (light map — a dark map can override them under [data-theme="dark"]) mirrored as Tailwind tokens: navy (brand-*), orange-red (accent-*), warm greys, body text. Headings Plus Jakarta Sans 700/800 with tight tracking, body Inter.', 'Foundations') ?>
  <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8" data-stagger="50">
    <?php foreach ([['--c-navy', '#0B2A5B', 'brand-900', true], ['--c-navy-deep', '#071A3B', 'brand-950', true], ['--c-flame', '#F05A28', 'accent-500 · vivid', true], ['--c-flame-fill', '#D03E16', 'accent-600 · fill', true], ['--c-flame-text', '#C93C14', 'links on white', true], ['--c-warm', '#F5F2EE', 'sections', false], ['--c-warm-2', '#EDE8E2', 'cards / borders', false], ['--c-body', '#4A5568', 'body text', true]] as [$var, $hex, $use, $dark]): ?>
      <div class="kit-swatch <?= $dark ? 'text-white' : 'text-brand-900' ?>" style="background:var(<?= $var ?>)"><span><?= e($var) ?></span><span class="opacity-75"><?= e($hex) ?> · <?= e($use) ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="mt-8 rounded-[22px] border border-[#EDE8E2] p-5">
    <p class="mb-3 text-sm font-bold text-brand-900">Colour map — levels &amp; departments (site_hues(), level_hue(), dept_hue(); TS: lib/hues.ts)</p>
    <div class="flex flex-wrap gap-2">
      <?php foreach (['UG' => 'Undergraduate', 'PG' => 'Postgraduate', 'Diploma' => 'Diploma', 'Certificate' => 'Certificate'] as $lv => $lbl): ?><span class="level-chip <?= level_hue($lv) ?>"><span class="hue-dot"></span><?= e($lbl) ?></span><?php endforeach; ?>
      <?php foreach (['mgt' => 'Management', 'tech' => 'Technology', 'ca' => 'Computer Applications', 'com' => 'Commerce', 'sci' => 'Science & Data', 'skill' => 'Professional & Skill', 'other' => 'Other'] as $k => $lbl): ?><span class="hue-chip hue-<?= $k ?>"><span class="hue-dot"></span><?= e($lbl) ?></span><?php endforeach; ?>
      <span class="tag-pill tag-popular">Most Popular</span><span class="tag-pill tag-featured">Featured</span>
    </div>
  </div>
  <div class="mt-10 grid gap-8 lg:grid-cols-2">
    <div>
      <p class="font-display text-5xl font-extrabold leading-[1.05] tracking-display text-brand-900 sm:text-6xl">Shaping <?= scribble('Future') ?> Leaders</p>
      <p class="mt-4 text-lg text-slate-600">Body copy in Inter — readable and neutral. <a href="#tokens" class="link">Orange-red links</a> use #C93C14-level contrast.</p>
    </div>
    <div class="card-soft p-6">
      <h3 class="font-display text-2xl font-extrabold text-brand-900">Build a career in <?= typing_text(['Management', 'Technology', 'Commerce', 'Data Science'], ['textClass' => 'text-accent-600']) ?></h3>
      <p class="mt-3 text-sm text-slate-600">Sticker tags: <?= sticker('Learn · Grow · Lead', ['color' => 'sun', 'rotate' => -6, 'size' => 'sm']) ?> <?= sticker('Future ready', ['color' => 'white', 'rotate' => 5, 'size' => 'sm']) ?></p>
    </div>
  </div>
  <?= $code("var(--c-navy) var(--c-flame) var(--c-warm) …   Tailwind: text-brand-900 bg-accent-600 bg-warm border-warm-2 text-body\n<h2 class=\"section-title\" data-text-reveal>Top <?= scribble('Programs') ?></h2>   sticker('Learn · Grow · Lead', ['color' => 'sun'])") ?>
<?= section_close() ?>

<!-- CTA buttons -->
<?= section_open(['id' => 'buttons', 'bg' => 'light']) ?>
  <?= $kitHeading('buttons-h', 'CTA button system', 'One consistent system site-wide: a second colour layer sweeps over every button on hover / focus / touch (apply → navy, explore → orange-red, brochure → navy fill, callback / white → orange-red), arrow nudge, 2px lift with a coloured shadow, light streak on primary CTAs, ripple, pressed, loading (pulsing overlay) and disabled states; WhatsApp CTAs (whatsapp_cta()) sweep to dark teal; optional idle glow for the main hero CTA (.btn-attention).', 'Components') ?>
  <div class="flex flex-wrap items-center gap-3">
    <?= button_link('Apply for Admission', '/apply', 'apply', ['magnetic' => true, 'shine' => true, 'attention' => true]) ?>
    <?= button_link('Explore Courses', '/programs', 'explore') ?>
    <?= button_link('Download Brochure', '/programs?brochure=1', 'brochure', ['icon_left' => 'download']) ?>
    <button type="button" class="btn btn-callback" data-modal-open="#enquiry-modal"><?= icon('phone', 'h-4 w-4') ?>Request a Callback</button>
    <?= button_link('Secondary', '#buttons', 'secondary', ['icon' => '']) ?>
    <?= whatsapp_cta() ?>
    <button type="button" class="btn btn-apply is-loading">Loading</button>
    <button type="button" class="btn btn-explore" disabled>Disabled</button>
  </div>
  <div class="mt-6 overflow-x-auto rounded-[22px] border border-[#EDE8E2] bg-white p-5">
    <table class="w-full min-w-[760px] text-left text-sm"><thead><tr class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500"><th class="pb-3">Variant</th><th class="pb-3">Normal</th><th class="pb-3">Hover (overlay)</th><th class="pb-3">Focus</th><th class="pb-3">Active</th><th class="pb-3">Loading</th><th class="pb-3">Disabled</th></tr></thead><tbody>
    <?php foreach (['apply' => 'Apply', 'explore' => 'Explore', 'brochure' => 'Brochure', 'callback' => 'Callback', 'whatsapp' => 'WhatsApp'] as $v => $l): $wa = $v === 'whatsapp' ? '<span class="btn-wa-icon">' . icon('whatsapp-brand', '') . '</span>' : ''; ?>
      <tr class="border-t border-[#EDE8E2]"><td class="py-3 font-mono text-xs">btn-<?= $v ?></td>
        <?php foreach (['', 'is-demo-hover', 'is-demo-focus', 'is-demo-active', 'is-loading'] as $st): ?><td class="py-3 pr-3"><span class="btn btn-sm btn-<?= $v ?> <?= $st ?>" aria-hidden="true"><?= $wa ?><?= $l ?><?= $wa ? '' : icon('arrow-right', 'h-4 w-4') ?></span></td><?php endforeach; ?>
        <td class="py-3"><button type="button" class="btn btn-sm btn-<?= $v ?>" disabled><?= $wa ?><?= $l ?></button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="mt-6 flex flex-wrap items-center gap-3 rounded-[22px] bg-brand-900 p-5">
    <?= button_link('Apply for Admission', '/apply', 'apply', ['size' => 'lg']) ?><?= button_link('Download Brochure', '#', 'outline-light', ['icon' => '', 'icon_left' => 'download', 'size' => 'lg']) ?><?= button_link('White', '#', 'white', ['icon' => '', 'size' => 'lg']) ?>
  </div>
  <div class="mt-6 flex flex-wrap gap-2.5"><button type="button" class="chip chip-active">Undergraduate</button><button type="button" class="chip">Postgraduate</button><button type="button" class="chip">Diploma &amp; Certificate</button><a href="#buttons" class="link-arrow">Link arrow<?= icon('arrow-right', 'h-4 w-4') ?></a></div>
  <?= $code("button_link('Apply for Admission', '/apply', 'apply')            // .btn-apply   primary filled orange-red\nbutton_link('Explore Courses', '/programs', 'explore')            // .btn-explore navy filled\nbutton_link('Download Brochure', \$url, 'brochure', ['icon_left' => 'download'])   // .btn-brochure navy outline\n<button class=\"btn btn-callback\" data-modal-open=\"#enquiry-modal\">Request a Callback</button>   // soft tint") ?>
<?= section_close() ?>

<!-- Headings + cards -->
<?= section_open(['id' => 'headings']) ?>
  <?= section_heading('Top Programs for 2026-27', 'section_heading() with a scribbled word, optional sticker and action link. Titles reveal line by line (text mask).', 'Section heading', 'left', button_link('View all', '/programs', 'brochure', ['size' => 'sm']), ['highlight' => 'Programs', 'sticker' => ['text' => 'Admissions open', 'color' => 'sun']]) ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" id="cards">
    <?php foreach (array_slice($programs, 0, 3) as $i => $p): ?><?= program_card($p, ['reveal' => 'fade-up', 'delay' => $i * 80]) ?><?php endforeach; ?>
  </div>
  <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70">
    <?= feature_tile('briefcase-business', 'Industry-aligned curriculum', 'Projects, case studies and industry interaction.') ?>
    <?= feature_tile('users', 'Experienced faculty', 'Mentors who guide academics and careers.', ['tint' => 'orange']) ?>
    <?= feature_tile('handshake', 'Placement cell', 'Aptitude, soft skills and campus drives.', ['variant' => 'warm']) ?>
    <?= feature_tile('building-2', 'Modern campus', 'Labs, library, hostel and sports.', ['tint' => 'orange', 'variant' => 'warm']) ?>
  </div>
  <div class="mt-10 grid gap-5 md:grid-cols-3">
    <?= pastel_card('Management Studies', 'BBA and MBA programs with specialisations.', ['color' => 'warm', 'button' => ['label' => 'View programs', 'url' => '/programs?category=management']]) ?>
    <?= pastel_card('Computer Applications', 'BCA, MCA and the DCA diploma.', ['color' => 'sky', 'button' => ['label' => 'View programs', 'url' => '/programs?category=technology']]) ?>
    <?= pastel_card('Scholarships', 'Merit and other scholarships for eligible students.', ['color' => 'navy', 'button' => ['label' => 'See scholarships', 'url' => '/admissions#scholarships']]) ?>
  </div>
  <div class="mt-10 grid gap-6 lg:grid-cols-2">
    <div><?= approvals_card() ?: '<p class="text-sm text-slate-500">approvals_card(): add approvals in Settings (approvals_list / affiliation).</p>' ?></div>
    <div class="grid grid-cols-2 gap-4 pt-3"><?php foreach (array_slice(site_approvals(), 0, 2) as $a): ?><?= approval_card($a) ?><?php endforeach; ?></div>
  </div>
  <div class="mt-10 grid gap-5 md:grid-cols-3" data-stagger="80">
    <?= overlay_card('Campus Life', 'Clubs, fests and sports on campus.', '/campus', 'assets/images/site/students-lawn.jpg') ?>
    <?= overlay_card('Placements', 'Training and campus recruitment drives.', '/placement', 'assets/images/site/placement-student.jpg', 'View placements') ?>
    <?= overlay_card('News & Events', 'Latest news from campus.', '/blog', 'assets/images/site/news-campus.jpg', 'View all') ?>
  </div>
  <?= $code("section_heading('Top Programs', 'Lead…', 'Eyebrow', 'left', \$actionHtml, ['highlight' => 'Programs', 'sticker' => ['text' => 'Admissions open', 'color' => 'sun']]);\nprogram_card(\$p, ['variant' => 'default'|'landing']);  feature_tile('users', 'Title', 'Text', ['tint' => 'navy'|'orange', 'variant' => 'card'|'warm'|'compact']);\npastel_card('Title', 'Text', ['color' => 'warm'|'navy'|'sky'…, 'button' => [...]]);  approvals_card();  approval_card(\$a);  overlay_card(…)") ?>
<?= section_close() ?>

<!-- Stats -->
<?= section_open(['id' => 'stats', 'bg' => 'ink']) ?>
  <?= section_heading('Our Impact', 'StatCounter "divided" variant on a navy band: bold Jakarta numbers with thin dividers (demo figures).', 'Island · StatCounter', 'left', '', ['highlight' => 'Impact', 'scribble' => 'light']) ?>
  <?= stat_counter([['value' => 12, 'label' => 'Programs'], ['value' => 980, 'label' => 'Approved intake'], ['value' => 176, 'label' => 'Placement offers on record'], ['value' => 25.8, 'decimals' => 1, 'suffix' => ' LPA', 'label' => 'Highest package']], ['variant' => 'divided', 'theme' => 'dark']) ?>
  <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><?= stat_card(12, 'Programs', 'graduation-cap', ['dark' => true, 'tint' => 'orange']) ?><?= stat_card(6, 'Departments', 'building-2', ['dark' => true]) ?></div>
  <?= $code("stat_counter(\$items, ['variant' => 'divided', 'theme' => 'dark']);   // cards | rings | bars | inline | divided\nstat_card(12, 'Programs', 'graduation-cap', ['tint' => 'orange']);") ?>
<?= section_close() ?>

<!-- Hero slider -->
<div id="hero"></div>
<?= hero_slider(['slides' => $slides, 'chips' => [['icon' => 'briefcase-business', 'label' => 'Industry focused'], ['icon' => 'users', 'label' => 'Expert faculty']],
    'stats' => [['value' => 12, 'label' => 'Programs'], ['value' => 6, 'label' => 'Departments'], ['value' => 980, 'label' => 'Approved intake'], ['value' => 176, 'label' => 'Offers on record']],
    'video' => ['url' => $video, 'label' => 'Watch our campus tour'], 'taglineWords' => ['Learn', 'Grow', 'Lead']]) ?>

<!-- Program explorer -->
<?= section_open(['id' => 'programs', 'bg' => 'light']) ?>
  <?= section_heading('Explore Our Programs', 'ProgramExplorer: navy active pill tabs + search over program_card()s (FLIP filtering).', 'Island · ProgramExplorer', 'left', '', ['highlight' => 'Programs']) ?>
  <?= program_explorer($programs, ['viewAll' => ['label' => 'View all programs', 'url' => '/programs'], 'categories' => array_slice(program_categories(), 0, 6)]) ?>
<?= section_close() ?>

<!-- Carousel -->
<?= section_open(['id' => 'carousel']) ?>
  <?= section_heading('Program carousel', 'Carousel island: scroll-snap track, round arrow buttons centred below, drag + keyboard.', 'Island · Carousel', 'left', '', ['highlight' => 'carousel']) ?>
  <?= carousel(array_map(fn ($p) => program_card($p, ['summary' => false]), array_slice($programs, 0, 8)), ['ariaLabel' => 'Programs', 'perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 4], 'gap' => 20]) ?>
  <div class="mt-12"><?= logo_marquee($recruiters, ['label' => 'Leading recruiters', 'rows' => 1]) ?></div>
<?= section_close() ?>

<!-- Stories -->
<?= section_open(['id' => 'stories', 'bg' => 'light']) ?>
  <?= section_heading('Real Stories, Real Impact', 'TestimonialSlider: photo (or initials) cards with program meta.', 'Island · TestimonialSlider', 'left', '', ['highlight' => 'Real Impact']) ?>
  <?= testimonial_slider($stories) ?>
  <div class="mt-14"><?= progress_steps([['title' => 'Explore Programs', 'icon' => 'search'], ['title' => 'Apply Online', 'icon' => 'file-text'], ['title' => 'Upload Documents', 'icon' => 'upload'], ['title' => 'Verification', 'icon' => 'clipboard-check'], ['title' => 'Enrol & Pay', 'icon' => 'wallet']], ['cta' => ['label' => 'Start your application', 'url' => '/apply']]) ?></div>
<?= section_close() ?>

<!-- FAQ + Tabs -->
<?= section_open(['id' => 'faq']) ?>
  <?= section_heading('Frequently Asked Questions', 'Accordion island (bold questions, round caret buttons) and Tabs (navy sliding pill).', 'Island · Accordion / Tabs', 'left', '', ['highlight' => 'Questions']) ?>
  <?= faq_accordion($faqs, ['columns' => 2, 'search' => true]) ?>
  <div class="mt-12"><?= content_tabs([['key' => 'ug', 'label' => 'Undergraduate'], ['key' => 'pg', 'label' => 'Postgraduate']], ['<p class="text-slate-600">BBA, B.Tech, BCA, B.Com (Hons), B.Sc.</p>', '<p class="text-slate-600">MBA, MCA, M.Com.</p>']) ?></div>
<?= section_close() ?>

<!-- Forms -->
<?= section_open(['id' => 'forms', 'bg' => 'sand']) ?>
  <?= section_heading('Enquiry forms & lead card', 'EnquiryForm themes light / dark / lead (+ yellow countdown & seats strip via the footer option) / plain. Posts to /api/public/enquiry with UTM capture.', 'Island · EnquiryForm', 'left', '', ['highlight' => 'lead card']) ?>
  <div class="grid gap-8 lg:grid-cols-12">
    <div class="lg:col-span-5">
      <?= enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'theme' => 'lead', 'compact' => true, 'captureUtm' => true, 'title' => 'Talk to an Admission Expert', 'titleHighlight' => 'Admission Expert',
          'tags' => [['icon' => 'wallet', 'label' => 'Semester-wise fee payment'], ['icon' => 'award', 'label' => 'Merit scholarships']], 'subtitle' => 'Get a Free Career Counselling Session', 'submitLabel' => 'Apply Now',
          'hidden' => ['source' => 'site-kit', 'form' => 'lead'], 'consent' => 'I agree to be contacted by GIMT about admissions.',
          'footer' => (countdown(null, ['label' => 'Application closes in']) ?: '<span>Countdown hides without a deadline</span>') . (site_seats_filled() !== null ? '<span><b class="enquiry-strip-big">' . site_seats_filled() . '%</b> of seats filled</span>' : ''),
          'fields' => [['name' => 'name', 'label' => 'Full Name', 'required' => true], ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
                       ['name' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'prefix' => '+91', 'pattern' => '^[6-9][0-9]{9}$', 'patternMessage' => 'Enter a valid 10-digit mobile number.', 'maxLength' => 10],
                       ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'options' => $programOptions, 'required' => true]]]) ?>
    </div>
    <div class="space-y-8 lg:col-span-7">
      <?= enquiry_form(['endpoint' => base_url('api/public/contact'), 'theme' => 'light', 'title' => 'Send Us a Message', 'submitLabel' => 'Send Message', 'consent' => 'I agree to the terms and allow GIMT to contact me.',
          'fields' => [['name' => 'name', 'label' => 'Full Name', 'required' => true, 'col' => 'half'], ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'col' => 'half'],
                       ['name' => 'message', 'label' => 'Your Message', 'type' => 'textarea', 'required' => true, 'minLength' => 10]]]) ?>
      <p class="text-sm text-slate-600">Countdown island (boxes): <?= countdown(null, ['variant' => 'boxes']) ?: 'no future deadline set' ?></p>
    </div>
  </div>
  <?= $code("enquiry_form(['theme' => 'lead', 'title' => 'Talk to an Admission Expert', 'titleHighlight' => 'Admission Expert', 'tags' => [...], 'captureUtm' => true,\n  'footer' => countdown(site_admissions_deadline(), ['label' => 'Application closes in']) . \$seatsHtml, 'fields' => [... ['type' => 'tel', 'prefix' => '+91'] ...]]);") ?>
<?= section_close() ?>

<!-- View modes -->
<?= section_open(['id' => 'views']) ?>
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <?= $kitHeading('views-h', 'Listing view modes', 'view_mode_toggle() + view_mode_open(): Grid / List / Compact / Detailed on generic .view-item cards; saved per listing key (no layout shift).', 'Helper') ?>
    <?= view_mode_toggle('kit-programs') ?>
  </div>
  <?= view_mode_open('kit-programs') ?>
    <?php foreach (array_slice($programs, 0, 6) as $p): ?>
      <article class="view-item">
        <a href="<?= e(site_url('programs/' . $p['slug'])) ?>" class="vm-media img-zoom"><img src="<?= e(site_image($p['image'])) ?>" alt="" loading="lazy"></a>
        <div class="vm-body">
          <p class="text-xs font-bold uppercase tracking-[0.12em] text-accent-700"><?= e(program_level_label($p['level'])) ?></p>
          <h3 class="font-display text-[17px] font-extrabold text-brand-900"><?= e($p['name']) ?></h3>
          <p class="vm-text text-sm text-slate-600"><?= e(str_limit(strip_tags((string) $p['overview']), 110)) ?></p>
          <p class="text-sm font-semibold text-brand-900"><?= e($p['duration_label']) ?> · <?= e(money($p['fee_per_year'])) ?> / year</p>
          <div class="vm-extra mt-2 text-sm text-slate-600"><?= e(str_limit(strip_tags((string) $p['eligibility']), 160)) ?></div>
        </div>
      </article>
    <?php endforeach; ?>
  <?= view_mode_close() ?>
  <?= $code("echo view_mode_toggle('programs');\necho view_mode_open('programs');   // <div class=\"view-host\" data-view=\"grid\" data-view-key=\"programs\">\n  <article class=\"view-item\"><a class=\"vm-media\">img</a><div class=\"vm-body\">… <p class=\"vm-text\"> … <div class=\"vm-extra\"></div></article>\necho view_mode_close();") ?>
<?= section_close() ?>

<!-- Floating widgets -->
<?= section_open(['id' => 'widgets', 'bg' => 'light']) ?>
  <?= $kitHeading('widgets-h', 'Floating widgets & bars', 'Rendered site-wide by site_header()/site_footer() (options + settings): announcement ticker with pause, dismissible admission bar (shown at the top of this page), vertical WhatsApp "Chat" tab + call button (or round WhatsApp button), desktop "Enquire Now" side tab + enquiry modal, mobile admission bar (Call · Apply Now), back-to-top ring.', 'Chrome') ?>
  <div class="flex flex-wrap gap-3">
    <button type="button" class="btn btn-callback" data-modal-open="#enquiry-modal"><?= icon('message-circle', 'h-4 w-4') ?>Open enquiry modal</button>
    <?= button_link('Landing page (general)', '/admissions-open', 'explore') ?>
    <?= button_link('Landing page (MBA)', '/admissions-open/mba', 'brochure', ['icon' => '']) ?>
  </div>
  <div class="mt-6 overflow-hidden rounded-2xl border border-[#EDE8E2]"><?= announcement_strip([['text' => 'Admissions Open for 2026-27', 'url' => null], ['text' => 'Merit scholarships available', 'url' => null]]) ?></div>
  <?= $code("site_header(['announce' => true|'A | B', 'admission_bar' => true, 'header' => 'minimal', 'header_cta' => [...]]);\nsite_footer(['mobile_cta' => true|['call' => …, 'apply' => …], 'enquiry_tab' => true, 'chat' => 'tab'|'button'|'none', 'footer' => 'full'|'minimal']);\necho whatsapp_button();  echo enquiry_tab();  echo enquiry_modal(['id' => 'lead-modal', 'program' => \$id]);  echo admission_bar();  echo back_to_top();") ?>
<?= section_close() ?>

<!-- Motion & effects -->
<?= section_open(['id' => 'effects']) ?>
  <?= $kitHeading('effects-h', 'Motion & surfaces', 'Transform/opacity (clip-path for photo reveals) only; static for reduced motion and automated browsers.', 'Motion library') ?>
  <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
    <div class="relative isolate flex min-h-[220px] flex-col justify-end overflow-hidden rounded-[22px] bg-mesh-navy grain p-6 text-white"><?= geo_shapes('card') ?><?= blob('-right-10 -top-10 h-56 w-56', 'flame') ?><p class="relative font-display text-lg font-extrabold">.bg-mesh-navy .grain + geo_shapes() + blob()</p></div>
    <div class="relative flex min-h-[220px] flex-col justify-end overflow-hidden rounded-[22px] bg-mesh-warm p-6" data-spotlight><p class="relative font-display text-lg font-extrabold text-brand-900">.bg-mesh-warm + [data-spotlight]</p><p class="relative text-sm text-slate-600">Cursor-following light (desktop pointer only).</p></div>
    <div class="gradient-border flex min-h-[220px] flex-col justify-end rounded-[22px] p-6" data-tilt="4"><span class="accent-line mb-4"<?= reveal_attr() ?>></span><p class="font-display text-lg font-extrabold text-brand-900">.gradient-border + .accent-line + [data-tilt]</p></div>
    <div class="page-hero-frame" data-reveal="clip"><img src="<?= e(asset('assets/images/site/campus-library.jpg')) ?>" alt="Library" loading="lazy"></div>
    <div class="glass-panel flex min-h-[220px] flex-col justify-center rounded-[22px] p-6"><p class="font-display text-lg font-extrabold text-brand-900">.glass-panel</p><p class="text-sm text-slate-600">Glassmorphism on selected floating panels, solid @supports fallback.</p></div>
    <div class="flex min-h-[220px] flex-col justify-center gap-4 rounded-[22px] border border-[#EDE8E2] p-6">
      <p class="font-display text-4xl font-extrabold text-brand-900"><span data-count="980" data-suffix="+">980+</span></p>
      <div class="progress-track"><span data-progress="72"></span></div>
      <div class="flex gap-3" aria-hidden="true"><span class="skeleton h-12 w-12 rounded-full"></span><span class="flex-1 space-y-2 pt-1"><span class="skeleton block h-3 w-2/3 rounded"></span><span class="skeleton block h-3 w-1/2 rounded"></span></span></div>
    </div>
  </div>
  <?= $code("<h2 data-text-reveal>…</h2>  <div data-reveal=\"clip\"><img></div>  <div data-tilt=\"4\">  <div data-spotlight>  <a class=\"btn btn-apply\" data-magnetic>\n.bg-mesh-navy .bg-mesh-warm .grain .gradient-border .accent-line .glass-panel   geo_shapes('hero'|'band'|'card')   blob('classes', 'flame'|'navy'|'warm')\nPage transitions: @view-transition { navigation: auto } (CSS) + viewTransition(fn) in core/effects.ts") ?>
<?= section_close() ?>

<!-- Media -->
<?= section_open(['id' => 'media', 'bg' => 'light']) ?>
  <?= $kitHeading('media-h', 'Video & gallery', 'VideoLightbox (thumb / button / chip) and GalleryLightbox (bento, filter, zoom).') ?>
  <div class="grid items-center gap-8 lg:grid-cols-2">
    <?= video_lightbox(['url' => $video, 'title' => 'GIMT Virtual Campus Tour', 'label' => 'Virtual Campus Tour', 'poster' => asset('assets/images/site/campus-building.jpg')]) ?>
    <div><?= video_lightbox(['url' => $video, 'label' => 'Watch our campus tour', 'sublabel' => '2 minutes', 'variant' => 'button']) ?></div>
  </div>
  <div class="mt-10"><?= gallery_grid($gallery, ['layout' => 'bento', 'columns' => 4, 'filter' => true]) ?></div>
<?= section_close() ?>

<?php site_footer(['cta_title' => 'Start Your Academic Journey at GIMT', 'cta_highlight' => 'Journey', 'cta_text' => 'Applications for the ' . (current_session()['name'] ?? '2026-27') . ' session are open.']); ?>
