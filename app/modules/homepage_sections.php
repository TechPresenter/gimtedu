<?php
/**
 * Website CMS › Home page sections (fixed set of keys, docs/CMS.md §1). The Home Page Sections screen uses the
 * tailored endpoints in api/routes/cms.php; this module gives generic list/export/edit access with the same validation.
 */
require_once APP_ROOT . '/app/services/cms.php';

return [
    'table' => 'homepage_sections',
    'title' => 'Home Page Sections',
    'singular' => 'Home page section',
    'permission' => 'cms',
    'icon' => 'layout-template',
    'description' => 'Sections of the website home page, their order and content.',
    'search' => ['t.section_key', 't.title', 't.subtitle'],
    'order' => 't.sort_order, t.id',
    'columns' => [
        ['key' => 'sort_order', 'label' => '#', 'format' => 'number', 'align' => 'center', 'sortable' => true, 'width' => '56px'],
        ['key' => 'label', 'label' => 'Section', 'format' => 'title', 'sub' => 'section_key', 'sortable' => 't.section_key'],
        ['key' => 'title', 'label' => 'Heading', 'format' => 'truncate', 'truncate' => 60, 'sortable' => true],
        ['key' => 'is_enabled', 'label' => 'Enabled', 'format' => 'boolean', 'sortable' => true],
        ['key' => 'updated_at', 'label' => 'Last updated', 'format' => 'datetime', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'is_enabled', 'label' => 'Enabled', 'type' => 'boolean'],
    ],
    'fields' => [
        ['name' => 'title', 'label' => 'Heading', 'type' => 'text', 'maxlength' => 190, 'col' => 8],
        ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'min' => 0, 'max' => 99, 'col' => 2],
        ['name' => 'is_enabled', 'label' => 'Enabled', 'type' => 'boolean', 'col' => 2],
        ['name' => 'subtitle', 'label' => 'Lead text', 'type' => 'textarea', 'rows' => 2, 'maxlength' => 500],
        ['name' => 'content', 'label' => 'Content (JSON)', 'type' => 'json', 'rows' => 14, 'help' => 'Shape per section: docs/CMS.md §1. The Home Page Sections screen offers a visual editor.'],
    ],
    'bulk' => ['delete' => false],
    'export' => true,
    'permissions' => ['export' => ['cms', 'view']],
    'form' => ['size' => 'xl'],
    'hooks' => [
        'transform_row' => function (array $row): array {
            $row['label'] = cms_section_types()[$row['section_key']]['label'] ?? label_from_key((string) $row['section_key']);
            $row['is_enabled'] = (int) $row['is_enabled'];
            return $row;
        },
        'validate' => function (array $data, ?int $id, ?array $old, array $input): array {
            if (!$old) {
                return ['title' => 'Home page sections are fixed. Edit an existing section instead of creating one.'];
            }
            $content = array_key_exists('content', $data) ? json_decode((string) $data['content'], true) : json_decode((string) $old['content'], true);
            [, $errors] = cms_validate_section($old['section_key'], [
                'title' => array_key_exists('title', $data) ? $data['title'] : $old['title'],
                'subtitle' => array_key_exists('subtitle', $data) ? $data['subtitle'] : $old['subtitle'],
                'content' => is_array($content) ? $content : [],
            ]);
            $out = [];
            foreach ($errors as $k => $msg) {
                $out[str_starts_with($k, 'content') ? 'content' : $k] = str_starts_with($k, 'content.') ? substr($k, 8) . ': ' . $msg : $msg;
            }
            return $out;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            if ($old && array_key_exists('content', $data)) {
                [$clean] = cms_validate_section($old['section_key'], ['title' => $data['title'] ?? $old['title'], 'content' => json_decode((string) $data['content'], true) ?: []]);
                $data['content'] = json_encode($clean['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            return $data;
        },
        'before_delete' => fn (int $id, array $row): string => 'Home page sections cannot be deleted. Disable the section instead.',
        'describe' => fn (array $row): string => '"' . (cms_section_types()[$row['section_key']]['label'] ?? $row['section_key']) . '"',
    ],
];
