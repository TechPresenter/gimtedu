<?php
/**
 * WhatsApp Template Designer + Library: /api/whatsapp/templates/*
 * Permissions (app/registry.php `whatsapp_templates`): view, create (new drafts / duplicates), edit (drafts, mapping,
 * archive), delete (drafts), approve (submit to Interakt, sync, import), manage (test-mode approval simulator).
 * Logic: app/services/whatsapp_templates.php (prefix wa_tpl_). Variables in this file share the loader scope: prefix $waTpl.
 */

$waTplFind = function (array $p): array {
    $t = wa_tpl_get((int) ($p['id'] ?? 0));
    if (!$t) {
        api_error('Template not found.', 404);
    }
    return $t;
};

/** Library rows with stats, filters status|category|language|q|sort, page/per_page. */
route('GET', '/whatsapp/templates', function () {
    api_require('whatsapp_templates', 'view');
    [$page, $perPage] = api_pagination(24);
    api_ok(wa_tpl_list([
        'status' => api_query('status'),
        'category' => api_query('category'),
        'language' => api_query('language'),
        'q' => api_query('q'),
        'sort' => api_query('sort', 'updated'),
    ], $page, $perPage));
});

route('GET', '/whatsapp/templates/summary', function () {
    api_require('whatsapp_templates', 'view');
    api_ok(wa_tpl_summary());
});

/** Designer reference data: categories, languages, limits, statuses, GIMT variable catalogue, mode. */
route('GET', '/whatsapp/templates/meta', function () {
    api_require('whatsapp_templates', 'view');
    $mode = wa_tpl_mode();
    api_ok([
        'categories' => wa_tpl_categories(),
        'languages' => wa_tpl_languages(),
        'statuses' => wa_tpl_statuses(),
        'limits' => wa_tpl_limits(),
        'catalog' => wa_tpl_catalog(),
        'mode' => $mode,
        'configured' => function_exists('wa_is_configured') ? wa_is_configured() : false,
        'default_language' => function_exists('wa_setting') ? (string) wa_setting('wa_default_language', 'en') : 'en',
        'business_name' => function_exists('wa_setting') ? (string) (wa_setting('wa_business_name') ?: setting('site_name', 'GIMT')) : (string) setting('site_name', 'GIMT'),
        'business_number' => function_exists('wa_setting') ? (string) wa_setting('wa_business_number', '') : '',
        'can_simulate' => $mode === 'test' && can('whatsapp_templates', 'manage'),
        'last_sync' => setting('_wa_tpl_last_sync'),
    ]);
});

/** Is "name" free in "language"? Also returns the slugged name the designer will store. */
route('GET', '/whatsapp/templates/check-name', function () {
    api_require('whatsapp_templates', 'view');
    $name = wa_tpl_slug(api_query('name'));
    $lang = api_query('language', 'en');
    $id = (int) api_query('id', '0');
    $taken = $name !== '' && wa_tpl_name_taken($name, $lang, $id ?: null);
    api_ok([
        'name' => $name,
        'available' => $name !== '' && !$taken,
        'suggestion' => $taken ? wa_tpl_free_name($name, $lang, 'v') : null,
    ]);
});

/** Compliance check of designer input without saving. */
route('POST', '/whatsapp/templates/validate', function () {
    api_require('whatsapp_templates', 'view');
    $in = api_input();
    $id = isset($in['id']) ? (int) $in['id'] : null;
    $c = wa_tpl_compile($in, $id ?: null);
    $row = $c['row'];
    api_ok([
        'issues' => $c['issues'],
        'compiled' => [
            'name' => $row['name'],
            'header_text' => $row['header_text'],
            'body' => $row['body'],
            'buttons' => json_decode((string) $row['buttons'], true) ?: [],
            'variables' => json_decode((string) $row['variables'], true),
        ],
        'can_save' => !wa_tpl_blocking($c['issues']),
        'can_submit' => !wa_tpl_blocking($c['issues'], true),
    ]);
});

