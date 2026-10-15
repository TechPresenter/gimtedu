<?php
/** Admin layout header. Set $adminTitle before including. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$admin = current_admin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(($adminTitle ?? 'Admin') . ' | ' . site_name() . ' Admin') ?></title>
    <link rel="icon" type="image/png" href="<?= e(asset('assets/images/favicon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>">
</head>
<body class="admin-body">
<div class="admin-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="admin-menu-toggle" type="button" aria-label="Toggle menu" aria-expanded="false"><?= icon('menu') ?></button>
            <h1><?= e($adminTitle ?? 'Admin') ?></h1>
            <div class="admin-topbar-right">
                <a class="btn btn-ghost btn-sm hide-sm" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>View site</a>
                <?php if ($admin): ?>
                    <a class="admin-user" href="<?= e(admin_url('profile.php')) ?>">
                        <span class="avatar"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span>
                        <span class="hide-sm"><strong><?= e($admin['name']) ?></strong><small><?= e($admin['role_name']) ?></small></span>
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <div class="admin-content">
            <?= str_replace('flash-wrap container', 'flash-wrap', render_flash()) ?>
