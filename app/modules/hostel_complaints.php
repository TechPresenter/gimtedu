<?php
/**
 * Hostel complaints (brief §20): category, priority, room, student, assignment, status, resolution.
 * Workflow transitions (assign / start / resolve / close / reopen) are custom actions in api/routes/hostel.php.
 */
require_once APP_ROOT . '/app/services/hostel.php';

$roomSource = ['table' => 'hostel_rooms hr', 'joins' => 'LEFT JOIN hostel_blocks hbk ON hbk.id = hr.block_id', 'value' => 'hr.id', 'label' => "CONCAT('Room ', hr.room_no)",
    'sub' => "CONCAT_WS(' · ', hbk.name, CONCAT('Floor ', hr.floor_no))", 'order' => 'hbk.name, hr.floor_no, LENGTH(hr.room_no), hr.room_no',
    'search' => ['hr.room_no', 'hbk.name'], 'filters' => ['hostel_id' => 'hr.hostel_id']];

$residentSource = ['table' => 'hostel_allocations ra', 'joins' => 'JOIN students s ON s.id = ra.student_id JOIN hostel_rooms rr ON rr.id = ra.room_id', 'value' => 's.id',
    'label' => "CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')')", 'sub' => "CONCAT('Room ', rr.room_no)",
    'where' => "ra.status = 'active'", 'order' => 's.first_name, s.last_name',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 'rr.room_no', "CONCAT(s.first_name, ' ', s.last_name)"],
    'filters' => ['hostel_id' => 'ra.hostel_id', 'room_id' => 'ra.room_id'], 'async' => true];

$staffSource = ['table' => 'staff st', 'value' => 'st.id', 'label' => "TRIM(CONCAT_WS(' ', st.first_name, st.last_name))", 'sub' => 'st.designation',
    'where' => "st.status = 'active'", 'order' => "FIELD(st.category, 'hostel', 'maintenance', 'security') = 0, st.first_name",
    'search' => ['st.first_name', 'st.last_name', 'st.designation', 'st.employee_id'], 'filters' => ['category' => 'st.category']];

