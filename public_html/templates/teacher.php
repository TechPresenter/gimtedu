<?php
/**
 * Volunteer teacher application (/volunteer-teacher/): page sections + application form (module position).
 * Resume (PDF) is stored privately in uploads/documents/teachers and downloaded only through the admin panel.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$consents = ['privacy', 'child', 'conduct', 'verify', 'adult'];
$required = ['name', 'email', 'phone', 'city', 'state'];
$subjects = recruitment_options('subjects');
$classes = ['Primary (1-5)', 'Middle (6-8)', 'Secondary (9-10)', 'Senior Secondary (11-12)', 'Adult learners'];
$languages = recruitment_options('languages');
$modes = recruitment_options('modes');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    recruitment_guard_post($slug, 'teacher', 5);
    [$consentRules, $consentLabels] = recruitment_consent_rules($consents);
    [$data, $errors] = validate($_POST, personal_rules($required) + [
        'qualification'       => 'required|min:2|max:150',
        'subjects'            => 'required|array|in:' . implode(',', $subjects),
        'classes_preferred'   => 'array|in:' . implode(',', $classes),
        'languages'           => 'required|array|in:' . implode(',', $languages),
        'teaching_experience' => 'in:' . implode(',', recruitment_options('teaching_experience')),
        'teaching_mode'       => 'required|in:' . implode(',', array_keys($modes)),
        'availability'        => 'required|in:' . implode(',', AVAILABILITY_OPTIONS),
        'motivation'          => 'required|text|min:30|max:2000',
        'accept_terms'        => 'accepted',
    ] + $consentRules, $consentLabels + ['accept_terms' => 'Volunteer teacher terms & conditions', 'subjects' => 'Subjects', 'languages' => 'Teaching languages', 'teaching_mode' => 'Teaching mode', 'motivation' => 'Why you want to teach']);
    $resume = recruitment_upload('resume', 'pdf', 'documents/teachers', false, $errors);
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
    }
    try {
        $id = db_insert('teacher_applications', [
            'user_id'             => current_user_id(),
            'name'                => $data['name'],
            'email'               => $data['email'],
            'phone'               => $data['phone'],
            'date_of_birth'       => $data['date_of_birth'] ?: null,
            'gender'              => $data['gender'] ?: null,
            'address'             => $data['address'] ?: null,
            'city'                => $data['city'],
            'state'               => $data['state'] ?: null,
            'pincode'             => $data['pincode'] ?: null,
            'qualification'       => $data['qualification'],
            'subjects'            => mb_substr(implode(', ', $data['subjects']), 0, 255),
            'classes_preferred'   => $data['classes_preferred'] ? mb_substr(implode(', ', $data['classes_preferred']), 0, 100) : null,
            'languages'           => mb_substr(implode(', ', $data['languages']), 0, 150),
            'teaching_experience' => $data['teaching_experience'] ?: null,
            'teaching_mode'       => $data['teaching_mode'],
            'availability'        => $data['availability'],
            'resume_path'         => $resume,
            'motivation'          => $data['motivation'],
            'terms_accepted_at'   => date('Y-m-d H:i:s'),
            'terms_version'       => teacher_terms()['version'],
            'ip_address'          => client_ip(),
        ]);
    } catch (Throwable $e) {
        delete_upload($resume);
        throw $e;
    }
    recruitment_finish_submission([
        'kind' => 'teacher', 'id' => $id, 'prefix' => 'TCH', 'table' => 'teacher_applications', 'form' => 'teacher',
        'name' => $data['name'], 'email' => $data['email'], 'label' => 'Volunteer teacher application', 'conversion' => 'teacher_application',
        'slug' => $slug,
        'admin_html' => '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ', ' . e($data['phone']) . ', ' . e($data['city']) . ') applied to teach.</p>'
            . '<p>Subjects: ' . e(implode(', ', $data['subjects'])) . '<br>Mode: ' . e($modes[$data['teaching_mode']]) . '<br>Resume: ' . ($resume ? 'uploaded' : 'not uploaded') . '</p>',
        'next' => [
            ['title' => 'Review', 'text' => 'Our education coordinator reviews your subjects, experience and availability.'],
            ['title' => 'Conversation', 'text' => 'A short conversation, and sometimes a demo session, to understand your teaching style.'],
            ['title' => 'Verification', 'text' => 'Identity and reference checks and a child protection briefing before any placement.'],
            ['title' => 'Start teaching', 'text' => 'You are matched with a learning group in person or online.'],
        ],
    ]);
}

$success = recruitment_take_success($slug);
recruitment_form_assets();
$GLOBALS['extraScripts'][] = asset('assets/js/modules/terms-gate.js');
page_header($page, $success ? ['buttons' => []] : []);
if ($success) {
    echo recruitment_success_html($success);
    page_footer();
    return;
}

$user = current_user();
render_sections($page, function () use ($user, $slug, $consents, $required, $subjects, $classes, $languages, $modes) {
    $mode = (string) old('teaching_mode', 'in_person');
    ?>
    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?= ui_heading('Volunteer teacher application', 'Apply to teach', 'Share what you can teach and when. Fields marked * are required.', true) ?>
            <div class="rc-apply-layout">
                <div class="form-card rc-form-card terms-gated" data-terms-gate="teacher-terms">
                    <div class="terms-gate-bar" data-terms-bar hidden>
                        <span><?= icon('lock') ?>Please read and accept the terms &amp; conditions to fill in this form.</span>
                        <button type="button" class="btn btn-primary btn-sm" data-modal-open="teacher-terms"><?= icon('file-text') ?>Read &amp; accept</button>
                    </div>
                    <?= recruitment_form_open($slug, true) ?>
                        <h3 class="form-section-title"><?= icon('user') ?>Your details</h3>
                        <div class="form-grid">
                            <?php render_personal_fields($user, $required); ?>
                        </div>

                        <h3 class="form-section-title"><?= icon('graduation') ?>Teaching details</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="qualification">Highest qualification<span class="req">*</span></label>
                                <input id="qualification" type="text" name="qualification" required maxlength="150" placeholder="e.g. B.Sc., B.Ed., M.A." value="<?= e(old('qualification')) ?>">
                                <?= field_error('qualification') ?>
                            </div>
                            <div class="form-group">
                                <label for="teaching_experience">Teaching experience</label>
                                <select id="teaching_experience" name="teaching_experience"><?= options_html(recruitment_options('teaching_experience'), (string) old('teaching_experience'), false) ?></select>
                                <?= field_error('teaching_experience') ?>
                            </div>
                            <div class="form-group full" role="group" aria-labelledby="subjects-label">
                                <span class="label" id="subjects-label">Subjects you can teach<span class="req">*</span></span>
                                <?= chips_html('subjects', $subjects, (array) old('subjects', []), true) ?>
                                <?= field_error('subjects') ?>
                            </div>
                            <div class="form-group full" role="group" aria-labelledby="classes-label">
                                <span class="label" id="classes-label">Classes / learners you prefer</span>
                                <?= chips_html('classes_preferred', $classes, (array) old('classes_preferred', [])) ?>
                                <?= field_error('classes_preferred') ?>
                            </div>
                            <div class="form-group full" role="group" aria-labelledby="languages-label">
                                <span class="label" id="languages-label">Languages you can teach in<span class="req">*</span></span>
                                <?= chips_html('languages', $languages, (array) old('languages', []), true) ?>
                                <?= field_error('languages') ?>
                            </div>
                            <div class="form-group full" role="radiogroup" aria-labelledby="mode-label">
                                <span class="label" id="mode-label">How would you like to teach?<span class="req">*</span></span>
                                <div class="check-group">
                                    <?php foreach ($modes as $k => $label): ?>
                                        <label class="check-chip"><input type="radio" name="teaching_mode" value="<?= e($k) ?>"<?= $mode === $k ? ' checked' : '' ?> required><span><?= e($label) ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <?= field_error('teaching_mode') ?>
                            </div>
                            <div class="form-group">
                                <label for="availability">Availability<span class="req">*</span></label>
                                <select id="availability" name="availability" required><?= options_html(AVAILABILITY_OPTIONS, (string) old('availability'), false) ?></select>
                                <?= field_error('availability') ?>
                            </div>
                            <?= recruitment_file_field('resume', 'CV / resume (optional)', false, 'PDF only.') ?>
                            <div class="form-group full">
                                <label for="motivation">Why would you like to teach with us?<span class="req">*</span></label>
                                <textarea id="motivation" name="motivation" rows="4" required minlength="30" maxlength="2000" data-counter><?= e(old('motivation')) ?></textarea>
                                <?= field_error('motivation') ?>
                            </div>
                        </div>

                        <div class="callout callout-blue rc-form-callout"><span class="callout-icon"><?= icon('shield') ?></span><div>
                            <strong>Safeguarding comes first</strong>
                            <p>Volunteer teachers work with children. Before anyone is placed we verify identity and references and give a briefing on our <a href="<?= e(page_url('child-protection-policy')) ?>">Child Protection Policy</a>. Teaching happens in group settings or in online sessions arranged by our coordinators.</p>
                        </div></div>

                        <?= recruitment_consent_html($consents) ?>
                        <div class="form-group full terms-accept-row">
                            <label class="checkbox"><input type="checkbox" name="accept_terms" value="1" required data-terms-checkbox<?= old_checked('accept_terms', '1') ?>><span>I have read and accept the <button type="button" class="link-button" data-modal-open="teacher-terms">Volunteer Teacher Terms &amp; Conditions</button>.<span class="req">*</span></span></label>
                            <?= field_error('accept_terms') ?>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-blue btn-lg" type="submit" data-loading="Submitting…"><?= icon('send') ?>Submit application</button>
                        </div>
                        <?= recruitment_account_note($slug) ?>
                    </form>
                    <?= teacher_terms_modal_html() ?>
                </div>
                <?= recruitment_apply_aside([
                    ['title' => 'Apply online', 'text' => 'Subjects, classes, languages and availability.'],
                    ['title' => 'Conversation', 'text' => 'A short discussion, sometimes a demo session.'],
                    ['title' => 'Verification', 'text' => 'ID and reference checks, child protection briefing.'],
                    ['title' => 'Start teaching', 'text' => 'In person or online, with a coordinator.'],
                ]) ?>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
