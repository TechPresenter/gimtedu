<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('donations');

$q = clean_string($_GET['q'] ?? '');
$fy = (string) ($_GET['fy'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['r.receipt_no', 'd.donation_ref', 'd.donor_name', 'd.donor_email', 'd.donor_pan'], $q, $params);
if (preg_match('/^\d{4}-\d{2}$/', $fy)) {
    $where .= ' AND r.financial_year = ?';
    $params[] = $fy;
}
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_rows("SELECT r.receipt_no, r.financial_year, r.issued_at, r.status, d.donation_ref, d.donor_name, d.donor_email, d.donor_pan, d.donor_address, d.amount, d.payment_method, d.gateway_payment_id
                     FROM donation_receipts r JOIN donations d ON d.id = r.donation_id $where ORDER BY r.id DESC", $params);
    admin_log('receipts_exported', count($rows) . ' rows');
    send_csv('receipts-' . date('Ymd') . '.csv', ['Receipt No', 'FY', 'Issued', 'Status', 'Donation Ref', 'Donor', 'Email', 'PAN', 'Address', 'Amount', 'Method', 'Payment ID'], $rows);
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM donation_receipts r JOIN donations d ON d.id = r.donation_id $where", $params), 25);
$rows = db_rows("SELECT r.*, d.donation_ref, d.donor_name, d.donor_email, d.donor_pan, d.amount, d.id AS donation_id
                 FROM donation_receipts r JOIN donations d ON d.id = r.donation_id $where ORDER BY r.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$years = db_rows('SELECT DISTINCT financial_year FROM donation_receipts ORDER BY financial_year DESC');
$fyFilter = '<select name="fy" aria-label="Financial year"><option value="">All financial years</option>';
foreach ($years as $y) {
    $fyFilter .= '<option value="' . e($y['financial_year']) . '"' . ($fy === $y['financial_year'] ? ' selected' : '') . '>FY ' . e($y['financial_year']) . '</option>';
}
$fyFilter .= '</select>';

$adminTitle = 'Receipts';
require __DIR__ . '/includes/header.php';
?>
<?php admin_filter_bar([], ['html' => $fyFilter, 'active' => $fy !== '', 'placeholder' => 'Receipt no, donation ref, donor, email or PAN']); ?>
<div class="page-actions">
    <div class="muted small"><?= number_format($p['total']) ?> receipt(s). Receipts are created automatically only when a payment is confirmed.</div>
    <div class="actions"><a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => null]))) ?>"><?= icon('download') ?>Export CSV (for Form 10BD)</a></div>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Receipt no.</th><th>Issued</th><th>Donor</th><th>PAN</th><th>Amount</th><th>Status</th><th>Emailed</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><strong><?= e($r['receipt_no']) ?></strong></td>
                    <td class="nowrap"><?= e(format_date($r['issued_at'])) ?></td>
                    <td><a href="donation-view.php?id=<?= (int) $r['donation_id'] ?>"><?= e($r['donor_name']) ?></a><br><small class="muted"><?= e($r['donor_email']) ?></small></td>
                    <td><?= e($r['donor_pan'] ?: '-') ?></td>
                    <td class="nowrap"><?= e(money($r['amount'], true)) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= $r['emailed_at'] ? e(format_date($r['emailed_at'])) : '<span class="muted">No</span>' ?></td>
                    <td class="actions-cell">
                        <a class="icon-btn" href="<?= e(url('donation-receipt.php?ref=' . urlencode($r['donation_ref']))) ?>" target="_blank" aria-label="View"><?= icon('printer') ?></a>
                        <?php if ($r['status'] === 'issued'): ?>
                            <form class="inline-form" method="post" action="actions/donation-update.php">
                                <?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int) $r['donation_id'] ?>"><?= return_field() ?>
                                <button class="icon-btn" type="submit" aria-label="Email again" title="Email again"><?= icon('mail') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No receipts yet. They are issued automatically when donations are confirmed.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
