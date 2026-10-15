<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('users');

$editId = (int) ($_GET['edit'] ?? 0);
$showForm = is_super_admin() && ($editId || ($_GET['action'] ?? '') === 'add');
$edit = $editId ? db_row('SELECT * FROM roles WHERE id = ?', [$editId]) : null;
if ($edit && $edit['permissions'] === 'all') {
    $showForm = false;
}
$roles = db_rows('SELECT r.*, (SELECT COUNT(*) FROM admins a WHERE a.role_id = r.id) AS users FROM roles r ORDER BY r.id');

$adminTitle = 'Roles & Permissions';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <p class="muted mb-0">Roles decide which sections each admin can open. Every page and action checks these permissions on the server.</p>
    <?php if (is_super_admin()): ?><div class="actions"><a class="btn btn-primary btn-sm" href="roles.php?action=add"><?= icon('plus') ?>New role</a></div><?php endif; ?>
</div>
<div class="<?= $showForm ? 'admin-grid-main' : '' ?>">
    <div class="panel">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Role</th><th>Permissions</th><th>Users</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($roles as $r): ?>
                    <tr>
                        <td><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e($r['description']) ?></small></td>
                        <td><?php if ($r['permissions'] === 'all'): ?><span class="badge badge-green">Full access</span><?php else:
                                foreach (array_filter(explode(',', $r['permissions'])) as $p): ?><span class="badge badge-gray" style="margin:2px"><?= e(humanize($p)) ?></span><?php endforeach; endif; ?></td>
                        <td><?= (int) $r['users'] ?></td>
                        <td class="actions-cell">
                            <?php if (is_super_admin() && $r['permissions'] !== 'all'): ?>
                                <a class="icon-btn" href="roles.php?edit=<?= (int) $r['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a>
                                <?php if (!$r['is_system'] && !$r['users']): ?>
                                    <form class="inline-form" method="post" action="actions/user-save.php" data-confirm="Delete this role?">
                                        <?= csrf_field() ?><input type="hidden" name="type" value="role"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button class="icon-btn danger" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($showForm): ?>
        <div class="panel">
            <div class="panel-head"><h3><?= $edit ? 'Edit role' : 'New role' ?></h3><a class="btn btn-ghost btn-sm" href="roles.php">Close</a></div>
            <form method="post" action="actions/user-save.php" data-validate>
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="role">
                <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
                <div class="form-group mb-2"><label for="name">Role name<span class="req">*</span></label>
                    <input id="name" type="text" name="name" required maxlength="80" value="<?= e(old('name', $edit['name'] ?? '')) ?>"><?= field_error('name') ?></div>
                <div class="form-group mb-2"><label for="description">Description</label>
                    <input id="description" type="text" name="description" maxlength="255" value="<?= e(old('description', $edit['description'] ?? '')) ?>"></div>
                <span class="label">Permissions</span>
                <?php $current = (array) old('permissions', $edit ? explode(',', $edit['permissions']) : []); ?>
                <div class="perm-grid mt-1">
                    <?php foreach (ADMIN_PERMISSIONS as $key => $label): ?>
                        <label class="checkbox"><input type="checkbox" name="permissions[]" value="<?= e($key) ?>"<?= in_array($key, $current, true) ? ' checked' : '' ?>> <span><strong><?= e(humanize($key)) ?></strong><br><small class="muted"><?= e($label) ?></small></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Save role</button></div>
            </form>
        </div>
    <?php endif; ?>
</div>
<div class="panel">
    <h3>What each permission allows</h3>
    <dl class="detail-list">
        <?php foreach (ADMIN_PERMISSIONS as $key => $label): ?><dt><?= e(humanize($key)) ?></dt><dd><?= e($label) ?></dd><?php endforeach; ?>
    </dl>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
