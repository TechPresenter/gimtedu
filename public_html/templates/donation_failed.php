<?php
/**
 * Payment not completed (/donation-failed/?ref=&token=): honest status + retry with the same order.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$ref = get_str('ref');
$d = donation_by_ref_token($ref, get_str('token'));
if (!$d && $ref !== '' && is_logged_in()) {
    $d = db_row('SELECT * FROM donations WHERE donation_ref = ? AND user_id = ?', [$ref, current_user_id()]);
}
if (!$d) {
    not_found();
}
if ($d['status'] === 'success' || $d['status'] === 'refunded') {
    redirect(donation_success_url($d));
}
$canRetry = $d['payment_method'] === 'online' && $d['gateway_order_id'] && in_array($d['status'], ['pending', 'failed'], true) && gateway_active(donation_gateway($d));
$retryQuery = $d['campaign_id'] ? ['campaign' => (int) $d['campaign_id'], 'amount' => (int) round((float) $d['amount'])] : ['amount' => (int) round((float) $d['amount'])];

// WP-5 dataLayer: "donation_failed" (gateway only - no amount, reference or name). This page never loads Google tags,
// so the event is sent on the donor's next eligible page, after consent. Queued once per donation and session.
if (function_exists('seo_queue_event') && session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['seo_dl_failed'][(int) $d['id']])) {
    seo_queue_event('donation_failed', null, (int) $d['id']);
    $_SESSION['seo_dl_failed'][(int) $d['id']] = 1;
}
header('X-Robots-Tag: noindex');
page_header($page, ['banner' => false, 'noindex' => true, 'canonical' => abs_page_url('donation-failed')]);
?>
<section class="section fr-result-section">
    <div class="container narrow">
        <div class="form-card result-card fr-result">
            <span class="result-icon failed"><?= icon('alert-circle') ?></span>
            <h1>Your payment was not completed</h1>
            <p class="lead">No donation has been recorded against reference <strong><?= e($d['donation_ref']) ?></strong> (<?= e(money($d['amount'], true)) ?>).</p>
            <?php if ($d['failure_reason']): ?>
                <p class="muted">Reason reported: <?= e($d['failure_reason']) ?></p>
            <?php endif; ?>
            <p class="muted small">If money was deducted from your account, it will be confirmed automatically once our payment gateway reports the payment, or refunded by your bank as per its usual timelines. You can check the status again below.</p>
            <div class="btn-row center">
                <?php if ($canRetry): ?>
                    <a class="btn btn-donate" href="<?= e(donation_pay_url($d)) ?>"><?= icon('refresh') ?>Try again</a>
                <?php else: ?>
                    <a class="btn btn-donate" href="<?= e(donate_url($retryQuery)) ?>"><?= icon('heart') ?>Start a new donation</a>
                <?php endif; ?>
                <a class="btn btn-outline" href="<?= e(donation_success_url($d)) ?>"><?= icon('search') ?>Check status</a>
            </div>
            <ul class="fr-tips text-left">
                <li><?= icon('check') ?><span>Try a different payment method (UPI, card or net banking) in the payment window.</span></li>
                <li><?= icon('check') ?><span>Check that your bank's OTP or UPI request was approved before it expired.</span></li>
                <li><?= icon('check') ?><span>Never share an OTP or PIN with anyone who calls you about a donation.</span></li>
            </ul>
            <p class="small mt-2">Need help? <a href="<?= e(page_url('contact')) ?>">Contact us</a> and quote your reference number.</p>
        </div>
    </div>
</section>
<?php
page_footer();
