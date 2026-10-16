<?php
/**
 * Fundraising module (M3): campaigns, donations, Razorpay / Cashfree payments, receipts, refunds,
 * utilisation and transparency figures, and the CMS section types campaigns_grid,
 * donation_appeal and transparency_summary.
 *
 * Honesty rules built in:
 *  - every public figure comes from donations with status = 'success' (verified on the server
 *    with the payment gateway, or recorded by the finance team after the money was received);
 *  - a countdown is shown only for a real end date in the future;
 *  - registration / 80G / bank details are shown only when entered in Admin > Settings.
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** Largest single online donation accepted by the website (₹5,00,000). */
const DONATION_MAX_AMOUNT = 500000;

/** Offline / manual payment methods (donations.payment_method). */
const DONATION_METHODS = [
    'online' => 'Online payment', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'upi' => 'UPI (direct)', 'cash' => 'Cash',
];

/** Public campaign statuses (others are only visible to staff). */
const CAMPAIGN_PUBLIC_STATUSES = ['active', 'completed', 'closed'];

/** Expense categories offered in the admin (free text is not allowed so reports stay consistent). */
const EXPENSE_CATEGORIES = [
    'Relief material', 'Food & rations', 'Hygiene kits', 'Drinking water', 'Shelter material', 'Medical support',
    'Education material', 'Transport & logistics', 'Venue & events', 'Training & facilitation', 'Volunteer support', 'Other',
];

// ---------------------------------------------------------------------
// Campaign data
// ---------------------------------------------------------------------

/** Base query: campaigns with amount raised from VERIFIED donations only (alias c, columns raised + donor_count). */
function campaign_sql(): string
{
    return "SELECT c.*, COALESCE(d.raised, 0) AS raised, COALESCE(d.donor_count, 0) AS donor_count
            FROM campaigns c
            LEFT JOIN (
                SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS donor_count
                FROM donations WHERE status = 'success' AND campaign_id IS NOT NULL
                GROUP BY campaign_id
            ) d ON d.campaign_id = c.id";
}

function campaign_percent(array $c): int
{
    if ((float) ($c['goal_amount'] ?? 0) <= 0) {
        return 0;
    }
    return (int) min(100, floor(((float) ($c['raised'] ?? 0) / (float) $c['goal_amount']) * 100));
}

/** Labels for campaign categories: general fund + programme slugs + relief categories + any category in use. */
function campaign_categories(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = ['general' => 'General Fund'];
    try {
        foreach (db_rows("SELECT slug, title FROM programs WHERE status = 'published' ORDER BY sort_order, title") as $p) {
            $list[$p['slug']] = $p['title'];
        }
    } catch (Throwable $e) {
        error_log('campaign_categories: ' . $e->getMessage());
    }
    $list += ['disaster-relief' => 'Disaster Relief', 'food-distribution' => 'Food Distribution', 'education-literacy' => 'Education & Literacy', 'healthcare' => 'Health & Medical'];
    try {
        foreach (db_rows('SELECT DISTINCT category FROM campaigns') as $r) {
            if (!isset($list[$r['category']])) {
                $list[$r['category']] = humanize($r['category']);
            }
        }
    } catch (Throwable $e) {
        error_log('campaign_categories: ' . $e->getMessage());
    }
    return $list;
}

function campaign_category_label(?string $category): string
{
    $all = campaign_categories();
    return $all[(string) $category] ?? humanize((string) $category);
}

/** Campaign image or an illustration that matches its category. */
function campaign_image(array $c): string
{
    $cat = (string) ($c['category'] ?? 'general');
    if (function_exists('program_art')) {
        $fallback = program_art(['slug' => $cat], false);   // programme illustration (M2)
    } else {
        $map = ['education-literacy' => 'education', 'food-distribution' => 'disaster-relief', 'environment-protection' => 'environment'];
        $art = $map[$cat] ?? $cat;
        $fallback = is_file(ROOT_PATH . '/assets/images/programs/' . $art . '.svg') ? 'assets/images/programs/' . $art . '.svg' : 'assets/images/programs/general.svg';
    }
    return image_url($c['image'] ?? null, $fallback);
}

/** Absolute URL of the campaign's own uploaded image ('' when it has none). */
function campaign_image_abs(array $c): string
{
    $img = (string) ($c['image'] ?? '');
    return ($img !== '' && strpos($img, '..') === false && is_file(ROOT_PATH . '/' . $img)) ? abs_url($img) : '';
}

function campaign_days_left(array $c): ?int
{
    if (empty($c['end_date'])) {
        return null;
    }
    $diff = (int) floor((strtotime($c['end_date'] . ' 23:59:59') - time()) / 86400);
    return max(0, $diff);
}

/** Is the end date in the future (campaign still running)? */
function campaign_has_future_end(array $c): bool
{
    return !empty($c['end_date']) && strtotime($c['end_date'] . ' 23:59:59') > time();
}

function campaign_is_public(array $c): bool
{
    return in_array($c['status'] ?? '', CAMPAIGN_PUBLIC_STATUSES, true);
}

/** Active campaigns accept donations (end date passed = no longer accepting). */
function campaign_accepts_donations(array $c): bool
{
    return ($c['status'] ?? '') === 'active' && (empty($c['end_date']) || campaign_has_future_end($c));
}

/** Short status line for cards. */
function campaign_status_text(array $c): string
{
    if (($c['status'] ?? '') === 'completed') {
        return 'Completed';
    }
    if (($c['status'] ?? '') === 'closed') {
        return 'Closed';
    }
    if (!empty($c['end_date']) && !campaign_has_future_end($c)) {
        return 'Ended ' . format_date($c['end_date']);
    }
    return 'Accepting donations';
}

/** Campaign card for grids (verified progress, real countdown only, tags). */
function campaign_card(array $c, int $delay = 0): string
{
    $link = campaign_url($c['slug']);
    $raised = (float) ($c['raised'] ?? 0);
    $goal = (float) $c['goal_amount'];
    $html = '<article class="campaign-card fr-card reveal tilt" style="--d:' . ($delay % 4) . '">';
    $html .= '<a class="campaign-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(campaign_image($c)) . '"' . image_srcset_attr($c['image'] ?? null, '(max-width: 600px) 100vw, (max-width: 1100px) 50vw, 400px') . ' alt="" loading="lazy" width="480" height="300">';
    $html .= '<span class="tag tag-cat">' . e(campaign_category_label($c['category'])) . '</span>';
    if (!empty($c['is_urgent']) && campaign_accepts_donations($c)) {
        $html .= '<span class="tag tag-urgent">Urgent</span>';
    } elseif (!empty($c['is_featured'])) {
        $html .= '<span class="tag tag-featured">Featured</span>';
    }
    $html .= '</a><div class="campaign-body">';
    if (!empty($c['location'])) {
        $html .= '<span class="fr-card-loc">' . icon('map-pin') . e($c['location']) . '</span>';
    }
    $html .= '<h3><a href="' . e($link) . '">' . e($c['title']) . '</a></h3>';
    $html .= '<p>' . e(excerpt($c['short_description'], 130)) . '</p>';
    $html .= ui_progress($raised, $goal, (int) ($c['donor_count'] ?? 0));
    if (($c['status'] ?? '') === 'active' && campaign_has_future_end($c)) {
        $html .= ui_countdown($c['end_date'], 'Ends in');
    }
    $html .= '<div class="campaign-meta"><span>' . icon('users') . (int) ($c['donor_count'] ?? 0) . ' verified ' . ((int) ($c['donor_count'] ?? 0) === 1 ? 'donation' : 'donations') . '</span>'
        . '<span>' . icon(campaign_accepts_donations($c) ? 'heart' : 'check-circle') . e(campaign_status_text($c)) . '</span></div>';
    $html .= '<div class="campaign-actions">';
    if (campaign_accepts_donations($c)) {
        $html .= '<a class="btn btn-donate btn-sm" href="' . e(donate_url(['campaign' => (int) $c['id']])) . '">' . icon('heart') . 'Donate</a>';
    }
    $html .= '<a class="btn btn-outline btn-sm" href="' . e($link) . '">View details<span class="sr-only">: ' . e($c['title']) . '</span></a></div>';
    return $html . '</div></article>';
}

