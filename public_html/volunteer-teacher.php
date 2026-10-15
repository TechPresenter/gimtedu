<?php
require_once __DIR__ . '/functions.php';

$page = require_page('volunteer-teacher');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Volunteer' => 'volunteer.php']);
?>
<section class="section">
    <div class="container program-layout">
        <div>
            <article class="prose"><?= $page['content'] ?></article>
            <div class="grid grid-3 mt-4">
                <div class="work-card tone-blue"><span class="work-icon"><?= icon('file-text') ?></span><h3>1. Apply</h3><p class="mb-0">Fill in the application and upload your CV (optional).</p></div>
                <div class="work-card tone-gold"><span class="work-icon"><?= icon('message') ?></span><h3>2. Conversation</h3><p class="mb-0">Our team will contact shortlisted applicants for a short discussion.</p></div>
                <div class="work-card tone-green"><span class="work-icon"><?= icon('graduation') ?></span><h3>3. Start teaching</h3><p class="mb-0">After orientation you are matched with a class or learning circle.</p></div>
            </div>
        </div>
        <aside class="program-aside">
            <div class="aside-card aside-cta">
                <h3>Teach with us</h3>
                <p>In person or online, a few hours a week can change a child's future.</p>
                <a class="btn btn-blue btn-block" href="<?= e(url('apply-teacher.php')) ?>">Apply as Volunteer Teacher</a>
            </div>
            <div class="aside-card">
                <h3>Policies</h3>
                <ul class="link-list">
                    <li><a href="<?= e(url('child-protection-policy.php')) ?>"><?= icon('shield') ?>Child Protection Policy</a></li>
                    <li><a href="<?= e(url('volunteer-policy.php')) ?>"><?= icon('file-text') ?>Volunteer Policy</a></li>
                </ul>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
