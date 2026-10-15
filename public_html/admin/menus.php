<?php
/**
 * Menus: header (main, with one level of dropdown items), top bar apply links and footer columns.
 * Footer columns are menus with a key starting with "footer_" - new ones appear in the footer automatically.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('menus.manage');

const SITE_CORE_MENUS = ['main', 'topbar', 'footer_about', 'footer_involved', 'footer_legal'];

$menus = db_rows('SELECT * FROM menus ORDER BY FIELD(menu_key, "topbar", "main") DESC, id');
$menuKey = (string) ($_GET['menu'] ?? ($_POST['menu'] ?? 'main'));
$menu = null;
foreach ($menus as $m) {
    if ($m['menu_key'] === $menuKey) {
        $menu = $m;
    }
}
if (!$menu) {
    $menu = $menus[0] ?? null;
}
if (!$menu) {
    admin_page_start('Menus');
    echo '<div class="panel">' . empty_state('No menus found. Import the database again.') . '</div>';
    admin_page_end();
    exit;
}
$menuId = (int) $menu['id'];
$self = 'menus.php?menu=' . urlencode($menu['menu_key']);
$allowChildren = $menu['menu_key'] === 'main';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only($self);
    $action = (string) ($_POST['action'] ?? '');
    $menuLabel = 'menu "' . $menu['name'] . '" (' . $menu['menu_key'] . ')';

    if ($action === 'create_menu') {
        [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:60']);
        $key = 'footer_' . trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($data['name'] ?? '')), '_');
        if (!$errors && ($key === 'footer_' || strlen($key) > 60)) {
            $errors['name'] = 'Use letters or numbers in the name.';
        }
        if (!$errors && db_value('SELECT COUNT(*) FROM menus WHERE menu_key = ?', [$key])) {
            $errors['name'] = 'A footer column with this name already exists.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self, $_POST);
        }
        db_insert('menus', ['menu_key' => $key, 'name' => 'Footer: ' . $data['name'], 'description' => 'Footer link column (added in the admin)']);
        admin_log('menu_created', 'Footer menu "' . $data['name'] . '" (' . $key . ') created');
        flash('success', 'Footer column "' . $data['name'] . '" created. Add links to it - it appears in the footer as soon as it has a visible link.');
        admin_redirect('menus.php?menu=' . urlencode($key));
    }

    if ($action === 'rename_menu') {
        [$data, $errors] = validate($_POST, ['name' => 'required|min:2|max:60']);
        if ($errors || strpos($menu['menu_key'], 'footer_') !== 0) {
            back_with_errors($errors ?: ['name' => 'Only footer columns can be renamed.'], 'admin/' . $self, $_POST);
        }
        db_update('menus', ['name' => 'Footer: ' . preg_replace('/^Footer:\s*/i', '', $data['name'])], 'id = :id', ['id' => $menuId]);
        admin_log('menu_renamed', ucfirst($menuLabel) . ' renamed to "' . $data['name'] . '"');
        flash('success', 'Footer column renamed.');
        admin_redirect($self);
    }

    if ($action === 'delete_menu') {
        if (in_array($menu['menu_key'], SITE_CORE_MENUS, true)) {
            flash('error', 'The built-in menus cannot be deleted. You can hide their links instead.');
            admin_redirect($self);
        }
        db_query('DELETE FROM menus WHERE id = ?', [$menuId]);
        admin_log('menu_deleted', ucfirst($menuLabel) . ' deleted with its links');
        flash('success', 'Footer column deleted.');
        admin_redirect('menus.php');
    }

    $itemId = (int) ($_POST['id'] ?? 0);
    $item = $itemId ? db_row('SELECT * FROM menu_items WHERE id = ? AND menu_id = ?', [$itemId, $menuId]) : null;
    if ($itemId && !$item) {
        flash('error', 'Menu link not found.');
        admin_redirect($self);
    }

    if ($action === 'save_item') {
        $back = 'admin/' . $self . ($itemId ? '&edit=' . $itemId : '') . '#item-form';
        [$data, $errors] = validate($_POST, ['label' => 'required|max:80', 'url' => 'required|max:255', 'description' => 'max:150', 'parent_id' => 'integer', 'status' => 'in:visible,hidden'],
            ['url' => 'Link', 'label' => 'Link text']);
        $urlError = null;
        $url = site_clean_link((string) ($data['url'] ?? ''), $urlError);
        if (!isset($errors['url']) && ($urlError || $url === '')) {
            $errors['url'] = 'Link: ' . ($urlError ?: 'enter a link.');
        }
        $iconName = (string) ($_POST['icon'] ?? '');
        if ($iconName !== '' && !in_array($iconName, site_icon_names(), true)) {
            $errors['icon'] = 'Please choose an icon from the list.';
        }
        $parentId = $allowChildren ? ((int) ($data['parent_id'] ?? 0) ?: null) : null;
        if ($parentId) {
            $parent = db_row('SELECT * FROM menu_items WHERE id = ? AND menu_id = ?', [$parentId, $menuId]);
            if (!$parent || $parent['parent_id'] !== null || $parentId === $itemId) {
                $errors['parent_id'] = 'Choose a top-level link as the parent (menus have one level of dropdown links).';
            } elseif ($itemId && db_value('SELECT COUNT(*) FROM menu_items WHERE parent_id = ?', [$itemId])) {
                $errors['parent_id'] = 'This link has its own dropdown links, so it must stay at the top level.';
            }
        }
        if ($errors) {
            back_with_errors($errors, $back, $_POST);
        }
        $row = [
            'label' => $data['label'], 'url' => $url, 'icon' => $iconName !== '' ? $iconName : null,
            'description' => $data['description'] !== '' ? $data['description'] : null, 'open_new_tab' => !empty($_POST['open_new_tab']) ? 1 : 0,
            'status' => ($data['status'] ?? 'visible') === 'hidden' ? 'hidden' : 'visible', 'parent_id' => $parentId,
        ];
        if ($item) {
            if ((string) $item['parent_id'] !== (string) $parentId) {
                $row['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items WHERE menu_id = ? AND parent_id <=> ?', [$menuId, $parentId]);
            }
            db_update('menu_items', $row, 'id = :id', ['id' => $itemId]);
            admin_log('menu_item_updated', ucfirst($menuLabel) . ': link #' . $itemId . ' "' . $row['label'] . '" -> ' . $url);
        } else {
            $row['menu_id'] = $menuId;
            $row['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items WHERE menu_id = ? AND parent_id <=> ?', [$menuId, $parentId]);
            $itemId = db_insert('menu_items', $row);
            admin_log('menu_item_added', ucfirst($menuLabel) . ': link #' . $itemId . ' "' . $row['label'] . '" -> ' . $url);
        }
        $status = site_link_status($url);
        $msg = 'Menu link saved.';
        if ($status === 'missing') {
            flash('warning', 'Saved, but no page exists at ' . $url . ' yet - visitors would see "page not found". Check the address or create the page.');
        } elseif ($status === 'draft') {
            flash('warning', 'Saved, but the page at ' . $url . ' is not published yet - visitors cannot open it until you publish it.');
        } else {
            flash('success', $msg);
        }
        admin_redirect($self . '#item-' . $itemId);
    }

    if (!$item) {
        admin_redirect($self);
    }
    $itemLabel = ucfirst($menuLabel) . ': link #' . $itemId . ' "' . $item['label'] . '"';

    if ($action === 'move_item') {
        $siblings = db_rows('SELECT id FROM menu_items WHERE menu_id = ? AND parent_id <=> ? ORDER BY sort_order, id', [$menuId, $item['parent_id']]);
        $ids = array_map('intval', array_column($siblings, 'id'));
        $pos = array_search($itemId, $ids, true);
        $swap = ($_POST['dir'] ?? '') === 'up' ? $pos - 1 : $pos + 1;
        if ($pos !== false && isset($ids[$swap])) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
            foreach ($ids as $i => $sid) {
                db_query('UPDATE menu_items SET sort_order = ? WHERE id = ?', [$i + 1, $sid]);
            }
            admin_log('menu_item_moved', $itemLabel . ' moved ' . (($_POST['dir'] ?? '') === 'up' ? 'up' : 'down'));
        }
        admin_redirect($self . '#item-' . $itemId);
    }
    if ($action === 'toggle_item') {
        $new = $item['status'] === 'visible' ? 'hidden' : 'visible';
        db_update('menu_items', ['status' => $new], 'id = :id', ['id' => $itemId]);
        admin_log('menu_item_' . $new, $itemLabel . ' is now ' . $new);
        flash('success', 'Link is now ' . $new . '.');
        admin_redirect($self . '#item-' . $itemId);
    }
    if ($action === 'delete_item') {
        $children = (int) db_value('SELECT COUNT(*) FROM menu_items WHERE parent_id = ?', [$itemId]);
        db_query('DELETE FROM menu_items WHERE id = ?', [$itemId]);
        admin_log('menu_item_deleted', $itemLabel . ' deleted' . ($children ? ' with ' . $children . ' dropdown link(s)' : ''));
        flash('success', 'Link deleted' . ($children ? ' together with its ' . $children . ' dropdown link(s)' : '') . '.');
        admin_redirect($self);
    }
    admin_redirect($self);
}