/** Interakt templates not in the library yet (import dialog). */
route('GET', '/whatsapp/templates/remote', function () {
    api_require('whatsapp_templates', 'approve');
    if (wa_tpl_mode() !== 'live') {
        api_ok(['mode' => 'test', 'items' => []]);
    }
    api_ok(['mode' => 'live', 'items' => wa_tpl_remote_missing()]);
});

/** Pull approval statuses from Interakt. */
route('POST', '/whatsapp/templates/sync', function () {
    api_require('whatsapp_templates', 'approve');
    $r = wa_tpl_sync(false);
    if ($r['mode'] !== 'live') {
        api_ok($r, 'Test mode: Interakt was not contacted. Use "Simulate review" on pending templates, or switch to live mode in WhatsApp Settings.');
    }
    $waTplRecat = count($r['recategorised'] ?? []);
    log_activity('sync', 'whatsapp_templates', null, "Synced WhatsApp template statuses from Interakt ({$r['checked']} checked, {$r['updated']} changed"
        . ($waTplRecat ? ", $waTplRecat re-categorised by Meta" : '') . ')');
    $waTplMsg = $r['updated'] ? "{$r['updated']} template status(es) updated from Interakt." : ($waTplRecat ? '' : "All {$r['checked']} templates are up to date.");
    if ($waTplRecat) {
        $waTplMsg = trim($waTplMsg . ' ' . ($waTplRecat === 1
            ? 'Meta re-categorised "' . $r['recategorised'][0]['name'] . '" to ' . ucfirst(strtolower($r['recategorised'][0]['to'])) . ' — check where it is used.'
            : "Meta re-categorised $waTplRecat templates — check where they are used."));
    }
    api_ok($r, $waTplMsg);
});

/** Import approved/existing Interakt templates: {remote_ids: [...]} or {all: true}. */
route('POST', '/whatsapp/templates/import', function () {
    api_require('whatsapp_templates', 'approve');
    if (wa_tpl_mode() !== 'live') {
        api_error('Importing needs live mode with an Interakt API key (WhatsApp Settings).', 422);
    }
    $in = api_input();
    $ids = null;
    if (empty($in['all'])) {
        $ids = array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) ($in['remote_ids'] ?? []))));
        if (!$ids) {
            api_error('Choose at least one template to import.', 422, ['remote_ids' => 'Choose at least one template.']);
        }
    }
    $r = wa_tpl_sync(true, $ids);
    log_activity('import', 'whatsapp_templates', null, "Imported {$r['imported']} WhatsApp template(s) from Interakt");
    api_ok($r, $r['imported'] ? "Imported {$r['imported']} template(s). Map their variables to GIMT fields before using them." : 'Nothing new to import.');
});

route('GET', '/whatsapp/templates/{id:\d+}', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'view');
    $t = $waTplFind($p);
    $stats = wa_tpl_stats([$t['id']])[$t['id']];
    $stats30 = wa_tpl_stats([$t['id']], date('Y-m-d 00:00:00', strtotime('-29 days')))[$t['id']];
    $people = db_pairs('SELECT id, name FROM users WHERE id IN (?, ?)', [(int) ($t['created_by'] ?? 0), (int) ($t['updated_by'] ?? 0)]);
    $versions = array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'status' => $r['status'], 'created_at' => $r['created_at']], db_all(
        'SELECT id, name, status, created_at FROM wa_templates WHERE (parent_id = ? OR id = ?) AND id <> ? ORDER BY id',
        [$t['id'], (int) ($t['parent_id'] ?? 0), $t['id']]
    ));
    api_ok([
        'template' => $t + [
            'created_by_name' => $people[$t['created_by'] ?? 0] ?? null,
            'updated_by_name' => $people[$t['updated_by'] ?? 0] ?? null,
            'editable' => wa_tpl_is_editable($t),
            'recategorised' => wa_tpl_recategorised([$t])[$t['id']] ?? null,
        ],
        'named' => wa_tpl_named($t),
        'issues' => wa_tpl_issues($t),
        'stats' => $stats,
        'stats_30d' => $stats30,
        'trend' => wa_tpl_trend($t['id'], 30),
        'usage' => wa_tpl_usage($t['id'], $t['name']),
        'versions' => $versions,
    ]);
});

