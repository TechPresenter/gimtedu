<?php
/**
 * Campaigns, donations, payments (Razorpay) and receipts module (M3).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// ---------------------------------------------------------------------
// Campaigns
// ---------------------------------------------------------------------

/** Base query: campaigns with amount raised from VERIFIED donations only. */
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
    if ((float) $c['goal_amount'] <= 0) {
        return 0;
    }
    return (int) min(100, floor(((float) $c['raised'] / (float) $c['goal_amount']) * 100));
}

function campaign_image(array $c): string
{
    $fallback = is_file(ROOT_PATH . '/assets/images/programs/' . $c['category'] . '.svg')
        ? 'assets/images/programs/' . $c['category'] . '.svg'
        : 'assets/images/programs/general.svg';
    return image_url($c['image'] ?? null, $fallback);
}

function campaign_days_left(array $c): ?int
{
    if (empty($c['end_date'])) {
        return null;
    }
    $diff = (int) floor((strtotime($c['end_date'] . ' 23:59:59') - time()) / 86400);
    return max(0, $diff);
}

function campaign_card(array $c): string
{
    $pct = campaign_percent($c);
    $days = campaign_days_left($c);
    $categories = campaign_categories();
    $link = url('campaign-details.php?slug=' . urlencode($c['slug']));
    $html = '<article class="campaign-card">';
    $html .= '<a class="campaign-media" href="' . e($link) . '"><img src="' . e(campaign_image($c)) . '" alt="' . e($c['title']) . '" loading="lazy">';
    if ($c['is_urgent']) {
        $html .= '<span class="tag tag-urgent">Urgent</span>';
    }
    $html .= '<span class="tag tag-cat">' . e($categories[$c['category']] ?? humanize($c['category'])) . '</span></a>';
    $html .= '<div class="campaign-body"><h3><a href="' . e($link) . '">' . e($c['title']) . '</a></h3>';
    $html .= '<p>' . e(excerpt($c['short_description'], 130)) . '</p>';
    $html .= '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '" aria-label="Funding progress">'
        . '<span style="width:' . $pct . '%"></span></div>';
    $html .= '<div class="campaign-stats"><div><strong>' . e(money($c['raised'])) . '</strong><small>raised</small></div>';
    if ((float) $c['goal_amount'] > 0) {
        $html .= '<div class="text-right"><strong>' . e(money($c['goal_amount'])) . '</strong><small>goal</small></div>';
    }
    $html .= '</div><div class="campaign-meta"><span>' . icon('users') . (int) $c['donor_count'] . ' ' . ((int) $c['donor_count'] === 1 ? 'donation' : 'donations') . '</span>';
    if ($c['status'] !== 'active') {
        $html .= '<span>' . icon('check-circle') . humanize($c['status']) . '</span>';
    } elseif ($days !== null) {
        $html .= '<span>' . icon('clock') . ($days === 0 ? 'Last day' : $days . ' days left') . '</span>';
    }
    $html .= '</div><div class="campaign-actions">';
    if ($c['status'] === 'active') {
        $html .= '<a class="btn btn-donate btn-sm" href="' . e(url('donate.php?campaign=' . (int) $c['id'])) . '">' . icon('heart') . 'Donate</a>';
    }
    $html .= '<a class="btn btn-outline btn-sm" href="' . e($link) . '">Details</a></div></div></article>';
    return $html;
}

// ---------------------------------------------------------------------
// Payments (Razorpay) - all verification happens on the server
// ---------------------------------------------------------------------
function razorpay_configured(): bool
{
    return RAZORPAY_KEY_ID !== '' && RAZORPAY_KEY_SECRET !== '';
}

function payment_test_mode(): bool
{
    return strpos(RAZORPAY_KEY_ID, 'rzp_test_') === 0;
}

/** Call the Razorpay REST API. Returns [httpStatus, decodedBody]. */
function razorpay_request(string $method, string $endpoint, array $data = []): array
{
    $ch = curl_init(rtrim(RAZORPAY_API_BASE, '/') . '/' . ltrim($endpoint, '/'));
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        error_log('Razorpay request failed: ' . curl_error($ch));
        curl_close($ch);
        return [0, null];
    }
    curl_close($ch);
    return [$status, json_decode((string) $body, true)];
}

/** Paise as integer (Razorpay uses the smallest currency unit). */
function to_paise($amount): int
{
    return (int) round(((float) $amount) * 100);
}

function financial_year(?string $date = null): string
{
    $ts = $date ? strtotime($date) : time();
    $y = (int) date('Y', $ts);
    $start = (int) date('n', $ts) >= 4 ? $y : $y - 1;
    return $start . '-' . substr((string) ($start + 1), -2);
}

/**
 * Mark a donation as successful after the payment was verified with the
 * gateway. Safe to call more than once (webhook + checkout callback).
 * Returns true if the donation is (now) successful.
 */