/** Large "featured" card used at the top of the campaign list. */
function campaign_feature_card(array $c): string
{
    $link = campaign_url($c['slug']);
    $html = '<article class="fr-feature reveal">'
        . '<a class="fr-feature-media" href="' . e($link) . '" tabindex="-1" aria-hidden="true"><img src="' . e(campaign_image($c)) . '" alt="" width="640" height="420"></a>'
        . '<div class="fr-feature-body"><div class="fr-tags"><span class="badge badge-gold">' . icon('star') . 'Featured campaign</span>'
        . '<span class="badge badge-green">' . e(campaign_category_label($c['category'])) . '</span>'
        . (!empty($c['is_urgent']) && campaign_accepts_donations($c) ? '<span class="badge badge-red">Urgent</span>' : '') . '</div>'
        . '<h2><a href="' . e($link) . '">' . e($c['title']) . '</a></h2>'
        . '<p class="lead">' . e(excerpt($c['short_description'], 220)) . '</p>';
    $meta = [];
    if (!empty($c['location'])) {
        $meta[] = icon('map-pin') . e($c['location']);
    }
    if (!empty($c['start_date'])) {
        $meta[] = icon('calendar') . 'Started ' . e(format_date($c['start_date']));
    }
    if ($meta) {
        $html .= '<p class="fr-meta-line"><span>' . implode('</span><span>', $meta) . '</span></p>';
    }
    $html .= ui_progress((float) $c['raised'], (float) $c['goal_amount'], (int) $c['donor_count'], 'lg');
    if (campaign_has_future_end($c) && $c['status'] === 'active') {
        $html .= ui_countdown($c['end_date']);
    }
    $html .= '<div class="btn-row">';
    if (campaign_accepts_donations($c)) {
        $html .= '<a class="btn btn-donate" href="' . e(donate_url(['campaign' => (int) $c['id']])) . '">' . icon('heart') . 'Donate to this campaign</a>';
    }
    $html .= '<a class="btn btn-outline" href="' . e($link) . '">Read more</a></div></div></article>';
    return $html;
}

// ---------------------------------------------------------------------
// Donation settings & helpers
// ---------------------------------------------------------------------
function donation_min_amount(): int
{
    return max(1, min(DONATION_MAX_AMOUNT, (int) setting('donation_min_amount', '100')));
}

/** Suggested amounts from settings (valid, within min/max, max 6). */
function donation_presets(): array
{
    $min = donation_min_amount();
    $list = [];
    foreach (explode(',', setting('donation_presets', '500,1000,2500,5000')) as $v) {
        $n = (int) preg_replace('/\D/', '', $v);
        if ($n >= $min && $n <= DONATION_MAX_AMOUNT && !in_array($n, $list, true)) {
            $list[] = $n;
        }
    }
    return array_slice($list ?: [max($min, 500), max($min, 1000), max($min, 2500)], 0, 6);
}

/** Online donations are possible: enabled in settings AND at least one chosen gateway has its keys (includes/gateways.php). */
function online_donations_available(): bool
{
    return (bool) active_gateways();
}

/** Bank / UPI details entered in Admin > Settings (only non-empty values). */
function bank_transfer_details(): array
{
    $details = [];
    foreach (['bank_account_name' => 'Account name', 'bank_name' => 'Bank', 'bank_account_number' => 'Account number', 'bank_ifsc' => 'IFSC',
                 'bank_branch' => 'Branch', 'upi_id' => 'UPI ID'] as $key => $label) {
        if (trim(setting($key)) !== '') {
            $details[$label] = trim(setting($key));
        }
    }
    // Bank details are only useful with at least an account number + IFSC, or a UPI ID
    if (!isset($details['UPI ID']) && !(isset($details['Account number']) && isset($details['IFSC']))) {
        return [];
    }
    return $details;
}

/** Totals for public counters - verified (status = success) data only. */
function verified_donation_totals(): array
{
    static $t = null;
    if ($t === null) {
        $row = db_row("SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n, COUNT(DISTINCT campaign_id) AS campaigns FROM donations WHERE status = 'success'");
        $t = [
            'total'     => (float) $row['total'],
            'count'     => (int) $row['n'],
            'campaigns' => (int) $row['campaigns'],
            'receipts'  => (int) db_value("SELECT COUNT(*) FROM donation_receipts r JOIN donations d ON d.id = r.donation_id WHERE r.status = 'issued' AND d.status = 'success'"),
        ];
    }
    return $t;
}

/** Donor name for public display: first name and last initial (e.g. "Ravi K."). */
function public_donor_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if (count($parts) < 2) {
        return $parts[0] ?? 'Donor';
    }
    return $parts[0] . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.';
}

/** PAN partly masked for display (ABCDE1234F => ABCXXXX34F). */
function donation_mask_pan(?string $pan): string
{
    $pan = strtoupper(trim((string) $pan));
    if (strlen($pan) !== 10) {
        return $pan;
    }
    return substr($pan, 0, 3) . 'XXXX' . substr($pan, 7);
}

/** Gateway of an online donation row ('razorpay' for every online row created before Cashfree was added). */
function donation_gateway(array $d): string
{
    return ($d['gateway'] ?? '') === 'cashfree' ? 'cashfree' : 'razorpay';
}

/** Payment method reported by the gateway (upi, card, netbanking, credit_card ...) as a readable label. */
function gateway_method_label(?string $method): string
{
    $m = strtolower(trim((string) $method));
    $map = ['upi' => 'UPI', 'card' => 'Card', 'credit_card' => 'Credit card', 'debit_card' => 'Debit card', 'prepaid_card' => 'Prepaid card',
        'netbanking' => 'Net banking', 'net_banking' => 'Net banking', 'wallet' => 'Wallet', 'app' => 'Wallet', 'emi' => 'EMI',
        'cardless_emi' => 'Cardless EMI', 'credit_card_emi' => 'Card EMI', 'debit_card_emi' => 'Card EMI', 'paylater' => 'Pay later',
        'pay_later' => 'Pay later', 'bank_transfer' => 'Bank transfer'];
    return $map[$m] ?? ($m !== '' ? humanize($m) : '');
}

