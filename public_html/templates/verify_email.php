<?php
/**
 * Email verification (/verify-email/?token=...): tokens are hashed, single use and valid for 48 hours.
 * The token is processed and removed from the address bar (redirect), then the result is shown.
 * Anyone can ask for a new link with their email address (rate limited; the reply is always generic).
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['token'])) {
    $uid = verify_email_token((string) $_GET['token']);
    $_SESSION['m5_verify_result'] = $uid ? 'ok' : 'invalid';
    if ($uid) {
        add_notification($uid, 'Email address verified', 'Thank you - your email address is now verified.', '/dashboard/', 'success');
        if ((int) ($_SESSION['m5_verify_uid'] ?? 0) === $uid) {
            unset($_SESSION['m5_verify_uid']);
        }
    }
    redirect('/verify-email/');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify('/verify-email/');
    $me = current_user();
    if (($_POST['action'] ?? '') === 'resend_self' && $me) {
        if (!form_rate_limited('resend_verification', 5)) {
            member_resend_verification((int) $me['id']);
        }
        log_activity('form_resend_verification', 'Verification resend requested');
    } else {
        if (honeypot_triggered()) {
            redirect('/verify-email/?sent=1');
        }
        [$data, $errors] = validate($_POST, ['email' => 'required|email']);
        if ($errors) {
            back_with_errors($errors, '/verify-email/', $_POST);
        }
        if (form_rate_limited('resend_verification', 5)) {
            flash('error', 'Too many requests from your network. Please try again in an hour.');
            redirect('/verify-email/');
        }
        log_activity('form_resend_verification', 'Verification resend requested');
        $target = db_row('SELECT id FROM users WHERE email = ?', [$data['email']]);
        if ($target) {
            member_resend_verification((int) $target['id']);
        }
    }
    redirect('/verify-email/?sent=1');
}

$result = $_SESSION['m5_verify_result'] ?? '';
unset($_SESSION['m5_verify_result']);
$me = current_user();
if ($result === '' && isset($_GET['sent'])) {
    $result = 'sent';
}
if ($result === '' && $me && !empty($me['email_verified_at'])) {
    $result = 'ok';
}
$titles = ['ok' => 'Your email is verified', 'invalid' => 'This link has expired', 'sent' => 'Check your email', '' => 'Verify your email'];

page_header($page, [
    'title'            => $titles[$result] ?? 'Verify your email',
    'meta_title'       => ['ok' => 'Email address verified', 'invalid' => 'Verification link expired', 'sent' => 'New verification link sent', '' => 'Verify your email address'][$result] ?? 'Verify your email address',
    'meta_description' => 'Verify the email address of your ' . site_name() . ' account.',
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
                <div class="acc-result">
                    <?php if ($result === 'ok'): ?>
                        <span class="acc-result-icon"><?= icon('check-circle') ?></span>
                        <h1 id="acc-title">Your email is verified</h1>
                        <p>Thank you for confirming your email address. Your account is now active<?= $me ? '' : ' and you can sign in' ?>.</p>
                        <div class="btn-row">
                            <?php if ($me): ?>
                                <a class="btn btn-primary" href="<?= e(member_url()) ?>"><?= icon('grid') ?>Go to my dashboard</a>
                            <?php else: ?>
                                <a class="btn btn-primary" href="<?= e(page_url('login')) ?>"><?= icon('lock') ?>Sign in</a>
                            <?php endif; ?>
                            <a class="btn btn-ghost" href="<?= e(url('/')) ?>"><?= icon('home') ?>Homepage</a>
                        </div>
                    <?php else: ?>
                        <?php if ($result === 'invalid'): ?>
                            <span class="acc-result-icon is-warning"><?= icon('alert-triangle') ?></span>
                            <h1 id="acc-title">This link has expired</h1>
                            <p>The verification link is invalid, has already been used or is more than 48 hours old. If your email is already verified you can simply sign in; otherwise request a new link below.</p>
                        <?php elseif ($result === 'sent'): ?>
                            <span class="acc-result-icon"><?= icon('mail-open') ?></span>
                            <h1 id="acc-title">Check your email</h1>
                            <p>If an account with that address still needs to be verified, we have sent a new verification link. It is valid for 48 hours.</p>
                        <?php else: ?>
                            <span class="acc-result-icon"><?= icon('mail') ?></span>
                            <h1 id="acc-title">Verify your email</h1>
                            <p>To activate your account, open the verification link we emailed you after you registered. Need a new one? Enter your email address below.</p>
                        <?php endif; ?>

                        <?php if ($me && empty($me['email_verified_at'])): ?>
                            <form class="acc-form acc-form-inline" method="post" action="<?= e(page_url('verify-email')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="action" value="resend_self">
                                <button class="btn btn-primary" type="submit"><?= icon('send') ?>Send a new link to <?= e(member_mask_email((string) $me['email'])) ?></button>
                            </form>
                        <?php elseif (!$me): ?>
                            <form class="acc-form" method="post" action="<?= e(page_url('verify-email')) ?>">
                                <?= csrf_field() ?><?= honeypot_field() ?>
                                <input type="hidden" name="action" value="resend">
                                <div class="form-group">
                                    <label for="email">Email address</label>
                                    <div class="acc-input-icon"><?= icon('mail') ?>
                                        <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" autocapitalize="none" spellcheck="false" value="<?= e(old('email')) ?>">
                                    </div>
                                    <?= field_error('email') ?>
                                </div>
                                <button class="btn btn-primary btn-block" type="submit"><?= icon('send') ?>Send a new verification link</button>
                            </form>
                        <?php endif; ?>
                        <div class="acc-links">
                            <a href="<?= e(page_url('login')) ?>"><?= icon('chevron-left') ?>Back to sign in</a>
                            <a href="<?= e(page_url('contact')) ?>">Need help? Contact us</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php
page_footer();
