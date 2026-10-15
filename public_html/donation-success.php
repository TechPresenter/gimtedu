<?php
require_once __DIR__ . '/functions.php';

$d = db_row('SELECT d.*, c.title AS campaign_title, c.slug AS campaign_slug, r.receipt_no
             FROM donations d LEFT JOIN campaigns c ON c.id = d.campaign_id
             LEFT JOIN donation_receipts r ON r.donation_id = d.id
             WHERE d.donation_ref = ?', [(string) ($_GET['ref'] ?? '')]);
if (!$d || !hash_equals($d['access_token'], (string) ($_GET['token'] ?? ''))) {
    not_found();
}
if ($d['status'] === 'failed') {
    redirect('donation-failed.php?ref=' . urlencode($d['donation_ref']) . '&token=' . urlencode($d['access_token']));
}
$selfUrl = 'donation-success.php?ref=' . urlencode($d['donation_ref']) . '&token=' . urlencode($d['access_token']);
$receiptUrl = 'donation-receipt.php?ref=' . urlencode($d['donation_ref']) . '&token=' . urlencode($d['access_token']);
$shareUrl = abs_url($d['campaign_slug'] ? 'campaign-details.php?slug=' . urlencode($d['campaign_slug']) : 'donate.php');

$pageTitle = $d['status'] === 'success' ? 'Thank you for your donation' : 'Confirming your payment';
require __DIR__ . '/header.php';
?>
<section class="section">
    <div class="container narrow">
        <div class="form-card result-card">
            <?php if ($d['status'] === 'success'): ?>
                <span class="result-icon success"><?= icon('check-circle') ?></span>
                <h1 style="font-size:2rem">Thank you, <?= e($d['donor_name']) ?>!</h1>
                <p class="lead">Your donation of <strong><?= e(money($d['amount'], true)) ?></strong> has been received and verified.</p>
                <div class="pay-summary text-left">
                    <div><span>Receipt number</span><strong><?= e($d['receipt_no']) ?></strong></div>
                    <div><span>Donation reference</span><strong><?= e($d['donation_ref']) ?></strong></div>
                    <div><span>Payment ID</span><strong><?= e($d['gateway_payment_id'] ?: '-') ?></strong></div>
                    <div><span>Towards</span><strong><?= e($d['campaign_title'] ?: 'General Fund') ?></strong></div>
                    <div><span>Amount</span><strong><?= e(money($d['amount'], true)) ?></strong></div>
                </div>
                <div class="form-actions" style="justify-content:center">
                    <a class="btn btn-primary" href="<?= e(url($receiptUrl)) ?>"><?= icon('download') ?>View / Print Receipt</a>
                    <?php if (is_logged_in()): ?>
                        <a class="btn btn-outline" href="<?= e(url('my-donations.php')) ?>">My Donations</a>
                    <?php endif; ?>
                </div>
                <p class="muted small mt-3">A copy of the receipt has been emailed to <?= e($d['donor_email']) ?>.</p>
                <div class="share-row" style="justify-content:center">
                    <strong class="small">Inspire others:</strong>
                    <a href="https://wa.me/?text=<?= e(urlencode('I just supported ' . site_name() . '. Join me: ' . $shareUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('whatsapp') ?>WhatsApp</a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= e(urlencode($shareUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('facebook') ?>Facebook</a>
                </div>
            <?php elseif ($d['status'] === 'refunded'): ?>
                <span class="result-icon failed"><?= icon('refresh') ?></span>
                <h1 style="font-size:1.8rem">This donation was refunded</h1>
                <p class="muted">Donation <?= e($d['donation_ref']) ?> has been refunded to the original payment method.</p>
            <?php else: ?>
                <span class="result-icon pending"><?= icon('clock') ?></span>
                <h1 style="font-size:1.8rem">We are confirming your payment</h1>
                <p class="lead">Thank you, <?= e($d['donor_name']) ?>. Your payment for <strong><?= e(money($d['amount'], true)) ?></strong> is being verified with the payment gateway.</p>
                <p class="muted">This usually takes less than a minute. Your receipt will be generated and emailed to you automatically once the payment is confirmed. Reference: <strong><?= e($d['donation_ref']) ?></strong></p>
                <div class="form-actions" style="justify-content:center">
                    <a class="btn btn-primary" href="<?= e(url($selfUrl)) ?>"><?= icon('refresh') ?>Refresh status</a>
                    <a class="btn btn-outline" href="<?= e(url('contact.php')) ?>">Need help?</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
