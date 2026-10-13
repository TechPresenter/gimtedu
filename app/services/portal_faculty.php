<?php
/**
 * Faculty portal - area screens (unit faculty_portal): weekly timetable, my classes + rosters, class attendance,
 * marks entry, leave, own attendance, documents, payroll reference and profile correction requests.
 * Used by api/routes/portal/faculty.php (/api/portal/faculty/...).
 *
 * Scoping (no IDOR): every function receives the signed-in faculty id from portal_faculty_require(). Section / subject /
 * exam / leave / document ids that come from a request are always checked against the faculty member's own
 * assignments before anything is read or written:
 *   - classes   = faculty_subjects rows of the current session (section-specific, or every section of the subject's
 *                 semester when section_id is NULL) + published timetable periods of the current session;
 *   - marks     = exam subjects the faculty teaches (faculty_subjects of the exam's session), limited to the sections
 *                 assigned to them (every section when an assignment has no section);
 *   - leaves, documents, payroll = rows of employee_type 'faculty' with the faculty's own id.
 * Writes reuse the admin services (att_save_student_sheet, exam_save_marks, hr_validate_leave, hr_leave_decide) so
 * registers, aggregates, grades, notifications and the audit trail behave exactly like the admin modules. The admin
 * role permissions (attendance create/edit, results create/edit) still apply on top of the portal's own scoping.
 *
 * Settings: attendance_edit_days (default 7) - how many days back a faculty member may mark or correct a register
 * from the portal (0 = same day only). Locked registers and verified / published marks are never editable here.
 */

require_once APP_ROOT . '/app/services/attendance.php';
require_once APP_ROOT . '/app/services/exams.php';
require_once APP_ROOT . '/app/services/hr.php';

/** Portal scope violation / missing record -> HTTP status (403 not yours, 404 not found, 423 locked). */
class PortalFacultyException extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 403)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/* ------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------ */

function pfac_session_id(): int
{
    return (int) current_session_id();
}

/** Comma list of integers for IN (...) (never empty). */
function pfac_in(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ? implode(',', $ids) : '0';
}

function pfac_valid_date($v): bool
{
    return is_string($v) && att_valid_date($v);
}

function pfac_name(array $r): string
{
    return trim(implode(' ', array_filter([$r['first_name'] ?? null, $r['middle_name'] ?? null, $r['last_name'] ?? null])));
}

/** Users (active) who may edit faculty / HR records - receive document uploads and correction requests. */
function pfac_hr_user_ids(int $exceptUserId = 0): array
{
    return array_values(array_diff(exam_users_with('faculty', 'edit'), [$exceptUserId]));
}

/** Days back a register may be marked / corrected from the portal. */
function pfac_edit_days(): int
{
    return max(0, min(60, (int) setting('attendance_edit_days', 7)));
}

function pfac_earliest_date(): string
{
    return date('Y-m-d', strtotime('-' . pfac_edit_days() . ' days'));
}

/* ------------------------------------------------------------------
 * Classes (section + subject pairs)
 * ------------------------------------------------------------------ */

/**
 * Section + subject pairs taught by the faculty member in the current session.
 * @return array<string, array{section_id:int, subject_id:int, assigned:bool, timetable:bool}> keyed "section:subject"
 */
