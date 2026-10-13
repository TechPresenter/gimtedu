<?php
/** Website CMS › Menus (one menu per location; items are edited with the tree editor, see api/routes/menus.php). */
require_once APP_ROOT . '/app/services/cms.php';

return [
    'table' => 'menus',
    'title' => 'Menus',
    'singular' => 'Menu',
    'permission' => 'cms',
    'icon' => 'menu',
    'description' => 'Navigation menus by location (header, footer columns, legal bar …).',
    'select' => "t.*, (SELECT COUNT(*) FROM menu_items mi WHERE mi.menu_id = t.id) AS items_count,
                 (SELECT COUNT(*) FROM menu_items mi WHERE mi.menu_id = t.id AND mi.status = 'active') AS active_items",
    'search' => ['t.name', 't.location'],
    'order' => "FIELD(t.location, 'header', 'footer_quick', 'footer_academics', 'footer_admissions', 'footer_support', 'legal', 'topbar', 'mobile'), t.name",
    'columns' => [
        ['key' => 'name', 'label' => 'Menu', 'format' => 'title', 'sub' => 'location', 'sortable' => true],
        ['key' => 'location', 'label' => 'Location', 'format' => 'code', 'sortable' => true],
        ['key' => 'items_count', 'label' => 'Items', 'format' => 'number', 'align' => 'center', 'sortable' => 'items_count'],
        ['key' => 'active_items', 'label' => 'Active', 'format' => 'number', 'align' => 'center'],
        ['key' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
    ],
    'filters' => [
        ['key' => 'status', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'fields' => [
        ['name' => 'name', 'label' => 'Menu name', 'type' => 'text', 'required' => true, 'maxlength' => 100, 'col' => 6],
        ['name' => 'location', 'label' => 'Location', 'type' => 'select', 'required' => true, 'unique' => true, 'col' => 6, 'readonly_on_edit' => true,
            'options' => array_map(fn ($l) => $l['label'], cms_menu_locations())],
        ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
    ],
    'bulk' => ['delete' => false, 'status' => ['active', 'inactive']],
    'export' => true,
    'permissions' => ['export' => ['cms', 'view']],
    'form' => ['size' => 'md'],
    'hooks' => [
        'before_delete' => function (int $id, array $row): ?string {
            $n = (int) db_value('SELECT COUNT(*) FROM menu_items WHERE menu_id = ?', [$id]);
            return $n ? 'The ' . $row['name'] . ' menu still has ' . $n . ' item(s). Remove the items first or set the menu inactive.' : null;
        },
    ],
];
