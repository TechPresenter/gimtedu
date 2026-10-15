<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns');

$campaign = db_row('SELECT * FROM campaigns WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
if (!$campaign) {
    flash('error', 'Campaign not found.');
    admin_redirect('campaigns.php');
}
$adminTitle = 'Edit Campaign';
require __DIR__ . '/includes/header.php';
?>
<div class="page-actions">
    <a class="btn btn-ghost btn-sm" href="campaigns.php">&larr; All campaigns</a>
    <div class="actions">
        <a class="btn btn-ghost btn-sm" href="campaign-view.php?id=<?= (int) $campaign['id'] ?>"><?= icon('eye') ?>View donations</a>
        <?php if ($campaign['status'] !== 'draft'): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('campaign-details.php?slug=' . urlencode($campaign['slug']))) ?>" target="_blank"><?= icon('external') ?>Public page</a><?php endif; ?>
    </div>
</div>
<?php campaign_form($campaign); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
