<?php
/** Campaigns: list with verified progress, status filter, search, CSV export, delete (only without donations). */
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('campaigns.php');
    $id = (int) ($_POST['id'] ?? 0);
    $c = db_row('SELECT * FROM campaigns WHERE id = ?', [$id]);
    if (($_POST['action'] ?? '') === 'delete' && $c) {
        $donations = (int) db_value('SELECT COUNT(*) FROM donations WHERE campaign_id = ?', [$id]);
        if ($donations > 0 || in_array($c['status'], ['active', 'completed'], true)) {
            flash('error', 'This campaign has donations or is public, so it cannot be deleted. Set its status to Closed instead.');
        } else {
            foreach (db_rows('SELECT document_path FROM campaign_expenses WHERE campaign_id = ?', [$id]) as $x) {
                delete_upload($x['document_path']);
            }
            db_query('DELETE FROM campaigns WHERE id = ?', [$id]);
            if ($c['image'] && !db_value('SELECT COUNT(*) FROM campaigns WHERE image = ?', [$c['image']])) {
                delete_upload($c['image']);
            }
            admin_log('campaign_deleted', 'Campaign #' . $id . ' ' . $c['title']);
            flash('success', 'Campaign deleted.');
        }
    }
    admin_redirect(admin_back('campaigns.php'));
}

