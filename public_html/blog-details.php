<?php
require_once __DIR__ . '/functions.php';

$slug = (string) ($_GET['slug'] ?? '');
$post = db_row("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' AND published_at <= NOW()", [$slug]);
if (!$post) {
    not_found();
}
db_query('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
$related = db_rows("SELECT * FROM blog_posts WHERE status = 'published' AND published_at <= NOW() AND id <> ? AND category = ?
                    ORDER BY published_at DESC LIMIT 3", [$post['id'], $post['category']]);
$shareUrl = abs_url('blog-details.php?slug=' . urlencode($post['slug']));

$pageTitle = $post['title'];
$pageDescription = $post['excerpt'];
$ogImage = $post['image'] ?: null;
require __DIR__ . '/header.php';

page_banner($post['title'], '', ['Blog' => 'blog.php']);
?>
<section class="section">
    <div class="container narrow">
        <p class="eyebrow"><?= e(BLOG_CATEGORIES[$post['category']] ?? 'Blog') ?> · <?= e(format_date($post['published_at'], 'd M Y')) ?><?= $post['author_name'] ? ' · ' . e($post['author_name']) : '' ?></p>
        <?php if ($post['image']): ?>
            <div class="detail-hero-img"><img src="<?= e(image_url($post['image'])) ?>" alt="<?= e($post['title']) ?>"></div>
        <?php endif; ?>
        <article class="prose"><?= $post['content'] ?></article>
        <div class="share-row">
            <strong class="small">Share:</strong>
            <a href="https://wa.me/?text=<?= e(urlencode($post['title'] . ' ' . $shareUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('whatsapp') ?>WhatsApp</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= e(urlencode($shareUrl)) ?>" target="_blank" rel="noopener noreferrer"><?= icon('facebook') ?>Facebook</a>
            <a href="https://twitter.com/intent/tweet?url=<?= e(urlencode($shareUrl)) ?>&amp;text=<?= e(urlencode($post['title'])) ?>" target="_blank" rel="noopener noreferrer"><?= icon('twitter') ?>X</a>
            <button type="button" data-copy="<?= e($shareUrl) ?>"><?= icon('external') ?>Copy link</button>
        </div>
    </div>
</section>
<?php if ($related): ?>
<section class="section section-tint">
    <div class="container">
        <div class="section-head"><h2>Related articles</h2></div>
        <div class="grid grid-3"><?php foreach ($related as $r) { echo blog_card($r); } ?></div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
