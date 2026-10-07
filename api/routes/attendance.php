<?php
/**
 * Attendance API (students, faculty & staff, reports, alerts, device integration).
 *
 *   GET    /api/attendance/dashboard?date=&mine=1             today's dashboard (KPIs, trend, classes, low attendance)
 *   GET    /api/attendance/options                            sections the user may mark (current session)
 *   GET    /api/attendance/periods?section_id=&date=          timetable periods / subjects for a section + day info
 *   GET    /api/attendance/sheet?section_id=&subject_id=&time_slot_id=&date=   roster + existing sheet
 *   POST   /api/attendance/sheet                              save (create/update) a class sheet
 *   DELETE /api/attendance/sheet/{id}                         delete a class sheet
 *   POST   /api/attendance/sheet/{id}/lock   {locked: bool}   lock / unlock a sheet (approve)
 *   GET    /api/attendance/calendar?section_id=&month=YYYY-MM  monthly heatmap for a section
 *   GET    /api/attendance/employees/sheet?date=&type=&department_id=&q=
 *   POST   /api/attendance/employees/sheet                    save faculty/staff daily attendance
 *   GET    /api/attendance/employees/register?month=&type=&department_id=&q=&page=
 *   GET    /api/attendance/reports/{students|defaulters|department|subject|monthly}?from=&to=&department_id=&program_id=&semester=&section_id=&subject_id=&q=&threshold=&page=&per_page=&sort=&dir=
 *   GET    /api/attendance/reports/export?report=...&format=csv|xlsx   (+ the report filters)
 *   GET    /api/attendance/student/{id}?from=&to=             subject-wise + monthly breakdown for one student
 *   POST   /api/attendance/alerts   {student_ids[] | all: true, channels[], ...report filters}   low attendance alerts
 *   GET    /api/attendance/device                             device integration status + recent punches (manage)
 *   POST   /api/attendance/device/token                       generate / rotate the device token (manage) - token returned once
 *   DELETE /api/attendance/device/token                       disable device integration (manage)
 *
 * Device endpoint (no session; token-protected; idempotent):
 *   POST /api/attendance/punch
 *   Headers: X-Device-Token: <token>   (or Authorization: Bearer <token>)
 *   JSON: {"device_id": "GATE-01", "identifier": "GIMT26BBA0001" | "GIMT-F012" | "GIMT:STU:GIMT26BBA0001",
 *          "punch_id": "unique id from the device (recommended)", "punched_at": "2026-10-07 09:02:11",
 *          "method": "biometric|qr|rfid", "direction": "in|out|auto", "person_type": "student|faculty|staff" (optional)}
 *   Repeating a punch with the same device_id + punch_id returns the original result ("duplicate": true).
 */

require_once APP_ROOT . '/app/services/attendance.php';

/** Register a route whose AttendanceAccessException becomes HTTP 403. */
function att_route(string $method, string $pattern, callable $handler, array $opts = []): void
{
    route($method, $pattern, function ($p) use ($handler) {
        try {
            $handler($p);
        } catch (AttendanceAccessException $e) {
            api_error($e->getMessage(), 403);
        }
    }, $opts);
}

function att_req_date(string $key = 'date'): string
{
    $v = (string) ($_GET[$key] ?? api_param($key, ''));
    if ($v === '') {
        return date('Y-m-d');
    }
    if (!att_valid_date($v)) {
        api_error('Choose a valid date.', 422, [$key => 'Choose a valid date.']);
    }
    return $v;
}

function att_req_int(string $key, bool $required = false, string $label = ''): ?int
{
    $v = $_GET[$key] ?? api_param($key);
    if ($v === null || $v === '') {
        if ($required) {
            api_error(($label ?: label_from_key($key)) . ' is required.', 422, [$key => ($label ?: label_from_key($key)) . ' is required.']);
        }
        return null;
    }
    if (!ctype_digit((string) $v)) {
        api_error('Invalid ' . ($label ?: label_from_key($key)) . '.', 422, [$key => 'Invalid value.']);
    }
    return (int) $v;
}

function att_req_month(): string
{
    $m = (string) ($_GET['month'] ?? '');
    if ($m === '') {
        return date('Y-m');
    }
    if (!att_valid_month($m)) {
        api_error('Choose a valid month.', 422, ['month' => 'Choose a valid month (YYYY-MM).']);
    }
    return $m;
}

/* ---------------------------------------------------------------- Dashboard & marking */

att_route('GET', '/attendance/dashboard', function () {
    api_require('attendance', 'view');
    api_ok(att_dashboard(att_req_date(), !empty($_GET['mine'])));
});

att_route('GET', '/attendance/options', function () {
    api_require('attendance', 'view');
    $scope = att_faculty_scope();
    api_ok(['sections' => att_section_options(), 'restricted' => $scope !== null, 'slots' => att_time_slots(), 'min_percent' => att_min_percent(),
        'late_counts' => att_late_counts(), 'weekly_off' => att_weekly_off(), 'session' => current_session()]);
});

