<?php
/**
 * Payment gateways (v3): Razorpay + Cashfree Payments.
 *
 *  - Where the keys come from: a non-empty constant in pwf-config.php always wins; otherwise the value entered in
 *    Admin > Payment Gateways (Key ID / App ID as plain settings, secrets encrypted with includes/secrets.php).
 *  - Which gateways donors see: setting payment_gateway (razorpay | cashfree | both) + online_donations_enabled +
 *    keys actually configured (active_gateways()).
 *  - Cashfree Payments PG (API version 2023-08-01): orders, server-side verification (order PAID + a SUCCESS payment
 *    of the exact amount and currency), refunds, webhook signatures and the admin "Test connection" checks.
 *
 * Never trust the browser or a webhook body: every Cashfree payment is fetched from the Cashfree API and matched
 * against the donation row before the donation is marked successful (mark_donation_success() in campaigns.php).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// Optional overrides in pwf-config.php (empty = use the value entered in the admin panel).
defined('CASHFREE_APP_ID')      || define('CASHFREE_APP_ID', '');
defined('CASHFREE_SECRET_KEY')  || define('CASHFREE_SECRET_KEY', '');
// '' = chosen by the environment setting (sandbox / production). Only set this for a local mock server.
defined('CASHFREE_API_BASE')    || define('CASHFREE_API_BASE', '');
defined('CASHFREE_API_VERSION') || define('CASHFREE_API_VERSION', '2023-08-01');

/** Gateways the website can use (key => name shown to donors and staff). */
const PAYMENT_GATEWAYS = ['razorpay' => 'Razorpay', 'cashfree' => 'Cashfree'];

/** Cashfree JS SDK (v3), loaded only on the payment step of a Cashfree donation. */
const CASHFREE_SDK_URL = 'https://sdk.cashfree.com/js/v3/cashfree.js';

/** Webhooks older than this (seconds) are rejected (replay protection together with the stored event hash). */
const CASHFREE_WEBHOOK_MAX_AGE = 900;

// ---------------------------------------------------------------------
// Keys and their source
// ---------------------------------------------------------------------

/** A non-empty configuration constant (pwf-config.php / config.php), or ''. */
function gateway_constant(string $name): string
{
    return defined($name) ? trim((string) constant($name)) : '';
}

function razorpay_key_id(): string
{
    $c = gateway_constant('RAZORPAY_KEY_ID');
    return $c !== '' ? $c : trim(setting('razorpay_key_id'));
}

function razorpay_key_secret(): string
{
    return config_or_secret('RAZORPAY_KEY_SECRET', 'razorpay_key_secret');
}

function razorpay_webhook_secret(): string
{
    return config_or_secret('RAZORPAY_WEBHOOK_SECRET', 'razorpay_webhook_secret');
}

/** Online donations through Razorpay are possible (key id + secret). */
function razorpay_configured(): bool
{
    return razorpay_key_id() !== '' && razorpay_key_secret() !== '';
}

/** 'test', 'live' or '' (unknown / not set) from the Key ID prefix. */
function razorpay_mode(): string
{
    $id = razorpay_key_id();
    if (strpos($id, 'rzp_live_') === 0) {
        return 'live';
    }
    return strpos($id, 'rzp_test_') === 0 ? 'test' : '';
}

function cashfree_app_id(): string
{
    $c = gateway_constant('CASHFREE_APP_ID');
    return $c !== '' ? $c : trim(setting('cashfree_app_id'));
}

function cashfree_secret_key(): string
{
    return config_or_secret('CASHFREE_SECRET_KEY', 'cashfree_secret_key');
}

/** 'sandbox' (test) or 'production' (real money). */
function cashfree_env(): string
{
    $c = gateway_constant('CASHFREE_ENV');
    $env = $c !== '' ? $c : setting('cashfree_env', 'sandbox');
    return $env === 'production' ? 'production' : 'sandbox';
}

/** Cashfree PG REST base URL (no trailing slash). */
function cashfree_api_base(): string
{
    $c = gateway_constant('CASHFREE_API_BASE');
    if ($c !== '') {
        return rtrim($c, '/');
    }
    return cashfree_env() === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
}

function cashfree_configured(): bool
{
    return cashfree_app_id() !== '' && cashfree_secret_key() !== '';
}

/** Where a plain (non-secret) gateway value comes from: 'config', 'admin' or ''. */
function gateway_value_source(string $constant, string $setting): string
{
    if (gateway_constant($constant) !== '') {
        return 'config';
    }
    return trim(setting($setting)) !== '' ? 'admin' : '';
}

