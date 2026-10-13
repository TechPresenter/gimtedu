<?php
/** Blog / news categories (Website CMS). */
require_once APP_ROOT . '/app/services/media.php';

return [
    'table' => 'blog_categories',
    'title' => 'Blog Categories',
    'singular' => 'Category',
    'permission' => 'blog',
    'icon' => 'folder-tree',
    'description' => 'Group news and blog posts by topic.',
    'select' => "t.*,
                 (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = t.id) AS posts_count,
                 (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = t.id AND p.status = 'published') AS published_count,
                 (SELECT COALESCE(SUM(p.views), 0) FROM blog_posts p WHERE p.category_id = t.id) AS views_total,
                 (SELECT MAX(p.published_at) FROM blog_posts p WHERE p.category_id = t.id AND p.status = 'published') AS last_published_at",
    'search' => ['t.name', 't.slug', 't.description'],
    'order' => 't.sort_order ASC, t.name ASC',
    'columns' => [
        ['key' => 'name', 'label' => 'Category', 'format' => 'title', 'sub' => 'slug', 'sortable' => true, 'link' => 'view'],
        ['key' => 'description', 'label' => 'Description', 'format' => 'truncate', 'truncate' => 70],
        ['key' => 'posts_count', 'label' => 'Posts', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'published_count', 'label' => 'Published', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'views_total', 'label' => 'Views', 'format' => 'number', 'align' => 'right', 'sortable' => true],
        ['key' => 'last_published_at', 'label' => 'Last post', 'format' => 'date', 'sortable' => true, 'hidden' => true],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
        ['key' => 'usage', 'label' => 'Posts', 'sql_map' => ['with' => 'EXISTS (SELECT 1 FROM blog_posts p WHERE p.category_id = t.id)', 'empty' => 'NOT EXISTS (SELECT 1 FROM blog_posts p WHERE p.category_id = t.id)'],
            'options' => ['with' => 'Has posts', 'empty' => 'No posts yet']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'unique' => true, 'maxlength' => 100, 'col' => 8, 'placeholder' => 'e.g. Campus Life'],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'min' => 0, 'max' => 9999, 'default' => 0, 'col' => 4],
        ['name' => 'slug', 'label' => 'Slug', 'type' => 'slug', 'slug_from' => 'name', 'unique' => true, 'maxlength' => 120, 'col' => 8, 'prefix' => '/', 'help' => 'Used in the category URL. Generated from the name when left empty.'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive'], 'col' => 4],
        ['name' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxlength' => 255, 'rows' => 2],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'import' => true,
    'export' => true,
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_save' => function (array $data, ?int $id, ?array $old, array $input): array {
            if ($id === null && empty($data['slug']) && !empty($data['name'])) {
                $data['slug'] = slugify($data['name']);
            }
            return $data;
        },
        'validate' => function (array $data, ?int $id): array {
            $slug = $data['slug'] ?? null;
            if ($id === null && !$slug && !empty($data['name'])) {
                $slug = slugify($data['name']);
                if ((int) db_value('SELECT COUNT(*) FROM blog_categories WHERE slug = ?', [$slug])) {
                    return ['slug' => 'A category with the slug "' . $slug . '" already exists. Enter a different slug.'];
                }
            }
            return [];
        },
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM blog_posts WHERE category_id = ?', [$id]);
            return $n ? '"' . $row['name'] . '" has ' . $n . ' post' . ($n === 1 ? '' : 's') . '. Move them to another category or mark the category inactive instead.' : null;
        },
    ],
];
