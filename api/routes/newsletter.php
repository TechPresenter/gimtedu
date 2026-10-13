<?php
/**
 * Newsletter & campaigns — custom endpoints (CRUD: /api/crud/email_campaigns, /api/crud/newsletter_subscribers).
 *
 *   GET  /newsletter/summary                           KPIs, subscriber growth, sources, active sends (starts due scheduled campaigns)
 *   GET  /newsletter/audience-preview                  ?audience=&program_ids[]=&semester_no=&emails=&channel= -> recipient counts
 *   GET  /newsletter/campaigns/{id}                    campaign + progress + top errors + audience counts
 *   POST /newsletter/campaigns/{id}/test               {email} or {phone} send one test message
 *   POST /newsletter/campaigns/{id}/send               queue all recipients now (first batch inline)
 *   POST /newsletter/campaigns/{id}/process            send the next batch, returns progress
 *   POST /newsletter/campaigns/{id}/schedule           {scheduled_at}
 *   POST /newsletter/campaigns/{id}/cancel             cancel a schedule or stop a running send
 *   POST /newsletter/campaigns/{id}/duplicate          copy as a new draft
 *   POST /newsletter/subscribers/{id}/{unsubscribe|resubscribe}
 *   GET|POST /newsletter/cron?token=                   scheduled task (server cron, no session): starts due campaigns and
 *                                                      delivers pending notice / campaign / reminder messages
 *   GET  /newsletter/unsubscribe?token=                public confirmation page (no login)
 *   POST /newsletter/unsubscribe                       public: token -> unsubscribed (no login, token is the secret)
 */
require_once APP_ROOT . '/app/services/communication.php';

function nl_campaign_or_404(int $id): array
{
    $c = db_row('SELECT * FROM email_campaigns WHERE id = ?', [$id]);
    if (!$c) {
        api_error('Campaign not found. It may have been deleted.', 404);
    }
    return $c;
}

