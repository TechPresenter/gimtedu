<?php
/**
 * Admission applications (Admissions unit). Full-page form at /admissions/new, profile at /admissions/{id}.
 * Stage changes go through api/routes/admissions.php (admission_move) so every move is validated and logged.
 */
require_once APP_ROOT . '/app/services/admissions.php';

$counsellors = [
    'table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'sub' => 'u.designation', 'order' => 'u.name', 'search' => ['u.name', 'u.email'],
    'where' => "u.status = 'active' AND EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id LEFT JOIN role_permissions rp ON rp.role_id = r.id
                LEFT JOIN permissions pm ON pm.id = rp.permission_id WHERE ur.user_id = u.id AND (r.is_super = 1 OR (pm.module = 'admissions' AND pm.action IN ('edit','manage'))))",
];
$stageColors = ['enquiry' => 'slate', 'application' => 'blue', 'document_verification' => 'cyan', 'entrance_interview' => 'purple', 'approval' => 'amber',
    'fee_payment' => 'navy', 'confirmed' => 'green', 'waitlisted' => 'amber', 'rejected' => 'red', 'withdrawn' => 'slate'];
$fullName = "TRIM(CONCAT_WS(' ', t.first_name, t.middle_name, t.last_name))";

return [
    'table' => 'admissions',
    'title' => 'Applications',
    'singular' => 'Application',
    'permission' => 'admissions',
    'icon' => 'user-plus',
    'description' => 'Admission applications for the selected academic session.',
    'select' => "t.*, $fullName AS full_name, p.short_name AS program_name, p.name AS program_full, p.level AS program_level, c.name AS course_name,
                 p2.short_name AS second_program_name, ss.name AS session_name, u.name AS counsellor_name, u.avatar AS counsellor_avatar,
                 COALESCE(t.entrance_score, t.interview_score) AS score,
                 (SELECT MIN(f.next_followup_date) FROM admission_followups f WHERE f.admission_id = t.id AND f.completed_at IS NULL AND f.next_followup_date IS NOT NULL) AS next_followup,
                 (SELECT COUNT(*) FROM admission_documents d WHERE d.admission_id = t.id) AS docs_count,
                 (SELECT COUNT(*) FROM admission_documents d WHERE d.admission_id = t.id AND d.status = 'verified') AS docs_verified",
    'joins' => 'JOIN programs p ON p.id = t.program_id LEFT JOIN courses c ON c.id = t.course_id LEFT JOIN programs p2 ON p2.id = t.second_program_id
                LEFT JOIN academic_sessions ss ON ss.id = t.academic_session_id LEFT JOIN users u ON u.id = t.assigned_to',
    'search' => ['t.application_no', 't.first_name', 't.last_name', 't.phone', 't.email', "CONCAT(t.first_name, ' ', t.last_name)", 't.city'],
    'search_placeholder' => 'Search name, application no, phone, email…',
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'application_no', 'label' => 'Application No', 'format' => 'code', 'sortable' => true],
        ['key' => 'full_name', 'label' => 'Applicant', 'format' => 'person', 'image' => 'photo', 'sub' => 'phone', 'link' => '/admissions/{id}', 'sortable' => 't.first_name'],
        ['key' => 'program_name', 'label' => 'Program', 'format' => 'title', 'sub' => 'course_name', 'sortable' => 'p.short_name'],
        ['key' => 'source', 'label' => 'Source', 'format' => 'badge', 'sortable' => true, 'colors' => array_fill_keys(array_keys(admission_sources()), 'slate')],
        ['key' => 'stage', 'label' => 'Stage', 'format' => 'badge', 'sortable' => true, 'colors' => $stageColors],
        ['key' => 'counsellor_name', 'label' => 'Counsellor', 'format' => 'person', 'image' => 'counsellor_avatar', 'sortable' => 'u.name'],
        ['key' => 'score', 'label' => 'Score', 'format' => 'number', 'align' => 'center', 'sortable' => 'COALESCE(t.entrance_score, t.interview_score)'],
        ['key' => 'created_at', 'label' => 'Applied On', 'format' => 'date', 'sortable' => true],
        ['key' => 'email', 'label' => 'Email', 'format' => 'email', 'hidden' => true],
        ['key' => 'city', 'label' => 'City', 'hidden' => true, 'sortable' => true],
        ['key' => 'category', 'label' => 'Category', 'hidden' => true, 'sortable' => true],
        ['key' => 'quota', 'label' => 'Quota', 'format' => 'badge', 'hidden' => true],
        ['key' => 'previous_percentage', 'label' => 'Qualifying %', 'format' => 'percent', 'hidden' => true, 'sortable' => true],
        ['key' => 'session_name', 'label' => 'Session', 'hidden' => true],
        ['key' => 'fee_paid', 'label' => 'Fee Paid', 'format' => 'money', 'hidden' => true, 'sortable' => true],
        ['key' => 'next_followup', 'label' => 'Next Follow-up', 'format' => 'date', 'hidden' => true],
    ],
    'export_columns' => [
        ['key' => 'application_no', 'label' => 'Application No'], ['key' => 'full_name', 'label' => 'Applicant'], ['key' => 'gender', 'label' => 'Gender', 'format' => 'badge'],
        ['key' => 'dob', 'label' => 'Date of Birth', 'format' => 'date'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'email', 'label' => 'Email'], ['key' => 'city', 'label' => 'City'],
        ['key' => 'state', 'label' => 'State'], ['key' => 'program_name', 'label' => 'Program'], ['key' => 'course_name', 'label' => 'Specialisation'],
        ['key' => 'second_program_name', 'label' => 'Second Preference'], ['key' => 'category', 'label' => 'Category'], ['key' => 'quota', 'label' => 'Quota', 'format' => 'badge'],
        ['key' => 'previous_qualification', 'label' => 'Qualifying Exam'], ['key' => 'previous_percentage', 'label' => 'Qualifying %'], ['key' => 'source', 'label' => 'Source', 'format' => 'badge'],
        ['key' => 'stage', 'label' => 'Stage', 'format' => 'badge'], ['key' => 'counsellor_name', 'label' => 'Counsellor'], ['key' => 'entrance_score', 'label' => 'Entrance Score'],
        ['key' => 'interview_score', 'label' => 'Interview Score'], ['key' => 'admission_fee', 'label' => 'Admission Fee', 'format' => 'money'], ['key' => 'fee_paid', 'label' => 'Fee Paid', 'format' => 'money'],
        ['key' => 'session_name', 'label' => 'Session'], ['key' => 'created_at', 'label' => 'Applied On', 'format' => 'date'],
    ],
    'filters' => [
        ['key' => 'stage', 'label' => 'Stage', 'options' => admission_all_stages()],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs'],
        ['key' => 'source', 'label' => 'Source', 'options' => admission_sources()],
        ['key' => 'academic_session_id', 'label' => 'Session', 'source' => 'sessions'],
        ['key' => 'assigned_to', 'label' => 'Counsellor', 'source' => $counsellors],
        ['key' => 'category', 'label' => 'Category', 'options' => admission_categories()],
        ['key' => 'created_at', 'label' => 'Applied', 'type' => 'daterange'],
    ],
    'scopes' => ['enquiry_id' => 't.enquiry_id', 'program_id' => 't.program_id', 'academic_session_id' => 't.academic_session_id'],
    'fields' => [
        ['type' => 'section', 'label' => 'Applicant details', 'help' => 'Personal information exactly as in the Class 10 certificate.', 'name' => 'sec_personal'],
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 80, 'placeholder' => 'e.g. Ananya'],
        ['name' => 'middle_name', 'label' => 'Middle name', 'type' => 'text', 'col' => 4, 'maxlength' => 80],
        ['name' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'col' => 4, 'maxlength' => 80, 'placeholder' => 'e.g. Sharma'],
        ['name' => 'gender', 'label' => 'Gender', 'type' => 'select', 'required' => true, 'col' => 4, 'options' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
        ['name' => 'dob', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'col' => 4],
        ['name' => 'blood_group', 'label' => 'Blood group', 'type' => 'select', 'col' => 4, 'options' => array_combine(['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'], ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'])],
        ['name' => 'nationality', 'label' => 'Nationality', 'type' => 'text', 'col' => 4, 'default' => 'Indian', 'maxlength' => 60],
        ['name' => 'aadhaar_no', 'label' => 'Aadhaar number', 'type' => 'text', 'col' => 4, 'maxlength' => 14, 'placeholder' => '12-digit Aadhaar', 'rules' => ['regex:/^\d{4}\s?\d{4}\s?\d{4}$/']],
        ['name' => 'photo', 'label' => 'Passport photo', 'type' => 'image', 'col' => 4, 'folder' => 'admissions', 'help' => 'JPG/PNG, white background.'],

        ['type' => 'section', 'label' => 'Contact details', 'name' => 'sec_contact'],
        ['name' => 'phone', 'label' => 'Mobile number', 'type' => 'tel', 'required' => true, 'col' => 4, 'placeholder' => '+91 98765 43210'],
        ['name' => 'whatsapp', 'label' => 'WhatsApp number', 'type' => 'tel', 'col' => 4],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'col' => 4, 'placeholder' => 'name@example.com'],
        ['name' => 'address', 'label' => 'Address', 'type' => 'text', 'col' => 12, 'placeholder' => 'House no., street, locality'],
        ['name' => 'city', 'label' => 'City', 'type' => 'text', 'col' => 3, 'maxlength' => 80],
        ['name' => 'state', 'label' => 'State', 'type' => 'text', 'col' => 3, 'maxlength' => 80],
        ['name' => 'pincode', 'label' => 'PIN code', 'type' => 'text', 'col' => 3, 'maxlength' => 12, 'rules' => ['regex:/^\d{6}$/']],
        ['name' => 'country', 'label' => 'Country', 'type' => 'text', 'col' => 3, 'default' => 'India', 'maxlength' => 80],

        ['type' => 'section', 'label' => 'Parents / guardian', 'name' => 'sec_parents'],
        ['name' => 'father_name', 'label' => "Father's name", 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'father_phone', 'label' => "Father's mobile", 'type' => 'tel', 'col' => 3],
        ['name' => 'father_occupation', 'label' => 'Occupation', 'type' => 'text', 'col' => 3, 'maxlength' => 100],
        ['name' => 'mother_name', 'label' => "Mother's name", 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'mother_phone', 'label' => "Mother's mobile", 'type' => 'tel', 'col' => 3],
        ['name' => 'mother_occupation', 'label' => 'Occupation', 'type' => 'text', 'col' => 3, 'maxlength' => 100],
        ['name' => 'guardian_name', 'label' => 'Local guardian (if different)', 'type' => 'text', 'col' => 4, 'maxlength' => 150],
        ['name' => 'guardian_relation', 'label' => 'Relation', 'type' => 'text', 'col' => 4, 'maxlength' => 40],
        ['name' => 'guardian_phone', 'label' => 'Guardian mobile', 'type' => 'tel', 'col' => 4],
        ['name' => 'family_income', 'label' => 'Annual family income', 'type' => 'money', 'col' => 4, 'min' => 0, 'max' => 100000000],

        ['type' => 'section', 'label' => 'Previous education', 'help' => 'Class 10 and the qualifying examination (Class 12 for UG, graduation for PG programs).', 'name' => 'sec_education'],
        ['name' => 'tenth_board', 'label' => 'Class 10 board', 'type' => 'text', 'col' => 6, 'maxlength' => 100, 'placeholder' => 'e.g. CBSE'],
        ['name' => 'tenth_year', 'label' => 'Passing year', 'type' => 'number', 'col' => 3, 'min' => 1980, 'max' => 2030],
        ['name' => 'tenth_percentage', 'label' => 'Marks %', 'type' => 'decimal', 'col' => 3, 'min' => 0, 'max' => 100, 'step' => 0.01],
        ['name' => 'previous_qualification', 'label' => 'Qualifying exam', 'type' => 'select', 'required' => true, 'col' => 4,
            'options' => ['Class 12' => 'Class 12 / Intermediate', 'Diploma' => 'Diploma (3 years)', 'Graduation' => 'Graduation', 'Post Graduation' => 'Post Graduation', 'Other' => 'Other']],
        ['name' => 'previous_board', 'label' => 'Board / University', 'type' => 'text', 'required' => true, 'col' => 4, 'maxlength' => 100, 'placeholder' => 'e.g. CBSE / CCS University'],
        ['name' => 'previous_institution', 'label' => 'School / College', 'type' => 'text', 'col' => 4, 'maxlength' => 190],
        ['name' => 'passing_year', 'label' => 'Passing year', 'type' => 'number', 'required' => true, 'col' => 4, 'min' => 1980, 'max' => 2030],
        ['name' => 'previous_percentage', 'label' => 'Marks % / CGPA %', 'type' => 'decimal', 'required' => true, 'col' => 4, 'min' => 0, 'max' => 100, 'step' => 0.01],
        ['name' => 'entrance_exam', 'label' => 'Entrance exam taken', 'type' => 'text', 'col' => 4, 'maxlength' => 60, 'placeholder' => 'e.g. CUET, JEE Main, CAT, MAT'],

        ['type' => 'section', 'label' => 'Program choice', 'name' => 'sec_program'],
        ['name' => 'academic_session_id', 'label' => 'Academic session', 'type' => 'select', 'required' => true, 'col' => 6, 'source' => 'sessions', 'default' => current_session_id()],
        ['name' => 'program_id', 'label' => 'Program applied for', 'type' => 'select', 'required' => true, 'col' => 6, 'source' => 'programs'],
        ['name' => 'course_id', 'label' => 'Specialisation', 'type' => 'select', 'col' => 6, 'source' => 'courses', 'depends' => ['program_id' => 'program_id']],
        ['name' => 'second_program_id', 'label' => 'Second preference', 'type' => 'select', 'col' => 6, 'source' => 'programs'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'General', 'options' => admission_categories()],
        ['name' => 'quota', 'label' => 'Admission quota', 'type' => 'select', 'required' => true, 'col' => 4, 'default' => 'general', 'options' => admission_quotas()],
        ['name' => 'hostel_required', 'label' => 'Hostel required', 'type' => 'toggle', 'col' => 2],
        ['name' => 'transport_required', 'label' => 'Transport required', 'type' => 'toggle', 'col' => 2],

        ['type' => 'section', 'label' => 'Source & counselling', 'name' => 'sec_source'],
        ['name' => 'source', 'label' => 'Lead source', 'type' => 'select', 'required' => true, 'col' => 6, 'default' => 'walk_in', 'options' => admission_sources()],
        ['name' => 'assigned_to', 'label' => 'Counsellor', 'type' => 'select', 'col' => 6, 'source' => $counsellors],
        ['name' => 'counselling_notes', 'label' => 'Counselling notes', 'type' => 'textarea', 'col' => 12, 'rows' => 3, 'maxlength' => 5000, 'placeholder' => 'Career goals, preferences, concerns raised during counselling…'],

        ['type' => 'section', 'label' => 'Declaration', 'name' => 'sec_declaration'],
        ['name' => 'declaration_accepted', 'label' => 'Declaration accepted', 'type' => 'checkbox', 'col' => 12, 'import' => false],

        // Not on the form (managed by the pipeline/profile actions), exposed for import/export/API.
        ['name' => 'application_no', 'label' => 'Application no', 'type' => 'text', 'form' => false, 'import' => false],
        ['name' => 'entrance_score', 'label' => 'Entrance score', 'type' => 'decimal', 'form' => false, 'min' => 0, 'max' => 1000],
        ['name' => 'interview_score', 'label' => 'Interview score', 'type' => 'decimal', 'form' => false, 'min' => 0, 'max' => 100],
    ],
    'bulk' => ['delete' => true],
    'import' => true,
    'export' => true,
    'per_page' => 25,
    'view' => ['type' => 'page', 'url' => '/admissions/{id}'],
    'form' => ['size' => 'xl', 'mode' => 'page'],
    'default_sort' => ['key' => 'created_at', 'dir' => 'desc'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $e = [];
            $v = fn ($k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            if (!empty($data['dob'])) {
                $age = (int) floor((time() - strtotime($data['dob'])) / 31557600);
                if ($data['dob'] >= date('Y-m-d')) {
                    $e['dob'] = 'Date of birth must be in the past.';
                } elseif ($age < 14 || $age > 65) {
                    $e['dob'] = 'Applicant age must be between 14 and 65 years.';
                }
            }
            if (!empty($data['phone']) && strlen(admission_phone_key($data['phone'])) < 10) {
                $e['phone'] = 'Enter a valid 10-digit mobile number.';
            }
            foreach (['passing_year', 'tenth_year'] as $y) {
                if (!empty($data[$y]) && (int) $data[$y] > (int) date('Y') + 1) {
                    $e[$y] = 'Passing year cannot be in the future.';
                }
            }
            if ($v('tenth_year') && $v('passing_year') && (int) $v('passing_year') < (int) $v('tenth_year')) {
                $e['passing_year'] = 'Qualifying exam year must be after the Class 10 year.';
            }
            if ($v('second_program_id') && (int) $v('second_program_id') === (int) $v('program_id')) {
                $e['second_program_id'] = 'Second preference must be a different program.';
            }
            if ($v('course_id') && $v('program_id') && (int) db_value('SELECT program_id FROM courses WHERE id = ?', [(int) $v('course_id')]) !== (int) $v('program_id')) {
                $e['course_id'] = 'This specialisation does not belong to the selected program.';
            }
            if ($id === null && !empty($input['__form']) && empty($data['declaration_accepted'])) {
                $e['declaration_accepted'] = 'The applicant must accept the declaration.';
            }
            // Duplicate detection: same person already applied for the same program in the same session
            $phone = $v('phone');
            $email = $v('email');
            $program = (int) $v('program_id');
            $session = (int) ($v('academic_session_id') ?: current_session_id());
            if ($program && ($phone || $email) && empty($e['phone'])) {
                $args = [$program, $session];
                $or = [];
                if ($phone) {
                    $or[] = admission_phone_sql('a.phone') . ' = ?';
                    $args[] = admission_phone_key($phone);
                }
                if ($email) {
                    $or[] = 'LOWER(a.email) = ?';
                    $args[] = strtolower((string) $email);
                }
                $dup = db_row("SELECT a.application_no, a.phone, a.email, p.short_name FROM admissions a JOIN programs p ON p.id = a.program_id
                               WHERE a.program_id = ? AND a.academic_session_id = ? AND a.stage NOT IN ('rejected','withdrawn') AND (" . implode(' OR ', $or) . ')'
                               . ($id ? ' AND a.id <> ' . (int) $id : '') . ' LIMIT 1', $args);
                if ($dup) {
                    $field = $phone && admission_phone_key($dup['phone']) === admission_phone_key($phone) ? 'phone' : 'email';
                    $e[$field] = sprintf('This applicant has already applied for %s this session (%s).', $dup['short_name'], $dup['application_no']);
                }
            }
            return $e;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null) {
                $data['academic_session_id'] = $data['academic_session_id'] ?? current_session_id();
                $data['application_no'] = admission_next_application_no($data['academic_session_id'] ? (int) $data['academic_session_id'] : null);
                $data['stage'] = 'application';
                $data['stage_changed_at'] = date('Y-m-d H:i:s');
                $data['nationality'] = $data['nationality'] ?? 'Indian';
                $data['country'] = $data['country'] ?? 'India';
                $data['quota'] = $data['quota'] ?? 'general';
                $data['source'] = $data['source'] ?? 'walk_in';
                $data['ip_address'] = PHP_SAPI === 'cli' ? null : client_ip();
                if (!empty($input['enquiry_id']) && db_value('SELECT COUNT(*) FROM enquiries WHERE id = ?', [(int) $input['enquiry_id']])) {
                    $data['enquiry_id'] = (int) $input['enquiry_id'];
                }
            } elseif (isset($data['program_id']) && (int) $data['program_id'] !== (int) $old['program_id'] && (float) $old['fee_paid'] <= 0) {
                $data['admission_fee'] = null; // recalculated from the new program at approval
            }
            if (!empty($data['declaration_accepted']) && empty($old['declaration_accepted'])) {
                $data['declaration_at'] = date('Y-m-d H:i:s');
            }
            if (!empty($data['aadhaar_no'])) {
                $data['aadhaar_no'] = preg_replace('/\s+/', '', (string) $data['aadhaar_no']);
            }
            if (!empty($old['student_id'])) {
                foreach (['program_id', 'course_id', 'academic_session_id'] as $locked) {
                    unset($data[$locked]);
                }
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if ($old !== null) {
                if (array_key_exists('assigned_to', $data) && $data['assigned_to'] && (int) $data['assigned_to'] !== (int) $old['assigned_to'] && (int) $data['assigned_to'] !== (int) user_id()) {
                    notify((int) $data['assigned_to'], 'application', 'Application assigned to you', admission_full_name($old + $data) . ' (' . $old['application_no'] . ')', 'admin/admissions/' . $id, 'user-plus');
                }
                return;
            }
            $a = db_row('SELECT a.*, p.short_name, p.name AS program_name FROM admissions a JOIN programs p ON p.id = a.program_id WHERE a.id = ?', [$id]);
            admission_add_history($id, null, 'application', !empty($a['enquiry_id']) ? 'Application created from enquiry #' . $a['enquiry_id'] : 'Application received');
            if (!empty($a['enquiry_id'])) {
                db_exec("UPDATE enquiries SET status = 'converted', admission_id = ?, follow_up_date = NULL WHERE id = ?", [$id, (int) $a['enquiry_id']]);
                db_exec('UPDATE admission_followups SET completed_at = NOW(), completed_by = ? WHERE enquiry_id = ? AND completed_at IS NULL AND next_followup_date IS NOT NULL', [user_id(), (int) $a['enquiry_id']]);
            }
            if (adm_is_importing()) {
                return;
            }
            $name = admission_full_name($a);
            notify('perm:admissions', 'application', 'New application received', sprintf('%s applied for %s (%s).', $name, $a['short_name'], $a['application_no']), 'admin/admissions/' . $id, 'file-plus');
            if ($a['email']) {
                send_template_mail('admission_received', $a['email'], ['name' => $name, 'program' => $a['short_name'] . ' — ' . $a['program_name'], 'application_no' => $a['application_no']],
                    ['related_type' => 'admission', 'related_id' => $id]);
            }
        },
        'before_delete' => function (int $id, array $row) {
            if (!empty($row['student_id'])) {
                return 'Application ' . $row['application_no'] . ' has been converted to a student and cannot be deleted.';
            }
            if ((float) $row['fee_paid'] > 0) {
                return 'An admission fee is recorded on ' . $row['application_no'] . '. Mark the application as withdrawn instead of deleting it.';
            }
            $GLOBALS['__adm_delete_files'][$id] = db_column('SELECT file_path FROM admission_documents WHERE admission_id = ?', [$id]);
            return null;
        },
        'after_delete' => function (int $id, array $row): void {
            foreach ($GLOBALS['__adm_delete_files'][$id] ?? [] as $path) {
                delete_upload($path);
            }
        },
        'transform_row' => function (array $r): array {
            $r['stage_label'] = admission_stage_label($r['stage'] ?? '');
            return $r;
        },
        'summary' => function (string $from, array $args): array {
            $counts = db_pairs('SELECT t.stage, COUNT(*)' . $from . ' GROUP BY t.stage', $args);
            return ['stages' => array_map('intval', $counts)];
        },
        'describe' => fn (array $row) => admission_full_name($row) . ' (' . ($row['application_no'] ?? '#' . ($row['id'] ?? '')) . ')',
    ],
];
