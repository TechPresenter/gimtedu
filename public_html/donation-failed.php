<?php
require_once __DIR__ . '/functions.php';

$d = db_row('SELECT * FROM donations WHERE donation_ref = ?', [(string) ($_GET['ref'] ?? '')]);
if (!$d || !hash_equals($d['access_token'], (string) ($_GET['token'] ?? ''))) {
    not_found();
}
if ($d['status'] === 'success') {
    redirect('donation-success.php?ref=' . urlencode($d['donation_ref']) . '&token=' . urlencode($d['access_token']));
}
$canRetry = $d['gateway_order_id'] && in_array($d['status'], ['pending', 'failed'], true) && razorpay_configured();

$pageTitle = 'Payment not completed';
require __DIR__ . '/header.php';
?>
<section class="section">
    <div class="container narrow">
        <div class="form-card result-card">
            <span class="result-icon failed"><?= icon('alert-circle') ?></span>
            <h1 style="font-size:1.8rem">Your payment was not completed</h1>
            <p class="lead">Don't worry — no donation has been recorded against reference <strong><?= e($d['donation_ref']) ?></strong>.</p>
            <?php if ($d['failure_reason']): ?>
                <p class="muted">Reason: <?= e($d['failure_reason']) ?></p>
            <?php endif; ?>
            <p class="muted small">If money was deducted from your account, it will either be confirmed automatically within a few minutes or refunded by your bank as per its usual timelines. You can check the status again using the button below.</p>
            <div class="form-actions" style="justify-content:center">
                <?php if ($canRetry): ?>
                    <a class="btn btn-donate" href="<?= e(url('donate.php?pay=' . urlencode($d['donation_ref']) . '&token=' . urlencode($d['access_token']))) ?>"><?= icon('refresh') ?>Try Again</a>
                <?php endif; ?>
                <a class="btn btn-outline" href="<?= e(url('donation-success.php?ref=' . urlencode($d['donation_ref']) . '&token=' . urlencode($d['access_token']))) ?>">Check status</a>
                <a class="btn btn-ghost" href="<?= e(url('donate.php')) ?>">Start a new donation</a>
            </div>
            <p class="small mt-3">Need help? <a href="<?= e(url('contact.php')) ?>">Contact us</a> with your reference number.</p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
