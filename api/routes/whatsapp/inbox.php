<?php
/**
 * WhatsApp shared inbox — /api/whatsapp/inbox/* and /api/whatsapp/quick-replies/*  (service: app/services/whatsapp_inbox.php)
 *
 * RBAC (module whatsapp): view = read (counsellors: own + unassigned conversations, managers: all), create = send /
 * retry / notes / claim, edit = assign / status / tags / bot hand-over, delete = others' notes, manage = all
 * conversations + quick replies.
 */

/** Load a conversation for the current user (404 when missing, 403 when assigned to someone else). */
function wa_inbox_route_conv(int $id): array
{
    $conv = wa_inbox_get($id);
    if (!$conv) {
        api_error('This conversation no longer exists.', 404);
    }
    if (!wa_inbox_visible($conv)) {
        api_error('This conversation is assigned to another agent. Ask a WhatsApp manager to reassign it.', 403);
    }
    return $conv;
}

function wa_inbox_require(string $action): void
{
    if (!can('whatsapp', $action)) {
        $labels = ['view' => 'open the WhatsApp inbox', 'create' => 'send WhatsApp messages', 'edit' => 'manage WhatsApp conversations',
            'delete' => 'delete WhatsApp notes', 'manage' => 'manage the WhatsApp inbox'];
        api_error('Your role does not allow you to ' . ($labels[$action] ?? $action . ' WhatsApp') . '. Ask a Super Admin to update your role.', 403);
    }
}

/* ------------------------------------------------------------------------------------------------ conversations */

route('GET', '/whatsapp/inbox/conversations', function () {
    wa_inbox_require('view');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    api_ok(wa_inbox_list([
        'q'      => api_query('q'),
        'filter' => api_query('filter', 'all'),
        'cursor' => api_query('cursor'),
        'limit'  => (int) api_query('limit', '25'),
        'tag'    => (int) api_query('tag', '0'),
    ]));
});

route('GET', '/whatsapp/inbox/counts', function () {
    wa_inbox_require('view');
    api_ok(wa_inbox_counts());
});

/** Open (or create) the conversation of a contact — used by "Message on WhatsApp" links (?contact=ID). */
route('POST', '/whatsapp/inbox/start', function () {
    wa_inbox_require('view');
    $in = api_validate(['contact_id' => 'required|integer|exists:wa_contacts,id'], null, ['contact_id' => 'Contact']);
    $conv = wa_conversation_for((int) $in['contact_id']);
    $conv = wa_inbox_route_conv((int) $conv['id']);
    api_ok(['id' => (int) $conv['id']]);
});

route('GET', '/whatsapp/inbox/conversations/{id:\d+}', function ($p) {
    wa_inbox_require('view');
    $conv = wa_inbox_route_conv((int) $p['id']);
    api_ok(wa_inbox_detail((int) $conv['id']));
});

