<?php
/**
 * Success stories: /success-stories/ (list) and /success-stories/<slug>/ (story).
 * Only stories that are published AND whose written consent is confirmed are ever shown.
 * Staff with stories.manage may preview drafts.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$segments = route_segments();
if (count($segments) > 1) {
    not_found();
}

// ---------------------------------------------------------------------
// Story page
// ---------------------------------------------------------------------
if ($segments) {
    $slug = $segments[0];
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
        not_found();
    }
    $story = db_row('SELECT * FROM success_stories WHERE slug = ?', [$slug]);
    $visible = $story && $story['status'] === 'published' && (int) $story['consent_confirmed'] === 1
        && (!$story['published_at'] || strtotime($story['published_at']) <= time());
    $preview = $story && !$visible && staff_session_can('stories.manage');
    if (!$story || (!$visible && !$preview)) {
        not_found();
    }
    $canonical = abs_url('success-stories/' . $story['slug'] . '/');
    $who = array_filter([(string) $story['person_name'], (string) $story['location']]);
    $more = db_rows('SELECT id, slug, title, person_name, location, excerpt, image FROM success_stories WHERE ' . story_visible_sql() . ' AND id <> ?
                     ORDER BY (program_id <=> ?) DESC, published_at DESC LIMIT 3', [$story['id'], $story['program_id']]);
    $published = $story['published_at'] ?: $story['created_at'];

    // E-E-A-T (WP-6): accurate dates, author / reviewer Person nodes and sources (before page_header() prints the graph).
    // The story's person_name is its subject, never its author.
    $eeatDates = function_exists('seo_eeat_content_dates') ? seo_eeat_content_dates('story', (int) $story['id'], $story) : ['published' => $published, 'updated' => null];
    $articleSchema = content_article_schema($story, $canonical);
    if (function_exists('seo_eeat_article')) {
        $articleSchema = seo_eeat_article($articleSchema, 'story', (int) $story['id'], $story, $canonical);
    }
    content_prime_images(array_merge([(string) $story['image']], array_column($more, 'image')));
    page_header($page, [
        'title' => $story['title'],
        'subtitle' => excerpt($story['excerpt'], 200),
        'eyebrow' => 'Success story',
        'meta_title' => $story['title'],
        'meta_description' => excerpt($story['excerpt'], 160),
        'canonical' => $canonical,
        'og_image' => $story['image'] ?: '',
        'og_type' => 'article',
        'crumbs' => [[$page['title'], page_url($page['slug'])]],
        'noindex' => !$visible,
        'buttons' => [],
        'schema' => [$articleSchema],
    ]);
    ?>
    <section class="section article-section">
        <div class="container">
            <?php if ($preview): ?>
                <div class="alert alert-warning mb-3"><?= icon('eye') ?><div>Staff preview: this story is not visible to visitors
                    (<?= e((int) $story['consent_confirmed'] === 1 ? $story['status'] : 'consent not confirmed') ?>).
                    <a href="<?= e(url('admin/story-edit.php?id=' . (int) $story['id'])) ?>">Edit story</a></div></div>
            <?php endif; ?>
            <div class="article-layout">
                <article class="article-main">
                    <div class="article-meta">
                        <span class="post-chip post-chip-story">Success story</span>
                        <?php if ($who): ?><span><?= icon('user') ?><?= e(implode(' · ', $who)) ?></span><?php endif; ?>
                        <span><?= icon('calendar') ?><time datetime="<?= e(date('c', strtotime($published))) ?>"><?= e(format_date($published, 'd F Y')) ?></time></span>
                        <span><?= icon('clock') ?><?= post_reading_time($story['content']) ?> min read</span>
                        <?= function_exists('seo_dates_line') ? seo_dates_line($published, $eeatDates['updated'], ['wrap' => 'none', 'show_published' => false]) : '' ?>
                    </div>
                    <?php if ($story['image'] && is_file(ROOT_PATH . '/' . $story['image'])): ?>
                        <figure class="article-hero"><?= content_hero_picture($story['image'], $story['title'], '(max-width: 991px) calc(100vw - 40px), 820px') ?></figure>
                    <?php endif; ?>
                    <div class="prose article-prose"><?= content_body_html((string) $story['content']) ?></div>
                    <?= function_exists('seo_review_line') ? seo_review_line('story', (int) $story['id']) . seo_sources_list('story', (int) $story['id']) : '' ?>
                    <?= content_program_box($story['program_id'] ? (int) $story['program_id'] : null, 'This story is part of our programme') ?>
                    <p class="consent-note"><?= icon('shield') ?><span>Shared with the written consent of the people involved. Names or details may be changed to protect privacy.</span></p>
                    <?= function_exists('seo_author_box') ? seo_author_box('story', (int) $story['id'], null) : '' ?>
                    <div class="article-foot"><?= share_buttons($canonical, $story['title']) ?></div>
                </article>
                <aside class="article-aside">
                    <div class="aside-card aside-cta-card">
                        <span class="icon-circle gold"><?= icon('heart') ?></span>
                        <h2 class="h3">Help write the next story</h2>
                        <p>Your time or support helps people build a better tomorrow.</p>
                        <a class="btn btn-donate btn-block" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Donate</a>
                        <a class="btn btn-outline btn-block mt-1" href="<?= e(page_url('become-a-volunteer')) ?>">Volunteer with us</a>
                    </div>
                    <div class="aside-card">
                        <h2 class="h3">Know someone who needs support?</h2>
                        <p class="small muted">Tell us how we can help - requests are handled confidentially.</p>
                        <a class="link-arrow" href="<?= e(page_url('request-help')) ?>">Request help<?= icon('arrow-right') ?></a>
                    </div>
                </aside>
            </div>
        </div>
    </section>
    <?php if ($more): ?>
        <section class="section section-tint">
            <div class="container">
                <?= ui_heading('More stories of change', 'Keep reading') ?>
                <div class="card-grid"><?php foreach ($more as $i => $m) { echo story_card($m, $i); } ?></div>
            </div>
        </section>
    <?php endif;
    page_footer();
    return;
}

// ---------------------------------------------------------------------
// List page
// ---------------------------------------------------------------------
$total = (int) db_value('SELECT COUNT(*) FROM success_stories WHERE ' . story_visible_sql());
$p = paginate($total, 9);
$stories = db_rows('SELECT id, slug, title, person_name, location, excerpt, image FROM success_stories WHERE ' . story_visible_sql()
    . " ORDER BY published_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}");

page_header($page);
render_sections($page, function () use ($stories, $p) {
    echo '<section class="section stories-section" id="stories"><div class="container">';
    echo '<h2 class="sr-only">Stories</h2>';
    if (!$stories) {
        echo empty_state('Stories will be shared here with the consent of the people involved.', 'See our programmes', '/programs/');
    } else {
        content_prime_images(array_column($stories, 'image'));
        echo '<div class="card-grid">';
        foreach ($stories as $i => $s) {
            echo story_card($s, $i);
        }
        echo '</div>' . pagination_links($p);
    }
    echo '</div></section>';
}, 'before');
page_footer();
