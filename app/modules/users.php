<?php
/** Admin user accounts (System > Users). Roles are synced into user_roles by the hooks below. */
require_once APP_ROOT . '/app/services/system.php';

/** Role ids requested in the input, validated. Returns [ids, error|null]. */
$usersRoleCheck = function (array $input, ?int $id): array {
    if (!array_key_exists('roles', $input)) {
        return [null, null];
    }
    $ids = system_role_ids_from_input($input['roles']);
    if (!$ids) {
        return [[], 'Assign at least one role.'];
    }
    $roles = db_all('SELECT id, name, is_super FROM roles WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    if (count($roles) !== count($ids)) {
        return [$ids, 'One of the selected roles no longer exists.'];
    }
    $current = $id ? array_map('intval', db_column('SELECT role_id FROM user_roles WHERE user_id = ?', [$id])) : [];
    $added = array_diff($ids, $current);
    if (!is_super_admin()) {
        foreach ($roles as $r) {
            if (in_array((int) $r['id'], $added, true)) {
                if ($r['is_super']) {
                    return [$ids, 'Only a Super Admin can assign the Super Admin role.'];
                }
                // Prevent privilege escalation: you can only grant roles whose permissions you hold yourself.
                foreach (role_permission_keys((int) $r['id']) as $k) {
                    [$mod, $act] = explode('.', $k, 2);
                    if (!can($mod, $act)) {
                        return [$ids, "You cannot assign the {$r['name']} role because it includes permissions you do not have ($k)."];
                    }
                }
            }
        }
    }
    return [$ids, null];
};

