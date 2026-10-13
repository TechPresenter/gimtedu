<?php
/**
 * Examination workflow API (brief section 15): Create Exam -> Assign Subjects -> Schedule -> Allocate Students -> Enter Marks -> Verify -> Publish.
 * Exams, exam subjects and papers themselves are CRUD modules (/api/crud/exams, exam_subjects, exam_schedules).
 *
 *   GET    /exams/summary?session_id=                         KPIs + upcoming papers
 *   GET    /exams/datesheet?session_id&program_id&semester&exam_id&exam_type_id&classroom_id&from&to&q&paginate&page&per_page
 *   GET    /exams/marks/exams?session_id=                     exams open for marks entry (with progress)
 *   GET    /exams/{id}/overview                               workflow steps, counts, progress, timeline
 *   POST   /exams/{id}/subjects/sync                          add every curriculum subject of the semester
 *   POST   /exams/{id}/schedule/auto                          {start_date, start_time, duration, gap, classroom_id}
 *   GET    /exams/{id}/students?view=allocated|pending&status=&q=&page=
 *   POST   /exams/{id}/students/allocate                      {student_ids?: []}  (all candidates when omitted)
 *   POST   /exams/{id}/students/refresh                       recheck attendance / fee eligibility
 *   POST   /exams/{id}/students/remove                        {student_ids: []}
 *   POST   /exams/{id}/students/{student_id}/override         {eligible: bool, reason}
 *   DELETE /exams/{id}/students/{student_id}/override
 *   POST   /exams/{id}/remind                                 email the date sheet reminder to eligible students
 *   GET    /exams/{id}/papers/{schedule_id}/attendance
 *   POST   /exams/{id}/papers/{schedule_id}/attendance        {rows: [{student_id, status, remarks}]}
 *   GET    /exams/{id}/progress                               marks progress per subject
 *   GET    /exams/{id}/marks/{subject_id}?section_id=         marks grid
 *   POST   /exams/{id}/marks/{subject_id}                     {rows: [...], submit?: bool, autosave?: bool}
 *   POST   /exams/{id}/marks/{subject_id}/submit
 *   GET    /exams/{id}/marks/{subject_id}/export?format=csv|xlsx&section_id=
 *   POST   /exams/{id}/marks/{subject_id}/import              multipart file -> parsed rows (nothing saved)
 *   POST   /exams/{id}/verify | /unverify | /publish {notify} | /unpublish | /recompute
 */
require_once APP_ROOT . '/app/services/exams.php';
require_once APP_ROOT . '/app/xlsx.php';
require_once APP_ROOT . '/app/mailer.php';

function exams_api_exam(array $p): array
{
    $e = exam_find((int) $p['id']);
    if (!$e) {
        api_error('Exam not found. It may have been deleted.', 404);
    }
    return $e;
}

function exams_api_can_enter(?int $subjectId = null): void
{
    if (!can('results', 'create') && !can('results', 'edit')) {
        api_error('You do not have permission to enter marks.', 403);
    }
    if ($subjectId !== null && !exam_can_enter_subject($subjectId)) {
        api_error('You can only enter marks for the subjects you teach.', 403);
    }
}

function exams_api_ids($v): array
{
    if (is_string($v)) {
        $v = json_decode($v, true) ?? explode(',', $v);
    }
    return array_values(array_unique(array_filter(array_map('intval', (array) $v))));
}

route('GET', '/exams/summary', function () {
    api_require('examination', 'view');
    $sid = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int) $_GET['session_id'] : current_session_id();
    $out = exam_summary($sid ?: null);
    $out['session_id'] = $sid;
    $out['by_type'] = db_all("SELECT et.name AS label, COUNT(*) AS count FROM exams e JOIN exam_types et ON et.id = e.exam_type_id
                              WHERE e.status <> 'cancelled'" . ($sid ? ' AND e.academic_session_id = ?' : '') . ' GROUP BY et.id, et.name ORDER BY et.id', $sid ? [$sid] : []);
    $out['by_status'] = db_all('SELECT ' . exam_display_status_sql('e') . ' AS status, COUNT(*) AS count FROM exams e' . ($sid ? ' WHERE e.academic_session_id = ?' : '') . ' GROUP BY 1', $sid ? [$sid] : []);
    api_ok($out);
});

