<?php
require_once __DIR__ . '/functions.php';

$page = require_page('about');
$details = ngo_details();
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle']);
?>
<section class="section">
    <div class="container program-layout">
        <article class="prose"><?= $page['content'] ?></article>
        <aside class="program-aside">
            <div class="aside-card text-center">
                <img src="<?= e(site_logo()) ?>" alt="<?= e(site_name()) ?> logo" width="160" height="160" style="margin:0 auto 10px">
                <p class="hindi mb-0" style="color:var(--orange-500);font-weight:500"><?= e(setting('site_tagline')) ?></p>
                <p class="muted small mb-0"><?= e(setting('site_tagline_en')) ?></p>
            </div>
            <?php if ($details): ?>
                <div class="aside-card">
                    <h3>NGO details</h3>
                    <dl class="detail-list" style="grid-template-columns:1fr">
                        <?php foreach ($details as $label => $value): ?>
                            <dt><?= e($label) ?></dt><dd><?= e($value) ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endif; ?>
            <div class="aside-card aside-cta">
                <h3>Join our mission</h3>
                <p>Support our work with a donation or give your time as a volunteer.</p>
                <a class="btn btn-donate btn-block" href="<?= e(url('donate.php')) ?>"><?= icon('heart') ?>Donate Now</a>
                <a class="btn btn-outline btn-block" href="<?= e(url('volunteer.php')) ?>">Volunteer With Us</a>
            </div>
        </aside>
    </div>
</section>

<section class="section section-cream">
    <div class="container">
        <div class="section-head center"><span class="pill">What we do</span><h2>Our programme areas</h2></div>
        <div class="grid grid-4">
            <?php foreach (PROGRAMS as $p): ?>
                <a class="work-card tone-<?= e($p['tone']) ?>" href="<?= e(url($p['file'])) ?>">
                    <span class="work-icon"><?= icon($p['icon']) ?></span>
                    <h3><?= e($p['title']) ?></h3>
                    <p class="mb-0"><?= e($p['summary']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
