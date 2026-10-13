<?php
/** Newsletter / e-mail campaigns (Communication). Sending: api/routes/newsletter.php + message_queue */
require_once APP_ROOT . '/app/services/communication.php';

return [
    'table' => 'email_campaigns',
    'title' => 'Campaigns',
    'singular' => 'Campaign',
    'permission' => 'communication',
    'icon' => 'send',
    'description' => 'Newsletters and bulk e-mail / SMS campaigns.',
    'select' => 't.id, t.title, t.channel, t.subject, t.body_html, t.audience, t.audience_filter, t.template_id, t.status, t.scheduled_at, t.started_at, t.sent_at, t.test_sent_at,
                 t.recipients_count, t.sent_count, t.failed_count, t.created_by, t.created_at, t.updated_at, u.name AS created_by_name,
                 (SELECT COUNT(*) FROM message_queue q WHERE q.campaign_id = t.id AND q.status IN (\'pending\', \'processing\')) AS pending_count',
    'joins' => 'LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['t.title', 't.subject'],
    'order' => "FIELD(t.status, 'sending', 'scheduled', 'draft') DESC, COALESCE(t.sent_at, t.scheduled_at, t.created_at) DESC, t.id DESC",
    'search_placeholder' => 'Search campaigns by title or subject…',
    'scopes' => ['status' => 't.status'],
    'hidden_columns' => ['body_html'],
    'columns' => [
        ['key' => 'title', 'label' => 'Campaign', 'format' => 'title', 'sub' => 'subject', 'sortable' => true, 'link' => 'view'],
        ['key' => 'audience_label', 'label' => 'Audience', 'format' => 'text'],
        ['key' => 'channel', 'label' => 'Channel', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
        ['key' => 'recipients_count', 'label' => 'Delivery', 'format' => 'number', 'sortable' => true],
        ['key' => 'scheduled_at', 'label' => 'Scheduled', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'sent_at', 'label' => 'Sent / scheduled', 'format' => 'datetime', 'sortable' => 'COALESCE(t.sent_at, t.scheduled_at)'],
        ['key' => 'created_by_name', 'label' => 'Created by', 'format' => 'text', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Campaign'], ['key' => 'subject', 'label' => 'Subject'], ['key' => 'channel', 'label' => 'Channel', 'format' => 'badge'],
        ['key' => 'audience_label', 'label' => 'Audience'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'],
        ['key' => 'recipients_count', 'label' => 'Recipients', 'format' => 'number'], ['key' => 'sent_count', 'label' => 'Sent', 'format' => 'number'],
        ['key' => 'failed_count', 'label' => 'Failed', 'format' => 'number'], ['key' => 'scheduled_at', 'label' => 'Scheduled for', 'format' => 'datetime'],
        ['key' => 'sent_at', 'label' => 'Completed at', 'format' => 'datetime'], ['key' => 'created_by_name', 'label' => 'Created by'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed', 'cancelled' => 'Cancelled']],
        ['key' => 'audience', 'label' => 'Audience', 'options' => comm_campaign_audiences()],
        ['key' => 'channel', 'label' => 'Channel', 'options' => ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp']],
        ['key' => 'created_at', 'label' => 'Created', 'type' => 'daterange'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Campaign name', 'type' => 'text', 'required' => true, 'maxlength' => 190, 'col' => 8, 'placeholder' => 'e.g. October newsletter'],
        ['name' => 'channel', 'label' => 'Channel', 'type' => 'select', 'required' => true, 'default' => 'email', 'options' => ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'], 'col' => 4],
        ['name' => 'template_id', 'label' => 'Start from template', 'type' => 'combobox', 'col' => 12,
            'source' => ['table' => 'email_templates et', 'value' => 'et.id', 'label' => 'et.name', 'sub' => 'et.channel', 'where' => "et.status = 'active'", 'order' => 'et.name', 'search' => ['et.name', 'et.code']]],
        ['name' => 'subject', 'label' => 'Subject line', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
        ['name' => 'body_html', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'maxlength' => 500000, 'col' => 12],
        ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'required' => true, 'default' => 'subscribers', 'options' => comm_campaign_audiences(), 'col' => 6],
        ['name' => 'audience_filter', 'label' => 'Audience filter', 'type' => 'json', 'col' => 12, 'hidden' => true],
        ['name' => 'scheduled_at', 'label' => 'Schedule for', 'type' => 'datetime', 'col' => 6, 'hidden' => true],
    ],
    'bulk' => ['delete' => true],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $r['audience_label'] = comm_campaign_audience_label($r);
            foreach (['recipients_count', 'sent_count', 'failed_count', 'pending_count'] as $k) {
                if (isset($r[$k])) {
                    $r[$k] = (int) $r[$k];
                }
            }
            $r['audience_filter'] = comm_campaign_filter($r);
            $r['subject_display'] = $r['subject'] !== null ? comm_render((string) $r['subject'], comm_base_vars(), false) : null;
            return $r;
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            if ($old && in_array($old['status'], ['sending', 'sent'], true)) {
                throw new CrudException('This campaign has already been ' . ($old['status'] === 'sent' ? 'sent' : 'started') . ' and can no longer be edited. Duplicate it to make changes.');
            }
            $channel = $data['channel'] ?? ($old['channel'] ?? 'email');
            if ($channel !== 'email' && !comm_channel_enabled($channel)) {
                $errors['channel'] = ($channel === 'sms' ? 'SMS' : 'WhatsApp') . ' is not enabled under Settings → Email & SMS.';
            }
            $subject = array_key_exists('subject', $data) ? $data['subject'] : ($old['subject'] ?? null);
            if ($channel === 'email' && !$subject) {
                $errors['subject'] = 'Subject line is required for e-mail campaigns.';
            }
            if (array_key_exists('body_html', $data) && trim(comm_plain_text((string) $data['body_html'])) === '' && !preg_match('/<img/i', (string) $data['body_html'])) {
                $errors['body_html'] = 'Write the message before saving.';
            }
            $aud = $data['audience'] ?? ($old['audience'] ?? 'subscribers');
            $filter = array_key_exists('audience_filter', $data) ? (json_decode((string) $data['audience_filter'], true) ?: []) : (json_decode((string) ($old['audience_filter'] ?? ''), true) ?: []);
            if ($aud === 'program' && empty(array_filter((array) ($filter['program_ids'] ?? [])))) {
                $errors['audience_filter'] = 'Select at least one program.';
            }
            if ($aud === 'custom' && !comm_audience_people('custom', ['emails' => $filter['emails'] ?? ''])) {
                $errors['audience_filter'] = 'Enter at least one valid e-mail address.';
            }
            if ($aud === 'subscribers' && $channel !== 'email') {
                $errors['audience'] = 'Newsletter subscribers can only receive e-mail.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $channel = $data['channel'] ?? ($old['channel'] ?? 'email');
            if (array_key_exists('body_html', $data)) {
                $data['body_html'] = $channel === 'email' ? comm_clean_html((string) $data['body_html']) : comm_plain_text((string) $data['body_html']);
            }
            if ($channel !== 'email') {
                $data['subject'] = $data['subject'] ?? null;
            }
            if (array_key_exists('audience_filter', $data) && $data['audience_filter'] !== null) {
                $f = json_decode((string) $data['audience_filter'], true) ?: [];
                $data['audience_filter'] = json_encode(['program_ids' => array_values(array_filter(array_map('intval', (array) ($f['program_ids'] ?? [])))),
                    'semester_no' => !empty($f['semester_no']) ? (int) $f['semester_no'] : null, 'emails' => mb_substr(trim((string) ($f['emails'] ?? '')), 0, 20000)]);
            }
            if ($old && $old['status'] === 'scheduled' && array_key_exists('scheduled_at', $data) && !$data['scheduled_at']) {
                $data['status'] = 'draft';
            }
            if ($old && in_array($old['status'], ['failed', 'cancelled'], true)) {
                $data['status'] = 'draft';
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            return $row['status'] === 'sending' ? 'This campaign is being sent right now. Cancel it before deleting.' : null;
        },
        'after_delete' => function (int $id) {
            db_exec("DELETE FROM message_queue WHERE campaign_id = ? AND status IN ('pending', 'processing', 'cancelled')", [$id]);
        },
        'describe' => fn (array $r) => '"' . str_limit((string) ($r['title'] ?? ''), 80) . '"',
    ],
];
