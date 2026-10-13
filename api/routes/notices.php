<?php
/**
 * Notices & circulars — custom endpoints (CRUD itself: /api/crud/notices).
 *
 *   GET  /notices/summary                       KPIs, category + audience breakdown, channels enabled
 *   GET  /notices/audience-preview              ?audience=&department_id=&program_id=&semester_no= -> recipient counts per channel
 *   GET  /notices/{id}                          notice + audience label + delivery stats + audience counts
 *   POST /notices/{id}/publish                  {email, sms, whatsapp, notify} publish and queue delivery (first batch sent inline)
 *   POST /notices/{id}/unpublish                back to draft
 *   POST /notices/{id}/pin                      {pinned: bool}
 *   POST /notices/{id}/duplicate                copy as a new draft
 *   POST /notices/{id}/resume                   continue sending pending deliveries
 *   GET  /notices/{id}/print                    printable circular (HTML, A4, letterhead)
 */
require_once APP_ROOT . '/app/services/communication.php';

route('GET', '/notices/summary', function () {
    api_require('notices', 'view');
    comm_expire_notices();
    $state = comm_notice_state_sql('n');
    $counts = db_pairs("SELECT $state AS st, COUNT(*) FROM notices n GROUP BY st");
    $now = date('Y-m-d H:i:s');
    $monthStart = date('Y-m-01 00:00:00');
    $row = db_row("SELECT COUNT(*) AS total, SUM(is_pinned = 1 AND status = 'published') AS pinned,
                          SUM(status = 'published' AND expiry_date IS NOT NULL AND expiry_date BETWEEN ? AND ?) AS expiring,
                          SUM(COALESCE(publish_date, created_at) >= ?) AS this_month, SUM(views) AS views
                   FROM notices", [$now, date('Y-m-d H:i:s', strtotime('+7 days')), $monthStart]) ?? [];
    $cats = comm_notice_categories();
    $byCat = [];
    foreach (db_all("SELECT category, COUNT(*) AS total, SUM($state = 'live') AS live FROM notices n GROUP BY category ORDER BY total DESC") as $r) {
        $byCat[] = ['key' => $r['category'], 'label' => $cats[$r['category']] ?? label_from_key((string) $r['category']), 'total' => (int) $r['total'], 'live' => (int) $r['live']];
    }
    $auds = comm_notice_audiences();
    $byAud = array_map(fn ($r) => ['key' => $r['audience'], 'label' => $auds[$r['audience']] ?? label_from_key((string) $r['audience']), 'total' => (int) $r['n']],
        db_all('SELECT audience, COUNT(*) AS n FROM notices GROUP BY audience ORDER BY n DESC'));
    $delivery = db_row("SELECT SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed FROM message_logs WHERE related_type = 'notice' AND created_at >= ?",
        [date('Y-m-d H:i:s', strtotime('-30 days'))]) ?? [];
    $pending = (int) db_value("SELECT COUNT(*) FROM message_queue WHERE related_type = 'notice' AND status IN ('pending', 'processing')");
    $pendingNotices = array_map(fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'pending' => (int) $r['pending']],
        db_all("SELECT n.id, n.title, COUNT(*) AS pending FROM message_queue q JOIN notices n ON n.id = q.related_id
                WHERE q.related_type = 'notice' AND q.status IN ('pending', 'processing') GROUP BY n.id, n.title ORDER BY n.id DESC LIMIT 3"));
    api_ok([
        'total' => (int) ($row['total'] ?? 0), 'live' => (int) ($counts['live'] ?? 0), 'scheduled' => (int) ($counts['scheduled'] ?? 0), 'draft' => (int) ($counts['draft'] ?? 0),
        'expired' => (int) ($counts['expired'] ?? 0), 'pinned' => (int) ($row['pinned'] ?? 0), 'expiring' => (int) ($row['expiring'] ?? 0),
        'this_month' => (int) ($row['this_month'] ?? 0), 'views' => (int) ($row['views'] ?? 0),
        'delivered_30d' => (int) ($delivery['sent'] ?? 0), 'failed_30d' => (int) ($delivery['failed'] ?? 0), 'pending' => $pending, 'pending_notices' => $pendingNotices,
        'by_category' => $byCat, 'by_audience' => $byAud, 'channels' => comm_channels_state(),
    ]);
});

route('GET', '/notices/audience-preview', function () {
    api_require('notices', 'view');
    $aud = (string) ($_GET['audience'] ?? 'all');
    if (!isset(comm_notice_audiences()[$aud])) {
        api_error('Unknown audience.', 422, ['audience' => 'Select a valid audience.']);
    }
    api_ok(comm_audience_summary($aud, ['department_id' => $_GET['department_id'] ?? null, 'program_id' => $_GET['program_id'] ?? null, 'semester_no' => $_GET['semester_no'] ?? null]));
});

route('GET', '/notices/{id:\d+}', function ($p) {
    api_require('notices', 'view');
    $d = comm_notice_detail((int) $p['id']);
    if (!$d) {
        api_error('Notice not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

route('POST', '/notices/{id:\d+}/publish', function ($p) {
    api_require('notices', 'publish');
    $in = api_input();
    $truthy = fn ($v) => in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
    $channels = ['email' => $truthy($in['email'] ?? false), 'sms' => $truthy($in['sms'] ?? false), 'whatsapp' => $truthy($in['whatsapp'] ?? false)];
    $res = comm_notice_publish((int) $p['id'], $channels, $truthy($in['notify'] ?? true));
    $total = array_sum($res['queued']);
    $msg = $res['scheduled'] ? 'Notice scheduled.' : 'Notice published.';
    if ($channels['email'] || $channels['sms'] || $channels['whatsapp']) {
        $msg .= $total ? ' Sending to ' . number_format($total) . ' recipient' . ($total === 1 ? '' : 's') . '…' : ' No recipients with contact details were found for this audience.';
    }
    if ($res['notified']) {
        $msg .= ' ' . $res['notified'] . ' in-app alert' . ($res['notified'] === 1 ? '' : 's') . ' raised.';
    }
    api_ok($res + ['detail' => comm_notice_detail((int) $p['id'])], $msg);
});

route('POST', '/notices/{id:\d+}/resume', function ($p) {
    api_require('notices', 'publish');
    api_ok(comm_process_queue(['related_type' => 'notice', 'related_id' => (int) $p['id']], 8.0));
});

route('POST', '/notices/{id:\d+}/unpublish', function ($p) {
    api_require('notices', 'publish');
    $n = db_row('SELECT id, title, status FROM notices WHERE id = ?', [(int) $p['id']]);
    if (!$n) {
        api_error('Notice not found.', 404);
    }
    db_update('notices', ['status' => 'draft'], 'id = ?', [(int) $n['id']]);
    $cancelled = comm_queue_cancel(['related_type' => 'notice', 'related_id' => (int) $n['id']]);
    log_activity('update', 'notices', $n['id'], 'Unpublished notice "' . str_limit((string) $n['title'], 90) . '"' . ($cancelled ? " ($cancelled pending messages cancelled)" : ''));
    api_ok(['cancelled' => $cancelled], 'Notice moved back to draft.' . ($cancelled ? " $cancelled pending messages were cancelled." : ''));
});

route('POST', '/notices/{id:\d+}/pin', function ($p) {
    api_require('notices', 'edit');
    $n = db_row('SELECT id, title FROM notices WHERE id = ?', [(int) $p['id']]);
    if (!$n) {
        api_error('Notice not found.', 404);
    }
    $pinned = in_array(api_input()['pinned'] ?? true, [true, 1, '1', 'true'], true);
    db_update('notices', ['is_pinned' => $pinned ? 1 : 0], 'id = ?', [(int) $n['id']]);
    log_activity('update', 'notices', $n['id'], ($pinned ? 'Pinned' : 'Unpinned') . ' notice "' . str_limit((string) $n['title'], 90) . '"');
    api_ok(['pinned' => $pinned], $pinned ? 'Notice pinned to the top.' : 'Notice unpinned.');
});

route('POST', '/notices/{id:\d+}/duplicate', function ($p) {
    api_require('notices', 'create');
    $n = db_row('SELECT * FROM notices WHERE id = ?', [(int) $p['id']]);
    if (!$n) {
        api_error('Notice not found.', 404);
    }
    $title = mb_substr('Copy of ' . $n['title'], 0, 255);
    $id = db_insert('notices', [
        'title' => $title, 'slug' => comm_unique_slug('notices', $title), 'category' => $n['category'], 'audience' => $n['audience'], 'department_id' => $n['department_id'],
        'program_id' => $n['program_id'], 'semester_no' => $n['semester_no'], 'description' => $n['description'], 'attachment' => null, 'publish_date' => null, 'expiry_date' => null,
        'is_pinned' => 0, 'show_on_website' => $n['show_on_website'], 'status' => 'draft', 'created_by' => user_id(),
    ]);
    log_activity('create', 'notices', $id, 'Duplicated notice "' . str_limit((string) $n['title'], 80) . '" as a new draft');
    api_ok(['id' => $id], 'A draft copy of the notice was created.', 201);
});

route('GET', '/notices/{id:\d+}/print', function ($p) {
    comm_print_notice((int) $p['id']);
});
