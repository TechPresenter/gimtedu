<?php
/** E-mail / SMS / WhatsApp message templates (Communication). Preview + test send: api/routes/communication.php */
require_once APP_ROOT . '/app/services/communication.php';

$system = "'" . implode("','", comm_system_template_codes()) . "'";

return [
    'table' => 'email_templates',
    'title' => 'Message Templates',
    'singular' => 'Template',
    'permission' => 'communication',
    'icon' => 'file-text',
    'description' => 'Reusable e-mail, SMS and WhatsApp templates with {{placeholders}}.',
    'select' => "t.*, (t.code IN ($system)) AS is_system,
                 (SELECT COUNT(*) FROM email_campaigns c WHERE c.template_id = t.id) AS campaigns_count",
    'search' => ['t.name', 't.code', 't.subject'],
    'order' => 't.channel ASC, t.name ASC',
    'search_placeholder' => 'Search templates by name, code or subject…',
    'scopes' => ['channel' => 't.channel'],
    'columns' => [
        ['key' => 'name', 'label' => 'Template', 'format' => 'title', 'sub' => 'code', 'sortable' => true, 'link' => 'view'],
        ['key' => 'channel', 'label' => 'Channel', 'format' => 'badge', 'sortable' => true],
        ['key' => 'subject', 'label' => 'Subject', 'format' => 'truncate', 'truncate' => 60, 'sortable' => true],
        ['key' => 'variables', 'label' => 'Placeholders', 'format' => 'tags'],
        ['key' => 'updated_at', 'label' => 'Last updated', 'format' => 'datetime', 'sortable' => 'COALESCE(t.updated_at, t.created_at)', 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'export_columns' => [
        ['key' => 'code', 'label' => 'Code'], ['key' => 'name', 'label' => 'Name'], ['key' => 'channel', 'label' => 'Channel'], ['key' => 'subject', 'label' => 'Subject'],
        ['key' => 'variables', 'label' => 'Placeholders'], ['key' => 'status', 'label' => 'Status', 'format' => 'badge'], ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime'],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'kind', 'label' => 'Type', 'sql_map' => ['system' => "t.code IN ($system)", 'custom' => "t.code NOT IN ($system)"], 'options' => ['system' => 'System templates', 'custom' => 'Custom templates']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Template name', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 6, 'placeholder' => 'Fee reminder'],
        ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 60, 'rules' => 'alpha_dash', 'readonly_on_edit' => true, 'col' => 3,
            'help' => 'Used by the system to find the template. Cannot be changed later.'],
        ['name' => 'channel', 'label' => 'Channel', 'type' => 'select', 'required' => true, 'default' => 'email', 'options' => ['email' => 'E-mail', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp'], 'col' => 3],
        ['name' => 'subject', 'label' => 'Subject', 'type' => 'text', 'maxlength' => 255, 'col' => 12],
        ['name' => 'body_html', 'label' => 'Body', 'type' => 'textarea', 'required' => true, 'maxlength' => 200000, 'col' => 12],
        ['name' => 'variables', 'label' => 'Placeholders', 'type' => 'text', 'maxlength' => 500, 'col' => 8, 'help' => 'Comma separated. Detected automatically from the subject and body.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 4],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'transform_row' => function (array $r): array {
            $r['is_system'] = (bool) ($r['is_system'] ?? false);
            return $r;
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $channel = $data['channel'] ?? ($old['channel'] ?? 'email');
            $subject = array_key_exists('subject', $data) ? $data['subject'] : ($old['subject'] ?? null);
            if ($channel === 'email' && !$subject) {
                $errors['subject'] = 'Subject is required for e-mail templates.';
            }
            if (!empty($data['code'])) {
                $data['code'] = strtolower((string) $data['code']);
            }
            if (array_key_exists('body_html', $data) && trim(comm_plain_text((string) $data['body_html'])) === '' && !preg_match('/<img/i', (string) $data['body_html'])) {
                $errors['body_html'] = 'Write the template body.';
            }
            if ($channel !== 'email' && array_key_exists('body_html', $data) && mb_strlen(comm_plain_text((string) $data['body_html'])) > 1000) {
                $errors['body_html'] = 'SMS / WhatsApp templates must be under 1,000 characters.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $channel = $data['channel'] ?? ($old['channel'] ?? 'email');
            if (!empty($data['code'])) {
                $data['code'] = strtolower((string) $data['code']);
            }
            if (array_key_exists('body_html', $data) || array_key_exists('channel', $data)) {
                $body = (string) ($data['body_html'] ?? $old['body_html'] ?? '');
                $data['body_html'] = $channel === 'email' ? comm_clean_html($body) : comm_plain_text($body);
            }
            if ($channel !== 'email' && array_key_exists('subject', $data) && !$data['subject']) {
                $data['subject'] = $data['name'] ?? ($old['name'] ?? 'Message');
            }
            $declared = array_filter(array_map('trim', explode(',', (string) ($data['variables'] ?? $old['variables'] ?? ''))));
            $detected = comm_placeholders_in((string) ($data['subject'] ?? $old['subject'] ?? ''), (string) ($data['body_html'] ?? $old['body_html'] ?? ''));
            $base = ['institute_name', 'site_url', 'year'];
            $vars = array_values(array_unique(array_merge(array_values(array_diff($detected, $base)), $declared)));
            $data['variables'] = $vars ? mb_substr(implode(',', $vars), 0, 500) : null;
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            if (in_array($row['code'], comm_system_template_codes(), true)) {
                return '"' . $row['name'] . '" is a system template used by the ERP (' . $row['code'] . '). Deactivate it instead of deleting.';
            }
            return null;
        },
        'after_delete' => function (int $id) {
            db_exec('UPDATE email_campaigns SET template_id = NULL WHERE template_id = ?', [$id]);
        },
        'describe' => fn (array $r) => '"' . ($r['name'] ?? '') . '" (' . ($r['code'] ?? '') . ')',
    ],
];
