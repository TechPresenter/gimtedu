<?php
/**
 * Help requests (beneficiaries) - sensitive data: phone numbers are masked in the list, documents are private
 * and downloaded only through this page (logged). Status workflow with notes, history, applicant notification, CSV export.
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('beneficiaries.manage');

$kind = 'beneficiary';
$statuses = recruitment_kinds()[$kind]['statuses'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_only('beneficiaries.php');
    $id = (int) ($_POST['id'] ?? 0);
    $self = 'beneficiaries.php?id=' . $id;
    $row = db_row('SELECT * FROM beneficiaries WHERE id = ?', [$id]);
    if (!$row) {
        flash('error', 'Request not found.');
        admin_redirect('beneficiaries.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'status') {
        $status = (string) ($_POST['status'] ?? '');
        $note = mb_substr(clean_string($_POST['note'] ?? '', true), 0, 3000);
        if (!in_array($status, $statuses, true)) {
            flash('error', 'Please choose a valid status.');
            admin_redirect($self);
        }
        if ($status === $row['status'] && $note === '') {
            flash('info', 'The status has not changed. Add a note to record an update.');
            admin_redirect($self);
        }
        foreach (recruitment_support_change_status($kind, $row, $status, $note, !empty($_POST['visible']), !empty($_POST['notify'])) as $m) {
            flash('info', $m);
        }
        flash('success', 'Status updated to "' . recruitment_status_info($status, $kind)[0] . '".');
    } elseif ($action === 'notes') {
        $notes = mb_substr(clean_string($_POST['admin_notes'] ?? '', true), 0, 5000);
        db_update('beneficiaries', ['admin_notes' => $notes !== '' ? $notes : null], 'id = :id', ['id' => $id]);
        admin_log('beneficiary_notes', 'Help request ' . $row['reference_no'] . ' notes updated');
        flash('success', 'Internal notes saved.');
    }
    admin_redirect($self);
}

// ---------------------------------------------------------------------
// One request
// ---------------------------------------------------------------------
if (!empty($_GET['id'])) {
    $id = (int) $_GET['id'];
    if (isset($_GET['download'])) {
        send_application_document($kind, $id);
    }
    $row = db_row('SELECT b.*, r.name AS reviewer_name FROM beneficiaries b LEFT JOIN users r ON r.id = b.reviewed_by WHERE b.id = ?', [$id]);
    if (!$row) {
        flash('error', 'Request not found.');
        admin_redirect('beneficiaries.php');
    }
    admin_log('beneficiary_viewed', 'Help request ' . $row['reference_no']);
    admin_page_start('Help request ' . $row['reference_no'], [['label' => 'All help requests', 'url' => admin_url('beneficiaries.php'), 'icon' => 'list', 'style' => 'ghost']]);
    echo recruitment_admin_css();
    ?>
    <div class="admin-grid-main">
        <div>
            <section class="panel">
                <div class="panel-head"><h2><?= e($row['name']) ?></h2><?= recruitment_status_badge($row['status'], $kind) ?></div>
                <?php details_list([
                    'Reference' => $row['reference_no'],
                    'Support requested' => HELP_CATEGORIES[$row['help_category']] ?? humanize($row['help_category']),
                    'Mobile' => ['<a href="tel:' . e($row['phone']) . '">' . e($row['phone']) . '</a>'],
                    'Email' => $row['email'] ? ['<a href="mailto:' . e($row['email']) . '">' . e($row['email']) . '</a>'] : 'Not given',
                    'Gender' => GENDERS[$row['gender']] ?? '',
                    'Age' => $row['age'] !== null ? $row['age'] . ' years' : '',
                    'Address' => trim(implode(', ', array_filter([$row['address'], $row['city'], $row['state'], $row['pincode']]))),
                    'Family members' => $row['family_members'] !== null ? (string) $row['family_members'] : '',
                    'Annual family income' => $row['annual_income'] !== null ? money($row['annual_income']) : 'Not given',
                    'Situation' => $row['description'],
                    'Document' => $row['document_path'] ? ['<a class="btn btn-ghost btn-sm" href="' . e(admin_url('beneficiaries.php?id=' . $id . '&download=1')) . '">' . icon('download') . 'Download document</a>'] : 'None',
                    'Consent' => $row['consent'] ? 'Consent to be contacted and to use of information given' : 'Not recorded',
                    'Submitted' => format_date($row['created_at'], 'd M Y, h:i A') . ($row['user_id'] ? ' (signed-in user #' . $row['user_id'] . ')' : ''),
                    'Last reviewed' => $row['reviewed_at'] ? format_date($row['reviewed_at'], 'd M Y, h:i A') . ($row['reviewer_name'] ? ' by ' . $row['reviewer_name'] : '') : '',
                ]); ?>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>History</h2></div>
                <?= recruitment_timeline_html(application_history($kind, $id), $kind, true) ?>
            </section>
        </div>
        <div>
            <section class="panel">
                <div class="panel-head"><h2>Update status</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="form-group"><label for="status">Status</label>
                        <select id="status" name="status"><?php foreach ($statuses as $st): ?><option value="<?= e($st) ?>"<?= $st === $row['status'] ? ' selected' : '' ?>><?= e(recruitment_status_info($st, $kind)[0]) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label for="note">Note</label><textarea id="note" name="note" rows="3" maxlength="3000" placeholder="e.g. Ration kit arranged; volunteer visit on Saturday."></textarea></div>
                    <label class="checkbox small"><input type="checkbox" name="visible" value="1"><span>Show the note to the applicant on the status page</span></label>
                    <label class="checkbox small"><input type="checkbox" name="notify" value="1"<?= $row['email'] ? ' checked' : '' ?>><span>Notify the applicant<?= $row['email'] ? ' by email' : ' (no email on file - please call)' ?></span></label>
                    <button class="btn btn-primary btn-sm" type="submit"><?= icon('check') ?>Save status</button>
                </form>
            </section>
            <section class="panel">
                <div class="panel-head"><h2>Internal notes</h2></div>
                <form method="post" class="rc-stack">
                    <?= csrf_field() ?><input type="hidden" name="action" value="notes"><input type="hidden" name="id" value="<?= (int) $id ?>">
                    <div class="form-group"><label for="admin_notes" class="sr-only">Internal notes</label><textarea id="admin_notes" name="admin_notes" rows="5" maxlength="5000"><?= e((string) $row['admin_notes']) ?></textarea></div>
                    <button class="btn btn-ghost btn-sm" type="submit"><?= icon('check') ?>Save notes</button>
                </form>
            </section>
            <div class="rc-muted-box"><?= icon('shield') ?> This record contains personal and sensitive information. Share it only with people working on this request.</div>
        </div>
    </div>
    <?php
    admin_page_end();
    exit;
}

// ---------------------------------------------------------------------
// List
// ---------------------------------------------------------------------
$q = mb_substr(clean_string($_GET['q'] ?? ''), 0, 100);
$status = (string) ($_GET['status'] ?? '');
$category = (string) ($_GET['category'] ?? '');
$range = admin_date_range('all');
$params = [$range['from'] . ' 00:00:00', $range['to'] . ' 23:59:59'];
$where = ' AND a.created_at BETWEEN ? AND ?' . search_where(['a.reference_no', 'a.name', 'a.email', 'a.phone', 'a.city'], $q, $params);
if (isset(HELP_CATEGORIES[$category])) {
    $where .= ' AND a.help_category = ?';
    $params[] = $category;
}
$counts = recruitment_status_counts('beneficiaries', $where, $params);
if (in_array($status, $statuses, true)) {
    $where .= ' AND a.status = ?';
    $params[] = $status;
} else {
    $status = '';
}

if (isset($_GET['export'])) {
    require_permission('exports.run');
    $out = [];
    foreach (db_rows("SELECT a.* FROM beneficiaries a WHERE 1=1 $where ORDER BY a.created_at DESC LIMIT 5000", $params) as $r) {
        $out[] = [$r['reference_no'], $r['name'], $r['phone'], $r['email'], HELP_CATEGORIES[$r['help_category']] ?? $r['help_category'], $r['age'], $r['family_members'],
            $r['annual_income'], $r['city'], $r['state'], recruitment_status_info($r['status'], $kind)[0], $r['created_at'], $r['reviewed_at']];
    }
    admin_log('export', 'Help requests CSV (' . count($out) . ' rows, sensitive)');
    send_csv('help-requests-' . date('Y-m-d') . '.csv', ['Reference', 'Name', 'Phone', 'Email', 'Support', 'Age', 'Family members', 'Annual income', 'City', 'State', 'Status', 'Received', 'Reviewed'], $out);
}

$p = paginate((int) db_value("SELECT COUNT(*) FROM beneficiaries a WHERE 1=1 $where", $params), 25);
$rows = db_rows("SELECT a.* FROM beneficiaries a WHERE 1=1 $where ORDER BY FIELD(a.status, 'pending', 'under_review', 'approved', 'assisted', 'rejected'), a.created_at DESC
                 LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$actions = admin_can('exports.run') ? [['label' => 'Export CSV', 'url' => '?' . http_build_query(array_merge($_GET, ['export' => 1])), 'icon' => 'download', 'style' => 'ghost']] : [];
admin_page_start('Help Requests', $actions);
echo recruitment_admin_css();
$catSelect = '<select name="category" aria-label="Type of support"><option value="">All types of support</option>';
foreach (HELP_CATEGORIES as $k => $label) {
    $catSelect .= '<option value="' . e($k) . '"' . ($category === $k ? ' selected' : '') . '>' . e($label) . '</option>';
}
$catSelect .= '</select>';
$hidden = array_filter(['status' => $status, 'range' => is_string($_GET['range'] ?? null) ? $_GET['range'] : '', 'from' => is_string($_GET['from'] ?? null) ? $_GET['from'] : '', 'to' => is_string($_GET['to'] ?? null) ? $_GET['to'] : '']);
admin_filter_bar([], ['placeholder' => 'Reference, name, phone, email or city', 'html' => $catSelect, 'active' => $category !== '', 'hidden' => $hidden]);
echo admin_range_picker($range);
echo recruitment_chipbar($statuses, $counts, $status, array_diff_key($_GET, ['export' => 1]), $kind);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> request<?= $p['total'] === 1 ? '' : 's' ?> · <?= e($range['label']) ?></span><span><?= icon('lock') ?> Phone numbers are masked in this list</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th><th>Name</th><th>Support</th><th>City</th><th>Phone</th><th>Status</th><th>Received</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['reference_no']) ?></strong></a><?= $r['document_path'] ? '<br><small class="muted">document</small>' : '' ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e(HELP_CATEGORIES[$r['help_category']] ?? humanize($r['help_category'])) ?></td>
                    <td><?= e($r['city']) ?></td>
                    <td class="nowrap"><?= e(rc_mask_phone($r['phone'])) ?></td>
                    <td><?= recruitment_status_badge($r['status'], $kind) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Open <?= e($r['reference_no']) ?>" title="Open"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state($q !== '' || $status !== '' || $category !== '' ? 'No requests match these filters.' : 'No help requests yet. They appear here when someone uses the Request Help form.') ?>
    <?php endif; ?>
</div>
<?php
admin_page_end();
