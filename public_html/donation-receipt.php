<?php
/**
 * Printable donation receipt.
 * Available ONLY for confirmed donations, to:
 *   - anyone holding the secret link (ref + token) sent to the donor,
 *   - the logged-in donor who made the donation,
 *   - administrators with the "donations" permission.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/admin/includes/functions.php';

$d = db_row('SELECT d.*, r.receipt_no, r.issued_at, r.financial_year, r.status AS receipt_status, c.title AS campaign_title
             FROM donations d
             JOIN donation_receipts r ON r.donation_id = d.id
             LEFT JOIN campaigns c ON c.id = d.campaign_id
             WHERE d.donation_ref = ? AND d.status IN (\'success\', \'refunded\')', [(string) ($_GET['ref'] ?? '')]);

$allowed = false;
if ($d) {
    $token = (string) ($_GET['token'] ?? '');
    $allowed = ($token !== '' && hash_equals($d['access_token'], $token))
        || ($d['user_id'] && current_user_id() === (int) $d['user_id'])
        || admin_can('donations');
}
if (!$d || !$allowed) {
    http_response_code(404);
    $pageTitle = 'Receipt not available';
    require __DIR__ . '/header.php';
    echo '<section class="section"><div class="container narrow text-center"><h1>Receipt not available</h1>'
        . '<p class="lead">Receipts are issued only for confirmed donations. Please use the link from your confirmation email, or log in to see your donations.</p>'
        . '<p><a class="btn btn-primary" href="' . e(url('my-donations.php')) . '">My Donations</a></p></div></section>';
    require __DIR__ . '/footer.php';
    exit;
}

$cancelled = $d['status'] === 'refunded' || $d['receipt_status'] === 'cancelled';
$methods = ['online' => 'Online', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'upi' => 'UPI', 'cash' => 'Cash'];
$mode = $methods[$d['payment_method']] ?? 'Online';
if ($d['payment_method'] === 'online' && $d['gateway_method']) {
    $mode .= ' (' . strtoupper($d['gateway_method']) . ' via Razorpay)';
}

$pageTitle = 'Receipt ' . $d['receipt_no'];
$bodyClass = 'page-receipt';
header('X-Robots-Tag: noindex');
require __DIR__ . '/header.php';
?>
<section class="section">
    <div class="container">
        <div class="print-actions">
            <button class="btn btn-primary" type="button" data-print><?= icon('printer') ?>Print / Save as PDF</button>
            <?php if (is_logged_in()): ?><a class="btn btn-outline" href="<?= e(url('my-donations.php')) ?>">My Donations</a><?php endif; ?>
        </div>
        <div class="receipt">
            <?php if ($cancelled): ?><div class="watermark">CANCELLED</div><?php endif; ?>
            <div class="receipt-head">
                <div class="receipt-org">
                    <img src="<?= e(url('assets/images/logo.png')) ?>" alt="">
                    <div>
                        <h2><?= e(setting('legal_name', site_name())) ?></h2>
                        <p><?= e(setting('contact_address')) ?></p>
                        <p><?= e(setting('contact_email')) ?><?= setting('contact_phone') ? ' · ' . e(setting('contact_phone')) : '' ?></p>
                        <?php if (setting('registration_number')): ?><p><?= e(setting('registration_type', 'Registration')) ?> No: <?= e(setting('registration_number')) ?></p><?php endif; ?>
                        <?php if (setting('pan_number')): ?><p>PAN: <?= e(setting('pan_number')) ?></p><?php endif; ?>
                    </div>
                </div>
                <div class="receipt-title">
                    <strong>Donation Receipt</strong>
                    <span>No. <?= e($d['receipt_no']) ?></span><br>
                    <span>Date: <?= e(format_date($d['paid_at'] ?: $d['issued_at'])) ?></span>
                </div>
            </div>
            <div class="receipt-body">
                <p>Received with thanks from:</p>
                <table class="receipt-table">
                    <tr><th>Donor name</th><td><?= e($d['donor_name']) ?></td></tr>
                    <?php if ($d['donor_address']): ?><tr><th>Address</th><td><?= e($d['donor_address']) ?></td></tr><?php endif; ?>
                    <tr><th>Email / Mobile</th><td><?= e($d['donor_email']) ?><?= $d['donor_phone'] ? ' / ' . e($d['donor_phone']) : '' ?></td></tr>
                    <?php if ($d['donor_pan']): ?><tr><th>Donor PAN</th><td><?= e($d['donor_pan']) ?></td></tr><?php endif; ?>
                    <tr><th>Amount</th><td><span class="receipt-amount"><?= e(money($d['amount'], true)) ?></span><br><small><?= e(amount_in_words($d['amount'])) ?></small></td></tr>
                    <tr><th>Purpose</th><td><?= e($d['campaign_title'] ? 'Donation towards: ' . $d['campaign_title'] : 'General donation') ?></td></tr>
                    <tr><th>Mode of payment</th><td><?= e($mode) ?></td></tr>
                    <?php if ($d['gateway_payment_id']): ?><tr><th>Transaction ID</th><td><?= e($d['gateway_payment_id']) ?></td></tr><?php endif; ?>
                    <tr><th>Donation reference</th><td><?= e($d['donation_ref']) ?></td></tr>
                    <tr><th>Financial year</th><td><?= e($d['financial_year']) ?></td></tr>
                </table>
                <?php if (setting('reg_80g_number') && !$cancelled): ?>
                    <p class="small">This donation is eligible for deduction under Section 80G of the Income Tax Act, 1961, subject to the conditions of the Act.
                        80G Registration No: <strong><?= e(setting('reg_80g_number')) ?></strong>.</p>
                <?php endif; ?>
                <?php if ($cancelled): ?>
                    <p class="small" style="color:var(--red-600)"><strong>This receipt has been cancelled because the donation was refunded.</strong></p>
                <?php endif; ?>
                <p class="small muted"><?= e(setting('receipt_footer_note')) ?></p>
            </div>
            <div class="receipt-foot">
                <div>Thank you for supporting <?= e(site_name()) ?>.<br><span class="hindi"><?= e(setting('site_tagline')) ?></span></div>
                <div class="signature"><span><?= e(setting('receipt_signatory', 'Authorised Signatory')) ?></span></div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
