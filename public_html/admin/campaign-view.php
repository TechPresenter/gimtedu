<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns');

$c = db_row(campaign_sql() . ' WHERE c.id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$c) {
    flash('error', 'Campaign not found.');
    admin_redirect('campaigns.php');
}
$status = (string) ($_GET['status'] ?? 'success');
$statusSql = in_array($status, ['success', 'pending', 'failed', 'refunded'], true) ? $status : 'success';
$p = paginate((int) db_value('SELECT COUNT(*) FROM donations WHERE campaign_id = ? AND status = ?', [$c['id'], $statusSql]), 25);
$donations = db_rows("SELECT * FROM donations WHERE campaign_id = ? AND status = ? ORDER BY COALESCE(paid_at, created_at) DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", [$c['id'], $statusSql]);
$counts = [];
foreach (db_rows('SELECT status, COUNT(*) n, SUM(amount) s FROM donations WHERE campaign_id = ? GROUP BY status', [$c['id']]) as $r) {
    $counts[$r['status']] = $r;
}
$creator = $c['created_by'] ? db_value('SELECT name FROM admins WHERE id = ?', [$c['created_by']]) : null;

$adminTitle = $c['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="campaigns.php">&larr; All campaigns</a>
    <div class="actions">
        <a class="btn btn-primary btn-sm" href="campaign-edit.php?id=<?= (int) $c['id'] ?>"><?= icon('edit') ?>Edit</a>
        <?php if ($c['status'] !== 'draft'): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('campaign-details.php?slug=' . urlencode($c['slug']))) ?>" target="_blank"><?= icon('external') ?>Public page</a><?php endif; ?>
    </div>
</div>
<div class="stat-cards">
    <div class="stat-card"><span class="icon-circle gold"><?= icon('rupee') ?></span><div><strong><?= e(money($c['raised'])) ?></strong><small>Raised (verified)</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('target') ?></span><div><strong><?= (float) $c['goal_amount'] > 0 ? e(money($c['goal_amount'])) : '—' ?></strong><small>Goal · <?= campaign_percent($c) ?>% funded</small></div></div>
    <div class="stat-card"><span class="icon-circle blue"><?= icon('heart') ?></span><div><strong><?= (int) $c['donor_count'] ?></strong><small>Successful donations</small></div></div>
    <div class="stat-card"><span class="icon-circle"><?= icon('clock') ?></span><div><strong><?= (int) ($counts['pending']['n'] ?? 0) ?></strong><small>Pending (unverified)</small></div></div>
</div>
<div class="panel">
    <div class="panel-head">
        <h3>Donations</h3>
        <nav class="filter-row mb-0">
            <?php foreach (['success', 'pending', 'failed', 'refunded'] as $s): ?>
                <a class="btn btn-sm <?= $statusSql === $s ? 'btn-primary' : 'btn-ghost' ?>" href="?id=<?= (int) $c['id'] ?>&amp;status=<?= $s ?>"><?= e(humanize($s)) ?> (<?= (int) ($counts[$s]['n'] ?? 0) ?>)</a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php if ($donations): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Reference</th><th>Donor</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($donations as $d): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($d['paid_at'] ?: $d['created_at'], 'd M Y, h:i A')) ?></td>
                    <td><a href="donation-view.php?id=<?= (int) $d['id'] ?>"><?= e($d['donation_ref']) ?></a></td>
                    <td><?= e($d['donor_name']) ?><?= $d['is_anonymous'] ? ' <span class="badge badge-gray">Anonymous</span>' : '' ?><br><small class="muted"><?= e($d['donor_email']) ?></small></td>
                    <td class="nowrap"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                    <td><?= e(humanize($d['payment_method'])) ?></td>
                    <td><?= status_badge($d['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <p class="muted">No <?= e($statusSql) ?> donations for this campaign.</p>
    <?php endif; ?>
</div>
<div class="panel">
    <dl class="detail-list">
        <dt>Status</dt><dd><?= status_badge($c['status']) ?></dd>
        <dt>Cause</dt><dd><?= e(campaign_categories()[$c['category']] ?? $c['category']) ?></dd>
        <dt>Dates</dt><dd><?= e(format_date($c['start_date'])) ?> – <?= e(format_date($c['end_date'])) ?></dd>
        <dt>Location</dt><dd><?= e($c['location'] ?: '-') ?></dd>
        <dt>Created</dt><dd><?= e(format_date($c['created_at'], 'd M Y, h:i A')) ?><?= $creator ? ' by ' . e($creator) : '' ?></dd>
        <dt>Summary</dt><dd><?= e($c['short_description']) ?></dd>
    </dl>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
