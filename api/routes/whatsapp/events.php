<?php
/**
 * WhatsApp Event Manager + event ingestion — /api/whatsapp/events/*, /api/whatsapp/ingest/*
 * Service: app/services/whatsapp_events.php (loaded by the core service). Permission module: whatsapp_automation
 * (view = see, create = custom events, edit = edit / test / retry / link templates, publish = activate, delete = delete
 * custom events, manage = run the daily scan now).
 *
 *   GET    /whatsapp/events                    events (+ template, 30-day counts) + KPI summary
 *   GET    /whatsapp/events/options            categories, sources, recipients, operators, templates, variables, URLs
 *   GET    /whatsapp/events/{id}               one event + template variables + payload keys
 *   POST   /whatsapp/events                    create a custom event
 *   PUT    /whatsapp/events/{id}               edit (system events: name, description, template, rules, delay …)
 *   DELETE /whatsapp/events/{id}               delete a custom event (logs are kept)
 *   POST   /whatsapp/events/{id}/toggle        {active} activate / deactivate
 *   POST   /whatsapp/events/{id}/test          {phone, payload?} fire with the sample (or given) payload to a test phone
 *   GET    /whatsapp/events/{id}/stats         ?days= trend + statuses
 *   POST   /whatsapp/events/link-templates     link events to templates by name convention
 *   POST   /whatsapp/events/scan               {code?} run the daily time-based scan now
 *   GET    /whatsapp/events/logs               ?code=&status=&source=&q=&from=&to=&page=&per_page=
 *   GET    /whatsapp/events/logs/{id}          payload, actions, messages
 *   POST   /whatsapp/events/logs/{id}/retry    re-dispatch (new log row)
 *   POST   /whatsapp/events/logs/retry-failed  re-dispatch every failed log of the last 24 hours (max 100)
 *   POST   /whatsapp/ingest/event              external systems: header X-GIMT-Token (scope events), {event, phone, name, data, dedupe_key}
 *   POST   /whatsapp/ingest/track              website JS (CSRF meta token): {event, phone, name, consent, data} — events with source "js"
 */

function wa_evt_api_find(array $p): array
{
    $evt = wa_evt_find((int) ($p['id'] ?? 0));
    if (!$evt) {
        api_error('Event not found.', 404);
    }
    return $evt;
}

route('GET', '/whatsapp/events', function () {
    api_require('whatsapp_automation', 'view');
    api_ok(['events' => wa_evt_list(), 'summary' => wa_evt_summary(), 'categories' => wa_evt_categories(), 'fanouts' => wa_evt_fanout_jobs()]);
});

/** Bulk-event jobs (queued fan-outs) — open ones and those of the last 24 hours. */
route('GET', '/whatsapp/events/fanouts', function () {
    api_require('whatsapp_automation', 'view');
    api_ok(['items' => wa_evt_fanout_jobs(20)]);
});

/** Process queued bulk events now (normally the worker does it every minute). */
route('POST', '/whatsapp/events/fanouts/run', function () {
    api_require('whatsapp_automation', 'manage');
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // do not block the admin's other requests while the batch runs
    }
    $id = (int) (api_input()['id'] ?? 0);
    $res = wa_evt_process_fanouts(15.0, $id > 0 ? $id : null);
    if ($res['items'] > 0) {
        log_activity('run', 'whatsapp_automation', $id ?: null, "Processed queued WhatsApp bulk events: {$res['items']} recipient(s), {$res['fired']} event(s) fired");
    }
    $open = wa_evt_fanout_table() ? (int) db_value("SELECT COUNT(*) FROM wa_event_fanouts WHERE status IN ('pending', 'running')") : 0;
    api_ok($res + ['open' => $open, 'items_list' => wa_evt_fanout_jobs()], $res['items'] > 0
        ? "{$res['items']} recipient(s) processed, {$res['fired']} event(s) fired" . ($open ? " — $open job(s) still queued (the worker continues)." : '.')
        : ($open ? 'Queued jobs are being processed by another run. Try again in a minute.' : 'Nothing is queued.'));
});

