<?php
/**
 * WhatsApp bulk campaigns: /api/whatsapp/campaigns/*
 * Permissions (app/registry.php `whatsapp_campaigns`): view, create (new drafts, duplicates, own drafts), edit (any draft),
 * delete (drafts / cancelled), export (recipient reports), publish (send, schedule, pause, resume, cancel, retry),
 * manage (delete completed campaigns). Logic: app/services/whatsapp_campaigns.php (prefix wa_cmp_).
 * Variables in this file share the route loader scope: prefix $waCmp.
 *
 *   GET    whatsapp/campaigns                         list + KPI summary (status, type, template_id, q, from, to, sort, page)
 *   GET    whatsapp/campaigns/meta                    editor reference data (types, approved templates, CRM facets, segments…)
 *   POST   whatsapp/campaigns                         create a draft
 *   POST   whatsapp/campaigns/audience/preview        live recipient count + skip breakdown (unsaved editor state)
 *   GET    whatsapp/campaigns/upload-template         sample CSV for recipient uploads
 *   GET    whatsapp/campaigns/{id}                    detail (stats, charts, reasons, timeline, readiness)
 *   PUT    whatsapp/campaigns/{id}                    save a draft (a scheduled campaign goes back to draft)
 *   DELETE whatsapp/campaigns/{id}                    delete (draft / cancelled / failed; completed needs manage)
 *   POST   whatsapp/campaigns/{id}/duplicate
 *   POST   whatsapp/campaigns/{id}/upload             multipart "file" (CSV / XLSX) → replaces the uploaded list
 *   POST   whatsapp/campaigns/{id}/upload/map         {phone_column, name_column, consent_column}
 *   DELETE whatsapp/campaigns/{id}/upload
 *   GET    whatsapp/campaigns/{id}/upload/invalid     CSV report of the rows that cannot be used
 *   POST   whatsapp/campaigns/{id}/personalize/preview {variable_map, template_id?}
 *   POST   whatsapp/campaigns/{id}/test               {phone, variable_map?, template_id?}
 *   POST   whatsapp/campaigns/{id}/send               {when: now|schedule, scheduled_at}
 *   POST   whatsapp/campaigns/{id}/pause | resume | cancel | retry
 *   GET    whatsapp/campaigns/{id}/recipients         status, reason, source, q, sort, page, per_page
 *   GET    whatsapp/campaigns/{id}/export             format csv|xlsx + the recipient filters
 */

$waCmpFind = function (array $p): array {
    $c = wa_cmp_get((int) ($p['id'] ?? 0));
    if (!$c) {
        api_error('Campaign not found.', 404);
    }
    return $c;
};

/** Drafts can be changed by editors, or by their creator with create permission. */
$waCmpRequireEdit = function (array $c): void {
    if (can('whatsapp_campaigns', 'edit')) {
        return;
    }
    if (can('whatsapp_campaigns', 'create') && (int) ($c['created_by'] ?? 0) === (int) user_id() && in_array($c['status'], ['draft', 'scheduled'], true)) {
        return;
    }
    api_error('You do not have permission to edit this campaign.', 403);
};

/** Editor state from the request merged over the saved campaign (for previews of unsaved changes). */
$waCmpMerged = function (array $c, array $in): array {
    if (array_key_exists('audience', $in)) {
        $c['audience'] = wa_cmp_audience_clean($in['audience']);
    }
    if (isset($in['audience_source']) && in_array($in['audience_source'], ['filters', 'csv', 'mixed'], true)) {
        $c['audience_source'] = $in['audience_source'];
    }
    if (array_key_exists('template_id', $in)) {
        $tid = (int) api_str($in['template_id'] ?? '');
        $c['template_id'] = $tid > 0 ? $tid : null;
    }
    if (isset($in['type']) && isset(wa_cmp_types()[$in['type']])) {
        $c['type'] = $in['type'];
    }
    if (array_key_exists('rate_per_minute', $in)) {
        $c['rate_per_minute'] = (int) api_str($in['rate_per_minute'] ?? '') ?: null;
    }
    return $c;
};

/* ---------------------------------------------------------------------------------------------- list / meta */

route('GET', '/whatsapp/campaigns', function () {
    wa_cmp_require('view', 'view campaigns');
    [$page, $perPage] = api_pagination(20);
    $r = wa_cmp_list([
        'status' => api_query('status'), 'type' => api_query('type'), 'template_id' => api_query('template_id'), 'q' => api_query('q'),
        'from' => api_query('from'), 'to' => api_query('to'), 'sort' => api_query('sort', 'created'),
    ], $page, $perPage);
    $r['summary'] = wa_cmp_summary();
    api_ok($r);
});

