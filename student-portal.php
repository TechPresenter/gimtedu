<?php
/**
 * Student Portal information page (/student-portal, noindex,follow) — linked as "Student Login" in the top bar.
 * Deliberately NOT a login form: student self-service accounts are issued by the institute on request. The page explains
 * which services enrolled students can use and where (office / page that exists), how to request portal access
 * (prefilled email to the support desk from settings), quick links that really exist and account-safety tips.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/partials/content.php';

$c = cp_contact();
header('X-Robots-Tag: noindex, follow');
$accessMail = 'mailto:' . $c['support_email'] . '?subject=' . rawurlencode('Student portal access request') . '&body=' . rawurlencode(
    "Dear Sir/Madam,\n\nPlease create my student portal account.\n\nFull name:\nEnrolment / Student ID:\nProgram and semester:\nRegistered mobile number:\n\nThank you.");

// Services enrolled students use — each says where to get it today (links only when the page exists).
$services = [
    ['calendar-check', 'Attendance', 'Your attendance is recorded in the institute’s SmartCampus system. Ask your faculty mentor or the academic office for your subject-wise attendance report.', null],
    ['file-check', 'Results & grade cards', 'Results are published by the examination cell and grade cards are issued after each semester. Certificates issued by the institute can be verified online.', ['Verify a certificate', '/verify-certificate']],
    ['wallet', 'Fees & receipts', 'Pay semester fees at the accounts office or through the payment modes notified by the institute. A receipt is issued for every payment.', ['Fee policy', '/fee-policy']],
    ['calendar-days', 'Timetable & academic calendar', 'Your department shares the class timetable at the start of each semester; key dates are in the academic calendar.', ['Academic calendar', '/academics#calendar']],
    ['megaphone', 'Notices & circulars', 'Examination schedules, holidays, scholarship and placement announcements are published on the notice board.', ['Notice board', '/notices']],
    ['scroll-text', 'Certificates & letters', 'Request bonafide, character and other certificates at the academic office with your identity card. Allow a few working days for processing.', null],
    ['library', 'Library account', 'Issue and return books at the library counter with your identity card; ask the librarian about digital resources.', null],
    ['building-2', 'Hostel & transport', 'Hostel allocation, transport routes and related requests are handled by the hostel and transport offices.', ['Hostel & campus', '/campus#hostel']],
    ['messages-square', 'Help & grievances', 'Academic, examination, fee or facility concerns can be raised through the grievance redressal process.', ['Grievance redressal', '/grievance-redressal-policy']],
];
$quick = [
    ['Notice Board', '/notices', 'megaphone'], ['Academic Calendar', '/academics#calendar', 'calendar-days'], ['Verify a Certificate', '/verify-certificate', 'badge-check'],
    ['Fees & Scholarships', '/admissions#fees', 'wallet'], ['Examination Policy', '/examination-policy', 'file-check'], ['Anti-Ragging Help', '/anti-ragging-policy', 'shield-alert'],
    ['FAQs', '/faq', 'circle-help'], ['Contact the Office', '/contact', 'phone'],
];
$quick = array_values(array_filter($quick, fn ($q) => cp_url_ok($q[1])));

site_header([
    'title' => 'Student Portal — Access & Services',
    'description' => 'How enrolled ' . setting('institute_short_name', 'GIMT') . ' students get portal access and use attendance, results, fees, timetable, notices and certificate services.',
    'active' => 'student-portal',
    'robots' => 'noindex,follow',
    'preload' => [asset('assets/images/site/students-laptop.jpg')],
    'schema' => [sa_breadcrumb_ld([['Home', ''], ['Student Portal', 'student-portal']])],
]);

page_hero('Student Portal', 'Your records and campus services', ['Student Portal'], 'assets/images/site/students-laptop.jpg',
    'Attendance, results, fees, timetables, notices and certificates for enrolled students — and how to get your portal login.',
    [['icon' => 'shield-check', 'label' => 'Secure, role-based access'], ['icon' => 'id-card', 'label' => 'For enrolled students']],
    ['image_alt' => 'Students using laptops on campus', 'highlight' => 'Portal', 'badge' => false,
     'actions' => '<a href="' . e($accessMail) . '" class="btn btn-apply">' . icon('key-round', 'h-4 w-4') . 'Request Portal Access</a>'
        . '<a href="#services" class="btn btn-outline">How to use services</a>']);
?>

<?= section_open(['padding' => 'tight', 'class' => '!pb-0']) ?>
  <div class="flex flex-col gap-4 rounded-[22px] border border-amber-200 bg-amber-50 p-5 text-amber-950 sm:flex-row sm:items-start sm:p-6" role="note">
    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white text-amber-700 shadow-sm"><?= icon('info', 'h-5 w-5') ?></span>
    <div>
      <h2 class="font-display text-lg font-extrabold">Online self-service is provided on request</h2>
      <p class="mt-1 text-[15px] leading-relaxed">Student portal accounts are issued by the institute on request to enrolled students. Until you receive your login, every service below is available from the office named against it. The institute will never ask for your password by phone, SMS or email.</p>
    </div>
  </div>
<?= section_close() ?>

<?= section_open(['id' => 'services', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('Services for enrolled students', 'What you can do — and where to do it today.', 'Student services', 'left', '', ['highlight' => 'Services']) ?>
  <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-stagger="60">
    <?php foreach ($services as [$ic, $title, $text, $lnk]): $ok = $lnk && cp_url_ok($lnk[1]); ?>
      <li class="group relative flex h-full flex-col rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-card">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-accent-50 text-accent-600 transition group-hover:bg-accent-600 group-hover:text-white"><?= icon($ic, 'h-6 w-6') ?></span>
        <h3 class="mt-4 font-display text-lg font-extrabold text-brand-900"><?= e($title) ?></h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($text) ?></p>
        <?php if ($ok): ?><a href="<?= e(cms_link($lnk[1])) ?>" class="link-arrow mt-auto pt-4 text-sm"><?= e($lnk[0]) ?><?= icon('arrow-right', 'h-4 w-4') ?></a><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?= section_close() ?>

<?= section_open(['id' => 'access', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
    <div class="lg:col-span-7">
      <?= section_heading('How to get your portal login', 'Accounts are created only for enrolled students, after verification by the institute.', 'Portal access', 'left', '', ['highlight' => 'login']) ?>
      <?= progress_steps([
          ['title' => 'Keep your student ID ready', 'text' => 'You need your enrolment / student ID and the mobile number and email registered at admission.', 'icon' => 'id-card'],
          ['title' => 'Request access', 'text' => 'Email ' . $c['support_email'] . ' from your registered email address, or visit the academic office with your identity card.', 'icon' => 'mail'],
          ['title' => 'Identity check', 'text' => 'The office verifies your details against the student records before creating your account.', 'icon' => 'shield-check'],
          ['title' => 'Sign in and change your password', 'text' => 'You receive your login details on your registered email. Change the password when you first sign in and never share it.', 'icon' => 'key-round'],
      ], ['orientation' => 'vertical']) ?>
      <div class="mt-8 flex flex-wrap gap-3">
        <a href="<?= e($accessMail) ?>" class="btn btn-apply"><?= icon('mail', 'h-4 w-4') ?>Email the Support Desk</a>
        <a href="<?= e(cp_tel($c['phone'])) ?>" class="btn btn-callback"><?= icon('phone', 'h-4 w-4') ?><?= e($c['phone']) ?></a>
      </div>
    </div>
    <aside class="space-y-5 lg:col-span-5" aria-label="Quick links and help">
      <?php if ($quick): ?>
        <div class="rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm">
          <h2 class="font-display text-lg font-extrabold text-brand-900">Quick links</h2>
          <ul class="mt-3 grid gap-1 sm:grid-cols-2 lg:grid-cols-1">
            <?php foreach ($quick as [$label, $href, $ic]): ?>
              <li><a href="<?= e(cms_link($href)) ?>" class="group flex items-center gap-3 rounded-xl px-2 py-2 text-sm font-medium text-brand-900 transition hover:bg-warm"><?= icon($ic, 'h-4 w-4 text-accent-600') ?><span class="flex-1"><?= e($label) ?></span><?= icon('arrow-right', 'h-4 w-4 text-slate-400 transition group-hover:translate-x-0.5') ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <?= cp_help_card($c) ?>
      <div class="rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm">
        <h2 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('lock', 'h-5 w-5 text-accent-600') ?>Keep your account safe</h2>
        <?= check_list(['Never share your password or one-time codes with anyone.', 'Sign out on shared or lab computers.', 'Report suspicious messages asking for your login to ' . $c['support_email'] . '.'], 'mt-3 text-sm') ?>
      </div>
    </aside>
  </div>
<?= section_close() ?>

<?= section_open(['padding' => 'tight']) ?>
  <div class="flex flex-col items-start gap-4 rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
    <p class="flex items-center gap-3 text-brand-900"><span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-brand-50 text-brand-800"><?= icon('user-check', 'h-5 w-5') ?></span><span><strong>Faculty or staff member?</strong> Staff accounts and sign-in are on the faculty portal page.</span></p>
    <?= button_link('Faculty & Staff Portal', '/faculty-portal', 'outline', ['icon' => 'arrow-right']) ?>
  </div>
<?= section_close() ?>
<?php site_footer([
    'cta_title' => 'Not a Student Yet?',
    'cta_text' => 'Explore our industry-focused programs, check eligibility and apply online in a few minutes.',
    'cta_primary' => cp_route_ok('/apply') ? ['label' => 'Apply for Admission', 'url' => '/apply'] : ['label' => 'Contact Us', 'url' => '/contact'],
    'cta_secondary' => ['label' => 'Explore Courses', 'url' => '/programs', 'icon' => 'graduation-cap'],
]);
