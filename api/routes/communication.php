<?php
/**
 * Communication — templates, message logs and the delivery queue.
 *
 *   GET  /communication/templates/placeholders         placeholder catalogue with sample values + system codes
 *   GET  /communication/templates/summary              counts by channel / status
 *   POST /communication/templates/preview              {channel, subject, body_html, vars?} -> rendered preview (sample data)
 *   POST /communication/templates/{id}/test            {email | phone} send the stored template with sample data
 *   POST /communication/templates/{id}/duplicate       copy with a new code
 *   GET  /communication/message-logs/summary           ?days=14 KPIs, per-channel, daily chart, top failure reasons
 *   GET  /communication/message-logs/{id}              full message incl. body + retries (bodies carrying passwords /
 *                                                      reset links are hidden unless the user can manage users)
 *   POST /communication/message-logs/{id}/resend       re-send a failed / queued message
 *   POST /communication/message-logs/resend            {ids[]} bulk re-send (max 100)
 *   GET  /communication/queue/progress                 ?batch= | ?campaign_id= | ?related_type=&related_id=
 *   POST /communication/queue/process                  same scope in the body — send the next batch
 */
require_once APP_ROOT . '/app/services/communication.php';

route('GET', '/communication/templates/placeholders', function () {
    api_require('communication', 'view');
    api_ok(['placeholders' => comm_placeholder_catalog(), 'system_codes' => comm_system_template_codes(), 'channels' => comm_channels_state()]);
});

route('GET', '/communication/templates/summary', function () {
    api_require('communication', 'view');
    $system = comm_system_template_codes();
    $ph = implode(',', array_fill(0, count($system), '?'));
    $r = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(channel = 'email') AS email, SUM(channel = 'sms') AS sms, SUM(channel = 'whatsapp') AS whatsapp,
                        SUM(code IN ($ph)) AS system FROM email_templates", $system) ?? [];
    $used = (int) db_value("SELECT COUNT(DISTINCT template_id) FROM email_campaigns WHERE template_id IS NOT NULL");
    api_ok(array_map('intval', $r) + ['used_in_campaigns' => $used]);
});

route('POST', '/communication/templates/preview', function () {
    api_require('communication', 'view');
    $in = api_input();
    $channel = in_array($in['channel'] ?? 'email', ['email', 'sms', 'whatsapp'], true) ? $in['channel'] : 'email';
    $vars = is_array($in['vars'] ?? null) ? array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $in['vars']) : [];
    api_ok(comm_template_preview($channel, (string) ($in['subject'] ?? ''), (string) ($in['body_html'] ?? ''), $vars));
});

route('POST', '/communication/templates/{id:\d+}/test', function ($p) {
    api_require('communication', 'edit');
    $t = db_row('SELECT * FROM email_templates WHERE id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Template not found.', 404);
    }
    $in = api_input();
    $preview = comm_template_preview((string) $t['channel'], (string) $t['subject'], (string) $t['body_html'], ['name' => current_user()['name'] ?? 'Test Recipient']);
    $opts = ['related_type' => 'template_test', 'related_id' => (int) $t['id']];
    if ($t['channel'] === 'email') {
        $v = api_validate(['email' => 'required|email|max:190'], $in, ['email' => 'Test e-mail address']);
        $to = strtolower((string) $v['email']);
        $ok = send_mail($to, '[Test] ' . $preview['subject'], $preview['inner'], $opts);
    } else {
        if (!comm_channel_enabled((string) $t['channel'])) {
            api_error(($t['channel'] === 'sms' ? 'SMS' : 'WhatsApp') . ' is not enabled under Settings → Email & SMS.', 422);
        }
        $v = api_validate(['phone' => 'required|phone'], $in, ['phone' => 'Test mobile number']);
        $to = (string) $v['phone'];
        $ok = $t['channel'] === 'sms' ? send_sms($to, $preview['text'], $opts) : send_whatsapp($to, $preview['text'], $opts);
    }
    log_activity('notify', 'communication', $t['id'], sprintf('Sent a test of template "%s" to %s — %s', $t['code'], $to, $ok ? 'delivered' : 'failed'), $ok ? 'success' : 'failed');
    if (!$ok) {
        api_error('The test message could not be sent. Check Settings → Email & SMS and the message log for details.', 502);
    }
    api_ok(['to' => $to], 'Test message sent to ' . $to . ' using sample data.');
});

