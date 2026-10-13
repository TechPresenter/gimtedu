<?php
/**
 * Student portal - data behind the student area screens (/api/portal/student/..., routes in
 * api/routes/portal/student.php). Signed-in context comes from portal_student_require().
 *
 * Every function takes the signed-in student's profile row ($s = $me['student']) and scopes EVERY query by $s['id'].
 * Ids that arrive from the request (exam, result, invoice, payment, drive, application, ticket, document ...) are
 * always re-checked against the student; the lookups return null when the row belongs to someone else, which the
 * routes answer with 404 (no IDOR, no "exists but not yours" leak).
 *
 * Print views (admit card, marksheet, fee invoice, fee receipt) are rendered here as A4 HTML with the shared
 * print_layout_start()/print_layout_end() (letterhead + Print/Back toolbar + QR codes; styles from assets/css/print.css,
 * whose Tailwind build scans app/**.php).
 */

require_once APP_ROOT . '/app/services/portal_auth.php';
require_once APP_ROOT . '/app/services/attendance.php';

/** Load domain services on demand (each request only pays for the services it uses). */
function ps_need(string ...$names): void
{
    foreach ($names as $n) {
        require_once APP_ROOT . '/app/services/' . $n . '.php';
    }
}

function ps_today(): string
{
    return date('Y-m-d');
}

/** Whole days from today to $date (negative = past). */
function ps_days_until(?string $date): ?int
{
    if (!$date) {
        return null;
    }
    return (int) floor((strtotime(substr((string) $date, 0, 10)) - strtotime(ps_today())) / 86400);
}

function ps_f($v): ?float
{
    return $v === null || $v === '' ? null : (float) $v;
}

function ps_i($v): ?int
{
    return $v === null || $v === '' ? null : (int) $v;
}

/** Faculty display name SQL ("Dr. Nandini Gupta"). */
function ps_faculty_name_sql(string $a = 'f'): string
{
    return "TRIM(CONCAT_WS(' ', $a.title, $a.first_name, $a.last_name))";
}

/** Only hand out URLs for files in the public uploads area (private files go through dedicated endpoints). */
function ps_public_file(?string $path): ?string
{
    $path = (string) $path;
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, 'storage/')) {
        return null;
    }
    return upload_url($path);
}

/* ==================================================================
 * Dashboard extras (GET /portal/student/overview)
 * ================================================================== */

