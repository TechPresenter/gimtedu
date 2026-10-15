<?php
/**
 * Razorpay webhook endpoint.
 * Configure in Razorpay Dashboard > Settings > Webhooks:
 *   URL:    https://YOUR-DOMAIN/payment-webhook.php
 *   Secret: same value as RAZORPAY_WEBHOOK_SECRET in pwf-config.php / config.php
 *   Events: payment.captured, order.paid, payment.failed, refund.processed (refund.created optional)
 *
 * - Every request is authenticated with the X-Razorpay-Signature header (HMAC-SHA256 of the raw body).
 * - Every verified delivery is stored in payment_webhook_events; (gateway, event_id) is unique, so a
 *   repeated delivery is acknowledged with 200 and ignored.
 * - Amounts in the payload are never trusted: the payment is fetched from the Razorpay API and must match
 *   the donation's order id, amount and currency before the donation is marked successful.
 */
define('PWF_NO_SESSION', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

/** Send a JSON response and stop. */
function webhook_respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    webhook_respond(405, ['error' => 'Method not allowed']);
}

$payload = (string) file_get_contents('php://input', false, null, 0, 1024 * 1024);
$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if (!razorpay_webhook_signature_valid($payload, $signature)) {
    log_activity('webhook_invalid_signature', 'Rejected webhook request (' . strlen($payload) . ' bytes)');
    webhook_respond(400, ['error' => 'Invalid signature']);
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['event']) || !is_string($event['event'])) {
    webhook_respond(400, ['error' => 'Invalid payload']);
}

$type = mb_substr($event['event'], 0, 60);
$payloadHash = hash('sha256', $payload);
$eventId = (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');
if (!preg_match('/^[A-Za-z0-9_\-]{6,100}$/', $eventId)) {
    $eventId = 'sha256:' . substr($payloadHash, 0, 56);   // no event id header: identical bodies count as duplicates
}

// Store the delivery first; a duplicate (same gateway + event id) is acknowledged and ignored.
try {
    $eventRowId = db_insert('payment_webhook_events', [
        'gateway' => 'razorpay', 'event_id' => $eventId, 'event_type' => $type, 'payload_hash' => $payloadHash,
    ]);
} catch (PDOException $e) {
    if ((string) $e->getCode() === '23000') {
        webhook_respond(200, ['status' => 'duplicate']);
    }
    error_log('Webhook store failed: ' . $e->getMessage());
    webhook_respond(500, ['error' => 'Storage error']);
}

$paymentEntity = $event['payload']['payment']['entity'] ?? null;
$orderEntity = $event['payload']['order']['entity'] ?? null;
$refundEntity = $event['payload']['refund']['entity'] ?? null;
$donationId = null;
$result = 'ignored: unsupported event';

try {
    switch ($type) {
        case 'payment.captured':
        case 'order.paid':
            $orderId = (string) ($paymentEntity['order_id'] ?? ($orderEntity['id'] ?? ''));
            $paymentId = (string) ($paymentEntity['id'] ?? '');
            $donation = $orderId !== '' ? db_row('SELECT * FROM donations WHERE gateway_order_id = ?', [$orderId]) : null;
            if (!$donation) {
                $result = 'ignored: no donation for order';
                break;
            }
            $donationId = (int) $donation['id'];
            if ($donation['status'] === 'success') {
                $result = 'already successful';
                break;
            }
            if ($donation['status'] === 'refunded') {
                $result = 'ignored: donation refunded';
                break;
            }
            // Never trust the payload: fetch the payment from the API and match order, amount and currency.
            $payment = $paymentId !== '' ? razorpay_verify_payment_for_donation($paymentId, $donation) : razorpay_find_captured_payment($donation);
            if (!$payment) {
                log_activity('webhook_mismatch', 'Donation ' . $donation['donation_ref'] . ': payment not captured or does not match the order');
                $result = 'not confirmed: gateway payment did not match';
                break;
            }
            mark_donation_success($donationId, $payment, 'webhook');
            $result = 'donation confirmed';
            break;

        case 'payment.failed':
            $orderId = (string) ($paymentEntity['order_id'] ?? '');
            $donation = $orderId !== '' ? db_row('SELECT * FROM donations WHERE gateway_order_id = ?', [$orderId]) : null;
            if (!$donation) {
                $result = 'ignored: no donation for order';
                break;
            }
            $donationId = (int) $donation['id'];
            if ($donation['status'] !== 'pending') {
                $result = 'ignored: donation is ' . $donation['status'];
                break;
            }
            mark_donation_failed($donationId, clean_string($paymentEntity['error_description'] ?? 'Payment failed'));
            $result = 'marked failed';
            break;

        case 'refund.processed':
        case 'refund.created':
            $paymentId = (string) ($refundEntity['payment_id'] ?? ($paymentEntity['id'] ?? ''));
            $donation = $paymentId !== '' ? db_row('SELECT * FROM donations WHERE gateway_payment_id = ?', [$paymentId]) : null;
            if (!$donation) {
                $result = 'ignored: no donation for payment';
                break;
            }
            $donationId = (int) $donation['id'];
            if ($donation['status'] === 'refunded') {
                $result = 'already refunded';
                break;
            }
            if ($donation['status'] !== 'success') {
                $result = 'ignored: donation is ' . $donation['status'];
                break;
            }
            // Confirm with the API how much of the payment has really been refunded.
            $payment = razorpay_fetch_payment($paymentId);
            $refundedPaise = (int) ($payment['amount_refunded'] ?? 0);
            $refundId = preg_match('/^rfnd_[A-Za-z0-9]+$/', (string) ($refundEntity['id'] ?? '')) ? (string) $refundEntity['id'] : null;
            if ($payment && ($refundedPaise >= to_paise($donation['amount']) || ($payment['refund_status'] ?? '') === 'full')) {
                mark_donation_refunded($donationId, $refundId, 'Full refund reported by Razorpay (' . $type . ')');
                $result = 'donation refunded';
            } elseif ($payment && $refundedPaise > 0) {
                db_query("UPDATE donations SET admin_notes = TRIM(CONCAT(COALESCE(admin_notes, ''), ?)) WHERE id = ?",
                    ["\n[" . date('d M Y H:i') . '] Partial refund of ' . money($refundedPaise / 100, true) . ' reported by Razorpay' . ($refundId ? ' (' . $refundId . ')' : '') . ' - review needed.', $donationId]);
                log_activity('donation_partial_refund', 'Donation ' . $donation['donation_ref'] . ' partial refund ' . ($refundId ?? ''));
                $result = 'partial refund noted';
            } else {
                $result = 'not confirmed: gateway shows no refund';
            }
            break;
    }
} catch (Throwable $e) {
    error_log('Webhook processing error (' . $type . '): ' . $e->getMessage());
    // Remove the stored event so that Razorpay's automatic retry is processed again.
    db_query('DELETE FROM payment_webhook_events WHERE id = ?', [$eventRowId]);
    webhook_respond(500, ['error' => 'Processing error']);
}

db_update('payment_webhook_events', [
    'donation_id' => $donationId, 'result' => mb_substr($result, 0, 255), 'processed_at' => date('Y-m-d H:i:s'),
], 'id = :id', ['id' => $eventRowId]);

webhook_respond(200, ['status' => 'ok', 'result' => $result]);
