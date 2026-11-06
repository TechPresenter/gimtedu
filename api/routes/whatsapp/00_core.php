<?php
/**
 * WhatsApp core routes (/api/whatsapp/...). Loaded by api/routes/whatsapp.php after app/services/whatsapp.php.
 *
 *   POST     whatsapp/webhook                 Interakt webhook (no login / CSRF; ≤ 128 KB; signature checked before storing;
 *                                            unsigned webhooks ignored outside dev installs; always 200 for valid JSON)
 *   GET|POST whatsapp/cron?token=            background worker for the server cron (token = wa_cron_token())
 *   GET      whatsapp/status                  mode, configuration, queue sizes, worker health (any WhatsApp view)
 *   POST     whatsapp/worker/run              run one worker tick now (manage)
 *   POST     whatsapp/simulate/inbound        test tool: pretend a contact sent a message (manage; test mode)
 *   POST     whatsapp/simulate/status         test tool: pretend Interakt reported sent / delivered / read / failed
 *   POST     whatsapp/media/upload            store a public copy of a media file for sending (multipart "file")
 *   GET      whatsapp/media/{messageId}       stream a message's stored media (inbound copies are private)
 *   GET      whatsapp/variables               GIMT variable catalog for templates / mapping
 */

/** Pairs allowed to run the worker / simulators. */
function wa_core_manage_pairs(): array
{
    return [['whatsapp_settings', 'manage'], ['whatsapp', 'manage']];
}

/** Simulators are test tools: blocked in live mode unless wa_allow_simulate_live = 1. */
function wa_core_simulate_guard(): void
{
    wa_require_any(wa_core_manage_pairs(), 'use the test tools');
    if (wa_mode() === 'live' && wa_setting('wa_allow_simulate_live', '0') !== '1') {
        api_error('Simulated webhooks are disabled in live mode. Enable "Allow test tools in live mode" in WhatsApp settings to use them.', 409);
    }
}

/* ---------------------------------------------------------------------------------------------- webhook */
route('POST', '/whatsapp/webhook', function () {
    // Interakt webhooks are a few KB: anything over 128 KB is refused before it is read in full
    $max = wa_webhook_max_bytes();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) {
        api_error('Payload too large.', 413);
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
    if (strlen($raw) > $max) {
        api_error('Payload too large.', 413);
    }
    // an HTTP request is never a built-in simulation, whatever the server environment holds
    $server = $_SERVER;
    unset($server['WA_SIMULATED']);
    // The signature is checked before anything is stored. Requests that will not be processed (unsigned on a non-dev
    // install, wrong signature) get 300 per minute per IP; requests signed with the saved secret can only come from
    // Interakt and get a higher limit, so the delivery ticks of a busy campaign are never answered 429 (Interakt turns a
    // webhook off after 5 failed deliveries in 10 minutes).
    $verdict = wa_webhook_verdict($raw, $server);
    if (!rate_limit('wa-webhook:' . ($verdict['trusted'] ? 'signed:' : '') . client_ip(), $verdict['trusted'] ? 3000 : 300, 60)) {
        api_error('Too many requests.', 429);
    }
    if (!is_array(json_decode($raw, true))) {
        api_error('Invalid JSON.', 400);
    }
    $r = wa_webhook_receive($raw, $server);

    // answer Interakt at once (3 s limit, no retries); process afterwards when the SAPI can finish the request early,
    // otherwise the worker (cron / opportunistic ticks) processes the stored row.
    $body = (string) json_encode(['ok' => true, 'message' => '', 'data' => ['received' => $r['ok'], 'id' => $r['id']]]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: no-store');
    }
    echo $body;
    $finished = false;
    if (function_exists('fastcgi_finish_request')) {
        $finished = (bool) fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        $finished = true;
    }
    if ($finished && $r['ok']) {
        ignore_user_abort(true);
        @set_time_limit(40);
        try {
            wa_as_worker(function () { // background work: no user actor (wa_in_worker())
                wa_process_webhooks(8.0);
                wa_process_outbox(6.0, 50);
            });
        } catch (Throwable $e) {
            error_log('[GIMT] WhatsApp webhook post-processing failed: ' . $e->getMessage());
        }
    }
    exit;
}, ['auth' => false, 'csrf' => false]);

