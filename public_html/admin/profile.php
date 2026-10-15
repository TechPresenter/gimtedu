<?php
require_once __DIR__ . '/includes/auth.php';

$adminTitle = 'My Profile';
require __DIR__ . '/includes/header.php';
?>
<?php if ($admin['must_change_password']): ?>
    <div class="alert alert-warning mb-3"><?= icon('lock') ?><div>You are using a temporary password. Please choose a new password to continue using the admin panel.</div></div>
<?php endif; ?>
<div class="admin-grid-2">
    <div class="panel">
        <div class="panel-head"><h3>Profile</h3><span class="badge badge-blue"><?= e($admin['role_name']) ?></span></div>
        <form method="post" action="actions/user-save.php" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="profile">
            <div class="form-group mb-2">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name', $admin['name'])) ?>">
                <?= field_error('name') ?>
            </div>
            <div class="form-group mb-2">
                <label for="email">Email</label>
                <input id="email" type="email" value="<?= e($admin['email']) ?>" disabled>
                <span class="help">Ask a super admin to change your login email.</span>
            </div>
            <div class="form-group mb-2">
                <label for="phone">Phone</label>
                <input id="phone" type="tel" name="phone" maxlength="15" value="<?= e(old('phone', $admin['phone'])) ?>">
                <?= field_error('phone') ?>
            </div>
            <button class="btn btn-primary" type="submit">Save profile</button>
        </form>
        <p class="small muted mt-3">Last login: <?= e(format_date($admin['last_login_at'], 'd M Y, h:i A')) ?> from <?= e($admin['last_login_ip'] ?: '-') ?></p>
    </div>
    <div class="panel">
        <div class="panel-head"><h3>Change password</h3></div>
        <form method="post" action="actions/user-save.php" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="type" value="password">
            <div class="form-group mb-2">
                <label for="current_password">Current password</label>
                <input id="current_password" type="password" name="current_password" required maxlength="72" autocomplete="current-password">
                <?= field_error('current_password') ?>
            </div>
            <div class="form-group mb-2">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required minlength="10" maxlength="72" autocomplete="new-password" data-rule="password">
                <span class="help">At least 10 characters with letters and numbers.</span>
                <?= field_error('password') ?>
            </div>
            <div class="form-group mb-2">
                <label for="password_confirm">Confirm new password</label>
                <input id="password_confirm" type="password" name="password_confirm" required maxlength="72" autocomplete="new-password" data-match="password">
                <?= field_error('password_confirm') ?>
            </div>
            <button class="btn btn-primary" type="submit">Change password</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
