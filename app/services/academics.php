<?php
/**
 * Academics domain logic shared by the CRUD modules (app/modules/*), the academics API routes and other units.
 *
 * Hierarchy: Department -> Program -> Course (specialization) -> Semester -> Subject -> Faculty (faculty_subjects) -> Students
 *
 *   acad_section_label_sql('sc', 'p')       SQL expression "BBA · Sem 1 · Sec A"
 *   acad_set_current_session(4)             make exactly one session current
 *   acad_overview($sessionId)               KPI counts + department/program tree
 *   acad_program_tree($programId, $sid)     semesters -> subjects -> faculty -> students for one program
 *   acad_workload($sid, $filters)           faculty teaching load
 *   acad_unassigned($sid, $filters)         (section, subject) pairs without a faculty member
 *   acad_sync_semesters($programId, $n)     make sure semester rows 1..n exist
 */

/** SQL expression for a section label, e.g. "BBA · Sem 3 · Sec A". */
function acad_section_label_sql(string $sc = 'sc', string $p = 'p'): string
{
    return "CONCAT($p.short_name, ' · Sem ', $sc.semester_no, ' · Sec ', $sc.name)";
}

/** SQL expression for a person's display name (faculty), e.g. "Dr. Anita Sharma". */
function acad_faculty_name_sql(string $f = 'f'): string
{
    return "TRIM(CONCAT_WS(' ', $f.title, $f.first_name, $f.last_name))";
}

function acad_section_label(array $row): string
{
    return trim(($row['program_short'] ?? $row['short_name'] ?? '') . ' · Sem ' . ($row['semester_no'] ?? '') . ' · Sec ' . ($row['name'] ?? $row['section_name'] ?? ''));
}

/** Session id from the request (?session_id=) falling back to the admin's selected/current session. */
function acad_request_session_id(): ?int
{
    $sid = (int) ($_GET['session_id'] ?? 0);
    if ($sid && db_value('SELECT id FROM academic_sessions WHERE id = ?', [$sid])) {
        return $sid;
    }
    return current_session_id();
}

/** Make one academic session the institute-wide current session (exactly one is current). */
function acad_set_current_session(int $id): array
{
    $row = db_row('SELECT * FROM academic_sessions WHERE id = ?', [$id]);
    if (!$row) {
        throw new CrudException('Academic session not found. It may have been deleted.');
    }
    db_transaction(function () use ($id) {
        db_exec('UPDATE academic_sessions SET is_current = 0 WHERE id <> ? AND is_current = 1', [$id]);
        db_exec("UPDATE academic_sessions SET is_current = 1, status = 'active' WHERE id = ?", [$id]);
    });
    log_activity('update', 'academics', $id, 'Set academic session ' . $row['name'] . ' as the current session');
    notify('perm:academics', 'system', 'Academic session changed', 'Session ' . $row['name'] . ' is now the current academic session.', 'admin/academics?tab=sessions', 'calendar-range');
    return db_row('SELECT * FROM academic_sessions WHERE id = ?', [$id]) ?? $row;
}

/** Ensure semester rows 1..$total exist for a program (keeps existing rows and ids). */
function acad_sync_semesters(int $programId, int $total): int
{
    $have = array_map('intval', db_column('SELECT number FROM semesters WHERE program_id = ?', [$programId]));
    $added = 0;
    for ($n = 1; $n <= $total; $n++) {
        if (!in_array($n, $have, true)) {
            db_insert('semesters', ['program_id' => $programId, 'number' => $n, 'name' => 'Semester ' . $n, 'status' => 'active']);
            $added++;
        }
    }
    return $added;
}

