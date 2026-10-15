<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('content');

$cat = (string) ($_GET['category'] ?? '');
$params = [];
$where = 'WHERE 1=1';
if (isset(GALLERY_CATEGORIES[$cat])) {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM gallery $where", $params), 24);
$items = db_rows("SELECT * FROM gallery $where ORDER BY sort_order ASC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$adminTitle = 'Gallery';
require __DIR__ . '/includes/header.php';
?>
<div class="panel">
    <div class="panel-head"><h3>Upload photos</h3><span class="muted small">JPG, PNG or WebP, max <?= MAX_UPLOAD_MB ?> MB each, up to 20 at a time. Large photos are resized automatically.</span></div>
    <form class="filter-row mb-0" method="post" action="actions/upload-image.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="type" value="gallery">
        <input type="text" name="title" maxlength="150" placeholder="Title / caption (optional)" aria-label="Title">
        <select name="category" aria-label="Category"><?= options_html(GALLERY_CATEGORIES, 'general', true, '') ?></select>
        <input type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp" multiple required aria-label="Photos">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('plus') ?>Upload</button>
    </form>
</div>
<form class="filter-row" method="get">
    <select name="category" aria-label="Filter by category" data-autosubmit><option value="">All categories</option><?= options_html(GALLERY_CATEGORIES, $cat, true, '') ?></select>
    <noscript><button class="btn btn-ghost btn-sm" type="submit">Filter</button></noscript>
    <span class="muted small"><?= number_format($p['total']) ?> photo(s)</span>
</form>
<?php if ($items): ?>
    <div class="gallery-admin">
        <?php foreach ($items as $g): ?>
            <div class="gallery-admin-item">
                <a href="<?= e(url($g['image_path'])) ?>" target="_blank"><img src="<?= e(url($g['image_path'])) ?>" alt="<?= e($g['title']) ?>" loading="lazy"></a>
                <form method="post" action="actions/content-save.php">
                    <?= csrf_field() ?><?= return_field() ?>
                    <input type="hidden" name="type" value="gallery"><input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <input type="text" name="title" maxlength="150" value="<?= e($g['title']) ?>" aria-label="Title" required>
                    <select name="category" aria-label="Category"><?= options_html(GALLERY_CATEGORIES, $g['category'], true, '') ?></select>
                    <div style="display:flex;gap:6px">
                        <select name="status" aria-label="Status"><?= options_html(['published' => 'Published', 'hidden' => 'Hidden'], $g['status'], true, '') ?></select>
                        <input type="number" name="sort_order" value="<?= (int) $g['sort_order'] ?>" aria-label="Order" title="Order" style="width:70px">
                    </div>
                    <input type="hidden" name="description" value="<?= e($g['description']) ?>">
                    <div style="display:flex;gap:6px">
                        <button class="btn btn-primary btn-sm" type="submit" style="flex:1">Save</button>
                        <button class="btn btn-danger btn-sm" type="submit" name="action" value="delete" formnovalidate data-confirm-click="Delete this photo?"><?= icon('trash') ?></button>
                    </div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <?= pagination_links($p) ?>
<?php else: ?>
    <?= empty_state('No photos uploaded yet. Upload real photos from your programmes and events.') ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