route('POST', '/communication/templates/{id:\d+}/duplicate', function ($p) {
    api_require('communication', 'create');
    $t = db_row('SELECT * FROM email_templates WHERE id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Template not found.', 404);
    }
    $base = mb_substr(preg_replace('/_copy(_\d+)?$/', '', (string) $t['code']) . '_copy', 0, 52);
    $code = $base;
    $i = 2;
    while (db_value('SELECT COUNT(*) FROM email_templates WHERE code = ?', [$code])) {
        $code = $base . '_' . $i++;
    }
    $id = db_insert('email_templates', ['code' => $code, 'name' => mb_substr($t['name'] . ' (copy)', 0, 150), 'subject' => $t['subject'], 'body_html' => $t['body_html'],
        'variables' => $t['variables'], 'channel' => $t['channel'], 'status' => 'inactive']);
    log_activity('create', 'communication', $id, sprintf('Duplicated template "%s" as %s', $t['code'], $code));
    api_ok(['id' => $id, 'code' => $code], 'Template duplicated as "' . $code . '" (inactive until you enable it).', 201);
});

route('GET', '/communication/message-logs/summary', function () {
    api_require('communication', 'view');
    $days = max(7, min(90, (int) ($_GET['days'] ?? 14)));
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $tot = db_row("SELECT COUNT(*) AS total, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'queued') AS queued,
                          SUM(status = 'failed' AND NOT EXISTS (SELECT 1 FROM message_logs r WHERE r.retry_of = message_logs.id AND r.status = 'sent')) AS failed_open
                   FROM message_logs WHERE created_at >= ?", [$from . ' 00:00:00']) ?? [];
    $all = db_row("SELECT COUNT(*) AS total, SUM(status = 'failed') AS failed FROM message_logs") ?? [];
    $byChannel = [];
    foreach (db_all("SELECT channel, COUNT(*) AS total, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'queued') AS queued
                     FROM message_logs WHERE created_at >= ? GROUP BY channel", [$from . ' 00:00:00']) as $r) {
        $byChannel[$r['channel']] = array_map('intval', array_diff_key($r, ['channel' => 1]));
    }
    $daily = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $daily[date('Y-m-d', strtotime("-$i days"))] = ['sent' => 0, 'failed' => 0, 'queued' => 0];
    }
    foreach (db_all("SELECT DATE(created_at) AS d, status, COUNT(*) AS n FROM message_logs WHERE created_at >= ? GROUP BY DATE(created_at), status", [$from . ' 00:00:00']) as $r) {
        if (isset($daily[$r['d']]) && isset($daily[$r['d']][$r['status']])) {
            $daily[$r['d']][$r['status']] = (int) $r['n'];
        }
    }
    $types = comm_related_types();
    $byType = array_map(fn ($r) => ['key' => $r['related_type'], 'label' => $r['related_type'] ? ($types[$r['related_type']] ?? label_from_key((string) $r['related_type'])) : 'System', 'count' => (int) $r['n']],
        db_all('SELECT related_type, COUNT(*) AS n FROM message_logs WHERE created_at >= ? GROUP BY related_type ORDER BY n DESC LIMIT 8', [$from . ' 00:00:00']));
    $errors = array_map(fn ($r) => ['error' => $r['error'], 'count' => (int) $r['n']],
        db_all("SELECT COALESCE(NULLIF(error, ''), 'Unknown error') AS error, COUNT(*) AS n FROM message_logs WHERE status = 'failed' AND created_at >= ? GROUP BY error ORDER BY n DESC LIMIT 5", [$from . ' 00:00:00']));
    $sent = (int) ($tot['sent'] ?? 0);
    $failed = (int) ($tot['failed'] ?? 0);
    api_ok([
        'days' => $days, 'total' => (int) ($tot['total'] ?? 0), 'sent' => $sent, 'failed' => $failed, 'queued' => (int) ($tot['queued'] ?? 0), 'failed_open' => (int) ($tot['failed_open'] ?? 0),
        'delivery_rate' => ($sent + $failed) ? round($sent * 100 / ($sent + $failed), 1) : null, 'all_time' => (int) ($all['total'] ?? 0),
        'by_channel' => (object) $byChannel, 'by_type' => $byType, 'errors' => $errors,
        'daily' => ['labels' => array_map(fn ($d) => date('d M', strtotime($d)), array_keys($daily)), 'sent' => array_column(array_values($daily), 'sent'),
            'failed' => array_column(array_values($daily), 'failed'), 'queued' => array_column(array_values($daily), 'queued')],
        'channels' => comm_channels_state(),
    ]);
});

route('GET', '/communication/message-logs/{id:\d+}', function ($p) {
    api_require('communication', 'view');
    $log = db_row('SELECT l.*, c.title AS campaign_title FROM message_logs l LEFT JOIN email_campaigns c ON c.id = l.campaign_id WHERE l.id = ?', [(int) $p['id']]);
    if (!$log) {
        api_error('Message not found.', 404);
    }
    $types = comm_related_types();
    $log['id'] = (int) $log['id'];
    $log['related_label'] = $log['related_type'] ? ($types[$log['related_type']] ?? label_from_key((string) $log['related_type'])) : 'System';
    $log['related_title'] = match ($log['related_type']) {
        'notice' => db_value('SELECT title FROM notices WHERE id = ?', [(int) $log['related_id']]),
        'event' => db_value('SELECT title FROM events WHERE id = ?', [(int) $log['related_id']]),
        'event_registration' => db_value('SELECT e.title FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ?', [(int) $log['related_id']]),
        'campaign', 'campaign_test' => db_value('SELECT title FROM email_campaigns WHERE id = ?', [(int) $log['related_id']]),
        'template_test' => db_value('SELECT name FROM email_templates WHERE id = ?', [(int) $log['related_id']]),
        default => null,
    } ?: null;
    // Password-reset links / temporary passwords written by the account modules are secrets: only user managers see them.
    $log['redacted'] = comm_log_is_sensitive($log) && !comm_can_view_sensitive();
    if ($log['redacted']) {
        $log['body'] = null;
    }
    $log['html'] = $log['channel'] === 'email' && !$log['redacted'] ? email_layout((string) $log['body'], e((string) $log['subject'])) : null;
    $retries = db_all('SELECT id, status, error, created_at FROM message_logs WHERE retry_of = ? ORDER BY id', [(int) $log['id']]);
    $original = $log['retry_of'] ? db_row('SELECT id, status, error, created_at FROM message_logs WHERE id = ?', [(int) $log['retry_of']]) : null;
    $links = ['notice' => '/notices?view=', 'event' => '/events?view=', 'event_registration' => null, 'campaign' => '/newsletter?view=', 'campaign_test' => '/newsletter?view='];
    $log['related_url'] = isset($links[$log['related_type']]) && $links[$log['related_type']] && $log['related_id'] ? $links[$log['related_type']] . $log['related_id'] : null;
    api_ok(['log' => $log, 'retries' => $retries, 'original' => $original, 'can_resend' => $log['status'] !== 'sent' && comm_channel_enabled((string) $log['channel'])]);
});

route('POST', '/communication/message-logs/{id:\d+}/resend', function ($p) {
    api_require('communication', 'edit');
    $res = comm_resend_log((int) $p['id']);
    if (!$res['ok']) {
        api_error('The message could not be delivered again' . ($res['error'] ? ': ' . $res['error'] : '.'), 502);
    }
    api_ok($res, 'Message re-sent successfully.');
});

route('POST', '/communication/message-logs/resend', function () {
    api_require('communication', 'edit');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) (api_input()['ids'] ?? [])))));
    if (!$ids) {
        api_error('Select at least one message.', 422);
    }
    if (count($ids) > 100) {
        api_error('Re-send at most 100 messages at a time.', 422);
    }
    $ok = $failed = $skipped = 0;
    @set_time_limit(120);
    foreach ($ids as $id) {
        try {
            $r = comm_resend_log($id);
            $r['ok'] ? $ok++ : $failed++;
        } catch (CrudException $e) {
            $skipped++;
        }
    }
    $msg = "$ok message" . ($ok === 1 ? '' : 's') . ' re-sent';
    if ($failed) {
        $msg .= ", $failed failed again";
    }
    if ($skipped) {
        $msg .= ", $skipped skipped (already delivered, already re-sent or channel disabled)";
    }
    api_ok(['sent' => $ok, 'failed' => $failed, 'skipped' => $skipped], $msg . '.');
});

function comm_route_scope(array $src): array
{
    if (!empty($src['batch'])) {
        return ['batch' => preg_replace('/[^a-f0-9]/i', '', (string) $src['batch'])];
    }
    if (!empty($src['campaign_id'])) {
        return ['campaign_id' => (int) $src['campaign_id']];
    }
    if (!empty($src['related_type']) && !empty($src['related_id']) && in_array($src['related_type'], ['notice', 'event', 'campaign'], true)) {
        return ['related_type' => (string) $src['related_type'], 'related_id' => (int) $src['related_id']];
    }
    api_error('A delivery batch is required.', 422);
    return [];
}

route('GET', '/communication/queue/progress', function () {
    $scope = comm_route_scope($_GET);
    [$m, $a] = comm_queue_permission($scope);
    api_require($m, 'view');
    api_ok(comm_queue_progress($scope));
});

route('POST', '/communication/queue/process', function () {
    $scope = comm_route_scope(api_input());
    [$m, $a] = comm_queue_permission($scope);
    api_require($m, $a);
    api_ok(comm_process_queue($scope, 8.0));
});
