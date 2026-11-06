<?php
/**
 * Certificate Courses (/certifications) — hero, course explorer (ProgramExplorer island: group tiles + search,
 * ?group= / ?q= deep links) beside a sticky counselling sidebar, how certification works, why GIMT, certificate
 * verification strip, FAQs, CTA. Courses = active website programs of level Certificate, grouped by
 * site_cert_groups() (programs.category).
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/certifications.php';

$courses = site_cert_programs();
$groups = site_cert_groups();
$initial = (string) ($_GET['group'] ?? '');
$initial = isset($groups[$initial]) ? $initial : 'all';
$byGroup = cert_by_group($courses);
$n = count($courses);

// duration range in months ("1–6 months") from the duration labels / years
$months = array_filter(array_map(fn ($p) => preg_match('/(\d+)\s*month/i', (string) $p['duration_label'], $m) ? (int) $m[1] : (int) round((float) $p['duration_years'] * 12), $courses));
$range = $months ? (min($months) === max($months) ? min($months) . ' month' . (min($months) === 1 ? '' : 's') : min($months) . '–' . max($months) . ' months') : '';
$fees = array_filter(array_map(fn ($p) => (float) $p['fee_per_year'], $courses));
$verifyUrl = is_file(__DIR__ . '/verify-certificate.php') ? site_url('verify-certificate') : '';

$faqs = [
    ['q' => 'Who can join a certificate course at GIMT?', 'a' => 'Most courses are open to anyone who has passed Class 10 — school and college students, graduates and working professionals. A few courses ask for Class 12 or a degree; the exact eligibility is listed on each course page.'],
    ['q' => 'How long are the courses?', 'a' => ($range !== '' ? 'Courses run for ' . $range . '. ' : '') . 'Each course is divided into short modules and ends with a practical project or assessment. The duration and modules are listed on every course page.'],
    ['q' => 'What are the fees?', 'a' => $fees ? 'Published course fees range from ' . money(min($fees)) . ' to ' . money(max($fees)) . ' per course. For courses without a published fee, our counsellors share the current fee when you enquire.' : 'Our counsellors share the current course fee when you enquire. Request a call back using the form on this page.'],
    ['q' => 'Will I get a certificate?', 'a' => 'Yes. When you complete the modules and the final assessment you receive a GIMT course completion certificate with a unique certificate number and QR code' . ($verifyUrl !== '' ? ' that anyone — for example an employer — can check at ' . absolute_url('verify-certificate') : '') . '.'],
    ['q' => 'Can I take more than one course?', 'a' => 'Yes. Many learners combine courses, for example MS Office with Spoken English, or AI Tools with Advanced Excel. Our counsellors help you choose the right combination for your goals.'],
    ['q' => 'How do I enrol?', 'a' => 'Open the course you like and click Apply Now, or request a free call back with the form on this page and our team will guide you. You can also call ' . setting('phone', '+91 9955446477') . '.'],
];

$heroImg = 'assets/images/site/students-laptop.jpg';
site_header([
    'title' => site_fit_title(['Certificate Courses — Computer, Management, Soft Skills & AI', 'Certificate Courses at GIMT Noida', 'Certificate Courses']),
    'description' => site_meta_description('Explore ' . $n . ' short certificate courses at GIMT Noida in management & business, computers & technology, communication and soft skills, AI and career preparation' . ($range !== '' ? ' — ' . $range . ' each' : '') . ', with a QR-verifiable course completion certificate.'),
    'active' => 'programs',
    'seo' => ['route', '/certifications'],
    'image' => $heroImg,
    'preload' => [asset($heroImg)],
    'schema' => array_values(array_filter([
        sa_breadcrumb_ld([['Home', ''], ['Programs', 'programs'], ['Certificate Courses', null]]),
        $courses && !sa_seo_schema_has('ItemList', 'route', null, '/certifications') ? ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Certificate courses at ' . institute_name(), 'numberOfItems' => $n,
            'itemListElement' => array_map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => ['@type' => 'Course', 'name' => $p['name'], 'url' => absolute_url('programs/' . $p['slug']),
                'description' => str_limit((string) $p['overview'], 250), 'provider' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'sameAs' => absolute_url('')]]], $courses, array_keys($courses))] : null,
    ])),
]);

page_hero('Certificate Courses', 'Practical Skills for Study, Work and Career', ['Programs' => '/programs', 'Certificate Courses'], $heroImg,
    'Choose from ' . $n . ' short certificate courses in management, computers, communication, AI and career skills — open to students, graduates and working professionals.',
    array_values(array_filter([
        $range !== '' ? ['icon' => 'clock', 'label' => ucfirst($range) . ' courses'] : null,
        ['icon' => 'layers', 'label' => 'Module-wise curriculum'],
        ['icon' => 'presentation', 'label' => 'Practical projects'],
        ['icon' => 'qr-code', 'label' => 'QR-verifiable certificate'],
    ])),
    ['script' => 'Learn Practise Get Certified', 'image_alt' => 'GIMT students learning on a laptop']);
?>

<!-- Course explorer + counselling sidebar -->
<?= section_open(['id' => 'courses', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20 !pt-12 sm:!pt-14']) ?>
  <?php if ($courses): ?>
    <div class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_340px]">
      <div class="min-w-0">
        <?= section_heading('Explore Certificate Courses', 'Pick a group or search by skill — every course page lists the modules, duration, eligibility and fee.', $n . ' courses · ' . count(array_diff_key($byGroup, ['other' => 1])) . ' groups') ?>
        <?= cert_explorer($courses, $initial) ?>
      </div>
      <aside aria-label="Course counselling">
        <div class="space-y-4 lg:sticky lg:top-28">
          <?= sa_counselling_form(['source' => 'certifications-page', 'title' => 'Not Sure Which Course?', 'subtitle' => 'Get free guidance from our counsellors.',
              'select_label' => 'Course Interest', 'select_placeholder' => 'Select a course', 'options' => site_program_select_options($courses)]) ?>
          <?php if ($wa = sa_whatsapp_url('Hello GIMT, I would like to know more about your certificate courses.')): ?>
            <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="group flex items-center gap-3.5 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-card">
              <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white shadow-sm"><?= icon('whatsapp-brand', 'h-6 w-6') ?></span>
              <span class="min-w-0 flex-1"><span class="block font-display text-[15px] font-bold text-brand-900">Chat on WhatsApp</span><span class="block text-xs text-slate-600">Quick answers about courses and batches</span></span>
              <?= icon('chevron-right', 'h-4 w-4 text-slate-400 transition group-hover:translate-x-1') ?><span class="sr-only">(opens in a new tab)</span>
            </a>
          <?php endif; ?>
          <a href="<?= e(sa_brochure_url()) ?>" target="_blank" rel="noopener" class="group flex items-center gap-3.5 rounded-2xl border border-brand-100 bg-white p-4 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700"><?= icon('file-text', 'h-5 w-5') ?></span>
            <span class="min-w-0 flex-1"><span class="block font-display text-[15px] font-bold text-brand-900">Course Brochure</span><span class="block text-xs text-slate-600">All programs and courses, printable</span></span>
            <?= icon('download', 'h-4 w-4 text-slate-400') ?><span class="sr-only">(opens in a new tab)</span>
          </a>
        </div>
      </aside>
    </div>
  <?php else: ?>
    <div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white">
      <span class="empty-state-icon"><?= icon('badge-check', 'h-6 w-6') ?></span>
      <p class="mt-3 font-semibold text-brand-900">Certificate courses for the new session are being updated.</p>
      <p class="mt-1 text-sm text-slate-500">Request a counselling call and we will share the complete list with you.</p>
      <a href="<?= e(site_url('contact')) ?>" class="btn btn-secondary btn-sm mt-4">Contact Us</a>
    </div>
  <?php endif; ?>
<?= section_close() ?>

<!-- How certification works -->
<?= section_open(['id' => 'how-it-works']) ?>
  <?= section_heading('How Certification Works', 'From choosing a course to holding a certificate employers can verify.', 'Your Journey', 'center') ?>
  <?= progress_steps([
      ['title' => 'Choose a Course', 'text' => 'Compare courses by group, duration and eligibility, or talk to a counsellor.', 'icon' => 'search'],
      ['title' => 'Enrol', 'text' => 'Apply online or at the campus — our team confirms your start date.', 'icon' => 'clipboard-check'],
      ['title' => 'Learn & Practise', 'text' => 'Module-wise sessions with practical exercises in every module.', 'icon' => 'presentation'],
      ['title' => 'Assessment', 'text' => 'A final project or test shows what you have learned.', 'icon' => 'file-check'],
      ['title' => 'Get Certified', 'text' => 'Receive a GIMT certificate with a unique number and QR code.', 'icon' => 'award'],
  ], ['cta' => ['label' => 'Find Your Course', 'url' => '/certifications#courses']]) ?>
<?= section_close() ?>

<!-- Why GIMT -->
<?= section_open(['id' => 'why', 'bg' => 'light', 'pattern' => 'dots']) ?>
  <?= section_heading('Why Learn With GIMT?', 'Short courses, taught with the same care as our degree programs.') ?>
  <div class="grid grid-cols-2 gap-3 sm:grid-cols-4" data-stagger="60">
    <?php foreach ([
        ['layers', 'Module-wise Curriculum', 'blue', 'Clear modules, each with practical work.'],
        ['users', 'GIMT Faculty & Trainers', 'green', 'Learn from the institute’s own teachers.'],
        ['qr-code', 'Verifiable Certificate', 'violet', 'Unique number and QR code on every certificate.'],
        ['handshake', 'Career Guidance', 'cyan', 'Help with resumes, interviews and next steps.'],
    ] as [$ic, $label, $tint, $text]): ?><?= sa_icon_tile($ic, $label, $tint, $text) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>

<?php if ($verifyUrl !== ''): ?>
<!-- Verify a certificate -->
<?= section_open(['padding' => 'tight']) ?>
  <div class="flex flex-col items-start gap-4 rounded-3xl border border-warm-2 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between sm:p-8"<?= reveal_attr() ?>>
    <div class="flex items-start gap-4">
      <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl tint-navy"><?= icon('shield-check', 'h-6 w-6') ?></span>
      <div>
        <h2 class="font-display text-xl font-extrabold text-brand-900">Already have a GIMT certificate?</h2>
        <p class="mt-1 text-slate-600">Employers and institutions can check any GIMT certificate online with its certificate number or QR code.</p>
      </div>
    </div>
    <?= button_link('Verify a Certificate', '/verify-certificate', 'explore', ['icon' => 'arrow-right']) ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- FAQs -->
<?= section_open(['id' => 'faq', 'bg' => 'brand-soft']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Course Help</p>
      <h2 class="section-title">Questions About Certificate Courses?</h2>
      <p class="section-lead">Answers to what learners ask us most. Still unsure? Our counsellors are a call away.</p>
      <div class="mt-6 flex flex-wrap gap-3">
        <?= button_link('Talk to a Counsellor', '#courses', 'accent', ['icon' => 'arrow-right']) ?>
        <a href="tel:<?= e(preg_replace('/\s+/', '', (string) setting('phone', '+91 9955446477'))) ?>" class="btn btn-outline"><?= icon('phone', 'h-4 w-4') ?><?= e(setting('phone', '+91 9955446477')) ?></a>
      </div>
    </div>
    <div class="lg:col-span-8"><?= faq_accordion($faqs, ['mode' => 'single', 'defaultOpen' => 0]) ?></div>
  </div>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Learn a New Skill at GIMT',
    'cta_text' => 'Short certificate courses for students, graduates and working professionals.',
    'cta_primary' => ['label' => 'Find Your Course', 'url' => '/certifications#courses'],
    'cta_secondary' => ['label' => 'Explore Degree Programs', 'url' => '/programs#find', 'icon' => 'graduation-cap'],
]);
