<?php
/** Exam types (Mid Term, End Semester, Practical ...). Final types produce SGPA/CGPA results. */
return [
    'table' => 'exam_types',
    'title' => 'Exam Types',
    'singular' => 'Exam Type',
    'permission' => 'examination',
    'icon' => 'layers',
    'description' => 'Kinds of examinations, their weightage and whether they produce SGPA results.',
    'select' => 't.*, (SELECT COUNT(*) FROM exams e WHERE e.exam_type_id = t.id) AS exams_count',
    'search' => ['t.name', 't.code', 't.description'],
    'order' => 't.is_final DESC, t.id ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Exam type', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'code', 'label' => 'Code', 'format' => 'code', 'sortable' => true],
        ['key' => 'weightage', 'label' => 'Weightage', 'format' => 'percent', 'align' => 'right', 'sortable' => true],
        ['key' => 'is_final', 'label' => 'Final (SGPA)', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'exams_count', 'label' => 'Exams', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'is_final', 'label' => 'Final exam', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 80, 'placeholder' => 'e.g. Mid Term Examination'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'MID'],
        ['name' => 'weightage', 'label' => 'Weightage', 'type' => 'decimal', 'min' => 0, 'max' => 100, 'step' => 0.01, 'suffix' => '%', 'help' => 'Contribution to the final assessment (optional).'],
        ['name' => 'is_final', 'label' => 'Final examination', 'type' => 'toggle', 'default' => false, 'placeholder' => 'Produces SGPA / CGPA, backlogs and marksheets'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM exams WHERE exam_type_id = ?', [$id]);
            return $n ? $row['name'] . " is used by $n exam" . ($n === 1 ? '' : 's') . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
    ],
];
