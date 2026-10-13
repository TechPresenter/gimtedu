<?php
/** Blocked IP addresses (System > Security). Checked on every login by is_ip_blocked(). */
return [
    'table' => 'blocked_ips',
    'title' => 'Blocked IPs',
    'singular' => 'Blocked IP',
    'permission' => 'security',
    'icon' => 'ban',
    'description' => 'Addresses that are refused at sign-in.',
    'select' => "t.*, u.name AS created_by_name,
                 (SELECT COUNT(*) FROM login_logs l WHERE l.ip_address = t.ip_address AND l.status IN ('failed','blocked','locked')) AS failed_attempts,
                 IF(t.expires_at IS NULL OR t.expires_at > NOW(), 'active', 'expired') AS block_status",
    'joins' => 'LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['t.ip_address', 't.reason'],
    'search_placeholder' => 'Search IP or reason…',
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'ip_address', 'label' => 'IP address', 'format' => 'code', 'sortable' => true],
        ['key' => 'reason', 'label' => 'Reason', 'format' => 'truncate', 'truncate' => 70],
        ['key' => 'block_status', 'label' => 'Status', 'format' => 'badge', 'colors' => ['active' => 'red', 'expired' => 'slate']],
        ['key' => 'expires_at', 'label' => 'Expires', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'failed_attempts', 'label' => 'Failed attempts', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'created_by_name', 'label' => 'Blocked by'],
        ['key' => 'created_at', 'label' => 'Blocked on', 'format' => 'datetime', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'block_status', 'label' => 'Status', 'options' => ['active' => 'Active', 'expired' => 'Expired'],
            'sql_map' => ['active' => '(t.expires_at IS NULL OR t.expires_at > NOW())', 'expired' => 't.expires_at <= NOW()']],
    ],
    'fields' => [
        ['name' => 'ip_address', 'label' => 'IP address', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 45, 'col' => 6, 'placeholder' => '203.0.113.25'],
        ['name' => 'expires_at', 'label' => 'Block until', 'type' => 'datetime', 'col' => 6, 'help' => 'Leave empty to block permanently.'],
        ['name' => 'reason', 'label' => 'Reason', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'placeholder' => 'e.g. Repeated failed logins against multiple accounts'],
    ],
    'permissions' => ['create' => ['security', 'edit'], 'edit' => ['security', 'edit'], 'delete' => ['security', 'edit'], 'export' => ['security', 'view']],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'md'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            $ip = trim((string) ($data['ip_address'] ?? ''));
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors['ip_address'] = 'Enter a valid IPv4 or IPv6 address.';
            } elseif ($ip !== '' && $ip === client_ip()) {
                $errors['ip_address'] = 'This is your own IP address — blocking it would lock you out.';
            }
            if (!empty($data['expires_at']) && strtotime((string) $data['expires_at']) <= time()) {
                $errors['expires_at'] = 'Choose a date and time in the future.';
            }
            return $errors;
        },
        'after_save' => function (int $id, array $data, ?array $old, array $input): void {
            if ($old === null && !empty($data['ip_address'])) {
                notify('perm:security', 'security', 'IP address blocked', $data['ip_address'] . ' was blocked' . (!empty($data['reason']) ? ': ' . $data['reason'] : '.'), 'admin/security?tab=blocked', 'ban');
            }
        },
        'describe' => fn (array $row) => (string) ($row['ip_address'] ?? ''),
        'transform_row' => function (array $row): array {
            $row['failed_attempts'] = (int) ($row['failed_attempts'] ?? 0);
            return $row;
        },
    ],
];
