<?php
/**
 * Choose a new password (/reset-password/?token=...). Tokens are hashed, single use and expire after 1 hour
 * (48 hours for staff invitations). set_user_password() signs the account out everywhere and invalidates
 * every outstanding reset link and remember-me cookie.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$token = preg_match('/^[a-f0-9]{64}$/', $token) ? $token : '';
$self = '/reset-password/' . ($token !== '' ? '?token=' . $token : '');
$reset = $token !== '' ? find_password_reset($token) : null;
$resetUser = $reset ? db_row("SELECT id, name, email, status FROM users WHERE id = ?", [$reset['user_id']]) : null;
if ($resetUser && $resetUser['status'] !== 'active') {
    $resetUser = null;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify($self);
    if (!$reset || !$resetUser) {
        flash('error', 'This password reset link is invalid or has expired. Please request a new one.');
        redirect('/forgot-password/');
    }
    $password = (string) ($_POST['password'] ?? '');
    $errors = [];
    if ($problem = password_problem($password, (string) $resetUser['name'], (string) $resetUser['email'])) {
        $errors['password'] = $problem;
    } elseif (strlen($password) > 72) {
        $errors['password'] = 'Password may not be longer than 72 characters.';
    } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    if ($errors) {
        back_with_errors($errors, $self);
    }
    $uid = (int) $resetUser['id'];
    set_user_password($uid, $password);   // marks every reset token used, signs out all devices, emails the owner
    db_query('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$uid]); // the link proved ownership of the email
    log_activity('password_reset', 'Password reset with an emailed link', $uid);
    if ((int) ($_SESSION['uid'] ?? 0) === $uid) {
        logout_user();
    }
    unset($_SESSION['m5_verify_uid']);
    flash('success', 'Your password has been changed. Please sign in with your new password.');
    redirect('/login/');
}

page_header($page, [
    'title'            => 'Choose a new password',
    'meta_title'       => 'Choose a new password',
    'meta_description' => 'Choose a new password for your ' . site_name() . ' account.',
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
                <?php if (!$reset || !$resetUser): ?>
                    <div class="acc-result">
                        <span class="acc-result-icon is-warning"><?= icon('alert-triangle') ?></span>
                        <h1 id="acc-title"><?= $token === '' ? 'Open the link from your email' : 'This link has expired' ?></h1>
                        <p><?= $token === ''
                            ? 'To choose a new password, open the link in the password reset email we sent you.'
                            : 'This password reset link is invalid, has already been used or is more than 1 hour old. For your security, please request a new one.' ?></p>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="<?= e(page_url('forgot-password')) ?>"><?= icon('refresh') ?>Request a new link</a>
                            <a class="btn btn-ghost" href="<?= e(page_url('login')) ?>">Back to sign in</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="acc-card-head">
                        <span class="acc-card-icon"><?= icon('key') ?></span>
                        <div>
                            <h1 id="acc-title">Choose a new password</h1>
                            <p>For <strong><?= e(member_mask_email((string) $resetUser['email'])) ?></strong>. You will be signed out on all other devices.</p>
                        </div>
                    </div>
                    <form class="acc-form" method="post" action="<?= e(url($self)) ?>" data-acc-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="token" value="<?= e($token) ?>">
                        <?= account_password_field('password', 'password', ['label' => 'New password', 'required' => true, 'autocomplete' => 'new-password', 'meter' => true]) ?>
                        <?= account_password_field('password_confirm', 'password_confirm', ['label' => 'Confirm new password', 'required' => true, 'autocomplete' => 'new-password', 'match' => 'password']) ?>
                        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('check') ?>Save new password</button>
                        <p class="acc-fine"><?= icon('info') ?><span>Use at least 8 characters with letters and numbers. A longer phrase is stronger and easier to remember.</span></p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php
$GLOBALS['extraScripts'][] = asset('assets/js/modules/member.js');
page_footer();
