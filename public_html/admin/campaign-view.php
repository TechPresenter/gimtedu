<?php
/**
 * Campaign overview: verified figures, updates (create / edit / delete), expenses (forms post to expenses.php,
 * which checks campaigns.expenses) and the campaign's donations with CSV export.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns.manage');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? db_row(campaign_sql() . ' WHERE c.id = ?', [$id]) : null;
if (!$c) {
    flash('error', 'Campaign not found.');
    admin_redirect('campaigns.php');
}
$tab = in_array($_GET['tab'] ?? '', ['overview', 'updates', 'expenses', 'donations'], true) ? (string) $_GET['tab'] : 'overview';
$self = 'campaign-view.php?id=' . $id;

// ---------------------------------------------------------------------
// Campaign updates (news posted on the campaign page)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only($self . '&tab=updates');
    $action = (string) ($_POST['action'] ?? '');
    $uid = (int) ($_POST['update_id'] ?? 0);
    $update = $uid ? db_row('SELECT * FROM campaign_updates WHERE id = ? AND campaign_id = ?', [$uid, $id]) : null;
    if ($action === 'update_save') {
        [$data, $errors] = validate($_POST, ['title' => 'required|min:3|max:200', 'body' => 'required|text|min:10|max:5000', 'status' => 'required|in:draft,published', 'published_at' => 'date']);
        if ($uid && !$update) {
            $errors['title'] = 'Update not found.';
        }
        if ($errors) {
            back_with_errors($errors, 'admin/' . $self . '&tab=updates' . ($uid ? '&edit=' . $uid : ''), $_POST);
        }
        $row = ['title' => $data['title'], 'body' => $data['body'], 'status' => $data['status']];
        if ($data['status'] === 'published') {
            $row['published_at'] = $data['published_at'] !== '' ? $data['published_at'] . ' ' . date('H:i:s') : ($update['published_at'] ?? date('Y-m-d H:i:s'));
        }
        $img = null;
        if (!empty($_FILES['update_image']['name']) && ($_FILES['update_image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            $img = admin_upload_image('update_image', 'campaigns', $data['title']);
            if (!$img) {
                admin_redirect($self . '&tab=updates');
            }
            $row['image'] = $img;
        } elseif ($update && !empty($_POST['remove_image'])) {
            $row['image'] = null;
        }
        if ($update) {
            db_update('campaign_updates', $row, 'id = :id', ['id' => $uid]);
            if (($img || !empty($_POST['remove_image'])) && $update['image']) {
                delete_upload($update['image']);
            }
            admin_log('campaign_update_edited', 'Campaign #' . $id . ' update #' . $uid . ' (' . $data['status'] . ')');
            flash('success', 'Update saved.');
        } else {
            $row += ['campaign_id' => $id, 'created_by' => admin_id()];
            $uid = db_insert('campaign_updates', $row);
            admin_log('campaign_update_added', 'Campaign #' . $id . ' update #' . $uid . ' (' . $data['status'] . ')');
            flash('success', $data['status'] === 'published' ? 'Update published on the campaign page.' : 'Update saved as a draft.');
        }
    } elseif ($action === 'update_delete' && $update) {
        db_query('DELETE FROM campaign_updates WHERE id = ?', [$uid]);
        delete_upload($update['image']);
        admin_log('campaign_update_deleted', 'Campaign #' . $id . ' update #' . $uid . ' ' . $update['title']);
        flash('success', 'Update deleted.');
    }
    admin_redirect($self . '&tab=updates');
}

// Donations CSV for this campaign
if ($tab === 'donations' && isset($_GET['export'])) {
    require_permission('donations.view');
    require_permission('exports.run');
    $rows = db_rows("SELECT d.donation_ref, COALESCE(d.paid_at, d.created_at), d.donor_name, d.donor_email, d.donor_phone, d.amount, d.status, d.payment_method, d.gateway_payment_id, r.receipt_no
                     FROM donations d LEFT JOIN donation_receipts r ON r.donation_id = d.id WHERE d.campaign_id = ? ORDER BY d.created_at DESC", [$id]);
    admin_log('donations_exported', 'Campaign #' . $id . ': ' . count($rows) . ' rows');
    send_csv('campaign-' . $id . '-donations-' . date('Ymd') . '.csv', ['Reference', 'Date', 'Donor', 'Email', 'Phone', 'Amount', 'Status', 'Method', 'Payment ID', 'Receipt No'], $rows);
}

$expenseTotals = db_row("SELECT COALESCE(SUM(CASE WHEN status = 'approved' THEN amount END), 0) AS approved, COALESCE(SUM(CASE WHEN status = 'approved' AND is_published = 1 THEN amount END), 0) AS published,
                                COALESCE(SUM(CASE WHEN status = 'recorded' THEN amount END), 0) AS pending, COUNT(*) AS n FROM campaign_expenses WHERE campaign_id = ?", [$id]);
$pendingDonations = (int) db_value("SELECT COUNT(*) FROM donations WHERE campaign_id = ? AND status = 'pending'", [$id]);
$updates = db_rows('SELECT u.*, us.name AS author FROM campaign_updates u LEFT JOIN users us ON us.id = u.created_by WHERE u.campaign_id = ? ORDER BY COALESCE(u.published_at, u.created_at) DESC, u.id DESC', [$id]);
$editUpdate = null;
if (isset($_GET['edit'])) {
    foreach ($updates as $u) {
        if ((int) $u['id'] === (int) $_GET['edit']) {
            $editUpdate = $u;
        }
    }
}
$publicUrl = campaign_url($c['slug']) . (campaign_is_public($c) ? '' : '?preview=1');
admin_page_start($c['title'], [
    ['label' => 'Edit campaign', 'url' => admin_url('campaign-edit.php?id=' . $id), 'icon' => 'edit'],
    ['label' => campaign_is_public($c) ? 'View on website' : 'Preview', 'url' => $publicUrl, 'icon' => 'external', 'style' => 'ghost'],
]);
$tabs = ['overview' => 'Overview', 'updates' => 'Updates (' . count($updates) . ')', 'expenses' => 'Expenses (' . (int) $expenseTotals['n'] . ')'];
if (admin_can('donations.view')) {
    $tabs['donations'] = 'Donations (' . (int) $c['donor_count'] . ' verified)';
}
?>
<nav class="admin-tabs" aria-label="Campaign sections">
    <?php foreach ($tabs as $k => $label): ?><a class="<?= $tab === $k ? 'active' : '' ?>" href="<?= e(admin_url($self . '&tab=' . $k)) ?>"><?= e($label) ?></a><?php endforeach; ?>
    <a href="<?= e(admin_url('campaigns.php')) ?>">&larr; All campaigns</a>
</nav>

<?php if ($tab === 'overview'): ?>
    <div class="stat-cards">
        <div class="stat-card"><span class="icon-circle"><?= icon('rupee') ?></span><div><strong><?= e(money($c['raised'])) ?></strong><small>Verified raised<?= (float) $c['goal_amount'] > 0 ? ' of ' . e(money($c['goal_amount'])) : ' (no target)' ?></small></div></div>
        <div class="stat-card"><span class="icon-circle gold"><?= icon('heart') ?></span><div><strong><?= (int) $c['donor_count'] ?></strong><small>Verified donations<?= $pendingDonations ? ' · ' . $pendingDonations . ' pending' : '' ?></small></div></div>
        <div class="stat-card"><span class="icon-circle blue"><?= icon('pie-chart') ?></span><div><strong><?= e(money($expenseTotals['approved'])) ?></strong><small>Approved expenses (<?= e(money($expenseTotals['published'])) ?> published)</small></div></div>
        <div class="stat-card"><span class="icon-circle"><?= icon('clock') ?></span><div><strong><?= $c['end_date'] ? e(format_date($c['end_date'])) : 'Open-ended' ?></strong><small><?= e(campaign_status_text($c)) ?></small></div></div>
    </div>
    <div class="admin-grid-main">
        <section class="panel">
            <div class="panel-head"><h2>Details</h2><?= status_badge($c['status']) ?></div>
            <?php if ((float) $c['goal_amount'] > 0): ?><?= ui_progress((float) $c['raised'], (float) $c['goal_amount'], (int) $c['donor_count']) ?><?php endif; ?>
            <?php details_list([
                'Public address' => [e(campaign_url($c['slug']))],
                'Category' => campaign_category_label($c['category']),
                'Location' => $c['location'],
                'Dates' => trim(($c['start_date'] ? format_date($c['start_date']) : 'No start date') . ' – ' . ($c['end_date'] ? format_date($c['end_date']) : 'open-ended')),
                'Short description' => $c['short_description'],
                'Featured / urgent' => ($c['is_featured'] ? 'Featured' : 'Not featured') . ' · ' . ($c['is_urgent'] ? 'Urgent' : 'Not urgent'),
                'SEO title' => $c['meta_title'],
                'Organiser' => $c['organizer_name'] ? $c['organizer_name'] . ' (' . $c['organizer_email'] . ', ' . $c['organizer_phone'] . ')' : null,
                'Created' => format_date($c['created_at'], 'd M Y, h:i A'),
                'Last updated' => $c['updated_at'] ? format_date($c['updated_at'], 'd M Y, h:i A') : null,
            ]); ?>
        </section>
        <section class="panel">
            <div class="panel-head"><h2>Publishing checklist</h2></div>
            <ul class="health-list">
                <li class="<?= $c['image'] ? 'ok' : 'bad' ?>"><?= icon($c['image'] ? 'check-circle' : 'alert-circle') ?><span>Campaign image <?= $c['image'] ? 'uploaded' : 'missing (required to publish)' ?></span></li>
                <li class="<?= mb_strlen(trim(strip_tags((string) $c['description']))) >= 80 ? 'ok' : 'bad' ?>"><?= icon(mb_strlen(trim(strip_tags((string) $c['description']))) >= 80 ? 'check-circle' : 'alert-circle') ?><span>Full description</span></li>
                <li class="<?= $updates ? 'ok' : 'warn' ?>"><?= icon($updates ? 'check-circle' : 'info') ?><span><?= count($updates) ?> update(s) - post regular, factual updates</span></li>
                <li class="<?= (float) $expenseTotals['published'] > 0 ? 'ok' : 'warn' ?>"><?= icon((float) $expenseTotals['published'] > 0 ? 'check-circle' : 'info') ?><span>Utilisation: <?= e(money($expenseTotals['published'])) ?> of approved expenses published</span></li>
            </ul>
            <p class="small muted mt-2">Raised figures include only verified donations (payment gateway or finance team).</p>
        </section>
    </div>

<?php elseif ($tab === 'updates'): ?>
    <div class="admin-grid-main">
        <section class="panel">
            <div class="panel-head"><h2>Updates</h2></div>
            <?php if ($updates): ?>
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Date</th><th>Update</th><th>Status</th><th class="actions-cell">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($updates as $u): ?>
                        <tr>
                            <td class="nowrap"><?= e(format_date($u['published_at'] ?: $u['created_at'])) ?></td>
                            <td><strong><?= e($u['title']) ?></strong><br><small class="muted"><?= e(excerpt($u['body'], 110)) ?><?= $u['author'] ? ' · ' . e($u['author']) : '' ?></small></td>
                            <td><?= status_badge($u['status']) ?></td>
                            <td class="actions-cell">
                                <a class="icon-btn" href="<?= e(admin_url($self . '&tab=updates&edit=' . (int) $u['id'] . '#update-form')) ?>" aria-label="Edit update" title="Edit"><?= icon('edit') ?></a>
                                <form class="inline-form" method="post" data-confirm="Delete this update?">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="update_delete"><input type="hidden" name="update_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="icon-btn danger" type="submit" aria-label="Delete update" title="Delete"><?= icon('trash') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php else: ?>
                <p class="muted">No updates yet. Post short, factual updates about the work (what was done, where, when) - no unverified numbers.</p>
            <?php endif; ?>
        </section>
        <section class="panel" id="update-form">
            <div class="panel-head"><h2><?= $editUpdate ? 'Edit update' : 'Post an update' ?></h2></div>
            <form method="post" enctype="multipart/form-data" data-validate>
                <?= csrf_field() ?><input type="hidden" name="action" value="update_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="update_id" value="<?= (int) ($editUpdate['id'] ?? 0) ?>">
                <div class="form-group"><label for="u_title">Title<span class="req">*</span></label><input id="u_title" name="title" required maxlength="200" value="<?= e(old('title', $editUpdate['title'] ?? '')) ?>"><?= field_error('title') ?></div>
                <div class="form-group mt-2"><label for="u_body">Update<span class="req">*</span></label><textarea id="u_body" name="body" required minlength="10" maxlength="5000" rows="6"><?= e(old('body', $editUpdate['body'] ?? '')) ?></textarea><?= field_error('body') ?></div>
                <div class="form-group mt-2"><label for="u_status">Status</label><select id="u_status" name="status"><?= options_html(['published' => 'Published', 'draft' => 'Draft'], (string) old('status', $editUpdate['status'] ?? 'published'), true, '') ?></select></div>
                <div class="form-group mt-2"><label for="u_date">Date shown</label><input id="u_date" type="date" name="published_at" max="<?= date('Y-m-d') ?>" value="<?= e(old('published_at', $editUpdate && $editUpdate['published_at'] ? date('Y-m-d', strtotime($editUpdate['published_at'])) : '')) ?>"><span class="help">Blank = today.</span><?= field_error('published_at') ?></div>
                <?php if ($editUpdate && $editUpdate['image']): ?><label class="checkbox mt-2"><input type="checkbox" name="remove_image" value="1"> <span>Remove the current photo</span></label><?php endif; ?>
                <div class="form-group mt-2"><label for="u_image">Photo (optional)</label><input id="u_image" type="file" name="update_image" accept="image/jpeg,image/png,image/webp"><span class="help">Only photos with consent of identifiable people.</span></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('send') ?><?= $editUpdate ? 'Save update' : 'Post update' ?></button><?php if ($editUpdate): ?><a class="btn btn-ghost" href="<?= e(admin_url($self . '&tab=updates')) ?>">Cancel</a><?php endif; ?></div>
            </form>
        </section>
    </div>

<?php elseif ($tab === 'expenses'):
    $expenses = db_rows('SELECT e.*, r.name AS recorded_name, a.name AS approved_name FROM campaign_expenses e LEFT JOIN users r ON r.id = e.recorded_by LEFT JOIN users a ON a.id = e.approved_by WHERE e.campaign_id = ? ORDER BY e.expense_date DESC, e.id DESC', [$id]);
    $canExp = admin_can('campaigns.expenses'); ?>
    <div class="stat-cards">
        <div class="stat-card"><div><strong><?= e(money($expenseTotals['pending'])) ?></strong><small>Recorded, awaiting approval</small></div></div>
        <div class="stat-card"><div><strong><?= e(money($expenseTotals['approved'])) ?></strong><small>Approved</small></div></div>
        <div class="stat-card"><div><strong><?= e(money($expenseTotals['published'])) ?></strong><small>Published in utilisation report</small></div></div>
        <div class="stat-card"><div><strong><?= e(money($c['raised'])) ?></strong><small>Verified donations received</small></div></div>
    </div>
    <section class="panel">
        <div class="panel-head"><h2>Expenses</h2><?php if ($canExp): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('expenses.php?campaign=' . $id)) ?>"><?= icon('list') ?>Open in Expenses</a><?php endif; ?></div>
        <?php if ($expenses): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Date</th><th>Expense</th><th>Amount</th><th>Status</th><th>Bill</th><?php if ($canExp): ?><th class="actions-cell">Actions</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($expenses as $x): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($x['expense_date'])) ?></td>
                        <td><strong><?= e($x['category']) ?></strong><br><small class="muted"><?= e($x['description']) ?><?= $x['vendor'] ? ' · ' . e($x['vendor']) : '' ?> · recorded by <?= e($x['recorded_name'] ?: '-') ?></small></td>
                        <td class="nowrap"><?= e(money($x['amount'], true)) ?></td>
                        <td><?= status_badge($x['status']) ?><?= $x['is_published'] ? ' <span class="badge badge-blue">Published</span>' : '' ?><?= $x['approved_name'] ? '<br><small class="muted">by ' . e($x['approved_name']) . '</small>' : '' ?></td>
                        <td><?= $x['document_path'] ? ($canExp ? '<a href="' . e(admin_url('expenses.php?bill=' . (int) $x['id'])) . '">' . icon('download') . ' Bill</a>' : 'Attached') : '<span class="muted">—</span>' ?></td>
                        <?php if ($canExp): ?><td class="actions-cell"><?= expense_action_buttons($x, $self . '&tab=expenses') ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?>
            <p class="muted">No expenses recorded for this campaign yet.</p>
        <?php endif; ?>
    </section>
    <?php if ($canExp): ?>
        <section class="panel" id="expense-form">
            <div class="panel-head"><h2>Record an expense</h2></div>
            <?= expense_form_html(null, [$c], $id, $self . '&tab=expenses') ?>
        </section>
    <?php else: ?>
        <p class="small muted">Recording and approving expenses needs the "Record and approve campaign expenses" permission.</p>
    <?php endif; ?>

<?php elseif ($tab === 'donations'):
    require_permission('donations.view');
    $dStatus = in_array($_GET['status'] ?? '', ['success', 'pending', 'failed', 'refunded'], true) ? (string) $_GET['status'] : '';
    $dParams = [$id];
    $dWhere = 'd.campaign_id = ?';
    if ($dStatus !== '') {
        $dWhere .= ' AND d.status = ?';
        $dParams[] = $dStatus;
    }
    $p = paginate((int) db_value("SELECT COUNT(*) FROM donations d WHERE $dWhere", $dParams), 25);
    $donations = db_rows("SELECT d.*, r.receipt_no FROM donations d LEFT JOIN donation_receipts r ON r.donation_id = d.id WHERE $dWhere ORDER BY d.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $dParams); ?>
    <section class="panel">
        <div class="panel-head"><h2>Donations to this campaign</h2>
            <div class="actions" style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="get" class="filter-row" style="margin:0"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="donations">
                    <select name="status" data-autosubmit aria-label="Status"><option value="">All statuses</option><?= options_html(['success' => 'Success (verified)', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'], $dStatus, true, '') ?></select></form>
                <?php if (admin_can('exports.run')): ?><a class="btn btn-ghost btn-sm" href="<?= e(admin_url($self . '&tab=donations&export=1')) ?>"><?= icon('download') ?>Export CSV</a><?php endif; ?>
            </div></div>
        <?php if ($donations): ?>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Date</th><th>Reference</th><th>Donor</th><th>Amount</th><th>Method</th><th>Status</th><th>Receipt</th></tr></thead>
                <tbody>
                <?php foreach ($donations as $d): ?>
                    <tr>
                        <td class="nowrap"><?= e(format_date($d['paid_at'] ?: $d['created_at'], 'd M Y, h:i A')) ?></td>
                        <td><a href="<?= e(admin_url('donation-view.php?id=' . (int) $d['id'])) ?>"><strong><?= e($d['donation_ref']) ?></strong></a></td>
                        <td><?= e($d['donor_name']) ?><?= $d['is_anonymous'] ? ' <span class="badge badge-gray">Anonymous</span>' : '' ?><br><small class="muted"><?= e($d['donor_email']) ?></small></td>
                        <td class="nowrap"><strong><?= e(money($d['amount'], true)) ?></strong></td>
                        <td><?= e(DONATION_METHODS[$d['payment_method']] ?? $d['payment_method']) ?></td>
                        <td><?= status_badge($d['status']) ?></td>
                        <td class="nowrap"><?= $d['receipt_no'] ? e($d['receipt_no']) : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <?= pagination_links($p) ?>
        <?php else: ?>
            <p class="muted">No donations<?= $dStatus ? ' with this status' : '' ?> for this campaign yet.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php
admin_page_end();
