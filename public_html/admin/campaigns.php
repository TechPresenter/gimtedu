<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns');

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['c.title', 'c.location'], $q, $params);
if (in_array($status, ['draft', 'active', 'completed', 'closed'], true)) {
    $where .= ' AND c.status = ?';
    $params[] = $status;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM campaigns c $where", $params), 20);
$rows = db_rows(campaign_sql() . " $where ORDER BY FIELD(c.status,'active','draft','completed','closed'), c.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$categories = campaign_categories();

$adminTitle = 'Campaigns';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <?php admin_filter_bar(['draft', 'active', 'completed', 'closed'], ['placeholder' => 'Search campaign title or location']); ?>
    <div class="actions"><a class="btn btn-donate btn-sm" href="campaign-add.php"><?= icon('plus') ?>New campaign</a></div>
</div>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> campaign(s)</span><span>Raised = verified, successful donations only</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th></th><th>Campaign</th><th>Cause</th><th>Raised / Goal</th><th>Donations</th><th>Status</th><th>Ends</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $c): $pct = campaign_percent($c); ?>
                    <tr>
                        <td><img class="thumb" src="<?= e(campaign_image($c)) ?>" alt=""></td>
                        <td><a href="campaign-view.php?id=<?= (int) $c['id'] ?>"><strong><?= e($c['title']) ?></strong></a><?= $c['is_urgent'] ? ' <span class="badge badge-red">Urgent</span>' : '' ?><?= $c['is_featured'] ? ' <span class="badge badge-gold">Featured</span>' : '' ?></td>
                        <td><?= e($categories[$c['category']] ?? $c['category']) ?></td>
                        <td class="nowrap"><strong><?= e(money($c['raised'])) ?></strong> / <?= (float) $c['goal_amount'] > 0 ? e(money($c['goal_amount'])) : '—' ?>
                            <?php if ((float) $c['goal_amount'] > 0): ?><div class="mini-progress"><span style="width:<?= $pct ?>%"></span></div><?php endif; ?></td>
                        <td><?= (int) $c['donor_count'] ?></td>
                        <td><?= status_badge($c['status']) ?></td>
                        <td class="nowrap"><?= e(format_date($c['end_date'])) ?></td>
                        <td class="actions-cell">
                            <a class="icon-btn" href="campaign-view.php?id=<?= (int) $c['id'] ?>" aria-label="View"><?= icon('eye') ?></a>
                            <a class="icon-btn" href="campaign-edit.php?id=<?= (int) $c['id'] ?>" aria-label="Edit"><?= icon('edit') ?></a>
                            <?php if (!(int) db_value('SELECT COUNT(*) FROM donations WHERE campaign_id = ?', [$c['id']])): ?>
                                <form class="inline-form" method="post" action="actions/campaign-delete.php" data-confirm="Delete this campaign permanently?">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <button class="icon-btn danger" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No campaigns found. Create your first fundraising campaign.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
