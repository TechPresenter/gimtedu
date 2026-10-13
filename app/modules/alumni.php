<?php
/** Alumni directory (brief §23): profiles, batch, program, company, designation, location, industry, mentors. */
require_once APP_ROOT . '/app/services/alumni.php';

$distinct = fn (string $col) => [
    'table' => "(SELECT DISTINCT $col AS v FROM alumni WHERE $col IS NOT NULL AND $col <> '') x",
    'value' => 'x.v', 'label' => 'x.v', 'order' => 'x.v', 'search' => ['x.v'],
];

return [
    'table' => 'alumni',
    'title' => 'Alumni',
    'singular' => 'Alumni profile',
    'permission' => 'alumni',
    'icon' => 'users-round',
    'description' => 'Alumni directory with batch, program, current company, designation, location and mentor status.',
    'select' => "t.*, p.short_name AS program_short, p.name AS program_name, d.name AS department_name, s.student_uid,
                 CONCAT('Batch ', t.batch_year, IFNULL(CONCAT(' · ', p.short_name), '')) AS batch_label",
    'joins' => 'LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN students s ON s.id = t.student_id',
    'search' => ['t.name', 't.email', 't.phone', 't.company', 't.designation', 't.city', 'p.short_name', 's.student_uid'],
    'search_placeholder' => 'Search name, email, company, city…',
    'order' => 't.batch_year DESC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Alumni', 'format' => 'person', 'image' => 'photo', 'sub' => 'email', 'link' => '/alumni/{id}', 'sortable' => 't.name'],
        ['key' => 'batch_year', 'label' => 'Batch', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'program_short', 'label' => 'Program', 'hidden' => true, 'sortable' => 'p.short_name'],
        ['key' => 'company', 'label' => 'Company', 'format' => 'title', 'sub' => 'designation', 'sortable' => true],
        ['key' => 'designation', 'label' => 'Designation', 'hidden' => true, 'sortable' => true],
        ['key' => 'city', 'label' => 'City', 'sortable' => true],
        ['key' => 'industry', 'label' => 'Industry', 'sortable' => true],
        ['key' => 'employment_status', 'label' => 'Career status', 'format' => 'badge', 'hidden' => true, 'sortable' => true,
            'colors' => ['employed' => 'green', 'self_employed' => 'cyan', 'entrepreneur' => 'purple', 'higher_studies' => 'blue', 'seeking' => 'amber', 'other' => 'slate']],
        ['key' => 'is_mentor', 'label' => 'Mentor', 'format' => 'boolean', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'is_verified', 'label' => 'Verified', 'format' => 'boolean', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'department_name', 'label' => 'Department', 'hidden' => true],
        ['key' => 'country', 'label' => 'Country', 'hidden' => true],
        ['key' => 'linkedin_url', 'label' => 'LinkedIn', 'hidden' => true],
        ['key' => 'status', 'label' => 'Record', 'format' => 'badge', 'hidden' => true, 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'batch_year', 'label' => 'Batch', 'options' => alumni_batch_years()],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'industry', 'label' => 'Industry', 'options' => alumni_industries()],
        ['key' => 'employment_status', 'label' => 'Career status', 'options' => alumni_employment_statuses()],
        ['key' => 'company', 'label' => 'Company', 'source' => $distinct('company') + ['async' => true]],
        ['key' => 'city', 'label' => 'City', 'source' => $distinct('city')],
        ['key' => 'is_mentor', 'label' => 'Mentor', 'type' => 'boolean'],
        ['key' => 'is_verified', 'label' => 'Verified', 'type' => 'boolean'],
        ['key' => 'status', 'label' => 'Record status', 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Personal details'],
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 150, 'placeholder' => 'e.g. Ananya Sharma'],
        ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'col' => 3, 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        ['name' => 'status', 'label' => 'Record status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 3, 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'unique' => true, 'col' => 6, 'maxlength' => 190, 'placeholder' => 'name@example.com'],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'col' => 6, 'maxlength' => 20],
        ['name' => 'linkedin_url', 'label' => 'LinkedIn profile', 'type' => 'url', 'col' => 6, 'placeholder' => 'https://www.linkedin.com/in/…'],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'image', 'col' => 6, 'folder' => 'alumni'],
        ['type' => 'section', 'label' => 'Academic record'],
        ['name' => 'batch_year', 'label' => 'Batch (passing year)', 'type' => 'number', 'required' => true, 'col' => 3, 'min' => 1990, 'max' => (int) date('Y') + 1, 'default' => (int) date('Y')],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'source' => 'programs', 'col' => 9, 'validate_options' => false,
            'help' => 'Import: program code or short name (e.g. MBA) is accepted.'],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => 'departments', 'col' => 6, 'help' => 'Filled from the program when left empty.'],
        ['name' => 'student_id', 'label' => 'Linked student record', 'type' => 'combobox', 'source' => 'all_students', 'col' => 6, 'placeholder' => 'Search by name or student ID…',
            'help' => 'Optional — link the profile to the student\'s ERP record.'],
        ['type' => 'section', 'label' => 'Career'],
        ['name' => 'employment_status', 'label' => 'Career status', 'type' => 'select', 'required' => true, 'default' => 'employed', 'col' => 6, 'options' => alumni_employment_statuses()],
        ['name' => 'industry', 'label' => 'Industry', 'type' => 'select', 'col' => 6, 'options' => alumni_industries()],
        ['name' => 'company', 'label' => 'Company / organisation', 'type' => 'text', 'col' => 6, 'maxlength' => 190, 'placeholder' => 'e.g. Deloitte'],
        ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'col' => 6, 'maxlength' => 150, 'placeholder' => 'e.g. Senior Consultant'],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 6, 'maxlength' => 80],
        ['name' => 'country', 'label' => 'Country', 'type' => 'text', 'col' => 6, 'maxlength' => 80, 'default' => 'India'],
        ['type' => 'section', 'label' => 'Network & visibility'],
        ['name' => 'is_mentor', 'label' => 'Available as mentor', 'type' => 'toggle', 'col' => 4, 'default' => false],
        ['name' => 'is_verified', 'label' => 'Profile verified', 'type' => 'toggle', 'col' => 4, 'default' => false],
        ['name' => 'show_on_website', 'label' => 'Show on website', 'type' => 'toggle', 'col' => 4, 'default' => false],
        ['name' => 'bio', 'label' => 'Short bio', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000],
        ['name' => 'achievements', 'label' => 'Achievements & awards', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 2000],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'xl'],
    'per_page' => 25,
    'scopes' => ['program_id' => 't.program_id', 'batch_year' => 't.batch_year'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            if (array_key_exists('program_id', $data) && $data['program_id'] !== null && alumni_resolve_program($data['program_id']) === null) {
                $errors['program_id'] = 'Select a valid program.';
            }
            if (!empty($data['student_id'])) {
                $other = db_value('SELECT name FROM alumni WHERE student_id = ? AND id <> ?', [(int) $data['student_id'], (int) ($id ?? 0)]);
                if ($other) {
                    $errors['student_id'] = 'This student record is already linked to ' . $other . '.';
                }
            }
            $status = $data['employment_status'] ?? ($old['employment_status'] ?? null);
            $company = array_key_exists('company', $data) ? $data['company'] : ($old['company'] ?? null);
            if (in_array($status, ['employed', 'entrepreneur'], true) && ($company === null || $company === '')) {
                $errors['company'] = 'Company is required for ' . ($status === 'employed' ? 'employed' : 'entrepreneur') . ' alumni.';
            }
            $phone = $data['phone'] ?? null;
            if ($phone && strlen(preg_replace('/\D/', '', (string) $phone)) < 10) {
                $errors['phone'] = 'Enter a valid phone number with at least 10 digits.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (isset($data['name'])) {
                $data['name'] = preg_replace('/\s+/', ' ', trim((string) $data['name']));
            }
            if (array_key_exists('program_id', $data)) {
                $data['program_id'] = alumni_resolve_program($data['program_id']);
            }
            $programId = $data['program_id'] ?? ($old['program_id'] ?? null);
            $deptGiven = array_key_exists('department_id', $data) ? $data['department_id'] : ($old['department_id'] ?? null);
            if ($programId && !$deptGiven) {
                $data['department_id'] = db_value('SELECT department_id FROM programs WHERE id = ?', [(int) $programId]) ?: null;
            }
            if (array_key_exists('country', $data) && ($data['country'] === null || $data['country'] === '')) {
                $data['country'] = 'India';
            }
            if (($data['status'] ?? null) === 'inactive') {
                $data['is_mentor'] = 0;
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            $donations = (int) db_value("SELECT COUNT(*) FROM alumni_donations WHERE alumni_id = ? AND status <> 'cancelled'", [$id]);
            $stories = (int) db_value('SELECT COUNT(*) FROM alumni_success_stories WHERE alumni_id = ?', [$id]);
            $mentees = (int) db_value("SELECT COUNT(*) FROM alumni_mentorships WHERE alumni_id = ? AND status = 'active'", [$id]);
            $parts = [];
            if ($donations) {
                $parts[] = $donations . ' donation' . ($donations === 1 ? '' : 's');
            }
            if ($stories) {
                $parts[] = $stories . ' success stor' . ($stories === 1 ? 'y' : 'ies');
            }
            if ($mentees) {
                $parts[] = $mentees . ' active mentorship' . ($mentees === 1 ? '' : 's');
            }
            return $parts ? $row['name'] . ' has ' . implode(', ', $parts) . ' on record and cannot be deleted. Mark the profile inactive instead.' : null;
        },
        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, COALESCE(SUM(t.is_mentor = 1), 0) AS mentors,
                                COALESCE(SUM(t.employment_status IN ('employed', 'self_employed', 'entrepreneur')), 0) AS working,
                                COALESCE(SUM(t.is_verified = 1), 0) AS verified, COUNT(DISTINCT NULLIF(t.company, '')) AS companies" . $from, $args);
            return array_map('intval', $r ?? []);
        },
        'bulk_action' => fn (string $action, array $ids) => alumni_bulk_action($action, $ids),
        'describe' => fn (array $row) => '"' . ($row['name'] ?? '') . '" (Batch ' . ($row['batch_year'] ?? '') . ')',
    ],
];
