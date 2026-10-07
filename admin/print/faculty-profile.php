<?php
/**
 * Printable employee profile (A4, letterhead).
 *   /admin/print/faculty-profile.php?id={faculty_id}
 *   /admin/print/faculty-profile.php?id={staff_id}&type=staff
 * Sensitive payroll values are masked; the salary is printed only for users who can edit faculty records.
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/hr.php';
require_permission('faculty', 'view');

$type = ($_GET['type'] ?? 'faculty') === 'staff' ? 'staff' : 'faculty';
$id = input_int('id');
$data = $id ? hr_profile($type, $id) : null;

if (!$data) {
    http_response_code(404);
    print_layout_start(($type === 'faculty' ? 'Faculty' : 'Staff') . ' profile not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Profile not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The ' . e($type === 'faculty' ? 'faculty member' : 'staff member') . ' does not exist or has been deleted.</p></div>';
    print_layout_end();
    exit;
}

$p = $data['person'];
$s = $data['stats'];
$canSalary = can('faculty', 'edit');
$balance = hr_leave_balance($type, $id);
$subjects = $type === 'faculty' ? hr_faculty_subjects($id, current_session_id()) : [];
$leaves = db_all("SELECT l.*, lt.name AS type_name FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type
                  WHERE l.employee_type = ? AND l.employee_id = ? ORDER BY l.from_date DESC LIMIT 8", [$type, $id]);
$docs = db_all('SELECT title, doc_type, status, created_at FROM employee_documents WHERE employee_type = ? AND employee_id = ? ORDER BY created_at', [$type, $id]);
$categories = ['administration' => 'Administration', 'accounts' => 'Accounts', 'library' => 'Library', 'hostel' => 'Hostel', 'transport' => 'Transport',
    'maintenance' => 'Maintenance', 'security' => 'Security', 'it' => 'IT & Systems', 'laboratory' => 'Laboratory', 'other' => 'Other'];
$dash = '—';
$val = fn ($v) => ($v === null || $v === '') ? $dash : e((string) $v);
$age = $p['dob'] ? (int) date_diff(date_create($p['dob']), date_create('today'))->y : null;
$address = implode(', ', array_filter([$p['address'], $p['city'], $p['state'], $p['pincode']]));
$statusColors = ['active' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'on_leave' => 'bg-amber-50 text-amber-700 ring-amber-200', 'pending' => 'bg-amber-50 text-amber-700 ring-amber-200',
    'approved' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'verified' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'rejected' => 'bg-red-50 text-red-700 ring-red-200',
    'cancelled' => 'bg-slate-100 text-slate-600 ring-slate-200', 'resigned' => 'bg-red-50 text-red-700 ring-red-200', 'retired' => 'bg-slate-100 text-slate-600 ring-slate-200', 'inactive' => 'bg-red-50 text-red-700 ring-red-200'];
$pill = fn (string $st) => '<span class="inline-block rounded-full px-2 py-px text-[10px] font-semibold ring-1 ring-inset ' . ($statusColors[$st] ?? 'bg-slate-100 text-slate-600 ring-slate-200') . '">' . e(label_from_key($st)) . '</span>';
$row = fn (string $label, string $value) => '<tr><th class="w-[38%] py-1 pr-3 text-left align-top text-[11px] font-medium text-slate-500">' . e($label) . '</th><td class="py-1 align-top text-[12px] font-medium text-slate-800">' . $value . '</td></tr>';
$section = fn (string $title) => '<h2 class="mb-2 mt-5 border-b border-slate-200 pb-1 font-display text-[13px] font-bold uppercase tracking-wider text-brand-800">' . e($title) . '</h2>';
$days = fn ($d) => rtrim(rtrim(number_format((float) $d, 1), '0'), '.');

print_layout_start(($type === 'faculty' ? 'Faculty' : 'Staff') . ' Profile - ' . $p['full_name'] . ' (' . $p['employee_id'] . ')');
?>
<div class="mt-5 flex items-start gap-5">
  <?php if ($p['photo']): ?>
    <img src="<?= e(upload_url($p['photo'])) ?>" alt="" class="h-28 w-24 rounded-lg object-cover ring-1 ring-slate-200">
  <?php else: ?>
    <div class="flex h-28 w-24 items-center justify-center rounded-lg bg-brand-50 font-display text-3xl font-bold text-brand-800 ring-1 ring-brand-100"><?= e(initials($p['first_name'] . ' ' . ($p['last_name'] ?? ''))) ?></div>
  <?php endif; ?>
  <div class="min-w-0 flex-1">
    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-accent-700"><?= $type === 'faculty' ? 'Faculty Profile' : 'Staff Profile' ?></p>
    <h1 class="font-display text-2xl font-extrabold leading-tight text-brand-900"><?= e($p['full_name']) ?></h1>
    <p class="mt-0.5 text-sm text-slate-700"><?= e($p['designation']) ?><?= $p['department_name'] ? ' · ' . e($p['department_name']) : '' ?><?= $type === 'staff' && $p['section'] ? ' · ' . e($p['section']) : '' ?></p>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-slate-600">
      <span class="rounded bg-slate-100 px-2 py-0.5 font-mono font-semibold text-slate-800"><?= e($p['employee_id']) ?></span>
      <?= $pill($p['status']) ?>
      <span class="rounded-full bg-brand-50 px-2 py-px text-[10px] font-semibold text-brand-800 ring-1 ring-inset ring-brand-100"><?= e(label_from_key($p['employment_type'])) ?></span>
      <?php if (!empty($p['is_hod_of'])): ?><span class="rounded-full bg-accent-50 px-2 py-px text-[10px] font-semibold text-accent-800 ring-1 ring-inset ring-accent-200">Head of Department</span><?php endif; ?>
    </div>
  </div>
  <div class="text-center">
    <div data-qr="<?= e(absolute_url('admin/' . $type . '/' . $id)) ?>" class="h-20 w-20"></div>
    <p class="mt-1 text-[9px] text-slate-500">Scan for e-profile</p>
  </div>
</div>

<div class="mt-5 grid grid-cols-4 gap-2">
  <?php
  $tiles = $type === 'faculty'
      ? [['Experience', $s['experience_years'] !== null ? $days($s['experience_years']) . ' yrs' : $dash], ['Subjects', (string) ($s['subjects'] ?? 0)], ['Periods / week', (string) ($s['weekly_periods'] ?? 0)], ['Attendance', $s['attendance_percent'] !== null ? $s['attendance_percent'] . '%' : $dash]]
      : [['Service', $s['tenure_years'] !== null ? $days($s['tenure_years']) . ' yrs' : $dash], ['Category', $categories[$p['category']] ?? label_from_key((string) $p['category'])], ['Leave taken', $days($s['leave_taken']) . ' d'], ['Attendance', $s['attendance_percent'] !== null ? $s['attendance_percent'] . '%' : $dash]];
  foreach ($tiles as [$k, $v]): ?>
    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
      <p class="text-[9px] font-semibold uppercase tracking-wider text-slate-500"><?= e($k) ?></p>
      <p class="font-display text-base font-bold text-brand-900"><?= e($v) ?></p>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-2 gap-6">
  <div>
    <?= $section('Personal & contact') ?>
    <table class="w-full">
      <?= $row('Gender', $val($p['gender'] ? label_from_key($p['gender']) : null)) ?>
      <?= $row('Date of birth', $p['dob'] ? e(format_date($p['dob'])) . ($age ? ' (' . $age . ' yrs)' : '') : $dash) ?>
      <?= $row('Email', $val($p['email'])) ?>
      <?= $row('Mobile', $val($p['phone'])) ?>
      <?= $row('Alternate phone', $val($p['alternate_phone'])) ?>
      <?= $row('Address', $val($address)) ?>
    </table>
  </div>
  <div>
    <?= $section($type === 'faculty' ? 'Professional' : 'Employment') ?>
    <table class="w-full">
      <?php if ($type === 'faculty'): ?>
        <?= $row('Qualification', $val($p['qualification'])) ?>
        <?= $row('Specialization', $val($p['specialization'])) ?>
        <?= $row('Experience', $p['experience_years'] !== null ? e($days($p['experience_years'])) . ' years' : $dash) ?>
        <?= $row('Publications', $val($p['publications_count'])) ?>
      <?php else: ?>
        <?= $row('Category', e($categories[$p['category']] ?? label_from_key((string) $p['category']))) ?>
        <?= $row('Office / section', $val($p['section'])) ?>
        <?= $row('Qualification', $val($p['qualification'])) ?>
      <?php endif; ?>
      <?= $row('Joining date', $p['joining_date'] ? e(format_date($p['joining_date'])) . ($s['tenure_years'] !== null ? ' (' . e($days($s['tenure_years'])) . ' yrs)' : '') : $dash) ?>
      <?= $row('Employment type', e(label_from_key($p['employment_type']))) ?>
      <?= $row('Login account', $data['account'] ? e($data['account']['username']) . ' · ' . e((string) $data['account']['roles']) : 'Not created') ?>
    </table>
  </div>
</div>

<?php if ($type === 'faculty' && !empty($p['research_interests'])): ?>
  <?= $section('Research interests') ?>
  <p class="text-[12px] text-slate-700"><?= e($p['research_interests']) ?></p>
<?php endif; ?>

<?php if ($type === 'faculty'): ?>
  <?= $section('Assigned subjects · session ' . ($data['session']['name'] ?? '')) ?>
  <?php if ($subjects): ?>
    <table class="w-full border-collapse text-[11px]">
      <thead><tr class="bg-brand-900 text-left text-white"><th class="px-2 py-1">Code</th><th class="px-2 py-1">Subject</th><th class="px-2 py-1">Class</th><th class="px-2 py-1 text-center">Credits</th><th class="px-2 py-1 text-center">Periods/wk</th></tr></thead>
      <tbody>
        <?php foreach ($subjects as $i => $sb): ?>
          <tr class="<?= $i % 2 ? 'bg-slate-50' : '' ?>">
            <td class="border border-slate-200 px-2 py-1 font-mono font-semibold"><?= e($sb['code']) ?></td>
            <td class="border border-slate-200 px-2 py-1"><?= e($sb['name']) ?></td>
            <td class="border border-slate-200 px-2 py-1"><?= e($sb['program'] . ' · Sem ' . $sb['semester_no'] . ($sb['section'] ? ' · Sec ' . $sb['section'] : '')) ?></td>
            <td class="border border-slate-200 px-2 py-1 text-center"><?= e($days($sb['credits'])) ?></td>
            <td class="border border-slate-200 px-2 py-1 text-center"><?= (int) $sb['weekly_periods'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="text-[12px] text-slate-500">No subjects assigned for the current session.</p>
  <?php endif; ?>
<?php endif; ?>

<div class="grid grid-cols-2 gap-6">
  <div>
    <?= $section('Leave balance · session ' . ($data['session']['name'] ?? '')) ?>
    <table class="w-full border-collapse text-[11px]">
      <thead><tr class="bg-slate-100 text-left text-slate-600"><th class="px-2 py-1">Type</th><th class="px-2 py-1 text-center">Quota</th><th class="px-2 py-1 text-center">Used</th><th class="px-2 py-1 text-center">Left</th></tr></thead>
      <tbody>
        <?php foreach ($balance as $b): if (!$b['unlimited'] && $b['quota'] >= 100 && !$b['used']) { continue; } ?>
          <tr>
            <td class="border-b border-slate-100 px-2 py-1"><?= e($b['name']) ?></td>
            <td class="border-b border-slate-100 px-2 py-1 text-center"><?= $b['unlimited'] ? '—' : e($days($b['quota'])) ?></td>
            <td class="border-b border-slate-100 px-2 py-1 text-center"><?= e($days($b['used'])) ?><?= $b['pending'] ? ' <span class="text-amber-600">(+' . e($days($b['pending'])) . ')</span>' : '' ?></td>
            <td class="border-b border-slate-100 px-2 py-1 text-center font-semibold"><?= $b['unlimited'] ? '—' : e($days($b['available'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div>
    <?= $section('Recent leave') ?>
    <?php if ($leaves): ?>
      <table class="w-full border-collapse text-[11px]">
        <tbody>
          <?php foreach ($leaves as $l): ?>
            <tr>
              <td class="border-b border-slate-100 py-1 pr-2"><?= e($l['type_name'] ?? label_from_key($l['leave_type'])) ?></td>
              <td class="border-b border-slate-100 py-1 pr-2 whitespace-nowrap"><?= e(format_date($l['from_date'], 'd M')) ?><?= $l['to_date'] !== $l['from_date'] ? ' – ' . e(format_date($l['to_date'], 'd M Y')) : ' ' . e(date('Y', strtotime($l['from_date']))) ?></td>
              <td class="border-b border-slate-100 py-1 pr-2 text-center"><?= e($days($l['days'])) ?>d</td>
              <td class="border-b border-slate-100 py-1 text-right"><?= $pill($l['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="text-[12px] text-slate-500">No leave applications.</p>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-cols-2 gap-6">
  <div>
    <?= $section('Personnel documents') ?>
    <?php if ($docs): ?>
      <ul class="space-y-1 text-[11px]">
        <?php foreach ($docs as $d): ?>
          <li class="flex items-center justify-between gap-2 border-b border-slate-100 pb-1"><span><?= e($d['title']) ?></span><?= $pill($d['status']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-[12px] text-slate-500">No documents on file.</p>
    <?php endif; ?>
  </div>
  <div>
    <?= $section('Payroll reference') ?>
    <table class="w-full">
      <?= $row('Monthly gross', $canSalary && $p['salary'] !== null ? e(money($p['salary'])) : '<span class="text-slate-400">Restricted</span>') ?>
      <?= $row('Bank', $val($p['bank_name'])) ?>
      <?= $row('Account no.', $val($p['bank_account_masked'])) ?>
      <?= $row('IFSC', $val($p['bank_ifsc'])) ?>
      <?= $row('PAN', $val($p['pan_masked'])) ?>
      <?= $row('LOP days (session)', e($days(array_sum(array_column($data['lop'], 'days'))))) ?>
    </table>
  </div>
</div>

<div class="mt-12 grid grid-cols-3 gap-8 text-center text-[11px] text-slate-600">
  <div><div class="mb-1 border-t border-slate-400 pt-1">Employee</div></div>
  <div><div class="mb-1 border-t border-slate-400 pt-1"><?= $type === 'faculty' ? 'Head of Department' : 'Section In-charge' ?></div></div>
  <div><div class="mb-1 border-t border-slate-400 pt-1">Registrar / HR</div></div>
</div>
<p class="mt-6 text-center text-[9px] text-slate-400">Generated on <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(current_user()['name'] ?? 'SmartCampus') ?> · Confidential — for official use only.</p>
<?php
log_activity('view', $type, $id, 'Printed profile of ' . $p['full_name']);
print_layout_end();
