<?php
require_once __DIR__ . '/functions.php';

$page = require_page('our-team');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['About' => 'about.php']);
?>
<section class="section">
    <div class="container narrow">
        <article class="prose"><?= $page['content'] ?></article>
        <div class="cta-band mt-4">
            <div>
                <h2>Want to join our team?</h2>
                <p>We welcome volunteers, volunteer teachers and professionals who share our values.</p>
            </div>
            <div class="cta-actions">
                <a class="btn btn-donate" href="<?= e(url('volunteer-staff.php')) ?>">Work With Us</a>
                <a class="btn btn-light" href="<?= e(url('volunteer.php')) ?>">Volunteer</a>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
