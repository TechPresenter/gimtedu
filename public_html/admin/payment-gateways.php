<?php
/**
 * Payment Gateways (administrators only, permission payments.configure):
 *  - which gateway donors use: Razorpay, Cashfree or both (the donor chooses);
 *  - Razorpay Key ID + encrypted Key Secret and Webhook Secret; Cashfree App ID + encrypted Secret Key + environment;
 *  - "Test connection" (authenticated read-only API call made by the server);
 *  - webhook URLs, events to tick and step-by-step set-up guides.
 * Saving keys requires the administrator's current password. Secrets are never shown again (only "ends with …"),
 * never written to the audit log and never kept in the session. A constant in pwf-config.php always wins.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('payments.configure');

$self = 'payment-gateways.php';

/** Fields that hold secrets: never put back into the form, never kept in the session after an error. */
$secretFields = ['rzp_key_secret', 'rzp_webhook_secret', 'cf_secret_key', 'current_password'];

/** Stop with an error on the given section (secrets are dropped from the remembered input). */
$fail = function (array $errors, string $anchor) use ($secretFields): void {
    back_with_errors($errors, 'admin/payment-gateways.php#' . $anchor, array_diff_key($_POST, array_flip($secretFields)));
};

/** The signed-in administrator confirms the change with their current account password. */
$passwordOk = function (string $anchor) use ($fail): void {
    $uid = (int) admin_id();
    $key = 'user:' . $uid;
    if (too_many_attempts('gateway_password_failed', $key)) {
        admin_log('payment_gateway_locked', 'Too many wrong password confirmations on Payment Gateways');
        $fail([$anchor . '_password' => 'Too many wrong passwords. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again.'], $anchor);
    }
    $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
    if ($hash === '' || !password_verify(post_str('current_password'), $hash)) {
        log_activity('gateway_password_failed', $key, $uid, true);
        $fail([$anchor . '_password' => 'Your password is not correct. Enter the password you use to sign in to the admin panel.'], $anchor);
    }
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = post_str('action');

    // -----------------------------------------------------------------
    // Which gateway donors see
    // -----------------------------------------------------------------
    if ($action === 'choice') {
        $choice = post_str('payment_gateway');
        if (!in_array($choice, ['razorpay', 'cashfree', 'both'], true)) {
            $fail(['payment_gateway' => 'Choose Razorpay, Cashfree or both.'], 'choice');
        }
        $old = gateway_choice();
        save_setting('payment_gateway', $choice);
        save_setting('razorpay_enabled', $choice !== 'cashfree' ? '1' : '0');
        save_setting('cashfree_enabled', $choice !== 'razorpay' ? '1' : '0');
        if ($old !== $choice) {
            admin_log('payment_gateway_choice', 'Donors now pay with: ' . ['razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'both' => 'Razorpay or Cashfree (donor chooses)'][$choice] . ' (was ' . $old . ')');
        }
        $missing = array_values(array_filter($choice === 'both' ? ['razorpay', 'cashfree'] : [$choice], function ($g) { return !gateway_configured($g); }));
        if ($missing) {
            flash('warning', 'Saved. ' . implode(' and ', array_map('gateway_label', $missing)) . (count($missing) > 1 ? ' have' : ' has') . ' no keys yet, so ' . ($choice === 'both' && count($missing) === 1 ? 'donors will only see the other gateway until the keys are entered.' : 'online donations stay unavailable until the keys are entered below.'));
        } else {
            flash('success', 'Saved. ' . ($choice === 'both' ? 'Donors choose between Razorpay and Cashfree (Razorpay is pre-selected).' : 'Donors pay with ' . gateway_label($choice) . '.'));
        }
        admin_redirect($self . '#choice');
    }

    // -----------------------------------------------------------------
    // Razorpay keys
    // -----------------------------------------------------------------
    if ($action === 'save_razorpay') {
        $changes = [];
        $errors = [];
        $keyId = trim(post_str('rzp_key_id'));
        $keyIdFromConfig = gateway_constant('RAZORPAY_KEY_ID') !== '';
        $newSecret = trim(post_str('rzp_key_secret'));
        $newHook = trim(post_str('rzp_webhook_secret'));
        $removeSecret = !empty($_POST['rzp_key_secret_remove']);
        $removeHook = !empty($_POST['rzp_webhook_secret_remove']);
        $keyIdChanged = !$keyIdFromConfig && $keyId !== trim(setting('razorpay_key_id'));
        if ($keyIdChanged && $keyId !== '' && !preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,40}$/', $keyId)) {
            $errors['rzp_key_id'] = 'A Razorpay Key ID looks like rzp_test_… or rzp_live_… (letters and digits only). Copy it again from Razorpay > Account & Settings > API Keys.';
        }
        if ($newSecret !== '' && !preg_match('/^[A-Za-z0-9_\-]{8,100}$/', $newSecret)) {
            $errors['rzp_key_secret'] = 'This does not look like a Razorpay Key Secret (letters and digits, no spaces).';
        }
        if ($newHook !== '' && !preg_match('/^[\x21-\x7E][\x20-\x7E]{4,198}[\x21-\x7E]$/', $newHook)) {
            $errors['rzp_webhook_secret'] = 'Use 6 to 200 characters (letters, digits and symbols) - the same secret you typed in the Razorpay webhook form.';
        }
        if ($errors) {
            $fail($errors, 'razorpay');
        }
        if (!$keyIdChanged && $newSecret === '' && $newHook === '' && !$removeSecret && !$removeHook) {
            flash('info', 'Nothing changed for Razorpay.');
            admin_redirect($self . '#razorpay');
        }
        $passwordOk('razorpay');
        if ($keyIdChanged) {
            save_setting('razorpay_key_id', $keyId);
            $changes[] = $keyId === '' ? 'Key ID removed' : 'Key ID set (' . (strpos($keyId, 'rzp_live_') === 0 ? 'live' : 'test') . ' mode, ends …' . substr($keyId, -4) . ')';
        }
        if ($newSecret !== '') {
            save_secret_setting('razorpay_key_secret', $newSecret);
            $changes[] = 'Key Secret replaced';
        } elseif ($removeSecret) {
            save_secret_setting('razorpay_key_secret', '');
            $changes[] = 'Key Secret removed';
        }
        if ($newHook !== '') {
            save_secret_setting('razorpay_webhook_secret', $newHook);
            $changes[] = 'Webhook Secret replaced';
        } elseif ($removeHook) {
            save_secret_setting('razorpay_webhook_secret', '');
            $changes[] = 'Webhook Secret removed';
        }
        save_setting('razorpay_last_test', '');
        admin_log('payment_gateway_updated', 'Razorpay: ' . implode(', ', $changes));
        $msg = 'Razorpay saved: ' . implode(', ', $changes) . '.';
        if (gateway_constant('RAZORPAY_KEY_SECRET') !== '' && ($newSecret !== '' || $removeSecret)) {
            $msg .= ' Note: pwf-config.php also defines a Key Secret, and that value is the one used.';
        }
        flash('success', $msg . (razorpay_configured() ? ' Now press "Test connection".' : ''));
        admin_redirect($self . '#razorpay');
    }

    // -----------------------------------------------------------------
    // Cashfree keys
    // -----------------------------------------------------------------
    if ($action === 'save_cashfree') {
        $changes = [];
        $errors = [];
        $appId = trim(post_str('cf_app_id'));
        $appIdFromConfig = gateway_constant('CASHFREE_APP_ID') !== '';
        $newSecret = trim(post_str('cf_secret_key'));
        $removeSecret = !empty($_POST['cf_secret_key_remove']);
        $env = post_str('cf_env', cashfree_env());
        if (!in_array($env, ['sandbox', 'production'], true)) {
            $errors['cf_env'] = 'Choose Sandbox (test) or Production.';
        }
        $appIdChanged = !$appIdFromConfig && $appId !== trim(setting('cashfree_app_id'));
        $envChanged = gateway_constant('CASHFREE_ENV') === '' && $env !== setting('cashfree_env', 'sandbox');
        if ($appIdChanged && $appId !== '' && !preg_match('/^[A-Za-z0-9]{5,80}$/', $appId)) {
            $errors['cf_app_id'] = 'A Cashfree App ID contains only letters and digits. Copy it again from Cashfree > Developers > API Keys.';
        }
        if ($newSecret !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $newSecret)) {
            $errors['cf_secret_key'] = 'This does not look like a Cashfree Secret Key (it usually starts with cfsk_ma_).';
        }
        if ($errors) {
            $fail($errors, 'cashfree');
        }
        if (!$appIdChanged && !$envChanged && $newSecret === '' && !$removeSecret) {
            flash('info', 'Nothing changed for Cashfree.');
            admin_redirect($self . '#cashfree');
        }
        $passwordOk('cashfree');
        if ($appIdChanged) {
            save_setting('cashfree_app_id', $appId);
            $changes[] = $appId === '' ? 'App ID removed' : 'App ID set (ends …' . substr($appId, -4) . ')';
        }
        if ($envChanged) {
            save_setting('cashfree_env', $env);
            $changes[] = 'environment: ' . ($env === 'production' ? 'Production (real payments)' : 'Sandbox (test)');
        }
        if ($newSecret !== '') {
            save_secret_setting('cashfree_secret_key', $newSecret);
            $changes[] = 'Secret Key replaced';
        } elseif ($removeSecret) {
            save_secret_setting('cashfree_secret_key', '');
            $changes[] = 'Secret Key removed';
        }
        save_setting('cashfree_last_test', '');
        admin_log('payment_gateway_updated', 'Cashfree: ' . implode(', ', $changes));
        $warn = '';
        $sandboxKey = stripos(cashfree_app_id(), 'TEST') === 0 || strpos(cashfree_secret_key(), '_test_') !== false;
        if (cashfree_env() === 'production' && $sandboxKey) {
            $warn = ' Warning: the environment is Production but the keys look like sandbox (test) keys.';
        } elseif (cashfree_env() === 'sandbox' && cashfree_secret_key() !== '' && strpos(cashfree_secret_key(), '_prod_') !== false) {
            $warn = ' Warning: the environment is Sandbox but the Secret Key looks like a Production key.';
        }
        flash($warn !== '' ? 'warning' : 'success', 'Cashfree saved: ' . implode(', ', $changes) . '.' . $warn . (cashfree_configured() ? ' Now press "Test connection".' : ''));
        admin_redirect($self . '#cashfree');
    }

    // -----------------------------------------------------------------
    // Test connection (server-side, authenticated, read-only)
    // -----------------------------------------------------------------
    if ($action === 'test_razorpay' || $action === 'test_cashfree') {
        $gw = $action === 'test_razorpay' ? 'razorpay' : 'cashfree';
        $result = $gw === 'razorpay' ? razorpay_test_connection() : cashfree_test_connection();
        gateway_save_test($gw, $result);
        admin_log('payment_gateway_tested', gateway_label($gw) . ' connection test: ' . ($result['ok'] ? 'OK' : 'failed') . ' - ' . $result['message']);
        flash($result['ok'] ? 'success' : 'error', gateway_label($gw) . ': ' . $result['message']);
        admin_redirect($self . '#' . $gw);
    }

    flash('error', 'Unknown action.');
    admin_redirect($self);
}

