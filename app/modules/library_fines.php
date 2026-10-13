<?php
/** Library: fines (overdue, lost, damage, other). Collect / waive through the library API; manual fines can be added here. */
require_once APP_ROOT . '/app/services/library.php';

$reasons = ['late_return' => 'Overdue return', 'lost' => 'Lost book', 'damage' => 'Damaged book', 'other' => 'Other'];

return [
    'table' => 'library_fines',
    'title' => 'Library Fines',
    'singular' => 'Fine',
    'permission' => 'library',
    'icon' => 'receipt-indian-rupee',
    'description' => 'Overdue, lost and damage fines with collection and waiver audit.',
    'select' => "t.*, lm.membership_no, lm.member_type, " . lib_member_cols('lm') . ", b.title AS book_title, b.id AS book_id, c.accession_no,
                 uc.name AS collected_by_name, uw.name AS waived_by_name",
    'joins' => 'JOIN library_members lm ON lm.id = t.member_id ' . lib_member_joins('lm') . '
                LEFT JOIN library_transactions lt ON lt.id = t.transaction_id
                LEFT JOIN books b ON b.id = lt.book_id
                LEFT JOIN book_copies c ON c.id = lt.book_copy_id
                LEFT JOIN users uc ON uc.id = t.collected_by
                LEFT JOIN users uw ON uw.id = t.waived_by',
    'search' => ['lm.membership_no', 's.first_name', 's.last_name', 's.student_uid', 'f.first_name', 'f.last_name', 'f.employee_id', 'st.first_name', 'st.last_name', 'b.title', 'c.accession_no', 't.receipt_no'],
    'search_placeholder' => 'Search member, membership no, book or receipt no…',
    'order' => "(t.status = 'unpaid') DESC, t.created_at DESC, t.id DESC",
    'columns' => [
        ['key' => 'member_name', 'label' => 'Member', 'format' => 'person', 'image' => 'member_photo', 'sub' => 'membership_no', 'sortable' => 'member_name'],
        ['key' => 'book_title', 'label' => 'Book', 'format' => 'title', 'sub' => 'accession_no', 'sortable' => 'b.title'],
        ['key' => 'reason', 'label' => 'Reason', 'format' => 'badge', 'sortable' => true, 'colors' => ['late_return' => 'amber', 'lost' => 'red', 'damage' => 'purple', 'other' => 'slate']],
        ['key' => 'days_overdue', 'label' => 'Days late', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'amount', 'label' => 'Amount', 'format' => 'money', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['unpaid' => 'red', 'paid' => 'green', 'waived' => 'slate']],
        ['key' => 'created_at', 'label' => 'Raised on', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'receipt_no', 'label' => 'Receipt', 'format' => 'code', 'sortable' => true],
        ['key' => 'paid_at', 'label' => 'Paid on', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'payment_mode', 'label' => 'Mode', 'format' => 'badge', 'hidden' => true],
        ['key' => 'collected_by_name', 'label' => 'Collected by', 'format' => 'text', 'hidden' => true],
        ['key' => 'waived_by_name', 'label' => 'Waived by', 'format' => 'text', 'hidden' => true],
        ['key' => 'waive_reason', 'label' => 'Waiver reason', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'member_type', 'label' => 'Member type', 'format' => 'badge', 'hidden' => true, 'colors' => ['student' => 'blue', 'faculty' => 'purple', 'staff' => 'cyan']],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'created_at', 'label' => 'Raised on', 'format' => 'date'], ['key' => 'member_name', 'label' => 'Member'], ['key' => 'membership_no', 'label' => 'Membership No'],
        ['key' => 'member_type', 'label' => 'Member type', 'format' => 'badge'], ['key' => 'book_title', 'label' => 'Book'], ['key' => 'accession_no', 'label' => 'Accession No'],
        ['key' => 'reason', 'label' => 'Reason', 'format' => 'badge'], ['key' => 'days_overdue', 'label' => 'Days overdue', 'format' => 'number'], ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'receipt_no', 'label' => 'Receipt No'], ['key' => 'paid_at', 'label' => 'Paid on', 'format' => 'datetime'],
        ['key' => 'payment_mode', 'label' => 'Mode', 'format' => 'badge'], ['key' => 'collected_by_name', 'label' => 'Collected by'], ['key' => 'waived_by_name', 'label' => 'Waived by'],
        ['key' => 'waive_reason', 'label' => 'Waiver reason'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['unpaid' => 'Unpaid', 'paid' => 'Paid', 'waived' => 'Waived']],
        ['key' => 'reason', 'label' => 'Reason', 'options' => $reasons],
        ['key' => 'member_type', 'label' => 'Member type', 'column' => 'lm.member_type', 'options' => lib_member_types()],
        ['key' => 'payment_mode', 'label' => 'Payment mode', 'options' => lib_payment_modes()],
        ['key' => 'created_at', 'label' => 'Raised', 'type' => 'daterange', 'column' => 't.created_at'],
    ],
    'scopes' => ['member_id' => 't.member_id', 'transaction_id' => 't.transaction_id'],
    'fields' => [
        ['name' => 'member_id', 'label' => 'Member', 'type' => 'combobox', 'required' => true, 'col' => 12, 'readonly_on_edit' => true, 'source' => lib_member_source(), 'placeholder' => 'Search member name or membership no…'],
        ['name' => 'reason', 'label' => 'Reason', 'type' => 'select', 'required' => true, 'col' => 6, 'default' => 'damage', 'options' => $reasons],
        ['name' => 'amount', 'label' => 'Amount', 'type' => 'money', 'required' => true, 'col' => 6, 'min' => 1, 'max' => 100000],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255, 'placeholder' => 'e.g. Torn pages in reference copy ACC000123'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            if ($old && $old['status'] !== 'unpaid') {
                return ['amount' => 'This fine is already ' . $old['status'] . ' and can no longer be edited.'];
            }
            return [];
        },
        'after_save' => function (int $id, array $data, ?array $old): void {
            if ($old === null) {
                $m = lib_member((int) $data['member_id']);
                if ($m && $m['user_id']) {
                    notify($m['user_id'], 'system', 'Library fine raised', sprintf('A library fine of %s (%s) has been added to your account.', money($data['amount'], 2), strtolower(label_from_key((string) $data['reason']))), null, 'receipt-indian-rupee');
                }
            }
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ($row['status'] !== 'unpaid') {
                return 'This fine is ' . $row['status'] . ' and is part of the receipt audit trail, so it cannot be deleted.';
            }
            return $row['transaction_id'] ? 'Fines raised at return / lost cannot be deleted. Waive the fine with a reason instead.' : null;
        },
        'transform_row' => function (array $row): array {
            $row['payment_mode_label'] = $row['payment_mode'] ? (lib_payment_modes()[$row['payment_mode']] ?? label_from_key($row['payment_mode'])) : null;
            return $row;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS count, COALESCE(SUM(t.amount), 0) AS total,
                                COALESCE(SUM(CASE WHEN t.status = 'unpaid' THEN t.amount END), 0) AS unpaid, SUM(t.status = 'unpaid') AS unpaid_count,
                                COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.amount END), 0) AS paid, SUM(t.status = 'paid') AS paid_count,
                                COALESCE(SUM(CASE WHEN t.status = 'waived' THEN t.amount END), 0) AS waived, SUM(t.status = 'waived') AS waived_count" . $from, $args) ?? [];
            return array_map(fn ($v) => (float) ($v ?? 0), $r);
        },
        'describe' => fn (array $row) => money($row['amount'] ?? 0, 2) . ' (' . label_from_key((string) ($row['reason'] ?? '')) . ')',
    ],
];
