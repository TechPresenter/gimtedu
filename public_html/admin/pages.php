<?php
/**
 * Pages: list (search, status/template filters, pagination), create, publish / unpublish / archive,
 * duplicate (with sections), delete (custom pages only), preview links.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('pages.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('pages.php');
    $action = (string) ($_POST['action'] ?? '');
    $back = admin_back('pages.php');

    if ($action === 'create') {
        [$data, $errors] = validate($_POST, ['title' => 'required|min:2|max:200', 'template' => 'required|in:default,policy', 'parent_id' => 'integer']);
        if ($errors) {
            back_with_errors($errors, 'admin/pages.php', $_POST);
        }
        $slug = unique_slug('pages', $data['title']);
        if (in_array(explode('/', $slug)[0], SITE_RESERVED_SLUGS, true) || $slug === 'home') {
            $slug = unique_slug('pages', $data['title'] . ' page');
        }
        $parentId = (int) $data['parent_id'] && db_value('SELECT COUNT(*) FROM pages WHERE id = ?', [(int) $data['parent_id']]) ? (int) $data['parent_id'] : null;
        $newId = db_insert('pages', [
            'slug' => $slug, 'template' => $data['template'], 'title' => $data['title'], 'parent_id' => $parentId,
            'status' => 'draft', 'is_system' => 0, 'show_in_sitemap' => 1, 'noindex' => 0,
            'sort_order' => (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM pages'),
            'created_by' => admin_id(), 'updated_by' => admin_id(),
        ]);
        admin_log('page_created', 'Page #' . $newId . ' "' . $data['title'] . '" created as draft (/' . $slug . '/, template ' . $data['template'] . ')');
        flash('success', 'Page created as a draft. Add its sections, then publish it.');
        admin_redirect('page-edit.php?id=' . $newId . '&tab=sections');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $page = $id ? db_row('SELECT * FROM pages WHERE id = ?', [$id]) : null;
    if (!$page) {
        flash('error', 'Page not found.');
        admin_redirect($back);
    }
    $label = 'Page #' . $id . ' "' . $page['title'] . '" (/' . $page['slug'] . '/)';

    if (in_array($action, ['publish', 'draft', 'archive'], true)) {
        $status = ['publish' => 'published', 'draft' => 'draft', 'archive' => 'archived'][$action];
        if (in_array($page['slug'], ['home', 'not-found'], true) && $status !== 'published') {
            flash('error', 'The homepage and the "not found" page must stay published.');
            admin_redirect($back);
        }
        db_update('pages', ['status' => $status, 'updated_by' => admin_id(), 'published_at' => $page['published_at'] ?: ($status === 'published' ? date('Y-m-d H:i:s') : null)], 'id = :id', ['id' => $id]);
        site_touch_page($id);
        admin_log('page_' . $status, $label . ' is now ' . $status);
        flash('success', '"' . $page['title'] . '" is now ' . ($status === 'published' ? 'published and visible on the website.' : $status . ' (hidden from visitors).'));
        admin_redirect($back);
    }

    if ($action === 'duplicate') {
        $template = in_array($page['template'], ['default', 'policy', 'home'], true) ? ($page['template'] === 'home' ? 'default' : $page['template']) : 'default';
        $copy = $page;
        unset($copy['id'], $copy['created_at'], $copy['updated_at']);
        $copy['title'] = mb_substr($page['title'] . ' (copy)', 0, 200);
        $copy['slug'] = unique_slug('pages', $page['title'] . ' copy');
        $copy['template'] = $template;
        $copy['status'] = 'draft';
        $copy['is_system'] = 0;
        $copy['published_at'] = null;
        $copy['canonical_url'] = null;
        $copy['created_by'] = admin_id();
        $copy['updated_by'] = admin_id();
        $newId = db_insert('pages', $copy);
        $n = 0;
        foreach (db_rows('SELECT * FROM page_sections WHERE page_id = ? ORDER BY sort_order, id', [$id]) as $s) {
            unset($s['id'], $s['created_at'], $s['updated_at']);
            $s['page_id'] = $newId;
            $s['updated_by'] = admin_id();
            db_insert('page_sections', $s);
            $n++;
        }
        admin_log('page_duplicated', $label . ' copied to page #' . $newId . ' (/' . $copy['slug'] . '/) with ' . $n . ' sections');
        flash('success', 'Copy created as a draft with ' . $n . ' section(s).' . ($template !== $page['template'] ? ' The copy uses the standard content template because the original page has a built-in function.' : ''));
        admin_redirect('page-edit.php?id=' . $newId);
    }

    if ($action === 'delete') {
        if ((int) $page['is_system'] === 1) {
            flash('error', 'Built-in pages cannot be deleted. You can set them to draft or archived instead.');
            admin_redirect($back);
        }
        $url = '/' . $page['slug'] . '/';
        $menuRefs = (int) db_value('SELECT COUNT(*) FROM menu_items WHERE url = ?', [$url]);
        db_query('DELETE FROM pages WHERE id = ? AND is_system = 0', [$id]);
        // redirects that led to this page would now end on "not found"
        $redirects = db_query('DELETE FROM redirects WHERE target_url = ?', [$url])->rowCount();
        admin_log('page_deleted', $label . ' deleted with its sections' . ($redirects ? ' and ' . $redirects . ' redirect(s) to it' : ''));
        flash('success', '"' . $page['title'] . '" was deleted' . ($redirects ? ', together with ' . $redirects . ' redirect(s) that pointed to it' : '') . '.'
            . ($menuRefs ? ' Note: ' . $menuRefs . ' menu link(s) still point to ' . $url . ' - update them in Menus or add a redirect.' : ''));
        admin_redirect($back);
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$template = (string) ($_GET['template'] ?? '');
$where = 'WHERE 1=1';
$params = [];
$where .= search_where(['p.title', 'p.slug', 'p.meta_title'], $q, $params);
if (in_array($status, ['published', 'draft', 'archived'], true)) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
if ($template !== '' && isset(TEMPLATE_LABELS[$template])) {
    $where .= ' AND p.template = ?';
    $params[] = $template;
}
$total = (int) db_value("SELECT COUNT(*) FROM pages p $where", $params);
$pg = paginate($total, 25);
$rows = db_rows("SELECT p.*, u.name AS editor_name, par.title AS parent_title,
                        (SELECT COUNT(*) FROM page_sections s WHERE s.page_id = p.id) AS section_count
                 FROM pages p LEFT JOIN users u ON u.id = p.updated_by LEFT JOIN pages par ON par.id = p.parent_id
                 $where ORDER BY (p.slug = 'home') DESC, p.sort_order, p.title LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$counts = [];
foreach (db_rows('SELECT status, COUNT(*) c FROM pages GROUP BY status') as $c) {
    $counts[$c['status']] = (int) $c['c'];
}
$usedTemplates = array_column(db_rows('SELECT DISTINCT template FROM pages ORDER BY template'), 'template');
$parents = db_rows('SELECT id, title, slug FROM pages ORDER BY title');

admin_page_start('Pages & Sections');
?>
<div class="site-admin">
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('pages.php?status=published')) ?>"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) ($counts['published'] ?? 0) ?></strong><small>Published</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('pages.php?status=draft')) ?>"><span class="icon-circle gold"><?= icon('edit') ?></span><div><strong><?= (int) ($counts['draft'] ?? 0) ?></strong><small>Drafts</small></div></a>
    <a class="stat-card" href="<?= e(admin_url('pages.php?status=archived')) ?>"><span class="icon-circle blue"><?= icon('inbox') ?></span><div><strong><?= (int) ($counts['archived'] ?? 0) ?></strong><small>Archived</small></div></a>
</div>

<section class="panel">
    <div class="panel-head"><h2><?= icon('plus') ?>New page</h2><span class="muted small">New pages start as drafts; only staff can preview them.</span></div>
    <form method="post" action="<?= e(admin_url('pages.php')) ?>" class="site-create">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-group"><label for="new-title">Title<span class="req">*</span></label><input id="new-title" name="title" required minlength="2" maxlength="200" value="<?= e(old('title')) ?>" placeholder="e.g. Health Camps"><?= field_error('title') ?></div>
        <div class="form-group"><label for="new-template">Template</label><select id="new-template" name="template"><option value="default">Standard content page</option><option value="policy">Policy / long text</option></select></div>
        <div class="form-group"><label for="new-parent">Parent page (optional)</label><select id="new-parent" name="parent_id"><option value="">- None -</option>
            <?php foreach ($parents as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['title']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="submit"><?= icon('plus') ?>Create page</button>
    </form>
    <p class="help mt-1 mb-0">The web address is made from the title (you can change it later). The homepage is the page with the address "home" - edit it like any other page.</p>
</section>

<section class="panel">
    <?php
    $tplSelect = '<select name="template" aria-label="Template"><option value="">All templates</option>';
    foreach ($usedTemplates as $t) {
        $tplSelect .= '<option value="' . e($t) . '"' . ($template === $t ? ' selected' : '') . '>' . e(TEMPLATE_LABELS[$t] ?? $t) . '</option>';
    }
    $tplSelect .= '</select>';
    admin_filter_bar(['published', 'draft', 'archived'], ['placeholder' => 'Search title or address', 'html' => $tplSelect, 'active' => $template !== '']);
    ?>
    <div class="table-meta"><span><?= $total ?> page<?= $total === 1 ? '' : 's' ?></span><span>Built-in pages (with a form or list) cannot be deleted.</span></div>
    <?php if (!$rows): ?>
        <?= empty_state('No pages match your search.') ?>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Page</th><th>Template</th><th>Status</th><th>Sections</th><th>Last updated</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $p):
            $url = page_url($p['slug']);
            $edit = admin_url('page-edit.php?id=' . $p['id']); ?>
            <tr>
                <td class="site-title-cell"><strong><a href="<?= e($edit) ?>"><?= e($p['title']) ?></a></strong>
                    <small>/<?= e($p['slug'] === 'home' ? '' : $p['slug'] . '/') ?><?= $p['slug'] === 'home' ? ' · Homepage' : '' ?><?= $p['parent_title'] ? ' · under ' . e($p['parent_title']) : '' ?><?= (int) $p['noindex'] ? ' · noindex' : '' ?></small>
                    <?php if ($p['slug'] === 'home' && function_exists('home_hero_admin_summary') && ($heroSum = home_hero_admin_summary()) && $heroSum['exists']): ?><small><a href="<?= e(admin_url($heroSum['href'])) ?>"><?= icon('image') ?>Edit hero slider (text &amp; right-side images)</a></small><?php endif; ?></td>
                <td><?= e(TEMPLATE_LABELS[$p['template']] ?? $p['template']) ?><?= (int) $p['is_system'] ? '<br><span class="badge badge-gray">Built-in</span>' : '' ?></td>
                <td><?= status_badge($p['status']) ?></td>
                <td><a href="<?= e(admin_url('page-edit.php?id=' . $p['id'] . '&tab=sections')) ?>"><?= (int) $p['section_count'] ?></a></td>
                <td class="nowrap"><?= e(format_date($p['updated_at'] ?: $p['created_at'], 'd M Y, H:i')) ?><?php if ($p['editor_name']): ?><br><small class="muted">by <?= e($p['editor_name']) ?></small><?php endif; ?></td>
                <td><div class="site-actions">
                    <a class="icon-btn" href="<?= e($edit) ?>" aria-label="Edit <?= e($p['title']) ?>" title="Edit"><?= icon('edit') ?></a>
                    <?php if (in_array($p['template'], LINKLESS_TEMPLATES, true)): ?>
                        <?php /* opens only from a private donation link: a bare link would be a 404 */ ?>
                    <?php elseif ($p['status'] === 'published'): ?>
                        <a class="icon-btn" href="<?= e($url) ?>" target="_blank" rel="noopener" aria-label="View <?= e($p['title']) ?> on the website" title="View on website"><?= icon('external') ?></a>
                    <?php else: ?>
                        <a class="icon-btn" href="<?= e($url . '?preview=1') ?>" target="_blank" rel="noopener" aria-label="Preview <?= e($p['title']) ?>" title="Preview (staff only)"><?= icon('eye') ?></a>
                    <?php endif; ?>
                    <?php if ($p['status'] !== 'published'): ?>
                        <form method="post" action="<?= e(admin_url('pages.php')) ?>"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="icon-btn" type="submit" aria-label="Publish <?= e($p['title']) ?>" title="Publish"><?= icon('check-circle') ?></button></form>
                    <?php elseif (!in_array($p['slug'], ['home', 'not-found'], true)): ?>
                        <form method="post" action="<?= e(admin_url('pages.php')) ?>" data-confirm="Unpublish this page? Visitors will no longer see it."><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="draft"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="icon-btn" type="submit" aria-label="Unpublish <?= e($p['title']) ?>" title="Unpublish (draft)"><?= icon('lock') ?></button></form>
                    <?php endif; ?>
                    <?php if ($p['status'] !== 'archived' && !in_array($p['slug'], ['home', 'not-found'], true)): ?>
                        <form method="post" action="<?= e(admin_url('pages.php')) ?>" data-confirm="Archive this page? It will be hidden from visitors."><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="icon-btn" type="submit" aria-label="Archive <?= e($p['title']) ?>" title="Archive"><?= icon('inbox') ?></button></form>
                    <?php endif; ?>
                    <form method="post" action="<?= e(admin_url('pages.php')) ?>"><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="icon-btn" type="submit" aria-label="Duplicate <?= e($p['title']) ?>" title="Duplicate (with sections)"><?= icon('copy') ?></button></form>
                    <?php if (!(int) $p['is_system']): ?>
                        <form method="post" action="<?= e(admin_url('pages.php')) ?>" data-confirm="Delete &quot;<?= e($p['title']) ?>&quot; and all its sections permanently? This cannot be undone."><?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="icon-btn danger" type="submit" aria-label="Delete <?= e($p['title']) ?>" title="Delete"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?= pagination_links($pg) ?>
    <?php endif; ?>
</section>
</div>
<?php
admin_page_end();