route('GET', '/exams/datesheet', function () {
    api_require('examination', 'view');
    $q = $_GET;
    if (!isset($q['session_id'])) {
        $q['session_id'] = current_session_id();
    }
    $data = exam_datesheet($q);
    if (!empty($q['with_summary'])) {
        $where = ["ex.status <> 'cancelled'"];
        $args = [];
        if (!empty($q['session_id'])) {
            $where[] = 'ex.academic_session_id = ?';
            $args[] = (int) $q['session_id'];
        }
        $w = implode(' AND ', $where);
        $today = exam_today();
        $data['summary'] = [
            'papers' => (int) db_value("SELECT COUNT(*) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w", $args),
            'today' => (int) db_value("SELECT COUNT(*) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w AND s.exam_date = ?", array_merge($args, [$today])),
            'this_week' => (int) db_value("SELECT COUNT(*) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w AND s.exam_date BETWEEN ? AND ?", array_merge($args, [$today, date('Y-m-d', strtotime('+6 days'))])),
            'upcoming' => (int) db_value("SELECT COUNT(*) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w AND s.exam_date >= ?", array_merge($args, [$today])),
            'rooms' => (int) db_value("SELECT COUNT(DISTINCT s.classroom_id) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w AND s.exam_date >= ?", array_merge($args, [$today])),
            'unassigned_rooms' => (int) db_value("SELECT COUNT(*) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w AND s.exam_date >= ? AND s.classroom_id IS NULL", array_merge($args, [$today])),
            'next_date' => db_value("SELECT MIN(s.exam_date) FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id WHERE $w AND s.exam_date >= ?", array_merge($args, [$today])),
            'today_date' => $today,
        ];
    }
    api_ok($data);
});

