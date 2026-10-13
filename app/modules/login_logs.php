<?php
/** Login activity (System > Security). Read-only, written by app/auth.php. */
return [
    'table' => 'login_logs',
    'title' => 'Login Activity',
    'singular' => 'Login event',
    'permission' => 'security',
    'icon' => 'log-in',
    'description' => 'Every sign-in attempt with its result, IP address and browser.',
    'readonly' => true,
    'select' => 't.*, u.name AS user_name, u.avatar AS user_avatar',
    'joins' => 'LEFT JOIN users u ON u.id = t.user_id',
    'search' => ['t.identifier', 't.ip_address', 't.reason', 'u.name'],
    'search_placeholder' => 'Search user, email, IP or reason…',
    'order' => 't.created_at DESC, t.id DESC',
    'default_sort' => ['key' => 'created_at', 'dir' => 'desc'],
    'columns' => [
        ['key' => 'created_at', 'label' => 'Time', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'user_name', 'label' => 'User', 'format' => 'person', 'image' => 'user_avatar', 'sub' => 'identifier', 'sortable' => 'u.name'],
        ['key' => 'status', 'label' => 'Result', 'format' => 'badge', 'sortable' => true],
        ['key' => 'reason', 'label' => 'Details'],
        ['key' => 'ip_address', 'label' => 'IP address', 'sortable' => true],
        ['key' => 'browser', 'label' => 'Browser / device'],
    ],
    'export_columns' => [
        ['key' => 'created_at', 'label' => 'Date & time', 'format' => 'datetime'], ['key' => 'user_name', 'label' => 'User'], ['key' => 'identifier', 'label' => 'Login used'],
        ['key' => 'status', 'label' => 'Result', 'format' => 'badge'], ['key' => 'reason', 'label' => 'Details'], ['key' => 'ip_address', 'label' => 'IP address'], ['key' => 'browser', 'label' => 'Browser'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Result', 'options' => ['success' => 'Successful', 'failed' => 'Failed', 'locked' => 'Locked account', 'blocked' => 'Blocked', 'logout' => 'Sign-out']],
        ['key' => 'user_id', 'label' => 'User', 'source' => ['table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'sub' => 'u.email', 'order' => 'u.name', 'search' => ['u.name', 'u.email', 'u.username']]],
        ['key' => 'ip_address', 'label' => 'IP address', 'type' => 'text', 'placeholder' => 'IP address'],
        ['key' => 'created_at', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'permissions' => ['export' => ['security', 'view']],
    'bulk' => ['delete' => false],
    'hooks' => [
        'transform_row' => function (array $row): array {
            $row['browser'] = browser_label($row['user_agent'] ?? '');
            unset($row['user_agent']);
            return $row;
        },
    ],
];
