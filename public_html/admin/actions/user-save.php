<?php
/**
 * Saves: own profile, own password, admin users (users permission), roles (super admin only).
 */
require_once dirname(__DIR__) . '/includes/auth.php';

$type = (string) ($_POST['type'] ?? '');
csrf_verify($type === 'profile' || $type === 'password' ? 'admin/profile.php' : 'admin/users.php');

// ---------------------------------------------------------------------
// Own profile
// ---------------------------------------------------------------------
if ($type === 'profile') {
    [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:120', 'phone' => 'phone']);
    if ($errors) {
        back_with_errors($errors, 'admin/profile.php', $_POST);
    }
    db_update('admins', ['name' => $data['name'], 'phone' => $data['phone'] ?: null], 'id = :id', ['id' => admin_id()]);
    admin_log('admin_profile_updated', '');
    flash('success', 'Profile updated.');
    admin_redirect('profile.php');
}

// ---------------------------------------------------------------------
// Own password
// ---------------------------------------------------------------------
if ($type === 'password') {
    [$data, $errors] = validate($_POST, [
        'current_password' => 'required|max:72',
        'password'         => 'required|password|min:10',
        'password_confirm' => 'required|same:password',
    ], ['password' => 'New password', 'password_confirm' => 'Password confirmation', 'current_password' => 'Current password']);
    $hash = (string) db_value('SELECT password_hash FROM admins WHERE id = ?', [admin_id()]);
    if (!$errors && !password_verify($_POST['current_password'], $hash)) {
        $errors['current_password'] = 'Your current password is incorrect.';
    }
    if (!$errors && password_verify($data['password'], $hash)) {
        $errors['password'] = 'The new password must be different from the current one.';
    }
    if ($errors) {
        back_with_errors($errors, 'admin/profile.php');
    }
    db_update('admins', ['password_hash' => password_hash($data['password'], PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = :id', ['id' => admin_id()]);
    session_regenerate_id(true);
    admin_log('admin_password_changed', '');
    flash('success', 'Your password has been changed.');
    admin_redirect('dashboard.php');
}

// ---------------------------------------------------------------------
// Admin users
// ---------------------------------------------------------------------
if ($type === 'admin') {
    require_permission('users');
    $id = (int) ($_POST['id'] ?? 0);
    $back = $id ? 'admin/users.php?edit=' . $id : 'admin/users.php?action=add';
    $existing = $id ? db_row('SELECT * FROM admins WHERE id = ?', [$id]) : null;
    if ($id && !$existing) {
        flash('error', 'Admin user not found.');
        admin_redirect('users.php');
    }

    $rules = [
        'name'    => 'required|min:2|max:120',
        'email'   => 'required|email',
        'phone'   => 'phone',
        'role_id' => 'required|integer',
        'status'  => 'required|in:active,inactive',
    ];
    $rules['password'] = $id ? 'password|min:10' : 'required|password|min:10';
    [$data, $errors] = validate($_POST, $rules, ['role_id' => 'Role', 'password' => 'Password']);

    $role = db_row('SELECT * FROM roles WHERE id = ?', [(int) $data['role_id']]);
    if (!$errors && !$role) {
        $errors['role_id'] = 'Please choose a valid role.';
    }
    // Only a super admin can create or edit super admins
    if (!$errors && !is_super_admin() && ($role['permissions'] === 'all' || ($existing && db_value('SELECT permissions FROM roles WHERE id = ?', [$existing['role_id']]) === 'all'))) {
        $errors['role_id'] = 'Only a super admin can assign or edit the Super Admin role.';
    }
    if (!$errors && db_value('SELECT id FROM admins WHERE email = ? AND id <> ?', [$data['email'], $id])) {
        $errors['email'] = 'Another admin already uses this email.';
    }
    // Never lock out the last active super admin
    if (!$errors && $existing && db_value('SELECT permissions FROM roles WHERE id = ?', [$existing['role_id']]) === 'all'
        && ($data['status'] !== 'active' || $role['permissions'] !== 'all')) {
        $others = (int) db_value("SELECT COUNT(*) FROM admins a JOIN roles r ON r.id = a.role_id WHERE r.permissions = 'all' AND a.status = 'active' AND a.id <> ?", [$id]);
        if ($others === 0) {
            $errors['role_id'] = 'This is the only active super admin. Create another super admin first.';
        }
    }
    if ($id === admin_id() && $data['status'] !== 'active') {
        $errors['status'] = 'You cannot deactivate your own account.';
    }
    if ($errors) {
        back_with_errors($errors, $back, $_POST);
    }

    $row = [
        'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?: null,
        'role_id' => (int) $data['role_id'], 'status' => $data['status'],
    ];
    if ($data['password'] !== '') {
        $row['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
        $row['must_change_password'] = $id === admin_id() ? 0 : 1;
    }
    if ($id) {
        db_update('admins', $row, 'id = :id', ['id' => $id]);
        admin_log('admin_user_updated', 'Admin #' . $id . ' (' . $data['email'] . ') role ' . $role['name'] . ', ' . $data['status']);
        flash('success', 'Admin user updated.');
    } else {
        $id = db_insert('admins', $row);
        admin_log('admin_user_created', 'Admin #' . $id . ' (' . $data['email'] . ') role ' . $role['name']);
        flash('success', 'Admin user created. They will be asked to change the password on first login.');
    }
    admin_redirect('users.php');
}

// ---------------------------------------------------------------------
// Roles & permissions (super admin only)
// ---------------------------------------------------------------------
if ($type === 'role') {
    require_permission('users');
    if (!is_super_admin()) {
        flash('error', 'Only a super admin can change roles.');
        admin_redirect('roles.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $existing = $id ? db_row('SELECT * FROM roles WHERE id = ?', [$id]) : null;
    if ($existing && $existing['permissions'] === 'all') {
        flash('error', 'The Super Admin role always has full access and cannot be edited.');
        admin_redirect('roles.php');
    }
    if (($_POST['action'] ?? '') === 'delete' && $existing) {
        if ($existing['is_system']) {
            flash('error', 'Built-in roles cannot be deleted.');
        } elseif ((int) db_value('SELECT COUNT(*) FROM admins WHERE role_id = ?', [$id]) > 0) {
            flash('error', 'This role is assigned to admin users. Move them to another role first.');
        } else {
            db_query('DELETE FROM roles WHERE id = ?', [$id]);
            admin_log('role_deleted', $existing['name']);
            flash('success', 'Role deleted.');
        }
        admin_redirect('roles.php');
    }

    [$data, $errors] = validate($_POST, [
        'name'        => 'required|min:2|max:80',
        'description' => 'max:255',
        'permissions' => 'array|in:' . implode(',', array_keys(ADMIN_PERMISSIONS)),
    ]);
    if ($errors) {
        back_with_errors($errors, 'admin/roles.php' . ($id ? '?edit=' . $id : '?action=add'), $_POST);
    }
    $row = ['name' => $data['name'], 'description' => $data['description'] ?: null, 'permissions' => implode(',', $data['permissions'])];
    if ($existing) {
        db_update('roles', $row, 'id = :id', ['id' => $id]);
        admin_log('role_updated', $data['name'] . ': ' . $row['permissions']);
    } else {
        $row['slug'] = unique_slug('roles', $data['name']);
        db_insert('roles', $row);
        admin_log('role_created', $data['name'] . ': ' . $row['permissions']);
    }
    flash('success', 'Role saved.');
    admin_redirect('roles.php');
}

admin_redirect('dashboard.php');