att_route('GET', '/attendance/periods', function () {
    api_require('attendance', 'view');
    api_ok(att_periods(att_req_int('section_id', true, 'Section'), att_req_date()));
});

att_route('GET', '/attendance/sheet', function () {
    api_require('attendance', 'view');
    api_ok(att_student_sheet(att_req_int('section_id', true, 'Section'), att_req_int('subject_id', true, 'Subject'), att_req_int('time_slot_id'), att_req_date()));
});

att_route('POST', '/attendance/sheet', function () {
    if (!can('attendance', 'create') && !can('attendance', 'edit')) {
        api_error('You do not have permission to mark attendance.', 403);
    }
    $res = att_save_student_sheet(api_input());
    $c = $res['counts'];
    $msg = ($res['created'] ? 'Attendance saved' : 'Attendance updated') . ($c ? sprintf(' - %d of %d present.', $c['present'] + $c['late'], $c['total']) : '.');
    if ($res['new_defaulters']) {
        $msg .= ' ' . count($res['new_defaulters']) . ' student' . (count($res['new_defaulters']) === 1 ? '' : 's') . ' dropped below the minimum attendance.';
    }
    api_ok($res, $msg, $res['created'] ? 201 : 200);
});

att_route('DELETE', '/attendance/sheet/{id:\d+}', function ($p) {
    api_require('attendance', 'delete');
    $m = crud_module('attendance');
    $res = crud_delete($m, [(int) $p['id']]);
    if ($res['errors']) {
        api_error(reset($res['errors']), 422);
    }
    if (!$res['deleted']) {
        api_error('Attendance session not found. It may have been deleted already.', 404);
    }
    api_ok($res, 'Attendance session deleted.');
});

att_route('POST', '/attendance/sheet/{id:\d+}/lock', function ($p) {
    api_require('attendance', 'approve');
    $row = db_row('SELECT * FROM attendance WHERE id = ?', [(int) $p['id']]);
    if (!$row) {
        api_error('Attendance session not found.', 404);
    }
    $locked = in_array(api_param('locked', true), [true, 1, '1', 'true'], true) ? 1 : 0;
    db_update('attendance', ['is_locked' => $locked], 'id = ?', [(int) $row['id']]);
    log_activity($locked ? 'lock' : 'unlock', 'attendance', $row['id'], ($locked ? 'Locked' : 'Unlocked') . ' attendance ' . crud_describe(crud_module('attendance'), $row));
    api_ok(['id' => (int) $row['id'], 'is_locked' => (bool) $locked], $locked ? 'Attendance locked. No further changes can be made without unlocking.' : 'Attendance unlocked.');
});

att_route('GET', '/attendance/calendar', function () {
    api_require('attendance', 'view');
    api_ok(att_calendar(att_req_int('section_id', true, 'Section'), att_req_month()));
});

/* ---------------------------------------------------------------- Employees */

att_route('GET', '/attendance/employees/sheet', function () {
    api_require('attendance', 'view');
    $type = in_array($_GET['type'] ?? 'all', ['all', 'faculty', 'staff'], true) ? ($_GET['type'] ?? 'all') : 'all';
    api_ok(att_employee_sheet(att_req_date(), $type, att_req_int('department_id'), trim((string) ($_GET['q'] ?? ''))));
});

att_route('POST', '/attendance/employees/sheet', function () {
    if (!can('attendance', 'create') && !can('attendance', 'edit')) {
        api_error('You do not have permission to mark attendance.', 403);
    }
    $res = att_save_employee_sheet(api_input());
    api_ok($res, sprintf('Attendance saved for %d employee%s.', $res['saved'], $res['saved'] === 1 ? '' : 's'));
});

att_route('GET', '/attendance/employees/register', function () {
    api_require('attendance', 'view');
    $type = in_array($_GET['type'] ?? 'all', ['all', 'faculty', 'staff'], true) ? ($_GET['type'] ?? 'all') : 'all';
    $data = att_employee_register(att_req_month(), $type, att_req_int('department_id'), trim((string) ($_GET['q'] ?? '')));
    [$page, $perPage] = api_pagination(25);
    $p = paginate(count($data['people']), $page, $perPage);
    $data['total'] = $p['total'];
    $data['page'] = $p['page'];
    $data['pages'] = $p['pages'];
    $data['per_page'] = $p['per_page'];
    $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0, 'marked' => 0];
    foreach ($data['people'] as $person) {
        foreach ($totals as $k => $_) {
            $totals[$k] += $person['totals'][$k];
        }
    }
    $data['summary'] = $totals + ['employees' => $p['total'],
        'percent' => $totals['marked'] ? round(100 * ($totals['present'] + (att_late_counts() ? $totals['late'] : 0) + 0.5 * $totals['half_day']) / $totals['marked'], 1) : null];
    $data['people'] = array_slice($data['people'], $p['offset'], $p['per_page']);
    api_ok($data);
});

/* ---------------------------------------------------------------- Reports */

