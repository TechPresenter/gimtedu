<?php
/** Admission enquiries / leads (Admissions unit). Follow-ups + conversion via api/routes/enquiries.php. */
require_once APP_ROOT . '/app/services/admissions.php';

$counsellors = [
    'table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'sub' => 'u.designation', 'order' => 'u.name', 'search' => ['u.name', 'u.email'],
    'where' => "u.status = 'active' AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id
                LEFT JOIN permissions pm ON pm.id = rp.permission_id WHERE ur.user_id = u.id AND (r.is_super = 1 OR (pm.module = 'enquiries' AND pm.action IN ('edit','manage'))))",
];
$open = "t.status NOT IN ('converted','closed','not_interested')";

return [
    'table' => 'enquiries',
    'title' => 'Enquiries',
    'singular' => 'Enquiry',
    'permission' => 'enquiries',
    'icon' => 'message-circle',
    'description' => 'Admission leads from the website, walk-ins, calls, campaigns and fairs.',
    'select' => "t.*, p.short_name AS program_name, COALESCE(p.short_name, t.program_interest) AS interest, u.name AS assignee_name, u.avatar AS assignee_avatar,
                 a.application_no,
                 (SELECT COUNT(*) FROM admission_followups f WHERE f.enquiry_id = t.id) AS followups_count,
                 (SELECT MAX(f.created_at) FROM admission_followups f WHERE f.enquiry_id = t.id) AS last_contacted_at",
    'joins' => 'LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN admissions a ON a.id = t.admission_id',
    'search' => ['t.name', 't.phone', 't.email', 't.city', 't.program_interest', 'p.short_name'],
    'search_placeholder' => 'Search name, phone, email, city…',
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'name', 'label' => 'Lead', 'format' => 'person', 'sub' => 'phone', 'sortable' => true],
        ['key' => 'interest', 'label' => 'Program Interest', 'format' => 'title', 'sub' => 'city', 'sortable' => 'COALESCE(p.short_name, t.program_interest)'],
        ['key' => 'source', 'label' => 'Source', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys(admission_sources()), 'slate')],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge', 'sortable' => "FIELD(t.priority, 'low', 'medium', 'high')"],
        ['key' => 'assignee_name', 'label' => 'Assigned To', 'format' => 'person', 'image' => 'assignee_avatar', 'sortable' => 'u.name'],
        ['key' => 'follow_up_date', 'label' => 'Next Follow-up', 'format' => 'date', 'sortable' => true],
        ['key' => 'created_at', 'label' => 'Received', 'format' => 'date', 'sortable' => true],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true],
        ['key' => 'last_contacted_at', 'label' => 'Last Contacted', 'format' => 'datetime', 'hidden' => true, 'sortable' => true],
        ['key' => 'followups_count', 'label' => 'Follow-ups', 'format' => 'number', 'hidden' => true, 'align' => 'center'],
    ],
    'export_columns' => [
        ['key' => 'id', 'label' => 'Enquiry #'], ['key' => 'name', 'label' => 'Name'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'email', 'label' => 'Email'],
        ['key' => 'city', 'label' => 'City'], ['key' => 'interest', 'label' => 'Program Interest'], ['key' => 'source', 'label' => 'Source', 'format' => 'badge'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge'], ['key' => 'assignee_name', 'label' => 'Assigned To'],
        ['key' => 'follow_up_date', 'label' => 'Next Follow-up', 'format' => 'date'], ['key' => 'followups_count', 'label' => 'Follow-ups'], ['key' => 'application_no', 'label' => 'Application No'],
        ['key' => 'message', 'label' => 'Message'], ['key' => 'created_at', 'label' => 'Received', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => enquiry_statuses()],
        ['key' => 'source', 'label' => 'Source', 'options' => admission_sources()],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'assigned_to', 'label' => 'Assigned to', 'source' => $counsellors],
        ['key' => 'priority', 'label' => 'Priority', 'options' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']],
        ['key' => 'followup', 'label' => 'Follow-up', 'options' => ['overdue' => 'Overdue', 'today' => 'Due today', 'upcoming' => 'Upcoming', 'none' => 'Not scheduled'],
            'sql_map' => [
                'overdue' => "t.follow_up_date < CURDATE() AND $open",
                'today' => "t.follow_up_date = CURDATE() AND $open",
                'upcoming' => "t.follow_up_date > CURDATE() AND $open",
                'none' => "t.follow_up_date IS NULL AND $open",
            ]],
        ['key' => 'created_at', 'label' => 'Received', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 150, 'placeholder' => 'e.g. Rohan Verma'],
        ['name' => 'phone', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'col' => 6, 'placeholder' => '+91 98765 43210'],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'col' => 6],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 6, 'maxlength' => 80],
        ['name' => 'program_id', 'label' => 'Program of interest', 'type' => 'select', 'col' => 6, 'source' => 'programs'],
        ['name' => 'program_interest', 'label' => 'Other interest', 'type' => 'text', 'col' => 6, 'maxlength' => 190, 'placeholder' => 'When the program is not listed'],
        ['name' => 'source', 'label' => 'Source', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'walk_in', 'options' => admission_sources()],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'medium', 'options' => ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'new', 'options' => enquiry_statuses(), 'help' => 'Use "Convert to application" to mark an enquiry as converted.'],
        ['name' => 'assigned_to', 'label' => 'Assigned counsellor', 'type' => 'select', 'col' => 6, 'source' => $counsellors],
        ['name' => 'follow_up_date', 'label' => 'Next follow-up', 'type' => 'date', 'col' => 6],
        ['name' => 'message', 'label' => 'Enquiry / message', 'type' => 'textarea', 'col' => 12, 'rows' => 3, 'maxlength' => 5000],
        ['name' => 'notes', 'label' => 'Internal notes', 'type' => 'textarea', 'col' => 12, 'rows' => 2, 'maxlength' => 5000],
    ],
    'bulk' => ['delete' => true, 'status' => ['new', 'contacted', 'interested', 'not_interested', 'closed']],
    'import' => true,
    'export' => true,
    'per_page' => 25,
    'form' => ['size' => 'lg'],
    'default_sort' => ['key' => 'created_at', 'dir' => 'desc'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $e = [];
            if (!empty($data['phone']) && strlen(admission_phone_key($data['phone'])) < 10) {
                $e['phone'] = 'Enter a valid 10-digit mobile number.';
            }
            if ($id === null && !empty($data['follow_up_date']) && $data['follow_up_date'] < date('Y-m-d')) {
                $e['follow_up_date'] = 'Follow-up date cannot be in the past.';
            }
            if (($data['status'] ?? null) === 'converted' && empty($old['admission_id'])) {
                $e['status'] = 'Use "Convert to application" to mark an enquiry as converted.';
            }
            if (empty($e['phone']) && !empty($data['phone'])) {
                $dup = db_row('SELECT id, name, status FROM enquiries WHERE ' . admission_phone_sql('phone') . " = ? AND status NOT IN ('converted','closed','not_interested')" . ($id ? ' AND id <> ' . (int) $id : '') . ' LIMIT 1',
                    [admission_phone_key($data['phone'])]);
                if ($dup) {
                    $e['phone'] = sprintf('An open enquiry with this number already exists (#%d, %s — %s).', $dup['id'], $dup['name'], label_from_key($dup['status']));
                }
            }
            return $e;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null) {
                $data['ip_address'] = PHP_SAPI === 'cli' ? null : client_ip();
            }
            if ($old && $old['status'] === 'converted') {
                unset($data['status']); // converted enquiries stay converted
            }
            if (!empty($data['program_id'])) {
                $data['program_interest'] = $data['program_interest'] ?? null;
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            $assignee = $data['assigned_to'] ?? null;
            if ($old === null) {
                if (adm_is_importing()) {
                    return;
                }
                $prog = !empty($data['program_id']) ? db_value('SELECT short_name FROM programs WHERE id = ?', [(int) $data['program_id']]) : ($data['program_interest'] ?? null);
                notify('perm:enquiries', 'enquiry', 'New enquiry', $data['name'] . ' (' . $data['phone'] . ')' . ($prog ? ' — interested in ' . $prog : ''), 'admin/enquiries?q=' . rawurlencode((string) $data['phone']), 'message-circle');
            }
            if ($assignee && (int) $assignee !== (int) user_id() && (!$old || (int) $old['assigned_to'] !== (int) $assignee)) {
                notify((int) $assignee, 'enquiry', 'Enquiry assigned to you', ($data['name'] ?? $old['name']) . ' (' . ($data['phone'] ?? $old['phone']) . ')', 'admin/enquiries?q=' . rawurlencode((string) ($data['phone'] ?? $old['phone'])), 'message-circle');
            }
        },
        'before_delete' => function (int $id, array $row) {
            if (!empty($row['admission_id'])) {
                return 'Enquiry from ' . $row['name'] . ' was converted to an application and is kept for conversion reporting.';
            }
            return null;
        },
        'bulk_action' => function (string $action, array $ids, array $in) {
            if ($action !== 'assign') {
                throw new CrudException('Unknown bulk action.');
            }
            $uid = (int) ($in['value'] ?? 0);
            if ($uid && !db_value("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$uid])) {
                throw new CrudException('Select a valid counsellor.');
            }
            $n = db_exec('UPDATE enquiries SET assigned_to = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', [$uid ?: null]);
            $who = $uid ? (string) db_value('SELECT name FROM users WHERE id = ?', [$uid]) : 'nobody';
            log_activity('update', 'enquiries', implode(',', array_slice($ids, 0, 20)), sprintf('Assigned %d enquiries to %s', count($ids), $who));
            if ($uid && $uid !== (int) user_id()) {
                notify($uid, 'enquiry', count($ids) . ' enquiries assigned to you', 'Open the enquiries list to follow up.', 'admin/enquiries?f.assigned_to=' . $uid, 'message-circle');
            }
            return ['updated' => $n, 'message' => sprintf('%d enquir%s assigned to %s.', count($ids), count($ids) === 1 ? 'y' : 'ies', $who)];
        },
        'describe' => fn (array $row) => '"' . ($row['name'] ?? '') . '" (' . ($row['phone'] ?? '') . ')',
    ],
];
