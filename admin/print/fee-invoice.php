<?php
/**
 * Fee invoice / demand note (A4).
 *   /admin/print/fee-invoice.php?id={student_fee_id}
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/fees.php';
require_permission('fees', 'view');

$id = input_int('id');
$inv = $id ? fees_invoice_detail($id) : null;
if (!$inv) {
    http_response_code(404);
    print_layout_start('Invoice not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Invoice not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The invoice does not exist or has been deleted.</p>'
        . '<a href="' . e(admin_url('fees/invoices')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to invoices</a></div>';
    print_layout_end();
    exit;
}
log_activity('print', 'fees', $id, 'Printed invoice ' . $inv['invoice_no'] . ' (' . $inv['student_name'] . ')');

$gateway = fees_gateway_info();
$stamp = ['paid' => ['Paid', 'border-emerald-500/60 text-emerald-600/60'], 'cancelled' => ['Cancelled', 'border-red-500/60 text-red-500/50'], 'waived' => ['Waived', 'border-violet-500/60 text-violet-600/50']][$inv['status']] ?? null;
$statusClass = ['paid' => 'bg-emerald-50 text-emerald-700', 'overdue' => 'bg-red-50 text-red-700', 'partial' => 'bg-amber-50 text-amber-700', 'cancelled' => 'bg-red-50 text-red-700'][$inv['status']] ?? 'bg-slate-100 text-slate-700';
$cell = 'border border-slate-200 px-2 py-1.5';

print_layout_start('Invoice ' . $inv['invoice_no']);
?>
<div class="relative">
  <?php if ($stamp): ?>
    <div class="pointer-events-none absolute inset-x-0 top-40 flex justify-center"><span class="-rotate-12 rounded-xl border-4 px-8 py-2 font-display text-6xl font-extrabold uppercase tracking-widest <?= $stamp[1] ?>"><?= e($stamp[0]) ?></span></div>
  <?php endif; ?>
  <div class="mt-5 flex items-start justify-between gap-6">
    <div>
      <h1 class="font-display text-xl font-extrabold uppercase tracking-wide text-brand-900">Fee Invoice</h1>
      <p class="text-[11px] text-slate-500">Demand note · Academic session <?= e($inv['session_name'] ?? '—') ?></p>
      <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider <?= $statusClass ?>"><?= e(label_from_key($inv['status'])) ?></span>
    </div>
    <table class="text-[11px]">
      <tr><td class="pr-3 text-slate-500">Invoice No</td><td class="font-mono font-semibold text-slate-900"><?= e($inv['invoice_no']) ?></td></tr>
      <tr><td class="pr-3 text-slate-500">Invoice date</td><td class="font-semibold text-slate-900"><?= e(format_date($inv['created_at'])) ?></td></tr>
      <tr><td class="pr-3 text-slate-500">Due date</td><td class="font-semibold text-slate-900"><?= e(format_date($inv['due_date'])) ?></td></tr>
      <?php if ($inv['structure_name']): ?><tr><td class="pr-3 text-slate-500">Fee structure</td><td class="text-slate-700"><?= e($inv['structure_name']) ?></td></tr><?php endif; ?>
    </table>
  </div>

  <div class="mt-4 grid grid-cols-2 gap-4 text-[11px]">
    <div class="rounded-lg border border-slate-200 p-3">
      <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Billed to</p>
      <p class="mt-1 text-[13px] font-bold text-slate-900"><?= e($inv['student_name']) ?></p>
      <p class="text-slate-600"><?= $inv['father_name'] ? 'Parent / guardian: ' . e($inv['father_name']) . '<br>' : '' ?>Student ID <span class="font-mono"><?= e($inv['student_uid']) ?></span><?= $inv['roll_no'] ? ' · Roll ' . e($inv['roll_no']) : '' ?></p>
      <p class="text-slate-600"><?= e(trim(($inv['program_short'] ?? '') . ' — ' . ($inv['program_name'] ?? ''), ' —')) ?><?= $inv['semester_no'] ? ' · Semester ' . (int) $inv['semester_no'] : '' ?><?= $inv['section_name'] ? ' · Section ' . e($inv['section_name']) : '' ?></p>
      <p class="text-slate-600"><?= e(implode(' · ', array_filter([$inv['mobile'], $inv['email']]))) ?></p>
    </div>
    <div class="rounded-lg border border-slate-200 p-3">
      <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Summary</p>
      <table class="mt-1 w-full">
        <tr><td class="text-slate-600">Net payable</td><td class="text-right font-semibold tabular-nums"><?= e(money((float) $inv['net_amount'], 2)) ?></td></tr>
        <tr><td class="text-slate-600">Received</td><td class="text-right tabular-nums text-emerald-700"><?= e(money((float) $inv['paid_amount'], 2)) ?></td></tr>
        <tr><td class="font-semibold text-slate-900">Balance due</td><td class="text-right text-[13px] font-bold tabular-nums text-slate-900"><?= e(money(max(0, (float) $inv['balance_amount']), 2)) ?></td></tr>
        <?php if ((float) $inv['overdue_amount'] > 0): ?><tr><td class="text-red-600">Overdue now</td><td class="text-right font-semibold tabular-nums text-red-600"><?= e(money((float) $inv['overdue_amount'], 2)) ?></td></tr><?php endif; ?>
      </table>
    </div>
  </div>

  <table class="mt-4 w-full border-collapse text-[11px]">
    <thead><tr>
      <th class="w-10 border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">#</th>
      <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Fee head</th>
      <th class="w-[22%] border border-brand-900 bg-brand-900 px-2 py-1.5 text-right font-semibold text-white">Amount (₹)</th>
    </tr></thead>
    <tbody>
      <?php foreach ($inv['items'] as $i => $it): ?>
        <tr><td class="<?= $cell ?>"><?= $i + 1 ?></td><td class="<?= $cell ?>"><?= e($it['head_name']) ?> <span class="text-slate-400">(<?= e($it['head_code']) ?>)</span></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $it['amount'], 2)) ?></td></tr>
      <?php endforeach; ?>
      <tr class="bg-slate-50"><td class="<?= $cell ?>"></td><td class="<?= $cell ?> font-semibold">Gross amount</td><td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e(number_in((float) $inv['gross_amount'], 2)) ?></td></tr>
      <?php if ((float) $inv['scholarship_amount'] > 0): ?><tr><td class="<?= $cell ?>"></td><td class="<?= $cell ?>">Less: scholarship</td><td class="<?= $cell ?> text-right tabular-nums">− <?= e(number_in((float) $inv['scholarship_amount'], 2)) ?></td></tr><?php endif; ?>
      <?php if ((float) $inv['discount_amount'] > 0): ?><tr><td class="<?= $cell ?>"></td><td class="<?= $cell ?>">Less: discount / waiver</td><td class="<?= $cell ?> text-right tabular-nums">− <?= e(number_in((float) $inv['discount_amount'], 2)) ?></td></tr><?php endif; ?>
      <?php if ((float) $inv['fine_amount'] > 0): ?><tr><td class="<?= $cell ?>"></td><td class="<?= $cell ?>">Add: late fee<?= $inv['late_fee_upto'] ? ' (charged up to ' . e(format_date($inv['late_fee_upto'])) . ')' : '' ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $inv['fine_amount'], 2)) ?></td></tr><?php endif; ?>
      <tr class="bg-slate-50"><td class="<?= $cell ?>"></td><td class="<?= $cell ?> font-bold text-slate-900">Net payable</td><td class="<?= $cell ?> text-right font-bold tabular-nums text-slate-900"><?= e(number_in((float) $inv['net_amount'], 2)) ?></td></tr>
    </tbody>
  </table>
  <p class="mt-2 text-[11px] text-slate-700">Amount in words: <strong><?= e(fees_amount_in_words((float) $inv['net_amount'])) ?></strong></p>

  <?php if ($inv['concessions']): ?>
    <p class="mt-2 text-[10px] text-slate-500">Concessions: <?= e(implode('; ', array_map(fn ($c) => ($c['scholarship_name'] ?? label_from_key($c['type'])) . ' ' . money((float) $c['amount']) . ($c['status'] !== 'approved' ? ' (' . $c['status'] . ')' : ''), $inv['concessions']))) ?></p>
  <?php endif; ?>

  <?php if ($inv['installments']): ?>
    <h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Installment schedule</h2>
    <table class="mt-2 w-full border-collapse text-[11px]">
      <thead><tr class="bg-slate-50 text-slate-600">
        <th class="<?= $cell ?> text-left font-semibold">Installment</th><th class="<?= $cell ?> text-left font-semibold">Due date</th>
        <th class="<?= $cell ?> text-right font-semibold">Amount</th><th class="<?= $cell ?> text-right font-semibold">Paid</th><th class="<?= $cell ?> text-right font-semibold">Balance</th><th class="<?= $cell ?> text-left font-semibold">Status</th>
      </tr></thead>
      <tbody>
        <?php foreach ($inv['installments'] as $i): ?>
          <tr>
            <td class="<?= $cell ?>">Installment <?= (int) $i['installment_no'] ?></td><td class="<?= $cell ?>"><?= e(format_date($i['due_date'])) ?></td>
            <td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $i['amount'], 2)) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $i['paid_amount'], 2)) ?></td>
            <td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in(max(0, (float) $i['amount'] - (float) $i['paid_amount']), 2)) ?></td><td class="<?= $cell ?> <?= $i['status'] === 'overdue' ? 'font-semibold text-red-600' : '' ?>"><?= e(label_from_key($i['status'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <?php if ($inv['payments']): ?>
    <h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Payments received</h2>
    <table class="mt-2 w-full border-collapse text-[11px]">
      <thead><tr class="bg-slate-50 text-slate-600">
        <th class="<?= $cell ?> text-left font-semibold">Receipt</th><th class="<?= $cell ?> text-left font-semibold">Date</th><th class="<?= $cell ?> text-left font-semibold">Mode / reference</th><th class="<?= $cell ?> text-right font-semibold">Amount</th>
      </tr></thead>
      <tbody>
        <?php foreach ($inv['payments'] as $pm): ?>
          <tr class="<?= $pm['status'] === 'cancelled' ? 'text-slate-400 line-through' : '' ?>">
            <td class="<?= $cell ?> font-mono"><?= e($pm['receipt_no']) ?></td><td class="<?= $cell ?>"><?= e(format_date($pm['payment_date'])) ?></td>
            <td class="<?= $cell ?>"><?= e(fees_mode_label($pm['mode'])) ?><?= $pm['reference_no'] ? ' · ' . e($pm['reference_no']) : '' ?><?= $pm['status'] !== 'success' ? ' (' . e($pm['status'] === 'cancelled' ? 'void' : $pm['status']) . ')' : '' ?></td>
            <td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $pm['applied_amount'], 2)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="mt-6 grid grid-cols-2 gap-6 text-[10.5px] text-slate-600">
    <div class="rounded-lg bg-slate-50 p-3">
      <p class="font-semibold text-slate-800">How to pay</p>
      <ul class="mt-1 list-disc space-y-0.5 pl-4">
        <li>At the accounts office (cash / card / UPI / cheque / DD), Monday–Saturday 9:30 AM – 4:00 PM.</li>
        <li>Cheques / DDs in favour of "<?= e(institute_name()) ?>" — write the invoice number on the reverse.</li>
        <?php if ($gateway['gateway']): ?><li>Online through the <?= e($gateway['label']) ?> payment link shared by the institute.</li><?php endif; ?>
        <li>Late fee: <?= (float) $inv['late_fee_rate']['per_day'] > 0 ? e(money($inv['late_fee_rate']['per_day'])) . ' per day after the due date' . ($inv['late_fee_rate']['max'] ? ' (max ' . e(money($inv['late_fee_rate']['max'])) . ')' : '') : 'as per institute rules' ?>.</li>
      </ul>
    </div>
    <div class="flex flex-col items-end justify-between">
      <div data-qr="<?= e(absolute_url('admin/fees/invoices?q=' . rawurlencode($inv['invoice_no']))) ?>" class="h-16 w-16"></div>
      <div class="w-48 border-t border-slate-400 pt-1 text-right">Accounts Officer</div>
    </div>
  </div>
  <?php if ($inv['status'] === 'cancelled'): ?>
    <p class="mt-3 text-[10.5px] font-semibold text-red-600">Cancelled on <?= e(format_datetime($inv['cancelled_at'])) ?><?= $inv['cancelled_by_name'] ? ' by ' . e($inv['cancelled_by_name']) : '' ?>: <?= e($inv['cancelled_reason']) ?></p>
  <?php endif; ?>
  <p class="mt-4 text-center text-[9.5px] text-slate-400">This is a computer generated invoice. Fees once paid are refundable only as per the institute refund policy.</p>
</div>
<?php print_layout_end();
