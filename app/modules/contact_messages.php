<?php
/**
 * Website contact-form messages (inbox). The SPA uses this module for the paginated list + delete/export;
 * read/star/archive/resolve/reply/convert are in api/routes/contact-messages.php.
 */
require_once APP_ROOT . '/app/services/admissions.php';

return [
    'table' => 'contact_messages',
    'title' => 'Contact Messages',
    'singular' => 'Message',
    'permission' => 'contact_messages',
    'permissions' => ['create' => ['contact_messages', 'manage'], 'import' => ['contact_messages', 'manage']],
    'icon' => 'inbox',
    'description' => 'Messages received through the website contact form.',
    'select' => 't.*, u.name AS replied_by_name, e.status AS enquiry_status',
    'joins' => 'LEFT JOIN users u ON u.id = t.replied_by LEFT JOIN enquiries e ON e.id = t.enquiry_id',
    'search' => ['t.name', 't.email', 't.phone', 't.subject', 't.message'],
    'search_placeholder' => 'Search messages…',
    'order' => 't.created_at DESC, t.id DESC',
    'columns' => [
        ['key' => 'name', 'label' => 'From', 'format' => 'person', 'sub' => 'email', 'sortable' => true],
        ['key' => 'subject', 'label' => 'Subject', 'format' => 'title', 'sub' => 'enquiry_type', 'sortable' => true],
        ['key' => 'enquiry_type', 'label' => 'Type', 'format' => 'badge', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => ['new' => 'blue', 'read' => 'slate', 'replied' => 'green', 'closed' => 'purple']],
        ['key' => 'is_starred', 'label' => 'Starred', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'phone', 'label' => 'Phone', 'format' => 'phone', 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Received', 'format' => 'datetime', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'id', 'label' => '#'], ['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'],
        ['key' => 'enquiry_type', 'label' => 'Type', 'format' => 'badge'], ['key' => 'subject', 'label' => 'Subject'], ['key' => 'message', 'label' => 'Message'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'reply', 'label' => 'Reply'], ['key' => 'replied_by_name', 'label' => 'Replied By'],
        ['key' => 'replied_at', 'label' => 'Replied At', 'format' => 'datetime'], ['key' => 'created_at', 'label' => 'Received', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'folder', 'label' => 'Folder', 'options' => ['inbox' => 'Inbox', 'unread' => 'Unread', 'starred' => 'Starred', 'replied' => 'Replied', 'resolved' => 'Resolved', 'archived' => 'Archived'],
            'sql_map' => [
                'inbox' => "t.is_archived = 0 AND t.status <> 'closed'",
                'unread' => "t.is_archived = 0 AND t.status = 'new'",
                'starred' => 't.is_archived = 0 AND t.is_starred = 1',
                'replied' => "t.is_archived = 0 AND t.status = 'replied'",
                'resolved' => "t.is_archived = 0 AND t.status = 'closed'",
                'archived' => 't.is_archived = 1',
            ]],
        ['key' => 'enquiry_type', 'label' => 'Type', 'options' => contact_message_types()],
        ['key' => 'status', 'label' => 'Status', 'options' => ['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'closed' => 'Resolved']],
        ['key' => 'created_at', 'label' => 'Received', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxlength' => 150],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'phone', 'label' => 'Phone', 'type' => 'tel'],
        ['name' => 'enquiry_type', 'label' => 'Type', 'type' => 'select', 'options' => contact_message_types()],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'col' => 12, 'maxlength' => 255],
        ['name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'col' => 12, 'rows' => 5, 'maxlength' => 10000],
    ],
    'bulk' => ['delete' => true, 'status' => ['new', 'read', 'closed']],
    'export' => true,
    'per_page' => 20,
    'hooks' => [
        'transform_row' => function (array $r): array {
            $r['is_starred'] = (int) ($r['is_starred'] ?? 0);
            $r['is_archived'] = (int) ($r['is_archived'] ?? 0);
            $r['preview'] = str_limit(preg_replace('/\s+/', ' ', (string) ($r['message'] ?? '')), 140);
            return $r;
        },
        'describe' => fn (array $row) => 'from ' . ($row['name'] ?? '') . ' <' . ($row['email'] ?? '') . '>',
    ],
];
