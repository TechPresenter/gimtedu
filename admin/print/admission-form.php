<?php
/**
 * Printable admission application form (A4, letterhead).
 *   /admin/print/admission-form.php?id={admission_id}
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/admissions.php';
require_login();
require_permission('admissions', 'view');

$id = input_int('id');
$a = $id ? db_row("SELECT a.*, p.short_name AS program_short, p.name AS program_name, p.duration_years, c.name AS course_name, p2.short_name AS second_program, ss.name AS session_name,
                          u.name AS counsellor_name, ap.name AS approved_by_name
                   FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN courses c ON c.id = a.course_id LEFT JOIN programs p2 ON p2.id = a.second_program_id
                   LEFT JOIN academic_sessions ss ON ss.id = a.academic_session_id LEFT JOIN users u ON u.id = a.assigned_to LEFT JOIN users ap ON ap.id = a.approved_by
                   WHERE a.id = ?", [$id]) : null;
if (!$a) {
    http_response_code(404);
    print_layout_start('Application not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Application not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The application does not exist or has been deleted.</p>'
        . '<a href="' . e(admin_url('admissions/list')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to applications</a></div>';
    print_layout_end();
    exit;
}
$docs = db_all('SELECT doc_type, status, remarks, original_name, file_path, mime FROM admission_documents WHERE admission_id = ? ORDER BY id', [$id]);
$name = admission_full_name($a);
$dash = '—';
$val = fn ($v) => ($v === null || $v === '') ? $dash : e((string) $v);
$row = fn (string $label, string $value) => '<tr><th class="w-[38%] py-1 pr-3 text-left align-top text-[11px] font-medium text-slate-500">' . e($label) . '</th><td class="py-1 align-top text-[12px] font-medium text-slate-800">' . $value . '</td></tr>';
$section = fn (string $no, string $title) => '<h2 class="mb-2 mt-5 flex items-center gap-2 border-b border-slate-200 pb-1 font-display text-[13px] font-bold uppercase tracking-wider text-brand-800">'
    . '<span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-brand-800 text-[10px] text-white">' . e($no) . '</span>' . e($title) . '</h2>';
$th = fn (string $label, string $align = 'left') => '<th class="border-b border-slate-200 bg-slate-50 px-2 py-1.5 text-' . $align . ' text-[10px] font-semibold uppercase tracking-wider text-slate-500">' . e($label) . '</th>';
$td = fn (string $html, string $align = 'left') => '<td class="border-b border-slate-100 px-2 py-1.5 text-' . $align . ' text-[11px] text-slate-700">' . $html . '</td>';
$docColors = ['verified' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'pending' => 'bg-amber-50 text-amber-700 ring-amber-200', 'rejected' => 'bg-red-50 text-red-700 ring-red-200'];
$photoDoc = null;
foreach ($docs as $d) {
    if ($d['doc_type'] === 'photo' && str_starts_with((string) $d['mime'], 'image/') && is_file(APP_ROOT . '/' . $d['file_path'])) {
        $photoDoc = $d;
    }
}
$address = implode(', ', array_filter([$a['address'], $a['city'], $a['state'], $a['pincode'], $a['country']]));
$required = admission_required_docs($a);
$byType = [];
foreach ($docs as $d) {
    $byType[$d['doc_type']] = $d;
}
$quotas = admission_quotas();

log_activity('print', 'admissions', $id, 'Printed application form of ' . $name . ' (' . $a['application_no'] . ')');
print_layout_start('Application Form - ' . $a['application_no']);
?>
<div class="mt-5 flex items-start gap-5">
  <div class="min-w-0 flex-1">
    <p class="text-[10px] font-semibold uppercase tracking-[0.2em] text-accent-700">Application for Admission · Session <?= e($a['session_name'] ?? '') ?></p>
    <h1 class="font-display text-2xl font-extrabold leading-tight text-brand-900"><?= e($name) ?></h1>
    <p class="mt-0.5 text-sm text-slate-700"><?= e($a['program_short'] . ' — ' . $a['program_name']) ?><?= $a['course_name'] ? ' · ' . e($a['course_name']) : '' ?></p>
    <div class="mt-2 flex flex-wrap items-center gap-3 text-[11px] text-slate-600">
      <span class="rounded bg-slate-100 px-2 py-0.5 font-mono font-semibold text-slate-800"><?= e($a['application_no']) ?></span>
      <span>Applied on <strong><?= e(format_date($a['created_at'])) ?></strong></span>
      <span>Source: <strong><?= e(admission_sources()[$a['source']] ?? label_from_key($a['source'])) ?></strong></span>
      <span class="inline-block rounded-full bg-brand-50 px-2 py-px text-[10px] font-semibold text-brand-800 ring-1 ring-inset ring-brand-200"><?= e(admission_stage_label($a['stage'])) ?></span>
    </div>
  </div>
  <div class="text-center">
    <div data-qr="<?= e(absolute_url('admin/admissions/' . $id)) ?>" class="h-20 w-20"></div>
    <p class="mt-1 text-[9px] text-slate-500">Scan to verify</p>
  </div>
  <?php if ($a['photo']): ?>
    <img src="<?= e(upload_url($a['photo'])) ?>" alt="" class="h-32 w-[100px] rounded-md object-cover ring-1 ring-slate-300">
  <?php elseif ($photoDoc): ?>
    <img src="<?= e(base_url('api/files/download?path=' . rawurlencode($photoDoc['file_path']))) ?>" alt="" class="h-32 w-[100px] rounded-md object-cover ring-1 ring-slate-300">
  <?php else: ?>
    <div class="flex h-32 w-[100px] items-center justify-center rounded-md border border-dashed border-slate-300 text-center text-[10px] text-slate-400">Affix recent<br>passport-size<br>photograph</div>
  <?php endif; ?>
</div>

<?= $section('1', 'Applicant details') ?>
<div class="grid grid-cols-2 gap-x-8">
  <table class="w-full"><tbody>
    <?= $row('Full name', $val($name)) ?>
    <?= $row('Gender', $val($a['gender'] ? label_from_key($a['gender']) : null)) ?>
    <?= $row('Date of birth', $val($a['dob'] ? format_date($a['dob']) : null)) ?>
    <?= $row('Blood group', $val($a['blood_group'])) ?>
  </tbody></table>
  <table class="w-full"><tbody>
    <?= $row('Nationality', $val($a['nationality'])) ?>
    <?= $row('Aadhaar no.', $val($a['aadhaar_no'] ? 'XXXX XXXX ' . substr((string) $a['aadhaar_no'], -4) : null)) ?>
    <?= $row('Category', $val($a['category'])) ?>
    <?= $row('Admission quota', $val($quotas[$a['quota']] ?? $a['quota'])) ?>
  </tbody></table>
</div>

<?= $section('2', 'Contact details') ?>
<div class="grid grid-cols-2 gap-x-8">
  <table class="w-full"><tbody>
    <?= $row('Mobile', $val($a['phone'])) ?>
    <?= $row('WhatsApp', $val($a['whatsapp'])) ?>
    <?= $row('Email', $val($a['email'])) ?>
  </tbody></table>
  <table class="w-full"><tbody>
    <?= $row('Address', $val($address)) ?>
  </tbody></table>
</div>

<?= $section('3', 'Parents / guardian') ?>
<table class="w-full border-collapse">
  <thead><tr><?= $th('Relation') ?><?= $th('Name') ?><?= $th('Mobile') ?><?= $th('Occupation') ?></tr></thead>
  <tbody>
    <tr><?= $td('Father') ?><?= $td($val($a['father_name'])) ?><?= $td($val($a['father_phone'])) ?><?= $td($val($a['father_occupation'])) ?></tr>
    <tr><?= $td('Mother') ?><?= $td($val($a['mother_name'])) ?><?= $td($val($a['mother_phone'])) ?><?= $td($val($a['mother_occupation'])) ?></tr>
    <?php if ($a['guardian_name']): ?>
      <tr><?= $td('Guardian' . ($a['guardian_relation'] ? ' (' . e($a['guardian_relation']) . ')' : '')) ?><?= $td($val($a['guardian_name'])) ?><?= $td($val($a['guardian_phone'])) ?><?= $td($dash) ?></tr>
    <?php endif; ?>
  </tbody>
</table>
<p class="mt-1 text-[11px] text-slate-500">Annual family income: <strong class="text-slate-700"><?= $a['family_income'] ? e(money((float) $a['family_income'])) : $dash ?></strong></p>

<?= $section('4', 'Previous education') ?>
<table class="w-full border-collapse">
  <thead><tr><?= $th('Examination') ?><?= $th('Board / University') ?><?= $th('School / College') ?><?= $th('Year', 'center') ?><?= $th('Marks %', 'right') ?></tr></thead>
  <tbody>
    <tr><?= $td('Class 10') ?><?= $td($val($a['tenth_board'])) ?><?= $td($dash) ?><?= $td($val($a['tenth_year']), 'center') ?><?= $td($a['tenth_percentage'] !== null ? e(number_format((float) $a['tenth_percentage'], 2)) : $dash, 'right') ?></tr>
    <tr><?= $td(e($a['previous_qualification'] ?: 'Qualifying exam')) ?><?= $td($val($a['previous_board'])) ?><?= $td($val($a['previous_institution'])) ?><?= $td($val($a['passing_year']), 'center') ?><?= $td($a['previous_percentage'] !== null ? e(number_format((float) $a['previous_percentage'], 2)) : $dash, 'right') ?></tr>
  </tbody>
</table>
<?php if ($a['entrance_exam']): ?>
  <p class="mt-1 text-[11px] text-slate-500">Entrance exam: <strong class="text-slate-700"><?= e($a['entrance_exam']) ?></strong><?= $a['entrance_score'] !== null ? ' · Score ' . e($a['entrance_score']) : '' ?></p>
<?php endif; ?>

<?= $section('5', 'Program choice') ?>
<div class="grid grid-cols-2 gap-x-8">
  <table class="w-full"><tbody>
    <?= $row('Program applied for', e($a['program_short'] . ' — ' . $a['program_name'])) ?>
    <?= $row('Specialisation', $val($a['course_name'])) ?>
    <?= $row('Second preference', $val($a['second_program'])) ?>
  </tbody></table>
  <table class="w-full"><tbody>
    <?= $row('Academic session', $val($a['session_name'])) ?>
    <?= $row('Hostel required', $a['hostel_required'] ? 'Yes' : 'No') ?>
    <?= $row('Transport required', $a['transport_required'] ? 'Yes' : 'No') ?>
  </tbody></table>
</div>

<?= $section('6', 'Documents submitted') ?>
<table class="w-full border-collapse">
  <thead><tr><?= $th('Document') ?><?= $th('Required', 'center') ?><?= $th('Status', 'center') ?><?= $th('Remarks') ?></tr></thead>
  <tbody>
    <?php foreach (admission_doc_types() as $type => $label):
        $d = $byType[$type] ?? null;
        if (!$d && !in_array($type, $required, true)) {
            continue;
        } ?>
      <tr>
        <?= $td(e($label)) ?>
        <?= $td(in_array($type, $required, true) ? 'Yes' : 'Optional', 'center') ?>
        <?= $td($d ? '<span class="inline-block rounded-full px-2 py-px text-[10px] font-semibold ring-1 ring-inset ' . ($docColors[$d['status']] ?? '') . '">' . e(label_from_key($d['status'])) . '</span>' : '<span class="text-slate-400">Not submitted</span>', 'center') ?>
        <?= $td($val($d['remarks'] ?? null)) ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?= $section('7', 'Declaration') ?>
<p class="text-[11px] leading-relaxed text-slate-700">
  I declare that the information given in this application is true and complete to the best of my knowledge. I understand that the admission is provisional until all
  documents are verified and the admission fee is paid, and that any false information may lead to cancellation of the admission. I shall abide by the rules and
  regulations of <?= e(institute_name()) ?>, including the anti-ragging regulations.
  <?php if ($a['declaration_accepted']): ?><br><span class="text-[10px] text-emerald-700">Accepted electronically on <?= e(format_datetime($a['declaration_at'])) ?></span><?php endif; ?>
</p>
<div class="mt-10 grid grid-cols-3 gap-8 text-center text-[11px] text-slate-600">
  <div><div class="border-t border-slate-400 pt-1">Signature of applicant</div></div>
  <div><div class="border-t border-slate-400 pt-1">Signature of parent / guardian</div></div>
  <div><div class="border-t border-slate-400 pt-1">Admission officer</div></div>
</div>

<div class="mt-6 rounded-lg border border-slate-200 bg-slate-50 p-3">
  <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-500">For office use</p>
  <div class="grid grid-cols-4 gap-3 text-[11px] text-slate-700">
    <div>Stage<br><strong><?= e(admission_stage_label($a['stage'])) ?></strong></div>
    <div>Counsellor<br><strong><?= $val($a['counsellor_name']) ?></strong></div>
    <div>Entrance / Interview<br><strong><?= $a['entrance_score'] !== null ? e($a['entrance_score']) : $dash ?> / <?= $a['interview_score'] !== null ? e($a['interview_score']) : $dash ?></strong></div>
    <div>Offer letter<br><strong class="font-mono"><?= $val($a['offer_letter_no']) ?></strong></div>
  </div>
</div>
<p class="mt-4 text-center text-[10px] text-slate-400">Printed on <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> · <?= e(institute_name()) ?></p>
<?php print_layout_end();
