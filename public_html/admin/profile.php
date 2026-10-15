<?php
/** Staff profile: name/mobile, password change (signs out other devices) and two-step login (TOTP). */
require_once __DIR__ . '/includes/auth.php';

$me = db_row('SELECT * FROM users WHERE id = ?', [admin_id()]);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('profile.php');
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'profile') {
        [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:120', 'mobile' => 'phone']);
        $mobile = $data['mobile'] !== '' ? normalize_mobile($data['mobile']) : null;
        if ($mobile && db_value('SELECT COUNT(*) FROM users WHERE mobile = ? AND id <> ?', [$mobile, $me['id']])) {
            $errors['mobile'] = 'This mobile number is already used by another account.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/profile.php', $_POST);
        }
        db_update('users', ['name' => $data['name'], 'mobile' => $mobile], 'id = :id', ['id' => $me['id']]);
        admin_log('profile_updated', 'Updated own profile');
        flash('success', 'Profile updated.');
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        if (!password_verify($current, $me['password_hash'])) {
            back_with_errors(['current_password' => 'Your current password is not correct.'], 'admin/profile.php');
        }
        if ($p = password_problem($new, $me['name'], $me['email'])) {
            back_with_errors(['password' => $p], 'admin/profile.php');
        }
        if (mb_strlen($new) < 12) {
            back_with_errors(['password' => 'Staff passwords must be at least 12 characters long.'], 'admin/profile.php');
        }
        if ($new !== (string) ($_POST['password_confirm'] ?? '')) {
            back_with_errors(['password_confirm' => 'The passwords do not match.'], 'admin/profile.php');
        }
        set_user_password((int) $me['id'], $new);
        admin_log('password_changed', 'Changed own password');
        flash('success', 'Password changed. You have been signed out on other devices.');
    } elseif ($action === 'totp_start') {
        $_SESSION['totp_pending'] = totp_generate_secret();
        admin_redirect('profile.php#two-step');
    } elseif ($action === 'totp_enable') {
        $secret = (string) ($_SESSION['totp_pending'] ?? '');
        if ($secret !== '' && totp_verify($secret, (string) ($_POST['code'] ?? ''))) {
            db_update('users', ['totp_secret' => $secret, 'totp_enabled' => 1], 'id = :id', ['id' => $me['id']]);
            unset($_SESSION['totp_pending']);
            $_SESSION['totp_ok'] = true;
            admin_log('totp_enabled', 'Two-step login enabled');
            add_notification((int) $me['id'], 'Two-step login enabled', 'Two-step login was turned on for your account.', null, 'security');
            flash('success', 'Two-step login is now on. You will need your authenticator app each time you sign in to the admin panel.');
        } else {
            flash('error', 'That code did not match. Check the time on your phone and try again.');
        }
        admin_redirect('profile.php#two-step');
    } elseif ($action === 'totp_disable') {
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $me['password_hash'])) {
            flash('error', 'Enter your current password to turn off two-step login.');
        } else {
            db_update('users', ['totp_secret' => null, 'totp_enabled' => 0], 'id = :id', ['id' => $me['id']]);
            admin_log('totp_disabled', 'Two-step login disabled');
            add_notification((int) $me['id'], 'Two-step login turned off', 'Two-step login was turned off for your account. If this was not you, contact an administrator.', null, 'security');
            flash('warning', 'Two-step login has been turned off.');
        }
        admin_redirect('profile.php#two-step');
    }
    admin_redirect('profile.php');
}

$pending = (string) ($_SESSION['totp_pending'] ?? '');
$otpauth = $pending !== '' ? 'otpauth://totp/' . rawurlencode(site_name() . ':' . $me['email']) . '?secret=' . $pending . '&issuer=' . rawurlencode(site_name()) : '';
admin_page_start('My profile');
?>
<div class="admin-grid-2">
    <section class="panel">
        <div class="panel-head"><h2>Profile</h2></div>
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="profile">
            <div class="form-group full"><label for="name">Name</label><input id="name" name="name" required maxlength="120" value="<?= e(old('name', $me['name'])) ?>"><?= field_error('name') ?></div>
            <div class="form-group"><label>Email</label><input value="<?= e($me['email']) ?>" disabled><span class="help">Ask an administrator to change your sign-in email.</span></div>
            <div class="form-group"><label for="mobile">Mobile</label><input id="mobile" name="mobile" maxlength="15" value="<?= e(old('mobile', (string) $me['mobile'])) ?>"><?= field_error('mobile') ?></div>
            <div class="form-group full"><label>Roles</label><p><?= e(current_admin()['role_name']) ?></p></div>
            <div class="form-actions full"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save profile</button></div>
        </form>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Change password</h2></div>
        <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="action" value="password">
            <div class="form-group full"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" required autocomplete="current-password"><?= field_error('current_password') ?></div>
            <div class="form-group"><label for="password">New password</label><input id="password" type="password" name="password" required minlength="12" autocomplete="new-password"><?= field_error('password') ?><span class="help">12+ characters with letters and numbers.</span></div>
            <div class="form-group"><label for="password_confirm">Confirm new password</label><input id="password_confirm" type="password" name="password_confirm" required minlength="12" autocomplete="new-password"><?= field_error('password_confirm') ?></div>
            <div class="form-actions full"><button class="btn btn-primary" type="submit"><?= icon('key') ?>Change password</button></div>
        </form>
    </section>
</div>

<section class="panel mt-3" id="two-step">
    <div class="panel-head"><h2>Two-step login</h2><?= status_badge($me['totp_enabled'] ? 'active' : 'inactive') ?></div>
    <?php if ($me['totp_enabled']): ?>
        <p>Two-step login is <strong>on</strong>. Each admin sign-in needs the 6-digit code from your authenticator app.</p>
        <form method="post" class="filter-row" data-confirm="Turn off two-step login?">
            <?= csrf_field() ?><input type="hidden" name="action" value="totp_disable">
            <input type="password" name="current_password" placeholder="Current password" required autocomplete="current-password" aria-label="Current password">
            <button class="btn btn-ghost btn-sm" type="submit">Turn off</button>
        </form>
    <?php elseif ($pending !== ''): ?>
        <ol>
            <li>Open an authenticator app (Google Authenticator, Microsoft Authenticator, Authy or similar) and choose <em>Add account → Enter a setup key</em>.</li>
            <li>Account: <strong><?= e($me['email']) ?></strong> · Key (time based):<div class="totp-secret mt-1"><?= e(trim(chunk_split($pending, 4, ' '))) ?></div>
                <p class="small muted mt-1">Or open this link on the phone that has the app: <a href="<?= e($otpauth) ?>">add to authenticator</a></p></li>
            <li>Enter the 6-digit code the app shows:</li>
        </ol>
        <form method="post" class="filter-row">
            <?= csrf_field() ?><input type="hidden" name="action" value="totp_enable">
            <input name="code" inputmode="numeric" maxlength="7" required placeholder="123456" aria-label="Code" autocomplete="one-time-code">
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('shield') ?>Turn on</button>
        </form>
    <?php else: ?>
        <p>Add a second step to admin sign-in using an authenticator app on your phone. Recommended for every administrator and donation manager.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="totp_start"><button class="btn btn-primary btn-sm" type="submit"><?= icon('shield') ?>Set up two-step login</button></form>
    <?php endif; ?>
</section>
<?php
admin_page_end();
