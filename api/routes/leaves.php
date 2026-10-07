<?php
/**
 * Leave management for faculty & staff (list / create / edit / delete via /api/crud/employee_leaves).
 *   GET    /api/leaves/summary                                   KPIs, by-type and monthly trend (current session)
 *   GET    /api/leaves/preview?employee_ref&leave_type&from&to&half_day&exclude_id   working days, holidays, validation, balance
 *   GET    /api/leaves/balance?employee_ref=f-12                  balance per leave type for one employee
 *   GET    /api/leaves/balances?employee_type&department_id&q&page&per_page   balance summary per person (paginated)
 *   GET    /api/leaves/calendar?month=YYYY-MM&employee_type&department_id&include_pending=1   who is on leave
 *   GET    /api/leaves/{id}                                       application detail + history
 *   POST   /api/leaves/{id}/approve   {remarks?}                  needs faculty:approve
 *   POST   /api/leaves/{id}/reject    {remarks}                   needs faculty:approve
 *   POST   /api/leaves/{id}/cancel    {remarks?}                  needs faculty:edit
 *   GET    /api/leaves/types                                      leave policy (all types + usage)
 *   POST   /api/leaves/types | /api/leaves/types/{id}             create / update a leave type (faculty:manage)
 *   DELETE /api/leaves/types/{id}                                 delete an unused leave type (faculty:manage)
 */
require_once APP_ROOT . '/app/services/hr.php';

