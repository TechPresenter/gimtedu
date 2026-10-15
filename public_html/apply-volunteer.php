<?php
require_once __DIR__ . '/functions.php';

$type = (old('application_type', $_GET['type'] ?? 'volunteer') === 'intern') ? 'intern' : 'volunteer';
$user = current_user();
$pageTitle = $type === 'intern' ? 'Internship Application' : 'Volunteer Application';
$pageDescription = 'Apply to volunteer or intern with Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner($pageTitle, 'Tell us about yourself and how you would like to help', ['Volunteer' => 'volunteer.php']);
?>
<section class="section">
    <div class="container narrow">
        <div class="form-card">
            <form action="<?= e(url('process-volunteer.php')) ?>" method="post" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <h2 class="form-section-title"><?= icon('user') ?>Application type</h2>
                <div class="check-group">
                    <label class="check-chip"><input type="radio" name="application_type" value="volunteer"<?= $type === 'volunteer' ? ' checked' : '' ?>><span>Volunteer</span></label>
                    <label class="check-chip"><input type="radio" name="application_type" value="intern"<?= $type === 'intern' ? ' checked' : '' ?>><span>Intern</span></label>
                </div>

                <h2 class="form-section-title"><?= icon('user') ?>Personal details</h2>
                <div class="form-grid">
                    <?php render_personal_fields($user, ['name', 'email', 'phone', 'city', 'state']); ?>
                    <div class="form-group">
                        <label for="occupation">Occupation</label>
                        <input id="occupation" type="text" name="occupation" maxlength="120" placeholder="e.g. Student, Engineer, Homemaker" value="<?= e(old('occupation')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="education">Highest education</label>
                        <input id="education" type="text" name="education" maxlength="150" placeholder="e.g. B.Com (pursuing)" value="<?= e(old('education')) ?>">
                    </div>
                </div>

                <h2 class="form-section-title"><?= icon('heart') ?>How would you like to help?</h2>
                <div class="form-grid">
                    <div class="form-group full">
                        <span class="label">Areas of interest<span class="req">*</span></span>
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
                        <input id="skills" type="text" name="skills" maxlength="500" placeholder="e.g. Teaching, design, first aid, Excel" value="<?= e(old('skills')) ?>">
                    </div>
                    <div class="form-group full">
                        <label for="experience">Previous volunteering experience</label>
                        <textarea id="experience" name="experience" maxlength="2000" rows="3"><?= e(old('experience')) ?></textarea>
                    </div>
                    <div class="form-group full">
                        <label for="motivation">Why do you want to join us?<span class="req">*</span></label>
                        <textarea id="motivation" name="motivation" required minlength="20" maxlength="2000" data-counter><?= e(old('motivation')) ?></textarea>
                        <?= field_error('motivation') ?>
                    </div>
                    <div class="form-group full">
                        <label class="checkbox"><input type="checkbox" name="accept_policy" value="1" required> <span>I have read and agree to follow the <a href="<?= e(url('volunteer-policy.php')) ?>" target="_blank">Volunteer Policy</a> and <a href="<?= e(url('child-protection-policy.php')) ?>" target="_blank">Child Protection Policy</a>.</span></label>
                        <?= field_error('accept_policy') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…">Submit Application</button>
                </div>
                <p class="form-note"><?= icon('info') ?>You will receive an application number by email. <?= $user ? 'This application will also appear in your account under My Applications.' : '<a href="' . e(url('login.php?next=apply-volunteer.php')) . '">Log in</a> first to track it from your account.' ?></p>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
