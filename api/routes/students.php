<?php
/**
 * Student management API (CRUD lives at /api/crud/students; this file adds the screens' custom endpoints).
 *
 *   GET  /students/stats                          KPI strip for the students list
 *   GET  /students/academic-tree                  departments/programs/courses/batches/sessions/sections for dependent selects
 *   GET  /students/check-duplicates?email=&mobile=&admission_no=&aadhaar_no=&student_uid=&roll_no=&exclude_id=
 *   GET  /students/next-ids?program_id=&admission_date=&academic_session_id=   preview of auto-generated IDs
 *   GET  /students/id-cards?ids=1,2 | program_id=&section_id=&batch_id=&semester=&status=&q=   card data (max 300)
 *   GET  /students/promotion/preview?program_id=&semester=&section_id=&session_id=
 *   POST /students/promotion                      run a promotion (see students_promote())
 *   GET  /students/promotion/history?page=
 *   POST /students/documents/{id}/verify          {remarks?}
 *   POST /students/documents/{id}/reject          {reason}
 *   GET  /students/{id}/profile                   header + overview
 *   GET  /students/{id}/parents
 *   POST /students/{id}/status                    {status, reason}
 *   GET  /students/{id}/attendance|fees|exams|results|certificates|library|hostel|transport|placement|communication|activity
 */

require_once APP_ROOT . '/app/services/students.php';

/** 404 unless the student exists; returns the raw row. */
function stu_api_student(int $id): array
{
    $row = db_row('SELECT id, first_name, middle_name, last_name, student_uid, email, mobile, whatsapp, program_id, current_semester, section_id, status, guardian_phone, emergency_contact_phone FROM students WHERE id = ?', [$id]);
    if (!$row) {
        api_error('Student not found. The record may have been deleted.', 404);
    }
    return $row;
}

/** Print views that exist in admin/print (other units add theirs over time). */
function stu_api_print_views(): array
{
    $out = [];
    foreach (['receipt', 'invoice', 'fee-statement', 'marksheet', 'certificate', 'hall-ticket'] as $v) {
        $out[$v] = is_file(APP_ROOT . '/admin/print/' . $v . '.php');
    }
    return $out;
}

/* ------------------------------------------------------------------
 * List page helpers
 * ------------------------------------------------------------------ */

