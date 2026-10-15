<?php
/**
 * Programmes & welfare schemes module (M2).
 */

if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Body of a programme page (education.php, healthcare.php, ...).
 * $d (all optional) - written in each programme file:
 *   highlights  => ui_highlights() items        (focus areas strip)
 *   context     => ['title' => '', 'text' => '', 'points' => []]   (why this matters - no statistics)
 *   activities  => ui_features() items          (what we do, in detail)
 *   approach    => ui_steps() items             (how we work)
 *   audience    => ['title' => '', 'text' => '', 'points' => []]   (who we work with)
 *   help        => ui_features() items          (ways to support)
 *   faqs        => ui_faqs() items
 *   cta         => ['title' => '', 'text' => '']
 */
function render_program_body(string $key, array $d = []): void
{
    $program = PROGRAMS[$key];
    $page = get_page($key);
    $art = image_url($page['banner_image'] ?? null, 'assets/images/programs/' . $key . '.svg');
    $campaigns = db_rows(campaign_sql() . " WHERE c.status = 'active' AND c.category = ? ORDER BY c.is_urgent DESC, c.created_at DESC LIMIT 3", [$key]);
    $gallery = db_rows("SELECT * FROM gallery WHERE status = 'published' AND category IN (?, ?) ORDER BY sort_order, id DESC LIMIT 6",
        [$key, str_replace('healthcare', 'health', $key)]);

    $nav = ['overview' => 'Overview'];
    if (!empty($d['activities'])) { $nav['what-we-do'] = 'What we do'; }
    if (!empty($d['approach'])) { $nav['approach'] = 'Our approach'; }
    if (!empty($d['audience'])) { $nav['who-we-serve'] = 'Who we serve'; }
    if (!empty($d['help'])) { $nav['get-involved'] = 'How you can help'; }
    if (!empty($d['faqs'])) { $nav['faqs'] = 'FAQs'; }
    echo ui_subnav($nav);
    ?>
    <section class="section" id="overview">
        <div class="container program-layout">
            <div class="program-main">
                <div class="prose"><?= $page ? $page['content'] : '' ?></div>
                <?php if (!empty($d['highlights'])): ?><div class="mt-3"><?= ui_highlights($d['highlights']) ?></div><?php endif; ?>
            </div>
            <aside class="program-aside">
                <div class="aside-card program-art tone-<?= e($program['tone']) ?>">
                    <img src="<?= e($art) ?>" alt="<?= e($program['title']) ?>">
                </div>
                <div class="aside-card">
                    <h3>Key activities</h3>
                    <ul class="check-list">
                        <?php foreach ($program['points'] as $point): ?>
                            <li><?= icon('check') ?><span><?= e($point) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="aside-card aside-cta">
                    <h3>Support <?= e($program['title']) ?></h3>
                    <p>Your contribution helps us reach more people through this programme.</p>
                    <a class="btn btn-donate btn-block" href="<?= e(url('donate.php?program=' . $key)) ?>"><?= icon('heart') ?>Donate Now</a>
                    <a class="btn btn-outline btn-block" href="<?= e(url('volunteer.php')) ?>">Volunteer With Us</a>
                </div>
                <div class="aside-card">
                    <h3>Other programmes</h3>
                    <ul class="link-list">
                        <?php foreach (PROGRAMS as $k => $p): if ($k === $key) { continue; } ?>
                            <li><a href="<?= e(url($p['file'])) ?>"><?= icon('chevron-right') ?><?= e($p['title']) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </aside>
        </div>
    </section>

    <?php if (!empty($d['context'])): ?>
    <section class="section section-cream">
        <div class="container">
            <?= ui_split([
                'pill' => 'Why it matters',
                'title' => $d['context']['title'] ?? 'Why this work matters',
                'text' => $d['context']['text'] ?? '',
                'points' => $d['context']['points'] ?? [],
                'image' => 'assets/images/programs/' . $key . '.svg',
                'image_alt' => $program['title'],
            ]) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($d['activities'])): ?>
    <section class="section" id="what-we-do">
        <div class="container">
            <?= ui_heading('What we do', $program['title'], $program['summary']) ?>
            <?= ui_features($d['activities'], count($d['activities']) % 3 === 0 || count($d['activities']) > 4 ? 3 : 2) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($d['approach'])): ?>
    <section class="section section-tint" id="approach">
        <div class="container">
            <?= ui_heading('Our approach', 'How we work', 'Every activity follows a simple, accountable process.', true) ?>
            <?= ui_steps($d['approach']) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($d['audience'])): ?>
    <section class="section" id="who-we-serve">
        <div class="container">
            <?= ui_split([
                'pill' => 'Who we serve',
                'title' => $d['audience']['title'] ?? 'Who we work with',
                'text' => $d['audience']['text'] ?? '',
                'points' => $d['audience']['points'] ?? [],
                'image' => 'assets/images/hero/community.svg',
                'image_alt' => '',
                'reverse' => true,
                'actions' => [['label' => 'Request support', 'url' => 'request-help.php', 'style' => 'outline']],
            ]) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($campaigns): ?>
    <section class="section section-tint">
        <div class="container">
            <?= ui_heading('Support ' . $program['title'] . ' campaigns', 'Active campaigns', 'Amounts raised include verified donations only.') ?>
            <div class="grid grid-3"><?php foreach ($campaigns as $c) { echo campaign_card($c); } ?></div>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($d['help'])): ?>
    <section class="section<?= $campaigns ? '' : ' section-tint' ?>" id="get-involved">
        <div class="container">
            <?= ui_heading('How you can help', 'Get involved', 'There are many ways to support this programme — choose the one that suits you.', true) ?>
            <?= ui_features($d['help'], count($d['help']) >= 4 ? 4 : count($d['help'])) ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($gallery): ?>
    <section class="section">
        <div class="container">
            <?= ui_heading('Moments from the field', 'Gallery') ?>
            <div class="gallery-grid">
                <?php foreach ($gallery as $g): ?>
                    <a class="gallery-item" href="<?= e(url($g['image_path'])) ?>" data-lightbox data-caption="<?= e($g['title']) ?>">
                        <img src="<?= e(url($g['image_path'])) ?>" alt="<?= e($g['title']) ?>" loading="lazy"><span><?= e($g['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($d['faqs'])): ?>
    <section class="section section-cream" id="faqs">
        <div class="container narrow">
            <?= ui_heading('Frequently asked questions', $program['title'], '', true) ?>
            <?= ui_faqs($d['faqs'], true) ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="section-sm">
        <div class="container">
            <?= ui_cta($d['cta']['title'] ?? 'Support ' . $program['title'], $d['cta']['text'] ?? 'Together, let us build a better tomorrow for the communities we serve.', [
                ['label' => 'Donate Now', 'url' => 'donate.php?program=' . $key, 'style' => 'donate', 'icon' => 'heart', 'size' => 'lg'],
                ['label' => 'Volunteer', 'url' => 'volunteer.php', 'style' => 'light', 'size' => 'lg'],
            ], true) ?>
        </div>
    </section>
    <?php
}
