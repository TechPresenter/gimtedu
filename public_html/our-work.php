<?php
require_once __DIR__ . '/functions.php';

$page = require_page('our-work');
$campaigns = db_rows(campaign_sql() . " WHERE c.status = 'active' ORDER BY c.is_urgent DESC, c.created_at DESC LIMIT 3");
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle']);
?>
<section class="section section-cream with-lines">
    <div class="container">
        <div class="section-head"><div class="prose"><?= $page['content'] ?></div></div>
        <div class="grid grid-3">
            <?php foreach (PROGRAMS as $p): ?>
                <article class="work-card tone-<?= e($p['tone']) ?>">
                    <span class="work-icon"><?= icon($p['icon']) ?></span>
                    <h3><a href="<?= e(url($p['file'])) ?>"><?= e($p['title']) ?></a></h3>
                    <p><?= e($p['summary']) ?></p>
                    <ul class="dot-list">
                        <?php foreach ($p['points'] as $point): ?><li><?= e($point) ?></li><?php endforeach; ?>
                    </ul>
                    <a class="link-arrow" href="<?= e(url($p['file'])) ?>">Learn more <?= icon('arrow-right') ?></a>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php if ($campaigns): ?>
<section class="section">
    <div class="container">
        <div class="section-head"><span class="pill">Support our work</span><h2>Active campaigns</h2></div>
        <div class="grid grid-3"><?php foreach ($campaigns as $c) { echo campaign_card($c); } ?></div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
