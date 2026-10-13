<?php
/**
 * Program detail (/programs/{slug}) — hero, key facts, overview + eligibility + specializations, highlights,
 * semester-wise curriculum (Tabs island over the subjects table), fee structure (fee_structures of the current session
 * or the program fee), career opportunities + recruiters, department faculty (public fields), FAQs, sticky apply /
 * counselling sidebar (EnquiryForm with the program preselected), related programs carousel, JSON-LD Course +
 * BreadcrumbList. Unknown slugs get a helpful 404 page.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$slug = strtolower((string) ($_GET['slug'] ?? ''));
$program = null;
if (preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    $program = sa_try(fn () => db_row("SELECT p.*, d.name AS department_name, d.code AS department_code, d.description AS department_description
        FROM programs p LEFT JOIN departments d ON d.id = p.department_id
        WHERE p.slug = ? AND p.status = 'active' AND p.show_on_website = 1", [$slug]));
}
$all = sa_programs();

/* ------------------------------------------------------------------ 404 */
if (!$program) {
    http_response_code(404);
    // closest slug suggestion
    $best = null;
    $bestScore = 0;
    foreach ($all as $p) {
        similar_text($slug, $p['slug'], $pct);
        if ($pct > $bestScore) {
            [$best, $bestScore] = [$p, $pct];
        }
    }
    site_header(['title' => 'Program Not Found', 'description' => 'The program you are looking for could not be found. Explore all programs offered at GIMT, Greater Noida.',
        'active' => 'programs', 'robots' => 'noindex,follow', 'canonical' => absolute_url('programs')]);
    page_hero('Program Not Found', 'Let’s find the right program for you', ['Programs' => '/programs', 'Not Found'], 'assets/images/site/students-laptop.jpg',
        'The program you are looking for may have been renamed or is no longer offered. Browse our current programs or ask a counsellor for guidance.', [],
        ['actions' => button_link('Browse All Programs', '/programs', 'accent', ['shine' => true]) . button_link('Talk to a Counsellor', '/contact?type=program#message', 'outline-light', ['icon' => '']), 'size' => 'md']);
    ?>
    <?= section_open(['bg' => 'brand-soft']) ?>
      <?php if ($best && $bestScore >= 55): ?>
        <div class="mb-10 flex flex-col items-start gap-4 rounded-2xl border border-brand-100 bg-white p-5 shadow-sm sm:flex-row sm:items-center"<?= reveal_attr() ?>>
          <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl tint-navy"><?= icon('search-check', 'h-6 w-6') ?></span>
          <p class="flex-1 text-slate-700">Were you looking for <a href="<?= e(site_url('programs/' . $best['slug'])) ?>" class="font-semibold text-brand-800 underline decoration-brand-200 underline-offset-4 hover:text-accent-800"><?= e($best['name']) ?></a>?</p>
          <?= button_link('View Program', '/programs/' . $best['slug'], 'primary', ['size' => 'sm']) ?>
        </div>
      <?php endif; ?>
      <?= section_heading('Popular Programs', 'Industry-focused programs with admissions open for the ' . (sa_session()['name'] ?? 'current') . ' session.', 'Explore', 'left', '<a href="' . e(site_url('programs')) . '" class="link-arrow">All ' . count($all) . ' programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
      <?php if ($all): ?>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
          <?php foreach (array_slice($all, 0, 6) as $p): ?><?= sa_program_card($p) ?><?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white"><span class="empty-state-icon"><?= icon('graduation-cap', 'h-6 w-6') ?></span><p class="mt-3 font-semibold text-brand-900">Program details are being updated.</p><a href="<?= e(site_url('contact')) ?>" class="btn btn-secondary btn-sm mt-4">Contact Admissions</a></div>
      <?php endif; ?>
    <?= section_close() ?>
    <?php
    site_footer(['cta_title' => 'Not Sure Which Program Fits You?', 'cta_text' => 'Our admission counsellors will help you compare programs, eligibility and careers.',
        'cta_primary' => ['label' => 'Talk to a Counsellor', 'url' => '/contact?type=program#message'], 'cta_secondary' => ['label' => 'All Programs', 'url' => '/programs', 'icon' => 'graduation-cap']]);
    exit;
}

/* ------------------------------------------------------------------ data */
$p = $program;
$id = (int) $p['id'];
$name = $p['name'];
$short = $p['short_name'] ?: $name;
$suffix = program_title_suffix($p);
$session = sa_session();
$curriculum = sa_curriculum($id);
$totalCredits = array_sum(array_map(fn ($rows) => array_sum(array_column($rows, 'credits')), $curriculum));
$courses = sa_try(fn () => db_all("SELECT name, description FROM courses WHERE program_id = ? AND status = 'active' ORDER BY name", [$id]), []);
$feeStructures = sa_fee_structures($p);
$faculty = $p['department_id'] ? sa_faculty(['department_id' => (int) $p['department_id'], 'limit' => 8]) : [];
$highlights = sa_lines($p['highlights']);
$careers = sa_lines($p['career_prospects']);
$recruiters = sa_recruiters(12);
$testimonials = sa_testimonials(4, $id);
$fee = sa_program_fee($p);
$years = max(0.5, (float) $p['duration_years']);
$image = site_image($p['image'] ?? null, 'assets/images/site/campus-building.jpg');
$applyPath = 'apply?program=' . rawurlencode($p['slug']);
$applyUrl = site_url($applyPath);
$level = program_level_label($p['level']);
$duration = $p['duration_label'] ?: rtrim(rtrim(number_format($years, 1), '0'), '.') . ' Years';
$mode = 'Full-Time · On Campus';

// short eligibility for the facts strip
$elig = trim((string) $p['eligibility']);
$eligShort = $elig;
if (preg_match('/(\d{2})\s*%/', $elig, $m)) {
    $base = stripos($elig, '10+2') !== false ? '10+2' : (preg_match('/bachelor|graduat/i', $elig) ? 'Graduation' : '');
    $eligShort = $base !== '' ? $base . ' with ' . $m[1] . '%' : str_limit($elig, 30);
} elseif (stripos($elig, '10+2') !== false) {
    $eligShort = stripos($elig, 'graduation') !== false ? '10+2 or Graduation' : '10+2 (any stream)';
} else {
    $eligShort = str_limit($elig, 30);
}

// related programs: same category / department first, then same level
$related = [];
foreach ($all as $r) {
    if ((int) $r['id'] !== $id && ($r['category'] === $p['category'] || (int) $r['department_id'] === (int) $p['department_id'])) {
        $related[$r['id']] = $r;
    }
}
foreach ($all as $r) {
    if (count($related) >= 6) {
        break;
    }
    if ((int) $r['id'] !== $id && $r['level'] === $p['level']) {
        $related[$r['id']] = $r;
    }
}
foreach ($all as $r) {
    if (count($related) >= 6) {
        break;
    }
    if ((int) $r['id'] !== $id) {
        $related[$r['id']] = $r;
    }
}
$related = array_slice(array_values($related), 0, 6);

// FAQs generated from this program's data + CMS admission FAQs
$faqs = [
    ['q' => 'What is the duration of the ' . $short . ' program?', 'a' => 'The ' . $name . ' is a ' . strtolower($duration) . ' ' . strtolower($level) . ' program divided into ' . (int) $p['total_semesters'] . ' semester' . ((int) $p['total_semesters'] === 1 ? '' : 's') . ($totalCredits ? ' with ' . rtrim(rtrim(number_format($totalCredits, 1), '0'), '.') . ' credits in total' : '') . '.'],
];
if ($elig !== '') {
    $faqs[] = ['q' => 'What is the eligibility for ' . $short . '?', 'a' => $elig];
}
if ($fee !== '') {
    $faqs[] = ['q' => 'What is the fee for ' . $short . '?', 'a' => 'The tuition fee for the ' . ($session['name'] ?? 'current') . ' session is ' . $fee . '. Fees can be paid semester-wise; merit and need-based scholarships and education-loan assistance are available. Hostel and transport fees are separate.'];
}
if ($p['intake_capacity']) {
    $faqs[] = ['q' => 'How many seats are available?', 'a' => 'The annual intake for ' . $short . ' is ' . (int) $p['intake_capacity'] . ' seats. Admissions are on a first-come, merit-verified basis, so we recommend applying early.'];
}
if ($careers) {
    $faqs[] = ['q' => 'What career options are available after ' . $short . '?', 'a' => 'Graduates work as ' . implode(', ', array_slice($careers, 0, -1)) . (count($careers) > 1 ? ' and ' : '') . end($careers) . '. Our placement cell supports every student with training, internships and placement drives.'];
}
$faqs[] = ['q' => 'How do I apply for ' . $short . '?', 'a' => 'Apply online at ' . absolute_url('apply?program=' . $p['slug']) . ', upload your documents and pay the registration fee. For guidance, request a free counselling call or call ' . setting('phone', '+91 9955446477') . '.'];
foreach (sa_faqs(['admissions'], 3) as $f) {
    $faqs[] = $f;
}

// JSON-LD
$iso = fmod($years, 1.0) ? 'P' . (int) round($years * 12) . 'M' : 'P' . (int) $years . 'Y';
$courseLd = array_filter([
    '@context' => 'https://schema.org', '@type' => 'Course', 'name' => $name, 'courseCode' => $p['code'],
    'description' => str_limit(strip_tags((string) ($p['overview'] ?: $name)), 300), 'url' => absolute_url('programs/' . $p['slug']),
    'image' => sa_abs_image($image), 'educationalLevel' => $level, 'educationalCredentialAwarded' => $p['degree'] ?: $level,
    'timeRequired' => $iso, 'numberOfCredits' => $p['total_credits'] ? (int) $p['total_credits'] : null, 'inLanguage' => 'en-IN',
    'coursePrerequisites' => $elig ?: null,
    'provider' => ['@type' => 'CollegeOrUniversity', 'name' => institute_name(), 'sameAs' => absolute_url('')],
    'offers' => (float) $p['fee_per_year'] > 0 ? [['@type' => 'Offer', 'category' => 'Paid', 'price' => (float) $p['fee_per_year'], 'priceCurrency' => 'INR', 'url' => absolute_url('apply?program=' . $p['slug'])]] : null,
    'hasCourseInstance' => [['@type' => 'CourseInstance', 'courseMode' => 'Onsite', 'courseWorkload' => $iso,
        'location' => ['@type' => 'Place', 'name' => institute_name(), 'address' => setting('address', 'Knowledge Park, Greater Noida, Uttar Pradesh')]]],
], fn ($v) => $v !== null && $v !== '');

site_header([
    'title' => $name . ($suffix !== '' ? ' (' . $suffix . ')' : '') . ' — Fees, Eligibility & Syllabus',
    'description' => str_limit($name . ' at GIMT Greater Noida: ' . $duration . ' ' . strtolower($level) . ' program. ' . ($fee !== '' ? 'Fee ' . $fee . '. ' : '') . ($elig !== '' ? 'Eligibility: ' . $elig . ' ' : '') . (string) $p['overview'], 300),
    'active' => 'programs',
    'seo' => ['program', $id],
    'image' => $image,
    'preload' => [site_image('assets/images/site/campus-building.jpg'), $image],
    'og_type' => 'website',
    'schema' => array_values(array_filter([sa_breadcrumb_ld([['Home', ''], ['Programs', 'programs'], [$short, null]]), sa_seo_schema_has('Course', 'program', $id, null) ? null : $courseLd])),
]);

sa_program_hero($p, [
    'title' => $name . ($suffix !== '' ? ' (' . $suffix . ')' : ''),
    'subtitle' => $level . ' Program' . ($p['category'] && !in_array($p['category'], ['Diploma', 'Certificate'], true) ? ' in ' . $p['category'] : ''),
    'crumbs' => ['Programs' => '/programs', $short],
    'text' => (string) $p['overview'],
    'eyebrow' => ($p['department_name'] ?? '') . ($session && (int) ($session['admissions_open'] ?? 0) ? ' · Admissions Open ' . $session['name'] : ''),
    'actions' => button_link('Apply Now', $applyPath, 'accent', ['shine' => true, 'magnetic' => true, 'size' => 'lg'])
        . '<a href="' . e(sa_brochure_url($p)) . '" target="_blank" rel="noopener" class="btn btn-outline-light btn-lg">' . icon('download', 'h-4 w-4') . 'Download Brochure<span class="sr-only"> (opens in a new tab)</span></a>',
    'chips' => [['clock', $duration . ' · ' . (int) $p['total_semesters'] . ' Semester' . ((int) $p['total_semesters'] === 1 ? '' : 's')], ['users', $p['intake_capacity'] ? (int) $p['intake_capacity'] . ' seats · ' . ($session['name'] ?? '') : $level]],
    'image_alt' => $name . ' students at GIMT',
]);

$facts = [
    ['clock', 'Duration', $duration],
    ['graduation-cap', 'Level', $level],
    ['layers', 'Semesters', (string) (int) $p['total_semesters']],
    ['users', 'Intake', $p['intake_capacity'] ? (int) $p['intake_capacity'] . ' Seats' : 'Limited seats'],
    ['wallet', 'Fee ' . trim((string) ($p['fee_label'] ?: '/ Year')), $fee !== '' ? money($p['fee_per_year']) : 'On request'],
    ['clipboard-check', 'Eligibility', $eligShort ?: 'See below'],
    ['building-2', 'Mode', $mode],
];
$nav = array_filter([
    'overview' => 'Overview',
    'curriculum' => $curriculum ? 'Curriculum' : null,
    'fees' => 'Fees',
    'careers' => $careers ? 'Careers' : null,
    'faculty' => $faculty ? 'Faculty' : null,
    'faqs' => 'FAQs',
]);
?>

<!-- Key facts -->
<div class="relative z-10 -mt-8 sm:-mt-10">
  <div class="container-site">
    <dl class="grid grid-flow-dense grid-cols-2 gap-px overflow-hidden rounded-2xl border border-slate-100 bg-slate-100 shadow-pop sm:grid-cols-4 lg:grid-cols-7">
      <?php foreach ($facts as $i => [$ic, $label, $val]): ?>
        <div class="flex items-center gap-3 bg-white p-4 <?= $label === 'Eligibility' ? 'col-span-2 lg:col-span-1' : '' ?>">
          <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl tint-navy"><?= icon($ic, 'h-5 w-5') ?></span>
          <div class="min-w-0"><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400"><?= e($label) ?></dt><dd class="text-sm font-bold leading-snug text-brand-900"><?= e($val) ?></dd></div>
        </div>
      <?php endforeach; ?>
    </dl>
  </div>
</div>

<?= section_open(['id' => 'program', 'class' => '!pt-12 sm:!pt-14']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="min-w-0 lg:col-span-8">
      <nav aria-label="On this page" class="pill-row scrollbar-none mb-10">
        <?php foreach ($nav as $anchor => $label): ?><a href="#<?= e($anchor) ?>" class="chip"><?= e($label) ?></a><?php endforeach; ?>
      </nav>

      <!-- Overview -->
      <section id="overview" class="scroll-mt-28" aria-labelledby="overview-title"<?= reveal_attr() ?>>
        <p class="eyebrow mb-2">Program Overview</p>
        <h2 id="overview-title" class="section-title">About the <?= e($short) ?> Program</h2>
        <div class="mt-4 space-y-4 text-[15.5px] leading-relaxed text-slate-600">
          <p><?= e((string) $p['overview']) ?></p>
          <p>The program is delivered by the <?= e($p['department_name'] ?? 'GIMT faculty') ?> over <?= (int) $p['total_semesters'] ?> semester<?= (int) $p['total_semesters'] === 1 ? '' : 's' ?><?= $totalCredits ? ' (' . e(rtrim(rtrim(number_format($totalCredits, 1), '0'), '.')) . ' credits)' : '' ?>, combining classroom learning with projects, case studies and industry interaction.</p>
          <?php if ($p['department_description']): ?><p class="flex gap-2 text-sm text-slate-500"><?= icon('school', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') ?><span><strong class="font-semibold text-brand-900">Offered by <?= e($p['department_name']) ?>:</strong> <?= e($p['department_description']) ?></span></p><?php endif; ?>
        </div>
        <?php if ($elig !== ''): ?>
          <div id="eligibility" class="mt-6 flex gap-4 rounded-2xl border border-accent-100 bg-accent-50/60 p-5">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-accent-800 shadow-sm"><?= icon('clipboard-check', 'h-5 w-5') ?></span>
            <div><h3 class="font-display font-bold text-brand-900">Eligibility</h3><p class="mt-1 text-sm leading-relaxed text-slate-700"><?= e($elig) ?></p></div>
          </div>
        <?php endif; ?>
        <?php if ($courses): ?>
          <div class="mt-6">
            <h3 class="font-display font-bold text-brand-900">Specializations offered</h3>
            <ul class="mt-3 flex flex-wrap gap-2">
              <?php foreach ($courses as $c): ?><li class="inline-flex items-center gap-1.5 rounded-full border border-brand-100 bg-brand-50 px-3.5 py-1.5 text-sm font-semibold text-brand-800"><?= icon('sparkles', 'h-3.5 w-3.5 text-accent-600') ?><?= e(preg_replace('/^.*?\s[-–]\s/', '', $c['name'])) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($highlights): ?>
        <!-- Highlights -->
        <section id="highlights" class="mt-14 scroll-mt-28" aria-labelledby="highlights-title">
          <h2 id="highlights-title" class="font-display text-2xl font-extrabold text-brand-900">Program Highlights</h2>
          <ul class="mt-5 grid gap-3 sm:grid-cols-2" data-stagger="60">
            <?php foreach ($highlights as $i => $h): ?>
              <li class="flex items-start gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg <?= ['tint-blue', 'tint-green', 'tint-violet', 'tint-amber', 'tint-cyan', 'tint-rose'][$i % 6] ?>"><?= icon(['briefcase-business', 'presentation', 'award', 'handshake', 'laptop', 'users'][$i % 6], 'h-4 w-4') ?></span>
                <span class="pt-1.5 text-sm font-medium text-slate-700"><?= e($h) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if ($curriculum): ?>
        <!-- Curriculum -->
        <section id="curriculum" class="mt-14 scroll-mt-28" aria-labelledby="curriculum-title">
          <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 id="curriculum-title" class="font-display text-2xl font-extrabold text-brand-900">Curriculum &amp; Subjects</h2>
              <p class="mt-1 text-sm text-slate-500"><?= count($curriculum) ?> semester<?= count($curriculum) === 1 ? '' : 's' ?> · <?= array_sum(array_map('count', $curriculum)) ?> subjects · <?= e(rtrim(rtrim(number_format($totalCredits, 1), '0'), '.')) ?> credits</p>
            </div>
            <a href="<?= e(sa_brochure_url($p)) ?>" target="_blank" rel="noopener" class="link-arrow text-sm"><?= icon('printer', 'h-4 w-4') ?>Printable syllabus<span class="sr-only"> (opens in a new tab)</span></a>
          </div>
          <?php
          $panels = [];
          $tabs = [];
          foreach ($curriculum as $sem => $subjects) {
              $credits = array_sum(array_column($subjects, 'credits'));
              $rows = '';
              foreach ($subjects as $s) {
                  $rows .= '<tr><td class="whitespace-nowrap font-mono text-xs text-slate-500">' . e($s['code']) . '</td><td class="font-medium text-slate-800">' . e($s['name']) . '</td><td>' . sa_subject_type($s) . '</td><td class="text-right font-semibold text-brand-900">' . e(rtrim(rtrim((string) $s['credits'], '0'), '.')) . '</td></tr>';
              }
              $panels[] = '<div class="relative overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="data-table min-w-[520px]"><caption class="sr-only">Semester ' . (int) $sem . ' subjects</caption><thead><tr><th scope="col">Code</th><th scope="col">Subject</th><th scope="col">Type</th><th scope="col" class="text-right">Credits</th></tr></thead><tbody>' . $rows
                  . '</tbody><tfoot><tr><td colspan="3" class="px-4 py-3 text-sm font-semibold text-slate-600">Total — ' . count($subjects) . ' subjects</td><td class="px-4 py-3 text-right font-display font-extrabold text-brand-900">' . e(rtrim(rtrim(number_format($credits, 1), '0'), '.')) . '</td></tr></tfoot></table></div>';
              $tabs[] = ['key' => 'sem-' . (int) $sem, 'label' => (count($curriculum) > 4 ? 'Sem ' : 'Semester ') . (int) $sem];
          }
          echo count($panels) > 1 ? content_tabs($tabs, $panels, ['variant' => 'pills']) : $panels[0];
          ?>
        </section>
      <?php endif; ?>

      <!-- Fees -->
      <section id="fees" class="mt-14 scroll-mt-28" aria-labelledby="fees-title">
        <h2 id="fees-title" class="font-display text-2xl font-extrabold text-brand-900">Fee Structure<?= $session ? ' ' . e($session['name']) : '' ?></h2>
        <?php if ($feeStructures): $fsum = sa_fee_summary($feeStructures); $maxInst = max(array_column($fsum['rows'], 'installments')); ?>
          <?php $oneYear = abs($fsum['first_year'] - $fsum['total']) < 0.01; ?>
          <div class="mt-5 grid gap-3 <?= $oneYear ? 'sm:grid-cols-2' : 'sm:grid-cols-3' ?>">
            <?php if (!$oneYear): ?><div class="rounded-2xl bg-brand-900 p-4 text-white"><p class="text-[11px] font-bold uppercase tracking-wider text-white/60">First-year fee</p><p class="mt-1 font-display text-2xl font-extrabold"><?= e(money($fsum['first_year'])) ?></p></div><?php endif; ?>
            <div class="rounded-2xl p-4 <?= $oneYear ? 'bg-brand-900 text-white' : 'border border-slate-200 bg-white' ?>"><p class="text-[11px] font-bold uppercase tracking-wider <?= $oneYear ? 'text-white/60' : 'text-slate-400' ?>">Total program fee</p><p class="mt-1 font-display text-2xl font-extrabold <?= $oneYear ? '' : 'text-brand-900' ?>"><?= e(money($fsum['total'])) ?></p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4"><p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Payment</p><p class="mt-1 text-sm font-semibold text-brand-900"><?= $maxInst > 1 ? 'Up to ' . (int) $maxInst . ' instalments per term' : 'Per term, before the due date' ?></p></div>
          </div>
          <div class="relative mt-4 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="data-table min-w-[600px]">
              <caption class="sr-only">Fee structure for <?= e($name) ?>, <?= e($session['name'] ?? '') ?></caption>
              <thead><tr><th scope="col">Term</th><th scope="col">Fee components</th><th scope="col" class="text-right">Total</th></tr></thead>
              <tbody>
                <?php foreach ($fsum['rows'] as $r): ?>
                  <tr class="align-top">
                    <td class="whitespace-nowrap"><span class="font-semibold text-brand-900"><?= e($r['label']) ?></span><?php if ($r['year'] !== '' && !str_starts_with($r['label'], 'Year')): ?><span class="block text-xs text-slate-500"><?= e($r['year']) ?></span><?php endif; ?></td>
                    <td class="text-[13px] leading-relaxed text-slate-600"><?= e(implode(' · ', array_map(fn ($it) => $it['name'] . ' ' . money($it['amount']) . ($it['is_optional'] ? ' (optional)' : ''), $r['items'])) ?: '—') ?></td>
                    <td class="whitespace-nowrap text-right font-display font-extrabold text-brand-900"><?= e(money($r['total'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot><tr><td colspan="2" class="px-4 py-3 font-semibold text-slate-700">Total program fee (<?= e($session['name'] ?? '') ?> rates)</td><td class="px-4 py-3 text-right font-display text-lg font-extrabold text-brand-900"><?= e(money($fsum['total'])) ?></td></tr></tfoot>
            </table>
          </div>
        <?php elseif ((float) $p['fee_per_year'] > 0): ?>
          <?php $yearsN = max(1, (int) ceil($years)); $perCourse = stripos((string) $p['fee_label'], 'course') !== false || $years < 1; ?>
          <div class="relative mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="data-table">
              <caption class="sr-only">Tuition fee for <?= e($name) ?></caption>
              <thead><tr><th scope="col"><?= $perCourse ? 'Component' : 'Academic Year' ?></th><th scope="col">Semesters</th><th scope="col" class="text-right">Tuition Fee</th></tr></thead>
              <tbody>
                <?php if ($perCourse): ?>
                  <tr><td class="font-medium text-slate-800">Complete program (<?= e($duration) ?>)</td><td><?= (int) $p['total_semesters'] ?></td><td class="text-right font-semibold text-brand-900"><?= e(money($p['fee_per_year'])) ?></td></tr>
                <?php else: for ($y = 1; $y <= $yearsN; $y++): ?>
                  <tr><td class="font-medium text-slate-800">Year <?= $y ?></td><td><?= e(implode(' & ', array_filter([($y * 2 - 1) <= (int) $p['total_semesters'] ? 'Sem ' . ($y * 2 - 1) : null, ($y * 2) <= (int) $p['total_semesters'] ? 'Sem ' . ($y * 2) : null]))) ?></td><td class="text-right font-semibold text-brand-900"><?= e(money($p['fee_per_year'])) ?></td></tr>
                <?php endfor; endif; ?>
              </tbody>
              <tfoot><tr><td colspan="2" class="px-4 py-3 font-semibold text-slate-700">Total program tuition</td><td class="px-4 py-3 text-right font-display text-lg font-extrabold text-brand-900"><?= e(money($perCourse ? $p['fee_per_year'] : $p['fee_per_year'] * $yearsN)) ?></td></tr></tfoot>
            </table>
          </div>
        <?php else: ?>
          <p class="mt-4 text-slate-600">Fee details for this program are shared during counselling. Request a call back and our team will send you the complete fee structure.</p>
        <?php endif; ?>
        <ul class="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
          <li class="flex gap-2"><?= icon('award', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Merit, sports and need-based scholarships available</li>
          <li class="flex gap-2"><?= icon('calendar-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Semester-wise payment and education-loan assistance</li>
          <li class="flex gap-2"><?= icon('bed-double', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Hostel and transport fees are charged separately</li>
          <li class="flex gap-2"><?= icon('indian-rupee', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Pay online by UPI, card or net banking</li>
        </ul>
      </section>

      <?php if ($careers): ?>
        <!-- Careers -->
        <section id="careers" class="mt-14 scroll-mt-28" aria-labelledby="careers-title">
          <h2 id="careers-title" class="font-display text-2xl font-extrabold text-brand-900">Career Opportunities</h2>
          <p class="mt-1 text-slate-600">Roles our <?= e($short) ?> graduates step into.</p>
          <ul class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3" data-stagger="60">
            <?php foreach ($careers as $i => $c): ?>
              <li class="group flex items-center gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-card">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl <?= ['tint-blue', 'tint-green', 'tint-violet', 'tint-amber', 'tint-cyan', 'tint-rose'][$i % 6] ?> transition group-hover:scale-110"><?= icon(['briefcase-business', 'trending-up', 'target', 'rocket', 'chart-column', 'users'][$i % 6], 'h-5 w-5') ?></span>
                <span class="text-sm font-semibold text-brand-900"><?= e($c) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if ($recruiters): ?>
            <div class="mt-8 rounded-2xl border border-slate-100 bg-slate-50/80 p-5">
              <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-display font-bold text-brand-900">Top Recruiters</h3><?php if (is_file(__DIR__ . '/placement.php')): ?><a href="<?= e(site_url('placement')) ?>" class="link-arrow text-sm">Placement record<?= icon('arrow-right', 'h-4 w-4') ?></a><?php endif; ?></div>
              <ul class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($recruiters as $r): ?><li class="flex h-14 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 transition hover:-translate-y-0.5 hover:shadow-card"><?php if (!empty($r['logo'])): ?><img src="<?= e($r['logo']) ?>" alt="<?= e($r['name']) ?>" title="<?= e($r['name']) ?>" class="h-7 w-auto max-w-[8.5rem] object-contain" loading="lazy" decoding="async"><?php else: ?><span class="font-display text-sm font-extrabold tracking-tight text-slate-700"><?= e($r['name']) ?></span><?php endif; ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($faculty): ?>
        <!-- Faculty -->
        <section id="faculty" class="mt-14 scroll-mt-28" aria-labelledby="faculty-title">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <div><h2 id="faculty-title" class="font-display text-2xl font-extrabold text-brand-900">Learn From Our Faculty</h2><p class="mt-1 text-slate-600"><?= e($p['department_name'] ?? '') ?></p></div>
            <a href="<?= e(site_url('faculty?department=' . strtolower((string) $p['department_code'])) . '#directory') ?>" class="link-arrow text-sm">All department faculty<?= icon('arrow-right', 'h-4 w-4') ?></a>
          </div>
          <ul class="mt-5 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" data-stagger="60">
            <?php foreach ($faculty as $f): $fn = sa_person_name($f); ?>
              <li class="flex flex-col items-center rounded-2xl border border-slate-200/80 bg-white p-4 text-center shadow-sm transition hover:-translate-y-0.5 hover:shadow-card sm:p-5">
                <?= sa_avatar($f['photo'], $fn, (int) $f['id'], 'h-16 w-16 text-lg shadow-soft') ?>
                <p class="mt-3 font-display text-[15px] font-bold leading-snug text-brand-900"><?= e($fn) ?></p>
                <p class="text-xs font-semibold text-accent-800"><?= e($f['designation']) ?></p>
                <?php if ($f['qualification']): ?><p class="mt-1.5 text-xs leading-relaxed text-slate-500"><?= e(str_limit($f['qualification'], 60)) ?></p><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <!-- FAQs -->
      <section id="faqs" class="mt-14 scroll-mt-28" aria-labelledby="faqs-title">
        <h2 id="faqs-title" class="mb-5 font-display text-2xl font-extrabold text-brand-900">Frequently Asked Questions</h2>
        <?= faq_accordion($faqs, ['mode' => 'single', 'defaultOpen' => 0]) ?>
      </section>
    </div>

    <!-- Sidebar -->
    <aside class="lg:col-span-4" aria-label="Apply for <?= e($short) ?>">
      <div class="space-y-4 lg:sticky lg:top-24">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-card">
          <p class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-accent-800"><span class="live-dot pulse-ring" aria-hidden="true"></span><?= e($session && (int) ($session['admissions_open'] ?? 0) ? 'Admissions Open ' . $session['name'] : 'Admissions ' . ($session['name'] ?? '')) ?></p>
          <?php if ($fee !== ''): ?>
            <p class="mt-3 font-display text-3xl font-extrabold text-brand-900"><?= e(money($p['fee_per_year'])) ?> <span class="text-base font-semibold text-slate-500"><?= e(trim((string) ($p['fee_label'] ?: '/ Year'))) ?></span></p>
          <?php endif; ?>
          <p class="mt-1 text-sm text-slate-500"><?= e($duration) ?> · <?= e($level) ?><?= $p['intake_capacity'] ? ' · ' . (int) $p['intake_capacity'] . ' seats' : '' ?></p>
          <div class="mt-5 grid gap-2.5">
            <?= button_link('Apply Now', $applyPath, 'accent', ['shine' => true, 'class' => 'w-full']) ?>
            <a href="<?= e(sa_brochure_url($p)) ?>" target="_blank" rel="noopener" class="btn btn-outline w-full"><?= icon('download', 'h-4 w-4') ?>Download Brochure<span class="sr-only"> (opens in a new tab)</span></a>
          </div>
        </div>
        <?= sa_counselling_form(['program_id' => $id, 'source' => 'program-page', 'title' => 'Have Questions About ' . $short . '?', 'subtitle' => 'Get a free call back from our admission counsellors.', 'submit' => 'Request a Call Back']) ?>
        <?php if ($wa = sa_whatsapp_url('Hello GIMT, I would like to know more about ' . $short . '.')): ?>
          <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="group flex items-center gap-3.5 rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4 transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-card">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#25D366] text-white shadow-sm"><?= icon('whatsapp-brand', 'h-6 w-6') ?></span>
            <span class="min-w-0 flex-1"><span class="block font-display text-[15px] font-bold text-brand-900">Chat on WhatsApp</span><span class="block text-xs text-slate-600">Quick answers about <?= e($short) ?></span></span>
            <?= icon('chevron-right', 'h-4 w-4 text-slate-400 transition group-hover:translate-x-1') ?><span class="sr-only">(opens in a new tab)</span>
          </a>
        <?php endif; ?>
      </div>
    </aside>
  </div>
<?= section_close() ?>

<?php if ($testimonials): ?>
<?= section_open(['bg' => 'gradient', 'pattern' => 'grid', 'label' => 'Student stories']) ?>
  <div class="mb-10 max-w-2xl"<?= reveal_attr('fade-up') ?>>
    <p class="eyebrow mb-2">Student Stories</p>
    <h2 class="section-title !text-white">What Our <?= e($short) ?> Students Say</h2>
  </div>
  <?= testimonial_slider($testimonials, ['theme' => 'dark', 'ariaLabel' => $short . ' student stories', 'perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3, 'xl' => 3]]) ?>
<?= section_close() ?>
<?php endif; ?>

<?php if ($related): ?>
<?= section_open(['id' => 'related', 'bg' => 'light']) ?>
  <?= section_heading('Related Programs', 'Explore other programs students interested in ' . $short . ' also consider.', 'You may also like', 'left', '<a href="' . e(site_url('programs')) . '" class="link-arrow">All programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
  <?= carousel(array_map(fn ($r) => sa_program_card($r), $related), ['ariaLabel' => 'Related programs', 'perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3], 'gap' => 24]) ?>
<?= section_close() ?>
<?php endif; ?>

<?php site_footer([
    'cta_title' => 'Ready to Join ' . $short . ' at GIMT?',
    'cta_text' => 'Applications for the ' . ($session['name'] ?? 'new') . ' session are open. Seats are limited — apply today.',
    'cta_primary' => ['label' => 'Apply for ' . $short, 'url' => $applyPath],
    'cta_secondary' => ['label' => 'Download Brochure', 'url' => sa_brochure_path($p), 'icon' => 'download'],
]);
