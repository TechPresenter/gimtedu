<?php
/**
 * Scholarship application (/scholarship/): page sections + application form (module position).
 * Saves to scholarships (reference PWF-SCH-YYYY-NNNNN). Supporting document stored privately in uploads/documents/scholarships.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$consents = ['privacy', 'guardian', 'declaration'];
$required = ['email', 'phone', 'address', 'city', 'state'];
$yearNow = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
$academicYear = $yearNow . '-' . substr((string) ($yearNow + 1), -2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    recruitment_guard_post($slug, 'scholarship', 4);
    [$consentRules, $consentLabels] = recruitment_consent_rules($consents);
    $rules = personal_rules($required);
    unset($rules['name']);
    [$data, $errors] = validate($_POST, [
        'student_name'  => 'required|min:2|max:120',
        'guardian_name' => 'required|min:2|max:120',
    ] + $rules + [
        'institution'          => 'required|min:2|max:200',
        'course_class'         => 'required|min:1|max:120',
        'academic_year'        => 'required|max:20',
        'last_exam_percentage' => 'numeric|min:0|max:100',
        'annual_family_income' => 'required|numeric|min:0|max:100000000',
        'reason'               => 'required|text|min:50|max:3000',
    ] + $consentRules, $consentLabels + [
        'student_name' => 'Student name', 'guardian_name' => 'Parent / guardian name', 'course_class' => 'Class / course',
        'last_exam_percentage' => 'Last exam result', 'annual_family_income' => 'Annual family income', 'reason' => 'Reason for applying',
    ]);
    if (empty($errors['academic_year']) && !preg_match('/^(20\d{2})-(\d{2})$/', $data['academic_year'], $m)) {
        $errors['academic_year'] = 'Please enter the academic year like ' . $academicYear . '.';
    } elseif (empty($errors['academic_year']) && (int) $m[2] !== ((int) $m[1] + 1) % 100) {
        $errors['academic_year'] = 'Please enter the academic year like ' . $academicYear . '.';
    }
    $doc = recruitment_upload('document', 'document', 'documents/scholarships', false, $errors);
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
    }
    try {
        $id = db_insert('scholarships', [
            'user_id'              => current_user_id(),
            'student_name'         => $data['student_name'],
            'guardian_name'        => $data['guardian_name'],
            'email'                => $data['email'],
            'phone'                => $data['phone'],
            'date_of_birth'        => $data['date_of_birth'] ?: null,
            'gender'               => $data['gender'] ?: null,
            'address'              => $data['address'],
            'city'                 => $data['city'],
            'state'                => $data['state'] ?: null,
            'pincode'              => $data['pincode'] ?: null,
            'institution'          => $data['institution'],
            'course_class'         => $data['course_class'],
            'academic_year'        => $data['academic_year'],
            'last_exam_percentage' => $data['last_exam_percentage'] !== '' ? round((float) $data['last_exam_percentage'], 2) : null,
            'annual_family_income' => round((float) $data['annual_family_income'], 2),
            'reason'               => $data['reason'],
            'document_path'        => $doc,
            'ip_address'           => client_ip(),
        ]);
    } catch (Throwable $e) {
        delete_upload($doc);
        throw $e;
    }
    recruitment_finish_submission([
        'kind' => 'scholarship', 'id' => $id, 'prefix' => 'SCH', 'table' => 'scholarships', 'form' => 'scholarship', 'status' => 'pending',
        'name' => $data['guardian_name'], 'email' => $data['email'], 'label' => 'Scholarship application', 'conversion' => 'scholarship_application',
        'slug' => $slug,
        'admin_html' => '<p>Scholarship application for <strong>' . e($data['student_name']) . '</strong> (' . e($data['course_class']) . ', ' . e($data['institution']) . '), '
            . e($data['city']) . '.</p><p>Document: ' . ($doc ? 'uploaded' : 'not uploaded') . '</p>',
        'next' => [
            ['title' => 'Review', 'text' => 'Our team reviews the application, the documents and the family\'s situation.'],
            ['title' => 'Verification', 'text' => 'We may call you, ask for documents or speak with the school or college.'],
            ['title' => 'Decision', 'text' => 'Support depends on available funds and verification. We inform you of the outcome.'],
            ['title' => 'Support', 'text' => 'If the application is approved, we explain what support will be provided and how.'],
        ],
    ]);
}

$success = recruitment_take_success($slug);
recruitment_form_assets();
page_header($page, $success ? ['buttons' => []] : []);
if ($success) {
    echo recruitment_success_html($success);
    page_footer();
    return;
}

$user = current_user();
render_sections($page, function () use ($user, $slug, $consents, $required, $academicYear) {
    ?>
    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?= ui_heading('Scholarship application', 'Apply now', 'A parent or guardian should fill in this form with the student. Fields marked * are required.', true) ?>
            <div class="rc-apply-layout">
                <div class="form-card rc-form-card">
                    <?= recruitment_form_open($slug, true) ?>
                        <h3 class="form-section-title"><?= icon('user') ?>Student &amp; family</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="student_name">Student's full name<span class="req">*</span></label>
                                <input id="student_name" type="text" name="student_name" required maxlength="120" value="<?= e(old('student_name')) ?>">
                                <?= field_error('student_name') ?>
                            </div>
                            <div class="form-group">
                                <label for="guardian_name">Parent / guardian name<span class="req">*</span></label>
                                <input id="guardian_name" type="text" name="guardian_name" required maxlength="120" value="<?= e(old('guardian_name')) ?>">
                                <?= field_error('guardian_name') ?>
                            </div>
                            <?php render_personal_fields($user, $required, ['name']); ?>
                        </div>
                        <p class="help rc-help-line"><?= icon('info') ?> Email and mobile number are used to contact the family. Date of birth and gender refer to the student.</p>

                        <h3 class="form-section-title"><?= icon('graduation') ?>Education</h3>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="institution">School / college / institute<span class="req">*</span></label>
                                <input id="institution" type="text" name="institution" required maxlength="200" value="<?= e(old('institution')) ?>">
                                <?= field_error('institution') ?>
                            </div>
                            <div class="form-group">
                                <label for="course_class">Class / course<span class="req">*</span></label>
                                <input id="course_class" type="text" name="course_class" required maxlength="120" placeholder="e.g. Class 10, B.A. 2nd year" value="<?= e(old('course_class')) ?>">
                                <?= field_error('course_class') ?>
                            </div>
                            <div class="form-group">
                                <label for="academic_year">Academic year<span class="req">*</span></label>
                                <input id="academic_year" type="text" name="academic_year" required maxlength="7" pattern="20[0-9]{2}-[0-9]{2}" title="Format: <?= e($academicYear) ?>" value="<?= e(old('academic_year', $academicYear)) ?>">
                                <?= field_error('academic_year') ?>
                            </div>
                            <div class="form-group">
                                <label for="last_exam_percentage">Last exam result (%)</label>
                                <input id="last_exam_percentage" type="number" name="last_exam_percentage" min="0" max="100" step="0.01" inputmode="decimal" value="<?= e(old('last_exam_percentage')) ?>">
                                <?= field_error('last_exam_percentage') ?>
                            </div>
                            <div class="form-group">
                                <label for="annual_family_income">Annual family income (₹)<span class="req">*</span></label>
                                <input id="annual_family_income" type="number" name="annual_family_income" required min="0" max="100000000" step="1" inputmode="numeric" value="<?= e(old('annual_family_income')) ?>">
                                <?= field_error('annual_family_income') ?>
                            </div>
                            <div class="form-group full">
                                <label for="reason">Why does the student need support, and what for?<span class="req">*</span></label>
                                <textarea id="reason" name="reason" rows="5" required minlength="50" maxlength="3000" data-counter placeholder="For example: school or college fees, books, uniform, travel - and anything about the family's situation you would like us to know."><?= e(old('reason')) ?></textarea>
                                <?= field_error('reason') ?>
                            </div>
                            <?= recruitment_file_field('document', 'Supporting document (optional)', false, 'Latest marksheet, fee receipt or income certificate - one PDF, JPG or PNG. Please do not upload Aadhaar or bank card images.', false) ?>
                        </div>

                        <?= recruitment_consent_html($consents) ?>
                        <div class="form-actions">
                            <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…"><?= icon('send') ?>Submit application</button>
                        </div>
                        <?= recruitment_account_note($slug) ?>
                    </form>
                </div>
                <?= recruitment_apply_aside([
                    ['title' => 'Apply online', 'text' => 'Student, family and education details.'],
                    ['title' => 'Verification', 'text' => 'Documents and a conversation with the family.'],
                    ['title' => 'Decision', 'text' => 'Subject to available funds and verification.'],
                    ['title' => 'Support', 'text' => 'Fees, books, uniforms or other education needs.'],
                ], '', [
                    'Privacy Policy' => page_url('privacy-policy'), 'Child Protection Policy' => page_url('child-protection-policy'),
                    'Check application status' => page_url('application-status'), 'Request other support' => page_url('request-help'),
                ]) ?>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