$statuses = ['draft', 'active', 'completed', 'closed', 'rejected', 'proposal'];
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = in_array($_GET['status'] ?? '', $statuses, true) ? (string) $_GET['status'] : '';
$category = (string) ($_GET['category'] ?? '');
$categories = campaign_categories();
if (!isset($categories[$category])) {
    $category = '';
}
$params = [];
$where = $status !== '' ? 'c.status = ?' : "c.status NOT IN ('proposal')";
if ($status !== '') {
    $params[] = $status;
}
if ($category !== '') {
    $where .= ' AND c.category = ?';
    $params[] = $category;
}
$where .= search_where(['c.title', 'c.slug', 'c.location', 'c.organizer_name'], $q, $params);

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT c.id, c.title, c.slug, c.status, c.category, c.location, c.goal_amount, COALESCE(d.raised, 0), COALESCE(d.donor_count, 0),
                            (SELECT COALESCE(SUM(amount), 0) FROM campaign_expenses e WHERE e.campaign_id = c.id AND e.status = 'approved'),
                            c.start_date, c.end_date, c.is_featured, c.is_urgent, c.created_at
                     FROM campaigns c LEFT JOIN (SELECT campaign_id, SUM(amount) AS raised, COUNT(*) AS donor_count FROM donations WHERE status = 'success' GROUP BY campaign_id) d ON d.campaign_id = c.id
                     WHERE $where ORDER BY c.created_at DESC", $params);
    admin_log('campaigns_exported', count($rows) . ' rows');
    send_csv('campaigns-' . date('Ymd-His') . '.csv', ['ID', 'Title', 'Slug', 'Status', 'Category', 'Location', 'Goal', 'Verified raised', 'Verified donations', 'Approved expenses', 'Start', 'End', 'Featured', 'Urgent', 'Created'], $rows);
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM campaigns c WHERE $where", $params), 20);
$rows = db_rows(campaign_sql() . " WHERE $where ORDER BY FIELD(c.status, 'active', 'draft', 'completed', 'closed', 'rejected', 'proposal'), c.updated_at DESC, c.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$stats = db_row("SELECT SUM(status = 'active') AS active, SUM(status = 'draft') AS drafts, SUM(status IN ('completed','closed')) AS done, SUM(status = 'proposal') AS proposals FROM campaigns");
$raisedAll = (float) db_value("SELECT COALESCE(SUM(amount), 0) FROM donations WHERE status = 'success' AND campaign_id IS NOT NULL");

$actions = [['label' => 'New campaign', 'url' => admin_url('campaign-edit.php'), 'icon' => 'plus']];
if (admin_can('exports.run')) {
    $actions[] = ['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1, 'page' => null])), 'icon' => 'download', 'style' => 'ghost'];
}
admin_page_start('Campaigns', $actions);
?>
<div class="stat-cards">
    <a class="stat-card" href="?status=active"><span class="icon-circle"><?= icon('heart') ?></span><div><strong><?= (int) $stats['active'] ?></strong><small>Active campaigns</small></div></a>
    <a class="stat-card" href="?status=draft"><span class="icon-circle gold"><?= icon('edit') ?></span><div><strong><?= (int) $stats['drafts'] ?></strong><small>Drafts</small></div></a>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('rupee') ?></span><div><strong><?= e(money($raisedAll)) ?></strong><small>Verified raised by campaigns</small></div></div>
    <?php if (admin_can('campaigns.proposals')): ?>
        <a class="stat-card" href="<?= e(admin_url('proposals.php')) ?>"><span class="icon-circle gold"><?= icon('flag') ?></span><div><strong><?= (int) $stats['proposals'] ?></strong><small>Proposals awaiting review</small></div></a>
    <?php endif; ?>
</div>

<?php
$catSelect = '<select name="category" aria-label="Cause" style="max-width:100%;min-width:0"><option value="">All causes</option>' . options_html($categories, $category, true, '') . '</select>';
admin_filter_bar($statuses, ['placeholder' => 'Search title, slug, location or organiser', 'html' => $catSelect, 'active' => $category !== '']);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> campaign(s)<?= $status === '' ? ' (proposals are listed under Fundraiser Proposals)' : '' ?></span><span>Raised = verified donations only</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Campaign</th><th>Status</th><th>Verified raised</th><th>Donations</th><th>Dates</th><th class="actions-cell">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): $pct = campaign_percent($c); ?>
                <tr>
                    <td>
                        <div style="display:flex;gap:12px;align-items:center;min-width:240px">
                            <img class="thumb" src="<?= e(campaign_image($c)) ?>" alt="">
                            <div><a href="<?= e(admin_url('campaign-view.php?id=' . (int) $c['id'])) ?>"><strong><?= e($c['title']) ?></strong></a><br>
                                <small class="muted"><?= e(campaign_category_label($c['category'])) ?><?= $c['location'] ? ' · ' . e($c['location']) : '' ?></small></div>
                        </div>
                    </td>
                    <td><?= status_badge($c['status']) ?><?= $c['is_featured'] ? ' <span class="badge badge-gold">Featured</span>' : '' ?><?= $c['is_urgent'] ? ' <span class="badge badge-red">Urgent</span>' : '' ?></td>
                    <td class="nowrap"><strong><?= e(money($c['raised'])) ?></strong><?= (float) $c['goal_amount'] > 0 ? ' <small class="muted">of ' . e(money($c['goal_amount'])) . '</small><div class="mini-progress" title="' . $pct . '%"><span style="width:' . $pct . '%"></span></div>' : '<br><small class="muted">No target</small>' ?></td>
                    <td><?= (int) $c['donor_count'] ?></td>
                    <td class="nowrap small"><?= $c['start_date'] ? e(format_date($c['start_date'])) : '<span class="muted">No start</span>' ?><br><?= $c['end_date'] ? 'to ' . e(format_date($c['end_date'])) : '<span class="muted">Open-ended</span>' ?></td>
                    <td class="actions-cell">
                        <a class="icon-btn" href="<?= e(admin_url('campaign-view.php?id=' . (int) $c['id'])) ?>" aria-label="Open <?= e($c['title']) ?>" title="Overview, updates, expenses"><?= icon('eye') ?></a>
                        <a class="icon-btn" href="<?= e(admin_url('campaign-edit.php?id=' . (int) $c['id'])) ?>" aria-label="Edit <?= e($c['title']) ?>" title="Edit"><?= icon('edit') ?></a>
                        <a class="icon-btn" href="<?= e(campaign_url($c['slug']) . (campaign_is_public($c) ? '' : '?preview=1')) ?>" target="_blank" rel="noopener" aria-label="View on website" title="<?= campaign_is_public($c) ? 'View on website' : 'Preview' ?>"><?= icon('external') ?></a>
                        <?php if ((int) $c['donor_count'] === 0 && !in_array($c['status'], ['active', 'completed'], true)): ?>
                            <form class="inline-form" method="post" data-confirm="Delete the campaign &quot;<?= e($c['title']) ?>&quot; with its updates and expenses? This cannot be undone.">
                                <?= csrf_field() ?><?= return_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button class="icon-btn danger" type="submit" aria-label="Delete <?= e($c['title']) ?>" title="Delete"><?= icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <div class="empty-state"><?= icon('inbox') ?><p>No campaigns match these filters.</p><a class="btn btn-outline" href="<?= e(admin_url('campaign-edit.php')) ?>">Create a campaign</a></div>
    <?php endif; ?>
</div>
<p class="small muted">Drafts are visible only to staff (use the preview link). A campaign needs a description and an image before it can be published. Progress figures count only verified donations.</p>
<?php
admin_page_end();
