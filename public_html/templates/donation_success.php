<?php
/**
 * Donation status after checkout (/donation-success/?ref=&token=).
 * "Thank you" only for verified donations (status = success); otherwise an honest "confirming" message.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$ref = get_str('ref');
$d = donation_by_ref_token($ref, get_str('token'));
if (!$d && $ref !== '' && is_logged_in()) {
    $d = db_row('SELECT * FROM donations WHERE donation_ref = ? AND user_id = ?', [$ref, current_user_id()]);   // the signed-in donor
}
if (!$d) {
    not_found();
}
if ($d['status'] === 'failed') {
    redirect(donation_failed_url($d));
}
$campaign = $d['campaign_id'] ? db_row('SELECT id, title, slug, status FROM campaigns WHERE id = ?', [$d['campaign_id']]) : null;
$receipt = db_row('SELECT * FROM donation_receipts WHERE donation_id = ?', [$d['id']]);
$shareUrl = ($campaign && campaign_is_public($campaign)) ? abs_url('campaign/' . $campaign['slug'] . '/') : abs_page_url('donate');
$towards = $campaign ? $campaign['title'] : 'Where it is needed most (general fund)';

$titles = ['success' => 'Thank you for your donation', 'pending' => 'Confirming your payment', 'refunded' => 'Donation refunded'];
header('X-Robots-Tag: noindex');
page_header($page, ['title' => $titles[$d['status']] ?? 'Donation status', 'banner' => false, 'noindex' => true, 'canonical' => abs_page_url('donation-success')]);
?>
<section class="section fr-result-section">
    <div class="container narrow">
        <div class="form-card result-card fr-result">
            <?php if ($d['status'] === 'success'): ?>
                <span class="result-icon success"><?= icon('check-circle') ?></span>
                <h1>Thank you, <?= e(strtok($d['donor_name'], ' ') ?: $d['donor_name']) ?>!</h1>
                <p class="lead">Your donation of <strong><?= e(money($d['amount'], true)) ?></strong> has been received and verified.</p>
                <p class="hindi fr-tagline" lang="hi"><?= e(setting('site_tagline')) ?></p>
                <div class="pay-summary text-left">
                    <?php if ($receipt): ?><div><span>Receipt number</span><strong><?= e($receipt['receipt_no']) ?></strong></div><?php endif; ?>
                    <div><span>Donation reference</span><strong><?= e($d['donation_ref']) ?></strong></div>
                    <?php if ($d['gateway_payment_id']): ?><div><span>Payment ID<?= $d['payment_method'] === 'online' ? ' (' . e(gateway_label(donation_gateway($d))) . ')' : '' ?></span><strong class="fr-break"><?= e($d['gateway_payment_id']) ?></strong></div><?php endif; ?>
                    <div><span>Date</span><strong><?= e(format_date($d['paid_at'], 'd M Y, h:i A')) ?></strong></div>
                    <div><span>Towards</span><strong><?= e($towards) ?></strong></div>
                    <div><span>Amount</span><strong><?= e(money($d['amount'], true)) ?></strong></div>
                </div>
                <div class="btn-row center">
                    <?php if ($receipt && $receipt['status'] === 'issued'): ?>
                        <a class="btn btn-primary" href="<?= e(donation_receipt_url($d)) ?>"><?= icon('printer') ?>View / print receipt</a>
                    <?php endif; ?>
                    <?php if (is_logged_in()): ?>
                        <a class="btn btn-outline" href="<?= e(member_url('donations')) ?>"><?= icon('heart') ?>My donations</a>
                    <?php endif; ?>
                    <?php if ($campaign && campaign_is_public($campaign)): ?>
                        <a class="btn btn-ghost" href="<?= e(campaign_url($campaign['slug'])) ?>">Back to the campaign</a>
                    <?php endif; ?>
                </div>
                <p class="muted small mt-2">
                    <?php if ($receipt && $receipt['emailed_at']): ?>We have emailed the receipt link to <?= e($d['donor_email']) ?>.
                    <?php else: ?>Please save your receipt now<?= is_logged_in() ? ' - it is also available in your dashboard' : '' ?>. If email is available, a copy is sent to <?= e($d['donor_email']) ?>.<?php endif; ?>
                    Keep the receipt link private.
                </p>
                <div class="fr-share">
                    <p><strong>Inspire others to give</strong></p>
                    <?= share_buttons($shareUrl, 'I just supported ' . site_name() . ($campaign ? ' - ' . $campaign['title'] : '') . '. Join me:') ?>
                </div>
            <?php elseif ($d['status'] === 'refunded'): ?>
                <span class="result-icon failed"><?= icon('refresh') ?></span>
                <h1>This donation was refunded</h1>
                <p class="lead">Donation <strong><?= e($d['donation_ref']) ?></strong> of <?= e(money($d['amount'], true)) ?> has been refunded<?= $d['refunded_at'] ? ' on ' . e(format_date($d['refunded_at'])) : '' ?>.</p>
                <p class="muted">Refunds reach the original payment method as per your bank's usual timelines. The receipt for this donation has been cancelled.</p>
                <div class="btn-row center"><a class="btn btn-outline" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Contact us</a></div>
            <?php else: ?>
                <span class="result-icon pending"><?= icon('clock') ?></span>
                <h1>We are confirming your payment</h1>
                <p class="lead">Thank you, <?= e($d['donor_name']) ?>. Your payment of <strong><?= e(money($d['amount'], true)) ?></strong> is being verified with our payment gateway.</p>
                <p class="muted">This usually takes less than a minute. A donation is counted - and your receipt issued - only after the payment is confirmed. Reference: <strong><?= e($d['donation_ref']) ?></strong></p>
                <div class="btn-row center">
                    <a class="btn btn-primary" href="<?= e(donation_success_url($d)) ?>"><?= icon('refresh') ?>Refresh status</a>
                    <a class="btn btn-outline" href="<?= e(page_url('contact')) ?>">Need help?</a>
                </div>
                <p class="small muted mt-2">If money was deducted but the payment is not confirmed, it will be confirmed automatically when our payment gateway reports it, or refunded by your bank as per its usual timelines.</p>
            <?php endif; ?>
        </div>
        <?php if ($d['status'] === 'success'): ?>
            <div class="grid grid-3 fr-next mt-3">
                <a class="tile reveal" href="<?= e(page_url('become-a-volunteer')) ?>"><span class="icon-circle"><?= icon('users') ?></span><span><strong>Volunteer with us</strong><small>Give your time and skills</small></span><?= icon('chevron-right', 'tile-arrow') ?></a>
                <a class="tile reveal" href="<?= e(page_url('start-a-fundraiser')) ?>"><span class="icon-circle"><?= icon('flag') ?></span><span><strong>Start a fundraiser</strong><small>Rally friends for a cause</small></span><?= icon('chevron-right', 'tile-arrow') ?></a>
                <a class="tile reveal" href="<?= e(page_url('financial-transparency')) ?>"><span class="icon-circle"><?= icon('shield') ?></span><span><strong>See how funds are used</strong><small>Verified figures &amp; reports</small></span><?= icon('chevron-right', 'tile-arrow') ?></a>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php
page_footer();
