<?php
/**
 * WhatsApp contact CRM, admission leads and follow-ups — /api/whatsapp/contacts/*, /tags/*, /leads/*, /followups/*
 * (service: app/services/whatsapp_contacts.php).
 *
 * RBAC: whatsapp_contacts view (lists, detail, board, follow-up queue), create (add contact), edit (update, stage, tags,
 * counsellor, opt-in, notes, bulk, follow-up send/skip/reschedule), delete (delete, merge), export, import (file import,
 * ERP sync). Follow-up stage rules: whatsapp_automation edit. Sending a template: whatsapp create. Creating the ERP
 * admission enquiry of a contact (POST contacts/{id}/enquiry, POST contacts with create_enquiry): whatsapp_contacts edit
 * + enquiries create.
 */

function wa_crm_require(string $action, string $module = 'whatsapp_contacts'): void
{
    if (can($module, $action)) {
        return;
    }
    $labels = [
        'whatsapp_contacts' => ['view' => 'view WhatsApp contacts', 'create' => 'add WhatsApp contacts', 'edit' => 'update WhatsApp contacts and leads',
            'delete' => 'delete or merge WhatsApp contacts', 'export' => 'export WhatsApp contacts', 'import' => 'import or sync WhatsApp contacts'],
        'whatsapp_automation' => ['edit' => 'change follow-up rules'],
        'whatsapp' => ['create' => 'send WhatsApp messages'],
    ];
    api_error('Your role does not allow you to ' . ($labels[$module][$action] ?? $action . ' ' . $module) . '. Ask a Super Admin to update your role.', 403);
}

/** Filters from the query string (scalar or list values only). */
function wa_crm_query_filters(): array
{
    $f = [];
    foreach ($_GET as $k => $v) {
        if (!is_string($k) || in_array($k, ['r', 'page', 'per_page', 'format'], true)) {
            continue;
        }
        if (is_array($v)) {
            $f[$k] = array_values(array_filter($v, 'is_scalar'));
        } elseif (is_scalar($v)) {
            $f[$k] = trim((string) $v);
        }
    }
    return $f;
}

/** A scalar request value as a trimmed string ('' for lists / objects — never "Array" with a PHP warning). */
function wa_crm_str($v): string
{
    return is_scalar($v) ? trim((string) $v) : '';
}

function wa_crm_route_contact(int $id): array
{
    $c = wa_contact($id);
    if (!$c) {
        api_error('This contact no longer exists.', 404);
    }
    return $c;
}

function wa_crm_label(array $c): string
{
    return trim(($c['name'] ?: 'Contact') . ' (+' . $c['phone'] . ')');
}

/* ================================================================================================ contacts */

route('GET', '/whatsapp/contacts', function () {
    wa_crm_require('view');
    [$page, $perPage] = api_pagination(25);
    api_ok(wa_crm_contacts(wa_crm_query_filters(), $page, $perPage));
});

route('GET', '/whatsapp/contacts/summary', function () {
    wa_crm_require('view');
    api_ok(wa_crm_summary());
});

route('GET', '/whatsapp/contacts/facets', function () {
    wa_crm_require('view');
    api_ok(wa_crm_facets());
});