route('GET', '/newsletter/summary', function () {
    api_require('communication', 'view');
    $started = can('communication', 'publish') ? comm_dispatch_due_campaigns() : [];
    $sub = db_row("SELECT COUNT(*) AS total, SUM(status = 'subscribed') AS subscribed, SUM(status = 'unsubscribed') AS unsubscribed, SUM(status = 'bounced') AS bounced,
                          SUM(status = 'subscribed' AND subscribed_at >= ?) AS new_month, SUM(status = 'unsubscribed' AND unsubscribed_at >= ?) AS lost_month
                   FROM newsletter_subscribers", [date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00')]) ?? [];
    $camp = db_row("SELECT SUM(status = 'sent') AS sent, SUM(status = 'scheduled') AS scheduled, SUM(status = 'draft') AS drafts, SUM(status = 'sending') AS sending,
                           SUM(CASE WHEN status = 'sent' THEN sent_count ELSE 0 END) AS delivered, SUM(CASE WHEN status IN ('sent', 'failed') THEN failed_count ELSE 0 END) AS failed
                    FROM email_campaigns") ?? [];
    $mail30 = db_row("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM message_logs WHERE campaign_id IS NOT NULL AND created_at >= ?",
        [date('Y-m-d H:i:s', strtotime('-30 days'))]) ?? [];
    // growth: net subscribed per month for the last 6 months + running total
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("first day of -$i month"))] = ['joined' => 0, 'left' => 0];
    }
    $from = array_key_first($months) . '-01';
    foreach (db_all("SELECT DATE_FORMAT(subscribed_at, '%Y-%m') AS ym, COUNT(*) AS n FROM newsletter_subscribers WHERE subscribed_at >= ? GROUP BY ym", [$from]) as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['joined'] = (int) $r['n'];
        }
    }
    foreach (db_all("SELECT DATE_FORMAT(unsubscribed_at, '%Y-%m') AS ym, COUNT(*) AS n FROM newsletter_subscribers WHERE unsubscribed_at >= ? GROUP BY ym", [$from]) as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['left'] = (int) $r['n'];
        }
    }
    $sources = comm_subscriber_sources();
    $bySource = array_map(fn ($r) => ['key' => $r['source'] ?: 'website', 'label' => $sources[$r['source'] ?: 'website'] ?? label_from_key((string) $r['source']), 'count' => (int) $r['n']],
        db_all("SELECT source, COUNT(*) AS n FROM newsletter_subscribers WHERE status = 'subscribed' GROUP BY source ORDER BY n DESC"));
    $active = db_all("SELECT id, title, recipients_count, sent_count, failed_count, status FROM email_campaigns WHERE status = 'sending' ORDER BY started_at");
    foreach ($active as &$a) {
        $a['id'] = (int) $a['id'];
        $a['progress'] = comm_queue_progress(['campaign_id' => (int) $a['id']]);
    }
    unset($a);
    $next = db_row("SELECT id, title, scheduled_at FROM email_campaigns WHERE status = 'scheduled' ORDER BY scheduled_at LIMIT 1");
    $recent = array_map(fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'sent_at' => $r['sent_at'], 'recipients' => (int) $r['recipients_count'], 'sent' => (int) $r['sent_count'], 'failed' => (int) $r['failed_count']],
        db_all("SELECT id, title, sent_at, recipients_count, sent_count, failed_count FROM email_campaigns WHERE status IN ('sent', 'failed') AND sent_at IS NOT NULL ORDER BY sent_at DESC LIMIT 6"));
    api_ok([
        'subscribers' => array_map('intval', $sub), 'campaigns' => array_map('intval', $camp),
        'last30' => ['sent' => (int) ($mail30['sent'] ?? 0), 'failed' => (int) ($mail30['failed'] ?? 0)],
        'growth' => ['labels' => array_map(fn ($ym) => date('M Y', strtotime($ym . '-01')), array_keys($months)),
            'joined' => array_column(array_values($months), 'joined'), 'left' => array_column(array_values($months), 'left')],
        'by_source' => $bySource, 'active' => $active, 'next_scheduled' => $next, 'recent' => array_reverse($recent), 'started' => $started, 'channels' => comm_channels_state(),
        'cron' => can('communication', 'manage') ? comm_cron_info() : ['last_run' => setting('_comm_last_cron') ?: null],
    ]);
});

/* Scheduled task for a server cron job (no session; the token is derived from the application key). */
$commCron = function () {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals(comm_cron_token(), $token)) {
        api_error('Invalid token.', 403);
    }
    if (!rate_limit('comm-cron:' . client_ip(), 120, 3600)) {
        api_error('Too many requests.', 429);
    }
    $r = comm_run_scheduled(20.0);
    api_ok($r, sprintf('%d scheduled campaign(s) started, %d message(s) delivered, %d still pending.', count($r['started']), $r['processed'], $r['pending']));
};
route('GET', '/newsletter/cron', $commCron, ['auth' => false, 'csrf' => false]);
route('POST', '/newsletter/cron', $commCron, ['auth' => false, 'csrf' => false]);

route('GET', '/newsletter/audience-preview', function () {
    api_require('communication', 'view');
    $aud = (string) ($_GET['audience'] ?? 'subscribers');
    if (!isset(comm_campaign_audiences()[$aud])) {
        api_error('Unknown audience.', 422, ['audience' => 'Select a valid audience.']);
    }
    $ids = $_GET['program_ids'] ?? [];
    $ids = is_array($ids) ? $ids : explode(',', (string) $ids);
    api_ok(comm_audience_summary($aud, ['program_ids' => $ids, 'semester_no' => $_GET['semester_no'] ?? null, 'emails' => (string) ($_GET['emails'] ?? '')]));
});

