<?php
/**
 * Student fee statement of account (A4): every invoice, concession, late fee and receipt with a running balance.
 *   /admin/print/fee-statement.php?student_id={id}[&session_id={id}]
 */
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_once APP_ROOT . '/app/services/fees.php';
require_permission('fees', 'view');

$studentId = input_int('student_id');
$sessionId = input_int('session_id') ?: null;
$s = $studentId ? db_row("SELECT s.*, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS name, p.short_name AS program_short, p.name AS program_name, sec.name AS section_name
                          FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN sections sec ON sec.id = s.section_id WHERE s.id = ?", [$studentId]) : null;
if (!$s) {
    http_response_code(404);
    print_layout_start('Student not found');
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">Student not found</p>'
        . '<p class="mt-2 text-sm text-slate-500">Open the statement from an invoice or the fee collection screen.</p>'
        . '<a href="' . e(admin_url('fees/invoices')) . '" class="btn btn-primary btn-sm mt-5 no-print">Back to invoices</a></div>';
    print_layout_end();
    exit;
}
$sessionName = $sessionId ? db_value('SELECT name FROM academic_sessions WHERE id = ?', [$sessionId]) : null;
$sesSql = $sessionId ? ' AND sf.academic_session_id = ?' : '';
$args = $sessionId ? [$studentId, $sessionId] : [$studentId];

$invoices = db_all("SELECT sf.*, ses.name AS session_name FROM student_fees sf LEFT JOIN academic_sessions ses ON ses.id = sf.academic_session_id
                    WHERE sf.student_id = ?$sesSql ORDER BY sf.created_at, sf.id", $args);
$open = array_filter($invoices, fn ($i) => $i['status'] !== 'cancelled');
$openIds = array_map(fn ($i) => (int) $i['id'], $open);
$inList = $openIds ? implode(',', $openIds) : '0';

// Ledger entries: debit = charged to the student, credit = concessions and money received.
$entries = [];
foreach ($open as $i) {
    $entries[] = ['date' => substr($i['created_at'], 0, 10), 'sort' => $i['created_at'] . '-0', 'ref' => $i['invoice_no'], 'text' => 'Invoice raised — ' . $i['title'], 'debit' => (float) $i['gross_amount'], 'credit' => 0.0];
    if ((float) $i['fine_amount'] > 0) {
        $d = $i['late_fee_upto'] ?: substr($i['created_at'], 0, 10);
        $entries[] = ['date' => $d, 'sort' => $d . ' 00:00:00-1', 'ref' => $i['invoice_no'], 'text' => 'Late fee charged', 'debit' => (float) $i['fine_amount'], 'credit' => 0.0];
    }
}
foreach (db_all("SELECT fc.*, sf.invoice_no, sc.name AS scholarship_name FROM fee_concessions fc JOIN student_fees sf ON sf.id = fc.student_fee_id LEFT JOIN scholarships sc ON sc.id = fc.scholarship_id
                 WHERE fc.status = 'approved' AND fc.student_fee_id IN ($inList) ORDER BY fc.created_at") as $c) {
    $entries[] = ['date' => substr($c['created_at'], 0, 10), 'sort' => $c['created_at'] . '-2', 'ref' => $c['invoice_no'], 'text' => ($c['scholarship_name'] ?: label_from_key($c['type'])) . ($c['reason'] && !$c['scholarship_name'] ? ' — ' . $c['reason'] : ''), 'debit' => 0.0, 'credit' => (float) $c['amount']];
}
foreach (db_all("SELECT p.receipt_no, p.payment_date, p.mode, p.reference_no, p.created_at, COALESCE(pa.amount, p.amount + p.fine_amount) AS amt, sf.invoice_no
                 FROM payments p LEFT JOIN payment_allocations pa ON pa.payment_id = p.id
                 JOIN student_fees sf ON sf.id = COALESCE(pa.student_fee_id, p.student_fee_id)
                 WHERE p.status IN ('success', 'refunded') AND sf.id IN ($inList)
                   AND (pa.id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM payment_allocations x WHERE x.payment_id = p.id))
                 ORDER BY p.payment_date, p.id") as $p) {
    $entries[] = ['date' => $p['payment_date'], 'sort' => $p['payment_date'] . ' ' . substr($p['created_at'], 11) . '-3', 'ref' => $p['receipt_no'], 'text' => 'Payment received (' . fees_mode_label($p['mode']) . ($p['reference_no'] ? ' · ' . $p['reference_no'] : '') . ') — ' . $p['invoice_no'], 'debit' => 0.0, 'credit' => (float) $p['amt']];
}
usort($entries, fn ($a, $b) => strcmp($a['sort'], $b['sort']));
$refunds = db_all("SELECT r.refund_no, r.amount, r.status, r.refund_date, r.reason, p.receipt_no FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE r.student_id = ? ORDER BY r.created_at", [$studentId]);
$totals = ['billed' => 0.0, 'fine' => 0.0, 'concession' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue' => 0.0];
foreach ($open as $i) {
    $totals['billed'] += (float) $i['gross_amount'];
    $totals['fine'] += (float) $i['fine_amount'];
    $totals['concession'] += (float) $i['discount_amount'] + (float) $i['scholarship_amount'];
    $totals['paid'] += (float) $i['paid_amount'];
    $totals['balance'] += (float) $i['balance_amount'];
}
$totals['overdue'] = (float) (fees_student_totals($studentId, $sessionId)['overdue'] ?? 0);

log_activity('print', 'fees', $studentId, 'Printed fee statement of ' . $s['name'] . ' (' . $s['student_uid'] . ')' . ($sessionName ? ' for ' . $sessionName : ''));
$cell = 'border border-slate-200 px-2 py-1.5';
print_layout_start('Fee Statement — ' . $s['name']);
?>
<div class="mt-5 flex items-start justify-between gap-6">
  <div>
    <h1 class="font-display text-xl font-extrabold uppercase tracking-wide text-brand-900">Statement of Fee Account</h1>
    <p class="text-[11px] text-slate-500"><?= $sessionName ? 'Academic session ' . e($sessionName) : 'All sessions' ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p>
  </div>
  <div data-qr="<?= e(absolute_url('admin/fees/collect?student=' . $studentId)) ?>" class="h-16 w-16"></div>
</div>
<div class="mt-3 grid grid-cols-2 gap-4 text-[11px]">
  <div class="rounded-lg border border-slate-200 p-3">
    <p class="text-[13px] font-bold text-slate-900"><?= e($s['name']) ?></p>
    <p class="text-slate-600">Student ID <span class="font-mono"><?= e($s['student_uid']) ?></span><?= $s['admission_no'] ? ' · Adm. No ' . e($s['admission_no']) : '' ?><?= $s['roll_no'] ? ' · Roll ' . e($s['roll_no']) : '' ?></p>
    <p class="text-slate-600"><?= e(trim(($s['program_short'] ?? '') . ' — ' . ($s['program_name'] ?? ''), ' —')) ?> · Semester <?= (int) $s['current_semester'] ?><?= $s['section_name'] ? ' · Section ' . e($s['section_name']) : '' ?></p>
    <p class="text-slate-600"><?= $s['father_name'] ? 'Father: ' . e($s['father_name']) . ' · ' : '' ?><?= e($s['mobile'] ?? '') ?></p>
  </div>
  <div class="grid grid-cols-3 gap-2">
    <?php foreach ([['Billed', $totals['billed'] + $totals['fine'], 'text-slate-900'], ['Concessions', $totals['concession'], 'text-emerald-700'], ['Paid', $totals['paid'], 'text-emerald-700'],
                    ['Balance', $totals['balance'], 'text-slate-900'], ['Overdue', $totals['overdue'], 'text-red-600'], ['Late fee', $totals['fine'], 'text-slate-700']] as [$l, $v, $c]): ?>
      <div class="rounded-lg bg-slate-50 px-2 py-1.5"><p class="text-[9.5px] uppercase tracking-wide text-slate-500"><?= e($l) ?></p><p class="font-bold tabular-nums <?= $c ?>"><?= e(money($v)) ?></p></div>
    <?php endforeach; ?>
  </div>
</div>

<h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Invoices</h2>
<table class="mt-2 w-full border-collapse text-[10.5px]">
  <thead><tr class="bg-brand-900 text-white">
    <th class="border border-brand-900 px-2 py-1.5 text-left font-semibold">Invoice</th><th class="border border-brand-900 px-2 py-1.5 text-left font-semibold">Particulars</th>
    <th class="border border-brand-900 px-2 py-1.5 text-left font-semibold">Due</th><th class="border border-brand-900 px-2 py-1.5 text-right font-semibold">Net</th>
    <th class="border border-brand-900 px-2 py-1.5 text-right font-semibold">Paid</th><th class="border border-brand-900 px-2 py-1.5 text-right font-semibold">Balance</th>
    <th class="border border-brand-900 px-2 py-1.5 text-left font-semibold">Status</th>
  </tr></thead>
  <tbody>
    <?php foreach ($invoices as $i): $x = $i['status'] === 'cancelled'; ?>
      <tr class="<?= $x ? 'text-slate-400' : '' ?>">
        <td class="<?= $cell ?> font-mono"><?= e($i['invoice_no']) ?></td><td class="<?= $cell ?>"><?= e($i['title']) ?></td><td class="<?= $cell ?>"><?= e(format_date($i['due_date'])) ?></td>
        <td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $i['net_amount'], 2)) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $i['paid_amount'], 2)) ?></td>
        <td class="<?= $cell ?> text-right tabular-nums"><?= $x ? '—' : e(number_in((float) $i['balance_amount'], 2)) ?></td><td class="<?= $cell ?> <?= $i['status'] === 'overdue' ? 'font-semibold text-red-600' : '' ?>"><?= e(label_from_key($i['status'])) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7" class="<?= $cell ?> py-6 text-center text-slate-500">No invoices have been raised for this student.</td></tr><?php endif; ?>
  </tbody>
