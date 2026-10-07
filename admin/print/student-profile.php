<?php
/**
 * Printable student profile (A4, letterhead).
 *   /admin/print/student-profile.php?id={student_id}
 * Sections from other modules (attendance, fees, results, hostel, transport) are printed only when the user may view them.
 * Aadhaar is always masked.
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/students.php';
require_login();
require_permission('students', 'view');

$id = input_int('id');
$s = $id ? students_profile_row($id) : null;
if (!$s) {
    http_response_code(404);
    print_layout_start('Student not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Student not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The student does not exist or has been deleted.</p>'
        . '<a href="' . e(admin_url('students')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to students</a></div>';
    print_layout_end();
    exit;
}

$parents = students_parents($id);
$history = db_all('SELECT sa.semester_no, sa.status, sa.sgpa, sa.cgpa, sa.attendance_percent, sa.roll_no, sa.promoted_at, ses.name AS session_name, sc.name AS section_name
                   FROM student_academic sa LEFT JOIN academic_sessions ses ON ses.id = sa.academic_session_id LEFT JOIN sections sc ON sc.id = sa.section_id
                   WHERE sa.student_id = ? ORDER BY sa.semester_no, sa.id', [$id]);
$docs = db_all('SELECT title, doc_type, status, remarks, created_at FROM student_documents WHERE student_id = ? ORDER BY created_at', [$id]);
$att = can('attendance', 'view') ? students_attendance_stats($id) : null;
$fees = can('fees', 'view') ? students_fee_stats($id) : null;
$invoices = $fees ? db_all("SELECT invoice_no, title, net_amount, paid_amount, balance_amount, due_date, status FROM student_fees WHERE student_id = ? AND status <> 'cancelled' ORDER BY created_at DESC LIMIT 8", [$id]) : [];
$gpa = can('results', 'view') || can('examination', 'view') ? students_gpa($id) : null;
$results = can('results', 'view') ? db_all('SELECT e.name AS exam_name, r.semester_no, r.percentage, r.sgpa, r.cgpa, r.result_status FROM results r JOIN exams e ON e.id = r.exam_id WHERE r.student_id = ? ORDER BY r.semester_no, r.id', [$id]) : [];
$hostel = can('hostel', 'view') ? students_hostel_current($id) : null;
$transport = can('transport', 'view') ? students_transport_current($id) : null;
$docStats = students_document_stats($id);

$dash = '—';
$val = fn ($v) => ($v === null || $v === '') ? $dash : e((string) $v);
$statusColors = ['active' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'verified' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'paid' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    'promoted' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'studying' => 'bg-brand-50 text-brand-800 ring-brand-200',
    'pending' => 'bg-amber-50 text-amber-700 ring-amber-200', 'partial' => 'bg-amber-50 text-amber-700 ring-amber-200', 'detained' => 'bg-amber-50 text-amber-700 ring-amber-200',
    'rejected' => 'bg-red-50 text-red-700 ring-red-200', 'overdue' => 'bg-red-50 text-red-700 ring-red-200', 'inactive' => 'bg-red-50 text-red-700 ring-red-200', 'suspended' => 'bg-red-50 text-red-700 ring-red-200',
    'dropped' => 'bg-red-50 text-red-700 ring-red-200', 'graduated' => 'bg-blue-50 text-blue-700 ring-blue-200', 'alumni' => 'bg-blue-50 text-blue-700 ring-blue-200',
    'PASS' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'FAIL' => 'bg-red-50 text-red-700 ring-red-200', 'BACKLOG' => 'bg-red-50 text-red-700 ring-red-200'];
$pill = fn (string $st, ?string $label = null) => '<span class="inline-block rounded-full px-2 py-px text-[10px] font-semibold ring-1 ring-inset ' . ($statusColors[$st] ?? 'bg-slate-100 text-slate-600 ring-slate-200') . '">'
    . e($label ?? ($st === strtoupper($st) ? $st : label_from_key($st))) . '</span>';
$row = fn (string $label, string $value) => '<tr><th class="w-[40%] py-1 pr-3 text-left align-top text-[11px] font-medium text-slate-500">' . e($label) . '</th><td class="py-1 align-top text-[12px] font-medium text-slate-800">' . $value . '</td></tr>';
$section = fn (string $title) => '<h2 class="mb-2 mt-5 border-b border-slate-200 pb-1 font-display text-[13px] font-bold uppercase tracking-wider text-brand-800">' . e($title) . '</h2>';
$th = fn (string $label, string $align = 'left') => '<th class="border-b border-slate-200 bg-slate-50 px-2 py-1.5 text-' . $align . ' text-[10px] font-semibold uppercase tracking-wider text-slate-500">' . e($label) . '</th>';
$td = fn (string $html, string $align = 'left') => '<td class="border-b border-slate-100 px-2 py-1.5 text-' . $align . ' text-[11px] text-slate-700">' . $html . '</td>';
$address = implode(', ', array_filter([$s['address'], $s['city'], $s['state'], $s['pincode'], $s['country']]));
$statusLabel = students_status_options()[$s['status']] ?? label_from_key($s['status']);

log_activity('print', 'students', $id, 'Printed profile of ' . $s['full_name'] . ' (' . $s['student_uid'] . ')');
print_layout_start('Student Profile - ' . $s['full_name'] . ' (' . $s['student_uid'] . ')');
?>
<div class="mt-5 flex items-start gap-5">
  <?php if ($s['photo']): ?>
    <img src="<?= e(upload_url($s['photo'])) ?>" alt="" class="h-28 w-24 rounded-lg object-cover ring-1 ring-slate-200">
  <?php else: ?>
    <div class="flex h-28 w-24 items-center justify-center rounded-lg bg-brand-50 font-display text-3xl font-bold text-brand-800 ring-1 ring-brand-100"><?= e(initials($s['full_name'])) ?></div>
  <?php endif; ?>
  <div class="min-w-0 flex-1">
    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-accent-700">Student Profile</p>
    <h1 class="font-display text-2xl font-extrabold leading-tight text-brand-900"><?= e($s['full_name']) ?></h1>
    <p class="mt-0.5 text-sm text-slate-700"><?= e($s['program_full_name']) ?><?= $s['course_name'] ? ' · ' . e($s['course_name']) : '' ?></p>
    <p class="text-[12px] text-slate-600">Semester <?= (int) $s['current_semester'] ?> of <?= (int) $s['total_semesters'] ?><?= $s['section_name'] ? ' · Section ' . e($s['section_name']) : '' ?><?= $s['batch_name'] ? ' · ' . e($s['batch_name']) : '' ?></p>
    <div class="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-slate-600">
      <span class="rounded bg-slate-100 px-2 py-0.5 font-mono font-semibold text-slate-800"><?= e($s['student_uid']) ?></span>
      <span>Adm. <strong class="font-mono"><?= e($s['admission_no']) ?></strong></span>
      <?php if ($s['roll_no']): ?><span>Roll <strong class="font-mono"><?= e($s['roll_no']) ?></strong></span><?php endif; ?>
      <?= $pill($s['status'], $statusLabel) ?>
    </div>
  </div>
  <div class="text-center">
    <div data-qr="<?= e(absolute_url('admin/students/' . $id)) ?>" class="h-20 w-20"></div>
    <p class="mt-1 text-[9px] text-slate-500">Scan for e-profile</p>
  </div>
</div>

<?php if ($s['status'] !== 'active' && $s['status_reason']): ?>
  <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-800"><strong><?= e($statusLabel) ?>:</strong> <?= e($s['status_reason']) ?></p>
<?php endif; ?>

<div class="mt-5 grid grid-cols-4 gap-2">
  <?php
  $tiles = [
      ['Attendance', $att ? ($att['percent'] !== null ? $att['percent'] . '%' : $dash) : 'N/A', $att && $att['total'] ? $att['attended'] . ' / ' . $att['total'] . ' classes' : ''],
      ['CGPA', $gpa && $gpa['cgpa'] !== null ? number_format($gpa['cgpa'], 2) : $dash, $gpa && $gpa['semester'] ? 'Up to semester ' . $gpa['semester'] : ''],
      ['Fee balance', $fees ? money($fees['balance']) : 'N/A', $fees && $fees['overdue'] > 0 ? money($fees['overdue']) . ' overdue' : ''],
      ['Documents', $docStats['verified'] . ' / ' . $docStats['total'], 'verified'],
  ];
  foreach ($tiles as [$k, $v, $sub]): ?>
    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
      <p class="text-[9px] font-semibold uppercase tracking-wider text-slate-500"><?= e($k) ?></p>
      <p class="font-display text-lg font-bold text-brand-900"><?= e($v) ?></p>
      <?php if ($sub): ?><p class="text-[9px] text-slate-500"><?= e($sub) ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid grid-cols-2 gap-6" style="break-inside:avoid">
  <div>
    <?= $section('Personal details') ?>
    <table class="w-full"><tbody>
      <?= $row('Gender', $val(label_from_key((string) $s['gender']))) ?>
      <?= $row('Date of birth', $s['dob'] ? e(format_date($s['dob'])) . ($s['age'] !== null ? ' (' . (int) $s['age'] . ' yrs)' : '') : $dash) ?>
      <?= $row('Blood group', $val($s['blood_group'])) ?>
      <?= $row('Category', $val($s['category'])) ?>
      <?= $row('Religion', $val($s['religion'])) ?>
      <?= $row('Nationality', $val($s['nationality'])) ?>
      <?= $row('Aadhaar', $s['aadhaar_masked'] ? '<span class="font-mono">' . e($s['aadhaar_masked']) . '</span>' : $dash) ?>
    </tbody></table>
  </div>
  <div>
    <?= $section('Contact') ?>
    <table class="w-full"><tbody>
      <?= $row('Mobile', $val($s['mobile'])) ?>
      <?= $row('WhatsApp', $val($s['whatsapp'])) ?>
      <?= $row('Email', $val($s['email'])) ?>
      <?= $row('Emergency contact', $s['emergency_contact_name'] ? e($s['emergency_contact_name']) . ($s['emergency_contact_phone'] ? ' · ' . e($s['emergency_contact_phone']) : '') : $dash) ?>
      <?= $row('Address', $val($address)) ?>
      <?= $row('Permanent address', $val($s['permanent_address'])) ?>
    </tbody></table>
  </div>
</div>

<div style="break-inside:avoid">
  <?= $section('Parents & guardians') ?>
  <?php if ($parents): ?>
    <table class="w-full border-collapse"><thead><tr><?= $th('Relation') . $th('Name') . $th('Phone') . $th('Email') . $th('Occupation') . $th('Annual income', 'right') ?></tr></thead><tbody>
      <?php foreach ($parents as $p): ?>
        <tr>
          <?= $td(e($p['relation'] === 'guardian' && $s['guardian_relation'] ? $s['guardian_relation'] : label_from_key($p['relation'])) . ($p['is_emergency_contact'] ? ' <span class="text-[9px] font-semibold text-red-600">(Emergency)</span>' : '')) ?>
          <?= $td('<strong>' . e($p['name']) . '</strong>') ?>
          <?= $td($val($p['phone'])) ?>
          <?= $td($val($p['email'])) ?>
          <?= $td($val($p['occupation'])) ?>
          <?= $td($p['annual_income'] !== null ? e(money($p['annual_income'])) : $dash, 'right') ?>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p class="text-[11px] text-slate-500">No parent or guardian recorded.</p>
  <?php endif; ?>
</div>

<div class="grid grid-cols-2 gap-6" style="break-inside:avoid">
  <div>
    <?= $section('Academic details') ?>
    <table class="w-full"><tbody>
      <?= $row('Department', $val($s['department_name'])) ?>
      <?= $row('Program', e($s['program_full_name']) . ' (' . e($s['program_name']) . ')') ?>
      <?= $row('Specialization', $val($s['course_name'])) ?>
      <?= $row('Batch', $val($s['batch_name'])) ?>
      <?= $row('Admission session', $val($s['session_name'])) ?>
      <?= $row('Admission date', $s['admission_date'] ? e(format_date($s['admission_date'])) : $dash) ?>
    </tbody></table>
  </div>
  <div>
    <?= $section('Enrolment') ?>
    <table class="w-full"><tbody>
      <?= $row('Admission type', $val(students_admission_types()[$s['admission_type']] ?? $s['admission_type'])) ?>
      <?= $row('University enrollment', $val($s['enrollment_no'])) ?>
      <?= $row('Previous qualification', $s['previous_qualification'] ? e($s['previous_qualification']) . ($s['previous_percentage'] ? ' · ' . e(number_format((float) $s['previous_percentage'], 1)) . '%' : '') : $dash) ?>
      <?= $row('Hostel', $hostel ? e($hostel['hostel_name'] . ' · Room ' . $hostel['room_no'] . ($hostel['bed_no'] ? ' · Bed ' . $hostel['bed_no'] : '')) : ($s['is_hosteller'] ? 'Requested' : 'Day scholar')) ?>
      <?= $row('Transport', $transport ? e($transport['route_code'] . ' — ' . $transport['route_name'] . ($transport['stop_name'] ? ' · ' . $transport['stop_name'] : '')) : ($s['uses_transport'] ? 'Requested' : 'Not opted')) ?>
    </tbody></table>
  </div>
</div>

<?php if ($history): ?>
<div style="break-inside:avoid">
  <?= $section('Academic history') ?>
  <table class="w-full border-collapse"><thead><tr><?= $th('Semester') . $th('Session') . $th('Section') . $th('Attendance', 'right') . $th('SGPA', 'right') . $th('CGPA', 'right') . $th('Status') ?></tr></thead><tbody>
    <?php foreach ($history as $h): ?>
      <tr>
        <?= $td('<strong>Semester ' . (int) $h['semester_no'] . '</strong>') ?>
        <?= $td($val($h['session_name'])) ?>
        <?= $td($val($h['section_name'])) ?>
        <?= $td($h['attendance_percent'] !== null ? e(number_format((float) $h['attendance_percent'], 1)) . '%' : $dash, 'right') ?>
        <?= $td($h['sgpa'] !== null ? e(number_format((float) $h['sgpa'], 2)) : $dash, 'right') ?>
        <?= $td($h['cgpa'] !== null ? '<strong>' . e(number_format((float) $h['cgpa'], 2)) . '</strong>' : $dash, 'right') ?>
        <?= $td($pill($h['status'])) ?>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<?php if ($results): ?>
<div style="break-inside:avoid">
  <?= $section('Results') ?>
  <table class="w-full border-collapse"><thead><tr><?= $th('Exam') . $th('Semester') . $th('Percentage', 'right') . $th('SGPA', 'right') . $th('CGPA', 'right') . $th('Result') ?></tr></thead><tbody>
    <?php foreach ($results as $r): ?>
      <tr><?= $td(e($r['exam_name'])) . $td($r['semester_no'] ? 'Sem ' . (int) $r['semester_no'] : $dash) . $td($r['percentage'] !== null ? e($r['percentage']) . '%' : $dash, 'right')
          . $td($r['sgpa'] !== null ? e(number_format((float) $r['sgpa'], 2)) : $dash, 'right') . $td($r['cgpa'] !== null ? e(number_format((float) $r['cgpa'], 2)) : $dash, 'right') . $td($pill($r['result_status'])) ?></tr>
    <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<?php if ($fees): ?>
<div style="break-inside:avoid">
  <?= $section('Fee summary') ?>
  <p class="mb-2 text-[11px] text-slate-600">Total payable <strong><?= e(money($fees['net'])) ?></strong> · Paid <strong class="text-emerald-700"><?= e(money($fees['paid'])) ?></strong> · Balance <strong><?= e(money($fees['balance'])) ?></strong><?= $fees['overdue'] > 0 ? ' · Overdue <strong class="text-red-700">' . e(money($fees['overdue'])) . '</strong>' : '' ?></p>
  <?php if ($invoices): ?>
    <table class="w-full border-collapse"><thead><tr><?= $th('Invoice') . $th('Title') . $th('Net', 'right') . $th('Paid', 'right') . $th('Balance', 'right') . $th('Due') . $th('Status') ?></tr></thead><tbody>
      <?php foreach ($invoices as $i): ?>
        <tr><?= $td('<span class="font-mono">' . e($i['invoice_no']) . '</span>') . $td(e($i['title'])) . $td(e(money($i['net_amount'])), 'right') . $td(e(money($i['paid_amount'])), 'right')
            . $td('<strong>' . e(money($i['balance_amount'])) . '</strong>', 'right') . $td($i['due_date'] ? e(format_date($i['due_date'])) : $dash) . $td($pill($i['status'])) ?></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p class="text-[11px] text-slate-500">No invoices raised yet.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div style="break-inside:avoid">
  <?= $section('Documents') ?>
  <?php if ($docs): ?>
    <table class="w-full border-collapse"><thead><tr><?= $th('Document') . $th('Type') . $th('Uploaded') . $th('Status') . $th('Remarks') ?></tr></thead><tbody>
      <?php foreach ($docs as $d): ?>
        <tr><?= $td('<strong>' . e($d['title']) . '</strong>') . $td(e(students_document_types()[$d['doc_type']] ?? label_from_key($d['doc_type']))) . $td(e(format_date($d['created_at']))) . $td($pill($d['status'])) . $td($val($d['remarks'])) ?></tr>
      <?php endforeach; ?>
    </tbody></table>
  <?php else: ?>
    <p class="text-[11px] text-slate-500">No documents uploaded.</p>
  <?php endif; ?>
</div>

<div class="mt-12 grid grid-cols-3 gap-8 text-center text-[11px] text-slate-600" style="break-inside:avoid">
  <div><div class="border-t border-slate-400 pt-1">Student's signature</div></div>
  <div><div class="border-t border-slate-400 pt-1">Class teacher / HOD</div></div>
  <div><div class="border-t border-slate-400 pt-1">Registrar</div></div>
</div>
<p class="mt-6 text-center text-[9px] text-slate-400">Generated on <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(current_user()['name'] ?? 'Administrator') ?> · <?= e(institute_name()) ?> · This is a computer generated document.</p>
<?php print_layout_end();
