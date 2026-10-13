<?php
/** Transport › Complaints (open → in progress → resolved → closed workflow via api/routes/transport.php). */
require_once APP_ROOT . '/app/services/transport.php';

return [
    'table' => 'transport_complaints',
    'title' => 'Transport Complaints',
    'singular' => 'Complaint',
    'permission' => 'transport',
    'icon' => 'message-square-warning',
    'description' => 'Complaints from students, parents and staff about routes, vehicles and crew.',
    'select' => "t.*, r.code AS route_code, r.name AS route_name, v.vehicle_no, s.student_uid, au.name AS assigned_name, ru.name AS resolved_by_name,
                 CONCAT_WS(' · ', t.complaint_no, DATE_FORMAT(t.created_at, '%d %b %Y')) AS complaint_sub,
                 CONCAT_WS(' · ', t.phone, s.student_uid) AS complainant_sub,
                 TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.resolved_at, NOW())) AS age_hours",
    'joins' => 'LEFT JOIN transport_routes r ON r.id = t.route_id LEFT JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN students s ON s.id = t.student_id
               LEFT JOIN users au ON au.id = t.assigned_to LEFT JOIN users ru ON ru.id = t.resolved_by',
    'search' => ['t.complaint_no', 't.subject', 't.complainant_name', 't.phone', 's.student_uid', 'r.code', 'v.vehicle_no'],
    'search_placeholder' => 'Search complaint no, subject, name or route…',
    'order' => "FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.created_at DESC",
    'scopes' => ['route_id' => 't.route_id', 'vehicle_id' => 't.vehicle_id', 'student_id' => 't.student_id'],
    'columns' => [
        ['key' => 'subject', 'label' => 'Complaint', 'format' => 'title', 'sub' => 'complaint_sub', 'sortable' => 't.created_at', 'link' => 'view'],
        ['key' => 'complainant_name', 'label' => 'Raised by', 'format' => 'title', 'sub' => 'complainant_sub', 'sortable' => true],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true, 'hidden' => true, 'colors' => ['delay' => 'amber', 'driver_behaviour' => 'red', 'rash_driving' => 'red', 'overcrowding' => 'purple', 'cleanliness' => 'cyan', 'breakdown' => 'slate', 'route_change' => 'blue', 'fee' => 'navy', 'other' => 'slate']],
        ['key' => 'route_code', 'label' => 'Route / vehicle', 'format' => 'title', 'sub' => 'vehicle_no', 'sortable' => 'r.code'],
        ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge', 'sortable' => "FIELD(t.priority, 'low', 'medium', 'high', 'urgent')", 'colors' => ['low' => 'slate', 'medium' => 'blue', 'high' => 'amber', 'urgent' => 'red']],
        ['key' => 'assigned_name', 'label' => 'Assigned to', 'format' => 'text', 'sortable' => 'au.name', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Raised on', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'resolution', 'label' => 'Resolution', 'format' => 'truncate', 'truncate' => 40, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['open' => 'red', 'in_progress' => 'amber', 'resolved' => 'green', 'closed' => 'slate']],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => transport_complaint_statuses()],
        ['key' => 'priority', 'label' => 'Priority', 'options' => transport_complaint_priorities()],
        ['key' => 'category', 'label' => 'Category', 'options' => transport_complaint_categories()],
        ['key' => 'route_id', 'label' => 'Route', 'source' => transport_route_source(false)],
        ['key' => 'created_at', 'label' => 'Raised', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Complainant'],
        ['name' => 'student_id', 'label' => 'Student (if a student / parent complaint)', 'type' => 'combobox', 'source' => 'all_students', 'col' => 12, 'placeholder' => 'Search student…'],
        ['name' => 'complainant_name', 'label' => 'Name', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'help' => 'Filled from the student when left blank'],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'col' => 6],
        ['type' => 'section', 'label' => 'Complaint'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'delay', 'col' => 6, 'options' => transport_complaint_categories()],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'default' => 'medium', 'col' => 6, 'options' => transport_complaint_priorities()],
        ['name' => 'route_id', 'label' => 'Route', 'type' => 'select', 'col' => 6, 'source' => transport_route_source(false)],
        ['name' => 'vehicle_id', 'label' => 'Vehicle', 'type' => 'select', 'col' => 6, 'source' => 'vehicles'],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'col' => 12, 'maxlength' => 190],
        ['name' => 'description', 'label' => 'Details', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 5000, 'col' => 12],
        ['name' => 'assigned_to', 'label' => 'Assign to', 'type' => 'select', 'col' => 6, 'source' => 'users'],
        ['name' => 'complaint_no', 'label' => 'Complaint no.', 'type' => 'text', 'form' => false],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'form' => false, 'options' => transport_complaint_statuses()],
        ['name' => 'resolution', 'label' => 'Resolution', 'type' => 'textarea', 'form' => false],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $m = array_merge($old ?? [], $data);
            $errors = [];
            if (trim((string) ($m['complainant_name'] ?? '')) === '' && empty($m['student_id'])) {
                $errors['complainant_name'] = 'Enter the name of the person raising the complaint or select a student.';
            }
            if (empty($m['route_id']) && empty($m['vehicle_id']) && !in_array($m['category'] ?? '', ['fee', 'other', 'route_change'], true)) {
                $errors['route_id'] = 'Select the route or vehicle the complaint is about.';
            }
            if (!empty($m['route_id']) && !empty($m['vehicle_id']) && !db_value('SELECT COUNT(*) FROM vehicles WHERE id = ? AND (route_id = ? OR route_id IS NULL)', [(int) $m['vehicle_id'], (int) $m['route_id']])) {
                $errors['vehicle_id'] = 'This vehicle does not run on the selected route.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id): array {
            if (!empty($data['student_id']) && empty($data['complainant_name'])) {
                $s = db_row('SELECT first_name, last_name, mobile FROM students WHERE id = ?', [(int) $data['student_id']]);
                if ($s) {
                    $data['complainant_name'] = trim($s['first_name'] . ' ' . $s['last_name']);
                    $data['phone'] = $data['phone'] ?? $s['mobile'];
                }
            }
            if ($id === null) {
                $data['complaint_no'] = transport_complaint_no();
                $data['status'] = 'open';
            }
            unset($data['resolution']);
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            $c = db_row('SELECT * FROM transport_complaints WHERE id = ?', [$id]);
            if ($old === null) {
                notify('perm:transport', 'complaint', 'New transport complaint ' . $c['complaint_no'], $c['subject'] . ' — ' . $c['complainant_name'], 'admin/transport/maintenance?tab=complaints&complaint=' . $id, 'bus');
            }
            if ($c['assigned_to'] && (!$old || (int) $old['assigned_to'] !== (int) $c['assigned_to']) && (int) $c['assigned_to'] !== (int) user_id()) {
                notify((int) $c['assigned_to'], 'complaint', 'Transport complaint assigned', $c['complaint_no'] . ' — ' . $c['subject'], 'admin/transport/maintenance?tab=complaints&complaint=' . $id, 'bus');
            }
        },
        'transform_row' => function (array $row): array {
            if (isset($row['age_hours'])) {
                $row['age_hours'] = (int) $row['age_hours'];
            }
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.status = 'open') AS open, SUM(t.status = 'in_progress') AS in_progress, SUM(t.status = 'resolved') AS resolved,
                                SUM(t.status = 'closed') AS closed, SUM(t.status IN ('open', 'in_progress') AND t.priority IN ('high', 'urgent')) AS critical,
                                AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) END) AS avg_hours
                         $from", $args) ?? [];
            return ['total' => (int) $r['total'], 'open' => (int) $r['open'], 'in_progress' => (int) $r['in_progress'], 'resolved' => (int) $r['resolved'], 'closed' => (int) $r['closed'],
                'critical' => (int) $r['critical'], 'avg_hours' => $r['avg_hours'] !== null ? round((float) $r['avg_hours'], 1) : null];
        },
        'describe' => fn (array $row) => ($row['complaint_no'] ?? '#' . $row['id']) . ' "' . str_limit((string) $row['subject'], 60) . '"',
    ],
];
