<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId || ($_GET['action'] ?? '') === 'add') {
    $post = $editId ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$editId]) : null;
    if ($editId && !$post) {
        admin_redirect('blog.php');
    }
    $v = function (string $k, string $d = '') use ($post) {
        return (string) old($k, $post[$k] ?? $d);
    };
    $pub = (string) old('published_at', $post['published_at'] ?? '');
    $adminTitle = $post ? 'Edit post' : 'New post';
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions">
        <a class="btn btn-ghost btn-sm" href="blog.php">&larr; All posts</a>
        <?php if ($post && $post['status'] === 'published'): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('blog-details.php?slug=' . urlencode($post['slug']))) ?>" target="_blank"><?= icon('external') ?>View</a><?php endif; ?>
    </div>
    <form method="post" action="actions/content-save.php" enctype="multipart/form-data" data-validate>
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="blog">
        <input type="hidden" name="id" value="<?= (int) ($post['id'] ?? 0) ?>">
        <div class="admin-grid-main">
            <div class="panel">
                <div class="form-group mb-2"><label for="title">Title<span class="req">*</span></label><input id="title" type="text" name="title" required maxlength="220" value="<?= e($v('title')) ?>"><?= field_error('title') ?></div>
                <div class="form-group mb-2"><label for="excerpt">Excerpt<span class="req">*</span></label><textarea id="excerpt" name="excerpt" required maxlength="400" rows="2" style="min-height:70px"><?= e($v('excerpt')) ?></textarea><?= field_error('excerpt') ?></div>
                <div class="form-group"><label for="content">Content<span class="req">*</span></label><textarea id="content" name="content" required data-editor data-upload-url="actions/upload-image.php"><?= e($v('content')) ?></textarea><?= field_error('content') ?></div>
            </div>
            <div>
                <div class="panel">
                    <div class="form-group mb-2"><label for="status">Status</label><select id="status" name="status"><?= options_html(['draft' => 'Draft', 'published' => 'Published'], $v('status', 'draft'), true, '') ?></select></div>
                    <div class="form-group mb-2"><label for="category">Category</label><select id="category" name="category"><?= options_html(BLOG_CATEGORIES, $v('category', 'blog'), true, '') ?></select></div>
                    <div class="form-group mb-2"><label for="published_at">Publish date</label><input id="published_at" type="datetime-local" name="published_at" value="<?= e($pub ? date('Y-m-d\TH:i', strtotime($pub)) : '') ?>"><span class="help">Leave blank to publish now. A future date schedules the post.</span><?= field_error('published_at') ?></div>
                    <div class="form-group mb-2"><label for="author_name">Author</label><input id="author_name" type="text" name="author_name" maxlength="120" value="<?= e($v('author_name')) ?>"></div>
                    <div class="form-group"><label for="image">Featured image</label>
                        <?php if (!empty($post['image'])): ?><img src="<?= e(image_url($post['image'])) ?>" alt="" class="mb-1" style="border-radius:8px;max-height:140px"><?php endif; ?>
                        <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp"><?= field_error('image') ?></div>
                </div>
                <button class="btn btn-primary btn-lg btn-block" type="submit">Save post</button>
            </div>
        </div>
    </form>
    <?php if ($post): ?>
        <form method="post" action="actions/content-save.php" class="mt-3" data-confirm="Delete this post permanently?">
            <?= csrf_field() ?><input type="hidden" name="type" value="blog"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $post['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit"><?= icon('trash') ?>Delete post</button>
        </form>
    <?php endif; ?>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['title', 'excerpt'], $q, $params);
if (in_array($status, ['draft', 'published'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM blog_posts $where", $params), 20);
$rows = db_rows("SELECT * FROM blog_posts $where ORDER BY COALESCE(published_at, created_at) DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$adminTitle = 'Blog, News & Stories';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <?php admin_filter_bar(['draft', 'published'], ['placeholder' => 'Search posts']); ?>
    <a class="btn btn-primary btn-sm" href="?action=add"><?= icon('plus') ?>New post</a>
</div>
<div class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Published</th><th>Views</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="?edit=<?= (int) $r['id'] ?>"><strong><?= e($r['title']) ?></strong></a></td>
                    <td><?= e(BLOG_CATEGORIES[$r['category']] ?? $r['category']) ?></td>
                    <td><?= status_badge($r['status']) ?><?= $r['status'] === 'published' && $r['published_at'] > date('Y-m-d H:i:s') ? ' <span class="badge badge-blue">Scheduled</span>' : '' ?></td>
                    <td class="nowrap"><?= e(format_date($r['published_at'])) ?></td>
                    <td><?= number_format((int) $r['views']) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?edit=<?= (int) $r['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No posts yet.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
