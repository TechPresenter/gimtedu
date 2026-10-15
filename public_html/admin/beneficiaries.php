<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('beneficiaries');

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    $r = db_row('SELECT b.*, a.name AS reviewer_name FROM beneficiaries b LEFT JOIN admins a ON a.id = b.reviewed_by WHERE b.id = ?', [$id]);
    if (!$r) {
        flash('error', 'Request not found.');
        admin_redirect('beneficiaries.php');
    }
    if (isset($_GET['download'])) {
        admin_log('document_downloaded', 'Beneficiary document ' . $r['reference_no']);
        send_private_file($r['document_path'], $r['reference_no'] . '-document');
    }
    $adminTitle = 'Help request ' . $r['reference_no'];
    require __DIR__ . '/includes/header.php';
    ?>
    <div class="page-actions"><a class="btn btn-ghost btn-sm" href="beneficiaries.php">&larr; All help requests</a></div>
    <div class="admin-grid-main">
        <div class="panel">
            <div class="panel-head"><h3><?= e($r['name']) ?></h3><?= status_badge($r['status']) ?></div>
            <?php details_list([
                'Reference' => $r['reference_no'],
                'Support needed' => HELP_CATEGORIES[$r['help_category']] ?? $r['help_category'],
                'Phone' => $r['phone'],
                'Email' => $r['email'],
                'Gender / age' => trim((GENDERS[$r['gender']] ?? '') . ($r['age'] !== null ? ', ' . $r['age'] . ' years' : ''), ', '),
                'Address' => trim($r['address'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['pincode'], ', '),
                'Family members' => $r['family_members'],
                'Annual income' => $r['annual_income'] !== null ? money($r['annual_income']) : '',
                'Description' => $r['description'],
                'Document' => $r['document_path'] ? ['<a class="btn btn-ghost btn-sm" href="beneficiaries.php?id=' . (int) $r['id'] . '&amp;download=1">' . icon('download') . 'Download</a>'] : 'None',
                'Consent given' => $r['consent'] ? 'Yes' : 'No',
                'Submitted' => format_date($r['created_at'], 'd M Y, h:i A'),
            ]); ?>
        </div>
        <div><?php review_form('beneficiaries', $r); ?></div>
    </div>
    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}

$q = clean_string($_GET['q'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$cat = (string) ($_GET['category'] ?? '');
$params = [];
$where = 'WHERE 1=1' . search_where(['reference_no', 'name', 'phone', 'email', 'city'], $q, $params);
if (in_array($status, application_statuses('beneficiaries'), true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}
if (isset(HELP_CATEGORIES[$cat])) {
    $where .= ' AND help_category = ?';
    $params[] = $cat;
}
$p = paginate((int) db_value("SELECT COUNT(*) FROM beneficiaries $where", $params), 25);
$rows = db_rows("SELECT * FROM beneficiaries $where ORDER BY created_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);

$adminTitle = 'Help Requests (Beneficiaries)';
require __DIR__ . '/includes/header.php';
admin_filter_bar(application_statuses('beneficiaries'), ['html' => '<select name="category" aria-label="Category"><option value="">All support types</option>' . options_html(HELP_CATEGORIES, $cat, true, '') . '</select>', 'active' => $cat !== '']);
?>
<div class="panel">
    <div class="table-meta"><span><?= number_format($p['total']) ?> request(s)</span><span>Documents are private and download only for authorised admins.</span></div>
    <?php if ($rows): ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Reference</th><th>Name</th><th>Support</th><th>City</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap"><a href="?id=<?= (int) $r['id'] ?>"><strong><?= e($r['reference_no']) ?></strong></a></td>
                    <td><?= e($r['name']) ?><br><small class="muted"><?= e($r['phone']) ?></small></td>
                    <td><?= e(HELP_CATEGORIES[$r['help_category']] ?? $r['help_category']) ?></td>
                    <td><?= e($r['city']) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <td class="nowrap"><?= e(format_date($r['created_at'])) ?></td>
                    <td class="actions-cell"><a class="icon-btn" href="?id=<?= (int) $r['id'] ?>" aria-label="Review"><?= icon('eye') ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?= pagination_links($p) ?>
    <?php else: ?>
        <?= empty_state('No help requests found.') ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
