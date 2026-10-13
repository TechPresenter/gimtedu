<?php
/**
 * Events — custom endpoints (CRUD: /api/crud/events, /api/crud/event_registrations).
 *
 *   GET  /events/summary                          KPIs, next events, category split, registrations trend
 *   GET  /events/{id}                             event + registration stats + registration state
 *   POST /events/{id}/remind                      {note?} e-mail every registered participant (queued, first batch inline)
 *   POST /events/{id}/resume                      continue sending pending reminders
 *   POST /events/registrations/{id}/{action}      action = check-in | undo-check-in | cancel | restore
 *   GET  /events/{id}/print                       attendance sheet (HTML, A4) ?status=registered|attended
 */
require_once APP_ROOT . '/app/services/communication.php';

route('GET', '/events/summary', function () {
    api_require('events', 'view');
    $now = date('Y-m-d H:i:s');
    $row = db_row("SELECT SUM(start_datetime > ? AND status <> 'cancelled') AS upcoming,
                          SUM(start_datetime <= ? AND COALESCE(end_datetime, start_datetime) >= ? AND status <> 'cancelled') AS ongoing,
                          SUM(COALESCE(end_datetime, start_datetime) < ?) AS past,
                          SUM(start_datetime BETWEEN ? AND ?) AS this_month,
                          SUM(registration_enabled = 1 AND status = 'published' AND COALESCE(registration_deadline, end_datetime, start_datetime) >= ?) AS open_registration,
                          COUNT(*) AS total
                   FROM events", [$now, $now, $now, $now, date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59'), $now]) ?? [];
    $reg = db_row("SELECT SUM(r.status <> 'cancelled') AS active, SUM(r.status = 'attended') AS attended, SUM(r.created_at >= ?) AS last30,
                          SUM(r.status <> 'cancelled' AND e.start_datetime < ?) AS past_registered, SUM(r.status = 'attended' AND e.start_datetime < ?) AS past_attended
                   FROM event_registrations r JOIN events e ON e.id = r.event_id", [date('Y-m-d H:i:s', strtotime('-30 days')), $now, $now]) ?? [];
    $next = db_all("SELECT e.id, e.title, e.category, e.venue, e.start_datetime, e.end_datetime, e.image, e.max_participants, e.registration_enabled, e.status,
                           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status <> 'cancelled') AS registrations
                    FROM events e WHERE e.status IN ('published', 'draft') AND COALESCE(e.end_datetime, e.start_datetime) >= ? ORDER BY e.start_datetime LIMIT 4", [$now]);
    $cats = comm_event_categories();
    foreach ($next as &$n) {
        $n['id'] = (int) $n['id'];
        $n['registrations'] = (int) $n['registrations'];
        $n['max_participants'] = $n['max_participants'] !== null ? (int) $n['max_participants'] : null;
        $n['registration_enabled'] = (bool) $n['registration_enabled'];
        $n['category_label'] = $cats[$n['category']] ?? label_from_key((string) $n['category']);
        $n['image_url'] = $n['image'] ? upload_url($n['image']) : null;
    }
    unset($n);
    $byCat = array_map(fn ($r) => ['key' => $r['category'], 'label' => $cats[$r['category']] ?? label_from_key((string) $r['category']), 'events' => (int) $r['n'], 'registrations' => (int) $r['regs']],
        db_all("SELECT e.category, COUNT(DISTINCT e.id) AS n, COUNT(r.id) AS regs FROM events e LEFT JOIN event_registrations r ON r.event_id = e.id AND r.status <> 'cancelled'
                WHERE e.status <> 'cancelled' GROUP BY e.category ORDER BY n DESC"));
    // registrations per month: last 6 months
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("first day of -$i month"))] = 0;
    }
    foreach (db_all("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS n FROM event_registrations WHERE created_at >= ? GROUP BY ym", [array_key_first($months) . '-01']) as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']] = (int) $r['n'];
        }
    }
    // counts per period tab (same CASE as the events module scope, cancelled events included)
    $periods = db_pairs("SELECT CASE WHEN start_datetime > ? THEN 'upcoming' WHEN COALESCE(end_datetime, start_datetime) < ? THEN 'past' ELSE 'ongoing' END AS p, COUNT(*)
                         FROM events GROUP BY p", [$now, $now]);
    $pastReg = (int) ($reg['past_registered'] ?? 0);
    api_ok([
        'total' => (int) ($row['total'] ?? 0), 'upcoming' => (int) ($row['upcoming'] ?? 0), 'ongoing' => (int) ($row['ongoing'] ?? 0), 'past' => (int) ($row['past'] ?? 0),
        'periods' => ['upcoming' => (int) ($periods['upcoming'] ?? 0), 'ongoing' => (int) ($periods['ongoing'] ?? 0), 'past' => (int) ($periods['past'] ?? 0)], 'this_month' => (int) ($row['this_month'] ?? 0),
        'open_registration' => (int) ($row['open_registration'] ?? 0), 'registrations' => (int) ($reg['active'] ?? 0), 'attended' => (int) ($reg['attended'] ?? 0),
        'registrations_30d' => (int) ($reg['last30'] ?? 0), 'attendance_rate' => $pastReg ? round(((int) $reg['past_attended']) * 100 / $pastReg, 1) : null,
        'next' => $next, 'by_category' => $byCat,
        'trend' => ['labels' => array_map(fn ($ym) => date('M Y', strtotime($ym . '-01')), array_keys($months)), 'values' => array_values($months)],
    ]);
});

route('GET', '/events/{id:\d+}', function ($p) {
    api_require('events', 'view');
    $d = comm_event_detail((int) $p['id']);
    if (!$d) {
        api_error('Event not found. It may have been deleted.', 404);
    }
    api_ok($d);
});

route('POST', '/events/{id:\d+}/remind', function ($p) {
    api_require('events', 'edit');
    $note = mb_substr(trim((string) (api_input()['note'] ?? '')), 0, 1000);
    $res = comm_event_remind((int) $p['id'], $note);
    api_ok($res, 'Sending reminders to ' . number_format($res['queued']['email']) . ' participant' . ($res['queued']['email'] === 1 ? '' : 's') . '…');
});

route('POST', '/events/{id:\d+}/resume', function ($p) {
    api_require('events', 'edit');
    api_ok(comm_process_queue(['related_type' => 'event', 'related_id' => (int) $p['id']], 8.0));
});

route('POST', '/events/registrations/{id:\d+}/{action:check-in|undo-check-in|cancel|restore}', function ($p) {
    api_require('events', 'edit');
    $r = db_row('SELECT r.*, e.status AS event_status FROM event_registrations r JOIN events e ON e.id = r.event_id WHERE r.id = ?', [(int) $p['id']]);
    if (!$r) {
        api_error('Registration not found.', 404);
    }
    $action = str_replace('-', '_', $p['action']);
    if ($action === 'check_in' && $r['status'] === 'cancelled') {
        api_error('This registration was cancelled. Restore it before checking in.', 422);
    }
    if ($action === 'restore') {
        $e = db_row('SELECT max_participants FROM events WHERE id = ?', [(int) $r['event_id']]);
        $taken = (int) db_value("SELECT COUNT(*) FROM event_registrations WHERE event_id = ? AND status <> 'cancelled'", [(int) $r['event_id']]);
        if ($e && $e['max_participants'] && $taken >= (int) $e['max_participants']) {
            api_error('The event is full. Increase the capacity before restoring this registration.', 422);
        }
    }
    $n = comm_registration_action([(int) $r['id']], $action);
    $msg = ['check_in' => $r['name'] . ' checked in.', 'undo_check_in' => 'Check-in reverted for ' . $r['name'] . '.', 'cancel' => 'Registration of ' . $r['name'] . ' cancelled.',
        'restore' => 'Registration of ' . $r['name'] . ' restored.'][$action];
    api_ok(['updated' => $n, 'row' => db_row('SELECT * FROM event_registrations WHERE id = ?', [(int) $r['id']])], $n ? $msg : 'Nothing to change.');
});

route('GET', '/events/{id:\d+}/print', function ($p) {
    comm_print_event_attendees((int) $p['id']);
});
