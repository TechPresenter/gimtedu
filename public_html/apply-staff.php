<?php
require_once __DIR__ . '/functions.php';

$user = current_user();
$positions = array_column(staff_positions(), 'title');
$selected = (string) old('position', $_GET['position'] ?? 'General application');
$pageTitle = 'Job Application';
$pageDescription = 'Apply for a staff position at Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner('Job Application', 'Apply to work with Prerit Welfare Foundation', ['Work With Us' => 'volunteer-staff.php']);
?>
<section class="section">
    <div class="container narrow">
        <div class="form-card">
            <form action="<?= e(url('process-staff.php')) ?>" method="post" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
                <h2 class="form-section-title"><?= icon('briefcase') ?>Position</h2>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="position">Position applying for<span class="req">*</span></label>
                        <select id="position" name="position" required>
                            <?= options_html(array_merge($positions, ['General application']), $selected, false, '') ?>
                        </select>
                        <?= field_error('position') ?>
                    </div>
                </div>

                <h2 class="form-section-title"><?= icon('user') ?>Personal details</h2>
                <div class="form-grid">
                    <?php render_personal_fields($user, ['name', 'email', 'phone', 'city', 'state']); ?>
                </div>

                <h2 class="form-section-title"><?= icon('award') ?>Qualifications &amp; experience</h2>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="qualification">Highest qualification<span class="req">*</span></label>
                        <input id="qualification" type="text" name="qualification" required maxlength="150" value="<?= e(old('qualification')) ?>">
                        <?= field_error('qualification') ?>
                    </div>
                    <div class="form-group">
                        <label for="experience_years">Total experience (years)<span class="req">*</span></label>
                        <input id="experience_years" type="number" name="experience_years" required min="0" max="50" step="0.5" value="<?= e(old('experience_years', '0')) ?>">
                        <?= field_error('experience_years') ?>
                    </div>
                    <div class="form-group">
                        <label for="current_organization">Current / last organisation</label>
                        <input id="current_organization" type="text" name="current_organization" maxlength="150" value="<?= e(old('current_organization')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="notice_period">Notice period</label>
                        <select id="notice_period" name="notice_period"><?= options_html(['Immediate', '15 days', '1 month', '2 months', '3 months'], (string) old('notice_period'), false) ?></select>
                    </div>
                    <div class="form-group full">
                        <label for="resume">CV / resume<span class="req">*</span></label>
                        <input id="resume" type="file" name="resume" required accept=".pdf" data-max-mb="<?= MAX_UPLOAD_MB ?>">
                        <span class="help">PDF only, maximum <?= MAX_UPLOAD_MB ?> MB. Stored privately and seen only by our HR team.</span>
                        <?= field_error('resume') ?>
                    </div>
                    <div class="form-group full">
                        <label for="cover_letter">Cover letter<span class="req">*</span></label>
                        <textarea id="cover_letter" name="cover_letter" required minlength="50" maxlength="4000" data-counter placeholder="Tell us about your experience and why you would like this role."><?= e(old('cover_letter')) ?></textarea>
                        <?= field_error('cover_letter') ?>
                    </div>
                    <div class="form-group full">
                        <label class="checkbox"><input type="checkbox" name="declaration" value="1" required> <span>I confirm that the information provided is true and I consent to background verification.</span></label>
                        <?= field_error('declaration') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…">Submit Application</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
