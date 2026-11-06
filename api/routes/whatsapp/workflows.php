<?php
/**
 * WhatsApp automation workflows (Module 5) — /api/whatsapp/workflows/*
 *
 *   GET    /whatsapp/workflows                      list + KPIs + 14-day run trend + starter templates (?q=&status=)
 *   GET    /whatsapp/workflows/meta                 builder data: events, templates, stages, tags, counsellors, variables, rule fields
 *   GET    /whatsapp/workflows/options/contacts     contact options for the test picker (?q= | ?ids=)
 *   POST   /whatsapp/workflows/validate             {definition} -> issues
 *   GET    /whatsapp/workflows/runs                 execution history (?workflow_id=&status=&q=&test=&from=&to=&page=&per_page=)
 *   GET    /whatsapp/workflows/runs/{id}            run + step timeline + messages
 *   POST   /whatsapp/workflows/runs/{id}/retry      re-run a failed run from the failed step
 *   POST   /whatsapp/workflows/runs/{id}/cancel     stop a running / waiting run
 *   POST   /whatsapp/workflows                      create {name, description, starter?}
 *   GET    /whatsapp/workflows/{id}                 workflow + definition (secret headers masked) + issues + run counts
 *   PUT    /whatsapp/workflows/{id}                 save {name, description, definition, business_hours_only, allow_reentry, version} (version++)
 *   POST   /whatsapp/workflows/{id}/status          {status: active|inactive} (publish = activate)
 *   POST   /whatsapp/workflows/{id}/duplicate       copy as a draft
 *   DELETE /whatsapp/workflows/{id}
 *   POST   /whatsapp/workflows/{id}/test            run now for a test contact, waits fast-forwarded -> trace
 *   POST   /whatsapp/workflows/{id}/enroll          {contact_id} start a run manually (active workflows)
 */

function wa_wf_or_404(int $id): array
{
    $wf = wa_wf_get($id);
    if (!$wf) {
        api_error('Workflow not found. It may have been deleted.', 404);
    }
    return $wf;
}

function wa_wf_event_names(): array
{
    $names = [];
    try {
        $names = db_pairs('SELECT code, name FROM wa_events');
    } catch (Throwable $e) {
        $names = [];
    }
    foreach (wa_wf_internal_events() as $code => $ev) {
        $names[$code] = $ev['name'];
    }
    return $names;
}