// ---------------------------------------------------------------------
// View
// ---------------------------------------------------------------------
$items = db_rows('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order, id', [$menuId]);
$byParent = [];
foreach ($items as $it) {
    $byParent[(int) $it['parent_id']][] = $it;
}
$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId ? db_row('SELECT * FROM menu_items WHERE id = ? AND menu_id = ?', [$editId, $menuId]) : null;
$topLevel = $byParent[0] ?? [];

$whereShown = [
    'main' => 'Header navigation. Links with dropdown links open a menu (more than 7 dropdown links become a wide "mega" menu).',
    'topbar' => 'Quick "Apply" links in the top bar above the header (and in the mobile menu). Keep it to 3-5 short links.',
];
$shownText = $whereShown[$menu['menu_key']] ?? (strpos($menu['menu_key'], 'footer_') === 0 ? 'Footer column titled "' . preg_replace('/^Footer:\s*/i', '', $menu['name']) . '". Only top-level links are shown.' : (string) $menu['description']);

admin_page_start('Menus');

$renderItem = function (array $it, int $index, int $count) use (&$renderItem, $byParent, $self) {
    $kids = $byParent[(int) $it['id']] ?? [];
    $status = site_link_status($it['url']);
    $hidden = $it['status'] === 'hidden';
    ob_start(); ?>
    <li class="menu-node<?= $hidden ? ' is-hidden' : '' ?>" id="item-<?= (int) $it['id'] ?>">
        <div class="menu-node-row">
            <div class="menu-node-main"><?= $it['icon'] ? icon($it['icon']) : '<span class="site-indent" aria-hidden="true">•</span>' ?>
                <div><span class="menu-node-label"><?= e($it['label']) ?></span><?= $hidden ? ' <span class="badge badge-gray">Hidden</span>' : '' ?><?= $it['open_new_tab'] ? ' <span class="badge badge-blue">New tab</span>' : '' ?>
                    <span class="menu-node-url"><?= e($it['url']) ?><?= $it['description'] ? ' · ' . e($it['description']) : '' ?></span>
                    <?php if ($status === 'missing'): ?><span class="site-bad"><?= icon('alert-triangle') ?>No page at this address</span><?php elseif ($status === 'draft'): ?><span class="site-warn"><?= icon('alert-circle') ?>Page not published</span><?php elseif ($status === 'redirect'): ?><span class="site-warn"><?= icon('refresh') ?>Old address - redirects</span><?php endif; ?>
                </div></div>
            <div class="site-actions">
                <?php foreach ([['up', 'Move up', $index === 0], ['down', 'Move down', $index === $count - 1]] as [$dir, $lbl, $dis]): ?>
                    <form method="post" action="<?= e(admin_url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="move_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                        <button class="icon-btn<?= $dir === 'down' ? ' site-down' : '' ?>" type="submit" aria-label="<?= e($lbl . ': ' . $it['label']) ?>" title="<?= e($lbl) ?>"<?= $dis ? ' disabled' : '' ?>><?= icon('arrow-up') ?></button></form>
                <?php endforeach; ?>
                <form method="post" action="<?= e(admin_url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                    <button class="icon-btn<?= $hidden ? '' : ' site-eye-off' ?>" type="submit" aria-label="<?= e(($hidden ? 'Show: ' : 'Hide: ') . $it['label']) ?>" title="<?= $hidden ? 'Show' : 'Hide' ?>"><?= icon('eye') ?></button></form>
                <a class="icon-btn" href="<?= e(admin_url($self . '&edit=' . (int) $it['id'] . '#item-form')) ?>" aria-label="<?= e('Edit: ' . $it['label']) ?>" title="Edit"><?= icon('edit') ?></a>
                <form method="post" action="<?= e(admin_url($self)) ?>" data-confirm="<?= e('Delete the link "' . $it['label'] . '"' . ($kids ? ' and its ' . count($kids) . ' dropdown link(s)' : '') . '?') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                    <button class="icon-btn danger" type="submit" aria-label="<?= e('Delete: ' . $it['label']) ?>" title="Delete"><?= icon('trash') ?></button></form>
            </div>
        </div>
        <?php if ($kids): ?>
            <ul class="menu-tree">
                <?php foreach ($kids as $k => $kid) { echo $renderItem($kid, $k, count($kids)); } ?>
            </ul>
        <?php endif; ?>
    </li>
    <?php return (string) ob_get_clean();
};
$f = $edit ?: ['id' => 0, 'label' => '', 'url' => '', 'icon' => '', 'description' => '', 'open_new_tab' => 0, 'status' => 'visible', 'parent_id' => null];
?>
<div class="site-admin">
<nav class="admin-tabs site-menu-tabs" aria-label="Menus">
    <?php foreach ($menus as $m): ?>
        <a href="<?= e(admin_url('menus.php?menu=' . urlencode($m['menu_key']))) ?>" class="<?= $m['id'] === $menu['id'] ? 'active' : '' ?>"<?= $m['id'] === $menu['id'] ? ' aria-current="page"' : '' ?>><?= e($m['name']) ?></a>
    <?php endforeach; ?>
