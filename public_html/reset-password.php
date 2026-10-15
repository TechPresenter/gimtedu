<?php
require_once __DIR__ . '/functions.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $reset = db_row('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()', [hash('sha256', $token)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('reset-password.php?token=' . urlencode($token));
    if (!$reset) {
        flash('error', 'This reset link is invalid or has expired. Please request a new one.');
        redirect('forgot-password.php');
    }
    [$data, $errors] = validate($_POST, [
        'password'         => 'required|password',
        'password_confirm' => 'required|same:password',
    ], ['password_confirm' => 'Password confirmation']);
    if ($errors) {
        back_with_errors($errors, 'reset-password.php?token=' . urlencode($token));
    }
    db_update('users', ['password_hash' => password_hash($data['password'], PASSWORD_DEFAULT)], 'id = :id', ['id' => $reset['user_id']]);
    db_update('password_resets', ['used_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $reset['id']]);
    db_query('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$reset['user_id']]);
    log_activity('password_reset', 'Password reset via email link', null, (int) $reset['user_id']);
    logout_user();
    flash('success', 'Your password has been changed. Please log in with your new password.');
    redirect('login.php');
}

$pageTitle = 'Choose a New Password';
require __DIR__ . '/header.php';
page_banner('Choose a New Password');
?>
<section class="section">
    <div class="container">
        <div class="auth-wrap">
            <div class="form-card">
                <?php if (!$reset): ?>
                    <div class="auth-head">
                        <span class="icon-circle" style="background:var(--red-100);color:var(--red-600)"><?= icon('alert-circle') ?></span>
                        <h2>Link expired</h2>
                        <p class="muted">This password reset link is invalid, has already been used or has expired.</p>
                        <a class="btn btn-primary" href="<?= e(url('forgot-password.php')) ?>">Request a new link</a>
                    </div>
                <?php else: ?>
                    <div class="auth-head">
                        <span class="icon-circle"><?= icon('lock') ?></span>
                        <h2>New password</h2>
                    </div>
                    <form method="post" action="<?= e(url('reset-password.php')) ?>" data-validate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <div class="form-group mb-2">
                            <label for="password">New password<span class="req">*</span></label>
                            <div class="password-wrap">
                                <input id="password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-rule="password">
                                <button class="password-toggle" type="button" aria-label="Show password">Show</button>
                            </div>
                            <span class="help">At least 8 characters with letters and numbers.</span>
                            <?= field_error('password') ?>
                        </div>
                        <div class="form-group mb-2">
                            <label for="password_confirm">Confirm new password<span class="req">*</span></label>
                            <input id="password_confirm" type="password" name="password_confirm" required maxlength="72" autocomplete="new-password" data-match="password">
                            <?= field_error('password_confirm') ?>
                        </div>
                        <button class="btn btn-primary btn-lg btn-block" type="submit">Save New Password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