/** Cancel a queued / running bulk event (recipients already processed keep their messages). */
route('POST', '/whatsapp/events/fanouts/{id:\d+}/cancel', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $job = wa_evt_fanout_table() ? db_row('SELECT * FROM wa_event_fanouts WHERE id = ?', [(int) $p['id']]) : null;
    if (!$job) {
        api_error('Bulk event not found.', 404);
    }
    if (!in_array($job['status'], ['pending', 'running'], true)) {
        api_error('Only queued bulk events can be cancelled.', 409);
    }
    db_update('wa_event_fanouts', ['status' => 'cancelled', 'locked_until' => null, 'finished_at' => date('Y-m-d H:i:s'),
        'error' => 'Cancelled by ' . (current_user()['name'] ?? 'an administrator') . ' after ' . (int) $job['processed'] . ' of ' . (int) $job['total'] . '.'], 'id = ?', [(int) $job['id']]);
    log_activity('cancel', 'whatsapp_automation', (int) $job['id'], 'Cancelled WhatsApp bulk event ' . $job['event_code'] . ' (' . (int) $job['processed'] . ' of ' . (int) $job['total'] . ' recipients processed)');
    api_ok(['items' => wa_evt_fanout_jobs()], 'Bulk event cancelled. Recipients already processed keep their messages.');
});

route('GET', '/whatsapp/events/options', function () {
    api_require('whatsapp_automation', 'view');
    $templates = db_all(
        "SELECT id, name, display_name, category, language, status, body, variables, header_type
           FROM wa_templates WHERE status NOT IN ('archived')
          ORDER BY (status = 'approved') DESC, COALESCE(display_name, name)"
    );
    $templates = array_map(function ($t) {
        $keys = wa_evt_template_keys($t);
        return [
            'id' => (int) $t['id'], 'name' => $t['name'], 'display_name' => $t['display_name'] ?: $t['name'],
            'category' => $t['category'], 'language' => $t['language'], 'status' => $t['status'],
            'body' => mb_substr((string) $t['body'], 0, 1024), 'variables' => $keys,
        ];
    }, $templates);
    $catalog = [];
    if (function_exists('wa_variable_catalog')) {
        try {
            foreach ((array) wa_variable_catalog() as $k => $v) {
                $catalog[] = ['key' => (string) $k, 'label' => is_array($v) ? (string) ($v['label'] ?? $k) : (string) $v, 'sample' => is_array($v) ? (string) ($v['sample'] ?? '') : ''];
            }
        } catch (Throwable $e) {
            $catalog = [];
        }
    }
    api_ok([
        'categories' => wa_evt_categories(),
        'sources' => wa_evt_sources(),
        'recipients' => wa_evt_recipients(),
        'operators' => wa_evt_ops(),
        'contact_fields' => wa_evt_contact_fields(),
        'templates' => $templates,
        'variable_catalog' => $catalog,
        'scheduled_codes' => array_keys(wa_evt_scanners()),
        'ingest_url' => absolute_url('api/whatsapp/ingest/event'),
        'track_url' => absolute_url('api/whatsapp/ingest/track'),
        'can_see_tokens' => can('whatsapp_settings', 'manage'),
    ]);
});

