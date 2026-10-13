<?php
/**
 * AI chatbot administration — /api/chatbot/* (permission module "communication"). Logic: app/services/chatbot.php.
 *
 *   GET    /api/chatbot/settings                      settings (API keys masked), providers, knowledge sources, warnings
 *   POST   /api/chatbot/settings                      save (JSON or multipart: avatar file, <provider>_key to replace a key,
 *                                                     clear_<provider>_key = 1 to remove it)                  [edit]
 *   POST   /api/chatbot/test                          {provider, model?, key?} tiny real request → {ok, message, latency_ms} [edit]
 *   POST   /api/chatbot/try                           {message, history?[]} answer without storing (admin preview)
 *   GET    /api/chatbot/conversations                 ?q, lead, feedback, provider, language, from, to, sort, dir, page, per_page
 *   GET    /api/chatbot/conversations/export          same filters + format=csv|xlsx (+ ids[])                   [export]
 *   GET    /api/chatbot/conversations/{id}            transcript + metadata
 *   DELETE /api/chatbot/conversations/{id}                                                                   [delete]
 *   POST   /api/chatbot/conversations/delete          {ids: []}                                                [delete]
 *   GET    /api/chatbot/analytics                     ?days=7|30|90
 *   GET    /api/chatbot/logs                          recent provider calls that failed + connection tests
 *   POST   /api/chatbot/purge                         apply the retention period now                           [delete]
 */
require_once APP_ROOT . '/app/services/chatbot.php';
require_once APP_ROOT . '/app/services/public_forms.php';

route('GET', '/chatbot/settings', function () {
    api_require('communication', 'view');
    api_ok(chatbot_admin_settings());
});

route('POST', '/chatbot/settings', function () {
    api_require('communication', 'edit');
    $in = api_input();
    $errors = chatbot_validate_settings($in);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $changed = chatbot_save_settings($in, $_FILES['avatar'] ?? null);
    $keys = array_values(array_filter($changed, fn ($c) => str_contains($c, '_key')));
    log_activity('update', 'communication', null, 'Updated AI chatbot settings' . ($changed ? ': ' . mb_substr(implode(', ', $changed), 0, 300) : ' (no changes)'), 'success',
        ['changed' => $changed, 'provider' => setting('chatbot_provider')]);
    api_ok(chatbot_admin_settings(), $keys ? 'Chatbot settings saved. API keys are stored encrypted and never shown again.' : 'Chatbot settings saved.');
});

route('POST', '/chatbot/test', function () {
    api_require('communication', 'edit');
    $in = api_validate(['provider' => 'required|in:anthropic,openai,gemini', 'model' => 'max:80', 'key' => 'max:400'], null, ['provider' => 'Provider']);
    if (!rate_limit('chatbot-test:' . user_id(), 20, 600)) {
        api_error('Too many connection tests. Please wait a few minutes.', 429);
    }
    $res = chatbot_test_provider($in['provider'], $in['model'], $in['key']);
    log_activity('test', 'communication', null, 'AI chatbot connection test — ' . chatbot_providers()[$in['provider']]['label'] . ' (' . $res['model'] . '): ' . ($res['ok'] ? 'OK' : 'failed — ' . mb_substr($res['message'], 0, 200)),
        $res['ok'] ? 'success' : 'failed');
    api_ok($res);
});

