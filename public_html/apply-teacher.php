<?php
require_once __DIR__ . '/functions.php';

$user = current_user();
$subjects = ['Hindi', 'English', 'Mathematics', 'Science', 'Social Studies', 'Computer Basics', 'Spoken English', 'Arts & Crafts', 'Life Skills', 'Adult Literacy'];
$pageTitle = 'Volunteer Teacher Application';
$pageDescription = 'Apply to become a volunteer teacher with Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner('Volunteer Teacher Application', 'Share your knowledge with children who need it most', ['Volunteer Teacher' => 'volunteer-teacher.php']);
?>
<section class="section">
    <div class="container narrow">
        <div class="form-card">
            <form action="<?= e(url('process-teacher.php')) ?>" method="post" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
                <h2 class="form-section-title"><?= icon('user') ?>Personal details</h2>
                <div class="form-grid">
                    <?php render_personal_fields($user, ['name', 'email', 'phone', 'city', 'state']); ?>
                </div>

                <h2 class="form-section-title"><?= icon('graduation') ?>Teaching details</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="qualification">Highest qualification<span class="req">*</span></label>
                        <input id="qualification" type="text" name="qualification" required maxlength="150" placeholder="e.g. B.Sc., B.Ed." value="<?= e(old('qualification')) ?>">
                        <?= field_error('qualification') ?>
                    </div>
                    <div class="form-group">
                        <label for="teaching_experience">Teaching experience</label>
                        <select id="teaching_experience" name="teaching_experience"><?= options_html(['None', 'Less than 1 year', '1-3 years', '3-5 years', 'More than 5 years'], (string) old('teaching_experience'), false) ?></select>
                    </div>
                    <div class="form-group full">
                        <span class="label">Subjects you can teach<span class="req">*</span></span>
                        <?= chips_html('subjects', $subjects, (array) old('subjects', []), true) ?>
                        <?= field_error('subjects') ?>
                    </div>
                    <div class="form-group">
                        <label for="classes_preferred">Preferred level</label>
                        <select id="classes_preferred" name="classes_preferred"><?= options_html(['Primary (Class 1-5)', 'Middle (Class 6-8)', 'Secondary (Class 9-10)', 'Senior Secondary (Class 11-12)', 'Adult learners', 'Any'], (string) old('classes_preferred'), false) ?></select>
                    </div>
                    <div class="form-group">
                        <label for="languages">Languages you can teach in</label>
                        <input id="languages" type="text" name="languages" maxlength="150" placeholder="e.g. Hindi, English" value="<?= e(old('languages')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="teaching_mode">Mode<span class="req">*</span></label>
                        <select id="teaching_mode" name="teaching_mode" required><?= options_html(['in_person' => 'In person', 'online' => 'Online', 'both' => 'Both'], (string) old('teaching_mode', 'in_person'), true, '') ?></select>
                    </div>
                    <div class="form-group">
                        <label for="availability">Availability<span class="req">*</span></label>
                        <select id="availability" name="availability" required><?= options_html(AVAILABILITY_OPTIONS, (string) old('availability'), false) ?></select>
                        <?= field_error('availability') ?>
                    </div>
                    <div class="form-group full">
                        <label for="resume">CV / resume (optional)</label>
                        <input id="resume" type="file" name="resume" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= MAX_UPLOAD_MB ?>">
                        <span class="help">PDF, JPG or PNG, maximum <?= MAX_UPLOAD_MB ?> MB. Stored privately.</span>
                        <?= field_error('resume') ?>
                    </div>
                    <div class="form-group full">
                        <label for="motivation">Why do you want to teach with us?<span class="req">*</span></label>
                        <textarea id="motivation" name="motivation" required minlength="20" maxlength="2000" data-counter><?= e(old('motivation')) ?></textarea>
                        <?= field_error('motivation') ?>
                    </div>
                    <div class="form-group full">
                        <label class="checkbox"><input type="checkbox" name="accept_policy" value="1" required> <span>I agree to follow the <a href="<?= e(url('child-protection-policy.php')) ?>" target="_blank">Child Protection Policy</a> and consent to verification of my details.</span></label>
                        <?= field_error('accept_policy') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-blue btn-lg" type="submit" data-loading="Submitting…">Submit Application</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