function mark_donation_success(int $donationId, array $payment, string $via, ?int $adminId = null): bool
{
    $pdo = db();
    $newlyConfirmed = false;
    $pdo->beginTransaction();
    try {
        $donation = db_row('SELECT * FROM donations WHERE id = ? FOR UPDATE', [$donationId]);
        if (!$donation) {
            $pdo->rollBack();
            return false;
        }
        if ($donation['status'] === 'refunded') {
            $pdo->rollBack();
            return false;
        }
        if ($donation['status'] !== 'success') {
            db_update('donations', [
                'status'             => 'success',
                'gateway_payment_id' => $payment['id'] ?? $donation['gateway_payment_id'],
                'gateway_signature'  => $payment['signature'] ?? $donation['gateway_signature'],
                'gateway_method'     => isset($payment['method']) ? mb_substr((string) $payment['method'], 0, 40) : $donation['gateway_method'],
                'failure_reason'     => null,
                'paid_at'            => date('Y-m-d H:i:s', isset($payment['created_at']) ? (int) $payment['created_at'] : time()),
                'verified_via'       => $via,
                'verified_by'        => $adminId,
            ], 'id = :id', ['id' => $donationId]);
            $newlyConfirmed = true;
        }
        issue_receipt($donationId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('mark_donation_success failed: ' . $e->getMessage());
        throw $e;
    }

    if ($newlyConfirmed) {
        log_activity('donation_confirmed', 'Donation #' . $donationId . ' confirmed via ' . $via, $adminId);
        send_receipt_email($donationId);
    }
    return true;
}

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
    $fy = financial_year($donation['paid_at']);
    $id = db_insert('donation_receipts', [
        'donation_id'    => $donationId,
        'receipt_no'     => 'TMP-' . $donationId,
        'financial_year' => $fy,
        'status'         => 'issued',
        'issued_at'      => date('Y-m-d H:i:s'),
    ]);
    $receiptNo = 'PWF/' . $fy . '/' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    db_update('donation_receipts', ['receipt_no' => $receiptNo], 'id = :id', ['id' => $id]);
    return db_row('SELECT * FROM donation_receipts WHERE id = ?', [$id]);
}

function receipt_link(array $donation): string
{
    return abs_url('donation-receipt.php?ref=' . urlencode($donation['donation_ref']) . '&token=' . urlencode($donation['access_token']));
}

function send_receipt_email(int $donationId): void
{
    $d = db_row("SELECT d.*, r.receipt_no, c.title AS campaign_title FROM donations d
                 JOIN donation_receipts r ON r.donation_id = d.id
                 LEFT JOIN campaigns c ON c.id = d.campaign_id
                 WHERE d.id = ? AND d.status = 'success'", [$donationId]);
    if (!$d) {
        return;
    }
    $body = '<p>Dear ' . e($d['donor_name']) . ',</p>'
        . '<p>Thank you for your generous donation of <strong>' . e(money($d['amount'], true)) . '</strong>'
        . ($d['campaign_title'] ? ' towards <strong>' . e($d['campaign_title']) . '</strong>' : '') . ' to ' . e(site_name()) . '.</p>'
        . '<p>Your payment has been verified. Receipt number: <strong>' . e($d['receipt_no']) . '</strong><br>Donation reference: ' . e($d['donation_ref']) . '</p>'
        . '<p><a class="btn" href="' . e(receipt_link($d)) . '">View / download your receipt</a></p>'
        . '<p>Together, let us build a better tomorrow.</p>';
    if (send_mail($d['donor_email'], 'Donation receipt ' . $d['receipt_no'] . ' - ' . site_name(), $body)) {
        db_update('donation_receipts', ['emailed_at' => date('Y-m-d H:i:s')], 'donation_id = :id', ['id' => $donationId]);
    }
}

/** Ask Razorpay whether an order has a captured payment (used by admin re-check). */
function razorpay_find_captured_payment(array $donation): ?array
{
    if (!$donation['gateway_order_id'] || !razorpay_configured()) {
        return null;
    }
    [$status, $body] = razorpay_request('GET', 'orders/' . rawurlencode($donation['gateway_order_id']) . '/payments');
    if ($status !== 200 || empty($body['items'])) {
        return null;
    }
    foreach ($body['items'] as $payment) {
        if (($payment['status'] ?? '') === 'captured'
            && (int) ($payment['amount'] ?? 0) === to_paise($donation['amount'])
            && ($payment['currency'] ?? '') === $donation['currency']) {
            return $payment;
        }
    }
    return null;
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

/** Live totals for public counters - verified data only. */
function impact_counters(): array
{
    return [
        'raised'     => (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success'"),
        'donations'  => (int) db_value("SELECT COUNT(*) FROM donations WHERE status = 'success'"),
        'volunteers' => (int) db_value("SELECT COUNT(*) FROM volunteers WHERE status = 'approved'")
                      + (int) db_value("SELECT COUNT(*) FROM teacher_applications WHERE status = 'approved'"),
        'events'     => (int) db_value("SELECT COUNT(*) FROM events WHERE status = 'published' AND start_datetime < NOW()"),
        'campaigns'  => (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status IN ('active','completed')"),
    ];
}