// ---------------------------------------------------------------------
// Which gateways donors see
// ---------------------------------------------------------------------

/** Admin choice: 'razorpay', 'cashfree' or 'both'. */
function gateway_choice(): string
{
    $v = setting('payment_gateway', 'razorpay');
    return in_array($v, ['razorpay', 'cashfree', 'both'], true) ? $v : 'razorpay';
}

function gateway_label(?string $gateway): string
{
    if ($gateway === 'offline') {
        return 'Offline';
    }
    return PAYMENT_GATEWAYS[(string) $gateway] ?? humanize((string) $gateway);
}

function gateway_configured(string $gateway): bool
{
    if ($gateway === 'razorpay') {
        return razorpay_configured();
    }
    return $gateway === 'cashfree' ? cashfree_configured() : false;
}

/**
 * Gateways offered on the donate page, in display order (Razorpay first = default).
 * Empty when online donations are switched off or no chosen gateway has keys.
 */
function active_gateways(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (setting('online_donations_enabled', '1') !== '1') {
        return $cache;
    }
    $choice = gateway_choice();
    foreach (array_keys(PAYMENT_GATEWAYS) as $gw) {
        if (($choice === 'both' || $choice === $gw) && gateway_configured($gw)) {
            $cache[] = $gw;
        }
    }
    return $cache;
}

function gateway_active(string $gateway): bool
{
    return in_array($gateway, active_gateways(), true);
}

/** Gateway used when the donor does not choose (first active one). */
function default_gateway(): string
{
    $list = active_gateways();
    return $list[0] ?? 'razorpay';
}

/** Is this gateway in test / sandbox mode (no real money)? */
function gateway_test_mode(string $gateway): bool
{
    if ($gateway === 'cashfree') {
        return cashfree_env() === 'sandbox';
    }
    return razorpay_mode() === 'test';
}

/** Any gateway offered on the donate page is in test mode. */
function payment_test_mode(): bool
{
    foreach (active_gateways() as $gw) {
        if (gateway_test_mode($gw)) {
            return true;
        }
    }
    return false;
}

/** 10-digit Indian mobile number from what the donor typed ('' when it is not one). Cashfree requires it. */
function indian_mobile(?string $phone): string
{
    $digits = preg_replace('/[\s\-().]/', '', (string) $phone);
    return preg_match('/^(?:\+?91|0)?([6-9]\d{9})$/', (string) $digits, $m) ? $m[1] : '';
}

// ---------------------------------------------------------------------
// Cashfree PG API
// ---------------------------------------------------------------------

/**
 * Call the Cashfree PG API. Returns [httpStatus, decodedBody|null]. $creds = [appId, secret] for a test
 * with keys that are not saved yet; otherwise the configured keys are used.
 */
function cashfree_request(string $method, string $endpoint, array $data = [], ?array $creds = null): array
{
    [$appId, $secret] = $creds ?: [cashfree_app_id(), cashfree_secret_key()];
    if ($appId === '' || $secret === '' || !function_exists('curl_init')) {
        return [0, null];
    }
    $headers = [
        'Content-Type: application/json', 'Accept: application/json',
        'x-api-version: ' . CASHFREE_API_VERSION,
        'x-client-id: ' . $appId,
        'x-client-secret: ' . $secret,
        'x-request-id: pwf-' . bin2hex(random_bytes(8)),
    ];
    if ($method === 'POST') {
        $headers[] = 'x-idempotency-key: ' . bin2hex(random_bytes(16));
    }
    $ch = curl_init(cashfree_api_base() . '/' . ltrim($endpoint, '/'));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method === 'POST') {
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');   // amounts as 1501.5, never 1501.4999999999999
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data ?: new stdClass());
        ini_set('serialize_precision', (string) $precision);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        error_log('Cashfree request failed (' . preg_replace('/[^a-z\/]/i', '', $endpoint) . '): ' . curl_error($ch));
        curl_close($ch);
        return [0, null];
    }
    curl_close($ch);
    $decoded = json_decode((string) $body, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

/** Cashfree order id for a donation: reference + random suffix (letters, digits, _ and -, max 45). */
function cashfree_new_order_id(array $donation): string
{
    return substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $donation['donation_ref']), 0, 30) . '_' . bin2hex(random_bytes(4));
}

function cashfree_order_id_valid(string $orderId): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_\-]{3,45}$/', $orderId);
}

/** Return URL Cashfree sends the donor back to ({order_id} is filled in by Cashfree). */
function cashfree_return_url(): string
{
    return abs_page_url('donate') . '?cf_order={order_id}';
}

/**
 * Create the Cashfree order for a pending online donation.
 * Returns ['order_id' => ..., 'session' => payment_session_id] or null.
 */