route('GET', '/whatsapp/inbox/conversations/{id:\d+}/messages', function ($p) {
    wa_inbox_require('view');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $before = (int) api_query('before_id', '0');
    // release the session lock first: a poll that runs the worker tick must not block the agent's other requests
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (api_query('poll') === '1' && function_exists('wa_maybe_tick')) {
        try {
            wa_maybe_tick();
        } catch (Throwable $e) {
            error_log('[GIMT] wa_maybe_tick from inbox: ' . $e->getMessage());
        }
    }
    $res = wa_inbox_messages((int) $conv['id'], $before ?: null, (int) api_query('limit', '40'));
    if (!$before) {
        $res['conversation'] = wa_inbox_summary((int) $conv['id']);
    }
    api_ok($res);
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/send', function ($p) {
    wa_inbox_require('create');
    $conv = wa_inbox_route_conv((int) $p['id']);
    if (!rate_limit('wa-inbox-send:' . (int) user_id(), 90, 60)) {
        api_error('You are sending messages too quickly. Wait a moment and try again.', 429);
    }
    $in = api_input();
    $res = wa_inbox_send($conv, $in);
    if (!$res['ok']) {
        api_error($res['error'], ($res['code'] ?? '') === 'validation' ? 422 : 409, $res['errors'] ?? []);
    }
    $type = (string) ($in['type'] ?? 'text');
    if ($type === 'template') {
        log_activity('create', 'whatsapp', (int) $conv['id'], 'Sent WhatsApp template to ' . ($conv['name'] ?: wa_inbox_phone_display($conv['phone'])));
    }
    api_ok(['message' => $res['message'], 'conversation' => wa_inbox_summary((int) $conv['id'])], $type === 'template' ? 'Template queued for sending.' : '');
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/read', function ($p) {
    wa_inbox_require('view');
    $conv = wa_inbox_route_conv((int) $p['id']);
    wa_inbox_mark_read($conv);
    api_ok(['unread_count' => 0]);
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/assign', function ($p) {
    wa_inbox_require('view');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $raw = api_input()['user_id'] ?? null;
    $userId = ($raw === null || $raw === '' || (string) $raw === '0') ? null : (int) $raw;
    $self = $userId !== null && $userId === (int) user_id();
    $canClaim = $self && empty($conv['assigned_to']) && can('whatsapp', 'create');
    if (!$canClaim && !can('whatsapp', 'edit')) {
        api_error('Your role does not allow you to assign WhatsApp conversations. You can only claim unassigned ones.', 403);
    }
    if ($userId !== null && !in_array($userId, wa_inbox_agent_ids(), true)) {
        api_error('Choose a team member who has access to the WhatsApp inbox.', 422, ['user_id' => 'This user cannot work the WhatsApp inbox.']);
    }
    // an agent without "edit" may only claim; that update is guarded so two agents cannot claim the same chat
    $summary = wa_inbox_assign($conv, $userId, $canClaim && !can('whatsapp', 'edit'));
    if ($summary === null) {
        api_error('A colleague claimed this conversation a moment ago.', 409);
    }
    $msg = $userId === null ? 'Conversation unassigned.' : ($self ? 'Conversation assigned to you.' : 'Conversation assigned to ' . ($summary['assigned_name'] ?? 'agent') . '.');
    api_ok(['conversation' => $summary], $msg);
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/status', function ($p) {
    wa_inbox_require('edit');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $in = api_validate(['status' => 'required|in:open,pending,resolved'], null, ['status' => 'Status']);
    $summary = wa_inbox_set_status($conv, $in['status']);
    api_ok(['conversation' => $summary], ['open' => 'Conversation reopened.', 'pending' => 'Conversation marked pending.', 'resolved' => 'Conversation resolved.'][$in['status']]);
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/bot', function ($p) {
    wa_inbox_require('edit');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $active = filter_var(api_input()['active'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $summary = wa_inbox_set_bot($conv, $active);
    api_ok(['conversation' => $summary], $active ? 'Conversation handed back to the AI bot.' : 'You have taken over. The AI bot will stay quiet in this chat.');
});

/* ------------------------------------------------------------------------------------------------ notes */

route('GET', '/whatsapp/inbox/conversations/{id:\d+}/notes', function ($p) {
    wa_inbox_require('view');
    $conv = wa_inbox_route_conv((int) $p['id']);
    api_ok(wa_inbox_notes((int) $conv['contact_id']));
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/notes', function ($p) {
    wa_inbox_require('create');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $in = api_validate(['body' => 'required|max:2000'], null, ['body' => 'Note']);
    $note = wa_inbox_add_note($conv, (string) $in['body']);
    api_ok(['note' => $note, 'notes' => wa_inbox_notes((int) $conv['contact_id'])], 'Note added.');
});

route('PUT', '/whatsapp/inbox/notes/{id:\d+}', function ($p) {
    wa_inbox_require('create');
    $note = db_row('SELECT * FROM wa_notes WHERE id = ?', [(int) $p['id']]);
    if (!$note) {
        api_error('This note no longer exists.', 404);
    }
    if ($note['user_id'] === null || (int) $note['user_id'] !== (int) user_id()) {
        api_error('You can only edit your own notes.', 403);
    }
    if ($note['conversation_id']) {
        wa_inbox_route_conv((int) $note['conversation_id']);
    }
    $in = api_validate(['body' => 'required|max:2000'], null, ['body' => 'Note']);
    db_update('wa_notes', ['body' => (string) $in['body']], 'id = ?', [(int) $note['id']]);
    wa_agent_log('note', $note['conversation_id'] ? (int) $note['conversation_id'] : null, (int) $note['contact_id'], 'Edited a note');
    api_ok(null, 'Note updated.');
});

route('DELETE', '/whatsapp/inbox/notes/{id:\d+}', function ($p) {
    wa_inbox_require('view');
    $note = db_row('SELECT * FROM wa_notes WHERE id = ?', [(int) $p['id']]);
    if (!$note) {
        api_error('This note no longer exists.', 404);
    }
    $own = $note['user_id'] !== null && (int) $note['user_id'] === (int) user_id();
    if (!$own && !can('whatsapp', 'delete')) {
        api_error('You can only delete your own notes.', 403);
    }
    if ($note['conversation_id']) {
        wa_inbox_route_conv((int) $note['conversation_id']);
    }
    db_delete('wa_notes', 'id = ?', [(int) $note['id']]);
    wa_agent_log('note', $note['conversation_id'] ? (int) $note['conversation_id'] : null, (int) $note['contact_id'], 'Deleted a note');
    api_ok(null, 'Note deleted.');
});

/* ------------------------------------------------------------------------------------------------ tags */

route('GET', '/whatsapp/inbox/tags', function () {
    wa_inbox_require('view');
    api_ok(wa_inbox_all_tags());
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/tags', function ($p) {
    wa_inbox_require('edit');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $in = api_validate(['name' => 'required|max:60', 'color' => 'in:' . implode(',', WA_INBOX_TAG_COLORS)], null, ['name' => 'Tag', 'color' => 'Colour']);
    $tags = wa_inbox_add_tag($conv, (string) $in['name'], $in['color'] ?? null);
    api_ok(['tags' => $tags], 'Tag added.');
});

route('DELETE', '/whatsapp/inbox/conversations/{id:\d+}/tags/{tag:\d+}', function ($p) {
    wa_inbox_require('edit');
    $conv = wa_inbox_route_conv((int) $p['id']);
    api_ok(['tags' => wa_inbox_remove_tag($conv, (int) $p['tag'])], 'Tag removed.');
});

/* ------------------------------------------------------------------------------------------------ activity, templates */

route('GET', '/whatsapp/inbox/conversations/{id:\d+}/activity', function ($p) {
    wa_inbox_require('view');
    $conv = wa_inbox_route_conv((int) $p['id']);
    api_ok(wa_inbox_activity($conv));
});

route('GET', '/whatsapp/inbox/conversations/{id:\d+}/templates', function ($p) {
    wa_inbox_require('create');
    $conv = wa_inbox_route_conv((int) $p['id']);
    $contact = wa_contact((int) $conv['contact_id']) ?? [];
    api_ok(wa_inbox_templates_for($contact));
});

route('POST', '/whatsapp/inbox/conversations/{id:\d+}/template-preview', function ($p) {
    wa_inbox_require('create');
    wa_inbox_route_conv((int) $p['id']);
    $in = api_input();
    $tpl = wa_template_find((int) ($in['template_id'] ?? 0));
    if (!$tpl) {
        api_error('Choose a template.', 422, ['template_id' => 'Choose a template.']);
    }
    $r = wa_render_template($tpl, wa_inbox_clean_vars(is_array($in['vars'] ?? null) ? $in['vars'] : []));
    api_ok(['text' => $r['text'] ?? '', 'header' => $r['header'] ?? [], 'buttons' => $r['buttons'] ?? [], 'missing' => array_values((array) ($r['missing'] ?? []))]);
});

route('POST', '/whatsapp/inbox/messages/{id:\d+}/retry', function ($p) {
    wa_inbox_require('create');
    $m = db_row('SELECT * FROM wa_messages WHERE id = ?', [(int) $p['id']]);
    if (!$m) {
        api_error('This message no longer exists.', 404);
    }
    $conv = wa_inbox_route_conv((int) $m['conversation_id']);
    $res = wa_inbox_retry($m, $conv);
    if (!$res['ok']) {
        api_error($res['error'], 409);
    }
    api_ok(['message' => $res['message']], 'Message queued again.');
});

/* ------------------------------------------------------------------------------------------------ team */

route('GET', '/whatsapp/inbox/agents', function () {
    wa_inbox_require('view');
    api_ok(wa_inbox_agents(api_query('stats', '1') !== '0'));
});

route('GET', '/whatsapp/inbox/activity', function () {
    wa_inbox_require('view');
    api_ok(wa_inbox_activity_feed([
        'user_id'   => (int) api_query('user_id', '0'),
        'action'    => api_query('action'),
        'before_id' => (int) api_query('before_id', '0'),
        'limit'     => (int) api_query('limit', '50'),
    ]));
});

/* ------------------------------------------------------------------------------------------------ quick replies */

route('GET', '/whatsapp/quick-replies', function () {
    wa_inbox_require('view');
    $rows = wa_inbox_quick_replies(api_query('q'), api_query('category'));
    $convId = (int) api_query('conversation_id', '0');
    if ($convId) {
        $conv = wa_inbox_route_conv($convId);
        $profile = wa_inbox_profile((int) $conv['contact_id']);
        foreach ($rows as &$r) {
            $r['filled'] = wa_inbox_fill_quick_reply($r['body'], $profile);
        }
        unset($r);
    }
    $categories = db_column("SELECT DISTINCT category FROM wa_quick_replies WHERE category IS NOT NULL AND category <> '' ORDER BY category");
    api_ok(['rows' => $rows, 'categories' => $categories, 'can_manage' => wa_inbox_is_manager()]);
});

$waInboxQrSave = function (?int $id) {
    wa_inbox_require('manage');
    $in = api_input();
    $in['shortcut'] = wa_inbox_shortcut((string) ($in['shortcut'] ?? ''));
    $data = api_validate([
        'shortcut' => 'required|max:40|unique:wa_quick_replies,shortcut' . ($id ? ',' . $id : ''),
        'title'    => 'required|max:120',
        'body'     => 'required|max:4096',
        'category' => 'max:60',
    ], $in, ['shortcut' => 'Shortcut', 'title' => 'Title', 'body' => 'Message', 'category' => 'Category']);
    if ($id) {
        if (!db_row('SELECT id FROM wa_quick_replies WHERE id = ?', [$id])) {
            api_error('This quick reply no longer exists.', 404);
        }
        db_update('wa_quick_replies', $data, 'id = ?', [$id]);
        log_activity('update', 'whatsapp', $id, 'Updated WhatsApp quick reply /' . $data['shortcut']);
        api_ok(['id' => $id], 'Quick reply saved.');
    }
    $newId = (int) db_insert('wa_quick_replies', $data + ['created_by' => user_id()]);
    log_activity('create', 'whatsapp', $newId, 'Created WhatsApp quick reply /' . $data['shortcut']);
    api_ok(['id' => $newId], 'Quick reply created.', 201);
};

route('POST', '/whatsapp/quick-replies', fn () => $waInboxQrSave(null));
route('PUT', '/whatsapp/quick-replies/{id:\d+}', fn ($p) => $waInboxQrSave((int) $p['id']));

route('DELETE', '/whatsapp/quick-replies/{id:\d+}', function ($p) {
    wa_inbox_require('manage');
    $r = db_row('SELECT * FROM wa_quick_replies WHERE id = ?', [(int) $p['id']]);
    if (!$r) {
        api_error('This quick reply no longer exists.', 404);
    }
    db_delete('wa_quick_replies', 'id = ?', [(int) $r['id']]);
    log_activity('delete', 'whatsapp', (int) $r['id'], 'Deleted WhatsApp quick reply /' . $r['shortcut']);
    api_ok(null, 'Quick reply deleted.');
});

/** Count a use (composer insert) and return the body filled for the conversation's contact. */
route('POST', '/whatsapp/quick-replies/{id:\d+}/use', function ($p) {
    wa_inbox_require('create');
    $r = db_row('SELECT * FROM wa_quick_replies WHERE id = ?', [(int) $p['id']]);
    if (!$r) {
        api_error('This quick reply no longer exists.', 404);
    }
    $profile = null;
    $convId = (int) (api_input()['conversation_id'] ?? 0);
    if ($convId) {
        $conv = wa_inbox_route_conv($convId);
        $profile = wa_inbox_profile((int) $conv['contact_id']);
    }
    db_exec('UPDATE wa_quick_replies SET usage_count = usage_count + 1 WHERE id = ?', [(int) $r['id']]);
    api_ok(['body' => wa_inbox_fill_quick_reply($r['body'], $profile)]);
});
