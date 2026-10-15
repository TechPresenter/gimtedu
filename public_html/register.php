<?php
require_once __DIR__ . '/functions.php';

if (is_logged_in()) {
    redirect('member-dashboard.php');
}
$pageTitle = 'Create an Account';
$pageDescription = 'Register to track your donations, receipts, applications and certificates.';
require __DIR__ . '/header.php';
page_banner('Create an Account', 'Track your donations, receipts, applications and certificates in one place');
?>
<section class="section">
    <div class="container">
        <div class="auth-wrap">
            <div class="form-card">
                <div class="auth-head">
                    <span class="icon-circle"><?= icon('user') ?></span>
                    <h2>Register</h2>
                    <p class="muted mb-0">Already have an account? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
                </div>
                <form action="<?= e(url('process-registration.php')) ?>" method="post" data-validate>
                    <?= csrf_field() ?>
                    <?= honeypot_field() ?>
                    <div class="form-group mb-2">
                        <label for="name">Full name<span class="req">*</span></label>
                        <input id="name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>">
                        <?= field_error('name') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label for="email">Email<span class="req">*</span></label>
                        <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?= e(old('email')) ?>">
                        <?= field_error('email') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label for="phone">Mobile number<span class="req">*</span></label>
                        <input id="phone" type="tel" name="phone" required maxlength="15" autocomplete="tel" value="<?= e(old('phone')) ?>">
                        <?= field_error('phone') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label for="password">Password<span class="req">*</span></label>
                        <div class="password-wrap">
                            <input id="password" type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" data-rule="password">
                            <button class="password-toggle" type="button" aria-label="Show password">Show</button>
                        </div>
                        <span class="help">At least 8 characters with letters and numbers.</span>
                        <?= field_error('password') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label for="password_confirm">Confirm password<span class="req">*</span></label>
                        <input id="password_confirm" type="password" name="password_confirm" required maxlength="72" autocomplete="new-password" data-match="password">
                        <?= field_error('password_confirm') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label class="checkbox"><input type="checkbox" name="accept_terms" value="1" required> <span>I agree to the <a href="<?= e(url('terms.php')) ?>" target="_blank">Terms &amp; Conditions</a> and <a href="<?= e(url('privacy-policy.php')) ?>" target="_blank">Privacy Policy</a>.</span></label>
                        <?= field_error('accept_terms') ?>
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" type="submit" data-loading="Creating account…">Create Account</button>
                </form>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
