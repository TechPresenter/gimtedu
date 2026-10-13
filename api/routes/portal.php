<?php
/**
 * Student & Faculty portal API (/api/portal/...). Served to the portal SPA at /portal/ - never to the admin SPA.
 * Every route is registered with 'auth' => false and protected by its own guard (portal_student_require() /
 * portal_faculty_require()), so a student session can never reach admin routes and vice versa.
 * Non-GET routes still require the X-CSRF-Token header (token from the session endpoints).
 *
 * Student
 *   GET  /portal/student/session                 boot payload {authenticated, student, account, app, csrf_token}
 *   POST /portal/student/login                   {identifier, password, remember}
 *   POST /portal/student/logout
 *   POST /portal/student/activate/verify         {student_uid, dob, contact}      first-time activation, step 1
 *   POST /portal/student/activate                {password, password_confirmation} step 2 (signs in)
 *   POST /portal/student/forgot                  {identifier}                      emails a reset link
 *   GET  /portal/student/reset/{selector}?token= link check
 *   POST /portal/student/reset                   {selector, token, password, password_confirmation}
 *   POST /portal/student/password                {current_password, password, password_confirmation}
 *   POST /portal/student/ping | /prefs {theme}
 *   GET  /portal/student/dashboard | /profile | /notices | /notices/{id}
 *
 * Faculty (users linked to a faculty profile; password change / reset / ping / prefs reuse /api/auth/*)
 *   GET  /portal/faculty/session
 *   POST /portal/faculty/login                   {identifier, password, remember}
 *   POST /portal/faculty/logout
 *   POST /portal/faculty/forgot                  {email}   link -> /portal/faculty/reset (validated by /api/auth/reset)
 *   GET  /portal/faculty/dashboard | /profile | /notices | /notices/{id}
 *
 * Area modules added by later units live in api/routes/portal/student.php and api/routes/portal/faculty.php
 * (required at the end of this file).
 */

require_once APP_ROOT . '/app/services/portal_auth.php';
require_once APP_ROOT . '/app/services/attendance.php';
require_once APP_ROOT . '/app/services/hr.php';

/** Sends a service result array ({ok, status, message, errors?, data?}) as the JSON envelope. */
function portal_respond(array $r, $data = null): void
{
    if (!$r['ok']) {
        api_error($r['message'], (int) ($r['status'] ?? 422), $r['errors'] ?? []);
    }
    api_ok($data ?? ($r['data'] ?? null), $r['message']);
}

