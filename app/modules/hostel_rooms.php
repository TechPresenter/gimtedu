<?php
/** Hostel rooms (brief §20): room no, floor, type, capacity, rent, amenities, status. Beds are kept in sync with capacity. */
require_once APP_ROOT . '/app/services/hostel.php';

$blockSource = ['table' => 'hostel_blocks hb', 'value' => 'hb.id', 'label' => 'hb.name', 'sub' => "CONCAT(hb.floors, ' floors')", 'order' => 'hb.name', 'search' => ['hb.name'],
    'filters' => ['hostel_id' => 'hb.hostel_id']];

return [
    'table' => 'hostel_rooms',
    'title' => 'Rooms',
    'singular' => 'Room',
    'permission' => 'hostel',
    'icon' => 'door-open',
    'description' => 'Hostel rooms with capacity, rent, amenities and live occupancy.',
    'select' => "t.*, h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type, b.name AS block_name,
                 (SELECT COUNT(*) FROM hostel_beds hb WHERE hb.room_id = t.id AND hb.status = 'available') AS free_beds,
                 (SELECT COUNT(*) FROM hostel_beds hb WHERE hb.room_id = t.id AND hb.status = 'maintenance') AS maintenance_beds,
                 (SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) ORDER BY ha.id SEPARATOR ', ')
                    FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id WHERE ha.room_id = t.id AND ha.status = 'active') AS residents",
    'joins' => 'JOIN hostels h ON h.id = t.hostel_id LEFT JOIN hostel_blocks b ON b.id = t.block_id',
    'search' => ['t.room_no', 'h.name', 'h.code', 'b.name', 't.amenities'],
    'search_placeholder' => 'Search room no, hostel or block…',
    'order' => 'h.name, b.name, t.floor_no, LENGTH(t.room_no), t.room_no',
    'per_page' => 25,
    'columns' => [
        ['key' => 'room_no', 'label' => 'Room', 'format' => 'title', 'sub' => 'room_label', 'sortable' => 'LENGTH(t.room_no), t.room_no', 'link' => 'view'],
        ['key' => 'hostel_name', 'label' => 'Hostel', 'format' => 'text', 'sortable' => 'h.name', 'hidden' => true],
        ['key' => 'block_name', 'label' => 'Block', 'format' => 'text', 'sortable' => 'b.name', 'hidden' => true],
        ['key' => 'floor_no', 'label' => 'Floor', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'room_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['single' => 'purple', 'double' => 'blue', 'triple' => 'cyan', 'quad' => 'amber', 'dormitory' => 'slate']],
        ['key' => 'capacity', 'label' => 'Beds', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'occupied', 'label' => 'Occupied', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'free_beds', 'label' => 'Free', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'residents', 'label' => 'Residents', 'format' => 'truncate', 'truncate' => 48, 'hidden' => true],
        ['key' => 'is_ac', 'label' => 'AC', 'format' => 'boolean', 'align' => 'center', 'sortable' => true],
        ['key' => 'annual_fee', 'label' => 'Rent / year', 'format' => 'money', 'sortable' => true],
        ['key' => 'amenity_list', 'label' => 'Amenities', 'format' => 'tags', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['available' => 'green', 'full' => 'blue', 'maintenance' => 'amber', 'inactive' => 'red']],
    ],
    'filters' => [
        ['key' => 'hostel_id', 'label' => 'Hostel', 'source' => 'hostels'],
        ['key' => 'block_id', 'label' => 'Block', 'source' => $blockSource, 'depends' => ['hostel_id' => 'hostel_id']],
        ['key' => 'floor_no', 'label' => 'Floor', 'options' => array_combine(range(0, 8), array_map('hostel_floor_label', range(0, 8)))],
        ['key' => 'room_type', 'label' => 'Type', 'options' => hostel_room_types()],
        ['key' => 'availability', 'label' => 'Occupancy', 'options' => ['vacant' => 'Vacant', 'partial' => 'Partly occupied', 'full' => 'Full', 'has_free' => 'Has a free bed'],
            'sql_map' => [
                'vacant' => "t.occupied = 0 AND t.status NOT IN ('maintenance', 'inactive')",
                'partial' => 't.occupied > 0 AND t.occupied < t.capacity',
                'full' => 't.capacity > 0 AND t.occupied >= t.capacity',
                'has_free' => "t.status NOT IN ('maintenance', 'inactive') AND EXISTS (SELECT 1 FROM hostel_beds fb WHERE fb.room_id = t.id AND fb.status = 'available')",
            ]],
        ['key' => 'is_ac', 'label' => 'AC', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Status', 'options' => hostel_room_statuses()],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Location'],
        ['name' => 'hostel_id', 'label' => 'Hostel', 'type' => 'select', 'required' => true, 'source' => 'hostels', 'col' => 6],
        ['name' => 'block_id', 'label' => 'Block / building', 'type' => 'select', 'source' => $blockSource, 'depends' => ['hostel_id' => 'hostel_id'], 'col' => 6,
            'placeholder' => 'Select block…'],
        ['name' => 'floor_no', 'label' => 'Floor', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 30, 'default' => 0, 'col' => 4, 'help' => '0 = ground floor'],
        ['name' => 'room_no', 'label' => 'Room number', 'type' => 'text', 'required' => true, 'maxlength' => 20, 'col' => 4, 'placeholder' => 'e.g. A-101'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'available', 'options' => hostel_room_statuses(), 'col' => 4,
            'help' => 'Available / Full is set automatically from the beds.'],
        ['type' => 'section', 'label' => 'Room & rent'],
        ['name' => 'room_type', 'label' => 'Room type', 'type' => 'select', 'required' => true, 'default' => 'double', 'options' => hostel_room_types(), 'col' => 4],
        ['name' => 'capacity', 'label' => 'Beds (capacity)', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 12, 'default' => 2, 'col' => 4,
            'help' => 'Beds are added / removed automatically.'],
        ['name' => 'annual_fee', 'label' => 'Rent per year', 'type' => 'money', 'min' => 0, 'max' => 1000000, 'col' => 4, 'help' => 'Leave blank to use the hostel default.'],
        ['name' => 'is_ac', 'label' => 'Air conditioned', 'type' => 'toggle', 'default' => false, 'col' => 4, 'placeholder' => 'AC room'],
        ['name' => 'amenities', 'label' => 'Amenities', 'type' => 'multiselect', 'options' => hostel_room_amenities(), 'col' => 8, 'placeholder' => 'Select amenities…'],
    ],
    'scopes' => ['hostel_id' => 't.hostel_id', 'block_id' => 't.block_id'],
    'bulk' => ['delete' => true, 'status' => ['available', 'maintenance']],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $hostelId = (int) ($data['hostel_id'] ?? ($old['hostel_id'] ?? 0));
            $roomNo = $data['room_no'] ?? ($old['room_no'] ?? null);
            $hostel = $hostelId ? db_row('SELECT id, name, total_floors FROM hostels WHERE id = ?', [$hostelId]) : null;
            if ($hostel && $roomNo !== null) {
                $dup = (int) db_value('SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = ? AND room_no = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$hostelId, $roomNo, $id] : [$hostelId, $roomNo]);
                if ($dup) {
                    $errors['room_no'] = 'Room ' . $roomNo . ' already exists in ' . $hostel['name'] . '.';
                }
            }
            $blockId = array_key_exists('block_id', $data) ? $data['block_id'] : ($old['block_id'] ?? null);
            $floor = array_key_exists('floor_no', $data) ? $data['floor_no'] : ($old['floor_no'] ?? 0);
            if ($blockId) {
                $block = db_row('SELECT hostel_id, name, floors FROM hostel_blocks WHERE id = ?', [(int) $blockId]);
                if (!$block || (int) $block['hostel_id'] !== $hostelId) {
                    $errors['block_id'] = 'The selected block does not belong to this hostel.';
                } elseif ($floor !== null && (int) $floor >= (int) $block['floors']) {
                    $errors['floor_no'] = $block['name'] . ' has ' . (int) $block['floors'] . ' floor' . ((int) $block['floors'] === 1 ? '' : 's') . ' (ground to ' . hostel_floor_label((int) $block['floors'] - 1) . ').';
                }
            } elseif ($hostel && $hostel['total_floors'] && $floor !== null && (int) $floor >= (int) $hostel['total_floors']) {
                $errors['floor_no'] = $hostel['name'] . ' has ' . (int) $hostel['total_floors'] . ' floors (ground to ' . hostel_floor_label((int) $hostel['total_floors'] - 1) . ').';
            }
            if ($id && $old) {
                if (isset($data['hostel_id']) && (int) $data['hostel_id'] !== (int) $old['hostel_id']
                    && (int) db_value('SELECT COUNT(*) FROM hostel_allocations WHERE room_id = ?', [$id])) {
                    $errors['hostel_id'] = 'This room has allocation history and cannot be moved to another hostel.';
                }
                $occupied = (int) db_value("SELECT COUNT(*) FROM hostel_beds WHERE room_id = ? AND status = 'occupied'", [$id]);
                if (isset($data['capacity']) && (int) $data['capacity'] < $occupied) {
                    $errors['capacity'] = "$occupied bed" . ($occupied === 1 ? ' is' : 's are') . ' occupied. Capacity cannot be lower than that.';
                }
                if (($data['status'] ?? null) === 'inactive' && $occupied) {
                    $errors['status'] = 'Vacate or transfer the ' . $occupied . ' resident' . ($occupied === 1 ? '' : 's') . ' before marking the room inactive.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (isset($data['room_no'])) {
                $data['room_no'] = strtoupper(trim((string) $data['room_no']));
            }
            if (isset($data['status']) && $data['status'] === 'full') {
                $data['status'] = 'available';
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $cap = (int) ($data['capacity'] ?? ($old['capacity'] ?? 1));
            hostel_sync_beds($id, max(1, $cap));
        },
        'after_bulk' => function (array $ids): void {
            foreach ($ids as $rid) {
                hostel_sync_room((int) $rid);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $active = (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE room_id = ? AND status = 'active'", [$id]);
            if ($active) {
                return 'Room ' . $row['room_no'] . " has $active resident" . ($active === 1 ? '' : 's') . '. Vacate or transfer them first.';
            }
            $history = (int) db_value('SELECT COUNT(*) FROM hostel_allocations WHERE room_id = ?', [$id]);
            return $history ? 'Room ' . $row['room_no'] . ' has allocation history and cannot be deleted. Mark it inactive instead.' : null;
        },
        'transform_row' => function (array $r): array {
            $r['floor_label'] = hostel_floor_label($r['floor_no'] ?? 0);
            $r['room_label'] = trim(($r['hostel_code'] ?? '') . ($r['block_name'] ? ' · ' . $r['block_name'] : ''));
            $decoded = !empty($r['amenities']) && is_string($r['amenities']) ? json_decode($r['amenities'], true) : null;
            $labels = hostel_room_amenities();
            $r['amenity_list'] = is_array($decoded) ? implode(',', array_map(fn ($a) => $labels[$a] ?? $a, $decoded)) : null;
            return $r;
        },
        'describe' => fn (array $r): string => ($r['room_no'] ?? '') . (!empty($r['hostel_id']) ? ' (' . db_value('SELECT code FROM hostels WHERE id = ?', [(int) $r['hostel_id']]) . ')' : ''),
    ],
];
