<?php
/**
 * Cashfree Payment Gateway — online fee payments from the Student Portal (Cashfree Payment Links API, version
 * 2023-08-01; the student pays on Cashfree's hosted page by UPI, card, net banking or wallet).
 *
 * Setup (Admin › Settings › Payment): Gateway = Cashfree, Mode = Test / sandbox or Live, Key ID = Cashfree App ID
 * (x-client-id), Key secret = Cashfree Secret Key (x-client-secret), and tick "Online gateway" under Accepted payment
 * modes. In the Cashfree dashboard (Developers › Webhooks) add the webhook URL cashfree_webhook_url() for payment link
 * events. Test keys work only with Test mode, live keys only with Live mode.
 *
 * Flow: cashfree_start_payment() → gateway_orders row + Cashfree payment link (link_id = our order_ref) → student pays →
 * Cashfree redirects back to /portal/student/fees?payment=<order_ref> and calls the webhook → cashfree_sync() re-reads
 * the link from the Cashfree API (the browser and the webhook body are never trusted for the amount or status) and, once
 * PAID, posts the receipt with fees_collect() (mode online) exactly once (row lock on the order). A payment that cannot
 * be posted automatically (invoice paid or cancelled at the counter meanwhile) is kept as status "review" and the fees
 * team is notified.
 */
require_once APP_ROOT . '/app/crud.php';
require_once APP_ROOT . '/app/services/fees.php';

const CASHFREE_API_VERSION = '2023-08-01';

/** Keys + endpoint for the configured mode (no secrets leave the server). */
function cashfree_config(): array
{
    $live = (string) setting('gateway_mode', 'test') === 'live';
    return [
        'app_id' => trim((string) setting('gateway_key_id', '')), 'secret' => trim((string) setting('gateway_key_secret', '')),
        'live' => $live, 'base' => $live ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg',
    ];
}

/** Cashfree is the selected gateway, its keys are saved and "Online gateway" is an accepted payment mode. */
function cashfree_ready(): bool
{
    $c = cashfree_config();
    return (string) setting('payment_gateway', '') === 'cashfree' && $c['app_id'] !== '' && $c['secret'] !== ''
        && isset(fees_enabled_modes()['online']) && function_exists('curl_init');
}

/** Webhook URL to register in the Cashfree dashboard. */
function cashfree_webhook_url(): string
{
    return absolute_url('api/payments/cashfree/webhook');
}

/** Call the Cashfree PG API: ['status' => HTTP code (0 = network error), 'json' => decoded body | null, 'error' => message | null]. */
function cashfree_api(string $method, string $path, ?array $body = null): array
{
    $c = cashfree_config();
    $ch = curl_init($c['base'] . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'x-api-version: ' . CASHFREE_API_VERSION,
            'x-client-id: ' . $c['app_id'], 'x-client-secret: ' . $c['secret']],
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $err = $raw === false ? curl_error($ch) : null;
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    $json = is_array($json) ? $json : null;
    return ['status' => $code, 'json' => $json, 'error' => $err ?? ($code >= 400 ? (string) ($json['message'] ?? 'HTTP ' . $code) : null)];
}

/**
 * Start an online payment of $amount (default: the full balance) against one invoice of the student.
 * Returns ['order_ref', 'url'] — the Cashfree hosted payment page. An unpaid link for the same invoice and amount from
 * the last 30 minutes is reused. Throws CrudValidationException / CrudException with a message for the student.
 */
