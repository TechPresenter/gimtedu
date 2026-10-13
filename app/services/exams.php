<?php
/**
 * Examination, marks entry, results & marksheets - domain logic shared by api/routes/{exams,results,marksheets}.php,
 * the CRUD modules (exam_types, exams, exam_subjects, exam_schedules, grade_scales, results, marksheets),
 * the print views (admin/print/{marksheet,admit-card,exam-schedule}.php) and the demo seeder.
 *
 * Workflow (brief section 15):  Create Exam -> Assign Subjects -> Schedule -> Allocate Students -> Enter Marks -> Verify -> Publish Result
 *   exams.status   draft | scheduled | ongoing | marks_entry | verified | published | cancelled
 *                  ("ongoing" and "awaiting marks" are also derived from the dates for display - exam_display_status_sql()).
 *   exam_marks     draft (saved) -> submitted (subject complete, sent by faculty) -> verified (locked) -> published
 *
 * Result rules (brief section 16)
 *   subject  total = internal + external + practical; pass when not absent and total >= pass_marks; grade from grade_scales by
 *            percentage (a pass never gets a failing grade and vice versa); absent = grade "AB", 0 grade points.
 *   exam     SGPA = sum(credits x grade point) / sum(credits) (final exams only), CGPA = credit-weighted SGPAs of every published
 *            final result up to this exam (latest attempt per semester). Status: WITHHELD (manual, with reason) > ABSENT (absent
 *            in every paper) > PASS (no failed subject) > BACKLOG (final exams, up to setting exam_max_backlogs failed subjects)
 *            > FAIL. Rank = competition rank of PASS results by percentage.
 *
 * Functions never log or notify - the API routes do that, so the seeder can reuse everything silently.
 */

require_once APP_ROOT . '/app/crud.php';

/* ------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------ */

function exam_statuses(): array
{
    return ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'ongoing' => 'Ongoing', 'marks_entry' => 'Marks Entry', 'verified' => 'Verified',
        'published' => 'Published', 'cancelled' => 'Cancelled'];
}

function exam_result_statuses(): array
{
    return ['PASS' => 'Pass', 'BACKLOG' => 'Backlog', 'FAIL' => 'Fail', 'ABSENT' => 'Absent', 'WITHHELD' => 'Withheld'];
}

/**
 * Active users who may perform $action on $module (or manage it / super admins) - for targeted notifications, e.g.
 * "marks submitted" goes to the people who verify marks, not to every faculty member who can view results.
 */
