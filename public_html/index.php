<?php
require_once __DIR__ . '/functions.php';

$slides = json_decode(setting('hero_slides', '[]'), true) ?: [];
$campaigns = db_rows(campaign_sql() . " WHERE c.status = 'active' ORDER BY c.is_urgent DESC, c.is_featured DESC, c.created_at DESC LIMIT 3");
$tiles = db_rows("SELECT * FROM gallery WHERE status = 'published' ORDER BY sort_order ASC, id DESC LIMIT 8");
$events = db_rows("SELECT * FROM events WHERE status = 'published' AND start_datetime >= NOW() ORDER BY start_datetime ASC LIMIT 3");
$testimonials = db_rows("SELECT * FROM testimonials WHERE status = 'published' ORDER BY sort_order ASC, id DESC LIMIT 3");
$posts = db_rows("SELECT * FROM blog_posts WHERE status = 'published' AND published_at <= NOW() ORDER BY published_at DESC LIMIT 3");
$counters = setting('show_impact_counters', '1') === '1' ? impact_counters() : null;

$pageTitle = '';
$bodyClass = 'page-home';
require __DIR__ . '/header.php';
?>

<?php if ($slides): ?>
<section class="hero" data-slider aria-roledescription="carousel" aria-label="Highlights">
    <div class="container hero-track">
        <?php foreach ($slides as $i => $slide): ?>
            <div class="hero-slide<?= $i === 0 ? ' is-active' : '' ?>" role="group" aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= count($slides) ?>">
                <div class="hero-content">
                    <?php if (!empty($slide['eyebrow'])): ?><span class="hero-eyebrow"><?= e($slide['eyebrow']) ?></span><?php endif; ?>
                    <?php if ($i === 0): ?>
                        <h1><?= e($slide['title'] ?? '') ?></h1>
                    <?php else: ?>
                        <h2 class="hero-title"><?= e($slide['title'] ?? '') ?></h2>
                    <?php endif; ?>
                    <?php if (!empty($slide['text'])): ?><p><?= e($slide['text']) ?></p><?php endif; ?>
                    <div class="hero-actions">
                        <a class="btn btn-donate btn-lg" href="<?= e(safe_link($slide['button_link'] ?? 'donate.php')) ?>"><?= icon('heart') ?><?= e($slide['button_text'] ?? 'Donate Now') ?></a>
                        <a class="btn btn-light btn-lg" href="<?= e(url('volunteer.php')) ?>">Join as Volunteer</a>
                    </div>
                </div>
                <div class="hero-art">
                    <img src="<?= e(image_url($slide['image'] ?? null, 'assets/images/programs/general.svg')) ?>" alt="" <?= $i === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?> width="480" height="360">
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($slides) > 1): ?>
        <button class="hero-arrow hero-prev" type="button" aria-label="Previous slide"><?= icon('chevron-left') ?></button>
        <button class="hero-arrow hero-next" type="button" aria-label="Next slide"><?= icon('chevron-right') ?></button>
        <div class="hero-dots"></div>
    <?php endif; ?>
</section>
<?php endif; ?>

<section class="section section-cream with-lines" id="our-work">
    <div class="container">
        <div class="section-head">
            <span class="pill"><?= e(site_name()) ?></span>
            <h2><?= e(setting('home_work_title', 'Our Work')) ?></h2>
            <p><?= e(setting('home_work_intro')) ?></p>
        </div>
        <div class="grid grid-4">
            <?php foreach (PROGRAMS as $key => $p): ?>
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

