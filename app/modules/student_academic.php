<?php
/** Semester-wise academic history of students (promotion records). Embedded on the profile (scope student_id). */
require_once APP_ROOT . '/app/services/students.php';

return [
    'table' => 'student_academic',
    'title' => 'Academic History',
    'singular' => 'Academic Record',
    'permission' => 'students',
    'icon' => 'history',
    'description' => 'Semester-wise enrolment, promotion and performance records.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, p.short_name AS program_name, sc.name AS section_name,
                 ses.name AS session_name, CONCAT('Semester ', t.semester_no) AS semester_label, u.name AS promoted_by_name",
    'joins' => 'JOIN students s ON s.id = t.student_id
                JOIN programs p ON p.id = t.program_id
                LEFT JOIN sections sc ON sc.id = t.section_id
                LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id
                LEFT JOIN users u ON u.id = t.promoted_by',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 't.roll_no', 'p.short_name'],
    'order' => 't.semester_no DESC, t.id DESC',
    'columns' => [
        ['key' => 'semester_label', 'label' => 'Semester', 'format' => 'title', 'sub' => 'program_name', 'sortable' => 't.semester_no'],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'title', 'sub' => 'student_uid', 'sortable' => 's.first_name'],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'sortable' => 'ses.start_date'],
        ['key' => 'section_name', 'label' => 'Section', 'format' => 'text', 'align' => 'center'],
        ['key' => 'roll_no', 'label' => 'Roll No', 'format' => 'text'],
        ['key' => 'attendance_percent', 'label' => 'Attendance', 'format' => 'percent', 'sortable' => true],
        ['key' => 'sgpa', 'label' => 'SGPA', 'format' => 'text', 'align' => 'right', 'sortable' => true],
        ['key' => 'cgpa', 'label' => 'CGPA', 'format' => 'text', 'align' => 'right', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'promoted_at', 'label' => 'Closed on', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'promoted_by_name', 'label' => 'By', 'format' => 'text', 'hidden' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['studying' => 'Studying', 'promoted' => 'Promoted', 'detained' => 'Detained', 'completed' => 'Completed', 'dropped' => 'Dropped']],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
    ],
    'scopes' => ['student_id' => 't.student_id'],
    'fields' => [
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'source' => 'all_students', 'required' => true, 'col' => 12, 'readonly_on_edit' => true, 'form' => false],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'source' => 'programs', 'required' => true, 'col' => 6],
        ['name' => 'academic_session_id', 'label' => 'Session', 'type' => 'select', 'source' => 'sessions', 'required' => true, 'col' => 6],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12, 'col' => 4],
        ['name' => 'section_id', 'label' => 'Section', 'type' => 'select', 'source' => 'sections', 'depends' => ['program_id' => 'program_id', 'semester_no' => 'semester_no'], 'col' => 4],
        ['name' => 'roll_no', 'label' => 'Roll number', 'type' => 'text', 'maxlength' => 30, 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'promoted',
            'options' => ['studying' => 'Studying', 'promoted' => 'Promoted', 'detained' => 'Detained', 'completed' => 'Completed', 'dropped' => 'Dropped'], 'col' => 4],
        ['name' => 'sgpa', 'label' => 'SGPA', 'type' => 'decimal', 'min' => 0, 'max' => 10, 'step' => 0.01, 'col' => 4],
        ['name' => 'cgpa', 'label' => 'CGPA', 'type' => 'decimal', 'min' => 0, 'max' => 10, 'step' => 0.01, 'col' => 4],
        ['name' => 'attendance_percent', 'label' => 'Attendance %', 'type' => 'decimal', 'min' => 0, 'max' => 100, 'step' => 0.01, 'col' => 6],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 6],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            $get = fn (string $k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            if (!$get('student_id') || !db_value('SELECT COUNT(*) FROM students WHERE id = ?', [(int) $get('student_id')])) {
                return ['program_id' => 'Open the student profile to add an academic record.'];
            }
            $program = $get('program_id') ? students_program((int) $get('program_id')) : null;
            $sem = (int) $get('semester_no');
            if ($program && ($sem < 1 || $sem > (int) $program['total_semesters'])) {
                $errors['semester_no'] = $program['short_name'] . ' has ' . $program['total_semesters'] . ' semesters.';
            }
            if ($sec = $get('section_id')) {
                $s = db_row('SELECT program_id, semester_no FROM sections WHERE id = ?', [(int) $sec]);
                if (!$s || (int) $s['program_id'] !== (int) $get('program_id') || (int) $s['semester_no'] !== $sem) {
                    $errors['section_id'] = 'This section does not belong to the selected program and semester.';
                }
            }
            $dup = db_value('SELECT COUNT(*) FROM student_academic WHERE student_id = ? AND semester_no = ? AND academic_session_id <=> ?' . ($id ? ' AND id <> ' . (int) $id : ''),
                [(int) $get('student_id'), $sem, $get('academic_session_id')]);
            if ($dup) {
                $errors['semester_no'] = 'A record for this semester and session already exists.';
            }
            if ($get('status') === 'studying') {
                $other = db_value("SELECT COUNT(*) FROM student_academic WHERE student_id = ? AND status = 'studying'" . ($id ? ' AND id <> ' . (int) $id : ''), [(int) $get('student_id')]);
                if ($other) {
                    $errors['status'] = 'The student already has a current (studying) record. Close it before adding another.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $status = $data['status'] ?? $old['status'] ?? null;
            if ($status && $status !== 'studying' && empty($old['promoted_at']) && !isset($data['promoted_at'])) {
                $data['promoted_at'] = date('Y-m-d H:i:s');
                $data['promoted_by'] = user_id();
            }
            return $data;
        },
        'describe' => fn (array $row): string => 'semester ' . ($row['semester_no'] ?? '?') . ' record (student #' . ($row['student_id'] ?? '') . ')',
    ],
];
