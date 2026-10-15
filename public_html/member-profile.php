<?php
require_once __DIR__ . '/functions.php';

$user = require_login();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('member-profile.php');
    if (($_POST['form'] ?? '') === 'password') {
        [$data, $errors] = validate($_POST, [
            'current_password' => 'required|max:72',
            'password'         => 'required|password',
            'password_confirm' => 'required|same:password',
        ], ['current_password' => 'Current password', 'password' => 'New password', 'password_confirm' => 'Password confirmation']);
        $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!$errors && !password_verify($_POST['current_password'], $hash)) {
            $errors['current_password'] = 'Your current password is incorrect.';
            log_activity('user_password_change_failed', '', null, $uid);
        }
        if ($errors) {
            back_with_errors($errors, 'member-profile.php#password');
        }
        db_update('users', ['password_hash' => password_hash($data['password'], PASSWORD_DEFAULT)], 'id = :id', ['id' => $uid]);
        session_regenerate_id(true);
        log_activity('user_password_changed', '', null, $uid);
        send_mail($user['email'], 'Your password was changed', '<p>Dear ' . e($user['name']) . ',</p><p>The password for your account was changed on ' . date('d M Y, h:i A') . '. If this was not you, please reset your password immediately and contact us.</p>');
        flash('success', 'Your password has been changed.');
        redirect('member-profile.php');
    }

    [$data, $errors] = validate($_POST, [
        'name'    => 'required|min:2|max:120',
        'phone'   => 'required|phone',
        'address' => 'max:255',
        'city'    => 'max:80',
        'state'   => 'in:' . implode(',', indian_states()),
        'pincode' => 'pincode',
    ]);
    if ($errors) {
        back_with_errors($errors, 'member-profile.php', $_POST);
    }
    db_update('users', [
        'name' => $data['name'], 'phone' => $data['phone'], 'address' => $data['address'] ?: null,
        'city' => $data['city'] ?: null, 'state' => $data['state'] ?: null, 'pincode' => $data['pincode'] ?: null,
    ], 'id = :id', ['id' => $uid]);
    log_activity('user_profile_updated', '', null, $uid);
    flash('success', 'Your profile has been updated.');
    redirect('member-profile.php');
}

$pageTitle = 'My Profile';
require __DIR__ . '/header.php';
page_banner('Profile & Password', 'Keep your details up to date');
?>
<section class="section">
    <div class="container member-layout">
        <?php render_member_nav($user); ?>
        <div>
            <div class="panel">
                <div class="panel-head"><h2>Profile details</h2><span class="muted small">Member since <?= e(format_date($user['created_at'])) ?></span></div>
                <form method="post" action="<?= e(url('member-profile.php')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="profile">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="name">Full name<span class="req">*</span></label>
                            <input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name', $user['name'])) ?>">
                            <?= field_error('name') ?>
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input id="email" type="email" value="<?= e($user['email']) ?>" disabled>
                            <span class="help">To change your email, please contact us.</span>
                        </div>
                        <div class="form-group">
                            <label for="phone">Mobile number<span class="req">*</span></label>
                            <input id="phone" type="tel" name="phone" required maxlength="15" value="<?= e(old('phone', $user['phone'])) ?>">
                            <?= field_error('phone') ?>
                        </div>
                        <div class="form-group">
                            <label for="pincode">PIN code</label>
                            <input id="pincode" type="text" name="pincode" maxlength="6" inputmode="numeric" data-rule="pincode" value="<?= e(old('pincode', $user['pincode'])) ?>">
                            <?= field_error('pincode') ?>
                        </div>
                        <div class="form-group full">
                            <label for="address">Address</label>
                            <input id="address" type="text" name="address" maxlength="255" value="<?= e(old('address', $user['address'])) ?>">
                        </div>
                        <div class="form-group">
                            <label for="city">City</label>
                            <input id="city" type="text" name="city" maxlength="80" value="<?= e(old('city', $user['city'])) ?>">
                        </div>
                        <div class="form-group">
                            <label for="state">State</label>
                            <select id="state" name="state"><?= state_options((string) old('state', $user['state'] ?? '')) ?></select>
                        </div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Save Profile</button></div>
                </form>
            </div>

            <div class="panel" id="password">
                <div class="panel-head"><h2>Change password</h2></div>
                <form method="post" action="<?= e(url('member-profile.php')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="form" value="password">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label for="current_password">Current password<span class="req">*</span></label>
                            <input id="current_password" type="password" name="current_password" required maxlength="72" autocomplete="current-password">
                            <?= field_error('current_password') ?>
                        </div>
                        <div class="form-group">
                            <label for="password">New password<span class="req">*</span></label>
                            <input id="password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-rule="password">
                            <?= field_error('password') ?>
                        </div>
                        <div class="form-group">
                            <label for="password_confirm">Confirm new password<span class="req">*</span></label>
                            <input id="password_confirm" type="password" name="password_confirm" required maxlength="72" autocomplete="new-password" data-match="password">
                            <?= field_error('password_confirm') ?>
                        </div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">Change Password</button></div>
                </form>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