<?php if ($tiles): ?>
<section class="section section-tint">
    <div class="container">
        <div class="section-head center">
            <span class="pill">On the ground</span>
            <h2>Our work in pictures</h2>
        </div>
        <div class="photo-tiles">
            <?php foreach ($tiles as $t): ?>
                <a class="photo-tile" href="<?= e(url($t['image_path'])) ?>" data-lightbox data-caption="<?= e($t['title']) ?>">
                    <span class="photo-frame"><img src="<?= e(url($t['image_path'])) ?>" alt="<?= e($t['title']) ?>" loading="lazy"></span>
                    <span><?= e(GALLERY_CATEGORIES[$t['category']] ?? $t['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="text-center mt-3"><a class="btn btn-outline" href="<?= e(url('gallery.php')) ?>">View full gallery</a></p>
    </div>
</section>
<?php endif; ?>

<section class="section" id="campaigns">
    <div class="container">
        <div class="section-head-row">
            <div class="section-head">
                <span class="pill">Fundraising</span>
                <h2>Active campaigns</h2>
                <p>Every rupee shown as raised comes from verified, successful donations only.</p>
            </div>
            <a class="btn btn-outline" href="<?= e(url('campaigns.php')) ?>">All campaigns <?= icon('arrow-right') ?></a>
        </div>
        <?php if ($campaigns): ?>
            <div class="grid grid-3"><?php foreach ($campaigns as $c) { echo campaign_card($c); } ?></div>
        <?php else: ?>
            <?= empty_state('There are no active fundraising campaigns at the moment. You can still support all our programmes through a general donation.', 'Make a general donation', 'donate.php') ?>
        <?php endif; ?>
    </div>
</section>

<?php if ($counters && $counters['donations'] > 0): ?>
<section class="stats-band" aria-label="Our impact so far">
    <div class="container">
        <div class="stats-grid">
            <div class="stat"><strong data-count="<?= (int) round($counters['raised']) ?>" data-prefix="₹"><?= e(money($counters['raised'])) ?></strong><span>Raised through verified donations</span></div>
            <div class="stat"><strong data-count="<?= $counters['donations'] ?>"><?= number_format($counters['donations']) ?></strong><span>Donations received</span></div>
            <?php if ($counters['volunteers'] > 0): ?>
                <div class="stat"><strong data-count="<?= $counters['volunteers'] ?>"><?= number_format($counters['volunteers']) ?></strong><span>Volunteers onboarded</span></div>
            <?php endif; ?>
            <?php if ($counters['campaigns'] > 0): ?>
                <div class="stat"><strong data-count="<?= $counters['campaigns'] ?>"><?= number_format($counters['campaigns']) ?></strong><span>Campaigns run</span></div>
            <?php endif; ?>
        </div>
        <p class="stats-note">Figures are calculated live from our verified records.</p>
    </div>
</section>
<?php endif; ?>

<section class="section section-tint">
    <div class="container">
        <div class="section-head center">
            <span class="pill">Get involved</span>
            <h2>Be a part of the change</h2>
            <p>Give your time, share your skills or support a cause. Together, let us build a better tomorrow.</p>
        </div>
        <div class="grid grid-3">
            <article class="work-card tone-green">
                <span class="work-icon"><?= icon('users') ?></span>
                <h3>Volunteer With Us</h3>
                <p>Join our education, health, women empowerment and environment activities as a volunteer or intern.</p>
                <a class="btn btn-primary btn-sm mt-1" href="<?= e(url('apply-volunteer.php')) ?>">Apply as Volunteer</a>
            </article>
            <article class="work-card tone-blue">
                <span class="work-icon"><?= icon('graduation') ?></span>
                <h3>Become a Volunteer Teacher</h3>
                <p>Help children with literacy, school subjects and life skills, in person or online.</p>
                <a class="btn btn-blue btn-sm mt-1" href="<?= e(url('apply-teacher.php')) ?>">Apply to Teach</a>
            </article>
            <article class="work-card tone-gold">
                <span class="work-icon"><?= icon('briefcase') ?></span>
                <h3>Work With Us</h3>
                <p>Build a career with purpose. Apply for open positions or send a general application.</p>
                <a class="btn btn-donate btn-sm mt-1" href="<?= e(url('volunteer-staff.php')) ?>">View Opportunities</a>
            </article>
        </div>
    </div>
</section>

<?php if ($events): ?>
<section class="section">
    <div class="container">
        <div class="section-head-row">
            <div class="section-head"><span class="pill">Events</span><h2>Upcoming events</h2></div>
            <a class="btn btn-outline" href="<?= e(url('events.php')) ?>">All events <?= icon('arrow-right') ?></a>
        </div>
        <div class="grid grid-3"><?php foreach ($events as $ev) { echo event_card($ev); } ?></div>
    </div>
</section>
<?php endif; ?>

<?php if ($testimonials): ?>
<section class="section section-cream">
    <div class="container">
        <div class="section-head center"><span class="pill">Voices</span><h2>What people say</h2></div>
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

<?php if ($posts): ?>
<section class="section">
    <div class="container">
        <div class="section-head-row">
            <div class="section-head"><span class="pill">Stories &amp; updates</span><h2>From our blog</h2></div>
            <a class="btn btn-outline" href="<?= e(url('blog.php')) ?>">Read all <?= icon('arrow-right') ?></a>
        </div>
        <div class="grid grid-3"><?php foreach ($posts as $post) { echo blog_card($post); } ?></div>
    </div>
</section>
<?php endif; ?>

<section class="section-sm">
    <div class="container">
        <div class="cta-band">
            <div>
                <h2>Together, let us build a better tomorrow</h2>
                <p class="hindi">मिलकर बनाये बेहतर कल — your support helps vulnerable families access education, healthcare and livelihoods.</p>
            </div>
            <div class="cta-actions">
                <a class="btn btn-donate btn-lg" href="<?= e(url('donate.php')) ?>"><?= icon('heart') ?>Donate Now</a>
                <a class="btn btn-light btn-lg" href="<?= e(url('membership.php')) ?>">Become a Member</a>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
