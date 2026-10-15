<?php
/**
 * Website settings, grouped in tabs: branding, contact, header & footer, social links, NGO registration,
 * donations & bank (+ read-only payment gateway status), email, accounts, recruitment, SEO & analytics, data retention.
 * Secrets (database, SMTP and Razorpay keys) live in the configuration file only and are never shown or stored here.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('settings.manage');

$tabs = site_settings_tabs();
$tab = (string) ($_GET['tab'] ?? ($_POST['tab'] ?? 'general'));
if (!isset($tabs[$tab])) {
    $tab = 'general';
}
[$tabLabel, $tabIcon, $fields] = $tabs[$tab];
$self = 'settings.php?tab=' . $tab;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'test_email') {
        $to = setting('admin_notify_email', setting('contact_email'));
        if (!$to) {
            flash('error', 'Enter a notification email address first.');
            admin_redirect($self);
        }
        $ok = send_mail($to, 'Test email from ' . site_name(), '<p>This is a test email sent from the admin panel (Settings &gt; Email) by ' . e(current_admin()['name']) . ' on ' . e(date('d M Y, h:i A')) . '.</p><p>If you can read this, the website can send emails.</p>');
        admin_log('settings_test_email', 'Test email sent to the notification address (' . ($ok ? 'accepted' : 'failed') . ')');
        if ($ok) {
            flash('success', 'Test email sent to ' . $to . '. Check the inbox (and the spam folder).');
        } elseif (!MAIL_ENABLED) {
            flash('warning', 'Email sending is switched off in the configuration file (MAIL_ENABLED = false), so nothing was sent.');
        } else {
            flash('error', 'The test email could not be sent. Check the SMTP settings in the configuration file and the server error log.');
        }
        admin_redirect($self);
    }

    [$changed, $errors] = site_save_settings($fields, $_POST, 'branding');
    if ($errors) {
        back_with_errors($errors, 'admin/' . $self, $_POST);
    }
    if ($changed) {
        admin_log('settings_updated', 'Settings > ' . $tabLabel . ': ' . implode(', ', $changed));
        flash('success', $tabLabel . ' saved (' . count($changed) . ' change' . (count($changed) === 1 ? '' : 's') . ').');
    } else {
        flash('info', 'Nothing changed.');
    }
    admin_redirect($self);
}

admin_page_start('Settings');

/** Value for the form: the submitted value after a failed save, otherwise the stored setting. */
$val = function (string $key, array $def) {
    $stored = array_key_exists($key, settings_all()) ? (string) settings_all()[$key] : '';
    $v = old($key, null);
    if ($v === null || is_array($v)) {
        return $stored;
    }
    return (string) $v;
};
?>
<div class="site-admin">
<div class="site-settings-layout">
    <nav class="site-settings-nav" aria-label="Settings sections">
        <?php foreach ($tabs as $k => [$label, $ic]): ?>
            <a href="<?= e(admin_url('settings.php?tab=' . $k)) ?>" class="<?= $k === $tab ? 'active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= icon($ic) ?><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <div>
        <?php if ($tab === 'ngo'): ?>
            <div class="alert alert-warning"><?= icon('alert-triangle') ?><div><strong>Enter only verified details - leave blank if not applicable; blank values are never shown.</strong> Copy each number exactly from the official certificate. Wrong registration numbers mislead donors and can have legal consequences.</div></div>
        <?php elseif ($tab === 'donations'):
            $keyId = (string) RAZORPAY_KEY_ID;
            $mode = $keyId === '' || (string) RAZORPAY_KEY_SECRET === '' ? 'not configured' : (strpos($keyId, 'rzp_live_') === 0 ? 'live mode' : (strpos($keyId, 'rzp_test_') === 0 ? 'test mode' : 'unknown key type'));
            $modeBadge = ['not configured' => 'badge-gray', 'live mode' => 'badge-green', 'test mode' => 'badge-gold'][$mode] ?? 'badge-red'; ?>
            <section class="panel site-warning">
                <div class="panel-head"><h2><?= icon('shield') ?>Online payment gateway (Razorpay)</h2><span class="badge <?= $modeBadge ?>"><?= e(ucfirst($mode)) ?></span></div>
                <ul class="site-status-list">
                    <li><span>Status</span><strong><?= e(ucfirst($mode)) ?></strong></li>
                    <li><span>Key ID</span><code><?= $keyId !== '' ? e(substr($keyId, 0, 9) . str_repeat('•', 6)) : 'not set' ?></code></li>
                    <li><span>Webhook secret set</span><strong><?= (string) RAZORPAY_WEBHOOK_SECRET !== '' ? 'Yes' : 'No' ?></strong></li>
                    <li><span>Webhook URL (enter in the Razorpay dashboard)</span><span><code><?= e(abs_url('payment-webhook.php')) ?></code> <button type="button" class="btn btn-ghost btn-sm" data-copy-text="<?= e(abs_url('payment-webhook.php')) ?>"><?= icon('copy') ?><span>Copy</span></button></span></li>
                    <li><span>Webhook events</span><span>payment.captured, payment.authorized, payment.failed, order.paid, refund.processed</span></li>
                </ul>
                <p class="help mt-2 mb-0">The keys are kept in the configuration file (pwf-config.php or config.php) on the server - never in the database or this panel. Use test keys (rzp_test_…) until the foundation's merchant account is approved, then replace them with live keys.<?= $mode === 'test mode' ? ' In test mode no real money is collected and donation pages show a "test mode" notice.' : '' ?></p>
            </section>
        <?php elseif ($tab === 'email'): ?>
            <section class="panel">
                <div class="panel-head"><h2><?= icon('mail') ?>Email delivery</h2><span class="badge <?= MAIL_ENABLED ? 'badge-green' : 'badge-gray' ?>"><?= MAIL_ENABLED ? 'Enabled' : 'Switched off' ?></span></div>
                <ul class="site-status-list">
                    <li><span>Sending method</span><strong><?= SMTP_HOST !== '' ? 'SMTP server' : 'PHP mail() of the hosting server' ?></strong></li>
                    <?php if (SMTP_HOST !== ''): ?>
                        <li><span>SMTP server</span><code><?= e(SMTP_HOST . ':' . SMTP_PORT) ?> (<?= e(SMTP_ENCRYPTION ?: 'no encryption') ?>)</code></li>
                        <li><span>SMTP login</span><strong><?= SMTP_USER !== '' ? 'Set' : 'Not set' ?></strong></li>
                    <?php endif; ?>
                    <li><span>Sender</span><code><?= e(MAIL_FROM_NAME . ' <' . MAIL_FROM . '>') ?></code></li>
                </ul>
                <p class="help mt-2">Mail server settings are kept in the configuration file. Use an address on the foundation's own domain as the sender so emails are not marked as spam.</p>
                <form method="post" action="<?= e(admin_url($self)) ?>">
                    <?= csrf_field() ?><input type="hidden" name="tab" value="email"><input type="hidden" name="action" value="test_email">
                    <button class="btn btn-outline btn-sm" type="submit"><?= icon('send') ?>Send a test email to the notification address</button>
                </form>
            </section>
        <?php elseif ($tab === 'seo'): ?>
            <div class="alert alert-info"><?= icon('info') ?><div>Search engine files: <a href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener">sitemap.xml</a> · <a href="<?= e(url('robots.txt')) ?>" target="_blank" rel="noopener">robots.txt</a>. See the <a href="<?= e(admin_url('seo.php')) ?>">SEO audit</a> for pages that need attention and setup steps for Google Search Console and Analytics.</div></div>
            <?php if (setting('seo_noindex_site') === '1'): ?>
                <div class="alert alert-warning"><?= icon('alert-triangle') ?><div><strong>The whole website is hidden from search engines.</strong> Untick "Hide the whole website" below when the site is ready.</div></div>
            <?php endif; ?>
        <?php elseif ($tab === 'general'): ?>
            <div class="alert alert-info"><?= icon('info') ?><div>The header and footer show the emblem with the organisation name and taglines built in HTML. Upload a horizontal logo only if you have a clean version (about 600 × 200 px, PNG with a transparent or white background); it then replaces the built-in lockup.</div></div>
        <?php endif; ?>

        <form method="post" action="<?= e(admin_url($self)) ?>" enctype="multipart/form-data" class="panel">
            <div class="panel-head"><h2><?= icon($tabIcon) ?><?= e($tabLabel) ?></h2></div>
            <?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><input type="hidden" name="action" value="save">
            <div class="form-grid">
                <?php foreach ($fields as $key => $def): ?>
                    <?= site_setting_input($key, $def, $val($key, $def)) ?>
                    <?php if (($fe = field_error($key)) !== ''): ?><div class="form-group full"><?= $fe ?></div><?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div class="form-actions mt-2"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save <?= e(mb_strtolower($tabLabel)) ?></button></div>
        </form>

        <?php if ($tab === 'contact' && setting('map_embed_url') !== ''): ?>
            <section class="panel"><div class="panel-head"><h2>Map preview</h2></div>
                <iframe src="<?= e(setting('map_embed_url')) ?>" title="Map preview" style="width:100%;height:280px;border:0;border-radius:12px" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></section>
        <?php endif; ?>
    </div>
</div>
</div>
<?= site_page_datalist() ?>
<?= site_media_picker() ?>
<script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
admin_page_end();
