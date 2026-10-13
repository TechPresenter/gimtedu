<?php
/**
 * Faculty & Staff Portal information page (/faculty-portal, noindex,follow) — linked as "Faculty Login" in the top bar.
 * Not a login form of its own: authorised staff sign in to the SmartCampus administration portal (/admin/login), where
 * role-based permissions decide what each person sees. Explains what the portal is used for, how accounts are issued
 * and reset, quick links that exist and account-safety rules.
 */
require __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/partials/content.php';

$c = cp_contact();
header('X-Robots-Tag: noindex, follow');
$loginUrl = admin_url('login');
$forgotUrl = admin_url('forgot-password');
$accessMail = 'mailto:' . $c['support_email'] . '?subject=' . rawurlencode('Staff portal account request') . '&body=' . rawurlencode(
    "Dear Sir/Madam,\n\nPlease create / restore my SmartCampus staff account.\n\nFull name:\nEmployee ID:\nDepartment and designation:\nOfficial email:\n\nThank you.");

$tools = [
    ['calendar-check', 'Attendance', 'Mark and review class attendance for the subjects and sections assigned to you.'],
    ['file-check', 'Examinations & marks', 'Enter internal and practical marks, follow examination schedules and view results.'],
    ['calendar-days', 'Timetable', 'See your weekly teaching timetable, rooms and the class timetables of your department.'],
    ['users', 'Student information', 'View the students of your classes and their academic progress, as permitted for your role.'],
    ['megaphone', 'Notices & events', 'Read institute circulars and, where your role allows, publish notices and events for students.'],
    ['library', 'Library', 'Search the catalogue and manage library transactions if you are part of the library team.'],
];
$quick = [
    ['Academic Calendar', '/academics#calendar', 'calendar-days'], ['Notice Board', '/notices', 'megaphone'], ['Faculty Directory', '/faculty', 'users'],
    ['Examination Policy', '/examination-policy', 'file-check'], ['Code of Conduct', '/code-of-conduct', 'badge-check'],
    ['Internal Complaints Committee', '/internal-complaints-committee', 'shield-check'], ['Grievance Redressal', '/grievance-redressal-policy', 'messages-square'],
];
$quick = array_values(array_filter($quick, fn ($q) => cp_url_ok($q[1])));

site_header([
    'title' => 'Faculty & Staff Portal — Sign-in & Access',
    'description' => 'SmartCampus sign-in for ' . setting('institute_short_name', 'GIMT') . ' faculty and staff: attendance, marks, timetables and notices, and how to request or reset an account.',
    'active' => 'faculty-portal',
    'robots' => 'noindex,follow',
    'preload' => [asset('assets/images/site/campus-building.jpg')],
    'schema' => [sa_breadcrumb_ld([['Home', ''], ['Faculty & Staff Portal', 'faculty-portal']])],
]);

page_hero('Faculty & Staff Portal', 'SmartCampus for authorised staff', ['Faculty & Staff Portal'], 'assets/images/site/campus-building.jpg',
    'Faculty and staff sign in to SmartCampus — the institute’s secure, role-based system for attendance, marks, timetables, notices and records.',
    [['icon' => 'lock', 'label' => 'Authorised staff only'], ['icon' => 'shield-check', 'label' => 'Role-based permissions']],
    ['image_alt' => 'The GIMT campus building', 'highlight' => 'Portal', 'badge' => false,
     'actions' => '<a href="' . e($loginUrl) . '" class="btn btn-explore">' . icon('log-in', 'h-4 w-4') . 'Staff Sign-in</a>'
        . '<a href="' . e($accessMail) . '" class="btn btn-outline">' . icon('mail', 'h-4 w-4') . 'Request an Account</a>']);
?>

<?= section_open(['id' => 'tools', 'class' => 'scroll-mt-24']) ?>
  <?= section_heading('What you can do in SmartCampus', 'What you see depends on the role assigned to your account by the institute.', 'For faculty & staff', 'left', '', ['highlight' => 'SmartCampus']) ?>
  <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" data-stagger="60">
    <?php foreach ($tools as [$ic, $title, $text]): ?>
      <li class="group flex h-full flex-col rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-card">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-50 text-brand-800 transition group-hover:bg-brand-900 group-hover:text-white"><?= icon($ic, 'h-6 w-6') ?></span>
        <h3 class="mt-4 font-display text-lg font-extrabold text-brand-900"><?= e($title) ?></h3>
        <p class="mt-2 text-sm leading-relaxed text-slate-600"><?= e($text) ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
<?= section_close() ?>

<?= section_open(['id' => 'access', 'bg' => 'light', 'class' => 'scroll-mt-24']) ?>
  <div class="grid gap-10 lg:grid-cols-12 lg:gap-12">
    <div class="lg:col-span-7">
      <?= section_heading('Accounts and passwords', 'Staff accounts are created by the institute’s system administrator when you join.', 'Access', 'left', '', ['highlight' => 'passwords']) ?>
      <div class="space-y-4">
        <?php foreach ([
            ['user-check', 'New to the institute?', 'Your account is set up after joining formalities. If you have not received your sign-in details, email the support desk with your employee ID and official email address.'],
            ['key-round', 'Forgot your password?', 'Use “Forgot password” on the sign-in page — a reset link is sent to your registered email address.'],
            ['user-cog', 'Need different access?', 'Permissions follow your role (for example faculty, examination cell or accounts). Ask your head of department to request a change from the system administrator.'],
            ['lock', 'Leaving or on long leave?', 'Inform the office so your access can be suspended; accounts are reviewed periodically.'],
        ] as [$ic, $title, $text]): ?>
          <div class="flex gap-4 rounded-[20px] border border-warm-2 bg-white p-5 shadow-sm">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-50 text-accent-600"><?= icon($ic, 'h-5 w-5') ?></span>
            <div><h3 class="font-display text-base font-bold text-brand-900"><?= e($title) ?></h3><p class="mt-1 text-sm leading-relaxed text-slate-600"><?= e($text) ?></p></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-8 flex flex-wrap gap-3">
        <a href="<?= e($loginUrl) ?>" class="btn btn-explore"><?= icon('log-in', 'h-4 w-4') ?>Staff Sign-in</a>
        <a href="<?= e($forgotUrl) ?>" class="btn btn-outline"><?= icon('key-round', 'h-4 w-4') ?>Reset Password</a>
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
        <h2 class="flex items-center gap-2 font-display text-lg font-extrabold text-brand-900"><?= icon('shield-check', 'h-5 w-5 text-accent-600') ?>Security rules</h2>
        <?= check_list(['Student records are confidential — use them only for your official duties.', 'Never share your password; the institute will never ask for it.', 'Sign out after use, especially on shared computers.', 'All actions in SmartCampus are logged.'], 'mt-3 text-sm') ?>
      </div>
    </aside>
  </div>
<?= section_close() ?>

<?= section_open(['padding' => 'tight']) ?>
  <div class="flex flex-col items-start gap-4 rounded-[22px] border border-warm-2 bg-white p-6 shadow-sm sm:flex-row sm:items-center sm:justify-between">
    <p class="flex items-center gap-3 text-brand-900"><span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-accent-50 text-accent-600"><?= icon('graduation-cap', 'h-5 w-5') ?></span><span><strong>Are you a student?</strong> See how to get your student portal access.</span></p>
    <?= button_link('Student Portal', '/student-portal', 'outline', ['icon' => 'arrow-right']) ?>
  </div>
<?= section_close() ?>
<?php site_footer();
