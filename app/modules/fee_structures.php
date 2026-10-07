<?php
/**
 * Fee structures per program / session / semester. Listing, filters, export and delete use the CRUD engine; the
 * SPA creates / edits them (with fee-head items and the installment plan) through POST /api/fees/structures.
 */
require_once APP_ROOT . '/app/services/fees.php';

$semesters = [];
for ($i = 1; $i <= 8; $i++) {
    $semesters[$i] = 'Semester ' . $i;
}

return [
    'table' => 'fee_structures',
    'title' => 'Fee Structures',
    'singular' => 'Fee Structure',
    'permission' => 'fees',
    'icon' => 'layers',
    'description' => 'Program-wise fee structures with fee heads and installment plans.',
    'select' => "t.*, p.short_name AS program_short, p.name AS program_name, ses.name AS session_name,
                 CASE WHEN t.semester_no IS NOT NULL THEN CONCAT('Semester ', t.semester_no) WHEN t.year_no IS NOT NULL THEN CONCAT('Year ', t.year_no) ELSE 'All' END AS period_label,
                 (SELECT COUNT(*) FROM fee_structure_items fsi WHERE fsi.fee_structure_id = t.id) AS items_count,
                 (SELECT COUNT(*) FROM fee_structure_installments fi WHERE fi.fee_structure_id = t.id) AS installments_count,
                 (SELECT COUNT(*) FROM student_fees sf WHERE sf.fee_structure_id = t.id AND sf.status <> 'cancelled') AS invoiced_count,
                 (SELECT COALESCE(SUM(sf.paid_amount), 0) FROM student_fees sf WHERE sf.fee_structure_id = t.id AND sf.status <> 'cancelled') AS collected_amount",
    'joins' => 'JOIN programs p ON p.id = t.program_id JOIN academic_sessions ses ON ses.id = t.academic_session_id',
    'search' => ['t.name', 'p.name', 'p.short_name', 't.description'],
    'order' => 'ses.start_date DESC, p.sort_order ASC, t.semester_no ASC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Structure', 'format' => 'title', 'sub' => 'program_name', 'sortable' => true],
        ['key' => 'session_name', 'label' => 'Session', 'format' => 'text', 'sortable' => 'ses.start_date'],
        ['key' => 'period_label', 'label' => 'Semester', 'format' => 'text', 'sortable' => 't.semester_no'],
        ['key' => 'items_count', 'label' => 'Heads', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'installments_count', 'label' => 'Installments', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'total_amount', 'label' => 'Total', 'format' => 'money', 'sortable' => true],
        ['key' => 'invoiced_count', 'label' => 'Students invoiced', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'collected_amount', 'label' => 'Collected', 'format' => 'money', 'sortable' => true, 'hidden' => true],
        ['key' => 'due_date', 'label' => 'Final due', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'late_fee_per_day', 'label' => 'Late fee / day', 'format' => 'money', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'semester_no', 'label' => 'Semester', 'options' => $semesters],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'invoiced', 'label' => 'Assignment', 'sql_map' => [
            'assigned' => "EXISTS (SELECT 1 FROM student_fees sfx WHERE sfx.fee_structure_id = t.id AND sfx.status <> 'cancelled')",
            'unassigned' => "NOT EXISTS (SELECT 1 FROM student_fees sfx WHERE sfx.fee_structure_id = t.id AND sfx.status <> 'cancelled')",
        ], 'options' => ['assigned' => 'Assigned to students', 'unassigned' => 'Not yet assigned']],
    ],
    // Read-only representation (create / edit go through the structure editor, see api/routes/fees.php).
    'fields' => [
        ['name' => 'name', 'label' => 'Structure name', 'type' => 'text', 'required' => true, 'col' => 12, 'maxlength' => 150],
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'required' => true, 'source' => 'sessions'],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'required' => true, 'source' => 'programs'],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'select', 'options' => $semesters],
        ['name' => 'year_no', 'label' => 'Year of study', 'type' => 'number', 'min' => 1, 'max' => 6],
        ['name' => 'total_amount', 'label' => 'Total (compulsory heads)', 'type' => 'money', 'form' => false],
        ['name' => 'due_date', 'label' => 'Final due date', 'type' => 'date'],
        ['name' => 'late_fee_per_day', 'label' => 'Late fee per day', 'type' => 'money', 'min' => 0],
        ['name' => 'late_fee_max', 'label' => 'Late fee cap', 'type' => 'money', 'min' => 0],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 255],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id): array {
            return $id === null ? ['name' => 'Create fee structures with the structure editor (fee heads and installments are required).'] : [];
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value("SELECT COUNT(*) FROM student_fees WHERE fee_structure_id = ? AND status <> 'cancelled'", [$id]);
            return $n ? '"' . $row['name'] . '" has been assigned to ' . $n . ' student(s). Mark it inactive instead.' : null;
        },
    ],
];