route('GET', '/whatsapp/campaigns/meta', function () {
    wa_cmp_require('view', 'view campaigns');
    api_ok(wa_cmp_meta());
});

route('GET', '/whatsapp/campaigns/upload-template', function () {
    wa_cmp_require('view', 'view campaigns');
    csv_download('whatsapp-campaign-recipients.csv', ['Name', 'Mobile', 'WhatsApp consent', 'Course', 'Due amount', 'Due date'], [
        // numbers without a leading "+" so spreadsheet apps do not show the formula-guard apostrophe of csv_download()
        ['Rahul Sharma', '91 98765 43210', 'Yes', 'BBA (Business Analytics)', '18500', '15 Nov 2026'],
        ['Priya Verma', '9810012345', 'Yes', 'MBA', '24000', '20 Nov 2026'],
    ]);
});

/* ---------------------------------------------------------------------------------------------- create / preview */

route('POST', '/whatsapp/campaigns', function () {
    wa_cmp_require('create', 'create campaigns');
    if (!rate_limit('wa-cmp-create:' . (int) user_id(), 60, 600)) {
        api_error('Too many campaigns created. Wait a few minutes.', 429);
    }
    $in = api_input();
    $id = wa_cmp_save($in);
    wa_cmp_store_estimate($id);
    $c = wa_cmp_get($id);
    log_activity('create', 'whatsapp_campaigns', $id, 'Created WhatsApp campaign draft "' . $c['name'] . '" (' . $c['type_label'] . ')');
    api_ok(wa_cmp_public($c), 'Campaign draft created.');
});

route('POST', '/whatsapp/campaigns/audience/preview', function () use ($waCmpMerged) {
    wa_cmp_require('view', 'view campaigns');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $in = api_input();
    $id = (int) api_str($in['campaign_id'] ?? '');
    $base = $id > 0 ? wa_cmp_get($id) : null;
    if ($id > 0 && !$base) {
        api_error('Campaign not found.', 404);
    }
    $base = $base ?? ['id' => null, 'type' => 'announcement', 'template_id' => null, 'audience' => wa_cmp_audience_clean([]), 'audience_source' => 'filters', 'rate_per_minute' => null];
    api_ok(wa_cmp_audience_preview($waCmpMerged($base, $in)));
});

/* ---------------------------------------------------------------------------------------------- detail / save / delete */

route('GET', '/whatsapp/campaigns/{id:\d+}', function (array $p) use ($waCmpFind) {
    wa_cmp_require('view', 'view campaigns');
    $c = $waCmpFind($p);
    if (in_array($c['status'], ['sending', 'scheduled'], true) && function_exists('wa_maybe_tick')) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        wa_maybe_tick(); // keeps local / cron-less installs moving while someone watches the progress
    }
    api_ok(wa_cmp_detail((int) $c['id']));
});

route('PUT', '/whatsapp/campaigns/{id:\d+}', function (array $p) use ($waCmpFind, $waCmpRequireEdit) {
    $c = $waCmpFind($p);
    $waCmpRequireEdit($c);
    $wasScheduled = $c['status'] === 'scheduled';
    wa_cmp_save(api_input(), (int) $c['id']);
    wa_cmp_store_estimate((int) $c['id']);
    $c2 = wa_cmp_get((int) $c['id']);
    log_activity('update', 'whatsapp_campaigns', $c2['id'], 'Updated WhatsApp campaign "' . $c2['name'] . '"' . ($wasScheduled ? ' (moved back to draft)' : ''));
    api_ok(wa_cmp_public($c2), $wasScheduled ? 'Saved. The campaign is a draft again — schedule it from the Review step.' : 'Campaign saved.');
});

route('DELETE', '/whatsapp/campaigns/{id:\d+}', function (array $p) use ($waCmpFind) {
    wa_cmp_require('delete', 'delete campaigns');
    $c = $waCmpFind($p);
    wa_cmp_delete((int) $c['id'], can('whatsapp_campaigns', 'manage'));
    log_activity('delete', 'whatsapp_campaigns', $c['id'], 'Deleted WhatsApp campaign "' . $c['name'] . '" (' . $c['status'] . ')');
    api_ok(['id' => $c['id']], 'Campaign deleted.');
});