function donation_method_label(array $d): string
{
    $label = DONATION_METHODS[$d['payment_method']] ?? humanize($d['payment_method']);
    if ($d['payment_method'] === 'online') {
        $via = ' via ' . gateway_label(donation_gateway($d));
        $label = 'Online' . (!empty($d['gateway_method']) ? ' (' . gateway_method_label((string) $d['gateway_method']) . $via . ')' : $via);
    }
    return $label;
}

/** Reference shown to donors, e.g. PWFD261015A1B2C3D4 (D = online, O = offline). */
function new_donation_ref(string $prefix = 'PWFD'): string
{
    do {
        $ref = $prefix . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
    } while (db_value('SELECT id FROM donations WHERE donation_ref = ?', [$ref]));
    return $ref;
}

/** Load a donation by its public reference + secret token (constant-time token check). */
function donation_by_ref_token(string $ref, string $token): ?array
{
    if ($ref === '' || $token === '' || !preg_match('/^[A-Z0-9]{6,40}$/', $ref) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $d = db_row('SELECT * FROM donations WHERE donation_ref = ?', [$ref]);
    return ($d && hash_equals($d['access_token'], $token)) ? $d : null;
}

function donation_link_query(array $d): array
{
    return ['ref' => $d['donation_ref'], 'token' => $d['access_token']];
}

/** Private receipt link (ref + secret token). Pass $absolute for emails. */
function donation_receipt_url(array $donation, bool $absolute = false): string
{
    return $absolute ? abs_page_url('donation-receipt', donation_link_query($donation)) : page_url('donation-receipt', donation_link_query($donation));
}

function donation_success_url(array $donation): string
{
    return page_url('donation-success', donation_link_query($donation));
}

function donation_failed_url(array $donation): string
{
    return page_url('donation-failed', donation_link_query($donation));
}

/** Payment step for an existing order (also used for "try again"). */
function donation_pay_url(array $donation): string
{
    return donate_url(['pay' => $donation['donation_ref'], 'token' => $donation['access_token']]);
}

// ---------------------------------------------------------------------
// Razorpay - every verification happens on the server
// ---------------------------------------------------------------------
// Keys: razorpay_key_id(), razorpay_key_secret(), razorpay_webhook_secret(), razorpay_configured() and
// payment_test_mode() live in includes/gateways.php (pwf-config.php constant, else the admin panel value).

/** Call the Razorpay REST API. Returns [httpStatus, decodedBody|null]. */
function razorpay_request(string $method, string $endpoint, array $data = []): array
{
    if (!razorpay_configured() || !function_exists('curl_init')) {
        return [0, null];
    }
    $ch = curl_init(rtrim(RAZORPAY_API_BASE, '/') . '/' . ltrim($endpoint, '/'));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => razorpay_key_id() . ':' . razorpay_key_secret(),
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data ?: new stdClass());
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        error_log('Razorpay request failed (' . $endpoint . '): ' . curl_error($ch));
        curl_close($ch);
        return [0, null];
    }
    curl_close($ch);
    $decoded = json_decode((string) $body, true);
    return [$status, is_array($decoded) ? $decoded : null];
}

/** Paise as integer (Razorpay uses the smallest currency unit). */
function to_paise($amount): int
{
    return (int) round(((float) $amount) * 100);
}

/** Indian financial year of a date: 2026-27 (April to March). */
function financial_year(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    $y = (int) date('Y', $ts);
    $start = (int) date('n', $ts) >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string) ($start + 1), -2);
}

/** Create the gateway order for a pending online donation. Returns the order id or null. */
function razorpay_create_order(array $donation, string $campaignTitle = ''): ?string
{
    [$status, $order] = razorpay_request('POST', 'orders', [
        'amount'   => to_paise($donation['amount']),
        'currency' => $donation['currency'] ?: 'INR',
        'receipt'  => $donation['donation_ref'],
        'notes'    => ['donation_ref' => $donation['donation_ref'], 'purpose' => mb_substr($campaignTitle !== '' ? $campaignTitle : 'General donation', 0, 250)],
    ]);
    if ($status !== 200 || empty($order['id']) || (int) ($order['amount'] ?? 0) !== to_paise($donation['amount'])
        || !preg_match('/^order_[A-Za-z0-9]+$/', (string) $order['id'])) {
        error_log('Razorpay order creation failed for ' . $donation['donation_ref'] . ': HTTP ' . $status);
        return null;
    }
    return (string) $order['id'];
}

/** HMAC check of the Checkout response: sha256(order_id|payment_id, key secret). */
function razorpay_checkout_signature_valid(string $orderId, string $paymentId, string $signature): bool
{
    $secret = razorpay_key_secret();
    if ($orderId === '' || $paymentId === '' || $signature === '' || $secret === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $orderId . '|' . $paymentId, $secret), $signature);
}

/** Webhook signature: sha256 HMAC of the raw body with the webhook secret. */
function razorpay_webhook_signature_valid(string $payload, string $signature): bool
{
    $secret = razorpay_webhook_secret();
    return $secret !== '' && $signature !== ''
        && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
}

/** Fetch a payment from Razorpay (never trust what the browser or webhook body says). */
function razorpay_fetch_payment(string $paymentId): ?array
{
    if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) {
        return null;
    }
    [$status, $payment] = razorpay_request('GET', 'payments/' . rawurlencode($paymentId));
    return ($status === 200 && is_array($payment) && ($payment['id'] ?? '') === $paymentId) ? $payment : null;
}

/** Does a gateway payment exactly match this donation's order, amount and currency, and is it captured? */
function razorpay_payment_matches(array $payment, array $donation): bool
{
    return ($payment['status'] ?? '') === 'captured'
        && !empty($donation['gateway_order_id'])
        && ($payment['order_id'] ?? '') === $donation['gateway_order_id']
        && (int) ($payment['amount'] ?? -1) === to_paise($donation['amount'])
        && strtoupper((string) ($payment['currency'] ?? '')) === strtoupper((string) $donation['currency']);
}

/**
 * Verify a payment for a donation with the gateway: fetch it, capture it if only authorised
 * (exact amount), and check order/amount/currency. Returns the captured payment or null.
 */
function razorpay_verify_payment_for_donation(string $paymentId, array $donation): ?array
{
    $payment = razorpay_fetch_payment($paymentId);
    if (!$payment || ($payment['order_id'] ?? '') !== $donation['gateway_order_id']) {
        return null;
    }
    if (($payment['status'] ?? '') === 'authorized' && (int) ($payment['amount'] ?? -1) === to_paise($donation['amount'])
        && strtoupper((string) ($payment['currency'] ?? '')) === strtoupper((string) $donation['currency'])) {
        [$status, $captured] = razorpay_request('POST', 'payments/' . rawurlencode($paymentId) . '/capture', [
            'amount' => to_paise($donation['amount']), 'currency' => $donation['currency'],
        ]);
        if ($status === 200 && is_array($captured)) {
            $payment = $captured;
        }
    }
    return razorpay_payment_matches($payment, $donation) ? $payment : null;
}

