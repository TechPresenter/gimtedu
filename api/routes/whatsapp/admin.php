<?php
/**
 * WhatsApp ADMIN routes (/api/whatsapp/…): dashboard, reports, integration, logs, API tokens, team, settings, docs.
 * Service: app/services/whatsapp_admin.php (prefix wa_adm_), loaded by app/services/whatsapp.php.
 *
 *   GET    whatsapp/dashboard?period=daily|weekly|monthly|custom&from&to      dashboard payload (+ opportunistic worker tick)
 *   GET    whatsapp/reports/meta                                              report catalogue + filter options
 *   GET    whatsapp/reports/{key}?period|from|to|program_id|source|…          one report (summary, charts, tables)
 *   GET    whatsapp/reports/{key}/export?format=csv|xlsx&table=               download a report table
 *   GET    whatsapp/integration                                               connection + webhook + worker info (no secrets)
 *   PUT    whatsapp/integration                                               save API key (write-only), base URL, plan, rate, business number/name
 *   DELETE whatsapp/integration/key                                           remove the saved API key
 *   POST   whatsapp/integration/mode            {mode, confirm}               switch test / live
 *   POST   whatsapp/integration/test                                          test the Interakt connection (list templates)
 *   POST   whatsapp/integration/webhook-secret  {secret?}                     generate (or set) the webhook secret — returned once
 *   POST   whatsapp/integration/webhook-test                                  signed test webhook through receive + process
 *   GET    whatsapp/logs/webhooks | logs/webhooks/{id} | POST logs/webhooks/{id}/reprocess
 *   GET    whatsapp/logs/api | logs/api/{id}
 *   GET    whatsapp/logs/errors?days=
 *   GET    whatsapp/logs/queue?status= | POST logs/queue/retry {ids} | POST logs/queue/cancel {ids}
 *   GET    whatsapp/logs/track?q= | logs/track/{messageId}
 *   GET    whatsapp/tokens | POST whatsapp/tokens {name, scopes[]} | POST whatsapp/tokens/{id}/revoke
 *   GET    whatsapp/team | team/matrix | team/activity  ·  PUT whatsapp/team/roles/{id}/permissions {permissions[]}
 *   GET    whatsapp/settings | PUT whatsapp/settings/{section}
 *   GET    whatsapp/docs
 */

/** Any WhatsApp view (dashboard). */
function wa_adm_require_dashboard(): void
{
    wa_require_any([['whatsapp', 'view'], ['whatsapp_reports', 'view'], ['whatsapp_settings', 'view']], 'view the WhatsApp dashboard');
}

function wa_adm_require(string $action, string $what): void
{
    $pairs = [['whatsapp_settings', $action]];
    wa_require_any($pairs, $what);
}

function wa_adm_close_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/* ---------------------------------------------------------------------------------------------- dashboard */
route('GET', '/whatsapp/dashboard', function () {
    wa_adm_require_dashboard();
    wa_adm_close_session();
    wa_maybe_tick();
    $out = wa_adm_dashboard($_GET);
    if (!wa_can('whatsapp_settings', 'view')) {
        unset($out['connection']['last_test']);
    }
    api_ok($out);
});

/* ---------------------------------------------------------------------------------------------- reports */
route('GET', '/whatsapp/reports/meta', function () {
    api_require('whatsapp_reports', 'view');
    api_ok(wa_adm_report_meta());
});

route('GET', '/whatsapp/reports/{key:[a-z]+}', function (array $p) {
    api_require('whatsapp_reports', 'view');
    wa_adm_close_session();
    try {
        api_ok(wa_adm_report($p['key'], $_GET));
    } catch (InvalidArgumentException $e) {
        api_error('Unknown report.', 404);
    }
});

