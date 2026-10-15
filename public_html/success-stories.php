<?php
require_once __DIR__ . '/functions.php';

$where = "status = 'published' AND category = 'success_story' AND published_at <= NOW()";
$p = paginate((int) db_value("SELECT COUNT(*) FROM blog_posts WHERE $where"), 9);
$stories = db_rows("SELECT * FROM blog_posts WHERE $where ORDER BY published_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");
$testimonials = db_rows("SELECT * FROM testimonials WHERE status = 'published' ORDER BY sort_order ASC, id DESC");

$pageTitle = 'Success Stories';
$pageDescription = 'Real stories of change from the communities Prerit Welfare Foundation works with.';
require __DIR__ . '/header.php';

page_banner('Success Stories', 'Real stories of change from the communities we work with');
?>
<section class="section">
    <div class="container">
        <?php if ($stories): ?>
            <div class="grid grid-3"><?php foreach ($stories as $post) { echo blog_card($post); } ?></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state('Success stories from our programmes will be shared here soon.') ?>
        <?php endif; ?>
    </div>
</section>
<?php if ($testimonials): ?>
<section class="section section-cream">
    <div class="container">
        <div class="section-head center"><span class="pill">Voices</span><h2>In their own words</h2></div>
        <div class="grid grid-3">
            <?php foreach ($testimonials as $t): ?>
                <figure class="testimonial">
                    <p>“<?= e($t['message']) ?>”</p>
                    <figcaption class="testimonial-person">
                        <?php if ($t['photo']): ?>
                            <img src="<?= e(image_url($t['photo'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="avatar"><?= e(mb_strtoupper(mb_substr($t['name'], 0, 1))) ?></span>
                        <?php endif; ?>
                        <span><strong><?= e($t['name']) ?></strong><small><?= e($t['designation']) ?></small></span>
                    </figcaption>
                </figure>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