route('POST', '/whatsapp/campaigns/{id:\d+}/duplicate', function (array $p) use ($waCmpFind) {
    wa_cmp_require('create', 'create campaigns');
    $c = $waCmpFind($p);
    $nid = wa_cmp_duplicate((int) $c['id']);
    $n = wa_cmp_get($nid);
    log_activity('duplicate', 'whatsapp_campaigns', $nid, 'Duplicated WhatsApp campaign "' . $c['name'] . '" as "' . $n['name'] . '"');
    api_ok(wa_cmp_public($n), 'Campaign duplicated as a new draft.');
});

/* ---------------------------------------------------------------------------------------------- CSV / XLSX audience */

route('POST', '/whatsapp/campaigns/{id:\d+}/upload', function (array $p) use ($waCmpFind, $waCmpRequireEdit) {
    $c = $waCmpFind($p);
    $waCmpRequireEdit($c);
    if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
        api_error('The recipient list can only be changed on a draft.', 409);
    }
    if (!rate_limit('wa-cmp-upload:' . (int) user_id(), 40, 600)) {
        api_error('Too many uploads. Wait a few minutes.', 429);
    }
    $summary = wa_cmp_upload_store((int) $c['id'], $_FILES['file'] ?? null);
    wa_cmp_store_estimate((int) $c['id']);
    if ($c['status'] === 'scheduled') {
        db_update('wa_campaigns', ['status' => 'draft', 'scheduled_at' => null], 'id = ?', [(int) $c['id']]);
    }
    log_activity('upload', 'whatsapp_campaigns', $c['id'], 'Uploaded recipient list ' . $summary['file_name'] . ' to WhatsApp campaign "' . $c['name'] . '" ('
        . number_format($summary['valid_rows']) . ' valid, ' . number_format($summary['invalid_rows']) . ' invalid, ' . number_format($summary['duplicate_rows']) . ' duplicate rows)');
    api_ok($summary, number_format($summary['valid_rows']) . ' recipient(s) ready from ' . $summary['file_name'] . '.');
});

route('POST', '/whatsapp/campaigns/{id:\d+}/upload/map', function (array $p) use ($waCmpFind, $waCmpRequireEdit) {
    $c = $waCmpFind($p);
    $waCmpRequireEdit($c);
    if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
        api_error('The recipient list can only be changed on a draft.', 409);
    }
    $in = api_input();
    $col = function (string $k) use ($in): ?int {
        $v = api_str($in[$k] ?? '');
        return $v === '' || !preg_match('/^\d+$/', $v) ? null : (int) $v;
    };
    $summary = wa_cmp_upload_map((int) $c['id'], $col('phone_column'), $col('name_column'), $col('consent_column'));
    wa_cmp_store_estimate((int) $c['id']);
    api_ok($summary, 'Columns updated: ' . number_format($summary['valid_rows']) . ' valid recipient(s).');
});

route('DELETE', '/whatsapp/campaigns/{id:\d+}/upload', function (array $p) use ($waCmpFind, $waCmpRequireEdit) {
    $c = $waCmpFind($p);
    $waCmpRequireEdit($c);
    if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
        api_error('The recipient list can only be changed on a draft.', 409);
    }
    wa_cmp_upload_delete((int) $c['id']);
    wa_cmp_store_estimate((int) $c['id']);
    log_activity('update', 'whatsapp_campaigns', $c['id'], 'Removed the uploaded recipient list of WhatsApp campaign "' . $c['name'] . '"');
    api_ok(null, 'Uploaded list removed.');
});

route('GET', '/whatsapp/campaigns/{id:\d+}/upload/invalid', function (array $p) use ($waCmpFind) {
    // the rejected rows carry names and raw numbers of the uploaded list: people who build or export campaigns only
    wa_require_any([['whatsapp_campaigns', 'create'], ['whatsapp_campaigns', 'edit'], ['whatsapp_campaigns', 'export']], 'download the rejected rows of a recipient list');
    $c = $waCmpFind($p);
    $up = wa_cmp_upload_row_fresh((int) $c['id']);
    if (!$up) {
        api_error('No recipient list has been uploaded.', 404);
    }
    $an = wa_cmp_upload_analyse($up);
    $rows = [];
    $mask = wa_cmp_masking();
    foreach ($an['invalid'] as $iv) {
        $rows[] = [$iv['row'], $iv['name'] ?? '', $mask && $iv['value'] !== '' ? '•••' : $iv['value'], $iv['reason']];
    }
    foreach ($an['duplicates'] as $d) {
        $rows[] = [$d['row'], $d['name'] ?? '', wa_cmp_format_phone($d['phone']), 'Duplicate of row ' . $d['first_row']];
    }
    usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);
    log_activity('export', 'whatsapp_campaigns', $c['id'], 'Downloaded ' . number_format(count($rows)) . ' rejected row(s) of the recipient list of WhatsApp campaign "' . $c['name'] . '"');
    csv_download('campaign-' . $c['id'] . '-invalid-rows.csv', ['Row', 'Name', 'Phone value', 'Problem'], $rows);
});

