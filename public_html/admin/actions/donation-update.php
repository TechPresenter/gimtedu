<?php
/**
 * Donation actions: re-check with gateway, record offline donation,
 * mark refunded, resend receipt, add note.
 */
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('donations');

$action = (string) ($_POST['action'] ?? '');
$id = (int) ($_POST['id'] ?? 0);
csrf_verify($id ? 'admin/donation-view.php?id=' . $id : 'admin/donations.php');

// ---------------------------------------------------------------------
// Offline donation (bank transfer / cheque / UPI / cash) already received
// ---------------------------------------------------------------------
if ($action === 'offline') {
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
    ], ['paid_on' => 'Date received', 'reference' => 'Transaction reference']);
    if (!$errors && $data['paid_on'] > date('Y-m-d')) {
        $errors['paid_on'] = 'Date received cannot be in the future.';
    }
    if (!$errors && $data['campaign_id'] !== '' && !db_value('SELECT id FROM campaigns WHERE id = ?', [(int) $data['campaign_id']])) {
        $errors['campaign_id'] = 'Campaign not found.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/donations.php#offline', $_POST);
    }
    $donationId = db_insert('donations', [
        'donation_ref'   => 'PWFO' . date('ymd') . strtoupper(bin2hex(random_bytes(4))),
        'access_token'   => bin2hex(random_bytes(32)),
        // Not linked to a website account automatically: e-mail addresses of
        // website accounts are not verified, so linking by e-mail could expose
        // a donor's receipt (PAN, address) to someone else.
        'user_id'        => null,
        'campaign_id'    => $data['campaign_id'] !== '' ? (int) $data['campaign_id'] : null,
        'donor_name'     => $data['donor_name'],
        'donor_email'    => $data['donor_email'],
        'donor_phone'    => $data['donor_phone'] ?: null,
        'donor_pan'      => $data['donor_pan'] ?: null,
        'donor_address'  => $data['donor_address'] ?: null,
        'amount'         => round((float) $data['amount'], 2),
        'payment_method' => $data['payment_method'],
        'gateway'        => 'offline',
        'status'         => 'pending',
        'admin_notes'    => 'Offline ' . humanize($data['payment_method']) . ' reference: ' . $data['reference'] . ' (recorded by ' . current_admin()['name'] . ')',
        'ip_address'     => client_ip(),
    ]);
    // Re-use the same confirmation path as online payments (issues the receipt)
    $pdo = db();
    $pdo->beginTransaction();
    db_update('donations', [
        'status' => 'success', 'paid_at' => $data['paid_on'] . ' 12:00:00', 'verified_via' => 'offline', 'verified_by' => admin_id(),
    ], 'id = :id', ['id' => $donationId]);
    issue_receipt($donationId);
    $pdo->commit();
    admin_log('donation_offline_recorded', 'Donation #' . $donationId . ' ' . money($data['amount'], true) . ' ' . $data['payment_method'] . ' ref ' . $data['reference']);
    if (!empty($_POST['send_receipt'])) {
        send_receipt_email($donationId);
    }
    flash('success', 'Offline donation recorded and receipt issued.');
    admin_redirect('donation-view.php?id=' . $donationId);
}

$donation = db_row('SELECT * FROM donations WHERE id = ?', [$id]);
if (!$donation) {
    flash('error', 'Donation not found.');
    admin_redirect('donations.php');
}
$view = 'donation-view.php?id=' . $id;

switch ($action) {
    case 'recheck':
        if (!in_array($donation['status'], ['pending', 'failed'], true) || $donation['payment_method'] !== 'online') {
            flash('info', 'Only pending or failed online donations can be re-checked.');
            break;
        }
        if (!razorpay_configured()) {
            flash('error', 'Razorpay keys are not configured in config.php.');
            break;
        }
        $payment = razorpay_find_captured_payment($donation);
        if ($payment) {
            mark_donation_success($id, $payment, 'admin_recheck', admin_id());
            admin_log('donation_rechecked', 'Donation #' . $id . ' confirmed by gateway re-check');
            flash('success', 'Razorpay confirmed a captured payment. The donation is now successful and a receipt has been issued.');
        } else {
            admin_log('donation_rechecked', 'Donation #' . $id . ' - no captured payment found');
            flash('warning', 'Razorpay has no captured payment for this order. The donation remains ' . $donation['status'] . '.');
        }
        break;

    case 'refund':
        $note = mb_substr(clean_string($_POST['note'] ?? ''), 0, 255);
        if ($donation['status'] !== 'success' || $note === '') {
            flash('error', 'Only successful donations can be marked refunded, and a reference is required.');
            break;
        }
        db_update('donations', [
            'status' => 'refunded',
            'admin_notes' => trim(($donation['admin_notes'] ?? '') . "\n[" . date('d M Y H:i') . '] Refunded by ' . current_admin()['name'] . ': ' . $note),
        ], 'id = :id', ['id' => $id]);
        db_query("UPDATE donation_receipts SET status = 'cancelled' WHERE donation_id = ?", [$id]);
        admin_log('donation_refunded', 'Donation #' . $id . ': ' . $note);
        flash('success', 'Donation marked as refunded and receipt cancelled.');
        break;

    case 'resend':
        if ($donation['status'] !== 'success') {
            flash('error', 'Receipts can only be sent for successful donations.');
            break;
        }
        issue_receipt($id);
        send_receipt_email($id);
        admin_log('receipt_resent', 'Donation #' . $id);
        flash('success', 'Receipt email sent to ' . $donation['donor_email'] . ' (if email is configured).');
        admin_redirect(admin_back($view));
        break;

    case 'note':
        $note = mb_substr(clean_string($_POST['note'] ?? '', true), 0, 1000);
        if ($note !== '') {
            db_update('donations', ['admin_notes' => trim(($donation['admin_notes'] ?? '') . "\n[" . date('d M Y H:i') . '] ' . current_admin()['name'] . ': ' . $note)], 'id = :id', ['id' => $id]);
            admin_log('donation_note', 'Donation #' . $id);
            flash('success', 'Note saved.');
        }
        break;
}
admin_redirect($view);
