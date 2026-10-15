<?php
/**
 * Volunteer application (/become-a-volunteer/): page sections + application form (module position).
 * Saves to volunteer_applications, reference PWF-VOL-YYYY-NNNNN, history, acknowledgement and admin emails.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$consents = ['privacy', 'child', 'conduct', 'adult'];
$required = ['name', 'email', 'phone', 'city', 'state'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    recruitment_guard_post($slug, 'volunteer', 5);
    [$consentRules, $consentLabels] = recruitment_consent_rules($consents);
    [$data, $errors] = validate($_POST, personal_rules($required) + [
        'occupation'   => 'max:120',
        'education'    => 'max:150',
        'interests'    => 'required|array|max:6|in:' . implode(',', VOLUNTEER_INTERESTS),
        'availability' => 'required|in:' . implode(',', AVAILABILITY_OPTIONS),
        'skills'       => 'max:500',
        'experience'   => 'text|max:2000',
        'motivation'   => 'required|text|min:30|max:2000',
    ] + $consentRules, $consentLabels + ['interests' => 'Areas of interest', 'motivation' => 'Why you want to volunteer']);
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
    }
    $id = db_insert('volunteer_applications', [
        'user_id'       => current_user_id(),
        'name'          => $data['name'],
        'email'         => $data['email'],
        'phone'         => $data['phone'],
        'date_of_birth' => $data['date_of_birth'] ?: null,
        'gender'        => $data['gender'] ?: null,
        'address'       => $data['address'] ?: null,
        'city'          => $data['city'],
        'state'         => $data['state'] ?: null,
        'pincode'       => $data['pincode'] ?: null,
        'occupation'    => $data['occupation'] ?: null,
        'education'     => $data['education'] ?: null,
        'interests'     => mb_substr(implode(', ', $data['interests']), 0, 255),
        'availability'  => $data['availability'],
        'skills'        => $data['skills'] ?: null,
        'experience'    => $data['experience'] ?: null,
        'motivation'    => $data['motivation'],
        'ip_address'    => client_ip(),
    ]);
    recruitment_finish_submission([
        'kind' => 'volunteer', 'id' => $id, 'prefix' => 'VOL', 'table' => 'volunteer_applications', 'form' => 'volunteer',
        'name' => $data['name'], 'email' => $data['email'], 'label' => 'Volunteer application', 'conversion' => 'volunteer_application',
        'slug' => $slug,
        'admin_html' => '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ', ' . e($data['phone']) . ', ' . e($data['city']) . ') applied to volunteer.</p>'
            . '<p>Interests: ' . e(implode(', ', $data['interests'])) . '<br>Availability: ' . e($data['availability']) . '</p>',
        'next' => [
            ['title' => 'Review', 'text' => 'Our volunteer coordinator reads your application and matches it with current activities.'],
            ['title' => 'Conversation', 'text' => 'We call or email you to talk about your interests, location and availability.'],
            ['title' => 'Orientation', 'text' => 'A short orientation on our programmes, the code of conduct and child safeguarding.'],
            ['title' => 'Start volunteering', 'text' => 'You join an activity in the field or online with a coordinator to guide you.'],
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
render_sections($page, function () use ($user, $slug, $consents, $required) {
    ?>
    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?= ui_heading('Volunteer application', 'Apply now', 'It takes about five minutes. Fields marked * are required.', true) ?>
            <div class="rc-apply-layout">
                <div class="form-card rc-form-card">
                    <?= recruitment_form_open($slug) ?>
                        <h3 class="form-section-title"><?= icon('user') ?>Your details</h3>
                        <div class="form-grid">
                            <?php render_personal_fields($user, $required); ?>
                            <div class="form-group">
                                <label for="occupation">Occupation</label>
                                <input id="occupation" type="text" name="occupation" maxlength="120" placeholder="e.g. Student, engineer, homemaker" value="<?= e(old('occupation')) ?>">
                                <?= field_error('occupation') ?>
                            </div>
                            <div class="form-group">
                                <label for="education">Highest education</label>
                                <input id="education" type="text" name="education" maxlength="150" placeholder="e.g. B.Com (pursuing)" value="<?= e(old('education')) ?>">
                                <?= field_error('education') ?>
                            </div>
                        </div>

                        <h3 class="form-section-title"><?= icon('heart') ?>How you would like to help</h3>
                        <div class="form-grid">
                            <div class="form-group full" role="group" aria-labelledby="interests-label">
                                <span class="label" id="interests-label">Areas of interest (up to 6)<span class="req">*</span></span>
                                <?= chips_html('interests', VOLUNTEER_INTERESTS, (array) old('interests', []), true) ?>
                                <?= field_error('interests') ?>
                            </div>
                            <div class="form-group">
                                <label for="availability">Availability<span class="req">*</span></label>
                                <select id="availability" name="availability" required><?= options_html(AVAILABILITY_OPTIONS, (string) old('availability'), false) ?></select>
                                <?= field_error('availability') ?>
                            </div>
                            <div class="form-group">
                                <label for="skills">Skills</label>
                                <input id="skills" type="text" name="skills" maxlength="500" placeholder="e.g. Teaching, first aid, design, Excel" value="<?= e(old('skills')) ?>">
                                <?= field_error('skills') ?>
                            </div>
                            <div class="form-group full">
                                <label for="experience">Previous volunteering or community experience</label>
                                <textarea id="experience" name="experience" rows="3" maxlength="2000" data-counter><?= e(old('experience')) ?></textarea>
                                <?= field_error('experience') ?>
                            </div>
                            <div class="form-group full">
                                <label for="motivation">Why would you like to volunteer with us?<span class="req">*</span></label>
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
                    ['title' => 'Apply online', 'text' => 'Tell us about your interests and availability.'],
                    ['title' => 'Talk to us', 'text' => 'A short call with our volunteer coordinator.'],
                    ['title' => 'Orientation', 'text' => 'Code of conduct and child safeguarding briefing.'],
                    ['title' => 'Get started', 'text' => 'Join an activity near you or online.'],
                ]) ?>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
