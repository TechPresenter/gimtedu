<?php
/**
 * Security centre: configuration health checks, sign-in activity (failed attempts, rate-limited identifiers),
 * recent password resets and staff accounts, with account actions: sign out everywhere, revoke remembered
 * devices, require a password change. Never shows passwords, tokens or two-step secrets.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('security.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('security.php');
    $action = (string) ($_POST['action'] ?? '');
    $target = null;
    if (!empty($_POST['user_id'])) {
        $target = db_row('SELECT id, name, email FROM users WHERE id = ?', [(int) $_POST['user_id']]);
    } elseif (trim((string) ($_POST['login'] ?? '')) !== '') {
        $found = find_user_by_login((string) $_POST['login']);
        $target = $found ? ['id' => $found['id'], 'name' => $found['name'], 'email' => $found['email']] : null;
    }
    if (!$target || !in_array($action, ['signout', 'revoke', 'force_password'], true)) {
        flash('error', $target ? 'Unknown action.' : 'No account was found with that email address or mobile number.');
        admin_redirect('security.php');
    }
    $uid = (int) $target['id'];
    if (is_administrator($uid) && !is_administrator()) {
        admin_log('access_denied', 'Tried a security action on administrator #' . $uid);
        flash('error', 'Only an administrator can change another administrator\'s sessions.');
        admin_redirect('security.php');
    }
    $who = $target['name'] . ' <' . $target['email'] . '> (#' . $uid . ')';
    if ($action === 'signout') {
        logout_everywhere($uid);
        add_notification($uid, 'Signed out on all devices', 'For your security, an administrator signed your account out on every device. Please sign in again.', '/login/', 'security');
        admin_log('security_signout_everywhere', 'Signed out everywhere: ' . $who);
        flash('success', $target['name'] . ' has been signed out on every device.');
    } elseif ($action === 'revoke') {
        $n = db_query('DELETE FROM remember_tokens WHERE user_id = ?', [$uid])->rowCount();
        admin_log('security_remember_revoked', 'Revoked ' . $n . ' remembered device(s): ' . $who);
        flash('success', $n . ' remembered device' . ($n === 1 ? '' : 's') . ' removed for ' . $target['name'] . '.');
    } else {
        db_update('users', ['must_change_password' => 1], 'id = :id', ['id' => $uid]);
        logout_everywhere($uid);
        add_notification($uid, 'Please choose a new password', 'For your security, please set a new password the next time you sign in.', '/dashboard/password/', 'security');
        admin_log('security_password_change_required', 'Password change required: ' . $who);
        flash('success', $target['name'] . ' must choose a new password at the next sign-in, and has been signed out everywhere.');
    }
    admin_redirect('security.php');
}

$checks = security_health_checks();
$counts = array_count_values(array_column($checks, 'status'));
$failed24 = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action IN ('login_failed','totp_failed') AND created_at > NOW() - INTERVAL 1 DAY");
$failed7 = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action IN ('login_failed','totp_failed') AND created_at > NOW() - INTERVAL 7 DAY");
$logins7 = (int) db_value("SELECT COUNT(*) FROM activity_logs WHERE action = 'login' AND created_at > NOW() - INTERVAL 7 DAY");
$topIps = db_rows("SELECT ip_address, COUNT(*) n, MAX(created_at) last_at FROM activity_logs WHERE action = 'login_failed' AND created_at > NOW() - INTERVAL 7 DAY
                   GROUP BY ip_address ORDER BY n DESC LIMIT 8");
$topIds = db_rows("SELECT details AS identifier, COUNT(*) n, MAX(created_at) last_at, MAX(user_id) user_id FROM activity_logs
                   WHERE action = 'login_failed' AND created_at > NOW() - INTERVAL 7 DAY AND details <> '' GROUP BY details ORDER BY n DESC LIMIT 8");
$since = date('Y-m-d H:i:s', time() - LOGIN_LOCK_MINUTES * 60);
$lockedIds = db_rows("SELECT details AS identifier, COUNT(*) n, MAX(created_at) last_at FROM activity_logs WHERE action = 'login_failed' AND created_at > ? AND details <> ''
                      GROUP BY details HAVING n >= ? ORDER BY last_at DESC", [$since, LOGIN_MAX_ATTEMPTS]);
$lockedIps = db_rows("SELECT ip_address, COUNT(*) n, MAX(created_at) last_at FROM activity_logs WHERE action = 'login_failed' AND created_at > ?
                      GROUP BY ip_address HAVING n >= ? ORDER BY last_at DESC", [$since, LOGIN_MAX_ATTEMPTS * 2]);
$resets = db_rows('SELECT pr.created_at, pr.expires_at, pr.used_at, pr.ip_address, u.name, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
                   ORDER BY pr.id DESC LIMIT 12');
$staff = db_rows("SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.last_login_ip, u.totp_enabled, u.must_change_password,
                         GROUP_CONCAT(DISTINCT r.name ORDER BY r.sort_order SEPARATOR ', ') roles,
                         (SELECT COUNT(*) FROM remember_tokens t WHERE t.user_id = u.id AND t.expires_at > NOW()) devices
                  FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
                  WHERE r.is_staff = 1 GROUP BY u.id ORDER BY MIN(r.sort_order), u.name");
$canAct = function (int $uid): bool {
    return is_administrator() || !is_administrator($uid);
};
$actionForm = function (int $uid, string $action, string $label, string $icon, string $confirm) {
    return '<form method="post" class="inline-form" data-confirm="' . e($confirm) . '">' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="user_id" value="' . $uid . '">'
        . '<button type="submit" class="icon-btn" title="' . e($label) . '" aria-label="' . e($label) . '">' . icon($icon) . '</button></form>';
};

admin_page_start('Security Centre');
?>
<div class="stat-cards insight-kpis">
    <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) ($counts['ok'] ?? 0) ?> / <?= count($checks) ?></strong><small>Health checks passed</small><span class="kpi-sub"><?= (int) ($counts['bad'] ?? 0) ?> need attention · <?= (int) ($counts['warn'] ?? 0) ?> recommendations</span></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('alert-triangle') ?></span><div><strong><?= number_format($failed24) ?></strong><small>Failed sign-ins · last 24 hours</small><span class="kpi-sub"><?= number_format($failed7) ?> in the last 7 days</span></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('lock') ?></span><div><strong><?= count($lockedIds) + count($lockedIps) ?></strong><small>Currently rate-limited</small><span class="kpi-sub">Locked for <?= (int) LOGIN_LOCK_MINUTES ?> minutes after <?= (int) LOGIN_MAX_ATTEMPTS ?> failures</span></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('log-out') ?></span><div><strong><?= number_format($logins7) ?></strong><small>Successful sign-ins · 7 days</small></div></div>
</div>

<section class="panel">
    <div class="panel-head"><h2>Health checks</h2><span class="muted small">Fix red items first</span></div>
    <ul class="health-list">
        <?php
        usort($checks, function ($a, $b) { return ['bad' => 0, 'warn' => 1, 'ok' => 2][$a['status']] <=> ['bad' => 0, 'warn' => 1, 'ok' => 2][$b['status']]; });
        foreach ($checks as $c): ?>
            <li class="<?= e($c['status']) ?>"><?= icon($c['status'] === 'ok' ? 'check-circle' : ($c['status'] === 'warn' ? 'alert-circle' : 'alert-triangle')) ?>
                <div><strong><?= e($c['title']) ?></strong> <span class="badge badge-<?= $c['status'] === 'ok' ? 'green' : ($c['status'] === 'warn' ? 'gold' : 'red') ?>"><?= $c['status'] === 'ok' ? 'OK' : ($c['status'] === 'warn' ? 'Recommended' : 'Action needed') ?></span>
                    <span class="health-detail"><?= e($c['detail']) ?></span>
                    <?php if ($c['fix'] !== ''): ?><span class="health-fix"><?= icon('tool') ?><?= e($c['fix']) ?></span><?php endif; ?></div></li>
        <?php endforeach; ?>
    </ul>
</section>

<div class="admin-grid-2 mt-3">
    <section class="panel">
        <div class="panel-head"><h2>Failed sign-ins by IP · 7 days</h2></div>
        <?php if ($topIps): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>IP address</th><th class="text-right">Attempts</th><th>Last attempt</th></tr></thead>
                <tbody><?php foreach ($topIps as $r): ?><tr><td><code><?= e($r['ip_address']) ?></code></td><td class="text-right"><?= (int) $r['n'] ?></td><td class="nowrap"><?= e(format_date($r['last_at'], 'd M, h:i A')) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="muted mb-0">No failed sign-ins in the last 7 days.</p><?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Failed sign-ins by account · 7 days</h2></div>
        <?php if ($topIds): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Email / mobile entered</th><th class="text-right">Attempts</th><th>Last attempt</th></tr></thead>
                <tbody><?php foreach ($topIds as $r): ?><tr><td><?= e(mb_substr((string) $r['identifier'], 0, 80)) ?><?= $r['user_id'] ? ' <span class="badge badge-blue">Account exists</span>' : '' ?></td><td class="text-right"><?= (int) $r['n'] ?></td><td class="nowrap"><?= e(format_date($r['last_at'], 'd M, h:i A')) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="muted mb-0">No failed sign-ins in the last 7 days.</p><?php endif; ?>
    </section>
</div>

<div class="admin-grid-2 mt-3">
    <section class="panel">
        <div class="panel-head"><h2>Rate-limited right now</h2></div>
        <?php if ($lockedIds || $lockedIps): ?>
            <ul class="link-list security-locked">
                <?php foreach ($lockedIds as $r): ?><li><?= icon('user') ?><span><?= e(mb_substr((string) $r['identifier'], 0, 80)) ?> · <?= (int) $r['n'] ?> failures · unlocks about <?= e(date('h:i A', strtotime($r['last_at']) + LOGIN_LOCK_MINUTES * 60)) ?></span></li><?php endforeach; ?>
                <?php foreach ($lockedIps as $r): ?><li><?= icon('globe') ?><span>IP <code><?= e($r['ip_address']) ?></code> · <?= (int) $r['n'] ?> failures · unlocks about <?= e(date('h:i A', strtotime($r['last_at']) + LOGIN_LOCK_MINUTES * 60)) ?></span></li><?php endforeach; ?>
            </ul>
            <p class="muted small mb-0">Locks lift automatically. If a genuine user is locked out, ask them to wait or reset their password.</p>
        <?php else: ?><p class="muted mb-0">No account or IP address is locked at the moment.</p><?php endif; ?>
    </section>
    <section class="panel">
        <div class="panel-head"><h2>Recent password reset requests</h2></div>
        <?php if ($resets): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Account</th><th>Requested</th><th>Status</th></tr></thead>
                <tbody><?php foreach ($resets as $r):
                    $st = $r['used_at'] ? 'used' : (strtotime($r['expires_at']) < time() ? 'expired' : 'pending'); ?>
                    <tr><td><?= e($r['name']) ?><br><small class="muted"><?= e($r['email']) ?></small></td><td class="nowrap"><?= e(format_date($r['created_at'], 'd M, h:i A')) ?><br><small class="muted"><?= e((string) $r['ip_address']) ?></small></td>
                        <td><?= $st === 'used' ? '<span class="badge badge-green">Used / closed</span>' : ($st === 'expired' ? '<span class="badge badge-gray">Expired</span>' : '<span class="badge badge-gold">Link active</span>') ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php else: ?><p class="muted mb-0">No password reset requests yet.</p><?php endif; ?>
    </section>
</div>

<section class="panel mt-3">
    <div class="panel-head"><h2>Staff accounts</h2><span class="muted small">Sign-out and password actions apply immediately</span></div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Staff member</th><th>Roles</th><th>Last sign-in</th><th>Two-step login</th><th>Remembered devices</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): $uid = (int) $s['id']; ?>
            <tr>
                <td><strong><?= e($s['name']) ?></strong><?= $uid === admin_id() ? ' <span class="badge badge-blue">You</span>' : '' ?><br><small class="muted"><?= e($s['email']) ?></small>
                    <?= $s['status'] !== 'active' ? '<br>' . status_badge($s['status']) : '' ?><?= $s['must_change_password'] ? '<br><span class="badge badge-gold">Must change password</span>' : '' ?></td>
                <td><?= e($s['roles']) ?></td>
                <td class="nowrap"><?= $s['last_login_at'] ? e(format_date($s['last_login_at'], 'd M Y, h:i A')) . '<br><small class="muted">' . e((string) $s['last_login_ip']) . '</small>' : '<span class="muted">Never</span>' ?></td>
                <td><?= $s['totp_enabled'] ? '<span class="badge badge-green">On</span>' : '<span class="badge badge-red">Off</span>' ?></td>
                <td><?= (int) $s['devices'] ?></td>
                <td class="actions-cell">
                    <?php if ($canAct($uid)): ?>
                        <?= $actionForm($uid, 'signout', 'Sign out everywhere', 'log-out', 'Sign ' . $s['name'] . ' out on every device?') ?>
                        <?= $actionForm($uid, 'revoke', 'Revoke remembered devices', 'smartphone', 'Remove all remembered devices for ' . $s['name'] . '?') ?>
                        <?= $actionForm($uid, 'force_password', 'Require a password change', 'key', $s['name'] . ' will be signed out and must choose a new password. Continue?') ?>
                    <?php else: ?><span class="muted small">Administrator</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small mt-2 mb-0"><?= icon('info') ?> Roles are assigned in <a href="<?= e(admin_url('users.php')) ?>">Users &amp; Roles</a>. Each staff member turns on two-step login from <a href="<?= e(admin_url('profile.php#two-step')) ?>">My Profile</a>.</p>
</section>

<section class="panel mt-3">
    <div class="panel-head"><h2>Secure any account</h2></div>
    <p class="muted small">For a member or volunteer who reports a lost phone or a suspicious sign-in. Enter their email address or mobile number.</p>
    <form method="post" class="filter-row" data-confirm="Apply this security action to the account?">
        <?= csrf_field() ?>
        <label class="sr-only" for="sec-login">Email address or mobile number</label>
        <input id="sec-login" name="login" required maxlength="190" placeholder="Email address or mobile number" autocomplete="off">
        <label class="sr-only" for="sec-action">Action</label>
        <select id="sec-action" name="action">
            <option value="signout">Sign out on every device</option>
            <option value="revoke">Revoke remembered devices</option>
            <option value="force_password">Require a password change</option>
        </select>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('shield') ?>Apply</button>
    </form>
</section>
<?php
admin_page_end();
