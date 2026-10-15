<?php
/**
 * Sign in (/login/): email or mobile number + password, "remember me" (30-day rotating cookie),
 * ?next= support, resend of the verification e-mail for unverified accounts. Staff can sign in here too.
 * Rate limiting and generic error messages come from attempt_login().
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$next = safe_next((string) ($_POST['next'] ?? $_GET['next'] ?? ''), '');
$self = '/login/' . ($next !== '' ? '?next=' . urlencode($next) : '');

if (is_logged_in()) {
    redirect($next !== '' ? $next : '/dashboard/');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify($self);
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'resend') {
        // Only offered after a correct password for an unverified account (the id is kept in the session).
        $uid = (int) ($_SESSION['m5_verify_uid'] ?? 0);
        if (!form_rate_limited('resend_verification', 5) && $uid > 0) {
            member_resend_verification($uid);
        }
        log_activity('form_resend_verification', 'Verification resend requested from the sign-in page');
        unset($_SESSION['m5_verify_uid']);
        account_notice(['type' => 'success', 'text' => 'If your account still needs to be verified, we have sent a new verification link to its email address. Please check your inbox and spam folder.']);
        redirect($self);
    }

    $login = mb_substr(trim((string) ($_POST['login'] ?? '')), 0, 190);
    $password = (string) ($_POST['password'] ?? '');
    $_SESSION['_old'] = ['login' => $login, 'remember' => !empty($_POST['remember']) ? '1' : ''];
    if ($login === '' || $password === '') {
        account_notice(['type' => 'error', 'text' => 'Please enter your email address or mobile number and your password.']);
        redirect($self);
    }

    $result = attempt_login($login, mb_substr($password, 0, 128));
    if ($result['ok']) {
        unset($_SESSION['_old'], $_SESSION['m5_verify_uid']);
        $user = $result['user'];
        login_user($user, !empty($_POST['remember']));
        if (is_staff((int) $user['id'])) {
            // Signed in with a password: the admin panel may be opened (two-step login is still enforced there).
            $_SESSION['staff_auth'] = true;
            $_SESSION['admin_last_activity'] = time();
            unset($_SESSION['remembered']);
            log_activity('admin_login', 'Staff signed in via the website sign-in page', (int) $user['id'], true);
            flash('success', 'Welcome back, ' . strtok((string) $user['name'], ' ') . '. You can open the admin panel from your dashboard.');
        } else {
            flash('success', 'Welcome back, ' . strtok((string) $user['name'], ' ') . '.');
        }
        redirect($next !== '' ? $next : '/dashboard/');
    }

    if (($result['code'] ?? '') === 'unverified' && !empty($result['user']['id'])) {
        $_SESSION['m5_verify_uid'] = (int) $result['user']['id'];
    } else {
        unset($_SESSION['m5_verify_uid']);
    }
    account_notice(['type' => 'error', 'text' => $result['error'], 'code' => $result['code'] ?? 'invalid']);
    redirect($self);
}

$notice = account_notice();
// Name the destination in the title ("Sign in to continue to My Donations").
$nextTitle = '';
if ($next !== '') {
    $nextSlug = trim((string) parse_url($next, PHP_URL_PATH), '/');
    $nextParts = explode('/', $nextSlug);
    $memberSections = member_sections();
    if ($nextParts[0] === 'dashboard') {
        $nextTitle = $memberSections[$nextParts[1] ?? 'overview'][2] ?? 'My Dashboard';
    } elseif ($nextSlug !== '' && ($nextPage = get_page($nextSlug))) {
        $nextTitle = (string) $nextPage['title'];
    }
}
$showResend = !empty($_SESSION['m5_verify_uid']);
$registrationOpen = setting('allow_registration', '1') === '1';

page_header($page, [
    'title'            => 'Sign in',
    'meta_title'       => $nextTitle !== '' ? 'Sign in to continue to ' . $nextTitle : 'Sign in to your account',
    'meta_description' => 'Sign in to your ' . site_name() . ' account to see your donations, receipts, applications, events and certificates.',
    'banner'           => false,
    'noindex'          => true,
    'body_class'       => 'acc-page',
]);
?>
<section class="acc-auth" aria-labelledby="acc-title">
    <div class="container acc-grid">
        <?= account_side_panel('login') ?>
        <div class="acc-main">
            <div class="acc-card">
                <div class="acc-card-head">
                    <span class="acc-card-icon"><?= icon('lock') ?></span>
                    <div>
                        <h1 id="acc-title">Welcome back</h1>
                        <p>Sign in to your <?= e(site_name()) ?> account.</p>
                    </div>
                </div>

                <?= account_notice_html($notice) ?>

                <?php if ($showResend): ?>
                    <form class="acc-resend" method="post" action="<?= e(url($self)) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend">
                        <input type="hidden" name="next" value="<?= e($next) ?>">
                        <p><?= icon('mail') ?><span>Didn't receive the verification email, or has the link expired?</span></p>
                        <button class="btn btn-outline btn-sm" type="submit"><?= icon('send') ?>Resend verification email</button>
                    </form>
                <?php endif; ?>

                <form class="acc-form" method="post" action="<?= e(url($self)) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="next" value="<?= e($next) ?>">
                    <div class="form-group">
                        <label for="login">Email address or mobile number<span class="req">*</span></label>
                        <div class="acc-input-icon"><?= icon('user') ?>
                            <input id="login" type="text" name="login" required maxlength="190" autocomplete="username" autocapitalize="none" spellcheck="false" value="<?= e(old('login')) ?>">
                        </div>
                    </div>
                    <?= account_password_field('password', 'password', ['label' => 'Password', 'required' => true, 'autocomplete' => 'current-password']) ?>
                    <div class="acc-row">
                        <label class="checkbox"><input type="checkbox" name="remember" value="1"<?= old('remember') === '1' ? ' checked' : '' ?>><span>Remember me for 30 days</span></label>
                        <a class="acc-link" href="<?= e(page_url('forgot-password')) ?>">Forgot password?</a>
                    </div>
                    <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('lock') ?>Sign in</button>
                    <p class="acc-fine"><?= icon('info') ?><span>Use "Remember me" only on your own device. You can sign out of all devices from your dashboard at any time.</span></p>
                </form>

                <div class="acc-divider"><span>New to <?= e(site_name()) ?>?</span></div>
                <?php if ($registrationOpen): ?>
                    <a class="btn btn-outline btn-block" href="<?= e(page_url('register')) ?>"><?= icon('user') ?>Create a free account</a>
                <?php else: ?>
                    <p class="acc-fine center">New registrations are paused at the moment. You can still <a href="<?= e(page_url('donate')) ?>">donate</a> or <a href="<?= e(page_url('become-a-volunteer')) ?>">apply as a volunteer</a> without an account.</p>
                <?php endif; ?>
                <p class="acc-staff">Team member? Sign in here and open the admin panel from your dashboard, or go straight to the <a href="<?= e(url('admin/login.php')) ?>">admin sign-in</a>.</p>
            </div>
        </div>
    </div>
</section>
<?php
render_sections($page);
$GLOBALS['extraScripts'][] = asset('assets/js/modules/member.js');
page_footer();
