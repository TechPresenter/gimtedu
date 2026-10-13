<?php
/** Library: members (students, faculty, staff) with borrowing limits and validity. */
require_once APP_ROOT . '/app/services/library.php';

$personSource = function (string $type): array {
    $map = [
        'student' => ['table' => 'students s', 'joins' => 'LEFT JOIN programs sp ON sp.id = s.program_id', 'value' => 's.id',
            'label' => "CONCAT(TRIM(CONCAT_WS(' ', s.first_name, s.last_name)), ' (', s.student_uid, ')')", 'sub' => "CONCAT(sp.short_name, ' · Sem ', s.current_semester)",
            'where' => "s.status = 'active'", 'order' => 's.first_name, s.last_name', 'search' => ['s.first_name', 's.last_name', 's.student_uid', 's.admission_no'], 'async' => true],
        'faculty' => ['table' => 'faculty f', 'value' => 'f.id', 'label' => "CONCAT(TRIM(CONCAT_WS(' ', f.title, f.first_name, f.last_name)), ' (', f.employee_id, ')')", 'sub' => 'f.designation',
            'where' => "f.status IN ('active','on_leave')", 'order' => 'f.first_name', 'search' => ['f.first_name', 'f.last_name', 'f.employee_id'], 'async' => true],
        'staff' => ['table' => 'staff st', 'value' => 'st.id', 'label' => "CONCAT(TRIM(CONCAT_WS(' ', st.first_name, st.last_name)), ' (', st.employee_id, ')')", 'sub' => 'st.designation',
            'where' => "st.status IN ('active','on_leave')", 'order' => 'st.first_name', 'search' => ['st.first_name', 'st.last_name', 'st.employee_id'], 'async' => true],
    ];
    return $map[$type];
};

