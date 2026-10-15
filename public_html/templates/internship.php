<?php
/**
 * Internship application (/internships/): page sections + application form (module position).
 * Areas come from Admin > Settings (internship_areas). Resume (PDF) stored privately in uploads/documents/internships.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$consents = ['privacy', 'child', 'conduct'];
$required = ['name', 'email', 'phone', 'city', 'state'];
$areas = recruitment_options('internship_areas');
$durations = recruitment_options('durations');
$modes = recruitment_options('modes');
$years = recruitment_options('year_of_study');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    recruitment_guard_post($slug, 'internship', 5);
    [$consentRules, $consentLabels] = recruitment_consent_rules($consents);
    [$data, $errors] = validate($_POST, personal_rules($required) + [
        'institution'     => 'required|min:2|max:200',
        'course'          => 'required|min:2|max:150',
        'year_of_study'   => 'in:' . implode(',', $years),
        'internship_area' => 'required|in:' . implode(',', $areas),
        'duration'        => 'required|in:' . implode(',', $durations),
        'preferred_start' => 'date',
        'mode'            => 'required|in:' . implode(',', array_keys($modes)),
        'skills'          => 'max:500',
        'motivation'      => 'required|text|min:30|max:2000',
    ] + $consentRules, $consentLabels + ['institution' => 'College / institution', 'internship_area' => 'Internship area', 'preferred_start' => 'Preferred start date', 'motivation' => 'Why you want to intern with us']);
    if (empty($errors['preferred_start']) && $data['preferred_start'] !== '') {
        if ($data['preferred_start'] <= date('Y-m-d')) {
            $errors['preferred_start'] = 'Preferred start date must be in the future.';
        } elseif ($data['preferred_start'] > date('Y-m-d', strtotime('+18 months'))) {
            $errors['preferred_start'] = 'Please choose a start date within the next 18 months.';
        }
    }
    $resume = recruitment_upload('resume', 'pdf', 'documents/internships', false, $errors);
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
    }
    try {
        $id = db_insert('internship_applications', [
            'user_id'         => current_user_id(),
            'name'            => $data['name'],
            'email'           => $data['email'],
            'phone'           => $data['phone'],
            'date_of_birth'   => $data['date_of_birth'] ?: null,
            'gender'          => $data['gender'] ?: null,
            'address'         => $data['address'] ?: null,
            'city'            => $data['city'],
            'state'           => $data['state'] ?: null,
            'pincode'         => $data['pincode'] ?: null,
            'institution'     => $data['institution'],
            'course'          => $data['course'],
            'year_of_study'   => $data['year_of_study'] ?: null,
            'internship_area' => $data['internship_area'],
            'duration'        => $data['duration'],
            'preferred_start' => $data['preferred_start'] ?: null,
            'mode'            => $data['mode'],
            'skills'          => $data['skills'] ?: null,
            'resume_path'     => $resume,
            'motivation'      => $data['motivation'],
            'ip_address'      => client_ip(),
        ]);
    } catch (Throwable $e) {
        delete_upload($resume);
        throw $e;
    }
    recruitment_finish_submission([
        'kind' => 'internship', 'id' => $id, 'prefix' => 'INT', 'table' => 'internship_applications', 'form' => 'internship',
        'name' => $data['name'], 'email' => $data['email'], 'label' => 'Internship application', 'conversion' => 'internship_application',
        'slug' => $slug,
        'admin_html' => '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ', ' . e($data['phone']) . ') applied for an internship.</p>'
            . '<p>' . e($data['course']) . ', ' . e($data['institution']) . '<br>Area: ' . e($data['internship_area']) . ' · ' . e($data['duration']) . ' · ' . e($modes[$data['mode']]) . '</p>',
        'next' => [
            ['title' => 'Review', 'text' => 'We review your course, interests and preferred dates against current projects.'],
            ['title' => 'Conversation', 'text' => 'A short interview to agree on a project, a mentor and a schedule.'],
            ['title' => 'Internship', 'text' => 'Learn by doing with regular check-ins with your mentor.'],
            ['title' => 'Certificate', 'text' => 'A certificate on completion after a final evaluation of your work.'],
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
render_sections($page, function () use ($user, $slug, $consents, $required, $areas, $durations, $modes, $years) {
    $mode = (string) old('mode', 'in_person');
    ?>
    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?= ui_heading('Internship application', 'Apply now', 'Fields marked * are required.', true) ?>
            <div class="rc-apply-layout">
                <div class="form-card rc-form-card">
                    <?= recruitment_form_open($slug, true) ?>
                        <h3 class="form-section-title"><?= icon('user') ?>Your details</h3>
                        <div class="form-grid">
                            <?php render_personal_fields($user, $required); ?>
                        </div>

                        <h3 class="form-section-title"><?= icon('graduation') ?>Education</h3>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="institution">College / institution<span class="req">*</span></label>
                                <input id="institution" type="text" name="institution" required maxlength="200" value="<?= e(old('institution')) ?>">
                                <?= field_error('institution') ?>
                            </div>
                            <div class="form-group">
                                <label for="course">Course<span class="req">*</span></label>
                                <input id="course" type="text" name="course" required maxlength="150" placeholder="e.g. BA Social Work, MBA" value="<?= e(old('course')) ?>">
                                <?= field_error('course') ?>
                            </div>
                            <div class="form-group">
                                <label for="year_of_study">Year of study</label>
                                <select id="year_of_study" name="year_of_study"><?= options_html($years, (string) old('year_of_study'), false) ?></select>
                                <?= field_error('year_of_study') ?>
                            </div>
                        </div>

                        <h3 class="form-section-title"><?= icon('award') ?>Internship preferences</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="internship_area">Area of interest<span class="req">*</span></label>
                                <select id="internship_area" name="internship_area" required><?= options_html($areas, (string) old('internship_area', (string) ($_GET['area'] ?? '')), false) ?></select>
                                <?= field_error('internship_area') ?>
                            </div>
                            <div class="form-group">
                                <label for="duration">Duration<span class="req">*</span></label>
                                <select id="duration" name="duration" required><?= options_html($durations, (string) old('duration'), false) ?></select>
                                <?= field_error('duration') ?>
                            </div>
                            <div class="form-group">
                                <label for="preferred_start">Preferred start date</label>
                                <input id="preferred_start" type="date" name="preferred_start" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= e(date('Y-m-d', strtotime('+18 months'))) ?>" value="<?= e(old('preferred_start')) ?>">
                                <?= field_error('preferred_start') ?>
                            </div>
                            <div class="form-group" role="radiogroup" aria-labelledby="imode-label">
                                <span class="label" id="imode-label">Mode<span class="req">*</span></span>
                                <div class="check-group">
                                    <?php foreach ($modes as $k => $label): ?>
                                        <label class="check-chip"><input type="radio" name="mode" value="<?= e($k) ?>"<?= $mode === $k ? ' checked' : '' ?> required><span><?= e($label) ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <?= field_error('mode') ?>
                            </div>
                            <div class="form-group full">
                                <label for="skills">Skills</label>
                                <input id="skills" type="text" name="skills" maxlength="500" placeholder="e.g. Research, writing, Canva, Excel, field surveys" value="<?= e(old('skills')) ?>">
                                <?= field_error('skills') ?>
                            </div>
                            <?= recruitment_file_field('resume', 'Resume / CV (optional)', false, 'PDF only.') ?>
                            <div class="form-group full">
                                <label for="motivation">What would you like to learn and contribute?<span class="req">*</span></label>
                                <textarea id="motivation" name="motivation" rows="4" required minlength="30" maxlength="2000" data-counter><?= e(old('motivation')) ?></textarea>
                                <?= field_error('motivation') ?>
                            </div>
                        </div>

                        <?= recruitment_consent_html($consents) ?>
                        <div class="form-actions">
                            <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…"><?= icon('send') ?>Submit application</button>
                        </div>
                        <?= recruitment_account_note($slug) ?>
                    </form>
                </div>
                <?= recruitment_apply_aside([
                    ['title' => 'Apply online', 'text' => 'Choose an area, duration and dates.'],
                    ['title' => 'Interview', 'text' => 'Agree on a project and a mentor.'],
                    ['title' => 'Learn by doing', 'text' => 'Regular check-ins and feedback.'],
                    ['title' => 'Certificate', 'text' => 'Issued on completion after evaluation.'],
                ]) ?>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
