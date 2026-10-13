<?php
/**
 * Admission fee receipt (A4, letterhead) — student and office copies on one sheet.
 *   /admin/print/admission-receipt.php?id={admission_id}
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/admissions.php';
require_login();
if (!can('admissions', 'view') && !can('fees', 'view')) {
    require_permission('admissions', 'view');
}

$id = input_int('id');
$a = $id ? db_row("SELECT a.*, p.short_name AS program_short, p.name AS program_name, c.name AS course_name, ss.name AS session_name, u.name AS collected_by_name,
                          st.student_uid, pay.receipt_no AS payment_receipt_no, sf.invoice_no
                   FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN courses c ON c.id = a.course_id LEFT JOIN academic_sessions ss ON ss.id = a.academic_session_id
                   LEFT JOIN users u ON u.id = a.fee_collected_by LEFT JOIN students st ON st.id = a.student_id LEFT JOIN payments pay ON pay.id = a.payment_id
                   LEFT JOIN student_fees sf ON sf.id = pay.student_fee_id WHERE a.id = ?", [$id]) : null;
if (!$a || (float) $a['fee_paid'] <= 0) {
    http_response_code($a ? 200 : 404);
    print_layout_start($a ? 'No admission fee recorded' : 'Application not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . ($a ? 'No admission fee recorded yet' : 'Application not found') . '</p>'
        . '<p class="mt-2 text-sm text-slate-500">' . ($a ? 'Record the admission fee on the application to generate the receipt.' : 'The application does not exist or has been deleted.') . '</p>'
        . '<a href="' . e(admin_url($a ? 'admissions/' . $id . '?tab=fees' : 'admissions/list')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to application</a></div>';
    print_layout_end();
    exit;
}
$name = admission_full_name($a);
$due = (float) ($a['admission_fee'] ?: $a['fee_paid']);
$paid = (float) $a['fee_paid'];
$balance = max(0, $due - $paid);
$mode = admission_payment_modes()[$a['fee_mode']] ?? label_from_key((string) $a['fee_mode']);

log_activity('print', 'admissions', $id, 'Printed admission fee receipt ' . $a['fee_receipt_no'] . ' for ' . $name);
print_layout_start('Admission Receipt - ' . $a['fee_receipt_no'], false);

$copy = function (string $label) use ($a, $name, $due, $paid, $balance, $mode, $id) {
    ?>
  <section class="rounded-xl border border-slate-300 p-5">
    <?= print_letterhead() ?>
    <div class="mt-3 flex items-center justify-between">
      <h1 class="font-display text-base font-extrabold uppercase tracking-wide text-brand-900">Admission Fee Receipt</h1>
      <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600"><?= e($label) ?></span>
    </div>
    <div class="mt-3 grid grid-cols-3 gap-3 text-[11px] text-slate-600">
      <div>Receipt No<br><strong class="font-mono text-[12px] text-slate-900"><?= e($a['fee_receipt_no']) ?></strong></div>
      <div>Date<br><strong class="text-[12px] text-slate-900"><?= e(format_date($a['fee_paid_on'])) ?></strong></div>
      <div class="flex justify-end"><div data-qr="<?= e(absolute_url('admin/admissions/' . $id)) ?>" class="h-14 w-14"></div></div>
    </div>
    <table class="mt-3 w-full border-collapse text-[11px]">
      <tbody>
        <tr><th class="w-[30%] border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Received from</th><td class="border border-slate-200 px-2 py-1.5 font-semibold text-slate-900"><?= e($name) ?><?= $a['father_name'] ? ' <span class="font-normal text-slate-500">(' . ($a['gender'] === 'female' ? 'D/o ' : 'S/o ') . e($a['father_name']) . ')</span>' : '' ?></td></tr>
        <tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Application No</th><td class="border border-slate-200 px-2 py-1.5 font-mono"><?= e($a['application_no']) ?><?= $a['student_uid'] ? ' · Student ID ' . e($a['student_uid']) : '' ?></td></tr>
        <tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Program</th><td class="border border-slate-200 px-2 py-1.5"><?= e($a['program_short'] . ' — ' . $a['program_name']) ?><?= $a['course_name'] ? ' (' . e($a['course_name']) . ')' : '' ?> · Session <?= e($a['session_name'] ?? '') ?></td></tr>
        <tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Payment mode</th><td class="border border-slate-200 px-2 py-1.5"><?= e($mode) ?><?= $a['fee_reference'] ? ' · Ref <span class="font-mono">' . e($a['fee_reference']) . '</span>' : '' ?></td></tr>
      </tbody>
    </table>
    <table class="mt-3 w-full border-collapse text-[11px]">
      <thead><tr><th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Particulars</th><th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-right font-semibold text-white">Amount (₹)</th></tr></thead>
      <tbody>
        <tr><td class="border border-slate-200 px-2 py-1.5">Admission fee (as per offer <?= e($a['offer_letter_no'] ?? '') ?>)</td><td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums"><?= e(number_format($due, 2)) ?></td></tr>
        <tr><td class="border border-slate-200 px-2 py-1.5 font-semibold">Total received to date</td><td class="border border-slate-200 px-2 py-1.5 text-right font-semibold tabular-nums"><?= e(number_format($paid, 2)) ?></td></tr>
        <tr><td class="border border-slate-200 px-2 py-1.5">Balance payable</td><td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums"><?= e(number_format($balance, 2)) ?></td></tr>
      </tbody>
    </table>
    <p class="mt-2 text-[11px] text-slate-700">Amount in words: <strong><?= e(adm_amount_in_words($paid)) ?></strong></p>
    <?php if ($a['invoice_no']): ?><p class="mt-1 text-[10px] text-slate-500">Posted to student fee account · Invoice <?= e($a['invoice_no']) ?> · Payment <?= e($a['payment_receipt_no']) ?></p><?php endif; ?>
    <div class="mt-6 flex items-end justify-between text-[11px] text-slate-600">
      <p class="max-w-xs text-[10px] text-slate-400"><?= e(setting('receipt_footer_note', 'This is a computer generated receipt and does not require a signature.')) ?></p>
      <div class="text-right"><div class="w-44 border-t border-slate-400 pt-1">Received by<?= $a['collected_by_name'] ? ': ' . e($a['collected_by_name']) : '' ?></div></div>
    </div>
  </section>
    <?php
};
?>
<div class="space-y-6">
  <?php $copy('Student copy'); ?>
  <div class="border-t border-dashed border-slate-300 text-center text-[9px] uppercase tracking-widest text-slate-400">cut here</div>
  <?php $copy('Office copy'); ?>
</div>
<?php print_layout_end();
