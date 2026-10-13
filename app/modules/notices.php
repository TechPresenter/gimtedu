<?php
/** Notices & circulars (Communication). Publishing + delivery: api/routes/notices.php, app/services/communication.php */
require_once APP_ROOT . '/app/services/communication.php';

$state = comm_notice_state_sql('t');
$semesters = [];
for ($i = 1; $i <= 10; $i++) {
    $semesters[$i] = 'Semester ' . $i;
}

return [
    'table' => 'notices',
    'title' => 'Notices & Circulars',
    'singular' => 'Notice',
    'permission' => 'notices',
    'icon' => 'megaphone',
    'description' => 'Notices and circulars for students, faculty, staff, parents and alumni.',
    'select' => "t.id, t.title, t.slug, t.category, t.audience, t.description, t.department_id, t.program_id, t.semester_no, t.attachment, t.publish_date, t.expiry_date, t.is_pinned,
                 t.show_on_website, t.send_email, t.send_sms, t.send_whatsapp, t.views, t.status, t.created_by, t.created_at, t.updated_at,
                 $state AS state, d.name AS department_name, p.short_name AS program_short, u.name AS created_by_name,
                 (SELECT COUNT(*) FROM message_logs ml WHERE ml.related_type = 'notice' AND ml.related_id = t.id AND ml.status = 'sent') AS delivered_count,
                 (SELECT COUNT(*) FROM message_logs ml2 WHERE ml2.related_type = 'notice' AND ml2.related_id = t.id AND ml2.status = 'failed') AS failed_count",
    'joins' => 'LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN programs p ON p.id = t.program_id LEFT JOIN users u ON u.id = t.created_by',
    'search' => ['t.title', 't.slug', 'd.name', 'p.short_name'],
    'order' => 't.is_pinned DESC, COALESCE(t.publish_date, t.created_at) DESC, t.id DESC',
    'search_placeholder' => 'Search notices by title, department or course…',
    'scopes' => ['state' => $state, 'category' => 't.category', 'status' => 't.status'],
    'hidden_columns' => ['description'],
    'columns' => [
        ['key' => 'title', 'label' => 'Notice', 'format' => 'title', 'sub' => 'audience_label', 'sortable' => true, 'link' => 'view'],
        ['key' => 'category', 'label' => 'Category', 'format' => 'badge', 'sortable' => true],
        ['key' => 'audience_label', 'label' => 'Audience', 'format' => 'text', 'hidden' => true],
        ['key' => 'publish_date', 'label' => 'Publish date', 'format' => 'datetime', 'sortable' => true],
        ['key' => 'expiry_date', 'label' => 'Expires', 'format' => 'date', 'sortable' => true],
        ['key' => 'delivered_count', 'label' => 'Delivered', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'views', 'label' => 'Views', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'hidden' => true],
        ['key' => 'show_on_website', 'label' => 'Website', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'is_pinned', 'label' => 'Pinned', 'format' => 'boolean', 'hidden' => true],
        ['key' => 'created_by_name', 'label' => 'Created by', 'format' => 'text', 'hidden' => true],
        ['key' => 'state', 'label' => 'Status', 'format' => 'badge', 'sortable' => 'state'],
    ],
    'export_columns' => [
        ['key' => 'title', 'label' => 'Title'], ['key' => 'category', 'label' => 'Category', 'format' => 'badge'], ['key' => 'audience_label', 'label' => 'Audience'],
        ['key' => 'publish_date', 'label' => 'Publish date', 'format' => 'datetime'], ['key' => 'expiry_date', 'label' => 'Expiry date', 'format' => 'datetime'],
        ['key' => 'state', 'label' => 'Status', 'format' => 'badge'], ['key' => 'is_pinned', 'label' => 'Pinned', 'format' => 'boolean'],
        ['key' => 'show_on_website', 'label' => 'On website', 'format' => 'boolean'], ['key' => 'delivered_count', 'label' => 'Delivered', 'format' => 'number'],
        ['key' => 'views', 'label' => 'Views', 'format' => 'number'], ['key' => 'created_by_name', 'label' => 'Created by'],
    ],
    'filters' => [
        ['key' => 'category', 'label' => 'Category', 'options' => comm_notice_categories()],
        ['key' => 'audience', 'label' => 'Audience', 'options' => comm_notice_audiences()],
        ['key' => 'department_id', 'label' => 'Department', 'source' => 'departments'],
        ['key' => 'pinned', 'label' => 'Pinned', 'sql_map' => ['yes' => 't.is_pinned = 1', 'no' => 't.is_pinned = 0'], 'options' => ['yes' => 'Pinned only', 'no' => 'Not pinned']],
        ['key' => 'show_on_website', 'label' => 'On website', 'type' => 'boolean'],
        ['key' => 'publish_date', 'label' => 'Published', 'type' => 'daterange'],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Notice'],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 12, 'maxlength' => 255, 'placeholder' => 'e.g. Mid-semester examination schedule — October 2026'],
        ['name' => 'slug', 'label' => 'URL slug', 'type' => 'slug', 'slug_from' => 'title', 'col' => 6, 'maxlength' => 190, 'help' => 'Used for the public link /notices/{slug}. Generated from the title when left empty.'],
        ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'required' => true, 'default' => 'general', 'col' => 3, 'options' => comm_notice_categories()],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'col' => 3,
            'options' => ['draft' => 'Draft', 'published' => 'Published', 'expired' => 'Expired']],
        ['name' => 'description', 'label' => 'Description', 'type' => 'richtext', 'col' => 12, 'placeholder' => 'Write the notice…'],
        ['name' => 'attachment', 'label' => 'Attachment', 'type' => 'file', 'category' => 'document', 'folder' => 'notices', 'col' => 12, 'help' => 'PDF, Word, Excel or PowerPoint up to the configured upload size.'],
        ['type' => 'section', 'label' => 'Audience'],
        ['name' => 'audience', 'label' => 'Audience', 'type' => 'select', 'required' => true, 'default' => 'all', 'col' => 4, 'options' => comm_notice_audiences()],
        ['name' => 'department_id', 'label' => 'Department', 'type' => 'combobox', 'source' => 'departments', 'col' => 4],
        ['name' => 'program_id', 'label' => 'Course / Program', 'type' => 'combobox', 'source' => 'programs', 'col' => 4],
        ['name' => 'semester_no', 'label' => 'Semester', 'type' => 'select', 'options' => $semesters, 'col' => 4],
        ['type' => 'section', 'label' => 'Schedule & visibility'],
        ['name' => 'publish_date', 'label' => 'Publish date', 'type' => 'datetime', 'col' => 6, 'help' => 'Leave empty to publish immediately. A future date schedules the notice.'],
        ['name' => 'expiry_date', 'label' => 'Expiry date', 'type' => 'datetime', 'col' => 6, 'help' => 'The notice is hidden from the website after this date.'],
        ['name' => 'is_pinned', 'label' => 'Pin to top', 'type' => 'toggle', 'col' => 4, 'default' => false],
        ['name' => 'show_on_website', 'label' => 'Show on website', 'type' => 'toggle', 'col' => 4, 'default' => true],
        ['name' => 'send_email', 'label' => 'Send by e-mail', 'type' => 'toggle', 'col' => 4, 'default' => false, 'hidden' => true],
        ['name' => 'send_sms', 'label' => 'Send by SMS', 'type' => 'toggle', 'col' => 4, 'default' => false, 'hidden' => true],
        ['name' => 'send_whatsapp', 'label' => 'Send by WhatsApp', 'type' => 'toggle', 'col' => 4, 'default' => false, 'hidden' => true],
    ],
    'bulk' => ['delete' => true, 'status' => ['draft', 'published', 'expired']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'list_query' => function (&$where, &$args, $req) {
            static $done = false;
            if (!$done) {
                $done = true;
                comm_expire_notices();
            }
        },
        'transform_row' => function (array $r): array {
            $r['audience_label'] = comm_notice_audience_label($r);
            $r['category_label'] = comm_notice_categories()[$r['category']] ?? label_from_key((string) $r['category']);
            return $r;
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            $errors = [];
            $aud = $data['audience'] ?? ($old['audience'] ?? 'all');
            $pick = fn (string $k) => array_key_exists($k, $data) ? $data[$k] : ($old[$k] ?? null);
            if ($aud === 'department' && !$pick('department_id')) {
                $errors['department_id'] = 'Select the department this notice is for.';
            }
            if ($aud === 'course' && !$pick('program_id')) {
                $errors['program_id'] = 'Select the course / program this notice is for.';
            }
            if ($aud === 'semester' && !$pick('semester_no')) {
                $errors['semester_no'] = 'Select the semester this notice is for.';
            }
            $pub = $pick('publish_date');
            $exp = $pick('expiry_date');
            if ($pub && $exp && strtotime((string) $exp) <= strtotime((string) $pub)) {
                $errors['expiry_date'] = 'Expiry date must be after the publish date.';
            }
            if (($data['status'] ?? null) === 'published' && $exp && strtotime((string) $exp) < time()) {
                $errors['expiry_date'] = 'This expiry date is in the past — the notice would expire immediately.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $aud = $data['audience'] ?? ($old['audience'] ?? 'all');
            if (array_key_exists('audience', $data)) {
                if ($aud !== 'department') {
                    $data['department_id'] = null;
                }
                if (!in_array($aud, ['course', 'semester', 'parents'], true)) {
                    $data['program_id'] = null;
                }
                if ($aud !== 'semester') {
                    $data['semester_no'] = null;
                }
            }
            if ($id === null || array_key_exists('slug', $data)) {
                $slug = ($data['slug'] ?? null) ?: ($old['slug'] ?? null) ?: (string) ($data['title'] ?? $old['title'] ?? 'notice');
                $data['slug'] = comm_unique_slug('notices', $slug, $id);
            }
            if (($data['status'] ?? null) === 'published' && empty($data['publish_date']) && empty($old['publish_date'])) {
                $data['publish_date'] = date('Y-m-d H:i:s');
            }
            if (isset($data['description'])) {
                $data['description'] = comm_restore_placeholders((string) $data['description']);
            }
            return $data;
        },
        'after_bulk' => function (array $ids, string $field, $value) {
            if ($field === 'status' && $value === 'published') {
                db_exec('UPDATE notices SET publish_date = ? WHERE publish_date IS NULL AND id IN (' . implode(',', array_map('intval', $ids)) . ')', [date('Y-m-d H:i:s')]);
            }
        },
        'after_delete' => function (int $id) {
            comm_queue_cancel(['related_type' => 'notice', 'related_id' => $id]);
        },
        'describe' => fn (array $r) => '"' . str_limit((string) ($r['title'] ?? ''), 80) . '"',
    ],
];
