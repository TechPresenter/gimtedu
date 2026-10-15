<?php
require_once __DIR__ . '/functions.php';

$page = require_page('beneficiary');
$pageTitle = $page['title'];
$pageDescription = $page['meta_description'];
require __DIR__ . '/header.php';

page_banner($page['title'], (string) $page['subtitle'], ['Get Involved' => null]);
?>
<section class="section">
    <div class="container program-layout">
        <article class="prose"><?= $page['content'] ?></article>
        <aside class="program-aside">
            <div class="aside-card aside-cta">
                <h3>Need support?</h3>
                <p>Tell us about your situation. All information is kept confidential.</p>
                <a class="btn btn-primary btn-block" href="<?= e(url('request-help.php')) ?>">Request Help</a>
                <a class="btn btn-outline btn-block" href="<?= e(url('scholarship.php')) ?>">Apply for Scholarship</a>
            </div>
            <div class="aside-card">
                <h3>Support areas</h3>
                <ul class="check-list">
                    <li><?= icon('check') ?>Education &amp; study support</li>
                    <li><?= icon('check') ?>Health camps &amp; referrals</li>
                    <li><?= icon('check') ?>Women's livelihood &amp; SHG support</li>
                    <li><?= icon('check') ?>Skill training &amp; placement guidance</li>
                    <li><?= icon('check') ?>Food &amp; disaster relief</li>
                </ul>
            </div>
            <div class="aside-card">
                <h3>Already applied?</h3>
                <a class="link-arrow" href="<?= e(url('application-status.php')) ?>">Check your request status <?= icon('arrow-right') ?></a>
            </div>
        </aside>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
