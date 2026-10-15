<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('donations');

$d = db_row('SELECT d.*, c.title AS campaign_title, r.receipt_no, r.issued_at, r.emailed_at, r.status AS receipt_status,
                    u.name AS user_name, a.name AS verified_by_name
             FROM donations d
             LEFT JOIN campaigns c ON c.id = d.campaign_id
             LEFT JOIN donation_receipts r ON r.donation_id = d.id
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN admins a ON a.id = d.verified_by
             WHERE d.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$d) {
    flash('error', 'Donation not found.');
    admin_redirect('donations.php');
}
$adminTitle = 'Donation ' . $d['donation_ref'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="donations.php">&larr; All donations</a>
    <div class="actions">
        <?php if ($d['receipt_no']): ?><a class="btn btn-primary btn-sm" href="<?= e(url('donation-receipt.php?ref=' . urlencode($d['donation_ref']))) ?>" target="_blank"><?= icon('printer') ?>View receipt</a><?php endif; ?>
    </div>
</div>
<div class="admin-grid-main">
    <div>
        <div class="panel">
            <div class="panel-head"><h3><?= e(money($d['amount'], true)) ?> <?= e($d['currency']) ?></h3><?= status_badge($d['status']) ?></div>
            <dl class="detail-list">
                <dt>Reference</dt><dd><?= e($d['donation_ref']) ?></dd>
                <dt>Donor</dt><dd><?= e($d['donor_name']) ?><?= $d['is_anonymous'] ? ' <span class="badge badge-gray">Wants to be anonymous</span>' : '' ?></dd>
                <dt>Email / phone</dt><dd><?= e($d['donor_email']) ?> / <?= e($d['donor_phone'] ?: '-') ?></dd>
                <dt>PAN</dt><dd><?= e($d['donor_pan'] ?: '-') ?></dd>
                <dt>Address</dt><dd><?= e($d['donor_address'] ?: '-') ?></dd>
                <dt>Account</dt><dd><?= $d['user_id'] ? '<a href="members.php?id=' . (int) $d['user_id'] . '">' . e($d['user_name']) . '</a>' : 'Guest donation' ?></dd>
                <dt>Towards</dt><dd><?= $d['campaign_id'] ? '<a href="campaign-view.php?id=' . (int) $d['campaign_id'] . '">' . e($d['campaign_title']) . '</a>' : 'General Fund' ?></dd>
                <dt>Message</dt><dd><?= e_nl($d['message'] ?: '-') ?></dd>
            </dl>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Payment &amp; verification</h3></div>
            <dl class="detail-list">
                <dt>Method</dt><dd><?= e(humanize($d['payment_method'])) ?><?= $d['gateway_method'] ? ' (' . e($d['gateway_method']) . ')' : '' ?></dd>
                <dt>Gateway</dt><dd><?= e($d['gateway']) ?></dd>
                <dt>Order ID</dt><dd><?= e($d['gateway_order_id'] ?: '-') ?></dd>
                <dt>Payment ID</dt><dd><?= e($d['gateway_payment_id'] ?: '-') ?></dd>
                <dt>Created</dt><dd><?= e(format_date($d['created_at'], 'd M Y, h:i:s A')) ?> from IP <?= e($d['ip_address'] ?: '-') ?></dd>
                <dt>Paid / verified</dt><dd><?= e(format_date($d['paid_at'], 'd M Y, h:i:s A')) ?><?= $d['verified_via'] ? ' via ' . e(humanize($d['verified_via'])) : '' ?><?= $d['verified_by_name'] ? ' by ' . e($d['verified_by_name']) : '' ?></dd>
                <?php if ($d['failure_reason']): ?><dt>Failure reason</dt><dd><?= e($d['failure_reason']) ?></dd><?php endif; ?>
                <dt>Receipt</dt><dd><?= $d['receipt_no'] ? e($d['receipt_no']) . ' · issued ' . e(format_date($d['issued_at'], 'd M Y, h:i A')) . ($d['emailed_at'] ? ' · emailed ' . e(format_date($d['emailed_at'], 'd M Y, h:i A')) : ' · not emailed') . ' ' . status_badge($d['receipt_status']) : 'Not issued (only issued for confirmed payments)' ?></dd>
            </dl>
            <?php if ($d['admin_notes']): ?><h4 class="mt-3">Notes</h4><div class="note-box"><?= e($d['admin_notes']) ?></div><?php endif; ?>
        </div>
    </div>
    <div>
        <?php if (in_array($d['status'], ['pending', 'failed'], true) && $d['payment_method'] === 'online' && $d['gateway_order_id']): ?>
            <div class="panel">
                <h3>Re-check with gateway</h3>
                <p class="small muted">Asks Razorpay whether this order has a captured payment of the exact amount. The donation is marked successful only if Razorpay confirms it.</p>
                <form method="post" action="actions/donation-update.php">
                    <?= csrf_field() ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button class="btn btn-primary btn-block" type="submit"><?= icon('refresh') ?>Re-check payment status</button>
                </form>
            </div>
        <?php endif; ?>
        <?php if ($d['status'] === 'success'): ?>
            <div class="panel">
                <h3>Receipt</h3>
                <form method="post" action="actions/donation-update.php">
                    <?= csrf_field() ?><input type="hidden" name="action" value="resend"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <button class="btn btn-ghost btn-block" type="submit"><?= icon('mail') ?>Email receipt to donor again</button>
                </form>
            </div>
            <div class="panel">
                <h3>Mark as refunded</h3>
                <p class="small muted">Use after the refund has been processed in the Razorpay dashboard or bank. The amount is removed from verified totals and the receipt is cancelled.</p>
                <form method="post" action="actions/donation-update.php" data-confirm="Mark this donation as refunded? The receipt will be cancelled." data-validate>
                    <?= csrf_field() ?><input type="hidden" name="action" value="refund"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                    <div class="form-group mb-2"><label for="refund_note">Refund reference / reason<span class="req">*</span></label><input id="refund_note" type="text" name="note" required maxlength="255"></div>
                    <button class="btn btn-danger btn-block" type="submit">Mark refunded</button>
                </form>
            </div>
        <?php endif; ?>
        <div class="panel">
            <h3>Add a note</h3>
            <form method="post" action="actions/donation-update.php" data-validate>
                <?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <div class="form-group mb-2"><textarea name="note" required maxlength="1000" rows="3" style="min-height:90px" aria-label="Note"></textarea></div>
                <button class="btn btn-ghost btn-block" type="submit">Save note</button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
