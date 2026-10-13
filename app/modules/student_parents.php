<?php
/** Parents & guardians of a student (embedded on the student profile, scope student_id). */
require_once APP_ROOT . '/app/services/students.php';

return [
    'table' => 'student_parents',
    'title' => 'Parents & Guardians',
    'singular' => 'Parent / Guardian',
    'permission' => 'students',
    'icon' => 'users',
    'description' => 'Father, mother and local guardian contacts.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid",
    'joins' => 'JOIN students s ON s.id = t.student_id',
    'search' => ['t.name', 't.phone', 't.email', 's.first_name', 's.last_name', 's.student_uid'],
    'order' => "FIELD(t.relation, 'father', 'mother', 'guardian', 'other'), t.id",
    'columns' => [
        ['key' => 'name', 'label' => 'Name', 'format' => 'title', 'sub' => 'occupation', 'sortable' => true],
        ['key' => 'relation', 'label' => 'Relation', 'format' => 'badge', 'sortable' => true, 'colors' => ['father' => 'blue', 'mother' => 'purple', 'guardian' => 'amber', 'other' => 'slate']],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'title', 'sub' => 'student_uid', 'sortable' => 's.first_name'],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone'],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email'],
        ['key' => 'annual_income', 'label' => 'Annual income', 'format' => 'money', 'hidden' => true, 'sortable' => true],
        ['key' => 'is_emergency_contact', 'label' => 'Emergency', 'format' => 'boolean'],
    ],
    'filters' => [
        ['key' => 'relation', 'label' => 'Relation', 'options' => ['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian', 'other' => 'Other']],
    ],
    'scopes' => ['student_id' => 't.student_id'],
    'fields' => [
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'source' => 'all_students', 'required' => true, 'col' => 12, 'readonly_on_edit' => true, 'form' => false],
        ['name' => 'relation', 'label' => 'Relation', 'type' => 'select', 'required' => true, 'options' => ['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian', 'other' => 'Other'], 'col' => 4],
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 8],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'col' => 6],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'col' => 6],
        ['name' => 'occupation', 'label' => 'Occupation', 'type' => 'text', 'maxlength' => 100, 'col' => 6],
        ['name' => 'annual_income', 'label' => 'Annual income', 'type' => 'money', 'min' => 0, 'col' => 6],
        ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
        ['name' => 'is_emergency_contact', 'label' => 'Emergency contact', 'type' => 'toggle', 'col' => 12, 'placeholder' => 'Call this person in an emergency'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            $studentId = (int) ($data['student_id'] ?? $old['student_id'] ?? 0);
            if (!$studentId || !db_value('SELECT COUNT(*) FROM students WHERE id = ?', [$studentId])) {
                return ['name' => 'Open the student profile to add a parent or guardian.'];
            }
            $relation = $data['relation'] ?? $old['relation'] ?? null;
            if ($studentId && in_array($relation, ['father', 'mother'], true)) {
                $dup = db_value('SELECT COUNT(*) FROM student_parents WHERE student_id = ? AND relation = ?' . ($id ? ' AND id <> ' . (int) $id : ''), [$studentId, $relation]);
                if ($dup) {
                    $errors['relation'] = 'A ' . $relation . ' is already recorded for this student. Edit the existing record instead.';
                }
            }
            return $errors;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            $studentId = (int) ($data['student_id'] ?? $old['student_id']);
            if (!empty($data['is_emergency_contact'])) {
                db_exec('UPDATE student_parents SET is_emergency_contact = 0 WHERE student_id = ? AND id <> ?', [$studentId, $id]);
                $p = db_row('SELECT name, phone FROM student_parents WHERE id = ?', [$id]);
                if ($p && $p['phone']) {
                    db_update('students', ['emergency_contact_name' => $p['name'], 'emergency_contact_phone' => $p['phone']], 'id = ?', [$studentId]);
                }
            }
            students_refresh_parent_columns($studentId);
        },
        'after_delete' => function (int $id, array $row): void {
            students_refresh_parent_columns((int) $row['student_id']);
        },
        'describe' => fn (array $row): string => '"' . ($row['name'] ?? '') . '" as ' . ($row['relation'] ?? 'contact') . ' (student #' . ($row['student_id'] ?? '') . ')',
    ],
];