/** Option list for comboboxes: ?q= search, ?ids= resolve labels. */
route('GET', '/whatsapp/contacts/options', function () {
    wa_crm_require('view');
    $ids = array_filter(array_map('intval', explode(',', api_query('ids'))));
    if ($ids) {
        $rows = db_all('SELECT id, name, phone, contact_type FROM wa_contacts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids));
    } else {
        $res = wa_crm_contacts(['q' => api_query('q'), 'sort' => 'name', 'dir' => 'asc'], 1, max(5, min(50, (int) api_query('limit', '20'))));
        $rows = $res['data'];
    }
    api_ok(array_map(function ($r) {
        $phone = (string) wa_crm_show_phone((string) $r['phone']);
        $phone = str_starts_with($phone, '+') ? $phone : '+' . $phone;
        return ['value' => (int) $r['id'], 'label' => $r['name'] ?: $phone, 'sub' => $phone . ' · ' . (wa_crm_contact_types()[$r['contact_type']] ?? $r['contact_type'])];
    }, $rows));
});

route('GET', '/whatsapp/contacts/counsellors', function () {
    wa_crm_require('view');
    $q = mb_strtolower(api_query('q'));
    $list = wa_crm_counsellors();
    if ($q !== '') {
        $list = array_values(array_filter($list, fn ($o) => str_contains(mb_strtolower($o['label']), $q)));
    }
    api_ok($list);
});

/** Count contacts matching a filter set (segment builder, bulk "all matching"). */
route('POST', '/whatsapp/contacts/count', function () {
    wa_crm_require('view');
    $in = api_input();
    api_ok(wa_crm_count(wa_crm_clean_filters(is_array($in['filters'] ?? null) ? $in['filters'] : [])));
});

/** May the user turn WhatsApp contacts into ERP admission enquiries? (whatsapp_contacts edit + enquiries create) */
function wa_crm_can_create_enquiry(): bool
{
    return can('whatsapp_contacts', 'edit') && can('enquiries', 'create');
}

function wa_crm_require_enquiry(): void
{
    if (!wa_crm_can_create_enquiry()) {
        api_error('Your role does not allow you to create admission enquiries from WhatsApp contacts (needs WhatsApp contacts › edit and Enquiries › create). Ask a Super Admin to update your role.', 403);
    }
}

route('POST', '/whatsapp/contacts', function () {
    wa_crm_require('create');
    $in = api_input();
    // "Also create an admission enquiry" (Admission Leads › Add lead)
    $withEnquiry = !empty($in['create_enquiry']) && $in['create_enquiry'] !== '0' && $in['create_enquiry'] !== 'false';
    if ($withEnquiry) {
        wa_crm_require_enquiry();
    }
    $id = wa_crm_save_contact($in);
    $c = wa_contact($id);
    log_activity('create', 'whatsapp_contacts', $id, 'Added WhatsApp contact ' . wa_crm_label($c));
    if (!$withEnquiry) {
        api_ok(['id' => $id], 'Contact added.');
    }
    $enq = wa_crm_create_enquiry($id, ['origin' => 'leads']);
    api_ok(['id' => $id, 'enquiry' => $enq], $enq['ok'] ? 'Lead added. ' . $enq['message'] : 'Lead added, but the admission enquiry was not created: ' . $enq['error']);
});

/** Create (or link) the ERP admission enquiry of a contact — inbox contact panel, contact record. */
route('POST', '/whatsapp/contacts/{id:\d+}/enquiry', function ($p) {
    wa_crm_require('edit');
    wa_crm_require_enquiry();
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_validate([
        'name' => 'max:150', 'email' => 'email|max:190', 'city' => 'max:80', 'program_id' => 'integer|exists:programs,id', 'program_interest' => 'max:190',
        'assigned_to' => 'integer', 'priority' => 'in:high,medium,low', 'message' => 'max:5000', 'note' => 'max:1000', 'origin' => 'in:inbox,contact,leads',
    ], null, ['program_id' => 'Programme', 'assigned_to' => 'Counsellor', 'program_interest' => 'Other interest', 'message' => 'Enquiry / message', 'note' => 'Internal note']);
    if (!empty($in['assigned_to']) && !db_value("SELECT id FROM users WHERE id = ? AND status = 'active'", [(int) $in['assigned_to']])) {
        api_error('Please fix the highlighted fields and try again.', 422, ['assigned_to' => 'Choose an active counsellor.']);
    }
    $opts = ['origin' => $in['origin'] ?: 'contact'];
    foreach (['name', 'email', 'city', 'program_id', 'program_interest', 'assigned_to', 'priority', 'message', 'note'] as $k) {
        if ($in[$k] !== null && $in[$k] !== '') {
            $opts[$k] = $in[$k];
        }
    }
    $res = wa_crm_create_enquiry((int) $c['id'], $opts);
    if (!$res['ok']) {
        // field-level problems (e.g. no name on the request nor on the contact) also come back as errors.{field}, like api_validate
        api_error($res['error'] ?? 'The admission enquiry could not be created.', 422, $res['errors'] ?? []);
    }
    api_ok($res, $res['message']);
});

route('GET', '/whatsapp/contacts/{id:\d+}', function ($p) {
    wa_crm_require('view');
    $c = wa_crm_contact_detail((int) $p['id']);
    if (!$c) {
        api_error('This contact no longer exists.', 404);
    }
    api_ok($c);
});

route('PUT', '/whatsapp/contacts/{id:\d+}', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    wa_crm_save_contact(api_input(), (int) $c['id']);
    log_activity('edit', 'whatsapp_contacts', $c['id'], 'Updated WhatsApp contact ' . wa_crm_label(wa_contact((int) $c['id'])));
    api_ok(['id' => (int) $c['id']], 'Contact updated.');
});

route('DELETE', '/whatsapp/contacts/{id:\d+}', function ($p) {
    wa_crm_require('delete');
    $c = wa_crm_route_contact((int) $p['id']);
    db_delete('wa_contacts', 'id = ?', [(int) $c['id']]);
    log_activity('delete', 'whatsapp_contacts', $c['id'], 'Deleted WhatsApp contact ' . wa_crm_label($c) . ' with its conversation');
    api_ok(null, 'Contact deleted.');
});