/** Count references to a record in other tables: [['table', 'column', 'label'], ...] -> "3 students, 2 sections" */
function acad_usage(int $id, array $refs): array
{
    $out = [];
    foreach ($refs as [$table, $column, $label]) {
        if (!db_table_exists($table)) {
            continue;
        }
        $n = (int) db_value('SELECT COUNT(*) FROM ' . db_quote_ident($table) . ' WHERE ' . db_quote_ident($column) . ' = ?', [$id]);
        if ($n > 0) {
            $out[] = number_in($n) . ' ' . ($n === 1 ? rtrim($label, 's') : $label);
        }
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Overview & hierarchy
 * ------------------------------------------------------------------ */

function acad_overview(?int $sessionId): array
{
    $sid = $sessionId ?: 0;
    $kpis = [
        'departments' => (int) db_value("SELECT COUNT(*) FROM departments WHERE status = 'active'"),
        'programs' => (int) db_value("SELECT COUNT(*) FROM programs WHERE status = 'active'"),
        'courses' => (int) db_value("SELECT COUNT(*) FROM courses WHERE status = 'active'"),
        'subjects' => (int) db_value("SELECT COUNT(*) FROM subjects WHERE status = 'active'"),
        'electives' => (int) db_value("SELECT COUNT(*) FROM subjects WHERE status = 'active' AND is_elective = 1"),
        'sections' => (int) db_value("SELECT COUNT(*) FROM sections WHERE status = 'active' AND academic_session_id = ?", [$sid]),
        'batches' => (int) db_value("SELECT COUNT(*) FROM batches WHERE status = 'active'"),
        'faculty' => (int) db_value("SELECT COUNT(*) FROM faculty WHERE status IN ('active','on_leave')"),
        'students' => (int) db_value("SELECT COUNT(*) FROM students WHERE status = 'active'"),
        'classrooms' => (int) db_value("SELECT COUNT(*) FROM classrooms WHERE status = 'active'"),
        'assignments' => (int) db_value('SELECT COUNT(*) FROM faculty_subjects WHERE academic_session_id = ?', [$sid]),
        'unassigned' => count(acad_unassigned($sid, [], 5000)),
        'timetabled_sections' => (int) db_value('SELECT COUNT(DISTINCT section_id) FROM timetables WHERE academic_session_id = ?', [$sid]),
    ];
    $departments = db_all(
        "SELECT d.id, d.name, d.code, d.status, " . acad_faculty_name_sql('f') . " AS hod_name,
                (SELECT COUNT(*) FROM faculty fc WHERE fc.department_id = d.id AND fc.status IN ('active','on_leave')) AS faculty_count,
                (SELECT COUNT(*) FROM students s WHERE s.department_id = d.id AND s.status = 'active') AS students_count
         FROM departments d LEFT JOIN faculty f ON f.id = d.hod_faculty_id
         ORDER BY d.status = 'active' DESC, d.name"
    );
    $programs = db_all(
        "SELECT p.id, p.department_id, p.name, p.short_name, p.code, p.level, p.category, p.duration_label, p.duration_years, p.total_semesters,
                p.intake_capacity, p.status, p.is_featured,
                (SELECT COUNT(*) FROM students s WHERE s.program_id = p.id AND s.status = 'active') AS students_count,
                (SELECT COUNT(*) FROM subjects sb WHERE sb.program_id = p.id AND sb.status = 'active') AS subjects_count,
                (SELECT COUNT(*) FROM sections sc WHERE sc.program_id = p.id AND sc.academic_session_id = ? AND sc.status = 'active') AS sections_count
         FROM programs p ORDER BY p.sort_order, p.name",
        [$sid]
    );
    $courses = db_all(
        "SELECT c.id, c.program_id, c.name, c.code, c.status,
                (SELECT COUNT(*) FROM students s WHERE s.course_id = c.id AND s.status = 'active') AS students_count
         FROM courses c ORDER BY c.name"
    );
    $byProgram = [];
    foreach ($courses as $c) {
        $byProgram[(int) $c['program_id']][] = ['id' => (int) $c['id'], 'name' => $c['name'], 'code' => $c['code'], 'status' => $c['status'], 'students' => (int) $c['students_count']];
    }
    $byDept = [];
    foreach ($programs as $p) {
        $byDept[(int) $p['department_id']][] = [
            'id' => (int) $p['id'], 'name' => $p['name'], 'short_name' => $p['short_name'], 'code' => $p['code'], 'level' => $p['level'], 'category' => $p['category'],
            'duration' => $p['duration_label'] ?: ((float) $p['duration_years'] . ' Years'), 'total_semesters' => (int) $p['total_semesters'],
            'intake' => (int) $p['intake_capacity'], 'status' => $p['status'], 'featured' => (bool) $p['is_featured'],
            'students' => (int) $p['students_count'], 'subjects' => (int) $p['subjects_count'], 'sections' => (int) $p['sections_count'],
            'courses' => $byProgram[(int) $p['id']] ?? [],
        ];
    }
    $tree = [];
    foreach ($departments as $d) {
        $progs = $byDept[(int) $d['id']] ?? [];
        $tree[] = [
            'id' => (int) $d['id'], 'name' => $d['name'], 'code' => $d['code'], 'status' => $d['status'], 'hod_name' => $d['hod_name'] ?: null,
            'faculty' => (int) $d['faculty_count'], 'students' => (int) $d['students_count'], 'programs' => $progs,
            'subjects' => array_sum(array_column($progs, 'subjects')), 'sections' => array_sum(array_column($progs, 'sections')),
        ];
    }
    $levels = db_pairs("SELECT level, COUNT(*) FROM programs WHERE status = 'active' GROUP BY level ORDER BY FIELD(level, 'UG', 'PG', 'Diploma', 'Certificate', 'PhD')");
    $types = db_pairs("SELECT type, COUNT(*) FROM subjects WHERE status = 'active' GROUP BY type ORDER BY COUNT(*) DESC");
    return [
        'session' => $sid ? db_row('SELECT id, name, start_date, end_date, is_current, admissions_open, status FROM academic_sessions WHERE id = ?', [$sid]) : null,
        'kpis' => $kpis,
        'tree' => $tree,
        'levels' => array_map('intval', $levels),
        'subject_types' => array_map('intval', $types),
    ];
}

/** Semesters -> subjects -> faculty (per section) -> students for one program in a session. */
function acad_program_tree(int $programId, ?int $sessionId): ?array
{
    $p = db_row('SELECT p.*, d.name AS department_name FROM programs p JOIN departments d ON d.id = p.department_id WHERE p.id = ?', [$programId]);
    if (!$p) {
        return null;
    }
    $sid = $sessionId ?: 0;
    $semRows = db_all('SELECT id, number, name, start_date, end_date, status FROM semesters WHERE program_id = ? ORDER BY number', [$programId]);
    $subjects = db_all(
        'SELECT sb.id, sb.code, sb.name, sb.semester_no, sb.type, sb.is_elective, sb.credits, sb.hours_per_week, sb.status, c.name AS course_name
         FROM subjects sb LEFT JOIN courses c ON c.id = sb.course_id WHERE sb.program_id = ? ORDER BY sb.semester_no, sb.code',
        [$programId]
    );
    $sections = db_all(
        "SELECT sc.id, sc.name, sc.semester_no, sc.capacity, " . acad_faculty_name_sql('f') . " AS class_teacher, r.code AS room_code,
                (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') AS students
         FROM sections sc LEFT JOIN faculty f ON f.id = sc.class_teacher_id LEFT JOIN classrooms r ON r.id = sc.classroom_id
         WHERE sc.program_id = ? AND sc.academic_session_id = ? ORDER BY sc.semester_no, sc.name",
        [$programId, $sid]
    );
    $assign = db_all(
        "SELECT fs.subject_id, fs.section_id, fs.is_primary, f.id AS faculty_id, " . acad_faculty_name_sql('f') . " AS faculty_name, f.photo, f.designation, sc.name AS section_name
         FROM faculty_subjects fs JOIN faculty f ON f.id = fs.faculty_id JOIN subjects sb ON sb.id = fs.subject_id
         LEFT JOIN sections sc ON sc.id = fs.section_id
         WHERE sb.program_id = ? AND (fs.academic_session_id = ? OR fs.academic_session_id IS NULL)
         ORDER BY sc.name, f.first_name",
        [$programId, $sid]
    );
    $secById = [];
    foreach ($sections as $s) {
        $secById[(int) $s['id']] = $s;
    }
    $assignBySubject = [];
    foreach ($assign as $a) {
        $sec = $a['section_id'] ? ($secById[(int) $a['section_id']] ?? null) : null;
        $assignBySubject[(int) $a['subject_id']][] = [
            'faculty_id' => (int) $a['faculty_id'], 'name' => $a['faculty_name'], 'photo' => $a['photo'], 'designation' => $a['designation'],
            'section_id' => $a['section_id'] ? (int) $a['section_id'] : null, 'section' => $a['section_name'], 'students' => $sec ? (int) $sec['students'] : null,
            'primary' => (bool) $a['is_primary'],
        ];
    }
    // students per semester (active, enrolled in this program)
    $studentsBySem = array_map('intval', db_pairs("SELECT current_semester, COUNT(*) FROM students WHERE program_id = ? AND status = 'active' GROUP BY current_semester", [$programId]));
    $semesters = [];
    $numbers = array_unique(array_merge(array_map(fn ($s) => (int) $s['number'], $semRows), range(1, max(1, (int) $p['total_semesters']))));
    sort($numbers);
    foreach ($numbers as $n) {
        $meta = null;
        foreach ($semRows as $r) {
            if ((int) $r['number'] === $n) {
                $meta = $r;
            }
        }
        $subs = array_values(array_filter($subjects, fn ($s) => (int) $s['semester_no'] === $n));
        $secs = array_values(array_filter($sections, fn ($s) => (int) $s['semester_no'] === $n));
        $semesters[] = [
            'number' => $n, 'name' => $meta['name'] ?? ('Semester ' . $n), 'id' => $meta ? (int) $meta['id'] : null, 'status' => $meta['status'] ?? 'active',
            'credits' => array_sum(array_map(fn ($s) => (float) $s['credits'], $subs)),
            'students' => $studentsBySem[$n] ?? 0,
            'sections' => array_map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'students' => (int) $s['students'], 'capacity' => (int) $s['capacity'],
                'class_teacher' => $s['class_teacher'] ?: null, 'room' => $s['room_code']], $secs),
            'subjects' => array_map(function ($s) use ($assignBySubject, $secs) {
                $fac = $assignBySubject[(int) $s['id']] ?? [];
                $covered = array_unique(array_filter(array_column($fac, 'section_id')));
                return [
                    'id' => (int) $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'type' => $s['type'], 'elective' => (bool) $s['is_elective'],
                    'credits' => (float) $s['credits'], 'hours' => (int) $s['hours_per_week'], 'course' => $s['course_name'], 'status' => $s['status'],
                    'faculty' => $fac, 'missing_sections' => count($secs) - count(array_intersect($covered, array_map(fn ($x) => (int) $x['id'], $secs))),
                ];
            }, $subs),
        ];
    }
    return [
        'program' => ['id' => (int) $p['id'], 'name' => $p['name'], 'short_name' => $p['short_name'], 'department' => $p['department_name'], 'total_semesters' => (int) $p['total_semesters']],
        'semesters' => $semesters,
    ];
}

/* ------------------------------------------------------------------
 * Faculty workload & coverage
 * ------------------------------------------------------------------ */

/** Teaching load per faculty member for a session. Filters: department_id, q */
function acad_workload(?int $sessionId, array $filters = []): array
{
    $sid = $sessionId ?: 0;
    $where = ["f.status IN ('active','on_leave')"];
    $args = [$sid, $sid];
    if (!empty($filters['department_id'])) {
        $where[] = 'f.department_id = ?';
        $args[] = (int) $filters['department_id'];
    }
    if (!empty($filters['q'])) {
        $where[] = "(f.first_name LIKE ? OR f.last_name LIKE ? OR f.employee_id LIKE ?)";
        $q = '%' . $filters['q'] . '%';
        array_push($args, $q, $q, $q);
    }
    $rows = db_all(
        "SELECT f.id, " . acad_faculty_name_sql('f') . " AS name, f.employee_id, f.designation, f.photo, f.status, d.code AS department_code, d.name AS department_name,
                COUNT(fs.id) AS assignments,
                COUNT(DISTINCT fs.subject_id) AS subjects,
                COUNT(DISTINCT fs.section_id) AS sections,
                COALESCE(SUM(CASE WHEN sb.id IS NULL THEN 0 ELSE COALESCE(sb.hours_per_week, CASE WHEN sb.type IN ('lab','project','practical') THEN 2 ELSE 4 END) END), 0) AS planned_hours,
                (SELECT COUNT(*) FROM timetables t WHERE t.faculty_id = f.id AND t.academic_session_id = ?) AS scheduled_periods
         FROM faculty f
         LEFT JOIN departments d ON d.id = f.department_id
         LEFT JOIN faculty_subjects fs ON fs.faculty_id = f.id AND fs.academic_session_id = ?
         LEFT JOIN subjects sb ON sb.id = fs.subject_id
         WHERE " . implode(' AND ', $where) . "
         GROUP BY f.id, f.title, f.first_name, f.last_name, f.employee_id, f.designation, f.photo, f.status, d.code, d.name
         ORDER BY planned_hours DESC, f.first_name",
        $args
    );
    $max = (int) setting('max_teaching_hours', 24);
    return array_map(fn ($r) => [
        'id' => (int) $r['id'], 'name' => $r['name'], 'employee_id' => $r['employee_id'], 'designation' => $r['designation'], 'photo' => $r['photo'],
        'status' => $r['status'], 'department_code' => $r['department_code'], 'department_name' => $r['department_name'],
        'assignments' => (int) $r['assignments'], 'subjects' => (int) $r['subjects'], 'sections' => (int) $r['sections'],
        'planned_hours' => (int) $r['planned_hours'], 'scheduled_periods' => (int) $r['scheduled_periods'], 'max_hours' => $max,
        'load' => (int) $r['planned_hours'] > $max ? 'overloaded' : ((int) $r['planned_hours'] === 0 ? 'free' : ((int) $r['planned_hours'] < $max / 2 ? 'light' : 'normal')),
    ], $rows);
}

/** (section, subject) pairs of a session with no faculty assigned. Filters: program_id, department_id */
function acad_unassigned(?int $sessionId, array $filters = [], int $limit = 200): array
{
    $sid = $sessionId ?: 0;
    $where = ["sc.academic_session_id = ?", "sc.status = 'active'", "sb.status = 'active'"];
    $args = [$sid];
    if (!empty($filters['program_id'])) {
        $where[] = 'sc.program_id = ?';
        $args[] = (int) $filters['program_id'];
    }
    if (!empty($filters['department_id'])) {
        $where[] = 'p.department_id = ?';
        $args[] = (int) $filters['department_id'];
    }
    $args[] = $sid;
    return db_all(
        "SELECT sc.id AS section_id, " . acad_section_label_sql('sc', 'p') . " AS section_label, sb.id AS subject_id, sb.code AS subject_code, sb.name AS subject_name,
                sb.type AS subject_type, p.department_id
         FROM sections sc
         JOIN programs p ON p.id = sc.program_id
         JOIN subjects sb ON sb.program_id = sc.program_id AND sb.semester_no = sc.semester_no
         WHERE " . implode(' AND ', $where) . "
           AND NOT EXISTS (SELECT 1 FROM faculty_subjects fs WHERE fs.subject_id = sb.id AND fs.section_id = sc.id AND (fs.academic_session_id = ? OR fs.academic_session_id IS NULL))
         ORDER BY p.sort_order, sc.semester_no, sc.name, sb.code
         LIMIT " . max(1, min(5000, $limit)),
        $args
    );
}
