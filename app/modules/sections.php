<?php
/** Sections (class groups) of a program semester in an academic session. */
require_once APP_ROOT . '/app/services/academics.php';

return [
    'table' => 'sections',
    'title' => 'Sections',
    'singular' => 'Section',
    'permission' => 'academics',
    'icon' => 'users',
    'description' => 'Class sections with strength, class teacher and home room.',
    'select' => "t.*, p.name AS program_name, p.short_name AS program_short, p.department_id, CONCAT(p.short_name, ' · Sem ', t.semester_no, ' · Sec ', t.name) AS label,
                 s.name AS session_name, b.name AS batch_name, r.code AS room_code, r.name AS room_name,
                 TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS class_teacher, f.photo AS class_teacher_photo,
                 (SELECT COUNT(*) FROM students st WHERE st.section_id = t.id AND st.status = 'active') AS strength,
                 (SELECT COUNT(*) FROM timetables tt WHERE tt.section_id = t.id AND tt.academic_session_id = t.academic_session_id) AS periods_count,
                 (SELECT COUNT(*) FROM timetables tt WHERE tt.section_id = t.id AND tt.academic_session_id = t.academic_session_id AND tt.status = 'published') AS published_count",
    'joins' => 'JOIN programs p ON p.id = t.program_id LEFT JOIN academic_sessions s ON s.id = t.academic_session_id LEFT JOIN batches b ON b.id = t.batch_id
                LEFT JOIN classrooms r ON r.id = t.classroom_id LEFT JOIN faculty f ON f.id = t.class_teacher_id',
    'search' => ['p.short_name', 'p.name', 't.name', 'b.name', 'f.first_name', 'f.last_name', 'r.code'],
    'order' => 'p.sort_order ASC, t.semester_no ASC, t.name ASC',
    'columns' => [
        ['key' => 'label', 'label' => 'Section', 'format' => 'title', 'sub' => 'batch_name', 'sortable' => "CONCAT(p.short_name, LPAD(t.semester_no, 2, '0'), t.name)", 'link' => 'view'],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'sortable' => 's.start_date'],
        ['key' => 'strength_label', 'label' => 'Strength', 'format' => 'text', 'align' => 'center', 'sortable' => 'strength'],
        ['key' => 'class_teacher', 'label' => 'Class Teacher', 'format' => 'person', 'image' => 'class_teacher_photo', 'sortable' => 'f.first_name'],
        ['key' => 'room_code', 'label' => 'Home Room', 'format' => 'code', 'sortable' => 'r.code'],
        ['key' => 'timetable_status', 'label' => 'Timetable', 'format' => 'badge', 'colors' => ['published' => 'green', 'draft' => 'amber', 'partial' => 'blue', 'not_created' => 'slate']],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'default' => current_session_id()],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => array_combine(range(1, 12), array_map(fn ($n) => 'Semester ' . $n, range(1, 12)))],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments', 'column' => 'p.department_id'],
        ['key' => 'timetable', 'label' => 'Timetable', 'sql_map' => [
            'published' => "EXISTS (SELECT 1 FROM timetables tt WHERE tt.section_id = t.id AND tt.status = 'published')",
            'draft' => "EXISTS (SELECT 1 FROM timetables tt WHERE tt.section_id = t.id) AND NOT EXISTS (SELECT 1 FROM timetables tt WHERE tt.section_id = t.id AND tt.status = 'published')",
            'not_created' => 'NOT EXISTS (SELECT 1 FROM timetables tt WHERE tt.section_id = t.id)',
        ], 'options' => ['published' => 'Published', 'draft' => 'Draft', 'not_created' => 'Not created']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['program_id' => 't.program_id', 'batch_id' => 't.batch_id', 'academic_session_id' => 't.academic_session_id'],
    'fields' => [
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'source' => 'programs', 'col' => 8],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12, 'col' => 4],
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'required' => true, 'source' => 'sessions', 'default' => current_session_id(), 'col' => 4],
        ['name' => 'name', 'label' => 'Section name', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 20, 'placeholder' => 'A'],
        ['name' => 'capacity', 'label' => 'Capacity', 'type' => 'number', 'required' => true, 'default' => 60, 'min' => 1, 'max' => 500, 'col' => 4],
        ['name' => 'batch_id', 'label' => 'Batch', 'type' => 'select', 'source' => 'batches', 'depends' => ['program_id' => 'program_id'], 'col' => 6],
        ['name' => 'classroom_id', 'label' => 'Home classroom', 'type' => 'combobox', 'source' => 'classrooms', 'col' => 6],
        ['name' => 'class_teacher_id', 'label' => 'Class teacher', 'type' => 'combobox', 'source' => 'faculty', 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 6],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $r['strength_label'] = (int) $r['strength'] . ' / ' . (int) $r['capacity'];
            $n = (int) ($r['periods_count'] ?? 0);
            $pub = (int) ($r['published_count'] ?? 0);
            $r['timetable_status'] = $n === 0 ? 'not_created' : ($pub === $n ? 'published' : ($pub === 0 ? 'draft' : 'partial'));
            return $r;
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $pid = (int) ($data['program_id'] ?? ($old['program_id'] ?? 0));
            $sem = (int) ($data['semester_no'] ?? ($old['semester_no'] ?? 0));
            $sid = $data['academic_session_id'] ?? ($old['academic_session_id'] ?? null);
            $name = strtoupper(trim((string) ($data['name'] ?? ($old['name'] ?? ''))));
            if ($pid && $sem) {
                $total = (int) db_value('SELECT total_semesters FROM programs WHERE id = ?', [$pid]);
                if ($total && $sem > $total) {
                    $errors['semester_no'] = "This program has only $total semester" . ($total === 1 ? '' : 's') . '.';
                }
            }
            if ($pid && $sem && $name !== '' && db_value('SELECT COUNT(*) FROM sections WHERE program_id = ? AND semester_no = ? AND academic_session_id <=> ? AND name = ?' . ($id ? ' AND id <> ?' : ''),
                $id ? [$pid, $sem, $sid, $name, $id] : [$pid, $sem, $sid, $name])) {
                $errors['name'] = "Section $name already exists for this program, semester and session.";
            }
            $batch = $data['batch_id'] ?? ($old['batch_id'] ?? null);
            if ($batch && $pid && (int) db_value('SELECT program_id FROM batches WHERE id = ?', [$batch]) !== $pid) {
                $errors['batch_id'] = 'This batch belongs to another program.';
            }
            if ($id && isset($data['capacity'])) {
                $strength = (int) db_value("SELECT COUNT(*) FROM students WHERE section_id = ? AND status = 'active'", [$id]);
                if ((int) $data['capacity'] < $strength) {
                    $errors['capacity'] = "Capacity cannot be less than the current strength ($strength students).";
                }
            }
            if ($id && $old && ($pid !== (int) $old['program_id'] || $sem !== (int) $old['semester_no'])) {
                $tt = (int) db_value('SELECT COUNT(*) FROM timetables WHERE section_id = ?', [$id]);
                if ($tt) {
                    $errors['semester_no'] = "This section has $tt timetable periods. Clear its timetable before changing the program or semester.";
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (isset($data['name'])) {
                $data['name'] = strtoupper(trim($data['name']));
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['students', 'section_id', 'students'], ['timetables', 'section_id', 'timetable periods'], ['attendance', 'section_id', 'attendance sheets']]);
            return $usage ? 'This section has ' . implode(', ', $usage) . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
        'describe' => function (array $r): string {
            $p = db_value('SELECT short_name FROM programs WHERE id = ?', [(int) ($r['program_id'] ?? 0)]);
            return '"' . $p . ' · Sem ' . ($r['semester_no'] ?? '') . ' · Sec ' . ($r['name'] ?? '') . '"';
        },
    ],
];
