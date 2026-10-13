<?php
/**
 * Hostel room allocations (brief §20): student -> hostel -> room -> free bed, from/to dates, rent, hostel fee invoice.
 * Vacate / transfer / cancel / invoice are custom actions in api/routes/hostel.php; this module handles list, create and edit.
 */
require_once APP_ROOT . '/app/services/hostel.php';

$studentSource = ['table' => 'students s', 'joins' => 'LEFT JOIN programs p ON p.id = s.program_id', 'value' => 's.id',
    'label' => "CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')')",
    'sub' => "CONCAT_WS(' · ', p.short_name, CONCAT('Sem ', s.current_semester), CASE s.gender WHEN 'male' THEN 'Male' WHEN 'female' THEN 'Female' ELSE 'Gender not set' END)",
    'where' => "s.status = 'active' AND NOT EXISTS (SELECT 1 FROM hostel_allocations x WHERE x.student_id = s.id AND x.status = 'active')",
    'order' => 's.first_name, s.last_name',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.admission_no', 's.roll_no', 's.mobile', "CONCAT(s.first_name, ' ', s.last_name)"],
    'filters' => ['gender' => 's.gender', 'program_id' => 's.program_id'], 'async' => true];

$roomSource = ['table' => 'hostel_rooms hr', 'joins' => 'LEFT JOIN hostel_blocks hbk ON hbk.id = hr.block_id', 'value' => 'hr.id',
    'label' => "CONCAT('Room ', hr.room_no)",
    'sub' => "CONCAT_WS(' · ', hbk.name, CONCAT('Floor ', hr.floor_no), hr.room_type, CONCAT((SELECT COUNT(*) FROM hostel_beds fb WHERE fb.room_id = hr.id AND fb.status = 'available'), ' of ', hr.capacity, ' beds free'))",
    'where' => "hr.status = 'available' AND EXISTS (SELECT 1 FROM hostel_beds fb WHERE fb.room_id = hr.id AND fb.status = 'available')",
    'order' => 'hbk.name, hr.floor_no, LENGTH(hr.room_no), hr.room_no', 'search' => ['hr.room_no', 'hbk.name'], 'filters' => ['hostel_id' => 'hr.hostel_id']];

$bedSource = ['table' => 'hostel_beds hb', 'value' => 'hb.id', 'label' => "CONCAT('Bed ', hb.bed_no)", 'where' => "hb.status = 'available'",
    'order' => 'LENGTH(hb.bed_no), hb.bed_no', 'search' => ['hb.bed_no'], 'filters' => ['room_id' => 'hb.room_id']];

