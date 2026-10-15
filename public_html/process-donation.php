<?php
/**
 * Donation handler.
 *   action=create  - validate the form, save a pending donation, create a Razorpay order
 *   action=verify  - verify the checkout signature AND the payment with Razorpay's API
 *   action=failed  - record a failed attempt reported by the checkout window
 *
 * A donation is marked successful only after server-side verification.
 */
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('donate.php');
}
$action = (string) ($_POST['action'] ?? '');

/** Load a donation using its public reference and secret access token. */
function donation_from_request(): ?array
{
    $ref = (string) ($_POST['ref'] ?? '');
    $token = (string) ($_POST['token'] ?? '');
    $donation = db_row('SELECT * FROM donations WHERE donation_ref = ?', [$ref]);
    if (!$donation || $token === '' || !hash_equals($donation['access_token'], $token)) {
        return null;
    }
    return $donation;
}

function donation_page(string $page, array $donation): string
{
    return $page . '?ref=' . urlencode($donation['donation_ref']) . '&token=' . urlencode($donation['access_token']);
}

// ---------------------------------------------------------------------
// 1. Create donation + gateway order
// ---------------------------------------------------------------------
if ($action === 'create') {
    csrf_verify('donate.php');
    if (honeypot_triggered()) {
        redirect('donate.php');
    }
    if (!razorpay_configured()) {
        flash('error', 'Online payments are not available at the moment. Please try again later.');
        redirect('donate.php');
    }

    $minAmount = max(1, (int) setting('donation_min_amount', '100'));
    [$data, $errors] = validate($_POST, [
        'amount'        => 'required|numeric|min:' . $minAmount . '|max:1000000',
        'campaign_id'   => 'integer',
        'donor_name'    => 'required|min:2|max:120',
        'donor_email'   => 'required|email',
        'donor_phone'   => 'required|phone',
        'donor_pan'     => 'pan',
        'donor_address' => 'max:255',
        'message'       => 'text|max:500',
        'accept_policy' => 'accepted',
    ], ['donor_name' => 'Name', 'donor_email' => 'Email', 'donor_phone' => 'Mobile number', 'donor_pan' => 'PAN', 'accept_policy' => 'Donation policy']);

    $campaign = null;
    if (!$errors && $data['campaign_id'] !== '') {
        $campaign = db_row("SELECT id, title FROM campaigns WHERE id = ? AND status = 'active'", [(int) $data['campaign_id']]);
        if (!$campaign) {
            $errors['campaign_id'] = 'This campaign is no longer accepting donations. Please choose another.';
        }
    }
    if ($errors) {
        back_with_errors($errors, 'donate.php', $_POST);
    }
    if (form_rate_limited('donation', 20)) {
        flash('error', 'Too many payment attempts from your network. Please try again later.');
        redirect('donate.php');
    }
    log_activity('form_donation', '');

    $amount = round((float) $data['amount'], 2);
    $ref = 'PWFD' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
    $donationId = db_insert('donations', [
        'donation_ref'   => $ref,
        'access_token'   => bin2hex(random_bytes(32)),
        'user_id'        => current_user_id(),
        'campaign_id'    => $campaign['id'] ?? null,
        'donor_name'     => $data['donor_name'],
        'donor_email'    => $data['donor_email'],
        'donor_phone'    => $data['donor_phone'],
        'donor_pan'      => $data['donor_pan'] ?: null,
        'donor_address'  => $data['donor_address'] ?: null,
        'amount'         => $amount,
        'currency'       => 'INR',
        'is_anonymous'   => !empty($_POST['is_anonymous']) ? 1 : 0,
        'message'        => $data['message'] ?: null,
        'payment_method' => 'online',
        'gateway'        => 'razorpay',
        'status'         => 'pending',
        'ip_address'     => client_ip(),
    ]);

    [$status, $order] = razorpay_request('POST', 'orders', [
        'amount'   => to_paise($amount),
        'currency' => 'INR',
        'receipt'  => $ref,
        'notes'    => ['donation_ref' => $ref, 'campaign' => $campaign['title'] ?? 'General Fund'],
    ]);

    if ($status !== 200 || empty($order['id']) || (int) ($order['amount'] ?? 0) !== to_paise($amount)) {
        error_log('Razorpay order creation failed for ' . $ref . ': HTTP ' . $status . ' ' . json_encode($order));
        db_update('donations', ['status' => 'failed', 'failure_reason' => 'Payment could not be started'], 'id = :id', ['id' => $donationId]);
        flash('error', 'We could not connect to the payment gateway. Please try again in a few minutes.');
        redirect('donate.php');
    }

    db_update('donations', ['gateway_order_id' => $order['id']], 'id = :id', ['id' => $donationId]);
    $donation = db_row('SELECT * FROM donations WHERE id = ?', [$donationId]);
    redirect('donate.php?pay=' . urlencode($donation['donation_ref']) . '&token=' . urlencode($donation['access_token']));
}

