<?php
/**
 * Program detail (/programs/{slug}) — hero, key facts, overview + eligibility + specializations, highlights,
 * semester-wise curriculum (Tabs island over the subjects table), fee structure (fee_structures of the current session
 * or the program fee), career opportunities + recruiters, department faculty (public fields), FAQs, sticky apply /
 * counselling sidebar (EnquiryForm with the program preselected), related programs carousel, JSON-LD Course +
 * BreadcrumbList. Unknown slugs get a helpful 404 page.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/certifications.php';

$slug = strtolower((string) ($_GET['slug'] ?? ''));
$program = null;
if (preg_match('/^[a-z0-9-]{1,190}$/', $slug)) {
    $program = sa_try(fn () => db_row("SELECT p.*, d.name AS department_name, d.code AS department_code, d.description AS department_description, d.image AS department_image
        FROM programs p LEFT JOIN departments d ON d.id = p.department_id
        WHERE p.slug = ? AND p.status = 'active' AND p.show_on_website = 1", [$slug]));
}
$all = sa_programs();

/* ------------------------------------------------------------------ level / department landing pages */
// /programs/undergraduate, /programs/postgraduate and /programs/{department} (≥ 2 programs; see sa_landing_pages()).
// A real program slug always wins (sa_landing_pages() skips colliding slugs).
if (!$program && ($lp = sa_landing_pages()[$slug] ?? null)) {
    $list = $lp['programs'];
    $session = sa_session();
    $sName = $session['name'] ?? '';
    $f = sa_institute_facts();
    $isLevel = $lp['type'] === 'level';
    $label = $lp['label'];
    $h1 = $isLevel ? $label . ' Programs in ' . $f['city'] . ($sName ? ' (' . $sName . ')' : '') : $label . ' Programs at ' . $f['short'] . ' ' . $f['city'];
    $shorts = array_map(fn ($p) => ($p['short_name'] ?? '') ?: $p['name'], $list);
    $byYears = $list;
    usort($byYears, fn ($a, $b) => (float) $a['duration_years'] <=> (float) $b['duration_years']);
    [$minD, $maxD] = [sa_duration_text($byYears[0]), sa_duration_text($byYears[count($byYears) - 1])];
    $durText = $minD === $maxD ? $minD : ($minD !== '' && $maxD !== '' ? 'between ' . $minD . ' and ' . $maxD : '');
    if (preg_match('/^([\d.]+) (year|month)s?$/', $minD, $ma) && preg_match('/^([\d.]+) (year|month)s?$/', $maxD, $mb) && $ma[2] === $mb[2] && $ma[1] !== $mb[1]) {
        $durText = $ma[1] . ' to ' . $mb[1] . ' ' . $mb[2] . 's'; // "3 to 4 years"
    }
    $eligs = array_values(array_unique(array_filter(array_map(fn ($p) => sa_eligibility_short((string) $p['eligibility']), $list))));
    $yearFees = array_filter(array_map(fn ($p) => stripos((string) $p['fee_label'], 'year') !== false ? (float) $p['fee_per_year'] : 0.0, $list));
    $seats = array_sum(array_map(fn ($p) => (int) $p['intake_capacity'], $list));
    $levelWord = fn ($p) => strtolower(program_level_label((string) $p['level']));
    $mix = array_count_values(array_map($levelWord, $list));
    $mixText = sa_join(array_map(fn ($k, $n) => $n . ' ' . $k, array_keys($mix), $mix));
    $intro = $f['short'] . ' offers ' . count($list) . ' ' . ($isLevel ? strtolower($label) : '') . ' program' . (count($list) === 1 ? '' : 's') . ($isLevel ? '' : ' in ' . $label . ' (' . $mixText . ')')
        . ($sName ? ' for ' . $sName : '') . ' in ' . $f['city'] . ': ' . sa_join($shorts) . '.'
        . ($durText !== '' ? ' They run for ' . $durText . '.' : '')
        . ($eligs ? ' Eligibility: ' . sa_join($eligs, 'or') . ' (see each program).' : '')
        . ($yearFees ? ' Annual tuition ' . (min($yearFees) === max($yearFees) ? 'is ' . money(min($yearFees)) : 'ranges from ' . money(min($yearFees)) . ' to ' . money(max($yearFees))) . '.' : '');
    $intro = (string) preg_replace('/\s+/', ' ', $intro);

    // Quick answers (data only) → visible block + FAQPage
    $what = $isLevel ? strtolower($label) . ' programs' : $label . ' programs';
    $qa = [['q' => 'Which ' . $what . ' does ' . $f['short'] . ' ' . $f['city'] . ' offer?', 'a' => sa_join(array_map('sa_program_label', $list)) . ($sName ? ' — admissions for ' . $sName . '.' : '.')]];
    // programs that share a value are answered together ("3 years: BBA, BCA …; 4 years: B.Tech …")
    $grouped = function (callable $value) use ($list): array {
        $g = [];
        foreach ($list as $p) {
            if (($v = $value($p)) !== '') {
                $g[$v][] = ($p['short_name'] ?? '') ?: $p['name'];
            }
        }
        return $g;
    };
    $durG = $grouped(fn ($p) => sa_duration_text($p));
    $qa[] = ['q' => 'How long are the ' . $what . '?', 'a' => count($durG) === 1
        ? 'All of them run for ' . array_key_first($durG) . '.'
        : implode('; ', array_map(fn ($d, $names) => ucfirst($d) . ': ' . sa_join($names), array_keys($durG), $durG)) . '.'];
    $eligG = $grouped(fn ($p) => rtrim(trim((string) $p['eligibility']), '.'));
    if ($eligG) {
        $qa[] = ['q' => 'What is the eligibility for ' . $what . ' at ' . $f['short'] . '?', 'a' => count($eligG) === 1 && count(reset($eligG)) === count($list)
            ? 'For every program: ' . array_key_first($eligG) . '.'
            : implode(' ', array_map(fn ($el, $names) => sa_join($names) . ': ' . $el . '.', array_keys($eligG), $eligG))];
    }
    $feeRows = array_values(array_filter(array_map(fn ($p) => (float) $p['fee_per_year'] > 0 ? (($p['short_name'] ?? '') ?: $p['name']) . ' ' . sa_program_fee($p) : '', $list)));
    if ($feeRows) {
        $qa[] = ['q' => 'What are the fees for ' . $what . '?', 'a' => 'Tuition' . ($sName ? ' for ' . $sName : '') . ': ' . sa_join($feeRows) . '. The application (registration) fee is ' . sa_application_fee() . '.'];
    }
    if ($seats > 0) {
        $qa[] = ['q' => 'How many seats are available?', 'a' => sa_join(array_values(array_filter(array_map(fn ($p) => (int) $p['intake_capacity'] > 0 ? (($p['short_name'] ?? '') ?: $p['name']) . ' ' . (int) $p['intake_capacity'] : '', $list)))) . ' seats. ' . sa_admission_window() . '.'];
    }
    $qa[] = ['q' => 'How do I apply?', 'a' => sa_how_to_apply()];
    $qa[] = ['q' => 'Are scholarships available?', 'a' => sa_scholarship_statement() . '.'];
    if ($f['address'] !== '') {
        $qa[] = ['q' => 'Where are classes held?', 'a' => 'At ' . $f['name'] . ' (' . $f['short'] . '), ' . $f['address'] . '.'];
    }
    $qa = array_slice($qa, 0, 8);

    $path = 'programs/' . $lp['slug'];
    $image = $isLevel ? 'assets/images/site/students-laptop.jpg' : sa_department_image($lp['department']);
    $others = array_filter(sa_landing_pages(), fn ($o) => $o['slug'] !== $lp['slug']);
    site_header([
        'title' => site_fit_title($isLevel
            ? [$label . ' Programs in ' . $f['city'] . ($sName ? ' (' . $sName . ')' : '') . ' — ' . implode(', ', array_slice($shorts, 0, 3)), $label . ' Programs in ' . $f['city'] . ($sName ? ' (' . $sName . ')' : ''), $label . ' Programs in ' . $f['city']]
            : [$label . ' Programs in ' . $f['city'] . ' — ' . implode(' & ', array_slice($shorts, 0, 3)), $label . ' Programs in ' . $f['city'], $label . ' Programs'], 60),
        'description' => site_meta_description(count($list) . ' ' . ($isLevel ? strtolower($label) . ' ' : $label . ' ') . 'programs at ' . $f['short'] . ' ' . $f['city'] . ($sName ? ' for ' . $sName : '') . ': ' . implode(', ', $shorts) . '.'
            . ($durText !== '' ? ' ' . ucfirst($durText) . '.' : '') . ($yearFees ? ' Fees from ' . money(min($yearFees)) . '/yr.' : '') . ($eligs ? ' Eligibility: ' . $eligs[0] . '.' : '')),
        'active' => 'programs',
        'seo' => ['route', '/' . $path],
        'canonical' => absolute_url($path),
        'image' => site_image($image),
        'preload' => [site_image($image)],
        'schema' => array_values(array_filter([
            sa_breadcrumb_ld([['Home', ''], ['Programs', 'programs'], [$lp['title'], $path]]),
            seo_ld_course_list($list, $h1),
            seo_ld_faq($qa),
        ])),
    ]);
    page_hero($h1, $isLevel ? implode(' · ', array_slice($shorts, 0, 4)) . (count($shorts) > 4 ? ' and more' : '') : ($lp['department']['name'] ?? $label), ['Programs' => '/programs', $lp['title']], $image, $intro, [
        ['icon' => 'graduation-cap', 'label' => count($list) . ' program' . (count($list) === 1 ? '' : 's')],
        $durText !== '' ? ['icon' => 'clock', 'label' => ucfirst($durText)] : null,
        $seats ? ['icon' => 'users', 'label' => $seats . ' seats' . ($sName ? ' · ' . $sName : '')] : null,
        ['icon' => 'file-check', 'label' => sa_admission_window()],
    ], ['image_alt' => $h1, 'size' => 'md',
        'actions' => button_link('Apply Now', 'apply', 'accent', ['shine' => true]) . button_link('Compare Programs', '#compare', 'outline-light', ['icon' => ''])]);
    ?>
    <?= section_open(['id' => 'programs-list', 'class' => 'scroll-mt-24']) ?>
      <?= section_heading($lp['title'], ($isLevel ? 'Every ' . strtolower($label) . ' program' : 'Every program of ' . ($lp['department']['name'] ?? $label)) . ($sName ? ' open for ' . $sName : '') . '.', 'Explore', 'left', '<a href="' . e(site_url('programs')) . '" class="link-arrow">All programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
      <?php if (!$isLevel && trim((string) ($lp['department']['description'] ?? '')) !== ''): ?><p class="-mt-4 mb-8 max-w-3xl text-[15px] leading-relaxed text-slate-600"><?= e((string) $lp['department']['description']) ?></p><?php endif; ?>
      <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3" data-stagger="80">
        <?php foreach ($list as $p): ?><?= $p['level'] === 'Certificate' && function_exists('cert_card') ? cert_card($p) : sa_program_card($p) ?><?php endforeach; ?>
      </div>
    <?= section_close() ?>

    <?= section_open(['id' => 'compare', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
      <?= section_heading('Compare ' . $lp['title'], 'Duration, eligibility, annual tuition and seats' . ($sName ? ' for ' . $sName : '') . '.', 'At a glance') ?>
      <div class="relative overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="data-table min-w-[720px]">
          <caption class="sr-only"><?= e($lp['title']) ?> compared</caption>
          <thead><tr><th scope="col">Program</th><th scope="col">Duration</th><th scope="col">Eligibility</th><th scope="col" class="text-right">Fee</th><th scope="col" class="text-right">Seats</th></tr></thead>
          <tbody>
            <?php foreach ($list as $p): ?>
              <tr class="align-top">
                <th scope="row" class="font-semibold"><a href="<?= e(site_url('programs/' . $p['slug'])) ?>" class="text-brand-900 underline decoration-brand-200 underline-offset-4 hover:text-accent-800"><?= e(sa_program_label($p)) ?></a></th>
                <td class="whitespace-nowrap"><?= e((string) ($p['duration_label'] ?: sa_duration_text($p))) ?><?= (int) $p['total_semesters'] > 1 ? '<span class="block text-xs text-slate-500">' . (int) $p['total_semesters'] . ' semesters</span>' : '' ?></td>
                <td class="text-[13px] leading-relaxed text-slate-600"><?= e((string) $p['eligibility']) ?></td>
                <td class="whitespace-nowrap text-right font-semibold text-brand-900"><?= e(sa_program_fee($p) ?: 'On request') ?></td>
                <td class="text-right"><?= (int) $p['intake_capacity'] ?: '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="mt-4 flex items-start gap-2 text-sm text-slate-500"><?= icon('info', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') ?>Tuition fees<?= $sName ? ' for ' . e($sName) : '' ?>. Application (registration) fee <?= e(sa_application_fee()) ?>. <?= e(sa_scholarship_statement()) ?>.</p>
    <?= section_close() ?>

    <?= section_open(['id' => 'faqs', 'class' => 'scroll-mt-24']) ?>
      <div class="grid gap-10 lg:grid-cols-12">
        <div class="min-w-0 lg:col-span-8"><?= sa_quick_answers_html($qa, 'Questions about ' . $lp['title'] . ' at ' . $f['short'], 'quick-answers') ?></div>
        <aside class="lg:col-span-4" aria-label="Get counselling">
          <div class="space-y-4 lg:sticky lg:top-24">
            <?= sa_counselling_form(['source' => 'program-landing', 'title' => 'Need Help Choosing?', 'subtitle' => 'Compare ' . strtolower($lp['title']) . ' with an admission counsellor.', 'options' => site_program_select_options($list, true)]) ?>
            <?php if ($others): ?>
              <nav class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm" aria-label="More program groups">
                <p class="font-display text-[15px] font-bold text-brand-900">Explore more</p>
                <ul class="mt-3 flex flex-wrap gap-2">
                  <?php foreach ($others as $o): ?><li><a href="<?= e(site_url('programs/' . $o['slug'])) ?>" class="chip"><?= e($o['title']) ?></a></li><?php endforeach; ?>
                  <?php if (is_file(__DIR__ . '/certifications.php')): ?><li><a href="<?= e(site_url('certifications')) ?>" class="chip">Certificate Courses</a></li><?php endif; ?>
                </ul>
              </nav>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    <?= section_close() ?>
    <?php
    site_footer(['cta_title' => 'Find Your ' . ($isLevel ? $label : $label) . ' Program', 'cta_text' => sa_admission_window() . '. Application (registration) fee ' . sa_application_fee() . '.',
        'cta_primary' => ['label' => 'Apply Now', 'url' => '/apply'], 'cta_secondary' => ['label' => 'Talk to a Counsellor', 'url' => '/contact?type=program#message', 'icon' => 'phone']]);
    exit;
}

/* Short keys people type or print (/programs/mba, /programs/b-tech-cse): program code or slugified short name → 301 to
   the canonical slug URL (same resolution as the campaign landings /admissions-open/{key}). */
if (!$program && $slug !== '') {
    foreach ($all as $p) {
        if ($slug === strtolower((string) ($p['code'] ?? '')) || ($p['short_name'] ?? '') !== '' && $slug === slugify((string) $p['short_name'])) {
            header('Location: ' . site_url('programs/' . $p['slug']), true, 301);
            exit;
        }
    }
}

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
    site_header(['title' => 'Program Not Found', 'description' => 'The program you are looking for could not be found. Explore all programs offered at GIMT, Noida.',
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
// one credits figure everywhere (page, curriculum table, /programs cards, Course JSON-LD, FAQs): sa_program_credits()
$totalCredits = sa_program_credits($p, (float) array_sum(array_map(fn ($rows) => array_sum(array_column($rows, 'credits')), $curriculum)));
$p['total_credits'] = $totalCredits;
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
// certificate courses: modules instead of semesters/credits, their own listing page (/certifications), course wording
$isCert = $p['level'] === 'Certificate' && is_file(__DIR__ . '/certifications.php');
$certGroup = $isCert ? (site_cert_groups()[site_cert_group_key($p)] ?? null) : null;
$moduleCount = array_sum(array_map('count', $curriculum));
// certificate courses whose highlights say they also run online (e.g. BFSI & Soft Skills: "Online & offline classes") show both modes
$mode = $isCert ? (preg_match('/\bonline\b/i', (string) ($p['highlights'] ?? '')) ? 'Online & Offline' : 'Classroom · On Campus') : 'Full-Time · On Campus';

// short eligibility for the facts strip
$elig = trim((string) $p['eligibility']);
$eligShort = sa_eligibility_short($elig);
// modules of a certificate course: the listed modules, the final project counted separately ("6 modules + final project")
$certMods = $isCert && $curriculum ? array_merge(...array_values($curriculum)) : [];
$certProjects = count(array_filter($certMods, fn ($m) => ($m['type'] ?? '') === 'project'));
$certModText = $certMods ? (count($certMods) - $certProjects) . ' module' . (count($certMods) - $certProjects === 1 ? '' : 's') . ($certProjects ? ' + final project' : '') : '';

// related programs: same category / department first, then same level
$related = [];
foreach ([
    fn ($r) => $r['category'] === $p['category'],
    fn ($r) => (int) $r['department_id'] === (int) $p['department_id'],
    fn ($r) => $r['level'] === $p['level'],
    fn ($r) => true,
] as $test) {
    foreach ($all as $r) {
        if (count($related) >= 6) {
            break 2;
        }
        if ((int) $r['id'] !== $id && $test($r)) {
            $related[$r['id']] = $r;
        }
    }
}
$related = array_values($related);

// Quick answers (duration, eligibility, fee, seats, specialisations / subjects, how to apply, where) — built only from this
// program's row + the published admission facts; shown near the top and merged with the FAQs below into one FAQPage.
$feeTotal = null;
if ($feeStructures) {
    $feeTotal = sa_fee_summary($feeStructures)['total'] ?: null;
} elseif ((float) $p['fee_per_year'] > 0 && stripos((string) $p['fee_label'], 'year') !== false && $years >= 1) {
    $feeTotal = (float) $p['fee_per_year'] * max(1, (int) ceil($years));
}
$quick = sa_program_quick_answers($p, ['curriculum' => $curriculum, 'courses' => $courses, 'credits' => $totalCredits, 'fee_total' => $feeTotal]);

// FAQs: what the quick answers do not cover (no repeated question), then CMS admission FAQs
$faqs = [];
if ($fee === '' && $isCert) {
    $faqs[] = ['q' => 'What is the fee for ' . $short . '?', 'a' => 'Our counsellors share the current course fee when you enquire — request a call back using the form on this page. The application (registration) fee is ' . sa_application_fee() . '.'];
}
if ($careers && $isCert) {
    $faqs[] = ['q' => 'Where can I use the skills from ' . $short . '?', 'a' => 'The course is useful for ' . sa_join($careers) . '. ' . sa_placement_statement()];
} elseif ($careers) {
    $faqs[] = ['q' => 'What career options are available after ' . $short . '?', 'a' => 'Roles this program prepares you for: ' . sa_join($careers) . '. ' . sa_placement_statement()];
}
if (!$isCert) {
    $faqs[] = ['q' => 'Are scholarships available for ' . $short . '?', 'a' => sa_scholarship_statement() . '. Tuition can be paid semester-wise.'];
}
if ($isCert) {
    $faqs[] = ['q' => 'Will I get a certificate?', 'a' => 'Yes. When you complete the modules and the final assessment you receive a GIMT course completion certificate with a unique certificate number and QR code' . (is_file(__DIR__ . '/verify-certificate.php') ? ' that anyone can check at ' . absolute_url('verify-certificate') : '') . '.'];
}
$hours = trim((string) setting('office_hours', ''));
$faqs[] = ['q' => 'Can I talk to a counsellor about ' . $short . '?', 'a' => 'Yes. Request a free call back using the form on this page or call ' . setting('phone', '+91 9955446477') . ($hours !== '' ? ' (' . $hours . ')' : '') . '.'];
$quickQs = array_map(fn ($x) => mb_strtolower($x['q']), $quick);
foreach (sa_faqs(['admissions'], 3) as $f) {
    if (!in_array(mb_strtolower($f['q']), $quickQs, true)) {
        $faqs[] = $f;
    }
}

// JSON-LD: Course (offers / course instances only from the program row: fee, duration, mode) + FAQPage of the FAQs
// rendered below + BreadcrumbList. The provider is the site-wide organisation (@id), not a copy.
$courseLd = seo_ld_course($p, ['image' => $image]);
$city = trim((string) setting('city', 'Noida')) ?: 'Noida';
$label = $name . ($suffix !== '' ? ' (' . $suffix . ')' : '');
$feeShort = $fee !== '' ? 'Fee ' . money($p['fee_per_year']) . ' ' . trim((string) ($p['fee_label'] ?: '/ Year')) . '. ' : '';

site_header([
    // unique per program and within ~60 characters with the " | GIMT" suffix: the richest wording that fits
    // ("Fees" only when a fee is published — a title must not promise what the page does not show)
    'title' => site_fit_title(array_values(array_filter([
        $fee !== '' ? $label . ' in ' . $city . ' — Fees, Eligibility, Syllabus' : $label . ' in ' . $city . ' — Eligibility & Syllabus',
        $fee !== '' ? $label . ' in ' . $city . ' — Fees & Eligibility' : $label . ' in ' . $city . ' — Eligibility',
        $fee !== '' ? $label . ' in ' . $city . ' — Fees' : null,
        $label . ' in ' . $city,
        $name . ' in ' . $city,
        $label,
        $name,
    ])), 60),
    'description' => site_meta_description($name . ' at GIMT ' . $city . ': ' . $duration . ' ' . strtolower($level) . ($isCert ? ' course' : ' program') . '. '
        . $feeShort . ($eligShort !== '' ? 'Eligibility: ' . $eligShort . '. ' : '') . (string) $p['overview']),
    'active' => 'programs',
    'seo' => ['program', $id],
    'image' => $image,
    'image_alt' => $name . ' students at GIMT',
    'preload' => [site_image('assets/images/site/campus-building.jpg'), $image],
    'og_type' => 'website',
    'schema' => array_values(array_filter([
        sa_breadcrumb_ld($isCert ? [['Home', ''], ['Certificate Courses', 'certifications'], [$short, null]] : [['Home', ''], ['Programs', 'programs'], [$short, null]]),
        sa_seo_schema_has('Course', 'program', $id, null) ? null : $courseLd,
        sa_seo_schema_has('FAQPage', 'program', $id, null) ? null : seo_ld_faq(array_merge($quick, $faqs)),
    ])),
]);

sa_program_hero($p, [
    'title' => $name . ($suffix !== '' ? ' (' . $suffix . ')' : ''),
    'subtitle' => $isCert ? 'Certificate Course' . ($certGroup ? ' · ' . $certGroup['label'] : '') : $level . ' Program' . ($p['category'] && !in_array($p['category'], ['Diploma', 'Certificate'], true) ? ' in ' . $p['category'] : ''),
    'crumbs' => $isCert ? ['Programs' => '/programs', 'Certificate Courses' => '/certifications', $short] : ['Programs' => '/programs', $short],
    'text' => (string) $p['overview'],
    'eyebrow' => ($p['department_name'] ?? '') . ($session && (int) ($session['admissions_open'] ?? 0) ? ' · Admissions Open ' . $session['name'] : ''),
    'actions' => button_link('Apply Now', $applyPath, 'accent', ['gleam' => true, 'magnetic' => true, 'size' => 'lg'])
        . '<a href="' . e(sa_brochure_url($p)) . '" target="_blank" rel="noopener" class="btn btn-outline-light btn-lg">' . icon('download', 'h-4 w-4') . 'Download Brochure<span class="sr-only"> (opens in a new tab)</span></a>',
    'chips' => [['clock', $duration . ' · ' . ($isCert && $certModText !== '' ? ucfirst($certModText) : (int) $p['total_semesters'] . ' Semester' . ((int) $p['total_semesters'] === 1 ? '' : 's'))], ['users', $p['intake_capacity'] ? (int) $p['intake_capacity'] . ' seats · ' . ($session['name'] ?? '') : $level]],
    'image_alt' => $name . ' students at GIMT',
]);

$facts = [
    ['clock', 'Duration', $duration],
    ['graduation-cap', 'Level', $level],
    $isCert ? ['layers', 'Modules', $certModText !== '' ? ucfirst($certModText) : '—'] : ['layers', 'Semesters', (string) (int) $p['total_semesters']],
    $isCert ? ['award', 'Certificate', 'GIMT · QR verified'] : ['users', 'Intake', $p['intake_capacity'] ? (int) $p['intake_capacity'] . ' Seats' : 'Limited seats'],
    ['wallet', 'Fee ' . trim((string) ($p['fee_label'] ?: '/ Year')), $fee !== '' ? money($p['fee_per_year']) : 'On request'],
    ['clipboard-check', 'Eligibility', $eligShort ?: 'See below'],
    ['building-2', 'Mode', $mode],
];
// optional long-form details (calendar, timetable, assessment …) — admin rich text, cleaned like every CMS field
$details = trim((string) ($p['details_html'] ?? '')) !== '' ? sanitize_html((string) $p['details_html']) : '';
$nav = array_filter([
    'overview' => 'Overview',
    'quick-answers' => $quick ? 'Quick Answers' : null,
    'curriculum' => $curriculum ? ($isCert ? 'Modules' : 'Curriculum') : null,
    'fees' => 'Fees',
    'careers' => $careers ? 'Careers' : null,
    'details' => $details !== '' ? 'Details' : null,
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
          <div class="min-w-0"><dt class="text-[11px] font-bold uppercase tracking-wider text-slate-500"><?= e($label) ?></dt><dd class="text-sm font-bold leading-snug text-brand-900"><?= e($val) ?></dd></div>
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
          <?php if ($isCert): ?>
          <p>The course runs for <?= e(strtolower($duration)) ?><?= $certModText !== '' ? ' and covers ' . e($certModText) : '' ?><?= $mode === 'Online & Offline' ? ', with online and offline classes' : '' ?>.</p>
          <?php else: ?>
          <p>The program is offered by the <?= e($p['department_name'] ?? 'GIMT faculty') ?> over <?= (int) $p['total_semesters'] ?> semester<?= (int) $p['total_semesters'] === 1 ? '' : 's' ?><?= $totalCredits ? ' (' . e(sa_num($totalCredits)) . ' credits)' : '' ?>, combining classroom learning with projects and case studies.</p>
          <?php endif; ?>
          <?php if ($p['department_description']): ?><p class="flex gap-2 text-sm text-slate-500"><?= icon('school', 'mt-0.5 h-4 w-4 shrink-0 text-brand-500') ?><span><strong class="font-semibold text-brand-900">Offered by <?= e($p['department_name']) ?>:</strong> <?= e($p['department_description']) ?></span></p><?php endif; ?>
        </div>
        <?php if ($elig !== ''): ?>
          <div id="eligibility" class="mt-6 flex gap-4 rounded-2xl border border-accent-100 bg-accent-50/60 p-5">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-accent-800 shadow-sm"><?= icon('clipboard-check', 'h-5 w-5') ?></span>
            <div><h3 class="font-display font-bold text-brand-900">Eligibility</h3><p class="mt-1 text-sm leading-relaxed text-slate-700"><?= e($elig) ?></p></div>
          </div>
        <?php endif; ?>
        <?php if ($courses): ?>
          <div id="specializations" class="mt-6 scroll-mt-28">
            <h3 class="font-display font-bold text-brand-900">Specializations offered</h3>
            <ul class="mt-3 flex flex-wrap gap-2">
              <?php foreach ($courses as $c): ?><li class="inline-flex items-center gap-1.5 rounded-full border border-brand-100 bg-brand-50 px-3.5 py-1.5 text-sm font-semibold text-brand-800"><?= icon('sparkles', 'h-3.5 w-3.5 text-accent-600') ?><?= e(preg_replace('/^.*?\s[-–]\s/', '', $c['name'])) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($quick): ?>
        <!-- Quick answers (data-driven; also in the FAQPage JSON-LD) -->
        <div class="mt-14"><?= sa_quick_answers_html($quick, 'Quick answers about ' . $short, 'quick-answers') ?></div>
      <?php endif; ?>

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

      <?php if ($curriculum && $isCert): $mods = array_merge(...array_values($curriculum)); ?>
        <!-- Course modules (certificate courses: a numbered list, no semesters / credits) -->
        <section id="curriculum" class="mt-14 scroll-mt-28" aria-labelledby="curriculum-title">
          <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 id="curriculum-title" class="font-display text-2xl font-extrabold text-brand-900">Course Modules</h2>
              <p class="mt-1 text-sm text-slate-500"><?= e(ucfirst($certModText)) ?> · <?= e($duration) ?></p>
            </div>
            <a href="<?= e(sa_brochure_url($p)) ?>" target="_blank" rel="noopener" class="link-arrow text-sm"><?= icon('printer', 'h-4 w-4') ?>Printable syllabus<span class="sr-only"> (opens in a new tab)</span></a>
          </div>
          <ol class="grid gap-3 sm:grid-cols-2" data-stagger="50">
            <?php foreach ($mods as $i => $s): $final = $s['type'] === 'project';
                // module topics (subjects.description, "a; b; c"): the first 4 as chips, the rest behind "+N more topics"
                $topics = array_values(array_filter(array_map('trim', preg_split('/;\s*/', rtrim(trim((string) ($s['description'] ?? '')), '.')) ?: [])));
                $chip = fn (string $t) => '<li class="rounded-full bg-slate-100 px-2.5 py-1 text-[12px] leading-tight text-slate-700 dark:bg-white/10 dark:text-slate-200">' . e(ucfirst($t)) . '</li>'; ?>
              <li class="flex items-start gap-3.5 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl font-display text-sm font-extrabold <?= $final ? 'bg-accent-600 text-white' : 'tint-navy' ?>"><?= $final ? icon('award', 'h-4 w-4') : $i + 1 ?></span>
                <div class="min-w-0 flex-1 pt-1.5">
                  <p class="text-[15px] font-semibold leading-snug text-brand-900"><?= e($s['name']) ?></p>
                  <?php if ($final): ?><p class="mt-0.5 text-xs text-slate-500">Final project / assessment</p><?php endif; ?>
                  <?php if ($topics): ?>
                    <ul class="mt-2.5 flex flex-wrap gap-1.5" aria-label="Topics"><?= implode('', array_map($chip, array_slice($topics, 0, 4))) ?></ul>
                    <?php if (count($topics) > 4): ?>
                      <details class="module-more mt-2">
                        <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-[12.5px] font-semibold text-accent-700 hover:text-accent-800"><span class="module-more-open">+<?= count($topics) - 4 ?> more topics</span><span class="module-more-close">Show fewer</span><?= icon('chevron-down', 'h-3.5 w-3.5 transition-transform') ?></summary>
                        <ul class="mt-2 flex flex-wrap gap-1.5"><?= implode('', array_map($chip, array_slice($topics, 4))) ?></ul>
                      </details>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
        </section>
      <?php elseif ($curriculum): ?>
        <!-- Curriculum -->
        <section id="curriculum" class="mt-14 scroll-mt-28" aria-labelledby="curriculum-title">
          <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 id="curriculum-title" class="font-display text-2xl font-extrabold text-brand-900">Curriculum &amp; Subjects</h2>
              <p class="mt-1 text-sm text-slate-500"><?= count($curriculum) ?> semester<?= count($curriculum) === 1 ? '' : 's' ?> · <?= array_sum(array_map('count', $curriculum)) ?> subjects · <?= e(sa_num($totalCredits)) ?> credits</p>
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
            <div class="rounded-2xl p-4 <?= $oneYear ? 'bg-brand-900 text-white' : 'border border-slate-200 bg-white' ?>"><p class="text-[11px] font-bold uppercase tracking-wider <?= $oneYear ? 'text-white/75' : 'text-slate-500' ?>">Total program fee</p><p class="mt-1 font-display text-2xl font-extrabold <?= $oneYear ? '' : 'text-brand-900' ?>"><?= e(money($fsum['total'])) ?></p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4"><p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Payment</p><p class="mt-1 text-sm font-semibold text-brand-900"><?= $maxInst > 1 ? 'Up to ' . (int) $maxInst . ' instalments per term' : 'Per term, before the due date' ?></p></div>
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
        <?php if ($isCert): ?>
        <ul class="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
          <li class="flex gap-2"><?= icon('award', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>GIMT course completion certificate included</li>
          <li class="flex gap-2"><?= icon('file-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Application (registration) fee <?= e(sa_application_fee()) ?></li>
          <li class="flex gap-2"><?= icon('indian-rupee', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Pay online by UPI, card or net banking</li>
        </ul>
        <?php else: ?>
        <ul class="mt-4 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
          <li class="flex gap-2 sm:col-span-2"><?= icon('award', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><?= e(sa_scholarship_statement()) ?></li>
          <li class="flex gap-2"><?= icon('calendar-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Semester-wise payment and education-loan assistance</li>
          <li class="flex gap-2"><?= icon('file-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Application (registration) fee <?= e(sa_application_fee()) ?></li>
          <li class="flex gap-2"><?= icon('indian-rupee', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?>Pay online by UPI, card or net banking</li>
        </ul>
        <?php endif; ?>
      </section>

      <?php if ($careers): ?>
        <!-- Careers -->
        <section id="careers" class="mt-14 scroll-mt-28" aria-labelledby="careers-title">
          <h2 id="careers-title" class="font-display text-2xl font-extrabold text-brand-900"><?= $isCert ? 'Where These Skills Help' : 'Career Opportunities' ?></h2>
          <p class="mt-1 text-slate-600"><?= $isCert ? 'Roles and goals this course prepares you for.' : 'Roles this program prepares you for.' ?></p>
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
              <div class="flex flex-wrap items-center justify-between gap-2"><h3 class="font-display font-bold text-brand-900">Top Recruiters</h3><?php if (is_file(__DIR__ . '/placement.php')): ?><a href="<?= e(site_url('placement')) ?>" class="link-arrow text-sm">Placement assistance<?= icon('arrow-right', 'h-4 w-4') ?></a><?php endif; ?></div>
              <ul class="mt-4 flex flex-wrap gap-2">
                <?php foreach ($recruiters as $r): ?><li class="flex h-14 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 py-2 transition hover:-translate-y-0.5 hover:shadow-card"><?php if (!empty($r['logo'])): ?><img src="<?= e($r['logo']) ?>" alt="<?= e($r['name']) ?>" title="<?= e($r['name']) ?>"<?= seo_ld_img_dims($r['logo']) ?> class="h-7 w-auto max-w-[8.5rem] object-contain" loading="lazy" decoding="async"><?php else: ?><span class="font-display text-sm font-extrabold tracking-tight text-slate-700"><?= e($r['name']) ?></span><?php endif; ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($details !== ''): ?>
        <!-- Complete program details (Academics › Programs › "Complete program details", rich text) -->
        <section id="details" class="mt-14 scroll-mt-28" aria-labelledby="details-title">
          <h2 id="details-title" class="font-display text-2xl font-extrabold text-brand-900">Complete Program Details</h2>
          <?php
          // one collapsible panel per <h3> heading of the rich text (the first one open); text before the first heading stays on top
          $intro = $details;
          $parts = [];
          if (preg_match_all('~<h3[^>]*>(.*?)</h3>(.*?)(?=<h3[\s>]|\z)~s', $details, $dm, PREG_SET_ORDER)) {
              $intro = trim(substr($details, 0, (int) strpos($details, $dm[0][0])));
              foreach ($dm as $m) {
                  $parts[] = [trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')), trim($m[2])];
              }
          }
          ?>
          <?php if (!$parts): ?>
            <div class="prose-site program-details mt-5"><?= $details ?></div>
          <?php else: ?>
            <?php if ($intro !== ''): ?><div class="prose-site program-details mt-5"><?= $intro ?></div><?php endif; ?>
            <div class="mt-5 space-y-3">
              <?php foreach ($parts as $k => [$ptitle, $pbody]): ?>
                <details class="detail-panel group overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm"<?= $k === 0 ? ' open' : '' ?>>
                  <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 font-display text-[16px] font-bold text-brand-900 hover:bg-slate-50/70">
                    <span class="flex items-center gap-3"><span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg tint-navy text-xs font-extrabold"><?= $k + 1 ?></span><?= e($ptitle) ?></span>
                    <?= icon('chevron-down', 'h-5 w-5 shrink-0 text-slate-400 transition-transform group-open:rotate-180') ?>
                  </summary>
                  <div class="prose-site program-details border-t border-slate-100 px-5 pb-5 pt-1"><?= $pbody ?></div>
                </details>
              <?php endforeach; ?>
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
  <?php if ($isCert): ?>
    <?= section_heading('Related Courses', 'Other certificate courses learners interested in ' . $short . ' also take.', 'You may also like', 'left', '<a href="' . e(site_url('certifications')) . '" class="link-arrow">All certificate courses' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
    <?= carousel(array_map(fn ($r) => $r['level'] === 'Certificate' ? cert_card($r) : sa_program_card($r), $related), ['ariaLabel' => 'Related courses', 'perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3], 'gap' => 24]) ?>
  <?php else: ?>
    <?= section_heading('Related Programs', 'Explore other programs students interested in ' . $short . ' also consider.', 'You may also like', 'left', '<a href="' . e(site_url('programs')) . '" class="link-arrow">All programs' . icon('arrow-right', 'h-4 w-4') . '</a>') ?>
    <?= carousel(array_map(fn ($r) => sa_program_card($r), $related), ['ariaLabel' => 'Related programs', 'perView' => ['base' => 1.1, 'sm' => 2, 'lg' => 3], 'gap' => 24]) ?>
  <?php endif; ?>
<?= section_close() ?>
<?php endif; ?>

<?php site_footer([
    'cta_title' => 'Ready to Join ' . $short . ' at GIMT?',
    'cta_text' => $isCert ? 'Learn practical skills in ' . strtolower($duration) . ' and earn a GIMT course completion certificate.' : 'Admissions ' . ($session['name'] ?? '') . ': ' . lcfirst(sa_admission_window()) . '. Application (registration) fee ' . sa_application_fee() . '.',
    'cta_primary' => ['label' => 'Apply for ' . $short, 'url' => $applyPath],
    'cta_secondary' => ['label' => 'Download Brochure', 'url' => sa_brochure_path($p), 'icon' => 'download'],
]);
