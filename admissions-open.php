<?php
/**
 * Campaign landing pages for ad traffic (Design v3 + the Online Manipal ad-landing structure):
 *   /admissions-open           general landing (all programs, "Choose your program" index)
 *   /admissions-open/{key}     program landing — {key} = program slug | lower(code) | slugify(short_name)
 *                              (e.g. /admissions-open/mba, /admissions-open/master-of-business-administration,
 *                              /admissions-open/btcse, /admissions-open/b-tech-cse); unknown keys → friendly 404.
 * One data-driven template: every fact comes from the DB (programs, departments, courses, subjects, fee_structures,
 * scholarships, admissions, placement_offers, companies, testimonials / alumni_success_stories / alumni, faqs) or
 * settings (admissions_deadline, landing_*, approvals_list / affiliation, phone, whatsapp_number) — nothing invented;
 * sections without data are hidden. Lead forms POST to /api/public/enquiry with source=landing_{code} + UTM/gclid.
 * SEO: unique title/description, canonical to itself, robots noindex,follow unless setting landing_indexable = 1,
 * Course + FAQPage JSON-LD. Not linked from the site navigation (ads only).
 */
require __DIR__ . '/includes/config.php';

$programs = site_programs();
$key = strtolower(trim((string) ($_GET['program'] ?? ''), " /\t\n"));
$program = null;
if ($key !== '') {
    foreach ($programs as $p) {
        if ($key === strtolower((string) $p['slug']) || $key === strtolower((string) $p['code']) || $key === slugify((string) $p['short_name'])) {
            $program = $p;
            break;
        }
    }
}
$phone = (string) setting('phone', '+91 9955446477');
$robots = setting('landing_indexable', '0') === '1' ? 'index,follow' : 'noindex,follow';
$landingUrl = fn (?array $p = null) => site_url('admissions-open' . ($p ? '/' . $p['slug'] : ''));
$session = null;
try {
    $session = db_row('SELECT id, name, start_date FROM academic_sessions WHERE admissions_open = 1 ORDER BY is_current DESC, start_date DESC LIMIT 1')
        ?: db_row('SELECT id, name, start_date FROM academic_sessions WHERE is_current = 1 LIMIT 1');
} catch (Throwable $e) {
}
$sessionName = (string) ($session['name'] ?? '2026-27');

/* ------------------------------------------------------------------ unknown program key → friendly not-found */
if ($key !== '' && !$program) {
    http_response_code(404);
    site_header(['title' => 'Program not found', 'robots' => 'noindex,follow', 'header' => 'minimal', 'header_cta' => ['label' => 'Apply Now', 'url' => '/apply'], 'announce' => false, 'loader' => false]);
    ?>
    <section class="lp-hero">
      <?= geo_shapes('band', 'hidden md:block') ?>
      <div class="container-site relative py-16 sm:py-20">
        <p class="hero-eyebrow"><span class="hero-eyebrow-dot"></span>Admissions <?= e($sessionName) ?></p>
        <h1 class="lp-hero-title max-w-3xl">We couldn’t find that program page</h1>
        <p class="mt-4 max-w-2xl text-lg text-white/80">The link may be outdated. Choose one of our programs below to see its admission details, fees, scholarships and the free counselling form.</p>
        <div class="mt-8 flex flex-wrap gap-3"><?= button_link('All admission details', $landingUrl(), 'apply') ?><?= button_link('Explore Courses', '/programs', 'outline-light', ['icon' => '']) ?></div>
      </div>
    </section>
    <?= section_open(['bg' => 'light']) ?>
      <?= section_heading('Choose your program', 'Every program has its own admission page with eligibility, fee structure and scholarships.', 'Admissions ' . $sessionName, 'left', '', ['highlight' => 'program']) ?>
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <?php foreach ($programs as $p): ?>
          <a href="<?= e($landingUrl($p)) ?>" class="mega-card"><span class="mega-card-tag"><?= e($p['short_name']) ?></span><span class="mega-card-name"><?= e($p['name']) ?></span><span class="mega-card-row"><?= dept_chip($p) ?><span class="mega-card-meta"><?= icon('clock', 'h-3.5 w-3.5') ?><?= e($p['duration_label']) ?></span></span></a>
        <?php endforeach; ?>
      </div>
    <?= section_close() ?>
    <?php
    site_footer(['footer' => 'minimal', 'cta' => false, 'enquiry_tab' => false, 'mobile_cta' => ['call' => $phone, 'apply' => ['label' => 'Apply Now', 'url' => '/apply']]]);
    return;
}

