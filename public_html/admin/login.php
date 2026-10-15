<?php
/**
 * Staff sign-in: email or mobile + password, then the 6-digit code from an authenticator app
 * when two-step login is enabled for the account. Rate limited; generic error messages.
 */
require_once __DIR__ . '/includes/functions.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? '');
$nextSafe = preg_match('#^' . preg_quote(base_path() . '/admin/', '#') . '[a-z0-9\-]+\.php(\?[A-Za-z0-9=&%_\-.+]*)?$#i', $next) ? $next : '';
$goNext = function () use ($nextSafe): void {
    header('Location: ' . ($nextSafe !== '' ? $nextSafe : admin_url('dashboard.php')));
    exit;
};

$user = current_user();
$step = ($user && !empty($_SESSION['staff_auth']) && !empty($user['totp_enabled']) && empty($_SESSION['totp_ok'])) ? '2fa' : 'password';
if ($user && !empty($_SESSION['staff_auth']) && $step === 'password' && is_staff((int) $user['id'])) {
    $goNext();
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($step === '2fa') {
        $row = db_row('SELECT totp_secret FROM users WHERE id = ?', [$user['id']]);
        if (too_many_attempts('totp_failed', 'u' . $user['id'])) {
            $error = 'Too many incorrect codes. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes.';
        } elseif ($row && $row['totp_secret'] && totp_verify($row['totp_secret'], (string) ($_POST['code'] ?? ''))) {
            $_SESSION['totp_ok'] = true;
            session_regenerate_id(true);
            admin_log('admin_login', 'Staff sign-in completed with two-step code');
            $goNext();
        } else {
            log_activity('totp_failed', 'u' . $user['id'], (int) $user['id'], true);
            $error = 'That code is not correct. Use the current 6-digit code from your authenticator app.';
        }
    } else {
        $result = attempt_login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''), true);
        if ($result['ok']) {
            login_user($result['user'], false);
            $_SESSION['staff_auth'] = true;
            $_SESSION['admin_last_activity'] = time();
            unset($_SESSION['remembered']);
            if (!empty($result['user']['totp_enabled'])) {
                redirect('admin/login.php?step=2fa' . ($nextSafe !== '' ? '&next=' . urlencode($nextSafe) : ''));
            }
            admin_log('admin_login', 'Staff signed in');
            $goNext();
        }
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin sign in | <?= e(site_name()) ?></title>
    <link rel="icon" type="image/png" href="<?= e(asset('assets/images/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body>
<main class="login-screen">
    <div class="login-card">
        <div class="brand-row">
            <img src="<?= e(site_logo()) ?>" alt="<?= e(site_name()) ?>">
            <h1 class="h3"><?= $step === '2fa' ? 'Two-step verification' : 'Admin Panel' ?></h1>
            <p class="muted"><?= $step === '2fa' ? 'Enter the 6-digit code from your authenticator app.' : 'Sign in with your staff account' ?></p>
        </div>
        <?= str_replace('flash-wrap container', 'flash-wrap', render_flash()) ?>
        <?php if ($error): ?><div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><div><?= e($error) ?></div></div><?php endif; ?>
        <form method="post" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($nextSafe) ?>">
            <?php if ($step === '2fa'): ?>
                <div class="form-group"><label for="code">Authentication code</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus></div>
                <button class="btn btn-primary btn-block" type="submit"><?= icon('shield') ?>Verify</button>
                <p class="text-center mt-2 small"><a href="<?= e(page_url('logout')) ?>">Use a different account</a></p>
            <?php else: ?>
                <div class="form-group"><label for="login">Email or mobile number</label>
                    <input id="login" name="login" autocomplete="username" required maxlength="190" value="<?= e((string) ($_POST['login'] ?? '')) ?>" autofocus></div>
                <div class="form-group"><label for="password">Password</label>
                    <div class="password-wrap"><input id="password" type="password" name="password" autocomplete="current-password" required maxlength="128">
                    <button type="button" class="password-toggle" data-toggle-password="password" aria-label="Show password"><?= icon('eye') ?></button></div></div>
                <button class="btn btn-primary btn-block" type="submit"><?= icon('lock') ?>Sign in</button>
                <p class="text-center mt-2 small"><a href="<?= e(page_url('forgot-password')) ?>">Forgot password?</a> · <a href="<?= e(url('/')) ?>">Back to website</a></p>
            <?php endif; ?>
        </form>
    </div>
</main>
<script src="<?= e(asset('assets/js/form-validation.js')) ?>"></script>
</body>
</html>