/**
 * Ask Razorpay whether an order has a captured payment of the exact amount (admin re-check, order.paid webhook).
 * An authorised but not yet captured payment of the exact amount and currency is captured here, so that a donor
 * who closed the browser before the verify step is not auto-refunded by the gateway. Returns the payment or null.
 */
function razorpay_find_captured_payment(array $donation): ?array
{
    if (empty($donation['gateway_order_id']) || !razorpay_configured()) {
        return null;
    }
    [$status, $body] = razorpay_request('GET', 'orders/' . rawurlencode($donation['gateway_order_id']) . '/payments');
    if ($status !== 200 || empty($body['items']) || !is_array($body['items'])) {
        return null;
    }
    // An already captured payment wins, so nothing is ever captured twice.
    foreach ($body['items'] as $payment) {
        if (is_array($payment) && razorpay_payment_matches($payment, $donation)) {
            return $payment;
        }
    }
    foreach ($body['items'] as $payment) {
        if (is_array($payment) && ($payment['status'] ?? '') === 'authorized'
            && ($payment['order_id'] ?? '') === $donation['gateway_order_id']
            && (int) ($payment['amount'] ?? -1) === to_paise($donation['amount'])
            && strtoupper((string) ($payment['currency'] ?? '')) === strtoupper((string) $donation['currency'])
            && preg_match('/^pay_[A-Za-z0-9]+$/', (string) ($payment['id'] ?? ''))) {
            $captured = razorpay_verify_payment_for_donation((string) $payment['id'], $donation);
            if ($captured) {
                return $captured;
            }
        }
    }
    return null;
}

/**
 * Refund the remaining amount through the Razorpay refunds API (donations.amount is the net amount the foundation
 * keeps, so after a partial refund it equals what is left). Returns ['ok' => bool, 'refund' => array|null, 'error' => string].
 */
function razorpay_refund(array $donation, string $reason = ''): array
{
    if (empty($donation['gateway_payment_id'])) {
        return ['ok' => false, 'refund' => null, 'error' => 'This donation has no gateway payment ID.'];
    }
    $amountPaise = to_paise($donation['amount']);
    $payment = razorpay_fetch_payment((string) $donation['gateway_payment_id']);
    if ($payment && isset($payment['amount'])) {
        $amountPaise = min($amountPaise, (int) $payment['amount'] - (int) ($payment['amount_refunded'] ?? 0));
    }
    if ($amountPaise <= 0) {
        return ['ok' => false, 'refund' => null, 'error' => 'Razorpay shows nothing left to refund for this payment. Use "Check refunds with Razorpay" to update the donation.'];
    }
    [$status, $refund] = razorpay_request('POST', 'payments/' . rawurlencode($donation['gateway_payment_id']) . '/refund', [
        'amount' => $amountPaise,
        'notes'  => ['donation_ref' => $donation['donation_ref'], 'reason' => mb_substr($reason, 0, 200)],
    ]);
    if ($status === 200 && !empty($refund['id']) && preg_match('/^rfnd_[A-Za-z0-9]+$/', (string) $refund['id'])) {
        return ['ok' => true, 'refund' => $refund, 'error' => ''];
    }
    $msg = is_array($refund) ? (string) ($refund['error']['description'] ?? '') : '';
    return ['ok' => false, 'refund' => null, 'error' => $msg !== '' ? $msg : 'The payment gateway did not accept the refund (HTTP ' . $status . ').'];
}

// ---------------------------------------------------------------------
// Donation state changes (idempotent - safe to call from checkout, webhook and admin)
// ---------------------------------------------------------------------

/**
 * Mark a donation successful after the payment was verified with the gateway.
 * Returns true if the donation is (now) successful. Issues the receipt, emails it once,
 * gives a signed-in donor the "donor" role and records the conversion.
 */
