<?php
require_once __DIR__ . '/functions.php';

$type = ($_GET['type'] ?? old('form_type')) === 'feedback' ? 'feedback' : 'contact';
$user = current_user();
$phone = setting('contact_phone');
$mapUrl = setting('map_embed_url');
$pageTitle = $type === 'feedback' ? 'Feedback' : 'Contact Us';
$pageDescription = 'Contact Prerit Welfare Foundation, New Delhi. Send us a message, feedback or enquiry.';
require __DIR__ . '/header.php';

page_banner($pageTitle, 'We would love to hear from you');
?>
<section class="section">
    <div class="container split">
        <div class="info-panel">
            <h2>Get in touch</h2>
            <p class="muted">Have a question about our work, donations, volunteering or partnerships? Send us a message and our team will respond as soon as possible.</p>
            <div class="info-item">
                <span class="icon-circle"><?= icon('map-pin') ?></span>
                <div><h3>Address</h3><p><?= e(setting('contact_address')) ?></p></div>
            </div>
            <div class="info-item">
                <span class="icon-circle gold"><?= icon('mail') ?></span>
                <div><h3>Email</h3><p><a href="mailto:<?= e(setting('contact_email')) ?>"><?= e(setting('contact_email')) ?></a></p></div>
            </div>
            <?php if ($phone): ?>
                <div class="info-item">
                    <span class="icon-circle blue"><?= icon('phone') ?></span>
                    <div><h3>Phone</h3><p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></p></div>
                </div>
            <?php endif; ?>
            <?php if (setting('office_hours')): ?>
                <div class="info-item">
                    <span class="icon-circle"><?= icon('clock') ?></span>
                    <div><h3>Office hours</h3><p><?= e(setting('office_hours')) ?></p></div>
                </div>
            <?php endif; ?>
            <div class="notice notice-gold"><?= icon('shield') ?><div>Have a complaint? Use our <a href="<?= e(url('grievance.php')) ?>">grievance redressal form</a>.</div></div>
        </div>

        <div class="form-card">
            <h2><?= $type === 'feedback' ? 'Share your feedback' : 'Send us a message' ?></h2>
            <form action="<?= e(url('process-contact.php')) ?>" method="post" data-validate>
                <?= csrf_field() ?>
                <?= honeypot_field() ?>
                <input type="hidden" name="return_to" value="contact.php">
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="form_type">I want to</label>
                        <select id="form_type" name="form_type">
                            <option value="contact"<?= $type === 'contact' ? ' selected' : '' ?>>Send an enquiry</option>
                            <option value="feedback"<?= $type === 'feedback' ? ' selected' : '' ?>>Give feedback</option>
                        </select>
                    </div>
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
                        <label for="subject">Subject<span class="req">*</span></label>
                        <input id="subject" type="text" name="subject" required maxlength="200" value="<?= e(old('subject')) ?>">
                        <?= field_error('subject') ?>
                    </div>
                    <div class="form-group full">
                        <label for="message">Message<span class="req">*</span></label>
                        <textarea id="message" name="message" required minlength="10" maxlength="3000" data-counter><?= e(old('message')) ?></textarea>
                        <?= field_error('message') ?>
                    </div>
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary btn-lg" type="submit" data-loading="Sending…"><?= icon('send') ?>Send Message</button>
                </div>
                <p class="form-note"><?= icon('lock') ?>Your details are used only to respond to you. See our <a href="<?= e(url('privacy-policy.php')) ?>">privacy policy</a>.</p>
            </form>
        </div>
    </div>
    <?php if ($mapUrl && preg_match('#^https://(www\.)?google\.com/maps/embed#', $mapUrl)): ?>
        <div class="container mt-4">
            <iframe class="map-embed" src="<?= e($mapUrl) ?>" title="Map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/footer.php'; ?>