route('GET', '/whatsapp/events/logs', function () {
    api_require('whatsapp_automation', 'view');
    [$page, $perPage] = api_pagination(25);
    $where = ['1 = 1'];
    $args = [];
    if (($code = api_query('code')) !== '') {
        $where[] = 'l.event_code = ?';
        $args[] = $code;
    }
    $status = api_query('status');
    // "already sent recently": the repeat guard of wa_send() kept an earlier identical message instead of queueing a new one
    $recentDup = "l.actions LIKE '%\"send_status\":\"duplicate_recent\"%'";
    if ($status === 'failed') {
        $where[] = wa_evt_failed_sql('l');
    } elseif ($status === 'sent') {
        $where[] = "l.actions LIKE '%\"status\":\"queued\"%' AND NOT ($recentDup)";
    } elseif ($status === 'skipped') {
        $where[] = "(l.status = 'skipped' OR (l.actions LIKE '%\"handler\":\"event_manager\"%' AND l.actions LIKE '%\"status\":\"skipped\"%'))";
    } elseif ($status === 'duplicate') {
        $where[] = "(l.status = 'duplicate' OR $recentDup)";
    } elseif (in_array($status, ['received', 'processed'], true)) {
        $where[] = 'l.status = ?';
        $args[] = $status;
    }
    if (($source = api_query('source')) !== '') {
        $where[] = 'l.source = ?';
        $args[] = $source;
    }
    if (($cat = api_query('category')) !== '') {
        $where[] = 'e.category = ?';
        $args[] = $cat;
    }
    if (($from = api_query('from')) !== '' && strtotime($from)) {
        $where[] = 'l.created_at >= ?';
        $args[] = date('Y-m-d 00:00:00', strtotime($from));
    }
    if (($to = api_query('to')) !== '' && strtotime($to)) {
        $where[] = 'l.created_at <= ?';
        $args[] = date('Y-m-d 23:59:59', strtotime($to));
    }
    if (($q = api_query('q')) !== '') {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        $digits = preg_replace('/\D+/', '', $q);
        $where[] = '(c.name LIKE ? OR l.event_code LIKE ? OR l.dedupe_key LIKE ?' . (strlen($digits) >= 4 ? ' OR c.phone LIKE ?' : '') . ')';
        array_push($args, $like, $like, $like);
        if (strlen($digits) >= 4) {
            $args[] = '%' . $digits . '%';
        }
    }
    $sqlWhere = implode(' AND ', $where);
    $from = 'FROM wa_event_logs l LEFT JOIN wa_contacts c ON c.id = l.contact_id LEFT JOIN wa_events e ON e.code = l.event_code';
    $total = (int) db_value("SELECT COUNT(*) $from WHERE $sqlWhere", $args);
    $rows = db_all(
        "SELECT l.id, l.event_code, l.source, l.status, l.error, l.dedupe_key, l.contact_id, l.actions, l.created_at, l.processed_at,
                c.name AS contact_name, c.phone AS contact_phone, e.id AS event_id, e.name AS event_name, e.category
           $from WHERE $sqlWhere ORDER BY l.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
        $args
    );
    $dupIds = [];
    foreach ($rows as &$r) {
        $a = wa_evt_json($r['actions']);
        $r['actions'] = $a;
        $r['outcome'] = wa_evt_api_outcome($a);
        if (!empty($r['outcome']['duplicate_of'])) {
            $dupIds[] = (int) $r['outcome']['duplicate_of'];
        }
    }
    unset($r);
    // the earlier message of "already sent recently" rows (status + chat to link to), one query per page
    if ($dupIds) {
        $dupIds = array_values(array_unique($dupIds));
        $earlier = [];
        foreach (db_all('SELECT id, conversation_id, status, sender_type, created_at FROM wa_messages WHERE id IN (' . implode(',', array_fill(0, count($dupIds), '?')) . ')', $dupIds) as $m) {
            $earlier[(int) $m['id']] = ['id' => (int) $m['id'], 'conversation_id' => $m['conversation_id'] !== null ? (int) $m['conversation_id'] : null,
                'status' => $m['status'], 'sender_type' => $m['sender_type'], 'created_at' => $m['created_at']];
        }
        foreach ($rows as &$r) {
            if (!empty($r['outcome']['duplicate_of'])) {
                $r['outcome']['earlier_message'] = $earlier[(int) $r['outcome']['duplicate_of']] ?? null;
            }
        }
        unset($r);
    }
    api_ok(['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))]);
});

/** One-line outcome of a log's actions (for the list). */
function wa_evt_api_outcome(array $actions): ?array
{
    $list = isset($actions['handler']) || isset($actions['status']) ? [$actions] : array_values(array_filter($actions, 'is_array'));
    foreach ($list as $a) {
        if (($a['handler'] ?? '') === 'event_manager' || isset($a['event_id'])) {
            $recent = ($a['send_status'] ?? '') === 'duplicate_recent';
            return [
                'status' => $a['status'] ?? null,
                'reason' => $a['reason'] ?? null,
                'message_id' => $a['message_id'] ?? null,
                'template' => $a['template']['name'] ?? null,
                'recipient' => $a['recipient']['phone'] ?? null,
                'scheduled_at' => $a['scheduled_at'] ?? null,
                'stage' => $a['crm']['stage']['to'] ?? null,
                'send_status' => $a['send_status'] ?? null,
                // repeat guard: nothing new was queued, message_id is the earlier identical message
                'duplicate_of' => $recent ? ((int) ($a['duplicate_of'] ?? $a['message_id'] ?? 0) ?: null) : null,
                'note' => $recent ? ($a['note'] ?? null) : null,
            ];
        }
    }
    return null;
}

route('GET', '/whatsapp/events/logs/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'view');
    $log = wa_evt_log_detail((int) $p['id']);
    if (!$log) {
        api_error('Log entry not found.', 404);
    }
    api_ok($log);
});

route('POST', '/whatsapp/events/logs/{id:\d+}/retry', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $log = db_row('SELECT * FROM wa_event_logs WHERE id = ?', [(int) $p['id']]);
    if (!$log) {
        api_error('Log entry not found.', 404);
    }
    if (in_array($log['status'], ['duplicate', 'received'], true)) {
        api_error('Only processed, skipped or failed events can be retried.', 422);
    }
    $payload = wa_evt_json($log['payload']);
    if (!empty($payload['fanout'])) {
        // summary row of a bulk event that was logged once while nothing acted on it: queue its recipients now
        if (empty($payload['_fanout_ids'])) {
            api_error('This bulk event summary has no recipient list to send again. Repeat the action in the ERP instead.', 422);
        }
        if (str_contains((string) $log['error'], '[re-queued for')) {
            api_error('The recipients of this bulk event were already queued again.', 409);
        }
        $res = wa_evt_retry_fanout($log);
        if (!$res) {
            api_error('Activate this event (with a linked template) before sending its recipients again.', 422);
        }
        log_activity('retry', 'whatsapp_automation', (int) $log['id'], 'Queued WhatsApp bulk event ' . $log['event_code'] . ' again for ' . (int) ($payload['recipients_count'] ?? 0) . ' recipient(s) (log #' . $log['id'] . ')');
        api_ok(['log_id' => null, 'job_id' => $res['job_id'] ?? null, 'queued' => $res['queued'] ?? ($res['fired'] ?? 0)],
            'Queued for ' . (int) ($res['queued'] ?? $res['fired'] ?? 0) . ' recipient(s) — the worker sends them in the background.');
    }
    $newId = wa_evt_retry($log);
    if (!$newId) {
        api_error('The event could not be dispatched again. Check the WhatsApp settings and try again.', 422);
    }
    log_activity('retry', 'whatsapp_automation', (int) $log['id'], 'Re-dispatched WhatsApp event ' . $log['event_code'] . ' (log #' . $log['id'] . ' → #' . $newId . ')');
    api_ok(['log_id' => $newId, 'log' => wa_evt_log_detail($newId)], 'Event dispatched again (log #' . $newId . ').');
});