route('POST', '/whatsapp/contacts/{id:\d+}/stage', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_validate(['stage' => 'required|in:' . implode(',', array_keys(wa_crm_stage_defaults())), 'note' => 'max:255', 'source' => 'in:manual,board'], null, ['stage' => 'Stage']);
    $enqBefore = $c['enquiry_id'] ? db_value('SELECT status FROM enquiries WHERE id = ?', [(int) $c['enquiry_id']]) : null;
    $changed = wa_crm_set_stage((int) $c['id'], $in['stage'], $in['source'] ?: 'manual', $in['note']);
    if ($changed) {
        log_activity('edit', 'whatsapp_contacts', $c['id'], 'Moved ' . wa_crm_label($c) . ' from ' . wa_crm_stage_name($c['stage']) . ' to ' . wa_crm_stage_name($in['stage']));
    }
    // the move is mirrored on the linked ERP enquiry (wa_crm_enquiry_writeback) — say so when its status changed
    $enqAfter = $c['enquiry_id'] ? db_value('SELECT status FROM enquiries WHERE id = ?', [(int) $c['enquiry_id']]) : null;
    $enquiry = $enqAfter && $enqAfter !== $enqBefore ? ['id' => (int) $c['enquiry_id'], 'status' => $enqAfter] : null;
    api_ok(['stage' => $in['stage'], 'changed' => $changed, 'enquiry' => $enquiry], $changed
        ? 'Moved to ' . wa_crm_stage_name($in['stage']) . '.' . ($enquiry ? ' Enquiry ' . wa_crm_enquiry_no($enquiry['id']) . ' is now ' . label_from_key($enqAfter) . '.' : '')
        : 'Already in this stage.');
});

route('POST', '/whatsapp/contacts/{id:\d+}/assign', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_validate(['counsellor_id' => 'integer'], null, ['counsellor_id' => 'Counsellor']);
    $uid = $in['counsellor_id'] ? (int) $in['counsellor_id'] : null;
    $name = null;
    if ($uid) {
        $name = db_value("SELECT name FROM users WHERE id = ? AND status = 'active'", [$uid]);
        if (!$name) {
            api_error('Please fix the highlighted fields and try again.', 422, ['counsellor_id' => 'Choose an active counsellor.']);
        }
    }
    db_update('wa_contacts', ['counsellor_id' => $uid], 'id = ?', [(int) $c['id']]);
    wa_agent_log('counsellor', null, (int) $c['id'], $uid ? 'Counsellor set to ' . $name : 'Counsellor removed');
    if ($uid && $uid !== user_id()) {
        notify($uid, 'whatsapp', 'WhatsApp lead assigned: ' . ($c['name'] ?: '+' . $c['phone']), 'Assigned by ' . (current_user()['name'] ?? 'a colleague') . ' · ' . wa_crm_stage_name($c['stage']), 'admin/whatsapp/contacts/' . $c['id'], 'user-check');
    }
    log_activity('edit', 'whatsapp_contacts', $c['id'], ($uid ? 'Assigned ' . wa_crm_label($c) . ' to ' . $name : 'Unassigned ' . wa_crm_label($c)));
    api_ok(['counsellor_id' => $uid], $uid ? 'Assigned to ' . $name . '.' : 'Counsellor removed.');
});

route('POST', '/whatsapp/contacts/{id:\d+}/tags', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_input();
    $names = wa_crm_list($in['tags'] ?? []);
    foreach ($names as $n) {
        if (mb_strlen($n) > 60) {
            api_error('Please fix the highlighted fields and try again.', 422, ['tags' => 'Tags can be up to 60 characters.']);
        }
    }
    wa_crm_set_tags((int) $c['id'], $names);
    log_activity('edit', 'whatsapp_contacts', $c['id'], 'Updated tags of ' . wa_crm_label($c) . ': ' . ($names ? implode(', ', $names) : 'none'));
    api_ok(['tags' => $names], 'Tags updated.');
});

route('POST', '/whatsapp/contacts/{id:\d+}/opt', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_input();
    $optIn = !empty($in['opt_in']) && $in['opt_in'] !== '0' && $in['opt_in'] !== 'false';
    $source = mb_substr(wa_crm_str($in['source'] ?? ''), 0, 80);
    if ($optIn && $source === '') {
        api_error('Please fix the highlighted fields and try again.', 422, ['source' => 'Record where the consent came from (required for opt-in).']);
    }
    wa_contact_opt((int) $c['id'], $optIn, $source ?: 'manual');
    log_activity('edit', 'whatsapp_contacts', $c['id'], ($optIn ? 'Opted in ' : 'Opted out ') . wa_crm_label($c) . ($source ? ' — ' . $source : ''));
    api_ok(['opt_in' => $optIn ? 1 : 0], $optIn ? 'WhatsApp opt-in recorded.' : 'Contact opted out of WhatsApp.');
});

