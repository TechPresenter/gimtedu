<?php
/**
 * Payment gateway callbacks — /api/payments/*
 *
 *   POST /api/payments/cashfree/webhook   Cashfree webhook (payment link / order events). No login or CSRF: the request is
 *                                         authenticated by its x-webhook-signature (HMAC of timestamp + body with the
 *                                         secret key); the status is then re-read from the Cashfree API (cashfree_sync()).
 */
require_once APP_ROOT . '/app/services/cashfree.php';

route('POST', '/payments/cashfree/webhook', function () {
    $raw = (string) file_get_contents('php://input', false, null, 0, 65536);
    if (!cashfree_verify_signature($raw, (string) ($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''), (string) ($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? ''))) {
        log_system('warning', 'Cashfree webhook with an invalid signature rejected', ['ip' => client_ip()]);
        api_error('Invalid signature.', 401);
    }
    $payload = json_decode($raw, true);
    $ref = is_array($payload) ? cashfree_webhook_ref($payload) : null;
    $order = $ref !== null ? cashfree_sync($ref) : null;
    try { $waPay = (array) ($payload['data']['payment'] ?? []); if ($order && $order['status'] === 'created' && (str_contains(strtoupper((string) ($payload['type'] ?? '')), 'FAILED') || in_array(strtoupper((string) ($waPay['payment_status'] ?? '')), ['FAILED', 'USER_DROPPED'], true))) { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_PAYMENT_FAILED', ['student_id' => (int) $order['student_id'], 'order_ref' => $order['order_ref'], 'amount' => (float) $order['amount'], 'payment_link' => (string) $order['link_url'], 'reason' => mb_substr((string) ($waPay['payment_message'] ?? 'Payment was not completed'), 0, 190), 'invoice_no' => (string) db_value('SELECT invoice_no FROM student_fees WHERE id = ?', [(int) $order['student_fee_id']])], ['dedupe_key' => 'order:' . $order['id'] . ':fail:' . mb_substr((string) ($waPay['cf_payment_id'] ?? date('YmdHi')), 0, 40), 'source' => 'payment']); } } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    api_ok(['received' => true, 'status' => $order['status'] ?? null]);
}, ['auth' => false, 'csrf' => false]);