route('GET', '/whatsapp/reports/{key:[a-z]+}/export', function (array $p) {
    api_require('whatsapp_reports', 'view');
    if (!wa_adm_can_export()) {
        api_error(wa_can('whatsapp_reports', 'export')
            ? 'Only WhatsApp settings managers can export reports (WhatsApp › Settings › Security).'
            : 'Your role does not allow you to export WhatsApp reports. Ask a Super Admin to update your role.', 403);
    }
    if (!rate_limit('wa-report-export:' . (int) user_id(), 60, 3600)) {
        api_error('Too many exports. Please wait a few minutes.', 429);
    }
    try {
        $r = wa_adm_report($p['key'], $_GET);
    } catch (InvalidArgumentException $e) {
        api_error('Unknown report.', 404);
    }
    $tableKey = api_query('table');
    $table = null;
    foreach ($r['tables'] as $t) {
        if ($tableKey === '' || $t['key'] === $tableKey) {
            $table = $t;
            break;
        }
    }
    if (!$table) {
        api_error('Unknown report table.', 404);
    }
    $csv = api_query('format') === 'csv';
    if (!$csv && !class_exists('ZipArchive')) {
        api_error('Excel export needs the PHP zip extension on this server — download the CSV instead.', 422);
    }
    [$headers, $rows] = wa_adm_report_export_rows($table);
    $name = 'whatsapp-' . $p['key'] . '-' . $table['key'] . '-' . $r['range']['from'] . '_to_' . $r['range']['to'];
    log_activity('export', 'whatsapp_reports', null, 'Exported WhatsApp report "' . $r['title'] . ' › ' . $table['title'] . '" as ' . ($csv ? 'CSV' : 'Excel')
        . ' (' . count($rows) . ' rows, ' . $r['range']['from'] . ' to ' . $r['range']['to'] . ')');
    require_once APP_ROOT . '/app/xlsx.php';
    if ($csv) {
        wa_adm_csv_download($name . '.csv', $headers, $rows);
    }
    xlsx_download($name . '.xlsx', $headers, $rows, mb_substr($table['title'], 0, 31));
});

/* ---------------------------------------------------------------------------------------------- integration */
route('GET', '/whatsapp/integration', function () {
    wa_adm_require('view', 'view the integration');
    api_ok(wa_adm_integration_info());
});

route('PUT', '/whatsapp/integration', function () {
    wa_adm_require('manage', 'change the Interakt connection');
    $notes = wa_adm_integration_save(api_input());
    if ($notes) {
        log_activity('update', 'whatsapp_settings', null, 'Updated the Interakt connection: ' . implode('; ', $notes));
    }
    api_ok(wa_adm_integration_info(), $notes ? 'Interakt connection saved.' : 'No changes to save.');
});

route('DELETE', '/whatsapp/integration/key', function () {
    wa_adm_require('manage', 'remove the API key');
    if (!wa_is_configured()) {
        api_error('No API key is saved.', 404);
    }
    save_setting('wa_interakt_api_key', '', 'whatsapp');
    save_setting('_wa_last_connection_test', '', 'internal');
    if (wa_setting('wa_mode', 'test') === 'live') {
        save_setting('wa_mode', 'test', 'whatsapp');
    }
    log_activity('delete', 'whatsapp_settings', null, 'Removed the Interakt API key (WhatsApp switched to test mode)');
    api_ok(wa_adm_integration_info(), 'API key removed. WhatsApp is in test mode.');
});

route('POST', '/whatsapp/integration/mode', function () {
    wa_adm_require('manage', 'switch the WhatsApp mode');
    $in = api_validate(['mode' => 'required|in:test,live', 'confirm' => 'max:20'], null, ['mode' => 'Mode']);
    if ($in['mode'] === 'live') {
        if (!wa_is_configured()) {
            api_error('Save the Interakt API key before switching to live mode.', 422, ['mode' => 'Save the API key first.']);
        }
        if (strtoupper((string) ($in['confirm'] ?? '')) !== 'LIVE') {
            api_error('Type LIVE to confirm that real WhatsApp messages will be sent.', 422, ['confirm' => 'Type LIVE to confirm.']);
        }
    }
    $before = wa_setting('wa_mode', 'test');
    save_setting('wa_mode', $in['mode'], 'whatsapp');
    if ($before !== $in['mode']) {
        log_activity('update', 'whatsapp_settings', null, 'Switched WhatsApp to ' . strtoupper($in['mode']) . ' mode', 'success', ['from' => $before, 'to' => $in['mode']]);
    }
    $info = wa_adm_integration_info();
    $msg = $in['mode'] === 'live'
        ? ($info['mode'] === 'live' ? 'Live mode on — messages now go to WhatsApp through Interakt.' : 'Mode saved as live, but this is a development install, so messages stay simulated.')
        : 'Test mode on — messages are simulated and nothing is sent to WhatsApp.';
    api_ok($info, $msg);
});

