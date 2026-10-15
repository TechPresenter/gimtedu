<?php
/** Donation receipts: search, financial-year filter, resend, cancel with reason / reinstate, CSV export (e.g. for Form 10BD). */
require_once __DIR__ . '/includes/auth.php';
require_permission('receipts.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('receipts.php');
    $back = admin_back('receipts.php');
    $rid = (int) ($_POST['receipt_id'] ?? 0);
    $r = db_row('SELECT r.*, d.donation_ref, d.donor_email, d.status AS donation_status FROM donation_receipts r JOIN donations d ON d.id = r.donation_id WHERE r.id = ?', [$rid]);
    $action = (string) ($_POST['action'] ?? '');
    if (!$r) {
        flash('error', 'Receipt not found.');
        admin_redirect($back);
    }
    if ($action === 'resend') {
        if ($r['status'] !== 'issued' || $r['donation_status'] !== 'success') {
            flash('error', 'Only issued receipts of verified donations can be sent.');
        } else {
            $sent = send_receipt_email((int) $r['donation_id']);
            admin_log('receipt_resent', 'Receipt ' . $r['receipt_no'] . ($sent ? '' : ' (email failed)'));
            flash($sent ? 'success' : 'warning', $sent ? 'Receipt ' . $r['receipt_no'] . ' emailed to ' . $r['donor_email'] . '.' : 'The receipt email could not be sent. Check the email settings (SMTP).');
        }
    } elseif ($action === 'cancel') {
        $reason = mb_substr(clean_string($_POST['reason'] ?? ''), 0, 300);
        if ($r['status'] !== 'issued') {
            flash('info', 'This receipt is already cancelled.');
        } elseif (mb_strlen($reason) < 5) {
            flash('error', 'Give a reason for cancelling the receipt.');
        } else {
            cancel_receipt((int) $r['donation_id'], $reason);
            admin_log('receipt_cancelled', 'Receipt ' . $r['receipt_no'] . ': ' . $reason);
            flash('success', 'Receipt ' . $r['receipt_no'] . ' cancelled. The donation itself is unchanged.');
        }
    } elseif ($action === 'reinstate') {
        if ($r['status'] !== 'cancelled' || $r['donation_status'] !== 'success') {
            flash('error', 'Only cancelled receipts of verified (not refunded) donations can be reinstated.');
        } else {
            db_query("UPDATE donation_receipts SET status = 'issued' WHERE id = ?", [$rid]);
            db_query("UPDATE donations SET admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), ?)) WHERE id = ?", ["\n[" . date('d M Y H:i') . '] Receipt reinstated by ' . current_admin()['name'], $r['donation_id']]);
            admin_log('receipt_reinstated', 'Receipt ' . $r['receipt_no']);
            flash('success', 'Receipt ' . $r['receipt_no'] . ' reinstated.');
        }
    }
    admin_redirect($back);
}

$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$fy = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['fy'] ?? '')) ? (string) $_GET['fy'] : '';
$status = in_array($_GET['status'] ?? '', ['issued', 'cancelled'], true) ? (string) $_GET['status'] : '';
$params = [];
$where = 'WHERE 1=1' . search_where(['r.receipt_no', 'd.donation_ref', 'd.donor_name', 'd.donor_email', 'd.donor_pan', 'd.offline_reference'], $q, $params);
if ($fy !== '') {
    $where .= ' AND r.financial_year = ?';
    $params[] = $fy;
}
if ($status !== '') {
    $where .= ' AND r.status = ?';
    $params[] = $status;
}
if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT r.receipt_no, r.financial_year, r.issued_at, r.status AS receipt_status, d.donation_ref, d.paid_at, d.donor_name, d.donor_email, d.donor_pan, d.donor_address,
                            d.amount, d.payment_method, d.gateway_payment_id, d.offline_reference, c.title AS campaign
                     FROM donation_receipts r JOIN donations d ON d.id = r.donation_id LEFT JOIN campaigns c ON c.id = d.campaign_id $where ORDER BY r.id DESC", $params);
    admin_log('receipts_exported', count($rows) . ' rows');
    send_csv('receipts-' . date('Ymd-His') . '.csv', ['Receipt No', 'FY', 'Issued', 'Status', 'Donation Ref', 'Paid on', 'Donor', 'Email', 'PAN', 'Address', 'Amount', 'Method', 'Payment ID', 'Transaction / cheque ref', 'Campaign'], $rows);
}
$totals = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN r.status = 'issued' THEN d.amount END), 0) AS issued_total, SUM(r.status = 'issued' AND r.emailed_at IS NULL) AS not_emailed
                  FROM donation_receipts r JOIN donations d ON d.id = r.donation_id $where", $params);