route('POST', '/whatsapp/templates', function () {
    api_require('whatsapp_templates', 'create');
    $in = api_input();
    unset($in['id']);
    $r = wa_tpl_save($in, null);
    $t = $r['template'];
    log_activity('create', 'whatsapp_templates', $r['id'], 'Created WhatsApp template draft "' . $t['name'] . '" (' . $t['category'] . ', ' . $t['language'] . ')');
    api_ok($r, 'Draft saved.', 201);
});

route('PUT', '/whatsapp/templates/{id:\d+}', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'edit');
    $t = $waTplFind($p);
    $r = wa_tpl_save(api_input(), $t['id']);
    if (!empty($r['mapping_only'])) {
        log_activity('update', 'whatsapp_templates', $t['id'], 'Updated variable mapping of WhatsApp template "' . $t['name'] . '"');
        api_ok($r, 'Variable mapping saved.');
    }
    log_activity('update', 'whatsapp_templates', $t['id'], 'Updated WhatsApp template draft "' . $r['template']['name'] . '"');
    api_ok($r, 'Draft saved.');
});

route('POST', '/whatsapp/templates/{id:\d+}/duplicate', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'create');
    $t = $waTplFind($p);
    $asVersion = !empty(api_input()['version']);
    $newId = wa_tpl_duplicate($t['id'], $asVersion);
    $n = wa_tpl_get($newId);
    log_activity('create', 'whatsapp_templates', $newId, ($asVersion ? 'Created new version ' : 'Duplicated ') . 'WhatsApp template "' . $t['name'] . '" as "' . $n['name'] . '"');
    api_ok(['id' => $newId, 'template' => $n], $asVersion ? "New version \"{$n['name']}\" created as a draft." : "Copied as \"{$n['name']}\".", 201);
});

route('POST', '/whatsapp/templates/{id:\d+}/submit', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'approve');
    $t = $waTplFind($p);
    $r = wa_tpl_submit($t['id']);
    $live = $r['mode'] === 'live';
    log_activity('submit', 'whatsapp_templates', $t['id'], 'Submitted WhatsApp template "' . $r['template']['name'] . '" to Interakt' . ($live ? '' : ' (test mode)'));
    unset($r['payload']);
    api_ok($r, $live
        ? 'Submitted to Interakt. Meta usually reviews templates within minutes to 24 hours — statuses sync automatically.'
        : 'Test mode: marked as pending. Use "Simulate review" to approve or reject it locally.');
});

route('POST', '/whatsapp/templates/{id:\d+}/simulate', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'manage');
    $t = $waTplFind($p);
    $in = api_input();
    $status = is_scalar($in['status'] ?? null) ? (string) $in['status'] : '';
    $reason = is_scalar($in['reason'] ?? null) ? trim((string) $in['reason']) : null;
    $n = wa_tpl_simulate($t['id'], $status, $reason);
    log_activity('simulate', 'whatsapp_templates', $t['id'], 'Simulated Meta review of WhatsApp template "' . $t['name'] . '": ' . $status . ($reason ? " ($reason)" : ''));
    api_ok(['template' => $n], 'Template marked ' . $status . ' (simulated).');
});

route('POST', '/whatsapp/templates/{id:\d+}/archive', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'edit');
    $t = $waTplFind($p);
    wa_tpl_archive($t['id']);
    log_activity('archive', 'whatsapp_templates', $t['id'], 'Archived WhatsApp template "' . $t['name'] . '"');
    api_ok(['template' => wa_tpl_get($t['id'])], $t['interakt_id']
        ? 'Archived in GIMT. It still exists in Interakt — delete it in the Interakt dashboard if it is no longer needed.'
        : 'Template archived.');
});