function cashfree_create_order(array $donation, string $campaignTitle = ''): ?array
{
    $phone = indian_mobile($donation['donor_phone'] ?? '');
    if ($phone === '') {
        return null;
    }
    $orderId = cashfree_new_order_id($donation);
    $meta = ['return_url' => cashfree_return_url()];
    $notify = abs_url('cashfree-webhook.php');
    if (stripos($notify, 'https://') === 0) {   // Cashfree accepts only HTTPS notification URLs
        $meta['notify_url'] = $notify;
    }
    $customerId = !empty($donation['user_id']) ? 'pwf_user_' . (int) $donation['user_id'] : 'pwf_guest_' . (int) $donation['id'];
    [$status, $order] = cashfree_request('POST', 'orders', [
        'order_id'       => $orderId,
        'order_amount'   => round((float) $donation['amount'], 2),
        'order_currency' => $donation['currency'] ?: 'INR',
        'customer_details' => [
            'customer_id'    => $customerId,
            'customer_name'  => mb_substr((string) $donation['donor_name'], 0, 100),
            'customer_email' => (string) $donation['donor_email'],
            'customer_phone' => $phone,
        ],
        'order_meta'     => $meta,
        'order_note'     => mb_substr('Donation ' . $donation['donation_ref'] . ' - ' . ($campaignTitle !== '' ? $campaignTitle : 'General donation'), 0, 200),
        'order_tags'     => ['donation_ref' => (string) $donation['donation_ref']],
    ]);
    if ($status !== 200 || !is_array($order) || ($order['order_id'] ?? '') !== $orderId
        || to_paise($order['order_amount'] ?? -1) !== to_paise($donation['amount'])
        || !is_string($order['payment_session_id'] ?? null) || $order['payment_session_id'] === ''
        || strlen($order['payment_session_id']) > 500) {
        error_log('Cashfree order creation failed for ' . $donation['donation_ref'] . ': HTTP ' . $status
            . (is_array($order) && isset($order['code']) ? ' ' . preg_replace('/[^a-z_]/', '', (string) $order['code']) : ''));
        return null;
    }
    return ['order_id' => $orderId, 'session' => (string) $order['payment_session_id']];
}

/** Fetch an order from Cashfree (null when not found / not reachable). */
function cashfree_fetch_order(string $orderId): ?array
{
    if (!cashfree_order_id_valid($orderId)) {
        return null;
    }
    [$status, $order] = cashfree_request('GET', 'orders/' . rawurlencode($orderId));
    return ($status === 200 && is_array($order) && ($order['order_id'] ?? '') === $orderId) ? $order : null;
}

/** All payment attempts of an order (null when Cashfree could not be asked). */
function cashfree_order_payments(string $orderId): ?array
{
    if (!cashfree_order_id_valid($orderId)) {
        return null;
    }
    [$status, $list] = cashfree_request('GET', 'orders/' . rawurlencode($orderId) . '/payments');
    if ($status !== 200 || !is_array($list)) {
        return null;
    }
    return array_values(array_filter($list, 'is_array'));
}

/** Refunds of an order (null when Cashfree could not be asked). */
function cashfree_order_refunds(string $orderId): ?array
{
    if (!cashfree_order_id_valid($orderId)) {
        return null;
    }
    [$status, $list] = cashfree_request('GET', 'orders/' . rawurlencode($orderId) . '/refunds');
    if ($status !== 200 || !is_array($list)) {
        return null;
    }
    return array_values(array_filter($list, 'is_array'));
}

/** Does a Cashfree order match the donation (id, amount, currency)? */
function cashfree_order_matches(array $order, array $donation): bool
{
    return !empty($donation['gateway_order_id'])
        && ($order['order_id'] ?? '') === $donation['gateway_order_id']
        && to_paise($order['order_amount'] ?? -1) === to_paise($donation['amount'])
        && strtoupper((string) ($order['order_currency'] ?? '')) === strtoupper((string) $donation['currency']);
}

/** Is this a successful (captured) Cashfree payment of exactly the donation's amount and currency? */
function cashfree_payment_matches(array $payment, array $donation): bool
{
    return ($payment['payment_status'] ?? '') === 'SUCCESS'
        && (!isset($payment['order_id']) || $payment['order_id'] === $donation['gateway_order_id'])
        && (!array_key_exists('is_captured', $payment) || $payment['is_captured'] !== false)
        && to_paise($payment['payment_amount'] ?? -1) === to_paise($donation['amount'])
        && strtoupper((string) ($payment['payment_currency'] ?? '')) === strtoupper((string) $donation['currency'])
        && preg_match('/^[A-Za-z0-9_\-]{1,60}$/', (string) ($payment['cf_payment_id'] ?? ''));
}