function cashfree_start_payment(array $student, int $invoiceId, ?float $amount = null): array
{
    if (!cashfree_ready()) {
        throw new CrudException('Online payment is not available right now. Please pay at the accounts office or by bank transfer.');
    }
    $inv = $invoiceId ? db_row('SELECT * FROM student_fees WHERE id = ? AND student_id = ?', [$invoiceId, (int) $student['id']]) : null;
    if (!$inv || in_array($inv['status'], ['cancelled', 'paid', 'waived'], true)) {
        throw new CrudValidationException(['invoice_id' => 'This invoice cannot be paid online.']);
    }
    $balance = fees_round((float) $inv['net_amount'] - (float) $inv['paid_amount']);
    if ($balance <= 0) {
        throw new CrudValidationException(['invoice_id' => 'Nothing is due on invoice ' . $inv['invoice_no'] . '.']);
    }
    $amount = fees_round($amount ?? $balance);
    if ($amount < 1 || $amount > $balance + 0.001) {
        throw new CrudValidationException(['amount' => 'Enter an amount between ' . money(1, 2) . ' and ' . money($balance, 2) . '.']);
    }
    $open = db_row("SELECT order_ref, link_url FROM gateway_orders WHERE student_fee_id = ? AND amount = ? AND gateway = 'cashfree' AND status = 'created'
                    AND link_url IS NOT NULL AND created_at > ? ORDER BY id DESC LIMIT 1", [$invoiceId, $amount, date('Y-m-d H:i:s', strtotime('-30 minutes'))]);
    if ($open) {
        return ['order_ref' => $open['order_ref'], 'url' => $open['link_url']];
    }
    $phone = preg_replace('/\D+/', '', (string) ($student['mobile'] ?? ''));
    $phone = strlen($phone) > 10 ? substr($phone, -10) : $phone;
    if (strlen($phone) !== 10) {
        throw new CrudException('A valid 10-digit mobile number is needed to pay online. Please ask the accounts office to update your profile.');
    }

    $c = cashfree_config();
    $ref = next_number('gateway_order', 'GIMT-PAY-{Y}-{n:6}');
    $orderId = db_insert('gateway_orders', ['order_ref' => $ref, 'gateway' => 'cashfree', 'mode' => $c['live'] ? 'live' : 'test', 'student_id' => (int) $student['id'],
        'student_fee_id' => $invoiceId, 'amount' => $amount, 'currency' => 'INR', 'status' => 'created']);
    $name = trim(implode(' ', array_filter([(string) ($student['first_name'] ?? ''), (string) ($student['last_name'] ?? '')]))) ?: 'Student';
    $res = cashfree_api('POST', '/links', [
        'link_id' => $ref, 'link_amount' => $amount, 'link_currency' => 'INR',
        'link_purpose' => mb_substr('Fee ' . $inv['invoice_no'] . ' - ' . $inv['title'], 0, 500),
        'customer_details' => array_filter(['customer_phone' => $phone, 'customer_email' => (string) ($student['email'] ?? '') ?: null, 'customer_name' => mb_substr($name, 0, 100)]),
        'link_partial_payments' => false,
        'link_notify' => ['send_sms' => false, 'send_email' => false],
        'link_meta' => ['return_url' => absolute_url('portal/student/fees') . '?payment=' . rawurlencode($ref), 'notify_url' => cashfree_webhook_url()],
        'link_expiry_time' => date('c', strtotime('+1 day')),
        'link_notes' => ['invoice_no' => (string) $inv['invoice_no'], 'student_uid' => (string) ($student['student_uid'] ?? '')],
    ]);
    $link = $res['json'] ?? [];
    if ($res['status'] < 200 || $res['status'] >= 300 || empty($link['link_url'])) {
        db_update('gateway_orders', ['status' => 'failed', 'status_note' => mb_substr('Could not create the payment link: ' . ($res['error'] ?? 'no link returned'), 0, 255), 'response' => json_encode($link)], 'id = ?', [$orderId]);
        log_system('error', 'Cashfree: payment link ' . $ref . ' failed (HTTP ' . $res['status'] . ')', ['error' => $res['error'], 'mode' => $c['live'] ? 'live' : 'test']);
        throw new CrudException('Could not start the online payment. Please try again in a few minutes or pay at the accounts office.');
    }
    db_update('gateway_orders', ['link_url' => mb_substr((string) $link['link_url'], 0, 500), 'gateway_ref' => isset($link['cf_link_id']) ? (string) $link['cf_link_id'] : null, 'response' => json_encode($link)], 'id = ?', [$orderId]);
    log_activity('create', 'fees', $orderId, sprintf('Started online payment %s of %s for invoice %s (Cashfree)', $ref, money($amount, 2), $inv['invoice_no']));
    try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_PAYMENT_LINK_CREATED', ['student_id' => (int) $student['id'], 'order_ref' => $ref, 'amount' => $amount, 'invoice_no' => $inv['invoice_no'], 'fee_title' => $inv['title'], 'payment_link' => (string) $link['link_url'], 'due_date' => !empty($inv['due_date']) ? date('d M Y', strtotime($inv['due_date'])) : ''], ['dedupe_key' => 'order:' . $orderId, 'source' => 'payment']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    return ['order_ref' => $ref, 'url' => (string) $link['link_url']];
}

/**
 * Re-read a payment link from Cashfree and post the receipt once it is PAID. Idempotent and safe to run from the
 * return page and the webhook at the same time. Returns the fresh gateway_orders row (null when unknown).
 */
function cashfree_sync(string $ref): ?array
{
    $o = db_row("SELECT * FROM gateway_orders WHERE order_ref = ? AND gateway = 'cashfree'", [$ref]);
    if (!$o || in_array($o['status'], ['paid', 'review', 'failed'], true) || !$o['link_url'] || !cashfree_ready()) {
        return $o;
    }
    $res = cashfree_api('GET', '/links/' . rawurlencode($ref));
    $link = $res['json'];
    if ($res['status'] !== 200 || !$link) {
        return $o;   // network / API hiccup: the webhook or the next status check tries again
    }
    $status = strtoupper((string) ($link['link_status'] ?? ''));
    if ($status === 'PAID' || fees_round((float) ($link['link_amount_paid'] ?? 0)) >= (float) $o['amount'] - 0.001) {
        $orders = cashfree_api('GET', '/links/' . rawurlencode($ref) . '/orders');
        $txn = '';
        foreach ((array) ($orders['json'] ?? []) as $ord) {
            if (is_array($ord) && strtoupper((string) ($ord['order_status'] ?? '')) === 'PAID') {
                $txn = (string) ($ord['cf_order_id'] ?? $ord['order_id'] ?? '');
                break;
            }
        }
        cashfree_record($o, $txn !== '' ? $txn : (string) ($link['cf_link_id'] ?? $ref), ['link' => $link, 'orders' => $orders['json']]);
    } elseif (in_array($status, ['EXPIRED', 'CANCELLED'], true)) {
        db_update('gateway_orders', ['status' => strtolower($status), 'response' => json_encode($link)], "id = ? AND status = 'created'", [(int) $o['id']]);
    }
    return db_row('SELECT * FROM gateway_orders WHERE id = ?', [(int) $o['id']]);
}

/** Post the receipt of a paid order exactly once (row lock), or park it as "review" when the invoice no longer takes it. */
function cashfree_record(array $o, string $txn, array $response): void
{
    $txn = mb_substr($txn, 0, 100);
    $json = json_encode($response);
    try {
        $r = db_transaction(function () use ($o, $txn, $json) {
            $row = db_row('SELECT * FROM gateway_orders WHERE id = ? FOR UPDATE', [(int) $o['id']]);
            if (!$row || $row['payment_id'] || in_array($row['status'], ['paid', 'review'], true)) {
                return null;
            }
            $r = fees_collect([
                'student_id' => (int) $row['student_id'], 'payment_date' => fees_today(), 'mode' => 'online', 'reference_no' => $txn,
                'allocations' => [['student_fee_id' => (int) $row['student_fee_id'], 'amount' => (float) $row['amount']]],
                'remarks' => 'Cashfree online payment ' . $row['order_ref'],
            ]);
            db_update('payments', ['gateway' => 'cashfree', 'gateway_txn_id' => $txn, 'gateway_response' => $json], 'id = ?', [(int) $r['id']]);
            db_update('gateway_orders', ['status' => 'paid', 'payment_id' => (int) $r['id'], 'gateway_txn_id' => $txn, 'paid_at' => date('Y-m-d H:i:s'),
                'status_note' => 'Receipt ' . $r['receipt_no'], 'response' => $json], 'id = ?', [(int) $row['id']]);
            return $r;
        });
    } catch (CrudValidationException $e) {
        // money received, but the invoice changed meanwhile (paid / cancelled at the counter, online mode switched off …)
        db_update('gateway_orders', ['status' => 'review', 'gateway_txn_id' => $txn, 'paid_at' => date('Y-m-d H:i:s'), 'response' => $json,
            'status_note' => mb_substr('Paid online but not posted automatically: ' . implode(' ', $e->errors ?: [$e->getMessage()]), 0, 255)], "id = ? AND status = 'created'", [(int) $o['id']]);
        $who = db_value("SELECT TRIM(CONCAT(first_name, ' ', last_name, ' (', student_uid, ')')) FROM students WHERE id = ?", [(int) $o['student_id']]);
        notify('perm:fees', 'fees', 'Online payment needs review', sprintf('%s paid %s online (%s, Cashfree %s) but it could not be posted to the invoice. Record it manually.', $who, money((float) $o['amount'], 2), $o['order_ref'], $txn), 'admin/fees/payments');
        log_system('warning', 'Cashfree: paid order ' . $o['order_ref'] . ' needs manual posting', ['errors' => $e->errors]);
        return;
    }
    if ($r) {
        fees_email_receipt((int) $r['id']);
        $who = $r['student']['name'] . ' (' . $r['student']['student_uid'] . ')';
        notify('perm:fees', 'fees', 'Online fee payment received', sprintf('%s paid %s online — receipt %s.', $who, money($r['total'], 2), $r['receipt_no']), 'admin/fees/payments');
        try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_ONLINE_PAYMENT_SUCCESS', ['student_id' => (int) $o['student_id'], 'payment_id' => (int) $r['id'], 'order_ref' => $o['order_ref'], 'receipt_no' => $r['receipt_no'], 'amount' => (float) $r['total'], 'mode' => 'Online (Cashfree)', 'transaction_id' => $txn, 'payment_date' => date('d M Y'), 'receipt_link' => fees_receipt_verify_url($r['receipt_no'])], ['dedupe_key' => 'order:' . $o['id'] . ':paid', 'source' => 'payment']); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
    }
}

/** Verify a Cashfree webhook: Base64(HMAC-SHA256(timestamp + raw body, secret key)) = x-webhook-signature. */
function cashfree_verify_signature(string $raw, string $signature, string $timestamp): bool
{
    $secret = cashfree_config()['secret'];
    if ($secret === '' || $signature === '' || $timestamp === '' || $raw === '') {
        return false;
    }
    return hash_equals(base64_encode(hash_hmac('sha256', $timestamp . $raw, $secret, true)), $signature);
}

/** Our order_ref from a webhook payload (payment link events carry link_id; order events carry the link in order_tags). */
function cashfree_webhook_ref(array $payload): ?string
{
    $d = (array) ($payload['data'] ?? []);
    foreach ([$d['link_id'] ?? null, $d['order']['order_tags']['link_id'] ?? null, $d['order']['order_id'] ?? null] as $cand) {
        if (is_string($cand) && preg_match('/^[A-Za-z0-9_-]{3,45}$/', $cand) && db_value('SELECT COUNT(*) FROM gateway_orders WHERE order_ref = ?', [$cand])) {
            return $cand;
        }
    }
    return null;
}
