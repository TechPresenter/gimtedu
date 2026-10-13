<?php
/**
 * Concessions applied to invoices (scholarship awards, discounts, waivers). Created by
 * POST /api/fees/scholarships/{id}/award, POST /api/fees/invoices/{id}/concession or at the collection counter.
 * Editing the status (approve / reject) or revoking (delete) recomputes the invoice.
 */
require_once APP_ROOT . '/app/services/fees.php';

return [
    'table' => 'fee_concessions',
    'title' => 'Concessions',
    'singular' => 'Concession',
    'permission' => 'fees',
    'icon' => 'badge-percent',
    'description' => 'Scholarships, discounts and waivers applied to invoices.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo, CONCAT(s.student_uid, ' · ', COALESCE(pr.short_name, '')) AS student_sub,
                 sf.invoice_no, sf.title AS invoice_title, sf.academic_session_id, sc.name AS scholarship_name, COALESCE(sc.name, CONCAT(UPPER(LEFT(t.type, 1)), SUBSTRING(t.type, 2))) AS scheme,
                 au.name AS approved_by_name, p.receipt_no",
    'joins' => 'JOIN students s ON s.id = t.student_id JOIN student_fees sf ON sf.id = t.student_fee_id LEFT JOIN programs pr ON pr.id = s.program_id
                LEFT JOIN scholarships sc ON sc.id = t.scholarship_id LEFT JOIN users au ON au.id = t.approved_by LEFT JOIN payments p ON p.id = t.payment_id',
    'search' => ['s.first_name', 's.last_name', 's.student_uid', 'sf.invoice_no', 'sc.name', 't.reason', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search student, invoice, scheme…',
    'order' => 't.created_at DESC, t.id DESC',
    'scopes' => ['student_id' => 't.student_id', 'scholarship_id' => 't.scholarship_id', 'student_fee_id' => 't.student_fee_id'],
    'columns' => [
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_sub', 'sortable' => 's.first_name', 'link' => '/students/{student_id}'],
        ['key' => 'scheme', 'label' => 'Scheme', 'format' => 'title', 'sortable' => 'sc.name', 'width' => '220px'],
        ['key' => 'reason', 'label' => 'Reason', 'format' => 'truncate', 'truncate' => 50, 'hidden' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['scholarship' => 'blue', 'discount' => 'amber', 'waiver' => 'purple'], 'hidden' => true],
        ['key' => 'invoice_no', 'label' => 'Invoice', 'format' => 'code', 'sortable' => 'sf.invoice_no'],
        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'sortable' => true],
        ['key' => 'approved_by_name', 'label' => 'Approved by', 'format' => 'text', 'hidden' => true],
        ['key' => 'receipt_no', 'label' => 'Counter receipt', 'format' => 'code', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Date', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => fees_concession_types()],
        ['key' => 'scholarship_id', 'label' => 'Scheme', 'source' => 'scholarships'],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions', 'column' => 'sf.academic_session_id'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected']],
    ],
    'fields' => [
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => fees_concession_types(), 'readonly_on_edit' => true],
        ['name' => 'amount', 'label' => 'Amount', 'type' => 'money', 'readonly_on_edit' => true],
        ['name' => 'reason', 'label' => 'Reason / remarks', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected']],
    ],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return ['amount' => 'Award scholarships or add concessions from an invoice.'];
            }
            if ($old && ($data['status'] ?? $old['status']) === 'approved' && $old['status'] !== 'approved') {
                $inv = db_row('SELECT net_amount, paid_amount, status FROM student_fees WHERE id = ?', [(int) $old['student_fee_id']]);
                if ($inv && (float) $old['amount'] > (float) $inv['net_amount'] - (float) $inv['paid_amount']) {
                    return ['status' => 'The invoice balance is lower than this concession; it can no longer be approved.'];
                }
            }
            return [];
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if ($old) {
                fees_refresh_invoice((int) $old['student_fee_id']);
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ($row['payment_id'] && db_value("SELECT status FROM payments WHERE id = ?", [(int) $row['payment_id']]) === 'success') {
                return 'This discount was given at the counter with a receipt. Void that receipt to reverse it.';
            }
            return null;
        },
        'after_delete' => function (int $id, array $row): void {
            fees_refresh_invoice((int) $row['student_fee_id']);
        },
        'summary' => function (string $from, array $args): array {
            return db_row("SELECT COUNT(*) AS n, COUNT(DISTINCT t.student_id) AS students,
                                  COALESCE(SUM(CASE WHEN t.status = 'approved' THEN t.amount END), 0) AS approved_amount,
                                  COALESCE(SUM(CASE WHEN t.status = 'approved' AND t.type = 'scholarship' THEN t.amount END), 0) AS scholarship_amount,
                                  COALESCE(SUM(CASE WHEN t.status = 'approved' AND t.type = 'discount' THEN t.amount END), 0) AS discount_amount,
                                  COALESCE(SUM(CASE WHEN t.status = 'approved' AND t.type = 'waiver' THEN t.amount END), 0) AS waiver_amount,
                                  SUM(t.status = 'pending') AS pending" . $from, $args) ?? [];
        },
        'describe' => fn (array $row) => ucfirst((string) ($row['type'] ?? 'concession')) . ' ' . money((float) ($row['amount'] ?? 0)) . ' on invoice #' . ($row['student_fee_id'] ?? ''),
    ],
];
