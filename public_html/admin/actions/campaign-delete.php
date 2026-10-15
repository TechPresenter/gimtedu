<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_permission('campaigns');
csrf_verify('admin/campaigns.php');

$id = (int) ($_POST['id'] ?? 0);
$campaign = db_row('SELECT * FROM campaigns WHERE id = ?', [$id]);
if (!$campaign) {
    flash('error', 'Campaign not found.');
    admin_redirect('campaigns.php');
}
// Campaigns with any donation records are kept for audit purposes - close them instead.
if ((int) db_value('SELECT COUNT(*) FROM donations WHERE campaign_id = ?', [$id]) > 0) {
    flash('error', 'This campaign has donation records and cannot be deleted. Set its status to Closed instead.');
    admin_redirect('campaign-edit.php?id=' . $id);
}
db_query('DELETE FROM campaigns WHERE id = ?', [$id]);
delete_upload($campaign['image']);
admin_log('campaign_deleted', 'Campaign #' . $id . ' ' . $campaign['title']);
flash('success', 'Campaign deleted.');
admin_redirect('campaigns.php');
