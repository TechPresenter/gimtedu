<?php
/** Website CMS › Menu items (flat CRUD access; the Menus screen edits them as a tree via api/routes/menus.php). */
require_once APP_ROOT . '/app/services/cms.php';

return [
    'table' => 'menu_items',
    'title' => 'Menu Items',
    'singular' => 'Menu item',
    'permission' => 'cms',
    'icon' => 'list-tree',
    'description' => 'Links inside the website menus.',
    'select' => 't.*, m.name AS menu_name, m.location AS menu_location, pi.title AS parent_title, pg.title AS page_title',
    'joins' => 'JOIN menus m ON m.id = t.menu_id LEFT JOIN menu_items pi ON pi.id = t.parent_id LEFT JOIN pages pg ON pg.id = t.page_id',
    'search' => ['t.title', 't.url', 't.description'],
    'order' => 'm.location, t.parent_id IS NOT NULL, t.sort_order, t.id',
    'columns' => [
        ['key' => 'title', 'label' => 'Title', 'format' => 'title', 'sub' => 'url', 'sortable' => true],
        ['key' => 'menu_name', 'label' => 'Menu', 'format' => 'text', 'sortable' => 'm.name'],
        ['key' => 'parent_title', 'label' => 'Parent', 'format' => 'text', 'sortable' => 'pi.title'],
        ['key' => 'page_title', 'label' => 'Linked page', 'format' => 'text', 'hidden' => true],
        ['key' => 'is_mega', 'label' => 'Mega', 'format' => 'boolean'],
        ['key' => 'sort_order', 'label' => 'Order', 'format' => 'number', 'align' => 'center', 'sortable' => true],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'menu_id', 'label' => 'Menu', 'source' => 'menus'],
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'scopes' => ['menu_id' => 't.menu_id'],
    'fields' => [
        ['name' => 'menu_id', 'label' => 'Menu', 'type' => 'select', 'source' => 'menus', 'required' => true, 'col' => 6],
        ['name' => 'parent_id', 'label' => 'Parent item', 'type' => 'combobox', 'col' => 6, 'depends' => ['menu_id' => 'menu_id'],
            'source' => ['table' => 'menu_items mi', 'value' => 'mi.id', 'label' => 'mi.title', 'sub' => 'mi.url', 'order' => 'mi.sort_order', 'search' => ['mi.title'], 'filters' => ['menu_id' => 'mi.menu_id']]],
        ['name' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true, 'maxlength' => 100, 'col' => 6],
        ['name' => 'page_id', 'label' => 'Link to page', 'type' => 'combobox', 'source' => 'pages', 'col' => 6, 'help' => 'Choose a page or enter a URL below.'],
        ['name' => 'url', 'label' => 'URL', 'type' => 'text', 'maxlength' => 255, 'col' => 6, 'placeholder' => '/about or https://…'],
        ['name' => 'target', 'label' => 'Open in', 'type' => 'select', 'required' => true, 'default' => '_self', 'col' => 6, 'options' => ['_self' => 'Same tab', '_blank' => 'New tab']],
        ['name' => 'icon', 'label' => 'Icon', 'type' => 'select', 'col' => 6, 'options' => array_combine(cms_icon_names(), cms_icon_names())],
        ['name' => 'sort_order', 'label' => 'Sort order', 'type' => 'number', 'default' => 0, 'min' => 0, 'max' => 9999, 'col' => 6],
        ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'maxlength' => 190, 'help' => 'Shown under the title in dropdown and mega menus.'],
        ['name' => 'is_mega', 'label' => 'Mega menu', 'type' => 'boolean', 'col' => 6, 'placeholder' => 'Render children as mega-menu columns'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'col' => 6, 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => true, 'status' => ['active', 'inactive']],
    'export' => true,
    'permissions' => ['export' => ['cms', 'view']],
    'form' => ['size' => 'lg'],
    'hooks' => [
        'validate' => function (array $data, ?int $id, ?array $old): array {
            $errors = [];
            $menuId = (int) ($data['menu_id'] ?? $old['menu_id'] ?? 0);
            $url = (string) ($data['url'] ?? $old['url'] ?? '');
            $pageId = $data['page_id'] ?? $old['page_id'] ?? null;
            if ($url === '' && !$pageId) {
                $errors['url'] = 'Enter a URL or choose a page.';
            } elseif ($url !== '' && !$pageId && !cms_valid_url($url)) {
                $errors['url'] = 'Enter a valid link, e.g. /about or https://example.com.';
            }
            $parentId = (int) ($data['parent_id'] ?? 0);
            if ($parentId) {
                $parent = db_row('SELECT id, menu_id, parent_id FROM menu_items WHERE id = ?', [$parentId]);
                if (!$parent || (int) $parent['menu_id'] !== $menuId) {
                    $errors['parent_id'] = 'The parent must belong to the same menu.';
                } elseif ($id && $parentId === $id) {
                    $errors['parent_id'] = 'An item cannot be its own parent.';
                } else {
                    $location = (string) db_value('SELECT location FROM menus WHERE id = ?', [$menuId]);
                    $depth = 1 + ($parent['parent_id'] ? 1 : 0);
                    if ($depth >= (cms_menu_locations()[$location]['depth'] ?? 1)) {
                        $errors['parent_id'] = 'This menu does not allow that many levels.';
                    }
                }
            }
            return $errors;
        },
        'before_save' => function (array $data, ?int $id, ?array $old): array {
            $pageId = $data['page_id'] ?? null;
            if ($pageId && ($page = db_row('SELECT id, slug, is_system FROM pages WHERE id = ?', [(int) $pageId]))) {
                $data['url'] = cms_page_url($page);
            }
            if (array_key_exists('is_mega', $data) && $data['is_mega'] && !empty($data['parent_id'])) {
                $data['is_mega'] = 0;
            }
            return $data;
        },
    ],
];