route('POST', '/whatsapp/templates/{id:\d+}/restore', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'edit');
    $t = $waTplFind($p);
    $to = wa_tpl_restore($t['id']);
    log_activity('restore', 'whatsapp_templates', $t['id'], 'Restored WhatsApp template "' . $t['name'] . '" (' . $to . ')');
    api_ok(['template' => wa_tpl_get($t['id'])], 'Template restored as ' . $to . '.');
});

route('DELETE', '/whatsapp/templates/{id:\d+}', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'delete');
    $t = $waTplFind($p);
    wa_tpl_delete($t['id']);
    log_activity('delete', 'whatsapp_templates', $t['id'], 'Deleted WhatsApp template draft "' . $t['name'] . '"');
    api_ok(null, 'Draft deleted.');
});

/** Send an approved template to one number with the sample values (or given values). */
route('POST', '/whatsapp/templates/{id:\d+}/test', function (array $p) use ($waTplFind) {
    api_require('whatsapp_templates', 'view');
    api_require('whatsapp', 'create');
    $t = $waTplFind($p);
    $in = api_validate(['phone' => 'required|max:20'], null, ['phone' => 'WhatsApp number']);
    if (!rate_limit('wa-tpl-test:' . user_id(), 20, 600)) {
        api_error('Too many test messages — try again in a few minutes.', 429);
    }
    if ($t['status'] !== 'approved') {
        api_error('Only approved templates can be sent. This one is ' . $t['status'] . '.', 422);
    }
    $norm = function_exists('wa_normalize_phone') ? wa_normalize_phone((string) $in['phone']) : null;
    if (!$norm) {
        api_error('Enter a valid WhatsApp number.', 422, ['phone' => 'Enter a valid WhatsApp number with country code.']);
    }
    // A test send skips the opt-in requirement (staff test on their own number) but never messages a number that
    // explicitly opted out (replied STOP) or was blocked — WhatsApp marketing policy.
    $waTplContact = db_row('SELECT opt_in, opted_out_at, status FROM wa_contacts WHERE phone = ?', [$norm['phone']]);
    if ($waTplContact && ($waTplContact['status'] ?? '') === 'blocked') {
        api_error('This number is blocked in WhatsApp contacts.', 422, ['phone' => 'This number is blocked in WhatsApp contacts.']);
    }
    if ($waTplContact && $t['category'] === 'MARKETING' && (int) $waTplContact['opt_in'] !== 1 && !empty($waTplContact['opted_out_at'])) {
        api_error('This number opted out of WhatsApp marketing messages.', 422, ['phone' => 'This number opted out (replied STOP). Use a number that has not opted out.']);
    }
    $vars = [];
    foreach ((array) (wa_tpl_named($t)['samples']) as $k => $s) {
        $vars[$k] = $s;
    }
    foreach ((array) (api_input()['vars'] ?? []) as $k => $v) {
        if (is_string($k) && is_scalar($v) && trim((string) $v) !== '') {
            $vars[$k] = trim((string) $v);
        }
    }
    $r = wa_send([
        'phone' => $norm['phone'],
        'type' => 'template',
        'template' => $t['id'],
        'vars' => $vars,
        'sender_type' => 'agent',
        'sent_by' => user_id(),
    ], ['priority' => 1, 'skip_opt_in_check' => true, 'dedupe_key' => null]);
    if (empty($r['ok'])) {
        api_error('Not sent: ' . ($r['error'] ?? $r['code'] ?? 'unknown error'), 422);
    }
    log_activity('send', 'whatsapp_templates', $t['id'], 'Sent a test of WhatsApp template "' . $t['name'] . '" to ' . $norm['phone']);
    api_ok($r, wa_tpl_mode() === 'live' ? 'Test message queued — it arrives within a minute.' : 'Test message queued (test mode: simulated, not delivered).');
});
