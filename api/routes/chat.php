<?php
/**
 * Public AI chatbot API for the website widget (no login) — /api/chat/*. Logic: app/services/chatbot.php.
 *
 *   GET  /api/chat/config     non-secret widget settings (also printed as island props by includes/partials/chatbot.php)
 *   POST /api/chat/message    {conversation_id?, message, page?, referrer?, utm_source?, utm_medium?, utm_campaign?, website}
 *                             → {conversation_id, message_id, reply, sources[], suggestions[], lead_prompt, lead_prefill?, created_at}
 *   POST /api/chat/stream     same input → text/event-stream (events: meta, delta {t}, done {…as /message}, error {message})
 *                             when the "streaming" setting is on; otherwise the same JSON as /message
 *   POST /api/chat/lead       {conversation_id?, name, phone, email?, program_id?, message?, website} → enquiry (source = chatbot)
 *   POST /api/chat/feedback   {conversation_id, message_id, value: up|down|none}
 *   GET  /api/chat/history    ?conversation_id=… → the visitor's own conversation (resume after navigation)
 *
 * Every POST: CSRF header (X-CSRF-Token from <meta name="csrf-token">, enforced by the router → 419), honeypot field
 * "website" (must be empty → 422), per-IP + per-conversation rate limits (429 + Retry-After), input length limits.
 * The browser only ever sees the random conversation token, never numeric ids of other visitors' data.
 */
require_once APP_ROOT . '/app/services/chatbot.php';
require_once APP_ROOT . '/app/services/public_forms.php';

$chatOpts = ['auth' => false];

function chat_require_enabled(): void
{
    if (!chatbot_flag('enabled')) {
        api_error('The chat assistant is not available right now. Please call or WhatsApp the admissions office.', 503);
    }
}

function chat_rate(string $key, int $max, int $window, string $message): void
{
    if (rate_limit($key, $max, $window)) {
        return;
    }
    $reset = db_value('SELECT reset_at FROM rate_limits WHERE rl_key = ?', [substr($key, 0, 190)]);
    $wait = $reset ? max(1, strtotime((string) $reset) - time()) : $window;
    header('Retry-After: ' . $wait);
    log_system('warning', 'Chatbot rate limit reached', ['key' => preg_replace('/:[^:]+$/', ':…', $key)]);
    api_error(str_replace('{wait}', $wait > 90 ? (int) ceil($wait / 60) . ' minutes' : 'a minute', $message), 429);
}

/** The visitor's own conversation from a token, or null. */
function chat_owned_conversation($token): ?array
{
    $conv = chatbot_conversation_get(trim((string) $token));
    return $conv && chatbot_conversation_owned($conv) ? $conv : null;
}

/**
 * Shared checks for /message and /stream. Stores the visitor message and returns
 * [$conv, $userMessageId, $history, $text, $page]. Responds with JSON errors (419 by the router, 422, 429, 503).
 */
