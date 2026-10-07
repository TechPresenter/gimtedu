<?php
/** Roles (System > Roles & Permissions). Permissions themselves are edited through /api/roles/{id}/permissions. */
require_once APP_ROOT . '/app/services/system.php';

return [
    'table' => 'roles',
    'title' => 'Roles',
    'singular' => 'Role',
    'permission' => 'roles',
    'icon' => 'shield',
    'description' => 'Groups of permissions assigned to users.',
    'select' => "t.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = t.id) AS users_count,
                 (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = t.id) AS permissions_count",
    'search' => ['t.name', 't.slug', 't.description'],
    'order' => 't.is_super DESC, t.id ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Role', 'format' => 'title', 'sub' => 'description', 'sortable' => true],
        ['key' => 'slug', 'label' => 'Key', 'format' => 'code', 'sortable' => true],
        ['key' => 'users_count', 'label' => 'Users', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'permissions_count', 'label' => 'Permissions', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'is_system', 'label' => 'Built-in', 'format' => 'boolean', 'align' => 'center'],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'date', 'hidden' => true, 'sortable' => true],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Role name', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 80, 'col' => 8, 'placeholder' => 'e.g. Front Office Executive'],
        ['name' => 'color', 'label' => 'Badge colour', 'type' => 'select', 'default' => 'blue', 'col' => 4,
            'options' => ['blue' => 'Blue', 'navy' => 'Navy', 'green' => 'Green', 'cyan' => 'Cyan', 'purple' => 'Purple', 'amber' => 'Amber', 'orange' => 'Orange', 'pink' => 'Pink', 'red' => 'Red', 'slate' => 'Slate']],
        ['name' => 'slug', 'label' => 'Role key', 'type' => 'slug', 'slug_from' => 'name', 'unique' => true, 'maxlength' => 80, 'col' => 12, 'help' => 'Lower-case identifier, generated from the name.'],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 255, 'rows' => 2, 'col' => 12],
        ['name' => 'copy_from', 'label' => 'Start with the permissions of', 'type' => 'select', 'create_only' => true, 'col' => 12,
            'source' => ['table' => 'roles r', 'value' => 'r.id', 'label' => 'r.name', 'where' => 'r.is_super = 0', 'order' => 'r.id', 'search' => ['r.name']],
            'help' => 'Optional. You can fine-tune every permission in the matrix afterwards.'],
    ],
    'bulk' => ['delete' => false],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($old && !empty($old['is_super'])) {
                throw new CrudException('The Super Admin role is locked and cannot be changed.');
            }
            $errors = [];
            if (!empty($input['copy_from'])) {
                $src = db_row('SELECT * FROM roles WHERE id = ?', [(int) $input['copy_from']]);
                if (!$src) {
                    $errors['copy_from'] = 'The role to copy permissions from no longer exists.';
                } elseif ($src['is_super']) {
                    $errors['copy_from'] = 'Super Admin permissions cannot be copied — it bypasses all checks.';
                } elseif (!is_super_admin()) {
                    foreach (role_permission_keys((int) $src['id']) as $k) {
                        [$mod, $act] = explode('.', $k, 2);
                        if (!can($mod, $act)) {
                            $errors['copy_from'] = "You cannot copy {$src['name']} because it includes permissions you do not have ($k).";
                            break;
                        }
                    }
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if (empty($data['slug']) && !empty($data['name'])) {
                $data['slug'] = slugify((string) $data['name']);
            }
            if ($id === null) {
                $data['is_system'] = 0;
                $data['is_super'] = 0;
                $slug = $data['slug'];
                for ($i = 2; db_value('SELECT COUNT(*) FROM roles WHERE slug = ?', [$data['slug']]); $i++) {
                    $data['slug'] = $slug . '-' . $i;
                }
            }
            return $data;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if ($old === null && !empty($input['copy_from'])) {
                db_exec('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT ?, permission_id FROM role_permissions WHERE role_id = ?', [$id, (int) $input['copy_from']]);
                $src = db_value('SELECT name FROM roles WHERE id = ?', [(int) $input['copy_from']]);
                log_activity('update', 'roles', $id, 'Copied permissions from ' . $src . ' to new role ' . ($data['name'] ?? ''));
            }
        },
        'before_delete' => function (int $id, array $row) {
            if (!empty($row['is_super'])) {
                return 'The Super Admin role cannot be deleted.';
            }
            if (!empty($row['is_system'])) {
                return 'Built-in roles cannot be deleted. Edit their permissions instead.';
            }
            $users = (int) db_value('SELECT COUNT(*) FROM user_roles WHERE role_id = ?', [$id]);
            if ($users) {
                return "$users user(s) still have the {$row['name']} role. Assign them another role first.";
            }
            return null;
        },
        'transform_row' => function (array $row): array {
            foreach (['users_count', 'permissions_count'] as $k) {
                $row[$k] = (int) ($row[$k] ?? 0);
            }
            $row['is_system'] = (bool) $row['is_system'];
            $row['is_super'] = (bool) $row['is_super'];
            return $row;
        },
    ],
];
