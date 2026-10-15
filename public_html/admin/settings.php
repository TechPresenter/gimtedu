<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('settings');

$s = function (string $key) {
    return e((string) old($key, setting($key)));
};
$field = function (string $key, string $label, string $help = '', string $type = 'text', int $max = 255) use ($s): void {
    echo '<div class="form-group"><label for="' . $key . '">' . e($label) . '</label>'
        . '<input id="' . $key . '" type="' . $type . '" name="' . $key . '" maxlength="' . $max . '" value="' . $s($key) . '">'
        . ($help ? '<span class="help">' . e($help) . '</span>' : '') . field_error($key) . '</div>';
};
$adminTitle = 'Settings';
require __DIR__ . '/includes/header.php';
?>
<form method="post" action="actions/content-save.php" enctype="multipart/form-data" data-validate>
    <?= csrf_field() ?>
    <input type="hidden" name="type" value="settings">
    <div class="admin-grid-2">
        <div class="panel">
            <div class="panel-head"><h3>Organisation</h3></div>
            <div class="form-grid">
                <?php $field('site_name', 'Website name', '', 'text', 120); ?>
                <?php $field('legal_name', 'Legal name (on receipts)', '', 'text', 150); ?>
                <?php $field('site_tagline', 'Tagline (Hindi)', '', 'text', 120); ?>
                <?php $field('site_tagline_en', 'Tagline (English)', '', 'text', 160); ?>
                <div class="form-group full"><label for="site_description">Short description (SEO)</label><textarea id="site_description" name="site_description" maxlength="300" rows="3" style="min-height:80px"><?= $s('site_description') ?></textarea></div>
                <div class="form-group full"><label for="logo">Logo</label>
                    <div style="display:flex;gap:12px;align-items:center"><img src="<?= e(site_logo()) ?>" alt="" width="60" height="60"><input id="logo" type="file" name="logo" accept=".png,.jpg,.jpeg,.webp"></div>
                    <?php if (setting('logo_path')): ?><label class="checkbox mt-1"><input type="checkbox" name="remove_logo" value="1"> <span>Use the default logo</span></label><?php endif; ?>
                    <?= field_error('logo') ?></div>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Contact details</h3></div>
            <div class="form-grid">
                <?php $field('contact_email', 'Public email', '', 'email', 190); ?>
                <?php $field('admin_notify_email', 'Notification email', 'New submissions are emailed here', 'email', 190); ?>
                <?php $field('contact_phone', 'Phone (leave blank to hide)', '', 'text', 20); ?>
                <?php $field('whatsapp_number', 'WhatsApp number', 'Shows the floating WhatsApp button', 'text', 20); ?>
                <div class="form-group full"><label for="contact_address">Address</label><input id="contact_address" type="text" name="contact_address" maxlength="255" value="<?= $s('contact_address') ?>"></div>
                <?php $field('office_hours', 'Office hours', 'e.g. Mon–Sat, 10 am – 6 pm', 'text', 120); ?>
                <?php $field('map_embed_url', 'Google Maps embed link', 'Optional. From Google Maps > Share > Embed a map (src link only)', 'url', 1000); ?>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Registration &amp; tax details</h3></div>
            <p class="small muted">Enter only verified details from your registration certificates. They appear on the website and on donation receipts. The 80G statement is printed on receipts only when an 80G number is entered.</p>
            <div class="form-grid">
                <?php $field('registration_type', 'Registered as', 'e.g. Trust, Society, Section 8 Company', 'text', 80); ?>
                <?php $field('registration_number', 'Registration number', '', 'text', 80); ?>
                <?php $field('registration_date', 'Registration date', '', 'text', 40); ?>
                <?php $field('pan_number', 'PAN of the foundation', '', 'text', 10); ?>
                <?php $field('reg_12a_number', '12A registration no.', '', 'text', 80); ?>
                <?php $field('reg_80g_number', '80G registration no.', '', 'text', 80); ?>
                <?php $field('csr1_number', 'CSR-1 registration no.', '', 'text', 40); ?>
                <?php $field('darpan_id', 'NITI Aayog Darpan ID', '', 'text', 40); ?>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Bank details (for direct transfers)</h3></div>
            <div class="form-grid">
                <?php $field('bank_account_name', 'Account name', '', 'text', 150); ?>
                <?php $field('bank_name', 'Bank name', '', 'text', 120); ?>
                <?php $field('bank_account_number', 'Account number', '', 'text', 30); ?>
                <?php $field('bank_ifsc', 'IFSC', '', 'text', 11); ?>
                <?php $field('bank_branch', 'Branch', '', 'text', 120); ?>
                <?php $field('upi_id', 'UPI ID', '', 'text', 80); ?>
            </div>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Donations &amp; receipts</h3></div>
            <div class="form-grid">
                <?php $field('donation_presets', 'Suggested amounts (₹)', 'Comma separated, e.g. 500,1000,2500,5000', 'text', 60); ?>
                <?php $field('donation_min_amount', 'Minimum donation (₹)', '', 'number', 7); ?>
                <?php $field('receipt_signatory', 'Receipt signatory', 'e.g. Treasurer', 'text', 120); ?>
                <div class="form-group full"><label for="receipt_footer_note">Receipt footer note</label><input id="receipt_footer_note" type="text" name="receipt_footer_note" maxlength="300" value="<?= $s('receipt_footer_note') ?>"></div>
            </div>
            <div class="notice mt-2"><?= icon('lock') ?><div>Payment gateway keys (Razorpay key ID, secret and webhook secret) are stored in <code>config.php</code> — never in the database. Current mode: <strong><?= razorpay_configured() ? (payment_test_mode() ? 'TEST' : 'LIVE') : 'Not configured' ?></strong>.</div></div>
        </div>
        <div class="panel">
            <div class="panel-head"><h3>Social media</h3></div>
            <div class="form-grid">
                <?php $field('social_facebook', 'Facebook URL', '', 'url'); ?>
                <?php $field('social_youtube', 'YouTube URL', '', 'url'); ?>
                <?php $field('social_twitter', 'X (Twitter) URL', '', 'url'); ?>
                <?php $field('social_linkedin', 'LinkedIn URL', '', 'url'); ?>
                <?php $field('social_instagram', 'Instagram URL', '', 'url'); ?>
            </div>
        </div>
        <div class="panel" id="positions">
            <div class="panel-head"><h3>Open staff positions</h3></div>
            <div class="form-group"><label for="staff_positions">One position per line: <code>Title | short description</code></label>
                <textarea id="staff_positions" name="staff_positions" maxlength="5000" rows="5" placeholder="Field Coordinator | Coordinate education activities in New Delhi"><?= $s('staff_positions') ?></textarea>
                <span class="help">Shown on the Work With Us page and in the job application form. Leave empty if there are no openings.</span></div>
        </div>
    </div>
    <button class="btn btn-primary btn-lg" type="submit">Save settings</button>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
