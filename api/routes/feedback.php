<?php
/**
 * Feedback & complaints API (admissions unit). CRUD (list/create/edit/delete/export) via /api/crud/feedback.
 *
 *   GET  /feedback/summary                      KPIs (open, in progress, resolved, closed, SLA breached, avg resolution, rating), by category/type
 *   GET  /feedback/{id}/details                 ticket + timeline + allowed workflow transitions
 *   POST /feedback/{id}/status   {status, resolution, remarks}   workflow open -> in_progress -> resolved -> closed (reopen allowed)
 *   POST /feedback/{id}/assign   {assigned_to}
 *   POST /feedback/{id}/note     {note}         internal note on the ticket timeline
 */
require_once APP_ROOT . '/app/services/admissions.php';

function fb_api_find(int $id): array
{
    $f = db_row('SELECT * FROM feedback WHERE id = ?', [$id]);
    if (!$f) {
        api_error('Ticket not found. It may have been deleted.', 404);
    }
    return $f;
}

route('GET', '/feedback/summary', function () {
    api_require('feedback', 'view');
    $k = db_row("SELECT COUNT(*) AS total, SUM(status = 'open') AS open_count, SUM(status = 'in_progress') AS in_progress, SUM(status = 'resolved') AS resolved,
                        SUM(status = 'closed') AS closed, SUM(" . feedback_breach_sql('feedback') . ") AS breached,
                        SUM(status IN ('open','in_progress') AND priority IN ('urgent','high')) AS high_open,
                        SUM(type IN ('complaint','grievance')) AS complaints, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS last_30,
                        AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) AS avg_resolution_hours,
                        AVG(rating) AS avg_rating, SUM(rating IS NOT NULL) AS rated,
                        SUM(resolved_at IS NOT NULL AND TIMESTAMPDIFF(HOUR, created_at, resolved_at) <= CASE priority WHEN 'urgent' THEN 24 WHEN 'high' THEN 48 WHEN 'low' THEN 168 ELSE 96 END) AS resolved_in_sla,
                        SUM(resolved_at IS NOT NULL) AS resolved_total
                 FROM feedback");
    $out = [];
    foreach ($k as $key => $v) {
        $out[$key] = $v === null ? null : (in_array($key, ['avg_resolution_hours', 'avg_rating'], true) ? round((float) $v, 1) : (int) $v);
    }
    $out['sla_compliance'] = $out['resolved_total'] ? round($out['resolved_in_sla'] / $out['resolved_total'] * 100, 1) : null;
    $cats = db_all("SELECT category, COUNT(*) AS total, SUM(status IN ('open','in_progress')) AS open_count FROM feedback GROUP BY category ORDER BY total DESC");
    foreach ($cats as &$c) {
        $c = ['category' => $c['category'], 'label' => feedback_categories()[$c['category']] ?? label_from_key((string) $c['category']), 'total' => (int) $c['total'], 'open' => (int) $c['open_count']];
    }
    unset($c);
    $types = array_map('intval', db_pairs('SELECT type, COUNT(*) FROM feedback GROUP BY type'));
    api_ok(['kpis' => $out, 'categories' => $cats, 'types' => $types]);
});

