<?php
/**
 * Blog, news & press releases: list with category / status filters and search; delete.
 * Status "scheduled" = published with a publish date in the future.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('blog.manage');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('blog.php');
    $id = (int) ($_POST['id'] ?? 0);
    $post = $id ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
    if ($post && ($_POST['action'] ?? '') === 'delete') {
        db_query('DELETE FROM blog_posts WHERE id = ?', [$id]);
        content_delete_image($post['image']);
        admin_log('blog_deleted', 'Post #' . $id . ' "' . $post['title'] . '" deleted');
        flash('success', 'The post "' . $post['title'] . '" was deleted.');
    }
    admin_redirect(admin_back('blog.php'));
}

$category = isset(BLOG_CATEGORIES[$_GET['category'] ?? '']) ? (string) $_GET['category'] : '';
$status = in_array($_GET['status'] ?? '', ['draft', 'published', 'scheduled', 'archived'], true) ? (string) $_GET['status'] : '';
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100));
$where = 'WHERE 1';
$params = [];
if ($category !== '') {
    $where .= ' AND b.category = ?';
    $params[] = $category;
}
if ($status === 'published') {
    $where .= " AND b.status = 'published' AND b.published_at <= NOW()";
} elseif ($status === 'scheduled') {
    $where .= " AND b.status = 'published' AND b.published_at > NOW()";
} elseif ($status !== '') {
    $where .= ' AND b.status = ?';
    $params[] = $status;
}
$where .= search_where(['b.title', 'b.slug', 'b.excerpt', 'b.author_name'], $q, $params);
$p = paginate((int) db_value("SELECT COUNT(*) FROM blog_posts b $where", $params), 20);
$rows = db_rows("SELECT b.*, pr.title AS program_title FROM blog_posts b LEFT JOIN programs pr ON pr.id = b.program_id $where
                 ORDER BY (b.status = 'draft') DESC, COALESCE(b.published_at, b.created_at) DESC, b.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$counts = db_row("SELECT SUM(status = 'published' AND published_at <= NOW()) AS live, SUM(status = 'published' AND published_at > NOW()) AS scheduled,
                         SUM(status = 'draft') AS drafts, COALESCE(SUM(views), 0) AS views FROM blog_posts");

admin_page_start('Blog, News & Press', [['label' => 'New post', 'url' => admin_url('blog-edit.php'), 'icon' => 'plus']]);
?>
<div class="stat-cards">
    <a class="stat-card" href="<?= e(admin_url('blog.php?status=published')) ?>"><span class="icon-circle"><?= icon('check-circle') ?></span><span><strong><?= (int) $counts['live'] ?></strong><small>Published</small></span></a>
    <a class="stat-card" href="<?= e(admin_url('blog.php?status=scheduled')) ?>"><span class="icon-circle blue"><?= icon('clock') ?></span><span><strong><?= (int) $counts['scheduled'] ?></strong><small>Scheduled</small></span></a>
    <a class="stat-card" href="<?= e(admin_url('blog.php?status=draft')) ?>"><span class="icon-circle gold"><?= icon('edit') ?></span><span><strong><?= (int) $counts['drafts'] ?></strong><small>Drafts</small></span></a>
    <div class="stat-card"><span class="icon-circle"><?= icon('eye') ?></span><span><strong><?= number_format((int) $counts['views']) ?></strong><small>Article views (visitors)</small></span></div>
</div>

<?php
$catSelect = '<label class="sr-only" for="f-category">Category</label><select id="f-category" name="category"><option value="">All categories</option>';
foreach (BLOG_CATEGORIES as $k => $label) {
    $catSelect .= '<option value="' . e($k) . '"' . ($category === $k ? ' selected' : '') . '>' . e($label) . '</option>';
}
$catSelect .= '</select>';
admin_filter_bar(['published', 'scheduled', 'draft', 'archived'], ['placeholder' => 'Search title, slug, excerpt or author', 'html' => $catSelect, 'active' => $category !== '']);
?>

<section class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Post</th><th>Category</th><th>Status</th><th>Publish date</th><th>Views</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $scheduled = $r['status'] === 'published' && $r['published_at'] && strtotime($r['published_at']) > time(); ?>
                <tr>
                    <td>
                        <div style="display:flex;gap:12px;align-items:center;min-width:240px">
                            <img class="thumb" src="<?= e(image_url($r['image'], CONTENT_FALLBACK_IMAGE)) ?>" alt="" width="64" height="44" loading="lazy">
                            <div><a href="<?= e(admin_url('blog-edit.php?id=' . (int) $r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a>
                                <br><small class="muted">/blog/<?= e($r['slug']) ?>/<?= $r['program_title'] ? ' · ' . e($r['program_title']) : '' ?></small></div>
                        </div>
                    </td>
                    <td><?= e(BLOG_CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                    <td><?= $scheduled ? '<span class="badge badge-blue">Scheduled</span>' : status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= $r['published_at'] ? e(format_date($r['published_at'], 'd M Y, h:i A')) : '<span class="muted">-</span>' ?></td>
                    <td><?= number_format((int) $r['views']) ?></td>
                    <td class="actions-cell">
                        <a class="icon-btn" href="<?= e(admin_url('blog-edit.php?id=' . (int) $r['id'])) ?>" aria-label="Edit <?= e($r['title']) ?>" title="Edit"><?= icon('edit') ?></a>
                        <a class="icon-btn" href="<?= e(post_url($r['slug'])) ?>" target="_blank" rel="noopener" aria-label="View <?= e($r['title']) ?>" title="<?= $r['status'] === 'published' && !$scheduled ? 'View' : 'Preview' ?>"><?= icon('eye') ?></a>
                        <form class="inline-form" method="post" data-confirm="Delete this post permanently?">
                            <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($r['title']) ?>" title="Delete"><?= icon('trash') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state"><?= icon('file-text') ?><p><?= $q !== '' || $category !== '' || $status !== '' ? 'No posts match these filters.' : 'No posts yet. Write the first article, news update or press release.' ?></p>
            <a class="btn btn-primary" href="<?= e(admin_url('blog-edit.php')) ?>"><?= icon('plus') ?>New post</a></div>
    <?php endif; ?>
</section>
<p class="muted small"><?= icon('info') ?> Posts appear on the website only when they are <strong>published</strong> and their publish date has passed. Blog and news posts are listed on <a href="<?= e(page_url('blog')) ?>" target="_blank" rel="noopener">/blog/</a>, press releases on <a href="<?= e(page_url('media')) ?>" target="_blank" rel="noopener">/media/</a>.</p>
<?php
admin_page_end();