route('GET', '/newsletter/campaigns/{id:\d+}', function ($p) {
    api_require('communication', 'view');
    $d = comm_campaign_detail((int) $p['id']);
    if (!$d) {
        api_error('Campaign not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

route('POST', '/newsletter/campaigns/{id:\d+}/test', function ($p) {
    api_require('communication', 'create');
    $c = nl_campaign_or_404((int) $p['id']);
    $in = api_input();
    if ($c['channel'] === 'email') {
        $v = api_validate(['email' => 'required|email|max:190'], $in, ['email' => 'Test e-mail address']);
        $to = strtolower((string) $v['email']);
    } else {
        if (!comm_channel_enabled((string) $c['channel'])) {
            api_error(strtoupper((string) $c['channel']) . ' is not enabled under Settings → Email & SMS.', 422);
        }
        $v = api_validate(['phone' => 'required|phone'], $in, ['phone' => 'Test mobile number']);
        $to = (string) $v['phone'];
    }
    $msg = comm_campaign_message($c, ['name' => current_user()['name'] ?? 'Test Recipient', 'email' => $to, 'token' => null]);
    $opts = ['related_type' => 'campaign_test', 'related_id' => (int) $c['id']];
    $ok = match ($c['channel']) {
        'email' => send_mail($to, '[Test] ' . $msg['subject'], $msg['body'], $opts),
        'sms' => send_sms($to, $msg['body'], $opts),
        default => send_whatsapp($to, $msg['body'], $opts),
    };
    if ($ok) {
        db_update('email_campaigns', ['test_sent_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $c['id']]);
    }
    log_activity('notify', 'communication', $c['id'], sprintf('Sent a test of campaign "%s" to %s — %s', str_limit((string) $c['title'], 70), $to, $ok ? 'delivered' : 'failed'), $ok ? 'success' : 'failed');
    if (!$ok) {
        api_error('The test message could not be sent. Check Settings → Email & SMS and the message log for details.', 502);
    }
    api_ok(['to' => $to], 'Test message sent to ' . $to . '.');
});

route('POST', '/newsletter/campaigns/{id:\d+}/send', function ($p) {
    api_require('communication', 'publish');
    $res = comm_campaign_start((int) $p['id']);
    $progress = comm_process_queue(['campaign_id' => (int) $p['id']], 6.0);
    api_ok($res + ['progress' => $progress, 'detail' => comm_campaign_detail((int) $p['id'])], 'Sending to ' . number_format($res['recipients']) . ' recipients…');
});

route('POST', '/newsletter/campaigns/{id:\d+}/process', function ($p) {
    api_require('communication', 'publish');
    $c = nl_campaign_or_404((int) $p['id']);
    if ($c['status'] !== 'sending') {
        api_ok(comm_queue_progress(['campaign_id' => (int) $c['id']]) + ['status' => $c['status']]);
    }
    $progress = comm_process_queue(['campaign_id' => (int) $c['id']], 8.0);
    api_ok($progress + ['status' => db_value('SELECT status FROM email_campaigns WHERE id = ?', [(int) $c['id']])]);
});

route('POST', '/newsletter/campaigns/{id:\d+}/schedule', function ($p) {
    api_require('communication', 'publish');
    $c = comm_campaign_ready((int) $p['id']);
    $v = api_validate(['scheduled_at' => 'required'], null, ['scheduled_at' => 'Schedule date & time']);
    $ts = strtotime(str_replace('T', ' ', (string) $v['scheduled_at']));
    if (!$ts) {
        api_error('Please fix the highlighted fields and try again.', 422, ['scheduled_at' => 'Enter a valid date and time.']);
    }
    if ($ts < time() + 60) {
        api_error('Please fix the highlighted fields and try again.', 422, ['scheduled_at' => 'Choose a time at least a minute in the future.']);
    }
    $count = comm_audience_summary((string) $c['audience'], comm_campaign_filter($c))[$c['channel'] === 'email' ? 'email' : ($c['channel'] === 'sms' ? 'sms' : 'whatsapp')];
    if (!$count) {
        api_error('No recipients with valid contact details were found for this audience.', 422);
    }
    db_update('email_campaigns', ['status' => 'scheduled', 'scheduled_at' => date('Y-m-d H:i:s', $ts), 'recipients_count' => $count], 'id = ?', [(int) $c['id']]);
    log_activity('publish', 'communication', $c['id'], sprintf('Scheduled campaign "%s" for %s (%d recipients)', str_limit((string) $c['title'], 80), format_datetime(date('Y-m-d H:i:s', $ts)), $count));
    api_ok(['scheduled_at' => date('Y-m-d H:i:s', $ts), 'recipients' => $count], 'Campaign scheduled for ' . format_datetime(date('Y-m-d H:i:s', $ts)) . '.');
});

route('POST', '/newsletter/campaigns/{id:\d+}/cancel', function ($p) {
    api_require('communication', 'publish');
    $c = nl_campaign_or_404((int) $p['id']);
    if (!in_array($c['status'], ['scheduled', 'sending'], true)) {
        api_error('Only scheduled or sending campaigns can be cancelled.', 422);
    }
    $cancelled = comm_queue_cancel(['campaign_id' => (int) $c['id']]);
    $synced = comm_campaign_sync((int) $c['id']);
    $newStatus = $c['status'] === 'scheduled' ? 'draft' : 'cancelled';
    db_update('email_campaigns', ['status' => $newStatus] + ($c['status'] === 'scheduled' ? ['scheduled_at' => null] : ['sent_at' => date('Y-m-d H:i:s')]), 'id = ?', [(int) $c['id']]);
    log_activity('update', 'communication', $c['id'], $c['status'] === 'scheduled'
        ? sprintf('Cancelled the schedule of campaign "%s"', str_limit((string) $c['title'], 80))
        : sprintf('Stopped campaign "%s" — %d delivered, %d not sent', str_limit((string) $c['title'], 80), (int) ($synced['sent_count'] ?? 0), $cancelled));
    api_ok(['cancelled' => $cancelled, 'status' => $newStatus], $c['status'] === 'scheduled' ? 'Schedule cancelled — the campaign is a draft again.' : "Sending stopped. $cancelled pending messages were cancelled.");
});

route('POST', '/newsletter/campaigns/{id:\d+}/duplicate', function ($p) {
    api_require('communication', 'create');
    $c = nl_campaign_or_404((int) $p['id']);
    $id = db_insert('email_campaigns', [
        'title' => mb_substr('Copy of ' . $c['title'], 0, 190), 'channel' => $c['channel'], 'subject' => $c['subject'], 'body_html' => $c['body_html'], 'template_id' => $c['template_id'],
        'audience' => $c['audience'], 'audience_filter' => $c['audience_filter'], 'status' => 'draft', 'created_by' => user_id(),
    ]);
    log_activity('create', 'communication', $id, 'Duplicated campaign "' . str_limit((string) $c['title'], 80) . '" as a new draft');
    api_ok(['id' => $id], 'A draft copy of the campaign was created.', 201);
});

route('POST', '/newsletter/subscribers/{id:\d+}/{action:unsubscribe|resubscribe}', function ($p) {
    api_require('communication', 'edit');
    $s = db_row('SELECT * FROM newsletter_subscribers WHERE id = ?', [(int) $p['id']]);
    if (!$s) {
        api_error('Subscriber not found.', 404);
    }
    $sub = $p['action'] === 'resubscribe';
    db_update('newsletter_subscribers', $sub ? ['status' => 'subscribed', 'unsubscribed_at' => null, 'subscribed_at' => date('Y-m-d H:i:s')] : ['status' => 'unsubscribed', 'unsubscribed_at' => date('Y-m-d H:i:s')],
        'id = ?', [(int) $s['id']]);
    log_activity('update', 'communication', $s['id'], ($sub ? 'Re-subscribed ' : 'Unsubscribed ') . $s['email'] . ' from the newsletter');
    api_ok(null, $sub ? $s['email'] . ' is subscribed again.' : $s['email'] . ' has been unsubscribed.');
});

/* ---------------- Public one-click unsubscribe (link in every newsletter) ---------------- */

function nl_public_page(string $title, string $html, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $name = e(institute_name());
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . e($title) . ' · ' . $name . '</title><link rel="icon" href="' . e(asset('assets/images/favicon.svg')) . '">'
        . '<link rel="stylesheet" href="' . e(asset('assets/css/style.css')) . '"></head>'
        . '<body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased"><main class="mx-auto flex min-h-screen max-w-lg items-center px-4 py-10">'
        . '<div class="w-full rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-xl"><img src="' . e(logo_url()) . '" alt="' . $name . '" class="mx-auto h-14 w-auto">'
        . $html . '<p class="mt-8 text-xs text-slate-400">&copy; ' . date('Y') . ' ' . $name . '</p></div></main></body></html>';
    exit;
}

route('GET', '/newsletter/unsubscribe', function () {
    $token = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['token'] ?? ''));
    $s = $token !== '' ? db_row('SELECT id, email, status FROM newsletter_subscribers WHERE token = ?', [$token]) : null;
    if (!$s) {
        nl_public_page('Link not valid', '<h1 class="mt-6 font-display text-xl font-bold text-brand-900">This link is not valid</h1>'
            . '<p class="mt-2 text-sm text-slate-500">The unsubscribe link is incomplete or has already been replaced. Please use the link from the most recent e-mail.</p>'
            . '<a href="' . e(base_url('')) . '" class="btn btn-primary btn-sm mt-6">Go to website</a>', 404);
    }
    if ($s['status'] !== 'subscribed') {
        nl_public_page('Unsubscribed', '<h1 class="mt-6 font-display text-xl font-bold text-brand-900">You are unsubscribed</h1>'
            . '<p class="mt-2 text-sm text-slate-500"><strong>' . e($s['email']) . '</strong> will not receive any more newsletters from us.</p>'
            . '<a href="' . e(base_url('')) . '" class="btn btn-primary btn-sm mt-6">Go to website</a>');
    }
    nl_public_page('Unsubscribe', '<h1 class="mt-6 font-display text-xl font-bold text-brand-900">Unsubscribe from our newsletter?</h1>'
        . '<p class="mt-2 text-sm text-slate-500"><strong>' . e($s['email']) . '</strong> will stop receiving news, events and admission updates.</p>'
        . '<form method="post" action="' . e(base_url('api/newsletter/unsubscribe')) . '" class="mt-6"><input type="hidden" name="token" value="' . e($token) . '">'
        . '<button type="submit" class="btn btn-primary">Yes, unsubscribe me</button></form>'
        . '<a href="' . e(base_url('')) . '" class="mt-3 inline-block text-sm text-slate-500 hover:text-brand-700">No, keep me subscribed</a>');
}, ['auth' => false]);

route('POST', '/newsletter/unsubscribe', function () {
    $token = preg_replace('/[^a-f0-9]/i', '', (string) (api_input()['token'] ?? ''));
    if (!rate_limit('unsub:' . client_ip(), 30, 3600)) {
        nl_public_page('Too many requests', '<h1 class="mt-6 font-display text-xl font-bold text-brand-900">Please try again later</h1>', 429);
    }
    $s = $token !== '' ? db_row('SELECT id, email, status FROM newsletter_subscribers WHERE token = ?', [$token]) : null;
    if (!$s) {
        nl_public_page('Link not valid', '<h1 class="mt-6 font-display text-xl font-bold text-brand-900">This link is not valid</h1>', 404);
    }
    if ($s['status'] === 'subscribed') {
        db_update('newsletter_subscribers', ['status' => 'unsubscribed', 'unsubscribed_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $s['id']]);
        log_activity('update', 'communication', $s['id'], $s['email'] . ' unsubscribed via the newsletter link');
    }
    nl_public_page('Unsubscribed', '<h1 class="mt-6 font-display text-xl font-bold text-brand-900">You have been unsubscribed</h1>'
        . '<p class="mt-2 text-sm text-slate-500"><strong>' . e($s['email']) . '</strong> will not receive any more newsletters. Changed your mind? Subscribe again from the website footer.</p>'
        . '<a href="' . e(base_url('')) . '" class="btn btn-primary btn-sm mt-6">Go to website</a>');
}, ['auth' => false, 'csrf' => false]);