/* ---------------------------------------------------------------------------------------------- cron */
$waCoreCron = function () {
    $token = api_query('token') ?: trim((string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? ''));
    if ($token === '' || !hash_equals(wa_cron_token(), $token)) {
        api_error('Invalid token.', 403);
    }
    if (!rate_limit('wa-cron:' . client_ip(), 240, 3600)) {
        api_error('Too many requests.', 429);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $stats = wa_worker_tick(20.0);
    api_ok($stats, empty($stats['ok']) ? (string) ($stats['skipped'] ?? 'Skipped') : 'WhatsApp worker run complete.');
};
route('GET', '/whatsapp/cron', $waCoreCron, ['auth' => false, 'csrf' => false]);
route('POST', '/whatsapp/cron', $waCoreCron, ['auth' => false, 'csrf' => false]);

/* ---------------------------------------------------------------------------------------------- status */
route('GET', '/whatsapp/status', function () {
    wa_require_any(wa_any_view_pairs(), 'view this');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    // answer first, then run the opportunistic worker tick (only when the cron is missing) so a slow tick handler can
    // never make status polling slow
    wa_respond_then_continue(['ok' => true, 'message' => '', 'data' => wa_status_summary()]);
    wa_maybe_tick(2.5, false); // the response is already out — tick inline
    exit;
});

route('POST', '/whatsapp/worker/run', function () {
    wa_require_any(wa_core_manage_pairs(), 'run the worker');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $stats = wa_worker_tick(20.0);
    if (empty($stats['ok'])) {
        api_error((string) ($stats['skipped'] ?? 'The worker is busy. Try again in a moment.'), 409);
    }
    $sent = (int) ($stats['outbox']['sent'] ?? 0) + (int) ($stats['outbox']['simulated'] ?? 0);
    $hooks = (int) ($stats['webhooks']['processed'] ?? 0);
    log_activity('run', 'whatsapp_settings', null, 'Ran the WhatsApp worker manually (' . $sent . ' sent, ' . $hooks . ' webhooks processed)');
    api_ok($stats, 'Worker run complete: ' . $sent . ' message(s) sent, ' . $hooks . ' webhook(s) processed.');
});

/* ---------------------------------------------------------------------------------------------- simulators */
route('POST', '/whatsapp/simulate/inbound', function () {
    wa_core_simulate_guard();
    $in = api_validate([
        'phone' => 'required|max:30',
        'text' => 'max:4096',
        'type' => 'in:text,image,document,audio,video,location,button_reply,list_reply,sticker',
        'media_url' => 'url|max:500',
        'name' => 'max:150',
    ], null, ['phone' => 'WhatsApp number', 'text' => 'Message', 'media_url' => 'Media URL']);
    $type = $in['type'] ?? 'text';
    $norm = wa_normalize_phone((string) $in['phone']);
    if (!$norm) {
        api_error('Please fix the highlighted fields and try again.', 422, ['phone' => 'Enter a valid WhatsApp number.']);
    }
    if (in_array($type, ['text', 'button_reply', 'list_reply'], true) && trim((string) ($in['text'] ?? '')) === '') {
        api_error('Please fix the highlighted fields and try again.', 422, ['text' => 'Type the message the contact sends.']);
    }
    if (in_array($type, ['image', 'document', 'audio', 'video', 'sticker'], true) && empty($in['media_url'])) {
        api_error('Please fix the highlighted fields and try again.', 422, ['media_url' => 'A public media URL is required for this type.']);
    }
    if (!rate_limit('wa-sim:' . (int) user_id(), 120, 600)) {
        api_error('Too many simulated messages. Wait a few minutes.', 429);
    }
    $payload = wa_simulate_inbound_payload($norm['phone'], $in['text'] ?? null, $type, $in['media_url'] ?? null, $in['name'] ?? null);
    $r = wa_simulate_webhook($payload);
    $messageId = db_value('SELECT id FROM wa_messages WHERE interakt_id = ?', [$payload['data']['message']['id']]);
    $out = wa_process_outbox(3.0, 20); // bot / automation replies show up at once
    log_activity('create', 'whatsapp', $messageId ? (int) $messageId : null, 'Simulated an inbound WhatsApp message from +' . $norm['phone']);
    api_ok(['webhook_log_id' => $r['webhook_log_id'], 'status' => $r['status'], 'note' => $r['result']['note'] ?? null,
        'message_id' => $messageId ? (int) $messageId : null, 'outbox' => $out],
        $r['status'] === 'processed' ? 'Simulated message received.' : 'Webhook stored but not processed: ' . ($r['result']['note'] ?? $r['status']));
});

route('POST', '/whatsapp/simulate/status', function () {
    wa_core_simulate_guard();
    $in = api_validate(['message_id' => 'required|integer', 'status' => 'required|in:sent,delivered,read,failed', 'error' => 'max:255'],
        null, ['message_id' => 'Message', 'status' => 'Status']);
    $m = db_row('SELECT * FROM wa_messages WHERE id = ?', [(int) $in['message_id']]);
    if (!$m || $m['direction'] !== 'out') {
        api_error('Outbound WhatsApp message not found.', 404);
    }
    if ($m['status'] === 'queued') {
        wa_process_outbox(3.0, 50);
        $m = db_row('SELECT * FROM wa_messages WHERE id = ?', [(int) $m['id']]);
    }
    if ($m['status'] === 'queued') {
        api_error('This message is still queued (scheduled later or throttled). Run the worker, then try again.', 409);
    }
    if ($m['status'] === 'cancelled') {
        api_error('This message was cancelled.', 409);
    }
    $r = wa_simulate_webhook(wa_simulate_status_payload($m, (string) $in['status'], $in['error'] ?? null));
    $now = db_value('SELECT status FROM wa_messages WHERE id = ?', [(int) $m['id']]);
    log_activity('update', 'whatsapp', (int) $m['id'], 'Simulated WhatsApp status "' . $in['status'] . '" for message #' . $m['id']);
    api_ok(['webhook_log_id' => $r['webhook_log_id'], 'status' => $r['status'], 'message_status' => $now, 'note' => $r['result']['note'] ?? null],
        'Status updated to ' . $now . '.');
});

/* ---------------------------------------------------------------------------------------------- media */
route('POST', '/whatsapp/media/upload', function () {
    wa_require_any([['whatsapp', 'create'], ['whatsapp_templates', 'create'], ['whatsapp_templates', 'edit'], ['whatsapp_campaigns', 'create'],
        ['whatsapp_campaigns', 'edit'], ['whatsapp_automation', 'create'], ['whatsapp_automation', 'edit']], 'upload media');
    if (!rate_limit('wa-media:' . (int) user_id(), 120, 600)) {
        api_error('Too many uploads. Wait a few minutes.', 429);
    }
    $kind = api_str($_POST['type'] ?? null);
    if ($kind !== '' && !in_array($kind, ['image', 'video', 'audio', 'document', 'sticker'], true)) {
        api_error('Please fix the highlighted fields and try again.', 422, ['type' => 'Unknown media type.']);
    }
    $r = wa_media_store_upload($_FILES['file'] ?? null, $kind !== '' ? $kind : null);
    if (!$r['ok']) {
        api_error($r['error'], 422, ['file' => $r['error']]);
    }
    unset($r['ok']);
    log_activity('upload', 'whatsapp', null, 'Uploaded WhatsApp media ' . $r['name'] . ' (' . $r['type'] . ', ' . human_filesize((int) $r['size']) . ')');
    api_ok($r, 'File uploaded.');
});

route('GET', '/whatsapp/media/{id:\d+}', function (array $p) {
    wa_require_any([['whatsapp', 'view'], ['whatsapp_contacts', 'view'], ['whatsapp_campaigns', 'view'], ['whatsapp_automation', 'view']], 'view media');
    $m = db_row('SELECT m.id, m.media_url, m.media_local, m.media_mime, m.file_name, m.type, c.assigned_to
                   FROM wa_messages m LEFT JOIN wa_conversations c ON c.id = m.conversation_id WHERE m.id = ?', [(int) $p['id']]);
    if (!$m || (empty($m['media_local']) && empty($m['media_url']))) {
        api_error('Media not found.', 404);
    }
    // inbox-only users (counsellors) see the media of their own and unassigned chats only — same rule as the inbox
    $broad = wa_can_any([['whatsapp', 'manage'], ['whatsapp_contacts', 'view'], ['whatsapp_campaigns', 'view'], ['whatsapp_automation', 'view']]);
    if (!$broad && !empty($m['assigned_to']) && (int) $m['assigned_to'] !== (int) user_id()) {
        api_error('This conversation is assigned to another agent.', 403);
    }
    if (!empty($m['media_local'])) {
        $base = realpath(APP_ROOT . '/storage/whatsapp');
        $file = realpath(APP_ROOT . '/' . ltrim(str_replace('\\', '/', (string) $m['media_local']), '/'));
        if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            api_error('The media file is no longer available.', 404);
        }
        $mime = (string) ($m['media_mime'] ?: ((new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream'));
        $inline = (bool) preg_match('#^(image/(jpeg|png|webp|gif)|audio/|video/|application/pdf$)#', $mime);
        $name = $m['file_name'] ?: ('whatsapp-' . $m['id'] . '.' . pathinfo($file, PATHINFO_EXTENSION));
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . filesize($file));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
        header('X-Content-Type-Options: nosniff');
        // a sandboxed document cannot host the browser's PDF viewer, so verified PDFs (finfo, nosniff) are served without
        // CSP; every other type is sandboxed
        if ($mime !== 'application/pdf') {
            header('Content-Security-Policy: sandbox; default-src \'none\'; img-src \'self\'; media-src \'self\'');
        }
        header('Cache-Control: private, max-age=86400');
        readfile($file);
        exit;
    }
    $url = (string) $m['media_url'];
    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        $url = absolute_url(ltrim($url, '/')); // seeded / site-relative media
    }
    if (preg_match('#^https?://[^\s]+$#i', $url)) {
        header('Location: ' . str_replace(["\r", "\n"], '', $url), true, 302);
        exit;
    }
    api_error('Media not found.', 404);
});

/* ---------------------------------------------------------------------------------------------- variables */
route('GET', '/whatsapp/variables', function () {
    wa_require_any(wa_any_view_pairs(), 'view this');
    api_ok(array_values(wa_variable_catalog()));
});