route('GET', '/students/stats', function () {
    api_require('students', 'view');
    $current = current_session();
    $currentId = current_session_id();
    $prevId = !empty($current['start_date']) ? db_value('SELECT id FROM academic_sessions WHERE start_date < ? ORDER BY start_date DESC LIMIT 1', [$current['start_date']]) : null;
    $r = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active,
                    SUM(status = 'active' AND gender = 'male') AS male, SUM(status = 'active' AND gender = 'female') AS female,
                    SUM(status = 'active' AND gender NOT IN ('male', 'female')) AS other,
                    SUM(academic_session_id = ?) AS new_this_session, SUM(academic_session_id = ?) AS new_last_session,
                    SUM(created_at >= ?) AS new_this_month,
                    SUM(status = 'active' AND is_hosteller = 1) AS hostellers, SUM(status = 'active' AND uses_transport = 1) AS transport_users
                 FROM students", [$currentId ?? 0, $prevId ?? 0, date('Y-m-01')]) ?? [];
    $r = array_map('intval', $r);
    $byStatus = [];
    $counts = db_pairs('SELECT status, COUNT(*) FROM students GROUP BY status');
    foreach (students_status_options() as $k => $label) {
        $byStatus[] = ['status' => $k, 'label' => $label, 'count' => (int) ($counts[$k] ?? 0)];
    }
    $byProgram = db_all("SELECT p.id, p.short_name AS label, COUNT(s.id) AS count FROM programs p LEFT JOIN students s ON s.program_id = p.id AND s.status = 'active'
                         GROUP BY p.id, p.short_name, p.sort_order HAVING COUNT(s.id) > 0 ORDER BY count DESC");
    $byCategory = db_all("SELECT COALESCE(category, 'Not set') AS label, COUNT(*) AS count FROM students WHERE status = 'active' GROUP BY COALESCE(category, 'Not set') ORDER BY count DESC");
    $growth = $r['new_last_session'] ? round(($r['new_this_session'] - $r['new_last_session']) * 100 / $r['new_last_session'], 1) : null;
    api_ok($r + [
        'session' => $current['name'] ?? null,
        'new_growth_percent' => $growth,
        'by_status' => $byStatus,
        'by_program' => array_map(fn ($x) => ['id' => (int) $x['id'], 'label' => $x['label'], 'count' => (int) $x['count']], $byProgram),
        'by_category' => array_map(fn ($x) => ['label' => $x['label'], 'count' => (int) $x['count']], $byCategory),
    ]);
});

route('GET', '/students/academic-tree', function () {
    api_require('students', 'view');
    $int = function (array $rows, array $keys) {
        return array_map(function ($r) use ($keys) {
            foreach ($keys as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            return $r;
        }, $rows);
    };
    api_ok([
        'departments' => $int(db_all("SELECT id, name, code FROM departments WHERE status = 'active' ORDER BY name"), ['id']),
        'programs' => $int(db_all('SELECT id, department_id, name, short_name, code, level, total_semesters, status FROM programs ORDER BY sort_order, name'), ['id', 'department_id', 'total_semesters']),
        'courses' => $int(db_all("SELECT id, program_id, name, code FROM courses WHERE status = 'active' ORDER BY name"), ['id', 'program_id']),
        'batches' => $int(db_all('SELECT id, program_id, name, start_year, end_year, status FROM batches ORDER BY start_year DESC, name'), ['id', 'program_id', 'start_year', 'end_year']),
        'sessions' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'is_current' => (bool) $s['is_current'], 'start_date' => $s['start_date'], 'end_date' => $s['end_date'], 'status' => $s['status']],
            db_all('SELECT id, name, is_current, start_date, end_date, status FROM academic_sessions ORDER BY start_date DESC')),
        'sections' => $int(db_all("SELECT sc.id, sc.program_id, sc.semester_no, sc.academic_session_id, sc.batch_id, sc.name, sc.capacity,
                                          (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS strength
                                   FROM sections sc WHERE sc.status = 'active' ORDER BY sc.semester_no, sc.name"), ['id', 'program_id', 'semester_no', 'academic_session_id', 'batch_id', 'capacity', 'strength']),
        'current_session_id' => current_session_id(),
    ]);
});

route('GET', '/students/check-duplicates', function () {
    if (!can('students', 'create') && !can('students', 'edit')) {
        api_error('You do not have permission to check student records.', 403);
    }
    $exclude = (int) ($_GET['exclude_id'] ?? 0) ?: null;
    $values = array_intersect_key($_GET, array_flip(['email', 'mobile', 'admission_no', 'aadhaar_no', 'student_uid', 'roll_no']));
    $values = array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 190) : '', $values);
    api_ok(students_find_duplicates($values, $exclude) ?: new stdClass());
});

route('GET', '/students/next-ids', function () {
    if (!can('students', 'create') && !can('students', 'edit')) {
        api_error('You do not have permission to create students.', 403);
    }
    $programId = (int) ($_GET['program_id'] ?? 0);
    if (!$programId || !students_program($programId)) {
        api_ok(new stdClass());
    }
    $year = students_admission_year(['admission_date' => $_GET['admission_date'] ?? null, 'academic_session_id' => $_GET['academic_session_id'] ?? null]);
    api_ok(students_preview_numbers($programId, $year) + ['formats' => students_id_formats()]);
});

/* ------------------------------------------------------------------
 * ID cards
 * ------------------------------------------------------------------ */

route('GET', '/students/id-cards', function () {
    api_require('students', 'view');
    $res = students_card_rows($_GET, 300);
    api_ok(['rows' => $res['rows'], 'total' => $res['total'], 'limit' => 300, 'institute' => [
        'name' => institute_name(), 'phone' => setting('phone', '+91 9955446477'), 'email' => setting('email', 'info@gimt.ac.in'),
        'website' => setting('website', 'www.gimt.ac.in'), 'address' => setting('address', ''), 'logo' => logo_url(), 'logo_white' => logo_url(true),
    ]]);
});

/* ------------------------------------------------------------------
 * Promotion
 * ------------------------------------------------------------------ */

route('GET', '/students/promotion/preview', function () {
    api_require('students', 'edit');
    $in = api_validate(['program_id' => 'required|integer|exists:programs,id', 'semester' => 'required|integer|min:1|max:12', 'section_id' => 'integer|exists:sections,id',
        'session_id' => 'integer|exists:academic_sessions,id'], $_GET, ['program_id' => 'Program', 'semester' => 'Semester', 'section_id' => 'Section', 'session_id' => 'Session']);
    api_ok(students_promotion_preview((int) $in['program_id'], (int) $in['semester'], $in['section_id'] ? (int) $in['section_id'] : null, $in['session_id'] ? (int) $in['session_id'] : current_session_id()));
});

route('POST', '/students/promotion', function () {
    api_require('students', 'edit');
    $res = students_promote(api_input());
    $msg = [];
    if ($res['promoted']) {
        $msg[] = $res['promoted'] . ' promoted to semester ' . $res['to_semester'];
    }
    if ($res['passed_out']) {
        $msg[] = $res['passed_out'] . ' passed out';
    }
    if ($res['detained']) {
        $msg[] = $res['detained'] . ' detained';
    }
    api_ok($res, 'Promotion ' . $res['reference_no'] . ' completed: ' . (implode(', ', $msg) ?: 'no students changed') . '.');
});

route('GET', '/students/promotion/history', function () {
    api_require('students', 'view');
    [$page, $perPage] = api_pagination(10);
    $where = '1 = 1';
    $args = [];
    if (!empty($_GET['program_id'])) {
        $where = 'sp.program_id = ?';
        $args[] = (int) $_GET['program_id'];
    }
    $total = (int) db_value("SELECT COUNT(*) FROM student_promotions sp WHERE $where", $args);
    $p = paginate($total, $page, $perPage);
    $rows = db_all("SELECT sp.id, sp.reference_no, sp.program_id, p.short_name AS program_name, sp.from_semester, sp.to_semester, sc.name AS section_name,
                           fs.name AS from_session, ts.name AS to_session, sp.promoted_count, sp.detained_count, sp.passed_out_count, sp.skipped_count, sp.remarks,
                           u.name AS created_by_name, sp.created_at
                    FROM student_promotions sp JOIN programs p ON p.id = sp.program_id LEFT JOIN sections sc ON sc.id = sp.from_section_id
                    LEFT JOIN academic_sessions fs ON fs.id = sp.from_session_id LEFT JOIN academic_sessions ts ON ts.id = sp.to_session_id LEFT JOIN users u ON u.id = sp.created_by
                    WHERE $where ORDER BY sp.created_at DESC, sp.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
    foreach ($rows as &$r) {
        foreach (['id', 'program_id', 'from_semester', 'to_semester', 'promoted_count', 'detained_count', 'passed_out_count', 'skipped_count'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
    }
    unset($r);
    api_ok(['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'pages' => $p['pages'], 'per_page' => $p['per_page']]);
});

/* ------------------------------------------------------------------
 * Documents verification
 * ------------------------------------------------------------------ */

$docAction = function (array $p, string $status) {
    api_require('students', 'edit');
    $doc = db_row('SELECT d.*, s.first_name, s.last_name, s.student_uid FROM student_documents d JOIN students s ON s.id = d.student_id WHERE d.id = ?', [(int) $p['id']]);
    if (!$doc) {
        api_error('Document not found.', 404);
    }
    $note = trim((string) api_param($status === 'rejected' ? 'reason' : 'remarks', ''));
    if ($status === 'rejected' && $note === '') {
        api_error('Give the reason for rejecting this document.', 422, ['reason' => 'Enter the reason for rejection.']);
    }
    if (mb_strlen($note) > 255) {
        api_error('Please keep the note under 255 characters.', 422, [$status === 'rejected' ? 'reason' : 'remarks' => 'Maximum 255 characters.']);
    }
    db_update('student_documents', ['status' => $status, 'is_verified' => $status === 'verified' ? 1 : 0, 'verified_by' => user_id(), 'verified_at' => date('Y-m-d H:i:s'), 'remarks' => $note ?: null], 'id = ?', [(int) $doc['id']]);
    $who = trim($doc['first_name'] . ' ' . $doc['last_name']);
    log_activity($status === 'verified' ? 'approve' : 'reject', 'students', $doc['id'], ($status === 'verified' ? 'Verified' : 'Rejected') . ' document "' . $doc['title'] . '" of ' . $who . ' (student #' . $doc['student_id'] . ')' . ($note ? ' — ' . $note : ''));
    api_ok(['id' => (int) $doc['id'], 'status' => $status], $status === 'verified' ? 'Document verified.' : 'Document rejected. The student can upload a corrected copy.');
};
route('POST', '/students/documents/{id:\d+}/verify', fn ($p) => $docAction($p, 'verified'));
route('POST', '/students/documents/{id:\d+}/reject', fn ($p) => $docAction($p, 'rejected'));

/* ------------------------------------------------------------------
 * Profile
 * ------------------------------------------------------------------ */

/** Activity log condition for one student (own record + child records tagged "(student #id)" + bulk actions + other modules mentioning the student ID). */
function stu_api_activity_where(int $id, string $uid): array
{
    return ["((al.module = 'students' AND ((al.record_id = ? AND (al.description IS NULL OR al.description NOT LIKE '%(student #%')) OR al.description LIKE ? OR FIND_IN_SET(?, al.record_id) > 0 AND al.record_id LIKE '%,%'))
             OR (al.module <> 'students' AND al.description LIKE ?))",
        [(string) $id, '%(student #' . $id . ')%', (string) $id, '%' . $uid . '%']];
}

route('GET', '/students/{id:\d+}/profile', function ($p) {
    api_require('students', 'view');
    $id = (int) $p['id'];
    $student = students_profile_row($id);
    if (!$student) {
        api_error('Student not found. The record may have been deleted.', 404);
    }
    $stats = [
        'attendance' => can('attendance', 'view') ? students_attendance_stats($id) : null,
        'fees' => can('fees', 'view') ? students_fee_stats($id) : null,
        'gpa' => can('results', 'view') || can('examination', 'view') ? students_gpa($id) : null,
        'library' => can('library', 'view') ? students_library_stats($id) : null,
        'hostel' => can('hostel', 'view') ? students_hostel_current($id) : null,
        'transport' => can('transport', 'view') ? students_transport_current($id) : null,
        'placement' => can('placement', 'view') ? students_placement_stats($id) : null,
        'documents' => students_document_stats($id),
        'certificates' => can('certificates', 'view') ? (int) db_value("SELECT COUNT(*) FROM certificates WHERE student_id = ? AND status = 'issued'", [$id]) : null,
    ];
    [$w, $a] = stu_api_activity_where($id, (string) $student['student_uid']);
    $activity = db_all("SELECT al.id, al.action, al.module, al.description, al.created_at, u.name AS user_name FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id
                        WHERE $w ORDER BY al.created_at DESC, al.id DESC LIMIT 6", $a);
    $history = db_all('SELECT sa.semester_no, sa.status, sa.sgpa, sa.cgpa, ses.name AS session_name FROM student_academic sa LEFT JOIN academic_sessions ses ON ses.id = sa.academic_session_id
                       WHERE sa.student_id = ? ORDER BY sa.semester_no, sa.id', [$id]);
    api_ok([
        'student' => $student,
        'parents' => students_parents($id),
        'stats' => $stats,
        'recent_activity' => $activity,
        'academic_history' => $history,
        'print_views' => stu_api_print_views(),
    ]);
});

route('GET', '/students/{id:\d+}/parents', function ($p) {
    api_require('students', 'view');
    stu_api_student((int) $p['id']);
    api_ok(students_parents((int) $p['id']));
});

route('POST', '/students/{id:\d+}/status', function ($p) {
    api_require('students', 'edit');
    $s = stu_api_student((int) $p['id']);
    $in = api_validate(['status' => 'required|in:' . implode(',', array_keys(students_status_options())), 'reason' => 'max:255'], null, ['reason' => 'Reason']);
    if (in_array($in['status'], students_inactive_statuses(), true) && empty($in['reason'])) {
        api_error('Give a reason for this status change.', 422, ['reason' => 'Enter the reason (shown on the student profile).']);
    }
    if ($in['status'] === $s['status']) {
        api_error('The student is already ' . strtolower(students_status_options()[$in['status']]) . '.', 422, ['status' => 'Choose a different status.']);
    }
    db_update('students', ['status' => $in['status'], 'status_reason' => $in['status'] === 'active' ? null : ($in['reason'] ?: null)], 'id = ?', [(int) $s['id']]);
    if ($in['status'] === 'active') {
        students_sync_current_academic((int) $s['id']);
    }
    $name = students_full_name($s);
    log_activity('status', 'students', $s['id'], 'Changed status of ' . $name . ' (' . $s['student_uid'] . ') from ' . $s['status'] . ' to ' . $in['status'] . ($in['reason'] ? ' — ' . $in['reason'] : ''));
    $label = students_status_options()[$in['status']];
    api_ok(['id' => (int) $s['id'], 'status' => $in['status']], $in['status'] === 'active' ? "$name has been re-activated." : "$name marked as " . strtolower($label) . '.');
});

route('GET', '/students/{id:\d+}/attendance', function ($p) {
    api_require('students', 'view');
    api_require('attendance', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $sessionId = !empty($_GET['session_id']) ? (int) $_GET['session_id'] : null;
    $lateCounts = setting('late_counts_as_present', '1') === '1' ? " OR ar.status = 'late'" : '';
    $args = [$id];
    $sw = '';
    if ($sessionId) {
        $sw = ' AND a.academic_session_id = ?';
        $args[] = $sessionId;
    }
    $subjects = db_all("SELECT a.subject_id, sb.code, sb.name, COUNT(*) AS total, SUM(ar.status = 'present'$lateCounts) + 0.5 * SUM(ar.status = 'half_day') AS attended,
                               SUM(ar.status = 'absent') AS absent, SUM(ar.status = 'leave') AS on_leave
                        FROM attendance_records ar JOIN attendance a ON a.id = ar.attendance_id LEFT JOIN subjects sb ON sb.id = a.subject_id
                        WHERE ar.person_type = 'student' AND ar.person_id = ?$sw GROUP BY a.subject_id, sb.code, sb.name ORDER BY sb.code", $args);
    $monthly = db_all("SELECT DATE_FORMAT(a.attendance_date, '%Y-%m') AS ym, COUNT(*) AS total, SUM(ar.status = 'present'$lateCounts) + 0.5 * SUM(ar.status = 'half_day') AS attended
                       FROM attendance_records ar JOIN attendance a ON a.id = ar.attendance_id
                       WHERE ar.person_type = 'student' AND ar.person_id = ?$sw GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m') ORDER BY ym DESC LIMIT 12", $args);
    $recent = db_all("SELECT a.attendance_date, ar.status, ar.remarks, sb.code AS subject_code, sb.name AS subject_name, ts.name AS slot_name
                      FROM attendance_records ar JOIN attendance a ON a.id = ar.attendance_id LEFT JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN time_slots ts ON ts.id = a.time_slot_id
                      WHERE ar.person_type = 'student' AND ar.person_id = ?$sw ORDER BY a.attendance_date DESC, ts.sort_order DESC, ar.id DESC LIMIT 25", $args);
    $pct = fn ($r) => (int) $r['total'] ? round((float) $r['attended'] * 100 / (int) $r['total'], 1) : null;
    api_ok([
        'overall' => students_attendance_stats($id, $sessionId),
        'subjects' => array_map(fn ($r) => ['subject_id' => $r['subject_id'] ? (int) $r['subject_id'] : null, 'code' => $r['code'], 'name' => $r['name'] ?? 'Daily attendance',
            'total' => (int) $r['total'], 'attended' => (float) $r['attended'], 'absent' => (int) $r['absent'], 'leave' => (int) $r['on_leave'], 'percent' => $pct($r)], $subjects),
        'monthly' => array_reverse(array_map(fn ($r) => ['month' => $r['ym'], 'label' => date('M Y', strtotime($r['ym'] . '-01')), 'total' => (int) $r['total'], 'percent' => $pct($r)], $monthly)),
        'recent' => $recent,
    ]);
});

route('GET', '/students/{id:\d+}/fees', function ($p) {
    api_require('students', 'view');
    api_require('fees', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $invoices = db_all('SELECT sf.id, sf.invoice_no, sf.title, sf.semester_no, ses.name AS session_name, sf.gross_amount, sf.discount_amount, sf.scholarship_amount, sf.fine_amount,
                               sf.net_amount, sf.paid_amount, sf.balance_amount, sf.due_date, sf.status, sf.created_at
                        FROM student_fees sf LEFT JOIN academic_sessions ses ON ses.id = sf.academic_session_id WHERE sf.student_id = ? ORDER BY sf.created_at DESC, sf.id DESC LIMIT 100', [$id]);
    $payments = db_all('SELECT p.id, p.receipt_no, p.amount, p.fine_amount, p.payment_date, p.mode, p.reference_no, p.purpose, p.status, sf.invoice_no, u.name AS collected_by_name
                        FROM payments p LEFT JOIN student_fees sf ON sf.id = p.student_fee_id LEFT JOIN users u ON u.id = p.collected_by
                        WHERE p.student_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT 100', [$id]);
    foreach ($invoices as &$i) {
        $i['id'] = (int) $i['id'];
    }
    unset($i);
    foreach ($payments as &$pm) {
        $pm['id'] = (int) $pm['id'];
    }
    unset($pm);
    api_ok(['totals' => students_fee_stats($id), 'invoices' => $invoices, 'payments' => $payments, 'print_views' => stu_api_print_views()]);
});

route('GET', '/students/{id:\d+}/exams', function ($p) {
    api_require('students', 'view');
    api_require('examination', 'view');
    $id = (int) $p['id'];
    $s = stu_api_student($id);
    $exams = db_all("SELECT e.id, e.name, et.name AS type_name, ses.name AS session_name, e.semester_no, e.start_date, e.end_date, e.status, ex.hall_ticket_no, ex.seat_no, ex.is_eligible,
                            ex.ineligibility_reason, cr.name AS room_name
                     FROM exam_students ex JOIN exams e ON e.id = ex.exam_id JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN academic_sessions ses ON ses.id = e.academic_session_id
                     LEFT JOIN classrooms cr ON cr.id = ex.classroom_id
                     WHERE ex.student_id = ? ORDER BY e.start_date DESC, e.id DESC LIMIT 50", [$id]);
    $examIds = array_map(fn ($e) => (int) $e['id'], $exams);
    $schedules = [];
    if ($examIds) {
        $schedules = db_all('SELECT es.id, es.exam_id, e.name AS exam_name, sb.code AS subject_code, sb.name AS subject_name, es.exam_date, es.start_time, es.end_time, cr.name AS room_name, ea.status AS attendance_status
                             FROM exam_schedules es JOIN exams e ON e.id = es.exam_id JOIN subjects sb ON sb.id = es.subject_id LEFT JOIN classrooms cr ON cr.id = es.classroom_id
                             LEFT JOIN exam_attendance ea ON ea.exam_schedule_id = es.id AND ea.student_id = ?
                             WHERE es.exam_id IN (' . implode(',', $examIds) . ') ORDER BY es.exam_date, es.start_time LIMIT 200', [$id]);
    }
    // Upcoming exams of the student's class that have not allocated students yet
    $upcoming = db_all("SELECT e.id, e.name, et.name AS type_name, e.start_date, e.end_date, e.status FROM exams e JOIN exam_types et ON et.id = e.exam_type_id
                        WHERE (e.program_id = ? OR e.program_id IS NULL) AND (e.semester_no = ? OR e.semester_no IS NULL) AND e.status IN ('scheduled', 'ongoing')
                          AND e.id NOT IN (SELECT exam_id FROM exam_students WHERE student_id = ?) ORDER BY e.start_date LIMIT 10", [(int) $s['program_id'], (int) $s['current_semester'], $id]);
    foreach ($exams as &$e) {
        $e['id'] = (int) $e['id'];
        $e['is_eligible'] = (bool) $e['is_eligible'];
    }
    unset($e);
    api_ok(['exams' => $exams, 'schedules' => $schedules, 'upcoming' => $upcoming, 'print_views' => stu_api_print_views()]);
});

route('GET', '/students/{id:\d+}/results', function ($p) {
    api_require('students', 'view');
    api_require('results', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $results = db_all("SELECT r.id, r.exam_id, e.name AS exam_name, et.is_final, ses.name AS session_name, r.semester_no, r.total_marks, r.max_marks, r.percentage, r.credits_registered, r.credits_earned,
                              r.sgpa, r.cgpa, r.result_status, r.backlog_count, r.class_rank, r.division, r.is_published, r.published_at, m.id AS marksheet_id, m.marksheet_no
                       FROM results r JOIN exams e ON e.id = r.exam_id JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN academic_sessions ses ON ses.id = r.academic_session_id
                       LEFT JOIN marksheets m ON m.result_id = r.id AND m.status = 'issued'
                       WHERE r.student_id = ? ORDER BY r.semester_no, e.start_date, r.id", [$id]);
    $trend = [];
    foreach ($results as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_published'] = (bool) $r['is_published'];
        $r['backlog_count'] = (int) $r['backlog_count'];
        if ($r['sgpa'] !== null) {
            $trend[(int) $r['semester_no']] = ['semester' => (int) $r['semester_no'], 'sgpa' => (float) $r['sgpa'], 'cgpa' => $r['cgpa'] !== null ? (float) $r['cgpa'] : null];
        }
    }
    unset($r);
    $source = 'results';
    if (!$trend) {
        $source = 'academic';
        foreach (db_all('SELECT semester_no, sgpa, cgpa FROM student_academic WHERE student_id = ? AND sgpa IS NOT NULL ORDER BY semester_no', [$id]) as $h) {
            $trend[(int) $h['semester_no']] = ['semester' => (int) $h['semester_no'], 'sgpa' => (float) $h['sgpa'], 'cgpa' => $h['cgpa'] !== null ? (float) $h['cgpa'] : null];
        }
    }
    ksort($trend);
    $marks = [];
    $latest = $results ? end($results) : null;
    if ($latest) {
        $marks = db_all('SELECT sb.code, sb.name, sb.credits, em.internal_marks, em.external_marks, em.practical_marks, em.total_marks, em.max_marks, em.grade, em.grade_point, em.is_absent, em.is_pass
                         FROM exam_marks em JOIN subjects sb ON sb.id = em.subject_id WHERE em.exam_id = ? AND em.student_id = ? ORDER BY sb.code', [(int) $latest['exam_id'], $id]);
    }
    api_ok(['results' => $results, 'trend' => array_values($trend), 'trend_source' => $source, 'latest' => $latest ?: null, 'latest_marks' => $marks, 'gpa' => students_gpa($id), 'print_views' => stu_api_print_views()]);
});

route('GET', '/students/{id:\d+}/certificates', function ($p) {
    api_require('students', 'view');
    api_require('certificates', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $rows = db_all('SELECT c.id, c.certificate_no, c.type, c.title, c.purpose, c.issue_date, c.valid_until, c.status, c.revoked_reason, c.verified_count, u.name AS issued_by_name
                    FROM certificates c LEFT JOIN users u ON u.id = c.issued_by WHERE c.student_id = ? ORDER BY c.issue_date DESC, c.id DESC', [$id]);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['verified_count'] = (int) $r['verified_count'];
        $r['verify_url'] = absolute_url('verify-certificate/' . rawurlencode($r['certificate_no']));
    }
    unset($r);
    api_ok(['rows' => $rows, 'print_views' => stu_api_print_views()]);
});

route('GET', '/students/{id:\d+}/library', function ($p) {
    api_require('students', 'view');
    api_require('library', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $stats = students_library_stats($id);
    $rows = [];
    if ($stats['member']) {
        $rows = db_all("SELECT lt.id, b.title, b.isbn, bc.accession_no, lt.issue_date, lt.due_date, lt.return_date, lt.renew_count, lt.status, lt.fine_amount,
                               CASE WHEN lt.status = 'issued' AND lt.due_date < CURDATE() THEN DATEDIFF(CURDATE(), lt.due_date) ELSE 0 END AS days_overdue
                        FROM library_transactions lt JOIN books b ON b.id = lt.book_id JOIN book_copies bc ON bc.id = lt.book_copy_id
                        WHERE lt.member_id = ? ORDER BY lt.issue_date DESC, lt.id DESC LIMIT 100", [(int) $stats['member']['id']]);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['renew_count'] = (int) $r['renew_count'];
            $r['days_overdue'] = (int) $r['days_overdue'];
        }
        unset($r);
    }
    api_ok($stats + ['transactions' => $rows]);
});

route('GET', '/students/{id:\d+}/hostel', function ($p) {
    api_require('students', 'view');
    api_require('hostel', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $rows = db_all('SELECT ha.id, h.name AS hostel_name, h.type AS hostel_type, hr.room_no, hr.room_type, hr.floor_no, hb.bed_no, ses.name AS session_name, ha.allocated_on, ha.vacated_on,
                           ha.fee_amount, ha.status, ha.remarks, h.warden_name, h.warden_phone
                    FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id JOIN hostel_rooms hr ON hr.id = ha.room_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                    LEFT JOIN academic_sessions ses ON ses.id = ha.academic_session_id WHERE ha.student_id = ? ORDER BY ha.allocated_on DESC, ha.id DESC', [$id]);
    api_ok(['current' => students_hostel_current($id), 'rows' => $rows]);
});

route('GET', '/students/{id:\d+}/transport', function ($p) {
    api_require('students', 'view');
    api_require('transport', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $rows = db_all('SELECT ta.id, tr.name AS route_name, tr.code AS route_code, ts.name AS stop_name, ts.pickup_time, v.vehicle_no, ses.name AS session_name, ta.start_date, ta.end_date, ta.fee_amount, ta.status
                    FROM transport_allocations ta JOIN transport_routes tr ON tr.id = ta.route_id LEFT JOIN transport_stops ts ON ts.id = ta.stop_id LEFT JOIN vehicles v ON v.id = ta.vehicle_id
                    LEFT JOIN academic_sessions ses ON ses.id = ta.academic_session_id WHERE ta.student_id = ? ORDER BY ta.start_date DESC, ta.id DESC', [$id]);
    api_ok(['current' => students_transport_current($id), 'rows' => $rows]);
});

route('GET', '/students/{id:\d+}/placement', function ($p) {
    api_require('students', 'view');
    api_require('placement', 'view');
    $id = (int) $p['id'];
    stu_api_student($id);
    $apps = db_all('SELECT pa.id, d.title AS drive_title, d.job_role, d.drive_date, d.job_type, c.name AS company_name, pa.status, pa.current_round, pa.applied_at
                    FROM placement_applications pa JOIN placement_drives d ON d.id = pa.drive_id JOIN companies c ON c.id = d.company_id
                    WHERE pa.student_id = ? ORDER BY pa.applied_at DESC, pa.id DESC', [$id]);
    $offers = db_all('SELECT po.id, c.name AS company_name, po.job_role, po.package_lpa, po.offer_date, po.joining_date, po.location, po.status
                      FROM placement_offers po JOIN companies c ON c.id = po.company_id WHERE po.student_id = ? ORDER BY po.offer_date DESC, po.id DESC', [$id]);
    api_ok(['stats' => students_placement_stats($id), 'applications' => $apps, 'offers' => $offers]);
});

route('GET', '/students/{id:\d+}/communication', function ($p) {
    api_require('students', 'view');
    api_require('communication', 'view');
    $id = (int) $p['id'];
    $s = stu_api_student($id);
    [$page, $perPage] = api_pagination(15);
    $recipients = array_filter([strtolower((string) $s['email']), $s['mobile'], $s['whatsapp'], $s['guardian_phone'], $s['emergency_contact_phone']]);
    foreach (db_all('SELECT email, phone FROM student_parents WHERE student_id = ?', [$id]) as $pr) {
        $recipients[] = strtolower((string) $pr['email']);
        $recipients[] = $pr['phone'];
    }
    $recipients = array_values(array_unique(array_filter($recipients)));
    $variants = [];
    foreach ($recipients as $r) {
        $variants[] = $r;
        if (preg_match('/\d{10}$/', students_digits($r))) {
            $d = students_mobile_key($r);
            array_push($variants, $d, '+91' . $d, '+91 ' . $d, '91' . $d);
        }
    }
    $variants = array_values(array_unique($variants));
    $where = "(ml.related_type IN ('student', 'students') AND ml.related_id = ?)";
    $args = [$id];
    if ($variants) {
        $where .= ' OR ml.recipient IN (' . implode(',', array_fill(0, count($variants), '?')) . ')';
        $args = array_merge($args, $variants);
    }
    if (!empty($_GET['channel']) && in_array($_GET['channel'], ['email', 'sms', 'whatsapp'], true)) {
        $where = "($where) AND ml.channel = ?";
        $args[] = $_GET['channel'];
    }
    $total = (int) db_value("SELECT COUNT(*) FROM message_logs ml WHERE $where", $args);
    $pg = paginate($total, $page, $perPage);
    $rows = db_all("SELECT ml.id, ml.channel, ml.recipient, ml.subject, ml.body, ml.status, ml.error, ml.related_type, ml.sent_at, ml.created_at FROM message_logs ml
                    WHERE $where ORDER BY ml.created_at DESC, ml.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['excerpt'] = str_limit(trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $r['body'])))), 220);
        unset($r['body']);
    }
    unset($r);
    api_ok(['rows' => $rows, 'total' => $pg['total'], 'page' => $pg['page'], 'pages' => $pg['pages'], 'per_page' => $pg['per_page']]);
});

route('GET', '/students/{id:\d+}/activity', function ($p) {
    api_require('students', 'view');
    $id = (int) $p['id'];
    $s = stu_api_student($id);
    [$page, $perPage] = api_pagination(20);
    [$w, $a] = stu_api_activity_where($id, (string) $s['student_uid']);
    $total = (int) db_value("SELECT COUNT(*) FROM activity_logs al WHERE $w", $a);
    $pg = paginate($total, $page, $perPage);
    $rows = db_all("SELECT al.id, al.action, al.module, al.description, al.status, al.created_at, u.name AS user_name, u.avatar AS user_avatar
                    FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE $w ORDER BY al.created_at DESC, al.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $a);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);
    api_ok(['rows' => $rows, 'total' => $pg['total'], 'page' => $pg['page'], 'pages' => $pg['pages'], 'per_page' => $pg['per_page']]);
});
