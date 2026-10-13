<?php
/**
 * Website CMS › Menus — tree editor endpoints (docs/CMS.md §3).
 *   GET  /api/menus                          locations with their menu + item counts
 *   GET  /api/menus/{location}               menu + item tree
 *   PUT  /api/menus/{location}               {name?, status?, items: tree}  save the whole tree (insert/update/delete)
 *   POST /api/menus/{location}/sync-programs {mode: auto|columns}  header "Programs" mega menu: automatic cards or columns
 *   GET  /api/menus/{location}/fallback      default links used by the website when the menu is empty
 */
require_once APP_ROOT . '/app/services/cms.php';

function menus_location_or_404(string $location): array
{
    $loc = cms_menu_locations()[$location] ?? null;
    if (!$loc) {
        api_error('Unknown menu location.', 404);
    }
    return $loc;
}

/** The links includes/navbar.php and includes/footer.php fall back to (used for "Restore defaults" and the core seeder). */
function menus_fallback(string $location): array
{
    return cms_menu_fallback($location);
}

route('GET', '/menus', function () {
    cms_api_require('cms', 'view');
    $menus = [];
    foreach (db_all("SELECT m.*, (SELECT COUNT(*) FROM menu_items mi WHERE mi.menu_id = m.id) AS items, (SELECT COUNT(*) FROM menu_items mi WHERE mi.menu_id = m.id AND mi.status = 'active') AS active_items FROM menus m") as $m) {
        $menus[$m['location']] = $m;
    }
    $out = [];
    foreach (cms_menu_locations() as $key => $loc) {
        $m = $menus[$key] ?? null;
        $out[] = ['location' => $key] + $loc + ['menu_id' => $m ? (int) $m['id'] : null, 'name' => $m['name'] ?? $loc['label'], 'status' => $m['status'] ?? 'active',
            'items' => (int) ($m['items'] ?? 0), 'active_items' => (int) ($m['active_items'] ?? 0)];
    }
    api_ok(['locations' => $out, 'can' => ['edit' => can('cms', 'edit')]]);
});

route('GET', '/menus/{location}', function ($p) {
    cms_api_require('cms', 'view');
    $loc = menus_location_or_404($p['location']);
    $menu = db_row('SELECT * FROM menus WHERE location = ?', [$p['location']]);
    api_ok([
        'location' => ['location' => $p['location']] + $loc,
        'menu' => $menu ? ['id' => (int) $menu['id'], 'name' => $menu['name'], 'status' => $menu['status']] : ['id' => null, 'name' => $loc['label'], 'status' => 'active'],
        'items' => $menu ? cms_menu_tree((int) $menu['id']) : [],
        'program_columns' => $p['location'] === 'header' ? cms_program_menu_columns() : [],
    ]);
});

route('GET', '/menus/{location}/fallback', function ($p) {
    cms_api_require('cms', 'view');
    menus_location_or_404($p['location']);
    api_ok(['items' => menus_fallback($p['location'])]);
});

route('PUT', '/menus/{location}', function ($p) {
    cms_api_require('cms', 'edit');
    $loc = menus_location_or_404($p['location']);
    $in = api_input();
    $items = $in['items'] ?? null;
    if (!is_array($items)) {
        api_error('Send the menu items as a list.', 422, ['items' => 'Send the menu items as a list.']);
    }
    $res = cms_menu_save_tree($p['location'], $items, array_intersect_key($in, array_flip(['name', 'status'])));
    log_activity('update', 'cms', $res['menu']['id'], 'Saved the ' . $loc['label'] . ' menu (' . $res['count'] . ' item' . ($res['count'] === 1 ? '' : 's') . ', ' . $res['menu']['status'] . ')');
    api_ok(['menu' => ['id' => (int) $res['menu']['id'], 'name' => $res['menu']['name'], 'status' => $res['menu']['status']], 'items' => $res['items']], $loc['short'] . ' menu saved.');
});

route('POST', '/menus/{location}/sync-programs', function ($p) {
    cms_api_require('cms', 'edit');
    if ($p['location'] !== 'header') {
        api_error('Programs can only be synced into the header menu.', 422);
    }
    $mode = (api_input()['mode'] ?? 'auto') === 'columns' ? 'columns' : 'auto';
    $n = cms_sync_program_menu($mode);
    $menu = cms_menu_ensure('header');
    log_activity('update', 'cms', $menu['id'], $mode === 'columns' ? 'Rebuilt the Programs mega menu as columns from the programs table (' . $n . ' programs)' : 'Set the Programs mega menu to automatic program cards (' . $n . ' programs)');
    api_ok(['items' => cms_menu_tree((int) $menu['id']), 'programs' => $n],
        $mode === 'columns' ? 'Programs mega menu rebuilt as columns with ' . $n . ' programs.' : 'Programs mega menu now shows all ' . $n . ' programs automatically.');
});