/** Exams that can receive marks (or were already marked) - for the marks entry picker. */
route('GET', '/exams/marks/exams', function () {
    api_require('results', 'view');
    $sid = isset($_GET['session_id']) && $_GET['session_id'] !== '' ? (int) $_GET['session_id'] : current_session_id();
    $where = "e.status NOT IN ('cancelled','draft')";
    $args = [];
    if ($sid) {
        $where .= ' AND e.academic_session_id = ?';
        $args[] = $sid;
    }
    $scope = exam_marks_scope();
    if ($scope !== null) {
        $subjects = array_keys($scope['subjects']);
        $where .= ' AND e.id IN (SELECT exam_id FROM exam_subjects WHERE subject_id IN (' . exam_in($subjects) . '))';
    }
    $rows = db_all('SELECT e.id, e.name, e.status, ' . exam_display_status_sql('e') . " AS display_status, e.start_date, e.end_date, e.semester_no, et.name AS type_name,
                           CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', e.semester_no), '')) AS cohort,
                           (SELECT COUNT(*) FROM exam_subjects es WHERE es.exam_id = e.id) AS subjects,
                           (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = e.id AND x.is_eligible = 1) AS students,
                           (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = e.id) AS entered
                    FROM exams e JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = e.program_id
                    WHERE $where ORDER BY FIELD(" . exam_display_status_sql('e') . ", 'marks_entry', 'ongoing', 'scheduled', 'verified', 'published'), e.start_date DESC, p.sort_order, e.semester_no LIMIT 300", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $expected = (int) $r['subjects'] * (int) $r['students'];
        $r['percent'] = $expected ? round(min(100, 100 * (int) $r['entered'] / $expected), 1) : 0;
        foreach (['subjects', 'students', 'entered'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['locked'] = in_array($r['status'], ['verified', 'published'], true);
    }
    unset($r);
    api_ok(['rows' => $rows, 'session_id' => $sid, 'restricted' => $scope !== null]);
});

route('GET', '/exams/{id:\d+}/overview', function ($p) {
    api_require('examination', 'view');
    $e = exams_api_exam($p);
    $o = exam_overview($e);
    $o['permissions'] = [
        'edit' => can('examination', 'edit'), 'override' => can('examination', 'approve') || can('examination', 'manage'),
        'marks' => can('results', 'create') || can('results', 'edit'), 'verify' => can('results', 'approve'), 'publish' => can('results', 'publish'),
    ];
    $o['sections'] = db_all('SELECT sc.id, sc.name, COUNT(*) AS students FROM exam_students x JOIN students s ON s.id = x.student_id JOIN sections sc ON sc.id = s.section_id
                             WHERE x.exam_id = ? AND x.is_eligible = 1 GROUP BY sc.id, sc.name ORDER BY sc.name', [$e['id']]);
    api_ok($o);
});

route('POST', '/exams/{id:\d+}/subjects/sync', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    if (!$e['program_id'] || !$e['semester_no']) {
        api_error('Set the program and semester of the exam to load its curriculum subjects.', 422);
    }
    $n = exam_sync_subjects($e);
    if (!$n) {
        api_ok(['added' => 0], 'All curriculum subjects of ' . $e['cohort'] . ' are already in the exam.');
    }
    if ($e['status'] === 'scheduled') {
        db_update('exams', ['status' => 'draft'], 'id = ?', [$e['id']]);
    }
    log_activity('update', 'examination', $e['id'], "Added $n curriculum subject" . ($n === 1 ? '' : 's') . ' to "' . $e['name'] . '"');
    api_ok(['added' => $n], "$n subject" . ($n === 1 ? '' : 's') . ' added to the exam.');
});

route('POST', '/exams/{id:\d+}/schedule/auto', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    $in = api_validate(['start_date' => 'date', 'start_time' => 'time', 'duration' => 'integer|min:30|max:360', 'gap' => 'integer|min:1|max:5', 'classroom_id' => 'integer|exists:classrooms,id'],
        null, ['start_date' => 'First paper date', 'start_time' => 'Start time', 'duration' => 'Duration', 'gap' => 'Gap between papers', 'classroom_id' => 'Room']);
    if ($in['start_date'] && $e['start_date'] && $in['start_date'] < $e['start_date']) {
        api_error('Please fix the highlighted fields and try again.', 422, ['start_date' => 'The exam starts on ' . format_date($e['start_date']) . '.']);
    }
    if ($in['start_date'] && $e['end_date'] && $in['start_date'] > $e['end_date']) {
        api_error('Please fix the highlighted fields and try again.', 422, ['start_date' => 'The exam ends on ' . format_date($e['end_date']) . '.']);
    }
    $res = db_transaction(fn () => exam_auto_schedule($e, $in));
    $msg = $res['scheduled'] . ' paper' . ($res['scheduled'] === 1 ? '' : 's') . ' scheduled.';
    if ($res['unplaced']) {
        $msg .= ' Could not place: ' . implode(', ', array_slice($res['unplaced'], 0, 3)) . (count($res['unplaced']) > 3 ? '…' : '') . ' (no free date within the exam window).';
    }
    log_activity('update', 'examination', $e['id'], 'Generated the date sheet of "' . $e['name'] . '" (' . $res['scheduled'] . ' papers)');
    api_ok($res, $msg);
});

route('GET', '/exams/{id:\d+}/students', function ($p) {
    api_require('examination', 'view');
    $e = exams_api_exam($p);
    api_ok(exam_students_list($e, $_GET));
});

route('POST', '/exams/{id:\d+}/students/allocate', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    $in = api_input();
    $ids = array_key_exists('student_ids', $in) ? exams_api_ids($in['student_ids']) : null;
    if ($ids === []) {
        api_error('Select at least one student to allocate.', 422);
    }
    $res = db_transaction(fn () => exam_allocate($e, $ids));
    if (!$res['added']) {
        api_ok($res, 'Everyone selected is already allocated to the exam.');
    }
    log_activity('update', 'examination', $e['id'], sprintf('Allocated %d student%s to "%s" (%d eligible, %d not eligible)', $res['added'], $res['added'] === 1 ? '' : 's', $e['name'], $res['eligible'], $res['ineligible']));
    if ($res['ineligible']) {
        notify(exam_users_with('examination', 'approve'), 'exam_eligibility', 'Eligibility check: ' . $res['ineligible'] . ' student' . ($res['ineligible'] === 1 ? '' : 's') . ' not eligible',
            $e['name'] . ' — attendance below the minimum or overdue fees. Review overrides before admit cards are issued.', '/examination/' . $e['id'] . '?tab=students', 'user-x');
    }
    api_ok($res, sprintf('%d student%s allocated — %d eligible, %d not eligible.', $res['added'], $res['added'] === 1 ? '' : 's', $res['eligible'], $res['ineligible']));
});

