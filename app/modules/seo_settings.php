<?php
/** SEO Management › per-URL SEO records (docs/CMS.md §6). The SEO screen uses api/routes/seo.php; this is the generic CRUD. */
require_once APP_ROOT . '/app/services/cms.php';

return [
    'table' => 'seo_settings',
    'title' => 'SEO Settings',
    'singular' => 'SEO record',
    'permission' => 'seo',
    'icon' => 'search-check',
    'description' => 'Meta titles, descriptions, social cards, robots and schema per URL.',
    'select' => "t.*, CASE t.entity_type
                    WHEN 'route' THEN t.route
                    WHEN 'page' THEN (SELECT title FROM pages WHERE id = t.entity_id)
                    WHEN 'program' THEN (SELECT name FROM programs WHERE id = t.entity_id)
                    WHEN 'post' THEN (SELECT title FROM blog_posts WHERE id = t.entity_id)
                    WHEN 'event' THEN (SELECT title FROM events WHERE id = t.entity_id)
                    WHEN 'notice' THEN (SELECT title FROM notices WHERE id = t.entity_id) END AS target,
                 CHAR_LENGTH(COALESCE(t.meta_title, '')) AS title_length, CHAR_LENGTH(COALESCE(t.meta_description, '')) AS description_length",
    'search' => ['t.route', 't.meta_title', 't.meta_description', 't.meta_keywords'],
    'order' => "FIELD(t.entity_type, 'route', 'page', 'program', 'post', 'event', 'notice'), t.route, t.entity_id",
    'columns' => [
        ['key' => 'target', 'label' => 'URL / entity', 'format' => 'title', 'sub' => 'entity_type', 'sortable' => 'target'],
        ['key' => 'entity_type', 'label' => 'Type', 'format' => 'badge', 'sortable' => true],
        ['key' => 'meta_title', 'label' => 'SEO title', 'format' => 'truncate', 'truncate' => 60, 'sortable' => true],
        ['key' => 'title_length', 'label' => 'Title chars', 'format' => 'number', 'align' => 'center', 'sortable' => 'title_length', 'hidden' => true],
        ['key' => 'description_length', 'label' => 'Description chars', 'format' => 'number', 'align' => 'center', 'sortable' => 'description_length', 'hidden' => true],
        ['key' => 'robots', 'label' => 'Robots', 'format' => 'code', 'sortable' => true],
        ['key' => 'include_in_sitemap', 'label' => 'Sitemap', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'entity_type', 'label' => 'Type', 'options' => cms_seo_entity_types()],
        ['key' => 'robots', 'label' => 'Robots', 'options' => cms_robots_options()],
    ],
    'fields' => [
        ['name' => 'entity_type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'default' => 'route', 'col' => 4, 'options' => cms_seo_entity_types(), 'readonly_on_edit' => true],
        ['name' => 'entity_id', 'label' => 'Entity ID', 'type' => 'number', 'min' => 1, 'col' => 4, 'readonly_on_edit' => true, 'help' => 'For pages, programs, posts, events and notices.'],
        ['name' => 'route', 'label' => 'Route', 'type' => 'text', 'maxlength' => 190, 'col' => 4, 'readonly_on_edit' => true, 'placeholder' => '/contact'],
        ['name' => 'meta_title', 'label' => 'SEO title', 'type' => 'text', 'maxlength' => 190, 'help' => 'Aim for 30–60 characters.'],
        ['name' => 'meta_description', 'label' => 'Meta description', 'type' => 'textarea', 'rows' => 3, 'maxlength' => 320, 'help' => 'Aim for 70–160 characters.'],
        ['name' => 'meta_keywords', 'label' => 'Keywords', 'type' => 'text', 'maxlength' => 255],
        ['name' => 'canonical_url', 'label' => 'Canonical URL', 'type' => 'text', 'maxlength' => 255],
        ['name' => 'og_title', 'label' => 'Social title', 'type' => 'text', 'maxlength' => 190, 'col' => 6],
        ['name' => 'og_image', 'label' => 'Social image', 'type' => 'image', 'folder' => 'cms/seo', 'col' => 6],
        ['name' => 'og_description', 'label' => 'Social description', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 320],
        ['name' => 'robots', 'label' => 'Robots', 'type' => 'select', 'required' => true, 'default' => 'index,follow', 'col' => 4, 'options' => cms_robots_options()],
        ['name' => 'sitemap_priority', 'label' => 'Sitemap priority', 'type' => 'decimal', 'default' => 0.7, 'min' => 0, 'max' => 1, 'step' => 0.1, 'col' => 4],
        ['name' => 'sitemap_changefreq', 'label' => 'Change frequency', 'type' => 'select', 'required' => true, 'default' => 'weekly', 'col' => 4, 'options' => cms_changefreq_options()],
        ['name' => 'include_in_sitemap', 'label' => 'Include in sitemap', 'type' => 'boolean', 'default' => true],
        ['name' => 'schema_json', 'label' => 'Schema (JSON-LD)', 'type' => 'textarea', 'rows' => 6, 'maxlength' => 60000],
    ],
    'bulk' => ['delete' => true],
    'export' => true,
    'permissions' => ['create' => ['seo', 'edit'], 'delete' => ['seo', 'edit'], 'export' => ['seo', 'view'], 'import' => ['seo', 'manage']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $type = $data['entity_type'] ?? $old['entity_type'] ?? 'route';
            if (!$old) {
                $target = cms_seo_resolve($type, isset($data['entity_id']) ? (int) $data['entity_id'] : null, $data['route'] ?? null);
                if (!$target) {
                    $errors[$type === 'route' ? 'route' : 'entity_id'] = $type === 'route' ? 'Enter a path starting with /, e.g. /contact.' : 'No ' . $type . ' exists with this ID.';
                } elseif (cms_seo_find($target)) {
                    $errors[$type === 'route' ? 'route' : 'entity_id'] = 'SEO settings already exist for this URL. Edit the existing record instead.';
                }
            }
            [, $e] = cms_seo_check_input(array_merge($old ?? [], $data));
            foreach ($e as $k => $msg) {
                if ($k !== 'og_image') {
                    $errors[$k] = $msg;
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if (!$old) {
                $target = cms_seo_resolve($data['entity_type'] ?? 'route', isset($data['entity_id']) ? (int) $data['entity_id'] : null, $data['route'] ?? null);
                if ($target) {
                    $data['entity_type'] = $target['ref_type'];
                    $data['entity_id'] = $target['ref_id'];
                    $data['route'] = $target['ref_route'];
                }
            }
            if (!empty($data['schema_json'])) {
                [$clean] = cms_seo_check_input(['schema_json' => $data['schema_json']]);
                $data['schema_json'] = $clean['schema_json'];
            }
            return $data;
        },
        'describe' => fn (array $row): string => $row['entity_type'] === 'route' ? (string) $row['route'] : $row['entity_type'] . ' #' . $row['entity_id'],
    ],
];