function chat_prepare_message(): array
{
    chat_require_enabled();
    $in = api_input();
    if (trim((string) ($in['website'] ?? '')) !== '') {
        log_system('warning', 'Chatbot message blocked (honeypot)', ['ip' => client_ip(), 'ua' => user_agent()]);
        api_error('Your message could not be sent. Please refresh the page and try again.', 422);
    }
    $text = (string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", (string) ($in['message'] ?? '')));
    $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    $max = max(100, min(2000, (int) chatbot_cfg('max_input')));
    if ($text === '') {
        api_error('Please type a message.', 422, ['message' => 'Please type a message.']);
    }
    if (mb_strlen($text) > $max) {
        api_error('Messages can be at most ' . $max . ' characters.', 422, ['message' => 'Messages can be at most ' . $max . ' characters.']);
    }
    chat_rate('chat:ip:' . client_ip(), max(5, (int) chatbot_cfg('rate_ip')), 600, 'You are sending messages too quickly. Please wait {wait} and try again — or call the admissions office.');

    $conv = !empty($in['conversation_id']) ? chat_owned_conversation($in['conversation_id']) : null;
    if ($conv) {
        chat_rate('chat:conv:' . $conv['public_id'], 12, 60, 'Please slow down a little — wait {wait} before sending the next message.');
        if ((int) $conv['user_message_count'] >= 80) {
            api_error('This chat has become very long. Please start a new chat or talk to a counsellor.', 429);
        }
    } else {
        $conv = chatbot_conversation_create([
            'page' => $in['page'] ?? null, 'referrer' => $in['referrer'] ?? null,
            'utm_source' => $in['utm_source'] ?? null, 'utm_medium' => $in['utm_medium'] ?? null, 'utm_campaign' => $in['utm_campaign'] ?? null,
        ]);
    }
    chatbot_remember_conversation($conv['public_id']);
    $intents = chatbot_detect_intents($text);
    $intent = $intents[0] ?? (chatbot_detect_programs($text) ? 'program_detail' : 'unknown');
    $userId = chatbot_message_add((int) $conv['id'], 'user', $text, ['intent' => $intent, 'lang' => chatbot_detect_language($text)]);
    $history = chatbot_history_for((int) $conv['id'], $userId);
    $page = chatbot_safe_url(mb_substr(trim((string) ($in['page'] ?? '')), 0, 300));
    // release the session lock before the (possibly slow) AI call so the visitor can keep browsing
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    return [$conv, $userId, $history, $text, $page && $page[0] === '/' ? $page : null];
}

/** Store the assistant reply and build the response payload. */
function chat_finish(array $conv, array $ans): array
{
    $id = chatbot_message_add((int) $conv['id'], 'assistant', $ans['reply'], [
        'intent' => $ans['intent'], 'provider' => $ans['provider'], 'model' => $ans['model'], 'sources' => $ans['sources'], 'usage' => $ans['usage'],
        'latency_ms' => $ans['latency_ms'], 'errors' => $ans['errors'], 'lead_prompt' => $ans['lead_prompt'],
    ]);
    if (mt_rand(1, 50) === 1) {
        chatbot_maybe_purge();
    }
    return [
        'conversation_id' => $conv['public_id'], 'message_id' => $id, 'reply' => $ans['reply'], 'sources' => $ans['sources'], 'suggestions' => $ans['suggestions'],
        'lead_prompt' => $ans['lead_prompt'], 'lead_prefill' => $ans['lead_prefill'], 'created_at' => date('c'),
    ];
}

route('GET', '/chat/config', function () {
    api_ok(chatbot_public_config());
}, $chatOpts);

route('POST', '/chat/message', function () {
    [$conv, , $history, $text, $page] = chat_prepare_message();
    $ans = chatbot_answer($history, $text, ['page' => $page]);
    api_ok(chat_finish($conv, $ans));
}, $chatOpts);

route('POST', '/chat/stream', function () {
    [$conv, , $history, $text, $page] = chat_prepare_message();
    if (!chatbot_flag('streaming')) {
        api_ok(chat_finish($conv, chatbot_answer($history, $text, ['page' => $page])));
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    ob_implicit_flush(true);
    ignore_user_abort(true);
    @set_time_limit(120);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, no-transform');
    header('X-Accel-Buffering: no');
    $send = function (string $event, array $data): void {
        echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
        @flush();
    };
    echo ':' . str_repeat(' ', 2048) . "\n\n"; // defeat proxy buffering of the first bytes
    $send('meta', ['conversation_id' => $conv['public_id']]);
    try {
        $ans = chatbot_answer($history, $text, ['page' => $page, 'on_delta' => fn (string $t) => $send('delta', ['t' => $t])]);
        $send('done', chat_finish($conv, $ans));
    } catch (Throwable $e) {
        log_system('error', 'Chatbot stream failed', ['error' => $e->getMessage()]);
        $send('error', ['message' => 'Sorry, something went wrong. Please try again.']);
    }
    exit;
}, $chatOpts);

route('POST', '/chat/lead', function () {
    chat_require_enabled();
    if (!chatbot_flag('lead_capture')) {
        api_error('Callback requests are not available in the chat right now. Please use the contact page.', 403);
    }
    $in = api_input();
    $conv = !empty($in['conversation_id']) ? chat_owned_conversation($in['conversation_id']) : null;
    try {
        pf_guard('chatbot-lead', $in, 5, 600);
        $res = chatbot_submit_lead($in, $conv);
    } catch (PublicFormException $e) {
        if ($e->status === 429) {
            header('Retry-After: ' . (int) ($GLOBALS['__pf_retry_after'] ?? 600));
        }
        api_error($e->getMessage(), $e->status, $e->errors);
    }
    api_ok(['ok' => true], $res['message']);
}, $chatOpts);

route('POST', '/chat/feedback', function () {
    chat_require_enabled();
    $in = api_input();
    $conv = chat_owned_conversation($in['conversation_id'] ?? '');
    if (!$conv) {
        api_error('This conversation was not found.', 404);
    }
    chat_rate('chat:fb:' . client_ip(), 60, 600, 'Too many ratings. Please wait {wait}.');
    $value = match ((string) ($in['value'] ?? '')) {
        'up' => 1,
        'down' => -1,
        default => null,
    };
    chatbot_set_feedback($conv, (int) ($in['message_id'] ?? 0), $value);
    api_ok(['value' => $value === 1 ? 'up' : ($value === -1 ? 'down' : null)], $value === null ? '' : 'Thanks for your feedback!');
}, $chatOpts);

route('GET', '/chat/history', function () {
    chat_rate('chat:hist:' . client_ip(), 60, 600, 'Too many requests. Please wait {wait}.');
    $conv = chat_owned_conversation($_GET['conversation_id'] ?? '');
    if (!$conv) {
        api_error('This conversation was not found.', 404);
    }
    $messages = array_values(array_filter(chatbot_conversation_messages((int) $conv['id']), fn ($m) => in_array($m['role'], ['user', 'assistant'], true)));
    api_ok(['conversation_id' => $conv['public_id'], 'lead_status' => $conv['lead_status'], 'messages' => $messages]);
}, $chatOpts);