route('POST', '/whatsapp/contacts/{id:\d+}/notes', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_validate(['body' => 'required|max:2000'], null, ['body' => 'Note']);
    $conv = db_value('SELECT id FROM wa_conversations WHERE contact_id = ?', [(int) $c['id']]);
    $id = db_insert('wa_notes', ['contact_id' => (int) $c['id'], 'conversation_id' => $conv ?: null, 'user_id' => user_id(), 'body' => $in['body']]);
    log_activity('create', 'whatsapp_contacts', $c['id'], 'Added a note to ' . wa_crm_label($c));
    api_ok(['id' => $id], 'Note added.');
});

route('POST', '/whatsapp/contacts/{id:\d+}/rename', function ($p) {
    wa_crm_require('edit');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_validate(['name' => 'required|max:150'], null, ['name' => 'Name']);
    db_update('wa_contacts', ['name' => $in['name']], 'id = ?', [(int) $c['id']]);
    log_activity('edit', 'whatsapp_contacts', $c['id'], 'Renamed contact +' . $c['phone'] . ' from "' . ($c['name'] ?? '') . '" to "' . $in['name'] . '"');
    api_ok(null, 'Name updated.');
});

/** Templates that can be sent to this contact, with the values resolved from the CRM / ERP. */
route('GET', '/whatsapp/contacts/{id:\d+}/templates', function ($p) {
    wa_crm_require('view');
    $c = wa_crm_route_contact((int) $p['id']);
    $vars = wa_variables_for_contact($c);
    $rows = db_all("SELECT * FROM wa_templates WHERE status NOT IN ('archived','disabled') ORDER BY status = 'approved' DESC, usage_count DESC, name LIMIT 100");
    $out = [];
    foreach ($rows as $t) {
        try {
            $r = wa_render_template($t, $vars);
        } catch (Throwable $e) {
            $r = ['text' => $t['body'], 'missing' => []];
        }
        $out[] = [
            'id' => (int) $t['id'], 'name' => $t['name'], 'display_name' => $t['display_name'], 'category' => $t['category'], 'language' => $t['language'],
            'status' => $t['status'], 'body' => $t['body'], 'header_type' => $t['header_type'], 'header_text' => $t['header_text'], 'footer' => $t['footer'],
            'buttons' => json_decode((string) $t['buttons'], true) ?: [], 'variables' => json_decode((string) $t['variables'], true) ?: (object) [],
            'preview' => $r['text'] ?? '', 'missing' => array_values($r['missing'] ?? []),
        ];
    }
    api_ok(['templates' => $out, 'values' => $vars, 'opt_in' => (int) $c['opt_in'], 'window_open' => wa_contact_window_open($c)]);
});

route('POST', '/whatsapp/contacts/{id:\d+}/send-template', function ($p) {
    wa_crm_require('create', 'whatsapp');
    $c = wa_crm_route_contact((int) $p['id']);
    $in = api_input();
    $tplId = (int) ($in['template_id'] ?? 0);
    $tpl = $tplId ? wa_template_find($tplId) : null;
    if (!$tpl) {
        api_error('Please fix the highlighted fields and try again.', 422, ['template_id' => 'Choose a template.']);
    }
    $vars = array_merge(wa_variables_for_contact($c), array_filter(is_array($in['vars'] ?? null) ? array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $in['vars']) : [], fn ($v) => $v !== ''));
    $res = wa_send_template((int) $c['id'], (int) $tpl['id'], $vars, ['sender_type' => 'agent', 'sent_by' => user_id(), 'priority' => 2]);
    if (empty($res['ok'])) {
        $msgs = [
            'opted_out' => 'This contact has not opted in to WhatsApp marketing messages.', 'blocked' => 'This contact is blocked.',
            'template_not_approved' => 'This template is not approved by WhatsApp yet.', 'missing_variables' => 'Fill in every template variable.',
            'invalid_phone' => 'The contact number is not a valid WhatsApp number.',
        ];
        api_error($msgs[$res['code'] ?? ''] ?? ($res['error'] ?? 'The message could not be queued.'), 422, ($res['code'] ?? '') === 'missing_variables' ? ['vars' => (string) ($res['error'] ?? 'Missing variables')] : []);
    }
    wa_agent_log('template', null, (int) $c['id'], 'Sent template ' . $tpl['name']);
    log_activity('create', 'whatsapp_contacts', $c['id'], 'Sent WhatsApp template ' . $tpl['name'] . ' to ' . wa_crm_label($c));
    api_ok($res, 'Template message queued.');
});

/* ---------------------------------------------------------------- bulk / export / import / sync */