route('POST', '/whatsapp/events/logs/retry-failed', function () {
    api_require('whatsapp_automation', 'edit');
    $logs = db_all('SELECT * FROM wa_event_logs WHERE ' . wa_evt_failed_sql() . " AND source <> 'test' AND COALESCE(error, '') NOT LIKE '%[retried as #%' AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id LIMIT 100");
    $done = 0;
    foreach ($logs as $log) {
        if (wa_evt_retry($log)) {
            $done++;
        }
    }
    if ($done) {
        log_activity('retry', 'whatsapp_automation', null, "Re-dispatched $done failed WhatsApp event(s) of the last 24 hours");
    }
    api_ok(['retried' => $done, 'found' => count($logs)], $logs ? "$done of " . count($logs) . ' failed event(s) dispatched again.' : 'No failed events in the last 24 hours.');
});

route('POST', '/whatsapp/events/link-templates', function () {
    api_require('whatsapp_automation', 'edit');
    [$linked, $activated] = wa_evt_link_templates(!empty(api_input()['activate_core']) && can('whatsapp_automation', 'publish'));
    if ($linked) {
        log_activity('edit', 'whatsapp_automation', null, "Linked $linked WhatsApp event(s) to templates by name" . ($activated ? ", activated $activated" : ''));
    }
    api_ok(['linked' => $linked, 'activated' => $activated], $linked ? "Linked $linked event(s) to their templates." : 'No unlinked event has a matching template yet.');
});