return [
    'table' => 'hostel_allocations',
    'title' => 'Room Allocations',
    'singular' => 'Allocation',
    'permission' => 'hostel',
    'icon' => 'key-round',
    'description' => 'Students allotted to hostel rooms and beds, with rent and hostel fee invoices.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo, s.gender, s.mobile AS student_mobile,
                 s.current_semester, p.short_name AS program_short, h.name AS hostel_name, h.code AS hostel_code, h.type AS hostel_type, h.mess_fee, r.room_no, r.floor_no, r.room_type,
                 b.name AS block_name, hb.bed_no, ses.name AS session_name, sf.invoice_no, sf.status AS invoice_status, sf.net_amount AS invoice_amount,
                 sf.balance_amount AS invoice_balance, pf.allocation_no AS transferred_from_no",
    'joins' => "JOIN students s ON s.id = t.student_id LEFT JOIN programs p ON p.id = s.program_id JOIN hostels h ON h.id = t.hostel_id
                JOIN hostel_rooms r ON r.id = t.room_id LEFT JOIN hostel_blocks b ON b.id = r.block_id LEFT JOIN hostel_beds hb ON hb.id = t.bed_id
                LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id LEFT JOIN student_fees sf ON sf.id = t.student_fee_id
                LEFT JOIN hostel_allocations pf ON pf.id = t.transferred_from_id",
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 't.allocation_no', 'r.room_no', 'h.name', 'h.code', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search student, ID, allotment no or room…',
    'order' => "t.status = 'active' DESC, t.allocated_on DESC, t.id DESC",
    'columns' => [
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_sub', 'sortable' => 's.first_name'],
        ['key' => 'room_label', 'label' => 'Room / Bed', 'format' => 'title', 'sub' => 'room_sub', 'sortable' => 'h.name, LENGTH(r.room_no), r.room_no'],
        ['key' => 'allocation_no', 'label' => 'Allotment No', 'format' => 'code', 'sortable' => true, 'hidden' => true],
        ['key' => 'allocated_on', 'label' => 'Allotted', 'format' => 'date', 'sortable' => true],
        ['key' => 'valid_until', 'label' => 'To', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'vacated_on', 'label' => 'Vacated', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'fee_amount', 'label' => 'Rent / year', 'format' => 'money', 'sortable' => true],
        ['key' => 'invoice_status', 'label' => 'Hostel fee', 'format' => 'badge', 'sortable' => 'sf.status'],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['active' => 'green', 'vacated' => 'slate', 'transferred' => 'purple', 'cancelled' => 'red']],
    ],
    'filters' => [
        ['key' => 'hostel_id', 'label' => 'Hostel', 'source' => 'hostels'],
        ['key' => 'status', 'label' => 'Status', 'options' => hostel_allocation_statuses()],
        ['key' => 'gender', 'label' => 'Gender', 'options' => ['male' => 'Male', 'female' => 'Female'], 'column' => 's.gender'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'fee', 'label' => 'Hostel fee', 'options' => ['none' => 'Not invoiced', 'due' => 'Due / overdue', 'paid' => 'Paid'],
            'sql_map' => [
                'none' => "(t.student_fee_id IS NULL OR sf.status = 'cancelled')",
                'due' => "sf.status IN ('pending', 'partial', 'overdue')",
                'paid' => "sf.status = 'paid'",
            ]],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions'],
        ['key' => 'allocated_on', 'label' => 'Allotted', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Student & room'],
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'required' => true, 'create_only' => true, 'source' => $studentSource, 'col' => 12, 'placeholder' => 'Search active students without a room…'],
        ['name' => 'hostel_id', 'label' => 'Hostel', 'type' => 'select', 'required' => true, 'create_only' => true, 'source' => 'hostels', 'col' => 4],
        ['name' => 'room_id', 'label' => 'Room (with a free bed)', 'type' => 'combobox', 'required' => true, 'create_only' => true, 'source' => $roomSource, 'depends' => ['hostel_id' => 'hostel_id'], 'col' => 4],
        ['name' => 'bed_id', 'label' => 'Free bed', 'type' => 'select', 'required' => true, 'create_only' => true, 'source' => $bedSource, 'depends' => ['room_id' => 'room_id'], 'col' => 4],
        ['type' => 'section', 'label' => 'Stay & rent'],
        ['name' => 'allocated_on', 'label' => 'Allotted from', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d'), 'col' => 4],
        ['name' => 'valid_until', 'label' => 'Allotted until', 'type' => 'date', 'col' => 4, 'help' => 'Defaults to the end of the academic session.'],
        ['name' => 'fee_amount', 'label' => 'Room rent / year', 'type' => 'money', 'min' => 0, 'max' => 1000000, 'col' => 4, 'help' => 'Defaults to the room / hostel rent.'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
    ],
    'scopes' => ['student_id' => 't.student_id', 'hostel_id' => 't.hostel_id', 'room_id' => 't.room_id', 'status' => 't.status'],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return hostel_validate_allocation($data);
            }
            $errors = [];
            $from = $data['allocated_on'] ?? $old['allocated_on'];
            $until = array_key_exists('valid_until', $data) ? $data['valid_until'] : $old['valid_until'];
            if ($from && $until && $until < $from) {
                $errors['valid_until'] = 'Allotted until must be on or after the allotment date.';
            }
            if ($from && $old['vacated_on'] && $from > $old['vacated_on']) {
                $errors['allocated_on'] = 'Allotment date cannot be after the vacating date (' . format_date($old['vacated_on']) . ').';
            }
            if ($from && $from > date('Y-m-d', strtotime('+90 days'))) {
                $errors['allocated_on'] = 'Allotment date cannot be more than 90 days ahead.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            return $id === null ? hostel_prepare_allocation($data) : $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if ($old !== null) {
                return;
            }
            hostel_occupy($id);
            // the invoice is a separate action guarded by hostel/edit (same as POST hostel/allocations/{id}/invoice)
            if (!empty($input['raise_invoice']) && $input['raise_invoice'] !== '0' && can('hostel', 'edit')) {
                hostel_raise_invoice($id, [
                    'include_mess' => !isset($input['include_mess']) || ($input['include_mess'] !== '0' && $input['include_mess'] !== false),
                    'due_date' => $input['due_date'] ?? null,
                ]);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ($row['status'] === 'active') {
                return 'Allocation ' . ($row['allocation_no'] ?? '#' . $id) . ' is active. Vacate, transfer or cancel it before deleting the record.';
            }
            return null;
        },
        'bulk_action' => function (string $action, array $ids, array $input): array {
            if ($action !== 'invoice') {
                throw new InvalidArgumentException('Unknown bulk action.');
            }
            return hostel_bulk_invoice($ids, $input);
        },
        'transform_row' => function (array $r): array {
            if (isset($r['room_no'])) {
                $r['room_label'] = $r['hostel_code'] . ' ' . $r['room_no'] . ($r['bed_no'] ? ' · Bed ' . $r['bed_no'] : '');
                $r['room_sub'] = trim(($r['block_name'] ? $r['block_name'] . ' · ' : '') . hostel_floor_label($r['floor_no']));
                $r['student_sub'] = trim(($r['student_uid'] ?? '') . ($r['program_short'] ? ' · ' . $r['program_short'] : ''));
            }
            if (array_key_exists('invoice_status', $r) && empty($r['invoice_status']) && ($r['status'] ?? '') !== 'cancelled') {
                $r['invoice_status'] = 'not_invoiced';
            }
            return $r;
        },
        'describe' => fn (array $r): string => hostel_describe_allocation($r),
    ],
];
