<?php
/**
 * Faculty portal - area endpoints (/api/portal/faculty/...). Required by api/routes/portal.php (the auth, dashboard,
 * profile and notices routes live there). Domain logic: app/services/portal_faculty.php.
 *
 * Every route is registered with ['auth' => false] and guarded by portal_faculty_require() (401 signed out / expired,
 * 403 not linked to an active faculty profile or temporary password pending). Everything is derived from the signed-in
 * faculty id; section / subject / exam / leave / document ids from the request are checked against the faculty's own
 * assignments (403 not yours, 404 not found, 423 locked). Non-GET routes keep the default CSRF check (419).
 *
 *   GET  /portal/faculty/timetable?week=Y-m-d                     weekly grid (+ dates, holidays, register status)
 *   GET  /portal/faculty/classes                                  my classes (section + subject) with load + attendance
 *   GET  /portal/faculty/classes/{section}/{subject}              class roster (masked contacts, attendance, last registers)
 *   GET  /portal/faculty/attendance/day?date=                     my periods on a date + register status + class picker
 *   GET  /portal/faculty/attendance/sheet?date&section_id&subject_id&time_slot_id   roster + marks of one period
 *   POST /portal/faculty/attendance/sheet                         {date, section_id, subject_id, time_slot_id?, remarks?, records: [{student_id, status, remarks?}]}
 *   GET  /portal/faculty/attendance/history?section_id&subject_id&from&to&page   registers of my classes
 *   GET  /portal/faculty/attendance/me?month=Y-m                  my own (employee) attendance calendar
 *   GET  /portal/faculty/marks                                    exams with my papers + progress for my sections
 *   GET  /portal/faculty/marks/{exam}/{subject}?section_id=       marks grid (my sections only)
 *   POST /portal/faculty/marks/{exam}/{subject}                   {rows: [{student_id, internal_marks, external_marks, practical_marks, is_absent, remarks}], submit?}
 *   GET  /portal/faculty/leave?status&page                        balance, applications, policy
 *   GET  /portal/faculty/leave/preview?leave_type&from_date&to_date&is_half_day   working days + validation preview
 *   POST /portal/faculty/leave                                    {leave_type, from_date, to_date, is_half_day, reason}
 *   POST /portal/faculty/leave/{id}/cancel
 *   GET  /portal/faculty/documents                                my personnel documents
 *   POST /portal/faculty/documents                                multipart {doc_type, title, expiry_date?, file}  (pending verification)
 *   GET  /portal/faculty/documents/{id}/download[?download=1]
 *   POST /portal/faculty/documents/{id}/delete                    own upload still pending verification
 *   GET  /portal/faculty/payroll                                  payroll reference (own record only)
 *   GET  /portal/faculty/profile/corrections                      my correction requests
 *   POST /portal/faculty/profile/corrections                      {field, requested, note}
 */

require_once APP_ROOT . '/app/services/portal_faculty.php';

/** Register a faculty-area route: guard, scope exceptions -> HTTP status. The handler receives ($me, $params). */
function pfac_route(string $method, string $pattern, callable $handler): void
{
    route($method, '/portal/faculty' . $pattern, function ($p) use ($handler) {
        $me = portal_faculty_require();
        try {
            $handler($me, $p);
        } catch (PortalFacultyException $e) {
            api_error($e->getMessage(), $e->status);
        } catch (AttendanceAccessException $e) {
            api_error($e->getMessage(), 403);
        }
    }, ['auth' => false]);
}

/* ---------------------------------------------------------------- Timetable + classes */

pfac_route('GET', '/timetable', function (array $me) {
    api_ok(pfac_timetable($me['faculty_id'], isset($_GET['week']) ? (string) $_GET['week'] : null));
});

pfac_route('GET', '/classes', function (array $me) {
    api_ok(pfac_classes($me['faculty_id']));
});

pfac_route('GET', '/classes/{section:\d+}/{subject:\d+}', function (array $me, array $p) {
    api_ok(pfac_class_roster($me['faculty_id'], (int) $p['section'], (int) $p['subject']));
});

/* ---------------------------------------------------------------- Class attendance */

pfac_route('GET', '/attendance/day', function (array $me) {
    api_ok(pfac_attendance_day($me['faculty_id'], (string) ($_GET['date'] ?? date('Y-m-d'))));
});

pfac_route('GET', '/attendance/sheet', function (array $me) {
    $date = (string) ($_GET['date'] ?? '');
    $section = (int) ($_GET['section_id'] ?? 0);
    $subject = (int) ($_GET['subject_id'] ?? 0);
    if (!$section || !$subject) {
        api_error('Choose the class and subject.', 422, array_filter(['section_id' => $section ? null : 'Choose the class.', 'subject_id' => $subject ? null : 'Choose the subject.']));
    }
    $slot = isset($_GET['time_slot_id']) && $_GET['time_slot_id'] !== '' ? (int) $_GET['time_slot_id'] : null;
    api_ok(pfac_attendance_sheet($me['faculty_id'], $date, $section, $subject, $slot));
});

