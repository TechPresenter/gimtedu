<?php
/**
 * Donations: search, filters (status, campaign, method, dates), verified totals, CSV export and the
 * "record offline donation" form (money already received in the foundation's bank account by bank transfer,
 * cleared cheque or UPI, or cash that has been deposited and appears in the bank statement).
 * The transaction / cheque reference is stored in donations.offline_reference (exact-match duplicate check).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('donations.view');

// ---------------------------------------------------------------------
// Record an offline donation (donations.manage)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('donations.php?offline=1#offline');
    require_permission('donations.manage');
    [$data, $errors] = validate($_POST, [
        'donor_name'     => 'required|min:2|max:120',
        'donor_email'    => 'required|email',
        'donor_phone'    => 'phone',
        'donor_pan'      => 'pan',
        'donor_address'  => 'max:255',
        'amount'         => 'required|numeric|min:1|max:100000000',
        'payment_method' => 'required|in:bank_transfer,cheque,upi,cash',
        'paid_on'        => 'required|date',
        'reference'      => 'required|min:3|max:100',
        'campaign_id'    => 'integer',
        'admin_note'     => 'text|max:500',
        'confirm_received' => 'accepted',
    ], ['paid_on' => 'Date received', 'reference' => 'Transaction / cheque reference', 'confirm_received' => 'that the money has been received']);
    if (!isset($errors['paid_on']) && $data['paid_on'] > date('Y-m-d')) {
        $errors['paid_on'] = 'The date received cannot be in the future.';
    }
    if (!isset($errors['amount']) && $data['payment_method'] === 'cash' && (float) $data['amount'] >= 200000) {
        $errors['amount'] = 'Cash of ₹2,00,000 or more from one person cannot be accepted (Section 269ST of the Income Tax Act). Ask the donor to pay by bank transfer or cheque.';
    }
    if (!isset($errors['campaign_id']) && $data['campaign_id'] !== '' && (int) $data['campaign_id'] > 0
        && !db_value("SELECT id FROM campaigns WHERE id = ? AND status IN ('active','completed','closed')", [(int) $data['campaign_id']])) {
        $errors['campaign_id'] = 'Choose a published campaign or the general fund.';
    }
    // Exact match on the stored reference; a UTR is unique across bank transfer and UPI. Older rows kept the
    // reference only in the notes, so those are matched exactly (no LIKE wildcards) as a fallback.
    $sameChannel = in_array($data['payment_method'], ['bank_transfer', 'upi'], true) ? ['bank_transfer', 'upi'] : [$data['payment_method']];
    $in = implode(',', array_fill(0, count($sameChannel), '?'));
    if (!$errors && db_value("SELECT id FROM donations WHERE status = 'success' AND payment_method IN ($in)
                              AND (offline_reference = ? OR INSTR(COALESCE(admin_notes, ''), ?) > 0) LIMIT 1",
            array_merge($sameChannel, [$data['reference'], 'reference: ' . $data['reference'] . ' (recorded']))) {
        $errors['reference'] = 'A donation with this reference has already been recorded. Check the list before recording it again.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/donations.php?offline=1#offline', $_POST);
    }
    $admin = current_admin();
    $donationId = db_insert('donations', [
        'donation_ref'   => new_donation_ref('PWFO'),
        'access_token'   => bin2hex(random_bytes(32)),
        // Not linked to a website account automatically: linking by an unverified email could expose a receipt.
        'user_id'        => null,
        'campaign_id'    => $data['campaign_id'] !== '' && (int) $data['campaign_id'] > 0 ? (int) $data['campaign_id'] : null,
        'donor_name'     => $data['donor_name'],
        'donor_email'    => $data['donor_email'],
        'donor_phone'    => $data['donor_phone'] !== '' ? $data['donor_phone'] : null,
        'donor_pan'      => $data['donor_pan'] !== '' ? $data['donor_pan'] : null,
        'donor_address'  => $data['donor_address'] !== '' ? $data['donor_address'] : null,
        'amount'         => round((float) $data['amount'], 2),
        'currency'       => 'INR',
        // An offline donor never sees our form: the name is listed publicly only when staff record the donor's consent.
        'is_anonymous'   => !empty($_POST['show_name']) ? 0 : 1,
        'payment_method' => $data['payment_method'],
        'gateway'        => 'offline',
        'offline_reference' => $data['reference'],
        'status'         => 'success',
        'paid_at'        => $data['paid_on'] . ' 12:00:00',
        'verified_via'   => 'offline',
        'verified_by'    => admin_id(),
        'reconciled_at'  => date('Y-m-d H:i:s'),
        'reconciled_by'  => admin_id(),
        'admin_notes'    => '[' . date('d M Y H:i') . '] Offline ' . humanize($data['payment_method']) . ' reference: ' . $data['reference'] . ' (recorded by ' . $admin['name'] . ')'
            . ($data['admin_note'] !== '' ? "\n" . $data['admin_note'] : ''),
        'ip_address'     => client_ip(),
    ]);
    $receipt = issue_receipt($donationId);
    admin_log('donation_offline_recorded', 'Donation #' . $donationId . ' ' . money($data['amount'], true) . ' ' . $data['payment_method'] . ' ref ' . $data['reference'] . ' receipt ' . ($receipt['receipt_no'] ?? '-'));
    $mailed = !empty($_POST['send_receipt']) ? send_receipt_email($donationId) : false;
    flash('success', 'Offline donation recorded and receipt ' . ($receipt['receipt_no'] ?? '') . ' issued.' . (!empty($_POST['send_receipt']) ? ($mailed ? ' The receipt was emailed to the donor.' : ' The receipt email could not be sent (check email settings) - you can resend it later.') : '')
        . ($data['payment_method'] === 'cash' && (float) $data['amount'] > 2000 ? ' Cash donations above ₹2,000 are not eligible for an 80G deduction; the receipt says so.' : ''));
    admin_redirect('donation-view.php?id=' . $donationId);
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$statuses = ['success', 'pending', 'failed', 'refunded'];
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = in_array($_GET['status'] ?? '', $statuses, true) ? (string) $_GET['status'] : '';
$method = isset(DONATION_METHODS[$_GET['method'] ?? '']) ? (string) $_GET['method'] : '';
$gatewayFilters = ['razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'offline' => 'Offline (no gateway)'];
$gatewayFilter = isset($gatewayFilters[$_GET['gateway'] ?? '']) ? (string) $_GET['gateway'] : '';
$campaignId = (string) ($_GET['campaign'] ?? '');
$range = admin_date_range('all');
$params = [];
$where = 'WHERE 1=1' . search_where(['d.donation_ref', 'd.donor_name', 'd.donor_email', 'd.donor_phone', 'd.gateway_payment_id', 'd.gateway_order_id', 'd.donor_pan', 'd.offline_reference'], $q, $params);
if ($status !== '') {
    $where .= ' AND d.status = ?';
    $params[] = $status;
}
if ($method !== '') {
    $where .= ' AND d.payment_method = ?';
    $params[] = $method;
}
if ($gatewayFilter === 'offline') {
    $where .= " AND d.payment_method <> 'online'";
} elseif ($gatewayFilter === 'cashfree') {
    $where .= " AND d.payment_method = 'online' AND d.gateway = 'cashfree'";
} elseif ($gatewayFilter === 'razorpay') {
    $where .= " AND d.payment_method = 'online' AND d.gateway <> 'cashfree'";   // every online row before Cashfree was added
}
if ($campaignId === 'general') {
    $where .= ' AND d.campaign_id IS NULL';
} elseif ((int) $campaignId > 0) {
    $where .= ' AND d.campaign_id = ?';
    $params[] = (int) $campaignId;
}
if ($range['key'] !== 'all') {
    $where .= ' AND COALESCE(d.paid_at, d.created_at) BETWEEN ? AND ?';
    array_push($params, $range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59');
}

if (isset($_GET['export'])) {
    require_permission('exports.run');
    // every column has a unique alias, so the row keys never collide (d.status / r.status)
    $rows = db_rows("SELECT d.donation_ref, COALESCE(d.paid_at, d.created_at) AS date, d.donor_name, d.donor_email, d.donor_phone, d.donor_pan, d.donor_address,
                            d.amount, d.currency, d.status AS donation_status, d.payment_method,
                            CASE WHEN d.payment_method <> 'online' THEN 'Offline' WHEN d.gateway = 'cashfree' THEN 'Cashfree' ELSE 'Razorpay' END AS gateway_name,
                            d.gateway_method, d.gateway_order_id, d.gateway_payment_id,
                            d.offline_reference, d.verified_via, IF(d.reconciled_at IS NULL, 'No', 'Yes') AS reconciled, c.title AS campaign,
                            r.receipt_no, r.status AS receipt_status, d.is_anonymous
                     FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
                     $where ORDER BY d.created_at DESC", $params);
    admin_log('donations_exported', count($rows) . ' rows');
    send_csv('donations-' . date('Ymd-His') . '.csv', ['Reference', 'Date', 'Donor', 'Email', 'Phone', 'PAN', 'Address', 'Amount', 'Currency', 'Status', 'Method', 'Gateway', 'Gateway method',
        'Order ID', 'Payment ID', 'Transaction / cheque ref', 'Verified via', 'Reconciled', 'Campaign', 'Receipt No', 'Receipt status', 'Anonymous'], $rows);
}

$totals = db_row("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN d.status = 'success' THEN d.amount END), 0) AS verified, SUM(d.status = 'success') AS verified_n,
                         SUM(d.status = 'pending') AS pending_n, SUM(d.status = 'success' AND d.reconciled_at IS NULL) AS unreconciled
                  FROM donations d $where", $params);
$p = paginate((int) $totals['n'], 25);
$rows = db_rows("SELECT d.*, c.title AS campaign_title, r.receipt_no, r.status AS receipt_status FROM donations d
                 LEFT JOIN campaigns c ON c.id = d.campaign_id LEFT JOIN donation_receipts r ON r.donation_id = d.id
                 $where ORDER BY d.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$campaigns = db_rows("SELECT id, title, status FROM campaigns WHERE status IN ('active','completed','closed') ORDER BY FIELD(status, 'active', 'completed', 'closed'), title");

$actions = [];
if (admin_can('exports.run')) {
    $actions[] = ['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1, 'page' => null])), 'icon' => 'download', 'style' => 'ghost'];
}
if (admin_can('donations.manage')) {
    $actions[] = ['label' => 'Record offline donation', 'url' => '?offline=1#offline', 'icon' => 'plus'];
}
admin_page_start('Donations', $actions);
?>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle"><?= icon('rupee') ?></span><div><strong><?= e(money($totals['verified'], true)) ?></strong><small>Verified total in this view</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) $totals['verified_n'] ?></strong><small>Verified donations</small></div></div>
    <a class="stat-card" href="?status=pending"><span class="icon-circle gold"><?= icon('clock') ?></span><div><strong><?= (int) $totals['pending_n'] ?></strong><small>Pending (not counted)</small></div></a>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('shield') ?></span><div><strong><?= (int) $totals['unreconciled'] ?></strong><small>Verified, not yet reconciled</small></div></div>
</div>
<?php
$extra = '<select name="campaign" aria-label="Campaign" style="max-width:100%;min-width:0"><option value="">All campaigns</option><option value="general"' . ($campaignId === 'general' ? ' selected' : '') . '>General fund</option>';
foreach ($campaigns as $c) {
    $extra .= '<option value="' . (int) $c['id'] . '"' . ($campaignId === (string) $c['id'] ? ' selected' : '') . '>' . e(excerpt($c['title'], 45)) . '</option>';
}
$extra .= '</select><select name="method" aria-label="Payment method"><option value="">All methods</option>' . options_html(DONATION_METHODS, $method, true, '') . '</select>'
    . '<select name="gateway" aria-label="Payment gateway"><option value="">All gateways</option>' . options_html($gatewayFilters, $gatewayFilter, true, '') . '</select>';
$hidden = array_filter(['range' => $_GET['range'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''], function ($v) { return is_string($v) && $v !== ''; });
admin_filter_bar($statuses, ['placeholder' => 'Reference, donor, email, phone, PAN or payment ID', 'html' => $extra, 'hidden' => $hidden, 'active' => $method !== '' || $campaignId !== '' || $gatewayFilter !== '']);
echo admin_range_picker($range);
?>
<section class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> record(s) · <?= e($range['label']) ?></span><span>Only <strong>Success</strong> counts towards totals and public figures</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Reference</th><th>Donor</th><th>Towards</th><th>Amount</th><th>Method / gateway</th><th>Status</th><th>Receipt</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $d): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($d['paid_at'] ?: $d['created_at'], 'd M Y, h:i A')) ?></td>
                    <td><a href="<?= e(admin_url('donation-view.php?id=' . (int) $d['id'])) ?>"><strong><?= e($d['donation_ref']) ?></strong></a></td>
                    <td><?= e($d['donor_name']) ?><?= $d['is_anonymous'] ? ' <span class="badge badge-gray">Anonymous</span>' : '' ?><br><small class="muted"><?= e($d['donor_email']) ?></small></td>
                    <td><?= e(excerpt($d['campaign_title'] ?: 'General fund', 40)) ?></td>
                    <td class="nowrap"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                    <td><?php if ($d['payment_method'] === 'online'): ?><span class="gw-tag gw-tag-<?= e(donation_gateway($d)) ?>"><?= e(gateway_label(donation_gateway($d))) ?></span><?php else: ?><?= e(DONATION_METHODS[$d['payment_method']] ?? $d['payment_method']) ?><?php endif; ?><?= $d['gateway_method'] ? '<br><small class="muted">' . e(gateway_method_label($d['gateway_method'])) . '</small>' : '' ?></td>
                    <td><?= status_badge($d['status']) ?><?= $d['status'] === 'success' && $d['reconciled_at'] ? '<br><small class="muted">Reconciled</small>' : '' ?></td>
                    <td class="nowrap"><?= $d['receipt_no'] ? e($d['receipt_no']) . ($d['receipt_status'] === 'cancelled' ? ' <span class="badge badge-red">Cancelled</span>' : '') : '<span class="muted">—</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No donations match these filters.') ?>
    <?php endif; ?>
</section>

<?php if (admin_can('donations.manage')): ?>
    <details class="panel" id="offline"<?= isset($_GET['offline']) ? ' open' : '' ?>>
        <summary class="panel-head" style="cursor:pointer"><h2><?= icon('plus') ?> Record an offline donation</h2></summary>
        <p class="small muted">Use this only for money that has <strong>actually been received</strong> in the foundation's bank account (bank transfer, cleared cheque, direct UPI). Cash may be recorded only after it has been deposited into the foundation's bank account and appears in the bank statement; enter the cash receipt number and the deposit slip reference. It is recorded as a verified donation, counts towards public totals and a receipt is issued immediately.</p>
        <form method="post" data-validate data-confirm="Confirm that this amount has been received in the foundation's account?">
            <?= csrf_field() ?>
            <div class="form-grid form-grid-3">
                <div class="form-group"><label for="o_name">Donor name<span class="req">*</span></label><input id="o_name" name="donor_name" required maxlength="120" value="<?= e(old('donor_name')) ?>"><?= field_error('donor_name') ?></div>
                <div class="form-group"><label for="o_email">Donor email<span class="req">*</span></label><input id="o_email" type="email" name="donor_email" required maxlength="190" value="<?= e(old('donor_email')) ?>"><?= field_error('donor_email') ?></div>
                <div class="form-group"><label for="o_phone">Mobile</label><input id="o_phone" type="tel" name="donor_phone" maxlength="15" value="<?= e(old('donor_phone')) ?>"><?= field_error('donor_phone') ?></div>
                <div class="form-group"><label for="o_amount">Amount (₹)<span class="req">*</span></label><input id="o_amount" type="number" name="amount" required min="1" max="100000000" step="0.01" value="<?= e(old('amount')) ?>"><?= field_error('amount') ?></div>
                <div class="form-group"><label for="o_method">Method<span class="req">*</span></label><select id="o_method" name="payment_method" required aria-describedby="o_method_help"><?= options_html(['bank_transfer' => 'Bank transfer (NEFT/RTGS/IMPS)', 'cheque' => 'Cheque (cleared)', 'upi' => 'UPI (direct to bank)', 'cash' => 'Cash (deposited)'], (string) old('payment_method'), true, 'Select') ?></select><span class="help" id="o_method_help">Cash donations above ₹2,000 are not eligible for an 80G deduction; the receipt will say so.</span><?= field_error('payment_method') ?></div>
                <div class="form-group"><label for="o_date">Date received<span class="req">*</span></label><input id="o_date" type="date" name="paid_on" required max="<?= date('Y-m-d') ?>" value="<?= e(old('paid_on', date('Y-m-d'))) ?>"><?= field_error('paid_on') ?></div>
                <div class="form-group"><label for="o_ref">Transaction / cheque reference<span class="req">*</span></label><input id="o_ref" name="reference" required minlength="3" maxlength="100" value="<?= e(old('reference')) ?>"><?= field_error('reference') ?></div>
                <div class="form-group"><label for="o_pan">Donor PAN</label><input id="o_pan" name="donor_pan" maxlength="10" data-rule="pan" data-uppercase value="<?= e(old('donor_pan')) ?>"><?= field_error('donor_pan') ?></div>
                <div class="form-group"><label for="o_campaign">Towards</label><select id="o_campaign" name="campaign_id"><option value="">General fund</option><?php foreach ($campaigns as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (string) old('campaign_id') === (string) $c['id'] ? ' selected' : '' ?>><?= e(excerpt($c['title'], 50)) ?></option><?php endforeach; ?></select><?= field_error('campaign_id') ?></div>
                <div class="form-group full"><label for="o_address">Donor address</label><input id="o_address" name="donor_address" maxlength="255" value="<?= e(old('donor_address')) ?>"><?= field_error('donor_address') ?></div>
                <div class="form-group full"><label for="o_note">Internal note</label><textarea id="o_note" name="admin_note" maxlength="500" rows="2"><?= e(old('admin_note')) ?></textarea></div>
            </div>
            <label class="checkbox mt-2"><input type="checkbox" name="show_name" value="1"<?= old('show_name') ? ' checked' : '' ?>> <span>The donor has agreed to be listed on the campaign page as a supporter (first name and last initial only; the amount is never shown). Leave unticked to keep the donation anonymous.</span></label>
            <label class="checkbox mt-1"><input type="checkbox" name="send_receipt" value="1" checked> <span>Email the receipt to the donor</span></label>
            <label class="checkbox mt-1"><input type="checkbox" name="confirm_received" value="1" required> <span>I confirm this amount has been received in the foundation's bank account and matched with the bank statement (for cash: deposited and visible in the statement).<span class="req">*</span></span></label>
            <?= field_error('confirm_received') ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save &amp; issue receipt</button></div>
        </form>
    </details>
<?php endif; ?>
<?php
admin_page_end();
