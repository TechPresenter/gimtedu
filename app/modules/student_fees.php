<?php
/**
 * Student fee invoices. Invoices are generated from fee structures ("Assign to students") or raised manually with
 * POST /api/fees/invoices; this module provides the list (filters, search, export), detail editing of title /
 * due date / remarks and deletion of invoices that never received money.
 */
require_once APP_ROOT . '/app/services/fees.php';

$semesters = [];
for ($i = 1; $i <= 8; $i++) {
    $semesters[$i] = 'Semester ' . $i;
}

return [
    'table' => 'student_fees',
    'title' => 'Invoices',
    'singular' => 'Invoice',
    'permission' => 'fees',
    'icon' => 'file-text',
    'description' => 'Fee invoices raised to students.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', s.first_name, s.last_name)) AS student_name, s.student_uid, s.photo, s.mobile, s.email, s.current_semester, s.program_id,
                 p.short_name AS program_short, ses.name AS session_name, CONCAT(s.student_uid, ' · ', COALESCE(p.short_name, '')) AS student_sub,
                 " . fees_overdue_amount_sql('t') . " AS overdue_amount,
                 (SELECT COUNT(*) FROM fee_installments fi WHERE fi.student_fee_id = t.id) AS installments_count",
    'joins' => 'JOIN students s ON s.id = t.student_id LEFT JOIN programs p ON p.id = s.program_id LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id',
    'search' => ['t.invoice_no', 't.title', 's.first_name', 's.last_name', 's.student_uid', 's.admission_no', "CONCAT(s.first_name, ' ', s.last_name)"],
    'search_placeholder' => 'Search invoice no, student name or ID…',
    'order' => 't.due_date DESC, t.id DESC',
    'scopes' => ['student_id' => 't.student_id', 'fee_structure_id' => 't.fee_structure_id'],
    'columns' => [
        ['key' => 'invoice_no', 'label' => 'Invoice', 'format' => 'code', 'sortable' => true],
        ['key' => 'student_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_sub', 'sortable' => 's.first_name', 'link' => '/students/{student_id}'],
        ['key' => 'title', 'label' => 'Title', 'format' => 'title', 'sub' => 'session_name', 'sortable' => true],
        ['key' => 'due_date', 'label' => 'Due date', 'format' => 'date', 'sortable' => true],
        ['key' => 'gross_amount', 'label' => 'Gross', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'discount_amount', 'label' => 'Discount', 'format' => 'money', 'hidden' => true],
        ['key' => 'scholarship_amount', 'label' => 'Scholarship', 'format' => 'money', 'hidden' => true],
        ['key' => 'fine_amount', 'label' => 'Late fee', 'format' => 'money', 'hidden' => true],
        ['key' => 'net_amount', 'label' => 'Net', 'format' => 'money', 'sortable' => true],
        ['key' => 'paid_amount', 'label' => 'Paid', 'format' => 'money', 'sortable' => true],
        ['key' => 'balance_amount', 'label' => 'Balance', 'format' => 'money', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'invoice_no', 'label' => 'Invoice No'], ['key' => 'student_name', 'label' => 'Student'], ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'program_short', 'label' => 'Program'],
        ['key' => 'semester_no', 'label' => 'Semester'], ['key' => 'session_name', 'label' => 'Session'], ['key' => 'title', 'label' => 'Title'], ['key' => 'due_date', 'label' => 'Due date', 'format' => 'date'],
        ['key' => 'gross_amount', 'label' => 'Gross', 'format' => 'money'], ['key' => 'discount_amount', 'label' => 'Discount', 'format' => 'money'], ['key' => 'scholarship_amount', 'label' => 'Scholarship', 'format' => 'money'],
        ['key' => 'fine_amount', 'label' => 'Late fee', 'format' => 'money'], ['key' => 'net_amount', 'label' => 'Net', 'format' => 'money'], ['key' => 'paid_amount', 'label' => 'Paid', 'format' => 'money'],
        ['key' => 'balance_amount', 'label' => 'Balance', 'format' => 'money'], ['key' => 'overdue_amount', 'label' => 'Overdue', 'format' => 'money'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 's.program_id'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => $semesters],
        ['key' => 'status', 'label' => 'Status', 'options' => ['pending' => 'Pending', 'partial' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'waived' => 'Waived', 'cancelled' => 'Cancelled']],
        ['key' => 'overdue', 'label' => 'Overdue', 'sql_map' => ['yes' => fees_overdue_sql('t'), 'no' => 'NOT ' . fees_overdue_sql('t')], 'options' => ['yes' => 'Overdue only', 'no' => 'Not overdue']],
        ['key' => 'balance', 'label' => 'Balance', 'sql_map' => ['due' => "t.balance_amount > 0 AND t.status <> 'cancelled'", 'settled' => 't.balance_amount <= 0'], 'options' => ['due' => 'Has balance', 'settled' => 'Settled']],
        ['key' => 'due_date', 'label' => 'Due date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Invoice title', 'type' => 'text', 'required' => true, 'col' => 12, 'maxlength' => 190],
        ['name' => 'due_date', 'label' => 'Due date', 'type' => 'date', 'required' => true, 'help' => 'Installment due dates are managed on the installment plan.'],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'maxlength' => 255, 'rows' => 2],
    ],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'md'],
    'hooks' => [
        'list_query' => function (array &$where, array &$args, array $req): void {
            fees_sync_overdue();
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                return ['title' => 'Raise invoices from a fee structure or with the "Raise invoice" form.'];
            }
            if ($old && $old['status'] === 'cancelled') {
                return ['title' => 'Cancelled invoices cannot be edited.'];
            }
            return [];
        },
        'after_save' => function (int $id): void {
            fees_refresh_invoice($id);
        },
        'before_delete' => function (int $id, array $row): ?string {
            $paid = fees_invoice_paid($id)['paid'];
            $payments = (int) db_value('SELECT COUNT(*) FROM payments WHERE student_fee_id = ?', [$id]) + (int) db_value('SELECT COUNT(*) FROM payment_allocations WHERE student_fee_id = ?', [$id]);
            if ($paid > 0 || $payments) {
                return $row['invoice_no'] . ' has payments recorded against it and cannot be deleted. Cancel it instead.';
            }
            return null;
        },
        'summary' => function (string $from, array $args): array {
            return db_row('SELECT COUNT(*) AS invoices, COALESCE(SUM(t.net_amount), 0) AS net, COALESCE(SUM(t.paid_amount), 0) AS paid,
                                  COALESCE(SUM(CASE WHEN t.status <> \'cancelled\' THEN t.balance_amount ELSE 0 END), 0) AS balance,
                                  COALESCE(SUM(' . fees_overdue_amount_sql('t') . '), 0) AS overdue, SUM(CASE WHEN ' . fees_overdue_sql('t') . ' THEN 1 ELSE 0 END) AS overdue_count' . $from, $args) ?? [];
        },
        'describe' => fn (array $row) => $row['invoice_no'] ?? ('#' . ($row['id'] ?? '')),
    ],
];
