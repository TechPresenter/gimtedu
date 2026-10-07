<?php
/**
 * Enquiries API (admissions unit). List/create/edit/delete/import/export use the CRUD engine (/api/crud/enquiries).
 *
 *   GET  /enquiries/summary                    KPIs, by source, by status
 *   GET  /enquiries/{id}/followups             follow-up history of an enquiry
 *   POST /enquiries/{id}/followups             {type, notes, outcome, next_followup_date, status}
 *   POST /enquiries/{id}/assign                {assigned_to}
 *   GET  /enquiries/{id}/prefill               values for the application form (convert enquiry -> application)
 */
require_once APP_ROOT . '/app/services/admissions.php';

function enq_api_find(int $id): array
{
    $e = db_row('SELECT e.*, p.short_name AS program_name FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE e.id = ?', [$id]);
    if (!$e) {
        api_error('Enquiry not found. It may have been deleted.', 404);
    }
    return $e;
}

route('GET', '/enquiries/summary', function () {
    api_require('enquiries', 'view');
    $open = "status IN ('new','contacted','interested')";
    $k = db_row("SELECT COUNT(*) AS total, SUM(status = 'new') AS new_count, SUM($open) AS open_count,
                        SUM($open AND follow_up_date = CURDATE()) AS due_today, SUM($open AND follow_up_date < CURDATE()) AS overdue,
                        SUM(status = 'converted') AS converted, SUM(status = 'interested') AS interested,
                        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS last_30, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) AS prev_30,
                        SUM(DATE(created_at) = CURDATE()) AS today, SUM($open AND assigned_to IS NULL) AS unassigned
                 FROM enquiries");
    $k = array_map(fn ($v) => (int) $v, $k);
    $k['conversion_rate'] = $k['total'] ? round($k['converted'] / $k['total'] * 100, 1) : 0;
    $k['trend'] = $k['prev_30'] ? round(($k['last_30'] - $k['prev_30']) / $k['prev_30'] * 100, 1) : null;
    $sources = db_all("SELECT source, COUNT(*) AS total, SUM(status = 'converted') AS converted FROM enquiries GROUP BY source ORDER BY total DESC");
    foreach ($sources as &$s) {
        $s = ['source' => $s['source'], 'label' => admission_sources()[$s['source']] ?? label_from_key($s['source']), 'total' => (int) $s['total'], 'converted' => (int) $s['converted']];
    }
    unset($s);
    $statuses = array_map('intval', db_pairs('SELECT status, COUNT(*) FROM enquiries GROUP BY status'));
    api_ok(['kpis' => $k, 'sources' => $sources, 'statuses' => $statuses]);
});

route('GET', '/enquiries/{id:\d+}/followups', function ($p) {
    api_require('enquiries', 'view');
    $e = enq_api_find((int) $p['id']);
    $rows = db_all('SELECT f.*, u.name AS created_by_name, cu.name AS completed_by_name FROM admission_followups f LEFT JOIN users u ON u.id = f.created_by
                    LEFT JOIN users cu ON cu.id = f.completed_by WHERE f.enquiry_id = ? ORDER BY f.created_at DESC, f.id DESC', [(int) $e['id']]);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_open'] = $r['next_followup_date'] && !$r['completed_at'];
    }
    unset($r);
    $e['id'] = (int) $e['id'];
    $assignee = $e['assigned_to'] ? db_value('SELECT name FROM users WHERE id = ?', [(int) $e['assigned_to']]) : null;
    $application = $e['admission_id'] ? db_row('SELECT id, application_no, stage FROM admissions WHERE id = ?', [(int) $e['admission_id']]) : null;
    api_ok(['enquiry' => $e + ['assignee_name' => $assignee], 'followups' => $rows, 'application' => $application]);
});

route('POST', '/enquiries/{id:\d+}/followups', function ($p) {
    api_require('enquiries', 'edit');
    $e = enq_api_find((int) $p['id']);
    if ($e['status'] === 'converted') {
        api_error('This enquiry is already converted — log follow-ups on the application instead.', 422);
    }
    $in = api_input();
    if (!empty($in['status']) && !in_array($in['status'], ['new', 'contacted', 'interested', 'not_interested', 'closed'], true)) {
        api_error('Select a valid status.', 422, ['status' => 'Select a valid status.']);
    }
    $fid = followup_log(['enquiry_id' => (int) $e['id']] + $in);
    log_activity('create', 'enquiries', $e['id'], sprintf('Logged %s with %s (%s)', strtolower(admission_followup_types()[$in['type']] ?? 'follow-up'), $e['name'], $e['phone']));
    api_ok(['id' => $fid], 'Follow-up logged.' . (!empty($in['next_followup_date']) ? ' Next follow-up on ' . format_date($in['next_followup_date']) . '.' : ''));
});

route('POST', '/enquiries/{id:\d+}/assign', function ($p) {
    api_require('enquiries', 'edit');
    $e = enq_api_find((int) $p['id']);
    $uid = (int) (api_input()['assigned_to'] ?? 0);
    if ($uid && !db_value("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$uid])) {
        api_error('Select a valid counsellor.', 422, ['assigned_to' => 'Select a valid counsellor.']);
    }
    db_update('enquiries', ['assigned_to' => $uid ?: null], 'id = ?', [(int) $e['id']]);
    $who = $uid ? (string) db_value('SELECT name FROM users WHERE id = ?', [$uid]) : 'nobody';
    log_activity('update', 'enquiries', $e['id'], sprintf('Assigned enquiry of %s (%s) to %s', $e['name'], $e['phone'], $who));
    if ($uid && $uid !== (int) user_id()) {
        notify($uid, 'enquiry', 'Enquiry assigned to you', $e['name'] . ' (' . $e['phone'] . ')', 'admin/enquiries?q=' . rawurlencode((string) $e['phone']), 'message-circle');
    }
    api_ok(null, $uid ? 'Assigned to ' . $who . '.' : 'Counsellor removed.');
});

route('GET', '/enquiries/{id:\d+}/prefill', function ($p) {
    api_require('admissions', 'create');
    $e = enq_api_find((int) $p['id']);
    if ($e['status'] === 'converted' && $e['admission_id']) {
        $app = db_row('SELECT id, application_no FROM admissions WHERE id = ?', [(int) $e['admission_id']]);
        if ($app) {
            api_error('This enquiry was already converted to application ' . $app['application_no'] . '.', 409);
        }
    }
    $parts = preg_split('/\s+/', trim((string) $e['name']));
    $first = array_shift($parts);
    $last = $parts ? array_pop($parts) : '';
    $middle = implode(' ', $parts);
    api_ok([
        'enquiry' => ['id' => (int) $e['id'], 'name' => $e['name'], 'phone' => $e['phone'], 'program' => $e['program_name'] ?: $e['program_interest'], 'source' => $e['source']],
        'values' => array_filter([
            'first_name' => $first, 'middle_name' => $middle ?: null, 'last_name' => $last ?: null, 'phone' => $e['phone'], 'whatsapp' => $e['phone'], 'email' => $e['email'],
            'city' => $e['city'], 'program_id' => $e['program_id'] ? (string) $e['program_id'] : null, 'source' => $e['source'],
            'assigned_to' => $e['assigned_to'] ? (string) $e['assigned_to'] : null, 'counselling_notes' => trim(($e['message'] ? 'Enquiry: ' . $e['message'] . "\n" : '') . ($e['notes'] ?? '')) ?: null,
        ], fn ($v) => $v !== null && $v !== ''),
    ]);
});