</table>

<h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Account ledger</h2>
<table class="mt-2 w-full border-collapse text-[10.5px]">
  <thead><tr class="bg-slate-50 text-slate-600">
    <th class="<?= $cell ?> text-left font-semibold">Date</th><th class="<?= $cell ?> text-left font-semibold">Reference</th><th class="<?= $cell ?> text-left font-semibold">Particulars</th>
    <th class="<?= $cell ?> text-right font-semibold">Debit (₹)</th><th class="<?= $cell ?> text-right font-semibold">Credit (₹)</th><th class="<?= $cell ?> text-right font-semibold">Balance (₹)</th>
  </tr></thead>
  <tbody>
    <?php $run = 0.0; foreach ($entries as $en): $run = round($run + $en['debit'] - $en['credit'], 2); ?>
      <tr>
        <td class="<?= $cell ?> whitespace-nowrap"><?= e(format_date($en['date'])) ?></td><td class="<?= $cell ?> font-mono whitespace-nowrap"><?= e($en['ref']) ?></td><td class="<?= $cell ?>"><?= e($en['text']) ?></td>
        <td class="<?= $cell ?> text-right tabular-nums"><?= $en['debit'] ? e(number_in($en['debit'], 2)) : '' ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= $en['credit'] ? e(number_in($en['credit'], 2)) : '' ?></td>
        <td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e(number_in($run, 2)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$entries): ?><tr><td colspan="6" class="<?= $cell ?> py-6 text-center text-slate-500">No transactions.</td></tr><?php endif; ?>
    <tr class="bg-slate-50"><td colspan="5" class="<?= $cell ?> text-right font-bold">Closing balance</td><td class="<?= $cell ?> text-right font-bold tabular-nums"><?= e(number_in($run, 2)) ?></td></tr>
  </tbody>
</table>

<?php if ($refunds): ?>
  <h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Refunds</h2>
  <table class="mt-2 w-full border-collapse text-[10.5px]">
    <tbody>
      <?php foreach ($refunds as $r): ?>
        <tr><td class="<?= $cell ?> font-mono"><?= e($r['refund_no']) ?></td><td class="<?= $cell ?>">Against <?= e($r['receipt_no']) ?> — <?= e($r['reason']) ?></td>
          <td class="<?= $cell ?>"><?= e($r['status'] === 'processed' ? 'Paid ' . format_date($r['refund_date']) : label_from_key($r['status'])) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $r['amount'], 2)) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div class="mt-8 flex items-end justify-between text-[10.5px] text-slate-600">
  <p class="max-w-sm text-[9.5px] text-slate-400">Balance shown is as on <?= e(format_date(date('Y-m-d'))) ?>. Please report discrepancies to the accounts office within 7 days. Cheque / DD credits are subject to realisation.</p>
  <div class="w-48 border-t border-slate-400 pt-1 text-right">Accounts Officer</div>
</div>
<?php print_layout_end();