route('POST', '/whatsapp/integration/test', function () {
    wa_adm_require('edit', 'test the connection');
    if (!rate_limit('wa-adm-test:' . (int) user_id(), 20, 600)) {
        api_error('Too many connection tests. Wait a few minutes.', 429);
    }
    $r = wa_adm_test_connection();
    if (!empty($r['skipped'])) {
        // test mode: Interakt was not contacted — say so (200, not a fake "connected")
        log_activity('test', 'whatsapp_settings', null, 'Interakt connection test skipped (test mode — no request sent)', 'success');
        api_ok($r, 'Test mode — Interakt was not contacted. Switch to live mode to verify the API key.');
    }
    log_activity('test', 'whatsapp_settings', null, 'Tested the Interakt connection: ' . ($r['ok'] ? 'OK (' . $r['duration_ms'] . ' ms)' : 'failed — ' . $r['error']), $r['ok'] ? 'success' : 'failed');
    if (!$r['ok']) {
        api_error('Connection failed: ' . ($r['error'] ?: 'unknown error'), 422, ['connection' => (string) $r['error']]);
    }
    api_ok($r, 'Connected to Interakt' . ($r['templates'] !== null ? ' — ' . $r['templates'] . ' approved template(s) found.' : '.'));
});

route('POST', '/whatsapp/integration/webhook-secret', function () {
    wa_adm_require('manage', 'change the webhook secret');
    // The secret is generated / typed in the browser (it must be pasted into Interakt anyway); the server only stores it.
    $secret = trim((string) (api_input()['secret'] ?? ''));
    if ($secret === '' || strlen($secret) < 16 || strlen($secret) > 200 || preg_match('/\s/', $secret)) {
        api_error('Please fix the highlighted fields and try again.', 422, ['secret' => 'Use 16 to 200 characters without spaces.']);
    }
    $was = wa_adm_last4('wa_webhook_secret') !== null;
    wa_adm_rotate_webhook_secret($secret);
    log_activity('update', 'whatsapp_settings', null, ($was ? 'Changed' : 'Set') . ' the WhatsApp webhook secret (…' . substr($secret, -4) . ')');
    api_ok(['secret_set' => true, 'last4' => substr($secret, -4)], 'Webhook secret saved. Interakt must use exactly the same secret.');
});

route('POST', '/whatsapp/integration/webhook-test', function () {
    wa_adm_require('edit', 'send a test webhook');
    if (!rate_limit('wa-adm-hooktest:' . (int) user_id(), 30, 600)) {
        api_error('Too many test webhooks. Wait a few minutes.', 429);
    }
    $r = wa_adm_test_webhook();
    log_activity('test', 'whatsapp_settings', $r['webhook_log_id'], 'Sent a signed test webhook (log #' . $r['webhook_log_id'] . ')');
    $sig = $r['signature_valid'] === null ? 'no secret configured' : ($r['signature_valid'] ? 'signature valid' : 'signature INVALID');
    api_ok($r, 'Test webhook stored as log #' . $r['webhook_log_id'] . ' (' . $sig . ').');
});

/* ---------------------------------------------------------------------------------------------- logs */
route('GET', '/whatsapp/logs/webhooks', function () {
    wa_adm_require('view', 'view webhook logs');
    [$page, $per] = api_pagination(25);
    api_ok(wa_adm_webhook_logs($_GET, $page, $per));
});

route('GET', '/whatsapp/logs/webhooks/{id:\d+}', function (array $p) {
    wa_adm_require('view', 'view webhook logs');
    $r = wa_adm_webhook_log((int) $p['id']);
    if (!$r) {
        api_error('Webhook log not found.', 404);
    }
    api_ok($r);
});

route('POST', '/whatsapp/logs/webhooks/{id:\d+}/reprocess', function (array $p) {
    wa_adm_require('edit', 'reprocess webhooks');
    $r = wa_adm_webhook_reprocess((int) $p['id']);
    log_activity('update', 'whatsapp_settings', (int) $p['id'], 'Reprocessed WhatsApp webhook #' . $p['id'] . ' → ' . $r['status']);
    api_ok($r, 'Webhook reprocessed: ' . $r['status'] . ($r['note'] ? ' (' . $r['note'] . ')' : '') . '.');
});

route('GET', '/whatsapp/logs/api', function () {
    wa_adm_require('view', 'view API logs');
    [$page, $per] = api_pagination(25);
    api_ok(wa_adm_api_logs($_GET, $page, $per));
});

route('GET', '/whatsapp/logs/api/{id:\d+}', function (array $p) {
    wa_adm_require('view', 'view API logs');
    $r = wa_adm_api_log((int) $p['id']);
    if (!$r) {
        api_error('API log not found.', 404);
    }
    api_ok($r);
});

route('GET', '/whatsapp/logs/errors', function () {
    wa_adm_require('view', 'view error monitoring');
    api_ok(wa_adm_error_summary((int) ($_GET['days'] ?? 7)));
});

route('GET', '/whatsapp/logs/queue', function () {
    wa_adm_require('view', 'view the send queue');
    [$page, $per] = api_pagination(25);
    api_ok(wa_adm_queue($_GET, $page, $per));
});