route('POST', '/whatsapp/contacts/bulk', function () {
    $in = api_input();
    $action = (string) ($in['action'] ?? '');
    wa_crm_require($action === 'delete' ? 'delete' : 'edit');
    if (!empty($in['all'])) {
        $ids = wa_crm_contact_ids(wa_crm_clean_filters(is_array($in['filters'] ?? null) ? $in['filters'] : []));
    } else {
        $ids = array_map('intval', is_array($in['ids'] ?? null) ? $in['ids'] : []);
    }
    $res = wa_crm_bulk($action, $ids, $in);
    $labels = ['tag' => 'Tagged', 'untag' => 'Untagged', 'assign' => 'Assigned', 'stage' => 'Changed the stage of', 'opt_in' => 'Opted in', 'opt_out' => 'Opted out', 'status' => 'Changed the status of', 'delete' => 'Deleted'];
    $detail = $action === 'tag' || $action === 'untag' ? ' (' . implode(', ', wa_crm_list($in['tags'] ?? [])) . ')' : ($action === 'stage' ? ' → ' . wa_crm_stage_name((string) ($in['stage'] ?? '')) : ($action === 'status' ? ' → ' . ($in['status'] ?? '') : ''));
    log_activity($action === 'delete' ? 'delete' : 'edit', 'whatsapp_contacts', null, ($labels[$action] ?? $action) . ' ' . $res['affected'] . ' WhatsApp contact(s)' . $detail);
    api_ok($res, $res['message']);
});

route('GET', '/whatsapp/contacts/export', function () {
    wa_crm_require('export');
    if (!wa_crm_can_export()) {
        api_error('Exports are limited to WhatsApp settings managers (Settings › WhatsApp › Security).', 403);
    }
    $format = api_query('format', 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    $f = wa_crm_query_filters();
    $data = wa_crm_export_rows($f);
    log_activity('export', 'whatsapp_contacts', null, 'Exported ' . count($data['rows']) . ' WhatsApp contacts (' . strtoupper($format) . ')');
    $name = 'whatsapp-contacts-' . date('Y-m-d') . '.' . $format;
    if ($format === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            api_error('Excel export needs the PHP zip extension on this server. Export as CSV instead.', 422);
        }
        xlsx_download($name, $data['headers'], $data['rows'], 'Contacts');
    }
    wa_crm_csv_download($name, $data['headers'], $data['rows']);
});

/** CSV download (UTF-8 BOM, formula-injection safe) — passes fputcsv's escape argument explicitly (PHP 8.4). */
function wa_crm_csv_download(string $filename, array $headers, array $rows): void
{
    download_headers($filename, 'text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map('csv_safe', array_values($row)), ',', '"', '');
    }
    fclose($out);
    exit;
}

route('GET', '/whatsapp/contacts/import/template', function () {
    wa_crm_require('import');
    wa_crm_csv_download('whatsapp-contacts-import-template.csv', ['Name', 'WhatsApp number', 'Email', 'WhatsApp consent', 'Type', 'Programme', 'City', 'State', 'Batch', 'Stage', 'Tags', 'Counsellor', 'Notes'], [
        ['Aarav Sharma', '+91 98765 43210', 'aarav.sharma@example.com', 'Yes', 'Lead', 'BBA', 'Aligarh', 'Uttar Pradesh', '2026-27', 'New Enquiry', 'Hostel, Scholarship', '', 'Asked about hostel fees'],
        ['Priya Verma', '9812345678', '', 'No', 'Guardian', 'MBA', 'Mathura', 'Uttar Pradesh', '', 'Interested', 'MBA 2026', '', ''],
    ]);
});

route('POST', '/whatsapp/contacts/import/upload', function () {
    wa_crm_require('import');
    api_ok(wa_crm_import_upload($_FILES['file'] ?? []));
});

/** Merge strategy of an import request (skip | update | overwrite; anything else → update). */
function wa_crm_import_strategy(array $in): string
{
    $s = $in['strategy'] ?? 'update';
    return is_string($s) && in_array($s, ['skip', 'update', 'overwrite'], true) ? $s : 'update';
}

route('POST', '/whatsapp/contacts/import/preview', function () {
    wa_crm_require('import');
    $in = api_input();
    $strategy = wa_crm_import_strategy($in);
    api_ok(wa_crm_import_preview(wa_crm_str($in['token'] ?? ''), is_array($in['mapping'] ?? null) ? $in['mapping'] : [], $strategy));
});

route('POST', '/whatsapp/contacts/import/run', function () {
    wa_crm_require('import');
    $in = api_input();
    $strategy = wa_crm_import_strategy($in);
    @set_time_limit(300);
    $report = wa_crm_import_run(wa_crm_str($in['token'] ?? ''), is_array($in['mapping'] ?? null) ? $in['mapping'] : [], $strategy, [
        'contact_type' => wa_crm_str($in['contact_type'] ?? 'lead'), 'tags' => $in['tags'] ?? [], 'opt_in_source' => wa_crm_str($in['opt_in_source'] ?? ''),
    ]);
    if ($report['created'] || $report['updated']) {
        wa_crm_segments_recount();
    }
    log_activity('import', 'whatsapp_contacts', null, "Imported WhatsApp contacts from {$report['file']}: {$report['created']} new, {$report['updated']} updated, {$report['skipped']} skipped, {$report['failed']} failed, {$report['opted_in']} opted in");
    api_ok($report, "Import finished: {$report['created']} new, {$report['updated']} updated.");
});

