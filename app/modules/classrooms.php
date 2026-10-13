<?php
/** Classrooms, labs and halls used by the timetable and examinations. */
require_once APP_ROOT . '/app/services/academics.php';

$types = ['classroom' => 'Classroom', 'lab' => 'Laboratory', 'seminar_hall' => 'Seminar Hall', 'auditorium' => 'Auditorium', 'exam_hall' => 'Examination Hall'];
$sid = (int) current_session_id();

return [
    'table' => 'classrooms',
    'title' => 'Classrooms',
    'singular' => 'Classroom',
    'permission' => 'academics',
    'icon' => 'door-open',
    'description' => 'Rooms, labs and halls with capacity and weekly utilisation.',
    'select' => "t.*, CONCAT(COALESCE(t.building, '—'), IF(t.floor IS NULL OR t.floor = '', '', CONCAT(' · Floor ', t.floor))) AS location,
                 (SELECT COUNT(*) FROM timetables tt WHERE tt.classroom_id = t.id AND tt.academic_session_id = $sid) AS periods_count,
                 (SELECT COUNT(*) FROM sections sc WHERE sc.classroom_id = t.id AND sc.academic_session_id = $sid) AS home_sections",
    'search' => ['t.name', 't.code', 't.building', 't.facilities'],
    'order' => 't.code ASC',
    'columns' => [
        ['key' => 'code', 'label' => 'Room', 'format' => 'title', 'sub' => 'name', 'sortable' => true, 'link' => 'view'],
        ['key' => 'location', 'label' => 'Building / Floor', 'format' => 'text', 'sortable' => 't.building'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['classroom' => 'blue', 'lab' => 'purple', 'seminar_hall' => 'cyan', 'auditorium' => 'navy', 'exam_hall' => 'amber']],
        ['key' => 'capacity', 'label' => 'Capacity', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'periods_count', 'label' => 'Periods / Week', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'utilisation', 'label' => 'Utilisation', 'format' => 'percent', 'align' => 'right'],
        ['key' => 'home_sections', 'label' => 'Home Sections', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'facilities', 'label' => 'Facilities', 'format' => 'tags', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => $types],
        ['key' => 'building', 'label' => 'Building', 'source' => ['table' => 'classrooms c', 'value' => 'DISTINCT c.building', 'label' => 'c.building', 'where' => 'c.building IS NOT NULL', 'order' => 'c.building']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'maintenance' => 'Under maintenance', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'code', 'label' => 'Room code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'A101'],
        ['name' => 'name', 'label' => 'Room name', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 80, 'placeholder' => 'Room A101 / Computer Lab 1'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'classroom', 'options' => $types, 'col' => 4],
        ['name' => 'capacity', 'label' => 'Seating capacity', 'type' => 'number', 'required' => true, 'default' => 60, 'min' => 1, 'max' => 2000, 'col' => 4],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'maintenance' => 'Under maintenance', 'inactive' => 'Inactive'], 'col' => 4],
        ['name' => 'building', 'label' => 'Building / block', 'type' => 'text', 'col' => 8, 'maxlength' => 80, 'placeholder' => 'Academic Block A'],
        ['name' => 'floor', 'label' => 'Floor', 'type' => 'text', 'col' => 4, 'maxlength' => 20, 'placeholder' => 'G / 1 / 2'],
        ['name' => 'facilities', 'label' => 'Facilities', 'type' => 'text', 'col' => 12, 'maxlength' => 255, 'placeholder' => 'Smart board, Projector, AC', 'help' => 'Comma separated.'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'maintenance', 'inactive']],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            static $slots = null;
            if ($slots === null) {
                $slots = (int) db_value("SELECT COUNT(*) FROM time_slots WHERE status = 'active' AND is_break = 0") * 6;
            }
            $r['utilisation'] = $slots ? round((int) ($r['periods_count'] ?? 0) / $slots * 100, 1) : 0;
            return $r;
        },
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id && $old && isset($data['status']) && $data['status'] !== 'active' && $old['status'] === 'active') {
                $n = (int) db_value('SELECT COUNT(*) FROM timetables WHERE classroom_id = ? AND academic_session_id = ?', [$id, (int) current_session_id()]);
                if ($n) {
                    return ['status' => "This room has $n timetable periods this session. Move them to another room first (Timetable → Room view)."];
                }
            }
            return [];
        },
        'before_delete' => function (int $id, array $row): ?string {
            $usage = acad_usage($id, [['timetables', 'classroom_id', 'timetable periods'], ['exam_schedules', 'classroom_id', 'exam schedules'], ['sections', 'classroom_id', 'home sections']]);
            return $usage ? $row['code'] . ' is used by ' . implode(', ', $usage) . ' and cannot be deleted. Mark it inactive instead.' : null;
        },
        'describe' => fn (array $r) => '"' . ($r['code'] ?? '') . ' ' . ($r['name'] ?? '') . '"',
    ],
];
