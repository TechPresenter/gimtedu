<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('users');

$roles = db_rows('SELECT * FROM roles ORDER BY id');
$editId = (int) ($_GET['edit'] ?? 0);
$showForm = $editId || ($_GET['action'] ?? '') === 'add';
$edit = $editId ? db_row('SELECT * FROM admins WHERE id = ?', [$editId]) : null;
if ($editId && !$edit) {
    flash('error', 'Admin user not found.');
    admin_redirect('users.php');
}
$admins = db_rows('SELECT a.*, r.name AS role_name FROM admins a JOIN roles r ON r.id = a.role_id ORDER BY a.status, a.name');

$adminTitle = 'Admin Users';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <p class="muted mb-0">People who can sign in to this admin panel. What each person can do depends on their role.</p>
    <div class="actions"><a class="btn btn-primary btn-sm" href="users.php?action=add"><?= icon('plus') ?>Add admin user</a></div>
</div>
<div class="<?= $showForm ? 'admin-grid-main' : '' ?>">
    <div class="panel">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($admins as $a): ?>
                    <tr>
                        <td><strong><?= e($a['name']) ?></strong><?= (int) $a['id'] === admin_id() ? ' <span class="badge badge-blue">You</span>' : '' ?></td>
                        <td><?= e($a['email']) ?></td>
                        <td><?= e($a['role_name']) ?></td>
                        <td><?= status_badge($a['status']) ?></td>
                        <td class="nowrap"><?= e(format_date($a['last_login_at'], 'd M Y, h:i A')) ?></td>
                        <td class="actions-cell"><a class="icon-btn" href="users.php?edit=<?= (int) $a['id'] ?>" title="Edit" aria-label="Edit"><?= icon('edit') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($showForm): ?>
        <div class="panel">
            <div class="panel-head"><h3><?= $edit ? 'Edit admin user' : 'Add admin user' ?></h3><a class="btn btn-ghost btn-sm" href="users.php">Close</a></div>
            <form method="post" action="actions/user-save.php" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="admin">
                <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <div class="form-group mb-2"><label for="name">Name<span class="req">*</span></label>
                    <input id="name" type="text" name="name" required maxlength="120" value="<?= e(old('name', $edit['name'] ?? '')) ?>"><?= field_error('name') ?></div>
                <div class="form-group mb-2"><label for="email">Email (login)<span class="req">*</span></label>
                    <input id="email" type="email" name="email" required maxlength="190" value="<?= e(old('email', $edit['email'] ?? '')) ?>"><?= field_error('email') ?></div>
                <div class="form-group mb-2"><label for="phone">Phone</label>
                    <input id="phone" type="tel" name="phone" maxlength="15" value="<?= e(old('phone', $edit['phone'] ?? '')) ?>"><?= field_error('phone') ?></div>
                <div class="form-group mb-2"><label for="role_id">Role<span class="req">*</span></label>
                    <select id="role_id" name="role_id" required>
                        <?php foreach ($roles as $r): if ($r['permissions'] === 'all' && !is_super_admin()) { continue; } ?>
                            <option value="<?= (int) $r['id'] ?>"<?= (string) old('role_id', (string) ($edit['role_id'] ?? '')) === (string) $r['id'] ? ' selected' : '' ?>><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select><?= field_error('role_id') ?></div>
                <div class="form-group mb-2"><label for="status">Status</label>
                    <select id="status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive'], (string) old('status', $edit['status'] ?? 'active'), true, '') ?></select><?= field_error('status') ?></div>
                <div class="form-group mb-2"><label for="password"><?= $edit ? 'Reset password (leave blank to keep)' : 'Temporary password' ?><?= $edit ? '' : '<span class="req">*</span>' ?></label>
                    <input id="password" type="text" name="password" <?= $edit ? '' : 'required' ?> minlength="10" maxlength="72" autocomplete="new-password" data-rule="password">
                    <span class="help">At least 10 characters with letters and numbers. The user must change it at first login.</span><?= field_error('password') ?></div>
                <button class="btn btn-primary" type="submit">Save</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
