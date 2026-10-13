<?php
/**
 * Blog / news API (unit cms_content). CRUD of posts and categories goes through /api/crud/blog_posts and
 * /api/crud/blog_categories; these endpoints add the dashboard, SEO panel and quick actions.
 *   GET  /api/blog/summary                    KPIs, monthly publishing activity, categories, top posts, scheduled queue
 *   GET  /api/blog/posts/{id}/seo             SEO panel values (seo_settings entity "post")
 *   GET  /api/blog/seo-options                robots + sitemap change-frequency options
 *   GET  /api/blog/slug-check?slug=&id=       {available, suggestion}
 *   POST /api/blog/posts/{id}/publish         publish now (needs blog.publish)
 *   POST /api/blog/posts/{id}/unpublish       back to draft (needs blog.publish)
 *   POST /api/blog/posts/{id}/feature         toggle featured
 *   POST /api/blog/posts/{id}/duplicate       copy as a new draft (+ SEO)
 */

require_once APP_ROOT . '/app/services/media.php';

function blog_post_or_404(int $id): array
{
    $row = db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]);
    if (!$row) {
        api_error('This post no longer exists.', 404);
    }
    return $row;
}

/** Unique slug: base, base-2, base-3 … */
function blog_unique_slug(string $base, ?int $ignoreId = null): string
{
    $base = mb_substr(slugify($base), 0, 180);
    $slug = $base;
    $i = 2;
    while ((int) db_value('SELECT COUNT(*) FROM blog_posts WHERE slug = ?' . ($ignoreId ? ' AND id <> ?' : ''), $ignoreId ? [$slug, $ignoreId] : [$slug])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

route('GET', '/blog/summary', function () {
    api_require('blog', 'view');
    cms_blog_publish_due();
    $counts = db_pairs('SELECT status, COUNT(*) FROM blog_posts GROUP BY status');
    $monthStart = date('Y-m-01');
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime("$monthStart -$i months");
        $months[date('Y-m', $ts)] = ['label' => date('M y', $ts), 'posts' => 0, 'views' => 0];
    }
    $since = array_key_first($months) . '-01';
    foreach (db_all("SELECT DATE_FORMAT(published_at, '%Y-%m') AS ym, COUNT(*) AS n, COALESCE(SUM(views), 0) AS v FROM blog_posts
                     WHERE status = 'published' AND published_at >= ? GROUP BY ym", [$since]) as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['posts'] = (int) $r['n'];
            $months[$r['ym']]['views'] = (int) $r['v'];
        }
    }
    $lastMonthStart = date('Y-m-01', strtotime("$monthStart -1 month"));
    api_ok([
        'total' => (int) array_sum($counts),
        'published' => (int) ($counts['published'] ?? 0),
        'draft' => (int) ($counts['draft'] ?? 0),
        'scheduled' => (int) ($counts['scheduled'] ?? 0),
        'featured' => (int) db_value("SELECT COUNT(*) FROM blog_posts WHERE is_featured = 1 AND status = 'published'"),
        'views' => (int) db_value('SELECT COALESCE(SUM(views), 0) FROM blog_posts'),
        'this_month' => (int) db_value("SELECT COUNT(*) FROM blog_posts WHERE status = 'published' AND published_at >= ?", [$monthStart]),
        'last_month' => (int) db_value("SELECT COUNT(*) FROM blog_posts WHERE status = 'published' AND published_at >= ? AND published_at < ?", [$lastMonthStart, $monthStart]),
        'seo_missing' => (int) db_value("SELECT COUNT(*) FROM blog_posts p LEFT JOIN seo_settings s ON s.entity_type = 'post' AND s.entity_id = p.id
                                         WHERE p.status <> 'draft' AND (s.id IS NULL OR s.meta_description IS NULL OR s.meta_description = '')"),
        'categories_count' => (int) db_value("SELECT COUNT(*) FROM blog_categories WHERE status = 'active'"),
        'monthly' => array_values($months),
        'by_category' => array_map(fn ($r) => ['name' => $r['name'], 'posts' => (int) $r['posts'], 'published' => (int) $r['published']], db_all(
            "SELECT COALESCE(c.name, 'Uncategorised') AS name, COUNT(p.id) AS posts, SUM(p.status = 'published') AS published
             FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id GROUP BY c.id, c.name ORDER BY posts DESC LIMIT 8")),
        'top_posts' => array_map(fn ($r) => $r + ['id' => (int) $r['id'], 'views' => (int) $r['views']], db_all(
            "SELECT p.id, p.title, p.slug, p.thumbnail, p.views, p.published_at, c.name AS category_name FROM blog_posts p
             LEFT JOIN blog_categories c ON c.id = p.category_id WHERE p.status = 'published' ORDER BY p.views DESC, p.published_at DESC LIMIT 5")),
        'upcoming' => array_map(fn ($r) => $r + ['id' => (int) $r['id']], db_all(
            "SELECT p.id, p.title, p.published_at, c.name AS category_name FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id
             WHERE p.status = 'scheduled' ORDER BY p.published_at ASC LIMIT 5")),
    ]);
});

route('GET', '/blog/seo-options', function () {
    api_require('blog', 'view');
    $opt = fn (array $a) => array_map(fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), $a);
    api_ok(['robots' => $opt(cms_seo_robots_options()), 'changefreq' => $opt(cms_seo_changefreq_options()), 'site_url' => rtrim(absolute_url(''), '/'),
        'site_name' => institute_name()]);
});

route('GET', '/blog/posts/{id:\d+}/seo', function ($p) {
    api_require('blog', 'view');
    blog_post_or_404((int) $p['id']);
    api_ok(cms_seo_get('post', (int) $p['id']));
});

route('GET', '/blog/slug-check', function () {
    api_require('blog', 'view');
    $slug = slugify((string) ($_GET['slug'] ?? ''));
    $id = (int) ($_GET['id'] ?? 0) ?: null;
    $taken = (int) db_value('SELECT COUNT(*) FROM blog_posts WHERE slug = ?' . ($id ? ' AND id <> ?' : ''), $id ? [$slug, $id] : [$slug]) > 0;
    api_ok(['slug' => $slug, 'available' => !$taken, 'suggestion' => $taken ? blog_unique_slug($slug, $id) : $slug]);
});

route('POST', '/blog/posts/{id:\d+}/publish', function ($p) {
    api_require('blog', 'publish');
    $post = blog_post_or_404((int) $p['id']);
    if (mb_strlen(trim(strip_tags((string) $post['content']))) < 40) {
        api_error('Write the post content before publishing.', 422);
    }
    if ($post['status'] === 'published') {
        api_ok(['status' => 'published'], 'This post is already published.');
    }
    $at = $post['published_at'] && strtotime($post['published_at']) <= time() ? $post['published_at'] : date('Y-m-d H:i:s');
    db_update('blog_posts', ['status' => 'published', 'published_at' => $at, 'updated_by' => user_id()], 'id = ?', [(int) $post['id']]);
    log_activity('publish', 'blog', (int) $post['id'], 'Published blog post "' . str_limit($post['title'], 80) . '"');
    api_ok(['status' => 'published', 'published_at' => $at], 'Post published.');
});

route('POST', '/blog/posts/{id:\d+}/unpublish', function ($p) {
    api_require('blog', 'publish');
    $post = blog_post_or_404((int) $p['id']);
    if ($post['status'] === 'draft') {
        api_ok(['status' => 'draft'], 'This post is already a draft.');
    }
    db_update('blog_posts', ['status' => 'draft', 'updated_by' => user_id()], 'id = ?', [(int) $post['id']]);
    log_activity('update', 'blog', (int) $post['id'], 'Unpublished blog post "' . str_limit($post['title'], 80) . '" (moved to draft)');
    api_ok(['status' => 'draft'], 'Post moved to drafts. It is no longer visible on the website.');
});

route('POST', '/blog/posts/{id:\d+}/feature', function ($p) {
    api_require('blog', 'edit');
    $post = blog_post_or_404((int) $p['id']);
    $val = $post['is_featured'] ? 0 : 1;
    db_update('blog_posts', ['is_featured' => $val, 'updated_by' => user_id()], 'id = ?', [(int) $post['id']]);
    log_activity('update', 'blog', (int) $post['id'], ($val ? 'Featured' : 'Un-featured') . ' blog post "' . str_limit($post['title'], 80) . '"');
    api_ok(['is_featured' => (bool) $val], $val ? 'Post featured on the website.' : 'Post removed from featured.');
});

route('POST', '/blog/posts/{id:\d+}/duplicate', function ($p) {
    api_require('blog', 'create');
    $post = blog_post_or_404((int) $p['id']);
    $newId = db_transaction(function () use ($post) {
        $copy = array_intersect_key($post, array_flip(['category_id', 'thumbnail', 'excerpt', 'content', 'tags', 'type']));
        $copy['title'] = mb_substr($post['title'] . ' (Copy)', 0, 255);
        $copy['slug'] = blog_unique_slug($post['slug'] . '-copy');
        $copy += ['author_id' => user_id(), 'status' => 'draft', 'published_at' => null, 'is_featured' => 0, 'views' => 0, 'created_by' => user_id()];
        $id = db_insert('blog_posts', $copy);
        $seo = db_row("SELECT meta_title, meta_description, meta_keywords, og_title, og_description, og_image, robots, include_in_sitemap, sitemap_priority, sitemap_changefreq
                       FROM seo_settings WHERE entity_type = 'post' AND entity_id = ?", [(int) $post['id']]);
        if ($seo) {
            db_insert('seo_settings', $seo + ['entity_type' => 'post', 'entity_id' => $id]);
        }
        return $id;
    });
    log_activity('create', 'blog', $newId, 'Duplicated blog post "' . str_limit($post['title'], 80) . '" as a new draft');
    api_ok(['id' => $newId], 'Copy created as a draft.', 201);
});
