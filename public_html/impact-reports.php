<?php
require_once __DIR__ . '/functions.php';

$page = require_page('impact-reports');
$docs = public_documents('impact');
$counters = impact_counters();
$stories = db_rows("SELECT * FROM blog_posts WHERE status = 'published' AND category = 'success_story' AND published_at <= NOW() ORDER BY published_at DESC LIMIT 3");
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['About' => 'about.php']);
?>
<section class="section">
    <div class="container narrow">
        <article class="prose"><?= $page['content'] ?></article>

        <?php if ($counters['donations'] > 0 || $counters['events'] > 0 || $counters['volunteers'] > 0): ?>
            <h2 class="mt-4">Verified figures</h2>
            <p class="muted">These numbers are generated automatically from our records: successful, verified donations, approved volunteers and completed events.</p>
            <div class="kv-grid">
                <?php if ($counters['donations'] > 0): ?>
                    <div class="kv"><small>Raised through verified donations</small><strong><?= e(money($counters['raised'])) ?></strong></div>
                    <div class="kv"><small>Donations received</small><strong><?= number_format($counters['donations']) ?></strong></div>
                <?php endif; ?>
                <?php if ($counters['volunteers'] > 0): ?>
                    <div class="kv"><small>Volunteers onboarded</small><strong><?= number_format($counters['volunteers']) ?></strong></div>
                <?php endif; ?>
                <?php if ($counters['events'] > 0): ?>
                    <div class="kv"><small>Events held</small><strong><?= number_format($counters['events']) ?></strong></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h2 class="mt-4">Reports</h2>
        <?= $docs ? document_list($docs) : empty_state('Our impact reports will be published here once they are available.') ?>
    </div>
</section>

<?php if ($stories): ?>
<section class="section section-tint">
    <div class="container">
        <div class="section-head"><span class="pill">Stories of change</span><h2>Success stories</h2></div>
        <div class="grid grid-3"><?php foreach ($stories as $post) { echo blog_card($post); } ?></div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>