return [
    'table' => 'library_members',
    'title' => 'Library Members',
    'singular' => 'Member',
    'permission' => 'library',
    'icon' => 'id-card',
    'description' => 'Students, faculty and staff who can borrow from the library.',
    'select' => 't.*, ' . lib_member_cols('t') . ', md.name AS department_name,
                 (SELECT COUNT(*) FROM library_transactions lt WHERE lt.member_id = t.id AND lt.status = \'issued\') AS loans,
                 (SELECT COUNT(*) FROM library_transactions lt WHERE lt.member_id = t.id AND lt.status = \'issued\' AND lt.due_date < CURDATE()) AS overdue_count,
                 (SELECT COALESCE(SUM(fn.amount), 0) FROM library_fines fn WHERE fn.member_id = t.id AND fn.status = \'unpaid\') AS unpaid_fines,
                 (SELECT COUNT(*) FROM library_transactions lt WHERE lt.member_id = t.id) AS total_loans,
                 (CASE WHEN t.status = \'active\' AND t.valid_until IS NOT NULL AND t.valid_until < CURDATE() THEN \'expired\' ELSE t.status END) AS membership_state',
    'joins' => lib_member_joins('t'),
    'search' => ['t.membership_no', 's.first_name', 's.last_name', 's.student_uid', "CONCAT(s.first_name, ' ', s.last_name)", 'f.first_name', 'f.last_name', 'f.employee_id',
        "CONCAT(f.first_name, ' ', f.last_name)", 'st.first_name', 'st.last_name', 'st.employee_id'],
    'search_placeholder' => 'Search name, membership no or student / employee ID…',
    'order' => 't.membership_no ASC',
    'columns' => [
        ['key' => 'member_name', 'label' => 'Member', 'format' => 'person', 'image' => 'member_photo', 'sub' => 'member_info', 'sortable' => 'member_name'],
        ['key' => 'membership_no', 'label' => 'Membership No', 'format' => 'code', 'sortable' => true, 'hidden' => true],
        ['key' => 'member_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['student' => 'blue', 'faculty' => 'purple', 'staff' => 'cyan']],
        ['key' => 'member_code', 'label' => 'Student / Employee ID', 'format' => 'text', 'hidden' => true],
        ['key' => 'department_name', 'label' => 'Department', 'format' => 'text', 'hidden' => true, 'sortable' => 'md.name'],
        ['key' => 'loans', 'label' => 'Books issued', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'max_books', 'label' => 'Limit', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'loan_days', 'label' => 'Loan days', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'overdue_count', 'label' => 'Overdue', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'unpaid_fines', 'label' => 'Unpaid fines', 'format' => 'money', 'sortable' => true],
        ['key' => 'total_loans', 'label' => 'Lifetime loans', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'valid_until', 'label' => 'Valid till', 'format' => 'date', 'sortable' => true],
        ['key' => 'membership_state', 'label' => 'Status', 'format' => 'badge', 'sortable' => 't.status', 'colors' => ['active' => 'green', 'suspended' => 'red', 'expired' => 'amber']],
        ['key' => 'member_email', 'label' => 'Email', 'format' => 'email', 'hidden' => true],
        ['key' => 'member_phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'remarks', 'label' => 'Remarks', 'format' => 'truncate', 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'membership_no', 'label' => 'Membership No'], ['key' => 'member_name', 'label' => 'Name'], ['key' => 'member_type', 'label' => 'Type', 'format' => 'badge'],
        ['key' => 'member_code', 'label' => 'Student / Employee ID'], ['key' => 'member_info', 'label' => 'Program / Designation'], ['key' => 'department_name', 'label' => 'Department'],
        ['key' => 'member_email', 'label' => 'Email'], ['key' => 'member_phone', 'label' => 'Phone'], ['key' => 'max_books', 'label' => 'Book limit', 'format' => 'number'],
        ['key' => 'loan_days', 'label' => 'Loan days', 'format' => 'number'], ['key' => 'loans', 'label' => 'Books issued', 'format' => 'number'],
        ['key' => 'overdue_count', 'label' => 'Overdue', 'format' => 'number'], ['key' => 'unpaid_fines', 'label' => 'Unpaid fines', 'format' => 'money'],
        ['key' => 'valid_until', 'label' => 'Valid till', 'format' => 'date'], ['key' => 'membership_state', 'label' => 'Status', 'format' => 'badge'], ['key' => 'remarks', 'label' => 'Remarks'],
    ],
    'filters' => [
        ['key' => 'member_type', 'label' => 'Type', 'options' => lib_member_types()],
        ['key' => 'status', 'label' => 'Status', 'sql_map' => [
            'active' => "t.status = 'active' AND (t.valid_until IS NULL OR t.valid_until >= CURDATE())",
            'suspended' => "t.status = 'suspended'",
            'expired' => "t.status = 'expired' OR (t.status = 'active' AND t.valid_until < CURDATE())",
        ], 'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'expired' => 'Expired']],
        ['key' => 'department', 'label' => 'Department', 'column' => 'md.id', 'source' => 'departments'],
        ['key' => 'program', 'label' => 'Program', 'column' => 's.program_id', 'source' => 'programs'],
        ['key' => 'section', 'label' => 'Section', 'column' => 's.section_id', 'source' => 'sections', 'depends' => ['program_id' => 'program']],
        ['key' => 'loans_state', 'label' => 'Loans', 'sql_map' => [
            'borrowing' => "EXISTS (SELECT 1 FROM library_transactions x WHERE x.member_id = t.id AND x.status = 'issued')",
            'overdue' => "EXISTS (SELECT 1 FROM library_transactions x WHERE x.member_id = t.id AND x.status = 'issued' AND x.due_date < CURDATE())",
            'none' => "NOT EXISTS (SELECT 1 FROM library_transactions x WHERE x.member_id = t.id AND x.status = 'issued')",
        ], 'options' => ['borrowing' => 'Has books issued', 'overdue' => 'Has overdue books', 'none' => 'No books issued']],
        ['key' => 'fines_state', 'label' => 'Fines', 'sql_map' => [
            'unpaid' => "EXISTS (SELECT 1 FROM library_fines x WHERE x.member_id = t.id AND x.status = 'unpaid')",
            'clear' => "NOT EXISTS (SELECT 1 FROM library_fines x WHERE x.member_id = t.id AND x.status = 'unpaid')",
        ], 'options' => ['unpaid' => 'Has unpaid fines', 'clear' => 'No dues']],
    ],
    'scopes' => ['student_id' => 't.student_id', 'faculty_id' => 't.faculty_id', 'staff_id' => 't.staff_id'],
    'fields' => [
        ['name' => 'member_type', 'label' => 'Member type', 'type' => 'select', 'required' => true, 'col' => 4, 'readonly_on_edit' => true, 'default' => 'student', 'options' => lib_member_types()],
        ['name' => 'student_id', 'label' => 'Student', 'type' => 'combobox', 'col' => 8, 'readonly_on_edit' => true, 'source' => $personSource('student'), 'placeholder' => 'Search student…'],
        ['name' => 'faculty_id', 'label' => 'Faculty', 'type' => 'combobox', 'col' => 8, 'readonly_on_edit' => true, 'source' => $personSource('faculty'), 'placeholder' => 'Search faculty…'],
        ['name' => 'staff_id', 'label' => 'Staff', 'type' => 'combobox', 'col' => 8, 'readonly_on_edit' => true, 'source' => $personSource('staff'), 'placeholder' => 'Search staff…'],
        ['name' => 'membership_no', 'label' => 'Membership number', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 30, 'readonly_on_edit' => true, 'rules' => 'alpha_dash',
            'placeholder' => 'Auto-generated'],
        ['name' => 'max_books', 'label' => 'Books allowed', 'type' => 'number', 'col' => 4, 'min' => 1, 'max' => 30, 'help' => 'Blank = default for the member type'],
        ['name' => 'loan_days', 'label' => 'Loan period (days)', 'type' => 'number', 'col' => 4, 'min' => 1, 'max' => 365],
        ['name' => 'valid_until', 'label' => 'Valid until', 'type' => 'date', 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'col' => 6, 'default' => 'active', 'options' => ['active' => 'Active', 'suspended' => 'Suspended', 'expired' => 'Expired']],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            if ($id === null) {
                $type = $data['member_type'] ?? '';
                $col = $type . '_id';
                if (!in_array($type, ['student', 'faculty', 'staff'], true)) {
                    return $errors;
                }
                $pid = (int) ($data[$col] ?? 0);
                if (!$pid) {
                    $errors[$col] = 'Select the ' . $type . ' to enroll.';
                } elseif ($existing = db_value("SELECT membership_no FROM library_members WHERE $col = ?", [$pid])) {
                    $errors[$col] = 'This ' . $type . ' is already a library member (' . $existing . ').';
                }
            }
            if (!empty($data['valid_until']) && $data['valid_until'] < date('Y-m-d') && ($data['status'] ?? ($old['status'] ?? 'active')) === 'active') {
                $errors['valid_until'] = 'Validity date is in the past. Extend it, or set the status to expired.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if ($id === null) {
                $type = $data['member_type'];
                foreach (['student', 'faculty', 'staff'] as $t) {
                    if ($t !== $type) {
                        $data[$t . '_id'] = null;
                    }
                }
                $data['membership_no'] = !empty($data['membership_no']) ? strtoupper($data['membership_no']) : lib_next_membership_no();
                $defaults = lib_rules()['types'][$type];
                $data['max_books'] = !empty($data['max_books']) ? $data['max_books'] : $defaults['max_books'];
                $data['loan_days'] = !empty($data['loan_days']) ? $data['loan_days'] : $defaults['loan_days'];
                if (empty($data['valid_until'])) {
                    $data['valid_until'] = lib_default_valid_until();
                }
            } else {
                $defaults = lib_rules()['types'][$old['member_type']] ?? ['max_books' => 3, 'loan_days' => 14];
                if (array_key_exists('max_books', $data) && empty($data['max_books'])) {
                    $data['max_books'] = $defaults['max_books'];
                }
                if (array_key_exists('loan_days', $data) && empty($data['loan_days'])) {
                    $data['loan_days'] = $defaults['loan_days'];
                }
                // Blank validity = end of the current session (same rule as on create; the form hint says so).
                if (array_key_exists('valid_until', $data) && empty($data['valid_until'])) {
                    $data['valid_until'] = lib_default_valid_until();
                }
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $loans = (int) db_value('SELECT COUNT(*) FROM library_transactions WHERE member_id = ?', [$id]);
            if ($loans) {
                return 'Member ' . $row['membership_no'] . ' has ' . $loans . ' circulation record' . ($loans > 1 ? 's' : '') . ' and cannot be deleted. Suspend the membership instead.';
            }
            $fines = (int) db_value('SELECT COUNT(*) FROM library_fines WHERE member_id = ?', [$id]);
            return $fines ? 'Member ' . $row['membership_no'] . ' has fine records and cannot be deleted. Suspend the membership instead.' : null;
        },
        'describe' => function (array $row): string {
            $m = lib_member((int) ($row['id'] ?? 0));
            return ($row['membership_no'] ?? '') . ($m ? ' (' . $m['name'] . ')' : '');
        },
    ],
];
