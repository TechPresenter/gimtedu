<?php
/** Website CMS › Announcements: top bar messages, news ticker, popups and alert banners (docs/CMS.md §5). */
require_once APP_ROOT . '/app/services/cms.php';

$liveState = "CASE WHEN t.status <> 'active' THEN 'inactive'
                   WHEN t.starts_at IS NOT NULL AND t.starts_at > NOW() THEN 'scheduled'
                   WHEN t.ends_at IS NOT NULL AND t.ends_at < NOW() THEN 'expired'
                   ELSE 'live' END";

return [
    'table' => 'announcements',
    'title' => 'Announcements',
    'singular' => 'Announcement',
    'permission' => 'cms',
    'icon' => 'megaphone',
    'description' => 'Top bar messages, news ticker items, popups and alert banners.',
    'select' => "t.*, $liveState AS live_state",
    'search' => ['t.title', 't.content', 't.link_text'],
    'order' => "FIELD(t.type, 'topbar', 'ticker', 'popup', 'banner'), t.sort_order, t.id DESC",
    'columns' => [
        ['key' => 'title', 'label' => 'Announcement', 'format' => 'title', 'sub' => 'link', 'sortable' => true, 'link' => 'view'],
        ['key' => 'type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true, 'colors' => ['topbar' => 'navy', 'ticker' => 'blue', 'popup' => 'purple', 'banner' => 'amber']],
        ['key' => 'starts_at', 'label' => 'Starts', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'ends_at', 'label' => 'Ends', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'live_state', 'label' => 'Schedule', 'format' => 'badge', 'sortable' => 'live_state',
            'colors' => ['live' => 'green', 'scheduled' => 'amber', 'expired' => 'slate', 'inactive' => 'red']],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'type', 'label' => 'Type', 'options' => cms_announcement_types()],
        ['key' => 'live_state', 'label' => 'Schedule', 'sql_map' => [
            'live' => "t.status = 'active' AND (t.starts_at IS NULL OR t.starts_at <= NOW()) AND (t.ends_at IS NULL OR t.ends_at >= NOW())",
            'scheduled' => "t.status = 'active' AND t.starts_at > NOW()",
            'expired' => "t.status = 'active' AND t.ends_at < NOW()",
            'inactive' => "t.status <> 'active'",
        ], 'options' => ['live' => 'Live now', 'scheduled' => 'Scheduled', 'expired' => 'Expired', 'inactive' => 'Inactive']],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'ticker', 'col' => 6, 'options' => cms_announcement_types(),
            'help' => 'Top bar: rotating message in the navy bar · Ticker: scrolling news strip · Popup: shown once per visit · Alert banner: bar above the header.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 6, 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['name' => 'title', 'label' => 'Message', 'type' => 'text', 'required' => true, 'maxlength' => 255, 'placeholder' => 'e.g. Admissions open for 2026-27 — apply before 31 July'],
        ['name' => 'content', 'label' => 'Details', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 500, 'help' => 'Longer text, shown in popups.'],
        ['name' => 'link', 'label' => 'Link', 'type' => 'text', 'maxlength' => 255, 'col' => 6, 'placeholder' => '/admissions or https://…'],
        ['name' => 'link_text', 'label' => 'Link text', 'type' => 'text', 'maxlength' => 60, 'col' => 6, 'placeholder' => 'Apply now'],
        ['name' => 'starts_at', 'label' => 'Show from', 'type' => 'datetime', 'col' => 4, 'help' => 'Empty = immediately.'],
        ['name' => 'ends_at', 'label' => 'Show until', 'type' => 'datetime', 'col' => 4, 'help' => 'Empty = no end date.'],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 9999, 'col' => 4],
    ],
    'scopes' => ['type' => 't.type'],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'export' => true,
    'import' => true,
    'permissions' => ['export' => ['cms', 'view'], 'import' => ['cms', 'create']],
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $v = fn (string $k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            if ($v('link') && !cms_valid_url((string) $v('link'))) {
                $errors['link'] = 'Enter a valid link, e.g. /admissions or https://example.com.';
            }
            if ($v('link_text') && !$v('link')) {
                $errors['link'] = 'Add the link for this text.';
            }
            if ($v('starts_at') && $v('ends_at') && strtotime((string) $v('ends_at')) <= strtotime((string) $v('starts_at'))) {
                $errors['ends_at'] = 'The end date must be after the start date.';
            }
            return $errors;
        },
    ],
];