route('GET', '/whatsapp/workflows', function () {
    api_require('whatsapp_automation', 'view');
    $q = api_query('q');
    $status = api_query('status');
    $where = ['1=1'];
    $args = [];
    if ($q !== '') {
        $like = '%' . addcslashes(mb_substr($q, 0, 100), '%_\\') . '%';
        $where[] = '(w.name LIKE ? OR w.description LIKE ? OR w.trigger_event LIKE ?)';
        array_push($args, $like, $like, $like);
    }
    if (in_array($status, ['draft', 'active', 'inactive'], true)) {
        $where[] = 'w.status = ?';
        $args[] = $status;
    }
    $names = wa_wf_event_names();
    $rows = db_all("SELECT w.id, w.name, w.description, w.trigger_type, w.trigger_event, w.status, w.version, w.runs_total, w.runs_completed, w.runs_failed,
                           w.last_run_at, w.business_hours_only, w.allow_reentry, w.created_at, w.updated_at, w.definition, u.name AS updated_by_name,
                           (SELECT COUNT(*) FROM wa_workflow_runs r WHERE r.workflow_id = w.id AND r.status IN ('running', 'waiting') AND r.is_test = 0) AS runs_active
                    FROM wa_workflows w LEFT JOIN users u ON u.id = w.updated_by
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY CASE w.status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END, COALESCE(w.updated_at, w.created_at) DESC, w.id DESC", $args);
    $out = [];
    foreach ($rows as $r) {
        $def = wa_wf_definition($r);
        unset($r['definition']);
        foreach (['id', 'version', 'runs_total', 'runs_completed', 'runs_failed', 'runs_active'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['business_hours_only'] = !empty($r['business_hours_only']);
        $r['allow_reentry'] = !empty($r['allow_reentry']);
        $r['trigger_name'] = $r['trigger_event'] ? ($names[$r['trigger_event']] ?? $r['trigger_event']) : 'Manual enrolment';
        $trig = wa_wf_trigger_node($def);
        $from = wa_wf_schedule_ts($trig['data']['active_from'] ?? '');
        $until = wa_wf_schedule_ts($trig['data']['active_until'] ?? '');
        $r['schedule'] = $from || $until ? [
            'from' => $from ? date('Y-m-d H:i:s', $from) : null, 'until' => $until ? date('Y-m-d H:i:s', $until) : null,
            'state' => $trig ? (wa_wf_schedule_block($trig) === null ? 'open' : ($from && time() < $from ? 'upcoming' : 'ended')) : 'open',
        ] : null;
        $r['node_count'] = count($def['nodes']);
        $r['node_types'] = array_count_values(array_column($def['nodes'], 'type'));
        $out[] = $r;
    }
    $since = date('Y-m-d 00:00:00', strtotime('-29 days'));
    $k = db_row("SELECT COUNT(*) AS runs, SUM(status = 'completed') AS completed, SUM(status = 'failed') AS failed, SUM(status = 'cancelled') AS cancelled
                 FROM wa_workflow_runs WHERE is_test = 0 AND started_at >= ?", [$since]) ?? [];
    $w = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status = 'draft') AS draft, SUM(status = 'inactive') AS inactive FROM wa_workflows") ?? [];
    $waiting = (int) db_value("SELECT COUNT(*) FROM wa_workflow_runs WHERE is_test = 0 AND status IN ('running', 'waiting')");
    // 14-day trend
    $days = [];
    for ($i = 13; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-$i days"))] = ['started' => 0, 'completed' => 0, 'failed' => 0];
    }
    foreach (db_all("SELECT DATE(started_at) AS d, COUNT(*) AS n FROM wa_workflow_runs WHERE is_test = 0 AND started_at >= ? GROUP BY DATE(started_at)", [array_key_first($days) . ' 00:00:00']) as $t) {
        if (isset($days[$t['d']])) {
            $days[$t['d']]['started'] = (int) $t['n'];
        }
    }
    foreach (db_all("SELECT DATE(finished_at) AS d, SUM(status = 'completed') AS c, SUM(status = 'failed') AS f FROM wa_workflow_runs WHERE is_test = 0 AND finished_at >= ? GROUP BY DATE(finished_at)", [array_key_first($days) . ' 00:00:00']) as $t) {
        if (isset($days[$t['d']])) {
            $days[$t['d']]['completed'] = (int) $t['c'];
            $days[$t['d']]['failed'] = (int) $t['f'];
        }
    }
    $starters = [];
    foreach (wa_wf_starters() as $key => $s) {
        $starters[] = ['key' => $key, 'name' => $s['name'], 'description' => $s['description'], 'trigger_event' => $s['trigger_event'],
            'trigger_name' => $names[$s['trigger_event']] ?? $s['trigger_event'], 'node_count' => count($s['definition']['nodes'])];
    }
    api_ok([
        'rows' => $out,
        'summary' => [
            'total' => (int) ($w['total'] ?? 0), 'active' => (int) ($w['active'] ?? 0), 'draft' => (int) ($w['draft'] ?? 0), 'inactive' => (int) ($w['inactive'] ?? 0),
            'runs_30d' => (int) ($k['runs'] ?? 0), 'completed_30d' => (int) ($k['completed'] ?? 0), 'failed_30d' => (int) ($k['failed'] ?? 0), 'in_progress' => $waiting,
        ],
        'trend' => ['labels' => array_map(fn ($d) => date('d M', strtotime($d)), array_keys($days)), 'started' => array_column(array_values($days), 'started'),
            'completed' => array_column(array_values($days), 'completed'), 'failed' => array_column(array_values($days), 'failed')],
        'starters' => $starters,
    ]);
});

route('GET', '/whatsapp/workflows/meta', function () {
    api_require('whatsapp_automation', 'view');
    $events = [];
    try {
        // active + template: the Event Manager already messages the contact on this event (the builder warns about doubles)
        foreach (db_all("SELECT e.code, e.name, e.category, e.description, e.sample_payload, e.is_active, t.name AS template_name, COALESCE(NULLIF(t.display_name, ''), t.name) AS template_label
                         FROM wa_events e LEFT JOIN wa_templates t ON t.id = e.template_id ORDER BY e.category, e.name") as $e) {
            $events[] = ['code' => $e['code'], 'name' => $e['name'], 'category' => $e['category'], 'description' => $e['description'],
                'sample' => wa_wf_decode($e['sample_payload'], new stdClass()), 'internal' => false,
                'active' => !empty($e['is_active']), 'template' => $e['template_name'] ?: null, 'template_label' => $e['template_label'] ?: null];
        }
    } catch (Throwable $e) {
        $events = [];
    }
    foreach (wa_wf_internal_events() as $code => $ev) {
        $events[] = ['code' => $code, 'name' => $ev['name'], 'category' => $ev['category'], 'description' => $ev['description'], 'sample' => $ev['sample'], 'internal' => true,
            'active' => true, 'template' => null, 'template_label' => null];
    }
    $templates = [];
    foreach (db_all("SELECT id, name, display_name, category, language, status, header_type, header_text, body, footer, buttons, variables FROM wa_templates
                     WHERE status <> 'archived' ORDER BY CASE status WHEN 'approved' THEN 0 ELSE 1 END, COALESCE(display_name, name)") as $t) {
        $vars = wa_wf_decode($t['variables'], []);
        $keys = [];
        foreach (['header', 'body'] as $part) {
            foreach ((array) ($vars[$part] ?? []) as $v) {
                if (is_array($v) && !empty($v['key'])) {
                    $keys[] = ['key' => (string) $v['key'], 'part' => $part, 'pos' => (int) ($v['pos'] ?? 0), 'sample' => (string) ($v['sample'] ?? '')];
                }
            }
        }
        foreach ((array) ($vars['buttons'] ?? []) as $idx => $v) {
            if (is_array($v) && !empty($v['key'])) {
                $keys[] = ['key' => (string) $v['key'], 'part' => 'button', 'pos' => (int) $idx, 'sample' => (string) ($v['sample'] ?? '')];
            }
        }
        $templates[] = ['id' => (int) $t['id'], 'name' => $t['name'], 'display_name' => $t['display_name'], 'category' => $t['category'], 'language' => $t['language'],
            'status' => $t['status'], 'header_type' => $t['header_type'], 'header_text' => $t['header_text'], 'body' => $t['body'], 'footer' => $t['footer'],
            'buttons' => wa_wf_decode($t['buttons'], []), 'variables' => $keys];
    }
    $stages = [];
    foreach (wa_wf_stages() as $key => $name) {
        $stages[] = ['value' => $key, 'label' => $name];
    }
    $tags = db_all('SELECT name, color FROM wa_tags ORDER BY name');
    $catalog = function_exists('wa_variable_catalog') ? wa_variable_catalog() : [];
    $variables = [];
    foreach ($catalog as $key => $v) {
        $variables[] = ['key' => (string) $key, 'label' => is_array($v) ? (string) ($v['label'] ?? $key) : (string) $v, 'sample' => is_array($v) ? (string) ($v['sample'] ?? '') : ''];
    }
    $have = array_column($variables, 'key');
    foreach (['student_name' => 'Student / lead name', 'phone' => 'Phone', 'email' => 'E-mail', 'course_name' => 'Course / programme', 'application_number' => 'Application number',
        'counsellor_name' => 'Counsellor name', 'last_reply' => 'Last reply text', 'workflow_name' => 'Workflow name', 'institute_name' => 'Institute name', 'today' => "Today's date",
        'enquiry_no' => 'ERP enquiry number (Create / Link Contact step)', 'enquiry_id' => 'ERP enquiry id'] as $key => $label) {
        if (!in_array($key, $have, true)) {
            $variables[] = ['key' => $key, 'label' => $label, 'sample' => ''];
        }
    }
    api_ok([
        'events' => $events,
        'templates' => $templates,
        'stages' => $stages,
        'lead_statuses' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(wa_wf_lead_statuses()), wa_wf_lead_statuses()),
        'tags' => $tags,
        'counsellors' => array_map(fn ($u) => ['value' => (int) $u['id'], 'label' => $u['name'], 'sub' => $u['designation']], wa_wf_counsellors()),
        'variables' => $variables,
        'condition_fields' => wa_wf_condition_fields(),
        'condition_ops' => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys(wa_wf_condition_ops()), wa_wf_condition_ops()),
        'mode' => function_exists('wa_mode') ? wa_mode() : 'test',
        'business_hours' => function_exists('wa_setting') ? wa_wf_decode(wa_setting('wa_business_hours', '{}'), []) : [],
        'in_business_hours' => function_exists('wa_in_business_hours') ? wa_in_business_hours() : true,
        // data the builder must show as a dependency (filled into {{admissions_deadline}} / {{last_date}})
        'deadline' => array_intersect_key(wa_wf_deadline_info(), array_flip(['valid', 'reason', 'value', 'date', 'where', 'url'])),
        'limits' => [
            'max_node_visits' => WA_WF_MAX_NODE_VISITS, 'max_steps_per_run' => WA_WF_MAX_STEPS_PER_RUN, 'max_runs_per_day' => WA_WF_MAX_RUNS_PER_DAY,
            'reentry_cooldown_minutes' => intdiv(WA_WF_REENTRY_COOLDOWN, 60), 'max_chain' => WA_WF_MAX_CHAIN, 'http_timeout' => 10, 'http_max_kb' => intdiv(WA_WF_HTTP_MAX_BYTES, 1024),
        ],
    ]);
});

route('GET', '/whatsapp/workflows/options/contacts', function () {
    api_require('whatsapp_automation', 'view');
    $ids = array_filter(array_map('intval', explode(',', api_query('ids'))));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all("SELECT id, name, phone, stage FROM wa_contacts WHERE id IN ($in)", array_values($ids));
    } else {
        $q = mb_substr(api_query('q'), 0, 80);
        $digits = preg_replace('/\D/', '', $q);
        $args = [];
        $where = "status = 'active'";
        if ($q !== '') {
            $where .= ' AND (name LIKE ?' . ($digits !== '' ? ' OR phone LIKE ?' : '') . ')';
            $args[] = '%' . $q . '%';
            if ($digits !== '') {
                $args[] = '%' . $digits . '%';
            }
        }
        $rows = db_all("SELECT id, name, phone, stage FROM wa_contacts WHERE $where ORDER BY COALESCE(last_message_at, created_at) DESC LIMIT 30", $args);
    }
    api_ok(array_map(fn ($r) => ['value' => (int) $r['id'], 'label' => $r['name'] ?: '+' . $r['phone'], 'sub' => '+' . $r['phone'] . ($r['stage'] ? ' · ' . label_from_key($r['stage']) : '')], $rows));
});

route('POST', '/whatsapp/workflows/validate', function () {
    api_require('whatsapp_automation', 'view');
    $raw = api_input()['definition'] ?? [];
    if (!is_array($raw) || strlen(wa_wf_json($raw)) > 400000) {
        api_error('The workflow definition is missing or too large.', 422, ['definition' => 'Invalid definition.']);
    }
    // host names are resolved (DNS) — limited so the endpoint cannot be used to probe DNS at volume
    $resolve = rate_limit('wa-wf-validate:' . user_id(), 120, 600);
    api_ok(['issues' => wa_wf_validate(wa_wf_normalize_definition($raw), ['resolve' => $resolve]), 'resolved' => $resolve]);
});

/* ---------------------------------------------------------------------------------------- execution history */

route('GET', '/whatsapp/workflows/runs', function () {
    api_require('whatsapp_automation', 'view');
    [$page, $perPage] = api_pagination(20);
    $where = ['1=1'];
    $args = [];
    if (($wid = (int) api_query('workflow_id')) > 0) {
        $where[] = 'r.workflow_id = ?';
        $args[] = $wid;
    }
    $status = api_query('status');
    if (in_array($status, ['running', 'waiting', 'completed', 'failed', 'cancelled'], true)) {
        $where[] = 'r.status = ?';
        $args[] = $status;
    } elseif ($status === 'active') {
        $where[] = "r.status IN ('running', 'waiting')";
    }
    $test = api_query('test', '0');
    if ($test === '0' || $test === '1') {
        $where[] = 'r.is_test = ?';
        $args[] = (int) $test;
    }
    if (($q = mb_substr(api_query('q'), 0, 80)) !== '') {
        $digits = preg_replace('/\D/', '', $q);
        $where[] = '(c.name LIKE ?' . ($digits !== '' ? ' OR c.phone LIKE ?' : '') . ' OR r.id = ?)';
        $args[] = '%' . $q . '%';
        if ($digits !== '') {
            $args[] = '%' . $digits . '%';
        }
        $args[] = ctype_digit($q) ? (int) $q : 0;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from = api_query('from'))) {
        $where[] = 'r.started_at >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to = api_query('to'))) {
        $where[] = 'r.started_at <= ?';
        $args[] = $to . ' 23:59:59';
    }
    $w = implode(' AND ', $where);
    $base = "FROM wa_workflow_runs r JOIN wa_workflows wf ON wf.id = r.workflow_id LEFT JOIN wa_contacts c ON c.id = r.contact_id WHERE $w";
    $total = (int) db_value("SELECT COUNT(*) $base", $args);
    $rows = db_all("SELECT r.id, r.workflow_id, wf.name AS workflow_name, r.contact_id, c.name AS contact_name, c.phone AS contact_phone, r.status, r.current_node,
                           r.waiting_for, r.next_run_at, r.attempts, r.error, r.is_test, r.started_at, r.finished_at, r.context,
                           (SELECT COUNT(*) FROM wa_workflow_steps s WHERE s.run_id = r.id) AS steps_count,
                           (SELECT COUNT(*) FROM wa_messages m WHERE m.workflow_run_id = r.id) AS messages_count
                    $base ORDER BY r.started_at DESC, r.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $args);
    foreach ($rows as &$r) {
        $ctx = wa_wf_decode($r['context'], []);
        $nodes = wa_wf_node_map(wa_wf_normalize_definition($ctx['_def'] ?? []));
        $r['current_node_label'] = isset($nodes[$r['current_node'] ?? '']) ? wa_wf_node_title($nodes[$r['current_node']]) : $r['current_node'];
        $r['current_node_type'] = $nodes[$r['current_node'] ?? '']['type'] ?? null;
        unset($r['context']);
        $r = wa_wf_cast_run($r);
        $r['steps_count'] = (int) $r['steps_count'];
        $r['messages_count'] = (int) $r['messages_count'];
    }
    unset($r);
    $countArgs = [];
    $countWhere = ['1=1'];
    if ($wid > 0) {
        $countWhere[] = 'workflow_id = ?';
        $countArgs[] = $wid;
    }
    if ($test === '0' || $test === '1') {
        $countWhere[] = 'is_test = ?';
        $countArgs[] = (int) $test;
    }
    $counts = db_pairs('SELECT status, COUNT(*) FROM wa_workflow_runs WHERE ' . implode(' AND ', $countWhere) . ' GROUP BY status', $countArgs);
    api_ok(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage)),
        'counts' => array_map('intval', $counts)]);
});

