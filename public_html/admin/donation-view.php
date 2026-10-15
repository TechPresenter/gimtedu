<?php
/**
 * One donation: details, gateway ids, webhook events, and actions:
 * re-check with Razorpay (captures an authorised payment of the exact amount), check refunds with Razorpay,
 * mark reconciled, add note, website name visibility, refund (donations.manage); resend receipt (receipts.manage).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('donations.view');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$self = 'donation-view.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only($self);
    $d = db_row('SELECT * FROM donations WHERE id = ?', [$id]);
    if (!$d) {
        flash('error', 'Donation not found.');
        admin_redirect('donations.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    $admin = current_admin();
    require_permission($action === 'resend' ? 'receipts.manage' : 'donations.manage');

    switch ($action) {
        case 'recheck':
            if (!in_array($d['status'], ['pending', 'failed'], true) || $d['payment_method'] !== 'online' || !$d['gateway_order_id']) {
                flash('info', 'Only pending or failed online donations with a gateway order can be re-checked.');
                break;
            }
            if (!razorpay_configured()) {
                flash('error', 'Razorpay keys are not configured.');
                break;
            }
            $payment = razorpay_find_captured_payment($d);
            if ($payment) {
                mark_donation_success($id, $payment, 'admin_recheck', admin_id());
                admin_log('donation_rechecked', 'Donation #' . $id . ' ' . $d['donation_ref'] . ' confirmed by gateway re-check (' . ($payment['id'] ?? '') . ')');
                flash('success', 'Razorpay confirmed a captured payment of the exact amount (or an authorised payment that has now been captured). The donation is now verified and a receipt has been issued.');
            } else {
                admin_log('donation_rechecked', 'Donation #' . $id . ' ' . $d['donation_ref'] . ' - no matching captured payment');
                flash('warning', 'Razorpay has no captured payment of the exact amount for this order. The donation remains ' . $d['status'] . '.');
            }
            break;

        case 'sync_refund':
            if ($d['status'] !== 'success' || $d['payment_method'] !== 'online' || !$d['gateway_payment_id']) {
                flash('info', 'Only verified online donations with a gateway payment can be checked for refunds.');
                break;
            }
            if (!razorpay_configured()) {
                flash('error', 'Razorpay keys are not configured.');
                break;
            }
            $payment = razorpay_fetch_payment((string) $d['gateway_payment_id']);
            if (!$payment) {
                flash('error', 'Razorpay did not return this payment. Please try again later.');
                break;
            }
            $res = apply_gateway_refund_state($d, $payment, null, 'admin check', admin_id());
            admin_log('donation_refund_checked', 'Donation #' . $id . ' ' . $d['donation_ref'] . ': ' . $res);
            $messages = [
                'donation refunded' => ['success', 'Razorpay shows the full amount refunded. The donation is now marked refunded and its receipt cancelled.'],
                'partial refund applied' => ['success', 'Razorpay shows a partial refund. The donation amount now shows the net amount kept and the revised receipt was emailed.'],
                'partial refund already applied' => ['info', 'The donation already reflects the partial refund shown by Razorpay.'],
                'not confirmed: gateway shows no refund' => ['info', 'Razorpay shows no refund for this payment. Nothing was changed.'],
            ];
            [$type, $msg] = $messages[$res] ?? ['warning', 'Nothing was changed (' . $res . ').'];
            flash($type, $msg);
            break;

        case 'visibility':
            if ($d['status'] !== 'success') {
                flash('error', 'Only verified donations are listed on the website.');
                break;
            }
            $hide = ($_POST['hide'] ?? '') === '1' ? 1 : 0;
            db_update('donations', ['is_anonymous' => $hide], 'id = :id', ['id' => $id]);
            admin_log('donation_visibility_changed', 'Donation #' . $id . ' ' . $d['donation_ref'] . ': donor name ' . ($hide ? 'hidden on the website' : 'shown on the website (donor agreed)'));
            flash('success', $hide ? 'The donor\'s name is no longer shown on the website.' : 'The donor\'s name (first name and last initial) may now be shown among recent supporters.');
            break;

        case 'reconcile':
            if ($d['status'] !== 'success') {
                flash('error', 'Only verified (successful) donations can be marked as reconciled.');
                break;
            }
            if (!empty($_POST['undo'])) {
                db_update('donations', ['reconciled_at' => null, 'reconciled_by' => null], 'id = :id', ['id' => $id]);
                admin_log('donation_unreconciled', 'Donation #' . $id . ' ' . $d['donation_ref']);
                flash('success', 'Reconciliation mark removed.');
                break;
            }
            $note = mb_substr(clean_string($_POST['note'] ?? ''), 0, 255);
            db_update('donations', [
                'reconciled_at' => date('Y-m-d H:i:s'), 'reconciled_by' => admin_id(),
                'admin_notes' => trim(($d['admin_notes'] ?? '') . "\n[" . date('d M Y H:i') . '] Reconciled with bank / settlement by ' . $admin['name'] . ($note !== '' ? ': ' . $note : '')),
            ], 'id = :id', ['id' => $id]);
            admin_log('donation_reconciled', 'Donation #' . $id . ' ' . $d['donation_ref'] . ($note !== '' ? ': ' . $note : ''));
            flash('success', 'Donation marked as reconciled.');
            break;

        case 'note':
            $note = mb_substr(clean_string($_POST['note'] ?? '', true), 0, 1000);
            if ($note === '') {
                flash('error', 'Write a note first.');
                break;
            }
            db_update('donations', ['admin_notes' => trim(($d['admin_notes'] ?? '') . "\n[" . date('d M Y H:i') . '] ' . $admin['name'] . ': ' . $note)], 'id = :id', ['id' => $id]);
            admin_log('donation_note', 'Donation #' . $id . ' ' . $d['donation_ref']);
            flash('success', 'Note saved.');
            break;

        case 'refund':
            $reason = mb_substr(clean_string($_POST['reason'] ?? ''), 0, 255);
            if ($d['status'] !== 'success') {
                flash('error', 'Only verified (successful) donations can be refunded.');
                break;
            }
            if (mb_strlen($reason) < 5 || ($_POST['confirm_ref'] ?? '') !== $d['donation_ref']) {
                flash('error', 'To refund, give a reason and type the donation reference exactly as shown.');
                break;
            }
            if ($d['payment_method'] === 'online' && $d['gateway_payment_id']) {
                if (!razorpay_configured()) {
                    flash('error', 'Razorpay keys are not configured, so the refund cannot be sent.');
                    break;
                }
                $res = razorpay_refund($d, $reason);
                if (!$res['ok']) {
                    admin_log('donation_refund_failed', 'Donation #' . $id . ' ' . $d['donation_ref'] . ': ' . $res['error']);
                    flash('error', 'Razorpay did not accept the refund: ' . $res['error']);
                    break;
                }
                mark_donation_refunded($id, (string) $res['refund']['id'], 'Full refund via Razorpay (' . ($res['refund']['status'] ?? 'created') . '): ' . $reason, admin_id());
                admin_log('donation_refund_requested', 'Donation #' . $id . ' ' . $d['donation_ref'] . ' Razorpay refund ' . $res['refund']['id'] . ': ' . $reason);
                flash('success', 'Refund ' . $res['refund']['id'] . ' created with Razorpay. The donation is now marked refunded and its receipt cancelled.');
            } else {
                $ref = mb_substr(clean_string($_POST['refund_reference'] ?? ''), 0, 80);
                if ($ref === '') {
                    flash('error', 'Enter the bank / cheque reference of the offline refund.');
                    break;
                }
                mark_donation_refunded($id, $ref, 'Offline refund (' . $ref . '): ' . $reason, admin_id());
                admin_log('donation_refunded_offline', 'Donation #' . $id . ' ' . $d['donation_ref'] . ' ref ' . $ref . ': ' . $reason);
                flash('success', 'Donation marked as refunded and its receipt cancelled.');
            }
            break;

        case 'resend':
            if ($d['status'] !== 'success') {
                flash('error', 'Receipts can only be sent for verified donations.');
                break;
            }
            issue_receipt($id);
            $receipt = db_row('SELECT status FROM donation_receipts WHERE donation_id = ?', [$id]);
            if (($receipt['status'] ?? '') !== 'issued') {
                flash('error', 'The receipt for this donation is cancelled. Reinstate it in Receipts before sending.');
                break;
            }
            $sent = send_receipt_email($id);
            admin_log('receipt_resent', 'Donation #' . $id . ' ' . $d['donation_ref'] . ($sent ? '' : ' (email failed)'));
            flash($sent ? 'success' : 'warning', $sent ? 'Receipt emailed to ' . $d['donor_email'] . '.' : 'The receipt email could not be sent. Check the email settings (SMTP) and try again.');
            break;
    }
    admin_redirect($self);
}

$d = db_row('SELECT d.*, c.title AS campaign_title, c.slug AS campaign_slug, r.receipt_no, r.issued_at, r.emailed_at, r.email_count, r.status AS receipt_status, r.financial_year,
                    u.name AS user_name, v.name AS verified_by_name, rc.name AS reconciled_by_name
             FROM donations d
             LEFT JOIN campaigns c ON c.id = d.campaign_id
             LEFT JOIN donation_receipts r ON r.donation_id = d.id
             LEFT JOIN users u ON u.id = d.user_id
             LEFT JOIN users v ON v.id = d.verified_by
             LEFT JOIN users rc ON rc.id = d.reconciled_by
             WHERE d.id = ?', [$id]);
if (!$d) {
    flash('error', 'Donation not found.');
    admin_redirect('donations.php');
}
$events = db_rows('SELECT * FROM payment_webhook_events WHERE donation_id = ? ORDER BY received_at DESC, id DESC LIMIT 50', [$id]);
$canManage = admin_can('donations.manage');
$isOnline = $d['payment_method'] === 'online';
$actions = [['label' => 'All donations', 'url' => admin_url('donations.php'), 'icon' => 'list', 'style' => 'ghost']];
if ($d['receipt_no']) {
    $actions[] = ['label' => 'View receipt', 'url' => page_url('donation-receipt', ['ref' => $d['donation_ref']]), 'icon' => 'printer'];
}
admin_page_start('Donation ' . $d['donation_ref'], $actions);
?>
<div class="admin-grid-main">
    <div>
        <section class="panel">
            <div class="panel-head"><h2><?= e(money($d['amount'], true)) ?> <small class="muted"><?= e($d['currency']) ?></small></h2><?= status_badge($d['status']) ?></div>
            <?php if ($d['status'] === 'pending'): ?><div class="alert alert-warning mb-2"><?= icon('clock') ?><div>Pending - not counted in any total. It becomes successful only when Razorpay confirms a captured payment (checkout, webhook or re-check).</div></div><?php endif; ?>
            <?php details_list([
                'Reference' => $d['donation_ref'],
                'Donor' => [e($d['donor_name']) . ($d['is_anonymous'] ? ' <span class="badge badge-gray" title="Name hidden on the website">Anonymous</span>' : '')],
                'Email / mobile' => $d['donor_email'] . ' / ' . ($d['donor_phone'] ?: '-'),
                'PAN' => $d['donor_pan'] ?: '-',
                'Address' => $d['donor_address'] ?: '-',
                'Website account' => $d['user_id'] ? [admin_can('members.view') ? '<a href="' . e(admin_url('user-view.php?id=' . (int) $d['user_id'])) . '">' . e($d['user_name']) . '</a>' : e($d['user_name'])] : 'Guest donation',
                'Towards' => $d['campaign_id'] ? [(admin_can('campaigns.manage') ? '<a href="' . e(admin_url('campaign-view.php?id=' . (int) $d['campaign_id'])) . '">' . e($d['campaign_title']) . '</a>' : e($d['campaign_title']))] : 'General fund',
                'Message' => $d['message'] ?: '-',
            ]); ?>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Payment &amp; verification</h2></div>
            <?php details_list([
                'Method' => DONATION_METHODS[$d['payment_method']] ?? $d['payment_method'],
                'Gateway' => $d['gateway'] . ($d['gateway_method'] ? ' (' . $d['gateway_method'] . ')' : ''),
                'Order ID' => $d['gateway_order_id'] ?: '-',
                'Payment ID' => $d['gateway_payment_id'] ?: '-',
                'Transaction / cheque reference' => $d['offline_reference'] ?: null,
                'Created' => format_date($d['created_at'], 'd M Y, h:i:s A') . ' · IP ' . ($d['ip_address'] ?: '-'),
                'Paid / verified' => $d['paid_at'] ? format_date($d['paid_at'], 'd M Y, h:i:s A') . ($d['verified_via'] ? ' via ' . humanize($d['verified_via']) : '') . ($d['verified_by_name'] ? ' by ' . $d['verified_by_name'] : '') : 'Not verified',
                'Reconciled' => $d['reconciled_at'] ? format_date($d['reconciled_at'], 'd M Y, h:i A') . ($d['reconciled_by_name'] ? ' by ' . $d['reconciled_by_name'] : '') : 'No',
                'Failure reason' => $d['failure_reason'],
                'Refund' => $d['status'] === 'refunded' ? ($d['refund_id'] ?: '-') . ($d['refunded_at'] ? ' · ' . format_date($d['refunded_at'], 'd M Y, h:i A') : '') : null,
                'Receipt' => $d['receipt_no'] ? [e($d['receipt_no']) . ' · FY ' . e($d['financial_year']) . ' · issued ' . e(format_date($d['issued_at'], 'd M Y, h:i A')) . ' · ' . ($d['emailed_at'] ? 'emailed ' . (int) $d['email_count'] . 'x, last ' . e(format_date($d['emailed_at'], 'd M Y, h:i A')) : 'not emailed') . ' ' . status_badge($d['receipt_status'])] : 'Not issued (issued only for verified donations)',
            ]); ?>
            <?php if ($d['admin_notes']): ?><h3 class="mt-3">Notes &amp; history</h3><div class="note-box"><?= e($d['admin_notes']) ?></div><?php endif; ?>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Webhook events</h2><span class="muted small">Signed notifications from Razorpay</span></div>
            <?php if ($events): ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Received</th><th>Event</th><th>Event ID</th><th>Result</th></tr></thead>
                    <tbody><?php foreach ($events as $ev): ?>
                        <tr><td class="nowrap"><?= e(format_date($ev['received_at'], 'd M Y, h:i:s A')) ?></td><td><code><?= e($ev['event_type']) ?></code></td><td class="small"><?= e($ev['event_id']) ?></td><td class="small"><?= e($ev['result'] ?: 'Received') ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php else: ?>
                <p class="muted small">No webhook events recorded for this donation<?= $isOnline ? '' : ' (offline donation)' ?>.</p>
            <?php endif; ?>
        </section>
    </div>
    <div>
        <?php if ($canManage && $isOnline && in_array($d['status'], ['pending', 'failed'], true) && $d['gateway_order_id']): ?>
            <section class="panel">
                <div class="panel-head"><h2>Re-check with Razorpay</h2></div>
                <p class="small muted">Asks Razorpay whether this order has a captured payment of exactly <?= e(money($d['amount'], true)) ?>. The donation is marked successful only if Razorpay confirms it.</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="recheck">
                    <button class="btn btn-primary btn-block" type="submit"><?= icon('refresh') ?>Re-check payment status</button></form>
            </section>
        <?php endif; ?>
        <?php if ($d['status'] === 'success'): ?>
            <?php if (admin_can('receipts.manage')): ?>
                <section class="panel">
                    <div class="panel-head"><h2>Receipt</h2></div>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="resend">
                        <button class="btn btn-ghost btn-block" type="submit"><?= icon('mail') ?>Email receipt to donor</button></form>
                </section>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <section class="panel">
                    <div class="panel-head"><h2>Reconciliation</h2></div>
                    <?php if ($d['reconciled_at']): ?>
                        <p class="small">Reconciled on <?= e(format_date($d['reconciled_at'], 'd M Y')) ?><?= $d['reconciled_by_name'] ? ' by ' . e($d['reconciled_by_name']) : '' ?>.</p>
                        <form method="post" data-confirm="Remove the reconciliation mark?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reconcile"><input type="hidden" name="undo" value="1">
                            <button class="btn btn-ghost btn-sm" type="submit">Undo</button></form>
                    <?php else: ?>
                        <p class="small muted">Mark after matching this donation with the Razorpay settlement or bank statement.</p>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reconcile">
                            <div class="form-group mb-2"><label for="rec_note">Settlement / statement reference</label><input id="rec_note" name="note" maxlength="255"></div>
                            <button class="btn btn-primary btn-block" type="submit"><?= icon('check') ?>Mark as reconciled</button></form>
                    <?php endif; ?>
                </section>
                <section class="panel">
                    <div class="panel-head"><h2>Name on the website</h2></div>
                    <p class="small muted"><?= $d['is_anonymous'] ? 'Anonymous: the name is not shown among recent supporters.' : 'Shown among recent supporters as first name and last initial (the amount is never shown).' ?></p>
                    <form method="post"<?= $d['is_anonymous'] ? ' data-confirm="Show this donor\'s name on the website? Do this only when the donor has agreed."' : '' ?>><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="visibility"><input type="hidden" name="hide" value="<?= $d['is_anonymous'] ? '0' : '1' ?>">
                        <button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon($d['is_anonymous'] ? 'eye' : 'lock') ?><?= $d['is_anonymous'] ? 'Show name on website (donor agreed)' : 'Hide name on website' ?></button></form>
                </section>
                <?php if ($isOnline && $d['gateway_payment_id'] && razorpay_configured()): ?>
                    <section class="panel">
                        <div class="panel-head"><h2>Refunds at Razorpay</h2></div>
                        <p class="small muted">Use this if a refund was made in the Razorpay Dashboard or a refund notification was missed. A partial refund lowers the amount to the net amount kept and re-sends the revised receipt; a full refund marks the donation refunded.</p>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="sync_refund">
                            <button class="btn btn-ghost btn-sm btn-block" type="submit"><?= icon('refresh') ?>Check refunds with Razorpay</button></form>
                    </section>
                <?php endif; ?>
                <section class="panel">
                    <div class="panel-head"><h2>Refund</h2></div>
                    <p class="small muted"><?= $isOnline && $d['gateway_payment_id'] ? 'Refunds the remaining ' . e(money($d['amount'], true)) . ' through Razorpay to the original payment method.' : 'For an offline donation: record a refund that has already been paid back by bank transfer or cheque.' ?>
                        The donation is removed from verified totals and its receipt is cancelled.</p>
                    <form method="post" data-validate data-confirm="Refund <?= e(money($d['amount'], true)) ?> to <?= e($d['donor_name']) ?>? This cannot be undone.">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="refund">
                        <div class="form-group mb-2"><label for="refund_reason">Reason<span class="req">*</span></label><input id="refund_reason" name="reason" required minlength="5" maxlength="255"></div>
                        <?php if (!($isOnline && $d['gateway_payment_id'])): ?>
                            <div class="form-group mb-2"><label for="refund_reference">Refund bank / cheque reference<span class="req">*</span></label><input id="refund_reference" name="refund_reference" required maxlength="80"></div>
                        <?php endif; ?>
                        <div class="form-group mb-2"><label for="confirm_ref">Type <strong><?= e($d['donation_ref']) ?></strong> to confirm<span class="req">*</span></label><input id="confirm_ref" name="confirm_ref" required autocomplete="off"></div>
                        <button class="btn btn-danger btn-block" type="submit"><?= icon('refresh') ?><?= $isOnline && $d['gateway_payment_id'] ? 'Refund via Razorpay' : 'Mark as refunded' ?></button>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <section class="panel">
                <div class="panel-head"><h2>Add a note</h2></div>
                <form method="post" data-validate><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="note">
                    <div class="form-group mb-2"><label for="note" class="sr-only">Note</label><textarea id="note" name="note" required maxlength="1000" rows="3"></textarea></div>
                    <button class="btn btn-ghost btn-block" type="submit">Save note</button></form>
            </section>
        <?php endif; ?>
    </div>
</div>
<?php
admin_page_end();
