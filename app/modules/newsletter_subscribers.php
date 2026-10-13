<?php
/** Newsletter subscribers (Communication). Public sign-up: api/routes/public.php; unsubscribe link: api/routes/newsletter.php */
require_once APP_ROOT . '/app/services/communication.php';

return [
    'table' => 'newsletter_subscribers',
    'title' => 'Subscribers',
    'singular' => 'Subscriber',
    'permission' => 'communication',
    'icon' => 'mail-plus',
    'description' => 'People who receive the newsletter and e-mail campaigns.',
    'select' => 't.id, t.email, t.name, t.status, t.source, t.ip_address, t.subscribed_at, t.unsubscribed_at',
    'search' => ['t.email', 't.name'],
    'order' => 't.subscribed_at DESC, t.id DESC',
    'search_placeholder' => 'Search by e-mail or name…',
    'scopes' => ['status' => 't.status'],
    'hidden_columns' => ['token'],
    'columns' => [
        ['key' => 'email', 'label' => 'Subscriber', 'format' => 'title', 'sub' => 'name', 'sortable' => true, 'link' => 'view'],
        ['key' => 'name', 'label' => 'Name', 'format' => 'text', 'sortable' => true, 'hidden' => true],
        ['key' => 'source', 'label' => 'Source', 'format' => 'badge', 'sortable' => true],
        ['key' => 'subscribed_at', 'label' => 'Subscribed', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'unsubscribed_at', 'label' => 'Unsubscribed', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'ip_address', 'label' => 'IP address', 'format' => 'code', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'email', 'label' => 'email'], ['key' => 'name', 'label' => 'name'], ['key' => 'status', 'label' => 'status'], ['key' => 'source', 'label' => 'source'],
        ['key' => 'subscribed_at', 'label' => 'subscribed_at', 'format' => 'datetime'], ['key' => 'unsubscribed_at', 'label' => 'unsubscribed_at', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['subscribed' => 'Subscribed', 'unsubscribed' => 'Unsubscribed', 'bounced' => 'Bounced']],
        ['key' => 'source', 'label' => 'Source', 'options' => comm_subscriber_sources()],
        ['key' => 'subscribed_at', 'label' => 'Subscribed', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'unique' => true, 'maxlength' => 190, 'col' => 6, 'placeholder' => 'name@example.com'],
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'maxlength' => 150, 'col' => 6],
        ['name' => 'source', 'label' => 'Source', 'type' => 'select', 'default' => 'admin', 'options' => comm_subscriber_sources(), 'col' => 6],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'subscribed', 'options' => ['subscribed' => 'Subscribed', 'unsubscribed' => 'Unsubscribed', 'bounced' => 'Bounced'], 'col' => 6],
    ],
    'bulk' => ['delete' => true, 'status' => ['subscribed', 'unsubscribed', 'bounced']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $now = date('Y-m-d H:i:s');
            if ($id === null) {
                $data['token'] = bin2hex(random_bytes(16));
                $data['subscribed_at'] = $now;
                $importing = function_exists('api_path') && PHP_SAPI !== 'cli' && str_ends_with(api_path(), '/import');
                $data['source'] = ($data['source'] ?? null) ?: ($importing ? 'import' : 'admin');
                $data['status'] = $data['status'] ?? 'subscribed';
            }
            $status = $data['status'] ?? ($old['status'] ?? 'subscribed');
            if ($old && $status !== $old['status']) {
                if ($status === 'subscribed') {
                    $data['unsubscribed_at'] = null;
                    $data['subscribed_at'] = $now;
                } else {
                    $data['unsubscribed_at'] = $now;
                }
            } elseif ($id === null && $status !== 'subscribed') {
                $data['unsubscribed_at'] = $now;
            }
            return $data;
        },
        'after_bulk' => function (array $ids, string $field, $value) {
            $in = implode(',', array_map('intval', $ids));
            if ($value === 'subscribed') {
                db_exec("UPDATE newsletter_subscribers SET unsubscribed_at = NULL WHERE id IN ($in)");
            } else {
                db_exec("UPDATE newsletter_subscribers SET unsubscribed_at = COALESCE(unsubscribed_at, ?) WHERE id IN ($in)", [date('Y-m-d H:i:s')]);
            }
        },
        'describe' => fn (array $r) => (string) ($r['email'] ?? ''),
    ],
];
