<?php
/**
 * Academic Programs (/programs) — hero, "Find Your Program" (ProgramExplorer island: category tiles + search over
 * every active program, ?category= deep links), sticky counselling sidebar (EnquiryForm → /api/public/enquiry,
 * WhatsApp, brochure), programs at a glance (Tabs), Why Study at GIMT, recruiters (LogoMarquee), FAQs, CTA.
 *
 * /programs?brochure=1[&program=slug] renders the printable brochure (all programs, or one with its curriculum).
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$programs = sa_programs();

if (isset($_GET['brochure'])) {
    $slug = (string) ($_GET['program'] ?? '');
    $single = null;
    if ($slug !== '') {
        foreach ($programs as $p) {
            if ($p['slug'] === $slug) {
                $single = $p;
            }
        }
        if (!$single) {
            http_response_code(404);
        }
    }
    sa_render_brochure($programs, $single);
    exit;
}

$category = sa_category_key($_GET['category'] ?? null);
$counts = sa_counts();
$byLevel = ['UG' => [], 'PG' => [], 'Other' => []];
foreach ($programs as $p) {
    $byLevel[$p['level'] === 'UG' ? 'UG' : (in_array($p['level'], ['PG', 'PhD'], true) ? 'PG' : 'Other')][] = $p;
}
$fees = array_filter(array_map(fn ($p) => (float) $p['fee_per_year'], $programs));
$session = sa_session();
$recruiters = sa_recruiters(12);

// FAQs: CMS faqs (admissions / fees / academics) or answers generated from the programs table.
$faqs = sa_faqs(['admissions', 'fees', 'academics'], 6);
if (!$faqs) {
    $ugElig = $byLevel['UG'][0]['eligibility'] ?? '';
    $pgElig = $byLevel['PG'][0]['eligibility'] ?? '';
    $faqs = [
        ['q' => 'Which programs does GIMT offer?', 'a' => 'GIMT offers ' . count($programs) . ' programs: ' . count($byLevel['UG']) . ' undergraduate, ' . count($byLevel['PG']) . ' postgraduate and ' . count($byLevel['Other']) . ' diploma and certificate programs across management, technology, commerce, computer applications and data science.'],
        ['q' => 'What is the eligibility for undergraduate programs?', 'a' => $ugElig ?: 'Candidates need 10+2 or equivalent from a recognised board. Program-specific requirements are listed on each program page.'],
        ['q' => 'What is the eligibility for postgraduate programs?', 'a' => $pgElig ?: 'A bachelor’s degree from a recognised university. Program-specific requirements are listed on each program page.'],
        ['q' => 'How much are the program fees?', 'a' => $fees ? 'Fees for the ' . ($session['name'] ?? 'current') . ' session range from ' . money(min($fees)) . ' to ' . money(max($fees)) . ' per year (certificate programs are charged per course). Merit, sports and need-based scholarships and education-loan assistance are available.' : 'Fees are listed on each program page. Merit and need-based scholarships are available.'],
        ['q' => 'How do I apply for admission?', 'a' => "Choose your program and fill in the online application at " . absolute_url('apply') . ". Upload your documents, pay the registration fee and our admission team will verify your application.\n\nNeed guidance first? Request a free counselling call using the form on this page or call " . setting('phone', '+91 9955446477') . '.'],
        ['q' => 'Can I talk to a counsellor before choosing a program?', 'a' => 'Yes. Our admission counsellors help you compare programs, eligibility, fees and career outcomes. Submit the counselling form and we will call you within one working day.'],
    ];
}

// Programs at a glance tables (Tabs island)
$table = function (array $rows): string {
    if (!$rows) {
        return '<div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white"><p class="font-semibold text-brand-900">No programs in this category yet.</p></div>';
    }
    $h = '<div class="relative overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="data-table min-w-[560px] md:min-w-[720px]"><thead><tr><th scope="col">Program</th><th scope="col">Duration</th><th scope="col" class="hidden md:table-cell">Eligibility</th><th scope="col" class="text-right">Intake</th><th scope="col" class="text-right">Fee</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($rows as $p) {
        $h .= '<tr class="transition hover:bg-brand-50/40"><td><a href="' . e(site_url('programs/' . $p['slug'])) . '" class="font-semibold text-brand-900 hover:text-accent-800">' . e($p['name']) . '</a><span class="block text-xs text-slate-500">' . e($p['department_name'] ?? '') . '</span></td>'
            . '<td class="whitespace-nowrap">' . e($p['duration_label'] ?: $p['duration_years'] . ' Years') . '</td><td class="hidden max-w-xs text-slate-600 md:table-cell"><span class="line-clamp-2" title="' . e((string) $p['eligibility']) . '">' . e((string) $p['eligibility']) . '</span></td>'
            . '<td class="text-right">' . ($p['intake_capacity'] ? (int) $p['intake_capacity'] : '—') . '</td><td class="whitespace-nowrap text-right font-semibold text-brand-900">' . e(sa_program_fee($p) ?: '—') . '</td>'
            . '<td class="whitespace-nowrap text-right"><a href="' . e(site_url('apply?program=' . rawurlencode($p['slug']))) . '" class="link-arrow text-sm">Apply' . icon('arrow-right', 'h-4 w-4') . '</a></td></tr>';
    }
    return $h . '</tbody></table></div>';
};

$heroImg = 'assets/images/site/students-laptop.jpg';
site_header([
    'title' => 'Academic Programs — UG, PG, Diploma & Certificate Courses',
    'description' => 'Explore ' . count($programs) . ' industry-focused programs at GIMT Greater Noida — BBA, MBA, B.Tech, BCA, MCA, B.Com, M.Com, diploma and certificate courses with fees, eligibility and curriculum.',
    'active' => 'programs',
    'seo' => ['route', '/programs'],
    'image' => $heroImg,
    'preload' => [asset($heroImg)],
    'schema' => array_values(array_filter([
        sa_breadcrumb_ld([['Home', ''], ['Programs', null]]),
        sa_seo_schema_has('ItemList', 'route', null, '/programs') ? null : ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => 'Academic programs at ' . institute_name(), 'numberOfItems' => count($programs),
         'itemListElement' => array_map(fn ($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'item' => ['@type' => 'Course', 'name' => $p['name'], 'url' => absolute_url('programs/' . $p['slug']),
             'description' => str_limit((string) $p['overview'], 250), 'provider' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'sameAs' => absolute_url('')]]], $programs, array_keys($programs))],
    ])),
]);

page_hero('Academic Programs', 'Future-Ready Programs for a Brighter Tomorrow', ['Programs'], $heroImg,
    'Explore our wide range of undergraduate, postgraduate, diploma and certificate programs designed to build industry-ready skills and unlock global opportunities.',
    [
        ['icon' => 'presentation', 'label' => 'Industry Relevant Curriculum'],
        ['icon' => 'user-round', 'label' => 'Experienced Faculty'],
        ['icon' => 'building-2', 'label' => 'Modern Infrastructure'],
        ['icon' => 'globe', 'label' => 'Global Career Opportunities'],
    ],
    ['script' => 'Learn Grow Innovate Succeed', 'image_alt' => 'GIMT students collaborating on a laptop on campus']);
?>

<!-- Find your program: explorer + sticky counselling sidebar -->
<?= section_open(['id' => 'find', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20 !pt-12 sm:!pt-14']) ?>
  <?= section_heading('Find Your Program', 'Choose from our diverse range of programs across multiple disciplines.', '', 'left', '<a href="#compare" class="link-arrow">Compare All Programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?php if ($programs): ?>
    <div class="flex flex-col lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:grid-rows-[auto_auto_auto_1fr] lg:gap-x-8 xl:grid-cols-[minmax(0,1fr)_340px]
                [&>[data-island]]:contents [&_.program-explorer]:contents
                [&_.program-explorer>div:first-child]:order-1 lg:[&_.program-explorer>div:first-child]:col-span-2 lg:[&_.program-explorer>div:first-child]:row-start-1
                [&_.program-explorer>div.grid]:order-3 lg:[&_.program-explorer>div.grid]:col-start-1 lg:[&_.program-explorer>div.grid]:row-start-3
                [&_.program-explorer>.empty-state]:order-4 lg:[&_.program-explorer>.empty-state]:col-start-1 lg:[&_.program-explorer>.empty-state]:row-start-4 lg:[&_.program-explorer>.empty-state]:self-start
                lg:[&_[role=toolbar]]:grid-cols-4 xl:[&_[role=toolbar]]:grid-cols-8 xl:[&_.filter-tile]:flex-col xl:[&_.filter-tile]:gap-2 xl:[&_.filter-tile]:px-2">
      <?= sa_program_explorer($programs, ['initial' => $category]) ?>
      <div class="order-2 mb-6 lg:col-start-1 lg:row-start-2">
        <h2 class="font-display text-2xl font-extrabold tracking-tight text-brand-900 sm:text-3xl">Featured Programs</h2>
        <p class="mt-1 text-slate-600">Our most popular programs designed for industry success — filter by category or search to find yours.</p>
      </div>
      <aside class="order-5 mt-12 lg:col-start-2 lg:row-span-3 lg:row-start-2 lg:mt-0" aria-label="Admission counselling">
        <div class="space-y-4 lg:sticky lg:top-28">
          <?= sa_counselling_form(['source' => 'programs-page']) ?>
          <?= sa_sidebar_cards() ?>
        </div>
      </aside>
    </div>
  <?php else: ?>
    <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
      <div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white">
        <span class="empty-state-icon"><?= icon('graduation-cap', 'h-6 w-6') ?></span>
        <p class="mt-3 font-semibold text-brand-900">Program details for the new session are being updated.</p>
        <p class="mt-1 text-sm text-slate-500">Request a counselling call and we will share the complete program list with you.</p>
        <a href="<?= e(site_url('contact')) ?>" class="btn btn-secondary btn-sm mt-4">Contact Admissions</a>
      </div>
      <aside class="space-y-4" aria-label="Admission counselling"><?= sa_counselling_form(['source' => 'programs-page']) ?><?= sa_sidebar_cards() ?></aside>
    </div>
  <?php endif; ?>
<?= section_close() ?>

<!-- Programs at a glance -->
<?php if ($programs): ?>
<?= section_open(['id' => 'compare', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Programs at a Glance', 'Compare duration, eligibility, intake and fees' . ($session ? ' for the ' . $session['name'] . ' session' : '') . ' — then dive into the program that fits you.', 'Compare') ?>
  <?= content_tabs(
      array_values(array_filter([
          $byLevel['UG'] ? ['key' => 'ug', 'label' => 'Undergraduate (' . count($byLevel['UG']) . ')', 'icon' => 'graduation-cap'] : null,
          $byLevel['PG'] ? ['key' => 'pg', 'label' => 'Postgraduate (' . count($byLevel['PG']) . ')', 'icon' => 'school'] : null,
          $byLevel['Other'] ? ['key' => 'other', 'label' => 'Diploma & Certificate (' . count($byLevel['Other']) . ')', 'icon' => 'award'] : null,
      ])),
      array_values(array_filter([$byLevel['UG'] ? $table($byLevel['UG']) : null, $byLevel['PG'] ? $table($byLevel['PG']) : null, $byLevel['Other'] ? $table($byLevel['Other']) : null])),
      ['variant' => 'pills']
  ) ?>
  <p class="mt-4 flex items-start gap-2 text-sm text-slate-500"><?= icon('info', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') ?>Tuition fees<?= $session ? ' for ' . e($session['name']) : '' ?> are per year (certificate programs per course); hostel, transport and examination fees are charged separately. Scholarships are available on merit and need.</p>
<?= section_close() ?>
<?php endif; ?>

<!-- Why study at GIMT -->
<?= section_open(['id' => 'why', 'bg' => 'light', 'pattern' => 'dots']) ?>
  <?= section_heading('Why Study at GIMT?', 'Discover the advantages that make our programs unique and career-focused.') ?>
  <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8" data-stagger="60">
    <?php foreach ([
        ['graduation-cap', 'Industry-Aligned Curriculum', 'blue'], ['users', 'Expert Faculty & Mentorship', 'green'], ['presentation', 'Practical Learning', 'cyan'],
        ['briefcase-business', 'Internship Opportunities', 'violet'], ['handshake', 'Placement Support', 'blue'], ['globe', 'Global Exposure', 'violet'],
        ['building-2', 'Modern Infrastructure', 'cyan'], ['lightbulb', 'Holistic Development', 'green'],
    ] as [$ic, $label, $tint]): ?><?= sa_icon_tile($ic, $label, $tint) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>

<!-- Career opportunities: recruiters -->
<?= section_open(['id' => 'careers']) ?>
  <?= section_heading('Career Opportunities', 'Our graduates are placed in top companies across diverse industries.', '', 'left', is_file(__DIR__ . '/placement.php') ? '<a href="' . e(site_url('placement')) . '" class="link-arrow">View Placement Details' . icon('arrow-right', 'h-4 w-4') . '</a>' : '') ?>
  <div class="rounded-3xl border border-slate-100 bg-slate-50/80 px-2 py-5 sm:px-4"<?= reveal_attr() ?>>
    <?= logo_marquee($recruiters, ['rows' => 1, 'ariaLabel' => 'Companies that recruit GIMT graduates']) ?>
  </div>
<?= section_close() ?>

<!-- FAQs -->
<?= section_open(['id' => 'faq', 'bg' => 'brand-soft']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Admissions Help</p>
      <h2 class="section-title">Questions About Our Programs?</h2>
      <p class="section-lead">Answers to what students and parents ask us most. Still unsure? Our counsellors are a call away.</p>
      <div class="mt-6 flex flex-wrap gap-3">
        <?= button_link('Talk to a Counsellor', '#find', 'accent', ['icon' => 'arrow-right']) ?>
        <a href="tel:<?= e(preg_replace('/\s+/', '', (string) setting('phone', '+91 9955446477'))) ?>" class="btn btn-outline"><?= icon('phone', 'h-4 w-4') ?><?= e(setting('phone', '+91 9955446477')) ?></a>
      </div>
    </div>
    <div class="lg:col-span-8"><?= faq_accordion($faqs, ['mode' => 'single', 'defaultOpen' => 0]) ?></div>
  </div>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Start Your Academic Journey at GIMT',
    'cta_text' => 'Join a community of learners, innovators and future leaders.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore All Programs', 'url' => '/programs#find', 'icon' => 'graduation-cap'],
]);
