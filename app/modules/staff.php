<?php
/** Non-teaching staff — Faculty & Staff management. Profile at /staff/{id}. Permissions come from the "faculty" module. */
require_once APP_ROOT . '/app/services/hr.php';

$categories = ['administration' => 'Administration', 'accounts' => 'Accounts', 'library' => 'Library', 'hostel' => 'Hostel', 'transport' => 'Transport',
    'maintenance' => 'Maintenance', 'security' => 'Security', 'it' => 'IT & Systems', 'laboratory' => 'Laboratory', 'other' => 'Other'];
$employment = ['permanent' => 'Permanent', 'contract' => 'Contract', 'probation' => 'Probation', 'outsourced' => 'Outsourced', 'part_time' => 'Part-time'];
$statuses = ['active' => 'Active', 'on_leave' => 'On Leave', 'resigned' => 'Resigned', 'retired' => 'Retired', 'inactive' => 'Inactive'];
$today = date('Y-m-d');
$staffRoleId = (int) db_value("SELECT id FROM roles WHERE slug = 'staff'");

return [
    'table' => 'staff',
    'title' => 'Staff',
    'singular' => 'Staff Member',
    'permission' => 'staff',
    'permissions' => [
        'view' => ['faculty', 'view'], 'create' => ['faculty', 'create'], 'edit' => ['faculty', 'edit'], 'delete' => ['faculty', 'delete'],
        'export' => ['faculty', 'export'], 'import' => ['faculty', 'import'],
    ],
    'icon' => 'user-cog',
    'description' => 'Non-teaching staff across administration, accounts, library, hostel, transport and support services.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', t.first_name, t.last_name)) AS full_name, d.name AS department_name, d.code AS department_code,
                 CONCAT(t.employee_id, IFNULL(CONCAT(' · ', d.code), '')) AS employee_sub,
                 (SELECT COUNT(*) FROM employee_leaves el WHERE el.employee_type = 'staff' AND el.employee_id = t.id AND el.status = 'approved'
                    AND el.from_date <= '$today' AND el.to_date >= '$today') AS on_leave_today",
    'joins' => 'LEFT JOIN departments d ON d.id = t.department_id',
    'search' => ['t.first_name', 't.last_name', "CONCAT(t.first_name, ' ', COALESCE(t.last_name, ''))", 't.employee_id', 't.email', 't.phone', 't.designation', 't.section'],
    'search_placeholder' => 'Search name, employee ID, designation, phone…',
    'order' => 't.first_name ASC, t.last_name ASC',
    'hidden_columns' => ['bank_account', 'pan_no', 'bank_ifsc'],
    'columns' => [
        ['key' => 'full_name', 'label' => 'Staff member', 'format' => 'person', 'image' => 'photo', 'sub' => 'employee_sub', 'link' => '/staff/{id}', 'sortable' => 't.first_name'],
        ['key' => 'designation', 'label' => 'Designation', 'format' => 'title', 'sub' => 'section', 'sortable' => true],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'hidden' => true, 'sortable' => true,
            'colors' => ['administration' => 'navy', 'accounts' => 'green', 'library' => 'purple', 'hostel' => 'amber', 'transport' => 'cyan', 'maintenance' => 'slate', 'security' => 'red', 'it' => 'blue', 'laboratory' => 'purple', 'other' => 'slate']],
        ['key' => 'department_code', 'label' => 'Dept.', 'format' => 'code', 'hidden' => true, 'sortable' => 'd.code'],
        ['key' => 'phone', 'label' => 'Contact', 'format' => 'phone'],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true, 'sortable' => true],
        ['key' => 'department_name', 'label' => 'Department', 'hidden' => true, 'sortable' => 'd.name'],
        ['key' => 'employment_type', 'label' => 'Employment', 'format' => 'badge', 'sortable' => true,
            'colors' => ['permanent' => 'navy', 'contract' => 'cyan', 'probation' => 'amber', 'outsourced' => 'purple', 'part_time' => 'slate']],
        ['key' => 'joining_date', 'label' => 'Joined', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'qualification', 'label' => 'Qualification', 'hidden' => true],
        ['key' => 'salary', 'label' => 'Monthly Salary', 'format' => 'money', 'hidden' => true, 'sortable' => true],
        ['key' => 'bank_account_masked', 'label' => 'Bank A/c', 'format' => 'code', 'hidden' => true],
        ['key' => 'city', 'label' => 'City', 'hidden' => true],
        ['key' => 'has_login', 'label' => 'Login', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'employee_id', 'label' => 'Employee ID'], ['key' => 'full_name', 'label' => 'Name'], ['key' => 'designation', 'label' => 'Designation'],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge'], ['key' => 'section', 'label' => 'Section'], ['key' => 'department_name', 'label' => 'Department'],
        ['key' => 'qualification', 'label' => 'Qualification'], ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'],
        ['key' => 'employment_type', 'label' => 'Employment Type', 'format' => 'badge'], ['key' => 'joining_date', 'label' => 'Joining Date', 'format' => 'date'],
        ['key' => 'city', 'label' => 'City'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => $categories],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments'],
        ['key' => 'employment_type', 'label' => 'Employment', 'options' => $employment],
        ['key' => 'status', 'label' => 'Status', 'options' => $statuses],
        ['key' => 'on_leave', 'label' => 'Availability', 'options' => ['today' => 'On leave today'],
            'sql_map' => ['today' => "EXISTS (SELECT 1 FROM employee_leaves el WHERE el.employee_type = 'staff' AND el.employee_id = t.id AND el.status = 'approved' AND el.from_date <= '$today' AND el.to_date >= '$today')"]],
    ],
    'scopes' => ['department_id' => 't.department_id', 'category' => 't.category'],
    'fields' => [
        ['type' => 'section', 'label' => 'Personal details'],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'image', 'folder' => 'staff', 'col' => 12],
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 80],
        ['name' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'col' => 6, 'maxlength' => 80],
        ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'col' => 4, 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        ['name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'col' => 4],
        ['name' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'col' => 4, 'placeholder' => '+91 98765 43210'],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'unique' => true, 'col' => 6, 'placeholder' => 'name@gimt.ac.in'],
        ['name' => 'alternate_phone', 'label' => 'Alternate phone', 'type' => 'tel', 'col' => 6],
        ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'col' => 12],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'state', 'label' => 'State', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'pincode', 'label' => 'PIN code', 'type' => 'text', 'col' => 4, 'maxlength' => 6],

        ['type' => 'section', 'label' => 'Employment details'],
        ['name' => 'employee_id', 'label' => 'Employee ID', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 30, 'placeholder' => 'Auto (GIMT-S031)', 'help' => 'Leave blank to auto-generate.', 'rules' => 'regex:/^[A-Za-z0-9\-\/]+$/'],
        ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 100, 'placeholder' => 'Accounts Officer'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'administration', 'options' => $categories],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'col' => 6, 'source' => 'departments', 'help' => 'Academic department, if attached to one.'],
        ['name' => 'section', 'label' => 'Office / section', 'type' => 'text', 'col' => 6, 'maxlength' => 100, 'placeholder' => 'Exam Cell, Admission Office…'],
        ['name' => 'qualification', 'label' => 'Qualification', 'type' => 'text', 'col' => 6, 'maxlength' => 190],
        ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'col' => 6],
        ['name' => 'employment_type', 'label' => 'Employment type', 'type' => 'select', 'required' => true, 'col' => 6, 'default' => 'permanent', 'options' => $employment],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'col' => 6, 'default' => 'active', 'options' => $statuses],

        ['type' => 'section', 'label' => 'Salary & bank (payroll reference)', 'help' => 'Visible in full only to users who can edit staff records; lists show masked values.'],
        ['name' => 'salary', 'label' => 'Monthly gross salary', 'type' => 'money', 'col' => 4, 'min' => 0],
        ['name' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'col' => 4, 'maxlength' => 100],
        ['name' => 'bank_ifsc', 'label' => 'IFSC', 'type' => 'text', 'col' => 4, 'maxlength' => 11, 'placeholder' => 'SBIN0001234'],
        ['name' => 'bank_account', 'label' => 'Account number', 'type' => 'text', 'col' => 6, 'maxlength' => 40],
        ['name' => 'pan_no', 'label' => 'PAN', 'type' => 'text', 'col' => 6, 'maxlength' => 10, 'placeholder' => 'ABCDE1234F'],

        ['type' => 'section', 'label' => 'Login account', 'help' => 'Optionally create a SmartCampus login. Credentials are emailed to the staff member.', 'create_only' => true, 'name' => '__login_section'],
        ['name' => 'create_login', 'label' => 'Create login account', 'type' => 'toggle', 'col' => 4, 'create_only' => true, 'default' => false, 'import' => false, 'placeholder' => 'Email a temporary password'],
        ['name' => 'login_role_id', 'label' => 'Role', 'type' => 'select', 'col' => 4, 'create_only' => true, 'import' => false, 'default' => $staffRoleId ?: null, 'validate_options' => false,
            'source' => ['table' => 'roles r', 'value' => 'r.id', 'label' => 'r.name', 'where' => 'r.is_super = 0', 'order' => 'r.name', 'search' => ['r.name']]],
        ['name' => 'login_username', 'label' => 'Username', 'type' => 'text', 'col' => 4, 'create_only' => true, 'import' => false, 'maxlength' => 60, 'placeholder' => 'Auto from email'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'on_leave', 'resigned', 'retired', 'inactive']],
    'import' => true,
    'export' => true,
    'per_page' => 25,
    'form' => ['size' => 'xl'],
    'view' => ['type' => 'page', 'url' => '/staff/{id}'],
    'hooks' => [
        'transform_row' => function (array $row): array {
            $row = hr_mask_row($row);
            $row['has_login'] = !empty($row['user_id']) ? 1 : 0;
            $row['on_leave_today'] = (int) ($row['on_leave_today'] ?? 0);
            if (isset($GLOBALS['hr_new_account'][(int) $row['id']])) {
                $row['new_account'] = $GLOBALS['hr_new_account'][(int) $row['id']];
            }
            return $row;
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input) {
            $errors = hr_validate_person($data, $old, $input, 'staff');
            if (!$old && in_array($input['create_login'] ?? false, [true, 1, '1', 'true', 'on'], true) && empty($data['email'])) {
                $errors['email'] = 'Email is required to create a login account.';
            }
            return $errors;
        },
        'before_save' => fn (array $data, ?int $id) => hr_before_save_person($data, $id, 'staff'),
        'after_save' => function (int $id, array $data, ?array $old, array $input) {
            if (!$old && in_array($input['create_login'] ?? false, [true, 1, '1', 'true', 'on'], true)) {
                $GLOBALS['hr_new_account'][$id] = hr_create_account('staff', $id, ['username' => $input['login_username'] ?? '', 'role_id' => $input['login_role_id'] ?? null]);
            } elseif ($old) {
                hr_sync_user('staff', $id, $old);
            }
        },
        'before_delete' => fn (int $id, array $row) => hr_delete_blocker('staff', $id, $row),
        'after_delete' => fn (int $id, array $row) => hr_after_delete_person('staff', $id, $row),
        'after_bulk' => function (array $ids) {
            foreach ($ids as $id) {
                hr_sync_user('staff', (int) $id);
            }
        },
        'describe' => fn (array $row) => trim($row['first_name'] . ' ' . ($row['last_name'] ?? '')) . ' (' . $row['employee_id'] . ')',
    ],
];
