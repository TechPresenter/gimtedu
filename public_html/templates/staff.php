<?php
/**
 * Staff / job application (/volunteer-staff/, "Job Apply"): open positions (Admin > Settings: staff_positions)
 * + application form. Resume (PDF, required) is stored privately in uploads/documents/staff.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$slug = $page['slug'];
$consents = ['privacy', 'child', 'declaration'];
$required = ['name', 'email', 'phone', 'city', 'state'];
$positions = staff_positions();
$positionTitles = array_column($positions, 'title');
$general = 'General application';
$allowedPositions = array_merge($positionTitles, [$general]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    recruitment_guard_post($slug, 'staff', 5);
    [$consentRules, $consentLabels] = recruitment_consent_rules($consents);
    $position = clean_string($_POST['position'] ?? '');
    [$data, $errors] = validate($_POST, personal_rules($required) + [
        'qualification'        => 'required|min:2|max:150',
        'experience_years'     => 'required|numeric|min:0|max:50',
        'current_organization' => 'max:150',
        'notice_period'        => 'in:' . implode(',', recruitment_options('notice_period')),
        'cover_letter'         => 'required|text|min:50|max:4000',
    ] + $consentRules, $consentLabels + ['experience_years' => 'Years of experience', 'cover_letter' => 'Cover letter']);
    if (!in_array($position, $allowedPositions, true)) {
        $errors['position'] = 'Please choose a position or "General application".';
    }
    $resume = recruitment_upload('resume', 'pdf', 'documents/staff', true, $errors);
    if ($errors) {
        back_with_errors($errors, page_url($slug) . '#apply', $_POST);
    }
    try {
        $id = db_insert('staff_applications', [
            'user_id'              => current_user_id(),
            'position'             => mb_substr($position, 0, 120),
            'name'                 => $data['name'],
            'email'                => $data['email'],
            'phone'                => $data['phone'],
            'date_of_birth'        => $data['date_of_birth'] ?: null,
            'gender'               => $data['gender'] ?: null,
            'address'              => $data['address'] ?: null,
            'city'                 => $data['city'],
            'state'                => $data['state'] ?: null,
            'pincode'              => $data['pincode'] ?: null,
            'qualification'        => $data['qualification'],
            'experience_years'     => round((float) $data['experience_years'], 1),
            'current_organization' => $data['current_organization'] ?: null,
            'notice_period'        => $data['notice_period'] ?: null,
            'resume_path'          => $resume,
            'cover_letter'         => $data['cover_letter'],
            'ip_address'           => client_ip(),
        ]);
    } catch (Throwable $e) {
        delete_upload($resume);
        throw $e;
    }
    recruitment_finish_submission([
        'kind' => 'staff', 'id' => $id, 'prefix' => 'STF', 'table' => 'staff_applications', 'form' => 'staff',
        'name' => $data['name'], 'email' => $data['email'], 'label' => 'Staff application', 'conversion' => 'staff_application',
        'slug' => $slug, 'admin_subject' => 'New staff application (' . $position . ')',
        'admin_html' => '<p><strong>' . e($data['name']) . '</strong> (' . e($data['email']) . ', ' . e($data['phone']) . ', ' . e($data['city']) . ') applied for <strong>' . e($position) . '</strong>.</p>'
            . '<p>Qualification: ' . e($data['qualification']) . '<br>Experience: ' . e((string) round((float) $data['experience_years'], 1)) . ' years</p>',
        'next' => [
            ['title' => 'Screening', 'text' => 'Our team reviews your resume and cover letter against the role.'],
            ['title' => 'Interview', 'text' => 'Shortlisted applicants are invited for a conversation in person, by phone or by video call.'],
            ['title' => 'Decision', 'text' => 'We let you know the outcome by email. You can also check the status online.'],
            ['title' => 'Onboarding', 'text' => 'Selected applicants receive an orientation and a child protection briefing.'],
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
$preselect = (string) old('position', (string) ($_GET['position'] ?? ''));
if (!in_array($preselect, $allowedPositions, true)) {
    $preselect = '';
}
render_sections($page, function () use ($user, $slug, $consents, $required, $positions, $allowedPositions, $preselect, $general) {
    ?>
    <section class="section rc-positions-section" id="open-positions">
        <div class="container">
            <?= ui_heading('Open positions', 'Current openings', $positions ? 'Choose a role below to apply, or send a general application.' : '', true) ?>
            <?php if ($positions): ?>
                <div class="rc-positions">
                    <?php foreach ($positions as $i => $pos): ?>
                        <article class="position-card rc-position reveal" style="--d:<?= $i % 4 ?>">
                            <div>
                                <h3><?= e($pos['title']) ?></h3>
                                <?php if ($pos['description'] !== ''): ?><p class="muted small mb-0"><?= e($pos['description']) ?></p><?php endif; ?>
                            </div>
                            <a class="btn btn-primary btn-sm" href="<?= e(page_url($slug, ['position' => $pos['title']])) ?>#apply"><?= icon('briefcase') ?>Apply for this role</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="rc-empty-positions">
                    <?= empty_state('There are no open positions right now - you can still send your profile. We will keep it on file and contact you when a suitable role opens.', 'Send a general application', '#apply') ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section section-tint rc-apply-section" id="apply">
        <div class="container">
            <?= ui_heading('Job application', 'Apply now', 'Fields marked * are required. Please keep your resume ready as a PDF.', true) ?>
            <div class="rc-apply-layout">
                <div class="form-card rc-form-card">
                    <div class="callout callout-gold rc-form-callout"><span class="callout-icon"><?= icon('alert-triangle') ?></span><div>
                        <strong>We do not charge any fee to apply.</strong>
                        <p>If anyone asks you for money for a job or internship in our name, please <a href="<?= e(page_url('report-fraud')) ?>">report it</a> to us.</p>
                    </div></div>
                    <?= recruitment_form_open($slug, true) ?>
                        <h3 class="form-section-title"><?= icon('briefcase') ?>Position</h3>
                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="position">Position you are applying for<span class="req">*</span></label>
                                <select id="position" name="position" required>
                                    <option value="">Select</option>
                                    <?php foreach ($allowedPositions as $title): ?>
                                        <option value="<?= e($title) ?>"<?= $preselect === $title ? ' selected' : '' ?>><?= e($title === $general ? $general . ' (send my profile)' : $title) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?= field_error('position') ?>
                            </div>
                        </div>

                        <h3 class="form-section-title"><?= icon('user') ?>Your details</h3>
                        <div class="form-grid">
                            <?php render_personal_fields($user, $required); ?>
                        </div>

                        <h3 class="form-section-title"><?= icon('graduation') ?>Education &amp; experience</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="qualification">Highest qualification<span class="req">*</span></label>
                                <input id="qualification" type="text" name="qualification" required maxlength="150" placeholder="e.g. MSW, B.A., Diploma" value="<?= e(old('qualification')) ?>">
                                <?= field_error('qualification') ?>
                            </div>
                            <div class="form-group">
                                <label for="experience_years">Total work experience (years)<span class="req">*</span></label>
                                <input id="experience_years" type="number" name="experience_years" required min="0" max="50" step="0.5" inputmode="decimal" value="<?= e(old('experience_years')) ?>">
                                <?= field_error('experience_years') ?>
                            </div>
                            <div class="form-group">
                                <label for="current_organization">Current / last organisation</label>
                                <input id="current_organization" type="text" name="current_organization" maxlength="150" value="<?= e(old('current_organization')) ?>">
                                <?= field_error('current_organization') ?>
                            </div>
                            <div class="form-group">
                                <label for="notice_period">Notice period</label>
                                <select id="notice_period" name="notice_period"><?= options_html(recruitment_options('notice_period'), (string) old('notice_period'), false) ?></select>
                                <?= field_error('notice_period') ?>
                            </div>
                            <?= recruitment_file_field('resume', 'Resume / CV', true, 'PDF only.') ?>
                            <div class="form-group full">
                                <label for="cover_letter">Cover letter<span class="req">*</span></label>
                                <textarea id="cover_letter" name="cover_letter" rows="6" required minlength="50" maxlength="4000" data-counter placeholder="Tell us about your experience, why this role interests you and what you would bring to the team."><?= e(old('cover_letter')) ?></textarea>
                                <?= field_error('cover_letter') ?>
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
                    ['title' => 'Apply online', 'text' => 'Resume, cover letter and basic details.'],
                    ['title' => 'Screening', 'text' => 'We review every application received.'],
                    ['title' => 'Interview', 'text' => 'In person, by phone or video call.'],
                    ['title' => 'Decision & onboarding', 'text' => 'Outcome by email and on the status page.'],
                ]) ?>
            </div>
        </div>
    </section>
    <?php
});
page_footer();