route('POST', '/exams/{id:\d+}/students/refresh', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    $res = db_transaction(fn () => exam_refresh_eligibility($e));
    log_activity('update', 'examination', $e['id'], 'Rechecked eligibility for "' . $e['name'] . '" (' . $res['changed'] . ' changed)');
    api_ok($res, 'Eligibility rechecked for ' . $res['checked'] . ' students' . ($res['changed'] ? ' — ' . $res['changed'] . ' changed.' : ' — no changes.'));
});

route('POST', '/exams/{id:\d+}/students/remove', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    $ids = exams_api_ids(api_input()['student_ids'] ?? []);
    if (!$ids) {
        api_error('Select at least one student.', 422);
    }
    $removed = 0;
    $errors = [];
    foreach ($ids as $sid) {
        try {
            exam_remove_student($e, $sid);
            $removed++;
        } catch (CrudException $ex) {
            $errors[$sid] = $ex->getMessage();
        }
    }
    if ($removed) {
        exam_assign_seats($e['id']);
        log_activity('update', 'examination', $e['id'], "Removed $removed student" . ($removed === 1 ? '' : 's') . ' from "' . $e['name'] . '"');
    }
    if (!$removed && $errors) {
        api_error(reset($errors), 422);
    }
    api_ok(['removed' => $removed, 'errors' => $errors], "$removed student" . ($removed === 1 ? '' : 's') . ' removed' . ($errors ? ', ' . count($errors) . ' kept (marks already entered).' : '.'));
});

route('POST', '/exams/{id:\d+}/students/{sid:\d+}/override', function ($p) {
    if (!can('examination', 'approve') && !can('examination', 'manage')) {
        api_error('You do not have permission to override exam eligibility.', 403);
    }
    $e = exams_api_exam($p);
    $in = api_validate(['eligible' => 'required|in:0,1,true,false', 'reason' => 'required|min:5|max:255'], null, ['eligible' => 'Eligibility', 'reason' => 'Reason']);
    $eligible = in_array((string) $in['eligible'], ['1', 'true'], true);
    $row = exam_override_eligibility($e, (int) $p['sid'], $eligible, (string) $in['reason']);
    $name = db_value("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM students WHERE id = ?", [(int) $p['sid']]);
    log_activity('approve', 'examination', $e['id'], ($eligible ? 'Allowed ' : 'Debarred ') . $name . ' for "' . $e['name'] . '" (override: ' . $in['reason'] . ')');
    api_ok($row, $eligible ? "$name is now eligible for the exam." : "$name is marked not eligible.");
});

route('DELETE', '/exams/{id:\d+}/students/{sid:\d+}/override', function ($p) {
    if (!can('examination', 'approve') && !can('examination', 'manage')) {
        api_error('You do not have permission to override exam eligibility.', 403);
    }
    $e = exams_api_exam($p);
    exam_clear_override($e, (int) $p['sid']);
    $name = db_value("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM students WHERE id = ?", [(int) $p['sid']]);
    log_activity('update', 'examination', $e['id'], 'Cleared the eligibility override of ' . $name . ' for "' . $e['name'] . '"');
    api_ok(null, 'Override removed — eligibility recalculated.');
});

