<?php
/** Departments (Academics). */
require_once APP_ROOT . '/app/services/academics.php';

return [
    'table' => 'departments',
    'title' => 'Departments',
    'singular' => 'Department',
    'permission' => 'academics',
    'icon' => 'building-2',
    'description' => 'Academic departments and their heads.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)) AS hod_name, f.photo AS hod_photo, f.designation AS hod_designation,
                 (SELECT COUNT(*) FROM programs p WHERE p.department_id = t.id) AS programs_count,
                 (SELECT COUNT(*) FROM subjects sb JOIN programs p2 ON p2.id = sb.program_id WHERE p2.department_id = t.id AND sb.status = 'active') AS subjects_count,
                 (SELECT COUNT(*) FROM students s WHERE s.department_id = t.id AND s.status = 'active') AS students_count,
                 (SELECT COUNT(*) FROM faculty fc WHERE fc.department_id = t.id AND fc.status = 'active') AS faculty_count",
    'joins' => 'LEFT JOIN faculty f ON f.id = t.hod_faculty_id',
    'search' => ['t.name', 't.code', 't.email', 'f.first_name', 'f.last_name'],
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Department', 'format' => 'title', 'sub' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'hod_name', 'label' => 'Head of Department', 'format' => 'person', 'image' => 'hod_photo', 'sub' => 'hod_designation', 'sortable' => 'hod_name'],
        ['key' => 'programs_count', 'label' => 'Programs', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'subjects_count', 'label' => 'Subjects', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'faculty_count', 'label' => 'Faculty', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'established_year', 'label' => 'Established', 'format' => 'text', 'hidden' => true, 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'has_hod', 'label' => 'Head of department', 'sql_map' => ['assigned' => 't.hod_faculty_id IS NOT NULL', 'vacant' => 't.hod_faculty_id IS NULL'],
            'options' => ['assigned' => 'HOD assigned', 'vacant' => 'HOD vacant']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Department name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 8, 'maxlength' => 150, 'placeholder' => 'e.g. Department of Management Studies'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'MGT'],
        ['name' => 'hod_faculty_id', 'label' => 'Head of Department', 'type' => 'combobox', 'source' => 'faculty', 'placeholder' => 'Search faculty…'],
        ['name' => 'established_year', 'label' => 'Established year', 'type' => 'number', 'min' => 1900, 'max' => 2100],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'image', 'label' => 'Cover image', 'type' => 'image', 'folder' => 'departments'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['programs', 'department_id', 'programs'], ['faculty', 'department_id', 'faculty members'], ['students', 'department_id', 'students']]);
            return $usage ? $row['name'] . ' has ' . implode(', ', $usage) . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
    ],
];
