<?php
/**
 * Attendance domain logic (students + faculty/staff), shared by api/routes/attendance.php,
 * the CRUD modules (attendance, attendance_records, holidays) and admin/print/attendance-report.php.
 *
 * Concepts
 *  - A "sheet" (attendance row) is one class period for a section+subject(+time slot) on a date, or one
 *    daily sheet per employee type (faculty|staff). attendance.sheet_key makes it unique (NULL-safe).
 *  - Records (attendance_records) hold one status per person per sheet:
 *    present | absent | late | leave (students)  + half_day (employees).
 *  - Attendance % = (present + late* + 0.5 x half_day) / marked x 100   (*late counts when the
 *    academic setting late_counts_as_present = 1).
 *  - Holiday guard: no student attendance on weekly offs (setting attendance_weekly_off, default Sunday),
 *    holidays or vacations; exam breaks / events only warn. Future dates are never allowed.
 *  - Faculty users without attendance.manage may only mark classes assigned to them (faculty_subjects,
 *    timetable or class-teacher sections).
 */

/** Thrown when the user may not perform an attendance action (mapped to HTTP 403 by the routes). */
class AttendanceAccessException extends RuntimeException
{
}

/* ------------------------------------------------------------------
 * Settings & SQL helpers
 * ------------------------------------------------------------------ */

function att_student_statuses(): array
{
    return ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'leave' => 'Leave'];
}

function att_employee_statuses(): array
{
    return ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'leave' => 'Leave', 'half_day' => 'Half Day'];
}

function att_status_code(?string $status): string
{
    return ['present' => 'P', 'absent' => 'A', 'late' => 'L', 'leave' => 'LV', 'half_day' => 'HD'][$status ?? ''] ?? '';
}

function att_min_percent(): float
{
    $v = (float) setting('min_attendance_percent', 75);
    return $v > 0 && $v <= 100 ? $v : 75.0;
}

function att_late_counts(): bool
{
    return (string) setting('late_counts_as_present', '1') === '1';
}

/** SQL expression: attended weight of one record. */
function att_attended_expr(string $col = 'r.status'): string
{
    $late = att_late_counts() ? '1' : '0';
    return "(CASE $col WHEN 'present' THEN 1 WHEN 'late' THEN $late WHEN 'half_day' THEN 0.5 ELSE 0 END)";
}

/** SQL expression: attendance percentage over a grouped set of records. */
function att_pct_expr(string $col = 'r.status', string $count = 'COUNT(r.id)'): string
{
    return 'ROUND(100 * SUM(' . att_attended_expr($col) . ") / NULLIF($count, 0), 1)";
}

/** ISO weekday numbers (1 = Monday ... 7 = Sunday) that are weekly offs. */
function att_weekly_off(): array
{
    $days = array_values(array_filter(array_map('intval', explode(',', (string) setting('attendance_weekly_off', '7'))), fn ($d) => $d >= 1 && $d <= 7));
    return $days ?: [7];
}

function att_employee_late_after(): string
{
    $v = (string) setting('attendance_late_after', '09:15');
    return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : '09:15';
}

function att_valid_date($v): bool
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}

function att_valid_month($v): bool
{
    return is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) === 1;
}

function att_sheet_key(string $type, string $date, ?int $sectionId = null, ?int $subjectId = null, ?int $slotId = null): string
{
    return $type . '|' . $date . '|' . (int) $sectionId . '|' . (int) $subjectId . '|' . (int) $slotId;
}

function att_person_name_sql(string $a): string
{
    return "TRIM(CONCAT_WS(' ', $a.first_name, $a.last_name))";
}

function att_class_label_sql(string $p = 'p', string $sc = 'sc'): string
{
    return "CONCAT($p.short_name, ' · Sem ', $sc.semester_no, ' · Sec ', $sc.name)";
}

/* ------------------------------------------------------------------
 * Holiday guard
 * ------------------------------------------------------------------ */

