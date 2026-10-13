<?php
/** Hostel beds: one row per bed of a room, with the current resident. Occupied status is managed by allocations. */
require_once APP_ROOT . '/app/services/hostel.php';

$roomSource = ['table' => 'hostel_rooms hr', 'joins' => 'JOIN hostels h ON h.id = hr.hostel_id LEFT JOIN hostel_blocks hbk ON hbk.id = hr.block_id', 'value' => 'hr.id',
    'label' => "CONCAT(h.code, ' · Room ', hr.room_no)", 'sub' => "CONCAT(COALESCE(hbk.name, h.name), ' · ', hr.occupied, '/', hr.capacity, ' occupied')",
    'order' => 'h.name, LENGTH(hr.room_no), hr.room_no', 'search' => ['hr.room_no', 'h.name', 'h.code', "CONCAT(h.code, ' ', hr.room_no)"],
    'filters' => ['hostel_id' => 'hr.hostel_id'], 'async' => true];

return [
    'table' => 'hostel_beds',
    'title' => 'Beds',
    'singular' => 'Bed',
    'permission' => 'hostel',
    'icon' => 'bed-double',
    'description' => 'Every bed with its room and current resident.',
    'select' => "t.*, r.room_no, r.floor_no, r.room_type, r.hostel_id, r.status AS room_status, h.name AS hostel_name, h.code AS hostel_code, b.name AS block_name,
                 ha.id AS allocation_id, ha.allocation_no, ha.allocated_on, s.id AS student_id, s.student_uid, s.photo AS student_photo,
                 TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS resident_name, p.short_name AS program_short",
    'joins' => "JOIN hostel_rooms r ON r.id = t.room_id JOIN hostels h ON h.id = r.hostel_id LEFT JOIN hostel_blocks b ON b.id = r.block_id
                LEFT JOIN hostel_allocations ha ON ha.bed_id = t.id AND ha.status = 'active'
                LEFT JOIN students s ON s.id = ha.student_id LEFT JOIN programs p ON p.id = s.program_id",
    'search' => ['r.room_no', 't.bed_no', 'h.name', 'h.code', 's.first_name', 's.last_name', 's.student_uid'],
    'search_placeholder' => 'Search room, bed or resident…',
    'order' => 'h.name, b.name, r.floor_no, LENGTH(r.room_no), r.room_no, LENGTH(t.bed_no), t.bed_no',
    'columns' => [
        ['key' => 'bed_label', 'label' => 'Bed', 'format' => 'title', 'sub' => 'location', 'sortable' => 'LENGTH(r.room_no), r.room_no, t.bed_no', 'link' => 'view'],
        ['key' => 'hostel_name', 'label' => 'Hostel', 'format' => 'text', 'sortable' => 'h.name', 'hidden' => true],
        ['key' => 'room_type', 'label' => 'Room type', 'format' => 'badge', 'colors' => ['single' => 'purple', 'double' => 'blue', 'triple' => 'cyan', 'quad' => 'amber', 'dormitory' => 'slate']],
        ['key' => 'resident_name', 'label' => 'Resident', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_uid', 'sortable' => 's.first_name'],
        ['key' => 'allocated_on', 'label' => 'Since', 'format' => 'date', 'sortable' => 'ha.allocated_on'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['available' => 'green', 'occupied' => 'blue', 'maintenance' => 'amber']],
    ],
    'filters' => [
        ['key' => 'hostel_id', 'label' => 'Hostel', 'source' => 'hostels', 'column' => 'r.hostel_id'],
        ['key' => 'room_id', 'label' => 'Room', 'source' => $roomSource, 'depends' => ['hostel_id' => 'hostel_id']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['available' => 'Available', 'occupied' => 'Occupied', 'maintenance' => 'Under maintenance']],
    ],
    'fields' => [
        ['name' => 'room_id', 'label' => 'Room', 'type' => 'combobox', 'required' => true, 'source' => $roomSource, 'col' => 12, 'readonly_on_edit' => true, 'placeholder' => 'Search room…'],
        ['name' => 'bed_no', 'label' => 'Bed number / label', 'type' => 'text', 'required' => true, 'maxlength' => 10, 'col' => 6, 'placeholder' => 'e.g. 3 or A'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'available', 'options' => ['available' => 'Available', 'maintenance' => 'Under maintenance', 'occupied' => 'Occupied (via allocation)'], 'col' => 6,
            'help' => 'Occupied beds are managed through allocations.'],
    ],
    'scopes' => ['room_id' => 't.room_id', 'hostel_id' => 'r.hostel_id'],
    'bulk' => ['delete' => true, 'status' => ['available', 'maintenance']],
    'import' => false,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $roomId = (int) ($data['room_id'] ?? ($old['room_id'] ?? 0));
            $bedNo = $data['bed_no'] ?? ($old['bed_no'] ?? null);
            if ($roomId && $bedNo !== null) {
                $dup = (int) db_value('SELECT COUNT(*) FROM hostel_beds WHERE room_id = ? AND bed_no = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$roomId, $bedNo, $id] : [$roomId, $bedNo]);
                if ($dup) {
                    $errors['bed_no'] = 'This room already has bed ' . $bedNo . '.';
                }
            }
            if (!$id && $roomId) {
                $count = (int) db_value('SELECT COUNT(*) FROM hostel_beds WHERE room_id = ?', [$roomId]);
                if ($count >= 12) {
                    $errors['room_id'] = 'A room can have at most 12 beds.';
                }
            }
            $active = $id ? (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE bed_id = ? AND status = 'active'", [$id]) : 0;
            $wasOccupied = $active || ($old['status'] ?? '') === 'occupied';
            if ($wasOccupied && isset($data['status']) && $data['status'] !== 'occupied') {
                $errors['status'] = 'This bed is occupied. Vacate or transfer the resident before changing its status.';
            } elseif (!$wasOccupied && ($data['status'] ?? null) === 'occupied') {
                $errors['status'] = 'A bed becomes occupied only when a student is allotted to it (Room Allocation).';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (isset($data['bed_no'])) {
                $data['bed_no'] = strtoupper(trim((string) $data['bed_no']));
            }
            if ($id && $old && $old['status'] === 'occupied') {
                unset($data['status']);
            }
            return $data;
        },
        'after_save' => function (int $id): void {
            $roomId = (int) db_value('SELECT room_id FROM hostel_beds WHERE id = ?', [$id]);
            if ($roomId) {
                hostel_sync_room($roomId);
            }
        },
        'after_bulk' => function (array $ids): void {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) {
                return;
            }
            $in = implode(',', $ids);
            // Bulk status must never free an occupied bed: restore occupied beds that still have an active allocation.
            db_exec("UPDATE hostel_beds b SET b.status = 'occupied' WHERE b.id IN ($in) AND EXISTS (SELECT 1 FROM hostel_allocations ha WHERE ha.bed_id = b.id AND ha.status = 'active')");
            foreach (db_column("SELECT DISTINCT room_id FROM hostel_beds WHERE id IN ($in)") as $roomId) {
                hostel_sync_room((int) $roomId);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            $active = (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE bed_id = ? AND status = 'active'", [$id]);
            if ($active || $row['status'] === 'occupied') {
                return 'Bed ' . $row['bed_no'] . ' is occupied. Vacate or transfer the resident first.';
            }
            $beds = (int) db_value('SELECT COUNT(*) FROM hostel_beds WHERE room_id = ?', [(int) $row['room_id']]);
            return $beds <= 1 ? 'A room needs at least one bed. Delete the room instead, or mark the bed under maintenance.' : null;
        },
        'after_delete' => function (int $id, array $row): void {
            hostel_sync_room((int) $row['room_id']);
        },
        'transform_row' => function (array $r): array {
            if (isset($r['room_no'])) {
                $r['bed_label'] = 'Room ' . $r['room_no'] . ' · Bed ' . $r['bed_no'];
                $r['location'] = trim(($r['hostel_code'] ?? '') . ($r['block_name'] ? ' · ' . $r['block_name'] : '') . ' · ' . hostel_floor_label($r['floor_no'] ?? 0));
            }
            return $r;
        },
        'describe' => function (array $r): string {
            $room = db_row('SELECT r.room_no, h.code FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id WHERE r.id = ?', [(int) ($r['room_id'] ?? 0)]);
            return ($r['bed_no'] ?? '') . ($room ? ' (' . $room['code'] . ' Room ' . $room['room_no'] . ')' : '');
        },
    ],
];