/** Cashfree payment converted to the shape mark_donation_success() expects. */
function cashfree_normalize_payment(array $p): array
{
    $time = (string) ($p['payment_completion_time'] ?? ($p['payment_time'] ?? ''));
    $ts = $time !== '' ? strtotime($time) : false;
    return [
        'id'         => (string) $p['cf_payment_id'],
        'method'     => preg_replace('/[^a-z_]/', '', strtolower((string) ($p['payment_group'] ?? ''))) ?: null,
        'created_at' => $ts ?: time(),
        'gateway'    => 'cashfree',
    ];
}

/**
 * Ask Cashfree about a donation's order. Never trusts the browser: the order must be PAID and have a SUCCESS
 * payment of the exact amount and currency.
 * Returns ['state' => paid|pending|failed|none|error, 'payment' => normalized payment|null, 'message' => text].
 */
function cashfree_check_donation(array $donation): array
{
    $out = ['state' => 'error', 'payment' => null, 'message' => ''];
    if (($donation['gateway'] ?? '') !== 'cashfree' || empty($donation['gateway_order_id']) || !cashfree_configured()) {
        return $out;
    }
    $order = cashfree_fetch_order((string) $donation['gateway_order_id']);
    if (!$order) {
        $out['message'] = 'Cashfree could not be reached or did not return the order.';
        return $out;
    }
    if (!cashfree_order_matches($order, $donation)) {
        $out['state'] = 'failed';
        $out['message'] = 'The payment order does not match this donation.';
        log_activity('payment_mismatch', 'Donation ' . $donation['donation_ref'] . ': Cashfree order amount/currency does not match');
        return $out;
    }
    $payments = cashfree_order_payments((string) $donation['gateway_order_id']);
    if ($payments === null) {
        $out['message'] = 'Cashfree did not return the payments for this order.';
        return $out;
    }
    $orderStatus = (string) ($order['order_status'] ?? '');
    if ($orderStatus === 'PAID') {
        foreach ($payments as $p) {
            if (cashfree_payment_matches($p, $donation)) {
                return ['state' => 'paid', 'payment' => cashfree_normalize_payment($p), 'message' => ''];
            }
        }
        log_activity('payment_mismatch', 'Donation ' . $donation['donation_ref'] . ': Cashfree order PAID but no successful payment of the exact amount');
        return ['state' => 'pending', 'payment' => null, 'message' => 'The payment is being confirmed.'];
    }
    // Latest attempt decides what the donor is told.
    usort($payments, function ($a, $b) {
        return strcmp((string) ($b['payment_time'] ?? ''), (string) ($a['payment_time'] ?? ''));
    });
    foreach ($payments as $p) {
        if (in_array($p['payment_status'] ?? '', ['PENDING', 'SUCCESS'], true)) {
            return ['state' => 'pending', 'payment' => null, 'message' => 'The payment is being confirmed by your bank.'];
        }
    }
    if ($payments) {
        $latest = $payments[0];
        $msg = mb_substr(clean_string((string) ($latest['payment_message'] ?? ($latest['error_details']['error_description'] ?? ''))), 0, 200);
        $status = (string) ($latest['payment_status'] ?? '');
        $default = $status === 'USER_DROPPED' ? 'Payment was not completed' : ($status === 'CANCELLED' ? 'Payment was cancelled' : 'Payment failed');
        return ['state' => 'failed', 'payment' => null, 'message' => $msg !== '' ? $msg : $default];
    }
    if (in_array($orderStatus, ['EXPIRED', 'TERMINATED'], true)) {
        return ['state' => 'failed', 'payment' => null, 'message' => 'The payment window expired before a payment was made'];
    }
    return ['state' => 'none', 'payment' => null, 'message' => ''];
}

/**
 * Check a Cashfree donation with the API and record the outcome (idempotent; safe from checkout, return URL,
 * webhook and admin): paid => mark_donation_success(); a failed / dropped attempt of a still pending donation =>
 * mark_donation_failed(). Returns the cashfree_check_donation() result.
 */
function cashfree_confirm_donation(array $donation, string $via, ?int $adminId = null): array
{
    $check = cashfree_check_donation($donation);
    if ($check['state'] === 'paid') {
        mark_donation_success((int) $donation['id'], $check['payment'], $via, $adminId);
    } elseif ($check['state'] === 'failed' && $donation['status'] === 'pending') {
        mark_donation_failed((int) $donation['id'], $check['message']);
    }
    return $check;
}

