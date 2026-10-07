<?php
/** Audit trail (System > Activity Logs). Read-only list; purge and detail live in api/routes/activity-logs.php. */
require_once APP_ROOT . '/app/services/system.php';

return [
    'table' => 'activity_logs',
    'title' => 'Activity Logs',
    'singular' => 'Activity',
    'permission' => 'activity_logs',
    'icon' => 'history',
    'description' => 'Who did what, where and when across every module.',
    'readonly' => true,
    'select' => 't.id, t.user_id, t.action, t.module, t.record_id, t.description, t.status, t.ip_address, t.user_agent, t.created_at,
                 (t.meta IS NOT NULL) AS has_meta, u.name AS user_name, u.avatar AS user_avatar, u.email AS user_email',
    'joins' => 'LEFT JOIN users u ON u.id = t.user_id',
    'search' => ['t.description', 't.module', 't.action', 't.ip_address', 't.record_id', 'u.name'],
    'search_placeholder' => 'Search description, user, IP, record…',
    'order' => 't.created_at DESC, t.id DESC',
    'default_sort' => ['key' => 'created_at', 'dir' => 'desc'],
    'per_page' => 25,
    'columns' => [
        ['key' => 'created_at', 'label' => 'Time', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'user_name', 'label' => 'User', 'format' => 'person', 'image' => 'user_avatar', 'sub' => 'user_email', 'sortable' => 'u.name'],
        ['key' => 'action', 'label' => 'Action', 'format' => 'badge', 'sortable' => true],
        ['key' => 'module_label', 'label' => 'Module', 'sortable' => 't.module'],
        ['key' => 'record_id', 'label' => 'Record', 'format' => 'code'],
        ['key' => 'description', 'label' => 'Description', 'format' => 'truncate', 'truncate' => 90],
        ['key' => 'ip_address', 'label' => 'IP address', 'sortable' => true],
        ['key' => 'browser', 'label' => 'Browser', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'created_at', 'label' => 'Date & time', 'format' => 'datetime'], ['key' => 'user_name', 'label' => 'User'], ['key' => 'action', 'label' => 'Action', 'format' => 'badge'],
        ['key' => 'module_label', 'label' => 'Module'], ['key' => 'record_id', 'label' => 'Record'], ['key' => 'description', 'label' => 'Description'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'ip_address', 'label' => 'IP address'], ['key' => 'browser', 'label' => 'Browser'],
    ],
    'filters' => [
        ['key' => 'user_id', 'label' => 'User', 'source' => ['table' => 'users u', 'value' => 'u.id', 'label' => 'u.name', 'sub' => 'u.email', 'order' => 'u.name', 'search' => ['u.name', 'u.email', 'u.username']]],
        ['key' => 'module', 'label' => 'Module', 'source' => ['table' => '(SELECT DISTINCT module FROM activity_logs) m', 'value' => 'm.module', 'label' => 'm.module', 'order' => 'm.module', 'search' => ['m.module']]],
        ['key' => 'action', 'label' => 'Action', 'source' => ['table' => '(SELECT DISTINCT action FROM activity_logs) a', 'value' => 'a.action', 'label' => 'a.action', 'order' => 'a.action', 'search' => ['a.action']]],
        ['key' => 'status', 'label' => 'Status', 'options' => ['success' => 'Success', 'failed' => 'Failed']],
        ['key' => 'created_at', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'bulk' => ['delete' => false],
    'hooks' => [
        'transform_row' => function (array $row): array {
            static $labels = null;
            $labels = $labels ?? activity_module_labels();
            $row['module_label'] = $labels[$row['module']] ?? label_from_key((string) $row['module']);
            $row['browser'] = browser_label($row['user_agent'] ?? '');
            $row['has_meta'] = (bool) ($row['has_meta'] ?? false);
            $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
            unset($row['user_agent']);
            return $row;
        },
    ],
];
