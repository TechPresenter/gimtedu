<?php
/** Hostel visitor register (brief §20): visitor, relation, resident visited, purpose, ID proof, check-in / check-out. */
require_once APP_ROOT . '/app/services/hostel.php';

$residentSource = ['table' => 'hostel_allocations ra', 'joins' => 'JOIN students s ON s.id = ra.student_id JOIN hostel_rooms rr ON rr.id = ra.room_id JOIN hostels rh ON rh.id = ra.hostel_id', 'value' => 's.id',
    'label' => "CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')')", 'sub' => "CONCAT(rh.code, ' · Room ', rr.room_no)",
    'where' => "ra.status = 'active'", 'order' => 's.first_name, s.last_name',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 'rr.room_no', "CONCAT(s.first_name, ' ', s.last_name)"],
    'filters' => ['hostel_id' => 'ra.hostel_id'], 'async' => true];

return [
    'table' => 'hostel_visitors',
    'title' => 'Visitor Register',
    'singular' => 'Visitor',
    'permission' => 'hostel',
    'icon' => 'user-check',
    'description' => 'Gate register of visitors meeting hostel residents.',
    'select' => "t.*, h.name AS hostel_name, h.code AS hostel_code, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo,
                 (SELECT r.room_no FROM hostel_allocations ha JOIN hostel_rooms r ON r.id = ha.room_id WHERE ha.student_id = t.student_id AND ha.status = 'active' ORDER BY ha.id DESC LIMIT 1) AS room_no,
                 TIMESTAMPDIFF(MINUTE, t.check_in, COALESCE(t.check_out, NOW())) AS duration_minutes, u.name AS approved_by_name",
    'joins' => 'JOIN hostels h ON h.id = t.hostel_id JOIN students s ON s.id = t.student_id LEFT JOIN users u ON u.id = t.approved_by',
    'search' => ['t.visitor_name', 't.phone', 't.pass_no', 't.purpose', 's.first_name', 's.last_name', 's.student_uid', 't.vehicle_no'],
    'search_placeholder' => 'Search visitor, phone, pass no or resident…',
    'order' => 't.check_out IS NULL DESC, t.check_in DESC',
    'columns' => [
        ['key' => 'visitor_name', 'label' => 'Visitor', 'format' => 'person', 'sub' => 'visitor_sub', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Visiting', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'resident_sub', 'sortable' => 's.first_name'],
        ['key' => 'purpose', 'label' => 'Purpose', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'id_proof_label', 'label' => 'ID proof', 'format' => 'text'],
        ['key' => 'check_in', 'label' => 'Check-in', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'check_out', 'label' => 'Check-out', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'pass_no', 'label' => 'Pass No', 'format' => 'code', 'sortable' => true, 'hidden' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'vehicle_no', 'label' => 'Vehicle', 'format' => 'text', 'hidden' => true],
        ['key' => 'visit_status', 'label' => 'Status', 'format' => 'badge', 'colors' => ['inside' => 'amber', 'left' => 'green', 'overstay' => 'red']],
    ],
    'filters' => [
        ['key' => 'hostel_id', 'label' => 'Hostel', 'source' => 'hostels'],
        ['key' => 'presence', 'label' => 'Status', 'options' => ['inside' => 'Inside now', 'left' => 'Checked out', 'today' => 'Today'],
            'sql_map' => ['inside' => 't.check_out IS NULL', 'left' => 't.check_out IS NOT NULL', 'today' => 'DATE(t.check_in) = CURDATE()']],
        ['key' => 'relation', 'label' => 'Relation', 'options' => hostel_visitor_relations()],
        ['key' => 'id_proof_type', 'label' => 'ID proof', 'options' => hostel_id_proof_types()],
        ['key' => 'check_in', 'label' => 'Visit date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Resident'],
        ['name' => 'hostel_id', 'label' => 'Hostel', 'type' => 'select', 'required' => true, 'source' => 'hostels', 'col' => 6],
        ['name' => 'student_id', 'label' => 'Visiting resident', 'type' => 'combobox', 'required' => true, 'source' => $residentSource, 'depends' => ['hostel_id' => 'hostel_id'], 'col' => 6],
        ['type' => 'section', 'label' => 'Visitor'],
        ['name' => 'visitor_name', 'label' => 'Visitor name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 6, 'placeholder' => 'e.g. Rakesh Sharma'],
        ['name' => 'relation', 'label' => 'Relation', 'type' => 'select', 'required' => true, 'options' => hostel_visitor_relations(), 'col' => 4],
        ['name' => 'visitors_count', 'label' => 'No. of persons', 'type' => 'number', 'required' => true, 'min' => 1, 'max' => 10, 'default' => 1, 'col' => 2],
        ['name' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'required' => true, 'col' => 6, 'placeholder' => '+91 98XXXXXXXX'],
        ['name' => 'vehicle_no', 'label' => 'Vehicle no.', 'type' => 'text', 'maxlength' => 20, 'col' => 6, 'placeholder' => 'e.g. UP16 AB 1234'],
        ['name' => 'id_proof_type', 'label' => 'ID proof type', 'type' => 'select', 'required' => true, 'options' => hostel_id_proof_types(), 'col' => 6],
        ['name' => 'id_proof', 'label' => 'ID proof number', 'type' => 'text', 'required' => true, 'maxlength' => 60, 'col' => 6, 'help' => 'Only the last 4 characters are stored.'],
        ['name' => 'purpose', 'label' => 'Purpose of visit', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 12, 'placeholder' => 'e.g. Weekend visit, dropping documents'],
        ['type' => 'section', 'label' => 'Timing'],
        ['name' => 'check_in', 'label' => 'Check-in', 'type' => 'datetime', 'required' => true, 'default' => date('Y-m-d\TH:i'), 'col' => 6, 'help' => 'Defaults to the current time.'],
        ['name' => 'check_out', 'label' => 'Check-out', 'type' => 'datetime', 'col' => 6, 'edit_only' => true],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
    ],
    'scopes' => ['hostel_id' => 't.hostel_id', 'student_id' => 't.student_id', 'presence' => "IF(t.check_out IS NULL, 'inside', 'left')"],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $hostelId = (int) ($data['hostel_id'] ?? ($old['hostel_id'] ?? 0));
            $studentId = (int) ($data['student_id'] ?? ($old['student_id'] ?? 0));
            if ($studentId && (!$old || $studentId !== (int) $old['student_id'] || $hostelId !== (int) $old['hostel_id'])) {
                $stay = hostel_active_allocation($studentId);
                if (!$stay) {
                    $errors['student_id'] = 'This student does not stay in a hostel at present.';
                } elseif ((int) $stay['hostel_id'] !== $hostelId) {
                    $errors['student_id'] = 'This student stays in ' . $stay['hostel_name'] . ', not the selected hostel.';
                }
            }
            $in = $data['check_in'] ?? ($old['check_in'] ?? null);
            $out = array_key_exists('check_out', $data) ? $data['check_out'] : ($old['check_out'] ?? null);
            // the 30-day window applies to new entries and to moving a check-in; older entries stay editable (remarks, phone …)
            $inChanged = !$old || ($in && strtotime((string) $in) !== strtotime((string) $old['check_in']));
            if ($in && strtotime($in) > time() + 300) {
                $errors['check_in'] = 'Check-in time cannot be in the future.';
            } elseif ($in && $inChanged && strtotime($in) < strtotime('-30 days')) {
                $errors['check_in'] = 'Check-in cannot be back-dated by more than 30 days.';
            }
            if ($out && $in && $out < $in) {
                $errors['check_out'] = 'Check-out cannot be before check-in.';
            } elseif ($out && strtotime($out) > time() + 300) {
                $errors['check_out'] = 'Check-out time cannot be in the future.';
            }
            if (isset($data['phone']) && $data['phone'] !== null && strlen(preg_replace('/\D/', '', (string) $data['phone'])) < 10) {
                $errors['phone'] = 'Enter a valid 10-digit mobile number.';
            }
            if (isset($data['id_proof']) && $data['id_proof'] !== null && strlen(preg_replace('/[^A-Za-z0-9]/', '', (string) $data['id_proof'])) < 4) {
                $errors['id_proof'] = 'Enter the ID proof number (at least 4 characters).';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if ($id === null) {
                $data['pass_no'] = hostel_next_pass_no();
                $data['approved_by'] = user_id();
                $data['check_out'] = null;
            }
            if (isset($data['id_proof'])) {
                $data['id_proof'] = hostel_mask_id($data['id_proof']);
            }
            if (!empty($data['vehicle_no'])) {
                $data['vehicle_no'] = strtoupper((string) $data['vehicle_no']);
            }
            return $data;
        },
        'bulk_action' => function (string $action, array $ids): array {
            if ($action !== 'checkout') {
                throw new InvalidArgumentException('Unknown bulk action.');
            }
            $done = 0;
            foreach ($ids as $vid) {
                if (db_value('SELECT check_out FROM hostel_visitors WHERE id = ?', [(int) $vid]) === null && db_value('SELECT id FROM hostel_visitors WHERE id = ?', [(int) $vid])) {
                    hostel_visitor_checkout((int) $vid, []);
                    $done++;
                }
            }
            return ['checked_out' => $done, 'message' => $done ? "$done visitor" . ($done === 1 ? '' : 's') . ' checked out.' : 'The selected visitors had already checked out.'];
        },
        'transform_row' => function (array $r): array {
            if (array_key_exists('hostel_code', $r)) {
                $rel = $r['relation'] ? (hostel_visitor_relations()[$r['relation']] ?? $r['relation']) : null;
                $r['visitor_sub'] = trim(($rel ?? '') . ((int) ($r['visitors_count'] ?? 1) > 1 ? ' · ' . (int) $r['visitors_count'] . ' persons' : ''), ' ·');
                $r['resident_sub'] = trim($r['hostel_code'] . ($r['room_no'] ? ' · Room ' . $r['room_no'] : ''));
                $short = ['aadhaar' => 'Aadhaar', 'driving_licence' => 'DL', 'voter_id' => 'Voter ID', 'pan' => 'PAN', 'passport' => 'Passport', 'other' => 'ID'];
                $r['id_proof_label'] = $r['id_proof_type'] ? ($short[$r['id_proof_type']] ?? $r['id_proof_type']) . ($r['id_proof'] ? ' ' . $r['id_proof'] : '') : ($r['id_proof'] ?: null);
                $overstay = !$r['check_out'] && (int) ($r['duration_minutes'] ?? 0) > 6 * 60;
                $r['visit_status'] = $r['check_out'] ? 'left' : ($overstay ? 'overstay' : 'inside');
            }
            return $r;
        },
        'describe' => fn (array $r): string => '"' . ($r['visitor_name'] ?? '') . '"' . (!empty($r['pass_no']) ? ' (' . $r['pass_no'] . ')' : ''),
    ],
];