/** Remember (in the visitor's session) the Cashfree orders started in this browser, for the return-URL flow. */
function cashfree_remember_order(string $orderId): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !cashfree_order_id_valid($orderId)) {
        return;
    }
    $list = array_values(array_filter((array) ($_SESSION['cf_orders'] ?? []), 'is_string'));
    if (!in_array($orderId, $list, true)) {
        $list[] = $orderId;
    }
    $_SESSION['cf_orders'] = array_slice($list, -10);
}

function cashfree_order_in_session(string $orderId): bool
{
    return session_status() === PHP_SESSION_ACTIVE && in_array($orderId, (array) ($_SESSION['cf_orders'] ?? []), true);
}

/**
 * Payment session for the Cashfree checkout of a pending / failed donation. Opens the stored session, or asks
 * Cashfree again; an expired order is replaced by a new order of the same amount ("Try again" keeps working).
 * Returns ['session' => ..., 'paid' => bool] or null when the payment cannot be started.
 */
function cashfree_payment_session(array $donation): ?array
{
    $orderId = (string) $donation['gateway_order_id'];
    $session = (string) ($donation['gateway_session_id'] ?? '');
    if ($session !== '' && $donation['status'] === 'pending') {
        return ['session' => $session, 'paid' => false];
    }
    $order = $orderId !== '' ? cashfree_fetch_order($orderId) : null;
    if ($order && cashfree_order_matches($order, $donation)) {
        if (($order['order_status'] ?? '') === 'PAID') {
            return ['session' => '', 'paid' => true];
        }
        if (($order['order_status'] ?? '') === 'ACTIVE' && is_string($order['payment_session_id'] ?? null) && $order['payment_session_id'] !== '') {
            if ($order['payment_session_id'] !== $session) {
                db_update('donations', ['gateway_session_id' => mb_substr($order['payment_session_id'], 0, 512)], 'id = :id', ['id' => (int) $donation['id']]);
            }
            return ['session' => (string) $order['payment_session_id'], 'paid' => false];
        }
    }
    if ($order === null && $orderId !== '' && $session !== '') {
        return ['session' => $session, 'paid' => false];   // Cashfree not reachable right now: try the stored session
    }
    // Expired / terminated (or unknown) order: start a new order for the same donation.
    $campaignTitle = $donation['campaign_id'] ? (string) db_value('SELECT title FROM campaigns WHERE id = ?', [$donation['campaign_id']]) : '';
    $new = cashfree_create_order($donation, $campaignTitle);
    if (!$new) {
        return null;
    }
    db_update('donations', ['gateway_order_id' => $new['order_id'], 'gateway_session_id' => $new['session']], 'id = :id', ['id' => (int) $donation['id']]);
    log_activity('donation_order_renewed', 'Donation ' . $donation['donation_ref'] . ': new Cashfree order ' . $new['order_id'] . ' (previous order ' . ($orderId !== '' ? $orderId : '-') . ' expired)');
    cashfree_remember_order($new['order_id']);
    return ['session' => $new['session'], 'paid' => false];
}

/**
 * Gross / refunded amounts of a successful Cashfree donation, in the shape apply_gateway_refund_state() expects:
 * ['id' => cf_payment_id, 'amount' => gross paise, 'amount_refunded' => paise, 'refunds' => [...]]. Refunds that
 * are SUCCESS, PENDING or ONHOLD count as refunded (the money is on its way back); CANCELLED / FAILED do not.
 */
function cashfree_refund_state(array $donation): ?array
{
    if (empty($donation['gateway_order_id']) || empty($donation['gateway_payment_id'])) {
        return null;
    }
    $payments = cashfree_order_payments((string) $donation['gateway_order_id']);
    $refunds = cashfree_order_refunds((string) $donation['gateway_order_id']);
    if ($payments === null || $refunds === null) {
        return null;
    }
    $gross = 0;
    foreach ($payments as $p) {
        if ((string) ($p['cf_payment_id'] ?? '') === (string) $donation['gateway_payment_id'] && ($p['payment_status'] ?? '') === 'SUCCESS') {
            $gross = to_paise($p['payment_amount'] ?? 0);
        }
    }
    if ($gross <= 0) {
        return null;
    }
    $refunded = 0;
    foreach ($refunds as $r) {
        if (in_array(strtoupper((string) ($r['refund_status'] ?? '')), ['SUCCESS', 'PENDING', 'ONHOLD'], true)
            && (empty($r['cf_payment_id']) || (string) $r['cf_payment_id'] === (string) $donation['gateway_payment_id'])) {
            $refunded += to_paise($r['refund_amount'] ?? 0);
        }
    }
    return ['id' => (string) $donation['gateway_payment_id'], 'amount' => $gross, 'amount_refunded' => $refunded, 'refunds' => $refunds];
}

