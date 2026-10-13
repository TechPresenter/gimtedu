<?php
/** Alumni success stories (brief §23): photo, title, rich-text story, company, featured flag, publish to website. */
require_once APP_ROOT . '/app/services/alumni.php';

return [
    'table' => 'alumni_success_stories',
    'title' => 'Success Stories',
    'singular' => 'Success story',
    'permission' => 'alumni',
    'icon' => 'trophy',
    'description' => 'Alumni achievements published on the website and in newsletters.',
    'select' => "t.*, a.name AS alumni_name, a.photo AS alumni_photo, a.batch_year,
                 CONCAT('Batch ', a.batch_year, IFNULL(CONCAT(' · ', p.short_name), '')) AS alumni_sub, p.short_name AS program_short,
                 TRIM(CONCAT_WS(' · ', NULLIF(t.designation, ''), NULLIF(t.company, ''))) AS role_line",
    'joins' => 'JOIN alumni a ON a.id = t.alumni_id LEFT JOIN programs p ON p.id = a.program_id',
    'search' => ['t.title', 't.company', 't.excerpt', 'a.name'],
    'search_placeholder' => 'Search title, alumni, company…',
    'order' => 't.is_featured DESC, t.sort_order ASC, COALESCE(t.published_at, t.created_at) DESC',
    'columns' => [
        ['key' => 'image', 'label' => 'Photo', 'format' => 'image'],
        ['key' => 'title', 'label' => 'Story', 'format' => 'title', 'sub' => 'role_line', 'link' => 'view', 'sortable' => true],
        ['key' => 'alumni_name', 'label' => 'Alumni', 'format' => 'person', 'image' => 'alumni_photo', 'sub' => 'alumni_sub', 'link' => '/alumni/{alumni_id}', 'sortable' => 'a.name'],
        ['key' => 'company', 'label' => 'Company', 'hidden' => true, 'sortable' => true],
        ['key' => 'is_featured', 'label' => 'Featured', 'format' => 'boolean', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'show_on_website', 'label' => 'On website', 'format' => 'boolean', 'align' => 'center', 'hidden' => true, 'sortable' => true],
        ['key' => 'status', 'label' => 'Status & visibility', 'format' => 'badge', 'sortable' => true],
        ['key' => 'published_at', 'label' => 'Published', 'format' => 'date', 'hidden' => true, 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['published' => 'Published', 'draft' => 'Draft']],
        ['key' => 'is_featured', 'label' => 'Featured', 'type' => 'boolean'],
        ['key' => 'show_on_website', 'label' => 'On website', 'type' => 'boolean'],
        ['key' => 'program_id', 'label' => 'Program', 'source' => 'programs', 'column' => 'a.program_id'],
    ],
    'fields' => [
        ['name' => 'alumni_id', 'label' => 'Alumni', 'type' => 'combobox', 'required' => true, 'source' => alumni_lookup_source(), 'col' => 6, 'placeholder' => 'Search alumni…'],
        ['name' => 'title', 'label' => 'Headline', 'type' => 'text', 'required' => true, 'col' => 6, 'maxlength' => 190, 'placeholder' => 'e.g. From campus coder to engineering lead at Microsoft'],
        ['name' => 'company', 'label' => 'Company', 'type' => 'text', 'col' => 6, 'maxlength' => 190, 'help' => 'Defaults to the alumni\'s current company.'],
        ['name' => 'designation', 'label' => 'Designation', 'type' => 'text', 'col' => 6, 'maxlength' => 150],
        ['name' => 'excerpt', 'label' => 'Pull quote', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 300, 'help' => 'One or two lines shown on cards and the website (max 300 characters).'],
        ['name' => 'story', 'label' => 'Story', 'type' => 'richtext', 'required' => true],
        ['name' => 'image', 'label' => 'Photo', 'type' => 'image', 'col' => 6, 'folder' => 'alumni-stories'],
        ['name' => 'sort_order', 'label' => 'Display order', 'type' => 'number', 'col' => 6, 'min' => 0, 'max' => 999, 'default' => 0, 'help' => 'Lower numbers appear first.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'col' => 4, 'options' => ['draft' => 'Draft', 'published' => 'Published']],
        ['name' => 'is_featured', 'label' => 'Featured story', 'type' => 'toggle', 'col' => 4, 'default' => false],
        ['name' => 'show_on_website', 'label' => 'Publish to website', 'type' => 'toggle', 'col' => 4, 'default' => false],
    ],
    'bulk' => ['delete' => true, 'status' => ['published', 'draft']],
    'export' => true,
    'import' => false,
    'form' => ['size' => 'xl'],
    'scopes' => ['alumni_id' => 't.alumni_id'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $status = $data['status'] ?? ($old['status'] ?? 'draft');
            $web = array_key_exists('show_on_website', $data) ? (int) $data['show_on_website'] : (int) ($old['show_on_website'] ?? 0);
            if ($web && $status !== 'published') {
                $errors['show_on_website'] = 'Only published stories can appear on the website. Set the status to Published.';
            }
            if (array_key_exists('story', $data) && trim(strip_tags((string) $data['story'])) === '') {
                $errors['story'] = 'Story is required.';
            } elseif (array_key_exists('story', $data) && mb_strlen(trim(strip_tags((string) $data['story']))) < 80) {
                $errors['story'] = 'The story is too short — write at least 80 characters.';
            }
            if (!empty($data['is_featured']) && !($old['is_featured'] ?? 0)) {
                $count = (int) db_value('SELECT COUNT(*) FROM alumni_success_stories WHERE is_featured = 1 AND id <> ?', [(int) ($id ?? 0)]);
                if ($count >= ALUMNI_MAX_FEATURED_STORIES) {
                    $errors['is_featured'] = 'Up to ' . ALUMNI_MAX_FEATURED_STORIES . ' stories can be featured. Un-feature another story first.';
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $alumniId = (int) ($data['alumni_id'] ?? ($old['alumni_id'] ?? 0));
            if ($alumniId && ((array_key_exists('company', $data) && !$data['company']) || (array_key_exists('designation', $data) && !$data['designation']))) {
                $a = db_row('SELECT company, designation FROM alumni WHERE id = ?', [$alumniId]);
                if ($a) {
                    if (array_key_exists('company', $data) && !$data['company']) {
                        $data['company'] = $a['company'] ?: null;
                    }
                    if (array_key_exists('designation', $data) && !$data['designation']) {
                        $data['designation'] = $a['designation'] ?: null;
                    }
                }
            }
            $status = $data['status'] ?? ($old['status'] ?? 'draft');
            if ($status === 'published' && empty($old['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
            if ($status === 'draft' && isset($data['status'])) {
                $data['show_on_website'] = 0;
            }
            return $data;
        },
        'after_bulk' => function (array $ids, string $field, $value): void {
            if ($field === 'status' && $value === 'published') {
                db_exec('UPDATE alumni_success_stories SET published_at = NOW() WHERE published_at IS NULL AND id IN (' . implode(',', array_map('intval', $ids)) . ')');
            }
            if ($field === 'status' && $value === 'draft') {
                db_exec('UPDATE alumni_success_stories SET show_on_website = 0 WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
            }
        },
    ],
];
