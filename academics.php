<?php
/**
 * Academics (/academics) — academic excellence overview: live numbers, departments (#departments) with HODs and
 * programs, teaching methodology + assessment pattern (settings + subjects), academic calendar (#calendar) from
 * academic_sessions / holidays / exams / events, research & innovation (#research), faculty highlights (#faculty),
 * library & labs (#facilities, library + classrooms tables), academic outcomes (StatCounter), CTA.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/partials/site_academic.php';

$counts = sa_counts();
$departments = sa_departments();
$session = sa_session();
$calendar = sa_calendar($session);
$today = date('Y-m-d');
$attendance = sa_attendance_rate();
$library = sa_library_summary();
$spaces = sa_learning_spaces();
$seats = sa_classroom_seats();
$research = sa_research_areas(14);
$grades = sa_grade_scale();
$topFaculty = sa_faculty(['order' => 'publications', 'limit' => 4]);
$phdPct = $counts['faculty'] ? round(100 * $counts['phd'] / $counts['faculty']) : 0;
$ratio = $counts['faculty'] ? (int) round($counts['students'] / $counts['faculty']) : 0;

// assessment pattern: most common internal/external split + academic settings
$split = sa_try(fn () => db_row("SELECT max_internal, max_external FROM subjects WHERE status = 'active' AND type = 'theory' GROUP BY max_internal, max_external ORDER BY COUNT(*) DESC LIMIT 1")) ?: ['max_internal' => 30, 'max_external' => 70];
$int = (int) $split['max_internal'];
$ext = (int) $split['max_external'];
$intPct = $int + $ext > 0 ? (int) round(100 * $int / ($int + $ext)) : 30;
$projects = (int) sa_try(fn () => db_value("SELECT COUNT(*) FROM subjects WHERE status = 'active' AND type = 'project'"), 0);
$practicals = (int) sa_try(fn () => db_value("SELECT COUNT(*) FROM subjects WHERE status = 'active' AND type IN ('lab','practical')"), 0);
$grading = ['cgpa_10' => '10-point CGPA', 'cgpa_4' => '4-point GPA', 'percentage' => 'Percentage'][setting('grading_system', 'cgpa_10')] ?? '10-point CGPA';

// calendar panels
$upcoming = array_values(array_filter($calendar, fn ($e) => ($e['end'] ?: $e['date']) >= $today));
$oddEnd = $session ? date('Y', strtotime($session['start_date'])) . '-12-31' : null;
$odd = $session ? array_values(array_filter($calendar, fn ($e) => $e['date'] <= $oddEnd)) : [];
$even = $session ? array_values(array_filter($calendar, fn ($e) => $e['date'] > $oddEnd)) : [];
$sessionRange = $session ? date('M Y', strtotime($session['start_date'])) . ' – ' . date('M Y', strtotime($session['end_date'])) : '';

$heroImg = 'assets/images/site/hero-campus-students.jpg';
site_header([
    'title' => 'Academics — Departments, Calendar & Research',
    'description' => 'Academic excellence at GIMT Greater Noida: ' . count($departments) . ' departments, ' . $counts['programs'] . ' programs, ' . $counts['faculty'] . ' faculty members, outcome-based teaching, the ' . ($session['name'] ?? '') . ' academic calendar, research and modern labs.',
    'active' => 'academics',
    'seo' => ['route', '/academics'],
    'image' => $heroImg,
    'preload' => [asset($heroImg)],
    'schema' => [sa_breadcrumb_ld([['Home', ''], ['Academics', null]])],
]);

page_hero('Academic Excellence', 'Rigorous Learning. Real-World Impact.', ['Academics'], $heroImg,
    'An outcome-based curriculum, experienced faculty and industry-linked learning across management, technology, commerce and computer applications.',
    [
        ['icon' => 'book-open', 'label' => 'Outcome-Based Curriculum'],
        ['icon' => 'users', 'label' => $counts['faculty'] . ' Faculty Members'],
        ['icon' => 'microscope', 'label' => 'Research & Innovation'],
        ['icon' => 'handshake', 'label' => 'Industry Partnerships'],
    ],
    ['image_alt' => 'Students on the GIMT campus', 'actions' => button_link('Explore Departments', '#departments', 'accent', ['shine' => true]) . button_link('Academic Calendar', '#calendar', 'outline-light', ['icon' => 'calendar-days'])]);
?>

<!-- At a glance -->
<?= section_open(['id' => 'overview']) ?>
  <div class="grid items-center gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Academics at GIMT</p>
      <h2 class="section-title">Where Learning Meets Outcomes</h2>
      <p class="section-lead">Every program at GIMT follows a semester system with a <?= e($grading) ?> grading scheme, continuous internal assessment and hands-on project work — designed with recruiters so that what you learn in class is what industry needs.</p>
      <?= check_list(array_values(array_filter([
          count($departments) . ' academic departments and ' . $counts['programs'] . ' programs from certificate to postgraduate level',
          $projects ? $projects . ' project courses and ' . $practicals . ' lab / practical courses across the curriculum' : null,
          $ratio ? 'About ' . $ratio . ' students for every faculty member — personal mentoring for each learner' : null,
          'Industry certifications, internships and live projects built into every semester',
      ])), 'mt-6') ?>
    </div>
    <div class="lg:col-span-7"<?= reveal_attr('fade-up', 100) ?>>
      <?= stat_counter([
          ['value' => $counts['programs'], 'label' => 'Programs', 'icon' => 'graduation-cap'],
          ['value' => count($departments), 'label' => 'Departments', 'icon' => 'school'],
          ['value' => sa_round($counts['faculty'])[0], 'suffix' => sa_round($counts['faculty'])[1], 'label' => 'Faculty Members', 'icon' => 'users'],
          ['value' => sa_round($counts['publications'])[0], 'suffix' => sa_round($counts['publications'])[1], 'label' => 'Research Publications', 'icon' => 'book-open'],
      ], ['variant' => 'cards', 'gridClass' => 'grid-cols-1 sm:grid-cols-2']) ?>
    </div>
  </div>
<?= section_close() ?>

<!-- Departments -->
<?= section_open(['id' => 'departments', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Our Departments', 'Each department is led by an experienced head and a dedicated team of faculty, with programs designed around the careers they lead to.', 'Schools & Departments') ?>
  <?php if ($departments): ?>
    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3" data-stagger="80">
      <?php foreach ($departments as $i => $d): $hn = !empty($d['hod_id']) ? sa_person_name($d, 'hod_') : ''; ?>
        <article id="dept-<?= e(strtolower($d['code'])) ?>" class="group flex scroll-mt-28 flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card transition duration-300 hover:-translate-y-1 hover:shadow-soft">
          <div class="img-zoom relative aspect-[16/7] overflow-hidden bg-slate-100">
            <img src="<?= e(sa_department_image($d)) ?>" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
            <span class="absolute inset-0 bg-gradient-to-t from-brand-950/70 via-brand-950/10 to-transparent" aria-hidden="true"></span>
            <span class="absolute left-4 top-3 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-2.5 py-1 text-[11px] font-bold uppercase tracking-wider text-brand-900 shadow-sm"><?= e($d['code']) ?><?= $d['established_year'] ? ' · Est. ' . (int) $d['established_year'] : '' ?></span>
          </div>
          <div class="flex flex-1 flex-col p-5">
            <div class="flex items-start gap-3">
              <span class="relative z-[1] -mt-10 inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-900 text-white shadow-soft ring-4 ring-white transition duration-500 group-hover:-rotate-6"><?= icon(sa_department_icon($d), 'h-6 w-6') ?></span>
              <h3 class="font-display text-lg font-extrabold leading-snug text-brand-900"><?= e($d['name']) ?></h3>
            </div>
            <?php if ($d['description']): ?><p class="mt-3 text-sm leading-relaxed text-slate-600"><?= e($d['description']) ?></p><?php endif; ?>
            <ul class="mt-4 flex flex-wrap gap-2 text-xs font-semibold">
              <li class="rounded-full bg-brand-50 px-2.5 py-1 text-brand-800"><?= count($d['programs']) ?> program<?= count($d['programs']) === 1 ? '' : 's' ?></li>
              <li class="rounded-full bg-accent-50 px-2.5 py-1 text-accent-800"><?= (int) $d['faculty_count'] ?> faculty</li>
            </ul>
            <?php if ($d['programs']): ?>
              <ul class="mt-4 space-y-1.5 text-sm">
                <?php foreach ($d['programs'] as $p): ?>
                  <li><a href="<?= e(site_url('programs/' . $p['slug'])) ?>" class="group/link flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 font-medium text-slate-700 transition hover:bg-brand-50 hover:text-brand-900"><span><?= e($p['name']) ?></span><?= icon('arrow-right', 'h-4 w-4 shrink-0 text-slate-300 transition group-hover/link:translate-x-0.5 group-hover/link:text-accent-600') ?></a></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <div class="mt-auto flex items-center gap-3 border-t border-slate-100 pt-4">
              <?php if ($hn !== ''): ?>
                <?= sa_avatar($d['hod_photo'], $hn, (int) $d['hod_id'], 'h-11 w-11 text-sm') ?>
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-bold text-brand-900"><?= e($hn) ?></p><p class="text-xs text-slate-500">Head of Department</p></div>
              <?php else: ?>
                <div class="min-w-0 flex-1 text-sm text-slate-500"><?= (int) $d['faculty_count'] ?> faculty members</div>
              <?php endif; ?>
              <a href="<?= e(site_url('faculty?department=' . strtolower($d['code'])) . '#directory') ?>" class="link-arrow shrink-0 text-sm">Faculty<span class="sr-only"> of <?= e($d['name']) ?></span><?= icon('arrow-right', 'h-4 w-4') ?></a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state rounded-2xl border border-dashed border-slate-200 bg-white"><span class="empty-state-icon"><?= icon('school', 'h-6 w-6') ?></span><p class="mt-3 font-semibold text-brand-900">Department information is being updated.</p><a href="<?= e(site_url('programs')) ?>" class="btn btn-secondary btn-sm mt-4">Browse Programs</a></div>
  <?php endif; ?>
<?= section_close() ?>

<!-- Teaching methodology + assessment -->
<?= section_open(['id' => 'methodology']) ?>
  <?= section_heading('How We Teach', 'Active, practical and personal — our teaching methods turn knowledge into capability.', 'Teaching Methodology') ?>
  <div class="grid gap-8 lg:grid-cols-12">
    <div class="grid gap-5 sm:grid-cols-2 lg:col-span-8" data-stagger="70">
      <?= feature_tile('presentation', 'Case-Based Learning', 'Real business and technology cases analysed in class, from Indian startups to global enterprises.', ['tint' => 'blue']) ?>
      <?= feature_tile('cpu', 'Project & Lab Work', ($projects ? $projects . ' project courses and ' . $practicals . ' lab courses' : 'Project and lab courses') . ' build hands-on capability every semester.', ['tint' => 'green']) ?>
      <?= feature_tile('laptop', 'Blended & Flipped Classrooms', 'Smart classrooms and a learning management system with recorded lectures, quizzes and e-resources.', ['tint' => 'cyan']) ?>
      <?= feature_tile('briefcase-business', 'Industry Immersion', 'Guest lectures, industrial visits, internships and live projects with partner companies.', ['tint' => 'amber']) ?>
      <?= feature_tile('target', 'Continuous Assessment', 'Quizzes, assignments, presentations and mid-term exams keep learning on track all semester.', ['tint' => 'violet']) ?>
      <?= feature_tile('users', 'Mentoring & Counselling', 'Every student has a faculty mentor for academic guidance, career planning and wellbeing.', ['tint' => 'rose']) ?>
    </div>
    <aside class="lg:col-span-4" aria-labelledby="assessment-title"<?= reveal_attr('slide-right', 100) ?>>
      <div class="h-full rounded-2xl bg-brand-900 p-6 text-white shadow-soft">
        <h3 id="assessment-title" class="font-display text-xl font-extrabold text-white">Assessment &amp; Evaluation</h3>
        <p class="mt-1 text-sm text-white/70">How every theory subject is evaluated</p>
        <div class="mt-6">
          <div class="flex justify-between text-sm font-semibold"><span>Internal assessment</span><span><?= $int ?> marks</span></div>
          <div class="mt-2 flex h-3 overflow-hidden rounded-full bg-white/10" role="img" aria-label="<?= $intPct ?>% internal, <?= 100 - $intPct ?>% external">
            <span class="bg-accent-500" style="width:<?= $intPct ?>%"></span><span class="bg-cyan-400/80" style="width:<?= 100 - $intPct ?>%"></span>
          </div>
          <div class="mt-2 flex justify-between text-sm font-semibold"><span class="text-white/70">End-semester exam</span><span><?= $ext ?> marks</span></div>
        </div>
        <dl class="mt-6 space-y-3 text-sm">
          <div class="flex items-center justify-between gap-3 border-t border-white/10 pt-3"><dt class="flex items-center gap-2 text-white/75"><?= icon('award', 'h-4 w-4 text-accent-300') ?>Grading</dt><dd class="font-semibold"><?= e($grading) ?></dd></div>
          <div class="flex items-center justify-between gap-3 border-t border-white/10 pt-3"><dt class="flex items-center gap-2 text-white/75"><?= icon('circle-check', 'h-4 w-4 text-accent-300') ?>Passing marks</dt><dd class="font-semibold"><?= (int) setting('passing_percentage', 40) ?>% per subject</dd></div>
          <div class="flex items-center justify-between gap-3 border-t border-white/10 pt-3"><dt class="flex items-center gap-2 text-white/75"><?= icon('calendar-check', 'h-4 w-4 text-accent-300') ?>Attendance</dt><dd class="font-semibold">Min. <?= (int) setting('min_attendance_percent', 75) ?>% to sit exams</dd></div>
          <div class="flex items-center justify-between gap-3 border-t border-white/10 pt-3"><dt class="flex items-center gap-2 text-white/75"><?= icon('layers', 'h-4 w-4 text-accent-300') ?>System</dt><dd class="font-semibold">Two semesters a year</dd></div>
        </dl>
        <?php if ($grades): ?>
          <div class="mt-6 border-t border-white/10 pt-5">
            <h4 class="font-sans text-xs font-bold uppercase tracking-wider text-white/60">Grade scale</h4>
            <ul class="mt-3 grid grid-cols-4 gap-2 text-center">
              <?php foreach ($grades as $g): ?>
                <li class="rounded-xl px-1 py-2 ring-1 <?= $g['is_pass'] ? 'bg-white/5 ring-white/10' : 'bg-rose-500/10 ring-rose-300/20' ?>" title="<?= e(($g['description'] ? $g['description'] . ': ' : '') . rtrim(rtrim((string) $g['min_percent'], '0'), '.') . '–' . rtrim(rtrim((string) $g['max_percent'], '0'), '.') . '%') ?>">
                  <span class="block font-display text-base font-extrabold leading-tight <?= $g['is_pass'] ? 'text-white' : 'text-rose-200' ?>"><?= e($g['grade']) ?></span>
                  <span class="block text-[11px] font-semibold text-white/60"><?= e(rtrim(rtrim((string) $g['grade_point'], '0'), '.')) ?> pts</span>
                  <span class="block text-[10.5px] text-white/45"><?= (int) $g['min_percent'] ?>%+</span>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>
<?= section_close() ?>

<!-- Academic calendar -->
<?= section_open(['id' => 'calendar', 'bg' => 'light', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Academic Calendar ' . ($session['name'] ?? ''), $session ? 'Session ' . $sessionRange . ' — holidays, examinations, vacations and campus events. Odd semester: July–December · Even semester: January–June.' : 'The academic calendar for the new session will be published soon.', 'Plan Your Year') ?>
  <ul class="mb-6 flex flex-wrap gap-2 text-xs" aria-label="Legend">
    <li class="badge badge-red">Holiday</li><li class="badge badge-amber">Vacation</li><li class="badge badge-purple">Examination</li><li class="badge badge-green">Event</li>
  </ul>
  <?php if ($calendar): ?>
    <?= content_tabs(
        [['key' => 'upcoming', 'label' => 'Upcoming', 'icon' => 'calendar-check'], ['key' => 'odd', 'label' => 'Odd Semester', 'icon' => 'calendar-days'], ['key' => 'even', 'label' => 'Even Semester', 'icon' => 'calendar-days']],
        [sa_calendar_list(array_slice($upcoming, 0, 12), 'No upcoming entries this session.'), sa_calendar_list($odd), sa_calendar_list($even)],
        ['variant' => 'pills']
    ) ?>
  <?php else: ?>
    <?= sa_calendar_list([], 'The academic calendar for this session will be published soon.') ?>
  <?php endif; ?>
<?= section_close() ?>

<!-- Research & innovation -->
<?= section_open(['id' => 'research', 'bg' => 'gradient', 'pattern' => 'grid', 'class' => 'scroll-mt-20']) ?>
  <div class="blob -right-20 top-10 -z-[1] h-80 w-80 bg-accent-500/20" aria-hidden="true"></div>
  <div class="grid gap-10 lg:grid-cols-12">
    <div class="lg:col-span-5"<?= reveal_attr('slide-left') ?>>
      <p class="eyebrow mb-2">Research &amp; Innovation</p>
      <h2 class="section-title !text-white">Ideas That Move Industry Forward</h2>
      <p class="section-lead !text-white/75">Our faculty publish in peer-reviewed journals and conferences, guide student research projects and work with industry on real problems — from analytics and AI to finance and organisational behaviour.</p>
      <div class="mt-8">
        <?= stat_counter([
            ['value' => sa_round($counts['publications'])[0], 'suffix' => sa_round($counts['publications'])[1], 'label' => 'Publications'],
            ['value' => $counts['phd'], 'label' => 'PhD Faculty'],
            ['value' => count($research), 'label' => 'Research Areas'],
        ], ['variant' => 'inline', 'theme' => 'dark', 'gridClass' => 'grid-cols-3']) ?>
      </div>
    </div>
    <div class="lg:col-span-7"<?= reveal_attr('fade-up', 100) ?>>
      <?php if ($research): ?>
        <h3 class="font-sans text-sm font-bold uppercase tracking-wider text-white/60">Faculty research focus</h3>
        <ul class="mt-4 flex flex-wrap gap-2">
          <?php foreach ($research as $area => $n): ?><li class="rounded-full border border-white/15 bg-white/10 px-3.5 py-1.5 text-sm font-medium text-white backdrop-blur transition hover:-translate-y-0.5 hover:bg-white/15"><?= e($area) ?></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <div class="mt-8 grid gap-4 sm:grid-cols-2" data-stagger="70">
        <?php foreach ([
            ['lightbulb', 'Innovation & Startup Cell', 'Ideation workshops, hackathons and mentoring for student founders.'],
            ['cpu', 'AI & Data Science Lab', 'GPU workstations for machine-learning and analytics projects.'],
            ['file-text', 'Student Research Projects', ($projects ? $projects . ' project courses' : 'Capstone projects') . ' guided by faculty with industry problem statements.'],
            ['handshake', 'Industry Collaboration', 'Consultancy, live projects and guest research talks with partner companies.'],
        ] as [$ic, $t, $d]): ?>
          <div class="glass-dark rounded-2xl p-5 transition duration-300 hover:-translate-y-1">
            <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-accent-300 ring-1 ring-white/15"><?= icon($ic, 'h-5 w-5') ?></span>
            <h3 class="mt-3 font-display font-bold text-white"><?= e($t) ?></h3>
            <p class="mt-1 text-sm leading-relaxed text-white/70"><?= e($d) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?= section_close() ?>

<!-- Faculty highlights -->
<?php if ($topFaculty): ?>
<?= section_open(['id' => 'faculty', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Learn From Accomplished Faculty', $counts['phd'] . ' of our ' . $counts['faculty'] . ' faculty members hold doctorates, with an average of ' . (int) round($counts['experience']) . ' years of teaching, research and industry experience.', 'Faculty Highlights', 'left', button_link('Meet All Faculty', '/faculty', 'outline', ['size' => 'sm', 'icon' => 'arrow-right'])) ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70">
    <?php foreach ($topFaculty as $f): ?><?= sa_faculty_card($f) ?><?php endforeach; ?>
  </div>
<?= section_close() ?>
<?php endif; ?>

<!-- Library & labs -->
<?= section_open(['id' => 'facilities', 'bg' => 'brand-soft', 'class' => 'scroll-mt-20']) ?>
  <?= section_heading('Library & Laboratories', 'Spaces built for deep study, experimentation and collaboration.', 'Learning Resources') ?>
  <div class="grid gap-6 lg:grid-cols-2">
    <article class="group overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card"<?= reveal_attr('slide-left') ?>>
      <div class="img-zoom aspect-[16/7] overflow-hidden"><img src="<?= e(asset('assets/images/site/campus-library.jpg')) ?>" alt="GIMT library reading hall" loading="lazy" decoding="async" class="h-full w-full object-cover"></div>
      <div class="p-6">
        <h3 class="flex items-center gap-2 font-display text-xl font-extrabold text-brand-900"><?= icon('library', 'h-5 w-5 text-accent-600') ?>Central Library &amp; Learning Centre</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-600">A quiet, air-conditioned reading hall with text and reference books, journals and digital resources, open to every student and faculty member with an automated issue-return system.</p>
        <?php if ($library): ?>
          <ul class="mt-5 grid grid-cols-3 gap-3 text-center">
            <li class="rounded-xl bg-slate-50 p-3"><span class="block font-display text-xl font-extrabold text-brand-900" data-count="<?= (int) $library['titles'] ?>"><?= number_format((int) $library['titles']) ?></span><span class="text-xs text-slate-500">Titles</span></li>
            <li class="rounded-xl bg-slate-50 p-3"><span class="block font-display text-xl font-extrabold text-brand-900" data-count="<?= (int) $library['copies'] ?>"><?= number_format((int) $library['copies']) ?></span><span class="text-xs text-slate-500">Volumes</span></li>
            <li class="rounded-xl bg-slate-50 p-3"><span class="block font-display text-xl font-extrabold text-brand-900" data-count="<?= (int) $library['journals'] ?>"><?= number_format((int) $library['journals']) ?></span><span class="text-xs text-slate-500">Journals &amp; magazines</span></li>
          </ul>
          <?php if ($library['top_categories']): ?>
            <h4 class="mt-6 font-sans text-xs font-bold uppercase tracking-wider text-slate-400"><?= (int) $library['categories'] ?> subject collections, including</h4>
            <ul class="mt-3 flex flex-wrap gap-2">
              <?php foreach ($library['top_categories'] as $c): ?><li class="rounded-full bg-brand-50 px-3 py-1 text-xs font-semibold text-brand-800"><?= e($c) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?= check_list(array_values(array_filter([
              $library['student_books'] ? 'Students borrow up to ' . $library['student_books'] . ' books at a time for ' . $library['student_days'] . ' days' : null,
              'Reference, thesis and archival collections for research work',
          ])), 'mt-5 text-sm') ?>
        <?php else: ?>
          <?= check_list(['Text, reference and competitive-exam books', 'National and international journals', 'Digital library and e-resources access'], 'mt-5 text-sm') ?>
        <?php endif; ?>
      </div>
    </article>
    <article class="group overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-card"<?= reveal_attr('slide-right', 100) ?>>
      <div class="img-zoom aspect-[16/7] overflow-hidden"><img src="<?= e(asset('assets/images/site/facility-labs.jpg')) ?>" alt="Students in a GIMT computer laboratory" loading="lazy" decoding="async" class="h-full w-full object-cover"></div>
      <div class="p-6">
        <h3 class="flex items-center gap-2 font-display text-xl font-extrabold text-brand-900"><?= icon('cpu', 'h-5 w-5 text-accent-600') ?>Labs, Seminar Halls &amp; Classrooms</h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= $counts['classrooms'] ? (int) $counts['classrooms'] . ' smart classrooms' . ($seats ? ' seating ' . number_format($seats) . ' students' : '') . ', plus' : 'Smart classrooms plus' ?> specialised spaces for practical learning.</p>
        <?php if ($spaces): ?>
          <ul class="mt-4 divide-y divide-slate-100">
            <?php foreach ($spaces as $sp): ?>
              <li class="flex items-center justify-between gap-3 py-2.5 text-sm">
                <span class="flex min-w-0 items-center gap-2.5"><span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?= $sp['type'] === 'lab' ? 'tint-blue' : ($sp['type'] === 'auditorium' ? 'tint-violet' : 'tint-green') ?>"><?= icon($sp['type'] === 'lab' ? 'monitor' : ($sp['type'] === 'auditorium' ? 'landmark' : 'presentation'), 'h-4 w-4') ?></span>
                  <span class="min-w-0"><span class="block font-semibold text-slate-800"><?= e($sp['name']) ?></span><?php if ($sp['building']): ?><span class="block truncate text-xs text-slate-500"><?= e($sp['building']) ?><?= $sp['facilities'] ? ' · ' . e($sp['facilities']) : '' ?></span><?php endif; ?></span></span>
                <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"><?= (int) $sp['capacity'] ?> seats</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </article>
  </div>
<?= section_close() ?>

<!-- Outcomes -->
<?= section_open(['id' => 'outcomes']) ?>
  <?= section_heading('Academic Outcomes', 'Numbers we track every session to keep the quality of learning high.', 'Measured Every Semester', 'center') ?>
  <?= stat_counter(array_values(array_filter([
      ['value' => $phdPct, 'suffix' => '%', 'label' => 'PhD-qualified faculty', 'caption' => $counts['phd'] . ' of ' . $counts['faculty'] . ' faculty members'],
      $attendance !== null ? ['value' => $attendance, 'decimals' => 1, 'suffix' => '%', 'label' => 'Average attendance', 'caption' => 'Session ' . ($session['name'] ?? '') . ' so far'] : null,
      ['value' => $counts['satisfaction'], 'suffix' => '%', 'label' => 'Student satisfaction', 'caption' => 'Annual student feedback survey'],
  ])), ['variant' => 'rings', 'gridClass' => 'mx-auto max-w-4xl grid-cols-1 sm:grid-cols-3']) ?>
<?= section_close() ?>

<?php site_footer([
    'cta_title' => 'Begin Your Academic Journey at GIMT',
    'cta_text' => 'Choose from ' . $counts['programs'] . ' programs taught by experienced faculty in a modern, industry-connected campus.',
    'cta_primary' => ['label' => 'Apply for Admission', 'url' => '/apply'],
    'cta_secondary' => ['label' => 'Explore Programs', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
