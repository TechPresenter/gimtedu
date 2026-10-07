<?php
/** Semesters of each program (Semester 1..N) with optional dates for a session. */
require_once APP_ROOT . '/app/services/academics.php';

return [
    'table' => 'semesters',
    'title' => 'Semesters',
    'singular' => 'Semester',
    'permission' => 'academics',
    'icon' => 'layers',
    'description' => 'Program semesters, their dates and academic load.',
    'select' => "t.*, p.name AS program_name, p.short_name AS program_short, p.total_semesters, s.name AS session_name,
                 CONCAT(p.short_name, ' · ', t.name) AS display_name,
                 (SELECT COUNT(*) FROM subjects sb WHERE sb.program_id = t.program_id AND sb.semester_no = t.number AND sb.status = 'active') AS subjects_count,
                 (SELECT COALESCE(SUM(sb.credits), 0) FROM subjects sb WHERE sb.program_id = t.program_id AND sb.semester_no = t.number AND sb.status = 'active') AS credits_total,
                 (SELECT COUNT(*) FROM sections sc WHERE sc.program_id = t.program_id AND sc.semester_no = t.number AND sc.academic_session_id = " . (int) current_session_id() . ") AS sections_count,
                 (SELECT COUNT(*) FROM students st WHERE st.program_id = t.program_id AND st.current_semester = t.number AND st.status = 'active') AS students_count",
    'joins' => 'JOIN programs p ON p.id = t.program_id LEFT JOIN academic_sessions s ON s.id = t.academic_session_id',
    'search' => ['t.name', 'p.name', 'p.short_name'],
    'order' => 'p.sort_order ASC, t.number ASC',
    'columns' => [
        ['key' => 'display_name', 'label' => 'Semester', 'format' => 'title', 'sub' => 'program_name', 'sortable' => 'p.short_name', 'link' => 'view'],
        ['key' => 'number', 'label' => 'No.', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'subjects_count', 'label' => 'Subjects', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'credits_total', 'label' => 'Credits', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'sections_count', 'label' => 'Sections (current)', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'hidden' => true],
        ['key' => 'start_date', 'label' => 'Starts', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'end_date', 'label' => 'Ends', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'number', 'label' => 'Semester', 'options' => array_combine(range(1, 12), array_map(fn ($n) => 'Semester ' . $n, range(1, 12)))],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['program_id' => 't.program_id'],
    'fields' => [
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'source' => 'programs', 'col' => 8, 'readonly_on_edit' => true],
        ['name' => 'number', 'label' => 'Semester no.', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12, 'col' => 4, 'readonly_on_edit' => true],
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 60, 'placeholder' => 'Semester 1'],
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'source' => 'sessions', 'col' => 6],
        ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'col' => 4],
        ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 4],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => false,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $pid = (int) ($data['program_id'] ?? ($old['program_id'] ?? 0));
            $num = (int) ($data['number'] ?? ($old['number'] ?? 0));
            if ($pid && $num) {
                $total = (int) db_value('SELECT total_semesters FROM programs WHERE id = ?', [$pid]);
                if ($total && $num > $total) {
                    $errors['number'] = "This program has only $total semester" . ($total === 1 ? '' : 's') . '. Increase the program\'s total semesters first.';
                } elseif (!$id && db_value('SELECT COUNT(*) FROM semesters WHERE program_id = ? AND number = ?', [$pid, $num])) {
                    $errors['number'] = "Semester $num already exists for this program.";
                }
            }
            $start = $data['start_date'] ?? ($old['start_date'] ?? null);
            $end = $data['end_date'] ?? ($old['end_date'] ?? null);
            if ($start && $end && $end <= $start) {
                $errors['end_date'] = 'End date must be after the start date.';
            }
            return $errors;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $subjects = (int) db_value('SELECT COUNT(*) FROM subjects WHERE program_id = ? AND semester_no = ?', [$row['program_id'], $row['number']]);
            $sections = (int) db_value('SELECT COUNT(*) FROM sections WHERE program_id = ? AND semester_no = ?', [$row['program_id'], $row['number']]);
            if ($subjects || $sections) {
                return $row['name'] . ' has ' . implode(', ', array_filter([$subjects ? "$subjects subjects" : '', $sections ? "$sections sections" : ''])) . ' and cannot be deleted. Mark it inactive instead.';
            }
            return null;
        },
        'describe' => fn (array $r) => '"' . ($r['name'] ?? '') . '" of program #' . ($r['program_id'] ?? ''),
    ],
];
