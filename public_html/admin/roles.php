<?php
/**
 * Roles & permissions: list roles with user counts, edit names/descriptions, create custom roles
 * (staff or member type), edit the permission matrix of staff roles (grouped by permission group)
 * and delete custom roles that have no users. The Administrator role always has every permission.
 * Non-administrators cannot edit roles they hold, and cannot add permissions they do not have.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('roles.manage');

$meId = (int) admin_id();
$iAmAdmin = is_administrator($meId);
$myPerms = user_permissions($meId);
$myRoles = user_roles($meId);
$permissions = db_rows('SELECT id, perm_key, label, group_name, sort_order FROM permissions ORDER BY sort_order, id');
$permIds = array_column($permissions, 'id', 'perm_key');
$groups = [];
foreach ($permissions as $perm) {
    $groups[$perm['group_name']][] = $perm;
}
uasort($groups, function ($a, $b) {
    return $a[0]['sort_order'] <=> $b[0]['sort_order'];
});
$rolePerms = function (int $roleId): array {
    return array_column(db_rows('SELECT p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$roleId]), 'perm_key');
};
$editable = function (array $role) use ($iAmAdmin, $myRoles): bool {
    return $role['slug'] !== 'administrator' && ($iAmAdmin || !in_array($role['slug'], $myRoles, true));
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['id'] ?? 0);
    admin_post_only('roles.php' . ($rid ? '?id=' . $rid : ''));

    if ($action === 'create') {
        [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:80', 'description' => 'max:255', 'type' => 'required|in:staff,member'], ['type' => 'Role type']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($data['name'])), '_');
        if (!$errors && ($slug === '' || $slug === 'administrator')) {
            $errors['name'] = 'Please choose a different name.';
        }
        if (!$errors && db_value('SELECT COUNT(*) FROM roles WHERE name = ?', [$data['name']])) {
            $errors['name'] = 'A role with this name already exists.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/roles.php', $_POST);
        }
        $base = mb_substr($slug, 0, 70);
        $slug = $base;
        for ($i = 2; db_value('SELECT COUNT(*) FROM roles WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '_' . $i;
        }
        $isStaffRole = $data['type'] === 'staff';
        $newId = db_insert('roles', [
            'name' => $data['name'], 'slug' => $slug, 'description' => $data['description'] !== '' ? $data['description'] : null,
            'is_staff' => $isStaffRole ? 1 : 0, 'is_system' => 0,
            'allow_self_register' => (!$isStaffRole && !empty($_POST['allow_self_register'])) ? 1 : 0,
            'sort_order' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM roles'),
        ]);
        admin_log('role_created', 'Created ' . $data['type'] . ' role #' . $newId . ' "' . $data['name'] . '" (' . $slug . ')');
        flash('success', 'Role created.' . ($isStaffRole ? ' Now choose its permissions.' : ''));
        admin_redirect('roles.php?id=' . $newId);
    }

    $role = $rid ? db_row('SELECT * FROM roles WHERE id = ?', [$rid]) : null;
    if (!$role) {
        flash('error', 'Role not found.');
        admin_redirect('roles.php');
    }

    if ($action === 'update') {
        if ($role['slug'] === 'administrator' && !$iAmAdmin) {
            flash('error', 'Only administrators can edit the Administrator role.');
            admin_redirect('roles.php');
        }
        if ($role['slug'] !== 'administrator' && !$editable($role)) {
            flash('error', 'You cannot edit a role that you hold yourself. Ask an administrator.');
            admin_redirect('roles.php?id=' . $rid);
        }
        [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:80', 'description' => 'max:255']);
        if (!$errors && db_value('SELECT COUNT(*) FROM roles WHERE name = ? AND id <> ?', [$data['name'], $rid])) {
            $errors['name'] = 'Another role already uses this name.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/roles.php?id=' . $rid, $_POST);
        }
        $fields = ['name' => $data['name'], 'description' => $data['description'] !== '' ? $data['description'] : null];
        if (!(int) $role['is_staff']) {
            $fields['allow_self_register'] = !empty($_POST['allow_self_register']) ? 1 : 0;
        }
        db_update('roles', $fields, 'id = :id', ['id' => $rid]);
        $changes = [];
        if ((int) $role['is_staff'] && $role['slug'] !== 'administrator') {
            $existing = $rolePerms($rid);
            $posted = array_values(array_intersect(array_map('strval', (array) ($_POST['perms'] ?? [])), array_keys($permIds)));
            $allowedAdd = $iAmAdmin ? array_keys($permIds) : $myPerms;
            $final = array_values(array_unique(array_merge(array_intersect($existing, $posted), array_intersect($posted, $allowedAdd))));
            $added = array_diff($final, $existing);
            $removed = array_diff($existing, $final);
            if ($added || $removed) {
                db_query('DELETE FROM role_permissions WHERE role_id = ?', [$rid]);
                foreach ($final as $key) {
                    db_query('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$rid, $permIds[$key]]);
                }
                $changes[] = 'permissions +[' . implode(', ', $added) . '] -[' . implode(', ', $removed) . ']';
            }
        }
        admin_log('role_updated', 'Updated role #' . $rid . ' "' . $data['name'] . '"' . ($changes ? ': ' . implode('; ', $changes) : ''));
        flash('success', 'Role saved.');
        admin_redirect('roles.php?id=' . $rid);
    }

    if ($action === 'delete') {
        $users = (int) db_value('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$rid]);
        if ((int) $role['is_system'] || $users > 0) {
            flash('error', (int) $role['is_system'] ? 'Built-in roles cannot be deleted.' : 'Remove this role from its ' . $users . ' user(s) before deleting it.');
            admin_redirect('roles.php?id=' . $rid);
        }
        db_query('DELETE FROM roles WHERE id = ? AND is_system = 0', [$rid]);
        admin_log('role_deleted', 'Deleted custom role #' . $rid . ' "' . $role['name'] . '"');
        flash('success', 'Role deleted.');
        admin_redirect('roles.php');
    }
    admin_redirect('roles.php');
}

$roles = db_rows('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS users,
                         (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perms
                    FROM roles r ORDER BY r.is_staff DESC, r.sort_order, r.name');
$editId = (int) ($_GET['id'] ?? 0);
$edit = null;
foreach ($roles as $r) {
    if ((int) $r['id'] === $editId) {
        $edit = $r;
    }
}
$staffRoles = array_values(array_filter($roles, function ($r) { return (int) $r['is_staff'] === 1; }));
$matrix = [];
foreach (db_rows('SELECT rp.role_id, p.perm_key FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $row) {
    $matrix[$row['perm_key']][(int) $row['role_id']] = true;
}

admin_page_start('Roles & Permissions', [['label' => 'Users', 'url' => admin_url('users.php'), 'icon' => 'users', 'style' => 'ghost']]);
?>
<div class="admin-grid-main">
    <div>
        <?php if ($edit): $canEdit = $edit['slug'] === 'administrator' ? $iAmAdmin : $editable($edit); $current = $rolePerms((int) $edit['id']); ?>
            <section class="panel" id="edit-role">
                <div class="panel-head"><h2>Edit: <?= e($edit['name']) ?></h2><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('roles.php')) ?>">Close</a></div>
                <?php if (!$canEdit): ?><div class="alert alert-info"><?= icon('lock') ?><div>You cannot edit a role that you hold yourself. Ask an administrator to change it.</div></div><?php endif; ?>
                <form method="post" class="form-grid" style="grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr))">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                    <div class="form-group"><label for="role-name">Name</label><input id="role-name" type="text" name="name" required maxlength="80" value="<?= e(old('name', $edit['name'])) ?>"<?= $canEdit ? '' : ' disabled' ?>><?= field_error('name') ?></div>
                    <div class="form-group"><label for="role-slug">Key</label><input id="role-slug" type="text" value="<?= e($edit['slug']) ?>" disabled></div>
                    <div class="form-group full"><label for="role-desc">Description</label><input id="role-desc" type="text" name="description" maxlength="255" value="<?= e(old('description', (string) $edit['description'])) ?>"<?= $canEdit ? '' : ' disabled' ?>></div>
                    <?php if (!(int) $edit['is_staff']): ?>
                        <div class="form-group full"><label class="checkbox"><input type="checkbox" name="allow_self_register" value="1"<?= (int) $edit['allow_self_register'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>><span>Visitors may choose this account type on the public registration form</span></label></div>
                        <p class="full small muted"><?= icon('info') ?> Member roles do not open the admin panel, so they have no admin permissions.</p>
                    <?php elseif ($edit['slug'] === 'administrator'): ?>
                        <p class="full note-box"><?= icon('lock') ?> The Administrator role always has every permission. This cannot be changed.</p>
                    <?php endif; ?>
                    <?php if ((int) $edit['is_staff']): ?>
                        <div class="full">
                            <?php foreach ($groups as $groupName => $perms): ?>
                                <h3 class="h4 mt-2"><?= e($groupName) ?></h3>
                                <div class="perm-grid" style="grid-template-columns:repeat(auto-fill,minmax(min(100%,240px),1fr))">
                                    <?php foreach ($perms as $perm):
                                        $on = $edit['slug'] === 'administrator' || in_array($perm['perm_key'], $current, true);
                                        $lock = !$canEdit || $edit['slug'] === 'administrator' || (!$iAmAdmin && !$on && !in_array($perm['perm_key'], $myPerms, true)); ?>
                                        <label class="checkbox"><input type="checkbox" name="perms[]" value="<?= e($perm['perm_key']) ?>"<?= $on ? ' checked' : '' ?><?= $lock ? ' disabled' : '' ?>>
                                            <span><?= e($perm['label']) ?><br><small class="muted"><?= e($perm['perm_key']) ?></small></span></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($canEdit): ?><div class="form-actions full"><button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save role</button></div><?php endif; ?>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h2>Roles</h2><span class="small muted"><?= count($roles) ?> roles</span></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Role</th><th>Type</th><th>Users</th><th>Permissions</th><th class="actions-cell">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($roles as $r): ?>
                    <tr<?= $edit && (int) $edit['id'] === (int) $r['id'] ? ' style="background:var(--green-50)"' : '' ?>>
                        <td><a href="<?= e(admin_url('roles.php?id=' . (int) $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a><br><small class="muted"><?= e((string) $r['description']) ?></small></td>
                        <td><span class="badge <?= (int) $r['is_staff'] ? 'badge-blue' : 'badge-gray' ?>"><?= (int) $r['is_staff'] ? 'Staff' : 'Member' ?></span>
                            <?= (int) $r['is_system'] ? '<span class="badge badge-gray">Built-in</span>' : '<span class="badge badge-gold">Custom</span>' ?>
                            <?= (int) $r['allow_self_register'] ? '<span class="badge badge-green">On sign-up form</span>' : '' ?></td>
                        <td><a href="<?= e(admin_url('users.php?role=' . urlencode($r['slug']))) ?>"><?= (int) $r['users'] ?></a></td>
                        <td><?= $r['slug'] === 'administrator' ? 'All ' . icon('lock') : ((int) $r['is_staff'] ? (int) $r['perms'] : '<span class="muted">-</span>') ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('roles.php?id=' . (int) $r['id'])) ?>" aria-label="Edit <?= e($r['name']) ?>" title="Edit"><?= icon('edit') ?></a>
                            <?php if (!(int) $r['is_system'] && !(int) $r['users']): ?>
                                <form class="inline-form" method="post" data-confirm="Delete the role &quot;<?= e($r['name']) ?>&quot;?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($r['name']) ?>" title="Delete"><?= icon('trash') ?></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <div class="panel-head"><h2>Permission matrix (staff roles)</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Permission</th><?php foreach ($staffRoles as $r): ?><th style="text-align:center"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($groups as $groupName => $perms): ?>
                    <tr><th colspan="<?= count($staffRoles) + 1 ?>" style="background:var(--green-50);color:var(--green-800)"><?= e($groupName) ?></th></tr>
                    <?php foreach ($perms as $perm): ?>
                        <tr><td><?= e($perm['label']) ?></td>
                            <?php foreach ($staffRoles as $r): $has = $r['slug'] === 'administrator' || !empty($matrix[$perm['perm_key']][(int) $r['id']]); ?>
                                <td style="text-align:center"><?= $has ? '<span style="color:var(--green-600);display:inline-flex" role="img" aria-label="Granted" title="Granted">' . icon($r['slug'] === 'administrator' ? 'lock' : 'check') . '</span>' : '<span class="muted" role="img" aria-label="Not granted" title="Not granted">-</span>' ?></td>
                            <?php endforeach; ?></tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>
    </div>

    <aside>
        <section class="panel">
            <div class="panel-head"><h2>Create a custom role</h2></div>
            <form method="post" class="form-grid" style="grid-template-columns:1fr">
                <?= csrf_field() ?><input type="hidden" name="action" value="create">
                <div class="form-group"><label for="new-name">Name</label><input id="new-name" type="text" name="name" required maxlength="80" value="<?= e($edit ? '' : old('name')) ?>"><?= $edit ? '' : field_error('name') ?></div>
                <div class="form-group"><label for="new-desc">Description</label><input id="new-desc" type="text" name="description" maxlength="255" value="<?= e($edit ? '' : old('description')) ?>"></div>
                <fieldset class="form-group" style="border:0;padding:0;margin:0;min-width:0">
                    <legend class="label">Type</legend>
                    <label class="checkbox"><input type="radio" name="type" value="staff" checked><span><strong>Staff role</strong> - opens the admin panel with the permissions you choose</span></label>
                    <label class="checkbox"><input type="radio" name="type" value="member"><span><strong>Member role</strong> - a website account type without admin access</span></label>
                </fieldset>
                <label class="checkbox"><input type="checkbox" name="allow_self_register" value="1"><span>Member roles only: offer on the public registration form</span></label>
                <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Create role</button></div>
            </form>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Good practice</h2></div>
            <ul class="health-list">
                <li class="ok"><?= icon('shield') ?><span>Give each person only the permissions they need for their work.</span></li>
                <li class="ok"><?= icon('lock') ?><span>Staff roles are never offered on the public registration form.</span></li>
                <li class="warn"><?= icon('info') ?><span>Built-in roles can be renamed and adjusted but not deleted. Custom roles can be deleted once no user has them.</span></li>
            </ul>
        </section>
    </aside>
</div>
<?php
admin_page_end();