/** Semester-wise SGPA / CGPA from published final results (latest attempt per semester). */
function ps_gpa_trend(int $sid): array
{
    $rows = db_all("SELECT r.semester_no, r.sgpa, r.cgpa, r.result_status, r.percentage
                    FROM results r JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id
                    WHERE r.student_id = ? AND r.is_published = 1 AND et.is_final = 1 AND r.result_status <> 'WITHHELD' AND (r.sgpa IS NOT NULL OR r.cgpa IS NOT NULL)
                    ORDER BY r.semester_no, ex.end_date, ex.id", [$sid]);
    $by = [];
    foreach ($rows as $r) {
        $by[(int) $r['semester_no']] = ['semester' => (int) $r['semester_no'], 'label' => 'Sem ' . (int) $r['semester_no'], 'sgpa' => ps_f($r['sgpa']), 'cgpa' => ps_f($r['cgpa']),
            'percentage' => ps_f($r['percentage']), 'result_status' => $r['result_status']];
    }
    ksort($by);
    return array_values($by);
}

/** Unpaid fee parts (installments, or whole invoices without installments) ordered by due date. */
function ps_fee_parts(int $sid): array
{
    $rows = db_all("SELECT fi.due_date, LEAST(fi.amount - fi.paid_amount, sf.balance_amount) AS due, sf.id AS invoice_id, sf.invoice_no, sf.title, fi.installment_no
                    FROM fee_installments fi JOIN student_fees sf ON sf.id = fi.student_fee_id
                    WHERE sf.student_id = ? AND sf.status NOT IN ('cancelled', 'paid', 'waived') AND sf.balance_amount > 0 AND fi.paid_amount < fi.amount
                    UNION ALL
                    SELECT sf.due_date, sf.balance_amount AS due, sf.id AS invoice_id, sf.invoice_no, sf.title, NULL AS installment_no
                    FROM student_fees sf
                    WHERE sf.student_id = ? AND sf.status NOT IN ('cancelled', 'paid', 'waived') AND sf.balance_amount > 0
                      AND NOT EXISTS (SELECT 1 FROM fee_installments x WHERE x.student_fee_id = sf.id)
                    ORDER BY due_date", [$sid, $sid]);
    $today = ps_today();
    $out = [];
    foreach ($rows as $r) {
        if ((float) $r['due'] <= 0) {
            continue;
        }
        $out[] = ['due_date' => $r['due_date'], 'amount' => round((float) $r['due'], 2), 'invoice_id' => (int) $r['invoice_id'], 'invoice_no' => $r['invoice_no'], 'title' => $r['title'],
            'installment_no' => ps_i($r['installment_no']), 'overdue' => $r['due_date'] !== null && $r['due_date'] < $today, 'days_left' => ps_days_until($r['due_date'])];
    }
    return $out;
}

function ps_overview(array $s): array
{
    ps_need('fees');
    $sid = (int) $s['id'];
    $parts = ps_fee_parts($sid);
    $overdue = array_values(array_filter($parts, fn ($p) => $p['overdue']));
    $upcoming = array_values(array_filter($parts, fn ($p) => !$p['overdue']));

    $lib = students_library_stats($sid);
    $loans = [];
    if ($lib['member']) {
        foreach (db_all("SELECT t.id, t.due_date, t.issue_date, b.title FROM library_transactions t JOIN books b ON b.id = t.book_id
                         WHERE t.member_id = ? AND t.status = 'issued' ORDER BY t.due_date LIMIT 5", [(int) $lib['member']['id']]) as $l) {
            $loans[] = ['id' => (int) $l['id'], 'title' => $l['title'], 'issue_date' => $l['issue_date'], 'due_date' => $l['due_date'], 'days_left' => ps_days_until($l['due_date'])];
        }
    }
    $unpaidFines = $lib['member'] ? (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM library_fines WHERE member_id = ? AND status = 'unpaid'", [(int) $lib['member']['id']]) : 0.0;

    $admit = db_all("SELECT e.id AS exam_id, e.name, e.start_date, e.end_date, x.hall_ticket_no, x.is_eligible, x.ineligibility_reason,
                            (SELECT COUNT(*) FROM exam_schedules sch WHERE sch.exam_id = e.id) AS papers
                     FROM exam_students x JOIN exams e ON e.id = x.exam_id
                     WHERE x.student_id = ? AND e.status IN ('scheduled', 'ongoing') AND (e.end_date IS NULL OR e.end_date >= CURDATE())
                     ORDER BY e.start_date LIMIT 4", [$sid]);
    $admit = array_map(fn ($a) => ['exam_id' => (int) $a['exam_id'], 'name' => $a['name'], 'start_date' => $a['start_date'], 'end_date' => $a['end_date'],
        'hall_ticket_no' => $a['hall_ticket_no'], 'eligible' => (bool) $a['is_eligible'], 'reason' => $a['is_eligible'] ? null : ($a['ineligibility_reason'] ?: 'Not eligible'),
        'available' => (bool) $a['is_eligible'] && (int) $a['papers'] > 0, 'days_left' => ps_days_until($a['start_date'])], $admit);

    $hostel = students_hostel_current($sid);
    $transport = students_transport_current($sid);
    return [
        'gpa_trend' => ps_gpa_trend($sid),
        'fees' => [
            'next_due' => $upcoming[0] ?? null,
            'overdue_amount' => round(array_sum(array_column($overdue, 'amount')), 2),
            'overdue_count' => count($overdue),
            'oldest_overdue' => $overdue[0]['due_date'] ?? null,
        ],
        'library' => ['member' => (bool) $lib['member'], 'issued' => $lib['issued'], 'overdue' => $lib['overdue'], 'unpaid_fines' => $unpaidFines, 'loans' => $loans],
        'admit_cards' => $admit,
        'hostel' => $hostel ? ['hostel' => $hostel['hostel_name'], 'room_no' => $hostel['room_no'], 'bed_no' => $hostel['bed_no']] : null,
        'transport' => $transport ? ['route' => $transport['route_name'], 'stop' => $transport['stop_name'], 'pickup_time' => $transport['pickup_time'], 'vehicle_no' => $transport['vehicle_no']] : null,
        'counts' => [
            'open_tickets' => (int) db_value("SELECT COUNT(*) FROM feedback WHERE student_id = ? AND submitted_by_type = 'student' AND status IN ('open', 'in_progress')", [$sid]),
            'pending_certificates' => (int) db_value("SELECT COUNT(*) FROM certificates WHERE student_id = ? AND status = 'pending'", [$sid]),
            'rejected_documents' => (int) db_value("SELECT COUNT(*) FROM student_documents WHERE student_id = ? AND status = 'rejected'", [$sid]),
            'open_hostel_complaints' => (int) db_value("SELECT COUNT(*) FROM hostel_complaints WHERE student_id = ? AND status IN ('open', 'in_progress')", [$sid]),
        ],
    ];
}

/* ==================================================================
 * Timetable (own section, current session, published only)
 * ================================================================== */

function ps_timetable(array $s): array
{
    ps_need('timetable');
    $session = current_session();
    $sessionId = (int) ($session['id'] ?? 0);
    $sectionId = (int) ($s['section_id'] ?? 0);
    $todayDow = (int) date('N');
    $monday = date('Y-m-d', strtotime('-' . ($todayDow - 1) . ' days'));
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $holidays = att_holiday_map($monday, $sunday);
    $off = att_weekly_off();
    $days = [];
    foreach (tt_days() as $d) {
        $date = date('Y-m-d', strtotime($monday . ' +' . ($d['no'] - 1) . ' days'));
        $h = $holidays[$date] ?? null;
        $closed = $h && in_array($h['type'], ['holiday', 'vacation'], true);
        $days[] = ['no' => (int) $d['no'], 'name' => $d['name'], 'short' => $d['short'], 'date' => $date, 'is_today' => (int) $d['no'] === $todayDow,
            'holiday' => $closed ? $h['title'] : null, 'event' => $h && !$closed ? $h['title'] : null, 'weekly_off' => in_array((int) $d['no'], $off, true)];
    }

    $entries = [];
    $drafts = 0;
    if ($sectionId && $sessionId) {
        $entries = db_all("SELECT t.id, t.day_of_week, t.time_slot_id, t.type, t.notes, sb.id AS subject_id, sb.code AS subject_code, sb.name AS subject, sb.type AS subject_type, sb.credits,
                                  f.id AS faculty_id, " . ps_faculty_name_sql() . " AS faculty, cr.code AS room_code, cr.name AS room
                           FROM timetables t JOIN subjects sb ON sb.id = t.subject_id LEFT JOIN faculty f ON f.id = t.faculty_id LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                           WHERE t.section_id = ? AND t.academic_session_id = ? AND t.status = 'published' ORDER BY t.day_of_week, t.time_slot_id", [$sectionId, $sessionId]);
        if (!$entries) {
            $drafts = (int) db_value("SELECT COUNT(*) FROM timetables WHERE section_id = ? AND academic_session_id = ? AND status <> 'published'", [$sectionId, $sessionId]);
        }
    }
    $used = [];
    $subjects = [];
    foreach ($entries as &$e) {
        $e = ['id' => (int) $e['id'], 'day' => (int) $e['day_of_week'], 'slot_id' => (int) $e['time_slot_id'], 'type' => $e['type'], 'notes' => $e['notes'],
            'subject_id' => (int) $e['subject_id'], 'subject_code' => $e['subject_code'], 'subject' => $e['subject'], 'subject_type' => $e['subject_type'],
            'faculty' => $e['faculty'] ?: null, 'room_code' => $e['room_code'], 'room' => $e['room']];
        $used[$e['slot_id']] = true;
        $sub = &$subjects[$e['subject_id']];
        if (!$sub) {
            $sub = ['id' => $e['subject_id'], 'code' => $e['subject_code'], 'name' => $e['subject'], 'type' => $e['subject_type'], 'faculty' => [], 'periods' => 0];
        }
        $sub['periods']++;
        if ($e['faculty'] && !in_array($e['faculty'], $sub['faculty'], true)) {
            $sub['faculty'][] = $e['faculty'];
        }
        unset($sub);
    }
    unset($e);
    // slots used by the section, plus breaks that fall between the first and last used period
    $all = tt_slots(true);
    $usedSlots = array_values(array_filter($all, fn ($sl) => isset($used[$sl['id']])));
    $first = $usedSlots ? min(array_column($usedSlots, 'start_time')) : null;
    $last = $usedSlots ? max(array_column($usedSlots, 'end_time')) : null;
    $slots = array_values(array_filter($all, fn ($sl) => isset($used[$sl['id']]) || ($sl['is_break'] && $sl['status'] === 'active' && $first && $sl['start_time'] >= $first && $sl['end_time'] <= $last)));
    usort($slots, fn ($a, $b) => strcmp($a['start_time'], $b['start_time']));
    $subjects = array_values($subjects);
    usort($subjects, fn ($a, $b) => strcmp($a['code'], $b['code']));

    $section = $sectionId ? db_row('SELECT sc.name, ' . ps_faculty_name_sql() . ' AS class_teacher, f.email AS class_teacher_email, cr.code AS home_room
                                    FROM sections sc LEFT JOIN faculty f ON f.id = sc.class_teacher_id LEFT JOIN classrooms cr ON cr.id = sc.classroom_id WHERE sc.id = ?', [$sectionId]) : null;
    return [
        'session' => $session['name'] ?? null,
        'section' => $section ? ['name' => $section['name'], 'class_teacher' => $section['class_teacher'] ?: null, 'class_teacher_email' => $section['class_teacher_email'],
            'home_room' => $section['home_room']] : null,
        'program' => $s['program_name'] ?? null, 'semester' => (int) ($s['current_semester'] ?? 0),
        'days' => $days, 'slots' => array_map(fn ($sl) => ['id' => $sl['id'], 'name' => $sl['name'], 'start_time' => $sl['start_time'], 'end_time' => $sl['end_time'], 'is_break' => $sl['is_break']], $slots),
        'entries' => $entries, 'subjects' => $subjects, 'today' => $todayDow, 'now' => date('H:i'), 'week_start' => $monday,
        'periods_per_week' => count($entries), 'unpublished' => $drafts > 0,
    ];
}

/* ==================================================================
 * Attendance (current session; monthly calendar)
 * ================================================================== */

function ps_attendance(array $s, string $month = ''): array
{
    $sid = (int) $s['id'];
    $session = current_session();
    $sessionId = (int) ($session['id'] ?? 0);
    $stats = students_attendance_stats($sid, $sessionId ?: null);
    $threshold = (float) $stats['threshold'];
    $pct = $stats['percent'];
    $needed = $pct !== null && $pct < $threshold ? att_classes_needed((float) $stats['attended'], (int) $stats['total'], $threshold) : 0;
    $canMiss = $pct !== null && $pct >= $threshold && $threshold > 0 ? max(0, (int) floor((float) $stats['attended'] * 100 / $threshold - (int) $stats['total'] + 1e-9)) : 0;

    $att = att_attended_expr();
    $sessWhere = $sessionId ? ' AND a.academic_session_id = ?' : '';
    $sessArgs = $sessionId ? [$sessionId] : [];
    $subjects = db_all("SELECT sb.id, sb.code, sb.name, COUNT(r.id) AS held, SUM(r.status = 'present') AS present, SUM(r.status = 'absent') AS absent, SUM(r.status = 'late') AS late,
                               SUM(r.status = 'leave') AS on_leave, SUM(r.status = 'half_day') AS half_day, SUM($att) AS attended, MAX(a.attendance_date) AS last_date
                        FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id JOIN subjects sb ON sb.id = a.subject_id
                        WHERE r.person_type = 'student' AND r.person_id = ? AND a.type = 'student'$sessWhere
                        GROUP BY sb.id, sb.code, sb.name ORDER BY sb.code", array_merge([$sid], $sessArgs));
    $teachers = [];
    if (!empty($s['section_id']) && $sessionId) {
        foreach (db_all("SELECT DISTINCT t.subject_id, " . ps_faculty_name_sql() . " AS faculty FROM timetables t JOIN faculty f ON f.id = t.faculty_id
                         WHERE t.section_id = ? AND t.academic_session_id = ? AND t.status = 'published'", [(int) $s['section_id'], $sessionId]) as $t) {
            $teachers[(int) $t['subject_id']][] = $t['faculty'];
        }
    }
    $subjects = array_map(function ($r) use ($threshold, $teachers) {
        $held = (int) $r['held'];
        $attended = (float) $r['attended'];
        $p = $held ? round($attended * 100 / $held, 1) : null;
        return ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'held' => $held, 'present' => (int) $r['present'], 'absent' => (int) $r['absent'],
            'late' => (int) $r['late'], 'leave' => (int) $r['on_leave'], 'half_day' => (int) $r['half_day'], 'attended' => $attended, 'percent' => $p,
            'needed' => $p !== null && $p < $threshold ? att_classes_needed($attended, $held, $threshold) : 0,
            'can_miss' => $p !== null && $p >= $threshold && $threshold > 0 ? max(0, (int) floor($attended * 100 / $threshold - $held + 1e-9)) : 0,
            'faculty' => implode(', ', array_unique($teachers[(int) $r['id']] ?? [])) ?: null, 'last_date' => $r['last_date']];
    }, $subjects);

    // Months of the session that can be browsed (session start .. this month)
    $start = !empty($session['start_date']) ? substr((string) $session['start_date'], 0, 7) : date('Y-m', strtotime('-5 months'));
    $end = date('Y-m');
    if (!empty($session['end_date']) && substr((string) $session['end_date'], 0, 7) < $end) {
        $end = substr((string) $session['end_date'], 0, 7);
    }
    $months = [];
    for ($m = $start; $m <= $end && count($months) < 24; $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
        $months[] = ['value' => $m, 'label' => date('M Y', strtotime($m . '-01'))];
    }
    if (!$months) {
        $months[] = ['value' => $end, 'label' => date('M Y', strtotime($end . '-01'))];
    }
    $values = array_column($months, 'value');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || !in_array($month, $values, true)) {
        $month = end($values);
    }
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $records = db_all("SELECT a.attendance_date, r.status, r.remarks, sb.code AS subject_code, sb.name AS subject, ts.name AS slot, ts.start_time
                       FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id LEFT JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN time_slots ts ON ts.id = a.time_slot_id
                       WHERE r.person_type = 'student' AND r.person_id = ? AND a.type = 'student' AND a.attendance_date BETWEEN ? AND ?
                       ORDER BY a.attendance_date, ts.start_time, sb.code", [$sid, $from, $to]);
    $byDate = [];
    foreach ($records as $r) {
        $byDate[$r['attendance_date']][] = ['status' => $r['status'], 'subject_code' => $r['subject_code'], 'subject' => $r['subject'], 'slot' => $r['slot'],
            'start_time' => $r['start_time'] ? substr($r['start_time'], 0, 5) : null, 'remarks' => $r['remarks']];
    }
    $hol = att_holiday_map($from, $to);
    $off = att_weekly_off();
    $today = ps_today();
    $lateCounts = att_late_counts();
    $days = [];
    $mHeld = 0;
    $mAttended = 0.0;
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $recs = $byDate[$d] ?? [];
        $c = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0];
        foreach ($recs as $r) {
            if (isset($c[$r['status']])) {
                $c[$r['status']]++;
            }
        }
        $n = count($recs);
        $mHeld += $n;
        $mAttended += $c['present'] + ($lateCounts ? $c['late'] : 0) + 0.5 * $c['half_day'];
        $h = $hol[$d] ?? null;
        if ($n) {
            $status = $c['absent'] === $n ? 'absent' : ($c['leave'] === $n ? 'leave' : ($c['present'] + $c['late'] === $n ? ($c['late'] && !$c['present'] ? 'late' : 'present') : 'partial'));
        } elseif ($h && in_array($h['type'], ['holiday', 'vacation'], true)) {
            $status = 'holiday';
        } elseif (in_array((int) date('N', strtotime($d)), $off, true)) {
            $status = 'off';
        } elseif ($d > $today) {
            $status = 'future';
        } else {
            $status = 'none';
        }
        $days[] = ['date' => $d, 'status' => $status, 'counts' => $c, 'total' => $n, 'holiday' => $h['title'] ?? null, 'records' => $recs];
    }
    $trend = db_all("SELECT DATE_FORMAT(a.attendance_date, '%Y-%m') AS month, COUNT(r.id) AS held, " . att_pct_expr() . " AS percent
                     FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id
                     WHERE r.person_type = 'student' AND r.person_id = ? AND a.type = 'student'$sessWhere
                     GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m') ORDER BY month", array_merge([$sid], $sessArgs));
    $absences = db_all("SELECT a.attendance_date, r.status, sb.code AS subject_code, sb.name AS subject, ts.start_time
                        FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id LEFT JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN time_slots ts ON ts.id = a.time_slot_id
                        WHERE r.person_type = 'student' AND r.person_id = ? AND a.type = 'student' AND r.status = 'absent'$sessWhere
                        ORDER BY a.attendance_date DESC, ts.start_time DESC LIMIT 8", array_merge([$sid], $sessArgs));
    return [
        'session' => $session['name'] ?? null,
        'threshold' => $threshold,
        'late_counts_as_present' => $lateCounts,
        'overall' => ['percent' => $pct, 'held' => (int) $stats['total'], 'attended' => (float) $stats['attended'], 'present' => $stats['present'], 'absent' => $stats['absent'],
            'late' => $stats['late'], 'leave' => $stats['leave'], 'half_day' => $stats['half_day'], 'needed' => $needed, 'can_miss' => $canMiss],
        'subjects' => $subjects,
        'month' => ['value' => $month, 'label' => date('F Y', strtotime($from)), 'held' => $mHeld, 'attended' => $mAttended,
            'percent' => $mHeld ? round($mAttended * 100 / $mHeld, 1) : null, 'days' => $days, 'first_dow' => (int) date('N', strtotime($from))],
        'months' => $months,
        'trend' => array_map(fn ($r) => ['month' => $r['month'], 'label' => date('M', strtotime($r['month'] . '-01')), 'held' => (int) $r['held'], 'percent' => ps_f($r['percent'])], $trend),
        'absences' => array_map(fn ($r) => ['date' => $r['attendance_date'], 'subject_code' => $r['subject_code'], 'subject' => $r['subject'],
            'start_time' => $r['start_time'] ? substr($r['start_time'], 0, 5) : null], $absences),
    ];
}

/* ==================================================================
 * Examinations (date sheets + admit cards)
 * ================================================================== */

function ps_exam_rows(array $s): array
{
    ps_need('exams');
    $sid = (int) $s['id'];
    return db_all("SELECT e.id, e.name, e.semester_no, e.start_date, e.end_date, e.result_date, e.status, " . exam_display_status_sql('e') . " AS display_status, e.instructions,
                          et.name AS type_name, et.is_final, ss.name AS session_name, x.id AS alloc_id, x.is_eligible, x.ineligibility_reason, x.attendance_percent, x.fee_due,
                          x.hall_ticket_no, x.seat_no, cr.code AS room_code, cr.name AS room_name,
                          (SELECT r.id FROM results r WHERE r.exam_id = e.id AND r.student_id = ? AND r.is_published = 1 LIMIT 1) AS result_id
                   FROM exams e JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN academic_sessions ss ON ss.id = e.academic_session_id
                   LEFT JOIN exam_students x ON x.exam_id = e.id AND x.student_id = ? LEFT JOIN classrooms cr ON cr.id = x.classroom_id
                   WHERE e.status NOT IN ('draft', 'cancelled')
                     AND (x.id IS NOT NULL OR (e.program_id = ? AND (e.semester_no IS NULL OR e.semester_no = ?) AND NOT EXISTS (SELECT 1 FROM exam_students y WHERE y.exam_id = e.id)))
                   ORDER BY e.start_date DESC, e.id DESC LIMIT 60", [$sid, $sid, (int) $s['program_id'], (int) $s['current_semester']]);
}

function ps_exams(array $s): array
{
    $rows = ps_exam_rows($s);
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    $papers = [];
    if ($ids) {
        foreach (db_all('SELECT sch.id, sch.exam_id, sch.exam_date, sch.start_time, sch.end_time, sb.code, sb.name, sb.type, cr.code AS room_code, cr.name AS room
                         FROM exam_schedules sch JOIN subjects sb ON sb.id = sch.subject_id LEFT JOIN classrooms cr ON cr.id = sch.classroom_id
                         WHERE sch.exam_id IN (' . exam_in($ids) . ') ORDER BY sch.exam_date, sch.start_time, sb.code') as $p) {
            $papers[(int) $p['exam_id']][] = ['id' => (int) $p['id'], 'date' => $p['exam_date'], 'start_time' => $p['start_time'] ? substr($p['start_time'], 0, 5) : null,
                'end_time' => $p['end_time'] ? substr($p['end_time'], 0, 5) : null, 'code' => $p['code'], 'name' => $p['name'], 'type' => $p['type'],
                'room_code' => $p['room_code'], 'room' => $p['room'], 'days_left' => ps_days_until($p['exam_date'])];
        }
    }
    $today = ps_today();
    $upcoming = [];
    $past = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $list = $papers[$id] ?? [];
        $phase = $r['start_date'] && $r['start_date'] > $today ? 'upcoming' : ($r['end_date'] && $r['end_date'] < $today ? 'completed' : 'ongoing');
        if (!$r['start_date'] && !$r['end_date']) {
            $phase = 'upcoming';
        }
        $allocated = $r['alloc_id'] !== null;
        $eligible = $allocated && (int) $r['is_eligible'] === 1;
        $admitReason = null;
        if (!$allocated) {
            $admitReason = 'Admit cards are issued once the examination cell allocates candidates for this exam.';
        } elseif (!$eligible) {
            $admitReason = $r['ineligibility_reason'] ?: 'You are not eligible for this examination. Contact the examination cell.';
        } elseif (!$list) {
            $admitReason = 'The date sheet has not been published yet.';
        }
        $item = [
            'id' => $id, 'name' => $r['name'], 'type' => $r['type_name'], 'is_final' => (bool) $r['is_final'], 'semester' => ps_i($r['semester_no']), 'session' => $r['session_name'],
            'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'result_date' => $r['result_date'], 'status' => $r['display_status'], 'phase' => $phase,
            'days_left' => ps_days_until($r['start_date']), 'instructions' => $r['instructions'] ? trim((string) $r['instructions']) : null,
            'allocation' => $allocated ? ['eligible' => $eligible, 'reason' => $eligible ? null : ($r['ineligibility_reason'] ?: null), 'hall_ticket_no' => $r['hall_ticket_no'],
                'seat_no' => $r['seat_no'], 'room' => $r['room_name'] ?: $r['room_code'], 'attendance_percent' => ps_f($r['attendance_percent']), 'fee_due' => ps_f($r['fee_due'])] : null,
            'admit_card' => ['available' => $admitReason === null && $phase !== 'completed', 'reason' => $phase === 'completed' && $admitReason === null ? 'The examination is over.' : $admitReason],
            'result_id' => ps_i($r['result_id']), 'papers' => $list,
        ];
        if ($phase === 'completed') {
            $past[] = $item;
        } else {
            $upcoming[] = $item;
        }
    }
    usort($upcoming, fn ($a, $b) => strcmp((string) $a['start_date'], (string) $b['start_date']));
    $next = null;
    foreach ($upcoming as $e) {
        foreach ($e['papers'] as $p) {
            if ($p['date'] >= $today && (!$next || $p['date'] . $p['start_time'] < $next['date'] . $next['start_time'])) {
                $next = $p + ['exam_id' => $e['id'], 'exam' => $e['name']];
            }
        }
    }
    return ['upcoming' => $upcoming, 'past' => array_slice($past, 0, 20), 'next_paper' => $next, 'today' => $today];
}

/** Admit card data when the student is an eligible candidate of a scheduled exam (null otherwise). */
function ps_admit_card_data(array $s, int $examId): ?array
{
    ps_need('exams');
    $e = exam_find($examId);
    if (!$e || in_array($e['status'], ['draft', 'cancelled'], true)) {
        return null;
    }
    $d = exam_admit_cards($e, [(int) $s['id']]);
    if (!$d['students'] || !$d['papers']) {
        return null;
    }
    return ['exam' => $e, 'student' => $d['students'][0], 'papers' => $d['papers']];
}

/* ==================================================================
 * Results
 * ================================================================== */

function ps_results(array $s): array
{
    ps_need('exams');
    $sid = (int) $s['id'];
    $rows = db_all("SELECT r.id, r.exam_id, ex.name AS exam_name, et.name AS type_name, et.is_final, r.semester_no, ss.name AS session_name, r.total_marks, r.max_marks,
                           r.percentage, r.sgpa, r.cgpa, r.credits_registered, r.credits_earned, r.result_status, r.backlog_count, r.class_rank, r.division, ex.result_date,
                           r.published_at, ex.end_date, m.id AS marksheet_id, m.marksheet_no, m.issued_at AS marksheet_issued_at, m.status AS marksheet_status
                    FROM results r JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id
                    LEFT JOIN marksheets m ON m.result_id = r.id
                    WHERE r.student_id = ? AND r.is_published = 1 ORDER BY ex.end_date DESC, ex.id DESC", [$sid]);
    $items = array_map('ps_result_public', $rows);
    $finals = array_values(array_filter($items, fn ($r) => $r['is_final'] && !$r['withheld']));
    $latest = $finals[0] ?? null;
    $credits = 0.0;
    $bySem = [];
    foreach (array_reverse($finals) as $r) {
        $bySem[(int) $r['semester']] = $r;
    }
    foreach ($bySem as $r) {
        $credits += (float) ($r['credits_earned'] ?? 0);
    }
    $sgpas = array_values(array_filter(array_map(fn ($r) => $r['sgpa'], $bySem), fn ($v) => $v !== null));
    return [
        'summary' => [
            'cgpa' => $latest['cgpa'] ?? null, 'sgpa' => $latest['sgpa'] ?? null, 'semester' => $latest['semester'] ?? null, 'result_status' => $latest['result_status'] ?? null,
            'credits_earned' => round($credits, 1), 'backlogs' => (int) ($latest['backlog_count'] ?? 0), 'best_sgpa' => $sgpas ? max($sgpas) : null,
            'semesters_completed' => count($bySem), 'total_semesters' => (int) ($s['total_semesters'] ?? 0), 'division' => $latest['division'] ?? null,
        ],
        'trend' => ps_gpa_trend($sid),
        'results' => $items,
    ];
}

function ps_result_public(array $r): array
{
    $withheld = $r['result_status'] === 'WITHHELD';
    $hasSheet = !empty($r['marksheet_id']) && ($r['marksheet_status'] ?? '') === 'issued';
    return [
        'id' => (int) $r['id'], 'exam_id' => (int) $r['exam_id'], 'exam_name' => $r['exam_name'], 'type' => $r['type_name'], 'is_final' => (bool) $r['is_final'],
        'semester' => ps_i($r['semester_no']), 'session' => $r['session_name'], 'withheld' => $withheld,
        'total_marks' => $withheld ? null : ps_f($r['total_marks']), 'max_marks' => $withheld ? null : ps_f($r['max_marks']),
        'percentage' => $withheld ? null : ps_f($r['percentage']), 'sgpa' => $withheld ? null : ps_f($r['sgpa']), 'cgpa' => $withheld ? null : ps_f($r['cgpa']),
        'credits_registered' => $withheld ? null : ps_f($r['credits_registered']), 'credits_earned' => $withheld ? null : ps_f($r['credits_earned']),
        'result_status' => $r['result_status'], 'backlog_count' => $withheld ? 0 : (int) $r['backlog_count'], 'class_rank' => $withheld ? null : ps_i($r['class_rank']),
        'division' => $withheld ? null : $r['division'], 'result_date' => $r['result_date'] ?: ($r['published_at'] ? substr((string) $r['published_at'], 0, 10) : null),
        'marksheet' => $hasSheet ? ['no' => $r['marksheet_no'], 'issued_at' => $r['marksheet_issued_at']] : null,
        'printable' => !$withheld,
    ];
}

/** One published result of the student with subject marks (null when not the student's / not published). */
function ps_result_detail(array $s, int $resultId): ?array
{
    ps_need('exams');
    $r = result_find($resultId);
    if (!$r || (int) $r['student_id'] !== (int) $s['id'] || !(int) $r['is_published']) {
        return null;
    }
    $row = db_row("SELECT r.id, r.exam_id, ex.name AS exam_name, et.name AS type_name, et.is_final, r.semester_no, ss.name AS session_name, r.total_marks, r.max_marks,
                          r.percentage, r.sgpa, r.cgpa, r.credits_registered, r.credits_earned, r.result_status, r.backlog_count, r.class_rank, r.division, ex.result_date,
                          r.published_at, ex.end_date, m.id AS marksheet_id, m.marksheet_no, m.issued_at AS marksheet_issued_at, m.status AS marksheet_status
                   FROM results r JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id
                   LEFT JOIN marksheets m ON m.result_id = r.id WHERE r.id = ?", [$resultId]);
    $out = ps_result_public($row);
    $out['subjects'] = [];
    if (!$out['withheld']) {
        foreach (result_subject_marks($r) as $m) {
            $absent = (int) $m['is_absent'] === 1;
            $out['subjects'][] = ['code' => $m['code'], 'name' => $m['name'], 'type' => $m['type'], 'credits' => ps_f($m['credits']),
                'internal' => ps_f($m['internal_marks']), 'max_internal' => (int) $m['max_internal'], 'external' => ps_f($m['external_marks']), 'max_external' => (int) $m['max_external'],
                'practical' => ps_f($m['practical_marks']), 'max_practical' => (int) $m['max_practical'], 'total' => ps_f($m['total_marks']), 'max_total' => (int) $m['max_total'],
                'pass_marks' => (int) $m['pass_marks'], 'grade' => $m['grade'], 'grade_point' => ps_f($m['grade_point']), 'absent' => $absent,
                'pass' => $m['is_pass'] === null ? null : (bool) $m['is_pass']];
        }
    }
    $out['grade_scale'] = array_map(fn ($g) => ['grade' => $g['grade'], 'min' => (float) $g['min'], 'max' => (float) $g['max'], 'grade_point' => (float) $g['grade_point']], grade_scale());
    return $out;
}

/** Marksheet (issued) or provisional statement data for a published, non-withheld result of the student. */
function ps_marksheet_data(array $s, int $resultId): ?array
{
    ps_need('exams');
    $r = result_find($resultId);
    if (!$r || (int) $r['student_id'] !== (int) $s['id'] || !(int) $r['is_published'] || $r['result_status'] === 'WITHHELD') {
        return null;
    }
    if (!empty($r['marksheet_id']) && $r['marksheet_status'] === 'issued') {
        return marksheet_print_data((int) $r['marksheet_id']);
    }
    $student = db_row('SELECT s.*, b.name AS batch_name, c.name AS course_name FROM students s LEFT JOIN batches b ON b.id = s.batch_id LEFT JOIN courses c ON c.id = s.course_id WHERE s.id = ?', [(int) $s['id']]);
    return ['marksheet' => null, 'result' => $r, 'student' => $student, 'subjects' => result_subject_marks($r), 'verify_url' => null,
        'history' => (int) $r['is_final'] ? array_values(array_filter(result_student_history((int) $s['id']), fn ($h) => (int) $h['is_final'] === 1)) : []];
}

/* ==================================================================
 * Fees
 * ================================================================== */

/** Bank / UPI details for direct payments (Settings › Payment; empty values are left out). */
function ps_payment_options(): array
{
    ps_need('fees');
    $gw = fees_gateway_info();
    $modes = array_filter(array_map('trim', explode(',', (string) setting('enabled_payment_modes', 'cash'))));
    $bank = array_filter([
        'account_name' => (string) setting('bank_account_name', ''), 'bank_name' => (string) setting('bank_name', ''), 'account_no' => (string) setting('bank_account_no', ''),
        'ifsc' => (string) setting('bank_ifsc', ''), 'branch' => (string) setting('bank_branch', ''),
    ], fn ($v) => trim($v) !== '');
    $upi = trim((string) setting('upi_id', ''));
    return [
        'gateway' => ['provider' => $gw['label'], 'configured' => (bool) $gw['configured'], 'mode' => $gw['mode'], 'online_enabled' => in_array('online', $modes, true),
            // Online collection from the portal is not wired to a payment gateway yet - say so honestly.
            'available' => false],
        'modes' => array_values(array_map(fn ($m) => ['key' => $m, 'label' => fees_mode_label($m)], $modes)),
        'bank' => $bank ?: null, 'bank_complete' => isset($bank['account_no'], $bank['ifsc']),
        'upi_id' => $upi !== '' ? $upi : null,
        'office' => ['hours' => (string) setting('office_hours', 'Mon - Sat, 9:00 AM - 6:00 PM'), 'phone' => (string) setting('accounts_phone', setting('phone', '')),
            'email' => (string) setting('accounts_email', setting('email', '')), 'address' => (string) setting('address', '')],
        'late_fee_per_day' => (float) setting('late_fee_per_day', 0),
    ];
}

function ps_fees(array $s): array
{
    ps_need('fees');
    $sid = (int) $s['id'];
    $totals = fees_student_totals($sid);
    $invoices = db_all("SELECT t.id, t.invoice_no, t.title, t.semester_no, t.gross_amount, t.discount_amount, t.scholarship_amount, t.fine_amount, t.net_amount, t.paid_amount,
                               t.balance_amount, t.due_date, t.status, t.fee_structure_id, t.late_fee_upto, t.created_at, t.cancelled_reason, ses.name AS session_name,
                               " . fees_overdue_amount_sql('t') . " AS overdue_amount
                        FROM student_fees t LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id
                        WHERE t.student_id = ?
                        ORDER BY (t.status NOT IN ('cancelled', 'waived', 'paid') AND t.balance_amount > 0) DESC, t.due_date DESC, t.id DESC", [$sid]);
    $ids = array_map(fn ($i) => (int) $i['id'], $invoices);
    $inst = [];
    $items = [];
    if ($ids) {
        $in = implode(',', $ids);
        foreach (db_all("SELECT student_fee_id, installment_no, amount, due_date, paid_amount, status FROM fee_installments WHERE student_fee_id IN ($in) ORDER BY installment_no") as $r) {
            $inst[(int) $r['student_fee_id']][] = ['no' => (int) $r['installment_no'], 'amount' => (float) $r['amount'], 'paid' => (float) $r['paid_amount'], 'due_date' => $r['due_date'],
                'status' => (float) $r['paid_amount'] >= (float) $r['amount'] ? 'paid' : ($r['due_date'] < ps_today() ? 'overdue' : ((float) $r['paid_amount'] > 0 ? 'partial' : 'pending'))];
        }
        foreach (db_all("SELECT sfi.student_fee_id, fh.name, sfi.amount FROM student_fee_items sfi JOIN fee_heads fh ON fh.id = sfi.fee_head_id WHERE sfi.student_fee_id IN ($in) ORDER BY fh.sort_order, fh.name") as $r) {
            $items[(int) $r['student_fee_id']][] = ['head' => $r['name'], 'amount' => (float) $r['amount']];
        }
    }
    $invoices = array_map(function ($i) use ($inst, $items) {
        $id = (int) $i['id'];
        $open = !in_array($i['status'], ['cancelled', 'waived', 'paid'], true) && (float) $i['balance_amount'] > 0;
        return ['id' => $id, 'invoice_no' => $i['invoice_no'], 'title' => $i['title'], 'semester' => ps_i($i['semester_no']), 'session' => $i['session_name'],
            'gross' => (float) $i['gross_amount'], 'discount' => (float) $i['discount_amount'], 'scholarship' => (float) $i['scholarship_amount'], 'fine' => (float) $i['fine_amount'],
            'net' => (float) $i['net_amount'], 'paid' => (float) $i['paid_amount'], 'balance' => max(0, (float) $i['balance_amount']), 'overdue' => (float) $i['overdue_amount'],
            'due_date' => $i['due_date'], 'days_left' => $open ? ps_days_until($i['due_date']) : null, 'status' => $i['status'], 'open' => $open,
            'late_fee_due' => $open ? fees_late_fee_due($i) : 0.0, 'created_at' => substr((string) $i['created_at'], 0, 10),
            'cancelled_reason' => $i['status'] === 'cancelled' ? $i['cancelled_reason'] : null, 'installments' => $inst[$id] ?? [], 'items' => $items[$id] ?? []];
    }, $invoices);

    $payments = db_all("SELECT p.id, p.receipt_no, p.amount, p.fine_amount, p.amount + p.fine_amount AS total, p.payment_date, p.mode, p.reference_no, p.status, p.purpose,
                               p.cancelled_reason, (SELECT GROUP_CONCAT(sf.invoice_no ORDER BY sf.id SEPARATOR ', ') FROM payment_allocations pa JOIN student_fees sf ON sf.id = pa.student_fee_id WHERE pa.payment_id = p.id) AS invoices,
                               (SELECT sf2.invoice_no FROM student_fees sf2 WHERE sf2.id = p.student_fee_id) AS invoice_no
                        FROM payments p WHERE p.student_id = ? ORDER BY p.payment_date DESC, p.id DESC", [$sid]);
    $payments = array_map(fn ($p) => ['id' => (int) $p['id'], 'receipt_no' => $p['receipt_no'], 'amount' => (float) $p['amount'], 'fine' => (float) $p['fine_amount'],
        'total' => (float) $p['total'], 'date' => $p['payment_date'], 'mode' => $p['mode'], 'mode_label' => fees_mode_label($p['mode']), 'reference_no' => $p['reference_no'],
        'status' => $p['status'], 'purpose' => $p['purpose'], 'invoices' => $p['invoices'] ?: $p['invoice_no'], 'void_reason' => $p['status'] === 'cancelled' ? $p['cancelled_reason'] : null,
        'printable' => in_array($p['status'], ['success', 'refunded', 'cancelled'], true)], $payments);

    $concessions = db_all("SELECT fc.type, fc.amount, fc.reason, fc.created_at, sc.name AS scholarship, sf.invoice_no FROM fee_concessions fc
                           LEFT JOIN scholarships sc ON sc.id = fc.scholarship_id LEFT JOIN student_fees sf ON sf.id = fc.student_fee_id
                           WHERE fc.student_id = ? AND fc.status = 'approved' ORDER BY fc.created_at DESC", [$sid]);
    $parts = ps_fee_parts($sid);
    $upcoming = array_values(array_filter($parts, fn ($p) => !$p['overdue']));
    return [
        'totals' => ['invoices' => (int) ($totals['invoices'] ?? 0), 'net' => (float) ($totals['net'] ?? 0), 'paid' => (float) ($totals['paid'] ?? 0),
            'balance' => max(0, (float) ($totals['balance'] ?? 0)), 'overdue' => (float) ($totals['overdue'] ?? 0), 'concessions' => (float) ($totals['concessions'] ?? 0),
            'fine' => (float) ($totals['fine'] ?? 0)],
        'next_due' => $upcoming[0] ?? null,
        'schedule' => array_slice($parts, 0, 12),
        'invoices' => $invoices,
        'payments' => $payments,
        'concessions' => array_map(fn ($c) => ['name' => $c['scholarship'] ?: label_from_key((string) $c['type']), 'type' => $c['type'], 'amount' => (float) $c['amount'],
            'reason' => $c['reason'], 'date' => substr((string) $c['created_at'], 0, 10), 'invoice_no' => $c['invoice_no']], $concessions),
        'pay' => ps_payment_options(),
    ];
}

/** Invoice detail (student's own invoice only). */
function ps_invoice(array $s, int $id): ?array
{
    ps_need('fees');
    if (!$id || (int) db_value('SELECT student_id FROM student_fees WHERE id = ?', [$id]) !== (int) $s['id']) {
        return null;
    }
    return fees_invoice_detail($id);
}

/** Payment detail (student's own payment only). */
function ps_payment(array $s, int $id): ?array
{
    ps_need('fees');
    if (!$id || (int) db_value('SELECT student_id FROM payments WHERE id = ?', [$id]) !== (int) $s['id']) {
        return null;
    }
    return fees_payment_detail($id);
}

/* ==================================================================
 * Library
 * ================================================================== */

function ps_library(array $s): array
{
    ps_need('library');
    $sid = (int) $s['id'];
    $m = db_row('SELECT id, membership_no, max_books, loan_days, valid_until, status, created_at FROM library_members WHERE student_id = ? ORDER BY id DESC LIMIT 1', [$sid]);
    $rules = lib_rules();
    $out = ['member' => null, 'rules' => ['fine_per_day' => (float) $rules['fine_per_day'], 'grace_days' => (int) $rules['grace_days'], 'max_renewals' => (int) $rules['max_renewals'],
        'loan_days' => (int) ($rules['types']['student']['loan_days'] ?? 14), 'max_books' => (int) ($rules['types']['student']['max_books'] ?? 3)],
        'current' => [], 'history' => [], 'fines' => [], 'stats' => ['issued' => 0, 'overdue' => 0, 'due_soon' => 0, 'total' => 0, 'unpaid_fines' => 0.0, 'accrued' => 0.0]];
    if (!$m) {
        return $out;
    }
    $mid = (int) $m['id'];
    $out['member'] = ['membership_no' => $m['membership_no'], 'max_books' => (int) $m['max_books'], 'loan_days' => (int) $m['loan_days'], 'valid_until' => $m['valid_until'],
        'status' => $m['status'], 'since' => substr((string) $m['created_at'], 0, 10), 'expired' => $m['valid_until'] !== null && $m['valid_until'] < ps_today()];
    $pub = function (array $r) use ($rules) {
        $l = lib_loan_public($r, $rules);
        return ['id' => $l['id'], 'title' => $l['title'], 'authors' => $l['authors'], 'isbn' => $l['isbn'], 'category' => $l['category'], 'accession_no' => $l['accession_no'],
            'cover' => ps_public_file($l['cover_image']), 'issue_date' => $l['issue_date'], 'due_date' => $l['due_date'], 'return_date' => $l['return_date'],
            'renew_count' => $l['renew_count'], 'status' => $l['status'], 'state' => $l['state'], 'days_overdue' => $l['days_overdue'], 'accrued_fine' => $l['accrued_fine'],
            'fine_amount' => $l['fine_amount'], 'days_left' => $l['status'] === 'issued' ? ps_days_until($l['due_date']) : null,
            'renewable' => $l['can_renew']];
    };
    $out['current'] = array_map($pub, db_all(lib_loan_sql() . " WHERE t.member_id = ? AND t.status = 'issued' ORDER BY t.due_date, t.id", [$mid]));
    $out['history'] = array_map($pub, db_all(lib_loan_sql() . " WHERE t.member_id = ? AND t.status <> 'issued' ORDER BY COALESCE(t.return_date, t.issue_date) DESC, t.id DESC LIMIT 60", [$mid]));
    $out['fines'] = array_map(fn ($f) => ['id' => (int) $f['id'], 'book' => $f['book_title'], 'amount' => (float) $f['amount'], 'reason' => $f['reason'],
        'days_overdue' => ps_i($f['days_overdue']), 'status' => $f['status'], 'paid_at' => $f['paid_at'], 'receipt_no' => $f['receipt_no'], 'waive_reason' => $f['waive_reason'],
        'date' => substr((string) $f['created_at'], 0, 10)],
        db_all('SELECT fn.*, b.title AS book_title FROM library_fines fn LEFT JOIN library_transactions t ON t.id = fn.transaction_id LEFT JOIN books b ON b.id = t.book_id
                WHERE fn.member_id = ? ORDER BY fn.created_at DESC, fn.id DESC LIMIT 40', [$mid]));
    $out['stats'] = [
        'issued' => count($out['current']), 'overdue' => count(array_filter($out['current'], fn ($l) => $l['state'] === 'overdue')),
        'due_soon' => count(array_filter($out['current'], fn ($l) => $l['days_left'] !== null && $l['days_left'] >= 0 && $l['days_left'] <= 3)),
        'total' => (int) db_value('SELECT COUNT(*) FROM library_transactions WHERE member_id = ?', [$mid]),
        'unpaid_fines' => round(array_sum(array_map(fn ($f) => $f['status'] === 'unpaid' ? $f['amount'] : 0, $out['fines'])), 2),
        'accrued' => round(array_sum(array_column($out['current'], 'accrued_fine')), 2),
    ];
    return $out;
}

/* ==================================================================
 * Hostel
 * ================================================================== */

function ps_hostel(array $s): array
{
    ps_need('hostel');
    $sid = (int) $s['id'];
    $a = db_row("SELECT ha.id, ha.allocation_no, ha.hostel_id, ha.room_id, ha.bed_id, ha.allocated_on, ha.valid_until, ha.fee_amount, ha.student_fee_id,
                        h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type, h.warden_name, h.warden_phone, h.email AS hostel_email, h.address AS hostel_address,
                        h.visiting_hours, h.amenities AS hostel_amenities, h.mess_fee, r.room_no, r.room_type, r.floor_no, r.capacity, r.is_ac, r.amenities AS room_amenities,
                        b.name AS block_name, hb.bed_no, ss.name AS session_name
                 FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id JOIN hostel_rooms r ON r.id = ha.room_id LEFT JOIN hostel_blocks b ON b.id = r.block_id
                 LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id LEFT JOIN academic_sessions ss ON ss.id = ha.academic_session_id
                 WHERE ha.student_id = ? AND ha.status = 'active' ORDER BY ha.id DESC LIMIT 1", [$sid]);
    // amenities are stored as a JSON array of keys (older rows: comma separated text)
    $split = function ($v, array $labels): array {
        $v = trim((string) $v);
        $list = str_starts_with($v, '[') ? (json_decode($v, true) ?: []) : preg_split('/\s*[,;\n]\s*/', $v);
        return array_values(array_filter(array_map(fn ($k) => $labels[trim((string) $k)] ?? (trim((string) $k) !== '' ? label_from_key(trim((string) $k)) : null), (array) $list)));
    };
    $allocation = null;
    $roommates = [];
    if ($a) {
        $allocation = ['allocation_no' => $a['allocation_no'], 'hostel' => $a['hostel_name'], 'hostel_code' => $a['hostel_code'], 'hostel_type' => $a['hostel_type'],
            'block' => $a['block_name'], 'room_no' => $a['room_no'], 'room_type' => $a['room_type'], 'floor' => hostel_floor_label($a['floor_no']), 'capacity' => (int) $a['capacity'],
            'is_ac' => (bool) $a['is_ac'], 'bed_no' => $a['bed_no'], 'allocated_on' => $a['allocated_on'], 'valid_until' => $a['valid_until'], 'session' => $a['session_name'],
            'fee_amount' => ps_f($a['fee_amount']), 'mess_fee' => ps_f($a['mess_fee']), 'warden' => $a['warden_name'], 'warden_phone' => $a['warden_phone'], 'email' => $a['hostel_email'],
            'address' => $a['hostel_address'], 'visiting_hours' => $a['visiting_hours'], 'amenities' => $split($a['hostel_amenities'], hostel_amenities()),
            'room_amenities' => $split($a['room_amenities'], hostel_room_amenities())];
        // Roommates: first names + program only (no contact details)
        $roommates = array_map(fn ($r) => ['first_name' => $r['first_name'], 'program' => $r['program'], 'semester' => (int) $r['current_semester'], 'bed_no' => $r['bed_no']],
            db_all("SELECT s.first_name, p.short_name AS program, s.current_semester, hb.bed_no FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id
                    JOIN programs p ON p.id = s.program_id LEFT JOIN hostel_beds hb ON hb.id = ha.bed_id
                    WHERE ha.room_id = ? AND ha.status = 'active' AND ha.student_id <> ? ORDER BY hb.bed_no, s.first_name", [(int) $a['room_id'], $sid]));
    }
    $complaints = db_all("SELECT c.id, c.complaint_no, c.category, c.subject, c.description, c.priority, c.status, c.assigned_to, c.resolution, c.resolved_at, c.closed_at, c.created_at,
                                 h.code AS hostel_code, r.room_no
                          FROM hostel_complaints c JOIN hostels h ON h.id = c.hostel_id LEFT JOIN hostel_rooms r ON r.id = c.room_id
                          WHERE c.student_id = ? ORDER BY c.created_at DESC, c.id DESC LIMIT 50", [$sid]);
    $history = db_all("SELECT ha.allocation_no, ha.allocated_on, ha.vacated_on, ha.status, h.name AS hostel_name, r.room_no FROM hostel_allocations ha JOIN hostels h ON h.id = ha.hostel_id
                       JOIN hostel_rooms r ON r.id = ha.room_id WHERE ha.student_id = ? AND ha.status <> 'active' ORDER BY ha.allocated_on DESC LIMIT 10", [$sid]);
    return [
        'allocation' => $allocation,
        'roommates' => $roommates,
        'complaints' => array_map(fn ($c) => ['id' => (int) $c['id'], 'complaint_no' => $c['complaint_no'], 'category' => $c['category'],
            'category_label' => hostel_complaint_categories()[$c['category']] ?? label_from_key((string) $c['category']), 'subject' => $c['subject'], 'description' => $c['description'],
            'priority' => $c['priority'], 'status' => $c['status'], 'assigned' => (bool) $c['assigned_to'], 'resolution' => $c['resolution'], 'resolved_at' => $c['resolved_at'],
            'closed_at' => $c['closed_at'], 'created_at' => $c['created_at'], 'location' => $c['hostel_code'] . ($c['room_no'] ? ' · Room ' . $c['room_no'] : '')], $complaints),
        'history' => $history,
        'categories' => hostel_complaint_categories(),
        'priorities' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'],
    ];
}

/** Raise a hostel complaint for the student's current room. Throws CrudValidationException / CrudException. */
function ps_hostel_complaint(array $s, array $in): array
{
    ps_need('hostel');
    $sid = (int) $s['id'];
    $stay = hostel_active_allocation($sid);
    if (!$stay) {
        throw new CrudException('You do not have an active hostel allotment, so a hostel complaint cannot be raised.');
    }
    $category = (string) ($in['category'] ?? '');
    $priority = (string) ($in['priority'] ?? 'medium');
    $subject = trim((string) ($in['subject'] ?? ''));
    $description = trim((string) ($in['description'] ?? ''));
    $errors = [];
    if (!isset(hostel_complaint_categories()[$category])) {
        $errors['category'] = 'Choose a category.';
    }
    if (!isset(hostel_complaint_priorities()[$priority])) {
        $errors['priority'] = 'Choose a priority.';
    }
    if (mb_strlen($subject) < 5) {
        $errors['subject'] = 'Describe the problem in a few words (at least 5 characters).';
    } elseif (mb_strlen($subject) > 190) {
        $errors['subject'] = 'Keep the subject under 190 characters.';
    }
    if (mb_strlen($description) < 10) {
        $errors['description'] = 'Add a few details (at least 10 characters) so the warden can act on it.';
    } elseif (mb_strlen($description) > 2000) {
        $errors['description'] = 'Keep the details under 2000 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $open = (int) db_value("SELECT COUNT(*) FROM hostel_complaints WHERE student_id = ? AND status IN ('open', 'in_progress')", [$sid]);
    if ($open >= 5) {
        throw new CrudException('You already have 5 open complaints. Please wait for them to be resolved before raising another.');
    }
    $no = hostel_next_complaint_no();
    $id = db_insert('hostel_complaints', ['complaint_no' => $no, 'hostel_id' => (int) $stay['hostel_id'], 'room_id' => (int) $stay['room_id'], 'student_id' => $sid,
        'category' => $category, 'subject' => $subject, 'description' => $description, 'priority' => $priority, 'status' => 'open']);
    $name = $s['full_name'] ?? trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
    log_activity('create', 'hostel', $id, 'Hostel complaint ' . $no . ' "' . str_limit($subject, 60) . '" raised by ' . $name . ' (' . $s['student_uid'] . ') via the student portal');
    notify('perm:hostel', 'hostel', (in_array($priority, ['high', 'urgent'], true) ? ucfirst($priority) . ' hostel' : 'New hostel') . ' complaint',
        $no . ' · ' . $stay['hostel_code'] . ' · Room ' . $stay['room_no'] . ' · ' . str_limit($subject, 80), 'admin/hostel/complaints?view=' . $id, 'alert-triangle');
    return ['id' => $id, 'complaint_no' => $no];
}

/* ==================================================================
 * Transport
 * ================================================================== */

function ps_transport(array $s): array
{
    $sid = (int) $s['id'];
    $a = db_row("SELECT ta.id, ta.route_id, ta.stop_id, ta.vehicle_id, ta.start_date, ta.end_date, ta.fee_amount, ss.name AS session_name,
                        tr.name AS route_name, tr.code AS route_code, tr.start_point, tr.end_point, tr.distance_km, tr.morning_departure, tr.evening_departure,
                        ts.name AS stop_name, ts.landmark, ts.pickup_time, ts.drop_time, ts.sequence
                 FROM transport_allocations ta JOIN transport_routes tr ON tr.id = ta.route_id LEFT JOIN transport_stops ts ON ts.id = ta.stop_id
                 LEFT JOIN academic_sessions ss ON ss.id = ta.academic_session_id
                 WHERE ta.student_id = ? AND ta.status = 'active' ORDER BY ta.start_date DESC, ta.id DESC LIMIT 1", [$sid]);
    if (!$a) {
        return ['allocation' => null, 'stops' => [], 'vehicle' => null];
    }
    $vehicleId = (int) $a['vehicle_id'] ?: (int) db_value("SELECT id FROM vehicles WHERE route_id = ? AND status = 'active' ORDER BY id LIMIT 1", [(int) $a['route_id']]);
    $v = $vehicleId ? db_row("SELECT v.vehicle_no, v.type, v.make, v.model, v.capacity, v.status, d.name AS driver_name, d.phone AS driver_phone, c.name AS conductor_name, c.phone AS conductor_phone
                              FROM vehicles v LEFT JOIN drivers d ON d.id = v.driver_id LEFT JOIN drivers c ON c.id = v.conductor_id WHERE v.id = ?", [$vehicleId]) : null;
    $stops = db_all('SELECT id, name, landmark, sequence, pickup_time, drop_time FROM transport_stops WHERE route_id = ? ORDER BY sequence, id', [(int) $a['route_id']]);
    $t = fn ($x) => $x ? substr((string) $x, 0, 5) : null;
    return [
        'allocation' => ['route' => $a['route_name'], 'route_code' => $a['route_code'], 'start_point' => $a['start_point'], 'end_point' => $a['end_point'],
            'distance_km' => ps_f($a['distance_km']), 'morning_departure' => $t($a['morning_departure']), 'evening_departure' => $t($a['evening_departure']),
            'stop' => $a['stop_name'], 'landmark' => $a['landmark'], 'pickup_time' => $t($a['pickup_time']), 'drop_time' => $t($a['drop_time']),
            'start_date' => $a['start_date'], 'end_date' => $a['end_date'], 'fee_amount' => ps_f($a['fee_amount']), 'session' => $a['session_name']],
        'vehicle' => $v ? ['vehicle_no' => $v['vehicle_no'], 'type' => $v['type'], 'model' => trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?: null, 'capacity' => ps_i($v['capacity']),
            'status' => $v['status'], 'driver' => $v['driver_name'], 'driver_phone' => $v['driver_phone'], 'conductor' => $v['conductor_name'], 'conductor_phone' => $v['conductor_phone']] : null,
        'stops' => array_map(fn ($st) => ['id' => (int) $st['id'], 'name' => $st['name'], 'landmark' => $st['landmark'], 'sequence' => (int) $st['sequence'],
            'pickup_time' => $t($st['pickup_time']), 'drop_time' => $t($st['drop_time']), 'mine' => (int) $st['id'] === (int) $a['stop_id']], $stops),
    ];
}

/* ==================================================================
 * Certificates
 * ================================================================== */

/** Certificate types a student may request online (active template + eligible). */
function ps_certificate_requestable(array $s): array
{
    ps_need('certificates');
    $student = cert_student((int) $s['id']);
    $out = [];
    foreach (['bonafide', 'character'] as $type) {
        $def = cert_type($type);
        if (!$def || !$student || !cert_template_for($type, null)) {
            continue;
        }
        $why = cert_ineligible_reason($student, $type);
        $pending = db_value("SELECT certificate_no FROM certificates WHERE student_id = ? AND type = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [(int) $s['id'], $type]);
        $out[$type] = ['type' => $type, 'label' => $def['label'], 'description' => $def['description'], 'purpose_required' => in_array('purpose', $def['required'], true),
            'available' => !$why && !$pending, 'reason' => $why ? 'You ' . preg_replace('/^is /', 'are ', $why) : ($pending ? 'Your request ' . $pending . ' is waiting for approval.' : null)];
    }
    return $out;
}

function ps_certificates(array $s): array
{
    ps_need('certificates');
    $rows = db_all("SELECT id, certificate_no, type, title, purpose, issue_date, valid_until, verification_hash, status, revoked_reason, remarks, created_at, approved_at, revoked_at
                    FROM certificates WHERE student_id = ? ORDER BY created_at DESC, id DESC", [(int) $s['id']]);
    $issued = [];
    $requests = [];
    foreach ($rows as $c) {
        $status = cert_effective_status($c);
        $item = ['id' => (int) $c['id'], 'certificate_no' => $c['certificate_no'], 'type' => $c['type'], 'type_label' => cert_type_label($c['type']), 'title' => $c['title'],
            'purpose' => $c['purpose'], 'issue_date' => $c['issue_date'], 'valid_until' => $c['valid_until'], 'status' => $status,
            'status_label' => cert_status_labels()[$status] ?? label_from_key($status), 'requested_at' => $c['created_at']];
        if (in_array($c['status'], ['issued', 'revoked'], true)) {
            $item['verify_url'] = $c['status'] === 'issued' ? cert_verify_url($c['certificate_no'], $c['verification_hash']) : absolute_url('verify-certificate/' . rawurlencode($c['certificate_no']));
            $item['security_code'] = $c['status'] === 'issued' ? cert_security_code($c['verification_hash']) : null;
            $item['revoked_reason'] = $c['status'] === 'revoked' ? $c['revoked_reason'] : null;
            $issued[] = $item;
        } else {
            $item['reason'] = $c['status'] === 'rejected' ? $c['revoked_reason'] : null;
            $item['decided_at'] = $c['revoked_at'];
            $requests[] = $item;
        }
    }
    $types = ps_certificate_requestable($s);
    return ['issued' => $issued, 'requests' => $requests, 'requestable' => array_values($types),
        'purposes' => cert_field_defs()['purpose']['suggestions'] ?? [], 'verify_page' => absolute_url('verify-certificate')];
}

/** Record a certificate request as a PENDING certificate (the admin approves / rejects it in Certificates). */
function ps_certificate_request(array $s, array $in): array
{
    ps_need('certificates');
    $sid = (int) $s['id'];
    $type = (string) ($in['type'] ?? '');
    $purpose = trim((string) ($in['purpose'] ?? ''));
    $note = trim((string) ($in['note'] ?? ''));
    $types = ps_certificate_requestable($s);
    $errors = [];
    if (!isset($types[$type])) {
        $errors['type'] = 'Choose a certificate that can be requested online.';
    } elseif (!$types[$type]['available']) {
        $errors['type'] = $types[$type]['reason'] ?? 'This certificate cannot be requested right now.';
    }
    if ($type !== '' && isset($types[$type]) && $types[$type]['purpose_required'] && $purpose === '') {
        $errors['purpose'] = 'Tell us what the certificate is for (e.g. opening a bank account).';
    } elseif (mb_strlen($purpose) > 150) {
        $errors['purpose'] = 'Keep the purpose under 150 characters.';
    }
    if (mb_strlen($note) > 200) {
        $errors['note'] = 'Keep the note under 200 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $fields = $purpose !== '' ? ['purpose' => $purpose] : [];
    [$verr, $ctx] = cert_validate_issue(['student_id' => $sid, 'type' => $type, 'issue_date' => ps_today(), 'fields' => $fields,
        'remarks' => mb_substr('Requested by the student on the portal' . ($note !== '' ? ': ' . $note : '') . ($type === 'character' ? ' (conduct defaulted - review before approving)' : ''), 0, 255)]);
    if ($verr) {
        if (isset($verr['fields.purpose'])) {
            throw new CrudValidationException(['purpose' => $verr['fields.purpose']]);
        }
        throw new CrudException('This certificate cannot be requested online right now: ' . strip_tags((string) reset($verr)) . ' Please contact the academic office.');
    }
    $composed = cert_compose($ctx);
    if (cert_placeholder_message($composed)) {
        throw new CrudException('This certificate cannot be requested online right now because some of your details are missing. Please contact the academic office.');
    }
    $student = $ctx['student'];
    $name = cert_student_name($student);
    $row = db_transaction(function () use ($ctx, $student, $name, $sid) {
        $no = cert_next_number($ctx['type'], $ctx['issue_date']);
        $hash = cert_hash($no, $sid, $ctx['type'], $ctx['issue_date']);
        $final = cert_compose($ctx, ['certificate_no' => $no, 'verify_url' => cert_verify_url($no)]);
        $data = [
            'fields' => $ctx['fields'], 'design' => $final['design'], 'title' => $final['title'], 'subtitle' => $final['subtitle'],
            'student' => ['name' => $name, 'uid' => $student['student_uid'], 'program' => $student['program_name'], 'program_short' => $student['program_short'],
                'gender' => $student['gender'], 'photo' => $student['photo']],
            'template' => ['id' => (int) $ctx['template']['id'], 'name' => $ctx['template']['name']],
            'requested_via' => 'student_portal',
        ];
        $id = db_insert('certificates', [
            'certificate_no' => $no, 'type' => $ctx['type'], 'template_id' => (int) $ctx['template']['id'], 'student_id' => $sid,
            'academic_session_id' => cert_session_for($ctx['issue_date'])['id'] ?? current_session_id(), 'title' => mb_substr($final['title'], 0, 190),
            'purpose' => ($ctx['fields']['purpose'] ?? '') !== '' ? mb_substr($ctx['fields']['purpose'], 0, 255) : null,
            'content_html' => $final['body'], 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'issue_date' => $ctx['issue_date'], 'valid_until' => $ctx['valid_until'], 'verification_hash' => $hash, 'status' => 'pending', 'remarks' => $ctx['remarks'],
        ]);
        return db_row('SELECT * FROM certificates WHERE id = ?', [$id]);
    });
    log_activity('request', 'certificates', $row['id'], sprintf('%s (%s) requested %s %s via the student portal%s', $name, $student['student_uid'], cert_type_label($row['type']),
        $row['certificate_no'], $row['purpose'] ? ' — ' . $row['purpose'] : ''));
    cert_notify_issued($row, $name); // pending -> notifies the approvers
    return ['id' => (int) $row['id'], 'certificate_no' => $row['certificate_no'], 'type_label' => cert_type_label($row['type'])];
}

/* ==================================================================
 * Placement
 * ================================================================== */

function ps_placement(array $s): array
{
    ps_need('placement');
    $sid = (int) $s['id'];
    $me = db_row('SELECT ' . placement_cgpa_sql() . ' AS cgpa, ' . placement_backlog_sql() . ' AS backlogs, s.previous_percentage, p.total_semesters, b.end_year
                  FROM students s JOIN programs p ON p.id = s.program_id LEFT JOIN batches b ON b.id = s.batch_id WHERE s.id = ?', [$sid]);
    $programs = db_pairs('SELECT id, short_name FROM programs');
    $apps = db_all("SELECT pa.id, pa.drive_id, pa.status, pa.current_round, pa.applied_at, pa.updated_at, pa.remarks FROM placement_applications pa WHERE pa.student_id = ?", [$sid]);
    $appByDrive = [];
    foreach ($apps as $a) {
        $appByDrive[(int) $a['drive_id']] = $a;
    }
    $driveIds = array_keys($appByDrive);
    $drives = db_all("SELECT d.*, c.name AS company_name, c.logo AS company_logo, c.industry, c.website AS company_website, c.headquarters
                      FROM placement_drives d JOIN companies c ON c.id = d.company_id
                      WHERE (d.status IN ('upcoming', 'open', 'ongoing') AND (d.registration_deadline IS NULL OR d.registration_deadline >= CURDATE()))"
        . ($driveIds ? ' OR d.id IN (' . implode(',', array_map('intval', $driveIds)) . ')' : '') . '
                      ORDER BY COALESCE(d.registration_deadline, d.drive_date), d.id', []);
    $open = [];
    $mine = [];
    foreach ($drives as $d) {
        $did = (int) $d['id'];
        $progIds = placement_drive_programs($d);
        $app = $appByDrive[$did] ?? null;
        if (!$app && $progIds && !in_array((int) $s['program_id'], $progIds, true)) {
            continue; // drives for other programmes are not listed
        }
        $withdrawn = $app && $app['status'] === 'withdrawn';
        $reason = $app && !$withdrawn ? null : placement_ineligibility_reason($d, $sid);
        if ($reason !== null) {
            $reason = str_replace(['student graduates in', 'Only active students can be registered'], ['you graduate in', 'Only active students can apply'], $reason);
        }
        $closed = in_array($d['status'], ['completed', 'cancelled'], true) || ($d['registration_deadline'] && $d['registration_deadline'] < ps_today());
        $item = [
            'id' => $did, 'title' => $d['title'], 'role' => $d['job_role'], 'job_type' => $d['job_type'], 'job_type_label' => placement_job_types()[$d['job_type']] ?? label_from_key((string) $d['job_type']),
            'company' => $d['company_name'], 'logo' => ps_public_file($d['company_logo']), 'industry' => $d['industry'], 'website' => $d['company_website'], 'headquarters' => $d['headquarters'],
            'description' => $d['description'] ? trim(strip_tags((string) $d['description'])) : null, 'openings' => ps_i($d['openings']),
            'package_min' => ps_f($d['package_min']), 'package_max' => ps_f($d['package_max']), 'stipend' => ps_f($d['stipend']), 'location' => $d['location'],
            'drive_date' => $d['drive_date'], 'deadline' => $d['registration_deadline'], 'days_left' => ps_days_until($d['registration_deadline'] ?: $d['drive_date']),
            'venue' => $d['venue'], 'mode' => $d['mode'], 'status' => $d['status'], 'rounds' => placement_drive_rounds($d['rounds']),
            'criteria' => ['programs' => $progIds ? array_values(array_filter(array_map(fn ($id) => $programs[$id] ?? null, $progIds))) : [], 'min_cgpa' => ps_f($d['min_cgpa']),
                'max_backlogs' => $d['max_backlogs'] !== null && $d['max_backlogs'] !== '' ? (int) $d['max_backlogs'] : null, 'min_percentage' => ps_f($d['min_percentage']),
                'batch_year' => ps_i($d['batch_year']), 'notes' => $d['eligibility_notes']],
            'eligible' => $reason === null, 'reason' => $reason, 'can_apply' => (!$app || $withdrawn) && $reason === null && !$closed,
            'application' => $app ? ['id' => (int) $app['id'], 'status' => $app['status'], 'current_round' => $app['current_round'], 'applied_at' => $app['applied_at']] : null,
        ];
        if (!$closed || $app) {
            $open[] = $item;
        }
        if ($app) {
            $mine[$did] = $item;
        }
    }
    // Applications (with interview rounds) and offers
    $interviews = [];
    if ($apps) {
        foreach (db_all('SELECT application_id, round_name, scheduled_at, mode, result FROM placement_interviews WHERE application_id IN (' . implode(',', array_map(fn ($a) => (int) $a['id'], $apps)) . ')
                         ORDER BY scheduled_at, id') as $iv) {
            $interviews[(int) $iv['application_id']][] = ['round' => $iv['round_name'], 'scheduled_at' => $iv['scheduled_at'], 'mode' => $iv['mode'], 'result' => $iv['result']];
        }
    }
    $applications = [];
    $statuses = placement_app_statuses();
    foreach ($apps as $a) {
        $d = $mine[(int) $a['drive_id']] ?? null;
        if (!$d) {
            $row = placement_drive_row((int) $a['drive_id']);
            if (!$row) {
                continue;
            }
            $d = ['id' => (int) $row['id'], 'title' => $row['title'], 'role' => $row['job_role'], 'company' => $row['company_name'], 'logo' => ps_public_file($row['company_logo']),
                'drive_date' => $row['drive_date'], 'rounds' => placement_drive_rounds($row['rounds']), 'status' => $row['status'], 'package_min' => ps_f($row['package_min']), 'package_max' => ps_f($row['package_max'])];
        }
        $applications[] = ['id' => (int) $a['id'], 'drive_id' => (int) $a['drive_id'], 'status' => $a['status'], 'status_label' => $statuses[$a['status']] ?? label_from_key((string) $a['status']),
            'current_round' => $a['current_round'], 'applied_at' => $a['applied_at'], 'updated_at' => $a['updated_at'], 'company' => $d['company'], 'logo' => $d['logo'],
            'title' => $d['title'], 'role' => $d['role'], 'drive_date' => $d['drive_date'], 'drive_status' => $d['status'], 'rounds' => $d['rounds'],
            'package_min' => $d['package_min'] ?? null, 'package_max' => $d['package_max'] ?? null, 'interviews' => $interviews[(int) $a['id']] ?? [],
            'can_withdraw' => $a['status'] === 'applied' && !in_array($d['status'], ['completed', 'cancelled'], true)];
    }
    usort($applications, fn ($x, $y) => strcmp((string) $y['applied_at'], (string) $x['applied_at']));
    $offers = db_all("SELECT o.id, o.job_role, o.package_lpa, o.offer_date, o.joining_date, o.location, o.offer_letter, o.status, o.responded_at, c.name AS company, c.logo
                      FROM placement_offers o JOIN companies c ON c.id = o.company_id WHERE o.student_id = ? ORDER BY o.offer_date DESC, o.id DESC", [$sid]);
    $offerStatuses = placement_offer_statuses();
    $finalYear = (int) ($s['current_semester'] ?? 0) >= max(1, (int) ($me['total_semesters'] ?? 0) - 1);
    return [
        'profile' => ['cgpa' => ps_f($me['cgpa'] ?? null), 'backlogs' => (int) ($me['backlogs'] ?? 0), 'previous_percentage' => ps_f($me['previous_percentage'] ?? null),
            'final_year' => $finalYear, 'batch_end' => ps_i($me['end_year'] ?? null), 'status' => $s['status']],
        'drives' => array_values(array_filter($open, fn ($d) => !$d['application'] || ($d['application']['status'] === 'withdrawn' && $d['can_apply']))),
        'applications' => $applications,
        'offers' => array_map(fn ($o) => ['id' => (int) $o['id'], 'company' => $o['company'], 'logo' => ps_public_file($o['logo']), 'role' => $o['job_role'],
            'package_lpa' => ps_f($o['package_lpa']), 'offer_date' => $o['offer_date'], 'joining_date' => $o['joining_date'], 'location' => $o['location'],
            'status' => $o['status'], 'status_label' => $offerStatuses[$o['status']] ?? label_from_key((string) $o['status']), 'offer_letter' => ps_public_file($o['offer_letter'])], $offers),
    ];
}

/** Apply for a drive (eligibility re-checked server side). */
function ps_placement_apply(array $s, int $driveId): array
{
    ps_need('placement');
    $sid = (int) $s['id'];
    $d = $driveId ? placement_drive_row($driveId) : null;
    $progIds = $d ? placement_drive_programs($d) : [];
    if (!$d || ($progIds && !in_array((int) $s['program_id'], $progIds, true))) {
        return ['status' => 404, 'message' => 'Placement drive not found.'];
    }
    $existing = db_row('SELECT id, status FROM placement_applications WHERE drive_id = ? AND student_id = ?', [$driveId, $sid]);
    if ($existing && $existing['status'] !== 'withdrawn') {
        return ['status' => 409, 'message' => 'You have already applied for this drive.'];
    }
    if (in_array($d['status'], ['completed', 'cancelled'], true) || ($d['registration_deadline'] && $d['registration_deadline'] < ps_today())) {
        return ['status' => 422, 'message' => 'Registrations for this drive are closed.'];
    }
    if ($why = placement_ineligibility_reason($d, $sid)) {
        return ['status' => 403, 'message' => 'You are not eligible for this drive: ' . $why];
    }
    if ($existing) {
        // re-apply after withdrawing (same row, back to "applied")
        $id = (int) $existing['id'];
        db_update('placement_applications', ['status' => 'applied', 'current_round' => null, 'applied_at' => date('Y-m-d H:i:s'), 'remarks' => 'Re-applied via the student portal'], 'id = ?', [$id]);
    } else {
        try {
            $id = db_insert('placement_applications', ['drive_id' => $driveId, 'student_id' => $sid, 'status' => 'applied', 'applied_at' => date('Y-m-d H:i:s'), 'remarks' => 'Applied via the student portal']);
        } catch (Throwable $e) {
            return ['status' => 409, 'message' => 'You have already applied for this drive.'];
        }
    }
    $name = $s['full_name'] ?? '';
    log_activity('create', 'placement', $id, $name . ' (' . $s['student_uid'] . ') applied for ' . $d['company_name'] . ' — ' . $d['title'] . ' via the student portal');
    notify('perm:placement', 'placement', 'New drive application', $name . ' applied for ' . $d['company_name'] . ' — ' . str_limit($d['title'], 60), 'admin/placement/drives/' . $driveId, 'briefcase');
    return ['status' => 200, 'message' => 'Application submitted to ' . $d['company_name'] . '. Watch this page for shortlists and interview rounds.', 'id' => $id];
}

function ps_placement_withdraw(array $s, int $appId): array
{
    ps_need('placement');
    $a = db_row('SELECT pa.*, d.status AS drive_status, d.title, c.name AS company_name FROM placement_applications pa JOIN placement_drives d ON d.id = pa.drive_id
                 JOIN companies c ON c.id = d.company_id WHERE pa.id = ? AND pa.student_id = ?', [$appId, (int) $s['id']]);
    if (!$a) {
        return ['status' => 404, 'message' => 'Application not found.'];
    }
    if ($a['status'] !== 'applied' || in_array($a['drive_status'], ['completed', 'cancelled'], true)) {
        return ['status' => 422, 'message' => 'This application can no longer be withdrawn. Contact the placement cell.'];
    }
    db_update('placement_applications', ['status' => 'withdrawn', 'remarks' => 'Withdrawn by the student via the portal'], 'id = ?', [$appId]);
    log_activity('update', 'placement', $appId, ($s['full_name'] ?? '') . ' (' . $s['student_uid'] . ') withdrew the application for ' . $a['company_name'] . ' — ' . $a['title']);
    return ['status' => 200, 'message' => 'Your application for ' . $a['company_name'] . ' was withdrawn.'];
}

/* ==================================================================
 * Documents
 * ================================================================== */

function ps_documents(array $s): array
{
    $types = students_document_types();
    $rows = db_all('SELECT id, doc_type, title, original_name, mime, size_bytes, status, remarks, is_verified, verified_at, created_at FROM student_documents WHERE student_id = ?
                    ORDER BY FIELD(status, \'rejected\', \'pending\', \'verified\'), created_at DESC, id DESC', [(int) $s['id']]);
    return [
        'documents' => array_map(fn ($d) => ['id' => (int) $d['id'], 'type' => $d['doc_type'], 'type_label' => $types[$d['doc_type']] ?? label_from_key((string) $d['doc_type']),
            'title' => $d['title'], 'file_name' => $d['original_name'], 'mime' => $d['mime'], 'size' => ps_i($d['size_bytes']), 'status' => $d['status'],
            'remarks' => $d['remarks'], 'verified_at' => $d['verified_at'], 'uploaded_at' => $d['created_at']], $rows),
        'stats' => students_document_stats((int) $s['id']),
        'missing' => array_values(array_diff_key(array_intersect_key($types, array_flip(['10th_marksheet', '12th_marksheet', 'id_proof', 'photo'])),
            array_flip(array_column($rows, 'doc_type')))),
    ];
}

/* ==================================================================
 * Grievance / feedback tickets (feedback table, submitted_by_type = student)
 * ================================================================== */

function ps_ticket_public(array $f, bool $full = false): array
{
    ps_need('admissions');
    $out = ['id' => (int) $f['id'], 'ticket_no' => $f['ticket_no'], 'type' => $f['type'], 'type_label' => feedback_types()[$f['type']] ?? label_from_key((string) $f['type']),
        'category' => $f['category'], 'category_label' => feedback_categories()[$f['category']] ?? label_from_key((string) $f['category']), 'subject' => $f['subject'],
        'priority' => $f['priority'], 'status' => $f['status'], 'anonymous' => (bool) $f['is_anonymous'], 'rating' => ps_i($f['rating']), 'created_at' => $f['created_at'],
        'updated_at' => $f['updated_at'], 'resolved_at' => $f['resolved_at'], 'has_resolution' => trim((string) $f['resolution']) !== '',
        'sla_hours' => feedback_sla_hours((string) $f['priority'])];
    if ($full) {
        $out['message'] = $f['message'];
        $out['resolution'] = $f['resolution'];
        $out['started_at'] = $f['started_at'];
        $out['closed_at'] = $f['closed_at'];
        $out['assigned'] = $f['assigned_to'] ? (string) db_value('SELECT name FROM users WHERE id = ?', [(int) $f['assigned_to']]) : null;
        $out['can_rate'] = in_array($f['status'], ['resolved', 'closed'], true);
        $out['can_reopen'] = in_array($f['status'], ['resolved', 'closed'], true) && $f['resolved_at'] && strtotime((string) $f['resolved_at']) > strtotime('-14 days');
        $timeline = [['type' => 'created', 'title' => 'Submitted', 'at' => $f['created_at']]];
        if ($f['started_at']) {
            $timeline[] = ['type' => 'in_progress', 'title' => 'Being worked on' . ($out['assigned'] ? ' by ' . $out['assigned'] : ''), 'at' => $f['started_at']];
        }
        if ($f['resolved_at']) {
            $timeline[] = ['type' => 'resolved', 'title' => 'Resolved', 'at' => $f['resolved_at']];
        }
        if ($f['closed_at']) {
            $timeline[] = ['type' => 'closed', 'title' => 'Closed', 'at' => $f['closed_at']];
        }
        $out['timeline'] = $timeline;
    }
    return $out;
}

function ps_tickets(array $s): array
{
    ps_need('admissions');
    $rows = db_all("SELECT * FROM feedback WHERE student_id = ? AND submitted_by_type = 'student' ORDER BY FIELD(status, 'open', 'in_progress', 'resolved', 'closed'), created_at DESC, id DESC LIMIT 100",
        [(int) $s['id']]);
    $counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0];
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']]++;
        }
    }
    return ['tickets' => array_map(fn ($r) => ps_ticket_public($r), $rows), 'counts' => $counts,
        'types' => array_intersect_key(feedback_types(), array_flip(['complaint', 'grievance', 'suggestion', 'feedback'])), 'categories' => feedback_categories()];
}

function ps_ticket_row(array $s, int $id): ?array
{
    return $id ? db_row("SELECT * FROM feedback WHERE id = ? AND student_id = ? AND submitted_by_type = 'student'", [$id, (int) $s['id']]) : null;
}

/** Create a ticket for the student. $in: type, category, subject, message, priority, anonymous */
function ps_ticket_create(array $s, array $in, ?string $forcedSubject = null): array
{
    ps_need('admissions');
    $type = (string) ($in['type'] ?? 'complaint');
    $category = (string) ($in['category'] ?? '');
    $subject = trim((string) ($forcedSubject ?? ($in['subject'] ?? '')));
    $message = trim((string) ($in['message'] ?? ''));
    $priority = (string) ($in['priority'] ?? 'medium');
    $errors = [];
    if (!in_array($type, ['complaint', 'grievance', 'suggestion', 'feedback'], true)) {
        $errors['type'] = 'Choose what kind of request this is.';
    }
    if (!isset(feedback_categories()[$category])) {
        $errors['category'] = 'Choose a category.';
    }
    if (!in_array($priority, ['low', 'medium', 'high'], true)) {
        $errors['priority'] = 'Choose a priority.';
    }
    if ($category === 'ragging') {
        $priority = 'urgent'; // ragging / harassment is always urgent
    }
    if (mb_strlen($subject) < 5) {
        $errors['subject'] = 'Give your request a short title (at least 5 characters).';
    } elseif (mb_strlen($subject) > 200) {
        $errors['subject'] = 'Keep the title under 200 characters.';
    }
    if (mb_strlen($message) < 15) {
        $errors['message'] = 'Please describe the issue in a little more detail (at least 15 characters).';
    } elseif (mb_strlen($message) > 5000) {
        $errors['message'] = 'Keep the description under 5000 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $open = (int) db_value("SELECT COUNT(*) FROM feedback WHERE student_id = ? AND submitted_by_type = 'student' AND status IN ('open', 'in_progress')", [(int) $s['id']]);
    if ($open >= 10) {
        throw new CrudException('You have 10 open requests. Please wait for some of them to be resolved before raising more.');
    }
    $name = $s['full_name'] ?? trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
    $no = next_number('feedback_ticket', 'TKT-{Y}-{n:5}');
    $id = db_insert('feedback', [
        'ticket_no' => $no, 'type' => $type, 'category' => $category, 'submitted_by_type' => 'student', 'student_id' => (int) $s['id'],
        'name' => mb_substr($name, 0, 150), 'email' => $s['email'] ?: null, 'phone' => $s['mobile'] ?: null, 'subject' => mb_substr($subject, 0, 255),
        'message' => $message, 'priority' => $priority, 'status' => 'open', 'is_anonymous' => !empty($in['anonymous']) ? 1 : 0,
    ]);
    log_activity('create', 'feedback', $id, sprintf('%s %s "%s" raised by %s (%s) via the student portal', ucfirst($type), $no, str_limit($subject, 60),
        !empty($in['anonymous']) ? 'a student (identity hidden)' : $name, !empty($in['anonymous']) ? 'anonymous' : $s['student_uid']));
    notify('perm:feedback', 'feedback', 'New ' . $type . ' ' . $no, (!empty($in['anonymous']) ? 'Anonymous student' : $name) . ': ' . str_limit($subject, 80), 'admin/feedback?id=' . $id,
        in_array($type, ['complaint', 'grievance'], true) ? 'alert-triangle' : 'message-square');
    return ['id' => $id, 'ticket_no' => $no];
}

/** Profile correction request -> administration ticket with the requested change. */
function ps_profile_correction(array $s, array $in): array
{
    $fields = ps_correction_fields();
    $field = (string) ($in['field'] ?? '');
    $correct = trim((string) ($in['correct_value'] ?? ''));
    $details = trim((string) ($in['details'] ?? ''));
    $errors = [];
    if (!isset($fields[$field])) {
        $errors['field'] = 'Choose the detail that is wrong.';
    }
    if ($correct === '') {
        $errors['correct_value'] = 'Enter the correct value.';
    } elseif (mb_strlen($correct) > 255) {
        $errors['correct_value'] = 'Keep it under 255 characters.';
    }
    if (mb_strlen($details) > 1000) {
        $errors['details'] = 'Keep the note under 1000 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $col = $fields[$field]['column'];
    $current = $col && array_key_exists($col, $s) ? (string) ($s[$col] ?? '') : '';
    $label = $fields[$field]['label'];
    $message = "Please correct my {$label} in the student records.\n\nCurrently recorded: " . ($current !== '' ? $current : '(blank)') . "\nCorrect value: {$correct}"
        . ($details !== '' ? "\n\nNote: {$details}" : '') . "\n\nStudent ID: " . $s['student_uid'] . "\nSupporting documents can be verified at the academic office.";
    return ps_ticket_create($s, ['type' => 'feedback', 'category' => 'administration', 'message' => $message, 'priority' => 'medium'], 'Profile correction: ' . $label);
}

function ps_correction_fields(): array
{
    return [
        'name' => ['label' => 'Name (spelling)', 'column' => 'full_name'], 'dob' => ['label' => 'Date of birth', 'column' => 'dob'], 'gender' => ['label' => 'Gender', 'column' => 'gender'],
        'father_name' => ["label" => "Father's name", 'column' => 'father_name'], 'mother_name' => ["label" => "Mother's name", 'column' => 'mother_name'],
        'mobile' => ['label' => 'Mobile number', 'column' => 'mobile'], 'email' => ['label' => 'Email address', 'column' => 'email'], 'address' => ['label' => 'Address', 'column' => 'address'],
        'category' => ['label' => 'Category', 'column' => 'category'], 'blood_group' => ['label' => 'Blood group', 'column' => 'blood_group'],
        'guardian' => ['label' => 'Parent / guardian details', 'column' => 'guardian_name'], 'photo' => ['label' => 'Photograph', 'column' => null],
        'academic' => ['label' => 'Program / section / roll number', 'column' => 'roll_no'], 'other' => ['label' => 'Something else', 'column' => null],
    ];
}

/* ==================================================================
 * Print views (A4 HTML)
 * ================================================================== */

/** Polite HTML error page for the print endpoints, then exit. */
function ps_print_fail(string $title, string $message, int $status = 404, bool $signIn = false): void
{
    http_response_code($status);
    ps_print_headers();
    print_layout_start($title);
    echo '<div class="py-24 text-center"><p class="font-display text-xl font-bold text-brand-900">' . e($title) . '</p>'
        . '<p class="mx-auto mt-2 max-w-md text-sm text-slate-500">' . e($message) . '</p>'
        . '<a href="' . e(base_url($signIn ? 'portal/student/login' : 'portal/student')) . '" class="btn btn-primary btn-sm mt-5 no-print">' . ($signIn ? 'Sign in to the student portal' : 'Back to the student portal') . '</a></div>';
    print_layout_end();
    exit;
}

function ps_print_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; frame-ancestors 'self'; form-action 'none'; base-uri 'self'");
}

/** Guard for HTML print endpoints: a signed-in student (password already changed) or a polite error page. */
function ps_print_guard(): array
{
    $ctx = portal_student_session();
    if (!$ctx && portal_student_login_from_cookie()) {
        $ctx = portal_student_session(true);
    }
    if (!$ctx) {
        ps_print_fail('Please sign in', 'Your student portal session has ended. Sign in again and reopen this document.', 401, true);
    }
    if ($ctx['must_change_password']) {
        ps_print_fail('Set your password first', 'You are using a temporary password. Choose your own password in the student portal, then open this document again.', 403);
    }
    $ctx['student'] = portal_student_profile_row($ctx['student_id']);
    return $ctx;
}

function ps_print_admit_card(array $d): void
{
    $e = $d['exam'];
    $s = $d['student'];
    $name = trim(implode(' ', array_filter([$s['first_name'], $s['middle_name'], $s['last_name']])));
    $photo = !empty($s['photo']) ? upload_url($s['photo']) : '';
    $controllerTitle = (string) setting('exam_controller_designation', 'Controller of Examinations');
    $instructions = array_values(array_filter(array_map('trim', preg_split('/\r?\n|(?<=\.)\s+(?=[A-Z])/', (string) ($e['instructions'] ?: 'Report 30 minutes before the paper with this admit card and your college ID card. Mobile phones and electronic gadgets are not allowed in the examination hall.')))));
    ps_print_headers();
    print_layout_start('Admit card — ' . $e['name'], false);
    ?>
<style>
  .ac-card{border:1.5px solid #0B2A5B;border-radius:10px;padding:6mm;position:relative;overflow:hidden}
  .ac-band{background:#0B2A5B;color:#fff}
  .ac-table th,.ac-table td{border:1px solid #cbd5e1;padding:3px 5px}
  .ac-table thead th{background:#EEF2F7;color:#0B2A5B;font-weight:700}
</style>
<section class="ac-card">
  <header class="flex items-center gap-3 border-b border-slate-200 pb-3">
    <img src="<?= e(logo_url()) ?>" alt="GIMT" class="h-12 w-auto">
    <div class="flex-1">
      <p class="font-display text-[14px] font-extrabold uppercase leading-tight text-brand-900"><?= e(institute_name()) ?></p>
      <p class="text-[10px] text-slate-500"><?= e($e['type_name']) ?> · <?= e(date('F Y', strtotime($e['start_date'] ?: 'now'))) ?> · Session <?= e($e['session_name']) ?></p>
    </div>
    <span class="ac-band rounded-md px-3 py-1 text-[11px] font-bold uppercase tracking-widest">Admit Card</span>
  </header>
  <p class="mt-3 text-[12px] font-semibold text-slate-800"><?= e($e['name']) ?></p>
  <div class="mt-2 flex gap-4">
    <table class="w-full text-[11px]">
      <tbody>
        <tr><td class="w-[22%] py-[2px] text-slate-500">Candidate</td><td class="py-[2px] font-bold uppercase text-slate-900" colspan="3"><?= e($name) ?></td></tr>
        <tr><td class="py-[2px] text-slate-500">Father's name</td><td class="py-[2px] text-slate-800" colspan="3"><?= e($s['father_name'] ?: '—') ?></td></tr>
        <tr><td class="py-[2px] text-slate-500">Programme</td><td class="py-[2px] font-medium text-slate-800"><?= e($e['program_short'] ?? '') ?><?= $e['semester_no'] ? ' · Sem ' . e($e['semester_no']) : '' ?><?= $s['section_name'] ? ' · Sec ' . e($s['section_name']) : '' ?></td>
            <td class="w-[16%] py-[2px] text-slate-500">Roll no.</td><td class="py-[2px] font-mono font-semibold text-slate-900"><?= e($s['roll_no'] ?: '—') ?></td></tr>
        <tr><td class="py-[2px] text-slate-500">Hall ticket</td><td class="py-[2px] font-mono font-bold text-brand-900"><?= e($s['hall_ticket_no'] ?: '—') ?></td>
            <td class="py-[2px] text-slate-500">Student ID</td><td class="py-[2px] font-mono text-slate-800"><?= e($s['student_uid']) ?></td></tr>
        <tr><td class="py-[2px] text-slate-500">Seat / room</td><td class="py-[2px] font-semibold text-slate-900"><?= e($s['seat_no'] ?: 'Displayed on the notice board') ?><?= $s['room_name'] ? ' · ' . e($s['room_name']) : '' ?></td>
            <td class="py-[2px] text-slate-500">Enrollment</td><td class="py-[2px] font-mono text-slate-800"><?= e($s['enrollment_no'] ?: '—') ?></td></tr>
      </tbody>
    </table>
    <div class="flex h-[30mm] w-[24mm] shrink-0 items-center justify-center overflow-hidden rounded border border-slate-300 bg-slate-50">
      <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" class="h-full w-full object-cover"><?php else: ?><span class="font-display text-lg font-bold text-slate-400"><?= e(initials($name)) ?></span><?php endif; ?>
    </div>
    <div data-qr="<?= e('GIMT ADMIT CARD|' . $s['hall_ticket_no'] . '|' . $s['student_uid'] . '|EXAM ' . $e['id']) ?>" class="h-[22mm] w-[22mm] shrink-0"></div>
  </div>
  <table class="ac-table mt-3 w-full border-collapse text-[10.5px]">
    <thead><tr><th class="text-left">Date</th><th class="text-left">Time</th><th class="text-left">Paper</th><th class="text-left">Room</th><th class="w-[26mm] text-center">Invigilator's sign</th></tr></thead>
    <tbody>
      <?php foreach ($d['papers'] as $pp): ?>
        <tr>
          <td class="whitespace-nowrap"><?= e(date('d M Y, D', strtotime($pp['exam_date']))) ?></td>
          <td class="whitespace-nowrap"><?= e(exam_time_label($pp['start_time']) . ' – ' . exam_time_label($pp['end_time'])) ?></td>
          <td><span class="font-mono text-slate-500"><?= e($pp['code']) ?></span> <?= e($pp['name']) ?></td>
          <td class="whitespace-nowrap"><?= e($pp['room_code'] ?? '—') ?></td>
          <td></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <ul class="mt-3 list-disc space-y-0.5 pl-4 text-[9.5px] leading-snug text-slate-600">
    <?php foreach (array_slice($instructions, 0, 5) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
  </ul>
  <div class="mt-10 flex items-end justify-between text-[10px] text-slate-600">
    <div class="w-40 border-t border-slate-400 pt-1 text-center">Candidate's signature</div>
    <p class="text-[9px] text-slate-400">Downloaded from the student portal on <?= e(format_date(date('Y-m-d'))) ?> · not transferable</p>
    <div class="w-44 border-t border-slate-500 pt-1 text-center font-semibold text-slate-800"><?= e($controllerTitle) ?></div>
  </div>
</section>
<?php
    print_layout_end();
}

function ps_print_marksheet(array $d): void
{
    $m = $d['marksheet'];
    $r = $d['result'];
    $s = $d['student'] ?? [];
    $isFinal = (int) $r['is_final'] === 1;
    $scale = grade_scale();
    $controller = (string) setting('exam_controller_name', '');
    $controllerTitle = (string) setting('exam_controller_designation', 'Controller of Examinations');
    $affiliation = (string) setting('affiliation', '');
    $fmt = function ($v, int $dec = 2): string {
        if ($v === null || $v === '') {
            return '—';
        }
        $x = number_format((float) $v, $dec, '.', '');
        return str_contains($x, '.') ? rtrim(rtrim($x, '0'), '.') : $x;
    };
    $statusText = ['PASS' => 'PASS', 'BACKLOG' => 'PASS WITH BACKLOG', 'FAIL' => 'FAIL', 'ABSENT' => 'ABSENT'][$r['result_status']] ?? $r['result_status'];
    $statusColor = ['PASS' => 'text-emerald-700', 'BACKLOG' => 'text-amber-700', 'FAIL' => 'text-red-700', 'ABSENT' => 'text-slate-600'][$r['result_status']] ?? 'text-slate-800';
    $photo = !empty($s['photo']) ? upload_url($s['photo']) : '';
    ps_print_headers();
    print_layout_start($m ? 'Marksheet ' . $m['marksheet_no'] : 'Statement of marks (provisional)', false);
    ?>
<style>
  .ms-frame{position:relative;border:2px solid #0B2A5B;padding:5mm;min-height:268mm;display:flex;flex-direction:column}
  .ms-frame:before{content:"";position:absolute;inset:1.6mm;border:.6px solid #22943F;pointer-events:none}
  .ms-watermark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:.045}
  .ms-watermark img{width:62%}
  .ms-table th,.ms-table td{border:1px solid #cbd5e1;padding:4px 5px}
  .ms-table thead th{background:#0B2A5B;color:#fff;font-weight:600;border-color:#0B2A5B}
  .ms-stamp{position:absolute;top:38%;left:50%;transform:translate(-50%,-50%) rotate(-18deg);font-size:60px;font-weight:800;letter-spacing:.2em;border:6px solid currentColor;padding:4px 24px;border-radius:12px;opacity:.16}
</style>
<div class="ms-frame">
  <div class="ms-watermark"><img src="<?= e(logo_url()) ?>" alt=""></div>
  <?php if (!$m): ?><div class="ms-stamp text-slate-500">PROVISIONAL</div><?php endif; ?>
  <header class="relative flex items-center gap-4 border-b-2 border-brand-900 pb-3">
    <img src="<?= e(logo_url()) ?>" alt="GIMT" class="h-16 w-auto">
    <div class="flex-1 text-center">
      <p class="font-display text-[19px] font-extrabold uppercase leading-tight tracking-wide text-brand-900"><?= e(institute_name()) ?></p>
      <?php if ($affiliation !== ''): ?><p class="mt-0.5 text-[10px] text-slate-600"><?= e($affiliation) ?></p><?php endif; ?>
      <p class="text-[10px] text-slate-500"><?= e(setting('address', '')) ?></p>
    </div>
    <div class="w-16"></div>
  </header>
  <div class="relative mt-3 text-center">
    <p class="inline-block rounded-full bg-brand-900 px-5 py-1 text-[12px] font-bold uppercase tracking-[0.2em] text-white"><?= $isFinal ? 'Grade Card · Statement of Marks' : 'Statement of Marks' ?></p>
    <p class="mt-2 text-[12px] font-semibold text-slate-800"><?= e($r['type_name']) ?><?= $r['end_date'] ? ' — ' . e(date('F Y', strtotime($r['end_date']))) : '' ?></p>
    <p class="text-[10.5px] text-slate-600"><?= e($r['program_name'] ?? '') ?> · Semester <?= e($r['semester_no']) ?> · Academic Session <?= e($r['session_name'] ?? '') ?></p>
  </div>
  <div class="relative mt-3 flex gap-4">
    <table class="w-full text-[10.5px]">
      <tbody>
        <tr><td class="w-[24%] py-0.5 text-slate-500">Name of the student</td><td class="py-0.5 font-bold uppercase text-slate-900" colspan="3"><?= e($r['student_name']) ?></td></tr>
        <tr><td class="py-0.5 text-slate-500">Father's name</td><td class="py-0.5 font-medium text-slate-800"><?= e($r['father_name'] ?: '—') ?></td>
            <td class="w-[18%] py-0.5 text-slate-500">Mother's name</td><td class="py-0.5 font-medium text-slate-800"><?= e($r['mother_name'] ?: '—') ?></td></tr>
        <tr><td class="py-0.5 text-slate-500">Enrollment no.</td><td class="py-0.5 font-mono font-semibold text-slate-800"><?= e($r['enrollment_no'] ?: $r['student_uid']) ?></td>
            <td class="py-0.5 text-slate-500">Roll no.</td><td class="py-0.5 font-mono font-semibold text-slate-800"><?= e($r['roll_no'] ?: '—') ?></td></tr>
        <tr><td class="py-0.5 text-slate-500">Student ID</td><td class="py-0.5 font-mono text-slate-800"><?= e($r['student_uid']) ?></td>
            <td class="py-0.5 text-slate-500">Programme</td><td class="py-0.5 font-medium text-slate-800"><?= e($r['program_short'] ?? '') ?><?= !empty($s['course_name']) ? ' (' . e($s['course_name']) . ')' : '' ?></td></tr>
        <tr><td class="py-0.5 text-slate-500">Examination</td><td class="py-0.5 text-slate-800" colspan="3"><?= e($r['exam_name']) ?></td></tr>
      </tbody>
    </table>
    <div class="flex h-[30mm] w-[24mm] shrink-0 items-center justify-center overflow-hidden rounded border border-slate-300 bg-slate-50">
      <?php if ($photo): ?><img src="<?= e($photo) ?>" alt="" class="h-full w-full object-cover"><?php else: ?><span class="font-display text-xl font-bold text-slate-400"><?= e(initials($r['student_name'])) ?></span><?php endif; ?>
    </div>
  </div>
  <table class="ms-table relative mt-3 w-full border-collapse text-[10px]">
    <thead>
      <tr><th class="w-6 text-center" rowspan="2">#</th><th class="text-left" rowspan="2">Code</th><th class="text-left" rowspan="2">Subject</th><th class="text-center" rowspan="2">Credits</th>
        <th class="text-center" colspan="4">Marks obtained (maximum)</th><th class="text-center" rowspan="2">Grade</th><th class="text-center" rowspan="2">Grade<br>point</th><th class="text-center" rowspan="2">Result</th></tr>
      <tr><th class="text-center">Internal</th><th class="text-center">External</th><th class="text-center">Practical</th><th class="text-center">Total</th></tr>
    </thead>
    <tbody>
      <?php foreach ($d['subjects'] as $k => $sub):
          $absent = (int) $sub['is_absent'] === 1;
          $cell = fn ($v, $max) => (int) $max > 0 ? '<span class="font-semibold">' . ($v === null ? ($absent ? 'AB' : '—') : e($fmt($v))) . '</span> <span class="text-slate-400">(' . (int) $max . ')</span>' : '<span class="text-slate-300">—</span>';
          ?>
        <tr class="<?= $k % 2 ? 'bg-slate-50/60' : '' ?>">
          <td class="text-center text-slate-500"><?= $k + 1 ?></td>
          <td class="font-mono"><?= e($sub['code']) ?></td>
          <td><?= e($sub['name']) ?></td>
          <td class="text-center"><?= e($fmt($sub['credits'], 1)) ?></td>
          <td class="text-center"><?= $cell($sub['internal_marks'], $sub['max_internal']) ?></td>
          <td class="text-center"><?= $cell($sub['external_marks'], $sub['max_external']) ?></td>
          <td class="text-center"><?= $cell($sub['practical_marks'], $sub['max_practical']) ?></td>
          <td class="text-center"><span class="font-bold"><?= e($fmt($sub['total_marks'])) ?></span> <span class="text-slate-400">(<?= (int) $sub['max_total'] ?>)</span></td>
          <td class="text-center font-bold text-brand-900"><?= e($sub['grade'] ?? '—') ?></td>
          <td class="text-center"><?= e($fmt($sub['grade_point'])) ?></td>
          <td class="text-center font-semibold <?= $absent ? 'text-slate-500' : ((int) $sub['is_pass'] ? 'text-emerald-700' : 'text-red-700') ?>"><?= $absent ? 'AB' : ((int) $sub['is_pass'] ? 'P' : 'F') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="relative mt-3 grid grid-cols-6 overflow-hidden rounded-lg border border-brand-900 text-center text-[10px]">
    <div class="border-r border-slate-200 p-2"><p class="text-slate-500">Marks obtained</p><p class="mt-0.5 text-[13px] font-bold text-slate-900"><?= e($fmt($r['total_marks'])) ?> / <?= e($fmt($r['max_marks'], 0)) ?></p></div>
    <div class="border-r border-slate-200 p-2"><p class="text-slate-500">Percentage</p><p class="mt-0.5 text-[13px] font-bold text-slate-900"><?= e($fmt($r['percentage'])) ?>%</p></div>
    <div class="border-r border-slate-200 p-2"><p class="text-slate-500">Credits earned</p><p class="mt-0.5 text-[13px] font-bold text-slate-900"><?= e($fmt($r['credits_earned'], 1)) ?> / <?= e($fmt($r['credits_registered'], 1)) ?></p></div>
    <div class="border-r border-slate-200 bg-brand-50 p-2"><p class="text-slate-500">SGPA</p><p class="mt-0.5 text-[15px] font-extrabold text-brand-900"><?= $isFinal && $r['sgpa'] !== null ? e(number_format((float) $r['sgpa'], 2)) : '—' ?></p></div>
    <div class="border-r border-slate-200 bg-brand-50 p-2"><p class="text-slate-500">CGPA</p><p class="mt-0.5 text-[15px] font-extrabold text-brand-900"><?= $isFinal && $r['cgpa'] !== null ? e(number_format((float) $r['cgpa'], 2)) : '—' ?></p></div>
    <div class="p-2"><p class="text-slate-500">Result</p><p class="mt-0.5 text-[12px] font-extrabold <?= $statusColor ?>"><?= e($statusText) ?></p></div>
  </div>
  <p class="relative mt-1.5 text-[10px] text-slate-600">
    <?= $r['division'] ? 'Division: <strong class="text-slate-800">' . e($r['division']) . '</strong> · ' : '' ?>
    <?= (int) $r['backlog_count'] > 0 && $r['result_status'] !== 'ABSENT' ? 'Subjects to re-appear: <strong class="text-red-700">' . (int) $r['backlog_count'] . '</strong> · ' : '' ?>
    Result declared on <?= e(format_date($r['result_date'] ?: $r['published_at'])) ?>
  </p>
  <?php if ($isFinal && $d['history']): ?>
    <div class="relative mt-3">
      <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Semester-wise performance</p>
      <table class="ms-table mt-1 w-full border-collapse text-center text-[10px]">
        <thead><tr><th class="text-left">Semester</th><?php foreach ($d['history'] as $h): ?><th><?= e($h['semester_no']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <tr><td class="text-left font-semibold">SGPA</td><?php foreach ($d['history'] as $h): ?><td><?= $h['sgpa'] !== null ? e(number_format((float) $h['sgpa'], 2)) : '—' ?></td><?php endforeach; ?></tr>
          <tr><td class="text-left font-semibold">CGPA</td><?php foreach ($d['history'] as $h): ?><td><?= $h['cgpa'] !== null ? e(number_format((float) $h['cgpa'], 2)) : '—' ?></td><?php endforeach; ?></tr>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="relative mt-3 rounded-lg bg-slate-50 px-3 py-2 text-[9px] leading-relaxed text-slate-600">
    <span class="font-semibold text-slate-700">Grading scale:</span>
    <?php foreach ($scale as $g): ?>
      <span class="whitespace-nowrap"><strong class="text-slate-800"><?= e($g['grade']) ?></strong> <?= e($fmt($g['min'])) ?>–<?= e($fmt($g['max'])) ?>% (<?= e($fmt($g['grade_point'])) ?>)</span><span class="text-slate-300"> · </span>
    <?php endforeach; ?>
    <strong class="text-slate-800">AB</strong> Absent.
  </div>
  <div class="relative mt-auto flex items-end justify-between gap-4 pt-6 text-[10px]">
    <div class="flex min-w-0 flex-1 items-end gap-3">
      <?php if ($m): ?><div data-qr="<?= e($d['verify_url']) ?>" class="h-[22mm] w-[22mm] shrink-0"></div><?php endif; ?>
      <div class="min-w-0 leading-snug text-slate-600">
        <?php if ($m): ?>
          <p class="whitespace-nowrap">Marksheet no. <strong class="font-mono text-slate-900"><?= e($m['marksheet_no']) ?></strong></p>
          <p>Date of issue: <strong class="text-slate-800"><?= e(format_date($m['issued_at'])) ?></strong></p>
          <p>Security code: <strong class="font-mono tracking-wider text-slate-900"><?= e(marksheet_security_code((string) $m['verification_code'])) ?></strong></p>
          <p class="text-[8.5px] text-slate-500">Student copy downloaded from the portal on <?= e(format_date(date('Y-m-d'))) ?>. Scan the QR code to verify online.</p>
        <?php else: ?>
          <p class="font-semibold text-slate-700">Provisional statement — not valid as a marksheet.</p>
          <p>Downloaded from the student portal on <?= e(format_date(date('Y-m-d'))) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <div class="shrink-0 text-center text-slate-600"><div class="h-10"></div><div class="w-36 border-t border-slate-500 pt-1 font-semibold text-slate-800"><?= e($controllerTitle) ?></div><?php if ($controller): ?><div class="text-[9px] text-slate-500"><?= e($controller) ?></div><?php endif; ?></div>
  </div>
</div>
<?php
    print_layout_end();
}

function ps_print_receipt(array $p, float $balance): void
{
    $total = (float) $p['amount'] + (float) $p['fine_amount'];
    $void = $p['status'] === 'cancelled';
    $relation = ($p['gender'] ?? '') === 'female' ? 'D/o' : 'S/o';
    $cell = 'border border-slate-200 px-2 py-1.5';
    $head = 'border border-slate-200 bg-slate-50 px-2 py-1.5 text-left font-semibold text-slate-600';
    ps_print_headers();
    print_layout_start('Fee Receipt ' . $p['receipt_no']);
    ?>
<div class="relative">
  <?php if ($void): ?>
    <div class="pointer-events-none absolute inset-x-0 top-24 flex justify-center"><span class="-rotate-12 rounded-xl border-4 border-red-500/60 px-6 py-2 font-display text-5xl font-extrabold uppercase tracking-widest text-red-500/50">Void</span></div>
  <?php endif; ?>
  <div class="mt-4 flex items-center justify-between">
    <h1 class="font-display text-lg font-extrabold uppercase tracking-wide text-brand-900">Fee Receipt</h1>
    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-slate-600">Student copy</span>
  </div>
  <div class="mt-3 grid grid-cols-3 gap-3 text-[11px] text-slate-600">
    <div>Receipt No<br><strong class="font-mono text-[12px] text-slate-900"><?= e($p['receipt_no']) ?></strong></div>
    <div>Payment date<br><strong class="text-[12px] text-slate-900"><?= e(format_date($p['payment_date'])) ?></strong></div>
    <div>Issued<br><strong class="text-[12px] text-slate-900"><?= e(format_datetime($p['issued_at'] ?? $p['created_at'])) ?></strong></div>
  </div>
  <table class="mt-3 w-full border-collapse text-[11px]">
    <tbody>
      <tr><th class="w-[24%] <?= $head ?>">Received from</th>
        <td class="<?= $cell ?> font-semibold text-slate-900"><?= e($p['student_name'] ?? '—') ?><?= !empty($p['father_name']) ? ' <span class="font-normal text-slate-500">(' . $relation . ' ' . e($p['father_name']) . ')</span>' : '' ?></td>
        <th class="w-[16%] <?= $head ?>">Student ID</th><td class="<?= $cell ?> font-mono"><?= e($p['student_uid'] ?? '—') ?></td></tr>
      <tr><th class="<?= $head ?>">Program</th>
        <td class="<?= $cell ?>"><?= e(trim(($p['program_short'] ?? '') . ' — ' . ($p['program_name'] ?? ''), ' —')) ?><?= $p['current_semester'] ? ' · Semester ' . (int) $p['current_semester'] : '' ?><?= $p['section_name'] ? ' · Section ' . e($p['section_name']) : '' ?></td>
        <th class="<?= $head ?>">Roll No</th><td class="<?= $cell ?>"><?= e($p['roll_no'] ?? '—') ?></td></tr>
      <tr><th class="<?= $head ?>">Payment mode</th>
        <td class="<?= $cell ?>"><?= e($p['mode_label']) ?><?= $p['bank_name'] ? ' · ' . e($p['bank_name']) : '' ?></td>
        <th class="<?= $head ?>">Reference</th><td class="<?= $cell ?> font-mono"><?= e($p['reference_no'] ?? '—') ?></td></tr>
    </tbody>
  </table>
  <table class="mt-3 w-full border-collapse text-[11px]">
    <thead><tr>
      <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Particulars</th>
      <th class="w-[22%] border border-brand-900 bg-brand-900 px-2 py-1.5 text-right font-semibold text-white">Amount (₹)</th>
    </tr></thead>
    <tbody>
      <?php foreach ($p['allocations'] as $a): $fee = (float) $a['amount'] - (float) $a['fine_amount']; ?>
        <tr>
          <td class="<?= $cell ?>"><span class="font-semibold text-slate-900"><?= e($a['title']) ?></span> <span class="font-mono text-slate-500">(<?= e($a['invoice_no']) ?>)</span>
            <?php if ($a['items']): ?><br><span class="text-[10px] text-slate-500"><?= e(implode(' · ', array_map(fn ($i) => $i['head_name'], $a['items']))) ?></span><?php endif; ?></td>
          <td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in($fee, 2)) ?></td>
        </tr>
        <?php if ((float) $a['fine_amount'] > 0): ?>
          <tr><td class="<?= $cell ?>">Late fee — <?= e($a['invoice_no']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $a['fine_amount'], 2)) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      <tr class="bg-slate-50"><td class="<?= $cell ?> font-bold text-slate-900">Total received</td><td class="<?= $cell ?> text-right font-bold tabular-nums text-slate-900"><?= e(number_in($total, 2)) ?></td></tr>
    </tbody>
  </table>
  <p class="mt-2 text-[11px] text-slate-700">Amount in words: <strong><?= e(fees_amount_in_words($total)) ?></strong></p>
  <?php foreach ($p['discounts'] as $dc): ?>
    <p class="mt-1 text-[10px] text-slate-500">Concession of <?= e(money((float) $dc['amount'], 2)) ?> allowed on <?= e($dc['invoice_no']) ?> — <?= e($dc['reason']) ?></p>
  <?php endforeach; ?>
  <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-[10px] text-slate-500">
    <span>Current outstanding balance (all invoices): <strong class="text-slate-800"><?= e(money($balance, 2)) ?></strong></span>
    <?php if ($void): ?><span class="font-semibold text-red-600">Voided on <?= e(format_datetime($p['cancelled_at'])) ?><?= $p['cancelled_reason'] ? ' — ' . e($p['cancelled_reason']) : '' ?></span><?php endif; ?>
  </div>
  <div class="mt-8 flex items-end justify-between text-[11px] text-slate-600">
    <p class="max-w-sm text-[10px] text-slate-400"><?= e(setting('receipt_footer_note', 'This is a computer generated receipt and does not require a signature.')) ?> Student copy downloaded from the portal on <?= e(format_date(date('Y-m-d'))) ?>.</p>
    <div class="text-right"><div class="w-48 border-t border-slate-400 pt-1">Accounts Office</div></div>
  </div>
</div>
<?php
    print_layout_end();
}

function ps_print_invoice(array $inv): void
{
    $pay = ps_payment_options();
    $stamp = ['paid' => ['Paid', 'border-emerald-500/60 text-emerald-600/60'], 'cancelled' => ['Cancelled', 'border-red-500/60 text-red-500/50'], 'waived' => ['Waived', 'border-violet-500/60 text-violet-600/50']][$inv['status']] ?? null;
    $statusClass = ['paid' => 'bg-emerald-50 text-emerald-700', 'overdue' => 'bg-red-50 text-red-700', 'partial' => 'bg-amber-50 text-amber-700', 'cancelled' => 'bg-red-50 text-red-700'][$inv['status']] ?? 'bg-slate-100 text-slate-700';
    $cell = 'border border-slate-200 px-2 py-1.5';
    ps_print_headers();
    print_layout_start('Invoice ' . $inv['invoice_no']);
    ?>
<div class="relative">
  <?php if ($stamp): ?>
    <div class="pointer-events-none absolute inset-x-0 top-40 flex justify-center"><span class="-rotate-12 rounded-xl border-4 px-8 py-2 font-display text-6xl font-extrabold uppercase tracking-widest <?= $stamp[1] ?>"><?= e($stamp[0]) ?></span></div>
  <?php endif; ?>
  <div class="mt-5 flex items-start justify-between gap-6">
    <div>
      <h1 class="font-display text-xl font-extrabold uppercase tracking-wide text-brand-900">Fee Invoice</h1>
      <p class="text-[11px] text-slate-500">Demand note · Academic session <?= e($inv['session_name'] ?? '—') ?></p>
      <span class="mt-2 inline-block rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider <?= $statusClass ?>"><?= e(label_from_key($inv['status'])) ?></span>
    </div>
    <table class="text-[11px]">
      <tr><td class="pr-3 text-slate-500">Invoice No</td><td class="font-mono font-semibold text-slate-900"><?= e($inv['invoice_no']) ?></td></tr>
      <tr><td class="pr-3 text-slate-500">Invoice date</td><td class="font-semibold text-slate-900"><?= e(format_date($inv['created_at'])) ?></td></tr>
      <tr><td class="pr-3 text-slate-500">Due date</td><td class="font-semibold text-slate-900"><?= e(format_date($inv['due_date'])) ?></td></tr>
    </table>
  </div>
  <div class="mt-4 grid grid-cols-2 gap-4 text-[11px]">
    <div class="rounded-lg border border-slate-200 p-3">
      <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Billed to</p>
      <p class="mt-1 text-[13px] font-bold text-slate-900"><?= e($inv['student_name']) ?></p>
      <p class="text-slate-600"><?= $inv['father_name'] ? 'Parent / guardian: ' . e($inv['father_name']) . '<br>' : '' ?>Student ID <span class="font-mono"><?= e($inv['student_uid']) ?></span><?= $inv['roll_no'] ? ' · Roll ' . e($inv['roll_no']) : '' ?></p>
      <p class="text-slate-600"><?= e(trim(($inv['program_short'] ?? '') . ' — ' . ($inv['program_name'] ?? ''), ' —')) ?><?= $inv['semester_no'] ? ' · Semester ' . (int) $inv['semester_no'] : '' ?><?= $inv['section_name'] ? ' · Section ' . e($inv['section_name']) : '' ?></p>
    </div>
    <div class="rounded-lg border border-slate-200 p-3">
      <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Summary</p>
      <table class="mt-1 w-full">
        <tr><td class="text-slate-600">Net payable</td><td class="text-right font-semibold tabular-nums"><?= e(money((float) $inv['net_amount'], 2)) ?></td></tr>
        <tr><td class="text-slate-600">Received</td><td class="text-right tabular-nums text-emerald-700"><?= e(money((float) $inv['paid_amount'], 2)) ?></td></tr>
        <tr><td class="font-semibold text-slate-900">Balance due</td><td class="text-right text-[13px] font-bold tabular-nums text-slate-900"><?= e(money(max(0, (float) $inv['balance_amount']), 2)) ?></td></tr>
        <?php if ((float) $inv['overdue_amount'] > 0): ?><tr><td class="text-red-600">Overdue now</td><td class="text-right font-semibold tabular-nums text-red-600"><?= e(money((float) $inv['overdue_amount'], 2)) ?></td></tr><?php endif; ?>
      </table>
    </div>
  </div>
  <table class="mt-4 w-full border-collapse text-[11px]">
    <thead><tr>
      <th class="w-10 border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">#</th>
      <th class="border border-brand-900 bg-brand-900 px-2 py-1.5 text-left font-semibold text-white">Fee head</th>
      <th class="w-[22%] border border-brand-900 bg-brand-900 px-2 py-1.5 text-right font-semibold text-white">Amount (₹)</th>
    </tr></thead>
    <tbody>
      <?php foreach ($inv['items'] as $i => $it): ?>
        <tr><td class="<?= $cell ?>"><?= $i + 1 ?></td><td class="<?= $cell ?>"><?= e($it['head_name']) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $it['amount'], 2)) ?></td></tr>
      <?php endforeach; ?>
      <tr class="bg-slate-50"><td class="<?= $cell ?>"></td><td class="<?= $cell ?> font-semibold">Gross amount</td><td class="<?= $cell ?> text-right font-semibold tabular-nums"><?= e(number_in((float) $inv['gross_amount'], 2)) ?></td></tr>
      <?php if ((float) $inv['scholarship_amount'] > 0): ?><tr><td class="<?= $cell ?>"></td><td class="<?= $cell ?>">Less: scholarship</td><td class="<?= $cell ?> text-right tabular-nums">− <?= e(number_in((float) $inv['scholarship_amount'], 2)) ?></td></tr><?php endif; ?>
      <?php if ((float) $inv['discount_amount'] > 0): ?><tr><td class="<?= $cell ?>"></td><td class="<?= $cell ?>">Less: discount / waiver</td><td class="<?= $cell ?> text-right tabular-nums">− <?= e(number_in((float) $inv['discount_amount'], 2)) ?></td></tr><?php endif; ?>
      <?php if ((float) $inv['fine_amount'] > 0): ?><tr><td class="<?= $cell ?>"></td><td class="<?= $cell ?>">Add: late fee</td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $inv['fine_amount'], 2)) ?></td></tr><?php endif; ?>
      <tr class="bg-slate-50"><td class="<?= $cell ?>"></td><td class="<?= $cell ?> font-bold text-slate-900">Net payable</td><td class="<?= $cell ?> text-right font-bold tabular-nums text-slate-900"><?= e(number_in((float) $inv['net_amount'], 2)) ?></td></tr>
    </tbody>
  </table>
  <p class="mt-2 text-[11px] text-slate-700">Amount in words: <strong><?= e(fees_amount_in_words((float) $inv['net_amount'])) ?></strong></p>
  <?php if ($inv['installments']): ?>
    <h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Installment schedule</h2>
    <table class="mt-2 w-full border-collapse text-[11px]">
      <thead><tr class="bg-slate-50 text-slate-600"><th class="<?= $cell ?> text-left font-semibold">Installment</th><th class="<?= $cell ?> text-left font-semibold">Due date</th>
        <th class="<?= $cell ?> text-right font-semibold">Amount</th><th class="<?= $cell ?> text-right font-semibold">Paid</th><th class="<?= $cell ?> text-left font-semibold">Status</th></tr></thead>
      <tbody>
        <?php foreach ($inv['installments'] as $i): ?>
          <tr><td class="<?= $cell ?>">Installment <?= (int) $i['installment_no'] ?></td><td class="<?= $cell ?>"><?= e(format_date($i['due_date'])) ?></td>
            <td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $i['amount'], 2)) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $i['paid_amount'], 2)) ?></td>
            <td class="<?= $cell ?>"><?= e(label_from_key($i['status'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <?php if ($inv['payments']): ?>
    <h2 class="mt-5 text-[12px] font-bold uppercase tracking-wide text-brand-900">Payments received</h2>
    <table class="mt-2 w-full border-collapse text-[11px]">
      <thead><tr class="bg-slate-50 text-slate-600"><th class="<?= $cell ?> text-left font-semibold">Receipt</th><th class="<?= $cell ?> text-left font-semibold">Date</th><th class="<?= $cell ?> text-left font-semibold">Mode</th><th class="<?= $cell ?> text-right font-semibold">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($inv['payments'] as $pm): ?>
          <tr class="<?= $pm['status'] === 'cancelled' ? 'text-slate-400 line-through' : '' ?>"><td class="<?= $cell ?> font-mono"><?= e($pm['receipt_no']) ?></td><td class="<?= $cell ?>"><?= e(format_date($pm['payment_date'])) ?></td>
            <td class="<?= $cell ?>"><?= e(fees_mode_label($pm['mode'])) ?></td><td class="<?= $cell ?> text-right tabular-nums"><?= e(number_in((float) $pm['applied_amount'], 2)) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <div class="mt-6 rounded-lg bg-slate-50 p-3 text-[10.5px] text-slate-600">
    <p class="font-semibold text-slate-800">How to pay</p>
    <ul class="mt-1 list-disc space-y-0.5 pl-4">
      <li>At the accounts office (<?= e(implode(' / ', array_map(fn ($m) => $m['label'], array_filter($pay['modes'], fn ($m) => $m['key'] !== 'online')))) ?>), <?= e($pay['office']['hours']) ?>.</li>
      <?php if ($pay['bank']): ?><li>Bank transfer: <?= e(implode(' · ', array_values($pay['bank']))) ?> — quote invoice <?= e($inv['invoice_no']) ?> as the reference.</li><?php endif; ?>
      <?php if ($pay['upi_id']): ?><li>UPI: <?= e($pay['upi_id']) ?> — add invoice <?= e($inv['invoice_no']) ?> in the note.</li><?php endif; ?>
      <li>Late fee: <?= (float) $inv['late_fee_rate']['per_day'] > 0 ? e(money($inv['late_fee_rate']['per_day'])) . ' per day after the due date' : 'as per institute rules' ?>.</li>
    </ul>
  </div>
  <p class="mt-4 text-center text-[9.5px] text-slate-400">Computer generated invoice · student copy downloaded from the portal on <?= e(format_date(date('Y-m-d'))) ?>.</p>
</div>
<?php
    print_layout_end();
}
