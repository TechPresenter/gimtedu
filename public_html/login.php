<?php
require_once __DIR__ . '/functions.php';

$next = safe_next($_GET['next'] ?? old('next'), 'member-dashboard.php');
if (is_logged_in()) {
    redirect($next);
}
$pageTitle = 'Login';
$pageDescription = 'Log in to your Prerit Welfare Foundation account.';
require __DIR__ . '/header.php';
page_banner('Member & Donor Login', 'Access your donations, receipts, applications and certificates');
?>
<section class="section">
    <div class="container">
        <div class="auth-wrap">
            <div class="form-card">
                <div class="auth-head">
                    <span class="icon-circle"><?= icon('lock') ?></span>
                    <h2>Log in</h2>
                    <p class="muted mb-0">New here? <a href="<?= e(url('register.php')) ?>">Create an account</a></p>
                </div>
                <form action="<?= e(url('process-login.php')) ?>" method="post" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="next" value="<?= e($next) ?>">
                    <div class="form-group mb-2">
                        <label for="email">Email<span class="req">*</span></label>
                        <input id="email" type="email" name="email" required maxlength="190" autocomplete="username" value="<?= e(old('email')) ?>" autofocus>
                        <?= field_error('email') ?>
                    </div>
                    <div class="form-group mb-2">
                        <label for="password">Password<span class="req">*</span></label>
                        <div class="password-wrap">
                            <input id="password" type="password" name="password" required maxlength="72" autocomplete="current-password">
                            <button class="password-toggle" type="button" aria-label="Show password">Show</button>
                        </div>
                        <?= field_error('password') ?>
                    </div>
                    <button class="btn btn-primary btn-lg btn-block" type="submit" data-loading="Logging in…">Log In</button>
                </form>
                <div class="auth-links">
                    <a href="<?= e(url('forgot-password.php')) ?>">Forgot password?</a>
                    <a href="<?= e(url('application-status.php')) ?>">Check application status</a>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
