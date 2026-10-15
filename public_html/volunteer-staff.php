<?php
require_once __DIR__ . '/functions.php';

$page = require_page('volunteer-staff');
$positions = staff_positions();
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Get Involved' => null]);
?>
<section class="section">
    <div class="container narrow">
        <article class="prose"><?= $page['content'] ?></article>

        <h2 class="mt-4">Open positions</h2>
        <?php if ($positions): ?>
            <div class="grid" style="gap:14px">
                <?php foreach ($positions as $pos): ?>
                    <div class="position-card">
                        <div>
                            <h3><?= e($pos['title']) ?></h3>
                            <?php if ($pos['description']): ?><p class="muted small mb-0"><?= e($pos['description']) ?></p><?php endif; ?>
                        </div>
                        <a class="btn btn-primary btn-sm" href="<?= e(url('apply-staff.php?position=' . urlencode($pos['title']))) ?>">Apply</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="notice"><?= icon('info') ?><div>There are no open positions listed at the moment. You are welcome to send a general application and we will contact you when a suitable role opens.</div></div>
        <?php endif; ?>

        <div class="cta-band mt-4">
            <div>
                <h2>Don't see the right role?</h2>
                <p>Send us a general application with your CV.</p>
            </div>
            <div class="cta-actions">
                <a class="btn btn-donate" href="<?= e(url('apply-staff.php')) ?>">General Application</a>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