route('POST', '/exams/{id:\d+}/remind', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    if (!$e['start_date'] || $e['start_date'] < exam_today()) {
        api_error('Reminders can only be sent before the exam starts.', 422);
    }
    $students = db_all("SELECT s.email, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name FROM exam_students x JOIN students s ON s.id = x.student_id
                        WHERE x.exam_id = ? AND x.is_eligible = 1 AND s.email IS NOT NULL AND s.email <> ''", [$e['id']]);
    if (!$students) {
        api_error('Allocate eligible students (with email addresses) before sending reminders.', 422);
    }
    $sent = 0;
    foreach ($students as $s) {
        $sent += send_template_mail('exam_reminder', $s['email'], ['name' => $s['name'], 'exam' => $e['name'], 'date' => format_date($e['start_date'])],
            ['related_type' => 'exam', 'related_id' => $e['id']]) ? 1 : 0;
    }
    log_activity('send', 'examination', $e['id'], "Sent exam reminders for \"{$e['name']}\" to $sent students");
    api_ok(['sent' => $sent, 'total' => count($students)], "Reminder emailed to $sent student" . ($sent === 1 ? '' : 's') . '.');
});

route('GET', '/exams/{id:\d+}/papers/{sid:\d+}/attendance', function ($p) {
    api_require('examination', 'view');
    $e = exams_api_exam($p);
    $s = exam_schedule_row((int) $p['sid']);
    if (!$s || (int) $s['exam_id'] !== $e['id']) {
        api_error('Paper not found.', 404);
    }
    api_ok(exam_attendance_sheet($s) + ['can_edit' => can('examination', 'edit') && $e['status'] !== 'published']);
});

route('POST', '/exams/{id:\d+}/papers/{sid:\d+}/attendance', function ($p) {
    api_require('examination', 'edit');
    $e = exams_api_exam($p);
    $s = exam_schedule_row((int) $p['sid']);
    if (!$s || (int) $s['exam_id'] !== $e['id']) {
        api_error('Paper not found.', 404);
    }
    $rows = (array) (api_input()['rows'] ?? []);
    if (!$rows) {
        api_error('Mark attendance for at least one student.', 422);
    }
    $sheet = exam_save_attendance($s, $rows);
    log_activity('update', 'examination', $e['id'], sprintf('Recorded exam attendance for %s %s (%d present, %d absent)', $s['subject_code'], $s['subject_name'], $sheet['counts']['present'], $sheet['counts']['absent']));
    api_ok($sheet + ['can_edit' => true], 'Exam attendance saved.');
});