function att_holiday_on(string $date): ?array
{
    return db_row("SELECT id, title, type, holiday_date, end_date, description FROM holidays
                   WHERE holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ?
                   ORDER BY CASE type WHEN 'holiday' THEN 1 WHEN 'vacation' THEN 2 WHEN 'exam_break' THEN 3 ELSE 4 END, id LIMIT 1", [$date, $date]);
}

/** Holidays overlapping a date range, expanded to date => holiday. */
function att_holiday_map(string $from, string $to): array
{
    $map = [];
    $rows = db_all('SELECT id, title, type, holiday_date, end_date FROM holidays WHERE holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ? ORDER BY holiday_date', [$to, $from]);
    $rank = ['holiday' => 1, 'vacation' => 2, 'exam_break' => 3, 'event' => 4];
    foreach ($rows as $h) {
        $start = max(strtotime($h['holiday_date']), strtotime($from));
        $end = min(strtotime($h['end_date'] ?: $h['holiday_date']), strtotime($to));
        for ($t = $start; $t <= $end; $t += 86400) {
            $d = date('Y-m-d', $t);
            if (!isset($map[$d]) || ($rank[$h['type']] ?? 9) < ($rank[$map[$d]['type']] ?? 9)) {
                $map[$d] = ['id' => (int) $h['id'], 'title' => $h['title'], 'type' => $h['type']];
            }
        }
    }
    return $map;
}

/**
 * Day information for marking: holiday, weekly off and the blocking/warning message.
 * $forEmployees: employees may be marked on holidays/weekly offs (with a warning).
 */
function att_day_info(string $date, bool $forEmployees = false): array
{
    $h = att_holiday_on($date);
    $ts = strtotime($date);
    $weeklyOff = in_array((int) date('N', $ts), att_weekly_off(), true);
    $future = $date > date('Y-m-d');
    $blocked = null;
    $warning = null;
    $typeLabel = ['holiday' => 'holiday', 'vacation' => 'vacation', 'exam_break' => 'examination break', 'event' => 'institute event'];
    if ($future) {
        $blocked = 'Attendance cannot be marked for a future date.';
    } elseif ($weeklyOff) {
        $msg = date('l', $ts) . ' is a weekly off.';
        if ($forEmployees) {
            $warning = $msg . ' Mark only employees who are on duty.';
        } else {
            $blocked = $msg . ' Attendance cannot be marked.';
        }
    } elseif ($h && in_array($h['type'], ['holiday', 'vacation'], true)) {
        $msg = $h['title'] . ' - the institute is closed (' . $typeLabel[$h['type']] . ').';
        if ($forEmployees) {
            $warning = $msg . ' Mark only employees who are on duty.';
        } else {
            $blocked = $msg . ' Attendance cannot be marked.';
        }
    } elseif ($h) {
        $warning = $h['title'] . ' (' . ($typeLabel[$h['type']] ?? $h['type']) . ') - regular classes may not be scheduled today.';
    }
    return [
        'date' => $date, 'day_name' => date('l', $ts), 'label' => format_date($date), 'holiday' => $h, 'weekly_off' => $weeklyOff,
        'future' => $future, 'blocked' => $blocked, 'warning' => $warning,
    ];
}

/* ------------------------------------------------------------------
 * Faculty scope (who may mark which classes)
 * ------------------------------------------------------------------ */

/** null = unrestricted; otherwise the faculty member's assigned section/subject pairs. */
function att_faculty_scope(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (!user_id() || is_super_admin() || can('attendance', 'manage')) {
        return $cache = null;
    }
    $fid = (int) (current_user()['faculty_id'] ?? 0);
    if (!$fid) {
        return $cache = null;
    }
    $pairs = [];
    foreach (db_all('SELECT section_id, subject_id FROM faculty_subjects WHERE faculty_id = ? AND section_id IS NOT NULL', [$fid]) as $r) {
        $pairs[(int) $r['section_id'] . ':' . (int) $r['subject_id']] = true;
    }
    foreach (db_all('SELECT DISTINCT section_id, subject_id FROM timetables WHERE faculty_id = ? AND academic_session_id = ?', [$fid, current_session_id()]) as $r) {
        $pairs[(int) $r['section_id'] . ':' . (int) $r['subject_id']] = true;
    }
    $classTeacher = array_map('intval', db_column('SELECT id FROM sections WHERE class_teacher_id = ?', [$fid]));
    $sections = $classTeacher;
    foreach (array_keys($pairs) as $k) {
        $sections[] = (int) explode(':', $k)[0];
    }
    return $cache = ['faculty_id' => $fid, 'pairs' => $pairs, 'class_teacher' => $classTeacher, 'sections' => array_values(array_unique($sections))];
}

function att_can_mark_class(int $sectionId, int $subjectId): bool
{
    $s = att_faculty_scope();
    if ($s === null) {
        return true;
    }
    return in_array($sectionId, $s['class_teacher'], true) || isset($s['pairs'][$sectionId . ':' . $subjectId]);
}

/* ------------------------------------------------------------------
 * Lookups
 * ------------------------------------------------------------------ */

function att_section(int $id): ?array
{
    $row = db_row('SELECT sc.*, p.short_name AS program_short, p.name AS program_name, p.department_id, ' . att_class_label_sql() . " AS label,
                   TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS class_teacher_name,
                   (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS students_count
                   FROM sections sc JOIN programs p ON p.id = sc.program_id LEFT JOIN faculty f ON f.id = sc.class_teacher_id WHERE sc.id = ?", [$id]);
    if ($row) {
        $row['id'] = (int) $row['id'];
        $row['students_count'] = (int) $row['students_count'];
    }
    return $row;
}

/** Sections of the current session for the marking pickers (restricted for faculty). */
function att_section_options(): array
{
    $rows = db_all('SELECT sc.id AS value, ' . att_class_label_sql() . " AS label, sc.program_id, sc.semester_no,
                    (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS students
                    FROM sections sc JOIN programs p ON p.id = sc.program_id
                    WHERE sc.status = 'active' AND (sc.academic_session_id = ? OR sc.academic_session_id IS NULL)
                    ORDER BY p.sort_order, p.short_name, sc.semester_no, sc.name", [current_session_id()]);
    $scope = att_faculty_scope();
    $out = [];
    foreach ($rows as $r) {
        if ($scope !== null && !in_array((int) $r['value'], $scope['sections'], true)) {
            continue;
        }
        $out[] = ['value' => (int) $r['value'], 'label' => $r['label'], 'sub' => $r['students'] . ' students', 'program_id' => (int) $r['program_id'], 'semester_no' => (int) $r['semester_no']];
    }
    return $out;
}

/** Subjects of a section (program + semester) with the assigned faculty. */
function att_section_subjects(array $sec): array
{
    $rows = db_all("SELECT sb.id, sb.code, sb.name, sb.type,
                    (SELECT fs.faculty_id FROM faculty_subjects fs WHERE fs.subject_id = sb.id AND fs.section_id = ? ORDER BY fs.is_primary DESC, fs.id DESC LIMIT 1) AS faculty_id
                    FROM subjects sb WHERE sb.program_id = ? AND sb.semester_no = ? AND sb.status = 'active' ORDER BY sb.code", [$sec['id'], $sec['program_id'], $sec['semester_no']]);
    $names = att_faculty_names(array_filter(array_column($rows, 'faculty_id')));
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['faculty_id'] = $r['faculty_id'] ? (int) $r['faculty_id'] : null;
        $r['faculty_name'] = $r['faculty_id'] ? ($names[$r['faculty_id']] ?? null) : null;
        $r['allowed'] = att_can_mark_class((int) $sec['id'], $r['id']);
    }
    return $rows;
}

function att_faculty_names(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return [];
    }
    return db_pairs("SELECT id, TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE id IN (" . implode(',', $ids) . ')');
}

function att_time_slots(): array
{
    return array_map(fn ($r) => $r + ['label' => $r['name'] . ' (' . date('h:i A', strtotime($r['start_time'])) . ' - ' . date('h:i A', strtotime($r['end_time'])) . ')'],
        db_all("SELECT id, name, start_time, end_time FROM time_slots WHERE is_break = 0 AND status = 'active' ORDER BY sort_order, start_time"));
}

/** Count summary for a set of sheets (from the per-sheet count columns): [attendance_id => [present, absent, late, leave, half_day, total, percent]] */
function att_sheet_counts(array $sheetIds): array
{
    $sheetIds = array_values(array_unique(array_filter(array_map('intval', $sheetIds))));
    if (!$sheetIds) {
        return [];
    }
    $out = [];
    foreach (db_all('SELECT id, total_count, present_count, absent_count, late_count, leave_count, half_day_count FROM attendance WHERE id IN (' . implode(',', $sheetIds) . ')') as $r) {
        $out[(int) $r['id']] = att_counts_from_row($r);
    }
    return $out;
}

function att_counts_from_row(array $r): array
{
    $total = (int) $r['total_count'];
    return [
        'present' => (int) $r['present_count'], 'absent' => (int) $r['absent_count'], 'late' => (int) $r['late_count'], 'leave' => (int) $r['leave_count'],
        'half_day' => (int) $r['half_day_count'], 'total' => $total,
        'percent' => $total ? round(100 * ((int) $r['present_count'] + (att_late_counts() ? (int) $r['late_count'] : 0) + 0.5 * (int) $r['half_day_count']) / $total, 1) : null,
    ];
}

/** SQL: attended weight summed from the per-sheet count columns of attendance alias $a. */
function att_sheet_attended_sql(string $a = 'a'): string
{
    $late = att_late_counts() ? 1 : 0;
    return "($a.present_count + $late * $a.late_count + 0.5 * $a.half_day_count)";
}

/** Recalculate the per-sheet status counts from the records. */
function att_refresh_sheet_counts(array $sheetIds): void
{
    $sheetIds = array_values(array_unique(array_filter(array_map('intval', $sheetIds))));
    if (!$sheetIds) {
        return;
    }
    $in = implode(',', $sheetIds);
    db_exec("UPDATE attendance SET total_count = 0, present_count = 0, absent_count = 0, late_count = 0, leave_count = 0, half_day_count = 0 WHERE id IN ($in)");
    db_exec("UPDATE attendance a JOIN (SELECT attendance_id, COUNT(*) AS t, SUM(status = 'present') AS p, SUM(status = 'absent') AS ab, SUM(status = 'late') AS l,
             SUM(status = 'leave') AS lv, SUM(status = 'half_day') AS hd FROM attendance_records WHERE attendance_id IN ($in) GROUP BY attendance_id) c ON c.attendance_id = a.id
             SET a.total_count = c.t, a.present_count = c.p, a.absent_count = c.ab, a.late_count = c.l, a.leave_count = c.lv, a.half_day_count = c.hd");
}

/** Recalculate the per-student session totals (attendance_summaries) for the given students. */
function att_refresh_summaries(array $studentIds): void
{
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    foreach (array_chunk($studentIds, 300) as $chunk) {
        $in = implode(',', $chunk);
        db_exec("DELETE FROM attendance_summaries WHERE student_id IN ($in)");
        db_exec("INSERT INTO attendance_summaries (student_id, academic_session_id, held, present, absent, late, on_leave, last_date)
                 SELECT r.person_id, a.academic_session_id, COUNT(*), SUM(r.status = 'present'), SUM(r.status = 'absent'), SUM(r.status = 'late'), SUM(r.status = 'leave'), MAX(a.attendance_date)
                 FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id
                 WHERE r.person_type = 'student' AND a.type = 'student' AND a.academic_session_id IS NOT NULL AND r.person_id IN ($in)
                 GROUP BY r.person_id, a.academic_session_id");
    }
}

/** Full rebuild of sheet counts + student summaries (seeder / maintenance). */
function att_rebuild_aggregates(): void
{
    db_exec("UPDATE attendance a JOIN (SELECT attendance_id, COUNT(*) AS t, SUM(status = 'present') AS p, SUM(status = 'absent') AS ab, SUM(status = 'late') AS l,
             SUM(status = 'leave') AS lv, SUM(status = 'half_day') AS hd FROM attendance_records GROUP BY attendance_id) c ON c.attendance_id = a.id
             SET a.total_count = c.t, a.present_count = c.p, a.absent_count = c.ab, a.late_count = c.l, a.leave_count = c.lv, a.half_day_count = c.hd");
    db_exec('DELETE FROM attendance_summaries');
    db_exec("INSERT INTO attendance_summaries (student_id, academic_session_id, held, present, absent, late, on_leave, last_date)
             SELECT r.person_id, a.academic_session_id, COUNT(*), SUM(r.status = 'present'), SUM(r.status = 'absent'), SUM(r.status = 'late'), SUM(r.status = 'leave'), MAX(a.attendance_date)
             FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id JOIN students s ON s.id = r.person_id
             WHERE r.person_type = 'student' AND a.type = 'student' AND a.academic_session_id IS NOT NULL
             GROUP BY r.person_id, a.academic_session_id");
}

/** Keep aggregates in sync after records of these sheets changed. */
function att_after_records_changed(array $sheetIds, array $studentIds = []): void
{
    att_refresh_sheet_counts($sheetIds);
    if ($studentIds) {
        att_refresh_summaries($studentIds);
    }
}

/* ------------------------------------------------------------------
 * Student marking
 * ------------------------------------------------------------------ */

/** Periods for a section on a date: timetable periods when present, else the section's subjects + time slots. */
function att_periods(int $sectionId, string $date): array
{
    $sec = att_section($sectionId);
    if (!$sec) {
        throw new CrudValidationException(['section_id' => 'Select a valid section.']);
    }
    $dow = (int) date('N', strtotime($date));
    $sessionId = $sec['academic_session_id'] ?: current_session_id();
    $tt = db_all("SELECT t.id AS timetable_id, t.time_slot_id, t.subject_id, t.faculty_id, t.type, ts.name AS slot_name, ts.start_time, ts.end_time,
                  sb.code AS subject_code, sb.name AS subject_name, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name
                  FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN subjects sb ON sb.id = t.subject_id LEFT JOIN faculty f ON f.id = t.faculty_id
                  WHERE t.section_id = ? AND t.day_of_week = ? AND t.academic_session_id = ? AND t.status = 'published' AND ts.is_break = 0
                  ORDER BY ts.sort_order, ts.start_time", [$sectionId, $dow, $sessionId]);
    $sheets = db_all("SELECT a.id, a.subject_id, a.time_slot_id, a.is_locked, a.updated_at, a.created_at, sb.code AS subject_code, sb.name AS subject_name, ts.name AS slot_name, ts.start_time
                      FROM attendance a JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN time_slots ts ON ts.id = a.time_slot_id
                      WHERE a.type = 'student' AND a.section_id = ? AND a.attendance_date = ? ORDER BY ts.sort_order, a.id", [$sectionId, $date]);
    $counts = att_sheet_counts(array_column($sheets, 'id'));
    $byKey = [];
    foreach ($sheets as &$s) {
        $s['id'] = (int) $s['id'];
        $s['subject_id'] = (int) $s['subject_id'];
        $s['time_slot_id'] = $s['time_slot_id'] ? (int) $s['time_slot_id'] : null;
        $s['is_locked'] = (bool) $s['is_locked'];
        $s['counts'] = $counts[$s['id']] ?? null;
        $byKey[$s['subject_id'] . ':' . (int) $s['time_slot_id']] = $s;
    }
    unset($s);
    $periods = [];
    foreach ($tt as $p) {
        $k = (int) $p['subject_id'] . ':' . (int) $p['time_slot_id'];
        $periods[] = [
            'timetable_id' => (int) $p['timetable_id'], 'time_slot_id' => (int) $p['time_slot_id'], 'subject_id' => (int) $p['subject_id'],
            'faculty_id' => $p['faculty_id'] ? (int) $p['faculty_id'] : null, 'faculty_name' => $p['faculty_name'] ?: null, 'type' => $p['type'],
            'slot_name' => $p['slot_name'], 'start_time' => $p['start_time'], 'end_time' => $p['end_time'], 'subject_code' => $p['subject_code'],
            'subject_name' => $p['subject_name'], 'sheet' => $byKey[$k] ?? null, 'allowed' => att_can_mark_class($sectionId, (int) $p['subject_id']),
        ];
    }
    return [
        'section' => $sec, 'day' => att_day_info($date), 'source' => $periods ? 'timetable' : 'subjects', 'periods' => $periods,
        'subjects' => att_section_subjects($sec), 'slots' => att_time_slots(), 'sheets' => $sheets,
    ];
}

/** Attendance % per student (session-to-date by default), optionally also for one subject. */
function att_student_percents(array $studentIds, ?int $subjectId = null, ?string $from = null, ?string $to = null): array
{
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$studentIds) {
        return [];
    }
    $sess = current_session();
    $from = $from ?: ($sess['start_date'] ?? date('Y-01-01'));
    $to = $to ?: date('Y-m-d');
    $att = att_attended_expr();
    $sql = "SELECT r.person_id, COUNT(*) AS held, SUM($att) AS attended"
        . ($subjectId ? ", SUM(a.subject_id = ?) AS subj_held, SUM(CASE WHEN a.subject_id = ? THEN $att ELSE 0 END) AS subj_attended" : '')
        . " FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id
           WHERE r.person_type = 'student' AND a.type = 'student' AND a.attendance_date BETWEEN ? AND ? AND r.person_id IN (" . implode(',', $studentIds) . ')
           GROUP BY r.person_id';
    $args = $subjectId ? [$subjectId, $subjectId, $from, $to] : [$from, $to];
    $out = [];
    foreach (db_all($sql, $args) as $r) {
        $out[(int) $r['person_id']] = [
            'held' => (int) $r['held'], 'attended' => (float) $r['attended'],
            'percent' => $r['held'] ? round(100 * $r['attended'] / $r['held'], 1) : null,
            'subject_held' => isset($r['subj_held']) ? (int) $r['subj_held'] : null,
            'subject_percent' => !empty($r['subj_held']) ? round(100 * $r['subj_attended'] / $r['subj_held'], 1) : null,
        ];
    }
    return $out;
}

/** Load the roster + existing sheet for a class period. */
function att_student_sheet(int $sectionId, int $subjectId, ?int $slotId, string $date): array
{
    $sec = att_section($sectionId);
    if (!$sec) {
        throw new CrudValidationException(['section_id' => 'Select a valid section.']);
    }
    $subject = db_row('SELECT id, code, name, type FROM subjects WHERE id = ? AND program_id = ? AND semester_no = ?', [$subjectId, $sec['program_id'], $sec['semester_no']]);
    if (!$subject) {
        throw new CrudValidationException(['subject_id' => 'This subject is not taught in the selected section.']);
    }
    $slot = null;
    if ($slotId) {
        $slot = db_row('SELECT id, name, start_time, end_time FROM time_slots WHERE id = ? AND is_break = 0', [$slotId]);
        if (!$slot) {
            throw new CrudValidationException(['time_slot_id' => 'Select a valid period.']);
        }
    }
    $sheet = db_row("SELECT a.id, a.method, a.remarks, a.is_locked, a.faculty_id, a.taken_by, a.created_at, a.updated_at, u.name AS taken_by_name,
                     TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name
                     FROM attendance a LEFT JOIN users u ON u.id = a.taken_by LEFT JOIN faculty f ON f.id = a.faculty_id WHERE a.sheet_key = ?",
        [att_sheet_key('student', $date, $sectionId, $subjectId, $slotId)]);
    $records = [];
    if ($sheet) {
        $sheet['id'] = (int) $sheet['id'];
        $sheet['is_locked'] = (bool) $sheet['is_locked'];
        foreach (db_all("SELECT person_id, status, remarks FROM attendance_records WHERE attendance_id = ? AND person_type = 'student'", [$sheet['id']]) as $r) {
            $records[(int) $r['person_id']] = $r;
        }
    }
    $students = db_all("SELECT s.id, s.student_uid, s.roll_no, s.first_name, s.middle_name, s.last_name, s.photo, s.gender, s.section_id
                        FROM students s WHERE s.section_id = ? AND s.status = 'active' ORDER BY s.roll_no, s.first_name, s.id", [$sectionId]);
    $inRoster = array_flip(array_map(fn ($s) => (int) $s['id'], $students));
    $extra = array_diff(array_keys($records), array_keys($inRoster));
    if ($extra) {
        foreach (db_all('SELECT s.id, s.student_uid, s.roll_no, s.first_name, s.middle_name, s.last_name, s.photo, s.gender, s.section_id FROM students s WHERE s.id IN (' . implode(',', array_map('intval', $extra)) . ')') as $s) {
            $s['moved'] = true;
            $students[] = $s;
        }
    }
    $pct = att_student_percents(array_map(fn ($s) => (int) $s['id'], $students), $subjectId);
    $min = att_min_percent();
    $out = [];
    foreach ($students as $s) {
        $id = (int) $s['id'];
        $p = $pct[$id] ?? null;
        $out[] = [
            'id' => $id, 'name' => trim(implode(' ', array_filter([$s['first_name'], $s['middle_name'], $s['last_name']]))), 'student_uid' => $s['student_uid'],
            'roll_no' => $s['roll_no'], 'photo' => $s['photo'], 'gender' => $s['gender'], 'moved' => !empty($s['moved']),
            'status' => $records[$id]['status'] ?? null, 'remarks' => $records[$id]['remarks'] ?? null,
            'percent' => $p['percent'] ?? null, 'held' => $p['held'] ?? 0, 'subject_percent' => $p['subject_percent'] ?? null,
            'below' => $p && $p['percent'] !== null && $p['percent'] < $min,
        ];
    }
    // Default conducting faculty: timetable faculty for the period, else the subject's assigned faculty, else the current faculty user
    $defaultFaculty = null;
    if ($slotId) {
        $defaultFaculty = db_value('SELECT faculty_id FROM timetables WHERE section_id = ? AND subject_id = ? AND time_slot_id = ? AND day_of_week = ? AND academic_session_id = ? LIMIT 1',
            [$sectionId, $subjectId, $slotId, (int) date('N', strtotime($date)), $sec['academic_session_id'] ?: current_session_id()]);
    }
    $defaultFaculty = $defaultFaculty ?: db_value('SELECT faculty_id FROM faculty_subjects WHERE subject_id = ? AND section_id = ? ORDER BY is_primary DESC, id DESC LIMIT 1', [$subjectId, $sectionId]);
    $defaultFaculty = $defaultFaculty ?: (current_user()['faculty_id'] ?? null);
    $allowed = att_can_mark_class($sectionId, $subjectId);
    return [
        'section' => $sec, 'subject' => $subject, 'slot' => $slot, 'date' => $date, 'day' => att_day_info($date), 'sheet' => $sheet,
        'default_faculty_id' => $defaultFaculty ? (int) $defaultFaculty : null,
        'default_faculty_name' => $defaultFaculty ? (att_faculty_names([$defaultFaculty])[(int) $defaultFaculty] ?? null) : null,
        'students' => $out, 'min_percent' => $min,
        'can_save' => $allowed && ($sheet ? can('attendance', 'edit') : can('attendance', 'create')) && (!$sheet || !$sheet['is_locked'] || can('attendance', 'approve')),
        'restricted' => $allowed ? null : 'This class is not assigned to you. You can view it but only the assigned faculty or an attendance administrator can mark it.',
    ];
}

/**
 * Create or update the attendance sheet for a class period.
 * $in: date, section_id, subject_id, time_slot_id?, faculty_id?, remarks?, records: [{student_id, status, remarks?}]
 */
function att_save_student_sheet(array $in): array
{
    $errors = validate($in, [
        'date' => 'required|date', 'section_id' => 'required|integer', 'subject_id' => 'required|integer',
        'time_slot_id' => 'integer', 'faculty_id' => 'integer', 'remarks' => 'max:255',
    ], ['section_id' => 'Section', 'subject_id' => 'Subject', 'time_slot_id' => 'Period', 'faculty_id' => 'Faculty']);
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $date = (string) $in['date'];
    $sectionId = (int) $in['section_id'];
    $subjectId = (int) $in['subject_id'];
    $slotId = !empty($in['time_slot_id']) ? (int) $in['time_slot_id'] : null;
    $facultyId = !empty($in['faculty_id']) ? (int) $in['faculty_id'] : null;
    $sec = att_section($sectionId);
    if (!$sec) {
        throw new CrudValidationException(['section_id' => 'Select a valid section.']);
    }
    $subject = db_row('SELECT id, code, name FROM subjects WHERE id = ? AND program_id = ? AND semester_no = ?', [$subjectId, $sec['program_id'], $sec['semester_no']]);
    if (!$subject) {
        throw new CrudValidationException(['subject_id' => 'This subject is not taught in the selected section.']);
    }
    $slot = null;
    if ($slotId && !($slot = db_row('SELECT id, name, start_time FROM time_slots WHERE id = ? AND is_break = 0', [$slotId]))) {
        throw new CrudValidationException(['time_slot_id' => 'Select a valid period.']);
    }
    if ($facultyId && !db_value('SELECT COUNT(*) FROM faculty WHERE id = ?', [$facultyId])) {
        throw new CrudValidationException(['faculty_id' => 'Select a valid faculty member.']);
    }
    $day = att_day_info($date);
    if ($day['blocked']) {
        throw new CrudValidationException(['date' => $day['blocked']], $day['blocked']);
    }
    if (!att_can_mark_class($sectionId, $subjectId)) {
        throw new AttendanceAccessException('You can only mark attendance for classes assigned to you.');
    }

    // Records
    $input = $in['records'] ?? [];
    if (!is_array($input) || !$input) {
        throw new CrudValidationException(['records' => 'Mark attendance for at least one student.']);
    }
    $statuses = att_student_statuses();
    $marks = [];
    $recErrors = [];
    foreach ($input as $r) {
        $sid = (int) ($r['student_id'] ?? 0);
        $st = (string) ($r['status'] ?? '');
        if (!$sid) {
            continue;
        }
        if (!isset($statuses[$st])) {
            $recErrors['records.' . $sid] = 'Choose Present, Absent, Late or Leave.';
            continue;
        }
        $rem = isset($r['remarks']) ? trim((string) $r['remarks']) : '';
        if (mb_strlen($rem) > 255) {
            $recErrors['records.' . $sid] = 'Remarks may not be longer than 255 characters.';
            continue;
        }
        $marks[$sid] = ['status' => $st, 'remarks' => $rem === '' ? null : $rem];
    }
    $key = att_sheet_key('student', $date, $sectionId, $subjectId, $slotId);
    $existing = db_row('SELECT * FROM attendance WHERE sheet_key = ?', [$key]);
    $roster = array_map('intval', db_column("SELECT id FROM students WHERE section_id = ? AND status = 'active'", [$sectionId]));
    $already = $existing ? array_map('intval', db_column("SELECT person_id FROM attendance_records WHERE attendance_id = ? AND person_type = 'student'", [$existing['id']])) : [];
    $allowedIds = array_flip(array_merge($roster, $already));
    foreach (array_keys($marks) as $sid) {
        if (!isset($allowedIds[$sid])) {
            $recErrors['records'] = 'Some students in the list do not belong to ' . $sec['label'] . '. Reload the roster and try again.';
        }
    }
    $missing = array_diff($roster, array_keys($marks));
    if ($missing && !isset($recErrors['records'])) {
        $recErrors['records'] = 'Mark attendance for every student (' . count($missing) . ' not marked yet).';
    }
    if ($recErrors) {
        throw new CrudValidationException($recErrors);
    }

    if ($existing) {
        if (!can('attendance', 'edit')) {
            throw new AttendanceAccessException('You do not have permission to update attendance that was already marked.');
        }
        if ($existing['is_locked'] && !can('attendance', 'approve')) {
            throw new AttendanceAccessException('This attendance sheet is locked. Ask an attendance approver to unlock it before making changes.');
        }
    } elseif (!can('attendance', 'create')) {
        throw new AttendanceAccessException('You do not have permission to mark attendance.');
    }

    $before = att_student_percents(array_keys(array_filter($marks, fn ($m) => in_array($m['status'], ['absent', 'leave'], true))));
    $oldStatus = $existing ? db_pairs("SELECT person_id, status FROM attendance_records WHERE attendance_id = ? AND person_type = 'student'", [$existing['id']]) : [];

    $timetableId = $slotId ? db_value('SELECT id FROM timetables WHERE section_id = ? AND subject_id = ? AND time_slot_id = ? AND day_of_week = ? AND academic_session_id = ? LIMIT 1',
        [$sectionId, $subjectId, $slotId, (int) date('N', strtotime($date)), $sec['academic_session_id'] ?: current_session_id()]) : null;
    $remarks = trim((string) ($in['remarks'] ?? '')) ?: null;

    $result = db_transaction(function () use ($existing, $key, $date, $sec, $subjectId, $slotId, $facultyId, $timetableId, $remarks, $marks) {
        if ($existing) {
            $attId = (int) $existing['id'];
            db_update('attendance', ['faculty_id' => $facultyId ?: $existing['faculty_id'], 'remarks' => $remarks, 'timetable_id' => $timetableId ?: $existing['timetable_id']], 'id = ?', [$attId]);
            db_exec('UPDATE attendance SET updated_at = NOW() WHERE id = ?', [$attId]);
        } else {
            $attId = db_insert('attendance', [
                'type' => 'student', 'attendance_date' => $date, 'academic_session_id' => $sec['academic_session_id'] ?: current_session_id(),
                'program_id' => $sec['program_id'], 'semester_no' => $sec['semester_no'], 'section_id' => $sec['id'], 'subject_id' => $subjectId,
                'timetable_id' => $timetableId ?: null, 'time_slot_id' => $slotId, 'faculty_id' => $facultyId, 'sheet_key' => $key,
                'method' => 'manual', 'taken_by' => user_id(), 'remarks' => $remarks,
            ]);
        }
        $current = [];
        foreach (db_all("SELECT id, person_id, status, remarks FROM attendance_records WHERE attendance_id = ? AND person_type = 'student'", [$attId]) as $r) {
            $current[(int) $r['person_id']] = $r;
        }
        $changed = 0;
        foreach ($marks as $sid => $m) {
            if (isset($current[$sid])) {
                if ($current[$sid]['status'] !== $m['status'] || (string) $current[$sid]['remarks'] !== (string) $m['remarks']) {
                    db_update('attendance_records', ['status' => $m['status'], 'remarks' => $m['remarks']], 'id = ?', [(int) $current[$sid]['id']]);
                    $changed++;
                }
            } else {
                db_insert('attendance_records', ['attendance_id' => $attId, 'person_type' => 'student', 'person_id' => $sid, 'status' => $m['status'], 'remarks' => $m['remarks']]);
                $changed++;
            }
        }
        att_after_records_changed([$attId], array_keys($marks));
        return ['id' => $attId, 'changed' => $changed];
    });

    $counts = att_sheet_counts([$result['id']])[$result['id']] ?? null;
    $label = $sec['label'] . ' - ' . $subject['code'] . ' ' . $subject['name'] . ($slot ? ' (' . $slot['name'] . ')' : '');
    $summary = $counts ? sprintf('%d/%d present, %d absent, %d late, %d on leave', $counts['present'] + $counts['late'], $counts['total'], $counts['absent'], $counts['late'], $counts['leave']) : '';
    log_activity($existing ? 'update' : 'create', 'attendance', $result['id'],
        ($existing ? 'Updated' : 'Marked') . ' attendance for ' . $label . ' on ' . format_date($date) . ': ' . $summary);

    // Automatic low-attendance alert: students who dropped below the minimum with this sheet
    $alerts = att_detect_threshold_crossings($marks, $before, $oldStatus, (bool) $existing, $sec);

    return ['id' => $result['id'], 'created' => !$existing, 'changed' => $result['changed'], 'counts' => $counts, 'label' => $label, 'new_defaulters' => $alerts];
}

/** Compare before/after % for absent/leave students and raise an in-app alert for those who crossed below the minimum. */
function att_detect_threshold_crossings(array $marks, array $before, array $oldStatus, bool $existed, array $sec): array
{
    $ids = array_keys(array_filter($marks, fn ($m) => in_array($m['status'], ['absent', 'leave'], true)));
    if (!$ids) {
        return [];
    }
    $min = att_min_percent();
    $after = att_student_percents($ids);
    $crossed = [];
    foreach ($ids as $sid) {
        $a = $after[$sid] ?? null;
        if (!$a || $a['percent'] === null || $a['percent'] >= $min || $a['held'] < 5) {
            continue;
        }
        $b = $before[$sid] ?? null;
        if ($existed && isset($oldStatus[$sid])) {
            // Sheet already counted before: previous status contributed to "before"
            $prevAttended = in_array($oldStatus[$sid], ['present'], true) || ($oldStatus[$sid] === 'late' && att_late_counts());
            $wasAbove = $b && $b['percent'] !== null && $b['percent'] >= $min;
            if ($wasAbove || ($prevAttended && $b && $b['held'] ? 100 * $b['attended'] / $b['held'] >= $min : false)) {
                $crossed[] = $sid;
            }
        } elseif (!$b || $b['percent'] === null || $b['percent'] >= $min) {
            $crossed[] = $sid;
        }
    }
    if (!$crossed) {
        return [];
    }
    $names = db_pairs("SELECT id, TRIM(CONCAT_WS(' ', first_name, last_name)) FROM students WHERE id IN (" . implode(',', $crossed) . ')');
    $list = [];
    foreach ($crossed as $sid) {
        $list[] = ['id' => $sid, 'name' => $names[$sid] ?? ('#' . $sid), 'percent' => $after[$sid]['percent']];
    }
    $first = array_slice($list, 0, 3);
    $text = implode(', ', array_map(fn ($x) => $x['name'] . ' (' . $x['percent'] . '%)', $first)) . (count($list) > 3 ? ' and ' . (count($list) - 3) . ' more' : '');
    notify('perm:attendance', 'attendance', count($list) === 1 ? 'Low attendance alert' : count($list) . ' students below ' . $min . '% attendance',
        $text . ' in ' . $sec['label'] . ' fell below the minimum ' . $min . '% attendance.', 'attendance/reports?tab=defaulters&program_id=' . $sec['program_id'] . '&semester=' . $sec['semester_no'] . '&section_id=' . $sec['id'], 'user-x');
    return $list;
}

/* ------------------------------------------------------------------
 * Employee marking (faculty + staff daily attendance)
 * ------------------------------------------------------------------ */

function att_employee_people(string $type = 'all', ?int $departmentId = null, string $q = ''): array
{
    $people = [];
    $like = '%' . $q . '%';
    if ($type === 'all' || $type === 'faculty') {
        $sql = "SELECT 'faculty' AS person_type, f.id AS person_id, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS name, f.employee_id, f.photo, f.designation,
                d.name AS department, f.department_id, f.status FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE f.status IN ('active','on_leave')";
        $args = [];
        if ($departmentId) {
            $sql .= ' AND f.department_id = ?';
            $args[] = $departmentId;
        }
        if ($q !== '') {
            $sql .= " AND (f.first_name LIKE ? OR f.last_name LIKE ? OR f.employee_id LIKE ? OR CONCAT(f.first_name, ' ', f.last_name) LIKE ?)";
            array_push($args, $like, $like, $like, $like);
        }
        $people = array_merge($people, db_all($sql . ' ORDER BY f.first_name, f.last_name', $args));
    }
    if ($type === 'all' || $type === 'staff') {
        $sql = "SELECT 'staff' AS person_type, st.id AS person_id, TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) AS name, st.employee_id, st.photo, st.designation,
                COALESCE(d.name, CONCAT(UPPER(LEFT(st.category, 1)), SUBSTRING(st.category, 2))) AS department, st.department_id, st.status
                FROM staff st LEFT JOIN departments d ON d.id = st.department_id WHERE st.status IN ('active','on_leave')";
        $args = [];
        if ($departmentId) {
            $sql .= ' AND st.department_id = ?';
            $args[] = $departmentId;
        }
        if ($q !== '') {
            $sql .= " AND (st.first_name LIKE ? OR st.last_name LIKE ? OR st.employee_id LIKE ? OR CONCAT(st.first_name, ' ', st.last_name) LIKE ?)";
            array_push($args, $like, $like, $like, $like);
        }
        $people = array_merge($people, db_all($sql . ' ORDER BY st.first_name, st.last_name', $args));
    }
    foreach ($people as &$p) {
        $p['person_id'] = (int) $p['person_id'];
        $p['department_id'] = $p['department_id'] ? (int) $p['department_id'] : null;
    }
    return $people;
}

function att_employee_sheet(string $date, string $type = 'all', ?int $departmentId = null, string $q = ''): array
{
    $sheets = [];
    foreach (['faculty', 'staff'] as $t) {
        $row = db_row('SELECT a.id, a.method, a.is_locked, a.updated_at, a.created_at, u.name AS taken_by_name FROM attendance a LEFT JOIN users u ON u.id = a.taken_by WHERE a.sheet_key = ?',
            [att_sheet_key($t, $date)]);
        if ($row) {
            $row['id'] = (int) $row['id'];
            $row['is_locked'] = (bool) $row['is_locked'];
        }
        $sheets[$t] = $row;
    }
    $records = [];
    foreach ($sheets as $t => $s) {
        if ($s) {
            foreach (db_all('SELECT person_id, status, in_time, out_time, remarks FROM attendance_records WHERE attendance_id = ? AND person_type = ?', [$s['id'], $t]) as $r) {
                $records[$t . ':' . $r['person_id']] = $r;
            }
        }
    }
    $leaves = [];
    foreach (db_all("SELECT employee_type, employee_id, leave_type, from_date, to_date FROM employee_leaves WHERE status = 'approved' AND from_date <= ? AND to_date >= ?", [$date, $date]) as $l) {
        $leaves[$l['employee_type'] . ':' . $l['employee_id']] = $l;
    }
    $people = att_employee_people($type, $departmentId, $q);
    $counts = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0, 'unmarked' => 0];
    foreach ($people as &$p) {
        $k = $p['person_type'] . ':' . $p['person_id'];
        $r = $records[$k] ?? null;
        $p['status'] = $r['status'] ?? null;
        $p['in_time'] = $r && $r['in_time'] ? substr($r['in_time'], 0, 5) : null;
        $p['out_time'] = $r && $r['out_time'] ? substr($r['out_time'], 0, 5) : null;
        $p['remarks'] = $r['remarks'] ?? null;
        $p['leave'] = isset($leaves[$k]) ? ['type' => $leaves[$k]['leave_type'], 'from' => $leaves[$k]['from_date'], 'to' => $leaves[$k]['to_date']] : null;
        $counts[$p['status'] ?? 'unmarked']++;
    }
    unset($p);
    $locked = ($sheets['faculty']['is_locked'] ?? false) || ($sheets['staff']['is_locked'] ?? false);
    return [
        'date' => $date, 'day' => att_day_info($date, true), 'sheets' => $sheets, 'people' => $people, 'counts' => $counts, 'total' => count($people),
        'late_after' => att_employee_late_after(),
        'can_save' => (can('attendance', 'create') || can('attendance', 'edit')) && (!$locked || can('attendance', 'approve')),
    ];
}

/** $in: date, records: [{person_type, person_id, status, in_time?, out_time?, remarks?}] (partial lists allowed) */
function att_save_employee_sheet(array $in): array
{
    $date = (string) ($in['date'] ?? '');
    if (!att_valid_date($date)) {
        throw new CrudValidationException(['date' => 'Choose a valid date.']);
    }
    $day = att_day_info($date, true);
    if ($day['blocked']) {
        throw new CrudValidationException(['date' => $day['blocked']], $day['blocked']);
    }
    $input = $in['records'] ?? [];
    if (!is_array($input) || !$input) {
        throw new CrudValidationException(['records' => 'Mark attendance for at least one employee.']);
    }
    $statuses = att_employee_statuses();
    $byType = ['faculty' => [], 'staff' => []];
    $errors = [];
    foreach ($input as $r) {
        $t = (string) ($r['person_type'] ?? '');
        $pid = (int) ($r['person_id'] ?? 0);
        if (!isset($byType[$t]) || !$pid) {
            continue;
        }
        $k = "records.$t.$pid";
        $st = (string) ($r['status'] ?? '');
        if (!isset($statuses[$st])) {
            $errors[$k] = 'Choose a status.';
            continue;
        }
        $inT = trim((string) ($r['in_time'] ?? ''));
        $outT = trim((string) ($r['out_time'] ?? ''));
        foreach (['in' => $inT, 'out' => $outT] as $lbl => $v) {
            if ($v !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) {
                $errors[$k] = ucfirst($lbl) . ' time must be a valid time (HH:MM).';
            }
        }
        if (isset($errors[$k])) {
            continue;
        }
        if ($inT !== '' && $outT !== '' && strtotime($outT) <= strtotime($inT)) {
            $errors[$k] = 'Out time must be after in time.';
            continue;
        }
        if (in_array($st, ['absent', 'leave'], true)) {
            $inT = $outT = '';
        }
        $rem = trim((string) ($r['remarks'] ?? ''));
        if (mb_strlen($rem) > 255) {
            $errors[$k] = 'Remarks may not be longer than 255 characters.';
            continue;
        }
        $byType[$t][$pid] = ['status' => $st, 'in_time' => $inT !== '' ? $inT : null, 'out_time' => $outT !== '' ? $outT : null, 'remarks' => $rem !== '' ? $rem : null];
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if (!$byType['faculty'] && !$byType['staff']) {
        throw new CrudValidationException(['records' => 'Mark attendance for at least one employee.']);
    }
    foreach ($byType as $t => $list) {
        if (!$list) {
            continue;
        }
        $valid = array_map('intval', db_column('SELECT id FROM ' . ($t === 'faculty' ? 'faculty' : 'staff') . ' WHERE id IN (' . implode(',', array_keys($list)) . ')'));
        if (count($valid) !== count($list)) {
            throw new CrudValidationException(['records' => 'Some employees in the list no longer exist. Reload and try again.']);
        }
        $existing = db_row('SELECT id, is_locked FROM attendance WHERE sheet_key = ?', [att_sheet_key($t, $date)]);
        if ($existing && $existing['is_locked'] && !can('attendance', 'approve')) {
            throw new AttendanceAccessException('The ' . $t . ' attendance for ' . format_date($date) . ' is locked. Ask an attendance approver to unlock it.');
        }
        if ($existing && !can('attendance', 'edit')) {
            throw new AttendanceAccessException('You do not have permission to update attendance that was already marked.');
        }
        if (!$existing && !can('attendance', 'create')) {
            throw new AttendanceAccessException('You do not have permission to mark attendance.');
        }
    }
    $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0];
    $ids = db_transaction(function () use ($byType, $date, &$totals) {
        $ids = [];
        foreach ($byType as $t => $list) {
            if (!$list) {
                continue;
            }
            $attId = (int) db_value('SELECT id FROM attendance WHERE sheet_key = ?', [att_sheet_key($t, $date)]);
            if (!$attId) {
                $attId = db_insert('attendance', [
                    'type' => $t, 'attendance_date' => $date, 'academic_session_id' => current_session_id(), 'sheet_key' => att_sheet_key($t, $date),
                    'method' => 'manual', 'taken_by' => user_id(),
                ]);
            } else {
                db_exec('UPDATE attendance SET updated_at = NOW() WHERE id = ?', [$attId]);
            }
            $current = db_pairs('SELECT person_id, id FROM attendance_records WHERE attendance_id = ? AND person_type = ?', [$attId, $t]);
            foreach ($list as $pid => $m) {
                $totals[$m['status']]++;
                if (isset($current[$pid])) {
                    db_update('attendance_records', $m, 'id = ?', [(int) $current[$pid]]);
                } else {
                    db_insert('attendance_records', $m + ['attendance_id' => $attId, 'person_type' => $t, 'person_id' => $pid]);
                }
            }
            att_refresh_sheet_counts([$attId]);
            $ids[$t] = $attId;
        }
        return $ids;
    });
    $n = array_sum($totals);
    log_activity('update', 'attendance', implode(',', $ids), sprintf('Marked faculty & staff attendance for %s: %d employees (%d present, %d late, %d half day, %d on leave, %d absent)',
        format_date($date), $n, $totals['present'], $totals['late'], $totals['half_day'], $totals['leave'], $totals['absent']));
    return ['ids' => $ids, 'saved' => $n, 'totals' => $totals];
}

/** Monthly register for faculty & staff. */
function att_employee_register(string $month, string $type = 'all', ?int $departmentId = null, string $q = ''): array
{
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $people = att_employee_people($type, $departmentId, $q);
    $types = $type === 'all' ? ['faculty', 'staff'] : [$type];
    $rows = db_all('SELECT a.type, a.attendance_date, r.person_id, r.status, r.in_time, r.out_time FROM attendance a JOIN attendance_records r ON r.attendance_id = a.id AND r.person_type = a.type
                    WHERE a.type IN (' . implode(',', array_map(fn ($t) => db()->quote($t), $types)) . ') AND a.attendance_date BETWEEN ? AND ?', [$from, $to]);
    $grid = [];
    foreach ($rows as $r) {
        $grid[$r['type'] . ':' . $r['person_id']][(int) substr($r['attendance_date'], 8, 2)] = $r['status'];
    }
    $days = att_month_days($from, $to);
    $late = att_late_counts() ? 1 : 0;
    $daily = [];
    foreach ($people as &$p) {
        $cells = $grid[$p['person_type'] . ':' . $p['person_id']] ?? [];
        $t = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0];
        foreach ($cells as $d => $st) {
            if (isset($t[$st])) {
                $t[$st]++;
            }
            $daily[$d][$st] = ($daily[$d][$st] ?? 0) + 1;
        }
        $marked = array_sum($t);
        $p['cells'] = (object) $cells;
        $p['totals'] = $t + ['marked' => $marked, 'percent' => $marked ? round(100 * ($t['present'] + $late * $t['late'] + 0.5 * $t['half_day']) / $marked, 1) : null];
    }
    unset($p);
    return ['month' => $month, 'from' => $from, 'to' => $to, 'days' => $days, 'people' => $people, 'daily' => (object) $daily];
}

/** Day descriptors for a month grid. */
function att_month_days(string $from, string $to): array
{
    $hol = att_holiday_map($from, $to);
    $off = att_weekly_off();
    $today = date('Y-m-d');
    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $days[] = [
            'date' => $d, 'day' => (int) date('j', $t), 'dow' => (int) date('N', $t), 'dow_label' => substr(date('D', $t), 0, 2),
            'weekly_off' => in_array((int) date('N', $t), $off, true), 'holiday' => $hol[$d] ?? null, 'future' => $d > $today,
        ];
    }
    return $days;
}

/* ------------------------------------------------------------------
 * Dashboard & calendar
 * ------------------------------------------------------------------ */

function att_dashboard(string $date, bool $mine = false): array
{
    $att = att_attended_expr();
    $min = att_min_percent();
    $sess = current_session();
    $sessionId = current_session_id();
    $scope = att_faculty_scope();
    $myFaculty = (int) (current_user()['faculty_id'] ?? 0);
    $mine = $mine && $myFaculty > 0;

    $sum = db_row('SELECT SUM(a.total_count) AS marks, SUM(a.present_count) AS present, SUM(a.absent_count) AS absent, SUM(a.late_count) AS late,
                   SUM(a.leave_count) AS on_leave, COUNT(*) AS sessions, COUNT(DISTINCT a.section_id) AS sections,
                   ROUND(100 * SUM(' . att_sheet_attended_sql() . ") / NULLIF(SUM(a.total_count), 0), 1) AS percent
                   FROM attendance a WHERE a.type = 'student' AND a.attendance_date = ?", [$date]);
    // Day status per student: leave (all periods on leave) / absent (attended none) / late / present
    $dayStatus = db_pairs("SELECT x.ds, COUNT(*) FROM (SELECT r.person_id,
                    CASE WHEN SUM(r.status = 'leave') = COUNT(*) THEN 'leave' WHEN SUM(r.status IN ('present','late')) = 0 THEN 'absent'
                         WHEN SUM(r.status = 'late') > 0 THEN 'late' ELSE 'present' END AS ds
                    FROM attendance a JOIN attendance_records r ON r.attendance_id = a.id AND r.person_type = 'student'
                    WHERE a.type = 'student' AND a.attendance_date = ? GROUP BY r.person_id) x GROUP BY x.ds", [$date]);
    $activeSections = (int) db_value("SELECT COUNT(*) FROM sections WHERE status = 'active' AND academic_session_id = ?", [$sessionId]);
    $activeStudents = (int) db_value("SELECT COUNT(*) FROM students s JOIN sections sc ON sc.id = s.section_id WHERE s.status = 'active' AND sc.academic_session_id = ?", [$sessionId]);
    $dow = (int) date('N', strtotime($date));
    $scheduled = (int) db_value("SELECT COUNT(*) FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id WHERE t.academic_session_id = ? AND t.day_of_week = ? AND t.status = 'published' AND ts.is_break = 0"
        . ($mine ? ' AND t.faculty_id = ?' : ''), $mine ? [$sessionId, $dow, $myFaculty] : [$sessionId, $dow]);

    // Today's classes
    $schedule = [];
    if ($scheduled > 0) {
        $rows = db_all("SELECT t.section_id, t.subject_id, t.time_slot_id, t.faculty_id, ts.name AS slot_name, ts.start_time, ts.end_time, sb.code AS subject_code, sb.name AS subject_name,
                        " . att_class_label_sql() . " AS section_label, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name,
                        (SELECT a.id FROM attendance a WHERE a.sheet_key = CONCAT('student|', ?, '|', t.section_id, '|', t.subject_id, '|', t.time_slot_id)) AS attendance_id
                        FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN subjects sb ON sb.id = t.subject_id JOIN sections sc ON sc.id = t.section_id
                        JOIN programs p ON p.id = sc.program_id LEFT JOIN faculty f ON f.id = t.faculty_id
                        WHERE t.academic_session_id = ? AND t.day_of_week = ? AND t.status = 'published' AND ts.is_break = 0" . ($mine ? ' AND t.faculty_id = ?' : '') . '
                        ORDER BY ts.sort_order, p.sort_order, sc.semester_no, sc.name LIMIT 400', $mine ? [$date, $sessionId, $dow, $myFaculty] : [$date, $sessionId, $dow]);
        $counts = att_sheet_counts(array_column($rows, 'attendance_id'));
        foreach ($rows as $r) {
            $schedule[] = [
                'kind' => 'period', 'section_id' => (int) $r['section_id'], 'section_label' => $r['section_label'], 'subject_id' => (int) $r['subject_id'],
                'subject' => $r['subject_code'] . ' · ' . $r['subject_name'], 'time_slot_id' => (int) $r['time_slot_id'], 'slot_name' => $r['slot_name'],
                'start_time' => $r['start_time'], 'end_time' => $r['end_time'], 'faculty_name' => $r['faculty_name'], 'attendance_id' => $r['attendance_id'] ? (int) $r['attendance_id'] : null,
                'counts' => $r['attendance_id'] ? ($counts[(int) $r['attendance_id']] ?? null) : null,
            ];
        }
    } else {
        $where = "sc.status = 'active' AND sc.academic_session_id = ?";
        $args = [$date, $sessionId];
        if ($mine && $scope !== null) {
            $where .= $scope['sections'] ? ' AND sc.id IN (' . implode(',', $scope['sections']) . ')' : ' AND 1 = 0';
        } elseif ($mine) {
            $where .= ' AND (sc.class_teacher_id = ? OR sc.id IN (SELECT section_id FROM faculty_subjects WHERE faculty_id = ?))';
            array_push($args, $myFaculty, $myFaculty);
        }
        $rows = db_all('SELECT sc.id AS section_id, ' . att_class_label_sql() . " AS section_label, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name,
                        (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS students,
                        (SELECT COUNT(*) FROM attendance a WHERE a.type = 'student' AND a.section_id = sc.id AND a.attendance_date = ?) AS sessions
                        FROM sections sc JOIN programs p ON p.id = sc.program_id LEFT JOIN faculty f ON f.id = sc.class_teacher_id
                        WHERE $where ORDER BY p.sort_order, sc.semester_no, sc.name", $args);
        $pctBySection = [];
        foreach (db_all('SELECT a.section_id, SUM(a.total_count) AS marks, ROUND(100 * SUM(' . att_sheet_attended_sql() . ") / NULLIF(SUM(a.total_count), 0), 1) AS percent,
                         MAX(COALESCE(a.updated_at, a.created_at)) AS last_marked, SUM(a.absent_count) AS absent
                         FROM attendance a WHERE a.type = 'student' AND a.attendance_date = ? GROUP BY a.section_id", [$date]) as $r) {
            $pctBySection[(int) $r['section_id']] = $r;
        }
        foreach ($rows as $r) {
            $p = $pctBySection[(int) $r['section_id']] ?? null;
            $schedule[] = [
                'kind' => 'section', 'section_id' => (int) $r['section_id'], 'section_label' => $r['section_label'], 'faculty_name' => $r['faculty_name'],
                'students' => (int) $r['students'], 'sessions' => (int) $r['sessions'], 'percent' => $p ? (float) $p['percent'] : null,
                'absent' => $p ? (int) $p['absent'] : 0, 'last_marked' => $p['last_marked'] ?? null,
            ];
        }
    }

    // Trend: last 14 days that have attendance up to the date (per-sheet counts)
    $trend = array_reverse(db_all('SELECT a.attendance_date AS date, ROUND(100 * SUM(' . att_sheet_attended_sql() . ") / NULLIF(SUM(a.total_count), 0), 1) AS percent, COUNT(*) AS sessions
                    FROM attendance a WHERE a.type = 'student' AND a.attendance_date <= ? AND a.attendance_date >= DATE_SUB(?, INTERVAL 40 DAY)
                    GROUP BY a.attendance_date ORDER BY a.attendance_date DESC LIMIT 14", [$date, $date]));
    foreach ($trend as &$t) {
        $t['percent'] = $t['percent'] !== null ? (float) $t['percent'] : null;
        $t['sessions'] = (int) $t['sessions'];
        $t['label'] = date('d M', strtotime($t['date']));
    }
    unset($t);

    // Low attendance (session to date, active students) from the per-student summaries
    $late = att_late_counts() ? 1 : 0;
    $low = db_all("SELECT sm.student_id AS person_id, sm.held, ROUND(100 * (sm.present + $late * sm.late) / sm.held, 1) AS percent,
                   TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.photo, " . att_class_label_sql('p', 'sc') . " AS class_label
                   FROM attendance_summaries sm JOIN students s ON s.id = sm.student_id AND s.status = 'active'
                   LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN programs p ON p.id = s.program_id
                   WHERE sm.academic_session_id = ? AND sm.held > 0 AND 100 * (sm.present + $late * sm.late) / sm.held < ?
                   ORDER BY percent ASC, s.first_name", [$sessionId, $min]);
    $sessionPct = db_value("SELECT ROUND(100 * SUM(sm.present + $late * sm.late) / NULLIF(SUM(sm.held), 0), 1) FROM attendance_summaries sm WHERE sm.academic_session_id = ?", [$sessionId]);

    // Recently marked sessions
    $recent = db_all("SELECT a.id, a.attendance_date, a.section_id, a.subject_id, a.time_slot_id, a.updated_at, a.created_at, a.method, sb.code AS subject_code, sb.name AS subject_name,
                      ts.name AS slot_name, " . att_class_label_sql() . " AS section_label, u.name AS taken_by_name
                      FROM attendance a JOIN sections sc ON sc.id = a.section_id JOIN programs p ON p.id = sc.program_id JOIN subjects sb ON sb.id = a.subject_id
                      LEFT JOIN time_slots ts ON ts.id = a.time_slot_id LEFT JOIN users u ON u.id = a.taken_by
                      WHERE a.type = 'student' AND a.attendance_date <= ? ORDER BY a.attendance_date DESC, COALESCE(a.updated_at, a.created_at) DESC, a.id DESC LIMIT 8", [$date]);
    $rc = att_sheet_counts(array_column($recent, 'id'));
    foreach ($recent as &$r) {
        $r['id'] = (int) $r['id'];
        $r['section_id'] = (int) $r['section_id'];
        $r['subject_id'] = (int) $r['subject_id'];
        $r['time_slot_id'] = $r['time_slot_id'] ? (int) $r['time_slot_id'] : null;
        $r['counts'] = $rc[$r['id']] ?? null;
    }
    unset($r);

    // Employees on the date
    $emp = ['faculty' => ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0], 'staff' => ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0]];
    foreach (db_all("SELECT a.type, r.status, COUNT(*) AS n FROM attendance a JOIN attendance_records r ON r.attendance_id = a.id AND r.person_type = a.type
                     WHERE a.type IN ('faculty','staff') AND a.attendance_date = ? GROUP BY a.type, r.status", [$date]) as $r) {
        $emp[$r['type']][$r['status']] = (int) $r['n'];
    }
    $empTotal = (int) db_value("SELECT (SELECT COUNT(*) FROM faculty WHERE status IN ('active','on_leave')) + (SELECT COUNT(*) FROM staff WHERE status IN ('active','on_leave'))");

    $studentsMarked = array_sum(array_map('intval', $dayStatus));
    return [
        'date' => $date, 'day' => att_day_info($date), 'min_percent' => $min, 'mine' => $mine, 'is_faculty' => $myFaculty > 0,
        'kpis' => [
            'percent' => $sum['percent'] !== null ? (float) $sum['percent'] : null,
            'students_marked' => $studentsMarked, 'students_total' => $activeStudents,
            'present' => (int) ($dayStatus['present'] ?? 0), 'late' => (int) ($dayStatus['late'] ?? 0), 'absent' => (int) ($dayStatus['absent'] ?? 0), 'leave' => (int) ($dayStatus['leave'] ?? 0),
            'period_absences' => (int) $sum['absent'], 'marks' => (int) $sum['marks'],
            'sessions_marked' => (int) $sum['sessions'], 'sections_marked' => (int) $sum['sections'], 'sections_total' => $activeSections,
            'scheduled' => $scheduled, 'scheduled_source' => $scheduled > 0 ? 'timetable' : 'sections',
            'scheduled_marked' => $scheduled > 0 ? count(array_filter($schedule, fn ($s) => $s['attendance_id'])) : (int) $sum['sections'],
            'session_percent' => $sessionPct !== null ? (float) $sessionPct : null,
        ],
        'status_split' => ['present' => (int) $sum['present'], 'late' => (int) $sum['late'], 'absent' => (int) $sum['absent'], 'leave' => (int) $sum['on_leave']],
        'trend' => $trend, 'schedule' => $schedule,
        'low_attendance' => ['count' => count($low), 'threshold' => $min, 'students' => array_map(fn ($r) => [
            'id' => (int) $r['person_id'], 'name' => $r['name'], 'student_uid' => $r['student_uid'], 'photo' => $r['photo'], 'class_label' => $r['class_label'],
            'percent' => (float) $r['percent'], 'held' => (int) $r['held'],
        ], array_slice($low, 0, 6))],
        'recent' => $recent,
        'employees' => ['total' => $empTotal, 'faculty' => $emp['faculty'], 'staff' => $emp['staff']],
    ];
}

/** Monthly calendar heatmap for a section. */
function att_calendar(int $sectionId, string $month): array
{
    $sec = att_section($sectionId);
    if (!$sec) {
        throw new CrudValidationException(['section_id' => 'Select a valid section.']);
    }
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $sheets = db_all("SELECT a.id, a.attendance_date, a.subject_id, a.time_slot_id, a.is_locked, sb.code AS subject_code, sb.name AS subject_name, ts.name AS slot_name, ts.start_time,
                      TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name
                      FROM attendance a JOIN subjects sb ON sb.id = a.subject_id LEFT JOIN time_slots ts ON ts.id = a.time_slot_id LEFT JOIN faculty f ON f.id = a.faculty_id
                      WHERE a.type = 'student' AND a.section_id = ? AND a.attendance_date BETWEEN ? AND ? ORDER BY a.attendance_date, ts.sort_order, a.id", [$sectionId, $from, $to]);
    $counts = att_sheet_counts(array_column($sheets, 'id'));
    $byDay = [];
    foreach ($sheets as $s) {
        $c = $counts[(int) $s['id']] ?? ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'half_day' => 0, 'total' => 0, 'percent' => null];
        $byDay[$s['attendance_date']][] = [
            'id' => (int) $s['id'], 'subject_id' => (int) $s['subject_id'], 'time_slot_id' => $s['time_slot_id'] ? (int) $s['time_slot_id'] : null,
            'subject' => $s['subject_code'] . ' · ' . $s['subject_name'], 'slot_name' => $s['slot_name'], 'start_time' => $s['start_time'],
            'faculty_name' => $s['faculty_name'], 'is_locked' => (bool) $s['is_locked'], 'counts' => $c,
        ];
    }
    $late = att_late_counts() ? 1 : 0;
    $days = att_month_days($from, $to);
    $sumAtt = 0;
    $sumMarks = 0;
    $workingElapsed = 0;
    $best = null;
    $worst = null;
    foreach ($days as &$d) {
        $list = $byDay[$d['date']] ?? [];
        $t = ['present' => 0, 'absent' => 0, 'late' => 0, 'leave' => 0, 'total' => 0];
        foreach ($list as $s) {
            foreach ($t as $k => $_) {
                $t[$k] += $s['counts'][$k] ?? 0;
            }
        }
        $d['sessions'] = $list;
        $d['totals'] = $t;
        $d['percent'] = $t['total'] ? round(100 * ($t['present'] + $late * $t['late']) / $t['total'], 1) : null;
        $isWorking = !$d['weekly_off'] && !($d['holiday'] && in_array($d['holiday']['type'], ['holiday', 'vacation'], true)) && !$d['future'];
        if ($isWorking) {
            $workingElapsed++;
        }
        if ($d['percent'] !== null) {
            $sumAtt += $t['present'] + $late * $t['late'];
            $sumMarks += $t['total'];
            if (!$best || $d['percent'] > $best['percent']) {
                $best = ['date' => $d['date'], 'percent' => $d['percent']];
            }
            if (!$worst || $d['percent'] < $worst['percent']) {
                $worst = ['date' => $d['date'], 'percent' => $d['percent']];
            }
        }
    }
    unset($d);
    return [
        'section' => $sec, 'month' => $month, 'from' => $from, 'to' => $to, 'days' => $days, 'min_percent' => att_min_percent(),
        'summary' => [
            'working_days' => $workingElapsed, 'days_marked' => count($byDay), 'sessions' => count($sheets),
            'percent' => $sumMarks ? round(100 * $sumAtt / $sumMarks, 1) : null, 'best' => $best, 'worst' => $worst,
        ],
    ];
}

/* ------------------------------------------------------------------
 * Reports
 * ------------------------------------------------------------------ */

/** Normalised report filters + WHERE fragment over attendance a (+ programs ap). */
function att_report_scope(array $q): array
{
    $sess = current_session();
    $today = date('Y-m-d');
    $from = att_valid_date($q['from'] ?? null) ? $q['from'] : ($sess['start_date'] ?? date('Y-01-01'));
    $to = att_valid_date($q['to'] ?? null) ? $q['to'] : min($today, $sess['end_date'] ?? $today);
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    $where = ["a.type = 'student'", 'a.attendance_date BETWEEN ? AND ?'];
    $args = [$from, $to];
    $f = ['from' => $from, 'to' => $to];
    foreach (['department_id' => 'ap.department_id', 'program_id' => 'a.program_id', 'semester' => 'a.semester_no', 'section_id' => 'a.section_id', 'subject_id' => 'a.subject_id'] as $k => $col) {
        $v = $q[$k] ?? '';
        if ($v !== '' && $v !== null && !is_array($v) && ctype_digit((string) $v)) {
            $where[] = "$col = ?";
            $args[] = (int) $v;
            $f[$k] = (int) $v;
        }
    }
    $threshold = isset($q['threshold']) && is_numeric($q['threshold']) && $q['threshold'] > 0 && $q['threshold'] <= 100 ? (float) $q['threshold'] : att_min_percent();
    $f['threshold'] = $threshold;
    $f['q'] = trim((string) ($q['q'] ?? ''));
    return [implode(' AND ', $where), $args, $f];
}

/** Human readable filter summary for print/export headers. */
function att_report_filter_labels(array $f): array
{
    $out = ['Period: ' . format_date($f['from']) . ' - ' . format_date($f['to'])];
    if (!empty($f['department_id'])) {
        $out[] = 'Department: ' . db_value('SELECT name FROM departments WHERE id = ?', [$f['department_id']]);
    }
    if (!empty($f['program_id'])) {
        $out[] = 'Program: ' . db_value("SELECT CONCAT(short_name, ' - ', name) FROM programs WHERE id = ?", [$f['program_id']]);
    }
    if (!empty($f['semester'])) {
        $out[] = 'Semester: ' . $f['semester'];
    }
    if (!empty($f['section_id']) && ($s = att_section((int) $f['section_id']))) {
        $out[] = 'Section: ' . $s['label'];
    }
    if (!empty($f['subject_id'])) {
        $out[] = 'Subject: ' . db_value("SELECT CONCAT(code, ' - ', name) FROM subjects WHERE id = ?", [$f['subject_id']]);
    }
    if ($f['q'] !== '') {
        $out[] = 'Search: "' . $f['q'] . '"';
    }
    return $out;
}

/**
 * Per-student aggregate source for reports.
 * Uses attendance_summaries when the range covers the whole session to date and no subject filter is set
 * (filters then apply to the student's current program/section); otherwise aggregates the records live.
 * @return array [sql (rows: person_id, held, present, absent, late, on_leave, attended, percent, last_date), args, extra outer WHERE parts, outer args, 'summary'|'live']
 */
function att_student_agg_source(array $f, string $where, array $args): array
{
    $sess = current_session();
    $late = att_late_counts() ? 1 : 0;
    $fullRange = $f['from'] <= ($sess['start_date'] ?? $f['from']) && $f['to'] >= min(date('Y-m-d'), $sess['end_date'] ?? date('Y-m-d'));
    if ($fullRange && empty($f['subject_id']) && !empty($sess['id'])) {
        $sql = "SELECT sm.student_id AS person_id, sm.held, sm.present, sm.absent, sm.late, sm.on_leave, (sm.present + $late * sm.late) AS attended,
                ROUND(100 * (sm.present + $late * sm.late) / sm.held, 1) AS percent, sm.last_date
                FROM attendance_summaries sm WHERE sm.academic_session_id = ? AND sm.held > 0";
        $outer = [];
        $outerArgs = [];
        foreach (['department_id' => 's.department_id', 'program_id' => 's.program_id', 'semester' => 's.current_semester', 'section_id' => 's.section_id'] as $k => $col) {
            if (!empty($f[$k])) {
                $outer[] = "$col = ?";
                $outerArgs[] = $f[$k];
            }
        }
        return [$sql, [(int) $sess['id']], $outer, $outerArgs, 'summary'];
    }
    $att = att_attended_expr();
    $sql = "SELECT r.person_id, COUNT(r.id) AS held, SUM(r.status = 'present') AS present, SUM(r.status = 'absent') AS absent, SUM(r.status = 'late') AS late,
            SUM(r.status = 'leave') AS on_leave, SUM($att) AS attended, " . att_pct_expr() . " AS percent, MAX(a.attendance_date) AS last_date
            FROM attendance a JOIN programs ap ON ap.id = a.program_id JOIN attendance_records r FORCE INDEX (idx_attrec_sheet_status) ON r.attendance_id = a.id
            WHERE $where GROUP BY r.person_id";
    return [$sql, $args, [], [], 'live'];
}

/**
 * Student-wise attendance (also used for defaulters).
 * $opts: defaulters (bool), page, per_page (0 = all), sort, dir, ids (limit to students)
 */
function att_report_students(array $q, array $opts = []): array
{
    [$where, $args, $f] = att_report_scope($q);
    $defaulters = !empty($opts['defaulters']);
    [$inner, $innerArgs, $outerWhere, $outerArgs, $source] = att_student_agg_source($f, $where, $args);
    if ($defaulters) {
        $outerWhere[] = "s.status = 'active'";
        $outerWhere[] = 'x.percent < ?';
        $outerArgs[] = $f['threshold'];
    }
    if ($f['q'] !== '') {
        foreach (preg_split('/\s+/', mb_substr($f['q'], 0, 80)) as $w) {
            $outerWhere[] = '(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR s.roll_no LIKE ?)';
            array_push($outerArgs, "%$w%", "%$w%", "%$w%", "%$w%");
        }
    }
    if (!empty($opts['ids'])) {
        $outerWhere[] = 'x.person_id IN (' . implode(',', array_map('intval', $opts['ids'])) . ')';
    }
    $from = "FROM ($inner) x JOIN students s ON s.id = x.person_id LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN sections sc ON sc.id = s.section_id"
        . ($outerWhere ? ' WHERE ' . implode(' AND ', $outerWhere) : '');
    $allArgs = array_merge($innerArgs, $outerArgs);
    $min = att_min_percent();
    $agg = db_row("SELECT COUNT(*) AS students, SUM(x.percent < ?) AS below, SUM(x.held) AS held, SUM(x.attended) AS attended, MIN(x.percent) AS lowest $from", array_merge([$f['threshold']], $allArgs));
    $sortMap = [
        'name' => 's.first_name %s, s.last_name %s', 'roll_no' => 's.roll_no %s', 'class' => 'p.short_name %s, s.current_semester %s, sc.name %s',
        'percent' => 'x.percent %s', 'held' => 'x.held %s', 'absent' => 'x.absent %s', 'late' => 'x.late %s', 'present' => 'x.present %s',
    ];
    $sort = $opts['sort'] ?? ($defaulters ? 'percent' : 'class');
    $dir = strtolower((string) ($opts['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $order = sprintf($sortMap[$sort] ?? $sortMap['class'], $dir, $dir, $dir) . ', s.roll_no ASC, s.id ASC';
    $perPage = (int) ($opts['per_page'] ?? 25);
    $total = (int) $agg['students'];
    $p = $perPage > 0 ? paginate($total, max(1, (int) ($opts['page'] ?? 1)), min(100, $perPage)) : ['page' => 1, 'per_page' => $total, 'pages' => 1, 'offset' => 0, 'total' => $total];
    $limit = $perPage > 0 ? ' LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'] : ' LIMIT 20000';
    $rows = db_all("SELECT x.*, s.id, s.student_uid, s.roll_no, s.first_name, s.last_name, s.photo, s.email, s.mobile, s.status AS student_status,
                    COALESCE(" . att_class_label_sql('p', 'sc') . ", CONCAT(p.short_name, ' · Sem ', s.current_semester)) AS class_label $from ORDER BY $order$limit", $allArgs);
    $lastAlert = $rows ? att_last_alerts(array_map(fn ($r) => (int) $r['id'], $rows)) : [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'student_uid' => $r['student_uid'], 'roll_no' => $r['roll_no'],
            'photo' => $r['photo'], 'email' => $r['email'], 'mobile' => $r['mobile'], 'class_label' => $r['class_label'], 'student_status' => $r['student_status'],
            'held' => (int) $r['held'], 'present' => (int) $r['present'], 'absent' => (int) $r['absent'], 'late' => (int) $r['late'], 'leave' => (int) $r['on_leave'],
            'attended' => (float) $r['attended'], 'percent' => $r['percent'] !== null ? (float) $r['percent'] : null, 'last_date' => $r['last_date'],
            'shortfall' => $r['percent'] !== null && (float) $r['percent'] < $f['threshold'] ? att_classes_needed((float) $r['attended'], (int) $r['held'], $f['threshold']) : 0,
            'last_alert' => $lastAlert[(int) $r['id']] ?? null,
        ];
    }
    $sessions = (int) db_value("SELECT COUNT(*) FROM attendance a JOIN programs ap ON ap.id = a.program_id WHERE $where", $args);
    return [
        'rows' => $out, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'filters' => $f, 'source' => $source,
        'summary' => [
            'students' => $total, 'below' => (int) $agg['below'], 'sessions' => $sessions, 'min_percent' => $min, 'threshold' => $f['threshold'],
            'percent' => $agg['held'] ? round(100 * $agg['attended'] / $agg['held'], 1) : null, 'lowest' => $agg['lowest'] !== null ? (float) $agg['lowest'] : null,
        ],
    ];
}

/** Last low-attendance alert time per student id (from the per-student audit entries). */
function att_last_alerts(array $studentIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$ids) {
        return [];
    }
    $out = [];
    $keys = array_map(fn ($i) => 'student:' . $i, $ids);
    foreach (db_all("SELECT record_id, MAX(created_at) AS at FROM activity_logs WHERE module = 'attendance' AND action = 'alert' AND record_id IN ("
        . implode(',', array_fill(0, count($keys), '?')) . ') GROUP BY record_id', $keys) as $r) {
        $out[(int) substr($r['record_id'], 8)] = $r['at'];
    }
    return $out;
}

/** Consecutive classes a student must attend to reach the threshold. */
function att_classes_needed(float $attended, int $held, float $threshold): int
{
    if ($threshold >= 100) {
        return 0;
    }
    $t = $threshold / 100;
    $n = (int) ceil(($t * $held - $attended) / (1 - $t));
    return max(0, $n);
}

function att_report_departments(array $q): array
{
    [$where, $args, $f] = att_report_scope($q);
    $att = att_sheet_attended_sql();
    $rows = db_all("SELECT d.id, d.name, d.code, COUNT(*) AS sessions, SUM(a.total_count) AS held, SUM(a.present_count) AS present, SUM(a.absent_count) AS absent,
                    SUM(a.late_count) AS late, SUM(a.leave_count) AS on_leave, SUM($att) AS attended, ROUND(100 * SUM($att) / NULLIF(SUM(a.total_count), 0), 1) AS percent
                    FROM attendance a JOIN programs ap ON ap.id = a.program_id JOIN departments d ON d.id = ap.department_id
                    WHERE $where GROUP BY d.id, d.name, d.code ORDER BY d.name", $args);
    $programs = db_all("SELECT ap.department_id, ap.id, ap.short_name, ap.name, COUNT(*) AS sessions, ROUND(100 * SUM($att) / NULLIF(SUM(a.total_count), 0), 1) AS percent
                        FROM attendance a JOIN programs ap ON ap.id = a.program_id WHERE $where
                        GROUP BY ap.department_id, ap.id, ap.short_name, ap.name, ap.sort_order ORDER BY ap.sort_order, ap.short_name", $args);
    // Students & defaulters per department / program (per-student aggregates)
    [$inner, $innerArgs, $outerWhere, $outerArgs] = att_student_agg_source($f, $where, $args);
    $stats = [];
    foreach (db_all('SELECT p.department_id AS dept, p.id AS program_id, COUNT(*) AS students, SUM(x.percent < ?) AS below FROM (' . $inner . ') x
                     JOIN students s ON s.id = x.person_id JOIN programs p ON p.id = s.program_id' . ($outerWhere ? ' WHERE ' . implode(' AND ', $outerWhere) : '') . '
                     GROUP BY p.department_id, p.id', array_merge([$f['threshold']], $innerArgs, $outerArgs)) as $r) {
        $d = (int) $r['dept'];
        $stats[$d]['students'] = ($stats[$d]['students'] ?? 0) + (int) $r['students'];
        $stats[$d]['below'] = ($stats[$d]['below'] ?? 0) + (int) $r['below'];
        $stats[$d]['programs'][(int) $r['program_id']] = (int) $r['students'];
    }
    $byDept = [];
    foreach ($programs as $p) {
        $d = (int) $p['department_id'];
        $byDept[$d][] = ['id' => (int) $p['id'], 'short_name' => $p['short_name'], 'name' => $p['name'], 'students' => $stats[$d]['programs'][(int) $p['id']] ?? 0,
            'sessions' => (int) $p['sessions'], 'percent' => $p['percent'] !== null ? (float) $p['percent'] : null];
    }
    $out = [];
    $totHeld = 0;
    $totAtt = 0;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $out[] = [
            'id' => $id, 'name' => $r['name'], 'code' => $r['code'], 'sessions' => (int) $r['sessions'], 'students' => $stats[$id]['students'] ?? 0, 'held' => (int) $r['held'],
            'present' => (int) $r['present'], 'absent' => (int) $r['absent'], 'late' => (int) $r['late'], 'leave' => (int) $r['on_leave'],
            'percent' => $r['percent'] !== null ? (float) $r['percent'] : null, 'below' => $stats[$id]['below'] ?? 0, 'programs' => $byDept[$id] ?? [],
        ];
        $totHeld += (int) $r['held'];
        $totAtt += (float) $r['attended'];
    }
    return ['rows' => $out, 'filters' => $f, 'summary' => [
        'departments' => count($out), 'students' => array_sum(array_column($out, 'students')), 'sessions' => array_sum(array_column($out, 'sessions')),
        'below' => array_sum(array_column($out, 'below')), 'percent' => $totHeld ? round(100 * $totAtt / $totHeld, 1) : null, 'threshold' => $f['threshold'],
    ]];
}

function att_report_subjects(array $q, array $opts = []): array
{
    [$where, $args, $f] = att_report_scope($q);
    $att = att_sheet_attended_sql();
    $rows = db_all("SELECT a.subject_id, a.section_id, COUNT(*) AS sessions, MAX(a.total_count) AS students, SUM(a.total_count) AS held,
                    SUM(a.present_count) AS present, SUM(a.absent_count) AS absent, SUM(a.late_count) AS late, SUM(a.leave_count) AS on_leave,
                    ROUND(100 * SUM($att) / NULLIF(SUM(a.total_count), 0), 1) AS percent, MAX(a.attendance_date) AS last_date, MAX(a.faculty_id) AS faculty_id
                    FROM attendance a JOIN programs ap ON ap.id = a.program_id
                    WHERE $where AND a.subject_id IS NOT NULL GROUP BY a.subject_id, a.section_id", $args);
    $subjects = [];
    $sections = [];
    if ($rows) {
        $subjects = array_column(db_all('SELECT id, code, name, type FROM subjects WHERE id IN (' . implode(',', array_unique(array_map(fn ($r) => (int) $r['subject_id'], $rows))) . ')'), null, 'id');
        $sections = db_pairs('SELECT sc.id, ' . att_class_label_sql() . ' FROM sections sc JOIN programs p ON p.id = sc.program_id WHERE sc.id IN (' . implode(',', array_unique(array_map(fn ($r) => (int) $r['section_id'], $rows))) . ')');
    }
    $assigned = [];
    foreach (db_all('SELECT fs.subject_id, fs.section_id, fs.faculty_id FROM faculty_subjects fs WHERE fs.section_id IS NOT NULL ORDER BY fs.is_primary DESC, fs.id DESC') as $a) {
        $assigned[$a['subject_id'] . ':' . $a['section_id']] ??= (int) $a['faculty_id'];
    }
    $facIds = [];
    foreach ($rows as $r) {
        $facIds[] = $assigned[$r['subject_id'] . ':' . $r['section_id']] ?? (int) $r['faculty_id'];
    }
    $facNames = att_faculty_names($facIds);
    $out = [];
    foreach ($rows as $r) {
        $k = $r['subject_id'] . ':' . $r['section_id'];
        $sb = $subjects[$r['subject_id']] ?? ['code' => '', 'name' => '', 'type' => ''];
        $fid = $assigned[$k] ?? (int) $r['faculty_id'];
        $out[] = [
            'key' => $k, 'subject_id' => (int) $r['subject_id'], 'section_id' => (int) $r['section_id'], 'code' => $sb['code'], 'name' => $sb['name'], 'type' => $sb['type'],
            'section_label' => $sections[$r['section_id']] ?? '', 'faculty_name' => $facNames[$fid] ?? null, 'sessions' => (int) $r['sessions'], 'students' => (int) $r['students'],
            'held' => (int) $r['held'], 'present' => (int) $r['present'], 'absent' => (int) $r['absent'], 'late' => (int) $r['late'], 'leave' => (int) $r['on_leave'],
            'percent' => $r['percent'] !== null ? (float) $r['percent'] : null, 'below' => null, 'last_date' => $r['last_date'],
        ];
    }
    if ($f['q'] !== '') {
        $needle = mb_strtolower($f['q']);
        $out = array_values(array_filter($out, fn ($r) => str_contains(mb_strtolower($r['code'] . ' ' . $r['name'] . ' ' . $r['section_label'] . ' ' . $r['faculty_name']), $needle)));
    }
    $sort = $opts['sort'] ?? 'section';
    $dir = strtolower((string) ($opts['dir'] ?? 'asc')) === 'desc' ? -1 : 1;
    usort($out, function ($a, $b) use ($sort, $dir) {
        $cmp = match ($sort) {
            'percent' => $a['percent'] <=> $b['percent'], 'sessions' => $a['sessions'] <=> $b['sessions'], 'absent' => $a['absent'] <=> $b['absent'],
            'code' => strcmp($a['code'], $b['code']), 'faculty' => strcmp((string) $a['faculty_name'], (string) $b['faculty_name']),
            default => strcmp($a['section_label'], $b['section_label']) ?: strcmp($a['code'], $b['code']),
        };
        return $cmp * $dir;
    });
    $total = count($out);
    $perPage = (int) ($opts['per_page'] ?? 25);
    $sumHeld = array_sum(array_column($out, 'held'));
    $sumAtt = array_sum(array_map(fn ($r) => $r['present'] + (att_late_counts() ? $r['late'] : 0), $out));
    $summary = [
        'subjects' => $total, 'sessions' => array_sum(array_column($out, 'sessions')), 'percent' => $sumHeld ? round(100 * $sumAtt / $sumHeld, 1) : null,
        'threshold' => $f['threshold'], 'lowest' => $out ? min(array_map(fn ($r) => $r['percent'] ?? 100, $out)) : null,
        'below_subjects' => count(array_filter($out, fn ($r) => $r['percent'] !== null && $r['percent'] < $f['threshold'])),
    ];
    $p = $perPage > 0 ? paginate($total, max(1, (int) ($opts['page'] ?? 1)), min(100, $perPage)) : ['page' => 1, 'per_page' => max(1, $total), 'pages' => 1, 'offset' => 0];
    $out = $perPage > 0 ? array_slice($out, $p['offset'], $p['per_page']) : $out;
    // Students below the threshold in each listed subject/class (computed for the returned rows only)
    if ($out) {
        $pairs = [];
        $pairArgs = [];
        foreach ($out as $r) {
            $pairs[] = '(a.subject_id = ? AND a.section_id = ?)';
            array_push($pairArgs, $r['subject_id'], $r['section_id']);
        }
        $pct = att_attended_expr();
        $below = [];
        foreach (db_all("SELECT x.subject_id, x.section_id, COUNT(*) AS n FROM (SELECT a.subject_id, a.section_id, r.person_id, 100 * SUM($pct) / COUNT(r.id) AS pct
                         FROM attendance a JOIN programs ap ON ap.id = a.program_id JOIN attendance_records r FORCE INDEX (idx_attrec_sheet_status) ON r.attendance_id = a.id
                         WHERE $where AND (" . implode(' OR ', $pairs) . ") GROUP BY a.subject_id, a.section_id, r.person_id) x WHERE x.pct < ? GROUP BY x.subject_id, x.section_id",
            array_merge($args, $pairArgs, [$f['threshold']])) as $b) {
            $below[$b['subject_id'] . ':' . $b['section_id']] = (int) $b['n'];
        }
        foreach ($out as &$r) {
            $r['below'] = $below[$r['key']] ?? 0;
        }
        unset($r);
    }
    return ['rows' => $out, 'total' => $total, 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'filters' => $f, 'summary' => $summary];
}

/** Monthly register (students x days) for a section. */
function att_report_monthly(array $q): array
{
    $sectionId = (int) ($q['section_id'] ?? 0);
    $sec = $sectionId ? att_section($sectionId) : null;
    if (!$sec) {
        throw new CrudValidationException(['section_id' => 'Select a section to view the monthly register.']);
    }
    $month = att_valid_month($q['month'] ?? null) ? $q['month'] : date('Y-m');
    $subjectId = !empty($q['subject_id']) && ctype_digit((string) $q['subject_id']) ? (int) $q['subject_id'] : null;
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $sw = $subjectId ? ' AND a.subject_id = ?' : '';
    $args = $subjectId ? [$sectionId, $from, $to, $subjectId] : [$sectionId, $from, $to];
    $recs = db_all("SELECT a.attendance_date, r.person_id, r.status FROM attendance a JOIN attendance_records r ON r.attendance_id = a.id AND r.person_type = 'student'
                    WHERE a.type = 'student' AND a.section_id = ? AND a.attendance_date BETWEEN ? AND ?$sw ORDER BY a.attendance_date, a.time_slot_id", $args);
    $sessionsPerDay = db_pairs("SELECT a.attendance_date, COUNT(*) FROM attendance a WHERE a.type = 'student' AND a.section_id = ? AND a.attendance_date BETWEEN ? AND ?$sw GROUP BY a.attendance_date", $args);
    $ids = array_map('intval', db_column("SELECT id FROM students WHERE section_id = ? AND status = 'active'", [$sectionId]));
    $ids = array_values(array_unique(array_merge($ids, array_map(fn ($r) => (int) $r['person_id'], $recs))));
    $students = $ids ? db_all('SELECT id, student_uid, roll_no, first_name, last_name, photo FROM students WHERE id IN (' . implode(',', $ids) . ') ORDER BY roll_no, first_name, id') : [];
    $late = att_late_counts() ? 1 : 0;
    $cells = [];
    foreach ($recs as $r) {
        $d = (int) substr($r['attendance_date'], 8, 2);
        $c = &$cells[(int) $r['person_id']][$d];
        $c ??= ['held' => 0, 'attended' => 0, 'codes' => []];
        $c['held']++;
        $c['attended'] += $r['status'] === 'present' || ($r['status'] === 'late' && $late) ? 1 : 0;
        $c['codes'][] = att_status_code($r['status']);
        unset($c);
    }
    $out = [];
    $daily = [];
    foreach ($students as $s) {
        $sid = (int) $s['id'];
        $row = ['id' => $sid, 'name' => trim($s['first_name'] . ' ' . $s['last_name']), 'student_uid' => $s['student_uid'], 'roll_no' => $s['roll_no'], 'photo' => $s['photo'], 'cells' => []];
        $held = 0;
        $attended = 0;
        foreach ($cells[$sid] ?? [] as $d => $c) {
            $row['cells'][$d] = count($c['codes']) === 1 ? ['v' => $c['codes'][0], 'a' => $c['attended'], 'h' => 1] : ['v' => $c['attended'] . '/' . $c['held'], 'a' => $c['attended'], 'h' => $c['held']];
            $held += $c['held'];
            $attended += $c['attended'];
            $daily[$d]['a'] = ($daily[$d]['a'] ?? 0) + $c['attended'];
            $daily[$d]['h'] = ($daily[$d]['h'] ?? 0) + $c['held'];
        }
        $row['cells'] = (object) $row['cells'];
        $row['held'] = $held;
        $row['attended'] = $attended;
        $row['percent'] = $held ? round(100 * $attended / $held, 1) : null;
        $out[] = $row;
    }
    $dailyOut = [];
    foreach ($daily as $d => $v) {
        $dailyOut[$d] = $v['h'] ? round(100 * $v['a'] / $v['h']) : null;
    }
    $subject = $subjectId ? db_row('SELECT id, code, name FROM subjects WHERE id = ?', [$subjectId]) : null;
    $sumH = array_sum(array_column($out, 'held'));
    $sumA = array_sum(array_column($out, 'attended'));
    return [
        'section' => $sec, 'subject' => $subject, 'month' => $month, 'from' => $from, 'to' => $to, 'days' => att_month_days($from, $to),
        'sessions_per_day' => (object) array_combine(array_map(fn ($d) => (int) substr($d, 8, 2), array_keys($sessionsPerDay)), array_map('intval', array_values($sessionsPerDay))),
        'students' => $out, 'daily' => (object) $dailyOut, 'min_percent' => att_min_percent(),
        'summary' => ['students' => count($out), 'sessions' => array_sum(array_map('intval', $sessionsPerDay)), 'days' => count($sessionsPerDay),
            'percent' => $sumH ? round(100 * $sumA / $sumH, 1) : null, 'below' => count(array_filter($out, fn ($r) => $r['percent'] !== null && $r['percent'] < att_min_percent()))],
    ];
}

/** Subject-wise + monthly breakdown for one student. */
function att_student_detail(int $studentId, array $q): array
{
    $s = db_row('SELECT s.id, s.student_uid, s.roll_no, s.first_name, s.last_name, s.photo, s.email, s.mobile, s.status, s.section_id, ' . att_class_label_sql('p', 'sc') . " AS class_label
                 FROM students s LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN sections sc ON sc.id = s.section_id WHERE s.id = ?", [$studentId]);
    if (!$s) {
        throw new CrudException('Student not found.');
    }
    [, , $f] = att_report_scope($q);
    $att = att_attended_expr();
    $subjects = db_all("SELECT sb.id, sb.code, sb.name, COUNT(r.id) AS held, SUM(r.status = 'present') AS present, SUM(r.status = 'absent') AS absent, SUM(r.status = 'late') AS late,
                        SUM(r.status = 'leave') AS on_leave, SUM($att) AS attended, " . att_pct_expr() . " AS percent
                        FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id JOIN subjects sb ON sb.id = a.subject_id
                        WHERE r.person_type = 'student' AND r.person_id = ? AND a.type = 'student' AND a.attendance_date BETWEEN ? AND ?
                        GROUP BY sb.id, sb.code, sb.name ORDER BY sb.code", [$studentId, $f['from'], $f['to']]);
    $monthly = db_all("SELECT DATE_FORMAT(a.attendance_date, '%Y-%m') AS month, COUNT(r.id) AS held, " . att_pct_expr() . " AS percent
                       FROM attendance_records r JOIN attendance a ON a.id = r.attendance_id
                       WHERE r.person_type = 'student' AND r.person_id = ? AND a.type = 'student' AND a.attendance_date BETWEEN ? AND ?
                       GROUP BY DATE_FORMAT(a.attendance_date, '%Y-%m') ORDER BY month", [$studentId, $f['from'], $f['to']]);
    $held = array_sum(array_map(fn ($r) => (int) $r['held'], $subjects));
    $attended = array_sum(array_map(fn ($r) => (float) $r['attended'], $subjects));
    $min = att_min_percent();
    return [
        'student' => ['id' => (int) $s['id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name']), 'student_uid' => $s['student_uid'], 'roll_no' => $s['roll_no'],
            'photo' => $s['photo'], 'email' => $s['email'], 'mobile' => $s['mobile'], 'status' => $s['status'], 'class_label' => $s['class_label']],
        'filters' => $f, 'min_percent' => $min,
        'overall' => ['held' => $held, 'attended' => $attended, 'percent' => $held ? round(100 * $attended / $held, 1) : null,
            'needed' => $held && 100 * $attended / $held < $min ? att_classes_needed($attended, $held, $min) : 0,
            'absent' => array_sum(array_map(fn ($r) => (int) $r['absent'], $subjects)), 'late' => array_sum(array_map(fn ($r) => (int) $r['late'], $subjects)),
            'leave' => array_sum(array_map(fn ($r) => (int) $r['on_leave'], $subjects))],
        'subjects' => array_map(fn ($r) => ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'held' => (int) $r['held'], 'present' => (int) $r['present'],
            'absent' => (int) $r['absent'], 'late' => (int) $r['late'], 'leave' => (int) $r['on_leave'], 'percent' => (float) $r['percent']], $subjects),
        'monthly' => array_map(fn ($r) => ['month' => $r['month'], 'label' => date('M Y', strtotime($r['month'] . '-01')), 'held' => (int) $r['held'], 'percent' => (float) $r['percent']], $monthly),
        'last_alert' => att_last_alerts([$studentId])[$studentId] ?? null,
    ];
}

/* ------------------------------------------------------------------
 * Low attendance alerts (email / SMS / WhatsApp / in-app)
 * ------------------------------------------------------------------ */

/**
 * Send low-attendance alerts to students and their parents.
 * $channels subset of: inapp, email, sms, whatsapp. Students at/above the threshold are skipped.
 */
function att_send_alerts(array $studentIds, array $channels, array $q): array
{
    $channels = array_values(array_intersect(['inapp', 'email', 'sms', 'whatsapp'], $channels));
    if (!$channels) {
        throw new CrudValidationException(['channels' => 'Choose at least one channel.']);
    }
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$studentIds) {
        throw new CrudValidationException(['student_ids' => 'Select at least one student.']);
    }
    if (count($studentIds) > 500) {
        throw new CrudValidationException(['student_ids' => 'You can alert at most 500 students at a time. Narrow the filters and try again.']);
    }
    $report = att_report_students($q, ['ids' => $studentIds, 'per_page' => 0]);
    $threshold = $report['filters']['threshold'];
    $period = format_date($report['filters']['from']) . ' - ' . format_date($report['filters']['to']);
    $stats = ['students' => 0, 'skipped' => 0, 'email' => 0, 'sms' => 0, 'whatsapp' => 0, 'inapp' => 0, 'failed' => 0];
    $inst = institute_name();
    $byId = array_column($report['rows'], null, 'id');
    $skipped = count(array_diff($studentIds, array_keys($byId)));
    $stats['skipped'] += $skipped;
    foreach ($byId as $sid => $r) {
        if ($r['percent'] === null || $r['percent'] >= $threshold) {
            $stats['skipped']++;
            continue;
        }
        $st = db_row('SELECT s.*, sc.class_teacher_id FROM students s LEFT JOIN sections sc ON sc.id = s.section_id WHERE s.id = ?', [$sid]);
        if (!$st) {
            $stats['skipped']++;
            continue;
        }
        $parents = db_all('SELECT relation, name, phone, email FROM student_parents WHERE student_id = ?', [$sid]);
        $name = trim($st['first_name'] . ' ' . $st['last_name']);
        $vars = ['name' => $name, 'percent' => number_format($r['percent'], 1), 'required' => rtrim(rtrim(number_format($threshold, 1), '0'), '.'),
            'subject' => 'Attendance alert for ' . $name];
        $opts = ['related_type' => 'attendance_alert', 'related_id' => $sid];
        $sentAny = false;
        if (in_array('email', $channels, true)) {
            $emails = array_values(array_unique(array_filter(array_merge([$st['email']], array_column($parents, 'email')), fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))));
            if ($emails) {
                if (send_template_mail('low_attendance', $emails, $vars, $opts)) {
                    $stats['email'] += count($emails);
                } else {
                    $stats['failed']++;
                }
                $sentAny = true;
            }
        }
        $phones = array_values(array_unique(array_filter(array_merge([$st['mobile']], array_column($parents, 'phone'), [$st['guardian_phone']]))));
        $sms = sprintf('%s: Attendance of %s is %s%% (%s), below the required %s%%. Please ensure regular attendance.', $inst, $name, $vars['percent'], $period, $vars['required']);
        foreach (['sms', 'whatsapp'] as $ch) {
            if (in_array($ch, $channels, true) && $phones) {
                foreach ($phones as $ph) {
                    $ch === 'sms' ? send_sms($ph, $sms, $opts) : send_whatsapp($ph, $sms, $opts);
                    $stats[$ch]++;
                }
                $sentAny = true;
            }
        }
        if (in_array('inapp', $channels, true)) {
            $targets = array_filter([$st['user_id'] ? (int) $st['user_id'] : null,
                $st['class_teacher_id'] ? (int) db_value('SELECT user_id FROM faculty WHERE id = ?', [$st['class_teacher_id']]) : null]);
            if ($targets) {
                $stats['inapp'] += notify(array_values(array_unique($targets)), 'attendance', 'Low attendance: ' . $name,
                    $name . ' (' . $r['class_label'] . ') has ' . $vars['percent'] . '% attendance, below the required ' . $vars['required'] . '%.', 'attendance/reports?tab=defaulters', 'user-x');
            }
            $sentAny = true;
        }
        if ($sentAny) {
            $stats['students']++;
            // Per-student audit entry (also drives the "last alerted" column)
            log_activity('alert', 'attendance', 'student:' . $sid, 'Low attendance alert (' . $vars['percent'] . '%) sent to ' . $name . ' via ' . implode(', ', $channels));
        } else {
            $stats['skipped']++;
        }
    }
    if ($stats['students']) {
        notify('perm:attendance', 'attendance', 'Low attendance alerts sent',
            sprintf('%d student%s alerted (%s) by %s.', $stats['students'], $stats['students'] === 1 ? '' : 's', implode(', ', array_map('strtoupper', $channels)), current_user()['name'] ?? 'System'),
            'attendance/reports?tab=defaulters', 'send');
    }
    if ($stats['students']) {
        log_activity('notify', 'attendance', null, sprintf('Sent low attendance alerts to %d student(s) below %s%% (email %d, SMS %d, WhatsApp %d, in-app %d; %d skipped)',
            $stats['students'], $threshold, $stats['email'], $stats['sms'], $stats['whatsapp'], $stats['inapp'], $stats['skipped']), 'success', ['channels' => $channels, 'students' => array_slice($studentIds, 0, 100)]);
    }
    return $stats;
}

/* ------------------------------------------------------------------
 * Device integration (biometric / QR punches)
 * ------------------------------------------------------------------ */

function att_device_token(): ?string
{
    $t = (string) setting('attendance_device_token', '');
    return strlen($t) >= 20 ? $t : null;
}

function att_device_info(): array
{
    $token = att_device_token();
    $recent = db_all("SELECT p.*, CASE p.person_type WHEN 'student' THEN TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) WHEN 'faculty' THEN TRIM(CONCAT_WS(' ', f.first_name, f.last_name))
                      WHEN 'staff' THEN TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) END AS person_name
                      FROM attendance_punches p LEFT JOIN students s ON p.person_type = 'student' AND s.id = p.person_id
                      LEFT JOIN faculty f ON p.person_type = 'faculty' AND f.id = p.person_id LEFT JOIN staff st ON p.person_type = 'staff' AND st.id = p.person_id
                      ORDER BY p.punched_at DESC, p.id DESC LIMIT 10");
    foreach ($recent as &$r) {
        $r['id'] = (int) $r['id'];
    }
    return [
        'configured' => $token !== null, 'token_hint' => $token ? str_repeat('•', 8) . substr($token, -4) : null,
        'endpoint' => absolute_url('api/attendance/punch'), 'late_after' => att_employee_late_after(),
        'stats' => [
            'today' => (int) db_value('SELECT COUNT(*) FROM attendance_punches WHERE punched_at >= ?', [date('Y-m-d 00:00:00')]),
            'recorded_today' => (int) db_value("SELECT COUNT(*) FROM attendance_punches WHERE punched_at >= ? AND result = 'recorded'", [date('Y-m-d 00:00:00')]),
            'devices' => (int) db_value('SELECT COUNT(DISTINCT device_id) FROM attendance_punches WHERE punched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'),
        ],
        'recent' => $recent,
    ];
}

/** Resolve a punch identifier (student ID / roll no / admission no / employee ID, optionally "GIMT:STU:<id>") to a person. */
function att_resolve_person(string $identifier, ?string $type = null): ?array
{
    $id = trim($identifier);
    if (preg_match('/^GIMT:(STU|FAC|STF|EMP):(.+)$/i', $id, $m)) {
        $type = ['STU' => 'student', 'FAC' => 'faculty', 'STF' => 'staff', 'EMP' => null][strtoupper($m[1])];
        $id = trim($m[2]);
    }
    if ($id === '') {
        return null;
    }
    if (!$type || $type === 'student') {
        $s = db_row("SELECT id, first_name, last_name, section_id FROM students WHERE status = 'active' AND (student_uid = ? OR roll_no = ? OR admission_no = ?) LIMIT 1", [$id, $id, $id]);
        if ($s) {
            return ['type' => 'student', 'id' => (int) $s['id'], 'name' => trim($s['first_name'] . ' ' . $s['last_name']), 'section_id' => $s['section_id'] ? (int) $s['section_id'] : null];
        }
    }
    if (!$type || $type === 'faculty') {
        $f = db_row("SELECT id, title, first_name, last_name FROM faculty WHERE status IN ('active','on_leave') AND employee_id = ? LIMIT 1", [$id]);
        if ($f) {
            return ['type' => 'faculty', 'id' => (int) $f['id'], 'name' => trim($f['title'] . ' ' . $f['first_name'] . ' ' . $f['last_name'])];
        }
    }
    if (!$type || $type === 'staff') {
        $st = db_row("SELECT id, first_name, last_name FROM staff WHERE status IN ('active','on_leave') AND employee_id = ? LIMIT 1", [$id]);
        if ($st) {
            return ['type' => 'staff', 'id' => (int) $st['id'], 'name' => trim($st['first_name'] . ' ' . $st['last_name'])];
        }
    }
    return null;
}

/**
 * Process one device punch. Idempotent per (device_id, punch_id) - a retried punch returns the original result.
 * @return array{status:int, data:array, message:string}
 */
function att_process_punch(array $in, string $ip): array
{
    $errors = validate($in, [
        'device_id' => 'required|max:60|regex:/^[A-Za-z0-9_.:\-]+$/', 'identifier' => 'required|max:100', 'punch_id' => 'max:100',
        'punched_at' => 'max:30', 'method' => 'in:biometric,qr,rfid', 'direction' => 'in:in,out,auto', 'person_type' => 'in:student,faculty,staff',
    ]);
    if ($errors) {
        return ['status' => 422, 'data' => ['errors' => $errors], 'message' => 'Invalid punch payload.'];
    }
    $device = (string) $in['device_id'];
    $identifier = trim((string) $in['identifier']);
    $method = (string) ($in['method'] ?? 'biometric') ?: 'biometric';
    $direction = (string) ($in['direction'] ?? 'auto') ?: 'auto';
    $ts = !empty($in['punched_at']) ? strtotime(str_replace('T', ' ', (string) $in['punched_at'])) : time();
    if (!$ts) {
        return ['status' => 422, 'data' => ['errors' => ['punched_at' => 'punched_at must be a valid date-time (Y-m-d H:i:s).']], 'message' => 'Invalid punch payload.'];
    }
    if ($ts > time() + 300) {
        return ['status' => 422, 'data' => ['errors' => ['punched_at' => 'punched_at is in the future. Check the device clock.']], 'message' => 'Invalid punch time.'];
    }
    if ($ts < strtotime('-7 days')) {
        return ['status' => 422, 'data' => ['errors' => ['punched_at' => 'Punches older than 7 days are not accepted. Mark attendance manually.']], 'message' => 'Punch too old.'];
    }
    $punchedAt = date('Y-m-d H:i:s', $ts);
    $punchKey = trim((string) ($in['punch_id'] ?? '')) ?: substr(hash('sha256', $device . '|' . $identifier . '|' . $punchedAt), 0, 40);

    $existing = db_row('SELECT * FROM attendance_punches WHERE device_id = ? AND punch_key = ?', [$device, $punchKey]);
    if ($existing) {
        return ['status' => 200, 'data' => att_punch_payload($existing) + ['duplicate' => true], 'message' => 'Duplicate punch ignored (already processed).'];
    }

    $person = att_resolve_person($identifier, $in['person_type'] ?? null);
    $log = ['device_id' => $device, 'punch_key' => $punchKey, 'method' => $method, 'identifier' => mb_substr($identifier, 0, 100), 'punched_at' => $punchedAt,
        'direction' => $direction, 'ip_address' => mb_substr($ip, 0, 45), 'person_type' => $person['type'] ?? null, 'person_id' => $person['id'] ?? null];
    $date = date('Y-m-d', $ts);
    $time = date('H:i:s', $ts);

    try {
        $res = db_transaction(function () use ($person, $date, $time, $direction, $method, &$log) {
            if (!$person) {
                return ['result' => 'unknown_person', 'message' => 'No active student or employee matches this ID.'];
            }
            if ($person['type'] === 'student') {
                $day = att_day_info($date);
                if ($day['blocked'] && !$day['future']) {
                    return ['result' => 'holiday', 'message' => $day['blocked']];
                }
                if (!$person['section_id']) {
                    return ['result' => 'no_class', 'message' => 'Student is not assigned to a section.'];
                }
                $p = db_row("SELECT t.id, t.subject_id, t.time_slot_id, t.faculty_id, ts.start_time, ts.end_time, sc.program_id, sc.semester_no, sc.academic_session_id
                             FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN sections sc ON sc.id = t.section_id
                             WHERE t.section_id = ? AND t.day_of_week = ? AND t.status = 'published' AND ts.is_break = 0
                               AND ? BETWEEN SUBTIME(ts.start_time, '00:10:00') AND ts.end_time ORDER BY ts.start_time LIMIT 1",
                    [$person['section_id'], (int) date('N', strtotime($date)), $time]);
                if (!$p) {
                    return ['result' => 'no_class', 'message' => 'Punch logged. No class is scheduled for this student at this time.'];
                }
                $key = att_sheet_key('student', $date, $person['section_id'], (int) $p['subject_id'], (int) $p['time_slot_id']);
                $attId = (int) db_value('SELECT id FROM attendance WHERE sheet_key = ?', [$key]);
                if (!$attId) {
                    $attId = db_insert('attendance', ['type' => 'student', 'attendance_date' => $date, 'academic_session_id' => $p['academic_session_id'] ?: current_session_id(),
                        'program_id' => $p['program_id'], 'semester_no' => $p['semester_no'], 'section_id' => $person['section_id'], 'subject_id' => $p['subject_id'],
                        'timetable_id' => $p['id'], 'time_slot_id' => $p['time_slot_id'], 'faculty_id' => $p['faculty_id'], 'sheet_key' => $key, 'method' => $method]);
                }
                $grace = max(0, (int) setting('attendance_late_grace_minutes', 10));
                $status = strtotime($date . ' ' . $time) > strtotime($date . ' ' . $p['start_time']) + $grace * 60 ? 'late' : 'present';
                $rec = db_row("SELECT id, status FROM attendance_records WHERE attendance_id = ? AND person_type = 'student' AND person_id = ?", [$attId, $person['id']]);
                if (!$rec) {
                    db_insert('attendance_records', ['attendance_id' => $attId, 'person_type' => 'student', 'person_id' => $person['id'], 'status' => $status, 'in_time' => $time, 'remarks' => 'Device ' . strtoupper($method)]);
                } elseif ($rec['status'] === 'absent') {
                    db_update('attendance_records', ['status' => $status, 'in_time' => $time], 'id = ?', [(int) $rec['id']]);
                } else {
                    $status = $rec['status'];
                }
                return ['result' => 'recorded', 'attendance_id' => $attId, 'status' => $status, 'message' => 'Marked ' . $status . ' for the ' . date('h:i A', strtotime($p['start_time'])) . ' class.'];
            }
            // Employees: one daily sheet per type, in = first punch, out = last punch
            $type = $person['type'];
            $key = att_sheet_key($type, $date);
            $attId = (int) db_value('SELECT id FROM attendance WHERE sheet_key = ?', [$key]);
            if (!$attId) {
                $attId = db_insert('attendance', ['type' => $type, 'attendance_date' => $date, 'academic_session_id' => current_session_id(), 'sheet_key' => $key, 'method' => $method]);
            }
            $lateAfter = att_employee_late_after();
            $rec = db_row('SELECT id, status, in_time, out_time FROM attendance_records WHERE attendance_id = ? AND person_type = ? AND person_id = ?', [$attId, $type, $person['id']]);
            if (!$rec || !$rec['in_time']) {
                if ($direction === 'out' && $rec) {
                    db_update('attendance_records', ['out_time' => $time], 'id = ?', [(int) $rec['id']]);
                    return ['result' => 'recorded', 'attendance_id' => $attId, 'status' => $rec['status'], 'message' => 'Out time recorded.'];
                }
                $status = substr($time, 0, 5) > $lateAfter ? 'late' : 'present';
                if ($rec) {
                    $keep = in_array($rec['status'], ['leave', 'half_day', 'late', 'present'], true);
                    db_update('attendance_records', ['in_time' => $time] + ($keep ? [] : ['status' => $status]), 'id = ?', [(int) $rec['id']]);
                    $status = $keep ? $rec['status'] : $status;
                } else {
                    db_insert('attendance_records', ['attendance_id' => $attId, 'person_type' => $type, 'person_id' => $person['id'], 'status' => $status, 'in_time' => $time]);
                }
                return ['result' => 'recorded', 'attendance_id' => $attId, 'status' => $status, 'message' => 'In time ' . substr($time, 0, 5) . ' recorded (' . $status . ').'];
            }
            if ($direction === 'in' || $time <= $rec['in_time']) {
                if ($time < $rec['in_time']) {
                    db_update('attendance_records', ['in_time' => $time], 'id = ?', [(int) $rec['id']]);
                }
                return ['result' => 'recorded', 'attendance_id' => $attId, 'status' => $rec['status'], 'message' => 'Already checked in at ' . substr(min($time, $rec['in_time']), 0, 5) . '.'];
            }
            if (strtotime($date . ' ' . $time) - strtotime($date . ' ' . $rec['in_time']) < 120) {
                return ['result' => 'ignored', 'attendance_id' => $attId, 'status' => $rec['status'], 'message' => 'Repeated punch within 2 minutes ignored.'];
            }
            if (!$rec['out_time'] || $time > $rec['out_time']) {
                db_update('attendance_records', ['out_time' => $time], 'id = ?', [(int) $rec['id']]);
            }
            return ['result' => 'recorded', 'attendance_id' => $attId, 'status' => $rec['status'], 'message' => 'Out time ' . substr($time, 0, 5) . ' recorded.'];
        });
        if (!empty($res['attendance_id'])) {
            att_after_records_changed([(int) $res['attendance_id']], $person && $person['type'] === 'student' ? [$person['id']] : []);
        }
        $log += ['result' => $res['result'], 'attendance_id' => $res['attendance_id'] ?? null, 'record_status' => $res['status'] ?? null, 'message' => mb_substr($res['message'], 0, 255)];
        $log['id'] = db_insert('attendance_punches', $log);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            $existing = db_row('SELECT * FROM attendance_punches WHERE device_id = ? AND punch_key = ?', [$device, $punchKey]);
            if ($existing) {
                return ['status' => 200, 'data' => att_punch_payload($existing) + ['duplicate' => true], 'message' => 'Duplicate punch ignored (already processed).'];
            }
        }
        throw $e;
    }
    $payload = att_punch_payload($log) + ['duplicate' => false];
    if ($person) {
        $payload['person'] = ['type' => $person['type'], 'id' => $person['id'], 'name' => $person['name']];
    }
    return ['status' => $log['result'] === 'unknown_person' ? 404 : 200, 'data' => $payload, 'message' => $log['message']];
}

function att_punch_payload(array $p): array
{
    return [
        'punch_id' => $p['punch_key'], 'device_id' => $p['device_id'], 'result' => $p['result'], 'status' => $p['record_status'] ?? null,
        'attendance_id' => !empty($p['attendance_id']) ? (int) $p['attendance_id'] : null, 'person_type' => $p['person_type'] ?? null,
        'person_id' => !empty($p['person_id']) ? (int) $p['person_id'] : null, 'punched_at' => $p['punched_at'], 'message' => $p['message'] ?? '',
    ];
}

/* ------------------------------------------------------------------
 * Export / print tables
 * ------------------------------------------------------------------ */

/**
 * Flat table for a report: ['title', 'subtitle', 'filters' => [...], 'headers' => [...], 'rows' => [[...]], 'orientation', 'percent_cols' => [idx], 'summary' => [...]]
 * report: students | defaulters | department | subject | monthly | employees
 */
function att_report_table(string $report, array $q): array
{
    $min = att_min_percent();
    $pct = fn ($v) => $v === null ? '' : number_format((float) $v, 1) . '%';
    switch ($report) {
        case 'defaulters':
        case 'students':
            $d = $report === 'defaulters';
            $data = att_report_students($q, ['defaulters' => $d, 'per_page' => 0, 'sort' => $q['sort'] ?? null, 'dir' => $q['dir'] ?? null]);
            $headers = ['#', 'Student', 'Student ID', 'Roll No', 'Class', 'Classes', 'Present', 'Late', 'Absent', 'Leave', 'Attendance %'];
            if ($d) {
                $headers[] = 'Classes needed';
                $headers[] = 'Last alert';
            }
            $rows = [];
            foreach ($data['rows'] as $i => $r) {
                $line = [$i + 1, $r['name'], $r['student_uid'], (string) $r['roll_no'], $r['class_label'], $r['held'], $r['present'], $r['late'], $r['absent'], $r['leave'], $pct($r['percent'])];
                if ($d) {
                    $line[] = $r['shortfall'];
                    $line[] = $r['last_alert'] ? format_date($r['last_alert']) : '';
                }
                $rows[] = $line;
            }
            $s = $data['summary'];
            return ['title' => $d ? 'Attendance Defaulters (below ' . $data['filters']['threshold'] . '%)' : 'Student Attendance Report', 'filters' => att_report_filter_labels($data['filters']),
                'headers' => $headers, 'rows' => $rows, 'orientation' => 'landscape', 'percent_cols' => [10], 'threshold' => $data['filters']['threshold'],
                'summary' => ['Students' => number_in($s['students']), 'Classes held' => number_in($s['sessions']), 'Average attendance' => $pct($s['percent']), 'Below ' . $s['threshold'] . '%' => number_in($s['below'])]];
        case 'department':
            $data = att_report_departments($q);
            $rows = [];
            foreach ($data['rows'] as $i => $r) {
                $rows[] = [$i + 1, $r['name'], $r['code'], $r['students'], $r['sessions'], $r['present'], $r['late'], $r['absent'], $r['leave'], $pct($r['percent']), $r['below']];
            }
            $s = $data['summary'];
            return ['title' => 'Department-wise Attendance Report', 'filters' => att_report_filter_labels($data['filters']),
                'headers' => ['#', 'Department', 'Code', 'Students', 'Classes', 'Present', 'Late', 'Absent', 'Leave', 'Attendance %', 'Below ' . $data['filters']['threshold'] . '%'],
                'rows' => $rows, 'orientation' => 'landscape', 'percent_cols' => [9], 'threshold' => $min,
                'summary' => ['Departments' => $s['departments'], 'Students' => number_in($s['students']), 'Average attendance' => $pct($s['percent']), 'Defaulters' => number_in($s['below'])]];
        case 'subject':
            $data = att_report_subjects($q, ['per_page' => 0, 'sort' => $q['sort'] ?? null, 'dir' => $q['dir'] ?? null]);
            $rows = [];
            foreach ($data['rows'] as $i => $r) {
                $rows[] = [$i + 1, $r['code'], $r['name'], $r['section_label'], (string) $r['faculty_name'], $r['sessions'], $r['students'], $r['present'], $r['late'], $r['absent'], $r['leave'], $pct($r['percent']), $r['below']];
            }
            $s = $data['summary'];
            return ['title' => 'Subject-wise Attendance Report', 'filters' => att_report_filter_labels($data['filters']),
                'headers' => ['#', 'Code', 'Subject', 'Class', 'Faculty', 'Classes', 'Students', 'Present', 'Late', 'Absent', 'Leave', 'Attendance %', 'Below ' . $data['filters']['threshold'] . '%'],
                'rows' => $rows, 'orientation' => 'landscape', 'percent_cols' => [11], 'threshold' => $min,
                'summary' => ['Subject classes' => number_in($s['subjects']), 'Classes held' => number_in($s['sessions']), 'Average attendance' => $pct($s['percent']), 'Classes below ' . $s['threshold'] . '%' => number_in($s['below_subjects'])]];
        case 'monthly':
            $data = att_report_monthly($q);
            $headers = ['Roll No', 'Student'];
            foreach ($data['days'] as $day) {
                $headers[] = (string) $day['day'];
            }
            array_push($headers, 'Held', 'Attended', '%');
            $rows = [];
            foreach ($data['students'] as $r) {
                $line = [(string) $r['roll_no'], $r['name']];
                $cells = (array) $r['cells'];
                foreach ($data['days'] as $day) {
                    $line[] = isset($cells[$day['day']]) ? $cells[$day['day']]['v'] : ($day['holiday'] && in_array($day['holiday']['type'], ['holiday', 'vacation'], true) ? 'H' : ($day['weekly_off'] ? 'WO' : ''));
                }
                array_push($line, $r['held'], $r['attended'], $pct($r['percent']));
                $rows[] = $line;
            }
            $s = $data['summary'];
            return ['title' => 'Monthly Attendance Register - ' . date('F Y', strtotime($data['from'])), 'filters' => array_filter([
                'Class: ' . $data['section']['label'], $data['subject'] ? 'Subject: ' . $data['subject']['code'] . ' - ' . $data['subject']['name'] : 'All subjects (attended/held per day)',
                'Class teacher: ' . ($data['section']['class_teacher_name'] ?: '-')]),
                'headers' => $headers, 'rows' => $rows, 'orientation' => 'landscape', 'percent_cols' => [count($headers) - 1], 'threshold' => $min, 'register' => true,
                'legend' => 'P = Present · A = Absent · L = Late · LV = Leave · H = Holiday · WO = Weekly off · x/y = classes attended / held',
                'summary' => ['Students' => $s['students'], 'Class days' => $s['days'], 'Classes held' => $s['sessions'], 'Average attendance' => $pct($s['percent']), 'Below ' . $min . '%' => $s['below']]];
        case 'employees':
            $month = att_valid_month($q['month'] ?? null) ? $q['month'] : date('Y-m');
            $type = in_array($q['type'] ?? 'all', ['all', 'faculty', 'staff'], true) ? ($q['type'] ?? 'all') : 'all';
            $data = att_employee_register($month, $type, !empty($q['department_id']) ? (int) $q['department_id'] : null, trim((string) ($q['q'] ?? '')));
            $headers = ['Emp ID', 'Name', 'Type'];
            foreach ($data['days'] as $day) {
                $headers[] = (string) $day['day'];
            }
            array_push($headers, 'P', 'L', 'HD', 'LV', 'A', '%');
            $rows = [];
            foreach ($data['people'] as $p) {
                $line = [$p['employee_id'], $p['name'], ucfirst($p['person_type'])];
                $cells = (array) $p['cells'];
                foreach ($data['days'] as $day) {
                    $line[] = isset($cells[$day['day']]) ? att_status_code($cells[$day['day']]) : ($day['holiday'] && in_array($day['holiday']['type'], ['holiday', 'vacation'], true) ? 'H' : ($day['weekly_off'] ? 'WO' : ''));
                }
                $t = $p['totals'];
                array_push($line, $t['present'], $t['late'], $t['half_day'], $t['leave'], $t['absent'], $pct($t['percent']));
                $rows[] = $line;
            }
            $labels = ['Month: ' . date('F Y', strtotime($data['from'])), 'Employees: ' . ($type === 'all' ? 'Faculty & staff' : ucfirst($type))];
            if (!empty($q['department_id'])) {
                $labels[] = 'Department: ' . db_value('SELECT name FROM departments WHERE id = ?', [(int) $q['department_id']]);
            }
            return ['title' => 'Employee Attendance Register - ' . date('F Y', strtotime($data['from'])), 'filters' => $labels, 'headers' => $headers, 'rows' => $rows,
                'orientation' => 'landscape', 'percent_cols' => [count($headers) - 1], 'threshold' => 90, 'register' => true,
                'legend' => 'P = Present · L = Late · HD = Half day · LV = Leave · A = Absent · H = Holiday · WO = Weekly off',
                'summary' => ['Employees' => count($data['people'])]];
    }
    throw new InvalidArgumentException('Unknown report: ' . $report);
}
