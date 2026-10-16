<?php
/**
 * Cashfree Payments webhook endpoint (API version 2023-08-01).
 * Configure in the Cashfree Merchant Dashboard > Payment Gateway > Developers > Webhooks:
 *   URL:     https://YOUR-DOMAIN/cashfree-webhook.php
 *   Events:  Payment success, Payment failed, Payment user dropped, Refund status
 *   Cashfree signs every webhook with your Secret Key (there is no separate webhook secret).
 *
 * - Authentication: x-webhook-signature == base64(HMAC-SHA256(x-webhook-timestamp . rawBody, secret key)),
 *   compared in constant time. Bad or missing signature => 401; malformed request => 400.
 * - Replay protection: deliveries older than 15 minutes are rejected (400); every accepted delivery is stored in
 *   payment_webhook_events with a hash of timestamp + body as its id, so a repeated delivery gets 200 "duplicate"
 *   and is not processed again.
 * - Amounts in the payload are never trusted: the order, its payments and refunds are fetched from the Cashfree
 *   API and must match the donation's order id, amount and currency.
 */
define('PWF_NO_SESSION', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/** Send a JSON response and stop. */
function cf_webhook_respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    cf_webhook_respond(405, ['error' => 'Method not allowed']);
}

$payload = (string) file_get_contents('php://input', false, null, 0, 1024 * 1024);
$signature = trim((string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));
$timestamp = trim((string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? ''));

if ($payload === '' || $signature === '' || $timestamp === '') {
    cf_webhook_respond(400, ['error' => 'Missing signature, timestamp or body']);
}
if (!cashfree_configured()) {
    cf_webhook_respond(503, ['error' => 'Cashfree is not configured']);
}
if (!cashfree_webhook_signature_valid($payload, $timestamp, $signature)) {
    log_activity('webhook_invalid_signature', 'Rejected Cashfree webhook request (' . strlen($payload) . ' bytes)');
    cf_webhook_respond(401, ['error' => 'Invalid signature']);
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['type']) || !is_string($event['type'])) {
    cf_webhook_respond(400, ['error' => 'Invalid payload']);
}
$type = mb_substr(preg_replace('/[^A-Z_]/', '', strtoupper($event['type'])), 0, 60);
$eventId = 'cf_' . hash('sha256', $timestamp . '.' . $payload);

// An event that was already accepted is acknowledged and ignored (Cashfree retries until it gets a 2xx).
if (db_value("SELECT id FROM payment_webhook_events WHERE gateway = 'cashfree' AND event_id = ?", [$eventId])) {
    cf_webhook_respond(200, ['status' => 'duplicate']);
}
if (!cashfree_webhook_timestamp_fresh($timestamp)) {
    log_activity('webhook_stale', 'Rejected stale Cashfree webhook (' . $type . ')');
    cf_webhook_respond(400, ['error' => 'Stale timestamp']);
}

try {
    $eventRowId = db_insert('payment_webhook_events', [
        'gateway' => 'cashfree', 'event_id' => $eventId, 'event_type' => $type !== '' ? $type : 'UNKNOWN', 'payload_hash' => hash('sha256', $payload),
    ]);
} catch (PDOException $e) {
    if ((string) $e->getCode() === '23000') {
        cf_webhook_respond(200, ['status' => 'duplicate']);
    }
    error_log('Cashfree webhook store failed: ' . $e->getMessage());
    cf_webhook_respond(500, ['error' => 'Storage error']);
}

$data = is_array($event['data'] ?? null) ? $event['data'] : [];
$orderId = (string) ($data['order']['order_id'] ?? ($data['refund']['order_id'] ?? ''));
$donation = cashfree_order_id_valid($orderId)
    ? db_row("SELECT * FROM donations WHERE gateway = 'cashfree' AND payment_method = 'online' AND gateway_order_id = ?", [$orderId]) : null;
$donationId = $donation ? (int) $donation['id'] : null;
$result = 'ignored: unsupported event';

