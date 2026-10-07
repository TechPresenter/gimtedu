<?php
/**
 * Fee payments / receipts (read-only list — payments are created at the collection counter, POST /api/fees/collect,
 * and voided with POST /api/fees/payments/{id}/void).
 */
require_once APP_ROOT . '/app/services/fees.php';

return [
    'table' => 'payments',
    'title' => 'Payments',
    'singular' => 'Payment',
    'permission' => 'fees',
    'icon' => 'receipt',
    'description' => 'Receipts issued for fee payments.',
    'readonly' => true,
    'select' => "t.*, t.amount + t.fine_amount AS total_amount, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo, s.program_id,
                 pr.short_name AS program_short, CONCAT(COALESCE(s.student_uid, ''), ' · ', COALESCE(pr.short_name, '')) AS student_sub, u.name AS collected_by_name,
                 sf.invoice_no, (SELECT COUNT(*) FROM payment_allocations pa WHERE pa.payment_id = t.id) AS invoices_count,
                 (SELECT COALESCE(SUM(r.amount), 0) FROM refunds r WHERE r.payment_id = t.id AND r.status = 'processed') AS refunded_amount",
    'joins' => 'LEFT JOIN students s ON s.id = t.student_id LEFT JOIN programs pr ON pr.id = s.program_id LEFT JOIN users u ON u.id = t.collected_by LEFT JOIN student_fees sf ON sf.id = t.student_fee_id',
    'search' => ['t.receipt_no', 't.reference_no', 's.first_name', 's.last_name', 's.student_uid', "CONCAT(s.first_name, ' ', s.last_name)", 'sf.invoice_no'],
    'search_placeholder' => 'Search receipt no, student, reference…',
    'order' => 't.payment_date DESC, t.id DESC',
    'scopes' => ['student_id' => 't.student_id'],
    'default_sort' => ['key' => 'payment_date', 'dir' => 'desc'],
    'columns' => [
        ['key' => 'receipt_no', 'label' => 'Receipt', 'format' => 'code', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_sub', 'sortable' => 's.first_name'],
        ['key' => 'total_amount', 'label' => 'Amount', 'format' => 'money', 'sortable' => 't.amount + t.fine_amount'],
        ['key' => 'fine_amount', 'label' => 'Late fee', 'format' => 'money', 'hidden' => true],
        ['key' => 'discount_amount', 'label' => 'Discount', 'format' => 'money', 'hidden' => true],
        ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge', 'sortable' => true, 'colors' => ['cash' => 'green', 'upi' => 'purple', 'bank_transfer' => 'blue', 'cheque' => 'amber', 'dd' => 'amber', 'card' => 'cyan', 'online' => 'navy']],
        ['key' => 'reference_no', 'label' => 'Reference', 'format' => 'text'],
        ['key' => 'collected_by_name', 'label' => 'Collected by', 'format' => 'text', 'sortable' => 'u.name'],
        ['key' => 'payment_date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
        ['key' => 'purpose', 'label' => 'Purpose', 'format' => 'badge', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'receipt_no', 'label' => 'Receipt No'], ['key' => 'payment_date', 'label' => 'Date', 'format' => 'date'], ['key' => 'student_name', 'label' => 'Student'],
        ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'program_short', 'label' => 'Program'], ['key' => 'invoice_no', 'label' => 'Invoice'],
        ['key' => 'amount', 'label' => 'Fee amount', 'format' => 'money'], ['key' => 'fine_amount', 'label' => 'Late fee', 'format' => 'money'], ['key' => 'total_amount', 'label' => 'Total', 'format' => 'money'],
        ['key' => 'discount_amount', 'label' => 'Discount given', 'format' => 'money'], ['key' => 'mode', 'label' => 'Mode', 'format' => 'badge'], ['key' => 'reference_no', 'label' => 'Reference'],
        ['key' => 'bank_name', 'label' => 'Bank'], ['key' => 'collected_by_name', 'label' => 'Collected by'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'cancelled_reason', 'label' => 'Void reason'],
    ],
    'filters' => [
        ['key' => 'payment_date', 'label' => 'Date', 'type' => 'daterange'],
        ['key' => 'mode', 'label' => 'Mode', 'options' => fees_modes()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['success' => 'Success', 'cancelled' => 'Cancelled (void)', 'refunded' => 'Refunded', 'pending' => 'Pending', 'failed' => 'Failed']],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'collected_by', 'label' => 'Collected by', 'source' => ['table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'where' => 'u.id IN (SELECT DISTINCT collected_by FROM payments WHERE collected_by IS NOT NULL)', 'order' => 'u.name', 'search' => ['u.name']]],
        ['key' => 'purpose', 'label' => 'Purpose', 'options' => ['fee' => 'Fee', 'admission' => 'Admission', 'hostel' => 'Hostel', 'transport' => 'Transport', 'library_fine' => 'Library fine', 'exam' => 'Exam', 'other' => 'Other']],
    ],
    'fields' => [
        ['name' => 'receipt_no', 'label' => 'Receipt no', 'type' => 'text'],
        ['name' => 'payment_date', 'label' => 'Payment date', 'type' => 'date'],
        ['name' => 'amount', 'label' => 'Fee amount', 'type' => 'money'],
        ['name' => 'fine_amount', 'label' => 'Late fee', 'type' => 'money'],
        ['name' => 'discount_amount', 'label' => 'Discount given', 'type' => 'money'],
        ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => fees_modes()],
        ['name' => 'reference_no', 'label' => 'Reference', 'type' => 'text'],
        ['name' => 'bank_name', 'label' => 'Bank', 'type' => 'text'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['success' => 'Success', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'pending' => 'Pending', 'failed' => 'Failed']],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea'],
        ['name' => 'cancelled_reason', 'label' => 'Void reason', 'type' => 'text'],
    ],
    'bulk' => ['delete' => false],
    'hooks' => [
        'summary' => function (string $from, array $args): array {
            $row = db_row("SELECT COUNT(*) AS receipts, COALESCE(SUM(CASE WHEN t.status IN ('success', 'refunded') THEN t.amount + t.fine_amount ELSE 0 END), 0) AS collected,
                                  SUM(t.status = 'cancelled') AS voided, COALESCE(SUM(CASE WHEN t.status IN ('success', 'refunded') THEN t.fine_amount ELSE 0 END), 0) AS fines,
                                  COUNT(DISTINCT t.student_id) AS students" . $from, $args) ?? [];
            $row['modes'] = db_all("SELECT t.mode, COUNT(*) AS n, SUM(t.amount + t.fine_amount) AS amount" . $from . (str_contains($from, ' WHERE ') ? ' AND' : ' WHERE')
                . " t.status IN ('success', 'refunded') GROUP BY t.mode ORDER BY amount DESC", $args);
            return $row;
        },
        'describe' => fn (array $row) => $row['receipt_no'] ?? ('#' . ($row['id'] ?? '')),
    ],
];
