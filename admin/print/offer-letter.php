<?php
/**
 * Provisional offer of admission (A4, letterhead). Available once the application is approved (offer letter number issued).
 *   /admin/print/offer-letter.php?id={admission_id}
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/admissions.php';
require_login();
require_permission('admissions', 'view');

$id = input_int('id');
$a = $id ? db_row("SELECT a.*, p.short_name AS program_short, p.name AS program_name, p.duration_years, p.duration_label, p.fee_per_year, p.level, c.name AS course_name,
                          ss.name AS session_name, ss.start_date AS session_start, ap.name AS approved_by_name
                   FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN courses c ON c.id = a.course_id
                   LEFT JOIN academic_sessions ss ON ss.id = a.academic_session_id LEFT JOIN users ap ON ap.id = a.approved_by WHERE a.id = ?", [$id]) : null;

$fail = function (string $title, string $text) use ($id) {
    http_response_code($id && $title !== 'Application not found' ? 200 : 404);
    print_layout_start($title);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . e($title) . '</p>'
        . '<p class="mx-auto mt-2 max-w-md text-sm text-slate-500">' . e($text) . '</p>'
        . '<a href="' . e(admin_url($id ? 'admissions/' . $id : 'admissions/list')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to application</a></div>';
    print_layout_end();
    exit;
};
if (!$a) {
    $fail('Application not found', 'The application does not exist or has been deleted.');
}
if (empty($a['offer_letter_no']) || in_array($a['stage'], ['rejected', 'withdrawn'], true)) {
    $fail('Offer letter not available', 'An offer letter is generated when the application is approved by the admission committee. Current stage: ' . admission_stage_label($a['stage']) . '.');
}

$name = admission_full_name($a);
$issued = $a['offer_issued_at'] ?: $a['approved_at'] ?: date('Y-m-d H:i:s');
$deadline = date('Y-m-d', strtotime($issued . ' +7 days'));
$fee = (float) ($a['admission_fee'] ?: admission_default_fee((int) $a['program_id'], $a['academic_session_id'] ? (int) $a['academic_session_id'] : null));
$salutation = $a['gender'] === 'female' ? 'Ms.' : ($a['gender'] === 'male' ? 'Mr.' : '');
$address = implode(', ', array_filter([$a['address'], $a['city'], $a['state'] . ($a['pincode'] ? ' - ' . $a['pincode'] : '')]));
$duration = $a['duration_label'] ?: (rtrim(rtrim(number_format((float) $a['duration_years'], 1), '0'), '.') . ' year' . ((float) $a['duration_years'] > 1 ? 's' : ''));
$paid = (float) $a['fee_paid'];
$cell = fn (string $label, string $value) => '<tr><th class="w-[42%] border border-slate-200 bg-slate-50 px-3 py-1.5 text-left text-[11px] font-semibold text-slate-600">' . e($label) . '</th>'
    . '<td class="border border-slate-200 px-3 py-1.5 text-[12px] font-medium text-slate-800">' . $value . '</td></tr>';

log_activity('print', 'admissions', $id, 'Printed offer letter ' . $a['offer_letter_no'] . ' for ' . $name);
print_layout_start('Offer Letter - ' . $a['offer_letter_no']);
?>
<div class="mt-5 flex items-start justify-between text-[12px] text-slate-700">
  <div>
    <p>Ref: <strong class="font-mono"><?= e($a['offer_letter_no']) ?></strong></p>
    <p>Application No: <strong class="font-mono"><?= e($a['application_no']) ?></strong></p>
  </div>
  <div class="text-right">
    <p>Date: <strong><?= e(format_date($issued)) ?></strong></p>
    <div data-qr="<?= e(absolute_url('admin/admissions/' . $id)) ?>" class="ml-auto mt-2 h-16 w-16"></div>
  </div>
</div>

<div class="mt-4 text-[12px] leading-relaxed text-slate-700">
  <p>To,</p>
  <p class="font-semibold text-slate-900"><?= e(trim($salutation . ' ' . $name)) ?></p>
  <?php if ($a['father_name']): ?><p><?= e(($a['gender'] === 'female' ? 'D/o ' : 'S/o ') . $a['father_name']) ?></p><?php endif; ?>
  <?php if ($address): ?><p class="max-w-sm"><?= e($address) ?></p><?php endif; ?>
  <p><?= e($a['phone']) ?><?= $a['email'] ? ' · ' . e($a['email']) : '' ?></p>
</div>

<h1 class="mt-6 text-center font-display text-lg font-extrabold uppercase tracking-wide text-brand-900">Provisional Offer of Admission</h1>
<p class="mt-1 text-center text-[12px] font-semibold text-accent-700"><?= e($a['program_short'] . ' — ' . $a['program_name']) ?> · Session <?= e($a['session_name'] ?? '') ?></p>

<div class="mt-5 space-y-3 text-[12px] leading-relaxed text-slate-700">
  <p>Dear <?= e($a['first_name']) ?>,</p>
  <p>
    Congratulations! On the basis of your academic record<?= $a['entrance_score'] !== null || $a['interview_score'] !== null ? ', entrance and interview performance' : '' ?>, the Admission Committee of
    <strong><?= e(institute_name()) ?></strong> is pleased to offer you provisional admission to the program detailed below for the academic session <?= e($a['session_name'] ?? '') ?>.
  </p>
</div>

<table class="mt-4 w-full border-collapse"><tbody>
  <?= $cell('Program', e($a['program_short'] . ' — ' . $a['program_name'])) ?>
  <?= $cell('Specialisation', $a['course_name'] ? e($a['course_name']) : 'To be chosen as per program rules') ?>
  <?= $cell('Duration', e($duration)) ?>
  <?= $cell('Category / quota', e(($a['category'] ?: 'General') . ' / ' . (admission_quotas()[$a['quota']] ?? label_from_key((string) $a['quota'])))) ?>
  <?= $cell('Commencement of classes', e($a['session_start'] ? format_date(max($a['session_start'], date('Y-m-d', strtotime($issued . ' +10 days')))) : 'As per academic calendar')) ?>
  <?= $cell('Admission fee (payable now)', '<strong>' . e(money($fee)) . '</strong> <span class="text-[11px] text-slate-500">(' . e(adm_amount_in_words($fee)) . ')</span>') ?>
  <?php if ($a['fee_per_year']): ?><?= $cell('Tuition fee (per year)', e(money((float) $a['fee_per_year']))) ?><?php endif; ?>
  <?= $cell('Last date to accept the offer', '<strong>' . e(format_date($deadline)) . '</strong>') ?>
  <?php if ($paid > 0): ?><?= $cell('Admission fee received', e(money($paid)) . ($a['fee_receipt_no'] ? ' · Receipt <span class="font-mono">' . e($a['fee_receipt_no']) . '</span>' : '')) ?><?php endif; ?>
</tbody></table>

<div class="mt-5 text-[12px] leading-relaxed text-slate-700">
  <p class="font-semibold text-slate-900">Terms of the offer</p>
  <ol class="mt-1 list-decimal space-y-1 pl-5">
    <li>Confirm your seat by paying the admission fee on or before <?= e(format_date($deadline)) ?>. Unconfirmed offers may be released to waitlisted candidates.</li>
    <li>Present the originals of all documents submitted with the application (marksheets, ID proof, transfer and migration certificates) at the time of reporting.</li>
    <li>The admission is provisional and subject to fulfilment of the eligibility criteria of the affiliating university.</li>
    <li>An undertaking under the anti-ragging regulations must be submitted by the student and the parent at the time of joining.</li>
    <li>Fees once paid are refundable only as per the refund policy of the institute and the applicable regulatory guidelines.</li>
  </ol>
  <p class="mt-3">We look forward to welcoming you to the GIMT family. For any assistance, contact the admission office at <?= e(setting('admission_email', 'admissions@gimt.ac.in')) ?> or <?= e(setting('phone', '+91 9955446477')) ?>.</p>
</div>

<div class="mt-12 flex items-end justify-between text-[12px] text-slate-700">
  <div>
    <p class="text-[11px] text-slate-500">Accepted by the candidate</p>
    <div class="mt-8 w-56 border-t border-slate-400 pt-1 text-[11px]">Signature &amp; date</div>
  </div>
  <div class="text-right">
    <p class="font-semibold text-slate-900">For <?= e(institute_name()) ?></p>
    <div class="mt-8 ml-auto w-56 border-t border-slate-400 pt-1 text-[11px]">Director — Admissions<?= $a['approved_by_name'] ? '<br><span class="text-slate-500">Approved by ' . e($a['approved_by_name']) . '</span>' : '' ?></div>
  </div>
</div>
<p class="mt-6 text-center text-[10px] text-slate-400">This is a computer-generated offer letter. Verify authenticity by scanning the QR code.</p>
<?php print_layout_end();
