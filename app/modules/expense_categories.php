<?php
/** Expense categories with annual budgets. */
require_once APP_ROOT . '/app/services/fees.php';

return [
    'table' => 'expense_categories',
    'title' => 'Expense Categories',
    'singular' => 'Expense Category',
    'permission' => 'expenses',
    'icon' => 'folder-tree',
    'description' => 'Heads of expenditure and their annual budgets.',
    'select' => "t.*, (SELECT COALESCE(SUM(e.amount + e.tax_amount), 0) FROM expenses e WHERE e.category_id = t.id AND e.status IN ('approved', 'paid')
                       AND e.expense_date BETWEEN (SELECT start_date FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1) AND (SELECT end_date FROM academic_sessions WHERE is_current = 1 ORDER BY id DESC LIMIT 1)) AS spent_amount,
                 (SELECT COUNT(*) FROM expenses e WHERE e.category_id = t.id) AS expenses_count",
    'search' => ['t.name', 't.description'],
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Category', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'budget_amount', 'label' => 'Annual budget', 'format' => 'money', 'sortable' => true],
        ['key' => 'spent_amount', 'label' => 'Spent (session)', 'format' => 'money', 'sortable' => true],
        ['key' => 'utilization', 'label' => 'Utilisation', 'format' => 'percent', 'sortable' => 'spent_amount / NULLIF(t.budget_amount, 0)'],
        ['key' => 'expenses_count', 'label' => 'Entries', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Category name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 12, 'maxlength' => 100],
        ['name' => 'budget_amount', 'label' => 'Annual budget (₹)', 'type' => 'money', 'min' => 0],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'transform_row' => function (array $row): array {
            $row['utilization'] = (float) ($row['budget_amount'] ?? 0) > 0 ? round((float) $row['spent_amount'] * 100 / (float) $row['budget_amount'], 1) : null;
            return $row;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM expenses WHERE category_id = ?', [$id]);
            return $n ? $row['name'] . ' has ' . $n . ' expense(s). Mark it inactive instead.' : null;
        },
    ],
];