/**
 * Refund what is left of a Cashfree donation (donations.amount = net amount kept).
 * Returns ['ok' => bool, 'refund' => ['id' => refund_id, 'status' => ...]|null, 'error' => string].
 */
function cashfree_refund(array $donation, string $reason = ''): array
{
    if (empty($donation['gateway_order_id']) || empty($donation['gateway_payment_id'])) {
        return ['ok' => false, 'refund' => null, 'error' => 'This donation has no Cashfree payment.'];
    }
    $state = cashfree_refund_state($donation);
    if (!$state) {
        return ['ok' => false, 'refund' => null, 'error' => 'Cashfree did not return the payment and its refunds. Please try again later.'];
    }
    $amountPaise = min(to_paise($donation['amount']), $state['amount'] - $state['amount_refunded']);
    if ($amountPaise <= 0) {
        return ['ok' => false, 'refund' => null, 'error' => 'Cashfree shows nothing left to refund for this payment. Use "Check refunds with Cashfree" to update the donation.'];
    }
    $refundId = 'rf_' . substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $donation['donation_ref']), 0, 24) . '_' . bin2hex(random_bytes(3));
    $note = trim(preg_replace('/[^A-Za-z0-9 .,\-\/]/', ' ', $reason));
    [$status, $refund] = cashfree_request('POST', 'orders/' . rawurlencode((string) $donation['gateway_order_id']) . '/refunds', [
        'refund_amount' => round($amountPaise / 100, 2),
        'refund_id'     => $refundId,
        'refund_note'   => mb_substr($note !== '' ? $note : 'Donation refund', 0, 100),
        'refund_speed'  => 'STANDARD',
    ]);
    if ($status === 200 && is_array($refund) && ($refund['refund_id'] ?? '') === $refundId
        && !in_array(strtoupper((string) ($refund['refund_status'] ?? '')), ['CANCELLED', 'FAILED'], true)) {
        return ['ok' => true, 'refund' => ['id' => $refundId, 'status' => strtolower((string) ($refund['refund_status'] ?? 'pending')), 'amount' => $amountPaise], 'error' => ''];
    }
    $msg = is_array($refund) ? clean_string((string) ($refund['message'] ?? '')) : '';
    return ['ok' => false, 'refund' => null, 'error' => $msg !== '' ? mb_substr($msg, 0, 200) : 'The payment gateway did not accept the refund (HTTP ' . $status . ').'];
}

/** Webhook signature: base64(HMAC-SHA256(timestamp . rawBody, secret key)). */
function cashfree_webhook_signature_valid(string $payload, string $timestamp, string $signature): bool
{
    $secret = cashfree_secret_key();
    if ($secret === '' || $payload === '' || $timestamp === '' || $signature === '') {
        return false;
    }
    return hash_equals(base64_encode(hash_hmac('sha256', $timestamp . $payload, $secret, true)), $signature);
}

/** Timestamp header (milliseconds or seconds) within CASHFREE_WEBHOOK_MAX_AGE of now. */
function cashfree_webhook_timestamp_fresh(string $timestamp): bool
{
    if (!preg_match('/^\d{9,14}$/', $timestamp)) {
        return false;
    }
    $ts = strlen($timestamp) >= 12 ? (int) floor(((int) $timestamp) / 1000) : (int) $timestamp;
    return abs(time() - $ts) <= CASHFREE_WEBHOOK_MAX_AGE;
}

// ---------------------------------------------------------------------
// "Test connection" (Admin > Payment Gateways)
// ---------------------------------------------------------------------

/** Authenticated read-only call to Razorpay. Returns ['ok' => bool, 'message' => text]. */
function razorpay_test_connection(): array
{
    if (!razorpay_configured()) {
        return ['ok' => false, 'message' => 'Enter and save the Key ID and Key Secret first.'];
    }
    [$status] = razorpay_request('GET', 'orders?count=1');
    if ($status === 200) {
        return ['ok' => true, 'message' => 'Connected: Razorpay accepted the keys (' . (razorpay_mode() === 'live' ? 'LIVE mode - real payments' : 'TEST mode - no real money') . ').'];
    }
    if ($status === 401) {
        return ['ok' => false, 'message' => 'Razorpay rejected the keys (HTTP 401). Check that the Key ID and Key Secret belong together and to the same mode (test or live).'];
    }
    if ($status === 0) {
        return ['ok' => false, 'message' => 'Could not reach Razorpay from this server (network or SSL problem). Try again in a few minutes.'];
    }
    return ['ok' => false, 'message' => 'Razorpay answered with HTTP ' . $status . '. Try again later or check the Razorpay status page.'];
}

