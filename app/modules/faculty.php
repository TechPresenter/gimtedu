<?php
/** Faculty (teaching staff) — Faculty & Staff management. Profile at /faculty/{id}. */
require_once APP_ROOT . '/app/services/hr.php';

$designations = ['Dean', 'Professor & Head', 'Professor', 'Associate Professor', 'Assistant Professor', 'Senior Lecturer', 'Lecturer', 'Lab Instructor', 'Visiting Faculty', 'Guest Faculty', 'Teaching Assistant'];
$designationOptions = array_combine($designations, $designations);
$employment = ['permanent' => 'Permanent', 'contract' => 'Contract', 'probation' => 'Probation', 'visiting' => 'Visiting', 'guest' => 'Guest'];
$statuses = ['active' => 'Active', 'on_leave' => 'On Leave', 'resigned' => 'Resigned', 'retired' => 'Retired', 'inactive' => 'Inactive'];
$today = date('Y-m-d');

return [
    'table' => 'faculty',
    'title' => 'Faculty',
    'singular' => 'Faculty Member',
    'permission' => 'faculty',
    'icon' => 'users',
    'description' => 'Teaching faculty with departments, qualifications and employment details.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', t.title, t.first_name, t.last_name)) AS full_name, d.name AS department_name, d.code AS department_code,
                 CONCAT(t.employee_id, IFNULL(CONCAT(' · ', d.code), '')) AS employee_sub,
                 u.username AS login_username_current, u.last_login_at,
                 (SELECT COUNT(*) FROM employee_leaves el WHERE el.employee_type = 'faculty' AND el.employee_id = t.id AND el.status = 'approved'
                    AND el.from_date <= '$today' AND el.to_date >= '$today') AS on_leave_today",
    'joins' => 'LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN users u ON u.id = t.user_id',
    'search' => ['t.first_name', 't.last_name', "CONCAT(t.first_name, ' ', COALESCE(t.last_name, ''))", 't.employee_id', 't.email', 't.phone', 't.specialization', 't.qualification'],
    'search_placeholder' => 'Search name, employee ID, email, phone, specialization…',
    'order' => 't.first_name ASC, t.last_name ASC',
    'hidden_columns' => ['bank_account', 'pan_no', 'bank_ifsc'],
    'columns' => [
        ['key' => 'full_name', 'label' => 'Faculty', 'format' => 'person', 'image' => 'photo', 'sub' => 'employee_sub', 'link' => '/faculty/{id}', 'sortable' => 't.first_name'],
        ['key' => 'designation', 'label' => 'Designation', 'format' => 'title', 'sub' => 'qualification', 'sortable' => true],
        ['key' => 'department_code', 'label' => 'Dept.', 'format' => 'code', 'hidden' => true, 'sortable' => 'd.code'],
        ['key' => 'qualification', 'label' => 'Qualification', 'format' => 'truncate', 'truncate' => 30, 'hidden' => true, 'sortable' => true],
        ['key' => 'specialization', 'label' => 'Specialization', 'format' => 'truncate', 'truncate' => 26, 'hidden' => true, 'sortable' => true],
        ['key' => 'department_name', 'label' => 'Department', 'format' => 'text', 'hidden' => true, 'sortable' => 'd.name'],
        ['key' => 'phone', 'label' => 'Contact', 'format' => 'phone'],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true, 'sortable' => true],
        ['key' => 'employment_type', 'label' => 'Employment', 'format' => 'badge', 'sortable' => true,
            'colors' => ['permanent' => 'navy', 'contract' => 'cyan', 'probation' => 'amber', 'visiting' => 'purple', 'guest' => 'slate']],
        ['key' => 'joining_date', 'label' => 'Joined', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'experience_years', 'label' => 'Experience (yrs)', 'format' => 'number', 'hidden' => true, 'sortable' => true],
        ['key' => 'salary', 'label' => 'Monthly Salary', 'format' => 'money', 'hidden' => true, 'sortable' => true],
        ['key' => 'bank_account_masked', 'label' => 'Bank A/c', 'format' => 'code', 'hidden' => true],
        ['key' => 'gender', 'label' => 'Gender', 'format' => 'badge', 'hidden' => true, 'colors' => ['male' => 'blue', 'female' => 'purple', 'other' => 'slate']],
        ['key' => 'city', 'label' => 'City', 'hidden' => true],
        ['key' => 'has_login', 'label' => 'Login', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'employee_id', 'label' => 'Employee ID'], ['key' => 'full_name', 'label' => 'Name'], ['key' => 'designation', 'label' => 'Designation'],
        ['key' => 'department_name', 'label' => 'Department'], ['key' => 'qualification', 'label' => 'Qualification'], ['key' => 'specialization', 'label' => 'Specialization'],
        ['key' => 'experience_years', 'label' => 'Experience (yrs)', 'format' => 'number'], ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'],
        ['key' => 'gender', 'label' => 'Gender', 'format' => 'badge'], ['key' => 'employment_type', 'label' => 'Employment Type', 'format' => 'badge'],
        ['key' => 'joining_date', 'label' => 'Joining Date', 'format' => 'date'], ['key' => 'city', 'label' => 'City'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments'],
        ['key' => 'designation', 'label' => 'Designation', 'options' => $designationOptions],
        ['key' => 'employment_type', 'label' => 'Employment', 'options' => $employment],
        ['key' => 'status', 'label' => 'Status', 'options' => $statuses],
        ['key' => 'qualification_level', 'label' => 'Qualification', 'options' => ['phd' => 'PhD holders', 'non_phd' => 'Non-PhD'],
            'sql_map' => ['phd' => "(t.qualification LIKE '%Ph.D%' OR t.qualification LIKE '%PhD%')", 'non_phd' => "(t.qualification IS NULL OR (t.qualification NOT LIKE '%Ph.D%' AND t.qualification NOT LIKE '%PhD%'))"]],
        ['key' => 'on_leave', 'label' => 'Availability', 'options' => ['today' => 'On leave today'],
            'sql_map' => ['today' => "EXISTS (SELECT 1 FROM employee_leaves el WHERE el.employee_type = 'faculty' AND el.employee_id = t.id AND el.status = 'approved' AND el.from_date <= '$today' AND el.to_date >= '$today')"]],
    ],
    'scopes' => ['department_id' => 't.department_id'],
    'fields' => [
        ['type' => 'section', 'label' => 'Personal details'],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'image', 'folder' => 'faculty', 'col' => 12, 'help' => 'Square photo, JPG/PNG/WebP. Shown on the profile and the public website.'],
        ['name' => 'title', 'label' => 'Title', 'type' => 'select', 'col' => 2, 'options' => ['Dr.' => 'Dr.', 'Prof.' => 'Prof.', 'Mr.' => 'Mr.', 'Ms.' => 'Ms.', 'Mrs.' => 'Mrs.']],
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 80],
        ['name' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'col' => 4, 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        ['name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'col' => 4],
        ['name' => 'phone', 'label' => 'Mobile', 'type' => 'tel', 'col' => 4, 'placeholder' => '+91 98765 43210'],
        ['name' => 'email', 'label' => 'Official email', 'type' => 'email', 'required' => true, 'unique' => true, 'col' => 6, 'placeholder' => 'name@gimt.ac.in'],
        ['name' => 'alternate_phone', 'label' => 'Alternate phone', 'type' => 'tel', 'col' => 6],
        ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'col' => 12],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'state', 'label' => 'State', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'pincode', 'label' => 'PIN code', 'type' => 'text', 'col' => 4, 'maxlength' => 6],

        ['type' => 'section', 'label' => 'Professional details'],
        ['name' => 'employee_id', 'label' => 'Employee ID', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 30, 'placeholder' => 'Auto (GIMT-F053)', 'help' => 'Leave blank to auto-generate.', 'rules' => 'regex:/^[A-Za-z0-9\-\/]+$/'],
        ['name' => 'designation', 'label' => 'Designation', 'type' => 'select', 'required' => true, 'col' => 4, 'options' => $designationOptions],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'col' => 4, 'source' => 'departments'],
        ['name' => 'qualification', 'label' => 'Qualification', 'type' => 'text', 'col' => 6, 'maxlength' => 190, 'placeholder' => 'Ph.D. (Management), MBA'],
        ['name' => 'specialization', 'label' => 'Specialization', 'type' => 'text', 'col' => 6, 'maxlength' => 190, 'placeholder' => 'Finance, Machine Learning…'],
        ['name' => 'experience_years', 'label' => 'Experience (years)', 'type' => 'decimal', 'col' => 3, 'min' => 0, 'max' => 60, 'step' => 0.5],
        ['name' => 'joining_date', 'label' => 'Joining date', 'type' => 'date', 'col' => 3],
        ['name' => 'employment_type', 'label' => 'Employment type', 'type' => 'select', 'required' => true, 'col' => 3, 'default' => 'permanent', 'options' => $employment],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'col' => 3, 'default' => 'active', 'options' => $statuses],
        ['name' => 'publications_count', 'label' => 'Publications', 'type' => 'number', 'col' => 3, 'min' => 0, 'max' => 2000],
        ['name' => 'linkedin_url', 'label' => 'LinkedIn / profile URL', 'type' => 'url', 'col' => 6],
        ['name' => 'show_on_website', 'label' => 'Show on website', 'type' => 'toggle', 'col' => 3, 'default' => true, 'placeholder' => 'Listed on the public faculty page'],
        ['name' => 'research_interests', 'label' => 'Research interests', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 2000],
        ['name' => 'bio', 'label' => 'Short bio', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 3000],

        ['type' => 'section', 'label' => 'Salary & bank (payroll reference)', 'help' => 'Visible in full only to users who can edit faculty records; lists show masked values.'],
        ['name' => 'salary', 'label' => 'Monthly gross salary', 'type' => 'money', 'col' => 4, 'min' => 0],
        ['name' => 'bank_name', 'label' => 'Bank name', 'type' => 'text', 'col' => 4, 'maxlength' => 100],
        ['name' => 'bank_ifsc', 'label' => 'IFSC', 'type' => 'text', 'col' => 4, 'maxlength' => 11, 'placeholder' => 'SBIN0001234'],
        ['name' => 'bank_account', 'label' => 'Account number', 'type' => 'text', 'col' => 6, 'maxlength' => 40],
        ['name' => 'pan_no', 'label' => 'PAN', 'type' => 'text', 'col' => 6, 'maxlength' => 10, 'placeholder' => 'ABCDE1234F'],

        ['type' => 'section', 'label' => 'Login account', 'help' => 'Optionally create a SmartCampus login with the Faculty role. Credentials are emailed to the official email.', 'create_only' => true, 'name' => '__login_section'],
        ['name' => 'create_login', 'label' => 'Create login account', 'type' => 'toggle', 'col' => 6, 'create_only' => true, 'default' => false, 'import' => false, 'placeholder' => 'Email a temporary password'],
        ['name' => 'login_username', 'label' => 'Username', 'type' => 'text', 'col' => 6, 'create_only' => true, 'import' => false, 'maxlength' => 60, 'placeholder' => 'Auto from email', 'help' => 'Optional — suggested from the email address.'],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'on_leave', 'resigned', 'retired', 'inactive']],
    'import' => true,
    'export' => true,
    'per_page' => 25,
    'form' => ['size' => 'xl'],
    'view' => ['type' => 'page', 'url' => '/faculty/{id}'],
    'default_sort' => null,
    'hooks' => [
        'transform_row' => function (array $row): array {
            $row = hr_mask_row($row);
            $row['has_login'] = !empty($row['user_id']) ? 1 : 0;
            $row['on_leave_today'] = (int) ($row['on_leave_today'] ?? 0);
            // One-time credentials of a login account created in this request (shown once to the admin).
            if (isset($GLOBALS['hr_new_account'][(int) $row['id']])) {
                $row['new_account'] = $GLOBALS['hr_new_account'][(int) $row['id']];
            }
            return $row;
        },
        'validate' => fn (array $data, ?int $id, ?array $old, array $input) => hr_validate_person($data, $old, $input, 'faculty'),
        'before_save' => fn (array $data, ?int $id) => hr_before_save_person($data, $id, 'faculty'),
        'after_save' => function (int $id, array $data, ?array $old, array $input) {
            if (!$old && in_array($input['create_login'] ?? false, [true, 1, '1', 'true', 'on'], true)) {
                $GLOBALS['hr_new_account'][$id] = hr_create_account('faculty', $id, ['username' => $input['login_username'] ?? '']);
            } elseif ($old) {
                hr_sync_user('faculty', $id, $old);
            }
        },
        'before_delete' => fn (int $id, array $row) => hr_delete_blocker('faculty', $id, $row),
        'after_delete' => fn (int $id, array $row) => hr_after_delete_person('faculty', $id, $row),
        'after_bulk' => function (array $ids) {
            foreach ($ids as $id) {
                hr_sync_user('faculty', (int) $id);
            }
        },
        'describe' => fn (array $row) => trim(($row['title'] ?? '') . ' ' . $row['first_name'] . ' ' . ($row['last_name'] ?? '')) . ' (' . $row['employee_id'] . ')',
    ],
];