route('GET', '/whatsapp/workflows/runs/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'view');
    $d = wa_wf_run_detail((int) $p['id']);
    if (!$d) {
        api_error('Run not found.', 404);
    }
    api_ok($d);
});

route('POST', '/whatsapp/workflows/runs/{id:\d+}/retry', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $res = wa_wf_retry((int) $p['id']);
    $d = wa_wf_run_detail((int) $p['id']);
    log_activity('update', 'whatsapp_automation', $d['run']['workflow_id'] ?? null, sprintf('Retried run #%d of workflow "%s" — %s', (int) $p['id'], $d['run']['workflow_name'] ?? '', $res['status']));
    $msg = match ($res['status']) {
        'completed' => 'Run retried and completed.',
        'waiting' => 'Run retried — it is now waiting for the next step.',
        'failed' => 'The step failed again: ' . ($res['error'] ?? 'see the timeline.'),
        default => 'Run retried.',
    };
    api_ok($d, $msg);
});

route('POST', '/whatsapp/workflows/runs/{id:\d+}/cancel', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $user = current_user()['name'] ?? 'an admin';
    if (!wa_wf_cancel((int) $p['id'], 'Cancelled by ' . $user . '.')) {
        api_error('Only running or waiting runs can be cancelled.', 422);
    }
    $d = wa_wf_run_detail((int) $p['id']);
    log_activity('update', 'whatsapp_automation', $d['run']['workflow_id'] ?? null, sprintf('Cancelled run #%d of workflow "%s" for %s', (int) $p['id'], $d['run']['workflow_name'] ?? '', $d['run']['contact_name'] ?? 'a contact'));
    api_ok($d, 'Run cancelled — no further steps will run for this contact.');
});

