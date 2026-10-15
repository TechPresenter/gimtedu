<?php
require_once __DIR__ . '/functions.php';

$page = require_page('grievance');
$user = current_user();
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Policies' => null]);
?>
<section class="section">
    <div class="container split">
        <div class="prose"><?= $page['content'] ?></div>
        <div class="form-card">
            <h2>Submit a grievance</h2>
            <form action="<?= e(url('process-contact.php')) ?>" method="post" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="form_type" value="grievance">
                <input type="hidden" name="return_to" value="grievance.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Full name<span class="req">*</span></label>
                        <input id="name" type="text" name="name" required maxlength="120" autocomplete="name" value="<?= e(old('name', $user['name'] ?? '')) ?>">
                        <?= field_error('name') ?>
                    </div>
                    <div class="form-group">
                        <label for="email">Email<span class="req">*</span></label>
                        <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old('email', $user['email'] ?? '')) ?>">
                        <?= field_error('email') ?>
                    </div>
                    <div class="form-group">
                        <label for="phone">Mobile number</label>
                        <input id="phone" type="tel" name="phone" maxlength="15" autocomplete="tel" value="<?= e(old('phone', $user['phone'] ?? '')) ?>">
                        <?= field_error('phone') ?>
                    </div>
                    <div class="form-group">
                        <label for="subject">Nature of grievance<span class="req">*</span></label>
                        <select id="subject" name="subject" required>
                            <option value="">Select</option>
                            <?php foreach (['Donation or receipt', 'Volunteer / staff conduct', 'Child safety concern', 'Programme or service', 'Data privacy', 'Other'] as $opt): ?>
                                <option<?= old_selected('subject', $opt) ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= field_error('subject') ?>
                    </div>
                    <div class="form-group full">
                        <label for="message">Describe your grievance<span class="req">*</span></label>
                        <textarea id="message" name="message" required minlength="20" maxlength="3000" data-counter placeholder="Please include dates, reference numbers and any other details that will help us."><?= e(old('message')) ?></textarea>
                        <?= field_error('message') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary btn-lg" type="submit" data-loading="Submitting…">Submit Grievance</button>
                </div>
                <p class="form-note"><?= icon('shield') ?>Grievances are seen only by authorised members of the foundation.</p>
            </form>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
