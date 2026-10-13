<?php
/** Daily time slots (periods and breaks) used by the weekly timetable grid. */
require_once APP_ROOT . '/app/services/academics.php';

$sid = (int) current_session_id();

return [
    'table' => 'time_slots',
    'title' => 'Time Slots',
    'singular' => 'Time Slot',
    'permission' => 'academics',
    'icon' => 'clock',
    'description' => 'Periods and breaks of the teaching day.',
    'select' => "t.*, CONCAT(TIME_FORMAT(t.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(t.end_time, '%h:%i %p')) AS time_range,
                 TIMESTAMPDIFF(MINUTE, t.start_time, t.end_time) AS duration,
                 (SELECT COUNT(*) FROM timetables tt WHERE tt.time_slot_id = t.id AND tt.academic_session_id = $sid) AS periods_count",
    'search' => ['t.name'],
    'order' => 't.sort_order ASC, t.start_time ASC',
    'per_page' => 50,
    'columns' => [
        ['key' => 'name', 'label' => 'Slot', 'format' => 'title', 'sub' => 'time_range', 'sortable' => true, 'link' => 'view'],
        ['key' => 'start_time', 'label' => 'Starts', 'format' => 'time', 'sortable' => true],
        ['key' => 'end_time', 'label' => 'Ends', 'format' => 'time', 'sortable' => true],
        ['key' => 'duration', 'label' => 'Minutes', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'is_break', 'label' => 'Break', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'periods_count', 'label' => 'Classes / Week', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'is_break', 'label' => 'Break', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 40, 'placeholder' => 'Period 1 / Lunch Break'],
        ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 99, 'col' => 4],
        ['name' => 'start_time', 'label' => 'Start time', 'type' => 'time', 'required' => true, 'col' => 6],
        ['name' => 'end_time', 'label' => 'End time', 'type' => 'time', 'required' => true, 'col' => 6],
        ['name' => 'is_break', 'label' => 'Break / recess', 'type' => 'toggle', 'default' => false, 'col' => 6, 'placeholder' => 'No classes in this slot'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 6],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $start = isset($data['start_time']) ? substr((string) $data['start_time'], 0, 5) : ($old ? substr($old['start_time'], 0, 5) : null);
            $end = isset($data['end_time']) ? substr((string) $data['end_time'], 0, 5) : ($old ? substr($old['end_time'], 0, 5) : null);
            if ($start && $end && $end <= $start) {
                $errors['end_time'] = 'End time must be after the start time.';
            }
            $status = $data['status'] ?? ($old['status'] ?? 'active');
            if (!$errors && $start && $end && $status === 'active') {
                $clash = db_value("SELECT name FROM time_slots WHERE status = 'active' AND start_time < ? AND end_time > ?" . ($id ? ' AND id <> ?' : ''), $id ? [$end . ':00', $start . ':00', $id] : [$end . ':00', $start . ':00']);
                if ($clash) {
                    $errors['start_time'] = "This slot overlaps $clash.";
                }
            }
            if ($id && $old) {
                $used = (int) db_value('SELECT COUNT(*) FROM timetables WHERE time_slot_id = ?', [$id]);
                if ($used && !empty($data['is_break']) && !(int) $old['is_break']) {
                    $errors['is_break'] = "$used classes are scheduled in this slot. Move them before turning it into a break.";
                }
                if ($used && $status !== 'active' && $old['status'] === 'active') {
                    $errors['status'] = "$used classes are scheduled in this slot. Move them before deactivating it.";
                }
            }
            return $errors;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['timetables', 'time_slot_id', 'timetable periods']]);
            return $usage ? $row['name'] . ' has ' . implode(', ', $usage) . ' and cannot be deleted. Deactivate it instead.' : null;
        },
    ],
];
