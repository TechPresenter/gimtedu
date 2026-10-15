<?php
require_once __DIR__ . '/functions.php';

$category = (string) ($_GET['category'] ?? '');
if (!isset(BLOG_CATEGORIES[$category])) {
    $category = '';
}
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);

$where = "status = 'published' AND published_at <= NOW()";
$params = [];
if ($category !== '') {
    $where .= ' AND category = ?';
    $params[] = $category;
}
if ($q !== '') {
    $where .= ' AND (title LIKE ? OR excerpt LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM blog_posts WHERE $where", $params), 9);
$posts = db_rows("SELECT * FROM blog_posts WHERE $where ORDER BY published_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$title = $category ? BLOG_CATEGORIES[$category] : 'Blog & News';
$pageTitle = $title;
$pageDescription = 'Articles, news, press releases and stories from Prerit Welfare Foundation.';
require __DIR__ . '/header.php';

page_banner($title, 'Stories, updates and resources from our work');
?>
<section class="section">
    <div class="container">
        <form class="search-bar" method="get" action="<?= e(url('blog.php')) ?>" role="search">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search articles" aria-label="Search articles">
            <select name="category" aria-label="Category">
                <option value="">All categories</option>
                <?php foreach (BLOG_CATEGORIES as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $category === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit"><?= icon('search') ?>Search</button>
        </form>

        <?php if ($posts): ?>
            <div class="grid grid-3"><?php foreach ($posts as $post) { echo blog_card($post); } ?></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state($q || $category ? 'No articles match your search.' : 'Articles and news will be published here soon.') ?>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
