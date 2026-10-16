<?php
/**
 * Programme detail page (template "program", e.g. /programs/healthcare/ or /skill-development/).
 * The programme is the `programs` row linked to this page (page_id) or named in the page settings
 * {"program":"<slug>"}. Content comes from the page's sections; the "module" section (or the end of the
 * page) shows the programme hub: summary and quick actions, linked campaigns / events / stories / articles /
 * photos (only when they exist) and an aside with key activities, support CTAs and other programmes.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$program = program_for_page((int) $page['id']);
if ($program && $program['status'] !== 'published' && !staff_session_can('programs.manage') && !staff_session_can('pages.manage')) {
    not_found();
}

$canonical = $page['canonical_url'] ?: abs_page_url($page['slug']);
$sections = page_sections((int) $page['id']);
$opts = ['canonical' => $canonical];

if ($program) {
    $summary = (string) $program['summary'];
    $cover = !empty($program['cover_image']) && program_art($program) === $program['cover_image'] ? $program['cover_image'] : '';
    $opts['image'] = $cover ?: ($page['banner_image'] ?: program_art($program, false));
    $opts['eyebrow'] = $page['banner_eyebrow'] ?: 'Our programmes';
    $opts['subtitle'] = (string) ($page['banner_subtitle'] ?: $summary);
    $opts['meta_description'] = $page['meta_description'] ?: $summary;
    $opts['og_image'] = $page['og_image'] ?: ($cover ?: '');
    if (!json_field($page['banner_buttons'] ?? null)) {
        $opts['buttons'] = [
            ['label' => 'Support this programme', 'url' => '/donate/', 'style' => 'donate', 'icon' => 'heart'],
            ['label' => 'Volunteer with us', 'url' => '/become-a-volunteer/', 'style' => 'light', 'icon' => 'users'],
        ];
    }
    $org = ['@id' => abs_url('#organization')];
    $opts['schema'] = [
        [
            '@context' => 'https://schema.org', '@type' => 'Service', '@id' => $canonical . '#service',
            'name' => $program['title'], 'serviceType' => $program['title'], 'description' => $summary,
            'url' => $canonical, 'image' => abs_url($opts['image']), 'provider' => $org,
            'areaServed' => ['@type' => 'Country', 'name' => 'India'],
        ],
        [
            '@context' => 'https://schema.org', '@type' => 'WebPage', '@id' => $canonical . '#webpage',
            'url' => $canonical, 'name' => $page['meta_title'] ?: $page['title'], 'description' => $opts['meta_description'],
            'inLanguage' => 'en-IN', 'about' => ['@id' => $canonical . '#service'], 'publisher' => $org,
            'dateModified' => date('c', strtotime($page['updated_at'] ?: $page['created_at'])),
        ],
    ];
}

if (function_exists('seo_eeat_prepare')) {
    seo_eeat_prepare('page', (int) $page['id'], $canonical);   // reviewer / author Person nodes for the page graph (before page_header)
}
page_header($page, $opts);

// On-page navigation from the section anchors (when the editors have not placed a "subnav" section)
$hasSubnav = false;
$links = [];
foreach ($sections as $s) {
    if ($s['section_type'] === 'subnav') {
        $hasSubnav = true;
    }
    if (!empty($s['anchor']) && !empty($s['title'])) {
        $links[preg_replace('/[^a-z0-9\-_]/i', '', $s['anchor'])] = $s['title'];
    }
}
if (!$hasSubnav && count($links) > 2) {
    echo ui_subnav($links);
}

$hub = null;
if ($program) {
    $hub = function () use ($program, $page, $sections): void {
        $anchor = 'at-a-glance';
        $heading = 'At a glance';
        foreach ($sections as $s) {
            if ($s['section_type'] === 'module') {
                $anchor = preg_replace('/[^a-z0-9\-_]/i', '', (string) ($s['anchor'] ?: $anchor));
                $heading = (string) ($s['title'] ?: $heading);
                break;
            }
        }
        $related = program_related($program, $page);
        if (function_exists('seo_image_alt_prime')) {
            seo_image_alt_prime(array_merge(array_column($related['gallery'], 'image_path'), array_column($related['campaigns'], 'image'), array_column($related['events'], 'image')));
        }
        $points = program_key_points($program);
        $others = array_values(array_filter(programs_all(), function ($p) use ($program) {
            return (int) $p['id'] !== (int) $program['id'];
        }));
        $tone = program_tone($program);
        $actions = [
            ['life-buoy', 'Request support', 'For yourself or someone you know', page_url('request-help'), 'blue'],
            ['briefcase', 'Partner with us', 'Schools, colleges, companies and groups', page_url('contact', ['type' => 'partnership']), 'orange'],
            ['flag', 'Start a fundraiser', 'Raise funds for a cause you care about', page_url('start-a-fundraiser'), 'gold'],
            ['id-card', 'Become a member', 'Join the foundation and support its mission', page_url('membership'), 'green'],
        ];
        $follow = [
            ['calendar', 'Events & drives', page_url('events')],
            ['star', 'Success stories', page_url('success-stories')],
            ['image', 'Photo gallery', page_url('gallery')],
            ['mail', 'Newsletter', page_url('newsletter')],
        ];
        ?>
        <section class="section section-white program-hub" id="<?= e($anchor) ?>">
            <div class="container program-layout">
                <div class="program-main">
                    <div class="program-glance tone-<?= e($tone) ?> reveal">
                        <span class="program-glance-icon"><?= icon((string) ($program['icon'] ?: 'heart')) ?></span>
                        <div>
                            <span class="pill"><?= e($heading) ?></span>
                            <h2><?= e($program['title']) ?></h2>
                            <p class="lead"><?= e($program['summary']) ?></p>
                        </div>
                    </div>
                    <div class="glance-actions">
                        <?php foreach ($actions as $i => [$ic, $label, $text, $href, $actTone]): ?>
                            <a class="glance-action tone-<?= e($actTone) ?> reveal" style="--d:<?= $i ?>" href="<?= e($href) ?>"<?= $ic === 'briefcase' ? ' data-dl-event="cta_click" data-dl-cta="partnership-enquiry" data-dl-location="programme-page"' : '' ?>>
                                <span class="work-icon"><?= icon($ic) ?></span>
                                <span><strong><?= e($label) ?></strong><small><?= e($text) ?></small></span>
                                <?= icon('chevron-right', 'tile-arrow') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($related['campaigns']): ?>
                        <div class="program-related">
                            <div class="related-head"><h3><?= icon('heart') ?>Active campaigns</h3><a class="link-arrow" href="<?= e(page_url('campaigns')) ?>">All campaigns <?= icon('arrow-right') ?></a></div>
                            <p class="muted small">Progress shows verified donations only.</p>
                            <div class="related-grid"><?php foreach ($related['campaigns'] as $c) { echo program_related_card('campaign', $c); } ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($related['events']): ?>
                        <div class="program-related">
                            <div class="related-head"><h3><?= icon('calendar') ?>Upcoming events</h3><a class="link-arrow" href="<?= e(page_url('events')) ?>">All events <?= icon('arrow-right') ?></a></div>
                            <div class="related-grid"><?php foreach ($related['events'] as $ev) { echo program_related_card('event', $ev); } ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($related['stories']): ?>
                        <div class="program-related">
                            <div class="related-head"><h3><?= icon('star') ?>Stories of change</h3><a class="link-arrow" href="<?= e(page_url('success-stories')) ?>">All stories <?= icon('arrow-right') ?></a></div>
                            <div class="related-grid"><?php foreach ($related['stories'] as $st) { echo program_related_card('story', $st); } ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($related['posts']): ?>
                        <div class="program-related">
                            <div class="related-head"><h3><?= icon('file-text') ?>From our blog</h3><a class="link-arrow" href="<?= e(page_url('blog')) ?>">All articles <?= icon('arrow-right') ?></a></div>
                            <div class="related-grid"><?php foreach ($related['posts'] as $post) { echo program_related_card('post', $post); } ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if ($related['gallery']): ?>
                        <div class="program-related">
                            <div class="related-head"><h3><?= icon('image') ?>Moments from the field</h3><a class="link-arrow" href="<?= e(page_url('gallery')) ?>">Photo gallery <?= icon('arrow-right') ?></a></div>
                            <div class="program-photos">
                                <?php foreach ($related['gallery'] as $g): ?>
                                    <a class="program-photo" href="<?= e(page_url('gallery')) ?>"><?= program_picture((string) $g['image_path'], (string) $g['title'], ['sizes' => '(max-width: 600px) 50vw, 200px'],
                                        '<img src="' . e(image_url($g['image_path'])) . '" alt="' . e($g['title']) . '" loading="lazy">') ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!array_filter($related)): ?>
                        <div class="program-related program-follow">
                            <div class="related-head"><h3><?= icon('bell') ?>Follow our work</h3></div>
                            <p class="muted small">Campaigns, events and stories from this programme are listed here as soon as they are published. Updates are shared only after they have been verified.</p>
                            <div class="follow-links">
                                <?php foreach ($follow as [$ic, $label, $href]): ?>
                                    <a class="follow-link" href="<?= e($href) ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="program-aside" aria-label="About this programme">
                    <?php if ($points): ?>
                        <div class="aside-card program-points">
                            <h3>Key activities</h3>
                            <?= ui_checklist($points, 1) ?>
                        </div>
                    <?php endif; ?>
                    <div class="aside-card aside-cta">
                        <h3>Support this programme</h3>
                        <p>Your contribution helps us carry out this work with care and accountability. Every verified donation receives a receipt.</p>
                        <a class="btn btn-donate btn-block" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Donate now</a>
                        <a class="btn btn-outline btn-block" href="<?= e(page_url('become-a-volunteer')) ?>"><?= icon('users') ?>Become a volunteer</a>
                    </div>
                    <?php if ($others): ?>
                        <div class="aside-card">
                            <h3>Other programmes</h3>
                            <ul class="link-list program-links">
                                <?php foreach ($others as $o): ?>
                                    <li><a href="<?= e(program_url($o)) ?>"><?= icon((string) ($o['icon'] ?: 'chevron-right')) ?><?= e($o['title']) ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (get_page('programs')): ?><a class="link-arrow mt-2" href="<?= e(page_url('programs')) ?>">All programmes <?= icon('arrow-right') ?></a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </aside>
            </div>
        </section>
        <?php
    };
}

render_sections($page, $hub);
if (function_exists('seo_dates_line') && function_exists('seo_eeat_content_dates')) {
    // "Last updated" (only a substantive update an editor recorded), "Reviewed by ... on ..." and sources (only when entered)
    $eeatLine = seo_dates_line(null, seo_eeat_content_dates('page', (int) $page['id'], $page)['updated'], ['updated_label' => 'Last updated'])
        . (function_exists('seo_review_line') ? seo_review_line('page', (int) $page['id']) : '')
        . (function_exists('seo_sources_list') ? seo_sources_list('page', (int) $page['id']) : '');
    echo $eeatLine !== '' ? '<div class="container seo-page-meta">' . $eeatLine . '</div>' : '';
}
if (!$sections && !$program) {
    echo '<section class="section"><div class="container narrow">' . empty_state('Details of this programme are being updated. Please check back soon.', 'All programmes', '/programs/') . '</div></section>';
}
page_footer();
