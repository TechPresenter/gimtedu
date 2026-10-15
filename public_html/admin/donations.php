<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('donations');

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$method = (string) ($_GET['method'] ?? '');
$campaignId = (int) ($_GET['campaign'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';

$params = [];
$where = 'WHERE 1=1' . search_where(['d.donation_ref', 'd.donor_name', 'd.donor_email', 'd.donor_phone', 'd.gateway_payment_id', 'd.gateway_order_id'], $q, $params);
if (in_array($status, ['pending', 'success', 'failed', 'refunded'], true)) {
    $where .= ' AND d.status = ?';
    $params[] = $status;
}
if (in_array($method, ['online', 'bank_transfer', 'cheque', 'upi', 'cash'], true)) {
    $where .= ' AND d.payment_method = ?';
    $params[] = $method;
}
if ($campaignId) {
    $where .= ' AND d.campaign_id = ?';
    $params[] = $campaignId;
}
if ($from) {
    $where .= ' AND COALESCE(d.paid_at, d.created_at) >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to) {
    $where .= ' AND COALESCE(d.paid_at, d.created_at) <= ?';
    $params[] = $to . ' 23:59:59';
}

// CSV export of the current filter
if (($_GET['export'] ?? '') === 'csv') {
    $rows = db_rows("SELECT d.donation_ref, COALESCE(d.paid_at, d.created_at) AS date, d.donor_name, d.donor_email, d.donor_phone, d.donor_pan,
                            d.amount, d.currency, d.status, d.payment_method, d.gateway_payment_id, c.title AS campaign, r.receipt_no
                     FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
                     $where ORDER BY d.created_at DESC", $params);
    admin_log('donations_exported', count($rows) . ' rows');
    send_csv('donations-' . date('Ymd-His') . '.csv', ['Reference', 'Date', 'Donor', 'Email', 'Phone', 'PAN', 'Amount', 'Currency', 'Status', 'Method', 'Payment ID', 'Campaign', 'Receipt No'], $rows);
}

$totals = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN d.status = 'success' THEN d.amount END), 0) AS verified FROM donations d $where", $params);
$p = paginate((int) $totals['n'], 25);
$rows = db_rows("SELECT d.*, c.title AS campaign_title, r.receipt_no FROM donations d
                 LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
                 $where ORDER BY d.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$campaigns = db_rows('SELECT id, title FROM campaigns ORDER BY created_at DESC');
$campaignOptions = '<select name="campaign" aria-label="Campaign"><option value="">All campaigns</option>';
foreach ($campaigns as $c) {
    $campaignOptions .= '<option value="' . (int) $c['id'] . '"' . ($campaignId === (int) $c['id'] ? ' selected' : '') . '>' . e(excerpt($c['title'], 40)) . '</option>';
}
$campaignOptions .= '</select>';
$extraFilters = $campaignOptions
    . '<select name="method" aria-label="Method"><option value="">All methods</option>' . options_html(['online' => 'Online', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'upi' => 'UPI (direct)', 'cash' => 'Cash'], $method, true, '') . '</select>'
    . '<input type="date" name="from" value="' . e($from) . '" aria-label="From date"><input type="date" name="to" value="' . e($to) . '" aria-label="To date">';

$adminTitle = 'Donations';
require __DIR__ . '/includes/header.php';
?>
<?php admin_filter_bar(['success', 'pending', 'failed', 'refunded'], ['html' => $extraFilters, 'active' => $method || $campaignId || $from || $to, 'placeholder' => 'Reference, donor, email, phone or payment ID']); ?>
<div class="page-actions">
    <div class="muted small"><?= number_format($p['total']) ?> record(s) · Verified total in this view: <strong><?= e(money($totals['verified'], true)) ?></strong></div>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="?<?= e(http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => null]))) ?>"><?= icon('download') ?>Export CSV</a>
        <a class="btn btn-primary btn-sm" href="#offline"><?= icon('plus') ?>Record offline donation</a>
    </div>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Reference</th><th>Donor</th><th>Towards</th><th>Amount</th><th>Method</th><th>Status</th><th>Receipt</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($d['paid_at'] ?: $d['created_at'], 'd M Y, h:i A')) ?></td>
                    <td><a href="donation-view.php?id=<?= (int) $d['id'] ?>"><strong><?= e($d['donation_ref']) ?></strong></a></td>
                    <td><?= e($d['donor_name']) ?><br><small class="muted"><?= e($d['donor_email']) ?></small></td>
                    <td><?= e(excerpt($d['campaign_title'] ?: 'General Fund', 40)) ?></td>
                    <td class="nowrap"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                    <td><?= e(humanize($d['payment_method'])) ?><?= $d['gateway_method'] ? '<br><small class="muted">' . e($d['gateway_method']) . '</small>' : '' ?></td>
                    <td><?= status_badge($d['status']) ?></td>
                    <td class="nowrap"><?= $d['receipt_no'] ? '<a href="' . e(url('donation-receipt.php?ref=' . urlencode($d['donation_ref']))) . '" target="_blank">' . e($d['receipt_no']) . '</a>' : '<span class="muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No donations match these filters.') ?>
    <?php endif; ?>