// ---------------------------------------------------------------------
// 2. Verify payment returned by Razorpay Checkout
//    (authenticated by the HMAC signature, so no CSRF token is needed)
// ---------------------------------------------------------------------
if ($action === 'verify') {
    $donation = donation_from_request();
    if (!$donation) {
        not_found();
    }
    if ($donation['status'] === 'success') {
        redirect(donation_page('donation-success.php', $donation));
    }

    $paymentId = (string) ($_POST['razorpay_payment_id'] ?? '');
    $orderId = (string) ($_POST['razorpay_order_id'] ?? '');
    $signature = (string) ($_POST['razorpay_signature'] ?? '');

    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, RAZORPAY_KEY_SECRET);
    if ($orderId === '' || $orderId !== $donation['gateway_order_id'] || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
        || $signature === '' || !hash_equals($expected, $signature)) {
        log_activity('payment_signature_invalid', 'Donation ' . $donation['donation_ref']);
        flash('error', 'We could not verify this payment. If money was deducted, it will be confirmed automatically or refunded by the bank.');
        redirect(donation_page('donation-failed.php', $donation));
    }

    // Signature is valid - now confirm the payment details directly with Razorpay.
    [$status, $payment] = razorpay_request('GET', 'payments/' . rawurlencode($paymentId));
    if ($status === 200 && ($payment['status'] ?? '') === 'authorized') {
        [$status, $payment] = razorpay_request('POST', 'payments/' . rawurlencode($paymentId) . '/capture', [
            'amount' => to_paise($donation['amount']), 'currency' => $donation['currency'],
        ]);
    }

    $valid = $status === 200
        && ($payment['status'] ?? '') === 'captured'
        && ($payment['order_id'] ?? '') === $donation['gateway_order_id']
        && (int) ($payment['amount'] ?? 0) === to_paise($donation['amount'])
        && ($payment['currency'] ?? '') === $donation['currency'];

    if (!$valid) {
        // Keep it pending: the webhook (or an admin re-check) will confirm it once Razorpay captures it.
        log_activity('payment_not_captured', 'Donation ' . $donation['donation_ref'] . ' payment ' . $paymentId . ' status: ' . ($payment['status'] ?? 'unknown'));
        redirect(donation_page('donation-success.php', $donation));
    }

    $payment['signature'] = $signature;
    mark_donation_success((int) $donation['id'], $payment, 'checkout');
    redirect(donation_page('donation-success.php', $donation));
}

// ---------------------------------------------------------------------
// 3. Failed attempt reported by the checkout window
// ---------------------------------------------------------------------
if ($action === 'failed') {
    csrf_verify('donate.php');
    $donation = donation_from_request();
    if (!$donation) {
        not_found();
    }
    if ($donation['status'] === 'pending') {
        $reason = mb_substr(clean_string($_POST['error_description'] ?? 'Payment failed'), 0, 250) ?: 'Payment failed';
        db_update('donations', ['status' => 'failed', 'failure_reason' => $reason], 'id = :id AND status = :s', ['id' => $donation['id'], 's' => 'pending']);
    }
    redirect(donation_page('donation-failed.php', $donation));
}

redirect('donate.php');