/* ---------------------------------------------------------------------------------------------- personalisation / test */

route('POST', '/whatsapp/campaigns/{id:\d+}/personalize/preview', function (array $p) use ($waCmpFind, $waCmpMerged) {
    wa_cmp_require('view', 'view campaigns');
    $c = $waCmpFind($p);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $in = api_input();
    $merged = $waCmpMerged($c, $in);
    $map = is_array($in['variable_map'] ?? null) ? $in['variable_map'] : $c['variable_map'];
    api_ok(wa_cmp_personalize_preview($merged, $map, $merged['template_id'] ? (int) $merged['template_id'] : null));
});

route('POST', '/whatsapp/campaigns/{id:\d+}/test', function (array $p) use ($waCmpFind) {
    wa_require_any([['whatsapp_campaigns', 'create'], ['whatsapp_campaigns', 'edit'], ['whatsapp_campaigns', 'publish']], 'send test messages');
    $c = $waCmpFind($p);
    if (!rate_limit('wa-cmp-test:' . (int) user_id(), 20, 600)) {
        api_error('Too many test messages. Wait a few minutes.', 429);
    }
    $in = api_validate(['phone' => 'required|max:30'], null, ['phone' => 'WhatsApp number']);
    $raw = api_input();
    $map = is_array($raw['variable_map'] ?? null) ? $raw['variable_map'] : null;
    $tid = (int) api_str($raw['template_id'] ?? '');
    $r = wa_cmp_test_send($c, (string) $in['phone'], $map, $tid > 0 ? $tid : null);
    log_activity('test', 'whatsapp_campaigns', $c['id'], 'Sent a test of WhatsApp campaign "' . $c['name'] . '" to +' . $r['phone']);
    $msg = $r['mode'] === 'test'
        ? 'Test message simulated (test mode) for +' . $r['phone'] . '.'
        : ($r['status'] === 'failed' ? 'The test message failed: ' . ($r['error'] ?: 'unknown error') : 'Test message sent to +' . $r['phone'] . '.');
    api_ok($r, $msg);
});

/* ---------------------------------------------------------------------------------------------- lifecycle */

