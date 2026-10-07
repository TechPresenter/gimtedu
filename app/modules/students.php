<?php
/**
 * Students (Student management). List/import/export/bulk via the CRUD engine; the SPA uses a full-page form
 * (/students/new, /students/:id/edit) that posts here, plus custom endpoints in api/routes/students.php.
 *
 * Extra input understood by the hooks (besides the fields below):
 *   parents: [{relation: father|mother|guardian, name, phone, email, occupation, annual_income, is_emergency_contact}]
 *   allow_duplicate_mobile: 1   accept a mobile number already used by another student (siblings)
 */
require_once APP_ROOT . '/app/services/students.php';

$GLOBALS['__students_parents'] = null;
$GLOBALS['__students_deleted_docs'] = [];

return [
    'table' => 'students',
    'title' => 'Students',
    'singular' => 'Student',
    'permission' => 'students',
    'icon' => 'graduation-cap',
    'description' => 'Enrolled students, their programs, sections and status.',
    'select' => "t.*, TRIM(CONCAT_WS(' ', t.first_name, t.middle_name, t.last_name)) AS full_name, p.short_name AS program_name, p.name AS program_full_name, p.total_semesters,
                 d.name AS department_name, sc.name AS section_name, b.name AS batch_name, ses.name AS session_name, c.name AS course_name,
                 CONCAT('Sem ', t.current_semester) AS semester_label, CONCAT(b.start_year, '–', RIGHT(b.end_year, 2)) AS batch_label,
                 SUBSTRING_INDEX(c.name, ' - ', -1) AS course_short",
    'joins' => 'JOIN programs p ON p.id = t.program_id
                LEFT JOIN departments d ON d.id = t.department_id
                LEFT JOIN sections sc ON sc.id = t.section_id
                LEFT JOIN batches b ON b.id = t.batch_id
                LEFT JOIN courses c ON c.id = t.course_id
                LEFT JOIN academic_sessions ses ON ses.id = t.academic_session_id',
    'search' => ['t.first_name', 't.last_name', 't.student_uid', 't.admission_no', 't.roll_no', 't.enrollment_no', 't.mobile', 't.email', "CONCAT(t.first_name, ' ', COALESCE(t.last_name, ''))"],
    'search_placeholder' => 'Search name, student ID, admission / roll no, mobile, email…',
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'full_name', 'label' => 'Student', 'format' => 'person', 'image' => 'photo', 'sub' => 'student_uid', 'link' => '/students/{id}', 'sortable' => 't.first_name'],
        ['key' => 'admission_no', 'label' => 'Admission No', 'format' => 'code', 'sortable' => true],
        ['key' => 'roll_no', 'label' => 'Roll No', 'format' => 'text', 'sortable' => true],
        ['key' => 'program_name', 'label' => 'Program', 'format' => 'title', 'sub' => 'course_short', 'sortable' => 'p.short_name'],
        ['key' => 'semester_label', 'label' => 'Semester', 'format' => 'text', 'sortable' => 't.current_semester', 'align' => 'center'],
        ['key' => 'section_name', 'label' => 'Section', 'format' => 'text', 'sortable' => 'sc.name', 'align' => 'center'],
        ['key' => 'batch_label', 'label' => 'Batch', 'format' => 'text', 'sortable' => 'b.start_year'],
        ['key' => 'mobile', 'label' => 'Mobile', 'format' => 'phone'],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true],
        ['key' => 'gender', 'label' => 'Gender', 'format' => 'badge', 'hidden' => true, 'sortable' => true, 'colors' => ['male' => 'blue', 'female' => 'purple', 'other' => 'slate']],
        ['key' => 'category', 'label' => 'Category', 'format' => 'text', 'hidden' => true, 'sortable' => true],
        ['key' => 'department_name', 'label' => 'Department', 'format' => 'text', 'hidden' => true, 'sortable' => 'd.name'],
        ['key' => 'session_name', 'label' => 'Admission Session', 'format' => 'text', 'hidden' => true, 'sortable' => 'ses.name'],
        ['key' => 'admission_date', 'label' => 'Admitted On', 'format' => 'date', 'hidden' => true, 'sortable' => true],
        ['key' => 'father_name', 'label' => 'Father', 'format' => 'text', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['graduated' => 'blue', 'alumni' => 'navy']],
    ],
    'export_columns' => [
        ['key' => 'student_uid', 'label' => 'Student ID'], ['key' => 'admission_no', 'label' => 'Admission No'], ['key' => 'roll_no', 'label' => 'Roll No'],
        ['key' => 'enrollment_no', 'label' => 'Enrollment No'], ['key' => 'full_name', 'label' => 'Name'], ['key' => 'gender', 'label' => 'Gender', 'format' => 'badge'],
        ['key' => 'dob', 'label' => 'Date of Birth', 'format' => 'date'], ['key' => 'blood_group', 'label' => 'Blood Group'], ['key' => 'category', 'label' => 'Category'],
        ['key' => 'mobile', 'label' => 'Mobile'], ['key' => 'email', 'label' => 'Email'], ['key' => 'whatsapp', 'label' => 'WhatsApp'],
        ['key' => 'father_name', 'label' => 'Father Name'], ['key' => 'mother_name', 'label' => 'Mother Name'], ['key' => 'guardian_phone', 'label' => 'Guardian Phone'],
        ['key' => 'address', 'label' => 'Address'], ['key' => 'city', 'label' => 'City'], ['key' => 'state', 'label' => 'State'], ['key' => 'pincode', 'label' => 'Pincode'],
        ['key' => 'department_name', 'label' => 'Department'], ['key' => 'program_name', 'label' => 'Program'], ['key' => 'course_name', 'label' => 'Specialization'],
        ['key' => 'batch_name', 'label' => 'Batch'], ['key' => 'current_semester', 'label' => 'Semester', 'format' => 'number'], ['key' => 'section_name', 'label' => 'Section'],
        ['key' => 'session_name', 'label' => 'Admission Session'], ['key' => 'admission_date', 'label' => 'Admission Date', 'format' => 'date'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
    ],
    'filters' => [
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'depends' => ['department_id' => 'department_id']],
        ['key' => 'semester', 'label' => 'Semester', 'column' => 't.current_semester', 'options' => array_combine(range(1, 10), array_map(fn ($n) => "Semester $n", range(1, 10)))],
        ['key' => 'section_id', 'label' => 'Section', 'source' => 'sections', 'depends' => ['program_id' => 'program_id', 'semester_no' => 'semester']],
        ['key' => 'batch_id', 'label' => 'Batch', 'source' => 'batches', 'depends' => ['program_id' => 'program_id']],
        ['key' => 'status', 'label' => 'Status', 'options' => students_status_options()],
        ['key' => 'gender', 'label' => 'Gender', 'options' => students_gender_options()],
        ['key' => 'category', 'label' => 'Category', 'options' => students_category_options()],
        ['key' => 'academic_session_id', 'label' => 'Admission session', 'source' => 'sessions'],
        ['key' => 'course_id', 'label' => 'Specialization', 'source' => 'courses', 'depends' => ['program_id' => 'program_id']],
        ['key' => 'is_hosteller', 'label' => 'Hosteller', 'type' => 'boolean'],
        ['key' => 'uses_transport', 'label' => 'Transport', 'type' => 'boolean'],
        ['key' => 'admission_date', 'label' => 'Admitted between', 'type' => 'daterange'],
    ],
    'scopes' => ['program_id' => 't.program_id', 'section_id' => 't.section_id', 'batch_id' => 't.batch_id', 'department_id' => 't.department_id'],
    'fields' => [
        ['type' => 'section', 'label' => 'Identifiers', 'help' => 'Leave blank to auto-generate from Settings > Academic formats.'],
        ['name' => 'student_uid', 'label' => 'Student ID', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 30, 'help' => 'Leave blank to auto-generate.'],
        ['name' => 'admission_no', 'label' => 'Admission number', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 30, 'help' => 'Leave blank to auto-generate.'],
        ['name' => 'roll_no', 'label' => 'Roll number', 'type' => 'text', 'unique' => true, 'col' => 4, 'maxlength' => 30, 'help' => 'Leave blank to auto-generate.'],
        ['type' => 'section', 'label' => 'Personal details'],
        ['name' => 'photo', 'label' => 'Photo', 'type' => 'image', 'folder' => 'students', 'col' => 12],
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 80],
        ['name' => 'middle_name', 'label' => 'Middle name', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'options' => students_gender_options(), 'col' => 4],
        ['name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'col' => 4],
        ['name' => 'blood_group', 'label' => 'Blood group', 'type' => 'select', 'options' => students_blood_groups(), 'col' => 4],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => students_category_options(), 'col' => 4],
        ['name' => 'religion', 'label' => 'Religion', 'type' => 'select', 'options' => students_religion_options(), 'col' => 4],
        ['name' => 'nationality', 'label' => 'Nationality', 'type' => 'text', 'default' => 'Indian', 'col' => 4, 'maxlength' => 60],
        ['name' => 'aadhaar_no', 'label' => 'Aadhaar number', 'type' => 'text', 'col' => 6, 'maxlength' => 14, 'rules' => ['regex:/^\d{4}\s?\d{4}\s?\d{4}$/'], 'placeholder' => '12-digit Aadhaar number',
            'help' => 'Stored securely and shown masked (XXXX XXXX 1234).'],
        ['type' => 'section', 'label' => 'Contact'],
        ['name' => 'mobile', 'label' => 'Mobile', 'type' => 'tel', 'required' => true, 'col' => 4, 'maxlength' => 20],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'unique' => true, 'col' => 4, 'maxlength' => 190],
        ['name' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'tel', 'col' => 4, 'maxlength' => 20],
        ['name' => 'emergency_contact_name', 'label' => 'Emergency contact name', 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'emergency_contact_phone', 'label' => 'Emergency contact phone', 'type' => 'tel', 'col' => 6, 'maxlength' => 20],
        ['type' => 'section', 'label' => 'Address'],
        ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 3, 'maxlength' => 80],
        ['name' => 'state', 'label' => 'State', 'type' => 'text', 'col' => 3, 'maxlength' => 80],
        ['name' => 'country', 'label' => 'Country', 'type' => 'text', 'default' => 'India', 'col' => 3, 'maxlength' => 80],
        ['name' => 'pincode', 'label' => 'Pincode', 'type' => 'text', 'col' => 3, 'maxlength' => 12, 'rules' => ['regex:/^[A-Za-z0-9 \-]{3,12}$/']],
        ['name' => 'permanent_address', 'label' => 'Permanent address', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['type' => 'section', 'label' => 'Parents / guardian'],
        ['name' => 'father_name', 'label' => 'Father name', 'type' => 'text', 'col' => 4, 'maxlength' => 150],
        ['name' => 'mother_name', 'label' => 'Mother name', 'type' => 'text', 'col' => 4, 'maxlength' => 150],
        ['name' => 'guardian_name', 'label' => 'Guardian name', 'type' => 'text', 'col' => 4, 'maxlength' => 150],
        ['name' => 'guardian_relation', 'label' => 'Guardian relation', 'type' => 'text', 'col' => 6, 'maxlength' => 40],
        ['name' => 'guardian_phone', 'label' => 'Guardian phone', 'type' => 'tel', 'col' => 6, 'maxlength' => 20],
        ['type' => 'section', 'label' => 'Academic'],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => 'departments', 'col' => 6],
        ['name' => 'program_id', 'label' => 'Program', 'type' => 'select', 'source' => 'programs', 'required' => true, 'depends' => ['department_id' => 'department_id'], 'col' => 6],
        ['name' => 'course_id', 'label' => 'Specialization / course', 'type' => 'select', 'source' => 'courses', 'depends' => ['program_id' => 'program_id'], 'col' => 6],
        ['name' => 'batch_id', 'label' => 'Batch', 'type' => 'select', 'source' => 'batches', 'depends' => ['program_id' => 'program_id'], 'col' => 6],
        ['name' => 'current_semester', 'label' => 'Semester', 'type' => 'number', 'required' => true, 'default' => 1, 'min' => 1, 'max' => 12, 'col' => 4],
        ['name' => 'section_id', 'label' => 'Section', 'type' => 'select', 'source' => 'sections', 'depends' => ['program_id' => 'program_id', 'semester_no' => 'current_semester'], 'col' => 4],
        ['name' => 'academic_session_id', 'label' => 'Admission session', 'type' => 'select', 'source' => 'sessions', 'col' => 4],
        ['name' => 'admission_date', 'label' => 'Admission date', 'type' => 'date', 'col' => 4],
        ['name' => 'admission_type', 'label' => 'Admission type', 'type' => 'select', 'options' => students_admission_types(), 'default' => 'regular', 'col' => 4],
        ['name' => 'enrollment_no', 'label' => 'University enrollment no', 'type' => 'text', 'col' => 4, 'maxlength' => 40],
        ['name' => 'previous_qualification', 'label' => 'Previous qualification', 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'previous_percentage', 'label' => 'Previous percentage', 'type' => 'decimal', 'min' => 0, 'max' => 100, 'step' => 0.01, 'col' => 6],
        ['name' => 'is_hosteller', 'label' => 'Hosteller', 'type' => 'toggle', 'col' => 3],
        ['name' => 'uses_transport', 'label' => 'Uses college transport', 'type' => 'toggle', 'col' => 3],
        ['type' => 'section', 'label' => 'Status'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => students_status_options(), 'col' => 4],
        ['name' => 'status_reason', 'label' => 'Status reason', 'type' => 'text', 'col' => 8, 'maxlength' => 255],
        ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 2000],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive', 'suspended', 'dropped', 'graduated', 'alumni']],
    'import' => true,
    'export' => true,
    'per_page' => 25,
    'view' => ['type' => 'page', 'url' => '/students/{id}'],
    'form' => ['size' => 'xl', 'mode' => 'page'],
    'hidden_columns' => [],
    'hooks' => [
        'transform_row' => function (array $row): array {
            if (array_key_exists('aadhaar_no', $row)) {
                $row['aadhaar_masked'] = students_mask_aadhaar($row['aadhaar_no']);
                unset($row['aadhaar_no']);
            }
            foreach (['current_semester', 'program_id', 'department_id', 'section_id', 'batch_id', 'course_id', 'academic_session_id', 'total_semesters'] as $k) {
                if (isset($row[$k])) {
                    $row[$k] = (int) $row[$k];
                }
            }
            return $row;
        },

        'list_query' => function (array &$where, array &$args, array $req): void {
            // f[q_uid]=... exact student ID lookups (used by ID card printing)
            $ids = $req['f']['student_ids'] ?? null;
            if (is_string($ids) && $ids !== '') {
                $list = array_values(array_filter(array_map('intval', explode(',', $ids))));
                if ($list) {
                    $where[] = 't.id IN (' . implode(',', $list) . ')';
                }
            }
        },

        'summary' => function (string $from, array $args): array {
            $r = db_row("SELECT COUNT(*) AS total, SUM(t.status = 'active') AS active, SUM(t.gender = 'male') AS male, SUM(t.gender = 'female') AS female,
                                SUM(t.gender NOT IN ('male', 'female')) AS other, SUM(t.is_hosteller = 1) AS hostellers, SUM(t.uses_transport = 1) AS transport $from", $args) ?? [];
            return array_map('intval', $r);
        },

        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            $get = fn (string $k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            $programId = (int) $get('program_id');
            $program = $programId ? students_program($programId) : null;
            if ($programId && !$program) {
                $errors['program_id'] = 'Select a valid program.';
            }
            if ($program) {
                $dept = $get('department_id');
                if ($dept && (int) $dept !== (int) $program['department_id']) {
                    $errors['program_id'] = $program['short_name'] . ' does not belong to the selected department.';
                }
                $sem = (int) $get('current_semester');
                if ($sem < 1 || $sem > (int) $program['total_semesters']) {
                    $errors['current_semester'] = $program['short_name'] . ' has ' . $program['total_semesters'] . ' semester' . ((int) $program['total_semesters'] === 1 ? '' : 's') . '. Choose 1–' . $program['total_semesters'] . '.';
                }
                if ($course = $get('course_id')) {
                    if (!(int) db_value('SELECT COUNT(*) FROM courses WHERE id = ? AND program_id = ?', [(int) $course, $programId])) {
                        $errors['course_id'] = 'This specialization is not offered in ' . $program['short_name'] . '.';
                    }
                }
                if ($batch = $get('batch_id')) {
                    if (!(int) db_value('SELECT COUNT(*) FROM batches WHERE id = ? AND program_id = ?', [(int) $batch, $programId])) {
                        $errors['batch_id'] = 'This batch belongs to a different program.';
                    }
                }
                if ($section = $get('section_id')) {
                    $sec = db_row('SELECT program_id, semester_no FROM sections WHERE id = ?', [(int) $section]);
                    if (!$sec || (int) $sec['program_id'] !== $programId) {
                        $errors['section_id'] = 'This section belongs to a different program.';
                    } elseif ((int) $sec['semester_no'] !== $sem && !isset($errors['current_semester'])) {
                        $errors['section_id'] = 'This section is for semester ' . $sec['semester_no'] . ', not semester ' . $sem . '.';
                    }
                }
            }
            $today = date('Y-m-d');
            if (!empty($data['dob'])) {
                $age = (new DateTime($data['dob']))->diff(new DateTime($today))->y;
                if ($data['dob'] >= $today) {
                    $errors['dob'] = 'Date of birth must be in the past.';
                } elseif ($age < 14 || $age > 70) {
                    $errors['dob'] = 'Age must be between 14 and 70 years (currently ' . $age . ').';
                }
            }
            if (!empty($data['admission_date'])) {
                if ($data['admission_date'] > date('Y-m-d', strtotime('+1 year'))) {
                    $errors['admission_date'] = 'Admission date cannot be more than a year ahead.';
                } elseif (!empty($get('dob')) && $data['admission_date'] <= $get('dob')) {
                    $errors['admission_date'] = 'Admission date must be after the date of birth.';
                }
            }
            if (array_key_exists('aadhaar_no', $data) && $data['aadhaar_no'] !== null) {
                $digits = students_digits($data['aadhaar_no']);
                if (strlen($digits) !== 12 || $digits[0] === '0' || $digits[0] === '1') {
                    $errors['aadhaar_no'] = 'Enter a valid 12-digit Aadhaar number.';
                } elseif ($dup = students_find_duplicates(['aadhaar_no' => $digits], $id)['aadhaar_no'] ?? null) {
                    $errors['aadhaar_no'] = 'This Aadhaar number is already registered to ' . $dup[0]['name'] . ' (' . $dup[0]['student_uid'] . ').';
                }
            }
            if (!empty($data['mobile']) && strlen(students_mobile_key($data['mobile'])) < 10) {
                $errors['mobile'] = 'Enter a valid 10-digit mobile number.';
            } elseif (!empty($data['mobile']) && empty($input['allow_duplicate_mobile']) && (!$old || students_mobile_key($old['mobile']) !== students_mobile_key($data['mobile']))) {
                if ($dup = students_find_duplicates(['mobile' => $data['mobile']], $id)['mobile'] ?? null) {
                    $errors['mobile'] = 'This mobile number is already used by ' . $dup[0]['name'] . ' (' . $dup[0]['student_uid'] . '). Confirm it is shared (e.g. siblings) to continue.';
                }
            }
            if (!empty($data['whatsapp']) && strlen(students_mobile_key($data['whatsapp'])) < 10) {
                $errors['whatsapp'] = 'Enter a valid 10-digit WhatsApp number.';
            }
            $status = $get('status');
            $statusChanged = !$old || $old['status'] !== $status;
            if (in_array($status, students_inactive_statuses(), true) && $statusChanged && empty($get('status_reason'))) {
                $errors['status_reason'] = 'Give a reason when marking a student ' . strtolower(students_status_options()[$status] ?? $status) . '.';
            }
            // Parents posted by the full-page form
            $GLOBALS['__students_parents'] = null;
            if (isset($input['parents'])) {
                [$parents, $perr] = students_validate_parents($input['parents']);
                $errors += $perr;
                $GLOBALS['__students_parents'] = $parents;
            }
            return $errors;
        },

        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if (array_key_exists('aadhaar_no', $data) && $data['aadhaar_no'] !== null) {
                $data['aadhaar_no'] = students_digits($data['aadhaar_no']);
            }
            foreach (['first_name', 'middle_name', 'last_name', 'city', 'state'] as $k) {
                if (!empty($data[$k])) {
                    $data[$k] = students_name_case($data[$k]);
                }
            }
            if (!empty($data['pincode'])) {
                $data['pincode'] = strtoupper(trim($data['pincode']));
            }
            $programId = (int) ($data['program_id'] ?? $old['program_id'] ?? 0);
            if ($programId && ($program = students_program($programId))) {
                $data['department_id'] = (int) $program['department_id'];
            }
            // Parents from the full form drive the denormalised parent columns
            $parents = $GLOBALS['__students_parents'];
            if (is_array($parents)) {
                foreach (['father' => 'father_name', 'mother' => 'mother_name'] as $rel => $col) {
                    if (isset($parents[$rel])) {
                        $data[$col] = $parents[$rel]['name'] !== '' ? $parents[$rel]['name'] : null;
                    }
                }
                $g = null;
                foreach (['guardian', 'father', 'mother'] as $rel) {
                    if (!empty($parents[$rel]['name'])) {
                        $g = $parents[$rel] + ['rel' => $rel];
                        break;
                    }
                }
                foreach ($parents as $p) {
                    $hasEmergency = !empty($data['emergency_contact_phone']) || (!array_key_exists('emergency_contact_phone', $data) && !empty($old['emergency_contact_phone']));
                    if (!empty($p['is_emergency_contact']) && $p['name'] !== '' && $p['phone'] && !$hasEmergency) {
                        $data['emergency_contact_name'] = $p['name'];
                        $data['emergency_contact_phone'] = $p['phone'];
                    }
                }
                if ($g) {
                    $data['guardian_name'] = $g['name'];
                    $data['guardian_phone'] = $g['phone'];
                    if ($g['rel'] !== 'guardian') {
                        $data['guardian_relation'] = ucfirst($g['rel']);
                    } elseif (empty($data['guardian_relation'])) {
                        $data['guardian_relation'] = 'Guardian';
                    }
                }
            }
            // Auto-generate IDs from Settings > Academic formats
            $year = students_admission_year($data + ($old ?? []));
            foreach (['student_uid', 'admission_no', 'roll_no'] as $col) {
                $blank = !isset($data[$col]) || $data[$col] === null || $data[$col] === '';
                if ($id === null && $blank) {
                    $data[$col] = students_generate_number($col, $programId, $year);
                } elseif ($id !== null && $blank && array_key_exists($col, $data)) {
                    if (!empty($old[$col])) {
                        unset($data[$col]);           // keep the existing number
                    } else {
                        $data[$col] = students_generate_number($col, $programId, $year);
                    }
                }
            }
            if (isset($data['status']) && $data['status'] === 'active' && $old && $old['status'] !== 'active' && !array_key_exists('status_reason', $input)) {
                $data['status_reason'] = null;
            }
            return $data;
        },

        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            $parents = $GLOBALS['__students_parents'];
            if (is_array($parents)) {
                students_sync_parents($id, $parents);
                students_refresh_parent_columns($id);
            } elseif ($old === null) {
                // Imports / API clients without a parents array: create rows from the name columns
                $s = db_row('SELECT father_name, mother_name, guardian_name, guardian_relation, guardian_phone FROM students WHERE id = ?', [$id]);
                $rows = [];
                if (!empty($s['father_name'])) {
                    $rows['father'] = ['name' => $s['father_name'], 'phone' => (($s['guardian_relation'] ?? '') === 'Father' || !$s['guardian_relation']) ? $s['guardian_phone'] : null, 'email' => null, 'occupation' => null, 'annual_income' => null, 'address' => null, 'is_emergency_contact' => 1];
                }
                if (!empty($s['mother_name'])) {
                    $rows['mother'] = ['name' => $s['mother_name'], 'phone' => null, 'email' => null, 'occupation' => null, 'annual_income' => null, 'address' => null, 'is_emergency_contact' => empty($rows['father']) ? 1 : 0];
                }
                if (!empty($s['guardian_name']) && $s['guardian_name'] !== $s['father_name'] && $s['guardian_name'] !== $s['mother_name']) {
                    $rows['guardian'] = ['name' => $s['guardian_name'], 'phone' => $s['guardian_phone'], 'email' => null, 'occupation' => null, 'annual_income' => null, 'address' => null, 'is_emergency_contact' => 0];
                }
                if ($rows) {
                    students_sync_parents($id, $rows);
                }
            }
            $academicChanged = $old === null || (int) $old['program_id'] !== (int) ($data['program_id'] ?? $old['program_id'])
                || (int) $old['current_semester'] !== (int) ($data['current_semester'] ?? $old['current_semester'])
                || (int) $old['section_id'] !== (int) ($data['section_id'] ?? $old['section_id'])
                || (string) $old['roll_no'] !== (string) ($data['roll_no'] ?? $old['roll_no'])
                || ($old['status'] !== 'active' && ($data['status'] ?? $old['status']) === 'active');
            if ($academicChanged) {
                students_sync_current_academic($id);
            }
            if ($old === null) {
                $s = db_row("SELECT s.first_name, s.last_name, s.student_uid, p.short_name FROM students s JOIN programs p ON p.id = s.program_id WHERE s.id = ?", [$id]);
                notify('perm:fees', 'admission', 'New student enrolled', trim($s['first_name'] . ' ' . $s['last_name']) . ' (' . $s['student_uid'] . ') joined ' . $s['short_name'] . '. Assign the fee structure.', 'students/' . $id, 'user-plus');
            } elseif (isset($data['status']) && $old['status'] !== $data['status']) {
                log_activity('status', 'students', $id, 'Changed status of ' . trim($old['first_name'] . ' ' . $old['last_name']) . ' (' . $old['student_uid'] . ') from ' . $old['status'] . ' to ' . $data['status']
                    . (!empty($data['status_reason']) ? ' — ' . $data['status_reason'] : ''));
            }
        },

        'describe' => function (array $row): string {
            return '"' . trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) . '"' . (!empty($row['student_uid']) ? ' (' . $row['student_uid'] . ')' : '');
        },

        'before_delete' => function (int $id, array $row): ?string {
            $linked = [];
            foreach ([
                ['SELECT COUNT(*) FROM payments WHERE student_id = ?', 'fee payments'],
                ['SELECT COUNT(*) FROM student_fees WHERE student_id = ? AND paid_amount > 0', 'paid invoices'],
                ['SELECT COUNT(*) FROM results WHERE student_id = ?', 'exam results'],
                ['SELECT COUNT(*) FROM exam_marks WHERE student_id = ?', 'exam marks'],
                ['SELECT COUNT(*) FROM certificates WHERE student_id = ?', 'certificates'],
                ["SELECT COUNT(*) FROM attendance_records WHERE person_type = 'student' AND person_id = ?", 'attendance records'],
                ['SELECT COUNT(*) FROM library_transactions lt JOIN library_members lm ON lm.id = lt.member_id WHERE lm.student_id = ?', 'library transactions'],
                ['SELECT COUNT(*) FROM hostel_allocations WHERE student_id = ?', 'hostel allocations'],
                ['SELECT COUNT(*) FROM placement_offers WHERE student_id = ?', 'placement offers'],
            ] as [$sql, $label]) {
                $n = (int) db_value($sql, [$id]);
                if ($n) {
                    $linked[] = $n . ' ' . $label;
                }
            }
            if ($linked) {
                return trim($row['first_name'] . ' ' . $row['last_name']) . ' has ' . implode(', ', array_slice($linked, 0, 3)) . ' and cannot be deleted. Deactivate the student instead.';
            }
            $GLOBALS['__students_deleted_docs'][$id] = db_column('SELECT file_path FROM student_documents WHERE student_id = ?', [$id]);
            return null;
        },

        'after_delete' => function (int $id, array $row): void {
            foreach ($GLOBALS['__students_deleted_docs'][$id] ?? [] as $path) {
                delete_upload($path);
            }
        },

        'after_bulk' => function (array $ids, string $field, $value): void {
            if ($field === 'status' && $value === 'active') {
                db_exec('UPDATE students SET status_reason = NULL WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
                foreach ($ids as $sid) {
                    students_sync_current_academic((int) $sid);
                }
            } elseif ($field === 'status' && in_array($value, students_inactive_statuses(), true)) {
                db_exec("UPDATE students SET status_reason = COALESCE(status_reason, 'Bulk status update') WHERE id IN (" . implode(',', array_map('intval', $ids)) . ')');
            }
        },

        'bulk_action' => function (string $action, array $ids, array $input): array {
            if ($action !== 'deactivate') {
                throw new CrudException('Unknown bulk action.');
            }
            $reason = trim((string) ($input['reason'] ?? ''));
            $status = (string) ($input['status'] ?? 'inactive');
            if (!in_array($status, students_inactive_statuses(), true)) {
                throw new CrudValidationException(['status' => 'Choose inactive, suspended or dropped.']);
            }
            if ($reason === '' || mb_strlen($reason) > 255) {
                throw new CrudValidationException(['reason' => $reason === '' ? 'Enter the reason for deactivation.' : 'Reason may not be longer than 255 characters.']);
            }
            $ids = array_values(array_filter(array_map('intval', $ids)));
            $n = db_exec('UPDATE students SET status = ?, status_reason = ? WHERE id IN (' . implode(',', $ids) . ')', [$status, $reason]);
            log_activity('status', 'students', implode(',', array_slice($ids, 0, 20)), 'Marked ' . count($ids) . ' student(s) ' . $status . ' — ' . $reason);
            return ['updated' => $n, 'message' => $n . ' student' . ($n === 1 ? '' : 's') . ' marked ' . (students_status_options()[$status] ?? $status) . '.'];
        },
    ],
];
