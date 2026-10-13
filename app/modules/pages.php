<?php
/** Website CMS › Pages (page builder content lives in `blocks`, see docs/CMS.md §2). */
require_once APP_ROOT . '/app/services/cms.php';

return [
    'table' => 'pages',
    'title' => 'Pages',
    'singular' => 'Page',
    'permission' => 'cms',
    'icon' => 'file-text',
    'description' => 'Website pages, their content and page-builder blocks.',
    'select' => "t.*, pp.title AS parent_title, u.name AS updated_by_name,
                 COALESCE(JSON_LENGTH(t.blocks), 0) AS blocks_count,
                 COALESCE(t.updated_at, t.created_at) AS last_modified,
                 CONCAT('/', t.slug) AS path",
    'joins' => 'LEFT JOIN pages pp ON pp.id = t.parent_id LEFT JOIN users u ON u.id = COALESCE(t.updated_by, t.created_by)',
    'search' => ['t.title', 't.slug', 't.subtitle', 't.excerpt'],
    'order' => 't.is_system DESC, t.sort_order, t.title',
    'columns' => [
        ['key' => 'title', 'label' => 'Page', 'format' => 'title', 'sub' => 'path', 'sortable' => true, 'link' => 'view'],
        ['key' => 'template', 'label' => 'Template', 'format' => 'badge', 'sortable' => true, 'colors' => ['default' => 'slate', 'full_width' => 'blue', 'landing' => 'purple', 'sidebar' => 'cyan']],
        ['key' => 'parent_title', 'label' => 'Parent', 'format' => 'text', 'sortable' => 'pp.title', 'hidden' => true],
        ['key' => 'blocks_count', 'label' => 'Blocks', 'format' => 'number', 'align' => 'center', 'sortable' => 'blocks_count'],
        ['key' => 'is_system', 'label' => 'System', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
        ['key' => 'published_at', 'label' => 'Published', 'format' => 'datetime', 'sortable' => true, 'hidden' => true],
        ['key' => 'last_modified', 'label' => 'Last updated', 'format' => 'datetime', 'sortable' => 'last_modified'],
        ['key' => 'updated_by_name', 'label' => 'Updated by', 'format' => 'text', 'hidden' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['published' => 'Published', 'draft' => 'Draft']],
        ['key' => 'template', 'label' => 'Template', 'options' => cms_page_templates()],
        ['key' => 'kind', 'label' => 'Type', 'sql_map' => ['system' => 't.is_system = 1', 'custom' => 't.is_system = 0'], 'options' => ['system' => 'System pages', 'custom' => 'Custom pages']],
        ['key' => 'builder', 'label' => 'Builder', 'sql_map' => ['with' => 'COALESCE(JSON_LENGTH(t.blocks), 0) > 0', 'without' => 'COALESCE(JSON_LENGTH(t.blocks), 0) = 0'],
            'options' => ['with' => 'Uses page builder', 'without' => 'Rich text only']],
    ],
    'fields' => [
        ['type' => 'section', 'label' => 'Page'],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'col' => 8, 'maxlength' => 190, 'placeholder' => 'e.g. Scholarships'],
        ['name' => 'slug', 'label' => 'URL slug', 'type' => 'slug', 'slug_from' => 'title', 'required' => true, 'unique' => true, 'col' => 4, 'maxlength' => 190,
            'prefix' => '/', 'help' => 'Lowercase letters, numbers and dashes.'],
        ['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text', 'col' => 8, 'maxlength' => 255, 'placeholder' => 'Shown under the title in the page hero'],
        ['name' => 'template', 'label' => 'Template', 'type' => 'select', 'required' => true, 'default' => 'default', 'col' => 4, 'options' => cms_page_templates()],
        ['name' => 'parent_id', 'label' => 'Parent page', 'type' => 'combobox', 'source' => 'pages', 'col' => 6, 'placeholder' => 'None (top level)'],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 9999, 'col' => 6],
        ['name' => 'excerpt', 'label' => 'Excerpt', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500, 'help' => 'Short summary used in the hero and as the default meta description.'],
        // a path (bundled photo, media library image or upload via the CMS image picker) — not a per-record upload, so
        // duplicated pages can share the image and deleting one page never deletes a file another page still uses
        ['name' => 'banner_image', 'label' => 'Banner image', 'type' => 'text', 'maxlength' => 255, 'col' => 12, 'help' => 'Hero photo of the page (used when the page has no hero block).'],
        ['type' => 'section', 'label' => 'Content', 'help' => 'Rich text shown before the page-builder blocks. Use "Open builder" for sections such as cards, statistics and galleries.'],
        ['name' => 'content', 'label' => 'Content', 'type' => 'richtext'],
        ['type' => 'section', 'label' => 'Publishing'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'draft', 'col' => 6, 'options' => ['draft' => 'Draft', 'published' => 'Published']],
        ['name' => 'published_at', 'label' => 'Publish date', 'type' => 'datetime', 'col' => 6, 'help' => 'Leave empty to publish immediately; a future date schedules the page.'],
        ['name' => 'is_system', 'label' => 'System page', 'type' => 'boolean', 'form' => false],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'permissions' => ['export' => ['cms', 'view']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'transform_row' => function (array $row): array {
            $row['url'] = cms_page_url($row);
            $row['path'] = $row['url'];
            $row['is_system'] = (int) $row['is_system'];
            $row['blocks_count'] = (int) ($row['blocks_count'] ?? 0);
            [$t, $ref] = cms_page_seo_ref($row);
            $row['seo_ref'] = ['type' => $t, 'id' => $t === 'page' ? $ref : null, 'route' => $t === 'route' ? $ref : null];
            unset($row['blocks']);
            return $row;
        },
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            if ($old && (int) $old['is_system'] === 1 && isset($data['slug']) && $data['slug'] !== $old['slug']) {
                $errors['slug'] = 'The URL of a system page is fixed and cannot be changed.';
            }
            if (isset($data['slug']) && in_array($data['slug'], cms_reserved_slugs(), true) && !($old && $old['slug'] === $data['slug'])) {
                $errors['slug'] = '/' . $data['slug'] . ' is already used by the website (a built-in page or folder). Choose another slug.';
            } elseif (isset($data['slug']) && !preg_match('/^[a-z0-9][a-z0-9-]{0,189}$/', (string) $data['slug'])) {
                $errors['slug'] = 'Use lowercase letters, numbers and dashes only.';
            }
            if (!empty($data['banner_image']) && !cms_valid_image((string) $data['banner_image'])) {
                $errors['banner_image'] = 'Choose an image from the media library or upload one.';
            }
            if (!empty($data['parent_id'])) {
                $pid = (int) $data['parent_id'];
                if ($id && $pid === $id) {
                    $errors['parent_id'] = 'A page cannot be its own parent.';
                } elseif ($id) {
                    // walk up the chain to prevent cycles
                    $seen = [];
                    $cur = $pid;
                    while ($cur && !isset($seen[$cur])) {
                        if ($cur === $id) {
                            $errors['parent_id'] = 'This would create a loop: the selected parent is a child of this page.';
                            break;
                        }
                        $seen[$cur] = true;
                        $cur = (int) db_value('SELECT parent_id FROM pages WHERE id = ?', [$cur]);
                    }
                }
            }
            $newStatus = $data['status'] ?? ($old['status'] ?? 'draft');
            if ($newStatus === 'published' && ($old['status'] ?? 'draft') !== 'published' && !can('cms', 'publish')) {
                $errors['status'] = 'You do not have permission to publish pages. Save it as a draft and ask a content manager to publish it.';
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $status = $data['status'] ?? ($old['status'] ?? 'draft');
            if ($status === 'published' && empty($data['published_at']) && empty($old['published_at'])) {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
            return $data;
        },
        'before_delete' => function (int $id, array $row): ?string {
            if ((int) $row['is_system'] === 1) {
                return '"' . $row['title'] . '" is a system page and cannot be deleted. Set it to Draft to hide it.';
            }
            if ($n = (int) db_value('SELECT COUNT(*) FROM pages WHERE parent_id = ?', [$id])) {
                return '"' . $row['title'] . '" has ' . $n . ' child page' . ($n > 1 ? 's' : '') . '. Move or delete them first.';
            }
            return null;
        },
        'after_delete' => function (int $id, array $row): void {
            db_exec("DELETE FROM seo_settings WHERE entity_type = 'page' AND entity_id = ?", [$id]);
        },
        'bulk_action' => function (string $action, array $ids): array {
            if (!in_array($action, ['publish', 'unpublish'], true)) {
                throw new CrudException('Unknown bulk action.');
            }
            if (!can('cms', 'publish')) {
                throw new CrudException('You do not have permission to publish or unpublish pages.');
            }
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) {
                return ['updated' => 0, 'message' => 'Nothing to update.'];
            }
            $in = implode(',', $ids);
            $n = $action === 'publish'
                ? db_exec("UPDATE pages SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_by = ? WHERE id IN ($in) AND status <> 'published'", [user_id()])
                : db_exec("UPDATE pages SET status = 'draft', updated_by = ? WHERE id IN ($in) AND status <> 'draft'", [user_id()]);
            log_activity($action, 'cms', implode(',', array_slice($ids, 0, 20)), ($action === 'publish' ? 'Published ' : 'Unpublished ') . $n . ' page(s)');
            return ['updated' => $n, 'message' => $n . ' page' . ($n === 1 ? '' : 's') . ($action === 'publish' ? ' published.' : ' moved to draft.')];
        },
    ],
];
