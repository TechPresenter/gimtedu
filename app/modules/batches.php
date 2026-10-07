<?php
/** Batches = admission cohorts of a program (e.g. BBA 2026-2029). */
require_once APP_ROOT . '/app/services/academics.php';

return [
    'table' => 'batches',
    'title' => 'Batches',
    'singular' => 'Batch',
    'permission' => 'academics',
    'icon' => 'users-round',
    'description' => 'Admission cohorts of each program.',
    'select' => "t.*, p.name AS program_name, p.short_name AS program_short, CONCAT(t.start_year, ' - ', t.end_year) AS years,
                 (SELECT COUNT(*) FROM students s WHERE s.batch_id = t.id AND s.status = 'active') AS students_count,
                 (SELECT COUNT(*) FROM sections sc WHERE sc.batch_id = t.id) AS sections_count",
    'joins' => 'JOIN programs p ON p.id = t.program_id',
    'search' => ['t.name', 'p.name', 'p.short_name'],
    'order' => 'p.sort_order ASC, t.start_year DESC',
    'columns' => [
        ['key' => 'name', 'label' => 'Batch', 'format' => 'title', 'sub' => 'program_name', 'sortable' => true, 'link' => 'view'],
        ['key' => 'program_short', 'label' => 'Program', 'format' => 'text', 'sortable' => 'p.short_name'],
        ['key' => 'years', 'label' => 'Years', 'format' => 'text', 'sortable' => 't.start_year'],
        ['key' => 'sections_count', 'label' => 'Sections', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'students_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'start_year', 'label' => 'Admission year', 'source' => ['table' => 'batches b', 'value' => 'DISTINCT b.start_year', 'label' => 'b.start_year', 'order' => 'b.start_year DESC']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'completed' => 'Completed']],
    ],
    'scopes' => ['program_id' => 't.program_id'],
    'fields' => [
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'source' => 'programs', 'col' => 12],
        ['name' => 'start_year', 'label' => 'Start year', 'type' => 'number', 'required' => true, 'min' => 2000, 'max' => 2100, 'col' => 4, 'default' => (int) date('Y')],
        ['name' => 'end_year', 'label' => 'End year', 'type' => 'number', 'min' => 2000, 'max' => 2110, 'col' => 4, 'help' => 'Auto-filled from the program duration when blank.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'completed' => 'Completed'], 'col' => 4],
        ['name' => 'name', 'label' => 'Batch name', 'type' => 'text', 'col' => 12, 'maxlength' => 60, 'placeholder' => 'BBA 2026-2029', 'help' => 'Leave blank to generate "PROGRAM START-END".'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'completed']],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $pid = (int) ($data['program_id'] ?? ($old['program_id'] ?? 0));
            $start = (int) ($data['start_year'] ?? ($old['start_year'] ?? 0));
            $end = (int) ($data['end_year'] ?? ($old['end_year'] ?? 0));
            if ($start && $end && $end <= $start && !($end === $start && $pid && (float) db_value('SELECT duration_years FROM programs WHERE id = ?', [$pid]) < 1)) {
                $errors['end_year'] = 'End year must be after the start year.';
            }
            if ($pid && $start && db_value('SELECT COUNT(*) FROM batches WHERE program_id = ? AND start_year = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$pid, $start, $id] : [$pid, $start])) {
                $errors['start_year'] = "A batch starting in $start already exists for this program.";
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $pid = (int) ($data['program_id'] ?? ($old['program_id'] ?? 0));
            $p = $pid ? db_row('SELECT short_name, duration_years FROM programs WHERE id = ?', [$pid]) : null;
            if (empty($data['end_year']) && !empty($data['start_year']) && $p) {
                $data['end_year'] = (int) $data['start_year'] + max(1, (int) ceil((float) $p['duration_years']));
            }
            if (array_key_exists('name', $data) && empty($data['name']) && $p) {
                $data['name'] = $p['short_name'] . ' ' . ($data['start_year'] ?? $old['start_year']) . '-' . ($data['end_year'] ?? $old['end_year']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['students', 'batch_id', 'students'], ['sections', 'batch_id', 'sections']]);
            return $usage ? $row['name'] . ' has ' . implode(', ', $usage) . ' and cannot be deleted. Mark it completed instead.' : null;
        },
    ],
];
