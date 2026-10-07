<?php
/**
 * Roles & permission matrix (CRUD of the role itself is /api/crud/roles).
 *   GET  /api/roles/matrix                      registry groups x actions, action meanings, every role with its permission keys
 *   PUT  /api/roles/{id}/permissions            {permissions: ['students.view', ...]}  atomic replace
 *   POST /api/roles/{id}/duplicate              {name}
 *   GET  /api/roles/{id}/print                  printable permission matrix
 */
require_once APP_ROOT . '/app/services/system.php';

route('GET', '/roles/matrix', function () {
    api_require('roles', 'view');
    $roles = db_all('SELECT r.id, r.name, r.slug, r.description, r.color, r.is_system, r.is_super, r.created_at,
                            (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS users_count
                     FROM roles r ORDER BY r.is_super DESC, r.id');
    $grants = [];
    foreach (db_all("SELECT rp.role_id, CONCAT(p.module, '.', p.action) k FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id") as $g) {
        $grants[(int) $g['role_id']][] = $g['k'];
    }
    $members = [];
    foreach (db_all("SELECT ur.role_id, u.id, u.name, u.avatar FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE u.status = 'active' ORDER BY u.name") as $mbr) {
        if (count($members[(int) $mbr['role_id']] ?? []) < 5) {
            $members[(int) $mbr['role_id']][] = ['id' => (int) $mbr['id'], 'name' => $mbr['name'], 'avatar' => $mbr['avatar']];
        }
    }
    foreach ($roles as &$r) {
        $r['id'] = (int) $r['id'];
        $r['is_system'] = (bool) $r['is_system'];
        $r['is_super'] = (bool) $r['is_super'];
        $r['users_count'] = (int) $r['users_count'];
        $r['permissions'] = $grants[$r['id']] ?? [];
        $r['members'] = $members[$r['id']] ?? [];
    }
    unset($r);
    $total = 0;
    foreach (permission_modules() as $def) {
        $total += count($def['actions']);
    }
    api_ok(['groups' => permission_matrix_groups(), 'actions' => permission_action_meta(), 'roles' => $roles, 'total_permissions' => $total,
        'can' => ['create' => can('roles', 'create'), 'edit' => can('roles', 'edit'), 'delete' => can('roles', 'delete')]]);
});

route('PUT', '/roles/{id:\d+}/permissions', function ($p) {
    api_require('roles', 'edit');
    $role = db_row('SELECT * FROM roles WHERE id = ?', [(int) $p['id']]);
    if (!$role) {
        api_error('Role not found. It may have been deleted.', 404);
    }
    if ($role['is_super']) {
        api_error('The Super Admin role is locked: it always has every permission.', 422);
    }
    $keys = api_input()['permissions'] ?? null;
    if (!is_array($keys)) {
        api_error('Send the full list of permissions for this role.', 422, ['permissions' => 'Permissions must be a list.']);
    }
    $keys = array_values(array_unique(array_filter($keys, 'is_string')));
    $before = role_permission_keys((int) $role['id']);
    if (!is_super_admin()) {
        $denied = [];
        foreach (array_diff($keys, $before) as $k) {
            [$mod, $act] = array_pad(explode('.', $k, 2), 2, '');
            if (!can($mod, $act)) {
                $denied[] = $k;
            }
        }
        if ($denied) {
            api_error('You cannot grant permissions you do not have yourself: ' . implode(', ', array_slice($denied, 0, 6)) . (count($denied) > 6 ? '…' : '') . '.', 403);
        }
    }
    [$added, $removed] = role_save_permissions((int) $role['id'], $keys);
    if ($added || $removed) {
        log_activity('update', 'roles', $role['id'], sprintf('Updated permissions of role %s (+%d / −%d)', $role['name'], count($added), count($removed)), 'success',
            ['added' => $added, 'removed' => $removed]);
        $affected = db_column("SELECT ur.user_id FROM user_roles ur JOIN users u ON u.id = ur.user_id WHERE ur.role_id = ? AND u.status = 'active' AND u.id <> ?", [$role['id'], user_id()]);
        if ($affected) {
            notify(array_map('intval', $affected), 'system', 'Your access was updated', "Permissions of the {$role['name']} role were changed. Reload the page to see the new menu.", null, 'shield-check');
        }
    }
    $msg = ($added || $removed) ? sprintf('Permissions saved for %s: %d added, %d removed.', $role['name'], count($added), count($removed)) : 'No changes to save.';
    api_ok(['permissions' => role_permission_keys((int) $role['id']), 'added' => count($added), 'removed' => count($removed)], $msg);
});

route('POST', '/roles/{id:\d+}/duplicate', function ($p) {
    api_require('roles', 'create');
    $src = db_row('SELECT * FROM roles WHERE id = ?', [(int) $p['id']]);
    if (!$src) {
        api_error('Role not found.', 404);
    }
    if ($src['is_super']) {
        api_error('The Super Admin role cannot be duplicated.', 422);
    }
    $in = api_validate(['name' => 'required|max:80|unique:roles,name'], null, ['name' => 'Role name']);
    if (!is_super_admin()) {
        foreach (role_permission_keys((int) $src['id']) as $k) {
            [$mod, $act] = explode('.', $k, 2);
            if (!can($mod, $act)) {
                api_error("You cannot duplicate {$src['name']} because it includes permissions you do not have ($k).", 403);
            }
        }
    }
    $slug = slugify($in['name']);
    for ($i = 2, $base = $slug; db_value('SELECT COUNT(*) FROM roles WHERE slug = ?', [$slug]); $i++) {
        $slug = $base . '-' . $i;
    }
    $id = db_transaction(function () use ($src, $in, $slug) {
        $id = db_insert('roles', ['name' => $in['name'], 'slug' => $slug, 'description' => mb_substr('Copy of ' . $src['name'] . ($src['description'] ? ' — ' . $src['description'] : ''), 0, 255),
            'is_system' => 0, 'is_super' => 0, 'color' => $src['color']]);
        db_exec('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, permission_id FROM role_permissions WHERE role_id = ?', [$id, $src['id']]);
        return $id;
    });
    log_activity('create', 'roles', $id, "Created role {$in['name']} as a copy of {$src['name']}");
    api_ok(['id' => $id], "Role {$in['name']} created with the permissions of {$src['name']}.", 201);
});

route('GET', '/roles/{id:\d+}/print', function ($p) {
    api_require('roles', 'view');
    $role = db_row('SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) users_count FROM roles r WHERE r.id = ?', [(int) $p['id']]);
    if (!$role) {
        api_error('Role not found.', 404);
    }
    system_print_role_matrix($role);
    exit;
});