function portal_require_login_input(): array
{
    $in = api_input();
    $identifier = trim((string) ($in['identifier'] ?? ''));
    $password = (string) ($in['password'] ?? '');
    $errors = [];
    if ($identifier === '') {
        $errors['identifier'] = 'This field is required.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    }
    if ($errors) {
        api_error('Please enter your sign-in details.', 422, $errors);
    }
    if (mb_strlen($identifier) > 190 || strlen($password) > 200) {
        api_error('Invalid sign-in details.', 422);
    }
    if (!rate_limit('portal-login:' . client_ip(), 40, 600)) {
        api_error('Too many sign-in attempts. Please wait a few minutes and try again.', 429);
    }
    return [$identifier, $password, !empty($in['remember'])];
}

/** Holiday / vacation covering a date (title) or null. */
function portal_holiday_on(string $date): ?string
{
    $t = db_value("SELECT title FROM holidays WHERE type IN ('holiday', 'vacation') AND holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ? ORDER BY holiday_date LIMIT 1", [$date, $date]);
    return $t !== null ? (string) $t : null;
}

function portal_day_info(string $date): array
{
    $dow = (int) date('N', strtotime($date));
    $holiday = portal_holiday_on($date);
    $off = in_array($dow, att_weekly_off(), true);
    return ['date' => $date, 'dow' => $dow, 'day' => date('l', strtotime($date)), 'holiday' => $holiday, 'weekly_off' => $off, 'working' => !$holiday && !$off];
}

/* ==================================================================
 * STUDENT - auth
 * ================================================================== */

route('GET', '/portal/student/session', function () {
    api_ok(portal_student_payload());
}, ['auth' => false]);

route('POST', '/portal/student/login', function () {
    [$identifier, $password, $remember] = portal_require_login_input();
    $r = portal_student_attempt($identifier, $password, $remember);
    if (!$r['ok']) {
        api_error($r['message'], $r['status']);
    }
    api_ok(portal_student_payload(), $r['message']);
}, ['auth' => false]);

route('POST', '/portal/student/logout', function () {
    portal_student_logout();
    api_ok(['csrf_token' => csrf_token()], 'You have been signed out.');
}, ['auth' => false]);

route('POST', '/portal/student/activate/verify', function () {
    $in = api_input();
    $r = portal_student_activation_verify((string) ($in['student_uid'] ?? ''), (string) ($in['dob'] ?? ''), (string) ($in['contact'] ?? ''));
    portal_respond($r);
}, ['auth' => false]);

route('POST', '/portal/student/activate', function () {
    $in = api_input();
    $r = portal_student_activate((string) ($in['password'] ?? ''), (string) ($in['password_confirmation'] ?? ''));
    if (!$r['ok']) {
        api_error($r['message'], $r['status'], $r['errors'] ?? []);
    }
    api_ok(portal_student_payload(), $r['message']);
}, ['auth' => false]);

route('POST', '/portal/student/forgot', function () {
    $identifier = trim((string) api_param('identifier', ''));
    if ($identifier === '' || mb_strlen($identifier) > 190) {
        api_error('Enter your Student ID or registered email.', 422, ['identifier' => 'Enter your Student ID or registered email.']);
    }
    if (!rate_limit('portal-forgot:' . client_ip(), 5, 900)) {
        api_error('Too many reset requests. Please try again in 15 minutes.', 429);
    }
    if (portal_student_enabled()) {
        portal_student_forgot($identifier);
    }
    api_ok(null, 'If we find a portal account with an email on file, we have sent a password reset link to it. The link is valid for 60 minutes.');
}, ['auth' => false]);

route('GET', '/portal/student/reset/{selector}', function ($p) {
    api_ok(['valid' => (bool) portal_student_reset_row((string) $p['selector'], (string) ($_GET['token'] ?? ''))]);
}, ['auth' => false]);

route('POST', '/portal/student/reset', function () {
    $in = api_input();
    if (!rate_limit('portal-reset:' . client_ip(), 10, 900)) {
        api_error('Too many attempts. Please try again in 15 minutes.', 429);
    }
    $row = portal_student_reset_row((string) ($in['selector'] ?? ''), (string) ($in['token'] ?? ''));
    if (!$row) {
        api_error('This password reset link is invalid or has expired. Please request a new one.', 410);
    }
    $password = (string) ($in['password'] ?? '');
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    if ($password !== (string) ($in['password_confirmation'] ?? '')) {
        api_error('Passwords do not match.', 422, ['password_confirmation' => 'Passwords do not match.']);
    }
    $extra = ['must_change_password' => 0];
    if (empty($row['activated_at'])) {
        $extra += ['activated_at' => date('Y-m-d H:i:s'), 'activation_method' => 'reset'];
    }
    portal_student_set_password((int) $row['id'], $password, $extra);
    portal_student_log((int) $row['student_id'], $row['username'], 'reset', 'Password reset via email link');
    log_activity('password_reset', 'portal', $row['student_id'], 'Student ' . $row['username'] . ' reset their portal password via email link');
    api_ok(null, 'Your password has been reset. You can now sign in.');
}, ['auth' => false]);

route('POST', '/portal/student/password', function () {
    $me = portal_student_require(true);
    $in = api_input();
    $acc = db_row('SELECT * FROM student_accounts WHERE id = ?', [$me['account_id']]);
    if (!password_verify((string) ($in['current_password'] ?? ''), (string) $acc['password_hash'])) {
        api_error('Your current password is incorrect.', 422, ['current_password' => 'Your current password is incorrect.']);
    }
    $password = (string) ($in['password'] ?? '');
    if ($err = password_policy_error($password)) {
        api_error($err, 422, ['password' => $err]);
    }
    if ($password !== (string) ($in['password_confirmation'] ?? '')) {
        api_error('Passwords do not match.', 422, ['password_confirmation' => 'Passwords do not match.']);
    }
    if (password_verify($password, (string) $acc['password_hash'])) {
        api_error('Choose a password different from your current one.', 422, ['password' => 'Choose a password different from your current one.']);
    }
    $extra = ['must_change_password' => 0];
    if (empty($acc['activated_at'])) {
        $extra += ['activated_at' => date('Y-m-d H:i:s'), 'activation_method' => $acc['activation_method'] ?: 'admin'];
    }
    $changedAt = portal_student_set_password((int) $acc['id'], $password, $extra);
    // keep THIS session signed in (other devices are signed out by the password-version check)
    $_SESSION[PORTAL_STUDENT_KEY]['pwd'] = $changedAt;
    unset($GLOBALS['__portal_student_ctx']); // the payload must reflect the updated account (must_change_password = 0)
    portal_student_log($me['student_id'], $acc['username'], 'reset', 'Password changed by student');
    log_activity('update', 'portal', $me['student_id'], 'Student ' . $acc['username'] . ' changed their portal password');
    api_ok(portal_student_payload(), 'Your password has been updated.');
}, ['auth' => false]);

route('POST', '/portal/student/ping', function () {
    $me = portal_student_require(true);
    api_ok(['expires_in' => $me['expires_in']]);
}, ['auth' => false]);

route('POST', '/portal/student/prefs', function () {
    $me = portal_student_require(true);
    $theme = (string) api_param('theme', '');
    if (!in_array($theme, ['light', 'dark', 'system'], true)) {
        api_error('Choose light, dark or system.', 422, ['theme' => 'Choose light, dark or system.']);
    }
    db_update('student_accounts', ['theme' => $theme], 'id = ?', [$me['account_id']]);
    api_ok(['theme' => $theme], 'Preference saved.');
}, ['auth' => false]);

/* ==================================================================
 * STUDENT - dashboard, profile, notices
 * ================================================================== */

function portal_student_scope(array $s): array
{
    return ['department_id' => $s['department_id'] ?? null, 'program_id' => $s['program_id'] ?? null, 'semester' => $s['current_semester'] ?? null];
}

/** Upcoming exam papers for a student (allocated to the exam, or the exam targets the student's program + semester). */
function portal_student_upcoming_exams(array $s, int $limit = 6): array
{
    $where = "e.status IN ('scheduled', 'marks_entry') AND sch.exam_date >= CURDATE()
              AND (EXISTS (SELECT 1 FROM exam_students es WHERE es.exam_id = e.id AND es.student_id = ?)
                   OR (NOT EXISTS (SELECT 1 FROM exam_students es2 WHERE es2.exam_id = e.id) AND e.program_id = ? AND (e.semester_no IS NULL OR e.semester_no = ?)))";
    $args = [(int) $s['id'], (int) $s['program_id'], (int) $s['current_semester']];
    $count = (int) db_value("SELECT COUNT(*) FROM exam_schedules sch JOIN exams e ON e.id = sch.exam_id WHERE $where", $args);
    $rows = db_all("SELECT sch.id, sch.exam_date, sch.start_time, sch.end_time, e.id AS exam_id, e.name AS exam_name, sb.code AS subject_code, sb.name AS subject, cr.name AS room
                    FROM exam_schedules sch JOIN exams e ON e.id = sch.exam_id JOIN subjects sb ON sb.id = sch.subject_id LEFT JOIN classrooms cr ON cr.id = sch.classroom_id
                    WHERE $where ORDER BY sch.exam_date, sch.start_time LIMIT " . (int) $limit, $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['exam_id'] = (int) $r['exam_id'];
        $r['days_left'] = (int) floor((strtotime($r['exam_date']) - strtotime(date('Y-m-d'))) / 86400);
    }
    return ['count' => $count, 'items' => $rows];
}

route('GET', '/portal/student/dashboard', function () {
    $me = portal_student_require();
    $s = $me['student'];
    $sid = $me['student_id'];
    $session = current_session();
    $att = students_attendance_stats($sid, current_session_id());
    $gpa = students_gpa($sid);
    $fees = students_fee_stats($sid);
    $exams = portal_student_upcoming_exams($s);
    $today = portal_day_info(date('Y-m-d'));

    $classes = [];
    if ($s['section_id'] && $today['working']) {
        $classes = db_all("SELECT t.id, t.subject_id, t.time_slot_id, t.type, ts.name AS slot, ts.start_time, ts.end_time, sb.code AS subject_code, sb.name AS subject,
                                  TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty, cr.code AS room_code, cr.name AS room
                           FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN subjects sb ON sb.id = t.subject_id
                           LEFT JOIN faculty f ON f.id = t.faculty_id LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                           WHERE t.section_id = ? AND t.day_of_week = ? AND t.status = 'published' AND t.academic_session_id = ? ORDER BY ts.start_time",
            [(int) $s['section_id'], $today['dow'], (int) ($session['id'] ?? 0)]);
        $marks = [];
        foreach (db_all("SELECT a.subject_id, a.time_slot_id, r.status FROM attendance a
                         JOIN attendance_records r ON r.attendance_id = a.id AND r.person_type = 'student' AND r.person_id = ?
                         WHERE a.type = 'student' AND a.attendance_date = CURDATE() AND a.section_id = ?", [$sid, (int) $s['section_id']]) as $m) {
            $marks[(int) $m['subject_id'] . '|' . (int) $m['time_slot_id']] = $m['status'];
            $marks[(int) $m['subject_id'] . '|0'] = $m['status'];
        }
        $now = date('H:i:s');
        foreach ($classes as &$c) {
            $c['id'] = (int) $c['id'];
            $c['attendance'] = $marks[(int) $c['subject_id'] . '|' . (int) $c['time_slot_id']] ?? ($marks[(int) $c['subject_id'] . '|0'] ?? null);
            $c['state'] = $now >= $c['end_time'] ? 'done' : ($now >= $c['start_time'] ? 'now' : 'upcoming');
            unset($c['subject_id'], $c['time_slot_id']);
        }
        unset($c);
    }
    $notices = portal_notices('student', portal_student_scope($s), 5);
    api_ok([
        'today' => $today + ['classes' => $classes],
        'session' => $session['name'] ?? null,
        'kpis' => [
            'attendance' => ['percent' => $att['percent'], 'threshold' => $att['threshold'], 'held' => $att['total'], 'attended' => $att['attended'],
                'present' => $att['present'], 'absent' => $att['absent'], 'late' => $att['late'], 'leave' => $att['leave'],
                'classes_needed' => $att['percent'] !== null && $att['percent'] < $att['threshold'] ? att_classes_needed((float) $att['attended'], (int) $att['total'], (float) $att['threshold']) : 0],
            'cgpa' => $gpa,
            'fees' => $fees,
            'exams' => ['upcoming' => $exams['count'], 'next' => $exams['items'][0] ?? null],
        ],
        'exams' => $exams['items'],
        'notices' => $notices['items'],
    ]);
}, ['auth' => false]);

route('GET', '/portal/student/profile', function () {
    $me = portal_student_require(true);
    $s = $me['student'];
    $acc = db_row('SELECT username, status, activated_at, activation_method, last_login_at, last_login_ip, login_count, password_changed_at, created_at FROM student_accounts WHERE id = ?', [$me['account_id']]);
    $logins = db_all("SELECT status, reason, ip_address, user_agent, created_at FROM student_login_logs WHERE student_id = ? AND status IN ('success', 'failed', 'locked', 'logout', 'activated', 'reset')
                      ORDER BY id DESC LIMIT 8", [$me['student_id']]);
    foreach ($logins as &$l) {
        $l['device'] = browser_label($l['user_agent']);
        unset($l['user_agent']);
    }
    unset($l);
    $keep = ['id', 'student_uid', 'admission_no', 'roll_no', 'enrollment_no', 'first_name', 'middle_name', 'last_name', 'full_name', 'photo', 'gender', 'dob', 'age', 'blood_group',
        'category', 'nationality', 'mobile', 'email', 'whatsapp', 'address', 'city', 'state', 'country', 'pincode', 'permanent_address', 'father_name', 'mother_name',
        'guardian_name', 'guardian_relation', 'guardian_phone', 'emergency_contact_name', 'emergency_contact_phone', 'program_full_name', 'program_name', 'program_code',
        'program_level', 'total_semesters', 'department_name', 'course_name', 'batch_name', 'section_name', 'session_name', 'current_semester', 'admission_date',
        'admission_type', 'is_hosteller', 'uses_transport', 'status', 'aadhaar_masked', 'valid_until'];
    $profile = array_intersect_key($s, array_flip($keep));
    $profile['photo'] = $s['photo'] ? upload_url($s['photo']) : null;
    api_ok(['profile' => $profile, 'parents' => students_parents($me['student_id']), 'account' => $acc, 'logins' => $logins]);
}, ['auth' => false]);

route('GET', '/portal/student/notices', function () {
    $me = portal_student_require(true);
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $res = portal_notices('student', portal_student_scope($me['student']), $limit, ($page - 1) * $limit, trim((string) ($_GET['q'] ?? '')), trim((string) ($_GET['category'] ?? '')));
    api_ok($res + ['page' => $page, 'per_page' => $limit]);
}, ['auth' => false]);

route('GET', '/portal/student/notices/{id:\d+}', function ($p) {
    $me = portal_student_require(true);
    $n = portal_notice_find('student', portal_student_scope($me['student']), (int) $p['id']);
    if (!$n) {
        api_error('Notice not found.', 404);
    }
    api_ok($n);
}, ['auth' => false]);

/* ==================================================================
 * FACULTY - auth (password change / reset / ping / prefs: /api/auth/*)
 * ================================================================== */

route('GET', '/portal/faculty/session', function () {
    api_ok(portal_faculty_payload());
}, ['auth' => false]);

route('POST', '/portal/faculty/login', function () {
    [$identifier, $password, $remember] = portal_require_login_input();
    $r = portal_faculty_attempt($identifier, $password, $remember);
    if (!$r['ok']) {
        api_error($r['message'], $r['status']);
    }
    api_ok(portal_faculty_payload(), $r['message']);
}, ['auth' => false]);

route('POST', '/portal/faculty/logout', function () {
    if (user_id()) {
        logout_user();
        session_start_secure();
    } else {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    unset($GLOBALS['__portal_faculty_ctx']);
    api_ok(['csrf_token' => csrf_token()], 'You have been signed out.');
}, ['auth' => false]);

route('POST', '/portal/faculty/forgot', function () {
    $email = strtolower(trim((string) api_param('email', '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        api_error('Enter a valid email address.', 422, ['email' => 'Enter your registered email address.']);
    }
    if (!rate_limit('forgot:' . client_ip(), 5, 900)) {
        api_error('Too many reset requests. Please try again in 15 minutes.', 429);
    }
    if (portal_faculty_enabled()) {
        portal_faculty_forgot($email);
    }
    api_ok(null, 'If a faculty account exists for that email, a password reset link has been sent. The link is valid for 60 minutes.');
}, ['auth' => false]);

/* ==================================================================
 * FACULTY - dashboard, profile, notices
 * ================================================================== */

/** Faculty timetable periods in the current session (all days). */
function portal_faculty_periods(int $facultyId): array
{
    return db_all("SELECT t.id, t.section_id, t.subject_id, t.time_slot_id, t.day_of_week, t.semester_no, t.type, ts.name AS slot, ts.start_time, ts.end_time,
                          sb.code AS subject_code, sb.name AS subject, p.short_name AS program, sc.name AS section, cr.code AS room_code, cr.name AS room
                   FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN subjects sb ON sb.id = t.subject_id JOIN programs p ON p.id = t.program_id
                   JOIN sections sc ON sc.id = t.section_id LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                   WHERE t.faculty_id = ? AND t.academic_session_id = ? AND t.status = 'published' ORDER BY t.day_of_week, ts.start_time",
        [$facultyId, (int) current_session_id()]);
}

/** Classes (timetable periods already over) in the last $days days without a student attendance sheet. */
function portal_faculty_pending_attendance(array $periods, int $days = 7): array
{
    if (!$periods) {
        return [];
    }
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $to = date('Y-m-d');
    $sections = array_values(array_unique(array_map(fn ($p) => (int) $p['section_id'], $periods)));
    $marked = [];
    $in = implode(',', array_fill(0, count($sections), '?'));
    foreach (db_all("SELECT attendance_date, section_id, subject_id, time_slot_id FROM attendance WHERE type = 'student' AND attendance_date BETWEEN ? AND ? AND section_id IN ($in)",
        array_merge([$from, $to], $sections)) as $a) {
        $marked[$a['attendance_date'] . '|' . (int) $a['section_id'] . '|' . (int) $a['subject_id'] . '|' . (int) $a['time_slot_id']] = true;
        $marked[$a['attendance_date'] . '|' . (int) $a['section_id'] . '|' . (int) $a['subject_id'] . '|0'] = true;
    }
    $byDay = [];
    foreach ($periods as $p) {
        $byDay[(int) $p['day_of_week']][] = $p;
    }
    $now = date('H:i:s');
    $pending = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $info = portal_day_info($d);
        if (!$info['working']) {
            continue;
        }
        foreach ($byDay[$info['dow']] ?? [] as $p) {
            if ($d === $to && $p['end_time'] > $now) {
                continue;
            }
            $k = $d . '|' . (int) $p['section_id'] . '|' . (int) $p['subject_id'];
            if (empty($marked[$k . '|' . (int) $p['time_slot_id']]) && empty($marked[$k . '|0'])) {
                $pending[] = ['date' => $d, 'slot' => $p['slot'], 'start_time' => $p['start_time'], 'subject_code' => $p['subject_code'], 'subject' => $p['subject'],
                    'program' => $p['program'], 'semester_no' => (int) $p['semester_no'], 'section' => $p['section'], 'section_id' => (int) $p['section_id'], 'subject_id' => (int) $p['subject_id']];
            }
        }
    }
    usort($pending, fn ($a, $b) => strcmp($b['date'] . $b['start_time'], $a['date'] . $a['start_time']));
    return $pending;
}

/** Exam papers in marks entry for subjects this faculty teaches, where marks are not yet submitted for every eligible student. */
function portal_faculty_pending_marks(int $facultyId): array
{
    $rows = db_all("SELECT e.id AS exam_id, e.name AS exam, e.end_date, es.subject_id, sb.code AS subject_code, sb.name AS subject,
                           (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = e.id AND x.is_eligible = 1) AS students,
                           (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = e.id AND m.subject_id = es.subject_id) AS entered,
                           (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = e.id AND m.subject_id = es.subject_id AND m.status IN ('submitted', 'verified', 'published')) AS submitted
                    FROM exams e JOIN exam_subjects es ON es.exam_id = e.id JOIN subjects sb ON sb.id = es.subject_id
                    WHERE e.status = 'marks_entry'
                      AND es.subject_id IN (SELECT fs.subject_id FROM faculty_subjects fs WHERE fs.faculty_id = ? AND (fs.academic_session_id IS NULL OR fs.academic_session_id = e.academic_session_id))
                    ORDER BY e.end_date, sb.code", [$facultyId]);
    $out = [];
    foreach ($rows as $r) {
        if ((int) $r['students'] > 0 && (int) $r['submitted'] >= (int) $r['students']) {
            continue;
        }
        $out[] = ['exam_id' => (int) $r['exam_id'], 'exam' => $r['exam'], 'subject_id' => (int) $r['subject_id'], 'subject_code' => $r['subject_code'], 'subject' => $r['subject'],
            'students' => (int) $r['students'], 'entered' => (int) $r['entered'], 'submitted' => (int) $r['submitted'], 'end_date' => $r['end_date']];
    }
    return $out;
}

route('GET', '/portal/faculty/dashboard', function () {
    $me = portal_faculty_require();
    $fid = $me['faculty_id'];
    $today = portal_day_info(date('Y-m-d'));
    $periods = portal_faculty_periods($fid);
    $classes = [];
    $todayPeriods = array_values(array_filter($periods, fn ($p) => (int) $p['day_of_week'] === $today['dow']));
    if ($today['working'] && $todayPeriods) {
        $sheets = [];
        $secIds = array_values(array_unique(array_map(fn ($p) => (int) $p['section_id'], $todayPeriods)));
        $in = implode(',', array_fill(0, count($secIds), '?'));
        foreach (db_all("SELECT section_id, subject_id, time_slot_id, total_count, present_count FROM attendance WHERE type = 'student' AND attendance_date = CURDATE() AND section_id IN ($in)", $secIds) as $a) {
            $sheets[(int) $a['section_id'] . '|' . (int) $a['subject_id'] . '|' . (int) $a['time_slot_id']] = $a;
            $sheets[(int) $a['section_id'] . '|' . (int) $a['subject_id'] . '|0'] = $a;
        }
        $now = date('H:i:s');
        foreach ($todayPeriods as $p) {
            $k = (int) $p['section_id'] . '|' . (int) $p['subject_id'];
            $sheet = $sheets[$k . '|' . (int) $p['time_slot_id']] ?? ($sheets[$k . '|0'] ?? null);
            $classes[] = ['id' => (int) $p['id'], 'slot' => $p['slot'], 'start_time' => $p['start_time'], 'end_time' => $p['end_time'], 'type' => $p['type'],
                'subject_code' => $p['subject_code'], 'subject' => $p['subject'], 'program' => $p['program'], 'semester_no' => (int) $p['semester_no'], 'section' => $p['section'],
                'room' => $p['room'], 'room_code' => $p['room_code'], 'section_id' => (int) $p['section_id'], 'subject_id' => (int) $p['subject_id'],
                'marked' => (bool) $sheet, 'present' => $sheet ? (int) $sheet['present_count'] : null, 'total' => $sheet ? (int) $sheet['total_count'] : null,
                'state' => $now >= $p['end_time'] ? 'done' : ($now >= $p['start_time'] ? 'now' : 'upcoming')];
        }
    }
    $pendingAtt = portal_faculty_pending_attendance($periods);
    $pendingMarks = portal_faculty_pending_marks($fid);
    $balance = hr_leave_balance('faculty', $fid);
    $special = db_column('SELECT code FROM leave_types WHERE gender IS NOT NULL'); // maternity / paternity are not part of the regular balance
    $limited = array_values(array_filter($balance, fn ($b) => !$b['unlimited'] && $b['is_paid'] && !in_array($b['code'], $special, true)));
    $available = array_sum(array_map(fn ($b) => (float) $b['available'], $limited));
    $pendingLeaves = (int) db_value("SELECT COUNT(*) FROM employee_leaves WHERE employee_type = 'faculty' AND employee_id = ? AND status = 'pending'", [$fid]);
    $load = db_row('SELECT COUNT(DISTINCT subject_id) AS subjects, COUNT(DISTINCT section_id) AS sections, COUNT(*) AS periods FROM timetables
                    WHERE faculty_id = ? AND academic_session_id = ? AND status = \'published\'', [$fid, (int) current_session_id()]) ?? [];
    $notices = portal_notices('faculty', ['department_id' => $me['faculty']['department_id']], 5);
    api_ok([
        'today' => $today + ['classes' => $classes],
        'session' => current_session()['name'] ?? null,
        'load' => ['subjects' => (int) ($load['subjects'] ?? 0), 'sections' => (int) ($load['sections'] ?? 0), 'periods_per_week' => (int) ($load['periods'] ?? 0)],
        'kpis' => [
            'today_classes' => ['count' => count($classes), 'done' => count(array_filter($classes, fn ($c) => $c['state'] === 'done')), 'next' => array_values(array_filter($classes, fn ($c) => $c['state'] !== 'done'))[0] ?? null],
            'pending_attendance' => ['count' => count($pendingAtt), 'days' => 7],
            'pending_marks' => ['count' => count($pendingMarks), 'students' => array_sum(array_map(fn ($m) => max(0, $m['students'] - $m['submitted']), $pendingMarks))],
            'leave' => ['available' => $available, 'pending' => $pendingLeaves,
                'types' => array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'available' => $b['available'], 'quota' => $b['quota'], 'used' => $b['used']], $limited)],
        ],
        'pending_attendance' => array_slice($pendingAtt, 0, 6),
        'pending_marks' => array_slice($pendingMarks, 0, 6),
        'notices' => $notices['items'],
    ]);
}, ['auth' => false]);

route('GET', '/portal/faculty/profile', function () {
    $me = portal_faculty_require(true);
    $f = $me['faculty'];
    $u = $me['user'];
    $keep = ['id', 'employee_id', 'title', 'first_name', 'last_name', 'full_name', 'gender', 'dob', 'designation', 'department_name', 'department_code', 'qualification',
        'specialization', 'experience_years', 'email', 'phone', 'alternate_phone', 'address', 'city', 'state', 'pincode', 'joining_date', 'employment_type', 'bio',
        'research_interests', 'publications_count', 'linkedin_url', 'status'];
    $profile = array_intersect_key($f, array_flip($keep));
    $profile['photo'] = $f['photo'] ? upload_url($f['photo']) : ($u['avatar'] ? upload_url($u['avatar']) : null);
    $profile['bank_account_masked'] = hr_mask($f['bank_account'] ?? null);
    $profile['pan_masked'] = hr_mask($f['pan_no'] ?? null, 4);
    $subjects = db_all("SELECT DISTINCT sb.code, sb.name, sb.semester_no, p.short_name AS program, sc.name AS section
                        FROM faculty_subjects fs JOIN subjects sb ON sb.id = fs.subject_id JOIN programs p ON p.id = sb.program_id LEFT JOIN sections sc ON sc.id = fs.section_id
                        WHERE fs.faculty_id = ? AND (fs.academic_session_id IS NULL OR fs.academic_session_id = ?) ORDER BY p.short_name, sb.semester_no, sb.code",
        [$me['faculty_id'], (int) current_session_id()]);
    $logins = db_all("SELECT status, reason, ip_address, user_agent, created_at FROM login_logs WHERE user_id = ? ORDER BY id DESC LIMIT 8", [$me['user_id']]);
    foreach ($logins as &$l) {
        $l['device'] = browser_label($l['user_agent']);
        unset($l['user_agent']);
    }
    unset($l);
    api_ok([
        'profile' => $profile,
        'subjects' => $subjects,
        'account' => ['username' => $u['username'], 'email' => $u['email'], 'roles' => $u['role_names'], 'last_login_at' => $u['last_login_at'], 'last_login_ip' => $u['last_login_ip'],
            'password_changed_at' => $u['password_changed_at'], 'created_at' => $u['created_at']],
        'logins' => $logins,
    ]);
}, ['auth' => false]);

route('GET', '/portal/faculty/notices', function () {
    $me = portal_faculty_require(true);
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 10)));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $res = portal_notices('faculty', ['department_id' => $me['faculty']['department_id']], $limit, ($page - 1) * $limit, trim((string) ($_GET['q'] ?? '')), trim((string) ($_GET['category'] ?? '')));
    api_ok($res + ['page' => $page, 'per_page' => $limit]);
}, ['auth' => false]);

route('GET', '/portal/faculty/notices/{id:\d+}', function ($p) {
    $me = portal_faculty_require(true);
    $n = portal_notice_find('faculty', ['department_id' => $me['faculty']['department_id']], (int) $p['id']);
    if (!$n) {
        api_error('Notice not found.', 404);
    }
    api_ok($n);
}, ['auth' => false]);

/* ==================================================================
 * Area modules (filled by later units)
 * ================================================================== */
foreach (['student', 'faculty'] as $__portalArea) {
    $__portalFile = __DIR__ . '/portal/' . $__portalArea . '.php';
    if (is_file($__portalFile)) {
        require $__portalFile;
    }
}
unset($__portalArea, $__portalFile);
