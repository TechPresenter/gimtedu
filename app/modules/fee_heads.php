<?php
/** Fee heads (Admission, Tuition, Examination, Hostel, Transport, Library, Miscellaneous …). */
require_once APP_ROOT . '/app/services/fees.php';

return [
    'table' => 'fee_heads',
    'title' => 'Fee Heads',
    'singular' => 'Fee Head',
    'permission' => 'fees',
    'icon' => 'list-tree',
    'description' => 'Components that make up a fee structure and an invoice.',
    'select' => "t.*, (SELECT COUNT(*) FROM fee_structure_items fsi WHERE fsi.fee_head_id = t.id) AS structures_count,
                 (SELECT COALESCE(SUM(sfi.amount), 0) FROM student_fee_items sfi JOIN student_fees sf ON sf.id = sfi.student_fee_id WHERE sfi.fee_head_id = t.id AND sf.status <> 'cancelled') AS billed_amount",
    'search' => ['t.name', 't.code', 't.description'],
    'order' => 't.sort_order ASC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Fee head', 'format' => 'title', 'sub' => 'description', 'sortable' => true, 'link' => 'view'],
        ['key' => 'code', 'label' => 'Code', 'format' => 'code', 'sortable' => true],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['admission' => 'purple', 'tuition' => 'blue', 'examination' => 'amber', 'hostel' => 'cyan', 'transport' => 'green', 'library' => 'navy', 'miscellaneous' => 'slate']],
        ['key' => 'is_refundable', 'label' => 'Refundable', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'structures_count', 'label' => 'Used in structures', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'billed_amount', 'label' => 'Billed (all time)', 'format' => 'money', 'sortable' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => fees_head_types()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Fee head name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 8, 'maxlength' => 100, 'placeholder' => 'e.g. Tuition Fee'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'placeholder' => 'TUI', 'readonly_on_edit' => true],
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'tuition', 'options' => fees_head_types(), 'help' => 'Hostel / Transport heads marked optional in a structure apply only to hostellers / transport users.'],
        ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 999],
        ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'is_refundable', 'label' => 'Refundable (e.g. caution deposit)', 'type' => 'toggle', 'default' => false],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $s = (int) db_value('SELECT COUNT(*) FROM fee_structure_items WHERE fee_head_id = ?', [$id]);
            $i = (int) db_value('SELECT COUNT(*) FROM student_fee_items WHERE fee_head_id = ?', [$id]);
            if ($s || $i) {
                return $row['name'] . ' is used in ' . ($s ? "$s fee structure(s)" : '') . ($s && $i ? ' and ' : '') . ($i ? "$i invoice line(s)" : '') . '. Mark it inactive instead.';
            }
            return null;
        },
    ],
];
