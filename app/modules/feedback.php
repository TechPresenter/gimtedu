<?php
/**
 * Feedback, complaints, suggestions and grievances. Workflow open -> in_progress -> resolved -> closed is changed via
 * POST /api/feedback/{id}/status (feedback_set_status) so resolution notes and timestamps are enforced.
 */
require_once APP_ROOT . '/app/services/admissions.php';

$assignees = [
    'table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'sub' => 'u.designation', 'where' => "u.status = 'active'", 'order' => 'u.name', 'search' => ['u.name', 'u.email'],
];
$ageHours = 'TIMESTAMPDIFF(HOUR, t.created_at, COALESCE(t.resolved_at, NOW()))';

return [
    'table' => 'feedback',
    'title' => 'Feedback & Complaints',
    'singular' => 'Ticket',
    'permission' => 'feedback',
    'icon' => 'message-square',
    'description' => 'Feedback, complaints, suggestions and grievances with SLA tracking.',
    'select' => "t.*, u.name AS assignee_name, u.avatar AS assignee_avatar, st.student_uid, rb.name AS resolved_by_name,
                 $ageHours AS age_hours,
                 CASE t.priority WHEN 'urgent' THEN 24 WHEN 'high' THEN 48 WHEN 'low' THEN 168 ELSE 96 END AS sla_hours,
                 IF(" . feedback_breach_sql('t') . ", 1, 0) AS sla_breached",
    'joins' => 'LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN students st ON st.id = t.student_id LEFT JOIN users rb ON rb.id = t.resolved_by',
    'search' => ['t.ticket_no', 't.subject', 't.name', 't.email', 't.phone', 't.message', 'st.student_uid'],
    'search_placeholder' => 'Search ticket, subject, name…',
    'order' => "FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.created_at DESC",
    'columns' => [
        ['key' => 'ticket_no', 'label' => 'Ticket', 'format' => 'code', 'sortable' => true],
        ['key' => 'subject', 'label' => 'Subject', 'format' => 'title', 'sub' => 'name', 'sortable' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'hidden' => true, 'colors' => ['feedback' => 'blue', 'complaint' => 'red', 'suggestion' => 'cyan', 'grievance' => 'purple']],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys(feedback_categories()), 'slate')],
        ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge', 'sortable' => "FIELD(t.priority, 'low', 'medium', 'high', 'urgent')", 'colors' => ['low' => 'slate', 'medium' => 'blue', 'high' => 'amber', 'urgent' => 'red']],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => "FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed')", 'colors' => ['open' => 'blue', 'in_progress' => 'amber', 'resolved' => 'green', 'closed' => 'slate']],
        ['key' => 'age_hours', 'label' => 'Age / SLA', 'format' => 'number', 'sortable' => $ageHours],
        ['key' => 'assignee_name', 'label' => 'Assigned To', 'format' => 'person', 'image' => 'assignee_avatar', 'sortable' => 'u.name'],
        ['key' => 'rating', 'label' => 'Rating', 'format' => 'number', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'submitted_by_type', 'label' => 'Submitted By', 'format' => 'badge', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Submitted', 'format' => 'date', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'ticket_no', 'label' => 'Ticket'], ['key' => 'type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'category', 'label' => 'Category', 'format' => 'badge'],
        ['key' => 'priority', 'label' => 'Priority', 'format' => 'badge'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'subject', 'label' => 'Subject'],
        ['key' => 'message', 'label' => 'Details'], ['key' => 'name', 'label' => 'Submitted By'], ['key' => 'submitted_by_type', 'label' => 'Role', 'format' => 'badge'],
        ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'assignee_name', 'label' => 'Assigned To'], ['key' => 'rating', 'label' => 'Rating'],
        ['key' => 'age_hours', 'label' => 'Age (hours)'], ['key' => 'resolution', 'label' => 'Resolution'], ['key' => 'resolved_at', 'label' => 'Resolved At', 'format' => 'datetime'],
        ['key' => 'created_at', 'label' => 'Submitted', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => feedback_statuses()],
        ['key' => 'type', 'label' => 'Type', 'options' => feedback_types()],
        ['key' => 'category', 'label' => 'Category', 'options' => feedback_categories()],
        ['key' => 'priority', 'label' => 'Priority', 'options' => ['urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low']],
        ['key' => 'sla', 'label' => 'SLA', 'options' => ['breached' => 'SLA breached', 'within' => 'Within SLA'],
            'sql_map' => ['breached' => feedback_breach_sql('t'), 'within' => "t.status IN ('open','in_progress') AND NOT " . feedback_breach_sql('t')]],
        ['key' => 'assigned_to', 'label' => 'Assigned to', 'source' => $assignees],
        ['key' => 'created_at', 'label' => 'Submitted', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Ticket', 'name' => 'sec_ticket'],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'complaint', 'col' => 4, 'options' => feedback_types()],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'col' => 4, 'options' => feedback_categories()],
        ['name' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'default' => 'medium', 'col' => 4, 'options' => ['urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low']],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'col' => 12, 'maxlength' => 255],
        ['name' => 'message', 'label' => 'Details', 'type' => 'textarea', 'required' => true, 'col' => 12, 'rows' => 4, 'maxlength' => 10000],
        ['name' => 'assigned_to', 'label' => 'Assign to', 'type' => 'select', 'col' => 6, 'source' => $assignees],
        ['name' => 'rating', 'label' => 'Rating (1-5)', 'type' => 'number', 'col' => 6, 'min' => 1, 'max' => 5],
        ['type' => 'section', 'label' => 'Submitted by', 'name' => 'sec_person'],
        ['name' => 'submitted_by_type', 'label' => 'Submitted by', 'type' => 'select', 'required' => true, 'default' => 'student', 'col' => 6,
            'options' => ['student' => 'Student', 'parent' => 'Parent', 'faculty' => 'Faculty', 'staff' => 'Staff', 'alumni' => 'Alumni', 'visitor' => 'Visitor']],
        ['name' => 'student_id', 'label' => 'Student (optional)', 'type' => 'select', 'col' => 6, 'source' => 'students'],
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 150],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'col' => 4],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'col' => 4],
        ['name' => 'is_anonymous', 'label' => 'Keep identity confidential', 'type' => 'toggle', 'col' => 12],
        ['name' => 'ticket_no', 'label' => 'Ticket no', 'type' => 'text', 'form' => false, 'import' => false],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'per_page' => 25,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $e = [];
            if (!empty($data['student_id']) && ($data['submitted_by_type'] ?? ($old['submitted_by_type'] ?? 'student')) !== 'student' && ($data['submitted_by_type'] ?? '') !== 'parent') {
                $e['student_id'] = 'Only students or parents can be linked to a student record.';
            }
            if (($data['category'] ?? null) === 'ragging' && ($data['priority'] ?? 'urgent') !== 'urgent') {
                $e['priority'] = 'Ragging / harassment complaints must be marked Urgent.';
            }
            return $e;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null) {
                $data['ticket_no'] = next_number('feedback_ticket', 'TKT-{Y}-{n:5}');
                $data['status'] = 'open';
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            $f = db_row('SELECT * FROM feedback WHERE id = ?', [$id]);
            if ($old === null && !adm_is_importing()) {
                notify('perm:feedback', 'feedback', 'New ' . $f['type'] . ' ' . $f['ticket_no'], ($f['is_anonymous'] ? 'Anonymous' : $f['name']) . ': ' . str_limit($f['subject'], 80), 'admin/feedback?id=' . $id,
                    $f['type'] === 'complaint' || $f['type'] === 'grievance' ? 'alert-triangle' : 'message-square');
            }
            if ($f['assigned_to'] && (int) $f['assigned_to'] !== (int) user_id() && (!$old || (int) $old['assigned_to'] !== (int) $f['assigned_to'])) {
                notify((int) $f['assigned_to'], 'feedback', 'Ticket assigned to you', $f['ticket_no'] . ': ' . str_limit($f['subject'], 80), 'admin/feedback?id=' . $id, 'message-square');
            }
        },
        'bulk_action' => function (string $action, array $ids, array $in) {
            if ($action !== 'assign') {
                throw new CrudException('Unknown bulk action.');
            }
            $uid = (int) ($in['value'] ?? 0);
            if ($uid && !db_value("SELECT COUNT(*) FROM users WHERE id = ? AND status = 'active'", [$uid])) {
                throw new CrudException('Select a valid staff member.');
            }
            $n = db_exec('UPDATE feedback SET assigned_to = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', [$uid ?: null]);
            $who = $uid ? (string) db_value('SELECT name FROM users WHERE id = ?', [$uid]) : 'nobody';
            log_activity('update', 'feedback', implode(',', array_slice($ids, 0, 20)), sprintf('Assigned %d tickets to %s', count($ids), $who));
            if ($uid && $uid !== (int) user_id()) {
                notify($uid, 'feedback', count($ids) . ' tickets assigned to you', 'Open Feedback & Complaints to respond.', 'admin/feedback', 'message-square');
            }
            return ['updated' => $n, 'message' => sprintf('%d ticket%s assigned to %s.', count($ids), count($ids) === 1 ? '' : 's', $who)];
        },
        'transform_row' => function (array $r): array {
            if (!empty($r['is_anonymous'])) {
                $r['name_display'] = 'Anonymous';
            }
            $r['sla_breached'] = (int) ($r['sla_breached'] ?? 0);
            $r['age_hours'] = (int) ($r['age_hours'] ?? 0);
            return $r;
        },
        'summary' => function (string $from, array $args): array {
            return ['statuses' => array_map('intval', db_pairs('SELECT t.status, COUNT(*)' . $from . ' GROUP BY t.status', $args))];
        },
        'describe' => fn (array $row) => ($row['ticket_no'] ?? '#' . ($row['id'] ?? '')) . ' "' . str_limit((string) ($row['subject'] ?? ''), 60) . '"',
    ],
];
