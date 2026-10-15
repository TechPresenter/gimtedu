<?php
/**
 * Campaign expenses & utilisation: record (with a private bill), approve / reject, publish to the public
 * utilisation report, export. Bills are stored under uploads/documents/expenses/ and served only here.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('campaigns.expenses');

// Private bill download
if (isset($_GET['bill'])) {
    $x = db_row('SELECT id, document_path FROM campaign_expenses WHERE id = ?', [(int) $_GET['bill']]);
    if (!$x || !$x['document_path']) {
        flash('error', 'No bill is attached to this expense.');
        admin_redirect('expenses.php');
    }
    admin_log('expense_bill_downloaded', 'Expense #' . $x['id']);
    send_private_file($x['document_path'], 'expense-' . $x['id'] . '-bill');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_post_only('expenses.php');
    $back = admin_back('expenses.php');
    $action = (string) ($_POST['action'] ?? '');
    $xid = (int) ($_POST['expense_id'] ?? 0);
    $x = $xid ? db_row('SELECT e.*, c.title AS campaign_title FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id WHERE e.id = ?', [$xid]) : null;
    if ($xid && !$x) {
        flash('error', 'Expense not found.');
        admin_redirect($back);
    }

    if ($action === 'save') {
        [$data, $errors] = validate($_POST, [
            'campaign_id'  => 'required|integer',
            'expense_date' => 'required|date',
            'amount'       => 'required|numeric|min:1|max:100000000',
            'category'     => 'required|in:' . implode(',', EXPENSE_CATEGORIES),
            'vendor'       => 'max:150',
            'description'  => 'required|min:5|max:500',
        ], ['campaign_id' => 'Campaign', 'expense_date' => 'Expense date']);
        if (!isset($errors['expense_date']) && $data['expense_date'] > date('Y-m-d')) {
            $errors['expense_date'] = 'The expense date cannot be in the future.';
        }
        if (!isset($errors['campaign_id']) && !db_value("SELECT id FROM campaigns WHERE id = ? AND status NOT IN ('proposal','rejected')", [(int) $data['campaign_id']])) {
            $errors['campaign_id'] = 'Choose a valid campaign.';
        }
        if ($x && $x['status'] === 'approved') {
            $errors['amount'] = 'Approved expenses cannot be edited. Move it back to "recorded" first.';
        }
        $editBack = $x ? 'admin/expenses.php?edit=' . $xid . '#expense-form' : 'admin/' . $back;
        if ($errors) {
            back_with_errors($errors, $editBack, $_POST);
        }
        $bill = handle_upload('bill', 'document', 'documents/expenses');
        if (!empty($bill['error'])) {
            back_with_errors(['bill' => $bill['error']], $editBack, $_POST);
        }
        $row = [
            'campaign_id' => (int) $data['campaign_id'], 'expense_date' => $data['expense_date'], 'amount' => round((float) $data['amount'], 2),
            'category' => $data['category'], 'vendor' => $data['vendor'] !== '' ? $data['vendor'] : null, 'description' => $data['description'],
            'status' => 'recorded', 'is_published' => 0, 'approved_by' => null, 'approved_at' => null,
        ];
        if (!empty($bill['path'])) {
            $row['document_path'] = $bill['path'];
        }
        if ($x) {
            db_update('campaign_expenses', $row, 'id = :id', ['id' => $xid]);
            if (!empty($bill['path']) && $x['document_path']) {
                delete_upload($x['document_path']);
            }
            admin_log('expense_updated', 'Expense #' . $xid . ' ' . money($row['amount'], true) . ' campaign #' . $row['campaign_id']);
            flash('success', 'Expense updated. It now needs approval again.');
            admin_redirect('expenses.php?campaign=' . $row['campaign_id']);
        }
        $row['recorded_by'] = admin_id();
        $newId = db_insert('campaign_expenses', $row);
        admin_log('expense_recorded', 'Expense #' . $newId . ' ' . money($row['amount'], true) . ' campaign #' . $row['campaign_id'] . ($row['document_path'] ?? '' ? ' with bill' : ''));
        flash('success', 'Expense recorded. It must be approved before it can be published.');
        admin_redirect($back);
    }

    if (!$x) {
        admin_redirect($back);
    }
    $label = 'Expense #' . $xid . ' (' . money($x['amount'], true) . ', ' . $x['campaign_title'] . ')';
    switch ($action) {
        case 'approve':
        case 'reject':
            if ($x['status'] !== 'recorded') {
                flash('info', 'Only recorded expenses can be approved or rejected.');
                break;
            }
            if ($action === 'approve' && (int) $x['recorded_by'] === admin_id() && !is_administrator()) {
                flash('error', 'An expense must be approved by someone other than the person who recorded it.');
                break;
            }
            db_update('campaign_expenses', ['status' => $action === 'approve' ? 'approved' : 'rejected', 'approved_by' => admin_id(), 'approved_at' => date('Y-m-d H:i:s'), 'is_published' => 0], 'id = :id', ['id' => $xid]);
            admin_log($action === 'approve' ? 'expense_approved' : 'expense_rejected', $label);
            flash('success', $action === 'approve' ? 'Expense approved. You can now publish it in the utilisation report.' : 'Expense rejected.');
            break;
        case 'publish':
        case 'unpublish':
            if ($x['status'] !== 'approved') {
                flash('error', 'Only approved expenses can be published.');
                break;
            }
            db_update('campaign_expenses', ['is_published' => $action === 'publish' ? 1 : 0], 'id = :id', ['id' => $xid]);
            admin_log($action === 'publish' ? 'expense_published' : 'expense_unpublished', $label);
            flash('success', $action === 'publish' ? 'Expense published in the utilisation report.' : 'Expense removed from the public utilisation report.');
            break;
        case 'reopen':
            db_update('campaign_expenses', ['status' => 'recorded', 'is_published' => 0, 'approved_by' => null, 'approved_at' => null], 'id = :id', ['id' => $xid]);
            admin_log('expense_reopened', $label);
            flash('success', 'Expense moved back to "recorded".');
            break;
        case 'delete':
            if ($x['status'] === 'approved') {
                flash('error', 'Approved expenses cannot be deleted. Move it back to "recorded" first.');
                break;
            }
            db_query('DELETE FROM campaign_expenses WHERE id = ?', [$xid]);
            delete_upload($x['document_path']);
            admin_log('expense_deleted', $label . ' ' . $x['description']);
            flash('success', 'Expense deleted.');
            break;
    }
    admin_redirect($back);
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$campaigns = db_rows("SELECT id, title, status FROM campaigns WHERE status NOT IN ('proposal','rejected') ORDER BY FIELD(status, 'active', 'draft', 'completed', 'closed'), title");
$campaignId = (int) ($_GET['campaign'] ?? 0);
$status = in_array($_GET['status'] ?? '', ['recorded', 'approved', 'rejected'], true) ? (string) $_GET['status'] : '';
$published = (string) ($_GET['published'] ?? '');
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$params = [];
$where = '1=1';
if ($campaignId) {
    $where .= ' AND e.campaign_id = ?';
    $params[] = $campaignId;
}
if ($status !== '') {
    $where .= ' AND e.status = ?';
    $params[] = $status;
}
if ($published === '1' || $published === '0') {
    $where .= ' AND e.is_published = ?';
    $params[] = (int) $published;
}
$where .= search_where(['e.description', 'e.vendor', 'e.category', 'c.title'], $q, $params);

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $rows = db_rows("SELECT e.id, c.title, e.expense_date, e.category, e.description, e.vendor, e.amount, e.status, e.is_published, r.name, a.name, e.approved_at, IF(e.document_path IS NULL, 'No', 'Yes')
                     FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id LEFT JOIN users r ON r.id = e.recorded_by LEFT JOIN users a ON a.id = e.approved_by
                     WHERE $where ORDER BY e.expense_date DESC, e.id DESC", $params);
    admin_log('expenses_exported', count($rows) . ' rows');
    send_csv('expenses-' . date('Ymd-His') . '.csv', ['ID', 'Campaign', 'Date', 'Category', 'Details', 'Vendor', 'Amount', 'Status', 'Published', 'Recorded by', 'Approved by', 'Approved at', 'Bill attached'], $rows);
}

$totals = db_row("SELECT COALESCE(SUM(CASE WHEN e.status = 'recorded' THEN e.amount END), 0) AS recorded, COALESCE(SUM(CASE WHEN e.status = 'approved' THEN e.amount END), 0) AS approved,
                         COALESCE(SUM(CASE WHEN e.status = 'approved' AND e.is_published = 1 THEN e.amount END), 0) AS published, SUM(e.status = 'recorded') AS waiting
                  FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id WHERE $where", $params);
$p = paginate((int) db_value("SELECT COUNT(*) FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id WHERE $where", $params), 25);
$rows = db_rows("SELECT e.*, c.title AS campaign_title, r.name AS recorded_name, a.name AS approved_name
                 FROM campaign_expenses e JOIN campaigns c ON c.id = e.campaign_id LEFT JOIN users r ON r.id = e.recorded_by LEFT JOIN users a ON a.id = e.approved_by
                 WHERE $where ORDER BY FIELD(e.status, 'recorded', 'approved', 'rejected'), e.expense_date DESC, e.id DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
$edit = isset($_GET['edit']) ? db_row('SELECT * FROM campaign_expenses WHERE id = ?', [(int) $_GET['edit']]) : null;
if ($edit && $edit['status'] === 'approved') {
    $edit = null;
}

$actions = [];
if (admin_can('exports.run')) {
    $actions[] = ['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1, 'page' => null])), 'icon' => 'download', 'style' => 'ghost'];
}
$actions[] = ['label' => 'Record expense', 'url' => '#expense-form', 'icon' => 'plus'];
admin_page_start('Expenses & utilisation', $actions);
?>
<div class="stat-cards">
    <a class="stat-card" href="?status=recorded"><span class="icon-circle gold"><?= icon('clock') ?></span><div><strong><?= (int) $totals['waiting'] ?></strong><small>Awaiting approval (<?= e(money($totals['recorded'])) ?>)</small></div></a>
    <a class="stat-card" href="?status=approved"><span class="icon-circle"><?= icon('check-circle') ?></span><div><strong><?= e(money($totals['approved'])) ?></strong><small>Approved</small></div></a>
    <a class="stat-card" href="?status=approved&amp;published=1"><span class="icon-circle blue"><?= icon('globe') ?></span><div><strong><?= e(money($totals['published'])) ?></strong><small>Published in utilisation reports</small></div></a>
</div>
<?php
$extra = '<select name="campaign" aria-label="Campaign" style="max-width:100%;min-width:0"><option value="">All campaigns</option>';
foreach ($campaigns as $c) {
    $extra .= '<option value="' . (int) $c['id'] . '"' . ($campaignId === (int) $c['id'] ? ' selected' : '') . '>' . e(excerpt($c['title'], 50)) . '</option>';
}
$extra .= '</select><select name="published" aria-label="Published"><option value="">Published or not</option>' . options_html(['1' => 'Published', '0' => 'Not published'], $published, true, '') . '</select>';
admin_filter_bar(['recorded', 'approved', 'rejected'], ['placeholder' => 'Search details, vendor, category or campaign', 'html' => $extra, 'active' => $campaignId || $published !== '']);
?>
<section class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> expense(s)</span><span>Bills are private and never published</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Date</th><th>Campaign</th><th>Expense</th><th>Amount</th><th>Status</th><th>Bill</th><th class="actions-cell">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $x): ?>
                <tr>
                    <td class="nowrap"><?= e(format_date($x['expense_date'])) ?></td>
                    <td><a href="<?= e(admin_url('campaign-view.php?id=' . (int) $x['campaign_id'] . '&tab=expenses')) ?>"><?= e(excerpt($x['campaign_title'], 40)) ?></a></td>
                    <td><strong><?= e($x['category']) ?></strong><br><small class="muted"><?= e($x['description']) ?><?= $x['vendor'] ? ' · ' . e($x['vendor']) : '' ?><br>Recorded by <?= e($x['recorded_name'] ?: '-') ?> on <?= e(format_date($x['created_at'])) ?></small></td>
                    <td class="nowrap"><strong><?= e(money($x['amount'], true)) ?></strong></td>
                    <td><?= status_badge($x['status']) ?><?= $x['is_published'] ? ' <span class="badge badge-blue">Published</span>' : '' ?><?= $x['approved_name'] ? '<br><small class="muted">' . e(($x['status'] === 'rejected' ? 'Rejected' : 'Approved') . ' by ' . $x['approved_name']) . '</small>' : '' ?></td>
                    <td><?= $x['document_path'] ? '<a href="' . e(admin_url('expenses.php?bill=' . (int) $x['id'])) . '">' . icon('download') . ' Bill</a>' : '<span class="muted">—</span>' ?></td>
                    <td class="actions-cell"><?= expense_action_buttons($x, 'expenses.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . preg_replace('/[^A-Za-z0-9=&%_\-.+]/', '', (string) $_SERVER['QUERY_STRING']) : '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No expenses match these filters.') ?>
    <?php endif; ?>
</section>
<section class="panel" id="expense-form">
    <div class="panel-head"><h2><?= $edit ? 'Edit expense #' . (int) $edit['id'] : 'Record an expense' ?></h2></div>
    <?php if ($campaigns): ?>
        <?= expense_form_html($edit, $campaigns, null, 'expenses.php' . ($campaignId ? '?campaign=' . $campaignId : '')) ?>
    <?php else: ?>
        <p class="muted">Create a campaign first.</p>
    <?php endif; ?>
</section>
<p class="small muted">Only approved and published expenses appear on the website (campaign page, utilisation reports and financial transparency) - with date, category, details and amount; vendors and bills stay internal.</p>
<?php
admin_page_end();