return [
    'table' => 'users',
    'title' => 'Users',
    'singular' => 'User',
    'permission' => 'users',
    'icon' => 'user-cog',
    'description' => 'Admin panel accounts, their roles and sign-in status.',
    'select' => "t.id, t.name, t.username, t.email, t.phone, t.avatar, t.designation, t.department_id, t.status, t.theme, t.two_factor_enabled,
                 t.failed_attempts, t.locked_until, t.last_failed_login_at, t.last_login_at, t.last_login_ip, t.password_changed_at,
                 t.must_change_password, t.created_by, t.created_at, t.updated_at, d.name AS department_name, uc.name AS created_by_name,
                 (SELECT GROUP_CONCAT(r.name ORDER BY r.is_super DESC, r.id SEPARATOR ', ') FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = t.id) AS role_names,
                 (SELECT GROUP_CONCAT(ur.role_id ORDER BY ur.role_id) FROM user_roles ur WHERE ur.user_id = t.id) AS roles,
                 (SELECT CONCAT('[', GROUP_CONCAT(JSON_OBJECT('id', r.id, 'name', r.name, 'color', r.color, 'is_super', r.is_super) ORDER BY r.is_super DESC, r.id), ']')
                    FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = t.id) AS role_list,
                 (SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = t.id AND r.is_super = 1) AS is_super,
                 IF(t.locked_until IS NOT NULL AND t.locked_until > NOW(), 1, 0) AS is_locked",
    'joins' => 'LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN users uc ON uc.id = t.created_by',
    'search' => ['t.name', 't.username', 't.email', 't.phone', 't.designation', 't.last_login_ip'],
    'search_placeholder' => 'Search name, username, email, phone…',
    'order' => 't.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'User', 'format' => 'person', 'image' => 'avatar', 'sub' => 'email', 'sortable' => true],
        ['key' => 'username', 'label' => 'Username', 'format' => 'code', 'sortable' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'role_names', 'label' => 'Roles', 'format' => 'tags'],
        ['key' => 'designation', 'label' => 'Designation', 'hidden' => true, 'sortable' => true],
        ['key' => 'department_name', 'label' => 'Department', 'hidden' => true, 'sortable' => 'd.name'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'last_login_at', 'label' => 'Last login', 'format' => 'datetime', 'sub' => 'last_login_ip', 'sortable' => true],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'date', 'hidden' => true, 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'name', 'label' => 'Name'], ['key' => 'username', 'label' => 'Username'], ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'],
        ['key' => 'designation', 'label' => 'Designation'], ['key' => 'department_name', 'label' => 'Department'], ['key' => 'role_names', 'label' => 'Roles'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'is_locked', 'label' => 'Locked', 'format' => 'boolean'],
        ['key' => 'last_login_at', 'label' => 'Last login', 'format' => 'datetime'], ['key' => 'last_login_ip', 'label' => 'Last login IP'],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'date'],
    ],
    'filters' => [
        ['key' => 'role', 'label' => 'Role', 'source' => 'roles', 'sql' => 'EXISTS (SELECT 1 FROM user_roles urf WHERE urf.user_id = t.id AND urf.role_id = ?)'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive', 'locked' => 'Locked', 'never' => 'Never signed in', 'must_change' => 'Must change password'],
            'sql_map' => ['active' => "t.status = 'active'", 'inactive' => "t.status = 'inactive'", 'locked' => 't.locked_until > NOW()', 'never' => 't.last_login_at IS NULL',
                'must_change' => 't.must_change_password = 1']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Account details'],
        ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 6],
        ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 60, 'col' => 6, 'rules' => 'min:3|regex:/^[A-Za-z0-9._-]+$/'],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'unique' => true, 'maxlength' => 190, 'col' => 6],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'maxlength' => 30, 'col' => 6],
        ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'maxlength' => 120, 'col' => 6],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'source' => 'departments', 'col' => 6],
        ['name' => 'avatar', 'label' => 'Profile photo', 'type' => 'image', 'folder' => 'avatars', 'col' => 12],
        ['type' => 'section', 'label' => 'Access'],
        ['name' => 'roles', 'label' => 'Roles', 'type' => 'multiselect', 'source' => 'roles', 'required' => true, 'col' => 12],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 6],
        ['name' => 'must_change_password', 'label' => 'Require password change at next sign-in', 'type' => 'toggle', 'default' => true, 'col' => 6],
        ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'col' => 12, 'help' => 'Leave blank when editing to keep the current password.'],
    ],
    'bulk' => ['delete' => true],
    'import' => false,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input) use ($usersRoleCheck): array {
            $errors = [];
            if ($id !== null && !is_super_admin() && system_user_is_super($id)) {
                throw new CrudException('Only a Super Admin can change another Super Admin account.');
            }
            [$roleIds, $roleError] = $usersRoleCheck($input, $id);
            if ($roleError) {
                $errors['roles'] = $roleError;
            }
            $superRoles = system_super_role_ids();
            $losingSuper = $id !== null && $roleIds !== null && system_user_is_super($id) && !array_intersect($roleIds, $superRoles);
            $deactivating = $id !== null && ($data['status'] ?? $old['status'] ?? 'active') === 'inactive' && ($old['status'] ?? '') === 'active';
            if ($id !== null && $id === user_id() && $deactivating) {
                $errors['status'] = 'You cannot deactivate your own account.';
            }
            if (($losingSuper || $deactivating) && $id !== null && system_user_is_super($id) && system_super_admin_count($id) === 0) {
                $errors[$losingSuper ? 'roles' : 'status'] = 'This is the last active Super Admin. Give another user the Super Admin role first.';
            }
            if (isset($data['username']) && $data['username'] !== null && in_array(strtolower((string) $data['username']), ['system', 'root', 'cron'], true)) {
                $errors['username'] = 'This username is reserved.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if (!empty($data['password'])) {
                $data['password_hash'] = $data['password'];
                $data['password_changed_at'] = date('Y-m-d H:i:s');
            }
            unset($data['password'], $data['roles']);
            if (isset($data['username'])) {
                $data['username'] = strtolower((string) $data['username']);
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if (array_key_exists('roles', $input)) {
                $ids = system_role_ids_from_input($input['roles']);
                $before = db_column('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id', [$id]);
                db_exec('DELETE FROM user_roles WHERE user_id = ?', [$id]);
                foreach ($ids as $rid) {
                    db_exec('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $rid]);
                }
                $after = $ids ? db_column('SELECT name FROM roles WHERE id IN (' . implode(',', $ids) . ') ORDER BY id') : [];
                if ($old && $before !== $after) {
                    log_activity('update', 'users', $id, 'Changed roles of ' . ($data['name'] ?? $old['name']) . ': ' . (implode(', ', $before) ?: 'none') . ' → ' . (implode(', ', $after) ?: 'none'),
                        'success', ['before' => $before, 'after' => $after]);
                }
            }
            // Signing other devices out when the password changes or the account is deactivated.
            if ($old && ((!empty($data['password_hash']) && $id !== user_id()) || (($data['status'] ?? null) === 'inactive' && $old['status'] === 'active'))) {
                db_exec("DELETE FROM user_tokens WHERE user_id = ? AND type = 'remember'", [$id]);
            }
            if ($old && !empty($data['password_hash'])) {
                log_activity('password_reset', 'users', $id, 'Password changed for ' . ($data['name'] ?? $old['name']) . ' by an administrator');
            }
        },
        'before_delete' => function (int $id, array $row) {
            if ($id === user_id()) {
                return 'You cannot delete your own account.';
            }
            if (system_user_is_super($id)) {
                if (!is_super_admin()) {
                    return 'Only a Super Admin can delete another Super Admin account.';
                }
                if (system_super_admin_count($id) === 0) {
                    return 'This is the last active Super Admin and cannot be deleted.';
                }
            }
            return null;
        },
        'transform_row' => function (array $row): array {
            $row['role_list'] = json_decode((string) ($row['role_list'] ?? ''), true) ?: [];
            foreach ($row['role_list'] as &$r) {
                $r['id'] = (int) $r['id'];
                $r['is_super'] = (bool) $r['is_super'];
            }
            unset($r);
            $row['is_locked'] = (bool) ($row['is_locked'] ?? false);
            $row['is_super'] = (bool) ($row['is_super'] ?? false);
            $row['must_change_password'] = (bool) ($row['must_change_password'] ?? false);
            $row['is_self'] = function_exists('user_id') && (int) $row['id'] === user_id();
            return $row;
        },
        'describe' => fn (array $row) => '"' . ($row['name'] ?? '') . '" (' . ($row['username'] ?? $row['email'] ?? '') . ')',
    ],
];
