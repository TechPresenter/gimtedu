<?php
/** Courses = specializations / tracks inside a program (e.g. MBA - Finance). */
require_once APP_ROOT . '/app/services/academics.php';

return [
    'table' => 'courses',
    'title' => 'Courses / Specializations',
    'singular' => 'Course',
    'permission' => 'academics',
    'icon' => 'book-marked',
    'description' => 'Specializations and tracks offered within programs.',
    'select' => "t.*, p.name AS program_name, p.short_name AS program_short, d.name AS department_name,
                 (SELECT COUNT(*) FROM students s WHERE s.course_id = t.id AND s.status = 'active') AS students_count,
                 (SELECT COUNT(*) FROM subjects sb WHERE sb.course_id = t.id) AS subjects_count",
    'joins' => 'JOIN programs p ON p.id = t.program_id JOIN departments d ON d.id = p.department_id',
    'search' => ['t.name', 't.code', 'p.name', 'p.short_name'],
    'order' => 'p.sort_order ASC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Specialization', 'format' => 'title', 'sub' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'program_short', 'label' => 'Program', 'format' => 'title', 'sub' => 'department_name', 'sortable' => 'p.short_name'],
        ['key' => 'intake_capacity', 'label' => 'Intake', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'subjects_count', 'label' => 'Specialization Subjects', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'description', 'label' => 'Description', 'format' => 'truncate', 'truncate' => 60, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments', 'column' => 'p.department_id'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['program_id' => 't.program_id'],
    'fields' => [
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'source' => 'programs', 'col' => 12],
        ['name' => 'name', 'label' => 'Specialization name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'MBA - Finance'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'MBA-FIN'],
        ['name' => 'intake_capacity', 'label' => 'Intake (seats)', 'type' => 'number', 'min' => 1, 'max' => 2000, 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 6],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $pid = (int) ($data['program_id'] ?? ($old['program_id'] ?? 0));
            $name = $data['name'] ?? null;
            if ($pid && $name && db_value('SELECT COUNT(*) FROM courses WHERE program_id = ? AND name = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$pid, $name, $id] : [$pid, $name])) {
                return ['name' => 'This program already has a specialization with this name.'];
            }
            if ($id && $old && $pid !== (int) $old['program_id'] && (int) db_value("SELECT COUNT(*) FROM students WHERE course_id = ?", [$id])) {
                return ['program_id' => 'Students are enrolled in this specialization; it cannot be moved to another program.'];
            }
            return [];
        },
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['students', 'course_id', 'students'], ['admissions', 'course_id', 'admissions'], ['subjects', 'course_id', 'subjects']]);
            return $usage ? $row['name'] . ' has ' . implode(', ', $usage) . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
    ],
];
