<?php
/**
 * System settings.
 *   GET  /api/settings                          schema + values for every group (secrets masked), sessions, mail status
 *   POST /api/settings/{group}                  save a subset of one group (multipart for logo/favicon uploads)
 *   POST /api/settings/test-email               {to}
 *   POST /api/settings/test-sms                 {channel: sms|whatsapp, phone}
 *   POST /api/settings/academic-session         {id}   make a session current
 */
require_once APP_ROOT . '/app/services/system.php';
require_once APP_ROOT . '/app/services/backup.php';

function settings_meta_payload(): array
{
    $sessions = db_all('SELECT id, name, start_date, end_date, is_current, admissions_open, status FROM academic_sessions ORDER BY start_date DESC');
    foreach ($sessions as &$s) {
        $s['id'] = (int) $s['id'];
        $s['is_current'] = (bool) $s['is_current'];
        $s['admissions_open'] = (bool) $s['admissions_open'];
    }
    unset($s);
    $driver = setting('mail_driver', 'smtp');
    $effective = is_dev() ? 'log' : ($driver === 'smtp' && !setting('smtp_host') ? 'mail' : $driver);
    $last = db_row("SELECT created_at, status, size_bytes FROM backups WHERE type = 'database' ORDER BY created_at DESC LIMIT 1");
    $mailStats = db_row("SELECT COALESCE(SUM(status = 'sent'), 0) sent, COALESCE(SUM(status = 'failed'), 0) failed FROM message_logs WHERE channel = 'email' AND created_at >= NOW() - INTERVAL 30 DAY");
    return [
        'sessions' => $sessions,
        'current_session_id' => ($cur = db_value('SELECT id FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1')) ? (int) $cur : null,
        'mail' => ['driver' => $driver, 'effective_driver' => $effective, 'dev' => is_dev(), 'sent_30d' => (int) $mailStats['sent'], 'failed_30d' => (int) $mailStats['failed']],
        'backup' => ['last' => $last, 'next_due' => backup_next_due()],
        'can_edit' => can('settings', 'edit'),
        'defaults' => ['logo' => asset('assets/images/logo.svg'), 'logo_white' => asset('assets/images/logo-white.svg'), 'favicon' => asset('assets/images/favicon.svg')],
    ];
}

route('GET', '/settings', function () {
    api_require('settings', 'view');
    api_ok(['groups' => settings_payload()] + settings_meta_payload());
});

route('POST', '/settings/test-email', function () {
    api_require('settings', 'edit');
    $in = api_validate(['to' => 'required|email|max:190'], null, ['to' => 'Recipient']);
    if (!rate_limit('settings-test-mail:' . user_id(), 10, 600)) {
        api_error('Too many test emails. Please wait a few minutes.', 429);
    }
    $driver = is_dev() ? 'log' : (setting('mail_driver', 'smtp') === 'smtp' && !setting('smtp_host') ? 'mail' : setting('mail_driver', 'smtp'));
    $html = '<p>Hello,</p><p>This is a test email from <strong>' . e(institute_name()) . '</strong> SmartCampus admin, sent by ' . e(current_user()['name'] ?? 'an administrator') . ' on ' . e(format_datetime(date('Y-m-d H:i:s'))) . '.</p>'
        . '<p>If you are reading this, outgoing email is configured correctly.</p><p style="color:#64748b;font-size:13px">Driver: ' . e($driver) . (setting('smtp_host') ? ' · Host: ' . e(setting('smtp_host')) . ':' . e(setting('smtp_port')) : '') . '</p>';
    $ok = send_mail($in['to'], 'Test email from ' . setting('institute_short_name', 'GIMT') . ' SmartCampus', $html, ['related_type' => 'settings_test']);
    $error = $ok ? null : db_value("SELECT error FROM message_logs WHERE channel = 'email' AND recipient = ? ORDER BY id DESC LIMIT 1", [strtolower($in['to'])]);
    log_activity('test_email', 'settings', null, 'Sent a test email to ' . $in['to'] . ' via ' . $driver . ($ok ? '' : ' — failed: ' . mb_substr((string) $error, 0, 200)), $ok ? 'success' : 'failed');
    if (!$ok) {
        api_error('The test email could not be sent' . ($error ? ': ' . $error : '. Check the SMTP host, port, encryption and credentials.'), 422);
    }
    $message = match ($driver) {
        'log' => 'Development mode: the test email to ' . $in['to'] . ' was written to storage/logs/mail.log instead of being delivered.',
        'mail' => 'Test email handed to the server mail() function for ' . $in['to'] . '. Check the inbox (and spam folder).',
        default => 'Test email sent to ' . $in['to'] . ' through ' . setting('smtp_host') . ':' . setting('smtp_port') . '.',
    };
    api_ok(['driver' => $driver, 'to' => $in['to'], 'sent' => true], $message);
});