/* ---------------------------------------------------------------------------------------- workflows */

route('POST', '/whatsapp/workflows', function () {
    api_require('whatsapp_automation', 'create');
    $in = api_input();
    $starter = api_str($in['starter'] ?? null);
    if ($starter !== '' && !isset(wa_wf_starters()[$starter])) {
        api_error('Unknown workflow template.', 422, ['starter' => 'Choose one of the listed templates.']);
    }
    $defaults = $starter !== '' ? wa_wf_starters()[$starter] : null;
    $in['name'] = api_str($in['name'] ?? null) ?: ($defaults['name'] ?? '');
    $v = api_validate(['name' => 'required|max:150', 'description' => 'max:1000'], $in, ['name' => 'Workflow name']);
    $raw = $in['definition'] ?? null;
    if ($raw !== null && (!is_array($raw) || strlen(wa_wf_json($raw)) > 400000)) {
        api_error('The workflow definition is missing or too large.', 422, ['definition' => 'Invalid definition.']);
    }
    $id = wa_wf_create((string) $v['name'], $v['description'] ?? ($defaults['description'] ?? null), $starter ?: null);
    $issues = null;
    if (is_array($raw) && !$starter) {
        // the builder's first save: store the canvas as version 1 (no second "save" bumping the version)
        $def = wa_wf_normalize_definition($raw);
        $issues = wa_wf_validate($def, ['resolve' => true]);
        db_update('wa_workflows', ['definition' => wa_wf_json($def), 'business_hours_only' => !empty($in['business_hours_only']) ? 1 : 0,
            'allow_reentry' => !empty($in['allow_reentry']) ? 1 : 0] + wa_wf_trigger_columns($def), 'id = ?', [$id]);
        wa_wf_reset_cache();
    }
    log_activity('create', 'whatsapp_automation', $id, 'Created WhatsApp workflow "' . $v['name'] . '"' . ($starter ? ' from the "' . $defaults['name'] . '" template' : ''));
    $out = wa_wf_present(wa_wf_get($id));
    $out['issues'] = $issues ?? wa_wf_validate(wa_wf_definition(wa_wf_get($id)));
    $errors = count(array_filter($out['issues'], fn ($i) => $i['level'] === 'error'));
    api_ok($out, $errors ? "Workflow created as a draft (version 1) — $errors issue(s) to fix before it can be activated." : 'Workflow created as a draft (version 1).', 201);
});