return [
    'table' => 'hostel_complaints',
    'title' => 'Hostel Complaints',
    'singular' => 'Complaint',
    'permission' => 'hostel',
    'icon' => 'message-square-warning',
    'description' => 'Maintenance, mess, Wi-Fi and other complaints from hostel residents.',
    'select' => "t.*, h.name AS hostel_name, h.code AS hostel_code, r.room_no, r.floor_no, b.name AS block_name,
                 TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo AS student_photo,
                 TRIM(CONCAT_WS(' ', st.first_name, st.last_name)) AS staff_name, st.photo AS staff_photo, st.designation AS staff_designation,
                 TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.resolved_at, NOW())) AS age_hours",
    'joins' => 'JOIN hostels h ON h.id = t.hostel_id LEFT JOIN hostel_rooms r ON r.id = t.room_id LEFT JOIN hostel_blocks b ON b.id = r.block_id
                LEFT JOIN students s ON s.id = t.student_id LEFT JOIN staff st ON st.id = t.assigned_staff_id',
    'search' => ['t.subject', 't.complaint_no', 't.description', 'r.room_no', 's.first_name', 's.last_name', 's.student_uid', 't.assigned_to'],
    'search_placeholder' => 'Search subject, complaint no, room or student…',
    'order' => "FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.created_at DESC",
    'columns' => [
        ['key' => 'subject', 'label' => 'Complaint', 'format' => 'title', 'sub' => 'complaint_sub', 'sortable' => true, 'link' => 'view'],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true, 'colors' => ['maintenance' => 'slate', 'electrical' => 'amber', 'plumbing' => 'cyan',
            'cleanliness' => 'green', 'mess' => 'purple', 'security' => 'red', 'internet' => 'blue', 'furniture' => 'slate', 'other' => 'slate']],
        ['key' => 'location', 'label' => 'Hostel / Room', 'format' => 'text', 'sortable' => 'h.name, r.room_no', 'hidden' => true],
        ['key' => 'student_name', 'label' => 'Raised by', 'format' => 'person', 'image' => 'student_photo', 'sub' => 'student_uid', 'sortable' => 's.first_name'],
        ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge', 'sortable' => "FIELD(t.priority, 'low', 'medium', 'high', 'urgent')", 'hidden' => true,
            'colors' => ['low' => 'slate', 'medium' => 'amber', 'high' => 'red', 'urgent' => 'red']],
        ['key' => 'assigned_to', 'label' => 'Assigned to', 'format' => 'text', 'sortable' => true],
        ['key' => 'created_at', 'label' => 'Raised', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'resolved_at', 'label' => 'Resolved', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['open' => 'red', 'in_progress' => 'amber', 'resolved' => 'green', 'closed' => 'slate']],
    ],
    'filters' => [
        ['key' => 'hostel_id', 'label' => 'Hostel', 'source' => 'hostels'],
        ['key' => 'status', 'label' => 'Status', 'options' => hostel_complaint_statuses()],
        ['key' => 'stage', 'label' => 'Stage', 'options' => ['pending' => 'Pending (open + in progress)', 'done' => 'Done (resolved + closed)'],
            'sql_map' => ['pending' => "t.status IN ('open', 'in_progress')", 'done' => "t.status IN ('resolved', 'closed')"]],
        ['key' => 'category', 'label' => 'Category', 'options' => hostel_complaint_categories()],
        ['key' => 'priority', 'label' => 'Priority', 'options' => hostel_complaint_priorities()],
        ['key' => 'assignment', 'label' => 'Assignment', 'options' => ['unassigned' => 'Unassigned', 'assigned' => 'Assigned'],
            'sql_map' => ['unassigned' => "t.assigned_to IS NULL AND t.status IN ('open', 'in_progress')", 'assigned' => 't.assigned_to IS NOT NULL']],
        ['key' => 'created_at', 'label' => 'Raised', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Where'],
        ['name' => 'hostel_id', 'label' => 'Hostel', 'type' => 'select', 'required' => true, 'source' => 'hostels', 'col' => 6],
        ['name' => 'room_id', 'label' => 'Room', 'type' => 'combobox', 'source' => $roomSource, 'depends' => ['hostel_id' => 'hostel_id'], 'col' => 6, 'placeholder' => 'Common area if blank'],
        ['name' => 'student_id', 'label' => 'Raised by (resident)', 'type' => 'combobox', 'source' => $residentSource, 'depends' => ['hostel_id' => 'hostel_id'], 'col' => 12,
            'placeholder' => 'Search resident… (leave blank if raised by staff)'],
        ['type' => 'section', 'label' => 'Complaint'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'maintenance', 'options' => hostel_complaint_categories(), 'col' => 6],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'default' => 'medium', 'options' => hostel_complaint_priorities(), 'col' => 6],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 12, 'placeholder' => 'e.g. Ceiling fan not working in Room A-204'],
        ['name' => 'description', 'label' => 'Details', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000],
        ['name' => 'assigned_staff_id', 'label' => 'Assign to', 'type' => 'combobox', 'source' => $staffSource, 'col' => 12, 'placeholder' => 'Search warden / maintenance staff…', 'create_only' => true],
    ],
    'scopes' => ['hostel_id' => 't.hostel_id', 'student_id' => 't.student_id', 'room_id' => 't.room_id', 'status' => 't.status'],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $hostelId = (int) ($data['hostel_id'] ?? ($old['hostel_id'] ?? 0));
            $roomId = array_key_exists('room_id', $data) ? $data['room_id'] : ($old['room_id'] ?? null);
            if ($roomId && (int) db_value('SELECT hostel_id FROM hostel_rooms WHERE id = ?', [(int) $roomId]) !== $hostelId) {
                $errors['room_id'] = 'The selected room is not in this hostel.';
            }
            $studentId = array_key_exists('student_id', $data) ? $data['student_id'] : ($old['student_id'] ?? null);
            // re-check the resident when it changes and when the complaint is moved to another hostel
            if ($studentId && (!$old || (int) $studentId !== (int) ($old['student_id'] ?? 0) || $hostelId !== (int) $old['hostel_id'])) {
                $stay = hostel_active_allocation((int) $studentId);
                if (!$stay) {
                    $errors['student_id'] = 'This student does not stay in a hostel at present.';
                } elseif ((int) $stay['hostel_id'] !== $hostelId) {
                    $errors['student_id'] = 'This student stays in ' . $stay['hostel_name'] . ', not the selected hostel.';
                }
            }
            if (isset($data['subject']) && mb_strlen((string) $data['subject']) < 5) {
                $errors['subject'] = 'Subject must be at least 5 characters.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if ($id === null) {
                $data['complaint_no'] = hostel_next_complaint_no();
                $data['status'] = 'open';
                if (!empty($data['student_id']) && empty($data['room_id'])) {
                    $stay = hostel_active_allocation((int) $data['student_id']);
                    $data['room_id'] = $stay ? (int) $stay['room_id'] : null;
                }
                if (!empty($data['assigned_staff_id'])) {
                    $data['assigned_to'] = mb_substr((string) hostel_staff_name((int) $data['assigned_staff_id']), 0, 150);
                    $data['assigned_at'] = date('Y-m-d H:i:s');
                }
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if ($old === null && in_array($data['priority'] ?? '', ['high', 'urgent'], true)) {
                $c = db_row('SELECT c.complaint_no, c.subject, h.code FROM hostel_complaints c JOIN hostels h ON h.id = c.hostel_id WHERE c.id = ?', [$id]);
                notify('perm:hostel', 'hostel', ucfirst($data['priority']) . ' hostel complaint', $c['complaint_no'] . ' · ' . $c['code'] . ' · ' . $c['subject'], 'admin/hostel/complaints?view=' . $id, 'alert-triangle');
            }
        },
        'transform_row' => function (array $r): array {
            if (array_key_exists('hostel_code', $r)) {
                $r['location'] = $r['hostel_code'] . ($r['room_no'] ? ' · Room ' . $r['room_no'] : ' · Common area');
                $r['complaint_sub'] = trim(($r['complaint_no'] ?? '') . ' · ' . $r['location'] . ' · ' . time_ago($r['created_at']), ' ·');
            }
            return $r;
        },
        'describe' => fn (array $r): string => ($r['complaint_no'] ?? '') . ' "' . str_limit((string) ($r['subject'] ?? ''), 60) . '"',
    ],
];
