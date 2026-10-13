<?php
/**
 * Fee receipt (A4, student + office copy on one sheet).
 *   /admin/print/fee-receipt.php?id={payment_id}
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/fees.php';
require_permission('fees', 'view');

$id = input_int('id');
$p = $id ? fees_payment_detail($id) : null;
if (!$p) {
    http_response_code(404);
    print_layout_start('Receipt not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Receipt not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The payment does not exist or has been removed.</p>'
        . '<a href="' . e(admin_url('fees/payments')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to payments</a></div>';
    print_layout_end();
    exit;
}

db_exec('UPDATE payment_receipts SET printed_count = printed_count + 1, last_printed_at = NOW() WHERE payment_id = ?', [$id]);
log_activity('print', 'fees', $id, 'Printed fee receipt ' . $p['receipt_no'] . ' (' . ($p['student_name'] ?? '—') . ')');

$total = (float) $p['amount'] + (float) $p['fine_amount'];
$void = $p['status'] === 'cancelled';
$balance = $p['student_id'] ? (float) (fees_student_totals((int) $p['student_id'])['balance'] ?? 0) : 0.0;
$relation = ($p['gender'] ?? '') === 'female' ? 'D/o' : 'S/o';
$verify = absolute_url('admin/fees/payments?q=' . rawurlencode($p['receipt_no']));

print_layout_start('Fee Receipt ' . $p['receipt_no'], false);

$copy = function (string $label) use ($p, $total, $void, $balance, $relation, $verify) {
    ?>
  <section class="relative overflow-hidden rounded-xl border border-slate-300 p-5">
    <?php if ($void): ?>
      <div class="pointer-events-none absolute inset-0 flex items-center justify-center"><span class="-rotate-12 rounded-xl border-4 border-red-500/60 px-6 py-2 font-display text-5xl font-extrabold uppercase tracking-widest text-red-500/50">Void</span></div>
    <?php endif; ?>
    <?= print_letterhead() ?>
    <div class="mt-3 flex items-center justify-between">
      <h1 class="font-display text-base font-extrabold uppercase tracking-wide text-brand-900">Fee Receipt</h1>
      <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600"><?= e($label) ?></span>
    </div>
    <div class="mt-3 grid grid-cols-4 gap-3 text-[11px] text-slate-600">
      <div>Receipt No<br><strong class="font-mono text-[12px] text-slate-900"><?= e($p['receipt_no']) ?></strong></div>
      <div>Payment date<br><strong class="text-[12px] text-slate-900"><?= e(format_date($p['payment_date'])) ?></strong></div>
      <div>Issued<br><strong class="text-[12px] text-slate-900"><?= e(format_datetime($p['issued_at'] ?? $p['created_at'])) ?></strong></div>
      <div class="flex justify-end"><div data-qr="<?= e($verify) ?>" class="h-14 w-14"></div></div>
    </div>
    <table class="mt-3 w-full border-collapse text-[11px]">
      <tbody>
        <tr><th class="w-[24%] border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Received from</th>
          <td class="border border-slate-200 px-2 py-1.5 font-semibold text-slate-900"><?= e($p['student_name'] ?? '—') ?><?= !empty($p['father_name']) ? ' <span class="font-normal text-slate-500">(' . $relation . ' ' . e($p['father_name']) . ')</span>' : '' ?></td>
          <th class="w-[16%] border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Student ID</th>
          <td class="border border-slate-200 px-2 py-1.5 font-mono"><?= e($p['student_uid'] ?? '—') ?></td></tr>
        <tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Program</th>
          <td class="border border-slate-200 px-2 py-1.5"><?= e(trim(($p['program_short'] ?? '') . ' — ' . ($p['program_name'] ?? ''), ' —')) ?><?= $p['current_semester'] ? ' · Semester ' . (int) $p['current_semester'] : '' ?><?= $p['section_name'] ? ' · Section ' . e($p['section_name']) : '' ?></td>
          <th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Roll No</th>
          <td class="border border-slate-200 px-2 py-1.5"><?= e($p['roll_no'] ?? '—') ?></td></tr>
        <tr><th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Payment mode</th>
          <td class="border border-slate-200 px-2 py-1.5"><?= e($p['mode_label']) ?><?= $p['bank_name'] ? ' · ' . e($p['bank_name']) : '' ?><?= $p['gateway'] ? ' · ' . e(label_from_key($p['gateway'])) : '' ?></td>
          <th class="border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600">Reference</th>
          <td class="border border-slate-200 px-2 py-1.5 font-mono"><?= e($p['reference_no'] ?? '—') ?></td></tr>
      </tbody>
    </table>
    <table class="mt-3 w-full border-collapse text-[11px]">
      <thead><tr>
        <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Particulars</th>
        <th class="w-[22%] border border-brand-900 bg-brand-900 px-2 py-1.5 text-right font-semibold text-white">Amount (₹)</th>
      </tr></thead>
      <tbody>
        <?php foreach ($p['allocations'] as $a): $fee = (float) $a['amount'] - (float) $a['fine_amount']; ?>
          <tr>
            <td class="border border-slate-200 px-2 py-1.5">
              <span class="font-semibold text-slate-900"><?= e($a['title']) ?></span> <span class="font-mono text-slate-500">(<?= e($a['invoice_no']) ?>)</span>
              <?php if ($a['items']): ?><br><span class="text-[10px] text-slate-500"><?= e(implode(' · ', array_map(fn ($i) => $i['head_name'], $a['items']))) ?></span><?php endif; ?>
            </td>
            <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums"><?= e(number_in($fee, 2)) ?></td>
          </tr>
          <?php if ((float) $a['fine_amount'] > 0): ?>
            <tr><td class="border border-slate-200 px-2 py-1.5">Late fee — <?= e($a['invoice_no']) ?></td><td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums"><?= e(number_in((float) $a['fine_amount'], 2)) ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
        <tr class="bg-slate-50"><td class="border border-slate-200 px-2 py-1.5 font-bold text-slate-900">Total received</td><td class="border border-slate-200 px-2 py-1.5 text-right font-bold tabular-nums text-slate-900"><?= e(number_in($total, 2)) ?></td></tr>
      </tbody>
    </table>
    <p class="mt-2 text-[11px] text-slate-700">Amount in words: <strong><?= e(fees_amount_in_words($total)) ?></strong></p>
    <?php foreach ($p['discounts'] as $d): ?>
      <p class="mt-1 text-[10px] text-slate-500">Concession of <?= e(money((float) $d['amount'], 2)) ?> allowed on <?= e($d['invoice_no']) ?> — <?= e($d['reason']) ?></p>
    <?php endforeach; ?>
    <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-[10px] text-slate-500">
      <span>Current outstanding balance (all invoices): <strong class="text-slate-800"><?= e(money($balance, 2)) ?></strong></span>
      <?php if ($p['remarks']): ?><span>Remarks: <?= e($p['remarks']) ?></span><?php endif; ?>
      <?php if ($void): ?><span class="font-semibold text-red-600">Voided <?= e(format_datetime($p['cancelled_at'])) ?> — <?= e($p['cancelled_reason']) ?></span><?php endif; ?>
    </div>
    <div class="mt-6 flex items-end justify-between text-[11px] text-slate-600">
      <p class="max-w-xs text-[10px] text-slate-400"><?= e(setting('receipt_footer_note', 'This is a computer generated receipt and does not require a signature.')) ?> Cheque / DD receipts are subject to realisation.</p>
      <div class="text-right"><div class="w-48 border-t border-slate-400 pt-1">Received by<?= $p['collected_by_name'] ? ': ' . e($p['collected_by_name']) : ($p['mode'] === 'online' ? ': Online payment' : '') ?></div></div>
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
