<?php
/** 404 page (also used by not_found()). Suggests useful destinations and a site search of pages. */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

http_response_code(404);
$page = current_page_row();
page_header($page, ['banner' => false, 'noindex' => true, 'title' => $page['title'] ?: 'Page not found']);
$q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 80));
$results = [];
if ($q !== '') {
    $like = '%' . $q . '%';
    $results = db_rows("SELECT slug, title, meta_description FROM pages WHERE status = 'published' AND noindex = 0 AND (title LIKE ? OR meta_description LIKE ?) ORDER BY sort_order LIMIT 12", [$like, $like]);
}
?>
<section class="section notfound">
    <div class="container narrow text-center">
        <div class="notfound-art float-slow" aria-hidden="true"><span>4</span><img src="<?= e(site_logo()) ?>" alt="" width="120" height="120"><span>4</span></div>
        <h1><?= e($page['title'] ?: 'Page not found') ?></h1>
        <p class="lead"><?= e($page['banner_subtitle'] ?: 'Sorry, the page you are looking for does not exist, has moved, or is no longer available.') ?></p>
        <form class="search-bar notfound-search" method="get" action="<?= e(url('/not-found/')) ?>" role="search">
            <label class="sr-only" for="nf-q">Search pages</label>
            <input id="nf-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Search our pages, e.g. volunteer">
            <button class="btn btn-primary" type="submit"><?= icon('search') ?>Search</button>
        </form>
        <?php if ($q !== ''): ?>
            <?php if ($results): ?>
                <ul class="link-list notfound-results">
                    <?php foreach ($results as $r): ?><li><a href="<?= e(page_url($r['slug'])) ?>"><?= icon('chevron-right') ?><?= e($r['title']) ?></a></li><?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="muted">No pages matched "<?= e($q) ?>".</p>
            <?php endif; ?>
        <?php endif; ?>
        <div class="btn-row center mt-3">
            <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= icon('home') ?>Go to homepage</a>
            <a class="btn btn-donate" href="<?= e(page_url('donate')) ?>"><?= icon('heart') ?>Donate</a>
            <a class="btn btn-outline" href="<?= e(page_url('sitemap')) ?>"><?= icon('list') ?>All pages</a>
        </div>
    </div>
</section>
<?php
render_sections($page);
page_footer();
