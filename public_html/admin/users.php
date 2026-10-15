<?php
/**
 * Users & Roles: every website account (members and staff). Search, filters, pagination and CSV export.
 * Account changes (roles, status, verification, password reset ...) are made on user-view.php.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('members.view');

$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 120));
$role = (string) ($_GET['role'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$verified = (string) ($_GET['verified'] ?? '');
$roles = db_rows('SELECT id, name, slug, is_staff FROM roles ORDER BY sort_order, name');
$roleSlugs = array_column($roles, 'slug');

$where = ' WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $cols = ['u.name', 'u.email', 'u.mobile'];
    $where .= search_where($cols, $q, $params);
    $digits = normalize_mobile($q);
    if (strlen($digits) === 10 && $digits !== $q) {
        $where = substr($where, 0, -1) . ' OR u.mobile = ?)';
        $params[] = $digits;
    }
}
if ($role === 'staff') {
    $where .= ' AND EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.is_staff = 1)';
} elseif ($role === 'none') {
    $where .= ' AND NOT EXISTS (SELECT 1 FROM user_roles ur2 WHERE ur2.user_id = u.id)';
} elseif (in_array($role, $roleSlugs, true)) {
    $where .= ' AND EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND r2.slug = ?)';
    $params[] = $role;
} else {
    $role = '';
}
if (in_array($status, ['active', 'suspended', 'deactivated'], true)) {
    $where .= ' AND u.status = ?';
    $params[] = $status;
} else {
    $status = '';
}
if ($verified === 'yes') {
    $where .= ' AND u.email_verified_at IS NOT NULL';
} elseif ($verified === 'no') {
    $where .= ' AND u.email_verified_at IS NULL';
} else {
    $verified = '';
}

$select = "SELECT u.id, u.name, u.email, u.mobile, u.status, u.email_verified_at, u.city, u.state, u.communication_consent, u.totp_enabled,
                  u.last_login_at, u.created_at, GROUP_CONCAT(r.name ORDER BY r.sort_order SEPARATOR ', ') AS role_names, MAX(r.is_staff) AS staff
             FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id LEFT JOIN roles r ON r.id = ur.role_id" . $where . ' GROUP BY u.id';

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows($select . ' ORDER BY u.id', $params);
    admin_log('users_exported', 'Exported ' . count($rows) . ' users to CSV');
    send_csv('users-' . date('Y-m-d') . '.csv',
        ['ID', 'Name', 'Email', 'Mobile', 'Roles', 'Status', 'Email verified', 'City', 'State', 'Communication consent', 'Two-step login', 'Last sign-in', 'Joined'],
        array_map(function ($u) {
            return [$u['id'], $u['name'], $u['email'], $u['mobile'], $u['role_names'], $u['status'], $u['email_verified_at'] ? 'Yes' : 'No',
                $u['city'], $u['state'], $u['communication_consent'] ? 'Yes' : 'No', $u['totp_enabled'] ? 'On' : 'Off', $u['last_login_at'], $u['created_at']];
        }, $rows));
}

$total = (int) db_value('SELECT COUNT(*) FROM users u' . $where, $params);
$p = paginate($total, 25);
$users = db_rows($select . ' ORDER BY u.created_at DESC, u.id DESC LIMIT ' . (int) $p['per_page'] . ' OFFSET ' . (int) $p['offset'], $params);

$counts = db_row("SELECT COUNT(*) AS total, SUM(status = 'active') AS active, SUM(status <> 'active') AS inactive, SUM(email_verified_at IS NULL) AS unverified FROM users");
$staffCount = (int) db_value('SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE r.is_staff = 1');

$actions = [];
if (admin_can('users.manage')) {
    $actions[] = ['label' => 'Create staff user', 'url' => admin_url('user-view.php?new=1'), 'icon' => 'plus'];
}
if (admin_can('roles.manage')) {
    $actions[] = ['label' => 'Roles & permissions', 'url' => admin_url('roles.php'), 'icon' => 'lock', 'style' => 'ghost'];
}
if (admin_can('exports.run')) {
    $exportQuery = array_filter(['q' => $q, 'role' => $role, 'status' => $status, 'verified' => $verified], 'strlen') + ['export' => 'csv'];
    $actions[] = ['label' => 'Export CSV', 'url' => admin_url('users.php?' . http_build_query($exportQuery)), 'icon' => 'download', 'style' => 'outline'];
}
admin_page_start('Users & Roles', $actions);
?>
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('users.php')) ?>"><span class="icon-circle"><?= icon('users') ?></span><div><strong><?= (int) $counts['total'] ?></strong><small>Accounts</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('users.php?status=active')) ?>"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) $counts['active'] ?></strong><small>Active</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('users.php?verified=no')) ?>"><span class="icon-circle gold"><?= icon('mail') ?></span><div><strong><?= (int) $counts['unverified'] ?></strong><small>Email not verified</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('users.php?role=staff')) ?>"><span class="icon-circle blue"><?= icon('shield') ?></span><div><strong><?= $staffCount ?></strong><small>Staff accounts</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('users.php?status=suspended')) ?>"><span class="icon-circle"><?= icon('lock') ?></span><div><strong><?= (int) $counts['inactive'] ?></strong><small>Suspended / deactivated</small></div></a>
</div>

<section class="panel">
    <?php
    $roleSelect = '<select name="role" aria-label="Role"><option value="">All roles</option>'
        . '<option value="staff"' . ($role === 'staff' ? ' selected' : '') . '>Any staff role</option>'
        . '<option value="none"' . ($role === 'none' ? ' selected' : '') . '>No role</option>';
    foreach ($roles as $r) {
        $roleSelect .= '<option value="' . e($r['slug']) . '"' . ($role === $r['slug'] ? ' selected' : '') . '>' . e($r['name']) . '</option>';
    }
    $roleSelect .= '</select><select name="verified" aria-label="Email verification"><option value="">Verified or not</option>'
        . '<option value="yes"' . ($verified === 'yes' ? ' selected' : '') . '>Email verified</option>'
        . '<option value="no"' . ($verified === 'no' ? ' selected' : '') . '>Email not verified</option></select>';
    admin_filter_bar(['active', 'suspended', 'deactivated'], ['placeholder' => 'Search name, email or mobile', 'html' => $roleSelect, 'active' => $role !== '' || $verified !== '']);
    ?>
    <div class="table-meta"><span><?= $total ?> account<?= $total === 1 ? '' : 's' ?><?= $q !== '' || $role !== '' || $status !== '' || $verified !== '' ? ' match your filters' : '' ?></span><span>Page <?= (int) $p['page'] ?> of <?= (int) $p['pages'] ?></span></div>
    <?php if (!$users): ?>
        <?= empty_state('No accounts match these filters.') ?>
    <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>User</th><th>Mobile</th><th>Roles</th><th>Status</th><th>Email</th><th>Last sign-in</th><th>Joined</th><th class="actions-cell">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><div style="display:flex;gap:10px;align-items:center;min-width:220px">
                        <span class="avatar" style="width:38px;height:38px;flex-shrink:0" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span>
                        <span><a href="<?= e(admin_url('user-view.php?id=' . (int) $u['id'])) ?>"><strong><?= e($u['name']) ?></strong></a><br><small class="muted"><?= e($u['email']) ?></small></span></div></td>
                    <td class="nowrap"><?= e($u['mobile'] ?: '-') ?></td>
                    <td><?php if ($u['role_names']): ?><span class="badge <?= $u['staff'] ? 'badge-blue' : 'badge-gray' ?>" style="white-space:normal"><?= e($u['role_names']) ?></span><?php else: ?><span class="muted small">None</span><?php endif; ?></td>
                    <td><?= member_status_badge($u['status']) ?></td>
                    <td><?= member_status_badge($u['email_verified_at'] ? 'verified' : 'unverified') ?></td>
                    <td class="nowrap"><?= e(format_date($u['last_login_at'], 'd M Y, h:i A')) ?></td>
                    <td class="nowrap"><?= e(format_date($u['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="<?= e(admin_url('user-view.php?id=' . (int) $u['id'])) ?>" aria-label="Open <?= e($u['name']) ?>" title="Open"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php endif; ?>
</section>
<p class="small muted"><?= icon('info') ?> Passwords, sign-in tokens and two-step secrets are never shown or exported. Staff roles can only be assigned here by authorised staff - never through public registration.</p>
<?php
admin_page_end();
