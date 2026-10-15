<?php
require_once __DIR__ . '/functions.php';

$category = (string) ($_GET['category'] ?? '');
if (!isset(GALLERY_CATEGORIES[$category])) {
    $category = '';
}
$used = db_rows("SELECT category, COUNT(*) AS total FROM gallery WHERE status = 'published' GROUP BY category");
$where = "status = 'published'" . ($category ? ' AND category = ?' : '');
$params = $category ? [$category] : [];
$p = paginate((int) db_value("SELECT COUNT(*) FROM gallery WHERE $where", $params), 24);
$items = db_rows("SELECT * FROM gallery WHERE $where ORDER BY sort_order ASC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$pageTitle = 'Gallery';
$pageDescription = 'Photos from the programmes, events and campaigns of Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner('Our Gallery', 'Moments from our programmes, events and campaigns');
?>
<section class="section">
    <div class="container">
        <?php if ($used): ?>
            <nav class="filter-bar" aria-label="Gallery categories">
                <a class="filter-btn<?= $category === '' ? ' is-active' : '' ?>" href="<?= e(url('gallery.php')) ?>">All</a>
                <?php foreach ($used as $u): if (!isset(GALLERY_CATEGORIES[$u['category']])) { continue; } ?>
                    <a class="filter-btn<?= $category === $u['category'] ? ' is-active' : '' ?>" href="<?= e(url('gallery.php?category=' . urlencode($u['category']))) ?>"><?= e(GALLERY_CATEGORIES[$u['category']]) ?> (<?= (int) $u['total'] ?>)</a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php if ($items): ?>
            <div class="gallery-grid">
                <?php foreach ($items as $g): ?>
                    <a class="gallery-item" href="<?= e(url($g['image_path'])) ?>" data-lightbox data-caption="<?= e($g['title'] . ($g['description'] ? ' — ' . $g['description'] : '')) ?>">
                        <img src="<?= e(url($g['image_path'])) ?>" alt="<?= e($g['title']) ?>" loading="lazy">
                        <span><?= e($g['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state('Photos will be added to the gallery soon.') ?>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