</nav>

<div class="admin-grid-main">
    <section class="panel">
        <div class="panel-head"><h2><?= icon('list') ?><?= e($menu['name']) ?></h2><span class="muted small"><?= count($items) ?> link<?= count($items) === 1 ? '' : 's' ?></span></div>
        <p class="help mt-0"><?= e($shownText) ?></p>
        <?php if (!$topLevel): ?>
            <?= empty_state('This menu has no links yet. Add the first one with the form.') ?>
        <?php else: ?>
            <ul class="menu-tree">
                <?php foreach ($topLevel as $i => $it) { echo $renderItem($it, $i, count($topLevel)); } ?>
            </ul>
        <?php endif; ?>
    </section>

    <aside>
        <section class="panel" id="item-form">
            <div class="panel-head"><h2><?= icon($edit ? 'edit' : 'plus') ?><?= $edit ? 'Edit link' : 'Add a link' ?></h2><?php if ($edit): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url($self)) ?>">Cancel</a><?php endif; ?></div>
            <form method="post" action="<?= e(admin_url($self)) ?>" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_item"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <div class="form-group full"><label for="item-pick" hidden>Quick pick a page</label>
                    <select id="item-pick" data-url-pick="#item-url" data-label-target="#item-label" hidden>
                        <option value="">Choose a page to link to…</option>
                        <?php foreach (site_page_links() as $href => $title): ?><option value="<?= e($href) ?>"><?= e($title) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="form-group full"><label for="item-label">Link text<span class="req">*</span></label>
                    <input id="item-label" name="label" required maxlength="80" value="<?= e(old('label', $f['label'])) ?>"><?= field_error('label') ?></div>
                <div class="form-group full"><label for="item-url">Link<span class="req">*</span></label>
                    <input id="item-url" name="url" required maxlength="255" list="site-page-urls" autocomplete="off" placeholder="/about/ or https://…" value="<?= e(old('url', $f['url'])) ?>">
                    <span class="help">A page of this site (start typing to choose), a full https:// address, mailto: or tel:. "#" alone is not allowed.</span><?= field_error('url') ?></div>
                <?php if ($allowChildren): ?>
                    <div class="form-group full"><label for="item-parent">Show inside (dropdown)</label>
                        <select id="item-parent" name="parent_id"><option value="">- Top level -</option>
                            <?php foreach ($topLevel as $t): if ((int) $t['id'] === (int) $f['id']) { continue; } ?>
                                <option value="<?= (int) $t['id'] ?>"<?= (string) old('parent_id', (string) $f['parent_id']) === (string) $t['id'] ? ' selected' : '' ?>><?= e($t['label']) ?></option>
                            <?php endforeach; ?>
                        </select><?= field_error('parent_id') ?></div>
                <?php endif; ?>
                <?= site_field_html('icon', 'item-icon', ['icon', 'Icon (optional)'], (string) old('icon', (string) $f['icon']), '', ['wide' => true]) ?><?= field_error('icon') ?>
                <div class="form-group full"><label for="item-desc">Short description (dropdown links)</label>
                    <input id="item-desc" name="description" maxlength="150" value="<?= e(old('description', (string) $f['description'])) ?>"></div>
                <div class="form-group full site-check"><label class="checkbox" for="item-newtab"><input type="checkbox" id="item-newtab" name="open_new_tab" value="1"<?= old('open_new_tab', (string) $f['open_new_tab']) === '1' ? ' checked' : '' ?>><span>Open in a new tab</span></label></div>
                <div class="form-group full"><label for="item-status">Visibility</label>
                    <select id="item-status" name="status"><?= options_html(['visible' => 'Visible', 'hidden' => 'Hidden'], (string) old('status', $f['status']), true, '') ?></select></div>
                <div class="form-actions full"><button class="btn btn-primary btn-block" type="submit"><?= icon('check') ?><?= $edit ? 'Save link' : 'Add link' ?></button></div>
            </form>
        </section>

        <?php if (strpos($menu['menu_key'], 'footer_') === 0): ?>
            <section class="panel">
                <div class="panel-head"><h2>Footer column</h2></div>
                <form method="post" action="<?= e(admin_url($self)) ?>" class="form-grid">
                    <?= csrf_field() ?><input type="hidden" name="action" value="rename_menu">
                    <div class="form-group full"><label for="menu-rename">Column title</label><input id="menu-rename" name="name" required maxlength="60" value="<?= e(preg_replace('/^Footer:\s*/i', '', $menu['name'])) ?>"></div>
                    <div class="form-actions full"><button class="btn btn-ghost btn-sm" type="submit">Rename</button></div>
                </form>
                <?php if (!in_array($menu['menu_key'], SITE_CORE_MENUS, true)): ?>
                    <form method="post" action="<?= e(admin_url($self)) ?>" class="mt-2" data-confirm="Delete this footer column and all its links?">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete_menu">
                        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('trash') ?>Delete this column</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head"><h2><?= icon('plus') ?>New footer column</h2></div>
            <form method="post" action="<?= e(admin_url($self)) ?>" class="form-grid">
                <?= csrf_field() ?><input type="hidden" name="action" value="create_menu">
                <div class="form-group full"><label for="menu-name">Column title</label><input id="menu-name" name="name" required minlength="2" maxlength="60" placeholder="e.g. Programmes"><?= field_error('name') ?></div>
                <div class="form-actions full"><button class="btn btn-outline btn-sm" type="submit">Create column</button></div>
            </form>
            <p class="help mt-1 mb-0">Footer columns appear in the footer automatically, in the order they were created.</p>
        </section>
    </aside>
</div>
</div>
<?= site_page_datalist() ?>
<?= site_icon_sprite() ?>
<script src="<?= e(asset('assets/js/modules/admin-site.js')) ?>" defer></script>
<?php
admin_page_end();
