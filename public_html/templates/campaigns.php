<?php
/**
 * Campaign list (/campaigns/): status tabs (active / completed), cause chips, search, pagination,
 * a featured campaign card and an empty state that points to a general donation.
 * Progress on every card comes from verified donations only. Page sections render around the list.
 */
if (!defined('PWF_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$page = current_page_row();
$status = ($_GET['status'] ?? '') === 'completed' ? 'completed' : 'active';
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 80);
$statusWhere = $status === 'completed' ? "c.status IN ('completed','closed')" : "c.status = 'active'";

// Causes that actually have public campaigns in this tab
$catCounts = [];
foreach (db_rows("SELECT c.category, COUNT(*) AS n FROM campaigns c WHERE $statusWhere GROUP BY c.category") as $r) {
    $catCounts[$r['category']] = (int) $r['n'];
}
$category = get_str('category');
if (!isset($catCounts[$category])) {
    $category = '';
}
$tabCounts = [
    'active' => (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'active'"),
    'completed' => (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status IN ('completed','closed')"),
];

$where = $statusWhere;
$params = [];
if ($category !== '') {
    $where .= ' AND c.category = ?';
    $params[] = $category;
}
if ($q !== '') {
    $where .= ' AND (c.title LIKE ? OR c.short_description LIKE ? OR c.location LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}

// Featured hero card: first page of the unfiltered active list
$featured = null;
if ($status === 'active' && $category === '' && $q === '' && (int) ($_GET['page'] ?? 1) <= 1) {
    $featured = db_row(campaign_sql() . " WHERE c.status = 'active' AND c.is_featured = 1 ORDER BY c.is_urgent DESC, c.updated_at DESC, c.created_at DESC LIMIT 1");
    if ($featured && !campaign_accepts_donations($featured)) {
        $featured = null;
    }
}
if ($featured) {
    $where .= ' AND c.id <> ?';
    $params[] = (int) $featured['id'];
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM campaigns c WHERE $where", $params), 9);
$campaigns = db_rows(campaign_sql() . " WHERE $where ORDER BY c.is_urgent DESC, c.is_featured DESC, COALESCE(c.start_date, DATE(c.created_at)) DESC, c.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$listUrl = function (array $query) use ($status, $category, $q): string {
    $base = ['status' => $status === 'active' ? null : $status, 'category' => $category ?: null, 'q' => $q ?: null];
    return page_url('campaigns', array_filter(array_merge($base, $query), function ($v) { return $v !== null && $v !== ''; }));
};

$itemList = [];
foreach ($campaigns as $i => $c) {
    $itemList[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => abs_url('campaign/' . $c['slug'] . '/'), 'name' => $c['title']];
}
$metaTitle = null;
if ($category !== '' || $status === 'completed' || $q !== '' || $p['page'] > 1) {
    $metaTitle = trim(($status === 'completed' ? 'Completed ' : '') . ($category !== '' ? campaign_category_label($category) . ' ' : '') . ($status === 'completed' || $category !== '' ? 'campaigns' : 'Campaigns'))
        . ($q !== '' ? ' matching "' . $q . '"' : '') . ($p['page'] > 1 ? ' - page ' . $p['page'] : '');
    $metaTitle = mb_strtoupper(mb_substr($metaTitle, 0, 1)) . mb_substr($metaTitle, 1);
}
page_header($page, [
    'meta_title' => $metaTitle,
    'noindex' => $q !== '' || $category !== '' || $p['page'] > 1 ? true : null,
    'canonical' => abs_page_url('campaigns', $status === 'completed' ? ['status' => 'completed'] : []),
    'schema' => $itemList ? [['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $page['title'], 'url' => abs_page_url('campaigns'),
        'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $itemList]]] : [],
]);

render_sections($page, function () use ($status, $q, $category, $catCounts, $tabCounts, $featured, $campaigns, $p, $listUrl) {
    ?>
    <section class="section fr-list" id="campaign-list">
        <div class="container">
            <div class="fr-toolbar">
                <nav class="fr-tabs" aria-label="Campaign status">
                    <a class="fr-tab<?= $status === 'active' ? ' is-active' : '' ?>" href="<?= e(page_url('campaigns') . '#campaign-list') ?>"<?= $status === 'active' ? ' aria-current="page"' : '' ?>><?= icon('heart') ?>Active <span class="fr-count"><?= $tabCounts['active'] ?></span></a>
                    <a class="fr-tab<?= $status === 'completed' ? ' is-active' : '' ?>" href="<?= e(page_url('campaigns', ['status' => 'completed']) . '#campaign-list') ?>"<?= $status === 'completed' ? ' aria-current="page"' : '' ?>><?= icon('check-circle') ?>Completed <span class="fr-count"><?= $tabCounts['completed'] ?></span></a>
                </nav>
                <form class="fr-search" method="get" action="<?= e(page_url('campaigns')) ?>" role="search">
                    <?php if ($status !== 'active'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
                    <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
                    <label class="sr-only" for="campaign-q">Search campaigns</label>
                    <input id="campaign-q" type="search" name="q" value="<?= e($q) ?>" maxlength="80" placeholder="Search by name or place">
                    <button class="btn btn-primary btn-sm" type="submit" data-no-loading><?= icon('search') ?><span>Search</span></button>
                </form>
            </div>
            <?php if (count($catCounts) > 1 || $category !== ''): ?>
                <nav class="filter-bar fr-chips" aria-label="Filter by cause">
                    <a class="filter-btn<?= $category === '' ? ' is-active' : '' ?>" href="<?= e($listUrl(['category' => null, 'page' => null]) . '#campaign-list') ?>"<?= $category === '' ? ' aria-current="true"' : '' ?>>All causes</a>
                    <?php foreach ($catCounts as $slug => $n): ?>
                        <a class="filter-btn<?= $category === $slug ? ' is-active' : '' ?>" href="<?= e($listUrl(['category' => $slug, 'page' => null]) . '#campaign-list') ?>"<?= $category === $slug ? ' aria-current="true"' : '' ?>><?= e(campaign_category_label($slug)) ?> <span class="fr-count"><?= $n ?></span></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <?php if ($q !== ''): ?>
                <p class="fr-result-line" role="status"><?= (int) $p['total'] + ($featured ? 1 : 0) ?> result<?= ((int) $p['total'] + ($featured ? 1 : 0)) === 1 ? '' : 's' ?> for “<?= e($q) ?>” · <a href="<?= e($listUrl(['q' => null, 'page' => null])) ?>">Clear search</a></p>
            <?php endif; ?>

            <?php if ($featured): ?>
                <?= campaign_feature_card($featured) ?>
            <?php endif; ?>

            <?php if ($campaigns): ?>
                <div class="grid grid-3 fr-grid"><?php foreach ($campaigns as $i => $c) { echo campaign_card($c, $i); } ?></div>
                <?= pagination_links($p) ?>
            <?php elseif (!$featured): ?>
                <div class="empty-state fr-empty">
                    <?= icon('heart') ?>
                    <?php if ($q !== '' || $category !== ''): ?>
                        <p>No <?= $status === 'active' ? 'active' : 'completed' ?> campaigns match your search. Try another cause or clear the filters.</p>
                        <div class="btn-row center"><a class="btn btn-outline" href="<?= e(page_url('campaigns', $status === 'completed' ? ['status' => 'completed'] : [])) ?>">Show all campaigns</a>
                            <a class="btn btn-donate" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Make a general donation</a></div>
                    <?php elseif ($status === 'active' && !(online_donations_available() || bank_transfer_details())): ?>
                        <p><strong>There are no open fundraising campaigns right now.</strong><br>To support our programmes, write to us and our team will share the current ways to donate.</p>
                        <div class="btn-row center"><a class="btn btn-donate" href="<?= e(page_url('contact')) ?>"><?= icon('mail') ?>Ask how to donate</a>
                            <a class="btn btn-outline" href="<?= e(page_url('start-a-fundraiser')) ?>"><?= icon('flag') ?>Propose a fundraiser</a></div>
                    <?php elseif ($status === 'active'): ?>
                        <p><strong>There are no open fundraising campaigns right now.</strong><br>You can still support our programmes with a general donation - it is used where it is needed most, and you receive a receipt once it is verified.</p>
                        <div class="btn-row center"><a class="btn btn-donate" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Make a general donation</a>
                            <a class="btn btn-outline" href="<?= e(page_url('start-a-fundraiser')) ?>"><?= icon('flag') ?>Propose a fundraiser</a></div>
                    <?php else: ?>
                        <p>Completed campaigns will be listed here, with the verified amount raised and utilisation reports.</p>
                        <div class="btn-row center"><a class="btn btn-outline" href="<?= e(page_url('campaigns')) ?>">See active campaigns</a>
                            <a class="btn btn-donate" href="<?= e(donate_url()) ?>"><?= icon('heart') ?>Make a general donation</a></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <p class="fr-verified-note"><?= icon('shield') ?><?= e('Progress bars and totals count only donations verified with our payment gateway or reconciled by our finance team.') ?></p>
        </div>
    </section>
    <?php
}, 'before');
page_footer();