function exam_users_with(string $module, string $action): array
{
    return array_map('intval', db_column("SELECT DISTINCT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                                          LEFT JOIN role_permissions rp ON rp.role_id = r.id LEFT JOIN permissions p ON p.id = rp.permission_id
                                          WHERE u.status = 'active' AND (r.is_super = 1 OR (p.module = ? AND p.action IN (?, 'manage')))", [$module, $action]));
}

/** Comma list of integers for IN (...) clauses (never empty). */
function exam_in(array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return $ids ? implode(',', $ids) : '0';
}

function exam_today(): string
{
    return date('Y-m-d');
}

/** SQL for the status shown to users: scheduled exams become "ongoing" during their dates and "marks_entry" afterwards. */
function exam_display_status_sql(string $a = 't'): string
{
    $today = exam_today();
    return "(CASE WHEN $a.status IN ('scheduled','ongoing') AND $a.start_date IS NOT NULL AND $a.start_date <= '$today' AND ($a.end_date IS NULL OR $a.end_date >= '$today') THEN 'ongoing'
                  WHEN $a.status IN ('scheduled','ongoing') AND $a.end_date IS NOT NULL AND $a.end_date < '$today' THEN 'marks_entry'
                  ELSE $a.status END)";
}

/** SQL conditions for the KPI phases (upcoming / ongoing / completed / results pending). */
function exam_phase_sql(string $a = 't'): array
{
    $today = exam_today();
    return [
        'upcoming' => "$a.status <> 'cancelled' AND ($a.start_date IS NULL OR $a.start_date > '$today')",
        'ongoing' => "$a.status NOT IN ('cancelled','published') AND $a.start_date <= '$today' AND ($a.end_date IS NULL OR $a.end_date >= '$today')",
        'completed' => "$a.status <> 'cancelled' AND $a.end_date < '$today'",
        'results_pending' => "$a.status NOT IN ('cancelled','published') AND $a.end_date < '$today'",
        'published' => "$a.status = 'published'",
    ];
}

function exam_find(int $id): ?array
{
    $row = db_row('SELECT e.*, ' . exam_display_status_sql('e') . " AS display_status, et.name AS type_name, et.code AS type_code, et.is_final,
                          p.short_name AS program_short, p.name AS program_name, p.code AS program_code, p.total_semesters, p.department_id,
                          s.name AS session_name, pu.name AS published_by_name, vu.name AS verified_by_name, cu.name AS created_by_name
                   FROM exams e JOIN exam_types et ON et.id = e.exam_type_id LEFT JOIN programs p ON p.id = e.program_id
                   LEFT JOIN academic_sessions s ON s.id = e.academic_session_id LEFT JOIN users pu ON pu.id = e.published_by
                   LEFT JOIN users vu ON vu.id = e.verified_by LEFT JOIN users cu ON cu.id = e.created_by WHERE e.id = ?", [$id]);
    if ($row) {
        $row['id'] = (int) $row['id'];
        $row['is_final'] = (int) $row['is_final'];
        $row['cohort'] = trim(($row['program_short'] ?? 'All programs') . ($row['semester_no'] ? ' · Sem ' . $row['semester_no'] : ''));
    }
    return $row;
}

/** Load an exam or throw a user-facing error. */
function exam_require(int $id): array
{
    $e = exam_find($id);
    if (!$e) {
        throw new CrudException('Exam not found. It may have been deleted.');
    }
    return $e;
}

function exam_is_locked(array $e): bool
{
    return in_array($e['status'], ['verified', 'published'], true);
}

function exam_assert_open(array $e, string $doing = 'make changes'): void
{
    if ($e['status'] === 'cancelled') {
        throw new CrudException('This exam is cancelled. Re-open it (change its status) to ' . $doing . '.');
    }
    if ($e['status'] === 'published') {
        throw new CrudException('Results of this exam are published. Unpublish the results to ' . $doing . '.');
    }
    if ($e['status'] === 'verified') {
        throw new CrudException('Marks of this exam are verified and locked. Unlock the marks to ' . $doing . '.');
    }
}

/** Number of eligible allocated students (falls back to the active cohort size before allocation). */
function exam_headcount(array $e): int
{
    $n = (int) db_value('SELECT COUNT(*) FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$e['id']]);
    if ($n || !$e['program_id']) {
        return $n;
    }
    return count(exam_candidate_ids($e));
}

/* ------------------------------------------------------------------
 * Grade scale
 * ------------------------------------------------------------------ */

/** Grade scale rows ordered from the highest band down. */
function grade_scale(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = array_map(fn ($r) => ['grade' => $r['grade'], 'min' => (float) $r['min_percent'], 'max' => (float) $r['max_percent'], 'grade_point' => (float) $r['grade_point'],
            'description' => $r['description'], 'is_pass' => (int) $r['is_pass']], db_all('SELECT * FROM grade_scales ORDER BY min_percent DESC, grade_point DESC'));
    }
    return $cache;
}

/** Grade for a percentage. Falls back to the closest lower band when the percentage sits in a gap of the scale. */
function grade_for_percent(float $pct, ?array $scale = null): array
{
    $scale = $scale ?? grade_scale();
    if (!$scale) {
        return ['grade' => $pct >= 40 ? 'P' : 'F', 'grade_point' => $pct >= 40 ? 4.0 : 0.0, 'is_pass' => $pct >= 40 ? 1 : 0, 'description' => null];
    }
    foreach ($scale as $g) {
        if ($pct >= $g['min'] - 0.0001 && $pct <= $g['max'] + 0.0099) {
            return $g;
        }
    }
    foreach ($scale as $g) {
        if ($pct >= $g['min']) {
            return $g;
        }
    }
    return end($scale);
}

function grade_lowest_pass(?array $scale = null): ?array
{
    $pass = array_values(array_filter($scale ?? grade_scale(), fn ($g) => $g['is_pass']));
    return $pass ? end($pass) : null;
}

function grade_fail(?array $scale = null): array
{
    $fail = array_values(array_filter($scale ?? grade_scale(), fn ($g) => !$g['is_pass']));
    return $fail ? end($fail) : ['grade' => 'F', 'grade_point' => 0.0, 'is_pass' => 0, 'description' => 'Fail', 'min' => 0, 'max' => 0];
}

/** Overlaps (errors) and gaps (warnings) in a grade scale. $rows: [{id, grade, min_percent, max_percent}] */
function grade_scale_issues(array $rows): array
{
    usort($rows, fn ($a, $b) => (float) $a['min_percent'] <=> (float) $b['min_percent']);
    $issues = ['overlaps' => [], 'gaps' => []];
    $first = $rows[0] ?? null;
    if ($first && (float) $first['min_percent'] > 0) {
        $issues['gaps'][] = ['from' => 0, 'to' => (float) $first['min_percent'], 'message' => sprintf('No grade covers 0%% to %s%%.', rtrim(rtrim(number_format((float) $first['min_percent'], 2), '0'), '.'))];
    }
    for ($i = 1, $n = count($rows); $i < $n; $i++) {
        $prev = $rows[$i - 1];
        $cur = $rows[$i];
        $pMax = (float) $prev['max_percent'];
        $cMin = (float) $cur['min_percent'];
        if ($cMin <= $pMax) {
            $issues['overlaps'][] = ['a' => $prev['grade'], 'b' => $cur['grade'], 'message' => "Grades {$prev['grade']} and {$cur['grade']} overlap between {$cMin}% and {$pMax}%."];
        } elseif ($cMin - $pMax > 0.011) {
            $issues['gaps'][] = ['from' => $pMax, 'to' => $cMin, 'message' => "No grade covers {$pMax}% to {$cMin}% (between {$prev['grade']} and {$cur['grade']})."];
        }
    }
    $last = $rows ? end($rows) : null;
    if ($last && (float) $last['max_percent'] < 99.99) {
        $issues['gaps'][] = ['from' => (float) $last['max_percent'], 'to' => 100, 'message' => "No grade covers {$last['max_percent']}% to 100%."];
    }
    return $issues;
}

/* ------------------------------------------------------------------
 * Subjects
 * ------------------------------------------------------------------ */

/** Default maxima for a subject inside an exam (lab subjects are assessed by a practical examination). */
function exam_subject_defaults(array $subject, array $e): array
{
    $int = (int) $subject['max_internal'];
    $ext = (int) $subject['max_external'];
    $prac = (int) $subject['max_practical'];
    $pass = (int) $subject['pass_marks'];
    if (in_array($subject['type'], ['lab', 'practical'], true) && $prac === 0) {
        [$prac, $ext] = [$ext, 0];
    }
    if (empty($e['is_final']) && ($e['type_code'] ?? '') === 'MID') {
        // Mid-term: 40-mark written paper + 10 marks continuous assessment
        return in_array($subject['type'], ['lab', 'practical'], true)
            ? ['max_internal' => 10, 'max_external' => 0, 'max_practical' => 40, 'pass_marks' => 20]
            : ['max_internal' => 10, 'max_external' => 40, 'max_practical' => 0, 'pass_marks' => 20];
    }
    if (($e['type_code'] ?? '') === 'PRAC') {
        return ['max_internal' => 20, 'max_external' => 0, 'max_practical' => 30, 'pass_marks' => 20];
    }
    return ['max_internal' => $int, 'max_external' => $ext, 'max_practical' => $prac, 'pass_marks' => $pass];
}

/** Curriculum subjects of the exam's program/semester (practical exams: lab/project subjects only). */
function exam_curriculum_subjects(array $e): array
{
    if (!$e['program_id'] || !$e['semester_no']) {
        return [];
    }
    $rows = db_all("SELECT * FROM subjects WHERE program_id = ? AND semester_no = ? AND status = 'active' ORDER BY FIELD(type, 'theory', 'elective', 'lab', 'practical', 'project'), code",
        [$e['program_id'], $e['semester_no']]);
    if (($e['type_code'] ?? '') === 'PRAC') {
        $rows = array_values(array_filter($rows, fn ($s) => in_array($s['type'], ['lab', 'practical', 'project'], true)));
    }
    return $rows;
}

/** Add every curriculum subject not yet in the exam. Returns the number added. */
function exam_sync_subjects(array $e): int
{
    exam_assert_open($e, 'change subjects');
    $existing = array_map('intval', db_column('SELECT subject_id FROM exam_subjects WHERE exam_id = ?', [$e['id']]));
    $added = 0;
    foreach (exam_curriculum_subjects($e) as $s) {
        if (in_array((int) $s['id'], $existing, true)) {
            continue;
        }
        db_insert('exam_subjects', ['exam_id' => $e['id'], 'subject_id' => (int) $s['id']] + exam_subject_defaults($s, $e));
        $added++;
    }
    return $added;
}

function exam_subjects(int $examId): array
{
    return db_all("SELECT es.*, sb.code, sb.name, sb.type, sb.credits, (es.max_internal + es.max_external + es.max_practical) AS max_total,
                          sch.id AS schedule_id, sch.exam_date, sch.start_time, sch.end_time
                   FROM exam_subjects es JOIN subjects sb ON sb.id = es.subject_id
                   LEFT JOIN exam_schedules sch ON sch.exam_id = es.exam_id AND sch.subject_id = es.subject_id
                   WHERE es.exam_id = ? ORDER BY COALESCE(sch.exam_date, '9999-12-31'), sch.start_time, sb.code", [$examId]);
}

/* ------------------------------------------------------------------
 * Schedule (date sheet) + conflicts
 * ------------------------------------------------------------------ */

function exam_time_label(?string $t): string
{
    return $t ? date('h:i A', strtotime($t)) : '';
}

/**
 * Conflicts for a paper. $row: exam_id, subject_id, exam_date, start_time, end_time, classroom_id, invigilator_id.
 * Returns field => message.
 */
function exam_schedule_conflicts(array $row, ?int $scheduleId = null, array $invigilatorIds = []): array
{
    $errors = [];
    $e = exam_find((int) $row['exam_id']);
    if (!$e || empty($row['exam_date']) || empty($row['start_time']) || empty($row['end_time'])) {
        return $errors;
    }
    $start = substr((string) $row['start_time'], 0, 5) . ':00';
    $end = substr((string) $row['end_time'], 0, 5) . ':00';
    $overlaps = db_all("SELECT s.*, sb.code AS subject_code, sb.name AS subject_name, ex.name AS exam_name, ex.program_id, ex.semester_no, ex.academic_session_id,
                               p.short_name AS program_short, r.code AS room_code
                        FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id JOIN subjects sb ON sb.id = s.subject_id
                        LEFT JOIN programs p ON p.id = ex.program_id LEFT JOIN classrooms r ON r.id = s.classroom_id
                        WHERE s.exam_date = ? AND s.start_time < ? AND s.end_time > ? AND s.id <> ? AND ex.status <> 'cancelled'",
        [$row['exam_date'], $end, $start, (int) $scheduleId]);
    $when = fn ($o) => exam_time_label($o['start_time']) . '–' . exam_time_label($o['end_time']);
    $label = fn ($o) => trim(($o['program_short'] ?? '') . ($o['semester_no'] ? ' Sem ' . $o['semester_no'] : '')) . ' · ' . $o['subject_name'];

    // 1. Students: two papers of the same exam, or shared students across exams, at the same time.
    //    Each exam's students = its allocated students, or its candidates while nobody is allocated yet.
    $mine = null;
    foreach ($overlaps as $o) {
        if ((int) $o['exam_id'] === (int) $e['id']) {
            if ((int) $o['subject_id'] !== (int) $row['subject_id']) {
                $errors['start_time'] = "Students of this exam already write {$o['subject_name']} ({$when($o)}) on this date. Choose another date or time.";
                break;
            }
            continue;
        }
        $mine ??= exam_student_pool($e);
        $shared = $mine ? count(array_intersect_key($mine, exam_student_pool(exam_find((int) $o['exam_id']) ?? []))) : 0;
        if ($shared) {
            $errors['start_time'] = "$shared student" . ($shared === 1 ? '' : 's') . ' of this exam also write' . " {$label($o)} ({$o['exam_name']}) at {$when($o)}.";
            break;
        }
    }

    // 2. Room: classrooms/labs hold one paper at a time; halls are shared while seats last
    if (!empty($row['classroom_id'])) {
        $room = db_row('SELECT * FROM classrooms WHERE id = ?', [(int) $row['classroom_id']]);
        $inRoom = array_values(array_filter($overlaps, fn ($o) => (int) $o['classroom_id'] === (int) $row['classroom_id'] && !((int) $o['exam_id'] === (int) $e['id'] && (int) $o['subject_id'] === (int) $row['subject_id'])));
        if ($room && $inRoom) {
            if (in_array($room['type'], ['exam_hall', 'auditorium', 'seminar_hall'], true)) {
                $seats = exam_headcount($e);
                $seen = [(int) $e['id'] => true];
                foreach ($inRoom as $o) {
                    if (!isset($seen[(int) $o['exam_id']])) {
                        $seen[(int) $o['exam_id']] = true;
                        $other = exam_find((int) $o['exam_id']);
                        $seats += $other ? exam_headcount($other) : 0;
                    }
                }
                if ($seats > (int) $room['capacity']) {
                    $errors['classroom_id'] = "{$room['name']} has {$room['capacity']} seats but {$seats} students would be seated at that time (shared with {$label($inRoom[0])}).";
                }
            } else {
                $errors['classroom_id'] = "{$room['name']} is already booked for {$label($inRoom[0])} at {$when($inRoom[0])}.";
            }
        } elseif ($room) {
            $need = exam_headcount($e);
            if ($need > (int) $room['capacity'] && !in_array($room['type'], ['exam_hall', 'auditorium', 'seminar_hall'], true)) {
                $errors['classroom_id'] = "{$room['name']} seats {$room['capacity']} but {$need} students write this paper. Choose an examination hall or a larger room.";
            }
        }
    }

    // 3. Invigilators: one paper at a time
    $faculty = array_values(array_unique(array_filter(array_map('intval', array_merge([(int) ($row['invigilator_id'] ?? 0)], $invigilatorIds)))));
    if ($faculty && $overlaps) {
        $ids = exam_in(array_column($overlaps, 'id'));
        $busy = db_all("SELECT x.faculty_id, x.schedule_id FROM (
                            SELECT invigilator_id AS faculty_id, id AS schedule_id FROM exam_schedules WHERE id IN ($ids) AND invigilator_id IS NOT NULL
                            UNION SELECT faculty_id, exam_schedule_id FROM exam_invigilators WHERE exam_schedule_id IN ($ids)) x
                        WHERE x.faculty_id IN (" . exam_in($faculty) . ')');
        if ($busy) {
            $byId = array_column($overlaps, null, 'id');
            $names = db_pairs("SELECT id, TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE id IN (" . exam_in(array_column($busy, 'faculty_id')) . ')');
            $b = $busy[0];
            $o = $byId[$b['schedule_id']] ?? null;
            $msg = ($names[$b['faculty_id']] ?? 'An invigilator') . ' is already invigilating ' . ($o ? $label($o) . ' at ' . $when($o) : 'another paper at that time') . '.';
            $errors[in_array((int) $b['faculty_id'], [(int) ($row['invigilator_id'] ?? 0)], true) ? 'invigilator_id' : 'invigilator_ids'] = $msg;
        }
    }
    return $errors;
}

/**
 * Students who write an exam (for clash checks), as a [student_id => true] set: the eligible allocated students, or the
 * exam's candidates while nobody is allocated yet. Cached per request; exam_student_pool([], true) clears the cache.
 */
function exam_student_pool(array $e, bool $reset = false): array
{
    static $cache = [];
    if ($reset) {
        $cache = [];
        return [];
    }
    $id = (int) ($e['id'] ?? 0);
    if (!$id) {
        return [];
    }
    if (!isset($cache[$id])) {
        $ids = array_map('intval', db_column('SELECT student_id FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$id]));
        if (!$ids && !db_value('SELECT 1 FROM exam_students WHERE exam_id = ? LIMIT 1', [$id])) {
            $ids = exam_candidate_ids($e);
        }
        $cache[$id] = array_fill_keys($ids, true);
    }
    return $cache[$id];
}

/** Holiday / vacation dates between two dates (exam breaks and events do not block papers). */
function exam_blocked_dates(string $from, string $to): array
{
    $out = [];
    foreach (db_all("SELECT holiday_date, end_date FROM holidays WHERE type IN ('holiday','vacation') AND holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ?", [$to, $from]) as $h) {
        $d = strtotime($h['holiday_date']);
        $last = strtotime($h['end_date'] ?: $h['holiday_date']);
        for (; $d <= $last; $d += 86400) {
            $out[date('Y-m-d', $d)] = true;
        }
    }
    return $out;
}

/** Department faculty first, then everyone else (for invigilation duty). */
function exam_faculty_pool(array $e): array
{
    $dept = $e['department_id'] ?? null;
    return array_map('intval', db_column("SELECT id FROM faculty WHERE status = 'active' ORDER BY (department_id <=> ?) DESC, id", [$dept]));
}

/**
 * Create the date sheet for every exam subject that has no paper yet.
 * $opts: start_date, start_time (HH:MM), duration (minutes), gap (days between papers), classroom_id
 */
function exam_auto_schedule(array $e, array $opts = []): array
{
    exam_assert_open($e, 'change the schedule');
    $pending = array_values(array_filter(exam_subjects($e['id']), fn ($s) => !$s['schedule_id']));
    if (!$pending) {
        throw new CrudException('Every subject of this exam already has a paper scheduled.');
    }
    $date = ($opts['start_date'] ?? '') ?: ($e['start_date'] ?: date('Y-m-d', strtotime('+7 days')));
    $startTime = substr(($opts['start_time'] ?? '') ?: '10:00', 0, 5);
    $duration = (int) (($opts['duration'] ?? 0) ?: ($e['is_final'] ? 180 : 90));
    $duration = max(30, min(360, $duration));
    $gap = max(1, min(5, (int) ($opts['gap'] ?? 1)));
    $endTime = date('H:i', strtotime("2000-01-01 $startTime") + $duration * 60);
    $limit = $e['end_date'] ?: date('Y-m-d', strtotime($date . ' +60 days'));
    $blocked = exam_blocked_dates($date, date('Y-m-d', strtotime($limit . ' +60 days')));
    $rooms = db_all("SELECT id, capacity, type FROM classrooms WHERE status = 'active' ORDER BY FIELD(type, 'exam_hall', 'classroom', 'seminar_hall', 'lab', 'auditorium'), capacity");
    $headcount = exam_headcount($e);
    $pool = exam_faculty_pool($e);
    $made = [];
    $unplaced = [];
    $cursor = strtotime($date);
    foreach ($pending as $i => $s) {
        $placed = false;
        for ($tries = 0; $tries < 40 && !$placed; $tries++) {
            $d = date('Y-m-d', $cursor);
            if (date('N', $cursor) == 7 || isset($blocked[$d])) {
                $cursor += 86400;
                continue;
            }
            $row = ['exam_id' => $e['id'], 'subject_id' => (int) $s['subject_id'], 'exam_date' => $d, 'start_time' => $startTime, 'end_time' => $endTime, 'classroom_id' => null, 'invigilator_id' => null];
            if (exam_schedule_conflicts($row, null)) {
                $cursor += 86400;
                continue;
            }
            // Room
            $roomIds = !empty($opts['classroom_id']) ? [(int) $opts['classroom_id']] : array_map(fn ($r) => (int) $r['id'], array_filter($rooms, fn ($r) => (int) $r['capacity'] >= $headcount || $r['type'] === 'exam_hall'));
            foreach ($roomIds as $rid) {
                $try = $row + [];
                $try['classroom_id'] = $rid;
                if (!exam_schedule_conflicts($try, null)) {
                    $row = $try;
                    break;
                }
            }
            // Chief invigilator
            foreach ($pool as $k => $fid) {
                $try = $row;
                $try['invigilator_id'] = $fid;
                if (!exam_schedule_conflicts($try, null)) {
                    $row = $try;
                    unset($pool[$k]);
                    $pool[] = $fid; // rotate duty
                    break;
                }
            }
            if ($e['end_date'] && $d > $e['end_date']) {
                break;
            }
            $id = db_insert('exam_schedules', $row);
            $made[] = $id;
            $placed = true;
            $cursor += 86400 * $gap;
        }
        if (!$placed) {
            $unplaced[] = $s['name'];
        }
    }
    exam_refresh_schedule_status($e['id']);
    exam_assign_seats($e['id']);
    return ['scheduled' => count($made), 'unplaced' => $unplaced];
}

/** draft -> scheduled once every subject has a paper. */
function exam_refresh_schedule_status(int $examId): void
{
    $e = exam_find($examId);
    if (!$e || $e['status'] !== 'draft') {
        return;
    }
    $subjects = (int) db_value('SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ?', [$examId]);
    $scheduled = (int) db_value('SELECT COUNT(*) FROM exam_schedules s JOIN exam_subjects es ON es.exam_id = s.exam_id AND es.subject_id = s.subject_id WHERE s.exam_id = ?', [$examId]);
    if ($subjects && $scheduled >= $subjects) {
        db_update('exams', ['status' => 'scheduled'], 'id = ?', [$examId]);
    }
}

function exam_sync_invigilators(int $scheduleId, array $facultyIds, ?int $classroomId = null): void
{
    $facultyIds = array_values(array_unique(array_filter(array_map('intval', $facultyIds))));
    db_exec('DELETE FROM exam_invigilators WHERE exam_schedule_id = ?' . ($facultyIds ? ' AND faculty_id NOT IN (' . exam_in($facultyIds) . ')' : ''), [$scheduleId]);
    foreach ($facultyIds as $fid) {
        db_exec('INSERT IGNORE INTO exam_invigilators (exam_schedule_id, faculty_id, classroom_id) VALUES (?, ?, ?)', [$scheduleId, $fid, $classroomId]);
    }
}

/**
 * Date sheet across exams. $q: session_id, program_id, semester, exam_id, exam_type_id, from, to, q, page, per_page, paginate
 */
function exam_datesheet(array $q): array
{
    $where = ["ex.status <> 'cancelled'"];
    $args = [];
    $map = ['session_id' => 'ex.academic_session_id', 'program_id' => 'ex.program_id', 'semester' => 'ex.semester_no', 'exam_id' => 'ex.id', 'exam_type_id' => 'ex.exam_type_id', 'classroom_id' => 's.classroom_id'];
    foreach ($map as $k => $col) {
        if (isset($q[$k]) && $q[$k] !== '' && is_scalar($q[$k])) {
            $where[] = "$col = ?";
            $args[] = (int) $q[$k];
        }
    }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
        if (!empty($q[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $q[$k])) {
            $where[] = "s.exam_date $op ?";
            $args[] = $q[$k];
        }
    }
    $term = trim((string) ($q['q'] ?? ''));
    if ($term !== '') {
        $where[] = '(sb.name LIKE ? OR sb.code LIKE ? OR ex.name LIKE ? OR r.code LIKE ?)';
        array_push($args, "%$term%", "%$term%", "%$term%", "%$term%");
    }
    $from = ' FROM exam_schedules s JOIN exams ex ON ex.id = s.exam_id JOIN exam_types et ON et.id = ex.exam_type_id JOIN subjects sb ON sb.id = s.subject_id
              LEFT JOIN programs p ON p.id = ex.program_id LEFT JOIN classrooms r ON r.id = s.classroom_id LEFT JOIN faculty f ON f.id = s.invigilator_id
              WHERE ' . implode(' AND ', $where);
    $total = (int) db_value('SELECT COUNT(*)' . $from, $args);
    $paginate = !empty($q['paginate']);
    // paginate() caps pages at 200 rows - the agenda / calendar / printed date sheet need every paper (bounded at 2000)
    $p = $paginate ? paginate($total, max(1, (int) ($q['page'] ?? 1)), max(1, min(100, (int) ($q['per_page'] ?? 25))))
        : ['total' => $total, 'page' => 1, 'per_page' => 2000, 'pages' => 1, 'offset' => 0];
    $rows = db_all('SELECT s.id, s.exam_id, s.subject_id, s.exam_date, s.start_time, s.end_time, s.classroom_id, s.notes, ex.name AS exam_name, ' . exam_display_status_sql('ex') . ' AS exam_status,
                           et.name AS type_name, et.code AS type_code, p.short_name AS program_short, ex.semester_no, sb.code AS subject_code, sb.name AS subject_name, sb.type AS subject_type,
                           r.code AS room_code, r.name AS room_name, TRIM(CONCAT_WS(\' \', f.title, f.first_name, f.last_name)) AS chief_invigilator,
                           (SELECT COUNT(*) FROM exam_invigilators ei WHERE ei.exam_schedule_id = s.id) AS invigilators_count,
                           (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = s.exam_id AND x.is_eligible = 1) AS students_count'
        . $from . ' ORDER BY s.exam_date, s.start_time, p.sort_order, ex.semester_no, sb.code LIMIT ' . $p['per_page'] . ' OFFSET ' . $p['offset'], $args);
    foreach ($rows as &$r) {
        foreach (['id', 'exam_id', 'subject_id', 'semester_no', 'invigilators_count', 'students_count'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
    }
    unset($r);
    return ['rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages']];
}

/* ------------------------------------------------------------------
 * Students: candidates, eligibility, allocation, seating
 * ------------------------------------------------------------------ */

/** Student ids who should write this exam. */
function exam_candidate_ids(array $e): array
{
    if (!$e['program_id']) {
        return [];
    }
    if (($e['type_code'] ?? '') === 'SUPP') {
        return array_map('intval', db_column("SELECT DISTINCT r.student_id FROM results r JOIN exams ex ON ex.id = r.exam_id JOIN students s ON s.id = r.student_id
                                               WHERE r.is_published = 1 AND r.result_status IN ('BACKLOG','FAIL') AND ex.program_id = ? AND s.status = 'active'"
            . ($e['semester_no'] ? ' AND ex.semester_no = ?' : ''), $e['semester_no'] ? [$e['program_id'], $e['semester_no']] : [$e['program_id']]));
    }
    $current = (int) $e['academic_session_id'] === (int) current_session_id();
    if ($current || !$e['semester_no']) {
        return array_map('intval', db_column("SELECT id FROM students WHERE status = 'active' AND program_id = ?" . ($e['semester_no'] ? ' AND current_semester = ?' : '') . ' ORDER BY roll_no, id',
            $e['semester_no'] ? [$e['program_id'], $e['semester_no']] : [$e['program_id']]));
    }
    return array_map('intval', db_column("SELECT sa.student_id FROM student_academic sa JOIN students s ON s.id = sa.student_id
                                          WHERE sa.academic_session_id = ? AND sa.program_id = ? AND sa.semester_no = ? AND sa.status IN ('studying','promoted','completed','detained') ORDER BY s.roll_no, s.id",
        [$e['academic_session_id'], $e['program_id'], $e['semester_no']]));
}

function exam_min_attendance(array $e): float
{
    if ($e['min_attendance_percent'] !== null && $e['min_attendance_percent'] !== '') {
        return (float) $e['min_attendance_percent'];
    }
    $v = (float) setting('min_attendance_percent', 75);
    return $v > 0 && $v <= 100 ? $v : 75.0;
}

/** Eligibility for students: attendance >= minimum and no overdue fees. [student_id => [attendance_percent, fee_due, eligible, reason]] */
function exam_eligibility(array $e, array $studentIds): array
{
    $studentIds = array_values(array_unique(array_filter(array_map('intval', $studentIds))));
    if (!$studentIds) {
        return [];
    }
    $min = exam_min_attendance($e);
    $late = (string) setting('late_counts_as_present', '1') === '1' ? 1 : 0;
    $att = [];
    foreach (array_chunk($studentIds, 500) as $chunk) {
        foreach (db_all("SELECT student_id, held, present, late FROM attendance_summaries WHERE academic_session_id = ? AND student_id IN (" . exam_in($chunk) . ')', [$e['academic_session_id']]) as $r) {
            $att[(int) $r['student_id']] = (int) $r['held'] ? round(100 * ((int) $r['present'] + $late * (int) $r['late']) / (int) $r['held'], 2) : null;
        }
    }
    $fees = [];
    $feeSince = [];
    if ((string) setting('exam_block_fee_dues', '1') === '1') {
        // Dues block the admit card once they are overdue beyond the grace period (setting exam_fee_grace_days, default 30)
        $grace = max(0, (int) setting('exam_fee_grace_days', 30));
        $ref = date('Y-m-d', strtotime(min(exam_today(), $e['start_date'] ?: exam_today()) . " -$grace days"));
        foreach (array_chunk($studentIds, 500) as $chunk) {
            foreach (db_all("SELECT student_id, SUM(balance_amount) AS due, MIN(due_date) AS since FROM student_fees WHERE student_id IN (" . exam_in($chunk) . ")
                             AND status IN ('pending','partial','overdue') AND balance_amount > 0 AND due_date IS NOT NULL AND due_date < ? GROUP BY student_id", [$ref]) as $r) {
                $fees[(int) $r['student_id']] = round((float) $r['due'], 2);
                $feeSince[(int) $r['student_id']] = $r['since'];
            }
        }
    }
    $out = [];
    foreach ($studentIds as $sid) {
        $pct = $att[$sid] ?? null;
        $due = $fees[$sid] ?? 0.0;
        $reasons = [];
        if ($min > 0 && $pct !== null && $pct < $min) {
            $reasons[] = sprintf('Attendance %s%% is below the required %s%%', rtrim(rtrim(number_format($pct, 1), '0'), '.'), rtrim(rtrim(number_format($min, 2), '0'), '.'));
        }
        if ($due > 0) {
            $reasons[] = 'Overdue fees of ' . money($due) . (!empty($feeSince[$sid]) ? ' (due since ' . format_date($feeSince[$sid]) . ')' : '');
        }
        $out[$sid] = ['attendance_percent' => $pct, 'fee_due' => $due, 'eligible' => !$reasons, 'reason' => $reasons ? implode('; ', $reasons) : null];
    }
    return $out;
}

/** Allocate candidates (or the given students) to the exam. Ineligible students are recorded with their reason. */
function exam_allocate(array $e, ?array $onlyIds = null): array
{
    exam_assert_open($e, 'allocate students');
    $candidates = exam_candidate_ids($e);
    if ($onlyIds !== null) {
        $only = array_values(array_unique(array_filter(array_map('intval', $onlyIds))));
        $valid = array_map('intval', db_column("SELECT id FROM students WHERE status = 'active' AND id IN (" . exam_in($only) . ')'));
        $candidates = array_values(array_intersect($only, $valid));
    }
    if (!$candidates) {
        throw new CrudException($e['program_id'] ? 'No active students found for ' . $e['cohort'] . '.' : 'Choose the students to allocate.');
    }
    $existing = array_map('intval', db_column('SELECT student_id FROM exam_students WHERE exam_id = ?', [$e['id']]));
    $new = array_values(array_diff($candidates, $existing));
    $elig = exam_eligibility($e, $new);
    $rows = [];
    $counts = ['added' => 0, 'eligible' => 0, 'ineligible' => 0, 'already' => count(array_intersect($candidates, $existing))];
    foreach ($new as $sid) {
        $x = $elig[$sid];
        $rows[] = ['exam_id' => $e['id'], 'student_id' => $sid, 'is_eligible' => $x['eligible'] ? 1 : 0, 'ineligibility_reason' => $x['reason'],
            'attendance_percent' => $x['attendance_percent'], 'fee_due' => $x['fee_due'], 'hall_ticket_no' => exam_hall_ticket_no()];
        $counts['added']++;
        $counts[$x['eligible'] ? 'eligible' : 'ineligible']++;
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        $cols = array_keys($chunk[0]);
        $ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $args = [];
        foreach ($chunk as $r) {
            foreach ($cols as $c) {
                $args[] = $r[$c];
            }
        }
        db_exec('INSERT IGNORE INTO exam_students (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($chunk), $ph)), $args);
    }
    exam_assign_seats($e['id']);
    return $counts;
}

function exam_hall_ticket_no(): string
{
    return next_number('hall_ticket', 'HT{y}{n:6}');
}

/** Recalculate eligibility for every allocated student that was not overridden manually. */
function exam_refresh_eligibility(array $e): array
{
    exam_assert_open($e, 'refresh eligibility');
    $rows = db_all('SELECT student_id, is_eligible FROM exam_students WHERE exam_id = ? AND is_override = 0', [$e['id']]);
    $elig = exam_eligibility($e, array_column($rows, 'student_id'));
    $changed = 0;
    foreach ($rows as $r) {
        $x = $elig[(int) $r['student_id']] ?? null;
        if (!$x) {
            continue;
        }
        db_update('exam_students', ['is_eligible' => $x['eligible'] ? 1 : 0, 'ineligibility_reason' => $x['reason'], 'attendance_percent' => $x['attendance_percent'], 'fee_due' => $x['fee_due']],
            'exam_id = ? AND student_id = ?', [$e['id'], (int) $r['student_id']]);
        if ((int) $r['is_eligible'] !== ($x['eligible'] ? 1 : 0)) {
            $changed++;
        }
    }
    exam_assign_seats($e['id']);
    return ['checked' => count($rows), 'changed' => $changed];
}

/** Manual eligibility override (with reason). */
function exam_override_eligibility(array $e, int $studentId, bool $eligible, string $reason): array
{
    exam_assert_open($e, 'change eligibility');
    $row = db_row('SELECT * FROM exam_students WHERE exam_id = ? AND student_id = ?', [$e['id'], $studentId]);
    if (!$row) {
        throw new CrudException('This student is not allocated to the exam.');
    }
    if (!$eligible && db_value('SELECT 1 FROM exam_marks WHERE exam_id = ? AND student_id = ? LIMIT 1', [$e['id'], $studentId])) {
        throw new CrudException('Marks are already entered for this student. Remove the marks before debarring the student.');
    }
    $original = $row['is_override'] ? exam_eligibility($e, [$studentId])[$studentId] : ['reason' => $row['ineligibility_reason']];
    db_update('exam_students', [
        'is_eligible' => $eligible ? 1 : 0, 'is_override' => 1, 'override_reason' => mb_substr($reason, 0, 255), 'overridden_by' => user_id(), 'overridden_at' => date('Y-m-d H:i:s'),
        'ineligibility_reason' => $eligible ? ($original['reason'] ?? null) : mb_substr($reason, 0, 255),
    ], 'id = ?', [(int) $row['id']]);
    exam_assign_seats($e['id']);
    return db_row('SELECT * FROM exam_students WHERE id = ?', [(int) $row['id']]);
}

/** Clear a manual override (eligibility is recalculated). */
function exam_clear_override(array $e, int $studentId): void
{
    exam_assert_open($e, 'change eligibility');
    $x = exam_eligibility($e, [$studentId])[$studentId] ?? null;
    if (!$x) {
        return;
    }
    if (!$x['eligible'] && db_value('SELECT 1 FROM exam_marks WHERE exam_id = ? AND student_id = ? LIMIT 1', [$e['id'], $studentId])) {
        throw new CrudException('Marks are already entered for this student, who would become ineligible. Keep the override or remove the marks first.');
    }
    db_update('exam_students', ['is_eligible' => $x['eligible'] ? 1 : 0, 'ineligibility_reason' => $x['reason'], 'attendance_percent' => $x['attendance_percent'], 'fee_due' => $x['fee_due'],
        'is_override' => 0, 'override_reason' => null, 'overridden_by' => null, 'overridden_at' => null], 'exam_id = ? AND student_id = ?', [$e['id'], $studentId]);
    exam_assign_seats($e['id']);
}

function exam_remove_student(array $e, int $studentId): void
{
    exam_assert_open($e, 'remove students');
    if (db_value('SELECT 1 FROM exam_marks WHERE exam_id = ? AND student_id = ? LIMIT 1', [$e['id'], $studentId])) {
        throw new CrudException('Marks are already entered for this student and cannot be discarded. Mark the student absent instead.');
    }
    db_exec('DELETE FROM exam_students WHERE exam_id = ? AND student_id = ?', [$e['id'], $studentId]);
    db_exec('DELETE a FROM exam_attendance a JOIN exam_schedules s ON s.id = a.exam_schedule_id WHERE s.exam_id = ? AND a.student_id = ?', [$e['id'], $studentId]);
}

/** Seat eligible students across the exam's rooms (largest first) in roll-number order. Skipped once the exam has started. */
function exam_assign_seats(int $examId): void
{
    exam_student_pool([], true); // allocation may have changed
    $e = db_row('SELECT id, start_date FROM exams WHERE id = ?', [$examId]);
    if (!$e || ($e['start_date'] && $e['start_date'] <= exam_today())) {
        return;
    }
    $rooms = db_all('SELECT r.id, r.code, r.capacity FROM classrooms r WHERE r.id IN (SELECT DISTINCT classroom_id FROM exam_schedules WHERE exam_id = ? AND classroom_id IS NOT NULL) ORDER BY r.capacity DESC, r.code', [$examId]);
    $students = db_all('SELECT x.id FROM exam_students x JOIN students s ON s.id = x.student_id WHERE x.exam_id = ? AND x.is_eligible = 1 ORDER BY s.roll_no, s.student_uid', [$examId]);
    db_exec('UPDATE exam_students SET seat_no = NULL, classroom_id = NULL WHERE exam_id = ? AND is_eligible = 0', [$examId]);
    $roomIdx = 0;
    $used = 0;
    foreach ($students as $i => $s) {
        $room = $rooms[$roomIdx] ?? null;
        if ($room && $used >= (int) $room['capacity'] && isset($rooms[$roomIdx + 1])) {
            $roomIdx++;
            $used = 0;
            $room = $rooms[$roomIdx];
        }
        $used++;
        db_update('exam_students', ['seat_no' => ($room ? $room['code'] . '-' : 'S-') . str_pad((string) ($room ? $used : $i + 1), 3, '0', STR_PAD_LEFT), 'classroom_id' => $room['id'] ?? null], 'id = ?', [(int) $s['id']]);
    }
}

/** Allocated students (view=allocated) or not-yet-allocated candidates (view=pending), paginated. */
function exam_students_list(array $e, array $q): array
{
    [$page, $perPage] = [max(1, (int) ($q['page'] ?? 1)), max(1, min(100, (int) ($q['per_page'] ?? 25)))];
    $term = trim((string) ($q['q'] ?? ''));
    $search = '';
    $sargs = [];
    if ($term !== '') {
        $search = " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_uid LIKE ? OR s.roll_no LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        $sargs = array_fill(0, 5, "%$term%");
    }
    $allocatedIds = array_map('intval', db_column('SELECT student_id FROM exam_students WHERE exam_id = ?', [$e['id']]));
    $candidates = exam_candidate_ids($e);
    $pendingIds = array_values(array_diff($candidates, $allocatedIds));
    $summary = db_row('SELECT COUNT(*) AS allocated, COALESCE(SUM(is_eligible = 1), 0) AS eligible, COALESCE(SUM(is_eligible = 0), 0) AS ineligible, COALESCE(SUM(is_override = 1), 0) AS overridden,
                              COALESCE(SUM(hall_ticket_no IS NOT NULL AND is_eligible = 1), 0) AS admit_cards FROM exam_students WHERE exam_id = ?', [$e['id']]);
    $summary = array_map('intval', $summary) + ['pending' => count($pendingIds), 'candidates' => count($candidates), 'min_attendance' => exam_min_attendance($e),
        'fee_block' => (string) setting('exam_block_fee_dues', '1') === '1'];
    $view = ($q['view'] ?? 'allocated') === 'pending' ? 'pending' : 'allocated';
    $select = "s.id AS student_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.roll_no, s.photo, sc.name AS section_name";
    if ($view === 'pending') {
        $from = ' FROM students s LEFT JOIN sections sc ON sc.id = s.section_id WHERE s.id IN (' . exam_in($pendingIds) . ')' . $search;
        $total = $pendingIds ? (int) db_value('SELECT COUNT(*)' . $from, $sargs) : 0;
        $p = paginate($total, $page, $perPage);
        $rows = $total ? db_all("SELECT $select$from ORDER BY s.roll_no, s.student_uid LIMIT {$p['per_page']} OFFSET {$p['offset']}", $sargs) : [];
        $elig = exam_eligibility($e, array_column($rows, 'student_id'));
        foreach ($rows as &$r) {
            $x = $elig[(int) $r['student_id']];
            $r += ['allocated' => false, 'is_eligible' => $x['eligible'], 'ineligibility_reason' => $x['reason'], 'attendance_percent' => $x['attendance_percent'], 'fee_due' => $x['fee_due'],
                'is_override' => false, 'override_reason' => null, 'hall_ticket_no' => null, 'seat_no' => null, 'room_code' => null, 'marks_count' => 0];
        }
        unset($r);
    } else {
        $where = '';
        $filter = $q['status'] ?? '';
        if ($filter === 'eligible') {
            $where = ' AND x.is_eligible = 1';
        } elseif ($filter === 'ineligible') {
            $where = ' AND x.is_eligible = 0';
        } elseif ($filter === 'override') {
            $where = ' AND x.is_override = 1';
        }
        $from = ' FROM exam_students x JOIN students s ON s.id = x.student_id LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN classrooms r ON r.id = x.classroom_id
                  LEFT JOIN users ou ON ou.id = x.overridden_by WHERE x.exam_id = ?' . $where . $search;
        $args = array_merge([$e['id']], $sargs);
        $total = (int) db_value('SELECT COUNT(*)' . $from, $args);
        $p = paginate($total, $page, $perPage);
        $rows = db_all("SELECT $select, x.is_eligible, x.ineligibility_reason, x.attendance_percent, x.fee_due, x.is_override, x.override_reason, x.overridden_at, ou.name AS overridden_by_name,
                               x.hall_ticket_no, x.seat_no, r.code AS room_code,
                               (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = x.exam_id AND m.student_id = x.student_id) AS marks_count$from
                        ORDER BY x.is_eligible, s.roll_no, s.student_uid LIMIT {$p['per_page']} OFFSET {$p['offset']}", $args);
        foreach ($rows as &$r) {
            $r['allocated'] = true;
            $r['is_eligible'] = (bool) $r['is_eligible'];
            $r['is_override'] = (bool) $r['is_override'];
            $r['marks_count'] = (int) $r['marks_count'];
        }
        unset($r);
    }
    foreach ($rows as &$r) {
        $r['student_id'] = (int) $r['student_id'];
        $r['attendance_percent'] = $r['attendance_percent'] === null ? null : (float) $r['attendance_percent'];
        $r['fee_due'] = (float) $r['fee_due'];
    }
    unset($r);
    return ['view' => $view, 'rows' => $rows, 'total' => $p['total'], 'page' => $p['page'], 'per_page' => $p['per_page'], 'pages' => $p['pages'], 'summary' => $summary];
}

/* ------------------------------------------------------------------
 * Exam attendance (per paper)
 * ------------------------------------------------------------------ */

function exam_schedule_row(int $scheduleId): ?array
{
    return db_row("SELECT s.*, sb.code AS subject_code, sb.name AS subject_name, r.code AS room_code, r.name AS room_name FROM exam_schedules s JOIN subjects sb ON sb.id = s.subject_id
                   LEFT JOIN classrooms r ON r.id = s.classroom_id WHERE s.id = ?", [$scheduleId]);
}

function exam_attendance_sheet(array $schedule): array
{
    $rows = db_all("SELECT s.id AS student_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.roll_no, s.photo, x.seat_no,
                           a.status, a.remarks
                    FROM exam_students x JOIN students s ON s.id = x.student_id
                    LEFT JOIN exam_attendance a ON a.exam_schedule_id = ? AND a.student_id = x.student_id
                    WHERE x.exam_id = ? AND x.is_eligible = 1 ORDER BY s.roll_no, s.student_uid", [$schedule['id'], $schedule['exam_id']]);
    foreach ($rows as &$r) {
        $r['student_id'] = (int) $r['student_id'];
    }
    unset($r);
    $counts = ['present' => 0, 'absent' => 0, 'malpractice' => 0, 'unmarked' => 0];
    foreach ($rows as $r) {
        $counts[$r['status'] ?? 'unmarked'] = ($counts[$r['status'] ?? 'unmarked'] ?? 0) + 1;
    }
    return ['schedule' => $schedule, 'rows' => $rows, 'counts' => $counts, 'can_mark' => $schedule['exam_date'] <= exam_today()];
}

/** Save paper attendance. $rows: [{student_id, status: present|absent|malpractice, remarks}] */
function exam_save_attendance(array $schedule, array $rows): array
{
    if ($schedule['exam_date'] > exam_today()) {
        throw new CrudException('Attendance can be marked on or after the date of the paper (' . format_date($schedule['exam_date']) . ').');
    }
    $e = exam_require((int) $schedule['exam_id']);
    if ($e['status'] === 'published') {
        throw new CrudException('Results of this exam are published. Unpublish them to change attendance.');
    }
    $allowed = array_map('intval', db_column('SELECT student_id FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$schedule['exam_id']]));
    $errors = [];
    $clean = [];
    foreach ($rows as $r) {
        $sid = (int) ($r['student_id'] ?? 0);
        $st = (string) ($r['status'] ?? '');
        if (!in_array($sid, $allowed, true)) {
            continue;
        }
        if (!in_array($st, ['present', 'absent', 'malpractice'], true)) {
            $errors["rows.$sid.status"] = 'Choose present, absent or malpractice.';
            continue;
        }
        if ($st === 'malpractice' && trim((string) ($r['remarks'] ?? '')) === '') {
            $errors["rows.$sid.remarks"] = 'Describe the malpractice case.';
            continue;
        }
        $clean[$sid] = ['status' => $st, 'remarks' => mb_substr(trim((string) ($r['remarks'] ?? '')), 0, 255) ?: null];
    }
    if ($errors) {
        throw new CrudValidationException($errors, 'Some attendance entries need attention.');
    }
    db_transaction(function () use ($schedule, $clean) {
        foreach ($clean as $sid => $c) {
            db_exec('INSERT INTO exam_attendance (exam_schedule_id, student_id, status, remarks, marked_by) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks), marked_by = VALUES(marked_by)',
                [$schedule['id'], $sid, $c['status'], $c['remarks'], user_id()]);
        }
    });
    return exam_attendance_sheet($schedule);
}

/* ------------------------------------------------------------------
 * Marks entry
 * ------------------------------------------------------------------ */

/** Faculty without results approval may only enter marks for their own subjects/sections. null = unrestricted. */
function exam_marks_scope(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    if (!function_exists('user_id') || !user_id() || is_super_admin() || can('results', 'manage') || can('results', 'approve')) {
        return $cache = null;
    }
    $fid = (int) (current_user()['faculty_id'] ?? 0);
    if (!$fid) {
        return $cache = null;
    }
    $pairs = [];
    $subjects = [];
    foreach (db_all('SELECT subject_id, section_id FROM faculty_subjects WHERE faculty_id = ?', [$fid]) as $r) {
        $subjects[(int) $r['subject_id']] = true;
        if ($r['section_id']) {
            $pairs[(int) $r['subject_id']][] = (int) $r['section_id'];
        }
    }
    return $cache = ['faculty_id' => $fid, 'subjects' => $subjects, 'sections' => $pairs];
}

function exam_can_enter_subject(int $subjectId, ?int $sectionId = null): bool
{
    $s = exam_marks_scope();
    if ($s === null) {
        return true;
    }
    if (!isset($s['subjects'][$subjectId])) {
        return false;
    }
    return $sectionId === null || empty($s['sections'][$subjectId]) || in_array($sectionId, $s['sections'][$subjectId], true);
}

function exam_subject_row(int $examId, int $subjectId): ?array
{
    return db_row('SELECT es.*, sb.code, sb.name, sb.type, sb.credits, (es.max_internal + es.max_external + es.max_practical) AS max_total
                   FROM exam_subjects es JOIN subjects sb ON sb.id = es.subject_id WHERE es.exam_id = ? AND es.subject_id = ?', [$examId, $subjectId]);
}

/** Compute total / grade / pass for one mark entry. */
function exam_mark_compute(array $es, ?float $int, ?float $ext, ?float $prac, bool $absent, ?array $scale = null): array
{
    $scale = $scale ?? grade_scale();
    $max = (float) $es['max_internal'] + (float) $es['max_external'] + (float) $es['max_practical'];
    if ($absent) {
        return ['internal_marks' => $int, 'external_marks' => null, 'practical_marks' => null, 'total_marks' => round((float) $int, 2), 'max_marks' => $max,
            'grade' => 'AB', 'grade_point' => 0, 'is_absent' => 1, 'is_pass' => 0];
    }
    $total = round((float) $int + (float) $ext + (float) $prac, 2);
    $pct = $max > 0 ? $total * 100 / $max : 0;
    $pass = $total >= (float) $es['pass_marks'];
    $g = grade_for_percent($pct, $scale);
    if ($pass && !$g['is_pass']) {
        $g = grade_lowest_pass($scale) ?? $g;
    } elseif (!$pass && $g['is_pass']) {
        $g = grade_fail($scale);
    }
    return ['internal_marks' => $int, 'external_marks' => $ext, 'practical_marks' => $prac, 'total_marks' => $total, 'max_marks' => $max,
        'grade' => $g['grade'], 'grade_point' => $g['grade_point'], 'is_absent' => 0, 'is_pass' => $pass ? 1 : 0];
}

function exam_lock_reason(array $e): ?string
{
    return match ($e['status']) {
        'published' => 'Results are published - marks are read-only.',
        'verified' => 'Marks were verified and locked' . (!empty($e['verified_by_name']) ? ' by ' . $e['verified_by_name'] : '') . '. Unlock them from the exam to make corrections.',
        'cancelled' => 'This exam is cancelled.',
        default => null,
    };
}

/** Grid for one exam subject (optionally one section). */
function exam_marks_sheet(array $e, int $subjectId, ?int $sectionId = null): array
{
    $es = exam_subject_row($e['id'], $subjectId);
    if (!$es) {
        throw new CrudException('This subject is not part of the exam.');
    }
    $scope = exam_marks_scope();
    $sections = db_all("SELECT sc.id, sc.name, COUNT(*) AS students FROM exam_students x JOIN students s ON s.id = x.student_id JOIN sections sc ON sc.id = s.section_id
                        WHERE x.exam_id = ? AND x.is_eligible = 1 GROUP BY sc.id, sc.name ORDER BY sc.name", [$e['id']]);
    if ($scope !== null && !empty($scope['sections'][$subjectId])) {
        $sections = array_values(array_filter($sections, fn ($s) => in_array((int) $s['id'], $scope['sections'][$subjectId], true)));
        if ($sectionId === null && count($sections) === 1) {
            $sectionId = (int) $sections[0]['id'];
        }
    }
    $scheduleId = (int) db_value('SELECT id FROM exam_schedules WHERE exam_id = ? AND subject_id = ?', [$e['id'], $subjectId]);
    $where = 'x.exam_id = ? AND x.is_eligible = 1';
    $args = [$subjectId, $scheduleId, $e['id']];
    if ($sectionId) {
        $where .= ' AND s.section_id = ?';
        $args[] = $sectionId;
    } elseif ($scope !== null && !empty($scope['sections'][$subjectId])) {
        $where .= ' AND s.section_id IN (' . exam_in($scope['sections'][$subjectId]) . ')';
    }
    $rows = db_all("SELECT s.id AS student_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.roll_no, s.photo, sc.name AS section_name,
                           m.id AS mark_id, m.internal_marks, m.external_marks, m.practical_marks, m.total_marks, m.grade, m.grade_point, m.is_absent, m.is_pass, m.status, m.remarks, m.updated_at,
                           att.status AS attendance_status
                    FROM exam_students x JOIN students s ON s.id = x.student_id LEFT JOIN sections sc ON sc.id = s.section_id
                    LEFT JOIN exam_marks m ON m.exam_id = x.exam_id AND m.student_id = x.student_id AND m.subject_id = ?
                    LEFT JOIN exam_attendance att ON att.exam_schedule_id = ? AND att.student_id = x.student_id
                    WHERE $where ORDER BY s.roll_no, s.student_uid", $args);
    $entered = 0;
    $absent = 0;
    $passed = 0;
    $sum = 0.0;
    $submitted = 0;
    foreach ($rows as &$r) {
        $r['student_id'] = (int) $r['student_id'];
        $r['entered'] = $r['mark_id'] !== null;
        foreach (['internal_marks', 'external_marks', 'practical_marks', 'total_marks', 'grade_point'] as $k) {
            $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        }
        $r['is_absent'] = (bool) $r['is_absent'];
        $r['is_pass'] = $r['is_pass'] === null ? null : (bool) $r['is_pass'];
        if ($r['entered']) {
            $entered++;
            $absent += $r['is_absent'] ? 1 : 0;
            $passed += $r['is_pass'] ? 1 : 0;
            $sum += $r['is_absent'] ? 0 : (float) $r['total_marks'];
            $submitted += in_array($r['status'], ['submitted', 'verified', 'published'], true) ? 1 : 0;
        }
    }
    unset($r);
    $schedule = db_row('SELECT s.exam_date, s.start_time, s.end_time, r.code AS room_code FROM exam_schedules s LEFT JOIN classrooms r ON r.id = s.classroom_id WHERE s.exam_id = ? AND s.subject_id = ?', [$e['id'], $subjectId]);
    $lock = exam_lock_reason($e);
    $canEdit = !$lock && (can('results', 'create') || can('results', 'edit')) && exam_can_enter_subject($subjectId, $sectionId);
    if (!$lock && $schedule && $schedule['exam_date'] > exam_today()) {
        $lock = 'The paper is scheduled on ' . format_date($schedule['exam_date']) . '. Marks can be entered after the examination.';
        $canEdit = false;
    }
    $present = $entered - $absent;
    return [
        'exam' => ['id' => $e['id'], 'name' => $e['name'], 'status' => $e['status'], 'display_status' => $e['display_status'], 'cohort' => $e['cohort'], 'session_name' => $e['session_name']],
        'subject' => ['id' => (int) $es['subject_id'], 'code' => $es['code'], 'name' => $es['name'], 'type' => $es['type'], 'credits' => (float) $es['credits'],
            'max_internal' => (int) $es['max_internal'], 'max_external' => (int) $es['max_external'], 'max_practical' => (int) $es['max_practical'],
            'max_total' => (int) $es['max_total'], 'pass_marks' => (int) $es['pass_marks'], 'schedule' => $schedule],
        'section_id' => $sectionId,
        'sections' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'students' => (int) $s['students']], $sections),
        'rows' => $rows,
        'stats' => ['students' => count($rows), 'entered' => $entered, 'absent' => $absent, 'passed' => $passed, 'submitted' => $submitted,
            'average' => $present > 0 ? round($sum / $present, 2) : null, 'pass_percent' => $present > 0 ? round(100 * $passed / $present, 1) : null],
        'grades' => array_map(fn ($g) => ['grade' => $g['grade'], 'min' => $g['min'], 'max' => $g['max'], 'grade_point' => $g['grade_point'], 'is_pass' => $g['is_pass']], grade_scale()),
        'can_edit' => $canEdit,
        'can_submit' => $canEdit,
        'lock_reason' => $lock ?? (!exam_can_enter_subject($subjectId, $sectionId) ? 'You can view these marks but marks entry is limited to the subjects you teach.' : (!(can('results', 'create') || can('results', 'edit')) ? 'You have read-only access to marks.' : null)),
    ];
}

/** Parse a decimal mark ("" / null -> null). Returns [value, error]. */
function exam_parse_mark($v, int $max, string $label): array
{
    if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
        return [null, null];
    }
    $s = is_string($v) ? trim(str_replace(',', '.', $v)) : $v;
    if (!is_numeric($s)) {
        return [null, "$label must be a number."];
    }
    $f = round((float) $s, 2);
    if ($max <= 0 && $f != 0) {
        return [null, "$label is not assessed in this paper."];
    }
    if ($f < 0) {
        return [null, "$label cannot be negative."];
    }
    if ($f > $max) {
        return [null, "$label cannot exceed $max."];
    }
    if (abs($f * 2 - round($f * 2)) > 0.0001) {
        return [null, "$label can only use half marks (e.g. 23.5)."];
    }
    return [$max <= 0 ? null : $f, null];
}

/**
 * Save marks for one subject. $rows: [{student_id, internal_marks, external_marks, practical_marks, is_absent, remarks}]
 * A row with no marks and not absent removes an existing entry. Validation is all-or-nothing (field errors "rows.{id}.{field}").
 */
function exam_save_marks(array $e, int $subjectId, array $rows, bool $submit = false): array
{
    $lock = exam_lock_reason($e);
    if ($lock) {
        throw new CrudException($lock);
    }
    $es = exam_subject_row($e['id'], $subjectId);
    if (!$es) {
        throw new CrudException('This subject is not part of the exam.');
    }
    $schedule = db_row('SELECT exam_date FROM exam_schedules WHERE exam_id = ? AND subject_id = ?', [$e['id'], $subjectId]);
    if ($schedule && $schedule['exam_date'] > exam_today()) {
        throw new CrudException('Marks can be entered after the paper is held on ' . format_date($schedule['exam_date']) . '.');
    }
    $students = db_pairs('SELECT x.student_id, s.section_id FROM exam_students x JOIN students s ON s.id = x.student_id WHERE x.exam_id = ? AND x.is_eligible = 1', [$e['id']]);
    $scale = grade_scale();
    $errors = [];
    $upserts = [];
    $deletes = [];
    $labels = ['internal_marks' => 'Internal', 'external_marks' => 'External', 'practical_marks' => 'Practical'];
    $maxes = ['internal_marks' => (int) $es['max_internal'], 'external_marks' => (int) $es['max_external'], 'practical_marks' => (int) $es['max_practical']];
    foreach ($rows as $r) {
        $sid = (int) ($r['student_id'] ?? 0);
        if (!array_key_exists($sid, $students)) {
            $errors["rows.$sid.student_id"] = 'This student is not allocated (or not eligible) for the exam.';
            continue;
        }
        if (!exam_can_enter_subject($subjectId, $students[$sid] !== null ? (int) $students[$sid] : null)) {
            $errors["rows.$sid.student_id"] = 'You can only enter marks for the sections you teach.';
            continue;
        }
        $absent = in_array($r['is_absent'] ?? false, [true, 1, '1', 'true', 'yes', 'on'], true);
        $vals = [];
        foreach ($maxes as $k => $max) {
            [$v, $err] = exam_parse_mark($r[$k] ?? null, $max, $labels[$k]);
            if ($err) {
                $errors["rows.$sid.$k"] = $err;
            }
            $vals[$k] = $v;
        }
        if (isset($errors["rows.$sid.internal_marks"]) || isset($errors["rows.$sid.external_marks"]) || isset($errors["rows.$sid.practical_marks"])) {
            continue;
        }
        $hasAny = array_filter($vals, fn ($v) => $v !== null);
        if (!$absent && !$hasAny) {
            $deletes[] = $sid;
            continue;
        }
        if (!$absent) {
            foreach ($maxes as $k => $max) {
                if ($max > 0 && $vals[$k] === null) {
                    $errors["rows.$sid.$k"] = $labels[$k] . ' marks are missing.';
                }
            }
            if (isset($errors["rows.$sid.internal_marks"]) || isset($errors["rows.$sid.external_marks"]) || isset($errors["rows.$sid.practical_marks"])) {
                continue;
            }
        }
        $calc = exam_mark_compute($es, $vals['internal_marks'], $vals['external_marks'], $vals['practical_marks'], $absent, $scale);
        $upserts[$sid] = $calc + ['remarks' => mb_substr(trim((string) ($r['remarks'] ?? '')), 0, 255) ?: null];
    }
    if ($errors) {
        throw new CrudValidationException($errors, 'Some marks need attention - check the highlighted cells.');
    }
    $result = db_transaction(function () use ($e, $subjectId, $upserts, $deletes, $submit) {
        $now = date('Y-m-d H:i:s');
        $status = $submit ? 'submitted' : 'draft';
        foreach ($upserts as $sid => $c) {
            db_exec('INSERT INTO exam_marks (exam_id, subject_id, student_id, internal_marks, external_marks, practical_marks, total_marks, max_marks, grade, grade_point, is_absent, is_pass, status, remarks, entered_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE internal_marks = VALUES(internal_marks), external_marks = VALUES(external_marks), practical_marks = VALUES(practical_marks),
                       total_marks = VALUES(total_marks), max_marks = VALUES(max_marks), grade = VALUES(grade), grade_point = VALUES(grade_point), is_absent = VALUES(is_absent),
                       is_pass = VALUES(is_pass), remarks = VALUES(remarks), entered_by = VALUES(entered_by),
                       status = IF(VALUES(status) = \'submitted\' OR status = \'submitted\', \'submitted\', VALUES(status)), updated_at = ?',
                [$e['id'], $subjectId, $sid, $c['internal_marks'], $c['external_marks'], $c['practical_marks'], $c['total_marks'], $c['max_marks'], $c['grade'], $c['grade_point'],
                    $c['is_absent'], $c['is_pass'], $status, $c['remarks'], user_id(), $now]);
        }
        $deleted = 0;
        if ($deletes) {
            $deleted = db_exec('DELETE FROM exam_marks WHERE exam_id = ? AND subject_id = ? AND student_id IN (' . exam_in($deletes) . ')', [$e['id'], $subjectId]);
        }
        if ($upserts && in_array($e['status'], ['draft', 'scheduled', 'ongoing'], true)) {
            db_update('exams', ['status' => 'marks_entry'], 'id = ?', [$e['id']]);
        }
        return ['saved' => count($upserts), 'deleted' => $deleted];
    });
    return $result;
}

/** Mark every entered row of a subject as submitted (requires complete entry). */
function exam_submit_marks(array $e, int $subjectId): int
{
    $lock = exam_lock_reason($e);
    if ($lock) {
        throw new CrudException($lock);
    }
    $p = exam_marks_progress($e['id'], $subjectId)[0] ?? null;
    if (!$p) {
        throw new CrudException('This subject is not part of the exam.');
    }
    if ($p['entered'] < $p['students']) {
        throw new CrudException(sprintf('Marks are missing for %d of %d students in %s. Enter all marks (or mark students absent) before submitting.', $p['students'] - $p['entered'], $p['students'], $p['name']));
    }
    return db_exec("UPDATE exam_marks SET status = 'submitted' WHERE exam_id = ? AND subject_id = ? AND status = 'draft'", [$e['id'], $subjectId]);
}

/** Per-subject marks progress. */
function exam_marks_progress(int $examId, ?int $subjectId = null): array
{
    $students = (int) db_value('SELECT COUNT(*) FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$examId]);
    $rows = db_all("SELECT es.subject_id, sb.code, sb.name, sb.type, sb.credits, (es.max_internal + es.max_external + es.max_practical) AS max_total, es.pass_marks,
                           COUNT(m.id) AS entered, COALESCE(SUM(m.is_absent), 0) AS absent, COALESCE(SUM(m.is_pass), 0) AS passed,
                           COALESCE(SUM(m.status IN ('submitted','verified','published')), 0) AS submitted, AVG(CASE WHEN m.is_absent = 0 THEN m.total_marks END) AS average,
                           MAX(m.total_marks) AS highest, MAX(m.updated_at) AS last_entry, sch.exam_date
                    FROM exam_subjects es JOIN subjects sb ON sb.id = es.subject_id
                    LEFT JOIN exam_marks m ON m.exam_id = es.exam_id AND m.subject_id = es.subject_id AND m.student_id IN (SELECT student_id FROM exam_students WHERE exam_id = es.exam_id AND is_eligible = 1)
                    LEFT JOIN exam_schedules sch ON sch.exam_id = es.exam_id AND sch.subject_id = es.subject_id
                    WHERE es.exam_id = ?" . ($subjectId ? ' AND es.subject_id = ?' : '') . '
                    GROUP BY es.subject_id, sb.code, sb.name, sb.type, sb.credits, es.max_internal, es.max_external, es.max_practical, es.pass_marks, sch.exam_date
                    ORDER BY COALESCE(sch.exam_date, \'9999-12-31\'), sb.code', $subjectId ? [$examId, $subjectId] : [$examId]);
    return array_map(function ($r) use ($students) {
        $entered = (int) $r['entered'];
        $present = $entered - (int) $r['absent'];
        return ['subject_id' => (int) $r['subject_id'], 'code' => $r['code'], 'name' => $r['name'], 'type' => $r['type'], 'credits' => (float) $r['credits'],
            'max_total' => (int) $r['max_total'], 'pass_marks' => (int) $r['pass_marks'], 'students' => $students, 'entered' => $entered, 'absent' => (int) $r['absent'],
            'passed' => (int) $r['passed'], 'submitted' => (int) $r['submitted'], 'average' => $r['average'] !== null ? round((float) $r['average'], 1) : null,
            'highest' => $r['highest'] !== null ? (float) $r['highest'] : null, 'pass_percent' => $present > 0 ? round(100 * (int) $r['passed'] / $present, 1) : null,
            'percent' => $students ? round(100 * $entered / $students, 1) : 0, 'last_entry' => $r['last_entry'], 'exam_date' => $r['exam_date'],
            'state' => $students && $entered >= $students ? ((int) $r['submitted'] >= $students ? 'submitted' : 'complete') : ($entered ? 'in_progress' : 'not_started')];
    }, $rows);
}

/** Marks rows for an export (CSV / Excel). */
function exam_marks_export_rows(array $e, int $subjectId, ?int $sectionId): array
{
    $sheet = exam_marks_sheet($e, $subjectId, $sectionId);
    $s = $sheet['subject'];
    $headers = ['student_uid', 'roll_no', 'name', 'section'];
    foreach (['internal' => 'max_internal', 'external' => 'max_external', 'practical' => 'max_practical'] as $k => $m) {
        if ($s[$m] > 0) {
            $headers[] = $k . '_marks (max ' . $s[$m] . ')';
        }
    }
    array_push($headers, 'absent', 'total', 'grade', 'remarks');
    $data = [];
    foreach ($sheet['rows'] as $r) {
        $line = [$r['student_uid'], $r['roll_no'], $r['name'], $r['section_name']];
        foreach (['internal' => 'max_internal', 'external' => 'max_external', 'practical' => 'max_practical'] as $k => $m) {
            if ($s[$m] > 0) {
                $line[] = $r[$k . '_marks'] === null ? '' : rtrim(rtrim(number_format($r[$k . '_marks'], 2, '.', ''), '0'), '.');
            }
        }
        array_push($line, $r['is_absent'] ? 'Y' : '', $r['entered'] ? rtrim(rtrim(number_format((float) $r['total_marks'], 2, '.', ''), '0'), '.') : '', $r['grade'] ?? '', $r['remarks'] ?? '');
        $data[] = $line;
    }
    return [$sheet, $headers, $data];
}

/**
 * Parse an uploaded marks sheet (CSV / XLSX) into grid rows - nothing is saved.
 * Students are matched by student_uid (or roll_no). Columns: internal*, external*, practical*, absent, remarks.
 */
function exam_parse_marks_file(array $e, int $subjectId, array $file): array
{
    $es = exam_subject_row($e['id'], $subjectId);
    if (!$es) {
        throw new CrudException('This subject is not part of the exam.');
    }
    $res = upload_file($file, 'spreadsheet', 'imports', true);
    if (!$res['ok']) {
        throw new CrudValidationException(['file' => $res['error']], $res['error']);
    }
    $path = APP_ROOT . '/' . $res['path'];
    try {
        $lines = spreadsheet_read($path, $res['extension']);
    } finally {
        @unlink($path);
    }
    if (count($lines) < 2) {
        throw new CrudValidationException(['file' => 'The file has no data rows.'], 'The file has no data rows. Export the sheet first to get the expected columns.');
    }
    $header = array_map(fn ($h) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $h), '_')), array_shift($lines));
    $find = function (array $names) use ($header) {
        foreach ($header as $i => $h) {
            foreach ($names as $n) {
                if ($h === $n || str_starts_with($h, $n . '_')) {
                    return $i;
                }
            }
        }
        return null;
    };
    $col = ['uid' => $find(['student_uid', 'student_id', 'uid']), 'roll' => $find(['roll_no', 'roll', 'roll_number']), 'internal_marks' => $find(['internal_marks', 'internal']),
        'external_marks' => $find(['external_marks', 'external', 'theory']), 'practical_marks' => $find(['practical_marks', 'practical']), 'absent' => $find(['absent', 'is_absent', 'ab']),
        'remarks' => $find(['remarks', 'remark'])];
    if ($col['uid'] === null && $col['roll'] === null) {
        throw new CrudValidationException(['file' => 'Add a student_uid or roll_no column.'], 'The file needs a student_uid or roll_no column to match students.');
    }
    $students = db_all('SELECT s.id, s.student_uid, s.roll_no FROM exam_students x JOIN students s ON s.id = x.student_id WHERE x.exam_id = ? AND x.is_eligible = 1', [$e['id']]);
    $byUid = [];
    $byRoll = [];
    foreach ($students as $s) {
        $byUid[strtoupper((string) $s['student_uid'])] = (int) $s['id'];
        if ($s['roll_no']) {
            $byRoll[strtoupper((string) $s['roll_no'])] = (int) $s['id'];
        }
    }
    $maxes = ['internal_marks' => (int) $es['max_internal'], 'external_marks' => (int) $es['max_external'], 'practical_marks' => (int) $es['max_practical']];
    $labels = ['internal_marks' => 'Internal', 'external_marks' => 'External', 'practical_marks' => 'Practical'];
    $out = [];
    $problems = [];
    foreach ($lines as $n => $line) {
        if (!array_filter($line, fn ($v) => trim((string) $v) !== '')) {
            continue;
        }
        $rowNo = $n + 2;
        $uid = $col['uid'] !== null ? strtoupper(trim((string) ($line[$col['uid']] ?? ''))) : '';
        $roll = $col['roll'] !== null ? strtoupper(trim((string) ($line[$col['roll']] ?? ''))) : '';
        $sid = $byUid[$uid] ?? ($byRoll[$roll] ?? null);
        if (!$sid) {
            $problems[] = ['row' => $rowNo, 'message' => 'Student ' . ($uid ?: $roll ?: '(blank)') . ' is not an eligible candidate of this exam.'];
            continue;
        }
        $absentRaw = $col['absent'] !== null ? strtoupper(trim((string) ($line[$col['absent']] ?? ''))) : '';
        $item = ['student_id' => $sid, 'is_absent' => in_array($absentRaw, ['Y', 'YES', '1', 'TRUE', 'AB', 'A', 'ABSENT'], true),
            'remarks' => $col['remarks'] !== null ? trim((string) ($line[$col['remarks']] ?? '')) : null];
        foreach ($maxes as $k => $max) {
            $raw = $col[$k] !== null ? ($line[$col[$k]] ?? '') : '';
            if (is_string($raw) && in_array(strtoupper(trim($raw)), ['AB', 'A', 'ABSENT'], true)) {
                $item['is_absent'] = true;
                $item[$k] = null;
                continue;
            }
            [$v, $err] = exam_parse_mark($raw, $max, $labels[$k]);
            if ($err) {
                $problems[] = ['row' => $rowNo, 'message' => ($uid ?: $roll) . ': ' . $err];
            }
            $item[$k] = $err ? null : $v;
        }
        $out[] = $item;
    }
    return ['rows' => $out, 'problems' => array_slice($problems, 0, 200), 'matched' => count($out), 'total' => count(array_filter($lines, fn ($l) => array_filter($l, fn ($v) => trim((string) $v) !== '')))];
}

/* ------------------------------------------------------------------
 * Verify / results / publish
 * ------------------------------------------------------------------ */

/** Missing marks per subject (empty array = complete). */
function exam_missing_marks(int $examId): array
{
    $missing = [];
    foreach (exam_marks_progress($examId) as $p) {
        if ($p['entered'] < $p['students']) {
            $missing[] = $p['name'] . ' (' . $p['entered'] . ' of ' . $p['students'] . ')';
        }
    }
    return $missing;
}

/** Lock marks and compute (unpublished) results. */
function exam_verify(array $e): array
{
    if ($e['status'] === 'published') {
        throw new CrudException('Results are already published.');
    }
    if ($e['status'] === 'verified') {
        throw new CrudException('Marks are already verified.');
    }
    if ($e['status'] === 'cancelled') {
        throw new CrudException('This exam is cancelled.');
    }
    if (!(int) db_value('SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ?', [$e['id']])) {
        throw new CrudException('Add subjects to the exam first.');
    }
    if (!(int) db_value('SELECT COUNT(*) FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$e['id']])) {
        throw new CrudException('Allocate eligible students to the exam first.');
    }
    $missing = exam_missing_marks($e['id']);
    if ($missing) {
        throw new CrudException('Marks are incomplete: ' . implode(', ', array_slice($missing, 0, 4)) . (count($missing) > 4 ? ' and ' . (count($missing) - 4) . ' more' : '') . '.');
    }
    return db_transaction(function () use ($e) {
        $now = date('Y-m-d H:i:s');
        db_exec("UPDATE exam_marks SET status = 'verified', verified_by = ?, verified_at = ? WHERE exam_id = ?", [user_id(), $now, $e['id']]);
        db_update('exams', ['status' => 'verified', 'verified_at' => $now, 'verified_by' => user_id()], 'id = ?', [$e['id']]);
        return exam_compute_results($e['id']);
    });
}

function exam_unverify(array $e): void
{
    if ($e['status'] === 'published') {
        throw new CrudException('Results are published. Unpublish them before unlocking the marks.');
    }
    if ($e['status'] !== 'verified') {
        throw new CrudException('Marks of this exam are not locked.');
    }
    db_transaction(function () use ($e) {
        db_exec("UPDATE exam_marks SET status = 'submitted', verified_by = NULL, verified_at = NULL WHERE exam_id = ?", [$e['id']]);
        db_update('exams', ['status' => 'marks_entry', 'verified_at' => null, 'verified_by' => null], 'id = ?', [$e['id']]);
    });
}

/** Rank PASS results by percentage (competition ranking 1, 2, 2, 4). */
function exam_rank(array $rows): array
{
    $pass = array_filter($rows, fn ($r) => $r['result_status'] === 'PASS');
    uasort($pass, fn ($a, $b) => [$b['percentage'], $b['total_marks']] <=> [$a['percentage'], $a['total_marks']]);
    $ranks = [];
    $pos = 0;
    $prev = null;
    $rank = 0;
    foreach ($pass as $sid => $r) {
        $pos++;
        $key = $r['percentage'] . '|' . $r['total_marks'];
        if ($key !== $prev) {
            $rank = $pos;
            $prev = $key;
        }
        $ranks[$sid] = $rank;
    }
    return $ranks;
}

function exam_division(string $status, float $pct): ?string
{
    if ($status !== 'PASS') {
        return null;
    }
    return $pct >= 75 ? 'First Division with Distinction' : ($pct >= 60 ? 'First Division' : ($pct >= 50 ? 'Second Division' : 'Pass Class'));
}

/**
 * CGPA per student as of this exam: credit-weighted SGPA of the latest published final result per semester (exams ending on/before
 * this one) plus this exam's own SGPA. $current: [student_id => [sgpa, credits]].
 */
function exam_cgpa_map(array $e, array $current): array
{
    if (!$current) {
        return [];
    }
    $hist = db_all('SELECT r.student_id, r.semester_no, r.sgpa, r.credits_registered, ex.end_date, ex.id AS exam_id FROM results r JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id
                    WHERE et.is_final = 1 AND r.is_published = 1 AND r.sgpa IS NOT NULL AND r.result_status <> \'WITHHELD\' AND r.exam_id <> ? AND r.student_id IN (' . exam_in(array_keys($current)) . ')
                      AND (ex.end_date IS NULL OR ex.end_date <= ?) ORDER BY ex.end_date, ex.id', [$e['id'], $e['end_date'] ?: '9999-12-31']);
    $bySem = [];
    foreach ($hist as $h) {
        $bySem[(int) $h['student_id']][(int) $h['semester_no']] = [(float) $h['sgpa'], (float) $h['credits_registered']];
    }
    $out = [];
    foreach ($current as $sid => [$sgpa, $credits]) {
        $sems = $bySem[$sid] ?? [];
        if ($sgpa !== null) {
            $sems[(int) ($e['semester_no'] ?? 0)] = [$sgpa, $credits];
        }
        $num = 0.0;
        $den = 0.0;
        foreach ($sems as [$g, $c]) {
            $num += $g * $c;
            $den += $c;
        }
        $out[$sid] = $den > 0 ? round($num / $den, 2) : null;
    }
    return $out;
}

/** (Re)compute results for every eligible student. Keeps publication flag and WITHHELD decisions. */
function exam_compute_results(int $examId): array
{
    $e = exam_require($examId);
    $subjects = db_all('SELECT es.*, sb.credits FROM exam_subjects es JOIN subjects sb ON sb.id = es.subject_id WHERE es.exam_id = ?', [$examId]);
    $students = array_map('intval', db_column('SELECT student_id FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$examId]));
    $marks = [];
    foreach (db_all('SELECT student_id, subject_id, total_marks, max_marks, grade_point, is_absent, is_pass FROM exam_marks WHERE exam_id = ?', [$examId]) as $m) {
        $marks[(int) $m['student_id']][(int) $m['subject_id']] = $m;
    }
    $existing = [];
    foreach (db_all('SELECT * FROM results WHERE exam_id = ?', [$examId]) as $r) {
        $existing[(int) $r['student_id']] = $r;
    }
    $maxBacklogs = max(0, (int) setting('exam_max_backlogs', 3));
    $final = (int) $e['is_final'] === 1;
    $rows = [];
    foreach ($students as $sid) {
        $total = 0.0;
        $max = 0.0;
        $credits = 0.0;
        $earned = 0.0;
        $points = 0.0;
        $failed = 0;
        $absent = 0;
        foreach ($subjects as $s) {
            $m = $marks[$sid][(int) $s['subject_id']] ?? null;
            $smax = (float) $s['max_internal'] + (float) $s['max_external'] + (float) $s['max_practical'];
            $max += $smax;
            $credits += (float) $s['credits'];
            if (!$m || (int) $m['is_absent']) {
                $absent++;
                $failed++;
                $total += $m ? (float) $m['total_marks'] : 0;
                continue;
            }
            $total += (float) $m['total_marks'];
            $points += (float) $s['credits'] * (float) $m['grade_point'];
            if ((int) $m['is_pass']) {
                $earned += (float) $s['credits'];
            } else {
                $failed++;
            }
        }
        $pct = $max > 0 ? round($total * 100 / $max, 2) : 0.0;
        if ($subjects && $absent === count($subjects)) {
            $status = 'ABSENT';
        } elseif ($failed === 0) {
            $status = 'PASS';
        } elseif ($final && $failed <= $maxBacklogs) {
            $status = 'BACKLOG';
        } else {
            $status = 'FAIL';
        }
        $old = $existing[$sid] ?? null;
        if ($old && $old['withheld_at']) {
            $status = 'WITHHELD';
        }
        $rows[$sid] = ['total_marks' => round($total, 2), 'max_marks' => $max, 'percentage' => $pct, 'credits_registered' => $credits, 'credits_earned' => $earned,
            'sgpa' => $final && $credits > 0 ? round($points / $credits, 2) : null, 'result_status' => $status, 'backlog_count' => min(255, $failed)];
    }
    $cgpa = $final ? exam_cgpa_map($e, array_map(fn ($r) => [$r['result_status'] === 'WITHHELD' ? null : $r['sgpa'], $r['credits_registered']], $rows)) : [];
    $ranks = exam_rank($rows);
    $now = date('Y-m-d H:i:s');
    db_transaction(function () use ($e, $rows, $cgpa, $ranks, $existing, $now, $students) {
        foreach ($rows as $sid => $r) {
            $data = $r + ['cgpa' => $cgpa[$sid] ?? null, 'class_rank' => $ranks[$sid] ?? null, 'division' => exam_division($r['result_status'], (float) $r['percentage']), 'computed_at' => $now,
                'academic_session_id' => $e['academic_session_id'], 'program_id' => $e['program_id'], 'semester_no' => $e['semester_no']];
            if (isset($existing[$sid])) {
                db_update('results', $data, 'id = ?', [(int) $existing[$sid]['id']]);
            } else {
                db_insert('results', $data + ['exam_id' => $e['id'], 'student_id' => $sid, 'is_published' => 0]);
            }
        }
        // Students no longer eligible lose their (unpublished) result
        $stale = array_diff(array_keys($existing), $students);
        if ($stale) {
            db_exec('DELETE FROM results WHERE exam_id = ? AND is_published = 0 AND student_id IN (' . exam_in($stale) . ')', [$e['id']]);
        }
    });
    return exam_result_counts($examId);
}

function exam_result_counts(int $examId): array
{
    $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(result_status = 'PASS'), 0) AS pass, COALESCE(SUM(result_status = 'BACKLOG'), 0) AS backlog, COALESCE(SUM(result_status = 'FAIL'), 0) AS fail,
                        COALESCE(SUM(result_status = 'ABSENT'), 0) AS absent, COALESCE(SUM(result_status = 'WITHHELD'), 0) AS withheld, COALESCE(SUM(is_published), 0) AS published,
                        AVG(CASE WHEN result_status NOT IN ('ABSENT','WITHHELD') THEN percentage END) AS avg_percent, MAX(percentage) AS top_percent, AVG(sgpa) AS avg_sgpa
                 FROM results WHERE exam_id = ?", [$examId]);
    $out = array_map('intval', array_intersect_key($r, array_flip(['total', 'pass', 'backlog', 'fail', 'absent', 'withheld', 'published'])));
    $appeared = $out['total'] - $out['absent'] - $out['withheld'];
    $out['appeared'] = $appeared;
    $out['pass_percent'] = $appeared > 0 ? round(100 * $out['pass'] / $appeared, 1) : null;
    $out['avg_percent'] = $r['avg_percent'] !== null ? round((float) $r['avg_percent'], 1) : null;
    $out['top_percent'] = $r['top_percent'] !== null ? (float) $r['top_percent'] : null;
    $out['avg_sgpa'] = $r['avg_sgpa'] !== null ? round((float) $r['avg_sgpa'], 2) : null;
    return $out;
}

/** Write SGPA/CGPA of a published final exam into the students' semester records (existing rows only). */
function exam_sync_student_academic(array $e): int
{
    if (!(int) $e['is_final'] || !$e['semester_no']) {
        return 0;
    }
    return db_exec("UPDATE student_academic sa JOIN results r ON r.student_id = sa.student_id AND r.exam_id = ?
                    SET sa.sgpa = r.sgpa, sa.cgpa = r.cgpa
                    WHERE sa.semester_no = ? AND sa.academic_session_id = ? AND r.is_published = 1 AND r.result_status <> 'WITHHELD' AND r.sgpa IS NOT NULL",
        [$e['id'], $e['semester_no'], $e['academic_session_id']]);
}

/** Publish results (recomputed first). Returns counts + the student contacts for notifications. */
function exam_publish(array $e): array
{
    if ($e['status'] === 'published') {
        throw new CrudException('Results are already published.');
    }
    if ($e['status'] !== 'verified') {
        throw new CrudException('Verify (lock) the marks before publishing results.');
    }
    return db_transaction(function () use ($e) {
        $counts = exam_compute_results($e['id']);
        $now = date('Y-m-d H:i:s');
        db_exec('UPDATE results SET is_published = 1, published_at = ? WHERE exam_id = ?', [$now, $e['id']]);
        db_exec("UPDATE exam_marks SET status = 'published' WHERE exam_id = ?", [$e['id']]);
        db_update('exams', ['status' => 'published', 'published_at' => $now, 'published_by' => user_id(), 'result_date' => $e['result_date'] ?: date('Y-m-d')], 'id = ?', [$e['id']]);
        exam_sync_student_academic($e);
        $counts['published'] = $counts['total'];
        return $counts;
    });
}

function exam_unpublish(array $e): void
{
    if ($e['status'] !== 'published') {
        throw new CrudException('Results of this exam are not published.');
    }
    $issued = (int) db_value("SELECT COUNT(*) FROM marksheets m JOIN results r ON r.id = m.result_id WHERE r.exam_id = ? AND m.status = 'issued'", [$e['id']]);
    if ($issued) {
        throw new CrudException("$issued marksheet" . ($issued === 1 ? ' is' : 's are') . ' issued for this exam. Revoke them before unpublishing the results.');
    }
    db_transaction(function () use ($e) {
        db_exec('UPDATE results SET is_published = 0, published_at = NULL WHERE exam_id = ?', [$e['id']]);
        db_exec("UPDATE exam_marks SET status = 'verified' WHERE exam_id = ?", [$e['id']]);
        db_update('exams', ['status' => 'verified', 'published_at' => null, 'published_by' => null], 'id = ?', [$e['id']]);
    });
}

/** Students to notify after publishing: [{email, name, sgpa, status}] */
function exam_publish_recipients(int $examId): array
{
    return db_all("SELECT s.email, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, r.sgpa, r.percentage, r.result_status FROM results r JOIN students s ON s.id = r.student_id
                   WHERE r.exam_id = ? AND r.is_published = 1 AND r.result_status <> 'WITHHELD' AND s.email IS NOT NULL AND s.email <> ''", [$examId]);
}

function result_find(int $id): ?array
{
    return db_row("SELECT r.*, TRIM(CONCAT_WS(' ', s.first_name, s.middle_name, s.last_name)) AS student_name, s.student_uid, s.roll_no, s.enrollment_no, s.photo, s.father_name, s.mother_name,
                          s.dob, s.gender, sc.name AS section_name, ex.name AS exam_name, ex.status AS exam_status, ex.start_date, ex.end_date, ex.result_date, et.name AS type_name, et.is_final,
                          p.name AS program_name, p.short_name AS program_short, ss.name AS session_name, wu.name AS withheld_by_name,
                          m.id AS marksheet_id, m.marksheet_no, m.status AS marksheet_status
                   FROM results r JOIN students s ON s.id = r.student_id JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id
                   LEFT JOIN programs p ON p.id = r.program_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id LEFT JOIN sections sc ON sc.id = s.section_id
                   LEFT JOIN users wu ON wu.id = r.withheld_by LEFT JOIN marksheets m ON m.result_id = r.id WHERE r.id = ?", [$id]);
}

/** Subject-wise marks of a result. */
function result_subject_marks(array $r): array
{
    return db_all("SELECT sb.code, sb.name, sb.type, sb.credits, es.max_internal, es.max_external, es.max_practical, es.pass_marks,
                          (es.max_internal + es.max_external + es.max_practical) AS max_total, m.internal_marks, m.external_marks, m.practical_marks, m.total_marks,
                          m.grade, m.grade_point, m.is_absent, m.is_pass, m.remarks
                   FROM exam_subjects es JOIN subjects sb ON sb.id = es.subject_id
                   LEFT JOIN exam_marks m ON m.exam_id = es.exam_id AND m.subject_id = es.subject_id AND m.student_id = ?
                   WHERE es.exam_id = ? ORDER BY FIELD(sb.type, 'theory', 'elective', 'lab', 'practical', 'project'), sb.code", [$r['student_id'], $r['exam_id']]);
}

function result_withhold(array $r, string $reason): void
{
    if ($r['marksheet_id'] && $r['marksheet_status'] === 'issued') {
        throw new CrudException('Marksheet ' . $r['marksheet_no'] . ' is issued for this result. Revoke it before withholding the result.');
    }
    if ($r['withheld_at']) {
        throw new CrudException('This result is already withheld.');
    }
    db_update('results', ['result_status' => 'WITHHELD', 'withheld_reason' => mb_substr($reason, 0, 255), 'withheld_by' => user_id(), 'withheld_at' => date('Y-m-d H:i:s'), 'class_rank' => null, 'division' => null],
        'id = ?', [(int) $r['id']]);
}

function result_unwithhold(array $r): void
{
    if (!$r['withheld_at']) {
        throw new CrudException('This result is not withheld.');
    }
    db_update('results', ['withheld_reason' => null, 'withheld_by' => null, 'withheld_at' => null], 'id = ?', [(int) $r['id']]);
    exam_compute_results((int) $r['exam_id']);
    $e = exam_find((int) $r['exam_id']);
    if ($e && $e['status'] === 'published') {
        exam_sync_student_academic($e);
    }
}

/** Semester-wise published history of a student (CGPA trend). */
function result_student_history(int $studentId): array
{
    return array_map(function ($r) {
        foreach (['sgpa', 'cgpa', 'percentage'] as $k) {
            $r[$k] = $r[$k] === null ? null : (float) $r[$k];
        }
        $r['id'] = (int) $r['id'];
        return $r;
    }, db_all('SELECT r.id, r.exam_id, ex.name AS exam_name, et.name AS type_name, et.is_final, r.semester_no, ss.name AS session_name, r.percentage, r.sgpa, r.cgpa, r.result_status, r.backlog_count,
                      r.class_rank, ex.result_date, m.marksheet_no
               FROM results r JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id
               LEFT JOIN marksheets m ON m.result_id = r.id AND m.status = \'issued\'
               WHERE r.student_id = ? AND r.is_published = 1 ORDER BY ex.end_date, ex.id', [$studentId]));
}

/** Analytics for the results dashboard. */
function results_overview(array $e): array
{
    $counts = exam_result_counts($e['id']);
    $toppers = db_all("SELECT r.id, r.student_id, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS name, s.student_uid, s.photo, sc.name AS section_name, r.percentage, r.sgpa, r.cgpa, r.total_marks, r.max_marks, r.class_rank
                       FROM results r JOIN students s ON s.id = r.student_id LEFT JOIN sections sc ON sc.id = s.section_id
                       WHERE r.exam_id = ? AND r.result_status = 'PASS' AND r.class_rank IS NOT NULL ORDER BY r.class_rank, r.percentage DESC LIMIT 5", [$e['id']]);
    $grades = db_pairs('SELECT grade, COUNT(*) FROM exam_marks WHERE exam_id = ? AND grade IS NOT NULL GROUP BY grade', [$e['id']]);
    $order = array_merge(array_column(grade_scale(), 'grade'), ['AB']);
    $gradeDist = [];
    foreach ($order as $g) {
        $gradeDist[] = ['grade' => $g, 'count' => (int) ($grades[$g] ?? 0)];
    }
    $bands = db_row("SELECT COALESCE(SUM(percentage >= 90), 0) AS b90, COALESCE(SUM(percentage >= 75 AND percentage < 90), 0) AS b75, COALESCE(SUM(percentage >= 60 AND percentage < 75), 0) AS b60,
                            COALESCE(SUM(percentage >= 50 AND percentage < 60), 0) AS b50, COALESCE(SUM(percentage >= 40 AND percentage < 50), 0) AS b40, COALESCE(SUM(percentage < 40), 0) AS b0
                     FROM results WHERE exam_id = ? AND result_status NOT IN ('ABSENT','WITHHELD')", [$e['id']]);
    $subjects = array_map(function ($p) use ($e) {
        $dist = db_pairs('SELECT grade, COUNT(*) FROM exam_marks WHERE exam_id = ? AND subject_id = ? AND grade IS NOT NULL GROUP BY grade', [$e['id'], $p['subject_id']]);
        $low = db_value('SELECT MIN(total_marks) FROM exam_marks WHERE exam_id = ? AND subject_id = ? AND is_absent = 0', [$e['id'], $p['subject_id']]);
        return $p + ['lowest' => $low !== null ? (float) $low : null, 'average_percent' => $p['average'] !== null && $p['max_total'] ? round(100 * $p['average'] / $p['max_total'], 1) : null,
            'failed' => $p['entered'] - $p['absent'] - $p['passed'], 'grades' => array_map('intval', $dist)];
    }, exam_marks_progress($e['id']));
    return [
        'exam' => ['id' => $e['id'], 'name' => $e['name'], 'status' => $e['status'], 'display_status' => $e['display_status'], 'cohort' => $e['cohort'], 'session_name' => $e['session_name'],
            'type_name' => $e['type_name'], 'is_final' => (bool) $e['is_final'], 'program_id' => $e['program_id'] ? (int) $e['program_id'] : null, 'semester_no' => $e['semester_no'] ? (int) $e['semester_no'] : null,
            'academic_session_id' => (int) $e['academic_session_id'], 'published_at' => $e['published_at'], 'published_by_name' => $e['published_by_name'], 'verified_at' => $e['verified_at'],
            'result_date' => $e['result_date'], 'start_date' => $e['start_date'], 'end_date' => $e['end_date']],
        'counts' => $counts,
        'toppers' => array_map(fn ($t) => ['id' => (int) $t['id'], 'student_id' => (int) $t['student_id'], 'name' => $t['name'], 'student_uid' => $t['student_uid'], 'photo' => $t['photo'], 'section_name' => $t['section_name'],
            'percentage' => (float) $t['percentage'], 'sgpa' => $t['sgpa'] !== null ? (float) $t['sgpa'] : null, 'cgpa' => $t['cgpa'] !== null ? (float) $t['cgpa'] : null, 'class_rank' => (int) $t['class_rank'],
            'total_marks' => (float) $t['total_marks'], 'max_marks' => (float) $t['max_marks']], $toppers),
        'grade_distribution' => $gradeDist,
        'bands' => [['label' => '90%+', 'count' => (int) $bands['b90']], ['label' => '75–89%', 'count' => (int) $bands['b75']], ['label' => '60–74%', 'count' => (int) $bands['b60']],
            ['label' => '50–59%', 'count' => (int) $bands['b50']], ['label' => '40–49%', 'count' => (int) $bands['b40']], ['label' => '< 40%', 'count' => (int) $bands['b0']]],
        'subjects' => $subjects,
        'marksheets' => (int) db_value("SELECT COUNT(*) FROM marksheets m JOIN results r ON r.id = m.result_id WHERE r.exam_id = ? AND m.status = 'issued'", [$e['id']]),
        'missing_marks' => in_array($e['status'], ['verified', 'published'], true) ? [] : exam_missing_marks($e['id']),
    ];
}

/* ------------------------------------------------------------------
 * Workflow overview (exam detail page)
 * ------------------------------------------------------------------ */

function exam_overview(array $e): array
{
    $id = $e['id'];
    $subjects = (int) db_value('SELECT COUNT(*) FROM exam_subjects WHERE exam_id = ?', [$id]);
    $scheduled = (int) db_value('SELECT COUNT(*) FROM exam_schedules s JOIN exam_subjects es ON es.exam_id = s.exam_id AND es.subject_id = s.subject_id WHERE s.exam_id = ?', [$id]);
    $stu = db_row('SELECT COUNT(*) AS allocated, COALESCE(SUM(is_eligible = 1), 0) AS eligible, COALESCE(SUM(is_eligible = 0), 0) AS ineligible FROM exam_students WHERE exam_id = ?', [$id]);
    $progress = exam_marks_progress($id);
    $expected = array_sum(array_column($progress, 'students'));
    $entered = array_sum(array_column($progress, 'entered'));
    $marksPct = $expected ? round(100 * $entered / $expected, 1) : 0;
    $counts = exam_result_counts($id);
    $steps = [
        ['key' => 'create', 'label' => 'Create Exam', 'done' => true, 'detail' => 'Created ' . format_date($e['created_at'])],
        ['key' => 'subjects', 'label' => 'Assign Subjects', 'done' => $subjects > 0, 'detail' => $subjects . ' subject' . ($subjects === 1 ? '' : 's')],
        ['key' => 'schedule', 'label' => 'Schedule', 'done' => $subjects > 0 && $scheduled >= $subjects, 'detail' => $scheduled . ' of ' . $subjects . ' papers scheduled'],
        ['key' => 'students', 'label' => 'Allocate Students', 'done' => (int) $stu['eligible'] > 0, 'detail' => (int) $stu['eligible'] . ' eligible · ' . (int) $stu['ineligible'] . ' not eligible'],
        ['key' => 'marks', 'label' => 'Enter Marks', 'done' => $expected > 0 && $entered >= $expected, 'detail' => $marksPct . '% entered'],
        ['key' => 'verify', 'label' => 'Verify', 'done' => in_array($e['status'], ['verified', 'published'], true), 'detail' => $e['verified_at'] ? 'Locked ' . format_date($e['verified_at']) : 'Marks not locked'],
        ['key' => 'publish', 'label' => 'Publish Result', 'done' => $e['status'] === 'published', 'detail' => $e['published_at'] ? 'Published ' . format_date($e['published_at']) : 'Not published'],
    ];
    $current = 'publish';
    foreach ($steps as $s) {
        if (!$s['done']) {
            $current = $s['key'];
            break;
        }
    }
    if ($e['status'] === 'published') {
        $current = 'done';
    }
    $timeline = db_all("SELECT a.action, a.description, a.created_at, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id
                        WHERE a.module IN ('examination','results') AND a.record_id = ? ORDER BY a.id DESC LIMIT 12", [(string) $id]);
    $next = db_row('SELECT s.exam_date, s.start_time, s.end_time, sb.name AS subject_name, sb.code AS subject_code, r.code AS room_code FROM exam_schedules s JOIN subjects sb ON sb.id = s.subject_id
                    LEFT JOIN classrooms r ON r.id = s.classroom_id WHERE s.exam_id = ? AND s.exam_date >= ? ORDER BY s.exam_date, s.start_time LIMIT 1', [$id, exam_today()]);
    return [
        'exam' => $e,
        'steps' => $steps,
        'current_step' => $current,
        'counts' => ['subjects' => $subjects, 'scheduled' => $scheduled, 'allocated' => (int) $stu['allocated'], 'eligible' => (int) $stu['eligible'], 'ineligible' => (int) $stu['ineligible'],
            'marks_expected' => $expected, 'marks_entered' => $entered, 'marks_percent' => $marksPct, 'pending_candidates' => $e['program_id'] ? max(0, count(exam_candidate_ids($e)) - (int) $stu['allocated']) : 0],
        'results' => $counts,
        'progress' => $progress,
        'next_paper' => $next,
        'timeline' => $timeline,
        'min_attendance' => exam_min_attendance($e),
        'marksheets' => (int) db_value("SELECT COUNT(*) FROM marksheets m JOIN results r ON r.id = m.result_id WHERE r.exam_id = ? AND m.status = 'issued'", [$id]),
    ];
}

/** KPIs for the examinations list. */
function exam_summary(?int $sessionId): array
{
    $phase = exam_phase_sql('e');
    $where = $sessionId ? 'e.academic_session_id = ?' : '1=1';
    $args = $sessionId ? [$sessionId] : [];
    $sel = [];
    foreach ($phase as $k => $sql) {
        $sel[] = "COALESCE(SUM($sql), 0) AS $k";
    }
    $row = db_row('SELECT COUNT(*) AS total, ' . implode(', ', $sel) . " FROM exams e WHERE $where", $args);
    $out = array_map('intval', $row);
    $out['papers_next_14'] = (int) db_value("SELECT COUNT(*) FROM exam_schedules s JOIN exams e ON e.id = s.exam_id WHERE e.status <> 'cancelled' AND s.exam_date BETWEEN ? AND ?" . ($sessionId ? ' AND e.academic_session_id = ?' : ''),
        array_merge([exam_today(), date('Y-m-d', strtotime('+14 days'))], $args));
    $out['upcoming_papers'] = exam_datesheet(['session_id' => $sessionId, 'from' => exam_today(), 'paginate' => 1, 'per_page' => 6])['rows'];
    $out['marks_pending_subjects'] = (int) db_value("SELECT COUNT(*) FROM exam_subjects es JOIN exams e ON e.id = es.exam_id
        WHERE e.status IN ('scheduled','ongoing','marks_entry') AND e.end_date < ? AND $where
          AND (SELECT COUNT(*) FROM exam_marks m WHERE m.exam_id = es.exam_id AND m.subject_id = es.subject_id) < (SELECT COUNT(*) FROM exam_students x WHERE x.exam_id = es.exam_id AND x.is_eligible = 1)",
        array_merge([exam_today()], $args));
    return $out;
}

/* ------------------------------------------------------------------
 * Marksheets
 * ------------------------------------------------------------------ */

/** Issuing / re-issuing marksheets is done by the exam controller (results: publish or manage) - not by faculty entering marks. */
function marksheets_can_issue(): bool
{
    return can('results', 'publish') || can('results', 'manage');
}

/** Revoking an issued marksheet (results: approve or manage). */
function marksheets_can_revoke(): bool
{
    return can('results', 'approve') || can('results', 'manage');
}

function marksheet_number(): string
{
    return next_number('marksheet', 'GIMT-MS-{Y}-{n:6}');
}

/**
 * Public verification URL encoded in the marksheet QR code. It opens the marksheet verification page served by
 * GET /api/marksheets/verify/{no} (HTML for browsers, JSON for API clients).
 */
function marksheet_verify_url(array $m): string
{
    return absolute_url('api/marksheets/verify/' . rawurlencode($m['marksheet_no'])) . '?code=' . rawurlencode(substr((string) $m['verification_code'], 0, 16));
}

/** Human-readable security code printed on the marksheet (first 12 hex digits of the verification code, e.g. 21BD-E3E0-9716). */
function marksheet_security_code(string $verificationCode): string
{
    return strtoupper(implode('-', str_split(substr($verificationCode, 0, 12), 4)));
}

/** Generate marksheets for published results that have none (WITHHELD results are skipped). */
function marksheet_generate(int $examId, ?array $resultIds = null, ?string $issuedAt = null): array
{
    $e = exam_require($examId);
    if (!(int) $e['is_final']) {
        throw new CrudException('Marksheets are issued for final (end-semester) examinations. Print a provisional statement of marks from the result instead.');
    }
    if ($e['status'] !== 'published') {
        throw new CrudException('Publish the results of ' . $e['name'] . ' before generating marksheets.');
    }
    $sql = "SELECT r.id, r.student_id, r.result_status FROM results r LEFT JOIN marksheets m ON m.result_id = r.id WHERE r.exam_id = ? AND r.is_published = 1 AND m.id IS NULL";
    $args = [$examId];
    if ($resultIds !== null) {
        $sql .= ' AND r.id IN (' . exam_in($resultIds) . ')';
    }
    $rows = db_all($sql . ' ORDER BY r.id', $args);
    $made = [];
    $skipped = 0;
    foreach ($rows as $r) {
        if ($r['result_status'] === 'WITHHELD') {
            $skipped++;
            continue;
        }
        $made[] = db_insert('marksheets', ['marksheet_no' => marksheet_number(), 'result_id' => (int) $r['id'], 'student_id' => (int) $r['student_id'],
            'verification_code' => bin2hex(random_bytes(16)), 'issued_at' => $issuedAt ?? date('Y-m-d H:i:s'), 'issued_by' => function_exists('user_id') ? user_id() : null, 'status' => 'issued']);
    }
    return ['generated' => count($made), 'skipped_withheld' => $skipped, 'ids' => $made];
}

function marksheet_find(int $id): ?array
{
    return db_row("SELECT m.*, r.exam_id, r.result_status, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, iu.name AS issued_by_name
                   FROM marksheets m JOIN results r ON r.id = m.result_id JOIN students s ON s.id = m.student_id LEFT JOIN users iu ON iu.id = m.issued_by WHERE m.id = ?", [$id]);
}

function marksheet_revoke(array $m, string $reason): void
{
    if ($m['status'] === 'revoked') {
        throw new CrudException('This marksheet is already revoked.');
    }
    db_update('marksheets', ['status' => 'revoked', 'revoked_reason' => mb_substr($reason, 0, 255), 'revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int) $m['id']]);
}

/** Re-issue a revoked marksheet under a new number and verification code. Returns the new number. */
function marksheet_reissue(array $m): string
{
    if ($m['status'] !== 'revoked') {
        throw new CrudException('Only revoked marksheets can be re-issued.');
    }
    $r = db_row('SELECT r.result_status, r.is_published FROM results r WHERE r.id = ?', [(int) $m['result_id']]);
    if (!$r || !(int) $r['is_published']) {
        throw new CrudException('The result is no longer published.');
    }
    if ($r['result_status'] === 'WITHHELD') {
        throw new CrudException('The result is withheld. Release it before re-issuing the marksheet.');
    }
    $no = marksheet_number();
    // Remember the revoked number so scanning the old copy reports it as revoked / replaced (column holds 255 chars)
    $previous = array_values(array_filter(explode(',', (string) ($m['previous_numbers'] ?? ''))));
    $previous[] = $m['marksheet_no'];
    while (strlen(implode(',', $previous)) > 255) {
        array_shift($previous);
    }
    db_update('marksheets', ['marksheet_no' => $no, 'verification_code' => bin2hex(random_bytes(16)), 'status' => 'issued', 'issued_at' => date('Y-m-d H:i:s'), 'issued_by' => user_id(),
        'revoked_reason' => null, 'revoked_at' => null, 'previous_numbers' => implode(',', $previous), 'printed_count' => 0, 'last_printed_at' => null], 'id = ?', [(int) $m['id']]);
    return $no;
}

/** Everything the printed marksheet needs. */
function marksheet_print_data(int $id): ?array
{
    $m = db_row('SELECT * FROM marksheets WHERE id = ?', [$id]);
    if (!$m) {
        return null;
    }
    $r = result_find((int) $m['result_id']);
    if (!$r) {
        return null;
    }
    $student = db_row('SELECT s.*, b.name AS batch_name, c.name AS course_name FROM students s LEFT JOIN batches b ON b.id = s.batch_id LEFT JOIN courses c ON c.id = s.course_id WHERE s.id = ?', [(int) $m['student_id']]);
    return ['marksheet' => $m, 'result' => $r, 'student' => $student, 'subjects' => result_subject_marks($r), 'verify_url' => marksheet_verify_url($m),
        'history' => (int) $r['is_final'] ? array_values(array_filter(result_student_history((int) $m['student_id']), fn ($h) => (int) $h['is_final'] === 1)) : []];
}

/**
 * Public verification lookup. Marksheet numbers are sequential, so the number alone only confirms that the marksheet exists
 * (status, issue date, program, semester, masked name). The result itself (name, SGPA, CGPA, percentage, result) is shown only
 * with the security code printed on the marksheet / carried by its QR code (at least 12 hex digits).
 * Returns null when no marksheet has this number.
 */
function marksheet_verify(string $no, ?string $code = null): ?array
{
    $m = db_row("SELECT m.marksheet_no, m.status, m.issued_at, m.verification_code, m.revoked_at, s.first_name, s.last_name, s.student_uid,
                        p.name AS program_name, r.semester_no, ss.name AS session_name, ex.name AS exam_name, et.name AS type_name, r.sgpa, r.cgpa, r.result_status, r.percentage
                 FROM marksheets m JOIN results r ON r.id = m.result_id JOIN students s ON s.id = m.student_id JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id
                 LEFT JOIN programs p ON p.id = r.program_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id WHERE m.marksheet_no = ?", [$no]);
    if (!$m) {
        // An earlier copy that was revoked and re-issued under a new number
        $old = db_row("SELECT m.issued_at, s.first_name, s.last_name, p.name AS program_name, r.semester_no, ss.name AS session_name, et.name AS type_name
                       FROM marksheets m JOIN results r ON r.id = m.result_id JOIN students s ON s.id = m.student_id JOIN exams ex ON ex.id = r.exam_id JOIN exam_types et ON et.id = ex.exam_type_id
                       LEFT JOIN programs p ON p.id = r.program_id LEFT JOIN academic_sessions ss ON ss.id = r.academic_session_id
                       WHERE m.previous_numbers IS NOT NULL AND FIND_IN_SET(?, m.previous_numbers) > 0 LIMIT 1", [$no]);
        if (!$old) {
            return null;
        }
        return ['marksheet_no' => $no, 'status' => 'revoked', 'replaced' => true, 'issued_at' => null, 'revoked_at' => null, 'program_name' => $old['program_name'],
            'semester_no' => $old['semester_no'] !== null ? (int) $old['semester_no'] : null, 'session_name' => $old['session_name'], 'type_name' => $old['type_name'],
            'student_name' => trim($old['first_name'] . ' ' . ($old['last_name'] !== null && $old['last_name'] !== '' ? mb_substr($old['last_name'], 0, 1) . '.' : '')),
            'code_checked' => false, 'code_matches' => false, 'valid' => false];
    }
    $code = strtolower((string) preg_replace('/[^a-f0-9]/i', '', (string) $code));
    $codeOk = strlen($code) >= 12 && strlen($code) <= 32 && hash_equals(substr((string) $m['verification_code'], 0, strlen($code)), $code);
    $out = [
        'marksheet_no' => $m['marksheet_no'], 'status' => $m['status'], 'issued_at' => $m['issued_at'], 'revoked_at' => $m['revoked_at'],
        'program_name' => $m['program_name'], 'semester_no' => $m['semester_no'] !== null ? (int) $m['semester_no'] : null, 'session_name' => $m['session_name'],
        'type_name' => $m['type_name'],
        // Without the code only initials-style name: "Aditya S."
        'student_name' => $codeOk ? trim($m['first_name'] . ' ' . $m['last_name']) : trim($m['first_name'] . ' ' . ($m['last_name'] !== null && $m['last_name'] !== '' ? mb_substr($m['last_name'], 0, 1) . '.' : '')),
        'code_checked' => $code !== '', 'code_matches' => $codeOk, 'replaced' => false,
        'valid' => $m['status'] === 'issued' && $codeOk,
    ];
    if ($codeOk) {
        $out += ['student_uid' => $m['student_uid'], 'exam_name' => $m['exam_name'], 'sgpa' => $m['sgpa'] !== null ? (float) $m['sgpa'] : null,
            'cgpa' => $m['cgpa'] !== null ? (float) $m['cgpa'] : null, 'percentage' => $m['percentage'] !== null ? (float) $m['percentage'] : null, 'result_status' => $m['result_status'],
            'security_code' => marksheet_security_code((string) $m['verification_code'])];
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Admit cards
 * ------------------------------------------------------------------ */

function exam_admit_cards(array $e, ?array $studentIds = null): array
{
    $where = 'x.exam_id = ? AND x.is_eligible = 1';
    $args = [$e['id']];
    if ($studentIds !== null) {
        $where .= ' AND x.student_id IN (' . exam_in($studentIds) . ')';
    }
    $students = db_all("SELECT x.*, s.first_name, s.middle_name, s.last_name, s.student_uid, s.roll_no, s.enrollment_no, s.photo, s.father_name, s.gender, s.dob, sc.name AS section_name,
                               r.code AS room_code, r.name AS room_name
                        FROM exam_students x JOIN students s ON s.id = x.student_id LEFT JOIN sections sc ON sc.id = s.section_id LEFT JOIN classrooms r ON r.id = x.classroom_id
                        WHERE $where ORDER BY s.roll_no, s.student_uid", $args);
    $papers = db_all('SELECT s.exam_date, s.start_time, s.end_time, sb.code, sb.name, sb.type, r.code AS room_code FROM exam_schedules s JOIN subjects sb ON sb.id = s.subject_id
                      LEFT JOIN classrooms r ON r.id = s.classroom_id WHERE s.exam_id = ? ORDER BY s.exam_date, s.start_time', [$e['id']]);
    return ['students' => $students, 'papers' => $papers];
}