route('POST', '/whatsapp/logs/queue/retry', function () {
    wa_adm_require('edit', 'retry messages');
    $ids = api_input()['ids'] ?? null;
    if (!is_array($ids) || !$ids) {
        api_error('Choose at least one message to retry.', 422, ['ids' => 'Choose at least one message.']);
    }
    $r = wa_adm_queue_retry(array_slice($ids, 0, 500));
    $n = $r['requeued'];
    $why = implode(', ', array_map(fn ($k, $v) => $k . ' (' . $v . ')', array_keys($r['reasons']), $r['reasons']));
    if ($n) {
        log_activity('update', 'whatsapp_settings', null, 'Re-queued ' . $n . ' failed WhatsApp message(s) from the retry queue' . ($r['refused'] ? ' (' . $r['refused'] . ' refused: ' . $why . ')' : ''));
    }
    if (!$n && $r['refused']) {
        api_error('Nothing was re-queued: ' . $why . '.', 409, ['ids' => $why]);
    }
    api_ok($r, $n ? $n . ' message(s) put back in the queue' . ($r['refused'] ? ' · ' . $r['refused'] . ' skipped (' . $why . ')' : '') . '.'
        : 'Nothing to retry — only failed or stuck messages can be retried.');
});

route('POST', '/whatsapp/logs/queue/cancel', function () {
    wa_adm_require('edit', 'cancel queued messages');
    $ids = api_input()['ids'] ?? null;
    if (!is_array($ids) || !$ids) {
        api_error('Choose at least one message to cancel.', 422, ['ids' => 'Choose at least one message.']);
    }
    $n = wa_adm_queue_cancel(array_slice($ids, 0, 1000));
    if ($n) {
        log_activity('update', 'whatsapp_settings', null, 'Cancelled ' . $n . ' queued WhatsApp message(s)');
    }
    api_ok(['cancelled' => $n], $n ? $n . ' message(s) cancelled.' : 'Nothing to cancel — only pending messages can be cancelled.');
});

route('GET', '/whatsapp/logs/track', function () {
    wa_adm_require('view', 'track messages');
    api_ok(wa_adm_track_search(mb_substr(api_query('q'), 0, 80)));
});

route('GET', '/whatsapp/logs/track/{id:\d+}', function (array $p) {
    wa_adm_require('view', 'track messages');
    $r = wa_adm_track_message((int) $p['id']);
    if (!$r) {
        api_error('Message not found.', 404);
    }
    api_ok($r);
});

/* ---------------------------------------------------------------------------------------------- API tokens */
route('GET', '/whatsapp/tokens', function () {
    wa_adm_require('view', 'view API tokens');
    api_ok(['tokens' => wa_adm_tokens(), 'scopes' => wa_adm_token_scopes()]);
});

route('POST', '/whatsapp/tokens', function () {
    wa_adm_require('manage', 'create API tokens');
    $in = api_validate(['name' => 'required|max:120'], null, ['name' => 'Token name']);
    $scopes = api_input()['scopes'] ?? ['events'];
    $scopes = is_array($scopes) ? array_values(array_unique(array_filter($scopes, fn ($s) => is_string($s) && isset(wa_adm_token_scopes()[$s])))) : [];
    if (!$scopes) {
        api_error('Please fix the highlighted fields and try again.', 422, ['scopes' => 'Choose at least one scope.']);
    }
    if ((int) db_value('SELECT COUNT(*) FROM wa_api_tokens WHERE revoked_at IS NULL') >= 50) {
        api_error('You already have 50 active tokens. Revoke unused tokens first.', 422);
    }
    [$id, $token] = wa_adm_token_create(strip_tags((string) $in['name']), $scopes);
    log_activity('create', 'whatsapp_settings', $id, 'Created WhatsApp API token "' . $in['name'] . '" (…' . substr($token, -4) . ', scopes: ' . implode(', ', $scopes) . ')');
    api_ok(['id' => $id, 'token' => $token, 'last4' => substr($token, -4), 'scopes' => $scopes], 'Token created — copy it now, it will not be shown again.', 201);
});

route('POST', '/whatsapp/tokens/{id:\d+}/revoke', function (array $p) {
    wa_adm_require('manage', 'revoke API tokens');
    $t = db_row('SELECT id, name, token_last4, revoked_at FROM wa_api_tokens WHERE id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Token not found.', 404);
    }
    if ($t['revoked_at']) {
        api_error('This token is already revoked.', 409);
    }
    db_update('wa_api_tokens', ['revoked_at' => wa_now()], 'id = ?', [(int) $t['id']]);
    log_activity('delete', 'whatsapp_settings', (int) $t['id'], 'Revoked WhatsApp API token "' . $t['name'] . '" (…' . $t['token_last4'] . ')');
    api_ok(['id' => (int) $t['id']], 'Token "' . $t['name'] . '" revoked. Requests using it are now rejected.');
});