try {
    switch ($type) {
        case 'PAYMENT_SUCCESS_WEBHOOK':
            if (!$donation) {
                $result = 'ignored: no donation for order';
                break;
            }
            if ($donation['status'] === 'success') {
                $result = 'already successful';
                break;
            }
            if ($donation['status'] === 'refunded') {
                $result = 'ignored: donation refunded';
                break;
            }
            // Never trust the payload: the order must be PAID with a SUCCESS payment of the exact amount (API).
            $check = cashfree_confirm_donation($donation, 'webhook');
            if ($check['state'] === 'error') {
                throw new RuntimeException('Cashfree API not reachable - asking Cashfree to retry this webhook');
            }
            if ($check['state'] === 'paid') {
                $result = 'donation confirmed';
            } else {
                log_activity('webhook_mismatch', 'Donation ' . $donation['donation_ref'] . ': Cashfree success webhook not confirmed by the API (' . $check['state'] . ')');
                $result = 'not confirmed: gateway shows ' . $check['state'];
            }
            break;

        case 'PAYMENT_FAILED_WEBHOOK':
        case 'PAYMENT_USER_DROPPED_WEBHOOK':
            if (!$donation) {
                $result = 'ignored: no donation for order';
                break;
            }
            if ($donation['status'] !== 'pending') {
                $result = 'ignored: donation is ' . $donation['status'];
                break;
            }
            // Ask Cashfree: another attempt of the same order may already have succeeded.
            $check = cashfree_confirm_donation($donation, 'webhook');
            $result = ['paid' => 'donation confirmed (another attempt succeeded)', 'failed' => 'marked failed'][$check['state']] ?? 'left pending: gateway shows ' . $check['state'];
            break;

        case 'REFUND_STATUS_WEBHOOK':
            if (!$donation) {
                $result = 'ignored: no donation for order';
                break;
            }
            $refund = is_array($data['refund'] ?? null) ? $data['refund'] : [];
            $refundId = preg_match('/^[A-Za-z0-9_\-]{3,60}$/', (string) ($refund['refund_id'] ?? '')) ? (string) $refund['refund_id'] : null;
            $refundStatus = strtoupper((string) ($refund['refund_status'] ?? ''));
            if (in_array($refundStatus, ['CANCELLED', 'FAILED'], true)) {
                // A refund we already booked did not go through: tell the staff, never revive the donation silently.
                if ($donation['status'] === 'refunded' && $refundId !== null && $refundId === (string) $donation['refund_id']) {
                    db_query("UPDATE donations SET admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), ?)) WHERE id = ?",
                        ["\n[" . date('d M Y H:i') . '] Cashfree reports refund ' . $refundId . ' as ' . $refundStatus . ' - check the Cashfree dashboard and contact the donor.', $donationId]);
                    log_activity('donation_refund_failed', 'Donation #' . $donationId . ' ' . $donation['donation_ref'] . ': Cashfree refund ' . $refundId . ' ' . $refundStatus);
                    $result = 'refund ' . strtolower($refundStatus) . ': staff notified';
                } else {
                    $result = 'ignored: refund ' . strtolower($refundStatus);
                }
                break;
            }
            if ($donation['status'] === 'refunded') {
                $result = 'already refunded';
                break;
            }
            if ($donation['status'] !== 'success') {
                $result = 'ignored: donation is ' . $donation['status'];
                break;
            }
            // Confirm with the API how much of the payment has really been refunded (full or partial).
            $state = cashfree_refund_state($donation);
            if (!$state) {
                $result = 'not confirmed: gateway payment or refunds not found';
                break;
            }
            $result = apply_gateway_refund_state($donation, $state, $refundId, 'Cashfree refund webhook');
            break;
    }
} catch (Throwable $e) {
    error_log('Cashfree webhook processing error (' . $type . '): ' . $e->getMessage());
    // Remove the stored event so that Cashfree's automatic retry is processed again.
    db_query('DELETE FROM payment_webhook_events WHERE id = ?', [$eventRowId]);
    cf_webhook_respond(500, ['error' => 'Processing error']);
}

db_update('payment_webhook_events', [
    'donation_id' => $donationId, 'result' => mb_substr($result, 0, 255), 'processed_at' => date('Y-m-d H:i:s'),
], 'id = :id', ['id' => $eventRowId]);

cf_webhook_respond(200, ['status' => 'ok', 'result' => $result]);
