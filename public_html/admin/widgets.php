<?php
/**
 * Widgets & alerts: floating buttons, motion effects, announcement bar and the fraud / impersonation alert.
 * The fraud alert may only be switched on after a verified incident or genuine risk (mandatory confirmation).
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('widgets.manage');

$groups = site_widget_groups();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('widgets.php');
    $group = (string) ($_POST['group'] ?? '');
    if (!isset($groups[$group])) {
        admin_redirect('widgets.php');
    }
    [$label, $fields] = $groups[$group];
    $back = 'admin/widgets.php#' . $group;
    $errors = [];

    if ($group === 'announcement') {
        $enabled = ($_POST['announcement_enabled'] ?? '0') === '1';
        if ($enabled && trim(clean_string($_POST['announcement_text'] ?? '')) === '') {
            $errors['announcement_text'] = 'Enter the announcement text, or switch the announcement off.';
        }
        $hasLabel = trim(clean_string($_POST['announcement_link_label'] ?? '')) !== '';
        $hasUrl = trim(clean_string($_POST['announcement_link_url'] ?? '')) !== '';
        if ($hasLabel !== $hasUrl) {
            $errors['announcement_link_url'] = 'Enter both the link text and the link, or leave both empty.';
        }
        $s = trim((string) ($_POST['announcement_starts'] ?? ''));
        $en = trim((string) ($_POST['announcement_ends'] ?? ''));
        if ($s !== '' && $en !== '' && str_replace('T', ' ', $s) >= str_replace('T', ' ', $en)) {
            $errors['announcement_ends'] = '"Show until" must be after "Show from".';
        }
    }
    if ($group === 'fraud') {
        $enabled = ($_POST['fraud_alert_enabled'] ?? '0') === '1';
        if ($enabled && empty($_POST['fraud_confirm'])) {
            $errors['fraud_confirm'] = 'To switch the fraud alert on, confirm that it is based on a verified incident or a genuine risk to the public.';
        }
        if ($enabled && trim(clean_string($_POST['fraud_alert_message'] ?? '', true)) === '') {
            $errors['fraud_alert_message'] = 'Enter the alert message.';
        }
    }
    if ($errors) {
        back_with_errors($errors, $back, $_POST);
    }
    [$changed, $errors] = site_save_settings($fields, $_POST);
    if ($errors) {
        back_with_errors($errors, $back, $_POST);
    }

    if ($group === 'announcement') {
        save_setting('announcement_style', 'bar');
        save_setting('announcement_version', (string) ((int) setting('announcement_version', '1') + 1));
        admin_log('announcement_updated', 'Announcement ' . (setting('announcement_enabled') === '1' ? 'ON' : 'off') . ', version ' . setting('announcement_version') . ($changed ? ' (' . implode(', ', $changed) . ')' : ''));
        flash('success', 'Announcement saved' . (setting('announcement_enabled') === '1' ? ' and shown on the website' . (setting('announcement_starts') || setting('announcement_ends') ? ' during the chosen dates' : '') : ' (switched off)') . '. Visitors who closed an earlier announcement will see this one.');
    } elseif ($group === 'fraud') {
        $was = setting('fraud_alert_enabled') === '1';
        $on = ($_POST['fraud_alert_enabled'] ?? '0') === '1';
        save_setting('fraud_alert_enabled', $on ? '1' : '0');
        if ($on && trim(setting('fraud_alert_verified_note')) === '') {
            save_setting('fraud_alert_verified_note', 'Verified and published by ' . current_admin()['name'] . ' on ' . date('d M Y, h:i A') . '.');
        }
        save_setting('fraud_alert_version', (string) ((int) setting('fraud_alert_version', '1') + 1));
        admin_log('fraud_alert_updated', 'Fraud alert ' . ($on ? 'ON' : 'off') . ($on && !$was ? ' (switched on; verified incident confirmed by ' . current_admin()['name'] . ' at ' . date('Y-m-d H:i') . ')' : '') . ', version ' . setting('fraud_alert_version') . ($changed ? '; changed: ' . implode(', ', $changed) : ''));
        flash('success', $on ? 'Fraud alert saved and shown to visitors (' . (setting('fraud_alert_show') === 'once_per_version' ? 'once until it changes' : 'once per visit') . ').' : 'Fraud alert saved (switched off).');
    } else {
        if ($changed) {
            admin_log('widgets_updated', $label . ': ' . implode(', ', $changed));
        }
        flash($changed ? 'success' : 'info', $changed ? $label . ' saved.' : 'Nothing changed.');
    }
    admin_redirect('widgets.php#' . $group);
}

$val = function (string $key) {
    $v = old($key, null);
    return ($v === null || is_array($v)) ? setting($key) : (string) $v;
};
$faOn = (string) old('fraud_alert_enabled', setting('fraud_alert_enabled')) === '1';
$annOn = (string) old('announcement_enabled', setting('announcement_enabled')) === '1';
$hasWa = strlen(preg_replace('/\D+/', '', setting('whatsapp_number'))) >= 10;

admin_page_start('Widgets & Alerts');
?>
<div class="site-admin">
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle"><?= icon('message') ?></span><div><strong><?= setting('widget_help_enabled', '1') === '1' ? 'On' : 'Off' ?></strong><small>Help assistant</small></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('megaphone') ?></span><div><strong><?= announcement() ? 'Showing' : 'Off' ?></strong><small>Announcement bar</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('alert-triangle') ?></span><div><strong><?= fraud_alert_enabled() ? 'On' : 'Off' ?></strong><small>Fraud alert</small></div></div>
</div>

<!-- Floating buttons -->
<form method="post" action="<?= e(admin_url('widgets.php')) ?>" class="panel" id="floating">
    <div class="panel-head"><h2><?= icon('layers') ?>Floating buttons &amp; effects</h2></div>
    <?= csrf_field() ?><input type="hidden" name="group" value="floating">
    <?php if (!$hasWa): ?><p class="note-box site-help"><?= icon('info') ?> The WhatsApp button appears only after a WhatsApp number is entered in <a href="<?= e(admin_url('settings.php?tab=contact')) ?>">Settings &gt; Contact</a>.</p><?php endif; ?>
    <div class="form-grid mt-2">
        <?php foreach ($groups['floating'][1] as $key => $def): ?>
            <?= site_setting_input($key, $def, $val($key)) ?><?= field_error($key) ? '<div class="form-group full">' . field_error($key) . '</div>' : '' ?>
        <?php endforeach; ?>
    </div>
    <div class="form-actions mt-2"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save floating buttons</button></div>
</form>

<!-- Announcement -->
<form method="post" action="<?= e(admin_url('widgets.php')) ?>" class="panel" id="announcement">
    <div class="panel-head"><h2><?= icon('megaphone') ?>Announcement bar</h2><span class="badge <?= announcement() ? 'badge-green' : 'badge-gray' ?>"><?= announcement() ? 'Showing now' : 'Not showing' ?></span></div>
    <?= csrf_field() ?><input type="hidden" name="group" value="announcement">
    <p class="help mt-0">A slim bar above the header for real, current news (an upcoming event, a closure, an important update). Visitors can close it; after you save a change it shows again. Never use it for false urgency.</p>
    <div class="form-grid">
        <div class="form-group full site-check"><input type="hidden" name="announcement_enabled" value="0"><label class="checkbox" for="set-announcement_enabled"><input type="checkbox" id="set-announcement_enabled" name="announcement_enabled" value="1"<?= $annOn ? ' checked' : '' ?>><span>Show the announcement bar</span></label></div>
        <?php foreach ($groups['announcement'][1] as $key => $def): if ($key === 'announcement_enabled') { continue; } ?>
            <?= site_setting_input($key, $def, $val($key)) ?><?= field_error($key) ? '<div class="form-group full">' . field_error($key) . '</div>' : '' ?>
        <?php endforeach; ?>
    </div>
    <p class="help">Version <?= e(setting('announcement_version', '1')) ?> · dates use Indian Standard Time.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save announcement</button></div>
</form>

<!-- Fraud alert -->
<form method="post" action="<?= e(admin_url('widgets.php')) ?>" class="panel" id="fraud">
    <div class="panel-head"><h2><?= icon('alert-triangle') ?>Fraud / impersonation alert</h2><span class="badge <?= fraud_alert_enabled() ? 'badge-red' : 'badge-gray' ?>"><?= fraud_alert_enabled() ? 'On - shown to visitors' : 'Off' ?></span></div>
    <?= csrf_field() ?><input type="hidden" name="group" value="fraud">
    <div class="alert alert-warning"><?= icon('shield') ?><div>Switch this on <strong>only</strong> when the foundation has a verified incident (for example people collecting money in its name) or a genuine risk to the public. It opens as a dialog for every visitor and adds a "Fraud alert" button to the top bar. Switch it off when the risk has passed.</div></div>
    <div class="form-grid">
        <div class="form-group full site-check"><input type="hidden" name="fraud_alert_enabled" value="0"><label class="checkbox" for="set-fraud_alert_enabled"><input type="checkbox" id="set-fraud_alert_enabled" name="fraud_alert_enabled" value="1"<?= $faOn ? ' checked' : '' ?>><span>Show the fraud alert on the website</span></label></div>
        <div class="form-group full site-confirm"><label class="checkbox" for="fraud_confirm"><input type="checkbox" id="fraud_confirm" name="fraud_confirm" value="1"<?= old('fraud_confirm', '') === '1' ? ' checked' : '' ?>><span><strong>I confirm this alert is based on a verified incident or genuine risk to the public.</strong></span></label>
            <span class="help">Required every time you save the alert while it is switched on. Your name and the time are recorded in the audit log<?= setting('fraud_alert_verified_note') === '' ? ' and in the verification note' : '' ?>.</span><?= field_error('fraud_confirm') ?></div>
        <?php foreach ($groups['fraud'][1] as $key => $def): ?>
            <?= site_setting_input($key, $def, $val($key)) ?><?= field_error($key) ? '<div class="form-group full">' . field_error($key) . '</div>' : '' ?>
        <?php endforeach; ?>
    </div>
    <p class="help">Version <?= e(setting('fraud_alert_version', '1')) ?>. Saving creates a new version, so visitors see the updated alert again.</p>
    <div class="form-actions site-form-actions">
        <button class="btn btn-primary" type="submit"><?= icon('check') ?>Save fraud alert</button>
        <button class="btn btn-outline" type="button" data-fraud-preview="fraud-alert-preview" hidden><?= icon('eye') ?>Preview</button>
    </div>
</form>

<section class="panel">
    <div class="panel-head"><h2><?= icon('lock') ?>Security notice after sign-in</h2><span class="badge badge-blue">Automatic</span></div>
    <p class="mb-0">This notice is not configured here. It appears automatically, once, after a member or staff member signs in - and only when real unsuccessful sign-in attempts were recorded on that account since their last sign-in. It offers to change the password or report a problem. No message is ever shown without a real recorded event.</p>
</section>

<!-- Preview: same markup as the public fraud alert dialog (filled from the form above) -->
<dialog class="site-modal modal modal-alert" id="fraud-alert-preview" aria-labelledby="fraud-alert-preview-title">
    <div class="modal-card"><div class="modal-head modal-head-alert"><h2 id="fraud-alert-preview-title"><?= icon('alert-triangle') ?>Fraud Alert <span class="site-preview-tag">Preview</span></h2>
        <button type="button" class="modal-close" data-modal-close aria-label="Close preview"><?= icon('x') ?></button></div>
        <div class="modal-body"><div class="fraud-body">
            <div class="fraud-lang"><h3 data-fa="title"><?= e(setting('fraud_alert_title', 'Beware of fraud')) ?></h3><p data-fa="message"><?= e(setting('fraud_alert_message')) ?></p>
                <ul class="fraud-points" data-fa="points"></ul>
                <template data-fa-point><li><?= icon('shield') ?><span></span></li></template></div>
            <div class="fraud-lang hindi" lang="hi" data-fa-block="hi"><h3 data-fa="title_hi"><?= e(setting('fraud_alert_title_hi')) ?></h3><p data-fa="message_hi"><?= e(setting('fraud_alert_message_hi')) ?></p></div>
            <div class="fraud-report"><strong><?= icon('flag') ?>How to report</strong><p data-fa="report"><?= e(setting('fraud_alert_report_text')) ?></p>
                <div class="btn-row"><span class="btn btn-primary btn-sm"><?= icon('alert-triangle') ?>Report to us</span>
                    <?php if (setting('contact_email')): ?><span class="btn btn-outline btn-sm"><?= icon('mail') ?><?= e(setting('contact_email')) ?></span><?php endif; ?>
                    <span class="btn btn-ghost btn-sm"><?= icon('external') ?>cybercrime.gov.in · 1930</span></div></div>
            <p class="fraud-note muted small" data-fa-block="note"><span data-fa="note"><?= e(setting('fraud_alert_verified_note')) ?></span></p>
        </div></div></div>
</dialog>
</div>
<?= site_page_datalist() ?>
<script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
admin_page_end();