route('POST', '/whatsapp/contacts/sync', function () {
    wa_crm_require('import');
    $in = api_validate(['scope' => 'in:all,incremental,enquiries,admissions,students,guardians'], null, ['scope' => 'Scope']);
    @set_time_limit(300);
    $stats = wa_crm_sync($in['scope'] ?: 'all', ['live' => false]);
    log_activity('import', 'whatsapp_contacts', null, "Synced WhatsApp contacts from the admission portal ({$stats['scope']}): {$stats['created']} new, {$stats['updated']} updated, {$stats['stage_changes']} stage changes");
    api_ok($stats, "Sync finished: {$stats['created']} new, {$stats['updated']} updated.");
});

route('GET', '/whatsapp/contacts/duplicates', function () {
    wa_crm_require('view');
    @set_time_limit(120);
    api_ok(wa_crm_duplicates());
});

route('POST', '/whatsapp/contacts/merge', function () {
    wa_crm_require('delete');
    $in = api_input();
    $keep = (int) ($in['keep_id'] ?? 0);
    $res = wa_crm_merge($keep, is_array($in['merge_ids'] ?? null) ? $in['merge_ids'] : []);
    $c = wa_contact($keep);
    log_activity('edit', 'whatsapp_contacts', $keep, 'Merged ' . implode(', ', $res['merged']) . ' into ' . wa_crm_label($c));
    api_ok($res, 'Merged ' . count($res['merged']) . ' contact(s).');
});

/* ---------------------------------------------------------------- segments */

route('GET', '/whatsapp/contacts/segments', function () {
    wa_crm_require('view');
    api_ok(wa_crm_segments());
});

route('POST', '/whatsapp/contacts/segments', function () {
    wa_crm_require('edit');
    $id = wa_crm_segment_save(api_input());
    $s = wa_crm_segment($id);
    log_activity('create', 'whatsapp_contacts', $id, 'Saved WhatsApp segment "' . $s['name'] . '" (' . $s['contacts_count'] . ' contacts)');
    api_ok($s, 'Segment saved.');
});

route('PUT', '/whatsapp/contacts/segments/{id:\d+}', function ($p) {
    wa_crm_require('edit');
    if (!wa_crm_segment((int) $p['id'])) {
        api_error('This segment no longer exists.', 404);
    }
    wa_crm_segment_save(api_input(), (int) $p['id']);
    $s = wa_crm_segment((int) $p['id']);
    log_activity('edit', 'whatsapp_contacts', $s['id'], 'Updated WhatsApp segment "' . $s['name'] . '"');
    api_ok($s, 'Segment updated.');
});

route('DELETE', '/whatsapp/contacts/segments/{id:\d+}', function ($p) {
    wa_crm_require('edit');
    $s = wa_crm_segment((int) $p['id']);
    if (!$s) {
        api_error('This segment no longer exists.', 404);
    }
    db_delete('wa_segments', 'id = ?', [$s['id']]);
    log_activity('delete', 'whatsapp_contacts', $s['id'], 'Deleted WhatsApp segment "' . $s['name'] . '"');
    api_ok(null, 'Segment deleted.');
});

/* ================================================================================================ tags */

route('GET', '/whatsapp/tags', function () {
    wa_crm_require('view');
    api_ok(wa_crm_tags());
});

route('POST', '/whatsapp/tags', function () {
    wa_crm_require('edit');
    $id = wa_crm_tag_save(api_input());
    log_activity('create', 'whatsapp_contacts', $id, 'Created WhatsApp tag "' . db_value('SELECT name FROM wa_tags WHERE id = ?', [$id]) . '"');
    api_ok(['id' => $id], 'Tag created.');
});

route('PUT', '/whatsapp/tags/{id:\d+}', function ($p) {
    wa_crm_require('edit');
    $old = db_row('SELECT * FROM wa_tags WHERE id = ?', [(int) $p['id']]);
    if (!$old) {
        api_error('This tag no longer exists.', 404);
    }
    wa_crm_tag_save(api_input(), (int) $old['id']);
    log_activity('edit', 'whatsapp_contacts', $old['id'], 'Updated WhatsApp tag "' . $old['name'] . '"');
    api_ok(null, 'Tag updated.');
});