/* ------------------------------------------------------------------ facts (DB / settings only) */
$deadline = site_admissions_deadline();
$maxSch = site_max_scholarship();
$approvals = site_approvals();
$waText = 'Hello GIMT, I would like to know more about admission' . ($program ? ' to ' . $program['name'] : 's') . ' (' . $sessionName . ').';
$source = 'landing_' . ($program ? strtolower(preg_replace('/[^a-z0-9]+/i', '_', (string) $program['code'])) : 'admissions_open');
$scholarships = [];
try {
    $scholarships = db_all("SELECT name, type, value, category, criteria, max_amount FROM scholarships WHERE status = 'active' AND category IN ('merit','sports','scholarship','waiver')
                            ORDER BY (type = 'percentage') DESC, value DESC LIMIT 4");
} catch (Throwable $e) {
}
$schLabel = fn (array $s) => $s['type'] === 'percentage' ? rtrim(rtrim(number_format((float) $s['value'], 2), '0'), '.') . '% of tuition' : money($s['value']) . ' per year';
// seats filled (real): confirmed / converted admissions this session vs intake
$seats = null;
if ($program) {
    if (setting('landing_show_seats', '0') === '1' && $session && (int) $program['intake_capacity'] > 0) {
        try {
            $filled = (int) db_value("SELECT COUNT(*) FROM admissions WHERE academic_session_id = ? AND program_id = ? AND (stage = 'confirmed' OR student_id IS NOT NULL)", [(int) $session['id'], (int) $program['id']]);
            $seats = (int) max(0, min(100, round($filled * 100 / (int) $program['intake_capacity'])));
        } catch (Throwable $e) {
            $seats = null;
        }
    }
} else {
    $seats = site_seats_filled();
}
// placements (real offers from placement records)
$placeWhere = $program ? ' AND s.program_id = ' . (int) $program['id'] : '';
$placement = null;
$recruiters = [];
$roles = [];
try {
    $placement = db_row("SELECT COUNT(*) offers, COUNT(DISTINCT o.company_id) companies, MAX(o.package_lpa) highest, AVG(o.package_lpa) average
                         FROM placement_offers o JOIN students s ON s.id = o.student_id WHERE o.status <> 'declined'" . $placeWhere);
    if ($placement && (int) $placement['offers'] > 0) {
        // real logos first (companies.logo), wordmark fallback when NULL
        $recruiters = db_all("SELECT c.name, c.industry, c.logo, COUNT(*) n FROM placement_offers o JOIN students s ON s.id = o.student_id JOIN companies c ON c.id = o.company_id
                              WHERE o.status <> 'declined'" . $placeWhere . ' GROUP BY c.id, c.name, c.industry, c.logo ORDER BY (c.logo IS NULL OR c.logo = \'\'), n DESC, c.name LIMIT 14');
        $roles = db_column("SELECT o.job_role FROM placement_offers o JOIN students s ON s.id = o.student_id WHERE o.status <> 'declined'" . $placeWhere . ' GROUP BY o.job_role ORDER BY COUNT(*) DESC LIMIT 8');
    } else {
        $placement = null;
    }
} catch (Throwable $e) {
    $placement = null;
}
// stories: testimonials → published success stories → alumni profiles marked for the website (program first, then general)
$stories = [];
$storyRows = function (?int $pid) {
    $out = [];
    try {
        foreach (db_all("SELECT t.name, t.designation, t.company, t.photo, t.content, p.short_name FROM testimonials t LEFT JOIN programs p ON p.id = t.program_id
                         WHERE t.status = 'active'" . ($pid ? ' AND t.program_id = ' . $pid : '') . ' ORDER BY t.sort_order, t.id DESC LIMIT 8') as $t) {
            $out[] = ['name' => $t['name'], 'quote' => $t['content'], 'photo' => $t['photo'] ? site_image($t['photo']) : null, 'meta' => implode(' • ', array_filter([$t['short_name'], $t['company']]))];
        }
        if (!$out) {
            foreach (db_all("SELECT a.name, a.batch_year, a.photo, a.company, s.excerpt, s.story, s.image, p.short_name FROM alumni_success_stories s JOIN alumni a ON a.id = s.alumni_id
                             LEFT JOIN programs p ON p.id = a.program_id WHERE s.status = 'published' AND s.show_on_website = 1" . ($pid ? ' AND a.program_id = ' . $pid : '') . ' ORDER BY s.is_featured DESC, s.sort_order LIMIT 8') as $t) {
                $out[] = ['name' => $t['name'], 'quote' => $t['excerpt'] ?: str_limit(strip_tags((string) $t['story']), 260), 'photo' => ($t['image'] ?: $t['photo']) ? site_image($t['image'] ?: $t['photo']) : null, 'meta' => implode(' • ', array_filter([$t['short_name'], $t['batch_year'] ? 'Batch of ' . $t['batch_year'] : null]))];
            }
        }
        if (!$out) {
            foreach (db_all("SELECT a.name, a.batch_year, a.photo, a.company, a.designation, a.bio, p.short_name FROM alumni a LEFT JOIN programs p ON p.id = a.program_id
                             WHERE a.status = 'active' AND a.show_on_website = 1 AND a.bio IS NOT NULL AND a.bio <> ''" . ($pid ? ' AND a.program_id = ' . $pid : '') . ' ORDER BY a.batch_year DESC LIMIT 8') as $t) {
                $out[] = ['name' => $t['name'], 'quote' => str_limit((string) $t['bio'], 240), 'quoteMarks' => false, 'photo' => $t['photo'] ? site_image($t['photo']) : null,
                    'meta' => implode(' • ', array_filter([$t['short_name'], $t['batch_year'] ? 'Batch of ' . $t['batch_year'] : null, $t['designation'] && $t['company'] ? $t['designation'] . ', ' . $t['company'] : null]))];
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
};
$stories = $storyRows($program ? (int) $program['id'] : null) ?: ($program ? $storyRows(null) : []);
// program-only facts
$courses = $program ? (site_program_courses()[(int) $program['id']] ?? []) : [];
$curriculum = [];
$feeRows = [];
if ($program) {
    try {
        foreach (db_all("SELECT semester_no, name, code, type, is_elective, credits FROM subjects WHERE program_id = ? AND status = 'active' ORDER BY semester_no, is_elective, name", [(int) $program['id']]) as $sub) {
            $curriculum[(int) $sub['semester_no']][] = $sub;
        }
        if ($session) {
            $feeRows = db_all("SELECT name, semester_no, year_no, total_amount, installments_allowed FROM fee_structures WHERE program_id = ? AND academic_session_id = ? AND status = 'active' ORDER BY semester_no, year_no, id", [(int) $program['id'], (int) $session['id']]);
        }
    } catch (Throwable $e) {
    }
}
$faqs = [];
try {
    $faqs = db_all("SELECT question, answer, category FROM faqs WHERE status = 'active' AND category IN ('admissions','fees') ORDER BY show_on_home DESC, sort_order, id LIMIT 6");
} catch (Throwable $e) {
}

/* ------------------------------------------------------------------ copy */
$name = institute_name();
if ($program) {
    $title = $program['name'] . ' (' . $program['short_name'] . ') Admissions ' . $sessionName;
    $desc = 'Apply for ' . $program['name'] . ' at ' . $name . ' — ' . $program['duration_label'] . ' ' . strtolower(program_level_label($program['level'])) . ' program'
        . ((float) $program['fee_per_year'] > 0 ? ', fee ' . money($program['fee_per_year']) . ' per year' : '') . '. Eligibility, scholarships, placements and free career counselling.';
    $heroTitle = 'Admissions Open ' . $sessionName . ':';
    $heroPill = $program['name'] . ' at GIMT';
} else {
    $title = 'Admissions Open ' . $sessionName . ' — UG, PG, Diploma & Certificate Programs';
    $desc = 'Admissions open at ' . $name . ' for ' . $sessionName . '. Explore UG, PG, diploma and certificate programs, scholarships and fees, and book a free career counselling session.';
    $heroTitle = (string) setting('landing_hero_title', 'Industry-Ready UG & PG Programs at');
    $heroPill = (string) setting('landing_hero_pill', $name);
}
$schText = (string) setting('landing_scholarship_text', '') ?: ($maxSch ? 'Merit scholarships up to ' . $maxSch . '% of tuition' : '');
$stripItems = (string) setting('landing_strip_text', '');
if ($stripItems === '') {
    $stripItems = implode(' | ', array_filter([
        ($program ? $program['short_name'] . ' admissions open for ' : 'Admissions open for ') . $sessionName,
        $deadline ? 'Last date to apply: ' . date('d M Y', $deadline) : null,
        $schText ?: null,
        'Free career counselling: ' . $phone,
    ]));
}
$brochure = $program ? (!empty($program['brochure']) ? site_image($program['brochure']) : site_url('programs?brochure=1&program=' . rawurlencode($program['slug']))) : site_url('programs?brochure=1');
// Tall arch frame: use the program's own photo only when it is tall enough (program card photos are wide banners and
// would be upscaled), else a portrait-friendly campus photo varied per program.
$heroImg = 'assets/images/site/students-lawn.jpg';
if ($program) {
    $pool = ['students-laptop.jpg', 'students-atrium.jpg', 'hero-campus-students.jpg', 'students-lawn.jpg'];
    $heroImg = 'assets/images/site/' . $pool[(int) $program['id'] % count($pool)];
    $own = ltrim((string) ($program['image'] ?? ''), '/');
    if ($own !== '' && !preg_match('~^https?://~i', $own) && is_file(APP_ROOT . '/' . $own) && ((@getimagesize(APP_ROOT . '/' . $own))[1] ?? 0) >= 480) {
        $heroImg = $own;
    }
}
$heroImgUrl = site_image($heroImg);
$programOptions = array_map(fn ($p) => ['value' => (string) $p['id'], 'label' => $p['name']], $programs);
$leadFields = function (string $variant) use ($programOptions, $program) {
    $half = $variant === 'light' ? 'half' : 'full';
    return [
        ['name' => 'name', 'label' => 'Full Name', 'required' => true, 'maxLength' => 150, 'autocomplete' => 'name', 'col' => $half, 'placeholder' => 'Enter your full name'],
        ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true, 'autocomplete' => 'email', 'col' => $half, 'placeholder' => 'Enter your email'],
        ['name' => 'phone', 'label' => 'Mobile Number', 'type' => 'tel', 'required' => true, 'prefix' => '+91', 'pattern' => '^[6-9][0-9]{9}$', 'patternMessage' => 'Enter a valid 10-digit mobile number.', 'maxLength' => 10, 'autocomplete' => 'tel-national', 'col' => $half, 'placeholder' => 'Enter your mobile number'],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'options' => $programOptions, 'placeholder' => 'Select program', 'value' => $program ? (string) $program['id'] : '', 'col' => $half],
    ];
};
$consent = 'I authorise GIMT and its admission team to contact me about admissions by phone, SMS, email or WhatsApp. This consent overrides any DND/NDNC registration.';
$strip = '';
if ($cd = countdown($deadline, ['label' => 'Application closes in'])) {
    $strip .= '<span class="inline-flex items-center gap-2">' . icon('calendar-clock', 'h-5 w-5 shrink-0') . $cd . '</span>';
}
if ($seats !== null) {
    $strip .= '<span class="inline-flex items-baseline gap-1.5"><b class="enquiry-strip-big">' . $seats . '%</b><span class="font-semibold">of ' . ($program ? e($program['short_name']) . ' ' : '') . 'seats filled</span></span>';
}
$leadForm = fn (string $theme, string $form, ?string $footer = null, ?string $id = null) => enquiry_form([
    'endpoint' => base_url('api/public/enquiry'), 'theme' => $theme, 'compact' => $theme === 'lead', 'captureUtm' => true, 'id' => $id,
    'title' => $theme === 'lead' ? (string) setting('landing_form_title', 'Talk to an Admission Expert') : '',
    'titleHighlight' => (string) setting('landing_form_highlight', 'Admission Expert'),
    'tags' => $theme === 'lead' ? array_values(array_filter([['icon' => 'wallet', 'label' => 'Semester-wise fee payment'], $maxSch ? ['icon' => 'award', 'label' => 'Merit scholarships'] : null])) : [],
    'subtitle' => $theme === 'lead' ? (string) setting('landing_form_subtitle', 'Get a Free Career Counselling Session') : '',
    'submitLabel' => 'Apply Now', 'consent' => $consent, 'footer' => $footer,
    'hidden' => ['source' => $source, 'form' => $form],
    'successTitle' => 'Thank you!', 'successMessage' => 'Our admission counsellor will call you within one working day.',
    'fields' => $leadFields($theme),
]);

/* ------------------------------------------------------------------ FAQ + JSON-LD */
$faqItems = [];
if ($program) {
    $pn = $program['short_name'];
    $faqItems[] = ['q' => 'What is the duration of the ' . $pn . ' program at GIMT?', 'a' => $program['name'] . ' is a ' . $program['duration_label'] . ' ' . strtolower(program_level_label($program['level'])) . ' program' . ((int) $program['total_semesters'] > 0 ? ' divided into ' . (int) $program['total_semesters'] . ' semesters' : '') . '.'];
    if (!empty($program['eligibility'])) {
        $faqItems[] = ['q' => 'What is the eligibility for ' . $pn . '?', 'a' => strip_tags((string) $program['eligibility'])];
    }
    if ((float) $program['fee_per_year'] > 0 || $feeRows) {
        $feeA = (float) $program['fee_per_year'] > 0 ? 'The tuition fee is ' . money($program['fee_per_year']) . ' ' . ($program['fee_label'] ?: 'per year') . '.' : '';
        if ($feeRows) {
            $feeA .= ' Fee structure for ' . $sessionName . ': ' . implode('; ', array_map(fn ($r) => $r['name'] . ' — ' . money($r['total_amount']), array_slice($feeRows, 0, 8))) . '.';
        }
        $faqItems[] = ['q' => 'What is the fee for ' . $pn . ' in ' . $sessionName . '?', 'a' => trim($feeA)];
    }
    if ($scholarships) {
        $faqItems[] = ['q' => 'Are scholarships available for ' . $pn . '?', 'a' => 'Yes. Eligible students can apply for: ' . implode('; ', array_map(fn ($s) => $s['name'] . ' (' . $schLabel($s) . ')', $scholarships)) . '. Criteria are verified at the time of admission.'];
    }
    if ((int) $program['intake_capacity'] > 0) {
        $faqItems[] = ['q' => 'How many seats are available in ' . $pn . '?', 'a' => 'The approved intake is ' . (int) $program['intake_capacity'] . ' seats per year. Seats are allotted in order of merit and completed applications.'];
    }
    if ($placement) {
        $faqItems[] = ['q' => 'What about placements for ' . $pn . ' students?', 'a' => 'Our placement records show ' . (int) $placement['offers'] . ' offers from ' . (int) $placement['companies'] . ' recruiters for ' . $pn . ' students, with a highest package of ' . rtrim(rtrim(number_format((float) $placement['highest'], 1), '0'), '.') . ' LPA. The training & placement cell prepares students from the first year.'];
    }
}
foreach ($faqs as $f) {
    $faqItems[] = ['q' => $f['question'], 'a' => strip_tags((string) $f['answer'])];
}
$schema = [];
if ($faqItems) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqItems)];
}
if ($program) {
    $schema[] = ['@context' => 'https://schema.org', '@type' => 'Course', 'name' => $program['name'], 'courseCode' => $program['code'], 'description' => str_limit(strip_tags((string) ($program['overview'] ?: $desc)), 300),
        'url' => absolute_url('admissions-open/' . $program['slug']), 'educationalCredentialAwarded' => $program['degree'] ?: $program['short_name'],
        'provider' => ['@type' => 'CollegeOrUniversity', 'name' => $name, 'sameAs' => absolute_url('')],
        'hasCourseInstance' => ['@type' => 'CourseInstance', 'courseMode' => 'onsite', 'location' => (string) setting('city', 'Greater Noida')]];
}

site_header([
    'title' => $title, 'description' => $desc, 'robots' => $robots, 'active' => 'admissions-open',
    'canonical' => absolute_url('admissions-open' . ($program ? '/' . $program['slug'] : '')),
    'image' => $heroImgUrl, 'schema' => $schema, 'header' => 'minimal', 'header_cta' => ['label' => 'Apply Now', 'url' => '#lead'],
    'announce' => $stripItems, 'loader' => false,
    'preload' => [['href' => $heroImgUrl, 'media' => '(min-width: 1280px)']],
]);
?>

<!-- 1. Hero: navy mesh + grid, headline with highlighted pill phrase, lead card with the deadline / seats strip -->
<section class="lp-hero" aria-labelledby="lp-title">
  <?= geo_shapes('band', 'hidden lg:block') ?>
  <span class="lp-swirl -left-40 -top-40 hidden h-[760px] w-[760px] md:block" aria-hidden="true"><?= doodle('rings', 'h-full w-full') ?></span>
  <?= blob('right-[18%] top-10 hidden h-[520px] w-[520px] xl:block', 'flame') ?>
  <div class="container-site relative grid gap-10 py-10 sm:py-14 lg:grid-cols-12 lg:gap-8 lg:py-16">
    <div class="lg:col-span-7 xl:col-span-5">
      <p class="hero-eyebrow"><span class="hero-eyebrow-dot"></span><?= e(setting('admissions_open_text', 'Admissions Open for ' . $sessionName)) ?></p>
      <h1 id="lp-title" class="lp-hero-title"><?= e($heroTitle) ?><br><span class="pill-phrase"><?= e($heroPill) ?></span></h1>
      <?php if ($program): ?>
        <ul class="mt-6 flex flex-wrap gap-2" aria-label="Program facts">
          <?php foreach (array_filter([program_level_label($program['level']), $program['duration_label'], (int) $program['intake_capacity'] > 0 ? (int) $program['intake_capacity'] . ' seats' : null, 'Full-time · On campus']) as $chip): ?>
            <li class="rounded-full border border-white/15 bg-white/10 px-3.5 py-1.5 text-[13px] font-semibold text-white"><?= e($chip) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($schText !== ''): ?><p class="lp-scholarship"><?= e($schText) ?> <a href="#scholarships">(Know more)</a></p><?php endif; ?>
      <?php if ($program && $courses): ?>
        <p class="lp-programs"><span class="text-white/70">Specialisations:</span> <?= e(implode(', ', array_map(fn ($c) => trim((string) preg_replace('/^.*?\s-\s/', '', $c['name'])), $courses))) ?></p>
      <?php elseif (!$program): ?>
        <p class="lp-programs"><?= e(implode(', ', array_map(fn ($p) => $p['short_name'], array_slice($programs, 0, 8)))) ?><?= count($programs) > 8 ? ' and more' : '' ?></p>
      <?php endif; ?>
      <div class="mt-7 flex flex-wrap gap-3">
        <?= button_link('Download Brochure', $brochure, 'apply', ['icon' => '', 'icon_left' => 'download', 'target' => '_blank', 'magnetic' => true]) ?>
        <?= button_link($program ? 'Program details' : 'Explore Courses', $program ? '/programs/' . $program['slug'] : '/programs', 'outline-light', ['icon' => 'arrow-right']) ?>
      </div>
      <?php if ($approvals): ?><div class="mt-8 max-w-xl"><?= approvals_card($approvals) ?></div><?php endif; ?>
    </div>
    <div class="relative hidden xl:col-span-3 xl:block">
      <div class="lp-photo clip-in absolute inset-x-0 bottom-6 top-20"><img src="<?= e($heroImgUrl) ?>" alt="<?= e($program ? $program['name'] . ' students at GIMT' : 'Students at GIMT') ?>" fetchpriority="high" decoding="async" width="360" height="520"></div>
      <span class="absolute left-3 top-24 z-[2]"><?= sticker('Learn · Grow · Lead', ['color' => 'white', 'rotate' => -8, 'size' => 'sm']) ?></span>
    </div>
    <div class="lg:col-span-5 xl:col-span-4" id="lead">
      <?= $leadForm('lead', 'landing-hero', $strip !== '' ? $strip : null) ?>
    </div>
  </div>
</section>

<!-- 2. Highlights strip -->
<section class="border-b border-[#EDE8E2] bg-white" aria-label="Highlights">
  <div class="container-site py-7">
    <ul class="lp-features">
      <?php
      $hl = $program
          ? [['clock', 'Duration', $program['duration_label'] . ((int) $program['total_semesters'] ? ' · ' . (int) $program['total_semesters'] . ' semesters' : '')],
             ['graduation-cap', 'Eligibility', $program['eligibility'] ? str_limit(strip_tags((string) $program['eligibility']), 70) : program_level_label($program['level'])],
             ['wallet', 'Fee', (float) $program['fee_per_year'] > 0 ? money($program['fee_per_year']) . ' ' . ($program['fee_label'] ?: '/ year') : 'As per fee structure'],
             ['briefcase-business', 'Career outcomes', $placement ? (int) $placement['offers'] . ' placement offers on record' : 'Training & placement support']]
          : [['book-open', 'Programs', count($programs) . ' UG, PG, diploma & certificate programs'], ['users', 'Faculty', 'Experienced faculty & industry mentors'],
             ['briefcase-business', 'Placements', $placement ? (int) $placement['offers'] . ' offers from ' . (int) $placement['companies'] . ' recruiters on record' : 'Dedicated placement cell'],
             ['award', 'Scholarships', $maxSch ? 'Merit scholarships up to ' . $maxSch . '%' : 'Scholarships for eligible students']];
      foreach ($hl as $i => [$ic, $label, $val]): ?>
        <li class="lp-feature"><?= icon_circle($ic, $i % 2 ? 'orange' : 'navy') ?><span><span class="block text-[12px] font-bold uppercase tracking-[0.12em] text-slate-500"><?= e($label) ?></span><span class="block"><?= e($val) ?></span></span></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<?php if ($approvals): ?>
<!-- 3. Recognitions & approvals (ribbon cards) -->
<?= section_open(['id' => 'approvals', 'padding' => 'tight', 'class' => '!pt-16']) ?>
  <?= section_heading('Recognitions & Approvals', 'Verified approvals and affiliations of our programs.', '', 'left', '', ['highlight' => 'Approvals']) ?>
  <?= carousel(array_map(fn ($a) => '<div class="pt-3">' . approval_card($a) . '</div>', $approvals), ['ariaLabel' => 'Approvals and affiliations', 'perView' => ['base' => 1.3, 'sm' => 2.2, 'lg' => 4], 'gap' => 20]) ?>
<?= section_close() ?>
<?php endif; ?>

<?php if ($program): ?>
<!-- 4a. What you'll learn (semester-wise curriculum) -->
<?php if ($curriculum): ?>
<?= section_open(['id' => 'curriculum', 'bg' => 'light']) ?>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-4">
      <?= section_heading('What you’ll learn', 'Semester-wise subjects of the ' . $program['short_name'] . ' curriculum (' . $sessionName . ' scheme).', 'Curriculum', 'left', '', ['highlight' => 'learn']) ?>
      <?php if ($courses): ?>
        <p class="mb-3 text-sm font-bold uppercase tracking-[0.12em] text-slate-500">Specialisations</p>
        <ul class="flex flex-wrap gap-2"><?php foreach ($courses as $c): ?><li class="chip !cursor-default !py-1.5 !text-[13px]"><?= e(str_replace(' - ', ' – ', $c['name'])) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div class="space-y-3 lg:col-span-8" data-accordion="single">
      <?php foreach ($curriculum as $sem => $subs): ?>
        <details class="acc-item"<?= $sem === array_key_first($curriculum) ? ' open' : '' ?>>
          <summary>Semester <?= (int) $sem ?> <span class="ml-auto mr-3 text-[13px] font-semibold text-slate-500"><?= count($subs) ?> subjects · <?= rtrim(rtrim(number_format(array_sum(array_map(fn ($s) => (float) $s['credits'], $subs)), 1), '0'), '.') ?> credits</span></summary>
          <ul class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <?php foreach ($subs as $sub): ?>
              <li class="flex items-start gap-2.5"><?= icon('circle-check', 'mt-0.5 h-4 w-4 shrink-0 text-accent-600') ?><span><span class="font-medium text-brand-900"><?= e($sub['name']) ?></span><span class="block text-xs text-slate-500"><?= e($sub['code']) ?> · <?= e(ucfirst((string) $sub['type'])) ?><?= $sub['is_elective'] ? ' · Elective' : '' ?></span></span></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
<?= section_close() ?>
<?php endif; ?>
<?php else: ?>
<!-- 4b. Explore our programs (pill tabs) + Choose your program index -->
<?= section_open(['id' => 'programs', 'bg' => 'light']) ?>
  <?= section_heading('Explore Our Programs', 'Industry-focused programs for the ' . $sessionName . ' session.', 'Admissions ' . $sessionName, 'left', '', ['highlight' => 'Programs']) ?>
  <?php
  $groups = ['ug' => ['Undergraduate', ['UG']], 'pg' => ['Postgraduate', ['PG', 'PhD']], 'diploma' => ['Diploma & Certificate', ['Diploma', 'Certificate']]];
  $tabs = [];
  $panels = [];
  foreach ($groups as $gk => [$label, $levels]) {
      $list = array_values(array_filter($programs, fn ($p) => in_array($p['level'], $levels, true)));
      if (!$list) {
          continue;
      }
      $tabs[] = ['key' => $gk, 'label' => $label];
      $panels[] = '<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">' . implode('', array_map(fn ($p) => program_card($p, ['variant' => 'landing', 'apply_url' => $landingUrl($p) . '#lead', 'summary' => false]), $list)) . '</div>';
  }
  echo content_tabs($tabs, $panels);
  ?>
  <div class="mt-14">
    <h3 class="font-display text-xl font-extrabold text-brand-900">Choose your program</h3>
    <p class="mt-1 text-sm text-slate-600">Each program has its own admission page with eligibility, fees, curriculum and scholarships.</p>
    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <?php foreach ($programs as $p): ?>
        <a href="<?= e($landingUrl($p)) ?>" class="mega-card !min-h-[110px]"><span class="mega-card-tag"><?= e($p['short_name']) ?></span><span class="mega-card-name"><?= e($p['name']) ?></span><span class="mega-card-row"><?= dept_chip($p) ?><span class="mega-card-meta"><?= icon('clock', 'h-3.5 w-3.5') ?><?= e($p['duration_label']) ?></span></span></a>
      <?php endforeach; ?>
    </div>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- 5. Fees, scholarships & financing (sand band) + Request a Callback -->
<?= section_open(['id' => 'scholarships', 'bg' => 'sand']) ?>
  <?= section_heading($program ? 'Fees & Scholarships' : 'Scholarships & Financing', $program ? 'Transparent fees for ' . $sessionName . ' and scholarships for eligible students.' : 'Merit-based support and flexible fee payment for eligible students.', '', 'left', '', ['highlight' => $program ? 'Scholarships' : 'Scholarships']) ?>
  <div class="grid gap-5 lg:grid-cols-12">
    <?php if ($program && ((float) $program['fee_per_year'] > 0 || $feeRows)): ?>
      <div class="rounded-[22px] border border-[#EDE8E2] bg-white p-6 lg:col-span-5" data-spotlight>
        <p class="eyebrow">Fee structure · <?= e($sessionName) ?></p>
        <?php if ((float) $program['fee_per_year'] > 0): ?><p class="mt-3 font-display text-4xl font-extrabold text-brand-900"><?= e(money($program['fee_per_year'])) ?> <span class="text-base font-semibold text-slate-500"><?= e($program['fee_label'] ?: '/ year') ?></span></p><?php endif; ?>
        <?php if ($feeRows): ?>
          <ul class="mt-5 divide-y divide-[#EDE8E2] text-[15px]">
            <?php foreach (array_slice($feeRows, 0, 8) as $r): ?>
              <li class="flex items-center justify-between gap-4 py-2.5"><span><?= e(preg_replace('/\s*' . preg_quote($sessionName, '/') . '$/', '', $r['name'])) ?><?= (int) $r['installments_allowed'] > 1 ? ' <span class="badge badge-orange ml-1">' . (int) $r['installments_allowed'] . ' instalments</span>' : '' ?></span><span class="font-bold text-brand-900"><?= e(money($r['total_amount'])) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="grid gap-5 sm:grid-cols-2 <?= $program && ((float) $program['fee_per_year'] > 0 || $feeRows) ? 'lg:col-span-7' : 'lg:col-span-12 lg:grid-cols-4' ?>">
      <?php foreach (array_slice($scholarships, 0, $program ? 3 : 3) as $i => $s): ?>
        <article class="scholar-card"><?= icon_circle($i % 2 ? 'medal' : 'award', $i % 2 ? 'navy' : 'orange') ?><div><h3><?= e($s['name']) ?></h3><p><?= e(str_limit(strip_tags((string) $s['criteria']), 120)) ?></p><span class="scholar-value"><?= e($schLabel($s)) ?><?= $s['max_amount'] ? ' · up to ' . e(money($s['max_amount'])) : '' ?></span></div></article>
      <?php endforeach; ?>
      <article class="scholar-card"><?= icon_circle('wallet', 'navy') ?><div><h3><?= e(setting('landing_financing_title', 'Semester-wise fee payment')) ?></h3><p><?= e(setting('landing_financing_text', 'Pay your tuition semester-wise as per the approved fee structure.')) ?></p></div></article>
    </div>
  </div>
  <div class="mt-8 flex flex-wrap items-center gap-3">
    <button type="button" class="btn btn-callback btn-lg" data-modal-open="#lead-modal"><?= icon('phone', 'h-4 w-4') ?>Request a Callback</button>
    <?= button_link('Apply for Admission', '/apply' . ($program ? '?program=' . rawurlencode($program['slug']) : ''), 'apply', ['size' => 'lg']) ?>
    <?= whatsapp_cta('Chat with us on WhatsApp', $waText, ['size' => 'lg']) ?>
  </div>
<?= section_close() ?>

<?php if ($placement || ($program && !empty($program['career_prospects']))): ?>
<!-- 6. Career outcomes & placements (navy band) -->
<?= section_open(['id' => 'careers', 'bg' => 'ink']) ?>
  <?= section_heading($program ? 'Career Outcomes for ' . $program['short_name'] : 'Placements at GIMT', $placement ? 'Figures from our placement records (offers made to GIMT students; declined offers excluded).' : 'Roles our graduates prepare for.', '', 'left', '', ['highlight' => $program ? 'Outcomes' : 'Placements', 'scribble' => 'light']) ?>
  <?php if ($placement): ?>
    <?= stat_counter(array_values(array_filter([
        ['value' => (int) $placement['offers'], 'label' => 'Offers on record'],
        ['value' => (int) $placement['companies'], 'label' => 'Recruiting companies'],
        $placement['highest'] ? ['value' => round((float) $placement['highest'], 1), 'decimals' => 1, 'suffix' => ' LPA', 'label' => 'Highest package'] : null,
        $placement['average'] ? ['value' => round((float) $placement['average'], 1), 'decimals' => 1, 'suffix' => ' LPA', 'label' => 'Average package'] : null,
    ])), ['variant' => 'divided', 'theme' => 'dark', 'gridClass' => 'grid-cols-2 lg:grid-cols-4']) ?>
  <?php endif; ?>
  <div class="mt-12 grid gap-8 lg:grid-cols-12">
    <?php if ($roles || ($program && !empty($program['career_prospects']))): ?>
      <div class="min-w-0 lg:col-span-5">
        <h3 class="font-display text-lg font-extrabold text-white">Typical roles</h3>
        <ul class="mt-4 flex flex-wrap gap-2">
          <?php foreach ($roles ?: array_slice(array_filter(array_map('trim', preg_split('/[,;\n]+/', strip_tags((string) $program['career_prospects'])))), 0, 10) as $r): ?>
            <li class="rounded-full border border-white/15 bg-white/10 px-3.5 py-1.5 text-[13px] font-semibold text-white"><?= e($r) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($recruiters): ?>
      <div class="min-w-0 lg:col-span-7"><?= logo_marquee(array_map(fn ($c) => ['name' => $c['name'], 'industry' => $c['industry'], 'logo' => !empty($c['logo']) ? site_image($c['logo']) : null], $recruiters), ['label' => 'Recruiters on record', 'rows' => 1, 'ariaLabel' => 'Recruiters']) ?></div>
    <?php endif; ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- 7. The GIMT Advantage -->
<?= section_open(['id' => 'advantage']) ?>
  <?= section_heading('The GIMT Advantage', '', '', 'left', '', ['highlight' => 'Advantage']) ?>
  <?php
  $adv = json_decode((string) setting('landing_advantages', ''), true);
  if (!is_array($adv) || !$adv) {
      $adv = [
          ['icon' => 'briefcase-business', 'title' => 'Industry-aligned curriculum', 'text' => 'Programs designed with practical projects, case studies and industry interaction.'],
          ['icon' => 'users', 'title' => 'Experienced faculty & mentors', 'text' => 'Learn from qualified faculty who guide you through academics and career choices.'],
          ['icon' => 'handshake', 'title' => 'Training & placement cell', 'text' => 'Aptitude, soft-skills and interview preparation with campus recruitment drives.'],
          ['icon' => 'building-2', 'title' => 'Campus in ' . setting('city', 'Greater Noida'), 'text' => 'Classrooms, labs, library, hostel and transport facilities on campus.'],
      ];
  }
  ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="80">
    <?php foreach ($adv as $i => $a): ?><?= feature_tile((string) ($a['icon'] ?? 'sparkles'), (string) ($a['title'] ?? ''), (string) ($a['text'] ?? ''), ['tint' => $i % 2 ? 'orange' : 'navy', 'variant' => 'warm']) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>

<?php if ($stories): ?>
<!-- 8. Real stories -->
<?= section_open(['id' => 'stories', 'bg' => 'light']) ?>
  <?= section_heading('Real Stories, Real Impact', '', '', 'left', '', ['highlight' => 'Real Impact', 'sticker' => ['text' => 'GIMT alumni', 'color' => 'blush']]) ?>
  <?= testimonial_slider($stories, ['ariaLabel' => 'Student and alumni stories']) ?>
<?= section_close() ?>
<?php endif; ?>

<?php if ($faqItems): ?>
<!-- 9. FAQ -->
<?= section_open(['id' => 'faq']) ?>
  <?= section_heading('Frequently Asked Questions', '', '', 'left', '', ['highlight' => 'Questions']) ?>
  <?= faq_accordion($faqItems, ['columns' => count($faqItems) > 5 ? 2 : 1]) ?>
<?= section_close() ?>
<?php endif; ?>

<?php if ($program && count($programs) > 1): ?>
<!-- 10. Explore other programs -->
<?= section_open(['bg' => 'light', 'padding' => 'tight']) ?>
  <?= section_heading('Explore other programs', '', '', 'left', '', ['highlight' => 'other programs']) ?>
  <?= carousel(array_map(fn ($p) => '<a href="' . e($landingUrl($p)) . '" class="mega-card h-full"><span class="mega-card-tag">' . e($p['short_name']) . '</span><span class="mega-card-name">' . e($p['name']) . '</span><span class="mega-card-label">' . e(program_level_label($p['level'])) . '</span><span class="mega-card-row">' . dept_chip($p) . '<span class="mega-card-meta">' . icon('clock', 'h-3.5 w-3.5') . e($p['duration_label']) . '</span></span></a>', array_values(array_filter($programs, fn ($p) => (int) $p['id'] !== (int) $program['id']))), ['ariaLabel' => 'Other programs', 'perView' => ['base' => 1.25, 'sm' => 2.2, 'lg' => 4], 'gap' => 16]) ?>
<?= section_close() ?>
<?php endif; ?>

<!-- 11. Final CTA with the form again -->
<section class="relative isolate overflow-hidden bg-[#F5F2EE] py-16 sm:py-20" aria-labelledby="lp-final">
  <?= blob('-right-24 -top-24 h-[520px] w-[520px]', 'flame') ?>
  <div class="container-site grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-7">
      <h2 id="lp-final" class="section-title" data-text-reveal>Advance your <?= scribble('career') ?> with GIMT</h2>
      <p class="section-lead">Fill in your details and our admission team will connect with you shortly.</p>
      <div class="mt-7 rounded-[22px] border border-[#EDE8E2] bg-white p-5 sm:p-7"><?= $leadForm('plain', 'landing-footer') ?></div>
      <?php if ($waCta = whatsapp_cta('Chat with us on WhatsApp', $waText)): ?><p class="mt-5 flex flex-wrap items-center gap-3 text-sm text-body">Prefer chatting? <?= $waCta ?></p><?php endif; ?>
    </div>
    <div class="relative hidden lg:col-span-5 lg:block">
      <div class="page-hero-frame" data-reveal="clip"><img src="<?= e(site_image('assets/images/site/placement-student.jpg')) ?>" alt="A GIMT student" loading="lazy" decoding="async" width="560" height="470"></div>
      <span class="geo geo-dots -bottom-6 -left-6 h-24 w-24" aria-hidden="true"></span>
    </div>
  </div>
</section>

<?= enquiry_modal(['id' => 'lead-modal', 'source' => $source, 'program' => $program ? (string) $program['id'] : '']) ?>

<?php site_footer(['footer' => 'minimal', 'cta' => false, 'enquiry_tab' => false, 'mobile_cta' => ['call' => $phone, 'apply' => ['label' => 'Apply Now', 'url' => '#lead']]]); ?>