// ---------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------
$choice = (string) old('payment_gateway', gateway_choice());
$active = active_gateways();
$onlineOn = setting('online_donations_enabled', '1') === '1';
$rzpMode = razorpay_mode();
$rzpKeyId = razorpay_key_id();
$rzpKeyIdSource = gateway_value_source('RAZORPAY_KEY_ID', 'razorpay_key_id');
$cfAppIdSource = gateway_value_source('CASHFREE_APP_ID', 'cashfree_app_id');
$cfEnv = cashfree_env();
$rzpAllConfig = gateway_constant('RAZORPAY_KEY_ID') !== '' && gateway_constant('RAZORPAY_KEY_SECRET') !== '' && gateway_constant('RAZORPAY_WEBHOOK_SECRET') !== '';
$cfAllConfig = gateway_constant('CASHFREE_APP_ID') !== '' && gateway_constant('CASHFREE_SECRET_KEY') !== '' && gateway_constant('CASHFREE_ENV') !== '';
$rzpHook = abs_url('payment-webhook.php');
$cfHook = abs_url('cashfree-webhook.php');
$rzpTest = gateway_last_test('razorpay');
$cfTest = gateway_last_test('cashfree');
$events = db_rows("SELECT e.gateway, e.event_type, e.result, e.received_at, e.donation_id, d.donation_ref FROM payment_webhook_events e
                   LEFT JOIN donations d ON d.id = e.donation_id ORDER BY e.received_at DESC, e.id DESC LIMIT 8");
$hookCounts = [];
foreach (db_rows("SELECT gateway, COUNT(*) n, MAX(received_at) last_at FROM payment_webhook_events GROUP BY gateway") as $r) {
    $hookCounts[$r['gateway']] = $r;
}
// CMS texts that name only Razorpay (worth reviewing once Cashfree is offered)
$razorpayTexts = [];
if (in_array('cashfree', $active, true)) {
    $razorpayTexts = db_rows("SELECT DISTINCT p.id, p.title, p.slug FROM page_sections s JOIN pages p ON p.id = s.page_id
                              WHERE s.status = 'visible' AND s.anchor <> 'cashfree-payments' AND (s.body LIKE '%Razorpay%' OR s.items LIKE '%Razorpay%' OR s.intro LIKE '%Razorpay%' OR s.title LIKE '%Razorpay%')
                              AND s.body NOT LIKE '%Cashfree%' AND COALESCE(s.items, '') NOT LIKE '%Cashfree%' ORDER BY p.title LIMIT 12");
}

/** "Comes from" label for a value. */
$sourceLabel = function (string $source, bool $secret = false): string {
    if ($source === 'config') {
        return '<span class="pg-src pg-src-config">' . icon('lock') . 'pwf-config.php</span>';
    }
    if ($source === 'admin') {
        return '<span class="pg-src pg-src-admin">' . icon('shield') . ($secret ? 'Admin panel (encrypted)' : 'Admin panel') . '</span>';
    }
    return '<span class="pg-src pg-src-none">Not set</span>';
};
/** Secret state for the overview table (the source is in its own column). */
$secretState = function (string $constant, string $name): string {
    return str_replace(['Set in pwf-config.php', 'Saved (encrypted)'], 'Saved', secret_hint($constant, $name));
};
$testLine = function (?array $t): string {
    if (!$t) {
        return '<p class="pg-test pg-test-none">' . icon('info') . 'Not tested since the keys were last changed.</p>';
    }
    return '<p class="pg-test ' . ($t['ok'] ? 'pg-test-ok' : 'pg-test-bad') . '">' . icon($t['ok'] ? 'check-circle' : 'alert-circle')
        . '<span><strong>' . ($t['ok'] ? 'Connection OK' : 'Connection failed') . '</strong> · ' . e(format_date($t['at'], 'd M Y, h:i A')) . '<br>' . e($t['message']) . '</span></p>';
};
$secretInput = function (string $name, string $label, string $hint, string $help, string $configConst) {
    $fromConfig = gateway_constant($configConst) !== '';
    $id = 'f_' . $name;
    $html = '<div class="form-group full pg-secret"><label for="' . $id . '">' . e($label) . '</label>'
        . '<p class="pg-hint" id="' . $id . '_hint">' . icon('lock') . e($hint) . '</p>';
    if ($fromConfig) {
        $html .= '<input id="' . $id . '" type="password" disabled value="" aria-describedby="' . $id . '_hint ' . $id . '_help">'
            . '<span class="help" id="' . $id . '_help">Set in pwf-config.php on the server, which always wins. Remove it from that file to manage it here.</span>';
    } else {
        $html .= '<input id="' . $id . '" type="password" name="' . e($name) . '" autocomplete="off" spellcheck="false" autocapitalize="off" value="" aria-describedby="' . $id . '_hint ' . $id . '_help">'
            . '<span class="help" id="' . $id . '_help">' . e($help) . ' Leave blank to keep the saved value.</span>'
            . '<label class="checkbox mt-1"><input type="checkbox" name="' . e($name) . '_remove" value="1"> <span>Remove the saved ' . e(mb_strtolower($label)) . '</span></label>';
    }
    return $html . field_error($name) . '</div>';
};
$passwordField = function (string $gateway) {
    return '<div class="form-group full pg-confirm"><label for="' . $gateway . '_pw">Your admin password<span class="req">*</span></label>'
        . '<input id="' . $gateway . '_pw" type="password" name="current_password" autocomplete="current-password" required aria-describedby="' . $gateway . '_pw_help">'
        . '<span class="help" id="' . $gateway . '_pw_help">Needed to save any change to these keys. This protects the payment settings if someone else gets hold of an open admin session.</span>'
        . field_error($gateway . '_password') . '</div>';
};
$copyRow = function (string $label, string $value, string $id, string $gatewayName) {
    return '<div class="pg-copy"><span class="pg-copy-label" id="' . $id . '">' . e($label) . '</span>'
        . '<code>' . e($value) . '</code>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-copy="' . e($value) . '" aria-label="Copy the ' . e($gatewayName) . ' webhook URL">' . icon('copy') . 'Copy</button></div>';
};

admin_page_start('Payment Gateways', [
    ['label' => 'Donations', 'url' => admin_url('donations.php'), 'icon' => 'rupee', 'style' => 'ghost'],
]);
$choiceLabels = ['razorpay' => 'Razorpay only', 'cashfree' => 'Cashfree only', 'both' => 'Both - the donor chooses'];
?>
<div class="pg-page">

<div class="stat-cards pg-status">
    <div class="stat-card"><span class="icon-circle<?= $active ? '' : ' gold' ?>"><?= icon($active ? 'check-circle' : 'alert-triangle') ?></span>
        <div><strong><?= $active ? e(implode(' + ', array_map('gateway_label', $active))) : 'No online payments' ?></strong><small><?= $active ? 'Shown to donors on the donate page' : ($onlineOn ? 'No chosen gateway has keys yet' : 'Online donations are switched off') ?></small></div></div>
    <div class="stat-card"><span class="icon-circle<?= $rzpMode === 'live' && razorpay_configured() ? '' : ' blue' ?>"><?= icon('key') ?></span>
        <div><strong>Razorpay: <?= !razorpay_configured() ? 'not set up' : ($rzpMode === 'live' ? 'LIVE' : ($rzpMode === 'test' ? 'test mode' : 'check Key ID')) ?></strong><small><?= razorpay_configured() ? ($rzpTest ? ($rzpTest['ok'] ? 'Last test: OK' : 'Last test: failed') : 'Not tested yet') : 'Key ID and Key Secret needed' ?></small></div></div>
    <div class="stat-card"><span class="icon-circle<?= $cfEnv === 'production' && cashfree_configured() ? '' : ' blue' ?>"><?= icon('key') ?></span>
        <div><strong>Cashfree: <?= !cashfree_configured() ? 'not set up' : ($cfEnv === 'production' ? 'PRODUCTION' : 'sandbox') ?></strong><small><?= cashfree_configured() ? ($cfTest ? ($cfTest['ok'] ? 'Last test: OK' : 'Last test: failed') : 'Not tested yet') : 'App ID and Secret Key needed' ?></small></div></div>
    <a class="stat-card" href="<?= e(admin_url('settings.php?tab=donations')) ?>"><span class="icon-circle<?= $onlineOn ? '' : ' gold' ?>"><?= icon('sliders') ?></span>
        <div><strong>Online donations <?= $onlineOn ? 'on' : 'off' ?></strong><small>Switch in Settings &gt; Donations &amp; bank</small></div></a>
</div>

<?php if ($razorpayTexts): ?>
    <div class="alert alert-info"><?= icon('info') ?><div><strong>Some page texts mention only Razorpay.</strong> Donors can now also pay with Cashfree - review these pages so the wording stays accurate:
        <?php foreach ($razorpayTexts as $i => $pg): ?><?= $i ? ', ' : ' ' ?><a href="<?= e(admin_url('page-edit.php?id=' . (int) $pg['id'])) ?>"><?= e($pg['title']) ?></a><?php endforeach; ?>.</div></div>
<?php endif; ?>

<section class="panel" id="choice">
    <div class="panel-head"><h2><?= icon('sliders') ?>Which gateway do donors use?</h2></div>
    <form method="post" action="<?= e(admin_url($self)) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="choice">
        <fieldset class="pg-choice">
            <legend class="sr-only">Payment gateway shown on the donate page</legend>
            <?php foreach ($choiceLabels as $val => $label):
                $ready = $val === 'both' ? (razorpay_configured() && cashfree_configured()) : gateway_configured($val); ?>
                <label class="pg-choice-opt">
                    <input type="radio" name="payment_gateway" value="<?= e($val) ?>"<?= $choice === $val ? ' checked' : '' ?>>
                    <span class="pg-choice-card">
                        <strong><?= e($label) ?></strong>
                        <small><?= $val === 'both' ? 'Donors pick Razorpay or Cashfree on the form (Razorpay is pre-selected).' : 'Every online donation goes through ' . e(gateway_label($val)) . '.' ?></small>
                        <span class="badge <?= $ready ? 'badge-green' : 'badge-gray' ?>"><?= $ready ? 'Keys ready' : 'Keys missing' ?></span>
                    </span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?= field_error('payment_gateway') ?>
        <p class="help">Only gateways with saved keys are shown to donors. If none is ready, the donate page honestly shows the other ways to give (bank transfer / contact) instead of a payment form.</p>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save choice</button></div>
    </form>
</section>

<div class="pg-grid">
<!-- ================================================================ Razorpay -->
<section class="panel pg-gateway" id="razorpay" aria-labelledby="rzp-title">
    <div class="panel-head"><h2 id="rzp-title"><span class="pg-logo pg-logo-razorpay" aria-hidden="true">Razorpay</span><span class="sr-only">Razorpay</span></h2>
        <span class="badge <?= !razorpay_configured() ? 'badge-gray' : ($rzpMode === 'live' ? 'badge-green' : ($rzpMode === 'test' ? 'badge-gold' : 'badge-red')) ?>"><?= !razorpay_configured() ? 'Not configured' : ($rzpMode === 'live' ? 'Live mode' : ($rzpMode === 'test' ? 'Test mode' : 'Unknown key type')) ?></span></div>

    <table class="pg-sources">
        <caption class="sr-only">Where each Razorpay value comes from</caption>
        <tbody>
            <tr><th scope="row">Key ID</th><td><?= $rzpKeyId !== '' ? '<code>' . e($rzpKeyId) . '</code>' : '<span class="muted">-</span>' ?></td><td><?= $sourceLabel($rzpKeyIdSource) ?></td></tr>
            <tr><th scope="row">Key Secret</th><td><?= e($secretState('RAZORPAY_KEY_SECRET', 'razorpay_key_secret')) ?></td><td><?= $sourceLabel(secret_source('RAZORPAY_KEY_SECRET', 'razorpay_key_secret'), true) ?></td></tr>
            <tr><th scope="row">Webhook Secret</th><td><?= e($secretState('RAZORPAY_WEBHOOK_SECRET', 'razorpay_webhook_secret')) ?></td><td><?= $sourceLabel(secret_source('RAZORPAY_WEBHOOK_SECRET', 'razorpay_webhook_secret'), true) ?></td></tr>
        </tbody>
    </table>

    <?= $testLine(razorpay_configured() ? $rzpTest : null) ?>
    <form method="post" action="<?= e(admin_url($self)) ?>" class="pg-test-form"><?= csrf_field() ?><input type="hidden" name="action" value="test_razorpay">
        <button class="btn btn-outline btn-sm" type="submit"<?= razorpay_configured() ? '' : ' disabled' ?>><?= icon('zap') ?>Test connection</button>
        <span class="help">Makes a read-only, authenticated request to Razorpay from this server. Nothing is charged.</span></form>

    <h3 class="pg-sub">Keys</h3>
    <?php if ($rzpAllConfig): ?>
        <p class="note-box small"><?= icon('lock') ?> All Razorpay values are set in <strong>pwf-config.php</strong> on the server, which always wins over the admin panel. To manage the keys here instead, remove RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET and RAZORPAY_WEBHOOK_SECRET from that file (or leave them empty).</p>
    <?php else: ?>
    <form method="post" action="<?= e(admin_url($self)) ?>" autocomplete="off" data-validate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_razorpay">
        <div class="form-grid">
            <div class="form-group full">
                <label for="f_rzp_key_id">Key ID</label>
                <?php if ($rzpKeyIdSource === 'config'): ?>
                    <input id="f_rzp_key_id" type="text" value="<?= e($rzpKeyId) ?>" disabled aria-describedby="f_rzp_key_id_help">
                    <span class="help" id="f_rzp_key_id_help">Set in pwf-config.php on the server, which always wins. Remove it from that file to manage it here.</span>
                <?php else: ?>
                    <input id="f_rzp_key_id" type="text" name="rzp_key_id" maxlength="60" spellcheck="false" autocapitalize="off" placeholder="rzp_live_XXXXXXXXXXXXXX"
                           pattern="rzp_(test|live)_[A-Za-z0-9]{6,40}" title="rzp_test_… or rzp_live_… followed by letters and digits"
                           value="<?= e((string) old('rzp_key_id', setting('razorpay_key_id'))) ?>" aria-describedby="f_rzp_key_id_help">
                    <span class="help" id="f_rzp_key_id_help">Starts with <code>rzp_test_</code> (test mode) or <code>rzp_live_</code> (real payments). The Key ID is not secret - it is used by the checkout in the donor's browser.</span>
                <?php endif; ?>
                <?= field_error('rzp_key_id') ?>
            </div>
            <?= $secretInput('rzp_key_secret', 'Key Secret', secret_hint('RAZORPAY_KEY_SECRET', 'razorpay_key_secret'), 'Shown only once in Razorpay when you generate the key. Stored encrypted; never shown again.', 'RAZORPAY_KEY_SECRET') ?>
            <?= $secretInput('rzp_webhook_secret', 'Webhook Secret', secret_hint('RAZORPAY_WEBHOOK_SECRET', 'razorpay_webhook_secret'), 'The secret you typed when creating the webhook in Razorpay (it confirms that notifications really come from Razorpay).', 'RAZORPAY_WEBHOOK_SECRET') ?>
            <?= $passwordField('razorpay') ?>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('lock') ?>Save Razorpay keys</button></div>
    </form>
    <?php endif; ?>

    <h3 class="pg-sub">Webhook</h3>
    <?= $copyRow('Webhook URL', $rzpHook, 'rzp-hook-label', 'Razorpay') ?>
    <p class="small mb-1">Active events to tick in Razorpay:</p>
    <ul class="pg-events">
        <?php foreach (['payment.authorized' => 'capture a payment even if the donor closes the browser', 'payment.captured' => 'confirm the donation', 'order.paid' => 'confirm the donation', 'payment.failed' => 'record a failed attempt', 'refund.processed' => 'keep refunds and totals correct'] as $ev => $why): ?>
            <li><?= icon('check') ?><span><code><?= e($ev) ?></code> <span class="muted">- <?= e($why) ?></span></span></li>
        <?php endforeach; ?>
    </ul>
    <?php $rc = $hookCounts['razorpay'] ?? null; ?>
    <p class="small muted"><?= $rc ? 'Signed notifications received: ' . (int) $rc['n'] . ' · last ' . e(format_date($rc['last_at'], 'd M Y, h:i A')) : 'No Razorpay notification received yet.' ?></p>

    <details class="pg-guide">
        <summary><?= icon('help-circle') ?>Step-by-step: set up Razorpay</summary>
        <ol>
            <li>Sign in at <strong>dashboard.razorpay.com</strong> with the foundation's Razorpay account.</li>
            <li><strong>Start in Test Mode</strong>: use the Test / Live switch at the top of the dashboard and choose <em>Test Mode</em>.</li>
            <li>Open <strong>Account &amp; Settings &gt; API Keys</strong> (under "Website and app settings") and click <em>Generate Key</em>. Copy the <strong>Key ID</strong> (<code>rzp_test_…</code>) and the <strong>Key Secret</strong>. The secret is shown only once - download the key file if Razorpay offers it.</li>
            <li>Paste both above, enter your admin password and click <em>Save Razorpay keys</em>. Then press <em>Test connection</em>: it should say "Connected".</li>
            <li>Open <strong>Account &amp; Settings &gt; Webhooks</strong> &gt; <em>Add New Webhook</em>. Webhook URL: <code><?= e($rzpHook) ?></code>. Secret: make up a long random secret (a password manager can create one), type it there and paste the same value above as <strong>Webhook Secret</strong>. Tick the events listed above and save.</li>
            <li>Make a small test donation on the website. In test mode Razorpay offers test methods (for example the UPI ID <code>success@razorpay</code>); no real money moves. Check that it appears as <em>Success</em> in Donations with a receipt.</li>
            <li><strong>Going live:</strong> once Razorpay has activated the account, switch the dashboard to <em>Live Mode</em>, generate the live keys (<code>rzp_live_…</code>) and add the webhook again in Live Mode (test and live webhooks are separate). Enter the live Key ID, Key Secret and the live Webhook Secret here, then test the connection.</li>
            <li><strong>Keep the secrets secret:</strong> never send them by chat, email or screenshot. If a Key Secret or Webhook Secret was ever shared that way, <strong>regenerate it</strong> in Razorpay (API Keys &gt; Regenerate Key; edit the webhook for a new secret) and enter the new value here - the old one stops working.</li>
        </ol>
        <p class="small muted">Menu names can change slightly when Razorpay updates its dashboard.</p>
    </details>
</section>

<!-- ================================================================ Cashfree -->
<section class="panel pg-gateway" id="cashfree" aria-labelledby="cf-title">
    <div class="panel-head"><h2 id="cf-title"><span class="pg-logo pg-logo-cashfree" aria-hidden="true">cashfree</span><span class="sr-only">Cashfree Payments</span></h2>
        <span class="badge <?= !cashfree_configured() ? 'badge-gray' : ($cfEnv === 'production' ? 'badge-green' : 'badge-gold') ?>"><?= !cashfree_configured() ? 'Not configured' : ($cfEnv === 'production' ? 'Production' : 'Sandbox (test)') ?></span></div>

    <table class="pg-sources">
        <caption class="sr-only">Where each Cashfree value comes from</caption>
        <tbody>
            <tr><th scope="row">App ID</th><td><?= cashfree_app_id() !== '' ? '<code>' . e(cashfree_app_id()) . '</code>' : '<span class="muted">-</span>' ?></td><td><?= $sourceLabel($cfAppIdSource) ?></td></tr>
            <tr><th scope="row">Secret Key</th><td><?= e($secretState('CASHFREE_SECRET_KEY', 'cashfree_secret_key')) ?></td><td><?= $sourceLabel(secret_source('CASHFREE_SECRET_KEY', 'cashfree_secret_key'), true) ?></td></tr>
            <tr><th scope="row">Environment</th><td><?= $cfEnv === 'production' ? 'Production (real payments)' : 'Sandbox (test, no real money)' ?></td><td><?= $sourceLabel(gateway_constant('CASHFREE_ENV') !== '' ? 'config' : 'admin') ?></td></tr>
            <?php if (gateway_constant('CASHFREE_API_BASE') !== ''): ?>
                <tr><th scope="row">API address</th><td><code><?= e(cashfree_api_base()) ?></code></td><td><?= $sourceLabel('config') ?></td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?= $testLine(cashfree_configured() ? $cfTest : null) ?>
    <form method="post" action="<?= e(admin_url($self)) ?>" class="pg-test-form"><?= csrf_field() ?><input type="hidden" name="action" value="test_cashfree">
        <button class="btn btn-outline btn-sm" type="submit"<?= cashfree_configured() ? '' : ' disabled' ?>><?= icon('zap') ?>Test connection</button>
        <span class="help">Asks Cashfree for an order that does not exist: "not found" means the keys were accepted. Nothing is charged.</span></form>

    <h3 class="pg-sub">Keys</h3>
    <?php if ($cfAllConfig): ?>
        <p class="note-box small"><?= icon('lock') ?> The Cashfree App ID, Secret Key and environment are set in <strong>pwf-config.php</strong> on the server, which always wins over the admin panel.</p>
    <?php else: ?>
    <form method="post" action="<?= e(admin_url($self)) ?>" autocomplete="off" data-validate>
        <?= csrf_field() ?><input type="hidden" name="action" value="save_cashfree">
        <div class="form-grid">
            <div class="form-group full">
                <label for="f_cf_app_id">App ID</label>
                <?php if ($cfAppIdSource === 'config'): ?>
                    <input id="f_cf_app_id" type="text" value="<?= e(cashfree_app_id()) ?>" disabled aria-describedby="f_cf_app_id_help">
                    <span class="help" id="f_cf_app_id_help">Set in pwf-config.php on the server, which always wins.</span>
                <?php else: ?>
                    <input id="f_cf_app_id" type="text" name="cf_app_id" maxlength="80" spellcheck="false" autocapitalize="off" pattern="[A-Za-z0-9]{5,80}" title="Letters and digits only"
                           value="<?= e((string) old('cf_app_id', setting('cashfree_app_id'))) ?>" aria-describedby="f_cf_app_id_help">
                    <span class="help" id="f_cf_app_id_help">Also called Client ID. Sandbox App IDs usually start with <code>TEST</code>.</span>
                <?php endif; ?>
                <?= field_error('cf_app_id') ?>
            </div>
            <?= $secretInput('cf_secret_key', 'Secret Key', secret_hint('CASHFREE_SECRET_KEY', 'cashfree_secret_key'), 'Starts with cfsk_ma_. Stored encrypted; never shown again. Cashfree also uses it to sign webhooks.', 'CASHFREE_SECRET_KEY') ?>
            <fieldset class="form-group full pg-env"<?= gateway_constant('CASHFREE_ENV') !== '' ? ' disabled' : '' ?>>
                <legend>Environment</legend>
                <?php $envVal = (string) old('cf_env', setting('cashfree_env', 'sandbox')); ?>
                <label class="checkbox"><input type="radio" name="cf_env" value="sandbox"<?= $envVal !== 'production' ? ' checked' : '' ?>> <span><strong>Sandbox</strong> - test payments, no real money (use sandbox keys)</span></label>
                <label class="checkbox"><input type="radio" name="cf_env" value="production"<?= $envVal === 'production' ? ' checked' : '' ?>> <span><strong>Production</strong> - real donations (use production keys)</span></label>
                <?= field_error('cf_env') ?>
            </fieldset>
            <?= $passwordField('cashfree') ?>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('lock') ?>Save Cashfree keys</button></div>
    </form>
    <?php endif; ?>

    <h3 class="pg-sub">Webhook</h3>
    <?= $copyRow('Webhook URL', $cfHook, 'cf-hook-label', 'Cashfree') ?>
    <p class="small mb-1">Events to select in Cashfree (Payment Gateway, webhook version 2023-08-01):</p>
    <ul class="pg-events">
        <?php foreach (['PAYMENT_SUCCESS_WEBHOOK' => 'payment success - confirm the donation', 'PAYMENT_FAILED_WEBHOOK' => 'payment failed', 'PAYMENT_USER_DROPPED_WEBHOOK' => 'payment dropped by the donor', 'REFUND_STATUS_WEBHOOK' => 'refund status - keep refunds and totals correct'] as $ev => $why): ?>
            <li><?= icon('check') ?><span><code><?= e($ev) ?></code> <span class="muted">- <?= e($why) ?></span></span></li>
        <?php endforeach; ?>
    </ul>
    <p class="note-box small">Cashfree signs every webhook with your <strong>Secret Key</strong> - there is no separate webhook secret to copy. Notifications older than 15 minutes or already processed are ignored.</p>
    <?php $cc = $hookCounts['cashfree'] ?? null; ?>
    <p class="small muted"><?= $cc ? 'Signed notifications received: ' . (int) $cc['n'] . ' · last ' . e(format_date($cc['last_at'], 'd M Y, h:i A')) : 'No Cashfree notification received yet.' ?></p>

    <details class="pg-guide">
        <summary><?= icon('help-circle') ?>Step-by-step: set up Cashfree</summary>
        <ol>
            <li>Sign in at <strong>merchant.cashfree.com</strong> and open <strong>Payment Gateway</strong>.</li>
            <li><strong>Start in Sandbox (test)</strong>: switch the dashboard to the test environment (toggle at the top).</li>
            <li>Open <strong>Developers &gt; API Keys</strong> and generate or view the keys. Copy the <strong>App ID</strong> and the <strong>Secret Key</strong>.</li>
            <li>Paste them above, keep <em>Environment: Sandbox</em>, enter your admin password and click <em>Save Cashfree keys</em>. Press <em>Test connection</em>: it should say "Connected".</li>
            <li>Open <strong>Developers &gt; Webhooks</strong> &gt; <em>Add Webhook Endpoint</em>. URL: <code><?= e($cfHook) ?></code>, choose the Payment Gateway product, webhook version <strong>2023-08-01</strong> and the events listed above.</li>
            <li>Make a small test donation choosing Cashfree (the form asks for a 10-digit Indian mobile number, which Cashfree requires). Check that it appears as <em>Success</em> in Donations with a receipt.</li>
            <li><strong>Going live:</strong> after Cashfree activates the account, switch the dashboard to <em>Production</em>, generate the production keys, add the website domain under <strong>Developers &gt; Whitelisting</strong> if Cashfree asks for it, and add the webhook again in Production. Then select <em>Environment: Production</em> here, enter the production App ID and Secret Key and test the connection.</li>
            <li><strong>Keep the secret secret:</strong> never send the Secret Key by chat, email or screenshot. If it was ever shared that way, <strong>regenerate it</strong> in Cashfree (Developers &gt; API Keys) and enter the new one here - the old one stops working.</li>
        </ol>
        <p class="small muted">Menu names can change slightly when Cashfree updates its dashboard.</p>
    </details>
</section>
</div>

<section class="panel" id="activity">
    <div class="panel-head"><h2><?= icon('activity') ?>Recent gateway notifications</h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('donations.php')) ?>">Donations</a></div>
    <?php if ($events): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Received</th><th>Gateway</th><th>Event</th><th>Donation</th><th>Result</th></tr></thead>
            <tbody><?php foreach ($events as $ev): ?>
                <tr><td class="nowrap"><?= e(format_date($ev['received_at'], 'd M Y, h:i A')) ?></td><td><?= e(gateway_label($ev['gateway'])) ?></td><td><code><?= e($ev['event_type']) ?></code></td>
                    <td><?= $ev['donation_id'] && admin_can('donations.view') ? '<a href="' . e(admin_url('donation-view.php?id=' . (int) $ev['donation_id'])) . '">' . e($ev['donation_ref']) . '</a>' : '<span class="muted">-</span>' ?></td>
                    <td class="small"><?= e($ev['result'] ?: 'Received') ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php else: ?>
        <p class="muted small mb-0">No signed notification has been received from Razorpay or Cashfree yet. They appear here once a webhook is set up and a payment is made.</p>
    <?php endif; ?>
</section>
</div>
<?php
admin_page_end();