route('GET', '/feedback/{id:\d+}/details', function ($p) {
    api_require('feedback', 'view');
    $m = crud_module('feedback');
    $f = crud_find($m, (int) $p['id']);
    if (!$f) {
        api_error('Ticket not found. It may have been deleted.', 404);
    }
    $logs = db_all("SELECT l.action, l.description, l.meta, l.created_at, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
                    WHERE l.module = 'feedback' AND (l.record_id = ? OR l.record_id LIKE ? OR l.record_id LIKE ? OR l.record_id LIKE ?) ORDER BY l.created_at DESC, l.id DESC LIMIT 50",
        [(string) $f['id'], $f['id'] . ',%', '%,' . $f['id'], '%,' . $f['id'] . ',%']);
    $timeline = [];
    $seen = [];
    foreach ($logs as $l) {
        if ($l['action'] === 'create') {
            continue; // shown as the "submitted" event below
        }
        $meta = $l['meta'] ? json_decode($l['meta'], true) : [];
        $type = $l['action'] === 'note' ? 'note' : ($meta['to'] ?? $l['action']);
        $seen[$type] = true;
        $timeline[] = ['type' => $type, 'title' => $l['description'], 'user' => $l['user_name'], 'at' => $l['created_at'], 'note' => $meta['note'] ?? null];
    }
    // Workflow timestamps recorded on the ticket (covers tickets imported or created before logging existed)
    $names = db_pairs('SELECT id, name FROM users WHERE id IN (?, ?)', [(int) $f['assigned_to'], (int) $f['resolved_by']]);
    if ($f['started_at'] && empty($seen['in_progress'])) {
        $timeline[] = ['type' => 'in_progress', 'title' => 'Work started' . ($f['assigned_to'] ? ' — assigned to ' . ($names[$f['assigned_to']] ?? 'staff') : ''), 'user' => $names[$f['assigned_to']] ?? null, 'at' => $f['started_at'], 'note' => null];
    }
    if ($f['resolved_at'] && empty($seen['resolved'])) {
        $timeline[] = ['type' => 'resolved', 'title' => 'Ticket resolved', 'user' => $names[$f['resolved_by']] ?? null, 'at' => $f['resolved_at'], 'note' => $f['resolution']];
    }
    if ($f['closed_at'] && empty($seen['closed'])) {
        $timeline[] = ['type' => 'closed', 'title' => 'Ticket closed', 'user' => null, 'at' => $f['closed_at'], 'note' => null];
    }
    $timeline[] = ['type' => 'created', 'title' => 'Submitted by ' . ($f['is_anonymous'] ? 'an anonymous ' . $f['submitted_by_type'] : $f['name'] . ' (' . $f['submitted_by_type'] . ')'), 'user' => null, 'at' => $f['created_at'], 'note' => null];
    usort($timeline, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
    $f = crud_public_row($m, $f);
    api_ok([
        'ticket' => $f + ['sla_hours' => feedback_sla_hours($f['priority']), 'sla_due_at' => date('Y-m-d H:i:s', strtotime($f['created_at']) + feedback_sla_hours($f['priority']) * 3600)],
        'timeline' => $timeline,
        'transitions' => array_map(fn ($s) => ['status' => $s, 'label' => feedback_statuses()[$s]], feedback_transitions($f['status'])),
        'can' => ['edit' => can('feedback', 'edit'), 'delete' => can('feedback', 'delete')],
    ]);
});

route('POST', '/feedback/{id:\d+}/status', function ($p) {
    api_require('feedback', 'edit');
    fb_api_find((int) $p['id']);
    $in = api_input();
    $row = feedback_set_status((int) $p['id'], (string) ($in['status'] ?? ''), isset($in['resolution']) ? (string) $in['resolution'] : null, isset($in['remarks']) ? (string) $in['remarks'] : null);
    $msg = ['in_progress' => 'Ticket marked in progress.', 'resolved' => 'Ticket resolved.', 'closed' => 'Ticket closed.', 'open' => 'Ticket reopened.'][$row['status']] ?? 'Ticket updated.';
    api_ok(['status' => $row['status']], $msg);
});

route('POST', '/feedback/{id:\d+}/assign', function ($p) {
    api_require('feedback', 'edit');
    $f = fb_api_find((int) $p['id']);
    $uid = (int) (api_input()['assigned_to'] ?? 0);
    if ($uid && !db_value("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$uid])) {
        api_error('Select a valid staff member.', 422, ['assigned_to' => 'Select a valid staff member.']);
    }
    $upd = ['assigned_to' => $uid ?: null];
    if ($uid && $f['status'] === 'open') {
        $upd['status'] = 'in_progress';
        $upd['started_at'] = $f['started_at'] ?: date('Y-m-d H:i:s');
    }
    db_update('feedback', $upd, 'id = ?', [(int) $f['id']]);
    $who = $uid ? (string) db_value('SELECT name FROM users WHERE id = ?', [$uid]) : 'nobody';
    log_activity('update', 'feedback', $f['id'], sprintf('Assigned ticket %s to %s', $f['ticket_no'], $who), 'success', isset($upd['status']) ? ['from' => 'open', 'to' => 'in_progress'] : []);
    if ($uid && $uid !== (int) user_id()) {
        notify($uid, 'feedback', 'Ticket assigned to you', $f['ticket_no'] . ': ' . str_limit($f['subject'], 80), 'admin/feedback?id=' . $f['id'], 'message-square');
    }
    api_ok(null, $uid ? 'Assigned to ' . $who . (isset($upd['status']) ? ' and moved to In Progress.' : '.') : 'Assignee removed.');
});

route('POST', '/feedback/{id:\d+}/note', function ($p) {
    api_require('feedback', 'edit');
    $f = fb_api_find((int) $p['id']);
    $data = api_validate(['note' => 'required|min:2|max:2000'], null, ['note' => 'Note']);
    log_activity('note', 'feedback', $f['id'], 'Added a note to ticket ' . $f['ticket_no'], 'success', ['note' => $data['note']]);
    api_ok(null, 'Note added.');
});
