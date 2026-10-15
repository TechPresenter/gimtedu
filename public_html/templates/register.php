<?php
/**
 * Create an account (/register/). Account types come from self_register_roles() (never staff roles).
 * Duplicate e-mail addresses / mobile numbers never create a second account and are never revealed:
 * every submission ends on the same "check your email" screen; the existing owner receives a notice instead.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
if (is_logged_in()) {
    redirect('/dashboard/');
}

$open = setting('allow_registration', '1') === '1';
$roles = self_register_roles();
$roleSlugs = array_column($roles, 'slug');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify('/register/');
    if (!$open || !$roles) {
        flash('error', 'New registrations are paused at the moment.');
        redirect('/register/');
    }
    if (honeypot_triggered()) {
        redirect('/register/?sent=1');
    }
    if (form_rate_limited('register', 5)) {
        flash('error', 'Too many sign-up attempts from your network. Please try again in an hour.');
        redirect('/register/');
    }

    [$data, $errors] = validate($_POST, [
        'name'                  => 'required|min:2|max:120',
        'email'                 => 'required|email',
        'mobile'                => 'required|phone',
        'account_type'          => 'required|in:' . implode(',', $roleSlugs),
        'accept_terms'          => 'accepted',
        'communication_consent' => 'in:1',
    ], [
        'account_type' => 'Account type', 'accept_terms' => 'I agree to the Privacy Policy and Terms & Conditions',
        'communication_consent' => 'Communication preference', 'mobile' => 'Mobile number',
    ]);
    $password = (string) ($_POST['password'] ?? '');
    $mobile = normalize_mobile($data['mobile']);
    if (!isset($errors['mobile']) && (strlen($mobile) < 8 || strlen($mobile) > 15)) {
        $errors['mobile'] = 'Please enter a valid mobile number.';
    }
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif ($problem = password_problem($password, $data['name'], $data['email'])) {
        $errors['password'] = $problem;
    } elseif (strlen($password) > 72) {
        $errors['password'] = 'Password may not be longer than 72 characters.';
    } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
    if ($errors) {
        back_with_errors($errors, '/register/', $_POST);
    }

    log_activity('form_register', 'Registration form submitted');
    $email = $data['email'];
    $byEmail = db_row('SELECT id, name, email FROM users WHERE email = ?', [$email]);
    $byMobile = db_row('SELECT id, name, email FROM users WHERE mobile = ?', [$mobile]);
    $when = date('d M Y, h:i A');
    $help = '<p>If this was you, you already have an account: <a href="' . e(abs_url('login/')) . '">sign in</a> with your email address or mobile number,'
        . ' or <a href="' . e(abs_url('forgot-password/')) . '">reset your password</a> if you have forgotten it.</p>'
        . '<p>If it was not you, you can ignore this email. No new account was created and nothing about your account has changed.</p>';

    if ($byEmail) {
        send_mail($byEmail['email'], 'Someone tried to register with your email - ' . site_name(),
            '<p>Dear ' . e($byEmail['name']) . ',</p><p>On ' . e($when) . ' someone tried to create a new ' . e(site_name()) . ' account using your email address.</p>' . $help);
        log_activity('register_duplicate', 'Registration attempt with an existing email address', (int) $byEmail['id']);
    }
    if ($byMobile && (!$byEmail || (int) $byMobile['id'] !== (int) $byEmail['id'])) {
        send_mail($byMobile['email'], 'Someone tried to register with your mobile number - ' . site_name(),
            '<p>Dear ' . e($byMobile['name']) . ',</p><p>On ' . e($when) . ' someone tried to create a new ' . e(site_name()) . ' account using the mobile number registered on your account.</p>' . $help);
        log_activity('register_duplicate', 'Registration attempt with an existing mobile number', (int) $byMobile['id']);
    }

    if (!$byEmail && !$byMobile) {
        try {
            $id = create_user([
                'name' => $data['name'], 'email' => $email, 'mobile' => $mobile, 'password' => $password, 'status' => 'active',
                'communication_consent' => $data['communication_consent'] === '1', 'privacy_accepted' => true,
            ], [$data['account_type']]);
        } catch (PDOException $ex) {
            $id = 0; // a parallel registration with the same email/mobile: treated like a duplicate
        }
        if ($id > 0) {
            $user = db_row('SELECT * FROM users WHERE id = ?', [$id]);
            send_verification_email($user);
            add_notification($id, 'Welcome to ' . site_name(), 'Thank you for joining us. From your dashboard you can follow your donations, applications, events and certificates.', '/dashboard/', 'success');
            track_conversion('registration', $id);
            log_activity('register', 'Account created (' . $data['account_type'] . ')', $id);
            if (setting('require_email_verification', '1') !== '1') {
                login_user($user, false);
                flash('success', 'Welcome, ' . strtok($data['name'], ' ') . '! Your account is ready. We have also sent you a link to verify your email address.');
                redirect('/dashboard/');
            }
        }
    }
    redirect('/register/?sent=1');
}

$sent = isset($_GET['sent']);
$typeIcons = ['member' => 'id-card', 'registered_volunteer' => 'users', 'donor' => 'heart', 'beneficiary_applicant' => 'life-buoy'];
$selectedType = (string) old('account_type', in_array('member', $roleSlugs, true) ? 'member' : ($roleSlugs[0] ?? ''));

page_header($page, [
    'title'            => $sent ? 'Check your email' : 'Create an account',
    'meta_title'       => 'Create an account',
    'meta_description' => 'Create a free ' . site_name() . ' account to follow your donations and receipts, applications, events and certificates.',
    'banner'           => false,
    'noindex'          => true,
    'body_class'       => 'acc-page',
]);
?>
<section class="acc-auth" aria-labelledby="acc-title">
    <div class="container acc-grid acc-grid-wide">
        <?= account_side_panel('register') ?>
        <div class="acc-main">
            <div class="acc-card">
                <?php if ($sent): ?>
                    <div class="acc-result">
                        <span class="acc-result-icon"><?= icon('mail-open') ?></span>
                        <h1 id="acc-title">Check your email to activate your account</h1>
                        <p>We have sent an activation link to the email address you entered. Open the email and click <strong>Verify my email</strong> to activate your account. The link is valid for 48 hours.</p>
                        <ul class="acc-steps">
                            <li><?= icon('inbox') ?><span>Can't find it? Check your spam or promotions folder.</span></li>
                            <li><?= icon('user') ?><span>Already have an account with these details? We have emailed the account owner instead - simply <a href="<?= e(page_url('login')) ?>">sign in</a> or <a href="<?= e(page_url('forgot-password')) ?>">reset your password</a>.</span></li>
                            <li><?= icon('refresh') ?><span>Link expired? <a href="<?= e(page_url('verify-email')) ?>">Request a new verification email</a>.</span></li>
                        </ul>
                        <div class="btn-row">
                            <a class="btn btn-primary" href="<?= e(page_url('login')) ?>"><?= icon('lock') ?>Go to sign in</a>
                            <a class="btn btn-ghost" href="<?= e(url('/')) ?>"><?= icon('home') ?>Back to homepage</a>
                        </div>
                    </div>
                <?php elseif (!$open || !$roles): ?>
                    <div class="acc-result">
                        <span class="acc-result-icon is-muted"><?= icon('lock') ?></span>
                        <h1 id="acc-title">Registration is closed</h1>
                        <p>We are not accepting new account registrations at the moment. You can still donate, apply to volunteer or contact us without an account.</p>
                        <div class="btn-row">
                            <a class="btn btn-donate" href="<?= e(page_url('donate')) ?>"><?= icon('heart') ?>Donate</a>
                            <a class="btn btn-outline" href="<?= e(page_url('become-a-volunteer')) ?>"><?= icon('users') ?>Volunteer</a>
                            <a class="btn btn-ghost" href="<?= e(page_url('login')) ?>">Sign in</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="acc-card-head">
                        <span class="acc-card-icon"><?= icon('user') ?></span>
                        <div>
                            <h1 id="acc-title">Create your account</h1>
                            <p>It takes a minute. Already registered? <a href="<?= e(page_url('login')) ?>">Sign in</a></p>
                        </div>
                    </div>
                    <form class="acc-form" method="post" action="<?= e(page_url('register')) ?>" data-acc-form>
                        <?= csrf_field() ?><?= honeypot_field() ?>
                        <fieldset class="acc-types">
                            <legend>I am joining as<span class="req">*</span></legend>
                            <div class="acc-type-grid">
                                <?php foreach ($roles as $role): ?>
                                    <label class="acc-type">
                                        <input type="radio" name="account_type" value="<?= e($role['slug']) ?>" required<?= $selectedType === $role['slug'] ? ' checked' : '' ?>>
                                        <span class="acc-type-card">
                                            <span class="acc-type-icon"><?= icon($typeIcons[$role['slug']] ?? 'user') ?></span>
                                            <strong><?= e($role['name']) ?></strong>
                                            <?php if (!empty($role['description'])): ?><small><?= e($role['description']) ?></small><?php endif; ?>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?= field_error('account_type') ?>
                        </fieldset>

                        <div class="form-grid">
                            <div class="form-group full">
                                <label for="name">Full name<span class="req">*</span></label>
                                <input id="name" type="text" name="name" required minlength="2" maxlength="120" autocomplete="name" value="<?= e(old('name')) ?>">
                                <?= field_error('name') ?>
                            </div>
                            <div class="form-group">
                                <label for="email">Email address<span class="req">*</span></label>
                                <input id="email" type="email" name="email" required maxlength="190" autocomplete="email" autocapitalize="none" spellcheck="false" value="<?= e(old('email')) ?>">
                                <?= field_error('email') ?>
                            </div>
                            <div class="form-group">
                                <label for="mobile">Mobile number<span class="req">*</span></label>
                                <input id="mobile" type="tel" name="mobile" required maxlength="16" inputmode="tel" autocomplete="tel" aria-describedby="mobile-help" value="<?= e(old('mobile')) ?>">
                                <span class="help" id="mobile-help">You can also sign in with your 10-digit mobile number.</span>
                                <?= field_error('mobile') ?>
                            </div>
                            <?= account_password_field('password', 'password', ['label' => 'Password', 'required' => true, 'autocomplete' => 'new-password', 'meter' => true]) ?>
                            <?= account_password_field('password_confirm', 'password_confirm', ['label' => 'Confirm password', 'required' => true, 'autocomplete' => 'new-password', 'match' => 'password']) ?>
                        </div>

                        <div class="acc-consents">
                            <label class="checkbox"><input type="checkbox" name="accept_terms" value="1" required<?= old('accept_terms') === '1' ? ' checked' : '' ?>>
                                <span>I have read and agree to the <a href="<?= e(page_url('privacy-policy')) ?>" target="_blank" rel="noopener">Privacy Policy</a> and the <a href="<?= e(page_url('terms-conditions')) ?>" target="_blank" rel="noopener">Terms &amp; Conditions</a>.<span class="req">*</span></span></label>
                            <?= field_error('accept_terms') ?>
                            <label class="checkbox"><input type="checkbox" name="communication_consent" value="1"<?= old('communication_consent') === '1' ? ' checked' : '' ?>>
                                <span>Keep me informed by email about events, campaigns and volunteering opportunities (optional - you can change this any time).</span></label>
                        </div>

                        <button class="btn btn-primary btn-block btn-lg" type="submit"><?= icon('check') ?>Create my account</button>
                        <p class="acc-fine"><?= icon('shield') ?><span>We will email you a link to verify your address. We never share your details or ask for your password, OTP or bank details.</span></p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php
render_sections($page);
$GLOBALS['extraScripts'][] = asset('assets/js/modules/member.js');
page_footer();
