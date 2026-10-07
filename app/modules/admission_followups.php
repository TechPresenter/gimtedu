<?php
/** Follow-up log for applications and enquiries (calls, meetings, campus visits) with the next scheduled follow-up. */
require_once APP_ROOT . '/app/services/admissions.php';

return [
    'table' => 'admission_followups',
    'title' => 'Follow-ups',
    'singular' => 'Follow-up',
    'permission' => 'admissions',
    'icon' => 'phone-call',
    'description' => 'Counselling interactions and scheduled follow-ups.',
    'select' => "t.*, COALESCE(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), e.name) AS contact_name, COALESCE(a.phone, e.phone) AS contact_phone,
                 a.application_no, u.name AS created_by_name,
                 CASE WHEN t.next_followup_date IS NULL THEN 'logged' WHEN t.completed_at IS NOT NULL THEN 'done'
                      WHEN t.next_followup_date < CURDATE() THEN 'overdue' WHEN t.next_followup_date = CURDATE() THEN 'due_today' ELSE 'scheduled' END AS followup_state",
    'joins' => 'LEFT JOIN admissions a ON a.id = t.admission_id LEFT JOIN enquiries e ON e.id = t.enquiry_id LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['a.first_name', 'a.last_name', 'a.application_no', 'e.name', 'e.phone', 'a.phone', 't.notes'],
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'contact_name', 'label' => 'Contact', 'format' => 'person', 'sub' => 'contact_phone', 'sortable' => 'contact_name'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys(admission_followup_types()), 'slate')],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'truncate' => 70],
        ['key' => 'outcome', 'label' => 'Outcome', 'format' => 'badge'],
        ['key' => 'next_followup_date', 'label' => 'Next Follow-up', 'format' => 'date', 'sortable' => true],
        ['key' => 'followup_state', 'label' => 'State', 'format' => 'badge', 'colors' => ['logged' => 'slate', 'done' => 'green', 'overdue' => 'red', 'due_today' => 'amber', 'scheduled' => 'blue']],
        ['key' => 'created_by_name', 'label' => 'Logged By'],
        ['key' => 'created_at', 'label' => 'Logged On', 'format' => 'datetime', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => admission_followup_types()],
        ['key' => 'outcome', 'label' => 'Outcome', 'options' => admission_followup_outcomes()],
        ['key' => 'state', 'label' => 'State', 'options' => ['overdue' => 'Overdue', 'today' => 'Due today', 'scheduled' => 'Scheduled', 'done' => 'Done'],
            'sql_map' => [
                'overdue' => 't.completed_at IS NULL AND t.next_followup_date < CURDATE()',
                'today' => 't.completed_at IS NULL AND t.next_followup_date = CURDATE()',
                'scheduled' => 't.completed_at IS NULL AND t.next_followup_date > CURDATE()',
                'done' => 't.completed_at IS NOT NULL',
            ]],
        ['key' => 'created_at', 'label' => 'Logged', 'type' => 'daterange'],
    ],
    'scopes' => ['admission_id' => 't.admission_id', 'enquiry_id' => 't.enquiry_id'],
    'fields' => [
        ['name' => 'admission_id', 'label' => 'Application', 'type' => 'select', 'source' => 'admissions', 'col' => 6],
        ['name' => 'enquiry_id', 'label' => 'Enquiry', 'type' => 'select', 'col' => 6,
            'source' => ['table' => 'enquiries e', 'value' => 'e.id', 'label' => "CONCAT(e.name, ' (', e.phone, ')')", 'sub' => 'e.status', 'order' => 'e.created_at DESC', 'search' => ['e.name', 'e.phone', 'e.email'], 'async' => true]],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'call', 'options' => admission_followup_types(), 'col' => 4],
        ['name' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'options' => admission_followup_outcomes(), 'col' => 4],
        ['name' => 'next_followup_date', 'label' => 'Next follow-up', 'type' => 'date', 'col' => 4],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'required' => true, 'rows' => 3, 'maxlength' => 2000],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $e = [];
            $adm = $data['admission_id'] ?? ($old['admission_id'] ?? null);
            $enq = $data['enquiry_id'] ?? ($old['enquiry_id'] ?? null);
            if (!$adm && !$enq) {
                $e['admission_id'] = 'Choose the application or enquiry this follow-up belongs to.';
            }
            if (!empty($data['next_followup_date']) && $data['next_followup_date'] < date('Y-m-d') && (!$old || $old['next_followup_date'] !== $data['next_followup_date'])) {
                $e['next_followup_date'] = 'Next follow-up date cannot be in the past.';
            }
            return $e;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if ($old !== null) {
                return;
            }
            $f = db_row('SELECT * FROM admission_followups WHERE id = ?', [$id]);
            // A new interaction fulfils earlier open follow-ups of the same application/enquiry
            if ($f['admission_id']) {
                db_exec('UPDATE admission_followups SET completed_at = NOW(), completed_by = ? WHERE admission_id = ? AND id <> ? AND completed_at IS NULL AND next_followup_date IS NOT NULL', [user_id(), (int) $f['admission_id'], $id]);
            }
            if ($f['enquiry_id']) {
                db_exec('UPDATE admission_followups SET completed_at = NOW(), completed_by = ? WHERE enquiry_id = ? AND id <> ? AND completed_at IS NULL AND next_followup_date IS NOT NULL', [user_id(), (int) $f['enquiry_id'], $id]);
                db_exec("UPDATE enquiries SET follow_up_date = ?, status = IF(status = 'new', 'contacted', status) WHERE id = ?", [$f['next_followup_date'], (int) $f['enquiry_id']]);
            }
        },
        'describe' => fn (array $row) => label_from_key((string) ($row['type'] ?? 'follow-up')) . ' follow-up #' . ($row['id'] ?? ''),
    ],
];
