<?php
/** Hostel blocks / buildings with their floors. */
require_once APP_ROOT . '/app/services/hostel.php';

return [
    'table' => 'hostel_blocks',
    'title' => 'Hostel Blocks',
    'singular' => 'Block',
    'permission' => 'hostel',
    'icon' => 'building-2',
    'description' => 'Buildings / blocks inside each hostel and their floors.',
    'select' => "t.*, h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type,
                 (SELECT COUNT(*) FROM hostel_rooms r WHERE r.block_id = t.id) AS rooms_count,
                 (SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.block_id = t.id) AS beds_count,
                 (SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.block_id = t.id AND hb.status = 'occupied') AS occupied_count",
    'joins' => 'JOIN hostels h ON h.id = t.hostel_id',
    'search' => ['t.name', 'h.name', 'h.code', 't.description'],
    'order' => 'h.name, t.name',
    'columns' => [
        ['key' => 'name', 'label' => 'Block', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'hostel_name', 'label' => 'Hostel', 'format' => 'text', 'sortable' => 'h.name'],
        ['key' => 'floors', 'label' => 'Floors', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'rooms_count', 'label' => 'Rooms', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'beds_count', 'label' => 'Beds', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'occupied_count', 'label' => 'Occupied', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'hostel_id', 'label' => 'Hostel', 'source' => 'hostels'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'hostel_id', 'label' => 'Hostel', 'type' => 'select', 'required' => true, 'source' => 'hostels', 'col' => 6],
        ['name' => 'name', 'label' => 'Block / building name', 'type' => 'text', 'required' => true, 'maxlength' => 60, 'col' => 6, 'placeholder' => 'e.g. Block A'],
        ['name' => 'floors', 'label' => 'Number of floors', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 30, 'default' => 4, 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 6],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255, 'placeholder' => 'e.g. First-year wing, near the mess'],
    ],
    'scopes' => ['hostel_id' => 't.hostel_id'],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $hostelId = (int) ($data['hostel_id'] ?? ($old['hostel_id'] ?? 0));
            $name = $data['name'] ?? ($old['name'] ?? null);
            if ($hostelId && $name !== null) {
                $dup = (int) db_value('SELECT COUNT(*) FROM hostel_blocks WHERE hostel_id = ? AND name = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$hostelId, $name, $id] : [$hostelId, $name]);
                if ($dup) {
                    $errors['name'] = 'This hostel already has a block named "' . $name . '".';
                }
            }
            if ($id && isset($data['floors'])) {
                // floors are numbered 0 (ground) .. floors - 1, the same rule rooms and "Add rooms in bulk" use
                $maxFloor = db_value('SELECT MAX(floor_no) FROM hostel_rooms WHERE block_id = ?', [$id]);
                if ($maxFloor !== null && (int) $maxFloor >= (int) $data['floors']) {
                    $errors['floors'] = 'Rooms exist up to the ' . hostel_floor_label($maxFloor) . '. The block needs at least ' . ((int) $maxFloor + 1) . ' floors (ground floor included).';
                }
            }
            if ($id && $old && isset($data['hostel_id']) && (int) $data['hostel_id'] !== (int) $old['hostel_id'] && (int) db_value('SELECT COUNT(*) FROM hostel_rooms WHERE block_id = ?', [$id])) {
                $errors['hostel_id'] = 'This block has rooms; it cannot be moved to another hostel.';
            }
            return $errors;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM hostel_rooms WHERE block_id = ?', [$id]);
            return $n ? $row['name'] . " has $n room" . ($n === 1 ? '' : 's') . '. Delete or move the rooms first, or mark the block inactive.' : null;
        },
        'describe' => fn (array $r): string => '"' . ($r['name'] ?? '') . '"' . (!empty($r['hostel_id']) ? ' (' . db_value('SELECT code FROM hostels WHERE id = ?', [(int) $r['hostel_id']]) . ')' : ''),
    ],
];
