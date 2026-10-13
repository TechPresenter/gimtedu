<?php
/** Library: circulation history (issue / return / renew / lost). Read-only here — loans change through the circulation API. */
require_once APP_ROOT . '/app/services/library.php';

$stateSql = "(CASE WHEN t.status = 'issued' AND t.due_date < CURDATE() THEN 'overdue'
                   WHEN t.status = 'issued' AND t.due_date = CURDATE() THEN 'due_today'
                   WHEN t.status = 'returned' AND t.return_date > t.due_date THEN 'returned_late'
                   ELSE t.status END)";

return [
    'table' => 'library_transactions',
    'title' => 'Circulation History',
    'singular' => 'Loan',
    'permission' => 'library',
    'icon' => 'arrow-left-right',
    'description' => 'Every issue, return, renewal and lost book.',
    'readonly' => true,
    'select' => "t.*, b.title AS book_title, b.isbn, c.accession_no, bc.name AS category_name, lm.membership_no, lm.member_type, " . lib_member_cols('lm') . ",
                 ui.name AS issued_by_name, ur.name AS returned_to_name, $stateSql AS state,
                 (CASE WHEN t.status = 'issued' THEN GREATEST(DATEDIFF(CURDATE(), t.due_date), 0) WHEN t.return_date IS NOT NULL THEN GREATEST(DATEDIFF(t.return_date, t.due_date), 0) ELSE 0 END) AS days_late",
    'joins' => 'JOIN books b ON b.id = t.book_id
                JOIN book_copies c ON c.id = t.book_copy_id
                LEFT JOIN book_categories bc ON bc.id = b.category_id
                JOIN library_members lm ON lm.id = t.member_id ' . lib_member_joins('lm') . '
                LEFT JOIN users ui ON ui.id = t.issued_by
                LEFT JOIN users ur ON ur.id = t.returned_to',
    'search' => ['b.title', 'b.isbn', 'c.accession_no', 'lm.membership_no', 's.first_name', 's.last_name', 's.student_uid', 'f.first_name', 'f.last_name', 'f.employee_id', 'st.first_name', 'st.last_name'],
    'search_placeholder' => 'Search book, accession no, member or membership no…',
    'order' => 't.issue_date DESC, t.id DESC',
    'default_sort' => null,
    'columns' => [
        ['key' => 'book_title', 'label' => 'Book', 'format' => 'title', 'sub' => 'accession_no', 'sortable' => 'b.title', 'link' => '/library/books/{book_id}'],
        ['key' => 'member_name', 'label' => 'Member', 'format' => 'person', 'image' => 'member_photo', 'sub' => 'membership_no', 'sortable' => 'member_name'],
        ['key' => 'member_type', 'label' => 'Type', 'format' => 'badge', 'hidden' => true, 'colors' => ['student' => 'blue', 'faculty' => 'purple', 'staff' => 'cyan']],
        ['key' => 'issue_date', 'label' => 'Issued', 'format' => 'date', 'sortable' => true],
        ['key' => 'due_date', 'label' => 'Due', 'format' => 'date', 'sortable' => true],
        ['key' => 'return_date', 'label' => 'Returned', 'format' => 'date', 'sortable' => true],
        ['key' => 'days_late', 'label' => 'Days late', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'renew_count', 'label' => 'Renewals', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'fine_amount', 'label' => 'Fine', 'format' => 'money', 'sortable' => true],
        ['key' => 'state', 'label' => 'Status', 'format' => 'badge', 'sortable' => 'state',
            'colors' => ['issued' => 'blue', 'due_today' => 'amber', 'overdue' => 'red', 'returned' => 'green', 'returned_late' => 'amber', 'lost' => 'slate']],
        ['key' => 'category_name', 'label' => 'Category', 'format' => 'text', 'hidden' => true],
        ['key' => 'issued_by_name', 'label' => 'Issued by', 'format' => 'text', 'hidden' => true],
        ['key' => 'returned_to_name', 'label' => 'Received by', 'format' => 'text', 'hidden' => true],
        ['key' => 'return_condition', 'label' => 'Return condition', 'format' => 'badge', 'hidden' => true, 'colors' => ['good' => 'green', 'damaged' => 'red']],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'issue_date', 'label' => 'Issued', 'format' => 'date'], ['key' => 'book_title', 'label' => 'Book'], ['key' => 'accession_no', 'label' => 'Accession No'],
        ['key' => 'member_name', 'label' => 'Member'], ['key' => 'membership_no', 'label' => 'Membership No'], ['key' => 'member_type', 'label' => 'Type', 'format' => 'badge'],
        ['key' => 'due_date', 'label' => 'Due', 'format' => 'date'], ['key' => 'return_date', 'label' => 'Returned', 'format' => 'date'], ['key' => 'days_late', 'label' => 'Days late', 'format' => 'number'],
        ['key' => 'renew_count', 'label' => 'Renewals', 'format' => 'number'], ['key' => 'fine_amount', 'label' => 'Fine', 'format' => 'money'], ['key' => 'state', 'label' => 'Status', 'format' => 'badge'],
        ['key' => 'issued_by_name', 'label' => 'Issued by'], ['key' => 'returned_to_name', 'label' => 'Received by'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'state', 'label' => 'Status', 'sql_map' => [
            'issued' => "t.status = 'issued'",
            'overdue' => "t.status = 'issued' AND t.due_date < CURDATE()",
            'due_today' => "t.status = 'issued' AND t.due_date = CURDATE()",
            'due_soon' => "t.status = 'issued' AND t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)",
            'returned' => "t.status = 'returned'",
            'returned_late' => "t.status = 'returned' AND t.return_date > t.due_date",
            'lost' => "t.status = 'lost'",
        ], 'options' => ['issued' => 'On loan', 'overdue' => 'Overdue', 'due_today' => 'Due today', 'due_soon' => 'Due in 3 days', 'returned' => 'Returned', 'returned_late' => 'Returned late', 'lost' => 'Lost']],
        ['key' => 'member_type', 'label' => 'Member type', 'column' => 'lm.member_type', 'options' => lib_member_types()],
        ['key' => 'category', 'label' => 'Category', 'column' => 'b.category_id', 'source' => 'book_categories'],
        ['key' => 'issue_date', 'label' => 'Issued', 'type' => 'daterange', 'column' => 't.issue_date'],
    ],
    'scopes' => ['member_id' => 't.member_id', 'book_id' => 't.book_id', 'book_copy_id' => 't.book_copy_id'],
    'fields' => [],
    'bulk' => ['delete' => false],
    'export' => true,
    'import' => false,
    'hooks' => [
        'transform_row' => function (array $row): array {
            static $rules = null;
            $rules = $rules ?? lib_rules();
            $row['accrued_fine'] = $row['status'] === 'issued' ? lib_compute_fine($row['due_date'], date('Y-m-d'), $rules)['amount'] : 0;
            $row['can_renew'] = $row['status'] === 'issued' && $row['due_date'] >= date('Y-m-d') && (int) $row['renew_count'] < $rules['max_renewals'];
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.status = 'issued') AS on_loan, SUM(t.status = 'issued' AND t.due_date < CURDATE()) AS overdue,
                                SUM(t.status = 'returned') AS returned, SUM(t.status = 'lost') AS lost, COALESCE(SUM(t.fine_amount), 0) AS fines" . $from, $args);
            return array_map(fn ($v) => $v === null ? 0 : (is_numeric($v) && str_contains((string) $v, '.') ? (float) $v : (int) $v), $r ?? []);
        },
        'describe' => fn (array $row) => 'loan #' . ($row['id'] ?? ''),
    ],
];