function mark_donation_success(int $donationId, array $payment, string $via, ?int $adminId = null): bool
{
    $pdo = db();
    $newlyConfirmed = false;
    $pdo->beginTransaction();
    try {
        $donation = db_row('SELECT * FROM donations WHERE id = ? FOR UPDATE', [$donationId]);
        if (!$donation || $donation['status'] === 'refunded') {
            $pdo->rollBack();
            return false;
        }
        if ($donation['status'] !== 'success') {
            db_update('donations', [
                'status'             => 'success',
                'gateway_payment_id' => $payment['id'] ?? $donation['gateway_payment_id'],
                'gateway_signature'  => isset($payment['signature']) ? mb_substr((string) $payment['signature'], 0, 255) : $donation['gateway_signature'],
                'gateway_method'     => isset($payment['method']) ? mb_substr((string) $payment['method'], 0, 40) : $donation['gateway_method'],
                'failure_reason'     => null,
                'paid_at'            => date('Y-m-d H:i:s', !empty($payment['created_at']) ? (int) $payment['created_at'] : time()),
                'verified_via'       => in_array($via, ['checkout', 'webhook', 'admin_recheck', 'offline'], true) ? $via : 'checkout',
                'verified_by'        => $adminId,
            ], 'id = :id', ['id' => $donationId]);
            $newlyConfirmed = true;
        }
        issue_receipt($donationId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('mark_donation_success failed: ' . $e->getMessage());
        throw $e;
    }

    if ($newlyConfirmed) {
        log_activity('donation_confirmed', 'Donation #' . $donationId . ' ' . $donation['donation_ref'] . ' confirmed via ' . $via, $adminId, $adminId !== null);
        if (!empty($donation['user_id'])) {
            assign_role((int) $donation['user_id'], 'donor');
            add_notification((int) $donation['user_id'], 'Donation received - thank you!',
                'Your donation of ' . money($donation['amount'], true) . ' (' . $donation['donation_ref'] . ') has been verified. Your receipt is ready.',
                donation_receipt_url($donation), 'success');
        }
        track_conversion('donation_success', $donationId, (float) $donation['amount']);
        send_receipt_email($donationId);
    }
    return true;
}

/** Record a failed attempt (only while the donation is still pending). */
function mark_donation_failed(int $donationId, string $reason): void
{
    db_query("UPDATE donations SET status = 'failed', failure_reason = ? WHERE id = ? AND status = 'pending'",
        [mb_substr($reason !== '' ? $reason : 'Payment failed', 0, 250), $donationId]);
}

/**
 * Refund bookkeeping: donation => refunded (removed from verified totals), receipt cancelled, note added.
 * The money itself is returned through the gateway (razorpay_refund) or the bank (offline).
 */
function mark_donation_refunded(int $donationId, ?string $refundId, string $note, ?int $adminId = null): bool
{
    $d = db_row('SELECT * FROM donations WHERE id = ?', [$donationId]);
    if (!$d || $d['status'] !== 'success') {
        return false;
    }
    $who = $adminId ? (string) db_value('SELECT name FROM users WHERE id = ?', [$adminId]) : 'payment gateway';
    db_update('donations', [
        'status'      => 'refunded',
        'refund_id'   => $refundId ? mb_substr($refundId, 0, 80) : $d['refund_id'],
        'refunded_at' => date('Y-m-d H:i:s'),
        'admin_notes' => trim(($d['admin_notes'] ?? '') . "\n[" . date('d M Y H:i') . '] Refunded (' . $who . '): ' . $note),
    ], 'id = :id AND status = :s', ['id' => $donationId, 's' => 'success']);
    cancel_receipt($donationId, 'Donation refunded');
    log_activity('donation_refunded', 'Donation #' . $donationId . ' ' . $d['donation_ref'] . ($refundId ? ' refund ' . $refundId : '') . ': ' . mb_substr($note, 0, 200), $adminId, $adminId !== null);
    return true;
}

/**
 * Bring a successful online donation in line with the refunds the gateway reports for its payment ($payment is
 * fetched from the API, never taken from a webhook body; for Cashfree it comes from cashfree_refund_state()). donations.amount is the net amount the foundation keeps:
 * - everything refunded: the donation becomes "refunded" and its receipt is cancelled;
 * - part refunded: the amount becomes gross - refunded (always set from the gateway's cumulative figure, so
 *   repeated or out-of-order events are safe), a note is added and the revised receipt is emailed.
 * Returns a short result for the webhook log / admin message.
 */
function apply_gateway_refund_state(array $donation, array $payment, ?string $refundId, string $via, ?int $adminId = null): string
{
    $grossPaise = (int) ($payment['amount'] ?? 0);
    $refundedPaise = (int) ($payment['amount_refunded'] ?? 0);
    if ($grossPaise <= 0 || (string) ($payment['id'] ?? '') !== (string) $donation['gateway_payment_id']) {
        return 'not confirmed: gateway payment did not match';
    }
    if ($donation['status'] !== 'success') {
        return 'ignored: donation is ' . $donation['status'];
    }
    $gatewayName = gateway_label(donation_gateway($donation));
    if ($refundedPaise >= $grossPaise || ($payment['refund_status'] ?? '') === 'full') {
        mark_donation_refunded((int) $donation['id'], $refundId, 'Full refund reported by ' . $gatewayName . ' (' . $via . ')', $adminId);
        return 'donation refunded';
    }
    if ($refundedPaise <= 0) {
        return 'not confirmed: gateway shows no refund';
    }
    $net = round(($grossPaise - $refundedPaise) / 100, 2);
    if (abs($net - (float) $donation['amount']) < 0.005) {
        return 'partial refund already applied';
    }
    $note = "\n[" . date('d M Y H:i') . '] Partial refund: ' . money($refundedPaise / 100, true) . ' of ' . money($grossPaise / 100, true)
        . ' refunded by ' . $gatewayName . ($refundId ? ' (' . $refundId . ')' : '') . '; net donation now ' . money($net, true) . '; receipt revised.';
    $n = db_query("UPDATE donations SET amount = ?, admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), ?)) WHERE id = ? AND status = 'success'",
        [$net, $note, (int) $donation['id']])->rowCount();
    if (!$n) {
        return 'ignored: donation is no longer successful';
    }
    log_activity('donation_partial_refund', 'Donation #' . $donation['id'] . ' ' . $donation['donation_ref'] . ': ' . money($refundedPaise / 100, true)
        . ' of ' . money($grossPaise / 100, true) . ' refunded (' . $via . ')' . ($refundId ? ' ' . $refundId : ''), $adminId, $adminId !== null);
    send_receipt_email((int) $donation['id']);
    return 'partial refund applied';
}

// ---------------------------------------------------------------------
// Receipts
// ---------------------------------------------------------------------

/** Create the receipt record for a successful donation (only once). */
function issue_receipt(int $donationId): ?array
{
    $donation = db_row("SELECT * FROM donations WHERE id = ? AND status = 'success'", [$donationId]);
    if (!$donation) {
        return null;
    }
    $existing = db_row('SELECT * FROM donation_receipts WHERE donation_id = ?', [$donationId]);
    if ($existing) {
        return $existing;
    }
    $fy = financial_year($donation['paid_at'] ?: date('Y-m-d'));
    $id = db_insert('donation_receipts', [
        'donation_id'    => $donationId,
        'receipt_no'     => 'TMP-' . $donationId . '-' . bin2hex(random_bytes(3)),
        'financial_year' => $fy,
        'status'         => 'issued',
        'issued_at'      => date('Y-m-d H:i:s'),
    ]);
    $receiptNo = 'PWF/' . $fy . '/' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    db_update('donation_receipts', ['receipt_no' => $receiptNo], 'id = :id', ['id' => $id]);
    return db_row('SELECT * FROM donation_receipts WHERE id = ?', [$id]);
}

function cancel_receipt(int $donationId, string $reason = ''): void
{
    $n = db_query("UPDATE donation_receipts SET status = 'cancelled' WHERE donation_id = ? AND status = 'issued'", [$donationId])->rowCount();
    if ($n && $reason !== '' && $reason !== 'Donation refunded') {
        db_query("UPDATE donations SET admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), ?)) WHERE id = ?",
            ["\n[" . date('d M Y H:i') . '] Receipt cancelled: ' . mb_substr($reason, 0, 300), $donationId]);
    }
}

/** Email the receipt link to the donor. Returns true when the mail was sent. */
function send_receipt_email(int $donationId): bool
{
    $d = db_row("SELECT d.*, r.receipt_no, c.title AS campaign_title FROM donations d
                 JOIN donation_receipts r ON r.donation_id = d.id AND r.status = 'issued'
                 LEFT JOIN campaigns c ON c.id = d.campaign_id
                 WHERE d.id = ? AND d.status = 'success'", [$donationId]);
    if (!$d) {
        return false;
    }
    $body = '<p>Dear ' . e($d['donor_name']) . ',</p>'
        . '<p>Thank you for your donation of <strong>' . e(money($d['amount'], true)) . '</strong>'
        . ($d['campaign_title'] ? ' towards <strong>' . e($d['campaign_title']) . '</strong>' : '') . ' to ' . e(site_name()) . '.</p>'
        . '<p>Your payment has been verified.<br>Receipt number: <strong>' . e($d['receipt_no']) . '</strong><br>Donation reference: ' . e($d['donation_ref']) . '</p>'
        . '<p><a class="btn" href="' . e(donation_receipt_url($d, true)) . '">View / download your receipt</a></p>'
        . '<p>Please keep this link private - it opens your receipt without a password.</p>'
        . '<p>Together, let us build a better tomorrow.<br>' . e(site_name()) . '</p>';
    $sent = send_mail($d['donor_email'], 'Donation receipt ' . $d['receipt_no'] . ' - ' . site_name(), $body);
    if ($sent) {
        db_query('UPDATE donation_receipts SET emailed_at = NOW(), email_count = email_count + 1 WHERE donation_id = ?', [$donationId]);
    }
    return $sent;
}

// ---------------------------------------------------------------------
// Documents (resources table, managed by the information module)
// ---------------------------------------------------------------------

/** Public link of a resource row ('' when it cannot be opened publicly). */
function fundraising_document_href(array $r): string
{
    if (!empty($r['external_url']) && preg_match('#^https?://#i', (string) $r['external_url'])) {
        return (string) $r['external_url'];
    }
    $path = (string) ($r['file_path'] ?? '');
    if ($path === '' || strpos($path, '..') !== false || strpos($path, 'uploads/') !== 0 || !is_file(ROOT_PATH . '/' . $path)) {
        return '';
    }
    // Private folders are never linked directly
    if ((strpos($path, 'uploads/documents/') === 0 && strpos($path, 'uploads/documents/public/') !== 0) || strpos($path, 'uploads/certificates/') === 0) {
        return '';
    }
    return url($path);
}

/** Published documents of the given categories (optionally for one campaign) that can be opened publicly. */
function fundraising_documents(array $categories, ?int $campaignId = null, int $limit = 30): array
{
    if (!$categories) {
        return [];
    }
    $params = $categories;
    $sql = "SELECT * FROM resources WHERE status = 'published' AND category IN (" . implode(',', array_fill(0, count($categories), '?')) . ')';
    if ($campaignId !== null) {
        $sql .= ' AND campaign_id = ?';
        $params[] = $campaignId;
    }
    $sql .= ' ORDER BY COALESCE(document_date, created_at) DESC, sort_order LIMIT ' . (int) $limit;
    $out = [];
    try {
        foreach (db_rows($sql, $params) as $r) {
            $r['href'] = fundraising_document_href($r);
            if ($r['href'] !== '') {
                $out[] = $r;
            }
        }
    } catch (Throwable $e) {
        error_log('fundraising_documents: ' . $e->getMessage());
    }
    return $out;
}

function fundraising_document_list(array $docs): string
{
    $labels = ['financial_statement' => 'Financial statement', 'annual_report' => 'Annual report', 'utilization_report' => 'Utilisation report',
        'impact_report' => 'Impact report', 'certification' => 'Certificate'];
    $html = '<ul class="doc-list">';
    foreach ($docs as $d) {
        $external = !empty($d['external_url']) && $d['href'] === $d['external_url'];
        $html .= '<li><a href="' . e($d['href']) . '" target="_blank" rel="noopener">'
            . '<span class="icon-circle">' . icon($external ? 'external' : 'file-text') . '</span><span><strong>' . e($d['title']) . '</strong><br>'
            . '<small class="muted">' . e($labels[$d['category']] ?? humanize($d['category'])) . (!empty($d['document_date']) ? ' · ' . e(format_date($d['document_date'])) : '')
            . ($external ? ' · External link' : ' · PDF') . '</small></span>' . icon('download', 'doc-dl') . '</a></li>';
    }
    return $html . '</ul>';
}

// ---------------------------------------------------------------------
// Transparency / utilisation data (verified figures only)
// ---------------------------------------------------------------------

/** Verified totals per Indian financial year. */
function verified_totals_by_fy(): array
{
    return db_rows("SELECT CASE WHEN MONTH(paid_at) >= 4 THEN YEAR(paid_at) ELSE YEAR(paid_at) - 1 END AS fy_start,
                           COUNT(*) AS donations, SUM(amount) AS total, COUNT(DISTINCT campaign_id) AS campaigns,
                           SUM(payment_method = 'online') AS online_count, SUM(payment_method <> 'online') AS offline_count
                    FROM donations WHERE status = 'success' AND paid_at IS NOT NULL
                    GROUP BY fy_start ORDER BY fy_start DESC");
}

/** Per public campaign: verified received + approved & published expenses. */
function campaign_utilisation_rows(bool $onlyWithActivity = true): array
{
    $rows = db_rows("SELECT c.id, c.title, c.slug, c.status, c.goal_amount, c.category,
                            COALESCE(d.raised, 0) AS raised, COALESCE(d.n, 0) AS donations,
                            COALESCE(x.spent, 0) AS spent, COALESCE(x.n, 0) AS expenses
                     FROM campaigns c
                     LEFT JOIN (SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS n FROM donations WHERE status = 'success' GROUP BY campaign_id) d ON d.campaign_id = c.id
                     LEFT JOIN (SELECT campaign_id, SUM(amount) AS spent, COUNT(*) AS n FROM campaign_expenses WHERE status = 'approved' AND is_published = 1 GROUP BY campaign_id) x ON x.campaign_id = c.id
                     WHERE c.status IN ('active','completed','closed')
                     ORDER BY FIELD(c.status, 'active', 'completed', 'closed'), c.created_at DESC");
    if ($onlyWithActivity) {
        $rows = array_values(array_filter($rows, function ($r) {
            return (float) $r['raised'] > 0 || (float) $r['spent'] > 0;
        }));
    }
    return $rows;
}

/** Approved + published expenses (optionally of one campaign). */
function published_expenses(?int $campaignId = null, int $limit = 200): array
{
    $params = [];
    $where = "e.status = 'approved' AND e.is_published = 1 AND c.status IN ('active','completed','closed')";
    if ($campaignId !== null) {
        $where .= ' AND e.campaign_id = ?';
        $params[] = $campaignId;
    }
    return db_rows("SELECT e.id, e.campaign_id, e.expense_date, e.category, e.description, e.amount, e.vendor, c.title AS campaign_title, c.slug AS campaign_slug
                    FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id
                    WHERE $where ORDER BY e.expense_date DESC, e.id DESC LIMIT " . (int) $limit, $params);
}

/** Note shown under every public figure. */
function verified_figures_note(): string
{
    return 'Figures update automatically from verified payments; offline donations appear after reconciliation.';
}

// ---------------------------------------------------------------------
// CMS section types
// ---------------------------------------------------------------------

function section_schema_campaigns_grid(): array
{
    $cats = ['' => 'All causes'];
    try {
        $cats += campaign_categories();
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
    return [
        'label' => 'Campaigns (live list)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => [
            'status' => ['select', 'Which campaigns', ['active' => 'Active (accepting donations)', 'completed' => 'Completed / closed', 'all' => 'All public campaigns']],
            'category' => ['select', 'Cause', $cats],
            'limit' => ['number', 'How many (1-12)'],
            'featured_only' => ['checkbox', 'Only featured campaigns'],
            'more_link' => ['checkbox', 'Show a "View all campaigns" link'],
        ],
        'help' => 'Shows published campaigns with progress from verified donations only. The section is hidden when there is nothing to show.',
    ];
}

function section_campaigns_grid(array $s, array $items, array $set, array $page): string
{
    $status = in_array($set['status'] ?? 'active', ['active', 'completed', 'all'], true) ? ($set['status'] ?? 'active') : 'active';
    $limit = max(1, min(12, (int) ($set['limit'] ?? 3) ?: 3));
    $where = ['all' => "c.status IN ('active','completed','closed')", 'completed' => "c.status IN ('completed','closed')", 'active' => "c.status = 'active'"][$status];
    $params = [];
    if (!empty($set['category'])) {
        $where .= ' AND c.category = ?';
        $params[] = (string) $set['category'];
    }
    if (!empty($set['featured_only'])) {
        $where .= ' AND c.is_featured = 1';
    }
    $rows = db_rows(campaign_sql() . " WHERE $where ORDER BY (c.status = 'active') DESC, c.is_urgent DESC, c.is_featured DESC, c.created_at DESC LIMIT " . $limit, $params);
    if (!$rows) {
        return '';
    }
    $html = '<div class="grid grid-3 fr-grid">';
    foreach ($rows as $i => $c) {
        $html .= campaign_card($c, $i);
    }
    $html .= '</div>';
    if (!empty($set['more_link']) && ($page['slug'] ?? '') !== 'campaigns') {
        $html .= '<div class="btn-row center mt-3"><a class="btn btn-outline" href="' . e(page_url('campaigns')) . '">View all campaigns ' . icon('arrow-right') . '</a></div>';
    }
    $html .= '<p class="fr-verified-note">' . icon('shield') . e('Progress shows verified donations only.') . '</p>';
    return $html;
}

function section_schema_donation_appeal(): array
{
    return [
        'label' => 'Donation appeal box (amount buttons)',
        'fields' => ['pill', 'title', 'intro', 'body'],
        'items' => 'strings',
        'settings' => [
            'presets' => ['text', 'Amounts, comma separated (blank = donation settings)'],
            'button_label' => ['text', 'Button text (default: Donate now)'],
            'campaign_id' => ['number', 'Campaign ID (optional - leave blank for a general donation)'],
        ],
        'help' => 'Amount buttons open the donation form with the amount filled in. Items (optional) replace the standard notes about secure payment and receipts - write only true statements.',
    ];
}

function section_donation_appeal(array $s, array $items, array $set, array $page): string
{
    $amounts = [];
    if (!empty($set['presets'])) {
        foreach (explode(',', (string) $set['presets']) as $v) {
            $n = (int) preg_replace('/\D/', '', $v);
            if ($n >= donation_min_amount() && $n <= DONATION_MAX_AMOUNT) {
                $amounts[] = $n;
            }
        }
    }
    $amounts = array_slice(array_values(array_unique($amounts ?: donation_presets())), 0, 6);
    $query = [];
    $campaignTitle = '';
    if (!empty($set['campaign_id'])) {
        $c = db_row("SELECT id, title, status, end_date FROM campaigns WHERE id = ? AND status = 'active'", [(int) $set['campaign_id']]);
        if ($c && campaign_accepts_donations($c)) {
            $query['campaign'] = (int) $c['id'];
            $campaignTitle = $c['title'];
        }
    }
    $notes = array_values(array_filter(array_map('strval', array_filter($items, 'is_string'))));
    if (!$notes) {
        if (online_donations_available()) {
            $notes[] = 'Secure online payment through ' . implode(' or ', array_map('gateway_label', active_gateways())) . ' - UPI, cards and net banking.';
            $notes[] = 'Your donation is confirmed only after the payment is verified on our server.';
        } elseif (bank_transfer_details()) {
            $notes[] = 'Bank transfer and UPI details are shown on the donation page.';
        } else {
            $notes[] = 'Write to us to know the current ways to donate.';
        }
        $notes[] = 'A receipt is issued for every verified donation.';
        $notes[] = 'Campaign progress shows verified donations only.';
        if (setting('reg_80g_number') !== '') {
            $notes[] = 'Registered under Section 80G (No. ' . setting('reg_80g_number') . '); tax benefit as per applicable law.';
        }
    }
    $html = '<div class="fr-appeal reveal"><div class="fr-appeal-main">';
    if (!empty($s['body'])) {
        $html .= '<div class="prose">' . clean_html((string) $s['body']) . '</div>';
    }
    if ($campaignTitle !== '') {
        $html .= '<p class="fr-appeal-for">' . icon('heart') . 'Towards: <strong>' . e($campaignTitle) . '</strong></p>';
    }
    // Preset amounts only make sense for online checkout; otherwise point to the real ways to give.
    if (online_donations_available()) {
        $html .= '<p class="fr-appeal-label" id="appeal-amounts-' . (int) ($s['id'] ?? 0) . '">Choose an amount</p><div class="fr-amounts" role="group" aria-labelledby="appeal-amounts-' . (int) ($s['id'] ?? 0) . '">';
        foreach ($amounts as $a) {
            $html .= '<a class="fr-amount" href="' . e(donate_url($query + ['amount' => $a])) . '">' . e(money($a)) . '</a>';
        }
        $html .= '<a class="fr-amount fr-amount-other" href="' . e(donate_url($query)) . '">Other amount</a></div>';
        $html .= '<a class="btn btn-donate btn-lg" href="' . e(donate_url($query)) . '">' . icon('heart') . e(trim((string) ($set['button_label'] ?? '')) ?: 'Donate now') . '</a></div>';
    } elseif (bank_transfer_details()) {
        $html .= '<a class="btn btn-donate btn-lg" href="' . e(donate_url($query)) . '">' . icon('briefcase') . 'See bank transfer details</a></div>';
    } else {
        $html .= '<a class="btn btn-donate btn-lg" href="' . e(page_url('contact')) . '">' . icon('mail') . 'Ask how to donate</a></div>';
    }
    $html .= '<ul class="fr-appeal-notes">';
    foreach ($notes as $n) {
        $html .= '<li>' . icon('shield') . '<span>' . e($n) . '</span></li>';
    }
    return $html . '</ul></div>';
}

function section_schema_transparency_summary(): array
{
    return [
        'label' => 'Verified donation totals (live)',
        'fields' => ['pill', 'title', 'intro'],
        'settings' => ['more_link' => ['checkbox', 'Show a link to the Financial Transparency page']],
        'help' => 'Total received, number of verified donations, campaigns supported and receipts issued - calculated from verified payments only. Zero values are hidden and an honest note is shown instead.',
    ];
}

function section_transparency_summary(array $s, array $items, array $set, array $page): string
{
    $t = verified_donation_totals();
    $stats = [];
    if ($t['total'] > 0) {
        $stats[] = ['icon' => 'rupee', 'value' => money($t['total']), 'num' => (int) round($t['total']), 'label' => 'Total received (verified)', 'prefix' => '₹'];
    }
    if ($t['count'] > 0) {
        $stats[] = ['icon' => 'heart', 'value' => number_format($t['count']), 'num' => $t['count'], 'label' => 'Verified donations'];
    }
    if ($t['campaigns'] > 0) {
        $stats[] = ['icon' => 'flag', 'value' => number_format($t['campaigns']), 'num' => $t['campaigns'], 'label' => $t['campaigns'] === 1 ? 'Campaign supported' : 'Campaigns supported'];
    }
    if ($t['receipts'] > 0) {
        $stats[] = ['icon' => 'file-text', 'value' => number_format($t['receipts']), 'num' => $t['receipts'], 'label' => 'Receipts issued'];
    }
    $link = !empty($set['more_link']) && ($page['slug'] ?? '') !== 'financial-transparency'
        ? '<div class="btn-row center mt-2"><a class="btn btn-outline" href="' . e(page_url('financial-transparency')) . '">' . icon('shield') . 'Financial transparency</a></div>' : '';
    if (!$stats) {
        return ui_callout('No verified donations recorded yet', 'Totals will appear here automatically as soon as donations are verified with our payment gateway or reconciled by our finance team. We never show estimated or unverified figures.', 'blue', 'shield') . $link;
    }
    $html = '<div class="stats-grid stats-' . count($stats) . ' fr-stats">';
    foreach ($stats as $i => $st) {
        $html .= '<div class="stat reveal" style="--d:' . $i . '"><span class="stat-icon">' . icon($st['icon']) . '</span>'
            . '<strong data-count="' . (int) $st['num'] . '" data-format="' . e($st['value']) . '"' . (!empty($st['prefix']) ? ' data-prefix="' . e($st['prefix']) . '"' : '') . '>' . e($st['value']) . '</strong>'
            . '<span>' . e($st['label']) . '</span></div>';
    }
    return $html . '</div><p class="stats-note">' . icon('shield') . ' ' . e(verified_figures_note()) . '</p>' . $link;
}

// ---------------------------------------------------------------------
// Admin rendering helpers for expenses (used by admin/expenses.php and admin/campaign-view.php;
// the forms post to admin/expenses.php, which checks the campaigns.expenses permission)
// ---------------------------------------------------------------------

/** Approve / reject / publish / edit / delete buttons for one expense row. */
function expense_action_buttons(array $x, string $returnTo): string
{
    $form = function (string $action, string $label, string $iconName, string $confirm = '', string $extra = '') use ($x, $returnTo): string {
        return '<form class="inline-form" method="post" action="' . e(admin_url('expenses.php')) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
            . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="expense_id" value="' . (int) $x['id'] . '">'
            . '<input type="hidden" name="return_to" value="' . e($returnTo) . '">' . $extra
            . '<button class="icon-btn' . ($action === 'delete' || $action === 'reject' ? ' danger' : '') . '" type="submit" aria-label="' . e($label) . '" title="' . e($label) . '">' . icon($iconName) . '</button></form>';
    };
    $html = '';
    if ($x['status'] === 'recorded') {
        if (function_exists('admin_id') && (int) ($x['recorded_by'] ?? 0) === (int) admin_id()) {
            $html .= '<span class="small muted" title="The person who recorded or last edited an expense cannot approve it">Needs another approver</span> ';
        } else {
            $html .= $form('approve', 'Approve expense', 'check', 'Approve this expense of ' . money($x['amount'], true) . '? Approved expenses can be published in the utilisation report.');
        }
        $html .= $form('reject', 'Reject expense', 'x', 'Reject this expense?');
    }
    if ($x['status'] === 'approved') {
        $html .= $x['is_published']
            ? $form('unpublish', 'Remove from the public utilisation report', 'eye', 'Remove this expense from the public utilisation report?')
            : $form('publish', 'Publish in the utilisation report', 'globe', 'Publish this expense (date, category, details, amount - not the bill) on the website?');
        $html .= $form('reopen', 'Move back to "recorded" for correction', 'refresh', 'Move this approved expense back to "recorded"? It will be removed from the public report until approved again.');
    }
    if ($x['status'] !== 'approved') {
        $html .= '<a class="icon-btn" href="' . e(admin_url('expenses.php?edit=' . (int) $x['id'] . '#expense-form')) . '" aria-label="Edit expense" title="Edit">' . icon('edit') . '</a>';
        $html .= $form('delete', 'Delete expense', 'trash', 'Delete this expense record and its bill? This cannot be undone.');
    }
    return $html;
}

/** Record / edit expense form. $campaigns: rows with id + title; $fixedCampaignId hides the campaign selector. */
function expense_form_html(?array $x, array $campaigns, ?int $fixedCampaignId, string $returnTo): string
{
    $val = function (string $k, $default = '') use ($x) {
        return old($k, $x[$k] ?? $default);
    };
    $html = '<form method="post" action="' . e(admin_url('expenses.php')) . '" enctype="multipart/form-data" data-validate>' . csrf_field()
        . '<input type="hidden" name="action" value="save"><input type="hidden" name="expense_id" value="' . (int) ($x['id'] ?? 0) . '">'
        . '<input type="hidden" name="return_to" value="' . e($returnTo) . '"><div class="form-grid">';
    if ($fixedCampaignId) {
        $html .= '<input type="hidden" name="campaign_id" value="' . (int) $fixedCampaignId . '">';
    } else {
        $opts = '<option value="">Select campaign</option>';
        foreach ($campaigns as $c) {
            $opts .= '<option value="' . (int) $c['id'] . '"' . ((string) $val('campaign_id') === (string) $c['id'] ? ' selected' : '') . '>' . e(excerpt($c['title'], 70)) . ' (' . e(humanize($c['status'] ?? '')) . ')</option>';
        }
        $html .= '<div class="form-group full"><label for="x_campaign">Campaign<span class="req">*</span></label><select id="x_campaign" name="campaign_id" required style="max-width:100%">' . $opts . '</select>' . field_error('campaign_id') . '</div>';
    }
    $html .= '<div class="form-group"><label for="x_date">Expense date<span class="req">*</span></label><input id="x_date" type="date" name="expense_date" required max="' . date('Y-m-d') . '" value="' . e($val('expense_date', date('Y-m-d'))) . '">' . field_error('expense_date') . '</div>'
        . '<div class="form-group"><label for="x_amount">Amount (₹)<span class="req">*</span></label><input id="x_amount" type="number" name="amount" required min="1" max="100000000" step="0.01" value="' . e($val('amount')) . '">' . field_error('amount') . '</div>'
        . '<div class="form-group"><label for="x_category">Category<span class="req">*</span></label><select id="x_category" name="category" required>' . options_html(array_combine(EXPENSE_CATEGORIES, EXPENSE_CATEGORIES), (string) $val('category'), true, 'Select') . '</select>' . field_error('category') . '</div>'
        . '<div class="form-group"><label for="x_vendor">Vendor / paid to</label><input id="x_vendor" name="vendor" maxlength="150" value="' . e($val('vendor')) . '">' . field_error('vendor') . '</div>'
        . '<div class="form-group full"><label for="x_desc">Details<span class="req">*</span></label><input id="x_desc" name="description" required minlength="5" maxlength="500" placeholder="What was bought or paid for, and where" value="' . e($val('description')) . '"><span class="help">Shown publicly if the expense is published. Do not include names of beneficiaries.</span>' . field_error('description') . '</div>'
        . '<div class="form-group full"><label for="x_bill">Bill / invoice (private)</label><input id="x_bill" type="file" name="bill" accept="application/pdf,image/jpeg,image/png"><span class="help">PDF, JPG or PNG up to ' . upload_limit_label() . ' MB. Stored privately - never published.' . (!empty($x['document_path']) ? ' A bill is already attached; uploading replaces it.' : '') . '</span>' . field_error('bill') . '</div>'
        . '</div><div class="form-actions"><button class="btn btn-primary" type="submit">' . icon('check') . ($x ? 'Save changes' : 'Record expense') . '</button>'
        . ($x ? '<a class="btn btn-ghost" href="' . e(admin_url('expenses.php')) . '">Cancel</a>' : '') . '</div>'
        . '<p class="small muted mt-2">New and edited expenses are saved as "recorded" and need approval by another staff member with expense permission - never the person who recorded or last edited them.</p></form>';
    return $html;
}