route('POST', '/whatsapp/events/scan', function () {
    api_require('whatsapp_automation', 'manage');
    $code = api_str(api_input()['code'] ?? '');
    if ($code !== '' && !isset(wa_evt_scanners()[$code])) {
        api_error('This event is not a scheduled (daily) event.', 422);
    }
    $res = wa_evt_run_scanners(true, 20.0, null, $code !== '' ? $code : null);
    log_activity('run', 'whatsapp_automation', null, 'Ran the WhatsApp scheduled-event scan' . ($code ? " for $code" : '') . ": {$res['fired']} event(s) fired");
    api_ok($res, $res['fired'] ? "{$res['fired']} scheduled event(s) fired." : 'Scan complete — nothing new is due (already-sent reminders are skipped).');
});

route('GET', '/whatsapp/events/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'view');
    $evt = wa_evt_api_find($p);
    $tpl = wa_evt_template_for($evt);
    $out = wa_evt_present($evt);
    $out['template'] = $tpl ? ['id' => (int) $tpl['id'], 'name' => $tpl['name'], 'display_name' => $tpl['display_name'] ?? $tpl['name'], 'status' => $tpl['status'] ?? null, 'category' => $tpl['category'] ?? null, 'body' => $tpl['body'] ?? '', 'linked' => (int) ($evt['template_id'] ?? 0) === (int) $tpl['id']] : null;
    $out['template_variables'] = wa_evt_template_keys($tpl);
    $out['payload_keys'] = wa_evt_payload_keys($evt);
    // active workflows that also message on this event (warning in the drawer — the contact would get both)
    $out['workflow_overlaps'] = wa_evt_workflow_overlaps((string) $evt['code'], $tpl['name'] ?? null);
    $out['schedule'] = $out['is_scheduled'] ? wa_evt_schedule($evt) : null;
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $c = db_row('SELECT COUNT(*) AS fired, SUM(' . wa_evt_failed_sql() . ') AS failed FROM wa_event_logs WHERE event_code = ? AND created_at >= ?', [$evt['code'], $since]) ?: [];
    $out['fired_30d'] = (int) ($c['fired'] ?? 0);
    $out['failed_30d'] = (int) ($c['failed'] ?? 0);
    $out['messages_30d'] = (int) db_value("SELECT COUNT(*) FROM wa_messages m JOIN wa_event_logs l ON l.id = m.event_log_id WHERE l.event_code = ? AND m.created_at >= ? AND m.sender_type = 'event'", [$evt['code'], $since]);
    api_ok($out);
});

route('GET', '/whatsapp/events/{id:\d+}/stats', function ($p) {
    api_require('whatsapp_automation', 'view');
    $evt = wa_evt_api_find($p);
    api_ok(wa_evt_stats($evt, (int) ($_GET['days'] ?? 30)));
});

route('POST', '/whatsapp/events', function () {
    api_require('whatsapp_automation', 'create');
    $in = api_input();
    [$data, $errors] = wa_evt_clean($in, null);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    if (!empty($in['is_active'])) {
        if (!can('whatsapp_automation', 'publish')) {
            api_error('Your role cannot activate events. Save it inactive and ask a manager to activate it.', 403);
        }
        if (empty($data['template_id'])) {
            api_error('Choose a template before activating the event.', 422, ['template_id' => 'Required to activate the event.']);
        }
        $data['is_active'] = 1;
    }
    $data += ['recipient' => 'contact', 'delay_minutes' => 0, 'respect_business_hours' => 0];
    $data['created_by'] = user_id();
    $id = db_insert('wa_events', $data);
    log_activity('create', 'whatsapp_automation', $id, 'Created WhatsApp custom event ' . $data['code'] . ' (' . $data['name'] . ')');
    $saved = wa_evt_find($id);
    api_ok(wa_evt_present($saved) + ['warning' => !empty($data['is_active']) ? wa_evt_overlap_warning($saved) : null], 'Custom event created.');
});