</div>

<div class="panel" id="offline">
    <div class="panel-head"><h3>Record an offline donation</h3></div>
    <p class="muted small">Use this only for money that has <strong>actually been received</strong> in the foundation's bank account (bank transfer / cheque cleared / direct UPI) or as cash. A receipt is issued immediately and the amount counts towards verified totals.</p>
    <form method="post" action="actions/donation-update.php" data-validate data-confirm="Confirm that this amount has been received in the foundation's account?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="offline">
        <div class="form-grid form-grid-3">
            <div class="form-group"><label for="o_name">Donor name<span class="req">*</span></label><input id="o_name" type="text" name="donor_name" required maxlength="120" value="<?= e(old('donor_name')) ?>"><?= field_error('donor_name') ?></div>
            <div class="form-group"><label for="o_email">Donor email<span class="req">*</span></label><input id="o_email" type="email" name="donor_email" required maxlength="190" value="<?= e(old('donor_email')) ?>"><?= field_error('donor_email') ?></div>
            <div class="form-group"><label for="o_phone">Mobile</label><input id="o_phone" type="tel" name="donor_phone" maxlength="15" value="<?= e(old('donor_phone')) ?>"><?= field_error('donor_phone') ?></div>
            <div class="form-group"><label for="o_amount">Amount (₹)<span class="req">*</span></label><input id="o_amount" type="number" name="amount" required min="1" max="100000000" step="0.01" value="<?= e(old('amount')) ?>"><?= field_error('amount') ?></div>
            <div class="form-group"><label for="o_method">Method<span class="req">*</span></label><select id="o_method" name="payment_method" required><?= options_html(['bank_transfer' => 'Bank transfer (NEFT/RTGS/IMPS)', 'cheque' => 'Cheque', 'upi' => 'UPI (direct to bank)', 'cash' => 'Cash'], (string) old('payment_method')) ?></select><?= field_error('payment_method') ?></div>
            <div class="form-group"><label for="o_date">Date received<span class="req">*</span></label><input id="o_date" type="date" name="paid_on" required max="<?= date('Y-m-d') ?>" value="<?= e(old('paid_on', date('Y-m-d'))) ?>"><?= field_error('paid_on') ?></div>
            <div class="form-group"><label for="o_ref">Transaction / cheque reference<span class="req">*</span></label><input id="o_ref" type="text" name="reference" required maxlength="100" value="<?= e(old('reference')) ?>"><?= field_error('reference') ?></div>
            <div class="form-group"><label for="o_pan">Donor PAN</label><input id="o_pan" type="text" name="donor_pan" maxlength="10" data-rule="pan" data-uppercase value="<?= e(old('donor_pan')) ?>"><?= field_error('donor_pan') ?></div>
            <div class="form-group"><label for="o_campaign">Campaign</label><select id="o_campaign" name="campaign_id"><option value="">General Fund</option><?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e(excerpt($c['title'], 50)) ?></option><?php endforeach; ?></select></div>
            <div class="form-group full"><label for="o_address">Donor address</label><input id="o_address" type="text" name="donor_address" maxlength="255" value="<?= e(old('donor_address')) ?>"></div>
        </div>
        <label class="checkbox mt-2"><input type="checkbox" name="send_receipt" value="1" checked> <span>Email the receipt to the donor</span></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save &amp; issue receipt</button></div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
