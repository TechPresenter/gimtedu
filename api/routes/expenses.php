<?php
/**
 * Expenses endpoints (list / CRUD / export / import live under /api/crud/expenses and /api/crud/expense_categories).
 *
 *   GET  /expenses/summary?session_id=          KPIs, monthly spend vs fee income, category split vs budget, top vendors
 *   POST /expenses/{id}/approve                 {remarks?}                          expenses.approve
 *   POST /expenses/{id}/reject                  {remarks}                           expenses.approve
 *   POST /expenses/{id}/pay                     {payment_mode, payment_reference, paid_on?}   expenses.approve
 */
require_once APP_ROOT . '/app/services/fees.php';

route('GET', '/expenses/summary', function () {
    api_require('expenses', 'view');
    api_ok(expenses_summary(!empty($_GET['session_id']) ? (int) $_GET['session_id'] : null));
});

route('POST', '/expenses/{id:\d+}/{action:approve|reject|pay}', function ($p) {
    api_require('expenses', 'approve');
    $row = expenses_transition((int) $p['id'], $p['action'], api_input());
    $msg = ['approve' => 'Expense approved.', 'reject' => 'Expense rejected.', 'pay' => 'Expense marked as paid.'][$p['action']];
    api_ok($row, $msg);
});