/** Authenticated GET of a random, non-existent order: 404 = keys accepted, 401 = wrong keys. */
function cashfree_test_connection(): array
{
    if (!cashfree_configured()) {
        return ['ok' => false, 'message' => 'Enter and save the App ID and Secret Key first.'];
    }
    [$status] = cashfree_request('GET', 'orders/pwf_conncheck_' . bin2hex(random_bytes(6)));
    $env = cashfree_env() === 'production' ? 'PRODUCTION - real payments' : 'SANDBOX - no real money';
    if ($status === 404 || $status === 200) {
        return ['ok' => true, 'message' => 'Connected: Cashfree accepted the keys (' . $env . ').'];
    }
    if ($status === 401 || $status === 403) {
        return ['ok' => false, 'message' => 'Cashfree rejected the keys (HTTP ' . $status . '). Check the App ID and Secret Key, and that they match the selected environment (' . cashfree_env() . ').'];
    }
    if ($status === 0) {
        return ['ok' => false, 'message' => 'Could not reach Cashfree from this server (network or SSL problem). Try again in a few minutes.'];
    }
    return ['ok' => false, 'message' => 'Cashfree answered with HTTP ' . $status . '. Try again later.'];
}

/** Last "Test connection" result stored for the admin page: ['ok' => bool, 'message' => ..., 'at' => datetime] or null. */
function gateway_last_test(string $gateway): ?array
{
    $v = json_decode(setting($gateway . '_last_test'), true);
    return is_array($v) && isset($v['ok'], $v['message'], $v['at']) ? $v : null;
}

function gateway_save_test(string $gateway, array $result): void
{
    save_setting($gateway . '_last_test', json_encode(['ok' => (bool) $result['ok'], 'message' => (string) $result['message'], 'at' => date('Y-m-d H:i:s')]));
}

// ---------------------------------------------------------------------
// Dashboard set-up checklist + Security Centre (includes/home.php calls these)
// ---------------------------------------------------------------------

/** At least one gateway is offered and each offered gateway can confirm payments in the background. */
function payment_setup_complete(): bool
{
    $active = active_gateways();
    if (!$active) {
        return false;
    }
    return !in_array('razorpay', $active, true) || razorpay_webhook_secret() !== '';
}

/** Health checks: list of [status ok|warn|bad, title, detail, fix]. */
function payment_health_checks(): array
{
    $checks = [];
    $where = 'Admin > Payment Gateways';
    foreach (['razorpay_key_secret', 'razorpay_webhook_secret', 'cashfree_secret_key'] as $name) {
        if (secret_unreadable($name)) {
            $checks[] = ['bad', 'Payment secrets readable', 'A saved payment secret can no longer be decrypted (APP_KEY or the database credentials in pwf-config.php changed).', 'Enter the secret again in ' . $where . '.'];
            break;
        }
    }
    $active = active_gateways();
    if (!$active) {
        $off = setting('online_donations_enabled', '1') !== '1';
        $checks[] = ['warn', 'Online payments', $off ? 'Online donations are switched off in Settings > Donations & bank.' : 'No payment gateway has its keys - online donations are unavailable.',
            $off ? 'Switch online donations on once a gateway is set up.' : 'Enter the Razorpay or Cashfree keys in ' . $where . ' once the merchant account is approved.'];
        return $checks;
    }
    if (in_array('razorpay', $active, true)) {
        $mode = razorpay_mode();
        if ($mode === 'test') {
            $checks[] = ['warn', 'Online payments (Razorpay)', 'Razorpay is in TEST mode - no real money is collected.', 'Enter live keys (rzp_live_…) in ' . $where . ' when you are ready to accept donations.'];
        } elseif ($mode === 'live') {
            $checks[] = ['ok', 'Online payments (Razorpay)', 'Live Razorpay keys are configured.', ''];
        } else {
            $checks[] = ['bad', 'Online payments (Razorpay)', 'The Razorpay Key ID does not look like a Razorpay key (rzp_test_… or rzp_live_…).', 'Check the Key ID in ' . $where . '.'];
        }
        $hook = razorpay_webhook_secret() !== '';
        $checks[] = [$hook ? 'ok' : 'warn', 'Razorpay webhook secret', $hook ? 'Webhook secret is set (payments are confirmed even if the donor closes the browser).' : 'No Razorpay webhook secret configured.',
            $hook ? '' : 'In the Razorpay dashboard add the webhook ' . abs_url('payment-webhook.php') . ' and enter its secret in ' . $where . '.'];
    }
    if (in_array('cashfree', $active, true)) {
        $prod = cashfree_env() === 'production';
        $testKey = stripos(cashfree_app_id(), 'TEST') === 0 || strpos(cashfree_secret_key(), '_test_') !== false;
        if ($prod && $testKey) {
            $checks[] = ['bad', 'Online payments (Cashfree)', 'Cashfree is set to Production but the keys look like sandbox (test) keys.', 'Enter the Production App ID and Secret Key in ' . $where . '.'];
        } else {
            $checks[] = [$prod ? 'ok' : 'warn', 'Online payments (Cashfree)', $prod ? 'Cashfree Production keys are configured.' : 'Cashfree is in SANDBOX (test) mode - no real money is collected.',
                $prod ? '' : 'Switch the environment to Production and enter the Production keys in ' . $where . ' when you are ready.'];
        }
        $seen = (int) db_value("SELECT COUNT(*) FROM payment_webhook_events WHERE gateway = 'cashfree'") > 0;
        $checks[] = [$seen ? 'ok' : 'warn', 'Cashfree webhook', $seen ? 'Signed Cashfree notifications are being received.' : 'No signed Cashfree notification has been received yet.',
            $seen ? '' : 'Add ' . abs_url('cashfree-webhook.php') . ' in the Cashfree dashboard (Developers > Webhooks). This turns green after the first notification.'];
    }
    return $checks;
}

