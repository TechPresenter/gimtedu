<?php
require_once __DIR__ . '/includes/functions.php';

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'dashboard.php');
if (!preg_match('#^[a-z0-9\-]+\.php(\?[A-Za-z0-9=&%_\-.+]*)?$#i', $next) || $next === 'login.php') {
    $next = 'dashboard.php';
}
if (current_admin()) {
    admin_redirect($next);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify('admin/login.php');
    [$data, $errors] = validate($_POST, ['email' => 'required|email', 'password' => 'required|max:72']);
    if ($errors) {
        back_with_errors($errors, 'admin/login.php', $_POST);
    }
    if (too_many_attempts('admin_login_failed', $data['email'])) {
        flash('error', 'Too many failed attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes and try again.');
        admin_redirect('login.php');
    }
    $row = db_row('SELECT * FROM admins WHERE email = ?', [$data['email']]);
    $hash = $row['password_hash'] ?? '$2y$10$iphNgp5.B9HY75AgnVWmOOSD7/HiZA/dKJXEc7SWtiqysZppUgBtK';
    $ok = password_verify($_POST['password'], $hash) && $row;
    if (!$ok || $row['status'] !== 'active') {
        log_activity('admin_login_failed', $data['email']);
        back_with_errors(['password' => 'Invalid login details or the account is inactive.'], 'admin/login.php', ['email' => $data['email']]);
    }
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        db_update('admins', ['password_hash' => password_hash($_POST['password'], PASSWORD_DEFAULT)], 'id = :id', ['id' => $row['id']]);
    }
    admin_login($row);
    admin_redirect($next);
}
header('X-Robots-Tag: noindex, nofollow');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login | <?= e(site_name()) ?></title>
    <link rel="icon" type="image/png" href="<?= e(asset('assets/images/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body>
<main class="login-screen">
    <div class="login-card">
        <div class="brand-row">
            <img src="<?= e(url('assets/images/logo.png')) ?>" alt="<?= e(site_name()) ?> logo">
            <h1 style="font-size:1.35rem;margin:0"><?= e(site_name()) ?></h1>
            <p class="muted small mb-0">Admin Panel — authorised staff only</p>
        </div>
        <?= str_replace('flash-wrap container', 'flash-wrap', render_flash()) ?>
        <form method="post" action="<?= e(admin_url('login.php')) ?>" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($next) ?>">
            <div class="form-group mb-2">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" required maxlength="190" autocomplete="username" value="<?= e(old('email')) ?>" autofocus>
                <?= field_error('email') ?>
            </div>
            <div class="form-group mb-2">
                <label for="password">Password</label>
                <div class="password-wrap">
                    <input id="password" type="password" name="password" required maxlength="72" autocomplete="current-password">
                    <button class="password-toggle" type="button" aria-label="Show password">Show</button>
                </div>
                <?= field_error('password') ?>
            </div>
            <button class="btn btn-primary btn-lg btn-block" type="submit"><?= icon('lock') ?>Log In</button>
        </form>
        <p class="text-center small mt-3"><a href="<?= e(url('index.php')) ?>">&larr; Back to website</a></p>
    </div>
</main>
<script src="<?= e(asset('assets/js/form-validation.js')) ?>"></script>
</body>
</html>
<?php unset($_SESSION['_old'], $_SESSION['_errors']); ?>
