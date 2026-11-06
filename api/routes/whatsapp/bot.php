<?php
/**
 * WhatsApp AI chatbot (Module 9) — /api/whatsapp/bot/*. Logic: app/services/whatsapp_bot.php (loaded by the core service).
 * Permission module whatsapp_automation (view / create / edit / delete / publish = switch the bot on or off); the session
 * summary for the inbox right panel is also readable with whatsapp or whatsapp_contacts view.
 *
 *   GET    whatsapp/bot/settings                     settings, texts, qualification questions, AI provider status (no keys)
 *   POST   whatsapp/bot/settings                     save (edit; changing "enabled" also needs publish)
 *   POST   whatsapp/bot/toggle                       {enabled} switch the bot on / off                         [publish]
 *   GET    whatsapp/bot/overview?days=7|30|90        KPIs, daily chart, intents, languages, hand-over reasons, top questions
 *   POST   whatsapp/bot/test                         {message, payload?, state, history, contact} test console (nothing sent)
 *   GET    whatsapp/bot/knowledge                    WhatsApp KB answers (?q, category, status)
 *   POST   whatsapp/bot/knowledge                    create (+ turn_ids: mark those unanswered questions reviewed) [create]
 *   PUT    whatsapp/bot/knowledge/{id}               update                                                    [edit]
 *   POST   whatsapp/bot/knowledge/{id}/verify        mark verified by me now                                   [edit]
 *   DELETE whatsapp/bot/knowledge/{id}                                                                         [delete]
 *   GET    whatsapp/bot/faqs                         CMS FAQs with their WhatsApp switch
 *   POST   whatsapp/bot/faqs/{id}/toggle             {enabled} use / do not use a CMS FAQ on WhatsApp           [edit]
 *   GET    whatsapp/bot/courses                      website programs with the "verified for WhatsApp" flag + data checks
 *   POST   whatsapp/bot/courses/{programId}          {verified, note}                                          [edit]
 *   POST   whatsapp/bot/courses/bulk                 {ids[], verified}                                         [edit]
 *   GET    whatsapp/bot/unanswered?days=             grouped questions the bot could not answer
 *   POST   whatsapp/bot/unanswered/dismiss           {turn_ids[]}                                              [edit]
 *   GET    whatsapp/bot/sessions                     ?q, state, language, qualified, from, to, page, per_page
 *   GET    whatsapp/bot/sessions/{contactId}         one contact's bot session (language, qualification, summary, turns)
 *   POST   whatsapp/bot/sessions/{contactId}/summary regenerate the summary (AI when configured)              [whatsapp edit | automation edit]
 *   POST   whatsapp/bot/sessions/{contactId}/reset   restart the bot session (qualification, counters)         [edit]
 */

/** 403 unless the user holds whatsapp_automation.<action> (message spells WhatsApp correctly). */
function wa_bot_require(string $action): void
{
    $what = ['view' => 'view the AI chatbot', 'create' => 'add AI bot answers', 'edit' => 'change the AI chatbot', 'delete' => 'delete AI bot answers', 'publish' => 'switch the AI bot on or off'][$action] ?? 'do this';
    wa_require_any([['whatsapp_automation', $action]], $what);
}

/** Pairs that may read a contact's bot session (inbox right panel, contact page, chatbot screen). */
function wa_bot_session_view_pairs(): array
{
    return [['whatsapp_automation', 'view'], ['whatsapp', 'view'], ['whatsapp_contacts', 'view']];
}

/**
 * Inbox-only users (whatsapp view, no chatbot / contacts access) follow the inbox rule: a counsellor sees chats assigned
 * to them and unassigned ones, not a colleague's chat (summaries quote the conversation).
 */
function wa_bot_require_session_access(int $contactId): void
{
    if (can('whatsapp_automation', 'view') || can('whatsapp_contacts', 'view') || !function_exists('wa_inbox_visible')) {
        return;
    }
    $conv = db_row('SELECT id, assigned_to FROM wa_conversations WHERE contact_id = ?', [$contactId]);
    if ($conv && !wa_inbox_visible($conv)) {
        api_error('This conversation is assigned to another team member.', 403);
    }
}