route('POST', '/chatbot/try', function () {
    api_require('communication', 'view');
    $in = api_input();
    $message = trim((string) ($in['message'] ?? ''));
    if ($message === '' || mb_strlen($message) > 2000) {
        api_error('Type a question of up to 2000 characters.', 422, ['message' => 'Type a question of up to 2000 characters.']);
    }
    if (!rate_limit('chatbot-try:' . user_id(), 40, 600)) {
        api_error('Too many test questions. Please wait a few minutes.', 429);
    }
    $history = is_array($in['history'] ?? null) ? array_slice($in['history'], -10) : [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $ans = chatbot_answer($history, $message, ['page' => '/admin (preview)']);
    unset($ans['usage']['raw']);
    api_ok($ans);
});

route('GET', '/chatbot/conversations', function () {
    api_require('communication', 'view');
    [$page, $perPage] = api_pagination(20);
    api_ok(chatbot_admin_list($_GET, $page, $perPage));
});

route('GET', '/chatbot/conversations/export', function () {
    api_require('communication', 'export');
    [$where, $args] = chatbot_admin_where($_GET);
    $rows = db_all("SELECT c.* FROM chatbot_conversations c WHERE $where ORDER BY c.started_at DESC LIMIT 5000", $args);
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    $transcripts = [];
    if ($ids) {
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (db_all('SELECT conversation_id, role, content FROM chatbot_messages WHERE conversation_id IN (' . implode(',', $chunk) . ') ORDER BY id', []) as $m) {
                $who = ['user' => 'Visitor', 'assistant' => 'Bot', 'event' => 'Event'][$m['role']] ?? $m['role'];
                $transcripts[(int) $m['conversation_id']][] = $who . ': ' . preg_replace('/\s+/', ' ', $m['content']);
            }
        }
    }
    $headers = ['ID', 'Started', 'Last message', 'Messages', 'Visitor questions', 'Language', 'Lead status', 'Lead name', 'Enquiry ID', 'Helpful', 'Not helpful',
        'Provider', 'Page', 'UTM source', 'UTM campaign', 'Device', 'First question', 'Transcript'];
    $out = array_map(fn ($r) => [
        (int) $r['id'], $r['started_at'], $r['last_message_at'], (int) $r['message_count'], (int) $r['user_message_count'], $r['language'], $r['lead_status'], $r['lead_name'],
        $r['enquiry_id'], (int) $r['feedback_up'], (int) $r['feedback_down'], $r['last_provider'], $r['page_url'], $r['utm_source'], $r['utm_campaign'], browser_label($r['user_agent']),
        $r['title'], mb_substr(implode(' | ', $transcripts[(int) $r['id']] ?? []), 0, 30000),
    ], $rows);
    log_activity('export', 'communication', null, 'Exported ' . count($out) . ' AI chatbot conversation(s)');
    $name = 'chatbot-conversations-' . date('Y-m-d');
    if (($_GET['format'] ?? 'csv') === 'xlsx') {
        xlsx_download($name . '.xlsx', $headers, $out, 'Conversations');
    }
    csv_download($name . '.csv', $headers, $out);
});

route('GET', '/chatbot/conversations/{id:\d+}', function ($p) {
    api_require('communication', 'view');
    $c = chatbot_admin_conversation((int) $p['id']);
    if (!$c) {
        api_error('Conversation not found.', 404);
    }
    api_ok($c);
});

route('DELETE', '/chatbot/conversations/{id:\d+}', function ($p) {
    api_require('communication', 'delete');
    $c = db_row('SELECT id, title, started_at FROM chatbot_conversations WHERE id = ?', [(int) $p['id']]);
    if (!$c) {
        api_error('Conversation not found.', 404);
    }
    db_exec('DELETE FROM chatbot_conversations WHERE id = ?', [(int) $c['id']]);
    log_activity('delete', 'communication', $c['id'], 'Deleted AI chatbot conversation #' . $c['id'] . ' from ' . format_datetime($c['started_at']) . ($c['title'] ? ' ("' . mb_substr($c['title'], 0, 80) . '")' : ''));
    api_ok(null, 'Conversation deleted.');
});

route('POST', '/chatbot/conversations/delete', function () {
    api_require('communication', 'delete');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) (api_input()['ids'] ?? [])))));
    if (!$ids || count($ids) > 500) {
        api_error('Select between 1 and 500 conversations.', 422);
    }
    $n = db_exec('DELETE FROM chatbot_conversations WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    log_activity('delete', 'communication', null, 'Deleted ' . $n . ' AI chatbot conversation(s)', 'success', ['ids' => $ids]);
    api_ok(['deleted' => $n], $n . ' conversation' . ($n === 1 ? '' : 's') . ' deleted.');
});

route('GET', '/chatbot/analytics', function () {
    api_require('communication', 'view');
    api_ok(chatbot_analytics((int) ($_GET['days'] ?? 30)));
});

route('GET', '/chatbot/logs', function () {
    api_require('communication', 'view');
    $rows = db_all('SELECT l.id, l.provider, l.model, l.kind, l.status, l.http_status, l.error_type, l.message, l.latency_ms, l.created_at, u.name AS user_name
                    FROM chatbot_provider_logs l LEFT JOIN users u ON u.id = l.created_by ORDER BY l.id DESC LIMIT 50');
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['latency_ms'] = $r['latency_ms'] !== null ? (int) $r['latency_ms'] : null;
        $r['hint'] = $r['status'] === 'error' ? chatbot_error_hint((string) $r['error_type'], chatbot_providers()[$r['provider']]['label'] ?? $r['provider']) : '';
    }
    unset($r);
    api_ok($rows);
});

route('POST', '/chatbot/purge', function () {
    api_require('communication', 'delete');
    $days = (int) chatbot_cfg('retention_days');
    $n = chatbot_purge();
    log_activity('delete', 'communication', null, 'AI chatbot retention: deleted ' . $n . ' conversation(s) older than ' . $days . ' days');
    api_ok(['deleted' => $n], $n ? $n . ' old conversation' . ($n === 1 ? '' : 's') . ' deleted.' : 'Nothing to delete — no conversations are older than ' . $days . ' days.');
});