route('PUT', '/whatsapp/events/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $evt = wa_evt_api_find($p);
    $in = api_input();
    [$data, $errors] = wa_evt_clean($in, $evt);
    if ($errors) {
        api_error('Please fix the highlighted fields and try again.', 422, $errors);
    }
    if (array_key_exists('is_active', $in) && (bool) $in['is_active'] !== (bool) (int) $evt['is_active']) {
        if (!can('whatsapp_automation', 'publish')) {
            api_error('Your role cannot activate or deactivate events.', 403);
        }
        $data['is_active'] = !empty($in['is_active']) ? 1 : 0;
    }
    $templateAfter = array_key_exists('template_id', $data) ? $data['template_id'] : $evt['template_id'];
    if ((int) ($data['is_active'] ?? $evt['is_active']) === 1 && !$templateAfter && !wa_evt_template_for(['template_id' => null, 'code' => $evt['code']])) {
        api_error('Choose a template before activating the event.', 422, ['template_id' => 'Required while the event is active.']);
    }
    if (!$data) {
        api_ok(wa_evt_present($evt), 'Nothing to update.');
    }
    $data['updated_by'] = user_id();
    db_update('wa_events', $data, 'id = ?', [(int) $evt['id']]);
    log_activity('edit', 'whatsapp_automation', (int) $evt['id'], 'Updated WhatsApp event ' . $evt['code'] . ' (' . implode(', ', array_keys(array_diff_key($data, ['updated_by' => 1]))) . ')');
    $saved = wa_evt_find((int) $evt['id']);
    // warn (never block) when an active workflow started by the same event also sends a template
    $warning = (int) $saved['is_active'] === 1 ? wa_evt_overlap_warning($saved) : null;
    api_ok(wa_evt_present($saved) + ['warning' => $warning], 'Event saved.');
});

route('DELETE', '/whatsapp/events/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'delete');
    $evt = wa_evt_api_find($p);
    if ((int) $evt['is_system'] === 1) {
        api_error('Built-in events are fired by GIMT and cannot be deleted. Deactivate it instead.', 422);
    }
    if (db_value("SELECT 1 FROM wa_workflows WHERE trigger_event = ? AND status = 'active' LIMIT 1", [$evt['code']])) {
        api_error('An active automation workflow is triggered by this event. Deactivate or change that workflow first.', 422);
    }
    db_delete('wa_events', 'id = ?', [(int) $evt['id']]);
    log_activity('delete', 'whatsapp_automation', (int) $evt['id'], 'Deleted WhatsApp custom event ' . $evt['code'] . ' (' . $evt['name'] . ')');
    api_ok(null, 'Custom event deleted. Its execution logs are kept.');
});

route('POST', '/whatsapp/events/{id:\d+}/toggle', function ($p) {
    api_require('whatsapp_automation', 'publish');
    $evt = wa_evt_api_find($p);
    $active = !empty(api_input()['active']);
    if ($active && !wa_evt_template_for($evt)) {
        api_error('Link a WhatsApp template to "' . $evt['name'] . '" before activating it.', 422);
    }
    $data = ['is_active' => $active ? 1 : 0, 'updated_by' => user_id()];
    if ($active && empty($evt['template_id']) && ($tpl = wa_evt_template_for($evt))) {
        $data['template_id'] = (int) $tpl['id'];
    }
    db_update('wa_events', $data, 'id = ?', [(int) $evt['id']]);
    log_activity($active ? 'activate' : 'deactivate', 'whatsapp_automation', (int) $evt['id'], ($active ? 'Activated' : 'Deactivated') . ' WhatsApp event ' . $evt['code']);
    $saved = wa_evt_find((int) $evt['id']);
    // warn (never block) when an active workflow started by the same event also sends a template
    $warning = $active ? wa_evt_overlap_warning($saved) : null;
    api_ok(wa_evt_present($saved) + ['warning' => $warning], $active ? 'Event activated — messages will be sent when it fires.' : 'Event deactivated.');
});