/* ---------------------------------------------------------------------------------------------- team */
route('GET', '/whatsapp/team', function () {
    wa_adm_require('view', 'view the team');
    api_ok(wa_adm_team() + ['can_manage_users' => wa_can('users', 'create'), 'can_manage_roles' => wa_can('roles', 'view')]);
});

route('GET', '/whatsapp/team/matrix', function () {
    wa_adm_require('view', 'view team permissions');
    require_once APP_ROOT . '/app/services/system.php';
    api_ok(wa_adm_matrix());
});

route('PUT', '/whatsapp/team/roles/{id:\d+}/permissions', function (array $p) {
    wa_adm_require('manage', 'change team permissions');
    api_require('roles', 'edit');
    require_once APP_ROOT . '/app/services/system.php';
    $role = db_row('SELECT * FROM roles WHERE id = ?', [(int) $p['id']]);
    if (!$role) {
        api_error('Role not found. It may have been deleted.', 404);
    }
    if ($role['is_super']) {
        api_error('The Super Admin role is locked: it always has every permission.', 422);
    }
    $keys = api_input()['permissions'] ?? null;
    if (!is_array($keys)) {
        api_error('Send the list of WhatsApp permissions for this role.', 422, ['permissions' => 'Permissions must be a list.']);
    }
    $keys = array_values(array_unique(array_filter($keys, 'is_string')));
    [$added, $removed] = wa_adm_save_role_wa_permissions($role, $keys);
    if ($added || $removed) {
        log_activity('update', 'roles', $role['id'], sprintf('Updated WhatsApp permissions of role %s (+%d / −%d)', $role['name'], count($added), count($removed)), 'success',
            ['added' => $added, 'removed' => $removed]);
        $affected = db_column("SELECT ur.user_id FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = ? AND u.status = 'active' AND u.id <> ?", [$role['id'], user_id()]);
        if ($affected) {
            notify(array_map('intval', $affected), 'system', 'Your WhatsApp access was updated', "WhatsApp permissions of the {$role['name']} role were changed. Reload the page to see the new menu.", null, 'shield-check');
        }
    }
    $mods = wa_permission_modules();
    $now = array_values(array_filter(role_permission_keys((int) $role['id']), fn ($k) => in_array(explode('.', $k, 2)[0], $mods, true)));
    api_ok(['permissions' => $now, 'added' => count($added), 'removed' => count($removed)],
        ($added || $removed) ? sprintf('WhatsApp permissions saved for %s: %d added, %d removed.', $role['name'], count($added), count($removed)) : 'No changes to save.');
});

route('GET', '/whatsapp/team/activity', function () {
    wa_adm_require('view', 'view team activity');
    [$page, $per] = api_pagination(25);
    api_ok(wa_adm_team_activity($_GET, $page, $per));
});

/* ---------------------------------------------------------------------------------------------- settings */
route('GET', '/whatsapp/settings', function () {
    wa_adm_require('view', 'view WhatsApp settings');
    api_ok(wa_adm_settings_get() + ['can' => ['edit' => wa_can('whatsapp_settings', 'edit'), 'manage' => wa_can('whatsapp_settings', 'manage')]]);
});

route('PUT', '/whatsapp/settings/{section:[a-z]+}', function (array $p) {
    $section = $p['section'];
    $sensitive = in_array($section, ['security', 'webhook', 'retention'], true);
    wa_adm_require($sensitive ? 'manage' : 'edit', 'change WhatsApp settings');
    $titles = ['profile' => 'business profile', 'hours' => 'business hours', 'notifications' => 'notification settings', 'automation' => 'automation settings',
        'webhook' => 'webhook settings', 'retention' => 'data retention', 'security' => 'security settings'];
    if (!isset($titles[$section])) {
        api_error('Unknown settings section.', 404);
    }
    $changed = wa_adm_settings_save($section, api_input());
    if ($changed) {
        log_activity('update', 'whatsapp_settings', null, 'Updated WhatsApp ' . $titles[$section] . ' (' . implode(', ', $changed) . ')');
    }
    api_ok(wa_adm_settings_get(), $changed ? ucfirst($titles[$section]) . ' saved.' : 'No changes to save.');
});

/* ---------------------------------------------------------------------------------------------- docs */
route('GET', '/whatsapp/docs', function () {
    wa_adm_require('view', 'view the API documentation');
    api_ok(wa_adm_docs());
});
