<?php
/**
 * Printable donation receipt (/donation-receipt/?ref=&token=).
 * Shown ONLY for verified donations (status = success) to:
 *   - anyone holding the private link (ref + secret token) emailed to the donor,
 *   - the signed-in donor who made the donation,
 *   - staff with the donations.view permission (who also see refunded/cancelled receipts, watermarked).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$ref = get_str('ref');
$token = get_str('token');
$d = preg_match('/^[A-Z0-9]{6,40}$/', $ref) ? db_row('SELECT d.*, r.receipt_no, r.issued_at, r.financial_year, r.status AS receipt_status, c.title AS campaign_title
             FROM donations d
             JOIN donation_receipts r ON r.donation_id = d.id
             LEFT JOIN campaigns c ON c.id = d.campaign_id
             WHERE d.donation_ref = ?', [$ref]) : null;

$isStaff = staff_session_can('donations.view');
$allowed = false;
if ($d) {
    $owner = ($token !== '' && hash_equals($d['access_token'], $token)) || ($d['user_id'] && current_user_id() === (int) $d['user_id']);
    $allowed = ($d['status'] === 'success' && ($owner || $isStaff)) || ($d['status'] === 'refunded' && $isStaff);
}

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');
if (!$allowed) {
    http_response_code(404);
    page_header($page, ['title' => 'Receipt not available', 'banner' => false, 'noindex' => true, 'canonical' => abs_page_url('donation-receipt')]);
    echo '<section class="section"><div class="container narrow"><div class="form-card result-card fr-result">'
        . '<span class="result-icon pending">' . icon('file-text') . '</span><h1>Receipt not available</h1>'
        . '<p class="lead">Receipts are issued only for verified donations. Please open the link from your confirmation email, or sign in to see your donations.</p>'
        . '<div class="btn-row center"><a class="btn btn-primary" href="' . e(member_url('donations')) . '">' . icon('heart') . 'My donations</a>'
        . '<a class="btn btn-outline" href="' . e(page_url('contact')) . '">Contact us</a></div></div></div></section>';
    page_footer();
    return;
}

$cancelled = $d['status'] === 'refunded' || $d['receipt_status'] === 'cancelled';
// Section 80G(5D): cash donations exceeding ₹2,000 are not deductible
$cashOver80gLimit = ($d['payment_method'] ?? '') === 'cash' && (float) $d['amount'] > 2000;
$partiallyRefunded = strpos((string) ($d['admin_notes'] ?? ''), 'Partial refund:') !== false;
$orgLines = array_filter([
    setting('contact_address'),
    trim(setting('contact_email') . (setting('contact_phone') ? ' · ' . setting('contact_phone') : ''), ' ·'),
]);
$regLines = [];
if (setting('registration_number') !== '') {
    $regLines[] = (setting('registration_type') !== '' ? setting('registration_type') : 'Registration') . ' No. ' . setting('registration_number')
        . (setting('registration_date') !== '' ? ' dated ' . setting('registration_date') : '');
}
if (setting('pan_number') !== '') {
    $regLines[] = 'PAN: ' . setting('pan_number');
}
if (setting('reg_12a_number') !== '') {
    $regLines[] = '12A Registration: ' . setting('reg_12a_number');
}
if (setting('reg_80g_number') !== '') {
    $regLines[] = '80G Registration: ' . setting('reg_80g_number');
}

page_header($page, ['title' => 'Receipt ' . $d['receipt_no'], 'banner' => false, 'noindex' => true, 'canonical' => abs_page_url('donation-receipt'), 'body_class' => 'page-receipt']);
?>
<section class="section fr-receipt-section">
    <div class="container">
        <div class="print-actions">
            <button class="btn btn-primary" type="button" data-print><?= icon('printer') ?>Print / Save as PDF</button>
            <?php if (is_logged_in()): ?><a class="btn btn-outline" href="<?= e(member_url('donations')) ?>"><?= icon('heart') ?>My donations</a><?php endif; ?>
            <a class="btn btn-ghost" href="<?= e(page_url('home')) ?>">Back to website</a>
        </div>
        <?php if ($cancelled): ?>
            <div class="alert alert-error no-print" role="status"><?= icon('alert-circle') ?><div>This receipt has been cancelled<?= $d['status'] === 'refunded' ? ' because the donation was refunded' : '' ?>. It is shown for record-keeping only.</div></div>
        <?php endif; ?>
        <article class="receipt fr-receipt" aria-labelledby="receipt-title">
            <?php if ($cancelled): ?><div class="watermark" aria-hidden="true">CANCELLED</div><?php endif; ?>
            <header class="receipt-head">
                <div class="receipt-org">
                    <img src="<?= e(site_logo()) ?>" alt="<?= e(site_name()) ?> logo" width="84" height="84">
                    <div>
                        <h2><?= e(setting('legal_name', site_name())) ?></h2>
                        <?php foreach ($orgLines as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
                        <?php foreach ($regLines as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
                    </div>
                </div>
                <div class="receipt-title">
                    <h1 id="receipt-title">Donation Receipt</h1>
                    <span>Receipt No. <strong class="fr-receipt-no"><?= e($d['receipt_no']) ?></strong></span><br>
                    <span>Date: <?= e(format_date($d['paid_at'] ?: $d['issued_at'])) ?></span><br>
                    <span>Financial year: <?= e($d['financial_year']) ?></span>
                </div>
            </header>
            <div class="receipt-body">
                <p>Received with thanks from:</p>
                <table class="receipt-table">
                    <tr><th scope="row">Donor name</th><td><?= e($d['donor_name']) ?></td></tr>
                    <?php if ($d['donor_address']): ?><tr><th scope="row">Address</th><td><?= e($d['donor_address']) ?></td></tr><?php endif; ?>
                    <tr><th scope="row">Email / Mobile</th><td class="fr-break"><?= e($d['donor_email']) ?><?= $d['donor_phone'] ? ' / ' . e($d['donor_phone']) : '' ?></td></tr>
                    <?php if ($d['donor_pan']): ?><tr><th scope="row">Donor PAN</th><td><?= e(donation_mask_pan($d['donor_pan'])) ?></td></tr><?php endif; ?>
                    <tr><th scope="row">Amount</th><td><span class="receipt-amount"><?= e(money($d['amount'], true)) ?></span><br><small><?= e(amount_in_words($d['amount'])) ?></small><?php if ($partiallyRefunded): ?><br><small>Revised after a partial refund: this is the amount the foundation kept.</small><?php endif; ?></td></tr>
                    <tr><th scope="row">Purpose</th><td><?= e($d['campaign_title'] ? 'Donation towards: ' . $d['campaign_title'] : 'General donation (where it is needed most)') ?></td></tr>
                    <tr><th scope="row">Mode of payment</th><td><?= e(donation_method_label($d)) ?></td></tr>
                    <?php if ($d['gateway_payment_id']): ?><tr><th scope="row">Transaction ID</th><td class="fr-break"><?= e($d['gateway_payment_id']) ?></td></tr><?php endif; ?>
                    <?php if (!empty($d['offline_reference'])): ?><tr><th scope="row"><?= $d['payment_method'] === 'cheque' ? 'Cheque no. / reference' : ($d['payment_method'] === 'cash' ? 'Cash receipt / deposit reference' : 'Transaction / UTR reference') ?></th><td class="fr-break"><?= e($d['offline_reference']) ?></td></tr><?php endif; ?>
                    <tr><th scope="row">Donation reference</th><td><?= e($d['donation_ref']) ?></td></tr>
                </table>
                <?php if (setting('reg_80g_number') !== '' && !$cancelled && $cashOver80gLimit): ?>
                    <p class="small">Cash donations exceeding ₹2,000 are not eligible for deduction under Section 80G of the Income Tax Act, 1961 (Section 80G(5D)).</p>
                <?php elseif (setting('reg_80g_number') !== '' && !$cancelled): ?>
                    <p class="small">This donation may qualify for deduction under Section 80G of the Income Tax Act, 1961 (80G Registration No. <strong><?= e(setting('reg_80g_number')) ?></strong>), subject to the conditions and limits of the Act.
                        The deduction is allowed on the basis of the certificate in Form 10BE, which the foundation issues after filing its statement of donations in Form 10BD.</p>
                    <?php if (empty($d['donor_pan'])): ?>
                        <p class="small">No PAN was provided. To have this donation reported in Form 10BD and receive a Form 10BE certificate, please send your PAN to us.</p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($cancelled): ?>
                    <p class="small fr-cancelled"><strong>This receipt has been cancelled<?= $d['status'] === 'refunded' ? ' because the donation was refunded' : '' ?>.</strong></p>
                <?php endif; ?>
                <?php if (setting('receipt_footer_note') !== ''): ?><p class="small muted"><?= e(setting('receipt_footer_note')) ?></p><?php endif; ?>
            </div>
            <footer class="receipt-foot">
                <div>Thank you for supporting <?= e(site_name()) ?>.<br><span class="hindi" lang="hi"><?= e(setting('site_tagline')) ?></span></div>
                <div class="signature"><span><?= e(setting('receipt_signatory', 'Authorised Signatory')) ?></span><small><?= e(setting('legal_name', site_name())) ?></small></div>
            </footer>
        </article>
    </div>
</section>
<?php
page_footer();
