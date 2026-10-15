<?php
/**
 * One-time installer: checks the server, verifies the database schema and creates the FIRST administrator.
 *
 * Security:
 * - Requires INSTALL_KEY (set in pwf-config.php / config.php). Without it the installer refuses to run.
 * - Works only while no administrator exists. Afterwards it writes install.lock and refuses to run again.
 * - Attempts are rate limited. No default account or password exists anywhere in the code or database.
 */

define('PWF_INSTALLER', true);
require_once __DIR__ . '/functions.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$errors = [];
$checks = [];
$done = false;
$locked = is_file(__DIR__ . '/install.lock');

$check = function (bool $ok, string $label, string $hint = '') use (&$checks) {
    $checks[] = [$ok, $label, $hint];
    return $ok;
};

$check(version_compare(PHP_VERSION, '8.0.0', '>='), 'PHP 8.0 or newer (current ' . PHP_VERSION . ')', 'Change the PHP version in your hosting panel.');
foreach (['pdo_mysql', 'mbstring', 'curl', 'fileinfo', 'json', 'dom', 'openssl'] as $ext) {
    $check(extension_loaded($ext), 'PHP extension ' . $ext, 'Enable it in your hosting PHP settings.');
}
$check(extension_loaded('gd'), 'PHP extension gd (recommended for image optimisation)', 'Optional, but recommended.');
$check(is_writable(UPLOAD_PATH), 'uploads/ folder is writable', 'Set permission 755 on public_html/uploads and its sub-folders.');
$check(INSTALL_KEY !== '' && strlen(INSTALL_KEY) >= 16, 'INSTALL_KEY is set (16+ characters)', 'Add define(\'INSTALL_KEY\', \'a-long-random-value\'); to pwf-config.php or config.php.');
$check(BASE_URL !== '' && preg_match('#^https?://#', BASE_URL), 'BASE_URL is set (' . BASE_URL . ')', 'Set BASE_URL in config.');

$dbOk = false;
$hasAdmin = false;
$dbHint = '';
try {
    $tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $dbOk = $check(true, 'Connected to database ' . DB_NAME);
    $required = ['users', 'roles', 'permissions', 'user_roles', 'pages', 'page_sections', 'menus', 'menu_items', 'website_settings', 'campaigns', 'donations'];
    $missing = array_diff($required, $tables);
    $dbOk = $check(!$missing, 'database.sql has been imported (' . count($tables) . ' tables)', $missing ? 'Missing: ' . implode(', ', $missing) . '. Import database.sql with phpMyAdmin.' : '') && $dbOk;
    if ($dbOk) {
        $hasAdmin = (int) db_value("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.slug = 'administrator'") > 0;
    }
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $hint = stripos($msg, 'Access denied') !== false
        ? 'Wrong database username or password, or the user is not linked to the database. Reset the user password in your hosting panel (letters and numbers only) and copy it into DB_PASS.'
        : (stripos($msg, 'Unknown database') !== false ? 'DB_NAME does not exist. Copy the exact full name from your hosting panel.' : 'Check DB_HOST, DB_NAME, DB_USER and DB_PASS.');
    $check(false, 'Connect to the database', $hint);
    $dbHint = $hint;
}

if ($hasAdmin && !$locked) {
    @file_put_contents(__DIR__ . '/install.lock', 'Installed ' . date('c') . "\n");
    $locked = true;
}

if (!$locked && $dbOk && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Your session expired. Please submit the form again.';
    } elseif (too_many_attempts('install_failed')) {
        $errors[] = 'Too many attempts. Please wait ' . LOGIN_LOCK_MINUTES . ' minutes.';
    } elseif (INSTALL_KEY === '' || strlen(INSTALL_KEY) < 16 || !hash_equals(INSTALL_KEY, (string) ($_POST['install_key'] ?? ''))) {
        log_activity('install_failed', 'Wrong install key');
        $errors[] = 'The installation key is not correct.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $mobile = normalize_mobile((string) ($_POST['mobile'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($name) < 2) {
            $errors[] = 'Please enter the administrator\'s name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if ($mobile !== '' && !preg_match('/^[6-9]\d{9}$/', $mobile)) {
            $errors[] = 'Please enter a valid 10-digit mobile number or leave it empty.';
        }
        if ($p = password_problem($password, $name, $email)) {
            $errors[] = $p;
        } elseif (mb_strlen($password) < 12) {
            $errors[] = 'The administrator password must be at least 12 characters long.';
        }
        if ($password !== (string) ($_POST['password_confirm'] ?? '')) {
            $errors[] = 'The two passwords do not match.';
        }
        if (!$errors && db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) {
            $errors[] = 'A user with this email already exists.';
        }
        if (!$errors) {
            $id = create_user([
                'name' => $name, 'email' => $email, 'mobile' => $mobile ?: null, 'password' => $password,
                'email_verified_at' => date('Y-m-d H:i:s'), 'privacy_accepted' => true,
            ], ['administrator']);
            save_setting('installed_at', date('Y-m-d H:i:s'));
            if (setting('contact_email') === '') {
                save_setting('contact_email', $email);
            }
            log_activity('installed', 'First administrator created', $id, true);
            @file_put_contents(__DIR__ . '/install.lock', 'Installed ' . date('c') . "\n");
            $done = true;
            $locked = true;
        }
    }
}