route('DELETE', '/whatsapp/tags/{id:\d+}', function ($p) {
    wa_crm_require('delete');
    $old = db_row('SELECT * FROM wa_tags WHERE id = ?', [(int) $p['id']]);
    if (!$old) {
        api_error('This tag no longer exists.', 404);
    }
    $n = (int) db_value('SELECT COUNT(*) FROM wa_contact_tags WHERE tag_id = ?', [$old['id']]);
    db_delete('wa_tags', 'id = ?', [$old['id']]);
    log_activity('delete', 'whatsapp_contacts', $old['id'], 'Deleted WhatsApp tag "' . $old['name'] . '" (removed from ' . $n . ' contacts)');
    api_ok(null, 'Tag deleted.');
});

/* ================================================================================================ leads */

route('GET', '/whatsapp/leads/board', function () {
    wa_crm_require('view');
    api_ok(wa_crm_leads_board(wa_crm_query_filters(), max(5, min(60, (int) api_query('per_stage', '30')))));
});

route('GET', '/whatsapp/leads/stats', function () {
    wa_crm_require('view');
    api_ok(wa_crm_leads_stats(wa_crm_query_filters()));
});

/* ================================================================================================ follow-ups */

route('GET', '/whatsapp/followups', function () {
    wa_crm_require('view');
    [$page, $perPage] = api_pagination(25);
    $res = wa_crm_followups(wa_crm_query_filters(), $page, $perPage);
    $res['counts'] = wa_crm_followup_counts();
    api_ok($res);
});

route('GET', '/whatsapp/followups/stages', function () {
    wa_crm_require('view');
    $catalog = wa_variable_catalog();
    $templates = array_map(function ($t) use ($catalog) {
        $vars = json_decode((string) $t['variables'], true) ?: [];
        $keys = [];
        foreach (array_merge($vars['header'] ?? [], $vars['body'] ?? [], array_values(is_array($vars['buttons'] ?? null) ? $vars['buttons'] : [])) as $v) {
            if (is_array($v) && !empty($v['key']) && !isset($keys[$v['key']])) {
                $keys[$v['key']] = ['key' => $v['key'], 'label' => $catalog[$v['key']]['label'] ?? label_from_key((string) $v['key']), 'sample' => $v['sample'] ?? ($catalog[$v['key']]['sample'] ?? '')];
            }
        }
        return [
            'value' => (int) $t['id'], 'label' => ($t['display_name'] ?: $t['name']), 'sub' => $t['name'] . ' · ' . strtolower($t['category']) . ' · ' . $t['status'],
            'category' => $t['category'], 'status' => $t['status'], 'body' => $t['body'], 'variables' => array_values($keys),
        ];
    }, db_all("SELECT id, name, display_name, category, status, body, variables FROM wa_templates WHERE status NOT IN ('archived') ORDER BY status = 'approved' DESC, name"));
    // keys the engine fills from the contact / ERP data (the stage defaults only apply when those are empty)
    $auto = ['name', 'first_name', 'phone', 'email', 'city', 'student_name', 'student_first_name', 'course_name', 'program_short', 'batch', 'application_number',
        'application_status', 'admission_stage', 'counsellor_name', 'counsellor_phone', 'institute_name', 'institute_phone', 'institute_email', 'website_url',
        'apply_link', 'admission_link', 'brochure_link', 'support_number', 'academic_session', 'date_today', 'guardian_name', 'admission_no', 'due_amount', 'due_date',
        'enquiry_no', 'enquiry_id'];
    api_ok(['stages' => wa_crm_followup_stages(), 'templates' => $templates, 'counsellors' => wa_crm_counsellors(), 'counts' => wa_crm_followup_counts(),
        'auto_variables' => $auto, 'business_hours' => wa_in_business_hours(), 'can_edit' => can('whatsapp_automation', 'edit')]);
});

route('PUT', '/whatsapp/followups/stages/{key:[a-z_]+}', function ($p) {
    wa_crm_require('edit', 'whatsapp_automation');
    $res = wa_crm_followup_stage_save($p['key'], api_input());
    $s = wa_crm_stage_config($p['key']);
    log_activity('edit', 'whatsapp_automation', $res['id'], 'Updated WhatsApp follow-up rule for ' . $s['name'] . ': ' . ((int) $s['is_active'] ? (int) $s['max_followups'] . ' follow-up(s) every ' . (int) $s['delay_hours'] . ' h' : 'paused') . ($res['cancelled'] ? ", {$res['cancelled']} queued follow-up(s) cancelled" : ''));
    api_ok(['id' => $res['id'], 'cancelled' => $res['cancelled']], 'Follow-up rule saved.' . ($res['cancelled'] ? " {$res['cancelled']} queued follow-up(s) cancelled." : ''));
});

function wa_crm_route_followup(int $id): array
{
    $f = db_row('SELECT f.*, c.name AS contact_name, c.phone FROM wa_followups f JOIN wa_contacts c ON c.id = f.contact_id WHERE f.id = ?', [$id]);
    if (!$f) {
        api_error('This follow-up no longer exists.', 404);
    }
    return $f;
}

