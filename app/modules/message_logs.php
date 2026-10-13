<?php
/** Outbound message log (every e-mail / SMS / WhatsApp). Read-only list; resend: api/routes/communication.php */
require_once APP_ROOT . '/app/services/communication.php';

return [
    'table' => 'message_logs',
    'title' => 'Message Logs',
    'singular' => 'Message',
    'permission' => 'communication',
    'icon' => 'scroll-text',
    'description' => 'Every e-mail, SMS and WhatsApp message sent by the system.',
    'select' => "t.id, t.channel, t.recipient, t.subject, t.status, t.error, t.related_type, t.related_id, t.campaign_id, t.retry_of, t.sent_at, t.created_at,
                 c.title AS campaign_title, n.title AS notice_title, COALESCE(ev.title, ev2.title) AS event_title,
                 (SELECT MAX(r.status = 'sent') FROM message_logs r WHERE r.retry_of = t.id) AS retried_ok,
                 (SELECT COUNT(*) FROM message_logs r2 WHERE r2.retry_of = t.id) AS retry_count",
    'joins' => "LEFT JOIN email_campaigns c ON c.id = t.campaign_id
                LEFT JOIN notices n ON t.related_type = 'notice' AND n.id = t.related_id
                LEFT JOIN events ev ON t.related_type = 'event' AND ev.id = t.related_id
                LEFT JOIN event_registrations er ON t.related_type = 'event_registration' AND er.id = t.related_id
                LEFT JOIN events ev2 ON ev2.id = er.event_id",
    'search' => ['t.recipient', 't.subject'],
    'order' => 't.created_at DESC, t.id DESC',
    'search_placeholder' => 'Search by recipient or subject…',
    'readonly' => true,
    'scopes' => ['channel' => 't.channel', 'related_type' => 't.related_type', 'related_id' => 't.related_id', 'campaign_id' => 't.campaign_id', 'status' => 't.status'],
    'per_page' => 25,
    'columns' => [
        ['key' => 'recipient', 'label' => 'Recipient', 'format' => 'title', 'sub' => 'subject', 'sortable' => true, 'link' => 'view'],
        ['key' => 'channel', 'label' => 'Channel', 'format' => 'badge', 'sortable' => true],
        ['key' => 'related_label', 'label' => 'Related to', 'format' => 'text', 'sortable' => 't.related_type'],
        ['key' => 'error', 'label' => 'Error', 'format' => 'truncate', 'truncate' => 50, 'hidden' => true],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'sent_at', 'label' => 'Sent at', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'id', 'label' => 'ID'], ['key' => 'channel', 'label' => 'Channel'], ['key' => 'recipient', 'label' => 'Recipient'], ['key' => 'subject', 'label' => 'Subject'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'error', 'label' => 'Error'], ['key' => 'related_label', 'label' => 'Related to'],
        ['key' => 'created_at', 'label' => 'Created', 'format' => 'datetime'], ['key' => 'sent_at', 'label' => 'Sent at', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['sent' => 'Sent', 'failed' => 'Failed', 'queued' => 'Queued']],
        ['key' => 'channel', 'label' => 'Channel', 'options' => ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp']],
        ['key' => 'related_type', 'label' => 'Module', 'options' => comm_related_types()],
        ['key' => 'campaign_id', 'label' => 'Campaign', 'source' => ['table' => 'email_campaigns ec', 'value' => 'ec.id', 'label' => 'ec.title', 'order' => 'ec.id DESC', 'search' => ['ec.title']]],
        ['key' => 'created_at', 'label' => 'Date', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'channel', 'label' => 'Channel', 'type' => 'select', 'options' => ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp']],
        ['name' => 'recipient', 'label' => 'Recipient', 'type' => 'text'],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'col' => 12],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['sent' => 'Sent', 'failed' => 'Failed', 'queued' => 'Queued']],
        ['name' => 'error', 'label' => 'Error', 'type' => 'textarea', 'col' => 12],
        ['name' => 'related_type', 'label' => 'Related to', 'type' => 'text'],
        ['name' => 'sent_at', 'label' => 'Sent at', 'type' => 'datetime'],
    ],
    'bulk' => ['delete' => false],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $types = comm_related_types();
            $label = $r['related_type'] ? ($types[$r['related_type']] ?? label_from_key((string) $r['related_type'])) : 'System';
            if (!empty($r['campaign_title'])) {
                $label .= ' · ' . $r['campaign_title'];
            } elseif (!empty($r['notice_title']) || !empty($r['event_title'])) {
                $label .= ' · ' . ($r['notice_title'] ?: $r['event_title']);
            } elseif ($r['related_id']) {
                $label .= ' #' . $r['related_id'];
            }
            $r['related_label'] = $label;
            $r['retried_ok'] = (bool) ($r['retried_ok'] ?? false);
            $r['retry_count'] = (int) ($r['retry_count'] ?? 0);
            return $r;
        },
    ],
];
