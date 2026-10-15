<?php
require_once __DIR__ . '/functions.php';

$page = require_page('volunteer');
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
                <h3>Ready to join?</h3>
                <p>The application takes about 5 minutes.</p>
                <a class="btn btn-primary btn-block" href="<?= e(url('apply-volunteer.php')) ?>">Apply as Volunteer</a>
                <a class="btn btn-outline btn-block" href="<?= e(url('apply-volunteer.php?type=intern')) ?>">Apply for Internship</a>
            </div>
            <div class="aside-card">
                <h3>Before you apply</h3>
                <ul class="link-list">
                    <li><a href="<?= e(url('volunteer-policy.php')) ?>"><?= icon('file-text') ?>Volunteer Policy</a></li>
                    <li><a href="<?= e(url('child-protection-policy.php')) ?>"><?= icon('shield') ?>Child Protection Policy</a></li>
                    <li><a href="<?= e(url('application-status.php')) ?>"><?= icon('search') ?>Check application status</a></li>
                </ul>
            </div>
        </aside>
    </div>
</section>
<section class="section section-tint">
    <div class="container">
        <div class="section-head center"><span class="pill">Ways to help</span><h2>Choose how you want to contribute</h2></div>
        <div class="grid grid-4">
            <article class="work-card tone-green">
                <span class="work-icon"><?= icon('users') ?></span>
                <h3>Volunteer</h3>
                <p>Support our field activities, events, awareness drives and campaigns.</p>
                <a class="link-arrow" href="<?= e(url('apply-volunteer.php')) ?>">Apply now <?= icon('arrow-right') ?></a>
            </article>
            <article class="work-card tone-gold">
                <span class="work-icon"><?= icon('award') ?></span>
                <h3>Internship</h3>
                <p>Students can gain hands-on experience in the social sector and receive a certificate on completion.</p>
                <a class="link-arrow" href="<?= e(url('apply-volunteer.php?type=intern')) ?>">Apply now <?= icon('arrow-right') ?></a>
            </article>
            <article class="work-card tone-blue">
                <span class="work-icon"><?= icon('graduation') ?></span>
                <h3>Volunteer Teacher</h3>
                <p>Teach children literacy, school subjects and life skills, in person or online.</p>
                <a class="link-arrow" href="<?= e(url('volunteer-teacher.php')) ?>">Learn more <?= icon('arrow-right') ?></a>
            </article>
            <article class="work-card tone-orange">
                <span class="work-icon"><?= icon('briefcase') ?></span>
                <h3>Work With Us</h3>
                <p>Apply for staff positions and build a career with purpose.</p>
                <a class="link-arrow" href="<?= e(url('volunteer-staff.php')) ?>">View opportunities <?= icon('arrow-right') ?></a>
            </article>
        </div>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
