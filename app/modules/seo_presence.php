<?php
/**
 * SEO Management › Presence: digital presence / submission checklist (one row per platform). Statuses are set by
 * people; the automatic hints shown beside them come from GET /api/seo-ai/presence (app/services/seo_presence.php).
 */
require_once APP_ROOT . '/app/services/seo_presence.php';

$statusColors = ['not_started' => 'slate', 'in_progress' => 'amber', 'submitted' => 'blue', 'verified' => 'green', 'not_applicable' => 'slate', 'error' => 'red'];
$subColors = ['not_verified' => 'slate', 'pending' => 'amber', 'verified' => 'green', 'failed' => 'red', 'not_needed' => 'slate',
    'not_connected' => 'slate', 'connected' => 'green', 'error' => 'red', 'not_submitted' => 'slate', 'submitted' => 'blue', 'accepted' => 'green'];
$url = ['max:500', 'regex:/^(https?:\/\/[^\s"\'<>]+|\/[^\s"\'<>]*)$/i'];

return [
    'table' => 'seo_presence',
    'title' => 'Digital presence',
    'singular' => 'Platform',
    'permission' => 'seo',
    'permissions' => ['create' => ['seo', 'edit'], 'edit' => ['seo', 'edit'], 'delete' => ['seo', 'manage'], 'export' => ['seo', 'view'], 'import' => ['seo', 'manage']],
    'icon' => 'globe',
    'description' => 'Search engines, Google and Microsoft tools, AI search, technical files, social profiles and directories — status of each.',
    'select' => 't.*, u.name AS updated_by_name',
    'joins' => 'LEFT JOIN users u ON u.id = t.updated_by',
    'search' => ['t.title', 't.item_key', 't.method', 't.notes', 't.next_action', 't.profile_url'],
    'search_placeholder' => 'Search platforms, actions, notes…',
    'order' => "FIELD(t.category, 'search_engines', 'google', 'microsoft', 'ai', 'technical', 'local_social', 'directories'), t.sort_order, t.title",
    'per_page' => 100,
    'scopes' => ['category' => 't.category'],
    'columns' => [
        ['key' => 'title', 'label' => 'Platform', 'format' => 'title', 'sub' => 'method', 'sortable' => true],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true, 'hidden' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true, 'colors' => $statusColors],
        ['key' => 'verification_status', 'label' => 'Verification', 'format' => 'badge', 'colors' => $subColors],
        ['key' => 'connection_status', 'label' => 'Connection', 'format' => 'badge', 'colors' => $subColors, 'hidden' => true],
        ['key' => 'submission_status', 'label' => 'Submission', 'format' => 'badge', 'colors' => $subColors],
        ['key' => 'last_checked_at', 'label' => 'Last checked', 'format' => 'date', 'sortable' => true],
        ['key' => 'last_submitted_at', 'label' => 'Last submission', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'next_action', 'label' => 'Next action', 'format' => 'truncate'],
        ['key' => 'error_details', 'label' => 'Error', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'profile_url', 'label' => 'Profile URL', 'format' => 'link', 'hidden' => true],
        ['key' => 'platform_url', 'label' => 'Platform link', 'format' => 'link', 'hidden' => true],
        ['key' => 'notes', 'label' => 'Notes', 'format' => 'truncate', 'hidden' => true],
        ['key' => 'next_review_at', 'label' => 'Next review', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'updated_by_name', 'label' => 'Updated by', 'hidden' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => seo_presence_categories()],
        ['key' => 'status', 'label' => 'Status', 'options' => seo_presence_statuses()],
        ['key' => 'attention', 'label' => 'Attention', 'sql_map' => [
            'open' => "t.status NOT IN ('verified', 'not_applicable')",
            'errors' => "(t.status = 'error' OR t.verification_status = 'failed' OR t.connection_status = 'error' OR t.submission_status = 'error' OR t.error_details IS NOT NULL)",
            'review_due' => 't.next_review_at IS NOT NULL AND t.next_review_at <= CURDATE()',
            'never_checked' => "t.last_checked_at IS NULL AND t.status <> 'not_applicable'",
        ], 'options' => ['open' => 'Still to do', 'errors' => 'With problems', 'review_due' => 'Review due', 'never_checked' => 'Never checked']],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Platform', 'type' => 'text', 'required' => true, 'maxlength' => 150, 'col' => 8],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'search_engines', 'options' => seo_presence_categories(), 'col' => 4],
        ['name' => 'item_key', 'label' => 'Key', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 60, 'col' => 4, 'readonly_on_edit' => true,
            'rules' => ['regex:/^[a-z0-9_]{2,60}$/'], 'help' => 'Lower-case letters, numbers and _ (e.g. bing_sitemap).'],
        ['name' => 'platform_url', 'label' => 'Platform link (official)', 'type' => 'text', 'maxlength' => 500, 'col' => 8, 'rules' => $url, 'placeholder' => 'https://…'],
        ['name' => 'section_status', 'label' => 'Status', 'type' => 'section'],
        ['name' => 'status', 'label' => 'Overall status', 'type' => 'select', 'required' => true, 'default' => 'not_started', 'options' => seo_presence_statuses(), 'col' => 6,
            'help' => 'Set “Verified / live” only after checking it in the platform itself.'],
        ['name' => 'method', 'label' => 'Method', 'type' => 'text', 'maxlength' => 60, 'col' => 6, 'placeholder' => 'DNS TXT, HTML tag, import …'],
        ['name' => 'verification_status', 'label' => 'Verification', 'type' => 'select', 'options' => seo_presence_verification_statuses(), 'col' => 4],
        ['name' => 'connection_status', 'label' => 'Connection', 'type' => 'select', 'options' => seo_presence_connection_statuses(), 'col' => 4],
        ['name' => 'submission_status', 'label' => 'Submission', 'type' => 'select', 'options' => seo_presence_submission_statuses(), 'col' => 4],
        ['name' => 'last_checked_at', 'label' => 'Last checked', 'type' => 'date', 'col' => 4],
        ['name' => 'last_submitted_at', 'label' => 'Last submission', 'type' => 'date', 'col' => 4],
        ['name' => 'next_review_at', 'label' => 'Next review', 'type' => 'date', 'col' => 4],
        ['name' => 'section_details', 'label' => 'Details', 'type' => 'section'],
        ['name' => 'profile_url', 'label' => 'Profile / property URL', 'type' => 'text', 'maxlength' => 500, 'col' => 6, 'rules' => $url,
            'help' => 'The institute’s own listing, profile or property link (not the platform home page).'],
        ['name' => 'owner_role', 'label' => 'Owner', 'type' => 'text', 'maxlength' => 60, 'col' => 6, 'placeholder' => 'Marketing, IT, Admissions …'],
        ['name' => 'next_action', 'label' => 'Next action', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 255],
        ['name' => 'error_details', 'label' => 'Error / problem', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500],
        ['name' => 'evidence', 'label' => 'Evidence', 'type' => 'text', 'maxlength' => 500, 'help' => 'Where the proof is, e.g. “GSC › Settings › Ownership verification”, a ticket or a screenshot link.'],
        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 5000],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 500, 'min' => 0, 'max' => 32000, 'col' => 4],
    ],
    'bulk' => ['delete' => false, 'status' => array_keys(seo_presence_statuses()), 'status_field' => 'status'],
    'import' => false,
    'export' => true,
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            foreach (['title', 'method', 'owner_role', 'next_action', 'error_details', 'evidence', 'notes'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && cms_seo_has_markup($data[$k])) {
                    $errors[$k] = 'Use plain text only (no HTML or scripts).';
                }
            }
            if (($data['status'] ?? '') === 'verified' && ($data['verification_status'] ?? null) === 'failed') {
                $errors['status'] = 'Verification failed — the platform cannot be marked verified.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            foreach (['last_checked_at', 'last_submitted_at'] as $k) {
                if (!empty($data[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data[$k])) {
                    $data[$k] .= ' 00:00:00';
                }
            }
            if (($data['status'] ?? null) === 'submitted' && empty($data['last_submitted_at']) && empty($old['last_submitted_at'])) {
                $data['last_submitted_at'] = date('Y-m-d H:i:s');
            }
            $data['updated_by'] = user_id();
            return $data;
        },
        'before_delete' => function (int $id, array $row): void {
            if (in_array($row['item_key'], array_column(seo_presence_checklist(), 'item_key'), true)) {
                throw new CrudException('Checklist rows cannot be deleted. Set the status to “Not applicable” instead.');
            }
        },
        'transform_row' => function (array $row): array {
            foreach (['last_checked_at', 'last_submitted_at'] as $k) {
                $row[$k] = $row[$k] ? substr((string) $row[$k], 0, 10) : null;
            }
            return $row;
        },
        'describe' => fn (array $row): string => (string) ($row['title'] ?? $row['item_key'] ?? 'Platform'),
    ],
];
