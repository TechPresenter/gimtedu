<?php
require_once __DIR__ . '/functions.php';

$categories = campaign_categories();
$category = (string) ($_GET['category'] ?? '');
if (!isset($categories[$category])) {
    $category = '';
}
$status = ($_GET['status'] ?? '') === 'completed' ? 'completed' : 'active';
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);

$where = $status === 'completed' ? "c.status IN ('completed','closed')" : "c.status = 'active'";
$params = [];
if ($category !== '') {
    $where .= ' AND c.category = ?';
    $params[] = $category;
}
if ($q !== '') {
    $where .= ' AND (c.title LIKE ? OR c.short_description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM campaigns c WHERE $where", $params), 9);
$campaigns = db_rows(campaign_sql() . " WHERE $where ORDER BY c.is_urgent DESC, c.is_featured DESC, c.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$pageTitle = 'Campaigns';
$pageDescription = 'Support fundraising campaigns of Prerit Welfare Foundation, including disaster relief, education and health.';
require __DIR__ . '/header.php';

page_banner('Fundraising Campaigns', 'Choose a cause and help us reach our goal. Progress shows verified donations only.');
?>
<section class="section">
    <div class="container">
        <nav class="filter-bar" aria-label="Campaign status">
            <a class="filter-btn<?= $status === 'active' ? ' is-active' : '' ?>" href="<?= e(url('campaigns.php')) ?>">Active campaigns</a>
            <a class="filter-btn<?= $status === 'completed' ? ' is-active' : '' ?>" href="<?= e(url('campaigns.php?status=completed')) ?>">Completed</a>
        </nav>
        <form class="search-bar" method="get" action="<?= e(url('campaigns.php')) ?>" role="search">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search campaigns" aria-label="Search campaigns">
            <select name="category" aria-label="Cause">
                <option value="">All causes</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $category === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit"><?= icon('search') ?>Search</button>
        </form>

        <?php if ($campaigns): ?>
            <div class="grid grid-3"><?php foreach ($campaigns as $c) { echo campaign_card($c); } ?></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <?= empty_state($status === 'active' ? 'There are no active campaigns matching your search. You can still make a general donation.' : 'No completed campaigns to show yet.', 'Make a general donation', 'donate.php') ?>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/footer.php'; ?>
