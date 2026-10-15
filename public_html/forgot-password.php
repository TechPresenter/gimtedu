<?php
require_once __DIR__ . '/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('forgot-password.php');
    [$data, $errors] = validate($_POST, ['email' => 'required|email']);
    if ($errors) {
        back_with_errors($errors, 'forgot-password.php', $_POST);
    }
    if (form_rate_limited('forgot', 5)) {
        flash('error', 'Too many reset requests. Please try again later.');
        redirect('forgot-password.php');
    }
    log_activity('form_forgot', '');

    $user = db_row("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'", [$data['email']]);
    if ($user) {
        db_query('DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW()', [$user['id']]);
        $token = bin2hex(random_bytes(32));
        db_insert('password_resets', [
            'user_id'    => $user['id'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'ip_address' => client_ip(),
        ]);
        send_mail($user['email'], 'Reset your password - ' . site_name(),
            '<p>Dear ' . e($user['name']) . ',</p><p>We received a request to reset the password for your account. Click the button below to choose a new password. This link is valid for 1 hour and can be used only once.</p>'
            . '<p><a class="btn" href="' . e(abs_url('reset-password.php?token=' . $token)) . '">Reset my password</a></p>'
            . '<p>If you did not request this, you can safely ignore this email.</p>');
        log_activity('password_reset_requested', '', null, (int) $user['id']);
    }
    // Same message whether or not the account exists (prevents email discovery).
    flash('success', 'If an account exists for that email, a password reset link has been sent. Please check your inbox.');
    redirect('forgot-password.php');
}

$pageTitle = 'Forgot Password';
require __DIR__ . '/header.php';
page_banner('Forgot Password', 'We will email you a secure link to reset your password');
?>
<section class="section">
    <div class="container">
        <div class="auth-wrap">
            <div class="form-card">
                <div class="auth-head">
                    <span class="icon-circle gold"><?= icon('mail') ?></span>
                    <h2>Reset password</h2>
                    <p class="muted mb-0">Enter the email address you registered with.</p>
                </div>
                <form method="post" action="<?= e(url('forgot-password.php')) ?>" data-validate>
                    <?= csrf_field() ?>
                    <div class="form-group mb-2">
                        <label for="email">Email<span class="req">*</span></label>
                        <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old('email')) ?>">
                        <?= field_error('email') ?>
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" type="submit" data-loading="Sending…">Send Reset Link</button>
                </form>
                <div class="auth-links"><a href="<?= e(url('login.php')) ?>">Back to login</a></div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
