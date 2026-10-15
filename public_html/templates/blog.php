<?php
/**
 * Blog / news / press list and article pages.
 *   /blog/            list (page setting {"categories":["blog","news"]})
 *   /media/           press releases (page setting {"categories":["press"]})
 *   /blog/<slug>/     article of any category (canonical address); /media/<slug>/ redirects there
 * Visitors see only published posts whose publish date has passed; staff with blog.manage may preview.
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
$categories = array_values(array_filter((array) page_setting($page, 'categories', ['blog', 'news']), function ($c) {
    return isset(BLOG_CATEGORIES[$c]);
})) ?: ['blog', 'news'];

// ---------------------------------------------------------------------
// Article page
// ---------------------------------------------------------------------
if ($segments) {
    $slug = $segments[0];
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug)) {
        not_found();
    }
    if ($page['slug'] !== 'blog') {
        if (db_value('SELECT id FROM blog_posts WHERE slug = ?', [$slug])) {
            header('Location: ' . post_url($slug), true, 301);
            exit;
        }
        not_found();
    }
    $post = db_row('SELECT * FROM blog_posts WHERE slug = ?', [$slug]);
    $visible = $post && $post['status'] === 'published' && $post['published_at'] && strtotime($post['published_at']) <= time();
    $preview = $post && !$visible && user_can('blog.manage');
    if (!$post || (!$visible && !$preview)) {
        not_found();
    }

    // View counter: real visitors only (no bots, no staff), once per session
    if ($visible && !content_is_bot() && !is_staff() && empty($_SESSION['viewed_posts'][$post['id']])) {
        db_query('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
        $_SESSION['viewed_posts'][$post['id']] = 1;
    }

    $canonical = abs_url('blog/' . $post['slug'] . '/');
    $isPress = $post['category'] === 'press';
    $listPage = $isPress ? get_page('media') : get_page('blog');
    $crumbs = $listPage ? [[$listPage['title'], page_url($listPage['slug'])]] : [];
    $minutes = post_reading_time($post['content']);
    $published = $post['published_at'] ?: $post['created_at'];

    // Related: same programme or category first, then the latest
    $related = db_rows('SELECT id, slug, title, category, excerpt, content, image, author_name, published_at FROM blog_posts
                        WHERE ' . post_visible_sql() . ' AND id <> ?
                        ORDER BY (program_id <=> ?) DESC, (category = ?) DESC, published_at DESC LIMIT 3',
        [$post['id'], $post['program_id'], $post['category']]);
    $prev = $visible ? db_row('SELECT slug, title FROM blog_posts WHERE ' . post_visible_sql() . ' AND (published_at < ? OR (published_at = ? AND id < ?)) ORDER BY published_at DESC, id DESC LIMIT 1',
        [$post['published_at'], $post['published_at'], $post['id']]) : null;
    $next = $visible ? db_row('SELECT slug, title FROM blog_posts WHERE ' . post_visible_sql() . ' AND (published_at > ? OR (published_at = ? AND id > ?)) ORDER BY published_at ASC, id ASC LIMIT 1',
        [$post['published_at'], $post['published_at'], $post['id']]) : null;

    page_header($page, [
        'title' => $post['title'],
        'subtitle' => excerpt($post['excerpt'], 200),
        'eyebrow' => BLOG_CATEGORIES[$post['category']] ?? 'Article',
        'meta_title' => $post['meta_title'] ?: $post['title'],
        'meta_description' => $post['meta_description'] ?: excerpt($post['excerpt'], 160),
        'canonical' => $canonical,
        'og_image' => $post['image'] ?: '',
        'og_type' => 'article',
        'crumbs' => $crumbs,
        'noindex' => !$visible,
        'buttons' => [],
        'schema' => [content_article_schema($post, $canonical, $post['author_name'] ?: null)],
    ]);
    ?>
    <section class="section article-section">
        <div class="container">
            <?php if ($preview): ?>
                <div class="alert alert-warning mb-3"><?= icon('eye') ?><div>Staff preview: this post is <strong><?= e($post['status'] === 'published' ? 'scheduled for ' . format_date($post['published_at'], 'd M Y, h:i A') : $post['status']) ?></strong> and is not visible to visitors.
                    <a href="<?= e(admin_url('blog-edit.php?id=' . (int) $post['id'])) ?>">Edit post</a></div></div>
            <?php endif; ?>
            <div class="article-layout">
                <article class="article-main">
                    <div class="article-meta">
                        <span class="post-chip post-chip-<?= e($post['category']) ?>"><?= e(BLOG_CATEGORIES[$post['category']] ?? 'Blog') ?></span>
                        <span><?= icon('user') ?><?= e($post['author_name'] ?: site_name()) ?></span>
                        <span><?= icon('calendar') ?><time datetime="<?= e(date('c', strtotime($published))) ?>"><?= e(format_date($published, 'd F Y')) ?></time></span>
                        <span><?= icon('clock') ?><?= $minutes ?> min read</span>
                        <?php if ($post['updated_at'] && strtotime($post['updated_at']) > strtotime($published) + 86400): ?>
                            <span class="muted">Updated <?= e(format_date($post['updated_at'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($post['image'] && is_file(ROOT_PATH . '/' . $post['image'])): [$iw, $ih] = content_image_size($post['image']); ?>
                        <figure class="article-hero"><img src="<?= e(url($post['image'])) ?>" alt="<?= e($post['title']) ?>"<?= $iw ? ' width="' . $iw . '" height="' . $ih . '"' : '' ?> fetchpriority="high"></figure>
                    <?php endif; ?>
                    <div class="prose article-prose"><?= $post['content'] ?></div>
                    <?= content_program_box($post['program_id'] ? (int) $post['program_id'] : null, 'Read more about our programme') ?>
                    <div class="article-foot">
                        <?= share_buttons($canonical, $post['title']) ?>
                    </div>
                    <?php if ($prev || $next): ?>
                        <nav class="post-nav" aria-label="More articles">
                            <?php if ($prev): ?><a class="post-nav-prev" href="<?= e(post_url($prev['slug'])) ?>" rel="prev"><small><?= icon('chevron-left') ?>Previous</small><strong><?= e($prev['title']) ?></strong></a><?php else: ?><span></span><?php endif; ?>
                            <?php if ($next): ?><a class="post-nav-next" href="<?= e(post_url($next['slug'])) ?>" rel="next"><small>Next<?= icon('chevron-right') ?></small><strong><?= e($next['title']) ?></strong></a><?php endif; ?>
                        </nav>
                    <?php endif; ?>
                </article>
                <aside class="article-aside">
                    <div class="aside-card aside-cta-card">
                        <span class="icon-circle gold"><?= icon('heart') ?></span>
                        <h2 class="h3">Be part of the change</h2>
                        <p>Support our work in education, health, women empowerment and the environment.</p>
                        <a class="btn btn-donate btn-block" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Donate</a>
                        <a class="btn btn-outline btn-block mt-1" href="<?= e(page_url('become-a-volunteer')) ?>">Volunteer with us</a>
                    </div>
                    <div class="aside-card">
                        <h2 class="h3">Browse</h2>
                        <ul class="link-list">
                            <li><a href="<?= e(page_url('blog')) ?>"><?= icon('file-text') ?>Blogs &amp; articles</a></li>
                            <li><a href="<?= e(page_url('blog', ['category' => 'news'])) ?>"><?= icon('bell') ?>News</a></li>
                            <li><a href="<?= e(page_url('media')) ?>"><?= icon('megaphone') ?>Press releases</a></li>
                            <li><a href="<?= e(page_url('success-stories')) ?>"><?= icon('star') ?>Success stories</a></li>
                            <li><a href="<?= e(page_url('newsletter')) ?>"><?= icon('mail') ?>Get updates by email</a></li>
                        </ul>
                    </div>
                </aside>
            </div>
        </div>
    </section>
    <?php if ($related): ?>
        <section class="section section-tint">
            <div class="container">
                <?= ui_heading('You may also like', 'Keep reading') ?>
                <div class="card-grid">
                    <?php foreach ($related as $i => $r) { echo blog_card($r, $i); } ?>
                </div>
            </div>
        </section>
    <?php endif;
    page_footer();
    return;
}

// ---------------------------------------------------------------------
// List page
// ---------------------------------------------------------------------
$category = (string) ($_GET['category'] ?? '');
if (!in_array($category, $categories, true)) {
    $category = '';
}
$q = trim(mb_substr(clean_string($_GET['q'] ?? ''), 0, 80));
$where = post_visible_sql();
$params = [];
if ($category !== '') {
    $where .= ' AND category = ?';
    $params[] = $category;
} else {
    $where .= ' AND category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
    $params = array_merge($params, $categories);
}
if ($q !== '') {
    $where .= ' AND (title LIKE ? OR excerpt LIKE ? OR content LIKE ?)';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    array_push($params, $like, $like, $like);
}
$total = (int) db_value("SELECT COUNT(*) FROM blog_posts WHERE $where", $params);
$p = paginate($total, 9);
$posts = db_rows("SELECT id, slug, title, category, excerpt, content, image, author_name, published_at FROM blog_posts WHERE $where
                  ORDER BY published_at DESC, id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$isPressPage = $categories === ['press'];

$metaTitle = $page['meta_title'] ?: null;
if ($q !== '') {
    $metaTitle = 'Search: ' . $q . ' - ' . $page['title'];
} elseif ($category !== '') {
    $metaTitle = BLOG_CATEGORIES[$category] . ' - ' . $page['title'];
}
page_header($page, ['noindex' => $q !== '', 'meta_title' => $metaTitle]);
render_sections($page, function () use ($page, $posts, $p, $category, $categories, $q, $total, $isPressPage) {
    echo '<section class="section posts-section" id="articles"><div class="container">';
    echo '<h2 class="sr-only">' . e($isPressPage ? 'Press releases' : 'Articles') . '</h2>';
    echo '<div class="list-toolbar">';
    if (count($categories) > 1) {
        echo '<nav class="filter-bar list-filter" aria-label="Categories">';
        echo '<a class="filter-btn' . ($category === '' ? ' is-active' : '') . '" href="' . e(page_url($page['slug'], $q !== '' ? ['q' => $q] : [])) . '#articles"' . ($category === '' ? ' aria-current="page"' : '') . '>All</a>';
        foreach ($categories as $c) {
            $query = array_filter(['category' => $c, 'q' => $q]);
            echo '<a class="filter-btn' . ($category === $c ? ' is-active' : '') . '" href="' . e(page_url($page['slug'], $query)) . '#articles"' . ($category === $c ? ' aria-current="page"' : '') . '>' . e(BLOG_CATEGORIES[$c]) . '</a>';
        }
        echo '</nav>';
    }
    echo '<form class="search-bar list-search" method="get" action="' . e(page_url($page['slug'])) . '#articles" role="search">';
    if ($category !== '') {
        echo '<input type="hidden" name="category" value="' . e($category) . '">';
    }
    echo '<label class="sr-only" for="post-q">Search ' . ($isPressPage ? 'press releases' : 'articles') . '</label>'
        . '<input id="post-q" type="search" name="q" value="' . e($q) . '" maxlength="80" placeholder="' . ($isPressPage ? 'Search press releases' : 'Search articles') . '">'
        . '<button class="btn btn-primary" type="submit">' . icon('search') . 'Search</button></form></div>';

    if ($q !== '') {
        echo '<p class="list-summary">' . $total . ' ' . ($total === 1 ? 'result' : 'results') . ' for <strong>“' . e($q) . '”</strong> · <a href="' . e(page_url($page['slug'], $category !== '' ? ['category' => $category] : [])) . '#articles">Clear search</a></p>';
    }

    if (!$posts) {
        if ($q !== '') {
            echo empty_state('No ' . ($isPressPage ? 'press releases' : 'articles') . ' matched your search. Try different words.', 'Show all', page_url($page['slug']));
        } elseif ($isPressPage) {
            echo empty_state('Press releases will be published here once available. Journalists can write to us for information about our work.', 'Contact us', '/contact/');
        } else {
            echo empty_state('Articles and news from our work will be published here soon. Subscribe to hear when we post.', 'Get updates', '/newsletter/');
        }
    } else {
        $list = $posts;
        if ($p['page'] === 1 && $q === '' && count($list) > 1) {
            echo blog_card_featured(array_shift($list));
        }
        echo '<div class="card-grid">';
        foreach ($list as $i => $post) {
            echo blog_card($post, $i);
        }
        echo '</div>' . pagination_links($p);
    }
    echo '</div></section>';
}, 'before');
page_footer();