route('GET', '/exams/{id:\d+}/progress', function ($p) {
    if (!can('results', 'view') && !can('examination', 'view')) {
        api_error('You do not have permission to view marks progress.', 403);
    }
    $e = exams_api_exam($p);
    $rows = exam_marks_progress($e['id']);
    $scope = exam_marks_scope();
    foreach ($rows as &$r) {
        $r['can_enter'] = $scope === null || isset($scope['subjects'][$r['subject_id']]);
    }
    unset($r);
    api_ok(['exam' => ['id' => $e['id'], 'name' => $e['name'], 'status' => $e['status'], 'display_status' => $e['display_status'], 'cohort' => $e['cohort'], 'lock_reason' => exam_lock_reason($e)],
        'subjects' => $rows, 'sections' => db_all('SELECT sc.id, sc.name, COUNT(*) AS students FROM exam_students x JOIN students s ON s.id = x.student_id JOIN sections sc ON sc.id = s.section_id
                             WHERE x.exam_id = ? AND x.is_eligible = 1 GROUP BY sc.id, sc.name ORDER BY sc.name', [$e['id']])]);
});

route('GET', '/exams/{id:\d+}/marks/{subject:\d+}', function ($p) {
    api_require('results', 'view');
    $e = exams_api_exam($p);
    $section = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int) $_GET['section_id'] : null;
    api_ok(exam_marks_sheet($e, (int) $p['subject'], $section));
});

route('POST', '/exams/{id:\d+}/marks/{subject:\d+}', function ($p) {
    exams_api_can_enter((int) $p['subject']);
    $e = exams_api_exam($p);
    $in = api_input();
    $rows = (array) ($in['rows'] ?? []);
    if (!$rows) {
        api_error('There are no changes to save.', 422);
    }
    $submit = !empty($in['submit']) && $in['submit'] !== '0';
    $res = exam_save_marks($e, (int) $p['subject'], $rows, false);
    $s = exam_subject_row($e['id'], (int) $p['subject']);
    $submitError = null;
    if ($submit) {
        try {
            exam_submit_marks(exam_find($e['id']), (int) $p['subject']);
        } catch (CrudException $ex) {
            $submit = false;
            $submitError = $ex->getMessage();
        }
    }
    $res['submitted'] = $submit;
    log_activity($submit ? 'submit' : 'update', 'results', $e['id'], sprintf('%s marks of %s %s for %d student%s in "%s"%s', $submit ? 'Submitted' : (!empty($in['autosave']) ? 'Autosaved' : 'Entered'),
        $s['code'], $s['name'], $res['saved'], $res['saved'] === 1 ? '' : 's', $e['name'], $res['deleted'] ? " ({$res['deleted']} cleared)" : ''));
    $section = isset($in['section_id']) && $in['section_id'] !== '' && $in['section_id'] !== null ? (int) $in['section_id'] : null;
    $sheet = exam_marks_sheet(exam_find($e['id']), (int) $p['subject'], $section);
    if ($submit) {
        notify(exam_users_with('results', 'approve'), 'marks_submitted', 'Marks submitted: ' . $s['code'] . ' ' . $s['name'], $e['name'] . ' — ' . $sheet['stats']['entered'] . ' students. Ready for verification.', '/examination/' . $e['id'] . '?tab=marks', 'clipboard-check');
    }
    api_ok($res + ['sheet' => $sheet, 'submit_error' => $submitError],
        $submit ? 'Marks saved and submitted for verification.' : ($submitError ? 'Marks saved but not submitted: ' . $submitError : ($res['saved'] + $res['deleted'] ? 'Marks saved.' : 'No changes.')));
});

route('POST', '/exams/{id:\d+}/marks/{subject:\d+}/submit', function ($p) {
    exams_api_can_enter((int) $p['subject']);
    $e = exams_api_exam($p);
    $n = exam_submit_marks($e, (int) $p['subject']);
    $s = exam_subject_row($e['id'], (int) $p['subject']);
    log_activity('submit', 'results', $e['id'], 'Submitted marks of ' . $s['code'] . ' ' . $s['name'] . ' in "' . $e['name'] . '" for verification');
    notify(exam_users_with('results', 'approve'), 'marks_submitted', 'Marks submitted: ' . $s['code'] . ' ' . $s['name'], $e['name'] . ' — ready for verification.', '/examination/' . $e['id'] . '?tab=marks', 'clipboard-check');
    api_ok(['submitted' => $n], 'Marks submitted for verification.');
});

route('GET', '/exams/{id:\d+}/marks/{subject:\d+}/export', function ($p) {
    if (!can('results', 'export') && !can('results', 'create') && !can('results', 'edit')) {
        api_error('You do not have permission to export marks.', 403);
    }
    $e = exams_api_exam($p);
    $section = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int) $_GET['section_id'] : null;
    [$sheet, $headers, $data] = exam_marks_export_rows($e, (int) $p['subject'], $section);
    $file = slugify($e['name'] . '-' . $sheet['subject']['code']) . '-marks';
    log_activity('export', 'results', $e['id'], 'Exported marks of ' . $sheet['subject']['code'] . ' ' . $sheet['subject']['name'] . ' (' . count($data) . ' students)');
    if (($_GET['format'] ?? 'csv') === 'xlsx') {
        xlsx_download($file . '.xlsx', $headers, $data, 'Marks');
    }
    csv_download($file . '.csv', $headers, $data);
});

route('POST', '/exams/{id:\d+}/marks/{subject:\d+}/import', function ($p) {
    exams_api_can_enter((int) $p['subject']);
    $e = exams_api_exam($p);
    if ($lock = exam_lock_reason($e)) {
        api_error($lock, 422);
    }
    if (empty($_FILES['file'])) {
        api_error('Choose a CSV or Excel file.', 422, ['file' => 'Choose a CSV or Excel file.']);
    }
    $res = exam_parse_marks_file($e, (int) $p['subject'], $_FILES['file']);
    api_ok($res, sprintf('%d of %d rows matched.%s Review the marks and save.', $res['matched'], $res['total'], $res['problems'] ? ' ' . count($res['problems']) . ' row(s) need attention.' : ''));
});

route('POST', '/exams/{id:\d+}/verify', function ($p) {
    api_require('results', 'approve');
    $e = exams_api_exam($p);
    $counts = exam_verify($e);
    log_activity('approve', 'results', $e['id'], 'Verified and locked the marks of "' . $e['name'] . '" — ' . $counts['total'] . ' results computed');
    notify(exam_users_with('results', 'publish'), 'marks_verified', 'Marks verified: ' . $e['name'], $counts['total'] . ' results computed (' . ($counts['pass_percent'] ?? 0) . '% pass). Ready to publish.', '/results?exam=' . $e['id'], 'shield-check');
    api_ok($counts, 'Marks verified and locked. Results computed for ' . $counts['total'] . ' students.');
});

route('POST', '/exams/{id:\d+}/unverify', function ($p) {
    api_require('results', 'approve');
    $e = exams_api_exam($p);
    exam_unverify($e);
    log_activity('update', 'results', $e['id'], 'Unlocked the marks of "' . $e['name'] . '" for corrections');
    api_ok(null, 'Marks unlocked. Corrections can be made and verified again.');
});

route('POST', '/exams/{id:\d+}/recompute', function ($p) {
    api_require('results', 'approve');
    $e = exams_api_exam($p);
    if (!in_array($e['status'], ['verified', 'published'], true)) {
        api_error('Results are computed when the marks are verified.', 422);
    }
    $counts = db_transaction(function () use ($e) {
        $c = exam_compute_results($e['id']);
        if ($e['status'] === 'published') {
            db_exec('UPDATE results SET is_published = 1, published_at = COALESCE(published_at, NOW()) WHERE exam_id = ?', [$e['id']]);
            exam_sync_student_academic($e);
        }
        return $c;
    });
    log_activity('update', 'results', $e['id'], 'Recomputed the results of "' . $e['name'] . '"');
    api_ok($counts, 'Results recomputed (grades, SGPA, CGPA and ranks).');
});

route('POST', '/exams/{id:\d+}/publish', function ($p) {
    api_require('results', 'publish');
    $e = exams_api_exam($p);
    $counts = exam_publish($e);
    $in = api_input();
    $notifyStudents = !array_key_exists('notify', $in) || !in_array($in['notify'], [false, 0, '0', 'false'], true);
    log_activity('publish', 'results', $e['id'], sprintf('Published the results of "%s" — %d students, %s%% pass', $e['name'], $counts['total'], $counts['pass_percent'] ?? 0));
    notify('perm:results', 'result_published', 'Results published: ' . $e['name'], sprintf('%d students · %s%% pass · %d withheld', $counts['total'], $counts['pass_percent'] ?? 0, $counts['withheld']), '/results?exam=' . $e['id'], 'trophy');
    $mailed = 0;
    if ($notifyStudents) {
        $studentUsers = array_map('intval', db_column('SELECT s.user_id FROM results r JOIN students s ON s.id = r.student_id WHERE r.exam_id = ? AND r.result_status <> \'WITHHELD\' AND s.user_id IS NOT NULL', [$e['id']]));
        if ($studentUsers) {
            notify($studentUsers, 'result_published', 'Your result is published', $e['name'], null, 'trophy');
        }
        foreach (exam_publish_recipients($e['id']) as $r) {
            $mailed += send_template_mail('result_published', $r['email'], ['name' => $r['name'], 'exam' => $e['name'], 'sgpa' => $r['sgpa'] !== null ? number_format((float) $r['sgpa'], 2) : number_format((float) $r['percentage'], 2) . '%',
                'status' => $r['result_status']], ['related_type' => 'exam', 'related_id' => $e['id']]) ? 1 : 0;
        }
    }
    api_ok($counts + ['mailed' => $mailed], 'Results published for ' . $counts['total'] . ' students' . ($mailed ? " — $mailed students notified by email." : '.'));
});

route('POST', '/exams/{id:\d+}/unpublish', function ($p) {
    api_require('results', 'publish');
    $e = exams_api_exam($p);
    exam_unpublish($e);
    log_activity('update', 'results', $e['id'], 'Unpublished the results of "' . $e['name'] . '"');
    api_ok(null, 'Results unpublished. Students can no longer see them.');
});
