<?php
/**
 * Alumni donation receipt (A4) — or pledge acknowledgement for pledged donations; cancelled donations print as VOID.
 *   /admin/print/donation-receipt.php?id={alumni_donations.id}
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/alumni.php';
require_permission('alumni', 'view');

$id = input_int('id');
$d = $id ? db_row(
    "SELECT d.*, a.name AS donor_name, a.email AS donor_email, a.phone AS donor_phone, a.batch_year, a.company, a.designation, a.city, a.country,
            p.short_name AS program_short, p.name AS program_name, u.name AS recorded_by
     FROM alumni_donations d
     JOIN alumni a ON a.id = d.alumni_id
     LEFT JOIN programs p ON p.id = a.program_id
     LEFT JOIN users u ON u.id = d.created_by
     WHERE d.id = ?",
    [$id]
) : null;
if (!$d) {
    http_response_code(404);
    print_layout_start('Donation receipt not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Donation not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">The donation does not exist or has been removed.</p>'
        . '<a href="' . e(admin_url('alumni/donations')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to donations</a></div>';
    print_layout_end();
    exit;
}

$pledged = $d['status'] === 'pledged';
$void = $d['status'] === 'cancelled';
$title = $pledged ? 'Pledge Acknowledgement' : ($void ? 'Donation Receipt (Cancelled)' : 'Donation Receipt');
$amount = (float) $d['amount'];
// QR payload: the receipt facts the alumni office checks against the ERP record (no admin link on a document given to donors).
$verify = implode('|', ['GIMT ' . strtoupper($pledged ? 'Pledge acknowledgement' : 'Donation receipt'), $d['receipt_no'], strtoupper((string) $d['status']),
    'INR ' . number_format($amount, 2, '.', ''), $d['donation_date'], $d['donor_name']]);
$fy = alumni_fy_label(alumni_fy_start_year($d['donation_date']));
$mode = alumni_payment_modes()[$d['payment_mode']] ?? label_from_key((string) $d['payment_mode']);
log_activity('print', 'alumni', $id, 'Printed ' . strtolower($title) . ' ' . $d['receipt_no'] . ' (' . $d['donor_name'] . ')');

print_layout_start($title . ' ' . $d['receipt_no']);
?>
<section class="relative mt-6">
  <?php if ($void): ?>
    <div class="pointer-events-none absolute inset-0 flex items-center justify-center"><span class="-rotate-12 rounded-xl border-4 border-red-500/60 px-6 py-2 font-display text-5xl font-extrabold uppercase tracking-widest text-red-500/50">Void</span></div>
  <?php endif; ?>
  <div class="flex items-start justify-between gap-6">
    <div>
      <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-accent-700">Alumni Relations · Development Office</p>
      <h1 class="mt-1 font-display text-2xl font-extrabold text-brand-900"><?= e($title) ?></h1>
      <p class="mt-1 text-xs text-slate-500"><?= e($fy) ?> · <?= $pledged ? 'Pledged on' : 'Received on' ?> <?= e(format_date($d['donation_date'])) ?></p>
    </div>
    <div class="flex items-start gap-4">
      <div class="text-right text-[11px] text-slate-600">
        <p>Receipt No</p>
        <p class="font-mono text-base font-bold text-slate-900"><?= e($d['receipt_no']) ?></p>
        <p class="mt-1">Printed <?= e(format_date(date('Y-m-d'))) ?></p>
      </div>
      <div data-qr="<?= e($verify) ?>" class="h-20 w-20"></div>
    </div>
  </div>

  <table class="mt-6 w-full border-collapse text-[12px]">
    <tbody>
      <tr>
        <th class="w-[22%] border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-600"><?= $pledged ? 'Pledged by' : ($void ? 'Donor' : 'Received with thanks from') ?></th>
        <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-900" colspan="3"><?= e($d['donor_name']) ?>
          <span class="font-normal text-slate-500">— Alumnus, <?= e(trim(($d['program_short'] ? $d['program_short'] . ', ' : '') . 'Batch of ' . $d['batch_year'])) ?></span></td>
      </tr>
      <tr>
        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-600">Email</th>
        <td class="border border-slate-200 px-3 py-2"><?= e($d['donor_email'] ?: '—') ?></td>
        <th class="w-[16%] border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-600">Phone</th>
        <td class="border border-slate-200 px-3 py-2"><?= e($d['donor_phone'] ?: '—') ?></td>
      </tr>
      <tr>
        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-600">Organisation</th>
        <td class="border border-slate-200 px-3 py-2"><?= e(trim(($d['designation'] ? $d['designation'] . ', ' : '') . ($d['company'] ?? ''), ', ') ?: '—') ?></td>
        <th class="border border-slate-200 bg-slate-50 px-3 py-2 text-left font-semibold text-slate-600">PAN</th>
        <td class="border border-slate-200 px-3 py-2 font-mono"><?= e($d['pan_no'] ?: '—') ?></td>
      </tr>
    </tbody>
  </table>

  <table class="mt-5 w-full border-collapse text-[12px]">
    <thead>
      <tr>
        <th class="border border-brand-900 bg-brand-900 px-3 py-2 text-left font-semibold text-white">Purpose / fund</th>
        <th class="border border-brand-900 bg-brand-900 px-3 py-2 text-left font-semibold text-white">Campaign</th>
        <th class="w-[22%] border border-brand-900 bg-brand-900 px-3 py-2 text-right font-semibold text-white">Amount (₹)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td class="border border-slate-200 px-3 py-2.5 font-semibold text-slate-900"><?= e($d['purpose'] ?: 'General Development Fund') ?></td>
        <td class="border border-slate-200 px-3 py-2.5 text-slate-600"><?= e($d['campaign'] ?: '—') ?></td>
        <td class="border border-slate-200 px-3 py-2.5 text-right tabular-nums"><?= e(number_in($amount, 2)) ?></td>
      </tr>
      <tr class="bg-slate-50">
        <td class="border border-slate-200 px-3 py-2 font-bold text-slate-900" colspan="2"><?= $pledged ? 'Total pledged' : ($void ? 'Total (cancelled — not received)' : 'Total received') ?></td>
        <td class="border border-slate-200 px-3 py-2 text-right font-bold tabular-nums text-slate-900"><?= e(money($amount, 2)) ?></td>
      </tr>
    </tbody>
  </table>
  <p class="mt-2 text-[12px] text-slate-700">Amount in words: <strong><?= e(alumni_amount_in_words($amount)) ?></strong></p>

  <div class="mt-4 grid grid-cols-3 gap-3 text-[11px] text-slate-600">
    <div class="rounded-lg bg-slate-50 px-3 py-2">Payment mode<br><strong class="text-[12px] text-slate-900"><?= e($pledged ? 'To be received' : $mode) ?></strong></div>
    <div class="rounded-lg bg-slate-50 px-3 py-2">Reference / UTR / cheque<br><strong class="font-mono text-[12px] text-slate-900"><?= e($d['reference_no'] ?: '—') ?></strong></div>
    <div class="rounded-lg bg-slate-50 px-3 py-2">Status<br><strong class="text-[12px] <?= $void ? 'text-red-600' : ($pledged ? 'text-amber-700' : 'text-emerald-700') ?>"><?= e(alumni_donation_statuses()[$d['status']] ?? label_from_key((string) $d['status'])) ?></strong></div>
  </div>
  <?php if ($d['remarks']): ?><p class="mt-3 text-[11px] text-slate-500">Remarks: <?= e($d['remarks']) ?></p><?php endif; ?>

  <?php if ($void): ?>
  <div class="mt-6 rounded-xl border border-red-500/60 bg-red-50 px-5 py-4">
    <p class="font-display text-sm font-bold text-red-700">This receipt has been cancelled and is not valid.</p>
    <p class="mt-1 text-[11px] leading-relaxed text-slate-600">The contribution was not received (see remarks). The receipt number stays reserved for audit; it must not be used for tax deduction claims.</p>
  </div>
  <?php else: ?>
  <div class="mt-6 rounded-xl border border-accent-200 bg-accent-50 px-5 py-4">
    <p class="font-display text-sm font-bold text-brand-900">Thank you for giving back to <?= e(institute_name()) ?>.</p>
    <p class="mt-1 text-[11px] leading-relaxed text-slate-600">
      <?= $pledged
          ? 'We gratefully acknowledge your pledge. A receipt will be issued once the contribution is received.'
          : 'Your contribution directly supports ' . e(strtolower($d['purpose'] ?: 'the development of the institute')) . ' and helps the next generation of GIMT students learn, grow and succeed.' ?>
    </p>
  </div>
  <?php endif; ?>

  <div class="mt-10 flex items-end justify-between text-[11px] text-slate-600">
    <p class="max-w-sm text-[10px] leading-relaxed text-slate-400">
      <?= e(setting('donation_receipt_note', 'This is a computer generated receipt. Donations may be eligible for deduction under Section 80G of the Income Tax Act, 1961, subject to the institute\'s registration and applicable limits. Cheque / DD receipts are subject to realisation.')) ?>
      The QR code carries the receipt number, status, amount and date for verification by the Alumni Relations Office.
    </p>
    <div class="text-right">
      <div class="w-56 border-t border-slate-400 pt-1">Authorised signatory<br><span class="text-[10px] text-slate-400">Alumni Relations Office<?= $d['recorded_by'] ? ' · recorded by ' . e($d['recorded_by']) : '' ?></span></div>
    </div>
  </div>
</section>
<?php print_layout_end();