// =====================================================================
// Migrations (200-299 payments)
// =====================================================================

/** Payment Gateways admin page: settings, permission, Cashfree session column, privacy disclosure. */
function migration_201_payment_gateways(): void
{
    // donations.gateway already exists (razorpay | offline); Cashfree donations use 'cashfree'.
    add_column_if_missing('donations', 'gateway', "VARCHAR(30) NOT NULL DEFAULT 'razorpay' AFTER payment_method");
    // Cashfree payment_session_id (opens the checkout again for "Try again"); not secret.
    add_column_if_missing('donations', 'gateway_session_id', 'VARCHAR(512) DEFAULT NULL AFTER gateway_order_id');
    add_index_if_missing('donations', 'idx_donations_gateway', 'INDEX idx_donations_gateway (gateway)');
    // Offline rows that still carry the column default are labelled correctly; online rows (all Razorpay so far) keep 'razorpay'.
    db_query("UPDATE donations SET gateway = 'offline' WHERE payment_method <> 'online' AND gateway = 'razorpay'
              AND gateway_order_id IS NULL AND gateway_payment_id IS NULL");

    add_setting_if_missing('payment_gateway', 'razorpay');
    add_setting_if_missing('razorpay_key_id', '');
    add_setting_if_missing('cashfree_app_id', '');
    add_setting_if_missing('cashfree_env', 'sandbox');

    add_permission_if_missing('payments.configure', 'Configure payment gateways (keys, webhooks)', 'Fundraising', 37);

    // Privacy policy: disclosure for Cashfree as a second payment processor (new row; existing rows untouched).
    $pageId = (int) db_value("SELECT id FROM pages WHERE slug = 'privacy-policy'");
    if ($pageId && !db_value('SELECT id FROM page_sections WHERE page_id = ? AND anchor = ?', [$pageId, 'cashfree-payments'])) {
        $after = (int) db_value("SELECT sort_order FROM page_sections WHERE page_id = ? AND title = 'Payments' ORDER BY sort_order LIMIT 1", [$pageId]);
        db_insert('page_sections', [
            'page_id'      => $pageId,
            'section_type' => 'richtext',
            'anchor'       => 'cashfree-payments',
            'title'        => 'Payments through Cashfree',
            'body'         => '<p>Depending on the options shown on the donation page, an online donation may also be processed by <strong>Cashfree Payments</strong> (Cashfree Payments India Private Limited). If you choose Cashfree, you enter your card, UPI or bank details directly in Cashfree\'s secure checkout. <strong>The foundation never sees or stores your card number, CVV, UPI PIN or net banking password.</strong></p>'
                . '<p>To start the payment we share your name, email address, mobile number (Cashfree requires a mobile number for every payment), the amount and the donation reference with Cashfree. We receive back only what is needed to confirm and record your donation, such as the payment reference, amount, status and the type of payment method. Cashfree processes payment data under its own privacy policy and the regulations that apply to payment providers.</p>',
            'background'   => 'white',
            'status'       => 'visible',
            'sort_order'   => ($after ?: 50) + 5,
        ]);
    }
}
