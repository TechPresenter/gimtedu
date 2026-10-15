<?php
/**
 * Forgot password (/forgot-password/): email address or mobile number. The answer is always the same
 * generic message, whether or not an account exists. Reset links are single use and valid for 1 hour.
 * Rate limited per IP (5 per hour) and per identifier (3 per hour).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify('/forgot-password/');
    if (honeypot_triggered()) {
        redirect('/forgot-password/?sent=1');
    }
    $login = mb_substr(trim((string) ($_POST['login'] ?? '')), 0, 190);
    if ($login === '') {
        back_with_errors(['login' => 'Please enter your email address or mobile number.'], '/forgot-password/', $_POST);
    }
    if (form_rate_limited('forgot_password', 5)) {
        flash('error', 'Too many password reset requests from your network. Please try again in an hour.');
        redirect('/forgot-password/');
    }
    log_activity('form_forgot_password', 'Password reset form submitted');

    $key = mb_strtolower($login);
    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $user = find_user_by_login($login);
    // At most 3 requests per hour for the same identifier and for the same account (email or mobile).
    $recent = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action = 'password_reset_request' AND created_at > ? AND (details = ?"
        . ($user ? ' OR user_id = ?' : '') . ')', $user ? [$hourAgo, $key, (int) $user['id']] : [$hourAgo, $key]);
    if ($recent < 3) {
        log_activity('password_reset_request', $key, $user ? (int) $user['id'] : null);
        if ($user && $user['status'] === 'active') {
            member_send_reset_link($user);
        }
    }
    redirect('/forgot-password/?sent=1');
}

$sent = isset($_GET['sent']);
page_header($page, [
    'title'            => $sent ? 'Check your email' : 'Forgot your password?',
    'meta_title'       => $sent ? 'Check your email for a reset link' : 'Forgot password',
    'meta_description' => 'Reset the password of your ' . site_name() . ' account.',
    'banner'           => false,
    'noindex'          => true,
    'body_class'       => 'acc-page',
]);
?>
<section class="acc-auth acc-auth-compact" aria-labelledby="acc-title">
    <div class="container acc-grid">
        <?= account_side_panel('login') ?>
        <div class="acc-main">
            <div class="acc-card">
                <?php if ($sent): ?>
                    <div class="acc-result">
                        <span class="acc-result-icon"><?= icon('mail-open') ?></span>
                        <h1 id="acc-title">Check your email</h1>
                        <p>If an account matches the details you entered, we have sent a link to choose a new password to the email address on that account.</p>
                        <ul class="acc-steps">
                            <li><?= icon('clock') ?><span>The link is valid for 1 hour and can be used only once.</span></li>
                            <li><?= icon('inbox') ?><span>Nothing arrived after a few minutes? Check your spam folder or try again.</span></li>
                            <li><?= icon('shield') ?><span>We never ask for your password or OTP by phone, WhatsApp or email.</span></li>
                        </ul>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="<?= e(page_url('login')) ?>"><?= icon('lock') ?>Back to sign in</a>
                            <a class="btn btn-ghost" href="<?= e(page_url('forgot-password')) ?>"><?= icon('refresh') ?>Try again</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="acc-card-head">
                        <span class="acc-card-icon"><?= icon('key') ?></span>
                        <div>
                            <h1 id="acc-title">Forgot your password?</h1>
                            <p>Enter your email address or mobile number and we will email you a link to choose a new one.</p>
                        </div>
                    </div>
                    <form class="acc-form" method="post" action="<?= e(page_url('forgot-password')) ?>">
                        <?= csrf_field() ?><?= honeypot_field() ?>
                        <div class="form-group">
                            <label for="login">Email address or mobile number</label>
                            <div class="acc-input-icon"><?= icon('mail') ?>
                                <input id="login" type="text" name="login" required maxlength="190" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e(old('login')) ?>">
                            </div>
                            <?= field_error('login') ?>
                        </div>
                        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('send') ?>Send reset link</button>
                    </form>
                    <div class="acc-links">
                        <a href="<?= e(page_url('login')) ?>"><?= icon('chevron-left') ?>Back to sign in</a>
                        <?php if (setting('allow_registration', '1') === '1'): ?><a href="<?= e(page_url('register')) ?>">Create an account</a><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php
render_sections($page);
page_footer();