pfac_route('POST', '/attendance/sheet', function (array $me) {
    $res = pfac_save_attendance($me['faculty_id'], api_input());
    $c = $res['counts'] ?? null;
    $msg = ($res['created'] ? 'Attendance marked' : 'Attendance updated') . ($c ? sprintf(' — %d of %d present.', $c['present'] + $c['late'], $c['total']) : '.');
    if (!empty($res['new_defaulters'])) {
        $msg .= ' ' . count($res['new_defaulters']) . ' student' . (count($res['new_defaulters']) === 1 ? ' is' : 's are') . ' now below the minimum attendance.';
    }
    api_ok($res, $msg);
});

pfac_route('GET', '/attendance/history', function (array $me) {
    api_ok(pfac_attendance_history($me['faculty_id'], $_GET));
});

pfac_route('GET', '/attendance/me', function (array $me) {
    api_ok(pfac_my_attendance($me['faculty_id'], (string) ($_GET['month'] ?? date('Y-m'))));
});

/* ---------------------------------------------------------------- Marks entry */

pfac_route('GET', '/marks', function (array $me) {
    api_ok(pfac_marks_exams($me['faculty_id']));
});

pfac_route('GET', '/marks/{exam:\d+}/{subject:\d+}', function (array $me, array $p) {
    $section = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int) $_GET['section_id'] : null;
    api_ok(pfac_marks_sheet($me['faculty_id'], (int) $p['exam'], (int) $p['subject'], $section ?: null));
});

pfac_route('POST', '/marks/{exam:\d+}/{subject:\d+}', function (array $me, array $p) {
    $in = api_input();
    $res = pfac_save_marks($me['faculty_id'], (int) $p['exam'], (int) $p['subject'], $in);
    $section = isset($in['section_id']) && $in['section_id'] !== '' && $in['section_id'] !== null ? (int) $in['section_id'] : null;
    $res['sheet'] = pfac_marks_sheet($me['faculty_id'], (int) $p['exam'], (int) $p['subject'], $section ?: null);
    $msg = $res['submitted'] ? 'Marks saved and submitted for verification.'
        : ($res['submit_error'] ? 'Marks saved but not submitted: ' . $res['submit_error'] : ($res['saved'] + $res['deleted'] ? 'Marks saved.' : 'No changes to save.'));
    api_ok($res, $msg);
});

/* ---------------------------------------------------------------- Leave */

pfac_route('GET', '/leave', function (array $me) {
    api_ok(pfac_leave_overview($me['faculty_id'], $_GET));
});

pfac_route('GET', '/leave/preview', function (array $me) {
    api_ok(pfac_leave_preview($me['faculty_id'], $_GET));
});

pfac_route('POST', '/leave', function (array $me) {
    if (!rate_limit('portal-leave:' . $me['user_id'], 20, 3600)) {
        api_error('Too many leave applications in the last hour. Please try again later.', 429);
    }
    $row = pfac_leave_apply($me['faculty_id'], $me['user_id'], api_input());
    api_ok($row, 'Leave application submitted for approval.');
});

pfac_route('POST', '/leave/{id:\d+}/cancel', function (array $me, array $p) {
    api_ok(pfac_leave_cancel($me['faculty_id'], $me['user_id'], (int) $p['id']), 'Leave application cancelled.');
});

/* ---------------------------------------------------------------- Documents */

pfac_route('GET', '/documents', function (array $me) {
    api_ok(pfac_documents($me['faculty_id'], $me['user_id']));
});

pfac_route('POST', '/documents', function (array $me) {
    if (!rate_limit('portal-doc-upload:' . $me['user_id'], 20, 3600)) {
        api_error('Too many uploads in the last hour. Please try again later.', 429);
    }
    $res = pfac_document_upload($me['faculty_id'], $me['user_id'], api_input(), $_FILES['file'] ?? null);
    api_ok($res, 'Document uploaded. The HR office will verify it.');
});

pfac_route('GET', '/documents/{id:\d+}/download', function (array $me, array $p) {
    $row = pfac_document_find($me['faculty_id'], (int) $p['id']);
    $path = (string) $row['file_path'];
    $abs = APP_ROOT . '/' . $path;
    if (!str_starts_with($path, 'storage/private/') || str_contains($path, '..') || !is_file($abs)) {
        api_error('The file for this document is missing. Please contact the HR office.', 404);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($abs) ?: 'application/octet-stream';
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $base = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string) ($row['original_name'] ?: (slugify((string) $row['title']) . '.' . $ext)));
    $inline = in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) && empty($_GET['download']);
    log_activity('download', 'employee_documents', (int) $row['id'], 'Downloaded own document "' . $row['title'] . '" (faculty portal)');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $base . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($abs);
    exit;
});

pfac_route('POST', '/documents/{id:\d+}/delete', function (array $me, array $p) {
    pfac_document_delete($me['faculty_id'], $me['user_id'], (int) $p['id']);
    api_ok(['id' => (int) $p['id']], 'Document removed.');
});

/* ---------------------------------------------------------------- Payroll + profile corrections */

pfac_route('GET', '/payroll', function (array $me) {
    api_ok(pfac_payroll($me['faculty_id']));
});

pfac_route('GET', '/profile/corrections', function (array $me) {
    api_ok(['items' => pfac_corrections($me['faculty_id']), 'fields' => pfac_correction_fields()]);
});

pfac_route('POST', '/profile/corrections', function (array $me) {
    $r = pfac_correction_request($me['faculty_id'], $me['user_id'], api_input());
    api_ok($r, 'Your correction request has been sent to the HR office.');
});
