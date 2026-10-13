<?php
/**
 * Institute expenses with bill attachments and an approval workflow (pending → approved → paid, or rejected).
 * Status changes go through POST /api/expenses/{id}/approve|reject|pay (expenses › approve).
 */
require_once APP_ROOT . '/app/services/fees.php';

$modes = array_diff_key(fees_modes(), ['online' => true]);

return [
    'table' => 'expenses',
    'title' => 'Expenses',
    'singular' => 'Expense',
    'permission' => 'expenses',
    'icon' => 'wallet',
    'description' => 'Institute expenditure, bills and approvals.',
    'select' => "t.*, t.amount + t.tax_amount AS total_amount, c.name AS category_name, d.name AS department_name, cu.name AS created_by_name, au.name AS approved_by_name,
                 CONCAT(t.expense_no, IFNULL(CONCAT(' · ', t.vendor), '')) AS title_sub",
    'joins' => 'JOIN expense_categories c ON c.id = t.category_id LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN users cu ON cu.id = t.created_by LEFT JOIN users au ON au.id = t.approved_by',
    'search' => ['t.expense_no', 't.title', 't.vendor', 't.reference_no', 'c.name', 't.description'],
    'search_placeholder' => 'Search expense no, title, vendor, bill no…',
    'order' => 't.expense_date DESC, t.id DESC',
    'default_sort' => ['key' => 'expense_date', 'dir' => 'desc'],
    'scopes' => ['category_id' => 't.category_id'],
    'columns' => [
        ['key' => 'title', 'label' => 'Expense', 'format' => 'title', 'sub' => 'title_sub', 'sortable' => true, 'link' => 'view', 'width' => '300px'],
        ['key' => 'category_name', 'label' => 'Category', 'format' => 'text', 'sortable' => 'c.name'],
        ['key' => 'expense_date', 'label' => 'Date', 'format' => 'date', 'sortable' => true],
        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'tax_amount', 'label' => 'Tax', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'total_amount', 'label' => 'Total', 'format' => 'money', 'sortable' => 't.amount + t.tax_amount'],
        ['key' => 'payment_mode', 'label' => 'Mode', 'format' => 'badge', 'sortable' => true],
        ['key' => 'department_name', 'label' => 'Department', 'format' => 'text', 'hidden' => true],
        ['key' => 'reference_no', 'label' => 'Bill / ref no', 'format' => 'text', 'hidden' => true],
        ['key' => 'payment_reference', 'label' => 'Payment ref (UTR)', 'format' => 'text', 'hidden' => true],
        ['key' => 'paid_at', 'label' => 'Paid on', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'created_by_name', 'label' => 'Entered by', 'format' => 'text', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'rejected' => 'red']],
    ],
    'export_columns' => [
        ['key' => 'expense_no', 'label' => 'Expense No'], ['key' => 'expense_date', 'label' => 'Date', 'format' => 'date'], ['key' => 'title', 'label' => 'Title'], ['key' => 'category_name', 'label' => 'Category'],
        ['key' => 'vendor', 'label' => 'Vendor'], ['key' => 'reference_no', 'label' => 'Bill / Ref No'], ['key' => 'department_name', 'label' => 'Department'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ['key' => 'tax_amount', 'label' => 'Tax', 'format' => 'money'], ['key' => 'total_amount', 'label' => 'Total', 'format' => 'money'], ['key' => 'payment_mode', 'label' => 'Mode', 'format' => 'badge'],
        ['key' => 'payment_reference', 'label' => 'Payment Ref'], ['key' => 'paid_at', 'label' => 'Paid On', 'format' => 'date'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'approved_by_name', 'label' => 'Approved by'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'expense_date', 'label' => 'Date', 'type' => 'daterange'],
        ['key' => 'category_id', 'label' => 'Category', 'source' => 'expense_categories'],
        ['key' => 'status', 'label' => 'Status', 'options' => fees_expense_statuses()],
        ['key' => 'payment_mode', 'label' => 'Mode', 'options' => $modes],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments'],
        ['key' => 'bill', 'label' => 'Bill', 'sql_map' => ['yes' => "t.attachment IS NOT NULL AND t.attachment <> ''", 'no' => "(t.attachment IS NULL OR t.attachment = '')"], 'options' => ['yes' => 'Bill attached', 'no' => 'No bill attached']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Expense details'],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'e.g. Electricity bill — September'],
        ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'required' => true, 'source' => 'expense_categories', 'col' => 4],
        ['name' => 'vendor', 'label' => 'Vendor / payee', 'type' => 'text', 'maxlength' => 150, 'placeholder' => 'e.g. UPPCL'],
        ['name' => 'reference_no', 'label' => 'Bill / invoice no', 'type' => 'text', 'maxlength' => 100],
        ['name' => 'amount', 'label' => 'Amount (before tax)', 'type' => 'money', 'required' => true, 'min' => 1, 'max' => 100000000, 'col' => 4],
        ['name' => 'tax_amount', 'label' => 'GST / tax', 'type' => 'money', 'default' => 0, 'min' => 0, 'max' => 50000000, 'col' => 4],
        ['name' => 'expense_date', 'label' => 'Expense date', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'payment_mode', 'label' => 'Payment mode', 'type' => 'select', 'options' => $modes],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => 'departments', 'placeholder' => 'Institute-wide'],
        ['name' => 'attachment', 'label' => 'Bill / receipt attachment', 'type' => 'file', 'category' => 'any', 'folder' => 'expense-bills', 'private' => true, 'col' => 12],
        ['name' => 'description', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000],
        ['name' => 'expense_no', 'label' => 'Expense no', 'type' => 'text', 'form' => false],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => fees_expense_statuses(), 'form' => false],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            if ($old && in_array($old['status'], ['approved', 'paid'], true)) {
                $errors['title'] = 'Approved or paid expenses cannot be edited. Reject it first if a correction is needed.';
            }
            if (!empty($data['expense_date']) && $data['expense_date'] > date('Y-m-d')) {
                $errors['expense_date'] = 'Expense date cannot be in the future.';
            }
            if (isset($data['amount'], $data['tax_amount']) && (float) $data['tax_amount'] > (float) $data['amount']) {
                $errors['tax_amount'] = 'Tax cannot be more than the amount.';
            }
            if (!empty($data['category_id']) && db_value('SELECT status FROM expense_categories WHERE id = ?', [(int) $data['category_id']]) === 'inactive') {
                $errors['category_id'] = 'This category is inactive.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                $data['expense_no'] = fees_expense_no();
                $data['status'] = 'pending';
            } elseif ($old && $old['status'] === 'rejected') {
                $data['status'] = 'pending';
                $data['remarks'] = null;
                $data['approved_by'] = null;
                $data['approved_at'] = null;
            }
            $data['tax_amount'] = $data['tax_amount'] ?? 0;
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if ($old === null && !can('expenses', 'approve')) {
                $e = db_row('SELECT expense_no, title, amount, tax_amount FROM expenses WHERE id = ?', [$id]);
                notify('perm:expenses', 'system', 'Expense awaiting approval', sprintf('%s "%s" for %s', $e['expense_no'], $e['title'], money((float) $e['amount'] + (float) $e['tax_amount'])), 'admin/expenses?f.status=pending', 'wallet');
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            return in_array($row['status'], ['pending', 'rejected'], true) || can('expenses', 'manage') ? null : $row['expense_no'] . ' is ' . $row['status'] . ' and cannot be deleted.';
        },
        'summary' => function (string $from, array $args): array {
            return db_row("SELECT COUNT(*) AS n, COALESCE(SUM(t.amount + t.tax_amount), 0) AS total, COALESCE(SUM(CASE WHEN t.status = 'pending' THEN t.amount + t.tax_amount END), 0) AS pending,
                                  SUM(t.status = 'pending') AS pending_count, COALESCE(SUM(CASE WHEN t.status IN ('approved', 'paid') THEN t.amount + t.tax_amount END), 0) AS approved,
                                  COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.amount + t.tax_amount END), 0) AS paid" . $from, $args) ?? [];
        },
        'describe' => fn (array $row) => ($row['expense_no'] ?? '') . ' "' . str_limit((string) ($row['title'] ?? ''), 60) . '"',
    ],
];