route('POST', '/whatsapp/events/{id:\d+}/test', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $evt = wa_evt_api_find($p);
    $in = api_input();
    $phone = api_str($in['phone'] ?? '');
    if ($phone === '' || !wa_evt_phone_digits($phone)) {
        api_error('Please fix the highlighted fields and try again.', 422, ['phone' => 'Enter a valid WhatsApp number (with country code).']);
    }
    $payload = $in['payload'] ?? null;
    if (is_string($payload)) {
        $payload = trim($payload) === '' ? null : json_decode($payload, true);
        if ($payload === null && trim((string) ($in['payload'] ?? '')) !== '') {
            api_error('Please fix the highlighted fields and try again.', 422, ['payload' => 'The test payload must be a JSON object.']);
        }
    }
    if (!is_array($payload)) {
        $payload = wa_evt_json($evt['sample_payload']);
    }
    if (!wa_evt_template_for($evt)) {
        api_error('Link a WhatsApp template to this event before testing it.', 422);
    }
    // same rule as the template / campaign tests: never a blocked number, never a STOP number with a marketing template
    if ($refusal = wa_evt_test_refusal($evt, (string) wa_evt_phone_digits($phone))) {
        api_error($refusal['message'], 422, ['phone' => $refusal['field']]);
    }
    if (!rate_limit('wa-evt-test:' . user_id(), 30, 3600)) {
        api_error('Too many test messages. Please wait a while and try again.', 429);
    }
    $res = wa_evt_test($evt, $payload, $phone);
    log_activity('test', 'whatsapp_automation', (int) $evt['id'], 'Sent a test of WhatsApp event ' . $evt['code'] . ' to ' . wa_evt_mask_phone(wa_evt_phone_digits($phone)));
    $out = wa_evt_api_outcome((array) ($res['log']['actions'] ?? []));
    $msg = match ($out['status'] ?? null) {
        'queued' => 'Test message queued' . (function_exists('wa_mode') && wa_mode() === 'test' ? ' (test mode: simulated, nothing is sent to Interakt).' : '.'),
        'skipped' => 'Event fired but no message was queued: ' . ($out['reason'] ?? 'skipped') . '.',
        'failed' => 'Event fired but the message was refused: ' . ($out['reason'] ?? 'failed') . '.',
        default => 'Event fired (log #' . ($res['log_id'] ?? '—') . ').',
    };
    api_ok($res + ['outcome' => $out], $msg);
});

/* ---------------------------------------------------------------------------------------------- ingestion */

route('POST', '/whatsapp/ingest/event', function () {
    $token = (string) ($_SERVER['HTTP_X_GIMT_TOKEN'] ?? '');
    if (!rate_limit('wa-ingest-ip:' . client_ip(), 600, 60)) {
        header('Retry-After: 60');
        api_error('Too many requests.', 429);
    }
    $tok = wa_evt_token($token, 'events');
    if (!$tok) {
        log_system('warning', 'WhatsApp event ingest rejected (missing or invalid token)', ['ip' => client_ip()]);
        api_error('Invalid or missing X-GIMT-Token.', 401);
    }
    if (!rate_limit('wa-ingest-token:' . $tok['id'], 300, 60)) {
        header('Retry-After: 60');
        api_error('Rate limit exceeded (300 events per minute).', 429);
    }
    $in = api_input();
    $code = strtoupper(api_str($in['event'] ?? $in['code'] ?? ''));
    $evt = $code !== '' ? wa_evt_by_code($code) : null;
    if (!$evt) {
        api_error('Unknown event code. Create it in WhatsApp › Event Manager first.', 422, ['event' => 'Unknown event code.']);
    }
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];
    // internal markers (_test, _source, _client_id …) and contact_id are never accepted from outside: they would bypass
    // the active / opt-in checks or address an arbitrary CRM contact
    $payload = array_filter($data, fn ($k) => is_string($k) && $k !== '' && $k[0] !== '_' && $k !== 'contact_id' && $k !== 'fanout', ARRAY_FILTER_USE_KEY);
    foreach (['phone', 'name', 'email'] as $k) {
        if (isset($in[$k]) && is_scalar($in[$k]) && trim((string) $in[$k]) !== '') {
            $payload[$k] = mb_substr(trim((string) $in[$k]), 0, 190);
        }
    }
    if (!empty($payload['phone']) && !wa_evt_phone_digits((string) $payload['phone'])) {
        api_error('Invalid phone number.', 422, ['phone' => 'Send the number with its country code, e.g. +919876543210.']);
    }
    if (strlen((string) json_encode($payload)) > 32768) {
        api_error('Payload too large (max 32 KB).', 413);
    }
    $dedupe = api_str($in['dedupe_key'] ?? '');
    $payload['_client_id'] = (int) $tok['id'];
    $logId = wa_event($code, wa_evt_enrich($code, $payload), ['source' => 'api', 'dedupe_key' => $dedupe !== '' ? mb_substr('api:' . $dedupe, 0, 150) : null]);
    $log = $logId ? db_row('SELECT id, status, actions FROM wa_event_logs WHERE id = ?', [(int) $logId]) : null;
    api_ok([
        'event_log_id' => $logId ? (int) $logId : null,
        'status' => $log['status'] ?? ($dedupe !== '' ? 'duplicate' : 'received'),
        'duplicate' => !$logId && $dedupe !== '',
        'outcome' => $log ? wa_evt_api_outcome(wa_evt_json($log['actions'])) : null,
    ], $logId ? 'Event received.' : 'Duplicate event ignored.');
}, ['auth' => false, 'csrf' => false]);