route('GET', '/whatsapp/bot/settings', function () {
    wa_bot_require('view');
    api_ok(wa_bot_settings_payload());
});

route('POST', '/whatsapp/bot/settings', function () {
    wa_bot_require('edit');
    $in = api_input();
    if (array_key_exists('enabled', $in) && filter_var($in['enabled'], FILTER_VALIDATE_BOOLEAN) !== wa_bot_enabled() && !can('whatsapp_automation', 'publish')) {
        api_error('Your role cannot switch the AI bot on or off. Ask a Super Admin for the WhatsApp Automations "publish" permission.', 403);
    }
    [$errors, $changed] = wa_bot_save_settings($in);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $labels = array_map(fn ($k) => str_replace(['wa_bot_text_', 'wa_bot_'], ['text ', ''], $k), $changed);
    log_activity('update', 'whatsapp_automation', null, 'Updated WhatsApp AI bot settings' . ($changed ? ': ' . mb_substr(implode(', ', $labels), 0, 400) : ' (no changes)'), 'success', ['changed' => $changed]);
    api_ok(wa_bot_settings_payload(), $changed ? 'AI bot settings saved.' : 'No changes to save.');
});

route('POST', '/whatsapp/bot/toggle', function () {
    wa_bot_require('publish');
    $on = filter_var(api_input()['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    save_setting('wa_bot_enabled', $on ? '1' : '0', 'whatsapp');
    log_activity($on ? 'enable' : 'disable', 'whatsapp_automation', null, ($on ? 'Switched on' : 'Switched off') . ' the WhatsApp AI bot');
    api_ok(['enabled' => $on], $on ? 'The AI bot now answers WhatsApp chats that are not handled by the team.' : 'The AI bot is off — incoming chats wait for the team.');
});

route('GET', '/whatsapp/bot/overview', function () {
    wa_bot_require('view');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    api_ok(wa_bot_stats((int) api_query('days', '30')));
});

route('POST', '/whatsapp/bot/test', function () {
    wa_bot_require('view');
    $in = api_input();
    $message = trim(api_str($in['message'] ?? null));
    $payload = api_str($in['payload'] ?? null);
    if (($message === '' && $payload === '') || mb_strlen($message) > 1000) {
        api_error('Please fix the highlighted fields and try again.', 422, ['message' => 'Type a message of up to 1000 characters.']);
    }
    if ($payload !== '' && !preg_match('/^bot:[a-z0-9_:]{1,60}$/', $payload)) {
        $payload = '';
    }
    if (!rate_limit('wa-bot-test:' . (int) user_id(), 60, 600)) {
        api_error('Too many test messages. Please wait a few minutes.', 429);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $state = is_array($in['state'] ?? null) ? $in['state'] : [];
    $history = is_array($in['history'] ?? null) ? $in['history'] : [];
    $contact = is_array($in['contact'] ?? null) ? $in['contact'] : [];
    api_ok(wa_bot_test($message, $state, $history, $contact, $payload !== '' ? $payload : null));
});

/* ---------------------------------------------------------------------------------------------- knowledge base */
route('GET', '/whatsapp/bot/knowledge', function () {
    wa_bot_require('view');
    $rows = wa_bot_kb_list(['q' => mb_substr(api_query('q'), 0, 100), 'category' => api_query('category'), 'status' => api_query('status')]);
    $counts = [];
    foreach (db_all('SELECT status, COUNT(*) n FROM wa_bot_knowledge GROUP BY status') as $r) {
        $counts[$r['status']] = (int) $r['n'];
    }
    api_ok(['rows' => $rows, 'categories' => wa_bot_kb_categories(), 'counts' => $counts, 'placeholders' => wa_bot_kb_vars()]);
});

route('POST', '/whatsapp/bot/knowledge', function () {
    wa_bot_require('create');
    $in = api_input();
    [$errors, $data] = wa_bot_kb_validate($in);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $uid = user_id();
    $data += ['created_by' => $uid, 'updated_by' => $uid];
    if ($data['status'] === 'active') {
        $data += ['verified_by' => $uid, 'verified_at' => date('Y-m-d H:i:s')];
    }
    $id = db_insert('wa_bot_knowledge', $data);
    $reviewed = !empty($in['turn_ids']) && is_array($in['turn_ids']) ? wa_bot_mark_reviewed($in['turn_ids'], $id) : 0;
    log_activity('create', 'whatsapp_automation', $id, 'Added WhatsApp bot answer "' . mb_substr($data['question'], 0, 120) . '"' . ($reviewed ? ' (from ' . $reviewed . ' unanswered question' . ($reviewed === 1 ? '' : 's') . ')' : ''));
    api_ok(['id' => $id], $data['status'] === 'active' ? 'Answer added — the bot uses it from now on.' : 'Answer saved as ' . $data['status'] . '.');
});

route('PUT', '/whatsapp/bot/knowledge/{id:\d+}', function (array $p) {
    wa_bot_require('edit');
    $old = db_row('SELECT * FROM wa_bot_knowledge WHERE id = ?', [(int) $p['id']]);
    if (!$old) {
        api_error('Answer not found.', 404);
    }
    [$errors, $data] = wa_bot_kb_validate(api_input());
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    $data['updated_by'] = user_id();
    $contentChanged = $data['answer_en'] !== $old['answer_en'] || (string) $data['answer_hi'] !== (string) $old['answer_hi'] || $data['question'] !== $old['question'];
    if ($data['status'] === 'active' && ($contentChanged || $old['status'] !== 'active' || !$old['verified_at'])) {
        $data['verified_by'] = user_id();
        $data['verified_at'] = date('Y-m-d H:i:s');
    }
    db_update('wa_bot_knowledge', $data, 'id = ?', [(int) $old['id']]);
    log_activity('update', 'whatsapp_automation', (int) $old['id'], 'Updated WhatsApp bot answer "' . mb_substr($data['question'], 0, 120) . '"' . ($old['status'] !== $data['status'] ? ' (' . $old['status'] . ' → ' . $data['status'] . ')' : ''));
    api_ok(['id' => (int) $old['id']], 'Answer updated.');
});

route('POST', '/whatsapp/bot/knowledge/{id:\d+}/verify', function (array $p) {
    wa_bot_require('edit');
    $old = db_row('SELECT id, question, status FROM wa_bot_knowledge WHERE id = ?', [(int) $p['id']]);
    if (!$old) {
        api_error('Answer not found.', 404);
    }
    db_update('wa_bot_knowledge', ['verified_by' => user_id(), 'verified_at' => date('Y-m-d H:i:s'), 'status' => $old['status'] === 'archived' ? 'archived' : 'active', 'updated_by' => user_id()], 'id = ?', [(int) $old['id']]);
    log_activity('approve', 'whatsapp_automation', (int) $old['id'], 'Verified WhatsApp bot answer "' . mb_substr($old['question'], 0, 120) . '"');
    api_ok(null, 'Marked as verified by you today.');
});

route('DELETE', '/whatsapp/bot/knowledge/{id:\d+}', function (array $p) {
    wa_bot_require('delete');
    $old = db_row('SELECT id, question FROM wa_bot_knowledge WHERE id = ?', [(int) $p['id']]);
    if (!$old) {
        api_error('Answer not found.', 404);
    }
    db_exec('UPDATE wa_bot_turns SET knowledge_id = NULL WHERE knowledge_id = ?', [(int) $old['id']]);
    db_exec('DELETE FROM wa_bot_knowledge WHERE id = ?', [(int) $old['id']]);
    log_activity('delete', 'whatsapp_automation', (int) $old['id'], 'Deleted WhatsApp bot answer "' . mb_substr($old['question'], 0, 120) . '"');
    api_ok(null, 'Answer deleted.');
});

/* ---------------------------------------------------------------------------------------------- CMS FAQs */
route('GET', '/whatsapp/bot/faqs', function () {
    wa_bot_require('view');
    api_ok(['rows' => wa_bot_faqs(), 'can_edit_cms' => can('cms', 'edit')]);
});

route('POST', '/whatsapp/bot/faqs/{id:\d+}/toggle', function (array $p) {
    wa_bot_require('edit');
    $faq = db_row('SELECT id, question, status FROM faqs WHERE id = ?', [(int) $p['id']]);
    if (!$faq) {
        api_error('FAQ not found.', 404);
    }
    $on = filter_var(api_input()['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $ids = wa_bot_faq_excluded();
    $ids = $on ? array_values(array_diff($ids, [(int) $faq['id']])) : array_values(array_unique(array_merge($ids, [(int) $faq['id']])));
    save_setting('wa_bot_faq_exclude', wa_bot_json($ids), 'whatsapp');
    log_activity('update', 'whatsapp_automation', (int) $faq['id'], ($on ? 'Using' : 'Stopped using') . ' the website FAQ "' . mb_substr($faq['question'], 0, 120) . '" in WhatsApp bot answers');
    api_ok(['enabled' => $on], $on ? ($faq['status'] === 'active' ? 'The bot may use this FAQ.' : 'Switched on — the FAQ is used once it is active in the CMS.') : 'The bot will not use this FAQ on WhatsApp.');
});

/* ---------------------------------------------------------------------------------------------- course information */
route('GET', '/whatsapp/bot/courses', function () {
    wa_bot_require('view');
    api_ok(['rows' => wa_bot_courses(), 'can_edit_programs' => can('programs', 'edit') || can('academics', 'edit')]);
});

route('POST', '/whatsapp/bot/courses/bulk', function () {
    wa_bot_require('edit');
    $in = api_input();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
    if (!$ids || count($ids) > 500) {
        api_error('Select between 1 and 500 programs.', 422);
    }
    $on = filter_var($in['verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $valid = array_map('intval', db_column('SELECT id FROM programs WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids));
    foreach ($valid as $pid) {
        db_exec('INSERT INTO wa_bot_program_checks (program_id, verified, checked_by, checked_at) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE verified = VALUES(verified), note = IF(VALUES(verified) = 1, NULL, note), checked_by = VALUES(checked_by), checked_at = VALUES(checked_at)', [$pid, $on ? 1 : 0, user_id(), date('Y-m-d H:i:s')]);
    }
    log_activity('update', 'whatsapp_automation', null, 'Marked ' . count($valid) . ' program(s) as ' . ($on ? 'verified' : 'not verified') . ' for WhatsApp bot answers', 'success', ['ids' => $valid]);
    api_ok(['updated' => count($valid)], count($valid) . ' program' . (count($valid) === 1 ? '' : 's') . ' marked as ' . ($on ? 'verified.' : 'not verified — the bot sends fee and eligibility questions to a counsellor.'));
});

route('POST', '/whatsapp/bot/courses/{id:\d+}', function (array $p) {
    wa_bot_require('edit');
    $prog = db_row('SELECT id, name, short_name FROM programs WHERE id = ?', [(int) $p['id']]);
    if (!$prog) {
        api_error('Program not found.', 404);
    }
    $in = api_input();
    $note = trim(api_str($in['note'] ?? null));
    if (mb_strlen($note) > 255) {
        api_error('Please fix the highlighted fields and try again.', 422, ['note' => 'At most 255 characters.']);
    }
    $on = filter_var($in['verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    db_exec('INSERT INTO wa_bot_program_checks (program_id, verified, note, checked_by, checked_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE verified = VALUES(verified), note = VALUES(note), checked_by = VALUES(checked_by), checked_at = VALUES(checked_at)',
        [(int) $prog['id'], $on ? 1 : 0, $note !== '' ? $note : null, user_id(), date('Y-m-d H:i:s')]);
    log_activity('update', 'whatsapp_automation', (int) $prog['id'], 'Marked ' . $prog['name'] . ' as ' . ($on ? 'verified' : 'not verified') . ' for WhatsApp bot answers' . ($note !== '' ? ' — ' . mb_substr($note, 0, 120) : ''));
    api_ok(['verified' => $on], $on ? $prog['short_name'] . ': the bot quotes fee and eligibility from the program record.' : $prog['short_name'] . ': the bot will send fee and eligibility questions to a counsellor.');
});

/* ---------------------------------------------------------------------------------------------- unanswered questions */
route('GET', '/whatsapp/bot/unanswered', function () {
    wa_bot_require('view');
    api_ok(wa_bot_unanswered((int) api_query('days', '90'), (int) api_query('limit', '50')));
});

route('POST', '/whatsapp/bot/unanswered/dismiss', function () {
    wa_bot_require('edit');
    $ids = (array) (api_input()['turn_ids'] ?? []);
    if (!$ids || count($ids) > 1000) {
        api_error('Select at least one question.', 422);
    }
    $n = wa_bot_mark_reviewed($ids);
    log_activity('update', 'whatsapp_automation', null, 'Dismissed ' . $n . ' unanswered WhatsApp bot question(s)');
    api_ok(['updated' => $n], 'Question dismissed.');
});

/* ---------------------------------------------------------------------------------------------- sessions */
route('GET', '/whatsapp/bot/sessions', function () {
    wa_bot_require('view');
    [$page, $perPage] = api_pagination(20);
    $q = [];
    foreach (['q', 'state', 'language', 'qualified', 'from', 'to'] as $k) {
        $q[$k] = mb_substr(api_query($k), 0, 100);
    }
    api_ok(wa_bot_sessions_list($q, $page, $perPage));
});

route('GET', '/whatsapp/bot/sessions/{id:\d+}', function (array $p) {
    wa_require_any(wa_bot_session_view_pairs(), 'view AI bot sessions');
    wa_bot_require_session_access((int) $p['id']);
    $s = wa_bot_session_detail((int) $p['id']);
    if (!$s && !wa_contact((int) $p['id'])) {
        api_error('WhatsApp contact not found.', 404);
    }
    api_ok($s);
});

route('POST', '/whatsapp/bot/sessions/{id:\d+}/summary', function (array $p) {
    wa_require_any([['whatsapp_automation', 'edit'], ['whatsapp', 'edit']], 'refresh AI bot summaries');
    $c = wa_contact((int) $p['id']);
    if (!$c) {
        api_error('WhatsApp contact not found.', 404);
    }
    wa_bot_require_session_access((int) $c['id']);
    if (!wa_bot_session((int) $c['id'], false)) {
        api_error('The AI bot has not chatted with this contact yet.', 404);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!rate_limit('wa-bot-summary:' . (int) user_id(), 30, 600)) {
        api_error('Too many summary requests. Please wait a few minutes.', 429);
    }
    $summary = wa_bot_refresh_summary((int) $c['id'], true);
    log_activity('update', 'whatsapp', (int) $c['id'], 'Refreshed the AI bot summary for WhatsApp contact ' . ($c['name'] ?: '+' . $c['phone']));
    api_ok(wa_bot_session_detail((int) $c['id']), $summary !== '' ? 'Summary updated.' : 'Nothing to summarise yet.');
});

route('POST', '/whatsapp/bot/sessions/{id:\d+}/reset', function (array $p) {
    wa_bot_require('edit');
    $c = wa_contact((int) $p['id']);
    $s = $c ? db_row('SELECT id FROM wa_bot_sessions WHERE contact_id = ?', [(int) $c['id']]) : null;
    if (!$s) {
        api_error('This contact has no AI bot session.', 404);
    }
    // who is in charge does not change: a chat an agent handed back to the bot stays with the bot
    $old = wa_bot_meta_decode(db_value('SELECT qualification FROM wa_bot_sessions WHERE id = ?', [(int) $s['id']]));
    $flow = !empty($old['flow']['handback']) ? ['handback' => true] : [];
    db_update('wa_bot_sessions', ['state' => 'active', 'qualification' => wa_bot_meta_encode(['answers' => [], 'flow' => $flow]), 'handed_over_at' => null, 'handover_reason' => null,
        'messages_count' => 0, 'summary' => null, 'last_activity_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $s['id']]);
    log_activity('update', 'whatsapp_automation', (int) $c['id'], 'Restarted the AI bot session of WhatsApp contact ' . ($c['name'] ?: '+' . $c['phone']));
    api_ok(wa_bot_session_detail((int) $c['id']), 'Bot session restarted — qualification starts again on the next message. Hand the chat back to the bot in the inbox if a person took it over.');
});
