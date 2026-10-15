<?php
/**
 * Razorpay webhook endpoint.
 * Configure in Razorpay Dashboard > Settings > Webhooks:
 *   URL:    https://YOUR-DOMAIN/payment-webhook.php
 *   Secret: same value as RAZORPAY_WEBHOOK_SECRET in config
 *   Events: payment.captured, payment.failed, order.paid, refund.processed
 *
 * Every request is authenticated with the X-Razorpay-Signature header
 * (HMAC-SHA256 of the raw body). Unsigned or tampered requests are rejected.
 */
define('PWF_NO_SESSION', true);
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$payload = (string) file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');

if (RAZORPAY_WEBHOOK_SECRET === '' || $signature === ''
    || !hash_equals(hash_hmac('sha256', $payload, RAZORPAY_WEBHOOK_SECRET), $signature)) {
    log_activity('webhook_invalid_signature', 'Rejected webhook request');
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid signature']));
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['event'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid payload']));
}

$type = (string) $event['event'];
$payment = $event['payload']['payment']['entity'] ?? null;

try {
    switch ($type) {
        case 'payment.captured':
        case 'order.paid':
            if (!$payment || empty($payment['order_id'])) {
                break;
            }
            $donation = db_row('SELECT * FROM donations WHERE gateway_order_id = ?', [$payment['order_id']]);
            if (!$donation) {
                break;
            }
            if (($payment['status'] ?? '') !== 'captured'
                || (int) ($payment['amount'] ?? 0) !== to_paise($donation['amount'])
                || ($payment['currency'] ?? '') !== $donation['currency']) {
                log_activity('webhook_mismatch', 'Donation ' . $donation['donation_ref'] . ' amount/status mismatch');
                break;
            }
            mark_donation_success((int) $donation['id'], $payment, 'webhook');
            break;

        case 'payment.failed':
            if ($payment && !empty($payment['order_id'])) {
                $reason = mb_substr((string) ($payment['error_description'] ?? 'Payment failed'), 0, 250);
                db_query("UPDATE donations SET status = 'failed', failure_reason = ? WHERE gateway_order_id = ? AND status = 'pending'",
                    [$reason, $payment['order_id']]);
            }
            break;

        case 'refund.processed':
            $refund = $event['payload']['refund']['entity'] ?? null;
            if (!$refund || empty($refund['payment_id'])) {
                break;
            }
            $donation = db_row("SELECT * FROM donations WHERE gateway_payment_id = ? AND status = 'success'", [$refund['payment_id']]);
            if (!$donation) {
                break;
            }
            $refunded = (int) ($refund['amount'] ?? 0);
            if ($refunded >= to_paise($donation['amount'])) {
                db_update('donations', [
                    'status' => 'refunded',
                    'admin_notes' => trim(($donation['admin_notes'] ?? '') . "\nFull refund processed by gateway on " . date('d M Y H:i') . ' (' . ($refund['id'] ?? '') . ')'),
                ], 'id = :id', ['id' => $donation['id']]);
                db_query("UPDATE donation_receipts SET status = 'cancelled' WHERE donation_id = ?", [$donation['id']]);
            } else {
                db_update('donations', [
                    'admin_notes' => trim(($donation['admin_notes'] ?? '') . "\nPartial refund of " . money($refunded / 100, true) . ' on ' . date('d M Y H:i') . ' (' . ($refund['id'] ?? '') . ')'),
                ], 'id = :id', ['id' => $donation['id']]);
            }
            log_activity('donation_refunded', 'Donation ' . $donation['donation_ref'] . ' refund ' . ($refund['id'] ?? ''));
            break;
    }
} catch (Throwable $e) {
    error_log('Webhook processing error: ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['error' => 'Processing error']));
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
