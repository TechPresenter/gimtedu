<?php
/** Hostels (brief §20): boys / girls hostels with warden, facilities, fees and live capacity. */
require_once APP_ROOT . '/app/services/hostel.php';

return [
    'table' => 'hostels',
    'title' => 'Hostels',
    'singular' => 'Hostel',
    'permission' => 'hostel',
    'icon' => 'building',
    'description' => 'Hostel buildings, wardens, facilities and fees.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', ws.first_name, ws.last_name)) AS warden_staff_name, ws.photo AS warden_photo,
                 (SELECT COUNT(*) FROM hostel_blocks b WHERE b.hostel_id = t.id) AS blocks_count,
                 (SELECT COUNT(*) FROM hostel_rooms r WHERE r.hostel_id = t.id) AS rooms_count,
                 (SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.hostel_id = t.id) AS beds_count,
                 (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.hostel_id = t.id AND ha.status = 'active') AS residents_count,
                 ROUND(100 * (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.hostel_id = t.id AND ha.status = 'active')
                       / NULLIF((SELECT COUNT(*) FROM hostel_beds hb JOIN hostel_rooms r ON r.id = hb.room_id WHERE r.hostel_id = t.id), 0), 1) AS occupancy_pct",
    'joins' => 'LEFT JOIN staff ws ON ws.id = t.warden_staff_id',
    'search' => ['t.name', 't.code', 't.warden_name', 't.address'],
    'order' => "t.status = 'active' DESC, t.type, t.name",
    'columns' => [
        ['key' => 'name', 'label' => 'Hostel', 'format' => 'title', 'sub' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['boys' => 'blue', 'girls' => 'purple', 'staff' => 'slate', 'international' => 'cyan']],
        ['key' => 'warden_name', 'label' => 'Warden', 'format' => 'person', 'sub' => 'warden_phone', 'image' => 'warden_photo', 'sortable' => true],
        ['key' => 'blocks_count', 'label' => 'Blocks', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'rooms_count', 'label' => 'Rooms', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'beds_count', 'label' => 'Capacity (beds)', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'residents_count', 'label' => 'Residents', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'occupancy_pct', 'label' => 'Occupancy', 'format' => 'percent', 'align' => 'right', 'sortable' => true],
        ['key' => 'annual_fee', 'label' => 'Rent / year', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'mess_fee', 'label' => 'Mess / year', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'address', 'label' => 'Address', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => hostel_types()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Hostel details'],
        ['name' => 'name', 'label' => 'Hostel name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 8, 'maxlength' => 120, 'placeholder' => 'e.g. Aryabhatta Boys Hostel'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'ABH'],
        ['name' => 'type', 'label' => 'Hostel type', 'type' => 'select', 'required' => true, 'default' => 'boys', 'options' => hostel_types(), 'col' => 4,
            'help' => 'Boys hostels accept only male students, girls hostels only female students.'],
        ['name' => 'total_floors', 'label' => 'Floors', 'type' => 'number', 'min' => 1, 'max' => 30, 'col' => 4, 'placeholder' => '4'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 4],
        ['type' => 'section', 'label' => 'Warden & contact'],
        ['name' => 'warden_staff_id', 'label' => 'Warden (staff)', 'type' => 'combobox', 'source' => 'staff', 'placeholder' => 'Search staff…', 'help' => 'Name and phone are filled from the staff record when left blank.'],
        ['name' => 'warden_name', 'label' => 'Warden name', 'type' => 'text', 'maxlength' => 150],
        ['name' => 'warden_phone', 'label' => 'Warden phone', 'type' => 'tel'],
        ['name' => 'email', 'label' => 'Hostel email', 'type' => 'email', 'placeholder' => 'boyshostel@gimt.ac.in'],
        ['name' => 'address', 'label' => 'Address / location', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
        ['type' => 'section', 'label' => 'Facilities & fees'],
        ['name' => 'amenities', 'label' => 'Facilities', 'type' => 'multiselect', 'options' => hostel_amenities(), 'col' => 12, 'placeholder' => 'Select facilities…'],
        ['name' => 'annual_fee', 'label' => 'Default room rent / year', 'type' => 'money', 'min' => 0, 'max' => 1000000, 'col' => 4, 'help' => 'Used when a room has no rent of its own.'],
        ['name' => 'mess_fee', 'label' => 'Mess charges / year', 'type' => 'money', 'min' => 0, 'max' => 1000000, 'col' => 4],
        ['name' => 'visiting_hours', 'label' => 'Visiting hours', 'type' => 'text', 'maxlength' => 80, 'col' => 4, 'placeholder' => '10:00 AM – 7:00 PM'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3],
        ['name' => 'image', 'label' => 'Photo', 'type' => 'image', 'folder' => 'hostels'],
    ],
    'scopes' => ['type' => 't.type'],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            if ($id && isset($data['type']) && $old && $data['type'] !== $old['type']) {
                $need = hostel_gender_for_type($data['type']);
                if ($need) {
                    $mismatch = (int) db_value("SELECT COUNT(*) FROM hostel_allocations ha JOIN students s ON s.id = ha.student_id WHERE ha.hostel_id = ? AND ha.status = 'active' AND (s.gender IS NULL OR s.gender <> ?)", [$id, $need]);
                    if ($mismatch) {
                        $errors['type'] = "$mismatch current resident" . ($mismatch === 1 ? ' does' : 's do') . ' not match this hostel type. Transfer them first.';
                    }
                }
            }
            if ($id && ($data['status'] ?? null) === 'inactive' && $old && $old['status'] !== 'inactive') {
                $n = (int) db_value("SELECT COUNT(*) FROM hostel_allocations WHERE hostel_id = ? AND status = 'active'", [$id]);
                if ($n) {
                    $errors['status'] = "The hostel has $n resident" . ($n === 1 ? '' : 's') . '. Vacate or transfer them before deactivating it.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            if (!empty($data['warden_staff_id'])) {
                $st = db_row('SELECT first_name, last_name, phone, email FROM staff WHERE id = ?', [(int) $data['warden_staff_id']]);
                if ($st) {
                    if (empty($data['warden_name'])) {
                        $data['warden_name'] = trim($st['first_name'] . ' ' . $st['last_name']);
                    }
                    if (empty($data['warden_phone']) && $st['phone']) {
                        $data['warden_phone'] = $st['phone'];
                    }
                }
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM hostel_allocations WHERE hostel_id = ?', [$id]);
            if ($n) {
                return $row['name'] . " has $n allocation record" . ($n === 1 ? '' : 's') . ' and cannot be deleted. Mark it inactive instead.';
            }
            $c = (int) db_value('SELECT COUNT(*) FROM hostel_complaints WHERE hostel_id = ?', [$id]) + (int) db_value('SELECT COUNT(*) FROM hostel_visitors WHERE hostel_id = ?', [$id]);
            if ($c) {
                return $row['name'] . ' has complaint / visitor history and cannot be deleted. Mark it inactive instead.';
            }
            // never cascade-delete a whole building silently: rooms (and their beds) must be removed first
            $rooms = (int) db_value('SELECT COUNT(*) FROM hostel_rooms WHERE hostel_id = ?', [$id]);
            return $rooms ? $row['name'] . " still has $rooms room" . ($rooms === 1 ? '' : 's') . '. Delete the rooms first (Rooms tab, filter by hostel) or mark the hostel inactive.' : null;
        },
        'transform_row' => function (array $r): array {
            $r['occupancy_pct'] = $r['occupancy_pct'] !== null ? (float) $r['occupancy_pct'] : 0;
            return $r;
        },
        'describe' => fn (array $r): string => '"' . ($r['name'] ?? '') . '"',
    ],
];
