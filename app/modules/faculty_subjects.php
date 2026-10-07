<?php
/** Faculty assignments: faculty x subject x section x session (who teaches what to whom). */
require_once APP_ROOT . '/app/services/academics.php';

$sid = (int) current_session_id();

return [
    'table' => 'faculty_subjects',
    'title' => 'Faculty Assignments',
    'singular' => 'Assignment',
    'permission' => 'academics',
    'icon' => 'user-check',
    'description' => 'Subjects assigned to faculty members for each section and session.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS faculty_name, f.photo AS faculty_photo, f.designation, f.employee_id,
                 d.code AS department_code, sb.code AS subject_code, sb.name AS subject_name, sb.type AS subject_type, sb.credits,
                 COALESCE(sb.hours_per_week, IF(sb.type IN ('lab','project','practical'), 2, 4)) AS hours,
                 p.short_name AS program_short, sb.semester_no, CONCAT(p.short_name, ' · Sem ', sb.semester_no, IFNULL(CONCAT(' · Sec ', sc.name), ' · All sections')) AS class_label,
                 s.name AS session_name,
                 (SELECT COUNT(*) FROM students st WHERE st.section_id = t.section_id AND st.status = 'active') AS students_count,
                 (SELECT COUNT(*) FROM timetables tt WHERE tt.faculty_id = t.faculty_id AND tt.subject_id = t.subject_id AND tt.section_id <=> t.section_id
                     AND tt.academic_session_id <=> t.academic_session_id) AS scheduled_periods",
    'joins' => 'JOIN faculty f ON f.id = t.faculty_id LEFT JOIN departments d ON d.id = f.department_id JOIN subjects sb ON sb.id = t.subject_id
                JOIN programs p ON p.id = sb.program_id LEFT JOIN sections sc ON sc.id = t.section_id LEFT JOIN academic_sessions s ON s.id = t.academic_session_id',
    'search' => ['f.first_name', 'f.last_name', 'f.employee_id', 'sb.code', 'sb.name', 'p.short_name'],
    'order' => 'p.sort_order ASC, sb.semester_no ASC, sc.name ASC, sb.code ASC',
    'columns' => [
        ['key' => 'faculty_name', 'label' => 'Faculty', 'format' => 'person', 'image' => 'faculty_photo', 'sub' => 'designation', 'sortable' => 'f.first_name'],
        ['key' => 'subject_name', 'label' => 'Subject', 'format' => 'title', 'sub' => 'subject_code', 'sortable' => 'sb.code'],
        ['key' => 'class_label', 'label' => 'Class', 'format' => 'text', 'sortable' => "CONCAT(p.short_name, LPAD(sb.semester_no, 2, '0'), COALESCE(sc.name, ''))"],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'hours', 'label' => 'Hrs/Week', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'scheduled_periods', 'label' => 'Timetabled', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'department_code', 'label' => 'Dept', 'format' => 'code', 'sortable' => 'd.code', 'hidden' => true],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'sortable' => 's.start_date', 'hidden' => true],
        ['key' => 'is_primary', 'label' => 'Primary', 'format' => 'boolean', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'faculty_name', 'label' => 'Faculty'], ['key' => 'employee_id', 'label' => 'Employee ID'], ['key' => 'department_code', 'label' => 'Department'],
        ['key' => 'subject_code', 'label' => 'Subject Code'], ['key' => 'subject_name', 'label' => 'Subject'], ['key' => 'class_label', 'label' => 'Class'],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number'], ['key' => 'hours', 'label' => 'Hours/Week', 'format' => 'number'],
        ['key' => 'scheduled_periods', 'label' => 'Timetabled Periods', 'format' => 'number'], ['key' => 'session_name', 'label' => 'Session'],
        ['key' => 'is_primary', 'label' => 'Primary', 'format' => 'boolean'],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'default' => $sid ?: null],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments', 'column' => 'f.department_id'],
        ['key' => 'faculty_id', 'label' => 'Faculty', 'source' => 'faculty'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 'sb.program_id'],
        ['key' => 'semester_no', 'label' => 'Semester', 'column' => 'sb.semester_no', 'options' => array_combine(range(1, 12), array_map(fn ($n) => 'Semester ' . $n, range(1, 12)))],
        ['key' => 'section_id', 'label' => 'Section', 'source' => 'sections', 'depends' => ['program_id' => 'program_id']],
        ['key' => 'is_primary', 'label' => 'Primary', 'type' => 'boolean'],
    ],
    'scopes' => ['faculty_id' => 't.faculty_id', 'section_id' => 't.section_id', 'subject_id' => 't.subject_id'],
    'fields' => [
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'required' => true, 'source' => 'sessions', 'default' => $sid ?: null, 'col' => 4],
        ['name' => 'section_id', 'label' => 'Section', 'type' => 'combobox', 'required' => true, 'source' => 'sections', 'depends' => ['academic_session_id' => 'academic_session_id'], 'col' => 8],
        ['name' => 'subject_id', 'label' => 'Subject', 'type' => 'select', 'required' => true, 'depends' => ['section_id' => 'section_id'], 'col' => 12,
            'source' => ['table' => 'subjects sb JOIN sections sx ON sx.program_id = sb.program_id AND sx.semester_no = sb.semester_no', 'value' => 'sb.id',
                'label' => "CONCAT(sb.code, ' — ', sb.name)", 'sub' => "CONCAT(sb.type, ' · ', sb.credits, ' credits')", 'where' => "sb.status = 'active'", 'order' => 'sb.code',
                'search' => ['sb.code', 'sb.name'], 'filters' => ['section_id' => 'sx.id']]],
        ['name' => 'faculty_id', 'label' => 'Faculty member', 'type' => 'combobox', 'required' => true, 'source' => 'faculty', 'col' => 8],
        ['name' => 'is_primary', 'label' => 'Primary teacher', 'type' => 'toggle', 'default' => true, 'col' => 4, 'placeholder' => 'Primary'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $sectionId = (int) ($data['section_id'] ?? ($old['section_id'] ?? 0));
            $subjectId = (int) ($data['subject_id'] ?? ($old['subject_id'] ?? 0));
            $facultyId = (int) ($data['faculty_id'] ?? ($old['faculty_id'] ?? 0));
            $sessionId = $data['academic_session_id'] ?? ($old['academic_session_id'] ?? null);
            $sec = $sectionId ? db_row('SELECT program_id, semester_no, academic_session_id FROM sections WHERE id = ?', [$sectionId]) : null;
            $sub = $subjectId ? db_row('SELECT program_id, semester_no, code FROM subjects WHERE id = ?', [$subjectId]) : null;
            if ($sec && $sub && ((int) $sec['program_id'] !== (int) $sub['program_id'] || (int) $sec['semester_no'] !== (int) $sub['semester_no'])) {
                $errors['subject_id'] = $sub['code'] . ' is not taught in this section\'s program/semester.';
            }
            if ($sec && $sessionId && $sec['academic_session_id'] && (int) $sec['academic_session_id'] !== (int) $sessionId) {
                $errors['section_id'] = 'This section belongs to another academic session.';
            }
            if ($facultyId) {
                $st = db_value('SELECT status FROM faculty WHERE id = ?', [$facultyId]);
                if ($st && !in_array($st, ['active', 'on_leave'], true)) {
                    $errors['faculty_id'] = 'Only active faculty members can be assigned.';
                }
            }
            if ($facultyId && $subjectId && db_value('SELECT COUNT(*) FROM faculty_subjects WHERE faculty_id = ? AND subject_id = ? AND section_id <=> ? AND academic_session_id <=> ?' . ($id ? ' AND id <> ?' : ''),
                $id ? [$facultyId, $subjectId, $sectionId ?: null, $sessionId, $id] : [$facultyId, $subjectId, $sectionId ?: null, $sessionId])) {
                $errors['faculty_id'] = 'This faculty member is already assigned to this subject for the section.';
            }
            if ($id && $old && $facultyId !== (int) $old['faculty_id']) {
                $n = (int) db_value('SELECT COUNT(*) FROM timetables WHERE faculty_id = ? AND subject_id = ? AND section_id <=> ?', [(int) $old['faculty_id'], (int) $old['subject_id'], $old['section_id']]);
                if ($n) {
                    $errors["faculty_id"] = "The current teacher has $n timetable period" . ($n === 1 ? "" : "s") . " for this subject. Reassign them in the timetable first, or add a new assignment.";
                }
            }
            return $errors;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if (!empty($data['is_primary'])) {
                $row = db_row('SELECT subject_id, section_id, academic_session_id FROM faculty_subjects WHERE id = ?', [$id]);
                if ($row) {
                    db_exec('UPDATE faculty_subjects SET is_primary = 0 WHERE id <> ? AND subject_id = ? AND section_id <=> ? AND academic_session_id <=> ?',
                        [$id, $row['subject_id'], $row['section_id'], $row['academic_session_id']]);
                }
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM timetables WHERE faculty_id = ? AND subject_id = ? AND section_id <=> ? AND academic_session_id <=> ?',
                [$row['faculty_id'], $row['subject_id'], $row['section_id'], $row['academic_session_id']]);
            return $n ? "This assignment is used by $n timetable period" . ($n === 1 ? "" : "s") . ". Reassign or remove " . ($n === 1 ? "it" : "those periods") . " first." : null;
        },
        'summary' => function (string $from, array $args): array {
            $row = db_row('SELECT COUNT(*) AS assignments, COUNT(DISTINCT t.faculty_id) AS faculty, COUNT(DISTINCT t.subject_id) AS subjects,
                                  COALESCE(SUM(COALESCE(sb.hours_per_week, IF(sb.type IN (\'lab\',\'project\',\'practical\'), 2, 4))), 0) AS hours' . $from, $args);
            return array_map('intval', $row ?? []);
        },
        'describe' => function (array $r): string {
            $f = db_value("SELECT TRIM(CONCAT_WS(' ', title, first_name, last_name)) FROM faculty WHERE id = ?", [(int) ($r['faculty_id'] ?? 0)]);
            $s = db_value('SELECT code FROM subjects WHERE id = ?', [(int) ($r['subject_id'] ?? 0)]);
            return "$s to $f";
        },
    ],
];
