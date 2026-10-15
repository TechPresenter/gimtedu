<?php
/**
 * Fundraiser proposals submitted by members (/start-a-fundraiser/).
 * Approve -> the campaign becomes a staff-only draft for editing; reject -> status rejected with a note.
 * The organiser is emailed and notified either way.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns.proposals');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    admin_post_only('proposals.php?id=' . $id);
    $c = db_row("SELECT * FROM campaigns WHERE id = ? AND status = 'proposal'", [$id]);
    $action = (string) ($_POST['action'] ?? '');
    if (!$c || !in_array($action, ['approve', 'reject'], true)) {
        flash('error', 'This proposal was not found or has already been reviewed.');
        admin_redirect('proposals.php');
    }
    $note = mb_substr(clean_string($_POST['review_notes'] ?? '', true), 0, 2000);
    if ($action === 'reject' && mb_strlen($note) < 10) {
        back_with_errors(['review_notes' => 'Please write a short note for the organiser explaining the decision (at least 10 characters).'], 'admin/proposals.php?id=' . $id, $_POST);
    }
    $newStatus = $action === 'approve' ? 'draft' : 'rejected';
    db_update('campaigns', [
        'status' => $newStatus, 'review_notes' => $note !== '' ? $note : null, 'reviewed_by' => admin_id(), 'reviewed_at' => date('Y-m-d H:i:s'), 'updated_by' => admin_id(),
    ], 'id = :id AND status = :s', ['id' => $id, 's' => 'proposal']);
    admin_log($action === 'approve' ? 'proposal_approved' : 'proposal_rejected', 'Campaign #' . $id . ' ' . $c['title']);

    $subject = $action === 'approve' ? 'Your fundraiser proposal has been approved' : 'Update on your fundraiser proposal';
    $body = '<p>Dear ' . e($c['organizer_name'] ?: 'organiser') . ',</p>'
        . ($action === 'approve'
            ? '<p>Thank you for proposing <strong>' . e($c['title']) . '</strong>. Our team has approved the proposal in principle and will now prepare the campaign page with you. We will contact you before anything is published.</p>'
            : '<p>Thank you for proposing <strong>' . e($c['title']) . '</strong>. After careful review we are not able to take this fundraiser forward at this time.</p>')
        . ($note !== '' ? '<p><strong>Note from our team:</strong><br>' . e_nl($note) . '</p>' : '')
        . '<p>Remember: donations are always received directly by ' . e(site_name()) . ' through its website - never in a personal account.</p><p>Regards,<br>' . e(site_name()) . '</p>';
    $mailed = $c['organizer_email'] ? send_mail($c['organizer_email'], $subject . ' - ' . site_name(), $body) : false;
    if ($c['submitted_by']) {
        add_notification((int) $c['submitted_by'], $subject, $action === 'approve'
            ? 'Your proposal "' . $c['title'] . '" was approved. Our team will prepare the campaign with you.'
            : 'Your proposal "' . $c['title'] . '" was not approved.' . ($note !== '' ? ' Note: ' . mb_substr($note, 0, 400) : ''),
            page_url('start-a-fundraiser') . '#my-proposals', $action === 'approve' ? 'success' : 'info');
    }
    flash('success', ($action === 'approve' ? 'Proposal approved and saved as a draft campaign.' : 'Proposal rejected.') . ($mailed ? ' The organiser has been emailed.' : ' The organiser was notified in their account (email could not be sent).'));
    admin_redirect($action === 'approve' ? 'campaign-edit.php?id=' . $id : 'proposals.php');
}

// ---------------------------------------------------------------------
// Single proposal
// ---------------------------------------------------------------------
if (isset($_GET['id'])) {
    $c = db_row('SELECT c.*, u.name AS user_name, u.email AS user_email, u.created_at AS user_since, r.name AS reviewer
                 FROM campaigns c LEFT JOIN users u ON u.id = c.submitted_by LEFT JOIN users r ON r.id = c.reviewed_by
                 WHERE c.id = ? AND c.submitted_by IS NOT NULL', [(int) $_GET['id']]);
    if (!$c) {
        flash('error', 'Proposal not found.');
        admin_redirect('proposals.php');
    }
    $others = (int) db_value('SELECT COUNT(*) FROM campaigns WHERE submitted_by = ? AND id <> ?', [$c['submitted_by'], $c['id']]);
    admin_page_start('Proposal: ' . $c['title'], [['label' => 'All proposals', 'url' => admin_url('proposals.php'), 'icon' => 'list', 'style' => 'ghost']]);
    ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= e($c['title']) ?></h2><?= status_badge($c['status'] === 'proposal' ? 'pending' : ($c['status'] === 'draft' ? 'approved' : $c['status'])) ?></div>
                <?php details_list([
                    'Cause' => campaign_category_label($c['category']),
                    'Location' => $c['location'],
                    'Target amount' => money($c['goal_amount']),
                    'Proposed end date' => $c['end_date'] ? format_date($c['end_date']) : 'Not given',
                    'Submitted' => format_date($c['created_at'], 'd M Y, h:i A'),
                ]); ?>
                <h3 class="mt-3">Description of the cause</h3>
                <div class="prose"><?= clean_html((string) $c['description']) ?></div>
                <?php if ($c['proposal_notes']): ?><h3 class="mt-3">Supporting notes</h3><div class="note-box"><?= e($c['proposal_notes']) ?></div><?php endif; ?>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Organiser</h2></div>
                <?php details_list([
                    'Name' => $c['organizer_name'],
                    'Email' => $c['organizer_email'],
                    'Phone' => $c['organizer_phone'],
                    'Account' => $c['user_name'] ? $c['user_name'] . ' · member since ' . format_date($c['user_since']) : 'Account deleted',
                    'Other proposals' => (string) $others,
                ]); ?>
                <?php if (admin_can('members.view') && $c['submitted_by']): ?><p class="mt-2"><a class="btn btn-ghost btn-sm" href="<?= e(admin_url('user-view.php?id=' . (int) $c['submitted_by'])) ?>"><?= icon('user') ?>View user account</a></p><?php endif; ?>
            </section>
            <?php if ($c['status'] === 'proposal'): ?>
                <section class="panel">
                    <div class="panel-head"><h2>Decision</h2></div>
                    <p class="small muted">Check the cause, the budget and the organiser before approving. Approval creates a <strong>draft</strong> campaign - nothing is published until you complete and publish it.</p>
                    <form method="post" data-validate>
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <div class="form-group"><label for="review_notes">Note to the organiser</label><textarea id="review_notes" name="review_notes" rows="4" maxlength="2000"><?= e(old('review_notes')) ?></textarea><span class="help">Emailed to the organiser. Required when rejecting.</span><?= field_error('review_notes') ?></div>
                        <div class="form-actions">
                            <button class="btn btn-primary" type="submit" name="action" value="approve"><?= icon('check') ?>Approve as draft</button>
                            <button class="btn btn-danger" type="submit" name="action" value="reject" formnovalidate><?= icon('x') ?>Reject</button>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <section class="panel">
                    <div class="panel-head"><h2>Review</h2></div>
                    <?php details_list(['Decision' => $c['status'] === 'rejected' ? 'Rejected' : 'Approved (now ' . humanize($c['status']) . ')', 'Reviewed by' => $c['reviewer'], 'Reviewed on' => $c['reviewed_at'] ? format_date($c['reviewed_at'], 'd M Y, h:i A') : null, 'Note' => $c['review_notes']]); ?>
                    <?php if ($c['status'] !== 'rejected' && admin_can('campaigns.manage')): ?><p class="mt-2"><a class="btn btn-primary btn-sm" href="<?= e(admin_url('campaign-edit.php?id=' . (int) $c['id'])) ?>"><?= icon('edit') ?>Edit campaign</a></p><?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </div>
    <?php
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$view = in_array($_GET['view'] ?? '', ['pending', 'reviewed', 'all'], true) ? (string) $_GET['view'] : 'pending';
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$params = [];
$where = 'c.submitted_by IS NOT NULL' . ['pending' => " AND c.status = 'proposal'", 'reviewed' => " AND c.status <> 'proposal'", 'all' => ''][$view];
$where .= search_where(['c.title', 'c.organizer_name', 'c.organizer_email', 'c.location'], $q, $params);
$p = paginate((int) db_value("SELECT COUNT(*) FROM campaigns c WHERE $where", $params), 20);
$rows = db_rows("SELECT c.* FROM campaigns c WHERE $where ORDER BY (c.status = 'proposal') DESC, c.created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$pending = (int) db_value("SELECT COUNT(*) FROM campaigns WHERE status = 'proposal'");
admin_page_start('Fundraiser proposals');
?>
<nav class="admin-tabs" aria-label="Proposal views">
    <a class="<?= $view === 'pending' ? 'active' : '' ?>" href="?view=pending">Awaiting review (<?= $pending ?>)</a>
    <a class="<?= $view === 'reviewed' ? 'active' : '' ?>" href="?view=reviewed">Reviewed</a>
    <a class="<?= $view === 'all' ? 'active' : '' ?>" href="?view=all">All</a>
</nav>
<?php admin_filter_bar([], ['placeholder' => 'Search title, organiser or location', 'hidden' => ['view' => $view]]); ?>
<section class="panel">
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Submitted</th><th>Proposal</th><th>Organiser</th><th>Target</th><th>Status</th><th class="actions-cell"></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($c['created_at'])) ?></td>
                    <td><a href="?id=<?= (int) $c['id'] ?>"><strong><?= e($c['title']) ?></strong></a><br><small class="muted"><?= e(campaign_category_label($c['category'])) ?> · <?= e($c['location'] ?: '-') ?></small></td>
                    <td><?= e($c['organizer_name']) ?><br><small class="muted"><?= e($c['organizer_email']) ?></small></td>
                    <td class="nowrap"><?= e(money($c['goal_amount'])) ?></td>
                    <td><?= status_badge($c['status'] === 'proposal' ? 'pending' : $c['status']) ?></td>
                    <td class="actions-cell"><a class="btn btn-<?= $c['status'] === 'proposal' ? 'primary' : 'ghost' ?> btn-sm" href="?id=<?= (int) $c['id'] ?>"><?= $c['status'] === 'proposal' ? 'Review' : 'View' ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state($view === 'pending' ? 'No proposals are waiting for review.' : 'No proposals found.') ?>
    <?php endif; ?>
</section>
<p class="small muted">Members propose fundraisers at <a href="<?= e(page_url('start-a-fundraiser')) ?>" target="_blank" rel="noopener"><?= e(page_url('start-a-fundraiser')) ?></a>. They can see the status of their proposals there and in their dashboard.</p>
<?php
admin_page_end();