function pfac_class_pairs(int $fid): array
{
    static $cache = [];
    if (isset($cache[$fid])) {
        return $cache[$fid];
    }
    $sid = pfac_session_id();
    $pairs = [];
    $add = function (int $sec, int $sub, string $src) use (&$pairs): void {
        $k = $sec . ':' . $sub;
        $pairs[$k] = $pairs[$k] ?? ['section_id' => $sec, 'subject_id' => $sub, 'assigned' => false, 'timetable' => false];
        $pairs[$k][$src] = true;
    };
    $rows = db_all('SELECT fs.subject_id, fs.section_id, sb.program_id, sb.semester_no FROM faculty_subjects fs JOIN subjects sb ON sb.id = fs.subject_id
                    WHERE fs.faculty_id = ? AND (fs.academic_session_id IS NULL OR fs.academic_session_id = ?)', [$fid, $sid]);
    foreach ($rows as $r) {
        if ($r['section_id']) {
            $add((int) $r['section_id'], (int) $r['subject_id'], 'assigned');
            continue;
        }
        $secs = db_column("SELECT id FROM sections WHERE program_id = ? AND semester_no = ? AND status = 'active' AND (academic_session_id = ? OR academic_session_id IS NULL)",
            [(int) $r['program_id'], (int) $r['semester_no'], $sid]);
        foreach ($secs as $sec) {
            $add((int) $sec, (int) $r['subject_id'], 'assigned');
        }
    }
    foreach (db_all("SELECT DISTINCT section_id, subject_id FROM timetables WHERE faculty_id = ? AND academic_session_id = ? AND status = 'published'", [$fid, $sid]) as $r) {
        $add((int) $r['section_id'], (int) $r['subject_id'], 'timetable');
    }
    return $cache[$fid] = $pairs;
}

function pfac_teaches(int $fid, int $sectionId, int $subjectId): bool
{
    return $sectionId > 0 && $subjectId > 0 && isset(pfac_class_pairs($fid)[$sectionId . ':' . $subjectId]);
}

function pfac_require_class(int $fid, int $sectionId, int $subjectId): void
{
    if (!pfac_teaches($fid, $sectionId, $subjectId)) {
        throw new PortalFacultyException('This class is not assigned to you.', 403);
    }
}

/** SQL condition limiting attendance alias $a to the faculty's class pairs (+ args). */
function pfac_pairs_sql(int $fid, string $a = 'a'): array
{
    $pairs = pfac_class_pairs($fid);
    if (!$pairs) {
        return ['1 = 0', []];
    }
    $parts = [];
    $args = [];
    foreach ($pairs as $p) {
        $parts[] = "($a.section_id = ? AND $a.subject_id = ?)";
        $args[] = $p['section_id'];
        $args[] = $p['subject_id'];
    }
    return ['(' . implode(' OR ', $parts) . ')', $args];
}

/** Every class of the faculty with roster size, weekly load and session-to-date attendance. */
function pfac_classes(int $fid): array
{
    $pairs = pfac_class_pairs($fid);
    $sid = pfac_session_id();
    $summary = ['classes' => 0, 'students' => 0, 'periods_per_week' => 0, 'attendance_percent' => null, 'class_teacher_of' => []];
    $classTeacher = db_all("SELECT sc.id, CONCAT(p.short_name, ' · Sem ', sc.semester_no, ' · Sec ', sc.name) AS label,
                                   (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS students
                            FROM sections sc JOIN programs p ON p.id = sc.program_id
                            WHERE sc.class_teacher_id = ? AND sc.status = 'active' AND (sc.academic_session_id = ? OR sc.academic_session_id IS NULL)", [$fid, $sid]);
    $summary['class_teacher_of'] = array_map(fn ($r) => ['section_id' => (int) $r['id'], 'label' => $r['label'], 'students' => (int) $r['students']], $classTeacher);
    if (!$pairs) {
        return ['classes' => [], 'summary' => $summary];
    }
    $secIds = array_unique(array_column($pairs, 'section_id'));
    $subIds = array_unique(array_column($pairs, 'subject_id'));
    $sections = [];
    foreach (db_all("SELECT sc.id, sc.name, sc.semester_no, sc.program_id, sc.class_teacher_id, p.short_name AS program, p.name AS program_name, d.name AS department,
                            (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS students
                     FROM sections sc JOIN programs p ON p.id = sc.program_id LEFT JOIN departments d ON d.id = p.department_id
                     WHERE sc.id IN (" . pfac_in($secIds) . ')') as $r) {
        $sections[(int) $r['id']] = $r;
    }
    $subjects = [];
    foreach (db_all('SELECT id, code, name, type, credits, program_id, semester_no FROM subjects WHERE id IN (' . pfac_in($subIds) . ')') as $r) {
        $subjects[(int) $r['id']] = $r;
    }
    $load = [];
    foreach (db_all("SELECT section_id, subject_id, COUNT(*) AS n, GROUP_CONCAT(DISTINCT day_of_week ORDER BY day_of_week) AS days FROM timetables
                     WHERE faculty_id = ? AND academic_session_id = ? AND status = 'published' GROUP BY section_id, subject_id", [$fid, $sid]) as $r) {
        $load[(int) $r['section_id'] . ':' . (int) $r['subject_id']] = $r;
    }
    $w = hr_session_window();
    $att = [];
    foreach (db_all("SELECT a.section_id, a.subject_id, COUNT(*) AS held, SUM(a.total_count) AS marked, SUM(" . att_sheet_attended_sql('a') . ") AS attended, MAX(a.attendance_date) AS last_date
                     FROM attendance a WHERE a.type = 'student' AND a.section_id IN (" . pfac_in($secIds) . ') AND a.subject_id IN (' . pfac_in($subIds) . ')
                     AND a.attendance_date BETWEEN ? AND ? GROUP BY a.section_id, a.subject_id', [$w['start'], $w['end']]) as $r) {
        $att[(int) $r['section_id'] . ':' . (int) $r['subject_id']] = $r;
    }
    $out = [];
    $totMarked = 0;
    $totAttended = 0.0;
    $seenSections = [];
    foreach ($pairs as $k => $p) {
        $sec = $sections[$p['section_id']] ?? null;
        $sub = $subjects[$p['subject_id']] ?? null;
        // a subject mapped to a section of another program / semester cannot be marked - skip inconsistent assignments
        if (!$sec || !$sub || (int) $sub['program_id'] !== (int) $sec['program_id'] || (int) $sub['semester_no'] !== (int) $sec['semester_no']) {
            continue;
        }
        $a = $att[$k] ?? null;
        $marked = (int) ($a['marked'] ?? 0);
        $out[] = [
            'key' => $k, 'section_id' => $p['section_id'], 'subject_id' => $p['subject_id'],
            'subject_code' => $sub['code'], 'subject' => $sub['name'], 'subject_type' => $sub['type'], 'credits' => (float) $sub['credits'],
            'program' => $sec['program'], 'program_name' => $sec['program_name'], 'department' => $sec['department'], 'semester_no' => (int) $sec['semester_no'],
            'section' => $sec['name'], 'label' => $sec['program'] . ' · Sem ' . $sec['semester_no'] . ' · Sec ' . $sec['name'],
            'students' => (int) $sec['students'], 'periods_per_week' => (int) ($load[$k]['n'] ?? 0),
            'days' => isset($load[$k]) ? array_map('intval', explode(',', (string) $load[$k]['days'])) : [],
            'held' => (int) ($a['held'] ?? 0), 'attendance_percent' => $marked ? round(100 * (float) $a['attended'] / $marked, 1) : null,
            'last_marked' => $a['last_date'] ?? null, 'is_class_teacher' => (int) $sec['class_teacher_id'] === $fid,
            'assigned' => $p['assigned'], 'in_timetable' => $p['timetable'],
        ];
        $totMarked += $marked;
        $totAttended += (float) ($a['attended'] ?? 0);
        if (!isset($seenSections[$p['section_id']])) {
            $seenSections[$p['section_id']] = true;
            $summary['students'] += (int) $sec['students'];
        }
        $summary['periods_per_week'] += (int) ($load[$k]['n'] ?? 0);
    }
    usort($out, fn ($a, $b) => [$a['program'], $a['semester_no'], $a['section'], $a['subject_code']] <=> [$b['program'], $b['semester_no'], $b['section'], $b['subject_code']]);
    $summary['classes'] = count($out);
    $summary['attendance_percent'] = $totMarked ? round(100 * $totAttended / $totMarked, 1) : null;
    $summary['min_percent'] = att_min_percent();
    return ['classes' => $out, 'summary' => $summary];
}

/** Section + subject rows of a class the faculty teaches (403 / 404 otherwise). */
function pfac_class_context(int $fid, int $sectionId, int $subjectId): array
{
    pfac_require_class($fid, $sectionId, $subjectId);
    $sec = att_section($sectionId);
    if (!$sec) {
        throw new PortalFacultyException('Class not found.', 404);
    }
    $subject = db_row('SELECT id, code, name, type, credits FROM subjects WHERE id = ? AND program_id = ? AND semester_no = ?', [$subjectId, $sec['program_id'], $sec['semester_no']]);
    if (!$subject) {
        throw new PortalFacultyException('This subject is not taught in that section.', 404);
    }
    return [$sec, $subject];
}

/** Class roster with attendance (overall + this subject), the last registers and masked contact details. */
function pfac_class_roster(int $fid, int $sectionId, int $subjectId): array
{
    [$sec, $subject] = pfac_class_context($fid, $sectionId, $subjectId);
    $students = db_all("SELECT s.id, s.student_uid, s.roll_no, s.first_name, s.middle_name, s.last_name, s.photo, s.gender, s.email, s.mobile
                        FROM students s WHERE s.section_id = ? AND s.status = 'active' ORDER BY s.roll_no IS NULL, s.roll_no, s.first_name, s.id", [$sectionId]);
    $ids = array_map(fn ($s) => (int) $s['id'], $students);
    $pct = att_student_percents($ids, $subjectId);
    $recent = db_all("SELECT a.id, a.attendance_date, a.time_slot_id, ts.name AS slot, ts.start_time, a.total_count, a.present_count, a.absent_count, a.late_count, a.leave_count,
                             a.half_day_count, a.is_locked, a.remarks
                      FROM attendance a LEFT JOIN time_slots ts ON ts.id = a.time_slot_id
                      WHERE a.type = 'student' AND a.section_id = ? AND a.subject_id = ? ORDER BY a.attendance_date DESC, ts.start_time DESC, a.id DESC LIMIT 8", [$sectionId, $subjectId]);
    $trailSheets = array_reverse(array_slice($recent, 0, 6));
    $trail = [];
    if ($trailSheets) {
        foreach (db_all("SELECT attendance_id, person_id, status FROM attendance_records WHERE person_type = 'student' AND attendance_id IN (" . pfac_in(array_column($trailSheets, 'id')) . ')') as $r) {
            $trail[(int) $r['person_id']][(int) $r['attendance_id']] = $r['status'];
        }
    }
    $min = att_min_percent();
    $rows = [];
    $sumPct = 0.0;
    $nPct = 0;
    $below = 0;
    foreach ($students as $s) {
        $id = (int) $s['id'];
        $p = $pct[$id] ?? null;
        $subjPct = $p['subject_percent'] ?? null;
        if ($subjPct !== null) {
            $sumPct += $subjPct;
            $nPct++;
        }
        $isBelow = $subjPct !== null && $subjPct < $min;
        $below += $isBelow ? 1 : 0;
        $rows[] = [
            'id' => $id, 'name' => pfac_name($s), 'student_uid' => $s['student_uid'], 'roll_no' => $s['roll_no'], 'gender' => $s['gender'],
            'photo' => $s['photo'] ? upload_url($s['photo']) : null,
            'email' => portal_mask_email($s['email']), 'mobile' => portal_mask_phone($s['mobile']),
            'percent' => $p['percent'] ?? null, 'held' => $p['held'] ?? 0,
            'subject_percent' => $subjPct, 'subject_held' => $p['subject_held'] ?? 0,
            'below' => $isBelow, 'overall_below' => isset($p['percent']) && $p['percent'] !== null && $p['percent'] < $min,
            'trail' => array_map(fn ($sh) => $trail[$id][(int) $sh['id']] ?? null, $trailSheets),
        ];
    }
    $w = hr_session_window();
    $held = (int) db_value("SELECT COUNT(*) FROM attendance WHERE type = 'student' AND section_id = ? AND subject_id = ? AND attendance_date BETWEEN ? AND ?", [$sectionId, $subjectId, $w['start'], $w['end']]);
    $schedule = db_all("SELECT t.day_of_week, t.type, ts.name AS slot, ts.start_time, ts.end_time, cr.code AS room_code, cr.name AS room
                        FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                        WHERE t.faculty_id = ? AND t.section_id = ? AND t.subject_id = ? AND t.academic_session_id = ? AND t.status = 'published'
                        ORDER BY t.day_of_week, ts.start_time", [$fid, $sectionId, $subjectId, pfac_session_id()]);
    foreach ($schedule as &$sc) {
        $sc['day_of_week'] = (int) $sc['day_of_week'];
    }
    unset($sc);
    $nextExam = db_row("SELECT e.id AS exam_id, e.name AS exam, sch.exam_date, sch.start_time, sch.end_time FROM exam_schedules sch JOIN exams e ON e.id = sch.exam_id
                        WHERE sch.subject_id = ? AND sch.exam_date >= CURDATE() AND e.status NOT IN ('draft', 'cancelled') ORDER BY sch.exam_date, sch.start_time LIMIT 1", [$subjectId]);
    $registers = array_map(function ($r) {
        $c = att_counts_from_row($r);
        return ['id' => (int) $r['id'], 'date' => $r['attendance_date'], 'slot' => $r['slot'], 'start_time' => $r['start_time'], 'time_slot_id' => $r['time_slot_id'] ? (int) $r['time_slot_id'] : null,
            'counts' => $c, 'is_locked' => (bool) $r['is_locked'], 'remarks' => $r['remarks']];
    }, $recent);
    return [
        'class' => [
            'section_id' => $sectionId, 'subject_id' => $subjectId, 'subject_code' => $subject['code'], 'subject' => $subject['name'], 'subject_type' => $subject['type'],
            'credits' => (float) $subject['credits'], 'program' => $sec['program_short'], 'program_name' => $sec['program_name'], 'semester_no' => (int) $sec['semester_no'],
            'section' => $sec['name'], 'label' => $sec['label'], 'class_teacher' => $sec['class_teacher_name'] ?: null, 'is_class_teacher' => (int) $sec['class_teacher_id'] === $fid,
        ],
        'stats' => ['students' => count($rows), 'held' => $held, 'average' => $nPct ? round($sumPct / $nPct, 1) : null, 'below' => $below, 'min_percent' => $min,
            'last_marked' => $recent[0]['attendance_date'] ?? null],
        'trail_dates' => array_map(fn ($sh) => ['date' => $sh['attendance_date'], 'slot' => $sh['slot']], $trailSheets),
        'students' => $rows,
        'registers' => $registers,
        'schedule' => $schedule,
        'next_exam' => $nextExam ?: null,
    ];
}

/* ------------------------------------------------------------------
 * Timetable
 * ------------------------------------------------------------------ */

/** Monday (Y-m-d) of the week containing $date. */
function pfac_week_start(string $date): string
{
    $ts = strtotime($date);
    return date('Y-m-d', $ts - ((int) date('N', $ts) - 1) * 86400);
}

/** Weekly timetable of the faculty member with the dates of one week, holidays and register status per period. */
function pfac_timetable(int $fid, ?string $weekOf = null): array
{
    $sid = pfac_session_id();
    $today = date('Y-m-d');
    $monday = pfac_week_start(pfac_valid_date($weekOf) ? $weekOf : $today);
    $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
    $slots = db_all("SELECT id, name, start_time, end_time, is_break FROM time_slots WHERE status = 'active' ORDER BY sort_order, start_time");
    foreach ($slots as &$s) {
        $s['id'] = (int) $s['id'];
        $s['is_break'] = (bool) $s['is_break'];
    }
    unset($s);
    $periods = db_all("SELECT t.id, t.day_of_week, t.time_slot_id, t.type, t.section_id, t.subject_id, t.semester_no, t.notes, ts.name AS slot, ts.start_time, ts.end_time,
                              sb.code AS subject_code, sb.name AS subject, p.short_name AS program, sc.name AS section, cr.code AS room_code, cr.name AS room, cr.building
                       FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN subjects sb ON sb.id = t.subject_id JOIN programs p ON p.id = t.program_id
                       JOIN sections sc ON sc.id = t.section_id LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                       WHERE t.faculty_id = ? AND t.academic_session_id = ? AND t.status = 'published' ORDER BY t.day_of_week, ts.sort_order, ts.start_time", [$fid, $sid]);
    $offs = att_weekly_off();
    $usedDays = array_unique(array_map(fn ($p) => (int) $p['day_of_week'], $periods));
    $holidays = att_holiday_map($monday, $sunday);
    $days = [];
    for ($d = 1; $d <= 7; $d++) {
        $date = date('Y-m-d', strtotime($monday . ' +' . ($d - 1) . ' days'));
        if (in_array($d, $offs, true) && !in_array($d, $usedDays, true)) {
            continue;
        }
        $h = $holidays[$date] ?? null;
        $days[] = ['dow' => $d, 'name' => date('l', strtotime($date)), 'short' => date('D', strtotime($date)), 'date' => $date, 'is_today' => $date === $today,
            'holiday' => $h && in_array($h['type'], ['holiday', 'vacation'], true) ? $h['title'] : null, 'event' => $h && !in_array($h['type'], ['holiday', 'vacation'], true) ? $h['title'] : null,
            'weekly_off' => in_array($d, $offs, true)];
    }
    $dayByDow = [];
    foreach ($days as $d) {
        $dayByDow[$d['dow']] = $d;
    }
    // registers of the week for the periods already held
    $sheets = [];
    $secIds = array_unique(array_map(fn ($p) => (int) $p['section_id'], $periods));
    if ($secIds && $monday <= $today) {
        foreach (db_all("SELECT attendance_date, section_id, subject_id, time_slot_id, total_count, present_count, late_count, half_day_count, absent_count, leave_count
                         FROM attendance WHERE type = 'student' AND attendance_date BETWEEN ? AND ? AND section_id IN (" . pfac_in($secIds) . ')', [$monday, min($sunday, $today)]) as $a) {
            $k = $a['attendance_date'] . '|' . (int) $a['section_id'] . '|' . (int) $a['subject_id'];
            $sheets[$k . '|' . (int) $a['time_slot_id']] = $a;
            $sheets[$k . '|0'] = $sheets[$k . '|0'] ?? $a;
        }
    }
    $now = date('H:i:s');
    $minutes = 0;
    $byType = [];
    $perDay = [];
    foreach ($periods as &$p) {
        foreach (['id', 'day_of_week', 'time_slot_id', 'section_id', 'subject_id', 'semester_no'] as $k) {
            $p[$k] = (int) $p[$k];
        }
        $minutes += (int) round((strtotime($p['end_time']) - strtotime($p['start_time'])) / 60);
        $byType[$p['type']] = ($byType[$p['type']] ?? 0) + 1;
        $perDay[$p['day_of_week']] = ($perDay[$p['day_of_week']] ?? 0) + 1;
        $day = $dayByDow[$p['day_of_week']] ?? null;
        $p['date'] = $day['date'] ?? null;
        $state = 'upcoming';
        $counts = null;
        if (!$day) {
            $state = 'off';
        } elseif ($day['holiday']) {
            $state = 'holiday';
        } elseif ($day['date'] <= $today) {
            $k = $day['date'] . '|' . $p['section_id'] . '|' . $p['subject_id'];
            $sheet = $sheets[$k . '|' . $p['time_slot_id']] ?? ($sheets[$k . '|0'] ?? null);
            if ($sheet) {
                $state = 'marked';
                $counts = att_counts_from_row($sheet);
            } elseif ($day['date'] < $today || $p['end_time'] <= $now) {
                $state = 'pending';
            } elseif ($p['start_time'] <= $now) {
                $state = 'now';
            }
        }
        $p['state'] = $state;
        $p['counts'] = $counts;
    }
    unset($p);
    arsort($perDay);
    $busiest = $perDay ? (int) array_key_first($perDay) : null;
    return [
        'session' => current_session()['name'] ?? null,
        'week' => ['start' => $monday, 'end' => $sunday, 'is_current' => $monday === pfac_week_start($today),
            'prev' => date('Y-m-d', strtotime($monday . ' -7 days')), 'next' => date('Y-m-d', strtotime($monday . ' +7 days'))],
        'today' => $today, 'now' => $now, 'slots' => $slots, 'days' => $days, 'periods' => $periods,
        'summary' => ['periods' => count($periods), 'hours' => round($minutes / 60, 1), 'subjects' => count(array_unique(array_column($periods, 'subject_id'))),
            'sections' => count($secIds), 'by_type' => $byType, 'busiest_day' => $busiest ? ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$busiest] : null,
            'busiest_count' => $busiest ? $perDay[$busiest] : 0],
    ];
}

/* ------------------------------------------------------------------
 * Class attendance
 * ------------------------------------------------------------------ */

/** Periods of the faculty on a date (from the timetable) with their register status + the manual class picker. */
function pfac_attendance_day(int $fid, string $date): array
{
    if (!pfac_valid_date($date)) {
        throw new CrudValidationException(['date' => 'Choose a valid date.']);
    }
    $sid = pfac_session_id();
    $day = att_day_info($date);
    $earliest = pfac_earliest_date();
    $today = date('Y-m-d');
    $dow = (int) date('N', strtotime($date));
    $periods = db_all("SELECT t.id AS timetable_id, t.section_id, t.subject_id, t.time_slot_id, t.type, t.semester_no, ts.name AS slot, ts.start_time, ts.end_time,
                              sb.code AS subject_code, sb.name AS subject, p.short_name AS program, sc.name AS section, cr.code AS room_code, cr.name AS room,
                              (SELECT COUNT(*) FROM students s WHERE s.section_id = t.section_id AND s.status = 'active') AS students
                       FROM timetables t JOIN time_slots ts ON ts.id = t.time_slot_id JOIN subjects sb ON sb.id = t.subject_id JOIN programs p ON p.id = t.program_id
                       JOIN sections sc ON sc.id = t.section_id LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                       WHERE t.faculty_id = ? AND t.academic_session_id = ? AND t.status = 'published' AND t.day_of_week = ? AND ts.is_break = 0
                       ORDER BY ts.sort_order, ts.start_time", [$fid, $sid, $dow]);
    [$pairSql, $pairArgs] = pfac_pairs_sql($fid, 'a');
    $sheets = db_all("SELECT a.id, a.section_id, a.subject_id, a.time_slot_id, a.is_locked, a.updated_at, a.created_at, a.total_count, a.present_count, a.absent_count, a.late_count,
                             a.leave_count, a.half_day_count, a.faculty_id, u.name AS taken_by_name, ts.name AS slot, ts.start_time, ts.end_time, sb.code AS subject_code, sb.name AS subject,
                             p.short_name AS program, sc.name AS section, sc.semester_no
                      FROM attendance a JOIN subjects sb ON sb.id = a.subject_id JOIN sections sc ON sc.id = a.section_id JOIN programs p ON p.id = sc.program_id
                      LEFT JOIN time_slots ts ON ts.id = a.time_slot_id LEFT JOIN users u ON u.id = a.taken_by
                      WHERE a.type = 'student' AND a.attendance_date = ? AND $pairSql ORDER BY ts.sort_order, a.id", array_merge([$date], $pairArgs));
    $bySheetKey = [];
    foreach ($sheets as $s) {
        $bySheetKey[(int) $s['section_id'] . ':' . (int) $s['subject_id'] . ':' . (int) $s['time_slot_id']] = $s;
    }
    $sheetOut = function (array $s): array {
        return ['id' => (int) $s['id'], 'is_locked' => (bool) $s['is_locked'], 'counts' => att_counts_from_row($s), 'updated_at' => $s['updated_at'] ?: $s['created_at'], 'taken_by' => $s['taken_by_name']];
    };
    $now = date('H:i:s');
    $used = [];
    $out = [];
    foreach ($periods as $p) {
        $k = (int) $p['section_id'] . ':' . (int) $p['subject_id'] . ':' . (int) $p['time_slot_id'];
        $k0 = (int) $p['section_id'] . ':' . (int) $p['subject_id'] . ':0';
        $sheet = $bySheetKey[$k] ?? ($bySheetKey[$k0] ?? null);
        if ($sheet) {
            $used[(int) $sheet['id']] = true;
        }
        $timeState = $date < $today ? 'done' : ($date > $today ? 'upcoming' : ($now >= $p['end_time'] ? 'done' : ($now >= $p['start_time'] ? 'now' : 'upcoming')));
        $out[] = [
            'timetable_id' => (int) $p['timetable_id'], 'section_id' => (int) $p['section_id'], 'subject_id' => (int) $p['subject_id'],
            'time_slot_id' => $sheet && !$sheet['time_slot_id'] ? null : (int) $p['time_slot_id'], 'slot' => $p['slot'], 'start_time' => $p['start_time'], 'end_time' => $p['end_time'],
            'type' => $p['type'], 'subject_code' => $p['subject_code'], 'subject' => $p['subject'], 'program' => $p['program'], 'semester_no' => (int) $p['semester_no'],
            'section' => $p['section'], 'room_code' => $p['room_code'], 'room' => $p['room'], 'students' => (int) $p['students'],
            'time_state' => $timeState, 'sheet' => $sheet ? $sheetOut($sheet) : null,
        ];
    }
    // registers on that date for the faculty's classes outside the timetable (extra / substitute classes)
    $extra = [];
    foreach ($sheets as $s) {
        if (isset($used[(int) $s['id']])) {
            continue;
        }
        $extra[] = [
            'timetable_id' => null, 'section_id' => (int) $s['section_id'], 'subject_id' => (int) $s['subject_id'], 'time_slot_id' => $s['time_slot_id'] ? (int) $s['time_slot_id'] : null,
            'slot' => $s['slot'] ?: 'Extra class', 'start_time' => $s['start_time'], 'end_time' => $s['end_time'], 'type' => 'extra', 'subject_code' => $s['subject_code'],
            'subject' => $s['subject'], 'program' => $s['program'], 'semester_no' => (int) $s['semester_no'], 'section' => $s['section'], 'room_code' => null, 'room' => null,
            'students' => (int) $s['total_count'], 'time_state' => 'done', 'sheet' => $sheetOut($s),
        ];
    }
    $classes = array_map(fn ($c) => ['section_id' => $c['section_id'], 'subject_id' => $c['subject_id'], 'label' => $c['label'], 'subject_code' => $c['subject_code'],
        'subject' => $c['subject'], 'students' => $c['students']], pfac_classes($fid)['classes']);
    $inWindow = $date >= $earliest && $date <= $today;
    return [
        'date' => $date, 'day' => $day + ['working' => !$day['blocked']],
        'window' => ['earliest' => $earliest, 'latest' => $today, 'days' => pfac_edit_days(), 'open' => $inWindow,
            'message' => $inWindow ? null : ($date > $today ? 'Attendance cannot be marked for a future date.' : 'Registers older than ' . pfac_edit_days() . ' days can only be changed by the attendance office.')],
        'periods' => $out, 'extra' => $extra, 'classes' => $classes,
        'slots' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'start_time' => $s['start_time'], 'end_time' => $s['end_time'], 'label' => $s['label']], att_time_slots()),
        'statuses' => att_student_statuses(),
    ];
}

/** Why a register cannot be saved from the portal: null = it can, else [message, HTTP status]. */
function pfac_sheet_block(string $date, ?array $sheet, array $day): ?array
{
    if ($day['blocked']) {
        return [$day['blocked'], 422];
    }
    if ($date > date('Y-m-d')) {
        return ['Attendance cannot be marked for a future date.', 422];
    }
    if ($date < pfac_earliest_date()) {
        return [pfac_edit_days() === 0
            ? 'Registers can only be marked on the day of the class. Contact the attendance office to change older registers.'
            : 'Registers older than ' . pfac_edit_days() . ' days can only be changed by the attendance office.', 422];
    }
    if ($sheet && !empty($sheet['is_locked'])) {
        return ['This register has been locked by the attendance office.', 423];
    }
    if ($sheet ? !can('attendance', 'edit') : !can('attendance', 'create')) {
        return [$sheet ? 'Your account role does not allow changing attendance registers. Contact the administrator.' : 'Your account role does not allow marking attendance. Contact the administrator.', 403];
    }
    return null;
}

/** Roster + current marks of one class period (only the faculty's own classes). */
function pfac_attendance_sheet(int $fid, string $date, int $sectionId, int $subjectId, ?int $slotId): array
{
    if (!pfac_valid_date($date)) {
        throw new CrudValidationException(['date' => 'Choose a valid date.']);
    }
    pfac_class_context($fid, $sectionId, $subjectId);
    $data = att_student_sheet($sectionId, $subjectId, $slotId ?: null, $date);
    foreach ($data['students'] as &$s) {
        $s['photo'] = $s['photo'] ? upload_url($s['photo']) : null;
    }
    unset($s);
    $block = pfac_sheet_block($date, $data['sheet'], $data['day']);
    $period = $slotId ? db_row("SELECT t.id, cr.code AS room_code, t.type FROM timetables t LEFT JOIN classrooms cr ON cr.id = t.classroom_id
                                WHERE t.section_id = ? AND t.subject_id = ? AND t.time_slot_id = ? AND t.day_of_week = ? AND t.academic_session_id = ? LIMIT 1",
        [$sectionId, $subjectId, $slotId, (int) date('N', strtotime($date)), pfac_session_id()]) : null;
    unset($data['default_faculty_id'], $data['default_faculty_name'], $data['restricted'], $data['can_save']);
    $sec = $data['section'];
    $data['section'] = ['id' => (int) $sec['id'], 'name' => $sec['name'], 'semester_no' => (int) $sec['semester_no'], 'program' => $sec['program_short'], 'label' => $sec['label'],
        'class_teacher' => $sec['class_teacher_name'] ?: null, 'students' => (int) $sec['students_count']];
    if ($data['sheet']) {
        unset($data['sheet']['taken_by']);
    }
    return $data + [
        'can_save' => $block === null, 'read_only_reason' => $block[0] ?? null, 'period' => $period ?: null,
        'window' => ['earliest' => pfac_earliest_date(), 'days' => pfac_edit_days()],
        'statuses' => att_student_statuses(),
    ];
}

/** Create / update a register for one of the faculty's classes. */
function pfac_save_attendance(int $fid, array $in): array
{
    $date = (string) ($in['date'] ?? '');
    $sectionId = (int) ($in['section_id'] ?? 0);
    $subjectId = (int) ($in['subject_id'] ?? 0);
    $slotId = !empty($in['time_slot_id']) ? (int) $in['time_slot_id'] : null;
    $errors = [];
    if (!pfac_valid_date($date)) {
        $errors['date'] = 'Choose a valid date.';
    }
    if (!$sectionId) {
        $errors['section_id'] = 'Choose the class.';
    }
    if (!$subjectId) {
        $errors['subject_id'] = 'Choose the subject.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    pfac_class_context($fid, $sectionId, $subjectId);
    $existing = db_row('SELECT id, is_locked FROM attendance WHERE sheet_key = ?', [att_sheet_key('student', $date, $sectionId, $subjectId, $slotId)]);
    if ($block = pfac_sheet_block($date, $existing, att_day_info($date))) {
        if ($block[1] === 422) {
            throw new CrudValidationException(['date' => $block[0]], $block[0]);
        }
        throw new PortalFacultyException($block[0], $block[1]);
    }
    $records = [];
    foreach ((array) ($in['records'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $records[] = ['student_id' => (int) ($r['student_id'] ?? 0), 'status' => (string) ($r['status'] ?? ''), 'remarks' => isset($r['remarks']) ? (string) $r['remarks'] : null];
    }
    $remarks = mb_substr(trim((string) ($in['remarks'] ?? '')), 0, 255);
    try {
        $res = att_save_student_sheet(['date' => $date, 'section_id' => $sectionId, 'subject_id' => $subjectId, 'time_slot_id' => $slotId, 'faculty_id' => $fid,
            'remarks' => $remarks, 'records' => $records]);
    } catch (AttendanceAccessException $e) {
        throw new PortalFacultyException($e->getMessage(), 403);
    }
    return $res;
}

/** Registers of the faculty's classes (newest first). $q: section_id, subject_id, from, to, page, per_page */
function pfac_attendance_history(int $fid, array $q): array
{
    [$pairSql, $args] = pfac_pairs_sql($fid, 'a');
    $where = "a.type = 'student' AND $pairSql";
    $sec = (int) ($q['section_id'] ?? 0);
    $sub = (int) ($q['subject_id'] ?? 0);
    if ($sec || $sub) {
        if (!pfac_teaches($fid, $sec, $sub)) {
            throw new PortalFacultyException('This class is not assigned to you.', 403);
        }
        $where .= ' AND a.section_id = ? AND a.subject_id = ?';
        array_push($args, $sec, $sub);
    }
    if (!empty($q['from']) && pfac_valid_date($q['from'])) {
        $where .= ' AND a.attendance_date >= ?';
        $args[] = $q['from'];
    }
    if (!empty($q['to']) && pfac_valid_date($q['to'])) {
        $where .= ' AND a.attendance_date <= ?';
        $args[] = $q['to'];
    }
    $perPage = max(5, min(50, (int) ($q['per_page'] ?? 15)));
    $page = max(1, (int) ($q['page'] ?? 1));
    $total = (int) db_value("SELECT COUNT(*) FROM attendance a WHERE $where", $args);
    $rows = db_all("SELECT a.id, a.attendance_date, a.section_id, a.subject_id, a.time_slot_id, a.is_locked, a.remarks, a.updated_at, a.created_at, a.total_count, a.present_count,
                           a.absent_count, a.late_count, a.leave_count, a.half_day_count, ts.name AS slot, ts.start_time, sb.code AS subject_code, sb.name AS subject,
                           p.short_name AS program, sc.name AS section, sc.semester_no, u.name AS taken_by, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty
                    FROM attendance a JOIN subjects sb ON sb.id = a.subject_id JOIN sections sc ON sc.id = a.section_id JOIN programs p ON p.id = sc.program_id
                    LEFT JOIN time_slots ts ON ts.id = a.time_slot_id LEFT JOIN users u ON u.id = a.taken_by LEFT JOIN faculty f ON f.id = a.faculty_id
                    WHERE $where ORDER BY a.attendance_date DESC, ts.start_time DESC, a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $args);
    $earliest = pfac_earliest_date();
    $items = array_map(fn ($r) => [
        'id' => (int) $r['id'], 'date' => $r['attendance_date'], 'section_id' => (int) $r['section_id'], 'subject_id' => (int) $r['subject_id'],
        'time_slot_id' => $r['time_slot_id'] ? (int) $r['time_slot_id'] : null, 'slot' => $r['slot'], 'start_time' => $r['start_time'], 'subject_code' => $r['subject_code'],
        'subject' => $r['subject'], 'class' => $r['program'] . ' · Sem ' . $r['semester_no'] . ' · Sec ' . $r['section'], 'counts' => att_counts_from_row($r),
        'is_locked' => (bool) $r['is_locked'], 'remarks' => $r['remarks'], 'updated_at' => $r['updated_at'] ?: $r['created_at'], 'taken_by' => $r['taken_by'], 'faculty' => $r['faculty'] ?: null,
        'editable' => !$r['is_locked'] && $r['attendance_date'] >= $earliest,
    ], $rows);
    $stats = db_row("SELECT COUNT(*) AS registers, SUM(a.total_count) AS marked, SUM(" . att_sheet_attended_sql('a') . ") AS attended FROM attendance a WHERE $where", $args) ?? [];
    return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage)),
        'stats' => ['registers' => (int) ($stats['registers'] ?? 0), 'percent' => (int) ($stats['marked'] ?? 0) ? round(100 * (float) $stats['attended'] / (int) $stats['marked'], 1) : null],
        'edit_days' => pfac_edit_days(), 'earliest' => $earliest];
}

/* ------------------------------------------------------------------
 * Marks entry
 * ------------------------------------------------------------------ */

/**
 * Sections of an exam subject the faculty may enter marks for: null = not their subject, [] = every section
 * (assignment without a section), otherwise the section ids.
 */
function pfac_marks_sections(int $fid, int $examSessionId, int $subjectId): ?array
{
    $rows = db_all('SELECT section_id FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ? AND (academic_session_id IS NULL OR academic_session_id = ?)', [$fid, $subjectId, $examSessionId]);
    if (!$rows) {
        return null;
    }
    $ids = [];
    foreach ($rows as $r) {
        if ($r['section_id'] === null) {
            return [];
        }
        $ids[] = (int) $r['section_id'];
    }
    return array_values(array_unique($ids));
}

/** SQL fragment + args restricting students alias $s to the allowed sections. */
function pfac_section_filter(array $sections, string $s = 's'): string
{
    return $sections ? " AND $s.section_id IN (" . pfac_in($sections) . ')' : '';
}

/** Exams (current session, or still in marks entry) that include subjects the faculty teaches, with progress for their sections. */
function pfac_marks_exams(int $fid): array
{
    $sid = pfac_session_id();
    $rows = db_all('SELECT DISTINCT e.id AS exam_id, e.name AS exam, e.status, ' . exam_display_status_sql('e') . " AS display_status, e.start_date, e.end_date, e.semester_no,
                           e.academic_session_id, et.name AS type_name, et.is_final, CONCAT(COALESCE(p.short_name, 'All programs'), IFNULL(CONCAT(' · Sem ', e.semester_no), '')) AS cohort,
                           es.subject_id, sb.code AS subject_code, sb.name AS subject, sb.type AS subject_type, es.max_internal, es.max_external, es.max_practical, es.pass_marks,
                           sch.exam_date, sch.start_time
                    FROM exams e JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = e.program_id
                    JOIN exam_subjects es ON es.exam_id = e.id JOIN subjects sb ON sb.id = es.subject_id
                    JOIN faculty_subjects fs ON fs.subject_id = es.subject_id AND fs.faculty_id = ? AND (fs.academic_session_id IS NULL OR fs.academic_session_id = e.academic_session_id)
                    LEFT JOIN exam_schedules sch ON sch.exam_id = e.id AND sch.subject_id = es.subject_id
                    WHERE e.status NOT IN ('draft', 'cancelled') AND (e.academic_session_id = ? OR e.status = 'marks_entry')
                    ORDER BY e.start_date DESC, e.id DESC, sch.exam_date, sb.code", [$fid, $sid]);
    $today = date('Y-m-d');
    $exams = [];
    $kpi = ['open' => 0, 'pending_students' => 0, 'submitted' => 0, 'locked' => 0, 'upcoming' => 0];
    foreach ($rows as $r) {
        $eid = (int) $r['exam_id'];
        $sections = pfac_marks_sections($fid, (int) $r['academic_session_id'], (int) $r['subject_id']);
        if ($sections === null) {
            continue;
        }
        $filter = pfac_section_filter($sections);
        $secRows = db_all("SELECT sc.id, sc.name, COUNT(*) AS students FROM exam_students x JOIN students s ON s.id = x.student_id JOIN sections sc ON sc.id = s.section_id
                           WHERE x.exam_id = ? AND x.is_eligible = 1$filter GROUP BY sc.id, sc.name ORDER BY sc.name", [$eid]);
        $students = array_sum(array_map(fn ($x) => (int) $x['students'], $secRows));
        $m = db_row("SELECT COUNT(*) AS entered, COALESCE(SUM(m.status IN ('submitted', 'verified', 'published')), 0) AS submitted, COALESCE(SUM(m.is_absent), 0) AS absent
                     FROM exam_marks m JOIN exam_students x ON x.exam_id = m.exam_id AND x.student_id = m.student_id AND x.is_eligible = 1 JOIN students s ON s.id = m.student_id
                     WHERE m.exam_id = ? AND m.subject_id = ?$filter", [$eid, (int) $r['subject_id']]) ?? [];
        $entered = (int) ($m['entered'] ?? 0);
        $submitted = (int) ($m['submitted'] ?? 0);
        $locked = exam_is_locked(['status' => $r['status']]);
        $future = $r['exam_date'] && $r['exam_date'] > $today;
        $state = $locked ? $r['status'] : ($future ? 'upcoming' : ($students && $submitted >= $students ? 'submitted' : ($students && $entered >= $students ? 'complete' : ($entered ? 'in_progress' : 'not_started'))));
        $open = !$locked && !$future && $students > 0;
        if ($open && $state !== 'submitted') {
            $kpi['open']++;
            $kpi['pending_students'] += max(0, $students - $entered);
        }
        $kpi['submitted'] += $state === 'submitted' ? 1 : 0;
        $kpi['locked'] += $locked ? 1 : 0;
        $kpi['upcoming'] += $future && !$locked ? 1 : 0;
        if (!isset($exams[$eid])) {
            $exams[$eid] = ['id' => $eid, 'name' => $r['exam'], 'status' => $r['status'], 'display_status' => $r['display_status'], 'type' => $r['type_name'], 'cohort' => $r['cohort'],
                'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'is_final' => (bool) $r['is_final'], 'locked' => $locked, 'papers' => []];
        }
        $exams[$eid]['papers'][] = [
            'subject_id' => (int) $r['subject_id'], 'subject_code' => $r['subject_code'], 'subject' => $r['subject'], 'subject_type' => $r['subject_type'],
            'max_internal' => (int) $r['max_internal'], 'max_external' => (int) $r['max_external'], 'max_practical' => (int) $r['max_practical'],
            'max_total' => (int) $r['max_internal'] + (int) $r['max_external'] + (int) $r['max_practical'], 'pass_marks' => (int) $r['pass_marks'],
            'exam_date' => $r['exam_date'], 'start_time' => $r['start_time'],
            'sections' => array_map(fn ($x) => ['id' => (int) $x['id'], 'name' => $x['name'], 'students' => (int) $x['students']], $secRows),
            'students' => $students, 'entered' => $entered, 'submitted' => $submitted, 'absent' => (int) ($m['absent'] ?? 0),
            'state' => $state, 'open' => $open, 'locked' => $locked,
        ];
    }
    return ['exams' => array_values($exams), 'kpis' => $kpi, 'can_enter' => can('results', 'create') || can('results', 'edit')];
}

/** Lock message for faculty (exam_lock_reason() talks to exam administrators). */
function pfac_lock_reason(array $e): ?string
{
    return match ($e['status']) {
        'published' => 'Results of this exam are published - marks are read-only.',
        'verified' => 'Marks were verified and locked' . (!empty($e['verified_by_name']) ? ' by ' . $e['verified_by_name'] : '') . '. Contact the examination office for any correction.',
        'cancelled' => 'This exam is cancelled.',
        default => null,
    };
}

/** Exam + exam-subject rows for a paper the faculty may enter marks for (403 / 404 otherwise). */
function pfac_marks_context(int $fid, int $examId, int $subjectId): array
{
    $e = exam_find($examId);
    if (!$e || in_array($e['status'], ['draft'], true)) {
        throw new PortalFacultyException('Exam not found.', 404);
    }
    $es = exam_subject_row($examId, $subjectId);
    if (!$es) {
        throw new PortalFacultyException('This subject is not part of the exam.', 404);
    }
    $sections = pfac_marks_sections($fid, (int) $e['academic_session_id'], $subjectId);
    if ($sections === null) {
        throw new PortalFacultyException('You can only enter marks for the subjects you teach.', 403);
    }
    return [$e, $es, $sections];
}

/** Marks grid of one paper, limited to the faculty's sections (optionally one of them). */
function pfac_marks_sheet(int $fid, int $examId, int $subjectId, ?int $sectionId = null): array
{
    [$e, $es, $allowed] = pfac_marks_context($fid, $examId, $subjectId);
    $sections = db_all('SELECT sc.id, sc.name, COUNT(*) AS students FROM exam_students x JOIN students s ON s.id = x.student_id JOIN sections sc ON sc.id = s.section_id
                        WHERE x.exam_id = ? AND x.is_eligible = 1' . pfac_section_filter($allowed) . ' GROUP BY sc.id, sc.name ORDER BY sc.name', [$examId]);
    $sectionIds = array_map(fn ($s) => (int) $s['id'], $sections);
    if ($sectionId && !in_array($sectionId, $sectionIds, true)) {
        throw new PortalFacultyException('You can only enter marks for the sections you teach.', 403);
    }
    $scope = $sectionId ? [$sectionId] : ($allowed ?: []);
    $scheduleId = (int) db_value('SELECT id FROM exam_schedules WHERE exam_id = ? AND subject_id = ?', [$examId, $subjectId]);
    $rows = db_all("SELECT s.id AS student_id, s.first_name, s.middle_name, s.last_name, s.student_uid, s.roll_no, s.photo, sc.name AS section_name,
                           m.id AS mark_id, m.internal_marks, m.external_marks, m.practical_marks, m.total_marks, m.grade, m.grade_point, m.is_absent, m.is_pass, m.status, m.remarks, m.updated_at,
                           att.status AS attendance_status
                    FROM exam_students x JOIN students s ON s.id = x.student_id LEFT JOIN sections sc ON sc.id = s.section_id
                    LEFT JOIN exam_marks m ON m.exam_id = x.exam_id AND m.student_id = x.student_id AND m.subject_id = ?
                    LEFT JOIN exam_attendance att ON att.exam_schedule_id = ? AND att.student_id = x.student_id
                    WHERE x.exam_id = ? AND x.is_eligible = 1" . pfac_section_filter($scope) . ' ORDER BY sc.name, s.roll_no IS NULL, s.roll_no, s.student_uid', [$subjectId, $scheduleId, $examId]);
    $stats = ['students' => count($rows), 'entered' => 0, 'absent' => 0, 'passed' => 0, 'submitted' => 0, 'average' => null, 'highest' => null, 'pass_percent' => null];
    $sum = 0.0;
    foreach ($rows as &$r) {
        $r['student_id'] = (int) $r['student_id'];
        $r['name'] = pfac_name($r);
        unset($r['first_name'], $r['middle_name'], $r['last_name']);
        $r['photo'] = $r['photo'] ? upload_url($r['photo']) : null;
        $r['entered'] = $r['mark_id'] !== null;
        unset($r['mark_id']);
        foreach (['internal_marks', 'external_marks', 'practical_marks', 'total_marks', 'grade_point'] as $k) {
            $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        }
        $r['is_absent'] = (bool) $r['is_absent'];
        $r['is_pass'] = $r['is_pass'] === null ? null : (bool) $r['is_pass'];
        if ($r['entered']) {
            $stats['entered']++;
            $stats['absent'] += $r['is_absent'] ? 1 : 0;
            $stats['passed'] += $r['is_pass'] ? 1 : 0;
            $stats['submitted'] += in_array($r['status'], ['submitted', 'verified', 'published'], true) ? 1 : 0;
            if (!$r['is_absent']) {
                $sum += (float) $r['total_marks'];
                $stats['highest'] = max((float) ($stats['highest'] ?? 0), (float) $r['total_marks']);
            }
        }
    }
    unset($r);
    $present = $stats['entered'] - $stats['absent'];
    $stats['average'] = $present > 0 ? round($sum / $present, 2) : null;
    $stats['pass_percent'] = $present > 0 ? round(100 * $stats['passed'] / $present, 1) : null;
    $schedule = db_row('SELECT s.exam_date, s.start_time, s.end_time, r.code AS room_code FROM exam_schedules s LEFT JOIN classrooms r ON r.id = s.classroom_id WHERE s.exam_id = ? AND s.subject_id = ?', [$examId, $subjectId]);
    $lock = pfac_lock_reason($e);
    $lockKind = $lock ? $e['status'] : null;
    if (!$lock && $schedule && $schedule['exam_date'] > exam_today()) {
        $lock = 'The paper is scheduled on ' . format_date($schedule['exam_date']) . '. Marks can be entered after the examination.';
        $lockKind = 'upcoming';
    }
    $rbac = can('results', 'create') || can('results', 'edit');
    if (!$lock && !$rbac) {
        $lock = 'Your account role does not allow marks entry. Contact the examination office.';
        $lockKind = 'role';
    }
    return [
        'exam' => ['id' => (int) $e['id'], 'name' => $e['name'], 'status' => $e['status'], 'display_status' => $e['display_status'], 'cohort' => $e['cohort'],
            'session_name' => $e['session_name'], 'type' => $e['type_name'], 'start_date' => $e['start_date'], 'end_date' => $e['end_date']],
        'subject' => ['id' => (int) $es['subject_id'], 'code' => $es['code'], 'name' => $es['name'], 'type' => $es['type'], 'credits' => (float) $es['credits'],
            'max_internal' => (int) $es['max_internal'], 'max_external' => (int) $es['max_external'], 'max_practical' => (int) $es['max_practical'],
            'max_total' => (int) $es['max_total'], 'pass_marks' => (int) $es['pass_marks'], 'schedule' => $schedule ?: null],
        'section_id' => $sectionId,
        'sections' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'students' => (int) $s['students']], $sections),
        'rows' => $rows,
        'stats' => $stats,
        'grades' => array_map(fn ($g) => ['grade' => $g['grade'], 'min' => $g['min'], 'max' => $g['max'], 'grade_point' => $g['grade_point'], 'is_pass' => (bool) $g['is_pass']], grade_scale()),
        'can_edit' => $lock === null,
        'lock_reason' => $lock,
        'lock_kind' => $lockKind,
    ];
}

/**
 * Save marks for the faculty's students of one paper; optionally submit them for verification.
 * $in: rows [{student_id, internal_marks, external_marks, practical_marks, is_absent, remarks}], submit (bool), section_id (view)
 */
function pfac_save_marks(int $fid, int $examId, int $subjectId, array $in): array
{
    [$e, $es, $allowed] = pfac_marks_context($fid, $examId, $subjectId);
    if ($lock = pfac_lock_reason($e)) {
        throw new PortalFacultyException($lock, 423);
    }
    if (!can('results', 'create') && !can('results', 'edit')) {
        throw new PortalFacultyException('Your account role does not allow marks entry. Contact the examination office.', 403);
    }
    $mine = array_map('intval', db_column('SELECT x.student_id FROM exam_students x JOIN students s ON s.id = x.student_id WHERE x.exam_id = ? AND x.is_eligible = 1' . pfac_section_filter($allowed), [$examId]));
    $mineSet = array_flip($mine);
    $rows = [];
    foreach ((array) ($in['rows'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $sid = (int) ($r['student_id'] ?? 0);
        if (!isset($mineSet[$sid])) {
            throw new PortalFacultyException('You can only enter marks for students of the sections you teach.', 403);
        }
        $rows[] = $r;
    }
    $submit = !empty($in['submit']) && $in['submit'] !== '0' && $in['submit'] !== 'false';
    if (!$rows && !$submit) {
        throw new CrudValidationException(['rows' => 'There are no changes to save.'], 'There are no changes to save.');
    }
    $res = $rows ? exam_save_marks($e, $subjectId, $rows, false) : ['saved' => 0, 'deleted' => 0];
    $submitted = 0;
    $submitError = null;
    if ($submit) {
        $entered = (int) db_value('SELECT COUNT(*) FROM exam_marks WHERE exam_id = ? AND subject_id = ? AND student_id IN (' . pfac_in($mine) . ')', [$examId, $subjectId]);
        if (!$mine) {
            $submitError = 'There are no students to submit.';
        } elseif ($entered < count($mine)) {
            $submitError = sprintf('Marks are missing for %d of %d students. Enter all marks (or mark students absent) before submitting.', count($mine) - $entered, count($mine));
        } else {
            $submitted = db_exec("UPDATE exam_marks SET status = 'submitted' WHERE exam_id = ? AND subject_id = ? AND status = 'draft' AND student_id IN (" . pfac_in($mine) . ')', [$examId, $subjectId]);
        }
    }
    $label = $es['code'] . ' ' . $es['name'];
    if ($res['saved'] || $res['deleted']) {
        log_activity('update', 'results', $examId, sprintf('Entered marks of %s for %d student%s in "%s" (faculty portal)%s', $label, $res['saved'], $res['saved'] === 1 ? '' : 's', $e['name'],
            $res['deleted'] ? " ({$res['deleted']} cleared)" : ''));
    }
    if ($submit && !$submitError) {
        log_activity('submit', 'results', $examId, sprintf('Submitted marks of %s in "%s" for %d student%s for verification (faculty portal)', $label, $e['name'], count($mine), count($mine) === 1 ? '' : 's'));
        notify(exam_users_with('results', 'approve'), 'marks_submitted', 'Marks submitted: ' . $label, $e['name'] . ' — ' . count($mine) . ' students. Ready for verification.',
            '/examination/' . $examId . '?tab=marks', 'clipboard-check');
    }
    return $res + ['submitted' => $submit && !$submitError, 'submitted_rows' => $submitted, 'submit_error' => $submitError];
}

/* ------------------------------------------------------------------
 * Leave + own attendance
 * ------------------------------------------------------------------ */

/** Leave types a faculty member may apply for (active, for faculty, matching gender). */
function pfac_leave_types(int $fid): array
{
    $gender = (string) db_value('SELECT gender FROM faculty WHERE id = ?', [$fid]);
    $out = [];
    foreach (hr_leave_types(true, 'faculty') as $t) {
        if (!empty($t['gender']) && $gender !== '' && $t['gender'] !== $gender) {
            continue;
        }
        $out[] = ['code' => $t['code'], 'name' => $t['name'], 'quota' => $t['annual_quota'], 'is_paid' => $t['is_paid'], 'max_consecutive' => $t['max_consecutive'],
            'color' => $t['color'], 'description' => $t['description']];
    }
    return $out;
}

function pfac_leave_row_out(array $r): array
{
    $today = date('Y-m-d');
    return [
        'id' => (int) $r['id'], 'leave_type' => $r['leave_type'], 'leave_type_name' => $r['leave_type_name'] ?: label_from_key((string) $r['leave_type']), 'color' => $r['color'],
        'from_date' => $r['from_date'], 'to_date' => $r['to_date'], 'days' => (float) $r['days'], 'is_half_day' => (bool) $r['is_half_day'], 'reason' => $r['reason'],
        'status' => $r['status'], 'remarks' => $r['remarks'], 'approved_by' => $r['approved_by_name'], 'approved_at' => $r['approved_at'], 'applied_at' => $r['created_at'],
        'cancelled_at' => $r['cancelled_at'], 'is_paid' => $r['is_paid'] === null ? true : (bool) $r['is_paid'],
        'can_cancel' => $r['status'] === 'pending' || ($r['status'] === 'approved' && $r['from_date'] > $today),
        'phase' => $r['to_date'] < $today ? 'past' : ($r['from_date'] <= $today ? 'current' : 'upcoming'),
    ];
}

/** Balance, applications and policy for the leave screen. $q: status, page */
function pfac_leave_overview(int $fid, array $q = []): array
{
    $w = hr_session_window();
    // gender-specific types (maternity / paternity) are shown but not counted in the regular leave balance
    $special = array_flip(db_column('SELECT code FROM leave_types WHERE gender IS NOT NULL'));
    $balance = array_map(fn ($b) => $b + ['special' => isset($special[$b['code']])], hr_leave_balance('faculty', $fid));
    $where = "l.employee_type = 'faculty' AND l.employee_id = ?";
    $args = [$fid];
    $status = (string) ($q['status'] ?? '');
    if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
        $where .= ' AND l.status = ?';
        $args[] = $status;
    }
    $perPage = 10;
    $page = max(1, (int) ($q['page'] ?? 1));
    $total = (int) db_value("SELECT COUNT(*) FROM employee_leaves l WHERE $where", $args);
    $rows = db_all("SELECT l.*, lt.name AS leave_type_name, lt.color, lt.is_paid, u.name AS approved_by_name FROM employee_leaves l
                    LEFT JOIN leave_types lt ON lt.code = l.leave_type LEFT JOIN users u ON u.id = l.approved_by
                    WHERE $where ORDER BY l.from_date DESC, l.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $args);
    $counts = db_pairs("SELECT status, COUNT(*) FROM employee_leaves WHERE employee_type = 'faculty' AND employee_id = ? GROUP BY status", [$fid]);
    $today = date('Y-m-d');
    $current = db_row("SELECT l.*, lt.name AS leave_type_name, lt.color, lt.is_paid, NULL AS approved_by_name FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type
                       WHERE l.employee_type = 'faculty' AND l.employee_id = ? AND l.status = 'approved' AND l.from_date <= ? AND l.to_date >= ? LIMIT 1", [$fid, $today, $today]);
    $upcoming = db_all("SELECT l.*, lt.name AS leave_type_name, lt.color, lt.is_paid, NULL AS approved_by_name FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type
                        WHERE l.employee_type = 'faculty' AND l.employee_id = ? AND l.status IN ('approved', 'pending') AND l.from_date > ? ORDER BY l.from_date LIMIT 3", [$fid, $today]);
    $taken = (float) db_value("SELECT COALESCE(SUM(days), 0) FROM employee_leaves WHERE employee_type = 'faculty' AND employee_id = ? AND status = 'approved' AND from_date BETWEEN ? AND ?", [$fid, $w['start'], $w['end']]);
    $holidays = db_all("SELECT title, holiday_date, end_date, type FROM holidays WHERE type IN ('holiday', 'vacation') AND COALESCE(end_date, holiday_date) >= ? ORDER BY holiday_date LIMIT 5", [$today]);
    return [
        'session' => ['name' => $w['name'], 'start' => $w['start'], 'end' => $w['end']],
        'balance' => $balance,
        'types' => pfac_leave_types($fid),
        'items' => array_map('pfac_leave_row_out', $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage)),
        'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'approved' => (int) ($counts['approved'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0), 'cancelled' => (int) ($counts['cancelled'] ?? 0)],
        'taken' => $taken,
        'on_leave_today' => $current ? pfac_leave_row_out($current) : null,
        'upcoming' => array_map('pfac_leave_row_out', $upcoming),
        'holidays' => $holidays,
        'weekly_offs' => hr_weekly_offs(),
        'backdate_days' => 30,
    ];
}

/** Normalised leave input + business validation (hr_validate_leave). Returns [data, errors]. */
function pfac_leave_validate(int $fid, array $in): array
{
    $data = [
        'employee_type' => 'faculty', 'employee_id' => $fid,
        'leave_type' => trim((string) ($in['leave_type'] ?? '')),
        'from_date' => trim((string) ($in['from_date'] ?? '')),
        'to_date' => trim((string) ($in['to_date'] ?? '')),
        'is_half_day' => in_array($in['is_half_day'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true) ? 1 : 0,
        'reason' => trim((string) ($in['reason'] ?? '')),
    ];
    if ($data['to_date'] === '' && $data['from_date'] !== '') {
        $data['to_date'] = $data['from_date'];
    }
    $errors = [];
    if ($data['leave_type'] === '') {
        $errors['leave_type'] = 'Choose the leave type.';
    } elseif (!in_array($data['leave_type'], array_column(pfac_leave_types($fid), 'code'), true)) {
        $errors['leave_type'] = 'This leave type is not available to you.';
    }
    if (!pfac_valid_date($data['from_date'])) {
        $errors['from_date'] = 'Choose a valid start date.';
    }
    if (!pfac_valid_date($data['to_date'])) {
        $errors['to_date'] = 'Choose a valid end date.';
    }
    if (!isset($errors['from_date']) && $data['from_date'] < date('Y-m-d', strtotime('-30 days'))) {
        $errors['from_date'] = 'Leave older than 30 days must be recorded by the HR office.';
    }
    if (!isset($errors['from_date']) && !isset($errors['to_date']) && $data['to_date'] < $data['from_date']) {
        $errors['to_date'] = 'To date must be on or after the from date.';
    }
    if ($errors) {
        return [$data, $errors];
    }
    $errors = hr_validate_leave($data);
    if (isset($errors['employee_ref'])) {
        $errors['leave_type'] = $errors['employee_ref'];
        unset($errors['employee_ref']);
    }
    return [$data, $errors];
}

/** Working days + validation preview while the form is filled in. */
function pfac_leave_preview(int $fid, array $in): array
{
    [$data, $errors] = pfac_leave_validate($fid, $in);
    $wd = pfac_valid_date($data['from_date']) && pfac_valid_date($data['to_date']) && $data['to_date'] >= $data['from_date'] && (strtotime($data['to_date']) - strtotime($data['from_date'])) / 86400 <= 366
        ? hr_working_days($data['from_date'], $data['to_date'], (bool) $data['is_half_day']) : null;
    $bal = null;
    foreach (hr_leave_balance('faculty', $fid) as $b) {
        if ($b['code'] === $data['leave_type']) {
            $bal = $b;
        }
    }
    unset($errors['reason']);
    return ['days' => $wd, 'errors' => (object) $errors, 'balance' => $bal];
}

function pfac_leave_apply(int $fid, int $userId, array $in): array
{
    [$data, $errors] = pfac_leave_validate($fid, $in);
    if ($data['reason'] === '') {
        $errors['reason'] = 'Give a short reason for the leave.';
    } elseif (mb_strlen($data['reason']) > 500) {
        $errors['reason'] = 'Reason may not be longer than 500 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $wd = hr_working_days($data['from_date'], $data['to_date'], (bool) $data['is_half_day']);
    $id = db_insert('employee_leaves', [
        'employee_type' => 'faculty', 'employee_id' => $fid, 'leave_type' => $data['leave_type'], 'from_date' => $data['from_date'], 'to_date' => $data['to_date'],
        'days' => $wd['days'], 'is_half_day' => $data['is_half_day'] && $data['from_date'] === $data['to_date'] ? 1 : 0, 'reason' => $data['reason'],
        'status' => 'pending', 'applied_by' => $userId,
    ]);
    $leave = hr_leave_row($id);
    $label = sprintf('%s (%s to %s, %s)', $leave['leave_type_name'], format_date($leave['from_date']), format_date($leave['to_date']), hr_days_label($leave['days']));
    log_activity('create', 'leaves', $id, 'Applied for ' . $label . ' via the faculty portal');
    $approvers = array_values(array_diff(hr_leave_approver_ids(), [$userId]));
    if ($approvers) {
        notify($approvers, 'leave', 'Leave request: ' . $leave['employee_name'], sprintf('%s · %s to %s (%s)', $leave['leave_type_name'], format_date($leave['from_date']), format_date($leave['to_date']),
            hr_days_label($leave['days'])), 'leaves?tab=applications&f.status=pending', 'calendar-clock');
    }
    $row = db_row('SELECT l.*, lt.name AS leave_type_name, lt.color, lt.is_paid, NULL AS approved_by_name FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type WHERE l.id = ?', [$id]);
    return pfac_leave_row_out($row);
}

function pfac_leave_cancel(int $fid, int $userId, int $id): array
{
    $leave = db_row("SELECT * FROM employee_leaves WHERE id = ? AND employee_type = 'faculty' AND employee_id = ?", [$id, $fid]);
    if (!$leave) {
        throw new PortalFacultyException('Leave application not found.', 404);
    }
    if ($leave['status'] === 'approved' && $leave['from_date'] <= date('Y-m-d')) {
        throw new CrudException('This leave has already started. Contact the HR office to change it.');
    }
    if (!in_array($leave['status'], ['pending', 'approved'], true)) {
        throw new CrudException('This application is already ' . $leave['status'] . '.');
    }
    $row = hr_leave_decide($id, 'cancel', 'Cancelled by the applicant');
    if ($leave['status'] === 'approved') {
        $approvers = array_values(array_diff(hr_leave_approver_ids(), [$userId]));
        if ($approvers) {
            notify($approvers, 'leave', 'Approved leave cancelled: ' . $row['employee_name'], sprintf('%s · %s to %s (%s) was cancelled by the applicant.', $row['leave_type_name'],
                format_date($row['from_date']), format_date($row['to_date']), hr_days_label($row['days'])), 'leaves?tab=applications', 'calendar-x');
        }
    }
    return ['id' => $id, 'status' => 'cancelled'];
}

/** The faculty member's own daily attendance for a month (from the HR attendance registers). */
function pfac_my_attendance(int $fid, string $month): array
{
    if (!att_valid_month($month)) {
        $month = date('Y-m');
    }
    $data = hr_attendance('faculty', $fid, $month);
    $data['prev'] = date('Y-m', strtotime($month . '-01 -1 month'));
    $data['next'] = $month < date('Y-m') ? date('Y-m', strtotime($month . '-01 +1 month')) : null;
    return $data;
}

/* ------------------------------------------------------------------
 * Documents
 * ------------------------------------------------------------------ */

function pfac_document_types(): array
{
    return ['resume' => 'Resume / CV', 'id_proof' => 'ID Proof (Aadhaar / PAN)', 'degree' => 'Degree Certificate', 'experience' => 'Experience Letter',
        'appointment_letter' => 'Appointment Letter', 'joining_report' => 'Joining Report', 'relieving_letter' => 'Relieving Letter', 'photo' => 'Passport Photo',
        'address_proof' => 'Address Proof', 'research' => 'Research / Publication', 'other' => 'Other'];
}

function pfac_documents(int $fid, int $userId): array
{
    $types = pfac_document_types();
    $rows = db_all("SELECT d.id, d.doc_type, d.title, d.original_name, d.mime, d.size_bytes, d.status, d.remarks, d.expiry_date, d.created_at, d.verified_at, d.uploaded_by,
                           uu.name AS uploaded_by_name, vu.name AS verified_by_name
                    FROM employee_documents d LEFT JOIN users uu ON uu.id = d.uploaded_by LEFT JOIN users vu ON vu.id = d.verified_by
                    WHERE d.employee_type = 'faculty' AND d.employee_id = ? ORDER BY d.created_at DESC, d.id DESC", [$fid]);
    $today = date('Y-m-d');
    $items = array_map(fn ($r) => [
        'id' => (int) $r['id'], 'doc_type' => $r['doc_type'], 'doc_type_label' => $types[$r['doc_type']] ?? label_from_key((string) $r['doc_type']), 'title' => $r['title'],
        'original_name' => $r['original_name'], 'mime' => $r['mime'], 'size' => $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null,
        'size_label' => $r['size_bytes'] ? human_filesize((int) $r['size_bytes']) : null, 'status' => $r['status'], 'remarks' => $r['remarks'], 'expiry_date' => $r['expiry_date'],
        'expired' => $r['expiry_date'] && $r['expiry_date'] < $today, 'uploaded_at' => $r['created_at'], 'uploaded_by' => $r['uploaded_by_name'], 'verified_by' => $r['verified_by_name'],
        'verified_at' => $r['verified_at'], 'mine' => (int) $r['uploaded_by'] === $userId,
        'can_delete' => $r['status'] === 'pending' && (int) $r['uploaded_by'] === $userId,
    ], $rows);
    $counts = ['total' => count($items), 'verified' => 0, 'pending' => 0, 'rejected' => 0];
    foreach ($items as $i) {
        if (isset($counts[$i['status']])) {
            $counts[$i['status']]++;
        }
    }
    return ['items' => $items, 'counts' => $counts, 'types' => $types, 'max_bytes' => upload_max_bytes('any'),
        'accept' => implode(',', array_map(fn ($e) => '.' . $e, array_keys(upload_allowed('any'))))];
}

function pfac_document_find(int $fid, int $id): array
{
    $row = db_row("SELECT * FROM employee_documents WHERE id = ? AND employee_type = 'faculty' AND employee_id = ?", [$id, $fid]);
    if (!$row) {
        throw new PortalFacultyException('Document not found.', 404);
    }
    return $row;
}

function pfac_document_upload(int $fid, int $userId, array $in, ?array $file): array
{
    $types = pfac_document_types();
    $errors = [];
    $type = trim((string) ($in['doc_type'] ?? ''));
    $title = trim((string) ($in['title'] ?? ''));
    $expiry = trim((string) ($in['expiry_date'] ?? ''));
    if (!isset($types[$type])) {
        $errors['doc_type'] = 'Choose the document type.';
    }
    if ($title === '') {
        $errors['title'] = 'Give the document a title.';
    } elseif (mb_strlen($title) > 190) {
        $errors['title'] = 'Title may not be longer than 190 characters.';
    }
    if ($expiry !== '' && !pfac_valid_date($expiry)) {
        $errors['expiry_date'] = 'Choose a valid date.';
    }
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors['file'] = 'Choose the file to upload.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if ((int) db_value("SELECT COUNT(*) FROM employee_documents WHERE employee_type = 'faculty' AND employee_id = ? AND status = 'pending'", [$fid]) >= 20) {
        throw new CrudException('You already have 20 documents waiting for verification. Please wait for the HR office to review them.');
    }
    $up = upload_file($file, 'any', 'employee-docs', true);
    if (!$up['ok']) {
        throw new CrudValidationException(['file' => $up['error']], $up['error']);
    }
    $id = db_insert('employee_documents', [
        'employee_type' => 'faculty', 'employee_id' => $fid, 'doc_type' => $type, 'title' => $title, 'file_path' => $up['path'], 'original_name' => $up['original_name'],
        'mime' => $up['mime'], 'size_bytes' => $up['size'], 'uploaded_by' => $userId, 'status' => 'pending', 'expiry_date' => $expiry !== '' ? $expiry : null,
    ]);
    $name = (string) db_value("SELECT TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE id = ?", [$fid]);
    log_activity('create', 'employee_documents', $id, 'Uploaded "' . $title . '" (' . $types[$type] . ') for verification via the faculty portal');
    $hr = pfac_hr_user_ids($userId);
    if ($hr) {
        notify($hr, 'document', 'Document to verify: ' . $name, $title . ' (' . $types[$type] . ') was uploaded from the faculty portal.', 'faculty/' . $fid . '?tab=documents', 'file-check');
    }
    return ['id' => $id];
}

function pfac_document_delete(int $fid, int $userId, int $id): void
{
    $row = pfac_document_find($fid, $id);
    if ($row['status'] !== 'pending' || (int) $row['uploaded_by'] !== $userId) {
        throw new PortalFacultyException('Only documents you uploaded that are still awaiting verification can be removed.', 403);
    }
    db_delete('employee_documents', 'id = ?', [$id]);
    delete_upload($row['file_path']);
    log_activity('delete', 'employee_documents', $id, 'Removed "' . $row['title'] . '" (awaiting verification) via the faculty portal');
}

/* ------------------------------------------------------------------
 * Payroll reference
 * ------------------------------------------------------------------ */

function pfac_payroll(int $fid): array
{
    $f = db_row('SELECT f.employee_id, f.designation, f.employment_type, f.joining_date, f.salary, f.bank_name, f.bank_account, f.bank_ifsc, f.pan_no, d.name AS department
                 FROM faculty f LEFT JOIN departments d ON d.id = f.department_id WHERE f.id = ?', [$fid]) ?? [];
    $w = hr_session_window();
    $leave = db_row("SELECT COALESCE(SUM(CASE WHEN COALESCE(lt.is_paid, 1) = 1 THEN l.days END), 0) AS paid, COALESCE(SUM(CASE WHEN lt.is_paid = 0 THEN l.days END), 0) AS unpaid
                     FROM employee_leaves l LEFT JOIN leave_types lt ON lt.code = l.leave_type
                     WHERE l.employee_type = 'faculty' AND l.employee_id = ? AND l.status = 'approved' AND l.from_date BETWEEN ? AND ?", [$fid, $w['start'], $w['end']]) ?? [];
    $salary = $f['salary'] !== null && $f['salary'] !== '' ? (float) $f['salary'] : null;
    return [
        'employee_id' => $f['employee_id'] ?? null, 'designation' => $f['designation'] ?? null, 'department' => $f['department'] ?? null,
        'employment_type' => $f['employment_type'] ?? null, 'joining_date' => $f['joining_date'] ?? null,
        'tenure_years' => !empty($f['joining_date']) ? round((time() - strtotime($f['joining_date'])) / (365.25 * 86400), 1) : null,
        'salary' => $salary, 'annual' => $salary !== null ? round($salary * 12, 2) : null,
        'bank' => ['name' => $f['bank_name'] ?? null, 'account' => hr_mask($f['bank_account'] ?? null), 'ifsc' => $f['bank_ifsc'] ?? null],
        'pan' => hr_mask($f['pan_no'] ?? null, 4),
        'components' => null,
        'session' => ['name' => $w['name'], 'start' => $w['start'], 'end' => $w['end']],
        'leave' => ['paid' => (float) ($leave['paid'] ?? 0), 'unpaid' => (float) ($leave['unpaid'] ?? 0)],
        'lop' => hr_lop_by_month('faculty', $fid),
    ];
}

/* ------------------------------------------------------------------
 * Profile correction requests
 * ------------------------------------------------------------------ */

function pfac_correction_fields(): array
{
    return ['name' => 'Name (spelling)', 'dob' => 'Date of birth', 'phone' => 'Mobile number', 'alternate_phone' => 'Alternate phone', 'email' => 'Official email',
        'address' => 'Address', 'qualification' => 'Qualification', 'specialization' => 'Specialization', 'experience_years' => 'Experience', 'designation' => 'Designation',
        'department' => 'Department', 'joining_date' => 'Joining date', 'employment_type' => 'Employment type', 'bank' => 'Bank / PAN details', 'subjects' => 'Subject allocation',
        'other' => 'Something else'];
}

/**
 * Account / Aadhaar-like digit runs are masked before a request is stored or sent: every run of 8+ digits for bank details,
 * runs of 12+ digits elsewhere (phone numbers stay readable).
 */
function pfac_redact(string $text, bool $strict = false): string
{
    $re = $strict ? '/\d[\d\s-]{6,}\d/' : '/\d[\d\s-]{10,}\d/';
    return preg_replace_callback($re, function ($m) {
        $digits = preg_replace('/\D/', '', $m[0]);
        return strlen($digits) >= 8 ? '••••' . substr($digits, -4) : $m[0];
    }, $text) ?? $text;
}

function pfac_correction_request(int $fid, int $userId, array $in): array
{
    $fields = pfac_correction_fields();
    $field = trim((string) ($in['field'] ?? ''));
    $requested = trim((string) ($in['requested'] ?? ''));
    $note = trim((string) ($in['note'] ?? ''));
    $errors = [];
    if (!isset($fields[$field])) {
        $errors['field'] = 'Choose what needs to be corrected.';
    }
    if ($requested === '' && $field !== 'bank') {
        $errors['requested'] = 'Enter the correct value.';
    } elseif (mb_strlen($requested) > 300) {
        $errors['requested'] = 'Keep it under 300 characters.';
    }
    if (mb_strlen($note) > 500) {
        $errors['note'] = 'Keep the note under 500 characters.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    if (!rate_limit('portal-correction:' . $userId, 5, 3600)) {
        throw new PortalFacultyException('You have sent several requests in the last hour. Please wait before sending another one.', 429);
    }
    $requested = pfac_redact($requested, $field === 'bank');
    $note = pfac_redact($note, $field === 'bank');
    $name = (string) db_value("SELECT TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE id = ?", [$fid]);
    $desc = sprintf('Profile correction requested by %s — %s%s', $name, $fields[$field], $requested !== '' ? ': "' . $requested . '"' : '');
    log_activity('correction_request', 'faculty', $fid, mb_substr($desc . ($note !== '' ? ' (' . $note . ')' : ''), 0, 500), 'success',
        ['field' => $field, 'label' => $fields[$field], 'requested' => $requested, 'note' => $note, 'source' => 'faculty_portal']);
    $hr = pfac_hr_user_ids($userId);
    if ($hr) {
        notify($hr, 'profile', 'Profile correction: ' . $name, $fields[$field] . ($requested !== '' ? ' → ' . mb_substr($requested, 0, 120) : '') . ($note !== '' ? ' · ' . mb_substr($note, 0, 120) : ''),
            'faculty/' . $fid . '?tab=activity', 'user-pen');
    }
    return ['field' => $field, 'label' => $fields[$field]];
}

function pfac_corrections(int $fid): array
{
    $rows = db_all("SELECT id, meta, description, created_at FROM activity_logs WHERE module = 'faculty' AND action = 'correction_request' AND record_id = ? ORDER BY id DESC LIMIT 20", [(string) $fid]);
    return array_map(function ($r) {
        $m = json_decode((string) $r['meta'], true) ?: [];
        return ['id' => (int) $r['id'], 'field' => $m['field'] ?? null, 'label' => $m['label'] ?? null, 'requested' => $m['requested'] ?? null, 'note' => $m['note'] ?? null,
            'created_at' => $r['created_at']];
    }, $rows);
}
