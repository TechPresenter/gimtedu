<?php
/** Welfare schemes directory: list, filters and quick delete. Editing happens in scheme-edit.php. */
require_once __DIR__ . '/includes/auth.php';
require_permission('schemes.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('schemes.php');
    $scheme = db_row('SELECT id, title FROM schemes WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
    if ($scheme && ($_POST['action'] ?? '') === 'delete') {
        db_query('DELETE FROM schemes WHERE id = ?', [$scheme['id']]);
        admin_log('scheme_deleted', 'Scheme #' . $scheme['id'] . ' "' . $scheme['title'] . '" deleted');
        flash('success', 'Scheme "' . $scheme['title'] . '" deleted.');
    } else {
        flash('error', 'Scheme not found.');
    }
    admin_redirect(admin_back('schemes.php'));
}

$cats = scheme_categories();
$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$cat = (string) ($_GET['category'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['title', 'authority', 'summary', 'slug'], $q, $params);
if (in_array($status, ['draft', 'published', 'archived'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if (isset($cats[$cat])) {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
$total = (int) db_value("SELECT COUNT(*) FROM schemes $where", $params);
$pg = paginate($total, 25);
$rows = db_rows("SELECT * FROM schemes $where ORDER BY FIELD(status, 'draft', 'published', 'archived'), sort_order, title LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$counts = db_row("SELECT COUNT(*) AS total, SUM(status = 'published') AS published, SUM(status = 'draft') AS draft,
                  SUM(status = 'published' AND source_checked_on < CURDATE() - INTERVAL 12 MONTH) AS stale FROM schemes");

admin_page_start('Welfare Schemes', [['label' => 'New scheme', 'url' => admin_url('scheme-edit.php'), 'icon' => 'plus']]);
?>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle"><?= icon('list') ?></span><div><strong><?= (int) $counts['total'] ?></strong><small>Schemes</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= (int) $counts['published'] ?></strong><small>Published</small></div></div>
    <div class="stat-card"><span class="icon-circle gold"><?= icon('edit') ?></span><div><strong><?= (int) $counts['draft'] ?></strong><small>Drafts</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('refresh') ?></span><div><strong><?= (int) $counts['stale'] ?></strong><small>Published, not re-checked for 12+ months</small></div></div>
</div>

<div class="alert alert-info"><?= icon('shield') ?><div>Publish only details you have verified on the scheme's <strong>official government source</strong>. A scheme can be published only with an official https:// link and the date you checked it. Re-check published schemes regularly – rules and amounts change.</div></div>

<section class="panel">
    <?php admin_filter_bar(['draft', 'published', 'archived'], [
        'placeholder' => 'Search title, authority or summary',
        'html' => '<select name="category" aria-label="Category"><option value="">All categories</option>' . options_html($cats, isset($cats[$cat]) ? $cat : '', true, '') . '</select>',
        'active' => isset($cats[$cat]),
    ]); ?>
    <div class="table-meta"><span><?= $total ?> <?= $total === 1 ? 'scheme' : 'schemes' ?></span><a href="<?= e(url('/schemes/')) ?>" target="_blank" rel="noopener"><?= icon('external') ?> View public directory</a></div>
    <?php if (!$rows): ?>
        <?= empty_state($q !== '' || $status !== '' || $cat !== '' ? 'No schemes match your filter.' : 'No schemes yet. Add a scheme after verifying its details on the official source.', 'New scheme', 'admin/scheme-edit.php') ?>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">Scheme</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col">Source checked</th><th scope="col">Help</th><th scope="col" class="text-right">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $s): ?>
                    <tr>
                        <td><strong><?= e($s['title']) ?></strong><?php if ($s['authority']): ?><br><small class="muted"><?= e($s['authority']) ?></small><?php endif; ?></td>
                        <td><?= e(scheme_category_label($s['category'])) ?></td>
                        <td><?= status_badge($s['status']) ?></td>
                        <td>
                            <?= e(format_date($s['source_checked_on'])) ?>
                            <?php if ($s['status'] === 'published' && scheme_is_stale($s)): ?><br><span class="badge badge-gold">Re-check due</span><?php endif; ?>
                            <?php if ($s['official_url']): ?><br><a class="small" href="<?= e($s['official_url']) ?>" target="_blank" rel="noopener noreferrer"><?= icon('external') ?> Official source</a><?php endif; ?>
                        </td>
                        <td><?= $s['help_available'] ? 'Yes' : 'No' ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="<?= e(admin_url('scheme-edit.php?id=' . (int) $s['id'])) ?>" title="Edit" aria-label="Edit <?= e($s['title']) ?>"><?= icon('edit') ?></a>
                            <a class="icon-btn" href="<?= e(scheme_url($s['slug']) . ($s['status'] !== 'published' ? '?preview=1' : '')) ?>" target="_blank" rel="noopener" title="View" aria-label="View <?= e($s['title']) ?>"><?= icon('eye') ?></a>
                            <form method="post" class="inline-form" data-confirm="Delete the scheme &quot;<?= e($s['title']) ?>&quot;? This cannot be undone.">
                                <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button class="icon-btn danger" type="submit" title="Delete" aria-label="Delete <?= e($s['title']) ?>"><?= icon('trash') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($pg) ?>
    <?php endif; ?>
</section>
<?php
admin_page_end();