route('POST', '/settings/test-sms', function () {
    api_require('settings', 'edit');
    $in = api_validate(['channel' => 'required|in:sms,whatsapp', 'phone' => 'required|phone'], null, ['phone' => 'Mobile number']);
    $label = $in['channel'] === 'sms' ? 'SMS' : 'WhatsApp';
    if (setting($in['channel'] . '_enabled', '0') !== '1') {
        api_error("Enable the $label gateway and save it before sending a test.", 422);
    }
    if (!rate_limit('settings-test-' . $in['channel'] . ':' . user_id(), 10, 600)) {
        api_error('Too many test messages. Please wait a few minutes.', 429);
    }
    $ok = gateway_send($in['channel'], $in['phone'], 'Test message from ' . setting('institute_short_name', 'GIMT') . ' SmartCampus. Your ' . $label . ' gateway is working.', ['related_type' => 'settings_test']);
    $log = db_row('SELECT status, error FROM message_logs WHERE channel = ? ORDER BY id DESC LIMIT 1', [$in['channel']]);
    log_activity('test_' . $in['channel'], 'settings', null, "Sent a test $label to {$in['phone']}: " . ($log['status'] ?? ($ok ? 'sent' : 'failed')), $ok ? 'success' : 'failed');
    if ($ok) {
        api_ok(['status' => 'sent'], "Test $label sent to {$in['phone']}.");
    }
    if (($log['status'] ?? '') === 'queued') {
        api_ok(['status' => 'queued'], is_dev() ? "Development mode: the $label was recorded in message logs but not sent to the gateway." : "The $label was queued: " . ($log['error'] ?? 'gateway not configured') . '.');
    }
    api_error("The $label gateway rejected the message: " . ($log['error'] ?? 'unknown error'), 422);
});

route('POST', '/settings/academic-session', function () {
    api_require('settings', 'edit');
    $in = api_validate(['id' => 'required|integer|exists:academic_sessions,id'], null, ['id' => 'Academic session']);
    $name = db_value('SELECT name FROM academic_sessions WHERE id = ?', [(int) $in['id']]);
    db_transaction(function () use ($in) {
        db_exec('UPDATE academic_sessions SET is_current = CASE WHEN id = ? THEN 1 ELSE 0 END', [(int) $in['id']]);
    });
    $_SESSION['academic_session_id'] = (int) $in['id'];
    log_activity('update', 'settings', $in['id'], "Set the current academic session to $name");
    api_ok(settings_meta_payload(), "$name is now the current academic session.");
});

route('POST', '/settings/{group:[a-z]+}', function ($p) {
    api_require('settings', 'edit');
    $schema = settings_schema();
    if (!isset($schema[$p['group']])) {
        api_error('Unknown settings section.', 404);
    }
    $keys = settings_save_group($p['group'], api_input(), $_FILES);
    if ($keys) {
        $fields = settings_fields($p['group']);
        $names = array_map(fn ($k) => $fields[$k]['label'] ?? label_from_key($k), $keys);
        log_activity('update', 'settings', null, 'Updated ' . $schema[$p['group']]['title'] . ' settings: ' . implode(', ', array_slice($names, 0, 8)) . (count($names) > 8 ? ' and ' . (count($names) - 8) . ' more' : ''),
            'success', ['group' => $p['group'], 'keys' => $keys]);
    }
    $group = array_values(array_filter(settings_payload(), fn ($g) => $g['key'] === $p['group']))[0] ?? null;
    api_ok(['group' => $group, 'saved' => $keys], $keys ? $schema[$p['group']]['title'] . ' settings saved.' : 'Nothing to save — no changes were made.');
});