$p = paginate((int) $totals['n'], 25);
$rows = db_rows("SELECT r.*, d.donation_ref, d.donor_name, d.donor_email, d.donor_pan, d.amount, d.status AS donation_status, d.payment_method
                 FROM donation_receipts r JOIN donations d ON d.id = r.donation_id $where ORDER BY r.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$years = db_rows('SELECT DISTINCT financial_year FROM donation_receipts ORDER BY financial_year DESC');
$fyFilter = '<select name="fy" aria-label="Financial year"><option value="">All financial years</option>';
foreach ($years as $y) {
    $fyFilter .= '<option value="' . e($y['financial_year']) . '"' . ($fy === $y['financial_year'] ? ' selected' : '') . '>FY ' . e($y['financial_year']) . '</option>';
}
$fyFilter .= '</select>';
$actions = admin_can('exports.run') ? [['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1, 'page' => null])), 'icon' => 'download', 'style' => 'ghost']] : [];
admin_page_start('Receipts', $actions);
?>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle"><?= icon('file-text') ?></span><div><strong><?= number_format((int) $totals['n']) ?></strong><small>Receipts in this view</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('rupee') ?></span><div><strong><?= e(money($totals['issued_total'], true)) ?></strong><small>Value of issued receipts</small></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('mail') ?></span><div><strong><?= (int) $totals['not_emailed'] ?></strong><small>Issued but not emailed</small></div></div>
</div>
<?php admin_filter_bar(['issued', 'cancelled'], ['html' => $fyFilter, 'active' => $fy !== '', 'placeholder' => 'Receipt no, donation ref, donor, email or PAN']); ?>
<section class="panel">
    <div class="table-meta"><span>Receipts are created automatically when a donation is verified (online) or recorded (offline).</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Receipt no.</th><th>Issued</th><th>Donor</th><th>PAN</th><th>Amount</th><th>Status</th><th>Emailed</th><th class="actions-cell">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><strong><?= e($r['receipt_no']) ?></strong><br><small class="muted">FY <?= e($r['financial_year']) ?></small></td>
                    <td class="nowrap"><?= e(format_date($r['issued_at'])) ?></td>
                    <td><?= admin_can('donations.view') ? '<a href="' . e(admin_url('donation-view.php?id=' . (int) $r['donation_id'])) . '">' . e($r['donor_name']) . '</a>' : e($r['donor_name']) ?><br><small class="muted"><?= e($r['donor_email']) ?></small></td>
                    <td><?= e($r['donor_pan'] ? donation_mask_pan($r['donor_pan']) : '-') ?></td>
                    <td class="nowrap"><?= e(money($r['amount'], true)) ?><br><small class="muted"><?= e(DONATION_METHODS[$r['payment_method']] ?? $r['payment_method']) ?></small></td>
                    <td><?= status_badge($r['status']) ?><?= $r['donation_status'] === 'refunded' ? '<br><small class="muted">Donation refunded</small>' : '' ?></td>
                    <td class="nowrap"><?= $r['emailed_at'] ? e(format_date($r['emailed_at'])) . ' <small class="muted">(' . (int) $r['email_count'] . 'x)</small>' : '<span class="muted">No</span>' ?></td>
                    <td class="actions-cell">
                        <a class="icon-btn" href="<?= e(page_url('donation-receipt', ['ref' => $r['donation_ref']])) ?>" target="_blank" rel="noopener" aria-label="View receipt <?= e($r['receipt_no']) ?>" title="View / print"><?= icon('printer') ?></a>
                        <?php if ($r['status'] === 'issued' && $r['donation_status'] === 'success'): ?>
                            <form class="inline-form" method="post"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn" type="submit" aria-label="Email receipt <?= e($r['receipt_no']) ?> again" title="Email again"><?= icon('mail') ?></button></form>
                            <details class="inline-form" style="display:inline-block;position:relative">
                                <summary class="icon-btn danger" aria-label="Cancel receipt <?= e($r['receipt_no']) ?>" title="Cancel receipt" style="list-style:none"><?= icon('x') ?></summary>
                                <form method="post" data-confirm="Cancel receipt <?= e($r['receipt_no']) ?>?" style="position:absolute;right:0;z-index:5;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px;width:240px;box-shadow:var(--shadow);text-align:left;white-space:normal">
                                    <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
                                    <label for="reason-<?= (int) $r['id'] ?>" class="small">Reason for cancelling</label>
                                    <input id="reason-<?= (int) $r['id'] ?>" name="reason" required minlength="5" maxlength="300" style="margin:6px 0">
                                    <button class="btn btn-danger btn-sm" type="submit">Cancel receipt</button>
                                </form>
                            </details>
                        <?php elseif ($r['status'] === 'cancelled' && $r['donation_status'] === 'success'): ?>
                            <form class="inline-form" method="post" data-confirm="Reinstate receipt <?= e($r['receipt_no']) ?>?"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="reinstate"><input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>">
                                <button class="icon-btn" type="submit" aria-label="Reinstate receipt <?= e($r['receipt_no']) ?>" title="Reinstate"><?= icon('refresh') ?></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No receipts yet. They are issued automatically when donations are verified.') ?>
    <?php endif; ?>
</section>
<?php
admin_page_end();
