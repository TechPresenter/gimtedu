<?php
/** Scholarship / discount schemes. Awarding to students goes through POST /api/fees/scholarships/{id}/award. */
require_once APP_ROOT . '/app/services/fees.php';

return [
    'table' => 'scholarships',
    'title' => 'Scholarships',
    'singular' => 'Scholarship',
    'permission' => 'fees',
    'icon' => 'award',
    'description' => 'Scholarship, merit and concession schemes.',
    'select' => "t.*, CASE WHEN t.type = 'percentage' THEN CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM t.value)), '% of tuition') ELSE CONCAT('₹', FORMAT(t.value, 0, 'en_IN'), ' flat') END AS value_label,
                 (SELECT COUNT(DISTINCT fc.student_id) FROM fee_concessions fc WHERE fc.scholarship_id = t.id AND fc.status = 'approved') AS awarded_count,
                 (SELECT COALESCE(SUM(fc.amount), 0) FROM fee_concessions fc WHERE fc.scholarship_id = t.id AND fc.status = 'approved') AS awarded_amount",
    'search' => ['t.name', 't.code', 't.criteria'],
    'order' => 't.status ASC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Scheme', 'format' => 'title', 'sub' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true, 'colors' => ['merit' => 'blue', 'sports' => 'green', 'sibling' => 'purple', 'staff_ward' => 'cyan', 'scholarship' => 'navy', 'discount' => 'amber', 'waiver' => 'purple']],
        ['key' => 'value_label', 'label' => 'Benefit', 'format' => 'text', 'sortable' => 't.value'],
        ['key' => 'max_amount', 'label' => 'Cap / student', 'format' => 'money', 'sortable' => true],
        ['key' => 'criteria', 'label' => 'Eligibility criteria', 'format' => 'truncate', 'truncate' => 60],
        ['key' => 'awarded_count', 'label' => 'Students', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'awarded_amount', 'label' => 'Total awarded', 'format' => 'money', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => fees_scholarship_categories()],
        ['key' => 'type', 'label' => 'Benefit type', 'options' => ['percentage' => 'Percentage', 'fixed' => 'Fixed amount']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Scheme name', 'type' => 'text', 'required' => true, 'unique' => true, 'col' => 8, 'maxlength' => 150, 'placeholder' => 'e.g. Merit Scholarship (90%+)'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'col' => 4, 'maxlength' => 20, 'rules' => 'alpha_dash', 'unique' => true, 'placeholder' => 'MERIT90'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'scholarship', 'options' => fees_scholarship_categories(), 'col' => 4],
        ['name' => 'type', 'label' => 'Benefit type', 'type' => 'select', 'required' => true, 'default' => 'percentage', 'options' => ['percentage' => 'Percentage of tuition', 'fixed' => 'Fixed amount'], 'col' => 4],
        ['name' => 'value', 'label' => 'Value (% or ₹)', 'type' => 'decimal', 'required' => true, 'min' => 0.01, 'step' => 0.01, 'col' => 4],
        ['name' => 'max_amount', 'label' => 'Maximum per student (₹)', 'type' => 'money', 'min' => 0, 'help' => 'Optional cap for percentage schemes.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'criteria', 'label' => 'Eligibility criteria', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000, 'placeholder' => 'e.g. 90% or above in class XII; renewed every semester on SGPA ≥ 8.0'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $type = $data['type'] ?? ($old['type'] ?? 'percentage');
            $value = $data['value'] ?? ($old['value'] ?? null);
            if ($type === 'percentage' && $value !== null && (float) $value > 100) {
                return ['value' => 'A percentage scholarship cannot exceed 100%.'];
            }
            return [];
        },
        'before_save' => function (array $data): array {
            if (!empty($data['code'])) {
                $data['code'] = strtoupper($data['code']);
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM fee_concessions WHERE scholarship_id = ?', [$id]);
            return $n ? $row['name'] . ' has been awarded ' . $n . ' time(s). Mark it inactive instead.' : null;
        },
    ],
];
