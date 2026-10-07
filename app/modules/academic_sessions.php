<?php
/** Academic sessions (e.g. 2026-27). Exactly one session is "current" institute-wide. */
require_once APP_ROOT . '/app/services/academics.php';

$statuses = ['upcoming' => 'Upcoming', 'active' => 'Active', 'completed' => 'Completed'];

return [
    'table' => 'academic_sessions',
    'title' => 'Academic Sessions',
    'singular' => 'Academic Session',
    'permission' => 'academics',
    'icon' => 'calendar-range',
    'description' => 'Academic years, the current session and admission windows.',
    'select' => "t.*,
                 (SELECT COUNT(*) FROM sections sc WHERE sc.academic_session_id = t.id) AS sections_count,
                 (SELECT COUNT(*) FROM students s WHERE s.academic_session_id = t.id) AS admitted_count,
                 (SELECT COUNT(*) FROM timetables tt WHERE tt.academic_session_id = t.id) AS periods_count,
                 DATEDIFF(t.end_date, t.start_date) + 1 AS total_days,
                 GREATEST(0, LEAST(DATEDIFF(CURDATE(), t.start_date) + 1, DATEDIFF(t.end_date, t.start_date) + 1)) AS elapsed_days",
    'search' => ['t.name'],
    'order' => 't.start_date DESC',
    'default_sort' => ['key' => 'start_date', 'dir' => 'desc'],
    'columns' => [
        ['key' => 'name', 'label' => 'Session', 'format' => 'title', 'sub' => 'current_label', 'sortable' => true],
        ['key' => 'start_date', 'label' => 'Starts', 'format' => 'date', 'sortable' => true],
        ['key' => 'end_date', 'label' => 'Ends', 'format' => 'date', 'sortable' => true],
        ['key' => 'progress', 'label' => 'Progress', 'format' => 'percent', 'align' => 'right'],
        ['key' => 'is_current', 'label' => 'Current', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'admissions_open', 'label' => 'Admissions Open', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'sections_count', 'label' => 'Sections', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'admitted_count', 'label' => 'Students Admitted', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'periods_count', 'label' => 'Timetable Periods', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['upcoming' => 'blue', 'active' => 'green', 'completed' => 'slate']],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => $statuses],
        ['key' => 'admissions_open', 'label' => 'Admissions open', 'type' => 'boolean'],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Session name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'placeholder' => '2027-28',
            'help' => 'Format YYYY-YY, e.g. 2027-28.', 'rules' => ['regex:/^\d{4}-\d{2}$/']],
        ['name' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'end_date', 'label' => 'End date', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'upcoming', 'options' => $statuses, 'col' => 4],
        ['name' => 'is_current', 'label' => 'Current session', 'type' => 'toggle', 'default' => false, 'col' => 4, 'placeholder' => 'Set as current', 'help' => 'Only one session can be current.'],
        ['name' => 'admissions_open', 'label' => 'Admissions open', 'type' => 'toggle', 'default' => false, 'col' => 4, 'placeholder' => 'Accepting applications'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $total = max(1, (int) ($r['total_days'] ?? 1));
            $r['progress'] = round(min(100, max(0, (int) ($r['elapsed_days'] ?? 0) / $total * 100)), 1);
            $r['current_label'] = (int) $r['is_current'] ? 'Current session' : ((int) $r['admissions_open'] ? 'Admissions open' : '');
            return $r;
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $start = $data['start_date'] ?? ($old['start_date'] ?? null);
            $end = $data['end_date'] ?? ($old['end_date'] ?? null);
            if ($start && $end && $end <= $start) {
                $errors['end_date'] = 'End date must be after the start date.';
            }
            if ($start && $end && (strtotime($end) - strtotime($start)) > 400 * 86400) {
                $errors['end_date'] = 'A session cannot be longer than about 13 months.';
            }
            if ($start && $end) {
                $overlap = db_value('SELECT name FROM academic_sessions WHERE start_date <= ? AND end_date >= ?' . ($id ? ' AND id <> ?' : ''), $id ? [$end, $start, $id] : [$end, $start]);
                if ($overlap) {
                    $errors['start_date'] = "These dates overlap session $overlap.";
                }
            }
            if ($id && $old && (int) $old['is_current'] === 1 && array_key_exists('is_current', $data) && !(int) $data['is_current']) {
                $errors['is_current'] = 'There must always be one current session. Set another session as current instead.';
            }
            if (!empty($data['is_current']) && ($data['status'] ?? ($old['status'] ?? '')) === 'completed') {
                $errors['status'] = 'The current session cannot be marked completed.';
            }
            return $errors;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if (!empty($data['is_current']) && (!$old || !(int) $old['is_current'])) {
                db_exec('UPDATE academic_sessions SET is_current = 0 WHERE id <> ?', [$id]);
                log_activity('update', 'academics', $id, 'Set academic session ' . ($data['name'] ?? $old['name'] ?? '#' . $id) . ' as the current session');
            }
            // First session ever created becomes current automatically.
            if (!(int) db_value('SELECT COUNT(*) FROM academic_sessions WHERE is_current = 1')) {
                db_exec('UPDATE academic_sessions SET is_current = 1 WHERE id = ?', [$id]);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ((int) $row['is_current']) {
                return 'Session ' . $row['name'] . ' is the current session and cannot be deleted. Set another session as current first.';
            }
            $usage = acad_usage($id, [['sections', 'academic_session_id', 'sections'], ['timetables', 'academic_session_id', 'timetable periods'], ['students', 'academic_session_id', 'students'],
                ['admissions', 'academic_session_id', 'admissions'], ['fee_structures', 'academic_session_id', 'fee structures'], ['exams', 'academic_session_id', 'exams'], ['student_fees', 'academic_session_id', 'fee invoices']]);
            return $usage ? 'Session ' . $row['name'] . ' is used by ' . implode(', ', $usage) . ' and cannot be deleted. Mark it completed instead.' : null;
        },
        'describe' => fn (array $r) => '"' . ($r['name'] ?? '') . '"',
    ],
];