// Once installed, show only the locked notice: no PHP version, extensions, database name, BASE_URL or table count.
$showChecks = !$locked || $done;
if ($locked && !$done) {
    http_response_code($dbHint !== '' ? 503 : 403);
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>Install - Prerit Welfare Foundation</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset('assets/css/enhance.css')) ?>">
</head><body class="install-page">
<main class="container narrow section">
    <div class="form-card">
        <div class="auth-head"><img src="<?= e(url('assets/images/logo.png')) ?>" alt="" width="72" height="72"><div><h1>Website setup</h1><p class="muted">Prerit Welfare Foundation</p></div></div>

        <?php if ($showChecks): ?>
        <h2 class="form-section-title">Server checks</h2>
        <ul class="install-checks">
            <?php foreach ($checks as [$ok, $label, $hint]): ?>
                <li class="<?= $ok ? 'ok' : 'bad' ?>"><?= icon($ok ? 'check-circle' : 'alert-circle') ?><span><?= e($label) ?><?php if (!$ok && $hint): ?><small><?= e($hint) ?></small><?php endif; ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="alert alert-success"><?= icon('check-circle') ?><div><strong>Installation complete.</strong> The administrator account has been created and the installer is now locked.
                <br>Next: sign in at <a href="<?= e(url('/admin/')) ?>">/admin/</a>, then add your logo, contact details and verified registration information in Settings.
                For extra safety you can delete install.php from the server.</div></div>
        <?php elseif ($locked): ?>
            <?php if ($dbHint !== ''): ?>
                <div class="alert alert-error" role="alert"><?= icon('alert-circle') ?><div><strong>The website cannot connect to its database.</strong> <?= e($dbHint) ?> These settings are in pwf-config.php (or config.php).</div></div>
            <?php endif; ?>
            <div class="alert alert-info"><?= icon('lock') ?><div>This website is already installed, so the installer is locked. Sign in at <a href="<?= e(url('/admin/')) ?>">/admin/</a>. To recover access, use "Forgot password" on the login page.</div></div>
        <?php elseif (!$dbOk): ?>
            <div class="alert alert-error"><?= icon('alert-circle') ?><div>Fix the database items above, then reload this page.</div></div>
        <?php else: ?>
            <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= icon('alert-circle') ?><div><?= e($err) ?></div></div><?php endforeach; ?>
            <h2 class="form-section-title">Create the first administrator</h2>
            <form method="post" autocomplete="off" class="form-grid">
                <?= csrf_field() ?>
                <div class="form-group full"><label for="install_key">Installation key<span class="req">*</span></label>
                    <input id="install_key" type="password" name="install_key" required autocomplete="off"><span class="help">The INSTALL_KEY value from your configuration file.</span></div>
                <div class="form-group"><label for="name">Full name<span class="req">*</span></label><input id="name" name="name" required maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"></div>
                <div class="form-group"><label for="email">Email<span class="req">*</span></label><input id="email" type="email" name="email" required maxlength="190" value="<?= e($_POST['email'] ?? '') ?>"></div>
                <div class="form-group"><label for="mobile">Mobile (optional)</label><input id="mobile" type="tel" name="mobile" maxlength="15" value="<?= e($_POST['mobile'] ?? '') ?>"></div>
                <div class="form-group"><label for="password">Password<span class="req">*</span></label><input id="password" type="password" name="password" required minlength="12" autocomplete="new-password"><span class="help">At least 12 characters with letters and numbers.</span></div>
                <div class="form-group"><label for="password_confirm">Confirm password<span class="req">*</span></label><input id="password_confirm" type="password" name="password_confirm" required minlength="12" autocomplete="new-password"></div>
                <div class="form-actions full"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Create administrator</button></div>
            </form>
        <?php endif; ?>
    </div>
</main>
</body></html>
