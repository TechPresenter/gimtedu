<?php
/**
 * Refund requests against payments. Requests are created with POST /api/fees/refunds and moved through
 * approve / reject / process (mark paid) with POST /api/fees/refunds/{id}/{action} (fees › approve).
 */
require_once APP_ROOT . '/app/services/fees.php';

return [
    'table' => 'refunds',
    'title' => 'Refunds',
    'singular' => 'Refund',
    'permission' => 'fees',
    'icon' => 'undo-2',
    'description' => 'Refund requests and their approval status.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo, CONCAT(COALESCE(s.student_uid, ''), ' · ', COALESCE(pr.short_name, '')) AS student_sub,
                 p.receipt_no, CONCAT('Against ', p.receipt_no) AS against_label, p.payment_date, p.amount + p.fine_amount AS payment_total, p.mode AS payment_mode, ru.name AS requested_by_name, au.name AS approved_by_name, pu.name AS processed_by_name",
    'joins' => 'JOIN payments p ON p.id = t.payment_id LEFT JOIN students s ON s.id = t.student_id LEFT JOIN programs pr ON pr.id = s.program_id
                LEFT JOIN users ru ON ru.id = t.requested_by LEFT JOIN users au ON au.id = t.approved_by LEFT JOIN users pu ON pu.id = t.processed_by',
    'search' => ['t.refund_no', 'p.receipt_no', 's.first_name', 's.last_name', 's.student_uid', 't.reason', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search refund no, receipt, student…',
    'order' => "FIELD(t.status, 'pending', 'approved', 'processed', 'rejected'), t.created_at DESC",
    'scopes' => ['student_id' => 't.student_id', 'payment_id' => 't.payment_id'],
    'columns' => [
        ['key' => 'refund_no', 'label' => 'Refund', 'format' => 'title', 'sub' => 'against_label', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_sub', 'sortable' => 's.first_name'],
        ['key' => 'receipt_no', 'label' => 'Against receipt', 'format' => 'code', 'sortable' => 'p.receipt_no', 'hidden' => true],
        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'sortable' => true],
        ['key' => 'reason', 'label' => 'Reason', 'format' => 'truncate', 'truncate' => 70, 'width' => '260px'],
        ['key' => 'created_at', 'label' => 'Requested', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'requested_by_name', 'label' => 'Requested by', 'format' => 'text', 'hidden' => true],
        ['key' => 'refund_date', 'label' => 'Paid on', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'mode', 'label' => 'Paid via', 'format' => 'badge', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['pending' => 'amber', 'approved' => 'blue', 'processed' => 'green', 'rejected' => 'red']],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'processed' => 'Paid', 'rejected' => 'Rejected']],
        ['key' => 'created_at', 'label' => 'Requested', 'type' => 'daterange'],
        ['key' => 'mode', 'label' => 'Paid via', 'options' => fees_modes()],
    ],
    'fields' => [
        ['name' => 'refund_no', 'label' => 'Refund no', 'type' => 'text', 'form' => false],
        ['name' => 'amount', 'label' => 'Amount', 'type' => 'money', 'readonly_on_edit' => true],
        ['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea', 'required' => true, 'maxlength' => 255, 'rows' => 2],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['pending' => 'Pending', 'approved' => 'Approved', 'processed' => 'Paid', 'rejected' => 'Rejected'], 'readonly_on_edit' => true],
        ['name' => 'refund_date', 'label' => 'Refund date', 'type' => 'date', 'readonly_on_edit' => true],
        ['name' => 'mode', 'label' => 'Paid via', 'type' => 'select', 'options' => fees_modes(), 'readonly_on_edit' => true],
        ['name' => 'reference_no', 'label' => 'Reference', 'type' => 'text', 'readonly_on_edit' => true],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return ['reason' => 'Create refund requests from a payment (Payments › Request refund).'];
            }
            if ($old && $old['status'] !== 'pending') {
                return ['reason' => 'Only pending refund requests can be edited.'];
            }
            return [];
        },
        'before_delete' => function (int $id, array $row): ?string {
            return in_array($row['status'], ['pending', 'rejected'], true) ? null : $row['refund_no'] . ' is ' . $row['status'] . ' and cannot be deleted.';
        },
        'summary' => function (string $from, array $args): array {
            return db_row("SELECT COUNT(*) AS n, SUM(t.status = 'pending') AS pending, COALESCE(SUM(CASE WHEN t.status = 'pending' THEN t.amount END), 0) AS pending_amount,
                                  SUM(t.status = 'approved') AS approved, COALESCE(SUM(CASE WHEN t.status = 'approved' THEN t.amount END), 0) AS approved_amount,
                                  SUM(t.status = 'processed') AS processed, COALESCE(SUM(CASE WHEN t.status = 'processed' THEN t.amount END), 0) AS processed_amount,
                                  SUM(t.status = 'rejected') AS rejected" . $from, $args) ?? [];
        },
        'describe' => fn (array $row) => $row['refund_no'] ?? ('#' . ($row['id'] ?? '')),
    ],
];