route('GET', '/leaves/summary', function () {
    api_require('faculty', 'view');
    $w = hr_session_window();
    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $monthEnd = date('Y-m-t');
    $onLeave = db_all("SELECT l.employee_type, l.employee_id, l.leave_type, lt.name AS leave_type_name, lt.color, l.to_date,
                              IF(l.employee_type = 'faculty', TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)), TRIM(CONCAT_WS(' ', s.first_name, s.last_name))) AS name,
                              COALESCE(f.photo, s.photo) AS photo, COALESCE(f.designation, s.designation) AS designation
                       FROM employee_leaves l
                       LEFT JOIN faculty f ON l.employee_type = 'faculty' AND f.id = l.employee_id
                       LEFT JOIN staff s ON l.employee_type = 'staff' AND s.id = l.employee_id
                       LEFT JOIN leave_types lt ON lt.code = l.leave_type
                       WHERE l.status = 'approved' AND l.from_date <= ? AND l.to_date >= ? ORDER BY name", [$today, $today]);
    $byType = db_all("SELECT lt.code, lt.name, lt.color, COUNT(l.id) AS applications, COALESCE(SUM(l.days), 0) AS days FROM leave_types lt
                      LEFT JOIN employee_leaves l ON l.leave_type = lt.code AND l.status = 'approved' AND l.from_date BETWEEN ? AND ?
                      GROUP BY lt.code, lt.name, lt.color, lt.sort_order ORDER BY lt.sort_order", [$w['start'], $w['end']]);
    // Monthly trend + this month's days: spread every approved leave over the working days it actually covers
    // (a long leave such as maternity counts in each month it spans, not only in its first month).
    $trend = [];
    $m = substr($w['start'], 0, 7);
    $last = min(substr($w['end'], 0, 7), date('Y-m', strtotime('+2 months')));
    while ($m <= $last) {
        $trend[$m] = ['month' => $m, 'label' => date('M', strtotime($m . '-01')), 'faculty' => 0.0, 'staff' => 0.0];
        $m = date('Y-m', strtotime($m . '-01 +1 month'));
    }
    $holidays = hr_holidays_between($w['start'], $w['end']);
    $offs = hr_weekly_offs();
    $monthDays = 0.0;
    $monthApps = 0;
    foreach (db_all("SELECT employee_type, from_date, to_date, days, is_half_day FROM employee_leaves WHERE status = 'approved' AND from_date <= ? AND to_date >= ?", [$w['end'], $w['start']]) as $l) {
        $inMonth = false;
        $d = max($l['from_date'], $w['start']);
        $to = min($l['to_date'], $w['end']);
        while ($d <= $to) {
            if (!in_array((int) date('w', strtotime($d)), $offs, true) && !isset($holidays[$d])) {
                $unit = $l['is_half_day'] ? 0.5 : 1.0;
                $ym = substr($d, 0, 7);
                if (isset($trend[$ym])) {
                    $trend[$ym][$l['employee_type']] += $unit;
                }
                if ($d >= $monthStart && $d <= $monthEnd) {
                    $monthDays += $unit;
                    $inMonth = true;
                }
            }
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
        }
        $monthApps += $inMonth ? 1 : 0;
    }
    $month = ['n' => $monthApps, 'days' => $monthDays];
    api_ok([
        'session' => $w,
        'pending' => (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE status = 'pending'"),
        'pending_overdue' => (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE status = 'pending' AND from_date <= ?", [$today]),
        'on_leave_today' => count($onLeave),
        'on_leave_list' => array_map(fn ($r) => $r + ['ref' => hr_ref($r['employee_type'], (int) $r['employee_id'])], $onLeave),
        'upcoming_week' => (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE status = 'approved' AND from_date > ? AND from_date <= ?", [$today, date('Y-m-d', strtotime('+7 days'))]),
        'month_applications' => (int) $month['n'], 'month_days' => (float) $month['days'],
        'session_days' => (float) db_value("SELECT COALESCE(SUM(days), 0) FROM employee_leaves WHERE status = 'approved' AND from_date BETWEEN ? AND ?", [$w['start'], $w['end']]),
        'session_rejected' => (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE status = 'rejected' AND from_date BETWEEN ? AND ?", [$w['start'], $w['end']]),
        'lop_days' => (float) db_value("SELECT COALESCE(SUM(l.days), 0) FROM employee_leaves l JOIN leave_types lt ON lt.code = l.leave_type AND lt.is_paid = 0
                                        WHERE l.status = 'approved' AND l.from_date BETWEEN ? AND ?", [$w['start'], $w['end']]),
        'by_type' => array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'color' => $r['color'], 'applications' => (int) $r['applications'], 'days' => (float) $r['days']], $byType),
        'trend' => array_values($trend),
        'can' => ['approve' => can('faculty', 'approve'), 'create' => can('faculty', 'create'), 'edit' => can('faculty', 'edit'), 'manage' => can('faculty', 'manage')],
    ]);
});

route('GET', '/leaves/preview', function () {
    api_require('faculty', 'view');
    $ref = hr_parse_ref($_GET['employee_ref'] ?? '');
    $from = (string) ($_GET['from'] ?? '');
    $to = (string) ($_GET['to'] ?? '') ?: $from;
    $valid = fn ($d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
    if (!$valid($from) || !$valid($to) || $to < $from) {
        api_ok(['days' => 0, 'calendar_days' => 0, 'weekly_offs' => 0, 'holidays' => [], 'errors' => (object) [], 'balance' => null]);
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
        api_ok(['days' => 0, 'calendar_days' => 0, 'weekly_offs' => 0, 'holidays' => [], 'errors' => ['to_date' => 'A single application cannot exceed one year.'], 'balance' => null]);
    }
    $half = in_array($_GET['half_day'] ?? '0', ['1', 'true'], true);
    $wd = hr_working_days($from, $to, $half);
    $errors = [];
    $balance = null;
    $leaveType = (string) ($_GET['leave_type'] ?? '');
    if ($ref) {
        $errors = hr_validate_leave(['employee_type' => $ref[0], 'employee_id' => $ref[1], 'leave_type' => $leaveType, 'from_date' => $from, 'to_date' => $to, 'is_half_day' => $half],
            !empty($_GET['exclude_id']) ? (int) $_GET['exclude_id'] : null);
        if ($leaveType === '') {
            unset($errors['leave_type']);
        }
        foreach (hr_leave_balance($ref[0], $ref[1]) as $b) {
            if ($b['code'] === $leaveType) {
                $balance = $b;
            }
        }
    }
    api_ok($wd + ['errors' => (object) $errors, 'balance' => $balance]);
});

route('GET', '/leaves/balance', function () {
    api_require('faculty', 'view');
    $ref = hr_parse_ref($_GET['employee_ref'] ?? '');
    if (!$ref || !hr_person($ref[0], $ref[1])) {
        api_error('Select a valid employee.', 422, ['employee_ref' => 'Select a valid employee.']);
    }
    api_ok(['session' => hr_session_window(), 'balance' => hr_leave_balance($ref[0], $ref[1])]);
});

route('GET', '/leaves/balances', function () {
    api_require('faculty', 'view');
    [$page, $perPage] = api_pagination(15);
    $w = hr_session_window();
    $type = in_array($_GET['employee_type'] ?? '', HR_TYPES, true) ? $_GET['employee_type'] : '';
    $where = ["e.status IN ('active', 'on_leave')"];
    $args = [];
    if ($type) {
        $where[] = 'e.etype = ?';
        $args[] = $type;
    }
    if (!empty($_GET['department_id'])) {
        $where[] = 'e.department_id = ?';
        $args[] = (int) $_GET['department_id'];
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(e.name LIKE ? OR e.code LIKE ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
    }
    $union = "(SELECT 'faculty' AS etype, f.id, " . hr_name_sql('faculty', 'f') . " AS name, f.first_name AS sort_name, f.employee_id AS code, f.photo, f.designation, f.department_id, f.status, f.gender FROM faculty f
               UNION ALL
               SELECT 'staff', s.id, " . hr_name_sql('staff', 's') . ", s.first_name, s.employee_id, s.photo, s.designation, s.department_id, s.status, s.gender FROM staff s) e";
    $sqlWhere = ' WHERE ' . implode(' AND ', $where);
    $total = (int) db_value("SELECT COUNT(*) FROM $union $sqlWhere", $args);
    $p = paginate($total, $page, $perPage);
    $people = db_all("SELECT e.*, d.code AS department_code FROM $union LEFT JOIN departments d ON d.id = e.department_id $sqlWhere
                      ORDER BY e.etype, e.sort_name, e.name LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    $types = hr_leave_types(true);
    $rows = [];
    foreach ($people as $person) {
        $sums = [];
        foreach (db_all("SELECT leave_type, status, SUM(days) AS days FROM employee_leaves WHERE employee_type = ? AND employee_id = ? AND status IN ('approved', 'pending')
                         AND from_date BETWEEN ? AND ? GROUP BY leave_type, status", [$person['etype'], (int) $person['id'], $w['start'], $w['end']]) as $r) {
            $sums[$r['leave_type']][$r['status']] = (float) $r['days'];
        }
        $bal = [];
        $totalUsed = 0.0;
        foreach ($types as $t) {
            $genderMismatch = !empty($t['gender']) && !empty($person['gender']) && $t['gender'] !== $person['gender'] && empty($sums[$t['code']]);
            if (($t['applies_to'] !== 'all' && $t['applies_to'] !== $person['etype']) || $genderMismatch) {
                $bal[$t['code']] = null;
                continue;
            }
            $used = $sums[$t['code']]['approved'] ?? 0.0;
            $totalUsed += $used;
            $bal[$t['code']] = ['used' => $used, 'pending' => $sums[$t['code']]['pending'] ?? 0.0, 'quota' => $t['annual_quota'],
                'available' => $t['annual_quota'] > 0 ? max(0, $t['annual_quota'] - $used) : null];
        }
        $rows[] = ['ref' => hr_ref($person['etype'], (int) $person['id']), 'employee_type' => $person['etype'], 'id' => (int) $person['id'], 'name' => $person['name'],
            'code' => $person['code'], 'photo' => $person['photo'], 'designation' => $person['designation'], 'department_code' => $person['department_code'],
            'balances' => $bal, 'total_used' => $totalUsed];
    }
    api_ok(['rows' => $rows, 'types' => $types, 'session' => $w, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'from' => $p['from'], 'to' => $p['to']]);
});

route('GET', '/leaves/calendar', function () {
    api_require('faculty', 'view');
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
    $start = $month . '-01';
    $end = date('Y-m-t', strtotime($start));
    $statuses = !empty($_GET['include_pending']) && $_GET['include_pending'] !== '0' ? ['approved', 'pending'] : ['approved'];
    $where = ['l.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')', 'l.from_date <= ?', 'l.to_date >= ?'];
    $args = array_merge($statuses, [$end, $start]);
    if (in_array($_GET['employee_type'] ?? '', HR_TYPES, true)) {
        $where[] = 'l.employee_type = ?';
        $args[] = $_GET['employee_type'];
    }
    if (!empty($_GET['department_id'])) {
        $where[] = 'COALESCE(f.department_id, s.department_id) = ?';
        $args[] = (int) $_GET['department_id'];
    }
    if (!empty($_GET['leave_type'])) {
        $where[] = 'l.leave_type = ?';
        $args[] = (string) $_GET['leave_type'];
    }
    $rows = db_all("SELECT l.id, l.employee_type, l.employee_id, l.leave_type, l.from_date, l.to_date, l.days, l.is_half_day, l.status, l.reason,
                           lt.name AS leave_type_name, lt.color,
                           IF(l.employee_type = 'faculty', TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)), TRIM(CONCAT_WS(' ', s.first_name, s.last_name))) AS name,
                           COALESCE(f.employee_id, s.employee_id) AS code, COALESCE(f.photo, s.photo) AS photo,
                           COALESCE(f.designation, s.designation) AS designation, d.code AS department_code
                    FROM employee_leaves l
                    LEFT JOIN faculty f ON l.employee_type = 'faculty' AND f.id = l.employee_id
                    LEFT JOIN staff s ON l.employee_type = 'staff' AND s.id = l.employee_id
                    LEFT JOIN departments d ON d.id = COALESCE(f.department_id, s.department_id)
                    LEFT JOIN leave_types lt ON lt.code = l.leave_type
                    WHERE " . implode(' AND ', $where) . ' ORDER BY l.from_date, name LIMIT 2000', $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['employee_id'] = (int) $r['employee_id'];
        $r['days'] = (float) $r['days'];
        $r['is_half_day'] = (bool) $r['is_half_day'];
        $r['ref'] = hr_ref($r['employee_type'], $r['employee_id']);
    }
    api_ok(['month' => $month, 'start' => $start, 'end' => $end, 'today' => date('Y-m-d'), 'leaves' => $rows,
        'holidays' => (object) hr_holidays_between($start, $end), 'weekly_offs' => hr_weekly_offs(), 'types' => hr_leave_types(false)]);
});

/* ---------------- Leave types (policy) ---------------- */

route('GET', '/leaves/types', function () {
    api_require('faculty', 'view');
    $w = hr_session_window();
    $usage = db_pairs('SELECT leave_type, COUNT(*) FROM employee_leaves GROUP BY leave_type');
    $session = db_pairs("SELECT leave_type, SUM(days) FROM employee_leaves WHERE status = 'approved' AND from_date BETWEEN ? AND ? GROUP BY leave_type", [$w['start'], $w['end']]);
    $rows = array_map(fn ($t) => $t + ['applications' => (int) ($usage[$t['code']] ?? 0), 'session_days' => (float) ($session[$t['code']] ?? 0)], hr_leave_types(false));
    api_ok(['rows' => $rows, 'can_manage' => can('faculty', 'manage'), 'weekly_offs' => hr_weekly_offs()]);
});

$saveType = function (array $p) {
    api_require('faculty', 'manage');
    $id = isset($p['id']) ? (int) $p['id'] : null;
    $old = $id ? db_row('SELECT * FROM leave_types WHERE id = ?', [$id]) : null;
    if ($id && !$old) {
        api_error('Leave type not found.', 404);
    }
    $in = api_input();
    $in['code'] = strtolower(trim((string) ($in['code'] ?? ($old['code'] ?? ''))));
    $rules = [
        'code' => 'required|alpha_dash|max:30|unique:leave_types,code' . ($id ? ',' . $id : ''), 'name' => 'required|max:80',
        'annual_quota' => 'required|numeric|min:0|max:365', 'applies_to' => 'required|in:all,faculty,staff', 'gender' => 'in:male,female', 'max_consecutive' => 'integer|min:1|max:365',
        'color' => 'in:blue,green,amber,purple,pink,cyan,red,slate,orange,navy', 'description' => 'max:255', 'status' => 'required|in:active,inactive', 'sort_order' => 'integer|min:0|max:999',
    ];
    $v = api_validate($rules, $in, ['annual_quota' => 'Annual quota', 'max_consecutive' => 'Max consecutive days', 'applies_to' => 'Applies to']);
    if ($old && $old['code'] !== $v['code'] && (int) db_value('SELECT COUNT(*) FROM employee_leaves WHERE leave_type = ?', [$old['code']])) {
        api_error('Please fix the highlighted fields and try again.', 422, ['code' => 'The code cannot change because applications already use it.']);
    }
    $data = [
        'code' => $v['code'], 'name' => $v['name'], 'annual_quota' => (float) $v['annual_quota'], 'applies_to' => $v['applies_to'], 'gender' => $v['gender'] ?: null,
        'is_paid' => in_array($in['is_paid'] ?? true, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
        'max_consecutive' => $v['max_consecutive'] !== null ? (int) $v['max_consecutive'] : null, 'color' => $v['color'] ?: 'slate',
        'description' => $v['description'], 'status' => $v['status'], 'sort_order' => (int) ($v['sort_order'] ?? 0),
    ];
    if ($id) {
        db_update('leave_types', $data, 'id = ?', [$id]);
    } else {
        $id = db_insert('leave_types', $data);
    }
    log_activity($old ? 'update' : 'create', 'leaves', null, ($old ? 'Updated' : 'Created') . ' leave type "' . $data['name'] . '" (' . ($data['annual_quota'] > 0 ? $data['annual_quota'] . ' days/session' : 'no fixed quota') . ')');
    api_ok(['id' => $id], 'Leave type "' . $data['name'] . '" ' . ($old ? 'updated.' : 'created.'), $old ? 200 : 201);
};
route('POST', '/leaves/types', $saveType);
route('POST', '/leaves/types/{id:\d+}', $saveType);
route('PUT', '/leaves/types/{id:\d+}', $saveType);

route('DELETE', '/leaves/types/{id:\d+}', function ($p) {
    api_require('faculty', 'manage');
    $t = db_row('SELECT * FROM leave_types WHERE id = ?', [(int) $p['id']]);
    if (!$t) {
        api_error('Leave type not found.', 404);
    }
    $used = (int) db_value('SELECT COUNT(*) FROM employee_leaves WHERE leave_type = ?', [$t['code']]);
    if ($used) {
        api_error('"' . $t['name'] . '" is used by ' . $used . ' application(s) and cannot be deleted. Mark it inactive instead.', 409);
    }
    db_delete('leave_types', 'id = ?', [(int) $p['id']]);
    log_activity('delete', 'leaves', null, 'Deleted leave type "' . $t['name'] . '"');
    api_ok(null, 'Leave type "' . $t['name'] . '" deleted.');
});

/* ---------------- One application ---------------- */

route('GET', '/leaves/{id:\d+}', function ($p) {
    api_require('faculty', 'view');
    $leave = hr_leave_row((int) $p['id']);
    if (!$leave) {
        api_error('Leave application not found. It may have been deleted.', 404);
    }
    $person = hr_person($leave['employee_type'], (int) $leave['employee_id']);
    $balance = null;
    foreach (hr_leave_balance($leave['employee_type'], (int) $leave['employee_id']) as $b) {
        if ($b['code'] === $leave['leave_type']) {
            $balance = $b;
        }
    }
    $history = db_all("SELECT a.action, a.description, a.created_at, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
                       WHERE a.module = 'leaves' AND a.record_id = ? ORDER BY a.created_at, a.id", [(string) $leave['id']]);
    $users = db_pairs('SELECT id, name FROM users WHERE id IN (?, ?)', [(int) ($leave['approved_by'] ?? 0), (int) ($leave['applied_by'] ?? 0)]);
    $today = date('Y-m-d');
    api_ok([
        'leave' => $leave + ['approved_by_name' => $users[$leave['approved_by']] ?? null, 'applied_by_name' => $users[$leave['applied_by']] ?? null,
            'employee_ref' => hr_ref($leave['employee_type'], (int) $leave['employee_id'])],
        'employee' => $person ? ['name' => $person['full_name'], 'code' => $person['employee_id'], 'designation' => $person['designation'], 'photo' => $person['photo'],
            'department' => $person['department_name'], 'status' => $person['status'], 'type' => $leave['employee_type'], 'id' => (int) $person['id'], 'email' => $person['email'], 'phone' => $person['phone']] : null,
        'balance' => $balance,
        'working' => hr_working_days($leave['from_date'], $leave['to_date'], (bool) $leave['is_half_day']),
        'history' => $history,
        'can' => [
            'approve' => can('faculty', 'approve') && $leave['status'] === 'pending',
            'edit' => can('faculty', 'edit') && $leave['status'] === 'pending',
            'cancel' => can('faculty', 'edit') && ($leave['status'] === 'pending' || ($leave['status'] === 'approved' && $leave['to_date'] >= $today)),
            'delete' => can('faculty', 'delete') && $leave['status'] !== 'approved',
        ],
    ]);
});

foreach (['approve' => 'approve', 'reject' => 'approve', 'cancel' => 'edit'] as $decision => $perm) {
    route('POST', '/leaves/{id:\d+}/' . $decision, function ($p) use ($decision, $perm) {
        api_require('faculty', $perm);
        $v = api_validate(['remarks' => 'max:255']);
        $leave = hr_leave_decide((int) $p['id'], $decision, $v['remarks']);
        $msg = ['approve' => 'Leave approved for ', 'reject' => 'Leave rejected for ', 'cancel' => 'Leave cancelled for '][$decision] . $leave['employee_name'] . '.';
        api_ok(['id' => (int) $leave['id'], 'status' => $leave['status']], $msg);
    });
}
