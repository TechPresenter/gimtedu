<?php
/** Subjects / papers of each program semester, with credits and marks scheme. */
require_once APP_ROOT . '/app/services/academics.php';

$types = ['theory' => 'Theory', 'practical' => 'Practical', 'lab' => 'Lab', 'project' => 'Project / Internship'];
$sid = (int) current_session_id();

return [
    'table' => 'subjects',
    'title' => 'Subjects',
    'singular' => 'Subject',
    'permission' => 'academics',
    'icon' => 'book-open',
    'description' => 'Subjects with credits, marks distribution and faculty coverage.',
    'select' => "t.*, p.short_name AS program_short, p.name AS program_name, c.name AS course_name,
                 CONCAT(p.short_name, ' · Sem ', t.semester_no) AS program_sem,
                 (t.max_internal + t.max_external + t.max_practical) AS max_total,
                 (SELECT COUNT(DISTINCT fs.faculty_id) FROM faculty_subjects fs WHERE fs.subject_id = t.id AND (fs.academic_session_id = $sid OR fs.academic_session_id IS NULL)) AS faculty_count,
                 (SELECT GROUP_CONCAT(DISTINCT TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) ORDER BY f.first_name SEPARATOR ', ')
                    FROM faculty_subjects fs JOIN faculty f ON f.id = fs.faculty_id WHERE fs.subject_id = t.id AND (fs.academic_session_id = $sid OR fs.academic_session_id IS NULL)) AS faculty_names",
    'joins' => 'JOIN programs p ON p.id = t.program_id LEFT JOIN courses c ON c.id = t.course_id',
    'search' => ['t.name', 't.code', 'p.short_name', 'p.name'],
    'order' => 'p.sort_order ASC, t.semester_no ASC, t.code ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Subject', 'format' => 'title', 'sub' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'program_sem', 'label' => 'Program / Semester', 'format' => 'title', 'sub' => 'course_name', 'sortable' => "CONCAT(p.short_name, LPAD(t.semester_no, 2, '0'))"],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['theory' => 'blue', 'practical' => 'cyan', 'lab' => 'purple', 'project' => 'amber']],
        ['key' => 'is_elective', 'label' => 'Elective', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'credits', 'label' => 'Credits', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'marks_scheme', 'label' => 'Int / Ext / Prac', 'format' => 'text', 'align' => 'center'],
        ['key' => 'pass_marks', 'label' => 'Pass', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'hours_per_week', 'label' => 'Hrs/Week', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'faculty_names', 'label' => 'Faculty', 'format' => 'truncate', 'truncate' => 40],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Subject'], ['key' => 'program_short', 'label' => 'Program'], ['key' => 'semester_no', 'label' => 'Semester'],
        ['key' => 'course_name', 'label' => 'Specialization'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'is_elective', 'label' => 'Elective', 'format' => 'boolean'],
        ['key' => 'credits', 'label' => 'Credits'], ['key' => 'hours_per_week', 'label' => 'Hours/Week'], ['key' => 'max_internal', 'label' => 'Internal Max'],
        ['key' => 'max_external', 'label' => 'External Max'], ['key' => 'max_practical', 'label' => 'Practical Max'], ['key' => 'pass_marks', 'label' => 'Pass Marks'],
        ['key' => 'faculty_names', 'label' => 'Faculty'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => array_combine(range(1, 12), array_map(fn ($n) => 'Semester ' . $n, range(1, 12)))],
        ['key' => 'type', 'label' => 'Type', 'options' => $types],
        ['key' => 'is_elective', 'label' => 'Elective', 'type' => 'boolean'],
        ['key' => 'faculty', 'label' => 'Faculty', 'sql_map' => [
            'assigned' => "EXISTS (SELECT 1 FROM faculty_subjects fs WHERE fs.subject_id = t.id AND (fs.academic_session_id = $sid OR fs.academic_session_id IS NULL))",
            'unassigned' => "NOT EXISTS (SELECT 1 FROM faculty_subjects fs WHERE fs.subject_id = t.id AND (fs.academic_session_id = $sid OR fs.academic_session_id IS NULL))",
        ], 'options' => ['assigned' => 'Faculty assigned', 'unassigned' => 'No faculty']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['program_id' => 't.program_id', 'semester_no' => 't.semester_no'],
    'fields' => [
        ['type' => 'section', 'label' => 'Subject'],
        ['name' => 'code', 'label' => 'Subject code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 30, 'rules' => 'alpha_dash', 'placeholder' => 'BBA101'],
        ['name' => 'name', 'label' => 'Subject name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'Principles of Management'],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'source' => 'programs', 'col' => 6],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12, 'col' => 3],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'theory', 'options' => $types, 'col' => 3],
        ['name' => 'course_id', 'label' => 'Specialization', 'type' => 'select', 'source' => 'courses', 'depends' => ['program_id' => 'program_id'], 'col' => 6,
            'help' => 'Only for specialization-specific subjects.'],
        ['name' => 'is_elective', 'label' => 'Elective subject', 'type' => 'toggle', 'default' => false, 'col' => 3, 'placeholder' => 'Elective'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 3],
        ['type' => 'section', 'label' => 'Credits & marks'],
        ['name' => 'credits', 'label' => 'Credits', 'type' => 'decimal', 'required' => true, 'default' => 4, 'min' => 0, 'max' => 30, 'step' => 0.5, 'col' => 3],
        ['name' => 'hours_per_week', 'label' => 'Hours / week', 'type' => 'number', 'default' => 4, 'min' => 0, 'max' => 20, 'col' => 3, 'help' => 'Periods to schedule weekly.'],
        ['name' => 'max_internal', 'label' => 'Internal max', 'type' => 'number', 'required' => true, 'default' => 30, 'min' => 0, 'max' => 500, 'col' => 3],
        ['name' => 'max_external', 'label' => 'External max', 'type' => 'number', 'required' => true, 'default' => 70, 'min' => 0, 'max' => 500, 'col' => 3],
        ['name' => 'max_practical', 'label' => 'Practical max', 'type' => 'number', 'required' => true, 'default' => 0, 'min' => 0, 'max' => 500, 'col' => 3],
        ['name' => 'pass_marks', 'label' => 'Pass marks', 'type' => 'number', 'required' => true, 'default' => 40, 'min' => 0, 'max' => 1500, 'col' => 3],
        ['name' => 'description', 'label' => 'Syllabus outline', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 5000],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $r['marks_scheme'] = (int) $r['max_internal'] . ' / ' . (int) $r['max_external'] . ' / ' . (int) $r['max_practical'];
            $r['credits'] = rtrim(rtrim((string) $r['credits'], '0'), '.');
            return $r;
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $pid = (int) ($data['program_id'] ?? ($old['program_id'] ?? 0));
            $sem = (int) ($data['semester_no'] ?? ($old['semester_no'] ?? 0));
            if ($pid && $sem) {
                $total = (int) db_value('SELECT total_semesters FROM programs WHERE id = ?', [$pid]);
                if ($total && $sem > $total) {
                    $errors['semester_no'] = "This program has only $total semester" . ($total === 1 ? '' : 's') . '.';
                }
            }
            $course = $data['course_id'] ?? ($old['course_id'] ?? null);
            if ($course && $pid && (int) db_value('SELECT program_id FROM courses WHERE id = ?', [$course]) !== $pid) {
                $errors['course_id'] = 'This specialization belongs to another program.';
            }
            $int = (int) ($data['max_internal'] ?? ($old['max_internal'] ?? 0));
            $ext = (int) ($data['max_external'] ?? ($old['max_external'] ?? 0));
            $prac = (int) ($data['max_practical'] ?? ($old['max_practical'] ?? 0));
            $pass = (int) ($data['pass_marks'] ?? ($old['pass_marks'] ?? 0));
            if ($int + $ext + $prac <= 0) {
                $errors['max_external'] = 'Total marks (internal + external + practical) must be more than zero.';
            } elseif ($pass > $int + $ext + $prac) {
                $errors['pass_marks'] = 'Pass marks cannot exceed the total of ' . ($int + $ext + $prac) . '.';
            }
            if ($id && $old && $pid && ($pid !== (int) $old['program_id'] || $sem !== (int) $old['semester_no'])) {
                $tt = (int) db_value('SELECT COUNT(*) FROM timetables WHERE subject_id = ?', [$id]);
                if ($tt) {
                    $errors['semester_no'] = "This subject is scheduled in $tt timetable periods. Remove them before moving it to another program/semester.";
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            // Keep the program's semester rows in place for new semesters.
            if (!empty($data['program_id']) && !empty($data['semester_no'])) {
                $total = (int) db_value('SELECT total_semesters FROM programs WHERE id = ?', [(int) $data['program_id']]);
                acad_sync_semesters((int) $data['program_id'], min($total, max((int) $data['semester_no'], 1)));
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['exam_marks', 'subject_id', 'marks entries'], ['exam_subjects', 'subject_id', 'exam papers'], ['exam_schedules', 'subject_id', 'exam schedules'],
                ['attendance', 'subject_id', 'attendance sheets'], ['timetables', 'subject_id', 'timetable periods']]);
            return $usage ? $row['code'] . ' ' . $row['name'] . ' has ' . implode(', ', $usage) . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
        'describe' => fn (array $r) => '"' . ($r['code'] ?? '') . ' ' . ($r['name'] ?? '') . '"',
    ],
];