route('POST', '/whatsapp/ingest/track', function () {
    if (!rate_limit('wa-track-ip:' . client_ip(), 20, 600)) {
        header('Retry-After: 600');
        api_error('Too many requests. Please try again later.', 429);
    }
    $in = api_input();
    $code = strtoupper(api_str($in['event'] ?? ''));
    $evt = $code !== '' ? wa_evt_by_code($code) : null;
    if (!$evt || $evt['source'] !== 'js') {
        api_error('Unknown event.', 422, ['event' => 'This event cannot be sent from the website.']);
    }
    $consent = $in['consent'] ?? null;
    if (!($consent === true || $consent === 1 || $consent === '1' || $consent === 'true' || $consent === 'on')) {
        api_error('Please agree to be contacted on WhatsApp.', 422, ['consent' => 'Consent is required.']);
    }
    $phone = wa_evt_phone_digits(api_str($in['phone'] ?? ''));
    if (!$phone) {
        api_error('Please enter a valid mobile number.', 422, ['phone' => 'Enter a valid mobile number.']);
    }
    if (!rate_limit('wa-track-phone:' . $phone, 5, 3600)) {
        api_error('We already received your request. Our team will contact you shortly.', 429);
    }
    $name = mb_substr(trim(strip_tags(api_str($in['name'] ?? ''))), 0, 150);
    $data = is_array($in['data'] ?? null) ? $in['data'] : [];
    $clean = [];
    foreach (array_slice($data, 0, 20, true) as $k => $v) {
        // public endpoint: no internal markers, no record ids (they would pull a student's / applicant's data into the
        // message) and no phone-like keys (the message may only go to the visitor's own number); program_id is allowed
        if (!is_string($k) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k) || !is_scalar($v)
            || (str_ends_with($k, '_id') && $k !== 'program_id') || preg_match('/phone|mobile|whatsapp|email|contact|token|secret|fanout/', $k)) {
            continue;
        }
        $clean[$k] = mb_substr(strip_tags((string) $v), 0, 300);
    }
    if (isset($clean['program_id']) && !ctype_digit($clean['program_id'])) {
        unset($clean['program_id']);
    }
    $payload = $clean + ['phone' => $phone, 'name' => $name !== '' ? $name : null, 'page' => mb_substr(api_str($in['page'] ?? ''), 0, 300), 'consent' => true, 'source' => 'website'];
    $payload = array_filter($payload, fn ($v) => $v !== null && $v !== '');
    if (function_exists('wa_contact_upsert')) {
        $cid = wa_contact_upsert(array_filter(['phone' => $phone, 'name' => $name ?: null, 'source' => 'website'], fn ($v) => $v !== null));
        if ($cid && function_exists('wa_contact_opt')) {
            $c = wa_contact($cid);
            if ($c && (int) $c['opt_in'] !== 1 && empty($c['opted_out_at'])) {
                wa_contact_opt($cid, true, 'website: ' . strtolower($code));
            }
        }
    }
    $logId = wa_event($code, wa_evt_enrich($code, $payload), ['source' => 'js', 'dedupe_key' => 'js:' . $phone . ':' . date('Y-m-d')]);
    api_ok(['received' => true, 'duplicate' => !$logId], 'Thank you! We will send the details on WhatsApp shortly.');
}, ['auth' => false]);
