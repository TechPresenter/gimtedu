<?php
require_once __DIR__ . '/functions.php';

$user = current_user();
$categories = HELP_CATEGORIES;
$pageTitle = 'Request Help';
$pageDescription = 'Request support from Prerit Welfare Foundation for education, health, livelihood, food or disaster relief.';
require __DIR__ . '/header.php';

page_banner('Request Help', 'Tell us how we can support you or someone you know', ['Beneficiary Support' => 'beneficiary.php']);
?>
<section class="section">
    <div class="container narrow">
        <div class="notice mb-3"><?= icon('shield') ?><div>Your information is confidential and is used only to assess your request. Submitting a request does not guarantee assistance. You may also apply on behalf of a family member or neighbour with their consent.</div></div>
        <div class="form-card">
            <form action="<?= e(url('process-beneficiary.php')) ?>" method="post" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_MB * 1024 * 1024 ?>">
                <h2 class="form-section-title"><?= icon('user') ?>Details of the person who needs help</h2>
                <div class="form-grid">
                    <?php render_personal_fields($user, ['name', 'phone', 'address', 'city', 'state'], ['date_of_birth']); ?>
                    <div class="form-group">
                        <label for="age">Age</label>
                        <input id="age" type="number" name="age" min="0" max="120" value="<?= e(old('age')) ?>">
                        <?= field_error('age') ?>
                    </div>
                    <div class="form-group">
                        <label for="family_members">Members in family</label>
                        <input id="family_members" type="number" name="family_members" min="1" max="30" value="<?= e(old('family_members')) ?>">
                    </div>
                    <div class="form-group">
                        <label for="annual_income">Approx. annual family income (₹)</label>
                        <input id="annual_income" type="number" name="annual_income" min="0" max="100000000" step="1" value="<?= e(old('annual_income')) ?>">
                        <?= field_error('annual_income') ?>
                    </div>
                </div>

                <h2 class="form-section-title"><?= icon('heart') ?>Support needed</h2>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="help_category">Type of support<span class="req">*</span></label>
                        <select id="help_category" name="help_category" required><?= options_html($categories, (string) old('help_category')) ?></select>
                        <?= field_error('help_category') ?>
                    </div>
                    <div class="form-group full">
                        <label for="description">Describe the situation and the help needed<span class="req">*</span></label>
                        <textarea id="description" name="description" required minlength="30" maxlength="3000" data-counter><?= e(old('description')) ?></textarea>
                        <?= field_error('description') ?>
                    </div>
                    <div class="form-group full">
                        <label for="document">Supporting document (optional)</label>
                        <input id="document" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" data-max-mb="<?= MAX_UPLOAD_MB ?>">
                        <span class="help">For example a medical report or income certificate. PDF, JPG or PNG up to <?= MAX_UPLOAD_MB ?> MB. Do not upload Aadhaar or bank card images.</span>
                        <?= field_error('document') ?>
                    </div>
                    <div class="form-group full">
                        <label class="checkbox"><input type="checkbox" name="consent" value="1" required> <span>I confirm the information is true and I consent to Prerit Welfare Foundation contacting me and verifying these details.</span></label>
                        <?= field_error('consent') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