route('GET', '/whatsapp/workflows/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'view');
    $wf = wa_wf_or_404((int) $p['id']);
    $out = wa_wf_present($wf);
    $out['issues'] = wa_wf_validate(wa_wf_definition($wf));
    $out['run_counts'] = array_map('intval', db_pairs('SELECT status, COUNT(*) FROM wa_workflow_runs WHERE workflow_id = ? AND is_test = 0 GROUP BY status', [(int) $wf['id']]));
    $out['updated_by_name'] = $wf['updated_by'] ? db_value('SELECT name FROM users WHERE id = ?', [(int) $wf['updated_by']]) : null;
    api_ok($out);
});

/* performance analytics of one workflow: outcomes, duration, messages and how many runs reached / failed each step */
route('GET', '/whatsapp/workflows/{id:\d+}/stats', function ($p) {
    api_require('whatsapp_automation', 'view');
    $wf = wa_wf_or_404((int) $p['id']);
    $days = in_array((int) api_query('days', '30'), [7, 30, 90], true) ? (int) api_query('days', '30') : 30;
    $since = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
    $args = [(int) $wf['id'], $since];
    $runs = db_row("SELECT COUNT(*) AS total, SUM(status = 'completed') AS completed, SUM(status = 'failed') AS failed, SUM(status = 'cancelled') AS cancelled,
                           SUM(status IN ('running', 'waiting')) AS active,
                           AVG(CASE WHEN status = 'completed' AND finished_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, started_at, finished_at) END) AS avg_minutes
                    FROM wa_workflow_runs WHERE workflow_id = ? AND is_test = 0 AND started_at >= ?", $args) ?? [];
    $msgs = db_pairs("SELECT m.status, COUNT(*) FROM wa_messages m JOIN wa_workflow_runs r ON r.id = m.workflow_run_id
                      WHERE r.workflow_id = ? AND r.is_test = 0 AND r.started_at >= ? GROUP BY m.status", $args);
    $steps = [];
    foreach (db_all("SELECT s.node_id, s.status, COUNT(*) AS n FROM wa_workflow_steps s JOIN wa_workflow_runs r ON r.id = s.run_id
                     WHERE r.workflow_id = ? AND r.is_test = 0 AND r.started_at >= ? AND s.node_type NOT IN ('retry', 'cancel')
                     GROUP BY s.node_id, s.status", $args) as $s) {
        $steps[$s['node_id']][$s['status']] = (int) $s['n'];
    }
    $branches = [];
    foreach (db_all("SELECT s.node_id, s.output FROM wa_workflow_steps s JOIN wa_workflow_runs r ON r.id = s.run_id
                     WHERE r.workflow_id = ? AND r.is_test = 0 AND r.started_at >= ? AND s.node_type IN ('wait_reply', 'condition', 'ifelse') AND s.status = 'ok'", $args) as $s) {
        $b = (string) (wa_wf_decode($s['output'], [])['branch'] ?? '');
        if ($b !== '') {
            $branches[$s['node_id']][$b] = ($branches[$s['node_id']][$b] ?? 0) + 1;
        }
    }
    $def = wa_wf_definition($wf);
    $perNode = [];
    $queued = 0;
    foreach ($def['nodes'] as $n) {
        $c = $steps[$n['id']] ?? [];
        if (in_array($n['type'], ['template', 'message'], true)) {
            $queued += $c['ok'] ?? 0;   // every successful send step queued one message
        }
        $perNode[] = ['id' => $n['id'], 'type' => $n['type'], 'title' => wa_wf_node_title($n),
            'ok' => $c['ok'] ?? 0, 'skipped' => $c['skipped'] ?? 0, 'failed' => $c['failed'] ?? 0, 'waiting' => $c['waiting'] ?? 0,
            'reached' => array_sum($c), 'branches' => (object) ($branches[$n['id']] ?? [])];
    }
    $sent = 0;
    foreach ($msgs as $st => $cnt) {
        if (!in_array($st, ['queued', 'failed', 'cancelled'], true)) {
            $sent += (int) $cnt;
        }
    }
    $total = (int) ($runs['total'] ?? 0);
    api_ok([
        'days' => $days,
        'runs' => ['total' => $total, 'completed' => (int) ($runs['completed'] ?? 0), 'failed' => (int) ($runs['failed'] ?? 0), 'cancelled' => (int) ($runs['cancelled'] ?? 0),
            'active' => (int) ($runs['active'] ?? 0), 'completion_rate' => $total ? round(((int) $runs['completed']) / $total * 100, 1) : null,
            'avg_minutes' => $runs['avg_minutes'] !== null ? (int) round((float) $runs['avg_minutes']) : null],
        'messages' => ['queued' => $queued, 'total' => array_sum(array_map('intval', $msgs)), 'sent' => $sent, 'delivered' => (int) ($msgs['delivered'] ?? 0) + (int) ($msgs['read'] ?? 0),
            'read' => (int) ($msgs['read'] ?? 0), 'failed' => (int) ($msgs['failed'] ?? 0), 'by_status' => (object) array_map('intval', $msgs)],
        'nodes' => $perNode,
    ]);
});

route('PUT', '/whatsapp/workflows/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $wf = wa_wf_or_404((int) $p['id']);
    $in = api_input();
    $v = api_validate(['name' => 'required|max:150', 'description' => 'max:1000'], $in, ['name' => 'Workflow name']);
    if (isset($in['version']) && (int) $in['version'] !== (int) $wf['version']) {
        api_error('Someone else saved this workflow (now version ' . (int) $wf['version'] . ') while you were editing. Reload to see their changes, then apply yours again.', 409);
    }
    $raw = $in['definition'] ?? null;
    if (!is_array($raw)) {
        api_error('The workflow definition is missing.', 422);
    }
    if (strlen(wa_wf_json($raw)) > 400000) {
        api_error('The workflow is too large to save.', 422);
    }
    $def = wa_wf_unmask_definition(wa_wf_normalize_definition($raw), wa_wf_definition($wf), $cleared);
    $issues = wa_wf_validate($def, ['resolve' => true]);
    if ($wf['status'] === 'active' && wa_wf_has_errors($issues)) {
        $first = array_values(array_filter($issues, fn ($i) => $i['level'] === 'error'))[0];
        api_error('This workflow is active, so it can only be saved without errors: ' . $first['message'] . ' Fix it or deactivate the workflow first.', 422, ['definition' => $first['message']]);
    }
    $trigger = wa_wf_trigger_columns($def);
    $oldCfg = wa_wf_decode($wf['trigger_config'] ?? null, []);
    if (!empty($oldCfg['seed_key'])) {
        // keep the marker of a seeded workflow so a seeder re-run recognises it (and leaves the edited copy alone)
        $trigger['trigger_config'] = wa_wf_json(wa_wf_decode($trigger['trigger_config'], []) + ['seed_key' => $oldCfg['seed_key']]);
    }
    db_update('wa_workflows', [
        'name' => $v['name'], 'description' => $v['description'],
        'definition' => wa_wf_json($def), 'business_hours_only' => !empty($in['business_hours_only']) ? 1 : 0, 'allow_reentry' => !empty($in['allow_reentry']) ? 1 : 0,
        'version' => (int) $wf['version'] + 1, 'updated_by' => user_id(),
    ] + $trigger, 'id = ?', [(int) $wf['id']]);
    wa_wf_reset_cache();
    log_activity('update', 'whatsapp_automation', $wf['id'], sprintf('Saved WhatsApp workflow "%s" (version %d, %d steps)', $v['name'], (int) $wf['version'] + 1, count($def['nodes'])));
    $fresh = wa_wf_get((int) $wf['id']);
    $out = wa_wf_present($fresh);
    $out['issues'] = $issues;
    $errors = count(array_filter($issues, fn ($i) => $i['level'] === 'error'));
    $msg = $errors ? "Saved as version {$out['version']} — $errors issue(s) to fix before it can be activated." : "Workflow saved (version {$out['version']}).";
    if ($cleared) {
        $msg .= ' The API host changed, so these hidden header values were cleared — type them again: ' . implode(', ', $cleared) . '.';
    }
    api_ok($out, $msg);
});

route('POST', '/whatsapp/workflows/{id:\d+}/status', function ($p) {
    api_require('whatsapp_automation', 'publish');
    $wf = wa_wf_or_404((int) $p['id']);
    $status = api_str(api_input()['status'] ?? null);
    if (!in_array($status, ['active', 'inactive'], true)) {
        api_error('Choose whether to activate or deactivate the workflow.', 422, ['status' => 'Invalid status.']);
    }
    $issues = [];
    if ($status === 'active') {
        $issues = wa_wf_validate(wa_wf_definition($wf), ['resolve' => true]);
        if (wa_wf_has_errors($issues)) {
            $first = array_values(array_filter($issues, fn ($i) => $i['level'] === 'error'))[0];
            api_error('Fix the errors before activating: ' . $first['message'], 422, ['definition' => $first['message']]);
        }
    }
    db_exec('UPDATE wa_workflows SET status = ?, updated_by = ?, updated_at = updated_at WHERE id = ?', [$status, user_id(), (int) $wf['id']]);
    wa_wf_reset_cache();
    log_activity('publish', 'whatsapp_automation', $wf['id'], ($status === 'active' ? 'Activated' : 'Deactivated') . ' WhatsApp workflow "' . $wf['name'] . '"');
    $active = (int) db_value("SELECT COUNT(*) FROM wa_workflow_runs WHERE workflow_id = ? AND status IN ('running', 'waiting') AND is_test = 0", [(int) $wf['id']]);
    $names = wa_wf_event_names();
    $msg = 'Workflow deactivated — no new runs start' . ($active ? "; $active run(s) in progress will finish." : '.');
    if ($status === 'active') {
        $msg = $wf['trigger_event'] ? 'Workflow activated — it now runs whenever "' . ($names[$wf['trigger_event']] ?? $wf['trigger_event']) . '" happens.' : 'Workflow activated — enrol contacts from the builder (More › Enrol a contact).';
        // warnings do not block activation, but data dependencies must not fail silently
        $deadline = array_values(array_filter($issues, fn ($i) => ($i['code'] ?? '') === 'deadline'));
        $schedule = array_values(array_filter($issues, fn ($i) => ($i['code'] ?? '') === 'schedule'));
        $double = array_values(array_filter($issues, fn ($i) => ($i['code'] ?? '') === 'duplicate_message'));
        if ($deadline) {
            $msg .= ' Warning: ' . count($deadline) . ' step(s) use the admissions deadline, but ' . (wa_wf_deadline_problem() ?? 'it is not usable') . ' — set it in Settings › Website, or those steps fail.';
        }
        if ($schedule) {
            $msg .= ' ' . $schedule[0]['message'];
        }
        if ($double) {
            $msg .= ' Warning: ' . $double[0]['message'];
        }
    }
    api_ok(wa_wf_present(wa_wf_get((int) $wf['id'])) + ['issues' => $issues], $msg);
});

route('POST', '/whatsapp/workflows/{id:\d+}/duplicate', function ($p) {
    api_require('whatsapp_automation', 'create');
    $wf = wa_wf_or_404((int) $p['id']);
    $name = mb_substr('Copy of ' . $wf['name'], 0, 150);
    $cfg = wa_wf_decode($wf['trigger_config'] ?? null, []);
    unset($cfg['seed_key']);
    $id = db_insert('wa_workflows', [
        'name' => $name, 'description' => $wf['description'], 'trigger_type' => $wf['trigger_type'], 'trigger_event' => $wf['trigger_event'],
        'trigger_config' => wa_wf_json($cfg), 'definition' => $wf['definition'], 'status' => 'draft', 'business_hours_only' => (int) $wf['business_hours_only'],
        'allow_reentry' => (int) $wf['allow_reentry'], 'version' => 1, 'created_by' => user_id(), 'updated_by' => user_id(),
    ]);
    log_activity('create', 'whatsapp_automation', $id, 'Duplicated WhatsApp workflow "' . $wf['name'] . '" as a draft');
    api_ok(['id' => $id], 'A draft copy was created.', 201);
});

route('DELETE', '/whatsapp/workflows/{id:\d+}', function ($p) {
    api_require('whatsapp_automation', 'delete');
    $wf = wa_wf_or_404((int) $p['id']);
    if ($wf['status'] === 'active') {
        api_error('Deactivate the workflow before deleting it.', 422);
    }
    $runs = (int) db_value('SELECT COUNT(*) FROM wa_workflow_runs WHERE workflow_id = ?', [(int) $wf['id']]);
    db_transaction(function () use ($wf) {
        db_exec("UPDATE wa_workflow_runs SET status = 'cancelled', finished_at = ? WHERE workflow_id = ? AND status IN ('running', 'waiting')", [date('Y-m-d H:i:s'), (int) $wf['id']]);
        db_delete('wa_workflows', 'id = ?', [(int) $wf['id']]);
    });
    wa_wf_reset_cache();
    log_activity('delete', 'whatsapp_automation', $wf['id'], sprintf('Deleted WhatsApp workflow "%s" with %d run(s) of history', $wf['name'], $runs));
    api_ok(null, 'Workflow deleted.');
});

route('POST', '/whatsapp/workflows/{id:\d+}/test', function ($p) {
    api_require('whatsapp_automation', 'edit');
    $wf = wa_wf_or_404((int) $p['id']);
    $in = api_input();
    if (empty($in['contact_id']) && trim((string) ($in['phone'] ?? '')) === '') {
        api_error('Please fix the highlighted fields and try again.', 422, ['phone' => 'Pick a contact or type a WhatsApp number.']);
    }
    if (!rate_limit('wa-wf-test:' . user_id(), 30, 600)) {
        api_error('Too many test runs — wait a few minutes and try again.', 429);
    }
    $d = wa_wf_test($wf, $in);
    log_activity('update', 'whatsapp_automation', $wf['id'], sprintf('Test run #%d of workflow "%s" for %s — %s', $d['run']['id'], $wf['name'], $d['run']['contact_name'] ?: '+' . $d['run']['contact_phone'], $d['run']['status']));
    $msg = match ($d['run']['status']) {
        'completed' => 'Test run completed — ' . count($d['steps']) . ' step(s).',
        'failed' => 'Test run failed: ' . ($d['run']['error'] ?? 'see the trace.'),
        'cancelled' => 'Test run stopped: ' . ($d['run']['error'] ?? ''),
        default => 'Test run is ' . $d['run']['status'] . '.',
    };
    api_ok($d, $msg);
});

route('POST', '/whatsapp/workflows/{id:\d+}/enroll', function ($p) {
    api_require('whatsapp_automation', 'publish');
    $wf = wa_wf_or_404((int) $p['id']);
    if ($wf['status'] !== 'active') {
        api_error('Activate the workflow before enrolling contacts.', 422);
    }
    $v = api_validate(['contact_id' => 'required|integer|exists:wa_contacts,id'], null, ['contact_id' => 'Contact']);
    $contact = wa_contact((int) $v['contact_id']);
    $r = wa_wf_start($wf, $contact, ['contact_id' => (int) $contact['id'], 'phone' => $contact['phone'], 'name' => $contact['name'], 'enrolled_by' => current_user()['name'] ?? null],
        ['manual' => true, 'started_by' => user_id()]);
    if (!$r['ok']) {
        api_error($r['reason'] === 'already_running' ? 'This contact is already in this workflow (run #' . (int) ($r['run_id'] ?? 0) . ').' : 'The run could not start: ' . wa_wf_reason_text((string) $r['reason']) . '.', 422);
    }
    log_activity('create', 'whatsapp_automation', $wf['id'], sprintf('Enrolled %s in workflow "%s" (run #%d)', $contact['name'] ?: '+' . $contact['phone'], $wf['name'], $r['run_id']));
    api_ok(wa_wf_run_detail((int) $r['run_id']), 'Contact enrolled — run #' . $r['run_id'] . ' is ' . $r['status'] . '.');
});
