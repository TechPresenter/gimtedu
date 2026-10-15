<?php
require_once __DIR__ . '/functions.php';

$page = require_page('scholarship');
$user = current_user();
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Get Involved' => null]);
$year = (int) date('n') >= 4 ? date('Y') . '-' . date('y', strtotime('+1 year')) : (date('Y') - 1) . '-' . date('y');
?>
<section class="section">
    <div class="container">
        <div class="split">
            <div class="prose"><?= $page['content'] ?></div>
            <div class="form-card">
                <h2>Scholarship application</h2>
                <form action="<?= e(url('process-scholarship.php')) ?>" method="post" enctype="multipart/form-data" data-validate>
                    <?= csrf_field() ?>
                    <?= honeypot_field() ?>
                    <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
                    <h3 class="form-section-title"><?= icon('user') ?>Student details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="student_name">Student name<span class="req">*</span></label>
                            <input id="student_name" type="text" name="student_name" required maxlength="120" value="<?= e(old('student_name', $user['name'] ?? '')) ?>">
                            <?= field_error('student_name') ?>
                        </div>
                        <div class="form-group">
                            <label for="guardian_name">Parent / guardian name<span class="req">*</span></label>
                            <input id="guardian_name" type="text" name="guardian_name" required maxlength="120" value="<?= e(old('guardian_name')) ?>">
                            <?= field_error('guardian_name') ?>
                        </div>
                        <?php render_personal_fields($user, ['email', 'phone', 'address', 'city', 'state', 'date_of_birth'], ['name']); ?>
                    </div>
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
                            <input id="academic_year" type="text" name="academic_year" required maxlength="20" value="<?= e(old('academic_year', $year)) ?>">
                            <?= field_error('academic_year') ?>
                        </div>
                        <div class="form-group">
                            <label for="last_exam_percentage">Last exam result (%)</label>
                            <input id="last_exam_percentage" type="number" name="last_exam_percentage" min="0" max="100" step="0.01" value="<?= e(old('last_exam_percentage')) ?>">
                            <?= field_error('last_exam_percentage') ?>
                        </div>
                        <div class="form-group">
                            <label for="annual_family_income">Annual family income (₹)<span class="req">*</span></label>
                            <input id="annual_family_income" type="number" name="annual_family_income" required min="0" max="100000000" step="1" value="<?= e(old('annual_family_income')) ?>">
                            <?= field_error('annual_family_income') ?>
                        </div>
                        <div class="form-group full">
                            <label for="reason">Why do you need this scholarship?<span class="req">*</span></label>
                            <textarea id="reason" name="reason" required minlength="30" maxlength="3000" data-counter><?= e(old('reason')) ?></textarea>
                            <?= field_error('reason') ?>
                        </div>
                        <div class="form-group full">
                            <label for="document">Marksheet / income proof<span class="req">*</span></label>
                            <input id="document" type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= MAX_UPLOAD_MB ?>">
                            <span class="help">One PDF (combine documents if needed), JPG or PNG up to <?= MAX_UPLOAD_MB ?> MB. Stored privately.</span>
                            <?= field_error('document') ?>
                        </div>
                        <div class="form-group full">
                            <label class="checkbox"><input type="checkbox" name="declaration" value="1" required> <span>I declare that the information provided is true and I consent to its verification.</span></label>
                            <?= field_error('declaration') ?>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…">Submit Application</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