route('POST', '/whatsapp/followups/{id:\d+}/send', function ($p) {
    wa_crm_require('edit');
    wa_crm_require('create', 'whatsapp');
    $f = wa_crm_route_followup((int) $p['id']);
    if ($f['status'] !== 'scheduled') {
        api_error('Only scheduled follow-ups can be sent now.', 422);
    }
    $r = wa_crm_followup_process($f, true);
    if ($r['status'] === 'busy') {
        api_error('This follow-up is being sent by the background worker right now. Refresh in a moment.', 409);
    }
    log_activity('edit', 'whatsapp_contacts', $f['contact_id'], 'Sent follow-up #' . $f['attempt'] . ' (' . wa_crm_stage_name($f['stage_key']) . ') to ' . ($f['contact_name'] ?: '+' . $f['phone']) . ' now: ' . $r['status'] . ' — ' . $r['note']);
    if ($r['status'] !== 'sent') {
        api_error('Not sent: ' . $r['note'], 422);
    }
    api_ok($r, 'Follow-up queued for sending.');
});

route('POST', '/whatsapp/followups/{id:\d+}/skip', function ($p) {
    wa_crm_require('edit');
    $f = wa_crm_route_followup((int) $p['id']);
    if ($f['status'] !== 'scheduled') {
        api_error('Only scheduled follow-ups can be skipped.', 422);
    }
    $in = api_validate(['reason' => 'max:200'], null, ['reason' => 'Reason']);
    $note = 'Skipped by ' . (current_user()['name'] ?? 'staff') . ($in['reason'] ? ': ' . $in['reason'] : '');
    db_update('wa_followups', ['status' => 'skipped', 'note' => mb_substr($note, 0, 255)], 'id = ?', [$f['id']]);
    log_activity('edit', 'whatsapp_contacts', $f['contact_id'], 'Skipped follow-up #' . $f['attempt'] . ' (' . wa_crm_stage_name($f['stage_key']) . ') for ' . ($f['contact_name'] ?: '+' . $f['phone']));
    api_ok(null, 'Follow-up skipped.');
});

route('POST', '/whatsapp/followups/{id:\d+}/reschedule', function ($p) {
    wa_crm_require('edit');
    $f = wa_crm_route_followup((int) $p['id']);
    if (!in_array($f['status'], ['scheduled', 'skipped', 'failed'], true)) {
        api_error('This follow-up can no longer be rescheduled.', 422);
    }
    $in = api_input();
    $due = str_replace('T', ' ', trim((string) ($in['due_at'] ?? '')));
    $ts = strtotime($due);
    if (!$due || !$ts) {
        api_error('Please fix the highlighted fields and try again.', 422, ['due_at' => 'Choose a date and time.']);
    }
    if ($ts < time() - 300) {
        api_error('Please fix the highlighted fields and try again.', 422, ['due_at' => 'Choose a time in the future.']);
    }
    db_update('wa_followups', ['status' => 'scheduled', 'due_at' => date('Y-m-d H:i:s', $ts), 'note' => mb_substr('Rescheduled by ' . (current_user()['name'] ?? 'staff'), 0, 255)], 'id = ?', [$f['id']]);
    log_activity('edit', 'whatsapp_contacts', $f['contact_id'], 'Rescheduled follow-up #' . $f['attempt'] . ' for ' . ($f['contact_name'] ?: '+' . $f['phone']) . ' to ' . date('d M Y H:i', $ts));
    api_ok(null, 'Follow-up rescheduled to ' . date('d M Y, h:i A', $ts) . '.');
});

/** Process the follow-ups that are due right now (same as the worker tick). */
route('POST', '/whatsapp/followups/run', function () {
    wa_crm_require('edit');
    $stats = wa_crm_followups_tick(15.0);
    $done = $stats['sent'] + $stats['skipped'] + $stats['cancelled'] + $stats['failed'] + $stats['rescheduled'];
    log_activity('edit', 'whatsapp_contacts', null, 'Ran due WhatsApp follow-ups: ' . $stats['sent'] . ' sent, ' . $stats['skipped'] . ' skipped, ' . $stats['cancelled'] . ' cancelled, ' . $stats['failed'] . ' failed, ' . $stats['rescheduled'] . ' moved to business hours');
    $msg = !$done ? 'No follow-ups are due right now.' : ($stats['rescheduled'] && !$stats['sent']
        ? "Outside business hours — {$stats['rescheduled']} due follow-up(s) moved to the next business-hours slot."
        : "Processed $done follow-up(s): {$stats['sent']} sent" . ($stats['rescheduled'] ? ", {$stats['rescheduled']} moved to business hours" : '') . '.');
    if (!empty($stats['left_for_next_run'])) {
        $msg .= ' ' . $stats['left_for_next_run'] . ' more will be sent by the next worker run.';
    }
    api_ok($stats, $msg);
});