att_route('GET', '/attendance/reports/export', function () {
    api_require('attendance', 'export');
    $report = (string) ($_GET['report'] ?? 'students');
    if (!in_array($report, ['students', 'defaulters', 'department', 'subject', 'monthly', 'employees'], true)) {
        api_error('Unknown report.', 400);
    }
    $format = ($_GET['format'] ?? 'csv') === 'xlsx' ? 'xlsx' : 'csv';
    $t = att_report_table($report, $_GET);
    log_activity('export', 'attendance', null, 'Exported ' . strtolower($t['title']) . ' (' . count($t['rows']) . ' rows, ' . strtoupper($format) . ')');
    $name = 'attendance-' . $report . '-' . date('Y-m-d-His');
    if ($format === 'xlsx') {
        xlsx_download($name . '.xlsx', $t['headers'], $t['rows'], mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $t['title']), 0, 31) ?: 'Report');
    }
    csv_download($name . '.csv', $t['headers'], $t['rows']);
});

att_route('GET', '/attendance/reports/{report:students|defaulters|department|subject|monthly}', function ($p) {
    api_require('attendance', 'view');
    [$page, $perPage] = api_pagination(25);
    $opts = ['page' => $page, 'per_page' => $perPage, 'sort' => $_GET['sort'] ?? null, 'dir' => $_GET['dir'] ?? null];
    switch ($p['report']) {
        case 'students':
            api_ok(att_report_students($_GET, $opts));
        case 'defaulters':
            api_ok(att_report_students($_GET, $opts + ['defaulters' => true]));
        case 'department':
            api_ok(att_report_departments($_GET));
        case 'subject':
            api_ok(att_report_subjects($_GET, $opts));
        case 'monthly':
            api_ok(att_report_monthly($_GET));
    }
});

att_route('GET', '/attendance/student/{id:\d+}', function ($p) {
    api_require('attendance', 'view');
    api_ok(att_student_detail((int) $p['id'], $_GET));
});

att_route('POST', '/attendance/alerts', function () {
    api_require('attendance', 'manage');
    $in = api_input();
    $channels = array_values(array_filter((array) ($in['channels'] ?? []), 'is_string'));
    if (!empty($in['all'])) {
        $all = att_report_students($in, ['defaulters' => true, 'per_page' => 0]);
        $ids = array_column($all['rows'], 'id');
        if (!$ids) {
            api_error('No students are below the attendance threshold for these filters.', 422);
        }
    } else {
        $ids = array_values(array_filter(array_map('intval', (array) ($in['student_ids'] ?? []))));
    }
    $stats = att_send_alerts($ids, $channels, $in);
    if (!$stats['students']) {
        api_error('No alerts were sent. ' . ($stats['skipped'] ? $stats['skipped'] . ' selected student(s) are not below the threshold or have no contact details for the chosen channels.' : ''), 422);
    }
    api_ok($stats, sprintf('Low attendance alerts sent to %d student%s and their parents.%s', $stats['students'], $stats['students'] === 1 ? '' : 's',
        $stats['skipped'] ? ' ' . $stats['skipped'] . ' skipped.' : ''));
});

/* ---------------------------------------------------------------- Device integration */

att_route('GET', '/attendance/device', function () {
    api_require('attendance', 'view');
    api_ok(att_device_info() + ['can_manage' => can('attendance', 'manage')]);
});

att_route('POST', '/attendance/device/token', function () {
    api_require('attendance', 'manage');
    $token = bin2hex(random_bytes(24));
    save_setting('attendance_device_token', $token, 'academic');
    log_activity('update', 'attendance', null, 'Generated a new attendance device token (previous token revoked)');
    api_ok(['token' => $token] + att_device_info(), 'New device token generated. Update it on every biometric / QR device.');
});

att_route('DELETE', '/attendance/device/token', function () {
    api_require('attendance', 'manage');
    save_setting('attendance_device_token', '', 'academic');
    log_activity('update', 'attendance', null, 'Disabled attendance device integration (token revoked)');
    api_ok(att_device_info(), 'Device integration disabled. Devices can no longer post punches.');
});

route('POST', '/attendance/punch', function () {
    $ip = client_ip();
    if (!rate_limit('att_punch:' . $ip, 600, 60)) {
        api_error('Too many punches from this device. Slow down and retry.', 429);
    }
    $expected = att_device_token();
    if ($expected === null) {
        api_error('Device integration is not configured. Generate a device token in Attendance > Device integration.', 503);
    }
    $given = (string) ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '');
    if ($given === '' && preg_match('/^Bearer\s+(\S+)$/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) {
        $given = $m[1];
    }
    if ($given === '' || !hash_equals($expected, $given)) {
        log_system('warning', 'Attendance punch rejected: invalid device token', ['ip' => $ip]);
        api_error('Invalid device token.', 401);
    }
    $res = att_process_punch(api_input(), $ip);
    if ($res['status'] >= 400) {
        json_response(['ok' => false, 'message' => $res['message'], 'errors' => $res['data']['errors'] ?? (object) [], 'data' => isset($res['data']['errors']) ? null : $res['data']], $res['status']);
    }
    api_ok($res['data'], $res['message']);
}, ['auth' => false, 'csrf' => false]);
