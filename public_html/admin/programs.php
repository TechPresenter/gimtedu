<?php
/** Programmes: list in display order, quick publish / home-page toggles and sort order. */
require_once __DIR__ . '/includes/auth.php';
require_permission('programs.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('programs.php');
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $program = $id ? db_row('SELECT * FROM programs WHERE id = ?', [$id]) : null;

    if ($action === 'order') {
        $n = 0;
        foreach ((array) ($_POST['sort'] ?? []) as $pid => $sort) {
            if (is_scalar($sort) && preg_match('/^-?\d{1,6}$/', trim((string) $sort))) {
                $n += db_update('programs', ['sort_order' => (int) $sort], 'id = :id', ['id' => (int) $pid]);
            }
        }
        admin_log('programs_reordered', 'Updated the display order of programmes');
        flash('success', 'Display order saved.');
    } elseif ($program && $action === 'toggle_status') {
        $new = $program['status'] === 'published' ? 'draft' : 'published';
        db_update('programs', ['status' => $new], 'id = :id', ['id' => $id]);
        $pageNote = '';
        if ($program['page_id']) {
            $pageRow = db_row('SELECT id, status, is_system FROM pages WHERE id = ?', [$program['page_id']]);
            if ($pageRow && $new === 'draft' && $pageRow['status'] === 'published') {
                db_update('pages', ['status' => 'draft', 'updated_by' => admin_id()], 'id = :id', ['id' => $pageRow['id']]);
                $pageNote = ' Its page was unpublished too.';
            } elseif ($pageRow && $new === 'published' && $pageRow['status'] !== 'published') {
                db_update('pages', ['status' => 'published', 'updated_by' => admin_id(), 'published_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $pageRow['id']]);
                $pageNote = ' Its page was published too.';
            }
        }
        admin_log('program_' . ($new === 'published' ? 'published' : 'unpublished'), 'Programme #' . $id . ' "' . $program['title'] . '"' . $pageNote);
        flash('success', '"' . $program['title'] . '" is now ' . ($new === 'published' ? 'published' : 'a draft (hidden from visitors)') . '.' . $pageNote);
    } elseif ($program && $action === 'toggle_home') {
        $new = (int) $program['show_on_home'] === 1 ? 0 : 1;
        db_update('programs', ['show_on_home' => $new], 'id = :id', ['id' => $id]);
        admin_log('program_updated', 'Programme #' . $id . ' "' . $program['title'] . '": show on home ' . ($new ? 'on' : 'off'));
        flash('success', '"' . $program['title'] . '" will ' . ($new ? 'now' : 'no longer') . ' be shown on the home page.');
    } else {
        flash('error', 'Programme not found.');
    }
    admin_redirect('programs.php');
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['p.title', 'p.slug', 'p.summary'], $q, $params);
if (in_array($status, ['published', 'draft'], true)) {
    $where .= ' AND p.status = ?';
    $params[] = $status;
}
$rows = db_rows(program_select_sql() . ' ' . $where . ' ORDER BY p.sort_order, p.title', $params);
$counts = db_row("SELECT COUNT(*) AS total, SUM(status = 'published') AS published, SUM(status = 'draft') AS draft, SUM(show_on_home = 1 AND status = 'published') AS home FROM programs");
$linked = [];
foreach (db_rows("SELECT program_id, COUNT(*) AS n FROM campaigns WHERE program_id IS NOT NULL GROUP BY program_id") as $r) {
    $linked[(int) $r['program_id']]['campaigns'] = (int) $r['n'];
}
foreach (db_rows("SELECT program_id, COUNT(*) AS n FROM events WHERE program_id IS NOT NULL GROUP BY program_id") as $r) {
    $linked[(int) $r['program_id']]['events'] = (int) $r['n'];
}

admin_page_start('Programmes', [['label' => 'New programme', 'url' => admin_url('program-edit.php'), 'icon' => 'plus']]);
?>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle"><?= icon('target') ?></span><div><strong><?= (int) $counts['total'] ?></strong><small>Programmes</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) $counts['published'] ?></strong><small>Published</small></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('edit') ?></span><div><strong><?= (int) $counts['draft'] ?></strong><small>Drafts</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('home') ?></span><div><strong><?= (int) $counts['home'] ?></strong><small>Shown on home page</small></div></div>
</div>

<section class="panel">
    <div class="panel-head"><h2>All programmes</h2><a class="btn btn-ghost btn-sm" href="<?= e(url('/programs/')) ?>" target="_blank" rel="noopener"><?= icon('external') ?>View programmes page</a></div>
    <?php admin_filter_bar(['published', 'draft'], ['placeholder' => 'Search title, slug or summary']); ?>
    <p class="muted small">Each programme has its own page (banner, sections, FAQs). Use <strong>Edit page content</strong> to change the sections; use <strong>Edit</strong> for the card details shown in programme lists. Lower order numbers are shown first.</p>
    <?php if (!$rows): ?>
        <?= empty_state($q !== '' || $status !== '' ? 'No programmes match your filter.' : 'No programmes yet. Create the first one.', 'New programme', 'admin/program-edit.php') ?>
    <?php else: ?>
        <form id="order-form" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order"></form>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Order</th><th scope="col">Programme</th><th scope="col">Page</th><th scope="col">Status</th><th scope="col">Home page</th><th scope="col">Linked</th><th scope="col" class="text-right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $p): $pid = (int) $p['id']; ?>
                    <tr>
                        <td><input class="order-input" type="number" name="sort[<?= $pid ?>]" value="<?= (int) $p['sort_order'] ?>" form="order-form" style="width:76px" aria-label="Display order of <?= e($p['title']) ?>"></td>
                        <td style="min-width:250px">
                            <div style="display:flex;gap:12px;align-items:center">
                                <img class="thumb" src="<?= e(image_url(program_art($p))) ?>" alt="" style="object-fit:contain">
                                <div><strong><?= e($p['title']) ?></strong><br><small class="muted"><?= icon((string) ($p['icon'] ?: 'heart')) ?> <?= e($p['slug']) ?> · <?= e(program_tones()[program_tone($p)]) ?></small></div>
                            </div>
                        </td>
                        <td>
                            <?php if ($p['page_slug']): ?>
                                <a class="small" style="word-break:break-all" href="<?= e(page_url($p['page_slug'])) ?>" target="_blank" rel="noopener">/<?= e($p['page_slug']) ?>/</a><br><?= status_badge($p['page_status']) ?>
                            <?php else: ?>
                                <span class="badge badge-red">No page</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= $pid ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" title="<?= $p['status'] === 'published' ? 'Unpublish (also unpublishes its page)' : 'Publish (also publishes its page)' ?>"><?= status_badge($p['status']) ?></button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_home"><input type="hidden" name="id" value="<?= $pid ?>">
                                <button class="btn btn-ghost btn-sm" type="submit" aria-label="<?= (int) $p['show_on_home'] ? 'Hide from' : 'Show on' ?> home page"><?= icon((int) $p['show_on_home'] ? 'check-circle' : 'x') ?><?= (int) $p['show_on_home'] ? 'Shown' : 'Hidden' ?></button>
                            </form>
                        </td>
                        <td class="small nowrap"><?= (int) ($linked[$pid]['campaigns'] ?? 0) ?> campaigns<br><?= (int) ($linked[$pid]['events'] ?? 0) ?> events</td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('program-edit.php?id=' . $pid)) ?>" title="Edit programme" aria-label="Edit <?= e($p['title']) ?>"><?= icon('edit') ?></a>
                            <?php if ($p['detail_page_id'] && admin_can('pages.manage')): ?>
                                <a class="icon-btn" href="<?= e(admin_url('page-edit.php?id=' . (int) $p['detail_page_id'])) ?>" title="Edit page content" aria-label="Edit page content of <?= e($p['title']) ?>"><?= icon('layers') ?></a>
                            <?php endif; ?>
                            <?php if ($p['page_slug']): ?>
                                <a class="icon-btn" href="<?= e(page_url($p['page_slug']) . ($p['page_status'] !== 'published' ? '?preview=1' : '')) ?>" target="_blank" rel="noopener" title="View page" aria-label="View page of <?= e($p['title']) ?>"><?= icon('eye') ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-sm" type="submit" form="order-form"><?= icon('check') ?>Save order</button></div>
    <?php endif; ?>
</section>
<?php
admin_page_end();