route('POST', '/whatsapp/campaigns/{id:\d+}/send', function (array $p) use ($waCmpFind) {
    wa_cmp_require('publish', 'send, schedule, pause or cancel campaigns');
    $c = $waCmpFind($p);
    $in = api_validate(['when' => 'required|in:now,schedule', 'scheduled_at' => 'max:30'], null, ['when' => 'Send time', 'scheduled_at' => 'Schedule date and time']);
    if ($in['when'] === 'schedule') {
        if (empty($in['scheduled_at'])) {
            api_error('Please fix the highlighted fields and try again.', 422, ['scheduled_at' => 'Choose the date and time to send.']);
        }
        $ready = wa_cmp_schedule((int) $c['id'], (string) $in['scheduled_at']);
        $c2 = wa_cmp_get((int) $c['id']);
        log_activity('schedule', 'whatsapp_campaigns', $c['id'], 'Scheduled WhatsApp campaign "' . $c['name'] . '" for ' . date('d M Y, h:i A', strtotime((string) $c2['scheduled_at']))
            . ' (' . number_format($ready['eligible']) . ' recipients)');
        api_ok(wa_cmp_public($c2), 'Campaign scheduled for ' . date('d M Y, h:i A', strtotime((string) $c2['scheduled_at'])) . '.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $r = wa_cmp_start((int) $c['id'], 'manual', 100);
    $c2 = wa_cmp_get((int) $c['id']);
    api_ok(wa_cmp_public($c2) + ['built' => $r['built'] ?? null], 'Campaign started: ' . number_format((int) ($r['built']['eligible'] ?? 0)) . ' recipient(s) are being messaged.');
});

route('POST', '/whatsapp/campaigns/{id:\d+}/pause', function (array $p) use ($waCmpFind) {
    wa_cmp_require('publish', 'send, schedule, pause or cancel campaigns');
    $c = $waCmpFind($p);
    $r = wa_cmp_pause((int) $c['id']);
    log_activity('pause', 'whatsapp_campaigns', $c['id'], 'Paused WhatsApp campaign "' . $c['name'] . '"' . ($r['recalled'] ? ' (' . $r['recalled'] . ' queued messages held back)' : ''));
    api_ok(wa_cmp_public($r['campaign']), 'Campaign paused.' . ($r['recalled'] ? ' ' . $r['recalled'] . ' queued message(s) were held back.' : ''));
});

route('POST', '/whatsapp/campaigns/{id:\d+}/resume', function (array $p) use ($waCmpFind) {
    wa_cmp_require('publish', 'send, schedule, pause or cancel campaigns');
    $c = $waCmpFind($p);
    $r = wa_cmp_resume((int) $c['id']);
    log_activity('resume', 'whatsapp_campaigns', $c['id'], 'Resumed WhatsApp campaign "' . $c['name'] . '"');
    api_ok(wa_cmp_public($r['campaign']), 'Campaign resumed.');
});

route('POST', '/whatsapp/campaigns/{id:\d+}/cancel', function (array $p) use ($waCmpFind) {
    wa_cmp_require('publish', 'send, schedule, pause or cancel campaigns');
    $c = $waCmpFind($p);
    $r = wa_cmp_cancel((int) $c['id']);
    log_activity('cancel', 'whatsapp_campaigns', $c['id'], 'Cancelled WhatsApp campaign "' . $c['name'] . '"' . ($r['stopped'] ? ' (' . number_format($r['stopped']) . ' messages not sent)' : ''));
    api_ok(wa_cmp_public($r['campaign']), 'Campaign cancelled.' . ($r['stopped'] ? ' ' . number_format($r['stopped']) . ' message(s) will not be sent.' : ''));
});

route('POST', '/whatsapp/campaigns/{id:\d+}/retry', function (array $p) use ($waCmpFind) {
    wa_cmp_require('publish', 'send, schedule, pause or cancel campaigns');
    $c = $waCmpFind($p);
    $n = wa_cmp_retry_failed((int) $c['id']);
    if ($n === 0) {
        api_error('There are no failed messages to retry (each recipient is retried at most 5 times).', 409);
    }
    log_activity('retry', 'whatsapp_campaigns', $c['id'], 'Retried ' . number_format($n) . ' failed message(s) of WhatsApp campaign "' . $c['name'] . '"');
    api_ok(wa_cmp_public(wa_cmp_get((int) $c['id'])), number_format($n) . ' failed message(s) queued again.');
});

/* ---------------------------------------------------------------------------------------------- recipients / export */

route('GET', '/whatsapp/campaigns/{id:\d+}/recipients', function (array $p) use ($waCmpFind) {
    wa_cmp_require('view', 'view campaigns');
    $c = $waCmpFind($p);
    [$page, $perPage] = api_pagination(25);
    api_ok(wa_cmp_recipients((int) $c['id'], [
        'status' => api_query('status'), 'reason' => api_query('reason'), 'source' => api_query('source'), 'q' => api_query('q'), 'sort' => api_query('sort'),
    ], $page, $perPage));
});

route('GET', '/whatsapp/campaigns/{id:\d+}/export', function (array $p) use ($waCmpFind) {
    wa_cmp_require('export', 'export campaign reports');
    if (!wa_cmp_can_export()) {
        api_error('Only WhatsApp managers can export reports (Settings › WhatsApp › Security).', 403);
    }
    $c = $waCmpFind($p);
    $f = ['status' => api_query('status'), 'reason' => api_query('reason'), 'source' => api_query('source'), 'q' => api_query('q')];
    $data = wa_cmp_export_rows((int) $c['id'], $f);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($c['name'])), '-') ?: 'campaign';
    $suffix = $f['status'] !== '' ? '-' . preg_replace('/[^a-z]/', '', $f['status']) : '';
    // Excel needs the PHP zip extension; without it the same report is served as CSV instead of failing
    $format = api_query('format', 'csv') === 'xlsx' && class_exists('ZipArchive') ? 'xlsx' : 'csv';
    log_activity('export', 'whatsapp_campaigns', $c['id'], 'Exported ' . number_format(count($data['rows'])) . ' recipient(s) of WhatsApp campaign "' . $c['name'] . '" (' . strtoupper($format)
        . ($f['status'] !== '' ? ', ' . $f['status'] : '') . ')');
    $name = 'whatsapp-campaign-' . $c['id'] . '-' . mb_substr($slug, 0, 40) . $suffix . '-' . date('Ymd') . '.' . $format;
    if ($format === 'xlsx') {
        xlsx_download($name, $data['headers'], $data['rows'], 'Recipients');
    }
    csv_download($name, $data['headers'], $data['rows']);
});
